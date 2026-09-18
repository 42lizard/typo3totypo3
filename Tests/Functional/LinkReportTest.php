<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Backend\ConnectionToolbar;
use Lizard\Typo3ToTypo3\Backend\EditWarnings;
use Lizard\Typo3ToTypo3\Backend\Labels;
use Lizard\Typo3ToTypo3\Backend\LinkReport;
use Lizard\Typo3ToTypo3\Backend\ReportController;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\PendingStore;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LinkReportTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];
    private LinkReport $report;
    private string $previousConfig;
    private array $reference;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $this->report = $this->get(LinkReport::class);
        $this->previousConfig = getenv('TYPO3_EXCHANGE_CONFIG') ?: '';
        $path = $this->getInstancePath() . '/peer-test.json';
        $this->reference = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        file_put_contents($path, json_encode(['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'outgoing' => ['peer' => [
            'enabled' => true, 'instance' => $this->reference['instance'], 'token' => str_repeat('a', 64),
            'endpoint' => 'https://peer.example/typo3-exchange/v1/resolve', 'origins' => ['https://peer.example'],
        ]]], JSON_THROW_ON_ERROR));
        putenv('TYPO3_EXCHANGE_CONFIG=' . $path);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () {
            throw new \LogicException('Backend report must never perform HTTP');
        }];
    }

    protected function tearDown(): void
    {
        putenv('TYPO3_EXCHANGE_CONFIG=' . $this->previousConfig);
        parent::tearDown();
    }

    private function fixture(array $values = [], string $status = 'pending', string $field = 'header_link'): int
    {
        $db = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $db->insert('tt_content', array_replace(['pid' => 1, 'CType' => 'header', 'header' => 'Report fixture',
            'header_link' => 'https://peer.example/path?secret_token=DO_NOT_EXPOSE#private'], $values));
        $uid = (int)$db->lastInsertId();
        $row = $db->select(['*'], 'tt_content', ['uid' => $uid])->fetchAssociative();
        $this->get(PendingStore::class)->record('tt_content', $uid, $field, $row, ['status' => $status]);
        return $uid;
    }

    private function rows(int $uid): array
    {
        return $this->report->page(0, 'tt_content', $uid)['rows'];
    }

    private function job(int $uid): array
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(PendingStore::TABLE)
            ->select(['*'], PendingStore::TABLE, ['source_key' => PendingStore::key('tt_content', $uid, 'header_link')])->fetchAssociative();
    }

    #[Test]
    public function editorSeesOnlyPermittedRecordsAndSafeDestinationDetails(): void
    {
        $allowed = $this->fixture();
        $forbidden = $this->fixture(['pid' => 2]);
        $translated = $this->fixture(['sys_language_uid' => 2]);
        $noEdit = $this->fixture(['pid' => 3]);
        self::assertCount(1, $this->rows($allowed));
        self::assertSame([], $this->rows($forbidden));
        self::assertSame([], $this->rows($translated));
        self::assertSame([], $this->rows($noEdit));
        self::assertSame('https://peer.example:443', $this->rows($allowed)[0]['destination']);
        self::assertStringNotContainsString('DO_NOT_EXPOSE', json_encode($this->rows($allowed)));
        $job = $this->job($forbidden);
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
        self::assertSame([], $this->report->connections());
        self::assertFalse($this->report->resume('peer'));
    }

    public static function revokedPermissions(): array
    {
        return [['modules'], ['tables_modify'], ['non_exclude_fields'], ['webmounts']];
    }

    #[Test]
    #[DataProvider('revokedPermissions')]
    public function permissionsApplyToQueriesAndDirectRetry(string $permission): void
    {
        $uid = $this->fixture();
        $job = $this->job($uid);
        $GLOBALS['BE_USER']->groupData[$permission] = '';
        self::assertSame([], $this->rows($uid));
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
    }

    #[Test]
    public function retryPreservesGenerationsLeasesAndNewerEdits(): void
    {
        $uid = $this->fixture([], 'expired');
        self::assertSame('expired', $this->rows($uid)[0]['status']);
        $job = $this->job($uid);
        self::assertTrue($this->report->retry($job['source_key'], $job['generation']));
        self::assertSame('pending', $this->job($uid)['job_status']);
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
        $job = $this->job($uid);
        $db = $this->get(ConnectionPool::class)->getConnectionForTable(PendingStore::TABLE);
        $db->update(PendingStore::TABLE, ['lease_until' => time() + 120], ['source_key' => $job['source_key']]);
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
        $db->update(PendingStore::TABLE, ['lease_until' => 0], ['source_key' => $job['source_key']]);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'New edit'], ['uid' => $uid]);
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
        self::assertFalse($this->rows($uid)[0]['retry']);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['header_link' => 'https://changed.example/'], ['uid' => $uid]);
        self::assertSame([], $this->rows($uid));
    }

    #[Test]
    public function workspaceMembershipAndVersionAreEnforced(): void
    {
        $live = $this->fixture();
        $draft = $this->fixture(['t3ver_wsid' => 1, 't3ver_oid' => $live]);
        self::assertSame([], $this->rows($draft));
        self::assertTrue($GLOBALS['BE_USER']->setTemporaryWorkspace(1));
        self::assertCount(1, $this->rows($draft));
        self::assertSame([], $this->rows($live));
        $job = $this->job($draft);
        self::assertTrue($this->report->retry($job['source_key'], $job['generation']));
        $this->setUpBackendUser(3)->workspace = 1;
        self::assertSame([], $this->rows($draft));
        self::assertFalse($this->report->retry($job['source_key'], $job['generation']));
    }

    #[Test]
    public function overviewIncludesHealthyLinksAndFiltersWithoutLeakingForbiddenRecords(): void
    {
        $link = (new ManagedLink())->asString($this->reference);
        $healthy = $this->fixture(['header_link' => $link], 'managed');
        $forbidden = $this->fixture(['pid' => 2, 'header_link' => $link], 'managed');
        $pending = $this->fixture();
        $store = $this->get(DestinationStore::class);
        $store->record($this->reference, 'resolved', 'https://peer.example/healthy');
        self::assertCount(2, $this->report->page(filter: 'all')['rows']);
        self::assertSame([$healthy], array_column($this->report->page(filter: 'resolved')['rows'], 'uid'));
        self::assertSame([$pending], array_column($this->report->page(filter: 'problems')['rows'], 'uid'));
        self::assertSame([], $this->rows($healthy), 'Healthy links must not trigger edit warnings');
        self::assertSame([], $this->report->page(0, 'tt_content', $forbidden, 'all')['rows']);
        self::assertFalse($this->report->page(filter: 'resolved')['rows'][0]['retry']);
        $controller = $this->get(ReportController::class);
        $html = (string)$controller->handleRequest($this->request()->withQueryParams(['status' => 'resolved']))->getBody();
        self::assertTrue(str_contains($html, 'Healthy'));
        self::assertTrue(str_contains($html, 'https://peer.example/healthy'), 'Verified readable destination is displayed');
        self::assertTrue(str_contains($html, 'name="token"'), 'GET filter preserves the core route token');
        self::assertTrue(str_contains($html, 'value="resolved" selected="selected"'));
        self::assertFalse(str_contains($html, 'value="all" selected="selected"'));
        self::assertTrue(str_contains($html, 'record/edit') || str_contains($html, 'record_edit'), 'Source opens the core editor');
        $store->record($this->reference, 'denied');
        self::assertSame([], $this->report->page(filter: 'resolved')['rows']);
        self::assertSame([$healthy], array_column($this->report->page(filter: 'denied')['rows'], 'uid'));
        self::assertStringNotContainsString('/healthy', json_encode($this->report->page(filter: 'all')));
        $store->record($this->reference, 'resolved', 'https://peer.example/recovered');
        self::assertSame([$healthy], array_column($this->report->page(filter: 'resolved')['rows'], 'uid'));
        self::assertSame('https://peer.example/recovered', $this->report->page(filter: 'resolved')['rows'][0]['readableUrl']);
        $store->record($this->reference, 'unavailable');
        self::assertSame('', $this->report->page(filter: 'unavailable')['rows'][0]['readableUrl']);
    }

    #[Test]
    public function mixedRteDestinationsHaveSeparateStatesWithoutPrivateUrls(): void
    {
        $other = array_replace($this->reference, ['page' => PeerConfiguration::uuid(), 'language' => 2]);
        $store = $this->get(DestinationStore::class);
        $store->record($this->reference, 'resolved', 'https://peer.example/private-now');
        $store->record($this->reference, 'unavailable');
        $store->record($other, 'resolved', 'https://peer.example/old');
        $store->record($other, 'stale');
        $html = '<a href="' . htmlspecialchars((new ManagedLink())->asString($this->reference)) . '">A</a>'
            . '<a href="' . htmlspecialchars((new ManagedLink())->asString($other)) . '">B</a>';
        $uid = $this->fixture(['CType' => 'text', 'bodytext' => $html], 'managed', 'bodytext');
        self::assertSame(['unavailable', 'stale'], array_column($this->rows($uid), 'status'));
        self::assertStringNotContainsString('private-now', json_encode($this->rows($uid)));
        $store->record($other, 'denied');
        self::assertSame('denied', $this->rows($uid)[1]['status']);
    }

    #[Test]
    public function editWarningsAreConsolidatedAndPermissionFiltered(): void
    {
        $uid = $this->fixture();
        $hidden = $this->fixture(['pid' => 2]);
        $warnings = $this->get(EditWarnings::class);
        $data = ['command' => 'edit', 'tableName' => 'tt_content', 'databaseRow' => ['uid' => $uid]];
        $warnings->addData($data);
        $warnings->addData($data);
        $warnings->addData(array_replace($data, ['databaseRow' => ['uid' => $hidden]]));
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
        self::assertCount(1, $messages);
        self::assertStringContainsString('header_link', $messages[0]->getMessage());
    }

    #[Test]
    public function administratorCanRequeueDeniedConnectionsWithoutRestoringClickability(): void
    {
        $this->setUpBackendUser(1);
        $store = $this->get(DestinationStore::class);
        $store->record($this->reference, 'resolved', 'https://peer.example/old');
        $store->deny($this->reference['instance'], 'hash');
        self::assertCount(1, $this->report->connections());
        self::assertTrue($this->get(ConnectionToolbar::class)->checkAccess());
        self::assertTrue($this->report->resume('peer'));
        self::assertSame('denied', $store->find($this->reference)['status']);
        self::assertSame(0, (int)$store->find($this->reference)['refresh_paused']);
        self::assertTrue($this->get(ConnectionToolbar::class)->checkAccess());
        self::assertStringNotContainsString(str_repeat('a', 64), json_encode($this->report->connections()));
        $store->record($this->reference, 'resolved', 'https://peer.example/restored');
        self::assertSame([], $this->report->connections());
        self::assertFalse($this->get(ConnectionToolbar::class)->checkAccess());
    }

    #[Test]
    public function savingMultipleFailingFieldsProducesOneLocalizedWarningAndKeepsContent(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->user['lang'] = 'de';
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        $uid = $this->fixture(['CType' => 'text']);
        $url = 'https://peer.example/unresolved';
        $body = '<p><a href="' . $url . '">Original</a></p>';
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () {
            throw new \RuntimeException('Transport failed');
        }];
        $handler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $handler->start(['tt_content' => [$uid => ['header_link' => $url, 'bodytext' => $body]]], []);
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);
        $row = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->select(['header_link', 'bodytext'], 'tt_content', ['uid' => $uid])->fetchAssociative();
        self::assertSame(['header_link' => $url, 'bodytext' => $body], $row);
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
        self::assertCount(1, $messages);
        self::assertStringContainsString('ursprünglichen Werte', $messages[0]->getMessage());
        self::assertStringContainsString('header_link', $messages[0]->getMessage());
        self::assertStringContainsString('bodytext', $messages[0]->getMessage());
    }

    #[Test]
    public function everyLabelHasEnglishAndGermanTextWithMatchingPlaceholders(): void
    {
        $file = dirname(__DIR__, 2) . '/Resources/Private/Language/locallang.xlf';
        $english = simplexml_load_file($file);
        $german = simplexml_load_file(dirname($file) . '/de.locallang.xlf');
        $translations = [];
        foreach ($german->file->body->{'trans-unit'} as $unit) {
            $translations[(string)$unit['id']] = (string)$unit->target;
        }
        foreach ($english->file->body->{'trans-unit'} as $unit) {
            $id = (string)$unit['id'];
            self::assertNotSame('', (string)$unit->source, $id);
            self::assertNotEmpty($translations[$id] ?? '', $id);
            self::assertSame(substr_count((string)$unit->source, '%s'), substr_count($translations[$id], '%s'), $id);
        }
        self::assertCount(count($english->file->body->{'trans-unit'}), $translations);
    }

    #[Test]
    public function initialConnectionFailuresNotifyAdministratorsWithoutFlashSpam(): void
    {
        $this->fixture([], 'denied');
        self::assertFalse($this->get(ConnectionToolbar::class)->checkAccess());
        $this->setUpBackendUser(1);
        self::assertTrue($this->get(ConnectionToolbar::class)->checkAccess());
        self::assertTrue($this->get(ConnectionToolbar::class)->checkAccess());
        self::assertSame([], $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush());
    }

    private function request(string $method = 'GET'): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/exchange/links', $method, 'php://input', [], [
            'HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'SERVER_PORT' => '443',
            'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => $this->getInstancePath() . '/typo3/index.php',
        ]))
            ->withAttribute('applicationType', 2)
            ->withAttribute('route', new Route('/module/exchange/links', ['packageName' => '42lizard/typo3-to-typo3', '_identifier' => LinkReport::MODULE]));
        $request = $request->withAttribute('normalizedParams', \TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    #[Test]
    public function usageViewIsLocalizedAndForgetRequiresAdminConfirmationAndAnUnchangedStaleReport(): void
    {
        $registry = $this->get(\Lizard\Typo3ToTypo3\Exchange\UsageRegistry::class);
        $scope = hash('sha256', 'backend usage');
        $registry->rememberScope($scope, ['name' => 'Portal-production', 'instance' => PeerConfiguration::uuid(), 'environment' => PeerConfiguration::uuid()]);
        $page = (new \Lizard\Typo3ToTypo3\PageIdentity($this->get(ConnectionPool::class)))->forPage(1);
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        $db = $this->get(ConnectionPool::class)->getConnectionForTable('tx_typo3totypo3_usage');
        $reported = time() - 172801;
        $db->update('tx_typo3totypo3_usage', ['reported_at' => $reported], ['scope_key' => $scope]);
        $controller = $this->get(ReportController::class);
        $request = $this->request()->withQueryParams(['view' => 'usage', 'id' => '1']);
        $html = (string)$controller->handleRequest($request)->getBody();
        self::assertStringContainsString('Used by other instances', $html);
        self::assertStringContainsString('Portal-production', $html);
        self::assertStringContainsString('UTC', $html);
        $editRequest = $this->request()->withQueryParams(['edit' => ['pages' => [1 => 'edit']]]);
        $GLOBALS['TYPO3_REQUEST'] = $editRequest;
        $bar = $this->get(\TYPO3\CMS\Backend\Template\ModuleTemplateFactory::class)->create($editRequest)->getDocHeaderComponent()->getButtonBar();
        $buttons = $bar->getButtons($editRequest);
        self::assertStringContainsString('view=usage', $buttons['right'][30][0]->getHref());
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        self::assertStringContainsString('Von anderen Instanzen verwendet', (string)$controller->handleRequest($request)->getBody());
        $post = $this->request('POST');
        $body = ['action' => 'forgetUsage', 'scope' => $scope, 'page' => $page, 'language' => '0', 'reported' => (string)$reported,
            'confirmed' => '1', 'csrf' => $this->get(FormProtectionFactory::class)->createFromRequest($post)->generateToken('exchange-report')];
        self::assertSame(403, $controller->handleRequest($post->withParsedBody($body))->getStatusCode());
        $this->setUpBackendUser(1);
        $post = $this->request('POST');
        $body['csrf'] = $this->get(FormProtectionFactory::class)->createFromRequest($post)->generateToken('exchange-report');
        self::assertSame(403, $controller->handleRequest($post->withParsedBody(array_replace($body, ['confirmed' => '0'])))->getStatusCode());
        $db->update('tx_typo3totypo3_usage', ['reported_at' => time()], ['scope_key' => $scope]);
        self::assertSame(403, $controller->handleRequest($post->withParsedBody($body))->getStatusCode(), 'A new report wins over an old confirmation form.');
        $db->update('tx_typo3totypo3_usage', ['reported_at' => $reported], ['scope_key' => $scope]);
        self::assertSame(303, $controller->handleRequest($post->withParsedBody($body))->getStatusCode());
        self::assertSame([], $registry->usages($scope));
        self::assertSame(1, (int)$db->count('*', 'tx_typo3totypo3_usage_audit', ['scope_key' => $scope, 'actor_uid' => 1]));
    }

    #[Test]
    public function controllerRejectsForgedActionsAndRequiresModuleAccess(): void
    {
        $uid = $this->fixture();
        $job = $this->job($uid);
        $controller = $this->get(ReportController::class);
        $request = $this->request('POST')->withParsedBody(['action' => 'retry', 'key' => $job['source_key'], 'generation' => $job['generation'], 'csrf' => 'forged']);
        self::assertSame(403, $controller->handleRequest($request)->getStatusCode());
        $form = $this->get(FormProtectionFactory::class)->createFromRequest($request);
        $request = $request->withParsedBody(['action' => 'resume', 'peer' => 'peer', 'csrf' => $form->generateToken('exchange-report')]);
        self::assertSame(403, $controller->handleRequest($request)->getStatusCode());
        $request = $request->withParsedBody(['action' => 'retry', 'key' => $job['source_key'], 'generation' => $job['generation'], 'csrf' => $form->generateToken('exchange-report')]);
        self::assertSame(303, $controller->handleRequest($request)->getStatusCode());
        $GLOBALS['BE_USER']->groupData['modules'] = '';
        self::assertSame(403, $controller->handleRequest($this->request())->getStatusCode());
    }

    #[Test]
    public function englishAndGermanLabelsAndReportRenderThroughCore(): void
    {
        $this->fixture();
        $controller = $this->get(ReportController::class);
        self::assertSame('Cross-instance links', Labels::text('module.title'));
        $english = (string)$controller->handleRequest($this->request())->getBody();
        self::assertTrue(str_contains($english, 'Queue retry'), 'English report action is translated');
        self::assertFalse(str_contains($english, 'DO_NOT_EXPOSE'), 'Report does not expose URL secrets');
        $GLOBALS['BE_USER']->user['lang'] = 'de';
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        self::assertSame('Instanzübergreifende Links', Labels::text('module.title'));
        $german = (string)$controller->handleRequest($this->request())->getBody();
        self::assertTrue(str_contains($german, 'Erneuten Versuch einplanen'), 'German report action is translated');
        self::assertFalse(str_contains($german, 'Queue retry'), 'No English action label remains in German report');
    }
}
