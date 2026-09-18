<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Integration;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\PendingStore;
use Lizard\Typo3ToTypo3\Link\RetryWorker;
use Lizard\Typo3ToTypo3\Link\RteLinks;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** MariaDB is required by the retry worker's atomic content/job write contract. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SaveBudgetTest extends TestCase
{
    public static function peerDelays(): array
    {
        return ['slow peers' => [0.55], 'unresponsive peers' => [5.0]];
    }

    #[DataProvider('peerDelays')]
    public function testHundredDistinctLinksShareNetworkBudgetAndFinishAsynchronously(float $delay): void
    {
        if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing') {
            throw new \RuntimeException('Requires the isolated DDEV Testing context.');
        }
        $level = ob_get_level();
        $loader = require '/var/www/html/vendor/autoload.php';
        SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($loader);
        Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
        Bootstrap::initializeBackendAuthentication();
        $db = $container->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $store = $container->get(ConnectionStore::class);
        if ($db->getDatabase() !== 'db_testing' || $store->hasConfiguration()) {
            throw new \RuntimeException('Requires an unoccupied Testing connection configuration.');
        }
        $backup = [];
        foreach ($db->createSchemaManager()->listTableNames() as $table) {
            if (str_starts_with($table, 'tx_typo3totypo3_')) { $backup[$table] = $db->select(['*'], $table, [])->fetchAllAssociative(); }
        }
        $http = $GLOBALS['TYPO3_CONF_VARS']['HTTP'];
        $ids = [];
        $process = null;
        $delayFile = tempnam(sys_get_temp_dir(), 'exchange-delay-');
        $cleaned = false;
        $cleanup = static function () use (&$cleaned, &$process, &$ids, $db, $backup, $http, $delayFile): void {
            if ($cleaned) { return; }
            $cleaned = true;
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            unlink($delayFile);
            $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = $http;
            foreach ($ids as $uid) { $db->delete('tt_content', ['uid' => $uid]); }
            foreach ($backup as $table => $rows) {
                $db->executeStatement('DELETE FROM ' . $db->quoteIdentifier($table));
                foreach ($rows as $row) { $db->insert($table, $row); }
            }
        };
        register_shutdown_function($cleanup);
        try {
            foreach ($backup as $table => $_) { $db->executeStatement('DELETE FROM ' . $db->quoteIdentifier($table)); }
            $config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => []];
            $tokens = [];
            for ($peer = 0; $peer < 10; ++$peer) {
                $token = bin2hex(random_bytes(32));
                $origin = 'https://budget-peer-' . $peer . '.example';
                $instance = PeerConfiguration::uuid();
                $tokens[$token] = ['origin' => $origin, 'instance' => $instance];
                $config['outgoing']['peer' . $peer] = ['enabled' => true, 'instance' => $instance, 'origins' => [$origin], 'token' => $token];
            }
            file_put_contents($delayFile, (string)$delay);
            $process = proc_open(['python3', __DIR__ . '/Fixtures/slow-resolver.py'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode(['caller' => $config['instance'], 'tokens' => $tokens, 'delayFile' => $delayFile], JSON_THROW_ON_ERROR) . "\n");
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 5);
            $port = (int)fgets($pipes[1]);
            self::assertGreaterThan(0, $port, 'Test HTTPS server must start.');
            $host = parse_url(getenv('EXCHANGE_TEST_ORIGIN'), PHP_URL_HOST);
            $endpoint = 'https://' . $host . ':' . $port . '/typo3-exchange/v1/resolve';
            foreach ($config['outgoing'] as &$peer) { $peer['endpoint'] = $endpoint; }
            unset($peer);
            $store->save($config, '');
            // Route only the test endpoint to loopback; normal certificate/hostname validation stays enabled.
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy'] = '';
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':127.0.0.1'];
            if ($delay < 3) {
                $badHost = 'untrusted-budget.example';
                $untrusted = $config;
                $untrusted['outgoing']['peer0']['endpoint'] = 'https://' . $badHost . ':' . $port . '/typo3-exchange/v1/resolve';
                $store->save($untrusted, $store->read()['revision']);
                $GLOBALS['TYPO3_CONF_VARS']['HTTP']['curl'][CURLOPT_RESOLVE] = [$badHost . ':' . $port . ':127.0.0.1'];
                $tlsError = null;
                $GLOBALS['TYPO3_CONF_VARS']['HTTP']['on_stats'] = static function ($stats) use (&$tlsError): void { $tlsError = $stats->getHandlerErrorData(); };
                try {
                    (new \Lizard\Typo3ToTypo3\PeerClient(new PeerConfiguration(), GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\RequestFactory::class)))
                        ->resolve('peer0', [$config['outgoing']['peer0']['origins'][0] . '/page-0']);
                    self::fail('A hostname not covered by the certificate must be rejected.');
                } catch (\RuntimeException) {
                    self::assertSame(60, $tlsError, 'cURL must reject the peer certificate hostname.');
                } finally {
                    unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['on_stats']);
                    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':127.0.0.1'];
                    $store->save($config, $store->read()['revision']);
                }
                file_put_contents($delayFile, '-1');
                try {
                    (new \Lizard\Typo3ToTypo3\PeerClient(new PeerConfiguration(), GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\RequestFactory::class)))
                        ->resolve('peer0', [$config['outgoing']['peer0']['origins'][0] . '/page-0']);
                    self::fail('Authenticated resolver redirects must not be followed.');
                } catch (\RuntimeException $error) {
                    self::assertSame(302, $error->getCode(), 'Return the original redirect, not a response from its target.');
                } finally { file_put_contents($delayFile, (string)$delay); }
            }
            $calls = [];
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['measure' => static function ($next) use (&$calls, $endpoint) { return static function ($request, array $options) use ($next, &$calls, $endpoint) {
                self::assertSame($endpoint, (string)$request->getUri());
                self::assertTrue($options['verify']);
                self::assertFalse($options['allow_redirects']);
                $options['on_stats'] = static function ($stats) use (&$calls, $options): void {
                    $calls[] = ['seconds' => $stats->getTransferTime(), 'timeout' => $options['timeout']];
                };
                return $next($request, $options);
            }; }];
            $map = [];
            foreach ($config['outgoing'] as $index => $peer) {
                $db->insert('tt_content', ['pid' => 1, 'CType' => 'text', 'header' => 'Budget ' . $index]);
                $ids[] = $uid = (int)$db->lastInsertId();
                $body = '<p>';
                for ($n = 1; $n < 10; ++$n) { $body .= '<a href="' . $peer['origins'][0] . '/page-' . $n . '">Link ' . $n . '</a>'; }
                $map[$uid] = ['header_link' => $peer['origins'][0] . '/page-0', 'bodytext' => $body . '</p>'];
            }
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start(['tt_content' => $map], []);
            $start = hrtime(true);
            $handler->process_datamap();
            $saveSeconds = (hrtime(true) - $start) / 1e9;
            self::assertSame([], $handler->errorLog);
            $networkSeconds = array_sum(array_column($calls, 'seconds'));
            $initialRequests = count($calls);
            // 50ms covers cURL timer/scheduling granularity; configured budget remains exactly 3s.
            self::assertLessThanOrEqual(3.05, $networkSeconds);
            self::assertGreaterThan($delay < 3 ? 0.5 : 2.8, $networkSeconds, 'The workload must exercise actual network waiting.');
            self::assertLessThan(10, count($calls), 'Later peers must be deferred once the shared budget expires.');
            if ($delay < 3) { self::assertLessThan($calls[0]['timeout'], $calls[array_key_last($calls)]['timeout']); }
            $pending = $db->select(['*'], PendingStore::TABLE, ['job_status' => 'pending'])->fetchAllAssociative();
            self::assertNotEmpty($pending);
            $initialManaged = 0;
            foreach ($map as $uid => $fields) {
                $saved = $db->select(['header_link', 'bodytext'], 'tt_content', ['uid' => $uid])->fetchAssociative();
                self::assertCount(9, RteLinks::anchors($saved['bodytext']));
                foreach ($fields as $field => $value) {
                    self::assertTrue($saved[$field] === $value || str_contains($saved[$field], 't3://exchange?'), 'Save preserves every field or converts it after verification.');
                }
                foreach ([$saved['header_link'], ...array_column(RteLinks::anchors($saved['bodytext']), 'url')] as $url) {
                    if (str_starts_with($url, 't3://exchange?')) { ++$initialManaged; }
                }
            }
            if ($delay < 3) { self::assertGreaterThan(0, $initialManaged); }
            else { self::assertSame(0, $initialManaged, 'Timed-out peers must never establish a reference.'); }
            file_put_contents($delayFile, '0.01');
            $worker = $container->get(RetryWorker::class);
            // Advance only these fixture jobs to their first scheduled attempt instead of sleeping one minute.
            $due = $pending;
            $rounds = 0;
            do {
                self::assertLessThan(4, ++$rounds, 'All unchanged fixture fields eventually convert.');
                foreach ($due as $job) {
                    $db->update(PendingStore::TABLE, ['next_attempt' => time() - 1], ['source_key' => $job['source_key']]);
                    self::assertSame(['processed' => 1, 'failed' => 0], $worker->run(1, $job['source_key']));
                }
                $due = $db->select(['*'], PendingStore::TABLE, ['job_status' => 'pending'])->fetchAllAssociative();
            } while ($due);
            $references = [];
            foreach ($ids as $uid) {
                $saved = $db->select(['header_link', 'bodytext'], 'tt_content', ['uid' => $uid])->fetchAssociative();
                foreach ([$saved['header_link'], ...array_column(RteLinks::anchors($saved['bodytext']), 'url')] as $url) {
                    self::assertStringStartsWith('t3://exchange?', $url);
                    parse_str((string)parse_url($url, PHP_URL_QUERY), $reference);
                    self::assertTrue((new ManagedLink())->resolveHandlerData($reference)['valid']);
                    $references[] = ManagedLink::key($reference);
                }
            }
            self::assertCount(100, array_unique($references));
            self::assertSame(0, (int)$db->count('*', PendingStore::TABLE, ['job_status' => 'pending']));
            $metrics = sprintf("\nSAVE BUDGET: 100 distinct links / 10 peers / 10 records, %.3fs cURL network, %.3fs total save, %d initial requests, %d delayed fields completed in %d retry rounds.\n", $networkSeconds, $saveSeconds, $initialRequests, count($pending), $rounds);
        } finally {
            $cleanup();
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
        echo $metrics;
    }
}
