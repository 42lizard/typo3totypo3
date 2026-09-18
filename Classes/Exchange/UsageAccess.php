<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Lizard\Typo3ToTypo3\PageIdentity;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/** Site authorization for an already registered usage, including deleted destinations. */
final class UsageAccess
{
    public function __construct(private readonly ConnectionPool $connections, private readonly PageIdentity $identities, private readonly SiteFinder $sites) {}

    public function site(array $usage, array $grant): ?string
    {
        $site = $usage['site_identifier'];
        $pageId = $this->identities->findPage($usage['page_uuid']);
        $page = $pageId === null ? false : $this->connections->getConnectionForTable('pages')->select(['deleted'], 'pages', ['uid' => $pageId])->fetchAssociative();
        if ($page && !(int)$page['deleted']) {
            try { $site = $this->sites->getSiteByPageId($pageId)->getIdentifier(); }
            catch (SiteNotFoundException) { return null; }
        }
        return in_array($site, $grant['sites'], true) ? $site : null;
    }
}
