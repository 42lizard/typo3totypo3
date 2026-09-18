<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Backend\UsageReport;
use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use Lizard\Typo3ToTypo3\PageIdentity;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UsageReportTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testForbiddenLanguagesCannotAffectVisibleLimits(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(2);
        $GLOBALS['BE_USER']->groupData['allowed_languages'] = '2';
        $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable(UsageRegistry::TABLE);
        $page = (new PageIdentity($this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)))->forPage(1);
        for ($n = 0; $n < 1001; ++$n) {
            $scope = hash('sha256', 'hidden consumer ' . $n);
            $db->insert('tx_typo3totypo3_usage_pair', ['scope_key' => $scope, 'peer_name' => 'Hidden']);
            $db->insert(UsageRegistry::TABLE, ['scope_key' => $scope, 'page_uuid' => $page, 'language_id' => 1, 'present' => 1]);
        }
        $db->insert(UsageRegistry::TABLE, ['scope_key' => $scope, 'page_uuid' => $page, 'language_id' => 2, 'present' => 1]);
        $report = $this->get(UsageReport::class)->page(1);
        self::assertCount(1, $report['rows']);
        self::assertSame(2, $report['rows'][0]['language']);
        self::assertFalse($report['incomplete']);
        self::assertFalse($report['more']);
    }

    public function testAnIncompleteImpactCheckIsNotPresentedAsNoUsage(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(1);
        $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('pages');
        $rows = [];
        for ($uid = 10; $uid < 1011; ++$uid) { $rows[] = [$uid, 1, 'Child']; }
        $db->bulkInsert('pages', $rows, ['uid', 'pid', 'title']);
        $report = $this->get(UsageReport::class);
        self::assertTrue($report->page(1, true)['incomplete']);
        self::assertStringContainsString('impact check is incomplete', $report->warning(1));
    }

    public function testPageActionsWarnAboutReportedUsageWithoutAddingForbiddenActions(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(1);
        $scope = hash('sha256', 'context menu consumer');
        $registry = $this->get(UsageRegistry::class);
        $registry->rememberScope($scope, ['name' => 'Consumer', 'instance' => PeerConfiguration::uuid(), 'environment' => PeerConfiguration::uuid()]);
        $identity = new PageIdentity($this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class));
        $registry->report($scope, [['page' => $identity->forPage(3), 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        $provider = new \Lizard\Typo3ToTypo3\Backend\UsageContextMenu($this->get(UsageReport::class), $this->get(\TYPO3\CMS\Backend\Routing\UriBuilder::class));
        $provider->setContext('pages', '1');
        $items = $provider->addItems([
            'delete' => ['additionalAttributes' => ['data-message' => 'Delete page?']],
            'disable' => ['additionalAttributes' => []],
        ]);
        self::assertStringContainsString('Other instances', $items['delete']['additionalAttributes']['data-message']);
        self::assertStringStartsWith('Delete page?', $items['delete']['additionalAttributes']['data-message']);
        self::assertSame('@lizard/typo3-to-typo3/usage-actions', $items['disable']['additionalAttributes']['data-callback-module']);
        self::assertStringContainsString('view=usage', $items['disable']['additionalAttributes']['data-message']);
        self::assertSame([], $provider->addItems([]));
        $provider->setContext('pages', '2');
        self::assertSame(['delete' => []], $provider->addItems(['delete' => []]));
    }

    public function testUsageViewDoesNotExposeForbiddenPagesOrLanguages(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(2);
        $scope = hash('sha256', 'consumer environment');
        $registry = $this->get(UsageRegistry::class);
        $registry->rememberScope($scope, ['name' => 'Public website production', 'instance' => PeerConfiguration::uuid(), 'environment' => PeerConfiguration::uuid()]);
        $identities = new PageIdentity($this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class));
        $registry->report($scope, [
            ['page' => $identities->forPage(1), 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main'],
            ['page' => $identities->forPage(1), 'language' => 1, 'revision' => 1, 'present' => true, 'site' => 'main'],
            ['page' => $identities->forPage(2), 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main'],
        ]);
        $report = $this->get(UsageReport::class);
        $rows = $report->page(1)['rows'];
        self::assertCount(1, $rows);
        self::assertSame('Public website production', $rows[0]['peer']);
        self::assertSame('Allowed', $rows[0]['title']);
        self::assertSame([], $report->page(2)['rows']);
        $GLOBALS['BE_USER']->groupData['modules'] = '';
        self::assertSame([], $report->page(1)['rows']);
    }
}
