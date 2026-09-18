<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RteLinks;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/** Local source index. No source record information leaves this instance. */
final class SourceUsage
{
    private const SOURCE = 'tx_typo3totypo3_source_usage';
    private const DIRTY = 'tx_typo3totypo3_source_dirty';
    private const SCAN = 'tx_typo3totypo3_source_scan';

    public function __construct(
        private readonly ConnectionPool $connections,
        private readonly TcaSchemaFactory $schemas,
        private readonly TypoLinkCodecService $codec,
    ) {}

    public function changed(string $table, int $uid): void
    {
        if ($uid < 1 || !isset($GLOBALS['TCA'][$table])) { return; }
        $db = $this->connections->getConnectionForTable(self::DIRTY);
        $key = ['source_key' => hash('sha256', $table . ':' . $uid)];
        $data = ['table_name' => $table, 'record_uid' => $uid, 'generation' => bin2hex(random_bytes(16))];
        try { $db->insert(self::DIRTY, $key + $data); }
        catch (UniqueConstraintViolationException) { $db->update(self::DIRTY, $data, $key); }
    }

    public function references(string $instance): array
    {
        $rows = $this->connections->getConnectionForTable(self::SOURCE)->executeQuery(
            'SELECT DISTINCT page_uuid, language_id FROM ' . self::SOURCE . ' WHERE instance_uuid = ? ORDER BY page_uuid, language_id', [$instance]
        )->fetchAllAssociative();
        return array_map(static fn(array $row): array => ['instance' => $instance, 'page' => $row['page_uuid'], 'language' => (int)$row['language_id']], $rows);
    }

    public function snapshot(string $instance): ?array
    {
        $references = null;
        $this->leased(function (Connection $db, array $state) use ($instance, &$references): void {
            if ($db->count('*', self::DIRTY, []) === 0) {
                $references = ['references' => $this->references($instance),
                    'established' => (int)$state['indexed_at'] > 0,
                    'complete' => (int)$state['completed_at'] > 0 && $state['tables_json'] === ''];
            }
        });
        return $references;
    }

    public function processChanges(int $limit = 500, ?float $deadline = null): void
    {
        $deadline ??= microtime(true) + 40;
        $this->leased(function (Connection $db, array $state) use ($limit, $deadline): void {
            $rows = $db->select(['*'], self::DIRTY, [], [], ['source_key' => 'ASC'], max(1, min(1000, $limit)))->fetchAllAssociative();
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) { break; }
                $table = $row['table_name'];
                $record = isset($GLOBALS['TCA'][$table]) ? $this->connections->getConnectionForTable($table)->select(['*'], $table, ['uid' => $row['record_uid']])->fetchAssociative() : false;
                $db->transactional(function () use ($db, $row, $record, $table, $state): void {
                    $this->index($db, $table, (int)$row['record_uid'], $record ?: [], $state['epoch']);
                    $db->delete(self::DIRTY, ['source_key' => $row['source_key'], 'generation' => $row['generation']]);
                });
            }
        });
    }

    /** Resumable full pass. Returns true only when an entire pass has completed. */
    public function reconcile(int $limit = 1000, bool $force = false, ?float $deadline = null): bool
    {
        $complete = false;
        $deadline ??= microtime(true) + 40;
        $this->leased(function (Connection $db, array $state) use ($limit, $force, $deadline, &$complete): void {
            if ($state['tables_json'] === '') {
                if (!$force && (int)$state['completed_at'] > time() - 86400) { $complete = true; return; }
                $tables = [];
                foreach ($GLOBALS['TCA'] as $table => $tca) {
                    // Include fields enabled only by a record-type override as well.
                    $configs = array_merge(array_column($tca['columns'] ?? [], 'config'),
                        ...array_map(static fn(array $type): array => array_column($type['columnsOverrides'] ?? [], 'config'), array_values($tca['types'] ?? [])));
                    foreach ($configs as $config) {
                        if (($config['type'] ?? '') === 'link' || ($config['enableRichtext'] ?? false)) { $tables[] = $table; break; }
                    }
                }
                sort($tables);
                $state = array_replace($state, ['tables_json' => json_encode($tables, JSON_THROW_ON_ERROR), 'started_at' => time(), 'table_index' => 0, 'last_uid' => 0, 'epoch' => bin2hex(random_bytes(16)), 'scan_request' => (int)$state['request_revision']]);
                $db->update(self::SCAN, array_intersect_key($state, array_flip(['tables_json', 'started_at', 'table_index', 'last_uid', 'epoch', 'scan_request'])), ['uid' => 1]);
            }
            $tables = json_decode($state['tables_json'], true, flags: JSON_THROW_ON_ERROR);
            $remaining = max(1, min(5000, $limit));
            while (isset($tables[(int)$state['table_index']]) && $remaining > 0 && microtime(true) < $deadline) {
                $table = $tables[(int)$state['table_index']];
                $size = min(100, $remaining);
                $rows = isset($GLOBALS['TCA'][$table]) ? $this->connections->getConnectionForTable($table)
                    ->executeQuery('SELECT * FROM ' . $this->connections->getConnectionForTable($table)->quoteIdentifier($table)
                        . ' WHERE uid > ? ORDER BY uid LIMIT ' . $size, [(int)$state['last_uid']])->fetchAllAssociative() : [];
                $db->transactional(function () use ($db, $rows, $table, &$state, $size): void {
                    foreach ($rows as $record) {
                        $this->index($db, $table, (int)$record['uid'], $record, $state['epoch']);
                        $state['last_uid'] = (int)$record['uid'];
                    }
                    if (count($rows) < $size) { ++$state['table_index']; $state['last_uid'] = 0; }
                    $db->update(self::SCAN, ['table_index' => $state['table_index'], 'last_uid' => $state['last_uid']], ['uid' => 1]);
                });
                $remaining -= count($rows);
            }
            if (!isset($tables[(int)$state['table_index']])) {
                $db->transactional(function () use ($db, $state): void {
                    $db->executeStatement('DELETE FROM ' . self::SOURCE . ' WHERE epoch <> ?', [$state['epoch']]);
                    $requested = (int)$db->select(['request_revision'], self::SCAN, ['uid' => 1])->fetchOne();
                    $db->update(self::SCAN, ['tables_json' => '', 'indexed_at' => time(), 'completed_at' => $requested === (int)$state['scan_request'] ? time() : 0], ['uid' => 1]);
                });
                $complete = true;
            }
        });
        return $complete;
    }

    public function requestReconciliation(): void
    {
        $db = $this->connections->getConnectionForTable(self::SCAN);
        $db->executeStatement('UPDATE ' . self::SCAN . ' SET completed_at = 0, request_revision = request_revision + 1 WHERE uid = 1');
    }

    public function state(): array
    {
        $db = $this->connections->getConnectionForTable(self::SCAN);
        $state = $db->select(['indexed_at', 'started_at', 'tables_json'], self::SCAN, ['uid' => 1])->fetchAssociative();
        return ['completedAt' => (int)($state['indexed_at'] ?? 0),
            'overdue' => $state && (($state['tables_json'] !== '' && (int)$state['started_at'] < time() - 3600)
                || ((int)$state['indexed_at'] > 0 && (int)$state['indexed_at'] < time() - 90000))];
    }

    private function index(Connection $db, string $table, int $uid, array $record, string $epoch): void
    {
        $source = hash('sha256', $table . ':' . $uid);
        $db->delete(self::SOURCE, ['source_key' => $source]);
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        if (!$record || !empty($record[$ctrl['delete'] ?? 'deleted'])
            || (int)($record['t3ver_state'] ?? 0) === 2
            || ((int)($record['t3ver_oid'] ?? 0) > 0 && (int)($record['t3ver_wsid'] ?? 0) === 0)) { return; }
        $schema = $this->schemas->get($table);
        $type = BackendUtility::getTCAtypeValue($table, $record);
        $references = [];
        foreach ($record as $field => $value) {
            if (!is_string($value) || !str_contains($value, 't3://exchange?') || !$schema->hasField($field)) { continue; }
            $config = ($schema->hasSubSchema($type) && $schema->getSubSchema($type)->hasField($field)
                ? $schema->getSubSchema($type)->getField($field) : $schema->getField($field))->getConfiguration();
            if (($config['type'] ?? '') === 'link') { $links = [$this->codec->decode($value)]; }
            elseif (($config['type'] ?? '') === 'text' && ($config['enableRichtext'] ?? false)) { $links = RteLinks::anchors($value); }
            else { continue; }
            foreach ($links as $link) {
                if (!str_starts_with($link['url'], 't3://exchange?')) { continue; }
                parse_str((string)parse_url($link['url'], PHP_URL_QUERY), $parameters);
                $reference = (new ManagedLink())->resolveHandlerData($parameters);
                if ($reference['valid']) { $references[ManagedLink::key($reference)] = $reference; }
            }
        }
        foreach ($references as $key => $reference) {
            $db->insert(self::SOURCE, ['source_key' => $source, 'reference_key' => $key, 'instance_uuid' => $reference['instance'],
                'page_uuid' => $reference['page'], 'language_id' => $reference['language'], 'epoch' => $epoch]);
        }
    }

    private function leased(callable $operation): void
    {
        $db = $this->connections->getConnectionForTable(self::SCAN);
        foreach ([self::SOURCE, self::DIRTY] as $table) {
            if ($db !== $this->connections->getConnectionForTable($table)) { throw new \RuntimeException('Source index tables require one connection.'); }
        }
        try { $db->insert(self::SCAN, ['uid' => 1]); } catch (UniqueConstraintViolationException) {}
        $token = bin2hex(random_bytes(16));
        if ($db->executeStatement('UPDATE ' . self::SCAN . ' SET lease_token = ?, lease_until = ? WHERE uid = 1 AND lease_until <= ?', [$token, time() + 120, time()]) !== 1) { return; }
        try {
            $operation($db, $db->select(['*'], self::SCAN, ['uid' => 1])->fetchAssociative());
        } finally {
            $db->update(self::SCAN, ['lease_token' => '', 'lease_until' => 0], ['uid' => 1, 'lease_token' => $token]);
        }
    }
}
