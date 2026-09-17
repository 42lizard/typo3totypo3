<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use Doctrine\DBAL\ArrayParameterType;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\PendingStore;
use Lizard\Typo3ToTypo3\Link\RteLinks;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Backend\Form\Exception\AccessDeniedException;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/** Permission filtering happens here, before destination lookup or any output, including direct actions. */
final class LinkReport
{
    public const MODULE = 'exchange_links';

    public function __construct(
        private readonly ConnectionPool $connections,
        private readonly DestinationStore $destinations,
        private readonly PeerConfiguration $configuration,
        private readonly DatabaseUserPermissionCheck $permissions,
        private readonly TcaSchemaFactory $schemas,
        private readonly TypoLinkCodecService $codec,
    ) {}

    public function allowed(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user && !empty($user->user['uid']) && ($user->isAdmin() || $user->check('modules', self::MODULE));
    }

    /** Bounded pages of tracked fields. No content-table scans and no unfiltered total counts. */
    public function page(int $offset = 0, ?string $table = null, ?int $uid = null): array
    {
        if (!$this->allowed()) {
            return ['rows' => [], 'more' => false];
        }
        $user = $GLOBALS['BE_USER'];
        $tables = array_values(array_filter(array_keys($GLOBALS['TCA']),
            static fn($name) => $user->isAdmin() || $user->check('tables_modify', $name)));
        if (!$tables || ($table !== null && !in_array($table, $tables, true))) {
            return ['rows' => [], 'more' => false];
        }
        $sql = 'SELECT * FROM ' . PendingStore::TABLE . ' WHERE workspace_id = ? AND table_name IN (?)';
        $args = [$user->workspace, $tables];
        $types = [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::STRING];
        if ($table !== null) {
            $sql .= ' AND table_name = ? AND record_uid = ?';
            $args[] = $table;
            $args[] = $uid;
            $types[] = \Doctrine\DBAL\ParameterType::STRING;
            $types[] = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        $jobs = $this->connections->getConnectionForTable(PendingStore::TABLE)->executeQuery(
            $sql . ' ORDER BY source_key LIMIT 101 OFFSET ' . max(0, min(1000000, $offset)), $args, $types,
        )->fetchAllAssociative();
        $rows = [];
        foreach (array_slice($jobs, 0, 100) as $job) {
            $record = $this->authorizedRecord($job);
            if ($record !== null) {
                array_push($rows, ...$this->describe($job, $record));
            }
        }
        return ['rows' => $rows, 'more' => count($jobs) > 100];
    }

    /** Requeue only the exact version inspected. The scheduled worker retains all write-side guards. */
    public function retry(string $key, string $generation): bool
    {
        if (!$this->allowed() || !preg_match('/^[a-f0-9]{64}$/D', $key) || !preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            return false;
        }
        $db = $this->connections->getConnectionForTable(PendingStore::TABLE);
        $job = $db->select(['*'], PendingStore::TABLE, ['source_key' => $key, 'generation' => $generation])->fetchAssociative();
        $record = $job ? $this->authorizedRecord($job) : null;
        if (!$record || !hash_equals($job['record_hash'], PendingStore::fingerprint($record))) {
            return false;
        }
        return $db->executeStatement('UPDATE ' . PendingStore::TABLE
            . " SET job_status = 'pending', next_attempt = ?, created_at = ?, attempts = 0, generation = ?, lease_token = '', lease_until = 0"
            . " WHERE source_key = ? AND generation = ? AND job_status IN ('pending', 'attention') AND lease_until < ?",
            [time(), time(), bin2hex(random_bytes(16)), $key, $generation, time()],
        ) === 1;
    }

    public function resume(string $peerId): bool
    {
        if (!$this->allowed() || !$GLOBALS['BE_USER']->isAdmin()) {
            return false;
        }
        try {
            $peer = $this->configuration->load()['outgoing'][$peerId] ?? null;
            if (!is_array($peer) || ($peer['enabled'] ?? false) !== true || !PeerConfiguration::isUuid($peer['instance'] ?? null)) {
                return false;
            }
            $this->destinations->resume($peer['instance'], '', true);
            return true;
        } catch (\RuntimeException|\JsonException) {
            return false;
        }
    }

    /** Only administrators may see connection-wide state. Never return configuration or raw errors. */
    public function connections(): array
    {
        if (!$this->allowed() || !$GLOBALS['BE_USER']->isAdmin()) {
            return [];
        }
        try {
            $peers = $this->configuration->load()['outgoing'] ?? [];
        } catch (\RuntimeException|\JsonException) {
            return [['peer' => '', 'reason' => Labels::text('connection.invalid'), 'retry' => false]];
        }
        $problems = [];
        foreach ($peers as $id => $peer) {
            if (!is_array($peer) || !PeerConfiguration::isUuid($peer['instance'] ?? null)) {
                $problems[] = ['peer' => (string)$id, 'reason' => Labels::text('connection.identity'), 'retry' => false];
                continue;
            }
            $counts = $this->connections->getConnectionForTable(DestinationStore::TABLE)->executeQuery(
                'SELECT status, COUNT(*) AS total FROM ' . DestinationStore::TABLE
                . " WHERE instance_uuid = ? AND (status = 'denied' OR refresh_paused = 1 OR attempts >= 3) GROUP BY status", [$peer['instance']],
            )->fetchAllAssociative();
            if ($counts) {
                $problems[] = ['peer' => (string)$id,
                    'reason' => Labels::text('connection.failure', [array_sum(array_column($counts, 'total'))]),
                    'retry' => ($peer['enabled'] ?? false) === true];
            }
        }
        $initialFailures = (int)$this->connections->getConnectionForTable(PendingStore::TABLE)->executeQuery(
            'SELECT COUNT(*) FROM ' . PendingStore::TABLE
            . " WHERE job_status IN ('pending', 'attention') AND (status = 'denied' OR (status = 'pending' AND attempts >= 3))",
        )->fetchOne();
        if ($initialFailures > 0) {
            $problems[] = ['peer' => '', 'reason' => Labels::text('connection.initialFailure', [$initialFailures]), 'retry' => false];
        }
        return $problems;
    }

    private function authorizedRecord(array $job): ?array
    {
        $user = $GLOBALS['BE_USER'];
        $table = $job['table_name'];
        $field = $job['field_name'];
        if (!$this->schemas->has($table) || !isset($GLOBALS['TCA'][$table]['columns'][$field])
            || (int)$job['workspace_id'] !== $user->workspace
            || (!$user->isAdmin() && (!$user->check('tables_modify', $table) || !empty($GLOBALS['TCA'][$table]['ctrl']['adminOnly'])))
        ) {
            return null;
        }
        $record = BackendUtility::getRecord($table, (int)$job['record_uid'], '*', '', false);
        $deleted = $GLOBALS['TCA'][$table]['ctrl']['delete'] ?? null;
        if (!$record || ($deleted && !empty($record[$deleted])) || (int)($record['t3ver_wsid'] ?? 0) !== $user->workspace
            || !is_string($record[$field] ?? null) || !hash_equals($job['value_hash'], hash('sha256', $record[$field]))) {
            return null;
        }
        if (!$user->checkWorkspace($user->workspace) || ($user->workspace > 0 && !$user->workspaceCheckStageForCurrent((int)($record['t3ver_stage'] ?? 0)))) {
            return null;
        }
        $schema = $this->schemas->get($table);
        $type = BackendUtility::getTCAtypeValue($table, $record);
        $fieldSchema = $schema->hasSubSchema($type) && $schema->getSubSchema($type)->hasField($field)
            ? $schema->getSubSchema($type)->getField($field) : $schema->getField($field);
        $config = $fieldSchema->getConfiguration();
        if (!in_array($config['type'] ?? '', ['link', 'text'], true)
            || !empty($config['readOnly']) || !empty($GLOBALS['TCA'][$table]['ctrl']['readOnly'])
            || (!$user->isAdmin() && !empty($GLOBALS['TCA'][$table]['columns'][$field]['exclude']) && !$user->check('non_exclude_fields', $table . ':' . $field))) {
            return null;
        }
        $pageId = $table === 'pages' ? (int)($record['t3ver_oid'] ?: ($record['l10n_parent'] ?: $record['uid'])) : (int)$record['pid'];
        if (!$user->isAdmin() && ($pageId <= 0 || !$user->isInWebMount($pageId))) {
            return null;
        }
        $page = BackendUtility::getRecord('pages', $pageId);
        try {
            // Reuse the core permission gate, including language/auth-mode/edit-lock checks and extension events.
            $this->permissions->addData(['command' => 'edit', 'tableName' => $table, 'databaseRow' => $record,
                'parentPageRow' => $page, 'defaultLanguagePageRow' => $table === 'pages' ? $page : null,
                'processedTca' => $GLOBALS['TCA'][$table], 'tcaSchemata' => $this->schemas->all()]);
        } catch (AccessDeniedException) {
            return null;
        }
        return $record;
    }

    private function describe(array $job, array $record): array
    {
        $base = ['key' => $job['source_key'], 'generation' => $job['generation'], 'table' => $job['table_name'],
            'uid' => (int)$job['record_uid'], 'field' => $job['field_name'], 'workspace' => (int)$job['workspace_id'],
            'retry' => in_array($job['job_status'], ['pending', 'attention'], true) && (int)$job['lease_until'] < time()
                && hash_equals($job['record_hash'], PendingStore::fingerprint($record)),
            'checked' => (int)$job['checked_at'], 'next' => (int)$job['next_attempt']];
        $value = $record[$job['field_name']];
        $links = ($GLOBALS['TCA'][$job['table_name']]['columns'][$job['field_name']]['config']['type'] ?? '') === 'link'
            ? [$this->codec->decode($value)] : RteLinks::anchors($value);
        $origins = [];
        foreach ($links as $link) {
            try { $origins[] = PeerConfiguration::origin($link['url']); } catch (\InvalidArgumentException) {}
        }
        $sourceDestination = $origins ? implode(', ', array_slice(array_unique($origins), 0, 10)) : Labels::text('destination.original');
        $rows = [];
        if (in_array($job['job_status'], ['pending', 'attention'], true) || !in_array($job['status'], ['managed', 'resolved', 'ordinary'], true)) {
            $status = $job['job_status'] === 'attention' && $job['status'] === 'pending' ? 'expired' : $job['status'];
            $rows[] = $base + ['status' => $status, 'reason' => self::reason($status), 'destination' => $sourceDestination];
        }
        $seen = [];
        foreach ($links as $link) {
            if (!str_starts_with($link['url'], 't3://exchange?')) {
                continue;
            }
            parse_str((string)parse_url($link['url'], PHP_URL_QUERY), $parameters);
            $reference = (new ManagedLink())->resolveHandlerData($parameters);
            if (!$reference['valid']) {
                $rows[] = array_replace($base, ['status' => 'invalid', 'reason' => self::reason('invalid'), 'destination' => Labels::text('destination.invalid'), 'retry' => false]);
                continue;
            }
            $key = ManagedLink::key($reference);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $destination = $this->destinations->find($reference);
            $status = $destination['status'] ?? 'missing';
            if ($status === 'resolved') { continue; }
            // Unavailable/denied destination URLs are deliberately not exposed, even if formerly public.
            $rows[] = array_replace($base, ['status' => $status, 'reason' => self::reason($status), 'retry' => false,
                'destination' => Labels::text('destination.page', [$reference['page'], $reference['language']]),
                'checked' => (int)($destination['checked_at'] ?? 0), 'next' => (int)($destination['next_refresh'] ?? 0)]);
        }
        return $rows;
    }

    private static function reason(string $status): string
    {
        $key = match ($status) {
            'pending', 'stale', 'unavailable', 'denied', 'expired', 'unsupported', 'too_long', 'disabled', 'missing' => $status,
            'database', 'persistence', 'permissions' => 'attention',
            default => 'invalid',
        };
        return Labels::text('reason.' . $key);
    }
}
