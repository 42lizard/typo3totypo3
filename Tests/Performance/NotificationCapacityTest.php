<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Performance;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\DestinationChanges;
use Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/** Real resolver/database/queues; ten simulated authenticated consumers, not a trust test. */
final class NotificationCapacityTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testTenConsumersReceiveNormalAndBurstChangesWithinScheduledBudgets(): void
    {
        if (!function_exists('pcntl_fork')) { self::markTestSkipped('The opt-in capacity harness requires CLI pcntl.'); }
        require_once __DIR__ . '/SimulatedConsumer.php';
        $count = getenv('EXCHANGE_CAPACITY_SMOKE') === '1' ? 100 : 10000;
        $previous = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        $environment = PeerConfiguration::uuid();
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $environment);
        try {
            $path = \TYPO3\CMS\Core\Core\Environment::getConfigPath() . '/sites/main';
            if (!is_dir($path)) { mkdir($path, 0777, true); }
            file_put_contents($path . '/config.yaml', "rootPageId: 1\nbase: 'https://serving.example/'\nlanguages:\n  - title: English\n    enabled: true\n    languageId: 0\n    base: /\n    locale: en_US.UTF-8\n");
            $this->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getAllSites(false);
            $db = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
            $db->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
            $pages = $identities = [];
            for ($n = 0; $n < $count; ++$n) {
                $pages[] = [$n + 2, 1, 'Destination ' . $n, '/target-' . $n, 1];
                $identities[] = [$n + 2, PeerConfiguration::uuid()];
            }
            foreach (array_chunk($pages, 500) as $rows) { $db->bulkInsert('pages', $rows, ['uid', 'pid', 'title', 'slug', 'doktype']); }
            foreach (array_chunk($identities, 500) as $rows) { $db->bulkInsert('tx_typo3totypo3_identity', $rows, ['page_uid', 'uuid']); }
            $config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => [],
                'exchange' => ['environment' => $environment, 'incoming' => [], 'outgoing' => []]];
            $peers = [];
            for ($n = 0; $n < 10; ++$n) {
                $host = 'consumer' . $n . '.example';
                $grant = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'environment' => PeerConfiguration::uuid(),
                    'generation' => PeerConfiguration::uuid(), 'capability' => 'usage', 'sites' => ['main'], 'tokenHash' => hash('sha256', str_repeat('a', 64))];
                $notify = array_replace($grant, ['capability' => 'notify', 'generation' => PeerConfiguration::uuid(),
                    'token' => str_repeat('b', 64), 'endpoint' => 'https://' . $host . '/typo3-exchange/v2']);
                unset($notify['tokenHash']);
                $config['exchange']['incoming']['usage' . $n] = $grant;
                $config['exchange']['outgoing']['notify' . $n] = $notify;
                $peers[$host] = $notify;
                $scope = ExchangeConfiguration::scope($config['exchange'], $grant);
                $rows = array_map(static fn(array $identity): array => [$scope, $identity[1], 0, 1, 1, 1, 'main', time()], $identities);
                foreach (array_chunk($rows, 500) as $chunk) {
                    $db->bulkInsert('tx_typo3totypo3_usage', $chunk, ['scope_key', 'page_uuid', 'language_id', 'revision', 'registered_revision', 'present', 'site_identifier', 'reported_at']);
                }
            }
            $this->get(ConnectionStore::class)->save($config, '');
            $consumers = [];
            $http = $this->get(\TYPO3\CMS\Core\Http\RequestFactory::class);
            $resolver = $this->get(\Lizard\Typo3ToTypo3\PageResolver::class);
            foreach ($peers as $host => $peer) {
                $name = 'capacity_' . str_replace('.', '_', $host);
                $path = $this->getInstancePath() . '/' . $name . '.sqlite';
                if (is_file($path)) { unlink($path); }
                $consumers[$host] = new SimulatedConsumer($name, $path, $db, $config, $peer, $identities, $http);
            }
            $transport = new \ArrayObject(['offline' => false]);
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['capacity-peer' => static fn($next) => static function ($request) use ($peers, $consumers, $resolver, $config, $transport) {
                if ($transport['offline']) { return Create::promiseFor(new Response(503)); }
                $payload = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                if ($request->getUri()->getHost() === 'serving.example') {
                    usleep(100000);
                    $results = array_map(static fn(array $reference): array => $resolver->resolve($reference, true, ['main'], $config['instance']), $payload['references']);
                    return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                        'protocol' => 1, 'instance' => $config['instance'], 'environment' => $config['exchange']['environment'], 'results' => $results,
                    ], JSON_THROW_ON_ERROR)));
                }
                $peer = $peers[$request->getUri()->getHost()];
                $probe = str_ends_with($request->getUri()->getPath(), '/capabilities');
                // Fixed 100ms request latency models transport without claiming real-peer evidence.
                usleep(100000);
                if (!$probe) { $consumers[$request->getUri()->getHost()]->receive($payload['items']); }
                return Create::promiseFor(new Response($probe ? 200 : 202, ['Content-Type' => 'application/json'], json_encode([
                    'protocol' => 2, 'instance' => $peer['instance'], 'environment' => $peer['environment'], 'capabilities' => ['usage', 'notify'],
                ], JSON_THROW_ON_ERROR)));
            }];
            $worker = $this->get(ExchangeWorker::class);
            $drain = function (int $expected, int $budget, string $name, array $urls, bool $flush = true) use ($db, $worker, $consumers): void {
                $db->executeStatement('DELETE FROM tx_typo3totypo3_delivery_history');
                $runs = 0;
                $scheduled = 0.0;
                $previousStart = -60.0;
                do {
                    ++$runs;
                    $runStart = microtime(true);
                    $scheduleStart = max($scheduled, $previousStart + 60);
                    $result = $worker->run();
                    self::assertSame(0, $result['failed']);
                    $accepted = (int)$db->executeQuery('SELECT SUM(item_count) FROM tx_typo3totypo3_delivery_history')->fetchOne();
                    $scheduled = $scheduleStart + microtime(true) - $runStart;
                    $previousStart = $scheduleStart;
                    fwrite(STDERR, sprintf("\n%s: run %d, %d/%d accepted, %.2fs conservative scheduled elapsed.\n", $name, $runs, $accepted, $expected, $scheduled));
                    self::assertLessThanOrEqual($budget, $scheduled);
                } while ($accepted < $expected);
                self::assertSame($expected, $accepted);
                self::assertSame(0, (int)$db->count('*', 'tx_typo3totypo3_delivery', []));
                // Fork with no inherited native database handles; each child reconnects lazily.
                foreach ($consumers as $consumer) { $consumer->disconnect(); }
                $db->close();
                $children = [];
                foreach ($consumers as $host => $consumer) {
                    $file = $this->getInstancePath() . '/refresh-' . $host . '.json';
                    if (is_file($file)) { unlink($file); }
                    $pid = pcntl_fork();
                    if ($pid === -1) { throw new \RuntimeException('Cannot fork capacity consumer.'); }
                    if ($pid === 0) {
                        try {
                            $elapsed = $scheduled + 60; // Worst refresh scheduler phase alignment.
                            $previous = $scheduled;
                            $runs = 0;
                            do {
                                ++$runs;
                                $started = microtime(true);
                                $start = max($elapsed, $previous + 60);
                                $result = $consumer->refresh();
                                if ($result['failed'] !== 0) { throw new \RuntimeException('Consumer refresh failed.'); }
                                $elapsed = $start + microtime(true) - $started;
                                $previous = $start;
                                if ($elapsed > $budget) { throw new \RuntimeException('Consumer exceeded budget: ' . $elapsed); }
                            } while (!$consumer->complete($urls, $flush));
                            file_put_contents($file, json_encode(['elapsed' => $elapsed, 'runs' => $runs], JSON_THROW_ON_ERROR));
                            exit(0);
                        } catch (\Throwable $error) {
                            file_put_contents($file, json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR));
                            exit(1);
                        }
                    }
                    $children[$pid] = $file;
                }
                $maximum = 0.0;
                $errors = [];
                foreach ($children as $pid => $file) {
                    pcntl_waitpid($pid, $status);
                    $result = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : ['error' => 'Consumer exited without result'];
                    if (isset($result['error'])) { $errors[] = $result['error']; }
                    $maximum = max($maximum, $result['elapsed'] ?? 0);
                }
                self::assertSame([], $errors);
                fwrite(STDERR, sprintf("\n%s END TO END: %.2fs maximum scheduled across ten concurrent consumers; verified refresh and cache invalidation.\n", $name, $maximum));
                self::assertLessThanOrEqual($budget, $maximum);
            };
            $initial = [];
            foreach ($identities as $identity) { $initial[$identity[1]] = 'https://serving.example/target-' . ($identity[0] - 2); }
            $drain($count * 10, 1800, 'INITIAL BASELINE', $initial, false);
            // Choose the final UUID in scan order to expose whole-pass latency.
            $last = $identities;
            usort($last, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));
            // A missed hook must still be found by the age-ordered background pass.
            $passStarted = time();
            $db->executeStatement('UPDATE tx_typo3totypo3_observation SET checked_at = ?', [$passStarted - 181]);
            $db->update('pages', ['slug' => '/missed-event'], ['uid' => end($last)[0]]);
            $drain(10, 86400, 'MISSED EVENT FULL PASS', [end($last)[1] => 'https://serving.example/missed-event']);
            self::assertSame(0, (int)$db->executeQuery('SELECT COUNT(*) FROM tx_typo3totypo3_observation WHERE checked_at < ?', [$passStarted])->fetchOne());
            $db->update('pages', ['slug' => '/normal-change'], ['uid' => end($last)[0]]);
            $this->get(DestinationChanges::class)->requestRecheck((int)end($last)[0]);
            $drain(10, 300, 'NORMAL CHANGE', [end($last)[1] => 'https://serving.example/normal-change']);
            foreach ($consumers as $consumer) { $consumer->primeCache(); }
            $db->executeStatement("UPDATE pages SET slug = REPLACE(slug, '/target-', '/burst-') WHERE uid > 1");
            $db->update('pages', ['slug' => '/burst-normal'], ['uid' => end($last)[0]]);
            $this->get(DestinationChanges::class)->requestRecheck();
            $burst = array_map(static fn(string $url): string => str_replace('/target-', '/burst-', $url), $initial);
            $burst[end($last)[1]] = 'https://serving.example/burst-normal';
            $drain($count * 10, 1800, 'BURST', $burst);
            foreach ($consumers as $consumer) { $consumer->primeCache(); }
            $transport['offline'] = true;
            $db->executeStatement("UPDATE pages SET slug = REPLACE(slug, '/burst-', '/outage-') WHERE uid > 1");
            $changes = $this->get(DestinationChanges::class);
            $changes->requestRecheck();
            self::assertSame(10, $worker->run()['failed']);
            // Model elapsed outage time through durable timestamps, without sleeping for a day.
            $db->executeStatement('UPDATE tx_typo3totypo3_delivery_pair SET attempts = 16, first_failure = ?, next_attempt = ?', [time() - 86400, time() + 3600]);
            $exchange = $this->get(ExchangeConfiguration::class)->load();
            $changes->beginRun();
            foreach (array_keys($exchange['outgoing']) as $name) {
                while ($changes->scan($exchange, $name) > 0) {}
            }
            self::assertSame($count * 10, (int)$db->count('*', 'tx_typo3totypo3_delivery', []));
            $queue = $this->get(\Lizard\Typo3ToTypo3\Exchange\DeliveryQueue::class);
            foreach ($exchange['outgoing'] as $channel) {
                self::assertNull($queue->claim(ExchangeConfiguration::scope($exchange, $channel)), 'Recovery must respect the remaining hourly backoff.');
            }
            // Advance the test fixture to the first naturally eligible retry, not an operator retry.
            $db->executeStatement('UPDATE tx_typo3totypo3_delivery_pair SET next_attempt = ?', [time() - 1]);
            $transport['offline'] = false;
            fwrite(STDERR, "\nOUTAGE: modeled 24 hours, up to 3600s remaining backoff excluded from recovery measurement.\n");
            $outage = array_map(static fn(string $url): string => str_replace('/burst-', '/outage-', $url), $burst);
            $drain($count * 10, 1800, 'OUTAGE RECOVERY', $outage);
        } finally {
            putenv($previous === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $previous);
        }
    }
}
