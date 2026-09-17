<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Doctrine\DBAL\Query\QueryBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RetryWorker
{
    public function __construct(
        private readonly PendingStore $jobs,
        private readonly LinkFieldHook $conversion,
        private readonly ConnectionPool $connections,
        private readonly Context $context,
    ) {}

    public function run(int $limit = 10, ?string $sourceKey = null): array
    {
        $this->jobs->adoptLegacy($limit, $sourceKey);
        $counts = ['processed' => 0, 'failed' => 0];
        $deadline = hrtime(true) / 1e9 + 45;
        foreach ($this->jobs->due($limit, $sourceKey) as $candidate) {
            if (hrtime(true) / 1e9 >= $deadline) {
                break;
            }
            $job = $this->jobs->claim($candidate);
            if ($job === null) {
                continue;
            }
            try {
                $this->process($job);
                ++$counts['processed'];
            } catch (\Throwable $exception) {
                // Never persist raw exceptions: HTTP errors can contain submitted URLs or credentials.
                $this->jobs->finish($job, 'pending');
                ++$counts['failed'];
            }
        }
        return $counts;
    }

    private function process(array $job): void
    {
        if (time() - (int)$job['created_at'] >= 604800) {
            $this->jobs->finish($job, 'expired');
            return;
        }
        $table = $job['table_name'];
        $field = $job['field_name'];
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            $this->jobs->finish($job, 'unsupported');
            return;
        }
        $connection = $this->connections->getConnectionForTable($table);
        foreach ([PendingStore::TABLE, 'sys_history', 'sys_log'] as $atomicTable) {
            if ($this->connections->getConnectionForTable($atomicTable) !== $connection) {
                $this->jobs->finish($job, 'database');
                return;
            }
        }
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform) {
            $transactionalTables = (int)$connection->executeQuery(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?) AND engine = 'InnoDB'",
                [$table, PendingStore::TABLE, 'sys_history', 'sys_log'],
            )->fetchOne();
            if ($transactionalTables !== 4) {
                $this->jobs->finish($job, 'database');
                return;
            }
        } elseif (!$platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $this->jobs->finish($job, 'database');
            return;
        }
        $record = $this->read($connection, $table, (int)$job['record_uid']);
        if (!$this->matches($job, $record)) {
            $this->reconsider($job, $record);
            return;
        }
        $originalUser = $GLOBALS['BE_USER'];
        $originalWorkspace = $this->context->getAspect('workspace');
        $user = clone $originalUser;
        if (!$user->isAdmin() || !$user->setTemporaryWorkspace((int)$job['workspace_id'])) {
            $this->jobs->finish($job, 'permissions');
            return;
        }
        $GLOBALS['BE_USER'] = $user;
        $this->context->setAspect('workspace', new WorkspaceAspect((int)$job['workspace_id']));
        try {
            // All peer I/O happens BEFORE locking either content or job rows.
            $prepared = $this->conversion->prepare($table, (int)$job['record_uid'], $field, (string)$record[$field]);
            $connection->beginTransaction();
            try {
                // Match editor lock order: content first, then its outcome/job.
                $current = $this->read($connection, $table, (int)$job['record_uid'], true);
                $currentJob = (new QueryBuilder($connection))->select('*')->from(PendingStore::TABLE)
                    ->where('source_key = ?')->setParameter(0, $job['source_key'])->forUpdate()->executeQuery()->fetchAssociative();
                if (!$currentJob || $currentJob['generation'] !== $job['generation'] || $currentJob['lease_token'] !== $job['lease_token']) {
                    $connection->rollBack();
                    return;
                }
                if (!$this->matches($job, $current)) {
                    $connection->rollBack();
                    $this->reconsider($job, $current);
                    return;
                }
                if (!$user->setTemporaryWorkspace((int)$job['workspace_id'])) {
                    $connection->rollBack();
                    $this->jobs->finish($job, 'permissions');
                    return;
                }
                if ($prepared['value'] !== $current[$field]) {
                    $handler = GeneralUtility::makeInstance(DataHandler::class);
                    $handler->start([$table => [(int)$job['record_uid'] => [$field => $prepared['value']]]], [], $user);
                    $this->conversion->applyPrepared(static fn() => $handler->process_datamap());
                    $updated = $this->read($connection, $table, (int)$job['record_uid']);
                    if ($handler->errorLog || $handler->autoVersionIdMap
                        || !$updated || (int)($updated['t3ver_wsid'] ?? 0) !== (int)$job['workspace_id']
                        || ($updated[$field] ?? null) !== $prepared['value']) {
                        $connection->rollBack();
                        $this->jobs->finish($job, 'persistence');
                        return;
                    }
                    $current = $updated;
                }
                $this->jobs->finish($job, $prepared['status'], $current, $prepared['reference_key']);
                $connection->commit();
                // DataHandler provides history and invalidation; repeat invalidation after commit
                // so a frontend request during the transaction cannot leave old rendered output cached.
                if ($prepared['value'] !== $record[$field]) {
                    $handler->clear_cacheCmd((int)($table === 'pages' ? $current['uid'] : $current['pid']));
                }
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                throw $exception;
            }
        } finally {
            $GLOBALS['BE_USER'] = $originalUser;
            $this->context->setAspect('workspace', $originalWorkspace);
        }
    }

    private function read(Connection $connection, string $table, int $uid, bool $lock = false): ?array
    {
        $query = (new QueryBuilder($connection))->select('*')->from($connection->quoteIdentifier($table))
            ->where('uid = ?')->setParameter(0, $uid);
        if ($lock) {
            $query->forUpdate();
        }
        return $query->executeQuery()->fetchAssociative() ?: null;
    }

    private function matches(array $job, ?array $record): bool
    {
        $deleted = $GLOBALS['TCA'][$job['table_name']]['ctrl']['delete'] ?? null;
        return $record !== null && (!$deleted || empty($record[$deleted]))
            && (int)($record['t3ver_wsid'] ?? 0) === (int)$job['workspace_id']
            && hash_equals($job['value_hash'], hash('sha256', (string)($record[$job['field_name']] ?? '')))
            && hash_equals($job['record_hash'], PendingStore::fingerprint($record));
    }

    private function reconsider(array $job, ?array $record): void
    {
        $this->jobs->finish($job, $record === null ? 'deleted' : 'changed');
        // Publication may remove the draft or move it out of its original workspace.
        $deleted = $GLOBALS['TCA'][$job['table_name']]['ctrl']['delete'] ?? null;
        if ((!$record || ($deleted && !empty($record[$deleted])) || (int)($record['t3ver_wsid'] ?? 0) !== (int)$job['workspace_id']) && (int)$job['live_uid'] > 0) {
            $record = $this->read($this->connections->getConnectionForTable($job['table_name']), $job['table_name'], (int)$job['live_uid']);
        }
        $deleted = $GLOBALS['TCA'][$job['table_name']]['ctrl']['delete'] ?? null;
        if (!$record || ($deleted && !empty($record[$deleted])) || !is_string($record[$job['field_name']] ?? null)) {
            return;
        }
        // Reconsider current content on the next run. Do not write the stale prepared result.
        // An existing job from a newer save always takes precedence.
        if ((int)$record['uid'] === (int)$job['record_uid']) {
            $connection = $this->connections->getConnectionForTable(PendingStore::TABLE);
            $connection->delete(PendingStore::TABLE, ['source_key' => $job['source_key'], 'generation' => $job['generation'], 'job_status' => 'stale']);
        }
        $this->jobs->record($job['table_name'], (int)$record['uid'], $job['field_name'], $record,
            ['status' => 'pending', 'reference_key' => ''], true);
    }
}
