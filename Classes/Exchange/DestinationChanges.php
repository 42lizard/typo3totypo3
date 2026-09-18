<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lizard\Typo3ToTypo3\PageResolver;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DestinationChanges
{
    private const TABLE = 'tx_typo3totypo3_observation';
    private array $resolved = [];

    public function beginRun(): void { $this->resolved = []; }

    public function __construct(
        private readonly ConnectionPool $connections,
        private readonly UsageAccess $access,
        private readonly PageResolver $resolver,
        private readonly DeliveryQueue $queue,
    ) {}

    public function scan(array $config, string $name, int $limit = 50): int
    {
        $outgoing = $config['outgoing'][$name];
        if (!$outgoing['enabled'] || $outgoing['capability'] !== 'notify') { return 0; }
        $scope = ExchangeConfiguration::scope($config, $outgoing);
        $this->queue->initialize($scope);
        $db = $this->connections->getConnectionForTable(self::TABLE);
        foreach ([UsageRegistry::TABLE, 'tx_typo3totypo3_delivery', 'tx_typo3totypo3_delivery_pair'] as $table) {
            if ($db !== $this->connections->getConnectionForTable($table)) { throw new \RuntimeException('Change publication requires one connection.'); }
        }
        $processed = 0;
        foreach ($config['incoming'] as $grant) {
            if (!$this->matches($grant, $outgoing)) { continue; }
            $usageScope = ExchangeConfiguration::scope($config, $grant);
            $rows = $db->executeQuery('SELECT u.*, o.checked_at FROM ' . UsageRegistry::TABLE . ' u LEFT JOIN ' . self::TABLE
                . ' o ON o.usage_scope = u.scope_key AND o.notification_scope = ? AND o.page_uuid = u.page_uuid AND o.language_id = u.language_id'
                . ' WHERE u.scope_key = ? AND u.present = 1 AND (o.checked_at IS NULL OR o.checked_at <= ?)'
                . ' ORDER BY COALESCE(o.priority, 0) DESC, COALESCE(o.checked_at, 0), u.page_uuid, u.language_id LIMIT ' . max(1, min(50, $limit)), [$scope, $usageScope, time() - 180])->fetchAllAssociative();
            $db->transactional(function () use ($db, $rows, $config, $scope, $usageScope, $grant, &$processed): void {
                foreach ($rows as $usage) {
                    $reference = ['instance' => $config['instance'], 'page' => $usage['page_uuid'], 'language' => (int)$usage['language_id']];
                    $identity = ['usage_scope' => $usageScope, 'notification_scope' => $scope, 'page_uuid' => $usage['page_uuid'], 'language_id' => $usage['language_id']];
                    if (!$db->count('*', self::TABLE, $identity)) { $db->insert(self::TABLE, $identity); }
                    $authorized = $this->access->site($usage, $grant) !== null;
                    // Operational errors propagate; they are never interpreted as unavailability.
                    $key = hash('sha256', json_encode([$reference, $grant['sites']], JSON_THROW_ON_ERROR));
                    $result = $authorized ? ($this->resolved[$key] ??= $this->resolver->resolve($reference, true, $grant['sites'], $config['instance'])) : null;
                    $fingerprint = $result === null ? 'revoked' : hash('sha256', json_encode([
                        $result['status'], $result['url'] ?? '', (int)$usage['registered_revision'], $grant['sites'],
                    ], JSON_THROW_ON_ERROR));
                    $db->transactional(function () use ($db, $identity, $fingerprint, $result, $reference, $scope): void {
                        $db->update(self::TABLE, ['checked_at' => time(), 'priority' => 0], $identity);
                        $previous = $db->select(['state_hash'], self::TABLE, $identity)->fetchOne();
                        if ($previous !== $fingerprint && $result !== null) {
                            $revision = $this->queue->reserveRevision($scope);
                            $this->queue->enqueue($scope, $reference['page'] . ':' . $reference['language'],
                                ['items' => [['reference' => $reference, 'revision' => $revision]]]);
                        }
                        $db->update(self::TABLE, ['state_hash' => $fingerprint], $identity);
                    });
                    ++$processed;
                }
            });
        }
        return $processed;
    }

    public function mayNotify(array $config, array $outgoing, array $reference): bool
    {
        if (($reference['instance'] ?? null) !== $config['instance']) { return false; }
        foreach ($config['incoming'] as $grant) {
            if (!$this->matches($grant, $outgoing)) { continue; }
            $usage = $this->connections->getConnectionForTable(UsageRegistry::TABLE)->select(['*'], UsageRegistry::TABLE,
                ['scope_key' => ExchangeConfiguration::scope($config, $grant), 'page_uuid' => $reference['page'], 'language_id' => $reference['language'], 'present' => 1])->fetchAssociative();
            if ($usage && $this->access->site($usage, $grant) !== null) { return true; }
        }
        return false;
    }

    public function restart(string $scope): void
    {
        $this->connections->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['notification_scope' => $scope]);
    }

    public function requestRecheck(int $pageId = 0, int $depth = 0): void
    {
        if ($pageId > 0) {
            $db = $this->connections->getConnectionForTable('tx_typo3totypo3_change_hint');
            $data = ['generation' => bin2hex(random_bytes(16)), 'child_cursor' => 0, 'self_checked' => 0, 'depth' => $depth, 'queued_at' => time()];
            try { $db->insert('tx_typo3totypo3_change_hint', ['page_uid' => $pageId] + $data); }
            catch (UniqueConstraintViolationException) { $db->update('tx_typo3totypo3_change_hint', $data, ['page_uid' => $pageId]); }
            return;
        }
        $db = $this->connections->getConnectionForTable('tx_typo3totypo3_recheck');
        try { $db->insert('tx_typo3totypo3_recheck', ['uid' => 1]); } catch (UniqueConstraintViolationException) {}
        $db->executeStatement('UPDATE tx_typo3totypo3_recheck SET requested = requested + 1 WHERE uid = 1');
    }

    public function processRechecks(): void
    {
        $db = $this->connections->getConnectionForTable('tx_typo3totypo3_recheck');
        $state = $db->select(['*'], 'tx_typo3totypo3_recheck', ['uid' => 1])->fetchAssociative();
        if (!$state || (int)$state['requested'] === (int)$state['processed']) { return; }
        $db->transactional(function () use ($db, $state): void {
            // Preserve oldest-first progress when more global hints arrive during a pass.
            $due = time() - 181;
            $db->executeStatement('UPDATE ' . self::TABLE . ' SET checked_at = CASE WHEN checked_at > ? THEN ? ELSE checked_at END', [$due, $due]);
            $db->update('tx_typo3totypo3_recheck', ['processed' => $state['requested']], ['uid' => 1]);
        });
    }

    /** Expand changed subtrees in the worker, never in an editor's save request. */
    public function processHints(int $limit = 1000): void
    {
        $db = $this->connections->getConnectionForTable('tx_typo3totypo3_change_hint');
        $pages = $this->connections->getConnectionForTable('pages');
        $deadline = microtime(true) + 5;
        $processed = 0;
        while ($processed < $limit && microtime(true) < $deadline) {
            $rows = $db->select(['*'], 'tx_typo3totypo3_change_hint', [], [], ['queued_at' => 'ASC', 'page_uid' => 'ASC'], min(100, $limit - $processed))->fetchAllAssociative();
            if (!$rows) { break; }
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) { return; }
                $key = ['page_uid' => $row['page_uid'], 'generation' => $row['generation']];
                $page = $pages->select(['t3ver_oid', 'l10n_parent'], 'pages', ['uid' => $row['page_uid']])->fetchAssociative();
                if ((int)($page['t3ver_oid'] ?? 0) > 0) {
                    $live = $pages->select(['l10n_parent'], 'pages', ['uid' => $page['t3ver_oid']])->fetchAssociative();
                    if ((int)($live['l10n_parent'] ?? 0) > 0) { $page['t3ver_oid'] = $live['l10n_parent']; }
                }
                $liveId = (int)(($page['t3ver_oid'] ?? 0) ?: (($page['l10n_parent'] ?? 0) ?: $row['page_uid']));
                if (!(int)$row['self_checked']) {
                    $this->connections->getConnectionForTable(self::TABLE)->executeStatement('UPDATE ' . self::TABLE
                        . ' SET checked_at = 0, priority = 1 WHERE page_uuid IN (SELECT uuid FROM tx_typo3totypo3_identity WHERE page_uid = ?)', [$liveId]);
                    $db->update('tx_typo3totypo3_change_hint', ['self_checked' => 1], $key);
                }
                $children = (int)$row['depth'] >= 99 ? [] : $pages->executeQuery('SELECT uid FROM pages WHERE pid = ? AND uid > ? AND t3ver_wsid = 0 ORDER BY uid LIMIT 100', [$liveId, $row['child_cursor']])->fetchFirstColumn();
                foreach ($children as $child) { $this->requestRecheck((int)$child, (int)$row['depth'] + 1); }
                if (count($children) < 100) { $db->delete('tx_typo3totypo3_change_hint', $key); }
                else { $db->update('tx_typo3totypo3_change_hint', ['child_cursor' => (int)end($children)], $key); }
                ++$processed;
            }
        }
    }

    private function matches(array $grant, array $outgoing): bool
    {
        return $grant['enabled'] && $grant['capability'] === 'usage' && $grant['instance'] === $outgoing['instance'] && $grant['environment'] === $outgoing['environment'];
    }

}
