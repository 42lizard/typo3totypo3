<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Integration;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Lizard\Typo3ToTypo3\PageIdentity;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExchangeTest extends TestCase
{
    public function testHttpsUsageNotificationAndVerifiedRefreshLifecycle(): void
    {
        $level = ob_get_level();
        if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing') {
            throw new \RuntimeException('Use only the isolated Testing context.');
        }
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/vendor/bin/typo3';
        $loader = require '/var/www/html/vendor/autoload.php';
        SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($loader);
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        Bootstrap::initializeBackendAuthentication();
        $connections = $container->get(ConnectionPool::class);
        $db = $connections->getConnectionForTable('pages');
        $store = $container->get(ConnectionStore::class);
        if ($db->getDatabase() !== 'db_testing' || $store->hasConfiguration()) { throw new \RuntimeException('Requires an empty Testing connection configuration.'); }
        $activation = Environment::getConfigPath() . '/system/exchange-environment.php';
        $previousActivation = is_file($activation) ? file_get_contents($activation) : null;
        $environment = ExchangeConfiguration::initializeDeployment();
        $origin = getenv('EXCHANGE_TEST_ORIGIN');
        $instance = PeerConfiguration::uuid();
        $resolverToken = bin2hex(random_bytes(32));
        $usageToken = bin2hex(random_bytes(32));
        $notifyToken = bin2hex(random_bytes(32));
        $usage = ['enabled' => true, 'capability' => 'usage', 'instance' => $instance, 'environment' => $environment,
            'generation' => PeerConfiguration::uuid(), 'sites' => ['main']];
        $notify = ['enabled' => true, 'capability' => 'notify', 'instance' => $instance, 'environment' => $environment,
            'generation' => PeerConfiguration::uuid(), 'sites' => []];
        $config = ['enabled' => true, 'instance' => $instance,
            'incoming' => [$instance => ['enabled' => true, 'sites' => ['main'], 'tokenHash' => hash('sha256', $resolverToken), 'requestsPerMinute' => 120]],
            'outgoing' => ['self' => ['enabled' => true, 'instance' => $instance, 'environment' => $environment, 'endpoint' => $origin . '/typo3-exchange/v1/resolve', 'origins' => [$origin], 'token' => $resolverToken]],
            'exchange' => ['environment' => $environment,
                'incoming' => ['usage' => $usage + ['tokenHash' => hash('sha256', $usageToken)], 'notify' => $notify + ['tokenHash' => hash('sha256', $notifyToken)]],
                'outgoing' => ['usage' => $usage + ['token' => $usageToken, 'endpoint' => $origin . '/typo3-exchange/v2'],
                    'notify' => $notify + ['token' => $notifyToken, 'endpoint' => $origin . '/typo3-exchange/v2']]]];
        $pageId = $contentId = null;
        $tables = ['usage', 'usage_pair', 'usage_stage', 'notification', 'source_usage', 'source_dirty', 'source_scan', 'delivery', 'delivery_pair', 'report_state', 'report_reference', 'capability', 'observation', 'recheck', 'delivery_history', 'worker', 'change_hint'];
        $saved = [];
        foreach ($tables as $suffix) {
            $table = 'tx_typo3totypo3_' . $suffix;
            $saved[$table] = $db->select(['*'], $table, [])->fetchAllAssociative();
        }
        $destinations = $db->select(['*'], DestinationStore::TABLE, [])->fetchAllAssociative();
        try {
            foreach (array_keys($saved) as $table) { $db->executeStatement('DELETE FROM ' . $table); }
            $store->save($config, '');
            $db->insert('pages', ['pid' => 1, 'title' => 'Exchange integration target', 'slug' => '/exchange-integration-target', 'doktype' => 1]);
            $pageId = (int)$db->lastInsertId();
            $reference = ['instance' => $instance, 'page' => (new PageIdentity($connections))->forPage($pageId), 'language' => 0];
            $dest = $container->get(DestinationStore::class);
            $dest->record($reference, 'resolved', $origin . '/exchange-integration-target');
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['tt_content' => ['NEWexchange' => ['pid' => 1, 'CType' => 'header', 'header' => 'Usage integration source', 'header_link' => (new ManagedLink())->asString($reference)]]], []);
            $handler->process_datamap();
            self::assertSame([], $handler->errorLog);
            $contentId = (int)$handler->substNEWwithIDs['NEWexchange'];
            $worker = $container->get(ExchangeWorker::class);
            $result = $worker->run(500);
            self::assertSame(0, $result['failed']);
            self::assertSame(0, $result['deferred']);
            $scope = ExchangeConfiguration::scope($config['exchange'], $usage);
            $registered = $container->get(UsageRegistry::class)->usages($scope);
            self::assertCount(1, $registered);
            self::assertSame($reference['page'], $registered[0]['page_uuid']);
            $container->get(RefreshWorker::class)->run();
            self::assertSame('resolved', $dest->find($reference)['status']);
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['pages' => [$pageId => ['slug' => '/exchange-integration-renamed']]], []);
            $handler->process_datamap();
            self::assertSame([], $handler->errorLog);
            self::assertSame('/exchange-integration-renamed', $db->select(['slug'], 'pages', ['uid' => $pageId])->fetchOne());
            $renameResult = $worker->run(500);
            self::assertSame(0, $renameResult['failed']);
            self::assertGreaterThan(0, $renameResult['checked'], 'Committed page change queues a check.');
            self::assertGreaterThan(0, $renameResult['accepted'], 'Changed canonical URL queues delivery.');
            self::assertSame(0, (int)$dest->find($reference)['next_refresh'], 'Durable HTTPS notification makes the existing destination due.');
            $container->get(RefreshWorker::class)->run();
            self::assertSame($origin . '/exchange-integration-renamed', $dest->find($reference)['url']);
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['pages' => [$pageId => ['hidden' => 1]]], []);
            $handler->process_datamap();
            self::assertSame(0, $worker->run(500)['failed']);
            $container->get(RefreshWorker::class)->run();
            self::assertSame('unavailable', $dest->find($reference)['status']);
            self::assertCount(1, $container->get(UsageRegistry::class)->usages($scope), 'Unavailable destinations retain known usage.');
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['tt_content' => [$contentId => ['header_link' => '']]], []);
            $handler->process_datamap();
            self::assertSame(0, $worker->run(500)['failed']);
            self::assertSame([], $container->get(UsageRegistry::class)->usages($scope));
        } finally {
            $db->executeStatement('DELETE FROM ' . ConnectionStore::TABLE);
            if ($contentId !== null) {
                $db->delete('tt_content', ['uid' => $contentId]);
                $db->delete('tx_typo3totypo3_link_outcome', ['table_name' => 'tt_content', 'record_uid' => $contentId]);
            }
            if ($pageId !== null) { $db->delete('pages', ['uid' => $pageId]); $db->delete('tx_typo3totypo3_identity', ['page_uid' => $pageId]); }
            foreach ($saved as $table => $rows) { $db->executeStatement('DELETE FROM ' . $table); foreach ($rows as $row) { $db->insert($table, $row); } }
            $db->executeStatement('DELETE FROM ' . DestinationStore::TABLE);
            foreach ($destinations as $row) { $db->insert(DestinationStore::TABLE, $row); }
            if ($previousActivation === null) { unlink($activation); } else { file_put_contents($activation, $previousActivation); }
            $container->get(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('pages');
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
    }
}
