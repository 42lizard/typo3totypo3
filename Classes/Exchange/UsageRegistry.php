<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** Authorized reports only: the endpoint establishes the site before calling this seam. */
final class UsageRegistry
{
    public const TABLE = 'tx_typo3totypo3_usage';
    private const PAIR = 'tx_typo3totypo3_usage_pair';
    private const STAGE = 'tx_typo3totypo3_usage_stage';

    public function __construct(private readonly ConnectionPool $connections) {}

    public function rememberScope(string $scope, array $channel): void
    {
        $this->locked($scope, function (Connection $db) use ($scope, $channel): void {
            $db->update(self::PAIR, ['peer_name' => $channel['name'] ?? '', 'instance_uuid' => $channel['instance'],
                'environment_uuid' => $channel['environment']], ['scope_key' => $scope]);
        });
    }

    public function report(string $scope, array $items): void
    {
        $this->validateItems($items, true);
        $this->locked($scope, function (Connection $db, array $pair) use ($scope, $items): void {
            foreach ($items as $item) {
                if ($item['revision'] > (int)$pair['floor_revision']) {
                    $this->apply($db, $scope, $item);
                }
            }
        });
    }

    public function usages(string $scope): array
    {
        return $this->connections->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['scope_key' => $scope, 'present' => 1], [], ['page_uuid' => 'ASC', 'language_id' => 'ASC'])
            ->fetchAllAssociative();
    }

    public function stage(string $scope, string $snapshot, int $revision, array $items, string $grant = ''): void
    {
        $this->validateSnapshot($snapshot, $revision);
        $this->validateItems($items, false);
        $this->locked($scope, function (Connection $db, array $pair) use ($scope, $snapshot, $revision, $items, $grant): void {
            if ($revision <= (int)$pair['floor_revision']) {
                return;
            }
            $progress = false;
            if ($pair['snapshot_uuid'] !== $snapshot) {
                $progress = true;
                if ($revision <= (int)$pair['snapshot_revision']) {
                    throw new \InvalidArgumentException('Superseded snapshot.');
                }
                $db->delete(self::STAGE, ['scope_key' => $scope]);
                $db->update(self::PAIR, ['snapshot_uuid' => $snapshot, 'snapshot_revision' => $revision, 'snapshot_grant' => $grant], ['scope_key' => $scope]);
            } elseif ((int)$pair['snapshot_revision'] !== $revision || $pair['snapshot_grant'] !== $grant) {
                throw new \InvalidArgumentException('Snapshot revision cannot change.');
            }
            foreach ($items as $item) {
                $key = ['scope_key' => $scope, 'page_uuid' => $item['page'], 'language_id' => $item['language']];
                $existing = $db->select(['site_identifier'], self::STAGE, $key)->fetchOne();
                if ($existing !== false && $existing !== $item['site']) {
                    throw new \InvalidArgumentException('Snapshot item cannot change.');
                }
                if ($existing === false) {
                    $progress = true;
                    $db->insert(self::STAGE, $key + ['site_identifier' => $item['site']]);
                }
            }
            if ($progress) { $db->update(self::PAIR, ['snapshot_updated' => time()], ['scope_key' => $scope]); }
        });
    }

    /** The digest covers sorted, distinct page UUID/language lines, each ending in a newline. */
    public function complete(string $scope, string $snapshot, int $revision, int $count, string $digest, string $grant = ''): void
    {
        $this->validateSnapshot($snapshot, $revision);
        if ($count < 0 || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new \InvalidArgumentException('Invalid snapshot completion.');
        }
        $this->locked($scope, function (Connection $db, array $pair) use ($scope, $snapshot, $revision, $count, $digest, $grant): void {
            if ($revision <= (int)$pair['floor_revision']) {
                return;
            }
            if ($pair['snapshot_uuid'] !== $snapshot || (int)$pair['snapshot_revision'] !== $revision
                || $pair['snapshot_grant'] !== $grant || (int)$pair['snapshot_updated'] < time() - 86400) {
                throw new \InvalidArgumentException('Missing or expired snapshot.');
            }
            $rows = $db->select(['*'], self::STAGE, ['scope_key' => $scope], [], ['page_uuid' => 'ASC', 'language_id' => 'ASC'])->fetchAllAssociative();
            $hash = hash_init('sha256');
            foreach ($rows as $row) {
                hash_update($hash, $row['page_uuid'] . ':' . $row['language_id'] . "\n");
            }
            if (count($rows) !== $count || !hash_equals($digest, hash_final($hash))) {
                throw new \InvalidArgumentException('Incomplete snapshot.');
            }
            $db->executeStatement('UPDATE ' . self::TABLE . ' SET present = 0, revision = ? WHERE scope_key = ? AND revision <= ?'
                . ' AND NOT EXISTS (SELECT 1 FROM ' . self::STAGE . ' s WHERE s.scope_key = ' . self::TABLE . '.scope_key'
                . ' AND s.page_uuid = ' . self::TABLE . '.page_uuid AND s.language_id = ' . self::TABLE . '.language_id)', [$revision, $scope, $revision]);
            $match = 's.scope_key = ' . self::TABLE . '.scope_key AND s.page_uuid = ' . self::TABLE . '.page_uuid AND s.language_id = ' . self::TABLE . '.language_id';
            // Publish the snapshot in set operations; avoid two queries per destination inside an HTTP acknowledgment.
            $db->executeStatement('UPDATE ' . self::TABLE
                . ' SET registered_revision = CASE WHEN present = 1 THEN registered_revision ELSE ? END, present = 1, revision = ?, reported_at = ?,'
                . ' site_identifier = (SELECT s.site_identifier FROM ' . self::STAGE . ' s WHERE ' . $match . ')'
                . ' WHERE scope_key = ? AND revision <= ? AND EXISTS (SELECT 1 FROM ' . self::STAGE . ' s WHERE ' . $match . ')',
                [$revision, $revision, time(), $scope, $revision]);
            $db->executeStatement('INSERT INTO ' . self::TABLE . ' (scope_key, page_uuid, language_id, revision, registered_revision, present, site_identifier, reported_at)'
                . ' SELECT s.scope_key, s.page_uuid, s.language_id, ?, ?, 1, s.site_identifier, ? FROM ' . self::STAGE . ' s'
                . ' LEFT JOIN ' . self::TABLE . ' u ON u.scope_key = s.scope_key AND u.page_uuid = s.page_uuid AND u.language_id = s.language_id'
                . ' WHERE s.scope_key = ? AND u.page_uuid IS NULL', [$revision, $revision, time(), $scope]);
            $db->update(self::PAIR, ['floor_revision' => $revision], ['scope_key' => $scope]);
            $db->delete(self::STAGE, ['scope_key' => $scope]);
        });
    }

    public function expireSnapshots(): void
    {
        $db = $this->connections->getConnectionForTable(self::PAIR);
        $scopes = $db->executeQuery("SELECT scope_key FROM " . self::PAIR . " WHERE snapshot_uuid <> '' AND snapshot_revision > floor_revision AND snapshot_updated < ? LIMIT 100", [time() - 86400])->fetchFirstColumn();
        foreach ($scopes as $scope) {
            $this->locked($scope, function (Connection $db, array $pair) use ($scope): void {
                if ((int)$pair['snapshot_updated'] >= time() - 86400 || (int)$pair['snapshot_revision'] <= (int)$pair['floor_revision']) { return; }
                $db->delete(self::STAGE, ['scope_key' => $scope]);
                $db->update(self::PAIR, ['snapshot_uuid' => ''], ['scope_key' => $scope]);
            });
        }
    }

    private function apply(Connection $db, string $scope, array $item): void
    {
        $key = ['scope_key' => $scope, 'page_uuid' => $item['page'], 'language_id' => $item['language']];
        $previous = $db->select(['*'], self::TABLE, $key)->fetchAssociative();
        if ($previous !== false && (int)$previous['revision'] >= $item['revision']) {
            return;
        }
        $data = ['revision' => $item['revision'], 'present' => (int)$item['present'], 'site_identifier' => $item['site'], 'reported_at' => time(),
            'registered_revision' => $item['present'] && (!$previous || !(int)$previous['present']) ? $item['revision'] : (int)($previous['registered_revision'] ?? 0)];
        if ($previous === false) {
            $db->insert(self::TABLE, $key + $data);
        } else {
            $db->update(self::TABLE, $data, $key);
        }
    }

    /** One row lock per pairing serializes reports with atomic snapshot publication. */
    private function locked(string $scope, callable $operation): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope)) {
            throw new \InvalidArgumentException('Invalid usage scope.');
        }
        $db = $this->connections->getConnectionForTable(self::PAIR);
        foreach ([self::TABLE, self::STAGE] as $table) {
            if ($db !== $this->connections->getConnectionForTable($table)) {
                throw new \RuntimeException('Usage tables require one database connection.');
            }
        }
        try {
            $db->insert(self::PAIR, ['scope_key' => $scope]);
        } catch (UniqueConstraintViolationException) {
            // Existing scope; acquire its database write lock below.
        }
        $db->transactional(function () use ($db, $scope, $operation): void {
            $db->executeStatement('UPDATE ' . self::PAIR . ' SET floor_revision = floor_revision WHERE scope_key = ?', [$scope]);
            $pair = $db->select(['*'], self::PAIR, ['scope_key' => $scope])->fetchAssociative();
            $operation($db, $pair);
        });
    }

    private function validateSnapshot(string $snapshot, int $revision): void
    {
        if (!PeerConfiguration::isUuid($snapshot) || $revision < 1 || $revision > 2147483647) {
            throw new \InvalidArgumentException('Invalid snapshot identity.');
        }
    }

    private function validateItems(array $items, bool $report): void
    {
        if (!array_is_list($items) || count($items) > 50) {
            throw new \InvalidArgumentException('Invalid usage batch.');
        }
        foreach ($items as $item) {
            if (!is_array($item) || count($item) !== ($report ? 5 : 3)
                || !is_string($item['page'] ?? null) || !PeerConfiguration::isUuid($item['page'])
                || !is_int($item['language'] ?? null) || $item['language'] < 0 || $item['language'] > 2147483647
                || !is_string($item['site'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $item['site'])
                || ($report && (!is_int($item['revision'] ?? null) || $item['revision'] < 1
                    || $item['revision'] > 2147483647 || !is_bool($item['present'] ?? null)))) {
                throw new \InvalidArgumentException('Invalid usage item.');
            }
        }
    }
}
