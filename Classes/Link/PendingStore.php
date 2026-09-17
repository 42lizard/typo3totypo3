<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class PendingStore
{
    public const TABLE = 'tx_typo3totypo3_link_outcome';

    public function __construct(private readonly ConnectionPool $connections) {}

    public static function key(string $table, int $uid, string $field): string
    {
        return hash('sha256', $table . ':' . $uid . ':' . $field);
    }

    public static function fingerprint(array $record): string
    {
        ksort($record);
        return hash('sha256', json_encode(array_map(static fn($value) => (string)$value, $record), JSON_THROW_ON_ERROR));
    }

    public function record(string $table, int $uid, string $field, array $record, array $outcome, bool $onlyIfMissing = false): void
    {
        $connection = $this->connections->getConnectionForTable(self::TABLE);
        $key = ['source_key' => self::key($table, $uid, $field)];
        if ($outcome['status'] === 'ordinary') {
            if (!$onlyIfMissing) {
                $connection->delete(self::TABLE, $key);
            }
            return;
        }
        $data = [
            'table_name' => $table, 'record_uid' => $uid, 'field_name' => $field,
            'workspace_id' => (int)($record['t3ver_wsid'] ?? 0), 'live_uid' => (int)($record['t3ver_oid'] ?? 0),
            'value_hash' => hash('sha256', (string)$record[$field]), 'record_hash' => self::fingerprint($record),
            'status' => $outcome['status'], 'reference_key' => $outcome['reference_key'] ?? '',
            'checked_at' => time(), 'created_at' => time(), 'generation' => bin2hex(random_bytes(16)),
            'job_status' => $outcome['status'] === 'pending' ? 'pending'
                : (in_array($outcome['status'], ['resolved', 'managed'], true) ? 'complete' : 'attention'),
            'next_attempt' => $outcome['status'] === 'pending' ? time() + 60 : 0,
            'attempts' => 0, 'lease_token' => '', 'lease_until' => 0,
        ];
        try {
            $connection->insert(self::TABLE, $key + $data);
        } catch (UniqueConstraintViolationException) {
            if (!$onlyIfMissing) {
                $connection->update(self::TABLE, $data, $key);
            }
        }
    }

    /** Upgrade only already-recorded outcomes, never scan unrelated content for URLs. */
    public function adoptLegacy(int $limit, ?string $sourceKey = null): void
    {
        $connection = $this->connections->getConnectionForTable(self::TABLE);
        $rows = $connection->executeQuery(
            'SELECT * FROM ' . self::TABLE . " WHERE job_status = ''"
                . ($sourceKey === null ? '' : ' AND source_key = ?') . ' LIMIT ' . max(1, min(100, $limit)),
            $sourceKey === null ? [] : [$sourceKey],
        )->fetchAllAssociative();
        foreach ($rows as $row) {
            $table = $row['table_name'];
            $field = $row['field_name'];
            $record = null;
            if (isset($GLOBALS['TCA'][$table]['columns'][$field])) {
                $source = $this->connections->getConnectionForTable($table);
                $record = $source->executeQuery('SELECT * FROM ' . $source->quoteIdentifier($table) . ' WHERE uid = ?', [$row['record_uid']])->fetchAssociative();
            }
            $matches = $record && isset($record[$field]) && (int)($record['t3ver_wsid'] ?? 0) === (int)$row['workspace_id']
                && hash_equals($row['value_hash'], hash('sha256', (string)$record[$field]));
            $state = !$matches ? 'stale' : ($row['status'] === 'pending' ? 'pending'
                : (in_array($row['status'], ['resolved', 'managed'], true) ? 'complete' : 'attention'));
            $connection->update(self::TABLE, [
                'job_status' => $state, 'generation' => bin2hex(random_bytes(16)),
                'record_hash' => $record ? self::fingerprint($record) : '',
                'live_uid' => (int)($record['t3ver_oid'] ?? 0),
                'created_at' => (int)$row['checked_at'] ?: time(), 'next_attempt' => $state === 'pending' ? time() : 0,
            ], ['source_key' => $row['source_key'], 'job_status' => '', 'value_hash' => $row['value_hash']]);
        }
    }

    public function outstanding(int $limit): array
    {
        return $this->connections->getConnectionForTable(self::TABLE)->executeQuery(
            'SELECT source_key, table_name, record_uid, field_name, workspace_id, job_status, status, attempts, next_attempt FROM '
                . self::TABLE . ' WHERE job_status IN (?, ?) ORDER BY next_attempt, source_key LIMIT ' . max(1, min(100, $limit)),
            ['pending', 'attention'],
        )->fetchAllAssociative();
    }

    public function due(int $limit, ?string $sourceKey = null): array
    {
        return $this->connections->getConnectionForTable(self::TABLE)->executeQuery(
            'SELECT * FROM ' . self::TABLE . ' WHERE job_status = ? AND next_attempt <= ? AND lease_until < ?'
                . ($sourceKey === null ? '' : ' AND source_key = ?') . ' ORDER BY next_attempt, source_key LIMIT ' . max(1, min(100, $limit)),
            $sourceKey === null ? ['pending', time(), time()] : ['pending', time(), time(), $sourceKey],
        )->fetchAllAssociative();
    }

    public function claim(array $job): ?array
    {
        $token = bin2hex(random_bytes(16));
        $changed = $this->connections->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET lease_token = ?, lease_until = ? WHERE source_key = ? AND generation = ? AND job_status = ? AND lease_until < ? AND next_attempt <= ?',
            [$token, time() + 120, $job['source_key'], $job['generation'], 'pending', time(), time()],
        );
        return $changed === 1 ? array_replace($job, ['lease_token' => $token]) : null;
    }

    public function finish(array $job, string $status, ?array $record = null, ?string $referenceKey = null): void
    {
        $attempts = (int)$job['attempts'] + 1;
        $state = match ($status) {
            'pending' => time() - (int)$job['created_at'] >= 604800 ? 'attention' : 'pending',
            'resolved', 'managed', 'ordinary' => 'complete',
            'changed', 'deleted', 'published' => 'stale',
            default => 'attention',
        };
        $data = ['status' => $status, 'job_status' => $state, 'attempts' => $attempts, 'checked_at' => time(),
            'next_attempt' => $state === 'pending' ? time() + min(3600, 60 * (2 ** min(6, $attempts - 1))) : 0,
            'lease_token' => '', 'lease_until' => 0];
        if ($referenceKey !== null) {
            $data['reference_key'] = $referenceKey;
        }
        if ($record !== null) {
            $data['record_hash'] = self::fingerprint($record);
            $data['value_hash'] = hash('sha256', (string)$record[$job['field_name']]);
        }
        $this->connections->getConnectionForTable(self::TABLE)->update(self::TABLE, $data, [
            'source_key' => $job['source_key'], 'generation' => $job['generation'], 'lease_token' => $job['lease_token'],
        ]);
    }

    public function retry(string $key): bool
    {
        return $this->connections->getConnectionForTable(self::TABLE)->executeStatement(
            'UPDATE ' . self::TABLE . ' SET job_status = ?, next_attempt = ?, created_at = ?, attempts = 0, generation = ?, lease_token = ?, lease_until = 0 WHERE source_key = ? AND job_status = ? AND lease_until < ?',
            ['pending', time(), time(), bin2hex(random_bytes(16)), '', $key, 'attention', time()],
        ) === 1;
    }
}
