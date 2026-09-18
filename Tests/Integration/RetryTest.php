<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Integration;

use GuzzleHttp\Promise\Create;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use RuntimeException;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use stdClass;

final class ExchangeWriteProbe
{
    public function processDatamap_postProcessFieldArray($status, $table, $uid, &$fields, $handler): void
    {
        if (isset($GLOBALS['exchangeTestWriteProbe'])) {
            ($GLOBALS['exchangeTestWriteProbe'])('before', $table, $uid);
        }
    }
    public function processDatamap_afterDatabaseOperations($status, $table, $uid, $fields, $handler): void
    {
        if (isset($GLOBALS['exchangeTestWriteProbe'])) {
            ($GLOBALS['exchangeTestWriteProbe'])('after', $table, $uid);
        }
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RetryTest extends TestCase
{
    private int $outputBufferLevel;

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function testDelayedResolutionGuardsAndRecovery(): void
    {
        $this->outputBufferLevel = ob_get_level();
        if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing' || !in_array(getenv('DDEV_SITENAME'), ['t3exchange-v13', 't3exchange-v14'], true)) {
            throw new RuntimeException('Use only the isolated Testing-context DDEV instances.');
        }
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/vendor/bin/typo3';
        $loader = require '/var/www/html/vendor/autoload.php';
        SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($loader);
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        Bootstrap::initializeBackendAuthentication();
        $GLOBALS['LANG'] = $container->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
        $connections = $container->get(ConnectionPool::class);
        $db = $connections->getConnectionForTable('tt_content');
        $store = $container->get(DestinationStore::class);
        $codec = $container->get(TypoLinkCodecService::class);
        $config = (new PeerConfiguration())->load();
        $peer = reset($config['outgoing']);
        $origin = $peer['origins'][0];
        $fixtures = [];
        $references = [];
        $originalDestinations = $connections->getConnectionForTable(DestinationStore::TABLE)
            ->select(['*'], DestinationStore::TABLE, [])->fetchAllAssociativeIndexed();
        $handlers = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? [];
        $sitePath = '/var/www/html/var/exchange-testing/config/sites/main/config.yaml';
        $originalSite = file_get_contents($sitePath);
        $workspaceIds = [];
        $cleaned = false;
        $cleanup = static function () use (&$cleaned, &$fixtures, &$references, $connections, $db, $handlers, $originalDestinations, $sitePath, $originalSite, &$workspaceIds): void {
            if ($cleaned) {
                return;
            }
            $cleaned = true;
            file_put_contents($sitePath, $originalSite);
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $handlers;
            unset($GLOBALS['exchangeTestWriteProbe']);
            foreach ($workspaceIds as $workspaceId) {
                $connections->getConnectionForTable('sys_workspace')->delete('sys_workspace', ['uid' => $workspaceId]);
            }
            foreach ($fixtures as $uid) {
                $db->delete('tt_content', ['uid' => $uid]);
                $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->delete('tx_typo3totypo3_link_outcome', ['table_name' => 'tt_content', 'record_uid' => $uid]);
            }
            foreach ($references as $reference) {
                $key = ManagedLink::key($reference);
                $destinationConnection = $connections->getConnectionForTable(DestinationStore::TABLE);
                if (isset($originalDestinations[$key])) {
                    $destinationConnection->update(DestinationStore::TABLE, $originalDestinations[$key], ['reference_key' => $key]);
                } else {
                    $destinationConnection->delete(DestinationStore::TABLE, ['reference_key' => $key]);
                }
            }
            $container = GeneralUtility::getContainer();
            $container->get(CacheManager::class)->flushCaches();
        };
        register_shutdown_function($cleanup);

        $save = static function (array $map): DataHandler
        {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['tt_content' => $map], []);
            $handler->process_datamap();
            self::assertTrue(!$handler->errorLog, 'DataHandler save succeeds: ' . implode('; ', $handler->errorLog));
            return $handler;
        };
        $fixture = static function (string $link) use (&$db, &$fixtures): int
        {
            $db->insert('tt_content', ['pid' => 1, 'CType' => 'header', 'header' => 'Exchange link test', 'header_link' => $link]);
            $fixtures[] = $uid = (int)$db->lastInsertId();
            return $uid;
        };
        $value = static function (int $id) use (&$db): string
        {
            return $db->select(['header_link'], 'tt_content', ['uid' => $id])->fetchOne();
        };
        $body = static function (int $id) use (&$db): string
        {
            return $db->executeQuery('SELECT bodytext FROM tt_content WHERE uid = ?', [$id])->fetchOne();
        };
        $jobs = $container->get(\Lizard\Typo3ToTypo3\Link\PendingStore::class);
        $worker = $container->get(\Lizard\Typo3ToTypo3\Link\RetryWorker::class);
        $jobDb = $connections->getConnectionForTable(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE);
        $job = static function (int $uid, string $field = 'header_link') use (&$jobDb): array
        {
            return $jobDb->select(['*'], \Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['source_key' => \Lizard\Typo3ToTypo3\Link\PendingStore::key('tt_content', $uid, $field)])->fetchAssociative() ?: [];
        };
        $retryNow = static function (int $uid, string $field = 'header_link') use (&$jobDb, &$worker): array
        {
            $key = \Lizard\Typo3ToTypo3\Link\PendingStore::key('tt_content', $uid, $field);
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['next_attempt' => 0], ['source_key' => $key]);
            return $worker->run(1, $key);
        };
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = ExchangeWriteProbe::class;
        try {
            $fake = new stdClass();
            $fake->status = 503;
            $fake->real = false;
            $fake->calls = 0;
            $fake->callback = null;
            $fake->result = 'resolved';
            $fake->reference = ['instance' => $peer['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 0];
            $references[] = $fake->reference;
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function ($request, array $options) use ($fake, $peer, $next, &$save, &$fixture, &$value, &$job, &$retryNow) {
                ++$fake->calls;
                if ($fake->callback) { ($fake->callback)(); }
                if ($fake->real) { return $next($request, $options); }
                $urls = json_decode((string)$request->getBody(), true)['urls'];
                return Create::promiseFor(new JsonResponse(['protocol' => 1, 'instance' => $peer['instance'], 'results' => array_map(static fn($url) => ['status' => $fake->result, 'reference' => $fake->reference, 'url' => $url], $urls)], $fake->status));
            }];
            $id = $fixture('');
            $save([$id => ['header_link' => $origin . '/']]);
            self::assertTrue($value($id) === $origin . '/' && $job($id)['job_status'] === 'pending', 'Offline save preserves the URL and creates durable pending work');
            $retryNow($id);
            self::assertTrue($job($id)['attempts'] == 1 && $job($id)['next_attempt'] >= time() + 59, 'Transient failure schedules the first retry');
            $retryNow($id);
            self::assertTrue($job($id)['attempts'] == 2 && $job($id)['next_attempt'] >= time() + 119, 'Retry delay increases');
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['attempts' => 20], ['source_key' => $job($id)['source_key']]);
            $retryNow($id);
            self::assertTrue($job($id)['next_attempt'] <= time() + 3600 && $job($id)['next_attempt'] >= time() + 3599, 'Retry delay is capped at one hour');
            $fake->real = true;
            $recovery = $retryNow($id);
            self::assertTrue($recovery['failed'] === 0 && str_starts_with($value($id), 't3://exchange?') && $job($id)['job_status'] === 'complete', 'Real peer recovery converts unchanged content through the worker');
            $references[] = GeneralUtility::makeInstance(LinkService::class)->resolve($codec->decode($value($id))['url']);
            $historyCount = (int)$db->executeQuery('SELECT COUNT(*) FROM sys_history WHERE tablename = ? AND recuid = ?', ['tt_content', $id])->fetchOne();
            self::assertTrue($historyCount >= 2, 'Delayed conversion creates TYPO3 record history');
            $calls = $fake->calls;
            $retryNow($id);
            self::assertTrue($fake->calls === $calls, 'Completed work is idempotent and never recursively requeued');

            $fake->real = false;
            $fake->status = 503;
            $raceId = $fixture('');
            $save([$raceId => ['header_link' => $origin . '/before-race']]);
            $fake->status = 200;
            $fake->callback = static function () use ($db, $raceId): void {
                $db->update('tt_content', ['header_link' => 'https://editor.example/new-value'], ['uid' => $raceId]);
            };
            $retryNow($raceId);
            $fake->callback = null;
            self::assertTrue($value($raceId) === 'https://editor.example/new-value', 'Edit arriving during lookup wins over the stale resolution result');
            $retryNow($raceId);
            self::assertTrue($job($raceId)['job_status'] === 'complete' && $value($raceId) === 'https://editor.example/new-value', 'Changed content is reconsidered separately without restoring the old URL');

            $fake->status = 503;
            $lockedId = $fixture('');
            $save([$lockedId => ['header_link' => $origin . '/lock']]);
            $fake->status = 200;
            $second = \Doctrine\DBAL\DriverManager::getConnection($db->getParams());
            $second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $locked = false;
            $GLOBALS['exchangeTestWriteProbe'] = static function ($phase, $table, $uid) use ($second, $lockedId, &$locked): void {
                if ($phase === 'before' && $table === 'tt_content' && (int)$uid === $lockedId) {
                    try {
                        $second->executeStatement('UPDATE tt_content SET header_link = ? WHERE uid = ?', ['https://racing-editor.example/', $lockedId]);
                    } catch (\Doctrine\DBAL\Exception\LockWaitTimeoutException) {
                        $locked = true;
                    }
                }
            };
            $retryNow($lockedId);
            unset($GLOBALS['exchangeTestWriteProbe']);
            self::assertTrue($locked && str_starts_with($value($lockedId), 't3://exchange?'), 'Final DataHandler write holds a database row lock against a second connection');
            $second->executeStatement('UPDATE tt_content SET header_link = ? WHERE uid = ?', ['https://racing-editor.example/', $lockedId]);
            self::assertTrue($value($lockedId) === 'https://racing-editor.example/', 'Editor write after worker commit remains authoritative');
            $second->close();

            $fake->status = 503;
            $crashId = $fixture('');
            $save([$crashId => ['header_link' => $origin . '/crash']]);
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['next_attempt' => 0], ['source_key' => $job($crashId)['source_key']]);
            $claimed = $jobs->claim($job($crashId));
            $fake->status = 200;
            $calls = $fake->calls;
            $retryNow($crashId);
            self::assertTrue($fake->calls === $calls, 'Overlapping worker skips an active lease');
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['lease_until' => time() - 1], ['source_key' => $claimed['source_key']]);
            $retryNow($crashId);
            self::assertTrue($job($crashId)['job_status'] === 'complete', 'Abandoned lease is recovered after worker interruption');

            $fake->status = 503;
            $rollbackId = $fixture('');
            $save([$rollbackId => ['header_link' => $origin . '/rollback']]);
            $fake->status = 200;
            $GLOBALS['exchangeTestWriteProbe'] = static function ($phase, $table, $uid) use ($rollbackId): void {
                if ($phase === 'after' && $table === 'tt_content' && (int)$uid === $rollbackId) { throw new RuntimeException('Simulated worker interruption'); }
            };
            $retryNow($rollbackId);
            unset($GLOBALS['exchangeTestWriteProbe']);
            self::assertTrue($value($rollbackId) === $origin . '/rollback' && $job($rollbackId)['job_status'] === 'pending', 'Interruption after the content write rolls back content and leaves retryable work');
            $retryNow($rollbackId);
            self::assertTrue($job($rollbackId)['job_status'] === 'complete', 'Rolled-back conversion can be retried safely');

            $fake->status = 403;
            $deniedId = $fixture('');
            $save([$deniedId => ['header_link' => $origin . '/denied']]);
            self::assertTrue($job($deniedId)['job_status'] === 'attention', 'Access denial pauses for administrator attention');
            $fake->status = 200;
            $calls = $fake->calls;
            $retryNow($deniedId);
            self::assertTrue($fake->calls === $calls, 'Denied work does not continually retry');
            self::assertTrue($jobs->retry($job($deniedId)['source_key']), 'Manual retry requeues corrected credentials');
            $retryNow($deniedId);
            self::assertTrue($job($deniedId)['job_status'] === 'complete', 'Manual retry converts after access is restored');

            $fake->status = 503;
            $expiredId = $fixture('');
            $save([$expiredId => ['header_link' => $origin . '/expired']]);
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['created_at' => time() - 604801], ['source_key' => $job($expiredId)['source_key']]);
            $calls = $fake->calls;
            $retryNow($expiredId);
            self::assertTrue($job($expiredId)['job_status'] === 'attention' && $fake->calls === $calls, 'Seven-day expiry stops automatic retries before another lookup');
            $deletedId = $fixture('');
            $save([$deletedId => ['header_link' => $origin . '/deleted']]);
            $db->update('tt_content', ['deleted' => 1], ['uid' => $deletedId]);
            $calls = $fake->calls;
            $retryNow($deletedId);
            self::assertTrue($job($deletedId)['job_status'] === 'stale' && $fake->calls === $calls, 'Deleted source invalidates pending work without networking');

            $rteId = $fixture('');
            $rteOriginal = '<p><a href="' . $origin . '/rte#part"><strong>Read</strong> more</a></p>';
            $save([$rteId => ['CType' => 'text', 'bodytext' => $rteOriginal]]);
            self::assertTrue($job($rteId, 'bodytext')['job_status'] === 'pending', 'RTE outage produces a field-scoped job');
            $fake->status = 200;
            $retryNow($rteId, 'bodytext');
            self::assertTrue(str_contains($body($rteId), 't3://exchange?') && str_contains($body($rteId), '#part') && str_contains($body($rteId), '<strong>Read</strong> more'), 'Delayed RTE conversion preserves markup and fragment');
            $fake->status = 503;
            $legacyId = $fixture('');
            $save([$legacyId => ['header_link' => $origin . '/legacy-pending']]);
            $legacyKey = $job($legacyId)['source_key'];
            $jobDb->update(\Lizard\Typo3ToTypo3\Link\PendingStore::TABLE, ['job_status' => '', 'record_hash' => '', 'generation' => ''], ['source_key' => $legacyKey]);
            $fake->status = 200;
            $retryNow($legacyId);
            self::assertTrue($job($legacyId)['job_status'] === 'complete', 'Pre-upgrade pending outcomes are adopted only for the unchanged saved value');
            $jobs->finish($claimed, 'pending');
            self::assertTrue($job($crashId)['job_status'] === 'complete', 'An expired worker cannot overwrite the result of its replacement');
            $fake->result = 'unsupported';
            $unsupportedId = $fixture('');
            $save([$unsupportedId => ['header_link' => $origin . '/unsupported']]);
            $calls = $fake->calls;
            $retryNow($unsupportedId);
            self::assertTrue($job($unsupportedId)['job_status'] === 'attention' && $fake->calls === $calls, 'Unsupported links never enter an automatic retry loop');
            $fake->result = 'resolved';
            $workspaceDb = $connections->getConnectionForTable('sys_workspace');
            $workspaceDb->insert('sys_workspace', ['pid' => 0, 'title' => 'Exchange retry test', 'adminusers' => '', 'members' => '']);
            $workspaceIds[] = $workspaceId = (int)$workspaceDb->lastInsertId();
            $originalUser = $GLOBALS['BE_USER'];
            $workspaceUser = clone $originalUser;
            self::assertTrue($workspaceUser->setTemporaryWorkspace($workspaceId), 'Worker test uses a real TYPO3 workspace');
            $GLOBALS['BE_USER'] = $workspaceUser;
            try {
                $fake->status = 503;
                $draftHandler = $save(['NEWretrydraft' => ['pid' => 1, 'CType' => 'text', 'header' => 'Pending draft', 'bodytext' => '<a href="' . $origin . '/draft">Draft</a>']]);
                $fixtures[] = $draftId = (int)$draftHandler->substNEWwithIDs['NEWretrydraft'];
                self::assertTrue($job($draftId, 'bodytext')['workspace_id'] == $workspaceId, 'Pending job binds to the original draft workspace');
            } finally {
                $GLOBALS['BE_USER'] = $originalUser;
            }
            $fake->status = 200;
            $retryNow($draftId, 'bodytext');
            $draftState = $db->executeQuery('SELECT t3ver_wsid, t3ver_oid FROM tt_content WHERE uid = ?', [$draftId])->fetchAssociative();
            self::assertTrue(str_contains($body($draftId), 't3://exchange?') && (int)$draftState['t3ver_wsid'] === $workspaceId && (int)$draftState['t3ver_oid'] === 0, 'Worker converts the existing draft without publishing or creating another version');

            $liveId = $fixture('');
            $GLOBALS['BE_USER'] = $workspaceUser;
            try {
                $fake->status = 503;
                $versioned = $save([$liveId => ['header_link' => $origin . '/publish-pending']]);
                $publishDraftId = (int)($versioned->autoVersionIdMap['tt_content'][$liveId] ?? 0);
                if ($publishDraftId) { $fixtures[] = $publishDraftId; }
                self::assertTrue($publishDraftId > 0 && $job($publishDraftId)['live_uid'] == $liveId, 'Existing-page draft job records the live counterpart');
            } finally {
                $GLOBALS['BE_USER'] = $originalUser;
            }
            $publish = GeneralUtility::makeInstance(DataHandler::class);
            $publish->start([], ['tt_content' => [$liveId => ['version' => ['action' => 'swap', 'swapWith' => $publishDraftId]]]]);
            $publish->process_cmdmap();
            self::assertTrue(!$publish->errorLog && $value($liveId) === $origin . '/publish-pending', 'TYPO3 publication moves pending content into live');
            $fake->status = 200;
            $calls = $fake->calls;
            $retryNow($publishDraftId);
            self::assertTrue($fake->calls === $calls && $job($liveId)['job_status'] === 'pending', 'Publication invalidates the old draft job and considers the live record separately');
            $retryNow($liveId);
            self::assertTrue(str_starts_with($value($liveId), 't3://exchange?') && $job($liveId)['workspace_id'] == 0, 'Published content is converted only by its separate live job');

            $untouchedLive = $fixture('https://editor.example/live');
            $GLOBALS['BE_USER'] = $workspaceUser;
            try {
                $fake->status = 503;
                $versioned = $save([$untouchedLive => ['header_link' => $origin . '/obsolete-draft']]);
                $fixtures[] = $obsoleteDraft = (int)$versioned->autoVersionIdMap['tt_content'][$untouchedLive];
            } finally { $GLOBALS['BE_USER'] = $originalUser; }
            $fake->status = 200;
            $fake->callback = static function () use ($fake, $save, $obsoleteDraft): void {
                $fake->callback = null;
                $save([$obsoleteDraft => ['header_link' => 'https://editor.example/replaced-draft']]);
            };
            $retryNow($obsoleteDraft);
            self::assertSame('https://editor.example/replaced-draft', $value($obsoleteDraft), 'Replacing a draft during lookup wins over the stale result.');
            self::assertSame('https://editor.example/live', $value($untouchedLive), 'Draft replacement never changes the live record.');
            $GLOBALS['BE_USER'] = $workspaceUser;
            try {
                $fake->status = 503;
                $save([$obsoleteDraft => ['header_link' => $origin . '/deleted-draft']]);
                $delete = GeneralUtility::makeInstance(DataHandler::class);
                $delete->start([], ['tt_content' => [$obsoleteDraft => ['delete' => 1]]]);
                $delete->process_cmdmap();
                self::assertSame([], $delete->errorLog);
            } finally { $GLOBALS['BE_USER'] = $originalUser; }
            $fake->status = 200;
            $calls = $fake->calls;
            $retryNow($obsoleteDraft);
            self::assertSame($calls, $fake->calls, 'Deleting the draft invalidates its lookup before networking.');
            self::assertSame('https://editor.example/live', $value($untouchedLive));
            self::assertSame(0, (int)$db->select(['deleted'], 'tt_content', ['uid' => $untouchedLive])->fetchOne(), 'A retry never publishes a workspace deletion.');

        } finally {
            $cleanup();
        }
    }
}
