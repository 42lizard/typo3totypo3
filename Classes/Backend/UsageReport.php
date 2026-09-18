<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class UsageReport
{
    public function __construct(private readonly ConnectionPool $connections, private readonly LinkReport $links) {}

    public function warning(int $pageId): ?string
    {
        $report = $this->page($pageId, true);
        if (!$report['rows'] && !$report['incomplete']) { return null; }
        $message = $report['rows'] ? Labels::text('usage.actionWarning') . ' ' . Labels::text('usage.warningSummary',
            [count($report['rows']), count(array_filter($report['rows'], static fn(array $row): bool => $row['stale']))]) : '';
        if ($report['incomplete'] || $report['more']) { $message .= ' ' . Labels::text('usage.incompleteImpact'); }
        return trim($message);
    }

    public function page(int $pageId, bool $descendants = false, int $offset = 0): array
    {
        $empty = ['rows' => [], 'more' => false, 'incomplete' => false];
        if (!$this->links->allowed() || $pageId < 1) { return $empty; }
        $user = $GLOBALS['BE_USER'];
        $languageClause = '';
        if (!$user->isAdmin() && trim($user->groupData['allowed_languages'] ?? '') !== '') {
            $languages = array_filter(array_map('intval', explode(',', $user->groupData['allowed_languages'])), $user->checkLanguageAccess(...));
            $languageClause = $languages ? ' AND u.language_id IN (' . implode(',', $languages) . ')' : ' AND 1=0';
        }
        $pages = $this->connections->getConnectionForTable('pages');
        $db = $this->connections->getConnectionForTable(UsageRegistry::TABLE);
        $pending = [$pageId];
        $seen = [];
        $rows = [];
        $incomplete = false;
        try {
            while ($pending && count($seen) < 1000) {
                $uid = array_shift($pending);
                if (isset($seen[$uid])) { continue; }
                $seen[$uid] = true;
                $page = $pages->select(['*'], 'pages', ['uid' => $uid])->fetchAssociative();
                if (!$page || (int)$page['deleted'] || (int)$page['t3ver_wsid'] !== 0
                    || (!$user->isAdmin() && (!$user->isInWebMount($uid) || !$user->doesUserHaveAccess($page, 1)))) { continue; }
                $identity = $this->connections->getConnectionForTable('tx_typo3totypo3_identity')->select(['uuid'], 'tx_typo3totypo3_identity', ['page_uid' => $uid])->fetchOne();
                if ($identity !== false) {
                    $usages = $db->executeQuery('SELECT u.*, p.peer_name, p.environment_uuid FROM ' . UsageRegistry::TABLE
                        . ' u JOIN tx_typo3totypo3_usage_pair p ON p.scope_key = u.scope_key WHERE u.page_uuid = ? AND u.present = 1'
                        . $languageClause . ' ORDER BY u.language_id, p.peer_name, u.scope_key LIMIT 1001', [$identity])->fetchAllAssociative();
                    $incomplete = $incomplete || count($usages) > 1000;
                    $usages = array_slice($usages, 0, 1000);
                    foreach ($usages as $usage) {
                        if (!$user->checkLanguageAccess((int)$usage['language_id'])) { continue; }
                        $rows[] = ['scope' => $usage['scope_key'], 'page' => $identity, 'uid' => $uid, 'language' => (int)$usage['language_id'],
                            'title' => $page['title'], 'peer' => $usage['peer_name'] ?: Labels::text('usage.formerPeer'),
                            'reported' => (int)$usage['reported_at'], 'reportedLabel' => gmdate('Y-m-d H:i:s', (int)$usage['reported_at']) . ' UTC',
                            'stale' => (int)$usage['reported_at'] < time() - 172800,
                            'stateLabel' => Labels::text((int)$usage['reported_at'] < time() - 172800 ? 'usage.stale' : 'usage.current')];
                    }
                }
                if ($descendants) {
                    $children = $pages->executeQuery('SELECT uid FROM pages WHERE pid = ? AND deleted = 0 AND t3ver_wsid = 0 AND sys_language_uid = 0 AND ('
                        . $user->getPagePermsClause(1) . ') ORDER BY uid LIMIT 1001', [$uid])->fetchFirstColumn();
                    $incomplete = $incomplete || count($children) > 1000;
                    foreach (array_slice($children, 0, 1000) as $child) { $pending[] = (int)$child; }
                }
                if (count($rows) > $offset + 100) { break; }
            }
        } catch (\Doctrine\DBAL\Exception) {
            return array_replace($empty, ['incomplete' => true]);
        }
        return ['rows' => array_slice($rows, max(0, $offset), 100), 'more' => count($rows) > $offset + 100,
            'incomplete' => $incomplete || ($pending && count($seen) >= 1000)];
    }

    public function forget(array $body): bool
    {
        if (!$this->links->allowed() || !$GLOBALS['BE_USER']->isAdmin() || ($body['confirmed'] ?? '') !== '1'
            || !is_string($body['scope'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['scope'])
            || !is_string($body['page'] ?? null) || !\Lizard\Typo3ToTypo3\PeerConfiguration::isUuid($body['page'])) { return false; }
        $language = filter_var($body['language'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $reported = filter_var($body['reported'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($language === false || $reported === false || $reported >= time() - 172800) { return false; }
        $db = $this->connections->getConnectionForTable(UsageRegistry::TABLE);
        if ($db !== $this->connections->getConnectionForTable('tx_typo3totypo3_usage_audit')) { return false; }
        return $db->transactional(function () use ($db, $body, $language, $reported): bool {
            $key = ['scope_key' => $body['scope'], 'page_uuid' => $body['page'], 'language_id' => $language, 'reported_at' => $reported, 'present' => 1];
            if ($db->update(UsageRegistry::TABLE, ['present' => 0], $key) !== 1) { return false; }
            $db->insert('tx_typo3totypo3_usage_audit', ['event_key' => bin2hex(random_bytes(16)), 'scope_key' => $body['scope'],
                'page_uuid' => $body['page'], 'language_id' => $language, 'actor_uid' => (int)$GLOBALS['BE_USER']->user['uid'], 'created_at' => time()]);
            return true;
        });
    }
}
