<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Performance;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/** Per-instance polling workload: ten simulated peers, no change notifications. */
final class RefreshCapacityTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    public function testTenPeersAndTenThousandDestinationsMeetWorstPhaseFreshness(): void
    {
        $count = getenv('EXCHANGE_CAPACITY_SMOKE') === '1' ? 100 : 10000;
        $db = $this->get(ConnectionPool::class)->getConnectionForTable(DestinationStore::TABLE);
        $store = $this->get(DestinationStore::class);
        $cache = $this->get(CacheManager::class)->getCache('pages');
        $config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => []];
        for ($n = 0; $n < 10; ++$n) {
            $origin = 'https://refresh-peer-' . $n . '.example';
            $config['outgoing']['peer' . $n] = ['enabled' => true, 'instance' => PeerConfiguration::uuid(),
                'origins' => [$origin], 'endpoint' => $origin . '/typo3-exchange/v1/resolve', 'token' => bin2hex(random_bytes(32))];
        }
        $this->get(ConnectionStore::class)->save($config, '');
        $references = $expected = [];
        $db->transactional(function () use ($count, $config, $store, $cache, &$references, &$expected): void {
            for ($n = 0; $n < $count; ++$n) {
                $peer = $config['outgoing']['peer' . ($n % 10)];
                $reference = ['instance' => $peer['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 0];
                $key = ManagedLink::key($reference);
                $references[$key] = $reference;
                $expected[$key] = $peer['origins'][0] . '/changed-' . $n;
                $store->record($reference, 'resolved', $peer['origins'][0] . '/old-' . $n);
                $cache->set($key, 'Previously rendered link', [DestinationStore::tag($reference)], 86400);
            }
        });
        self::assertTrue($cache->has(array_key_first($references)), 'Use a real tagged cache, not the framework NullBackend.');
        $cache->set('unrelated', 'Unaffected output', ['unrelated'], 86400);
        $sample = $store->find(reset($references));
        $interval = (int)$sample['next_refresh'] - (int)$sample['checked_at'];
        self::assertGreaterThan(0, $interval);
        $calls = new \ArrayObject();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['capacity' => static fn($next) => static function ($request, array $options) use ($config, $expected, $calls) {
            $matches = array_filter($config['outgoing'], static fn(array $peer): bool => $peer['endpoint'] === (string)$request->getUri());
            self::assertCount(1, $matches);
            $peer = reset($matches);
            $references = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR)['references'];
            self::assertLessThanOrEqual(50, count($references));
            $calls[] = $peer['instance'];
            usleep(100000); // Healthy remote processing plus transport, measured rather than subtracted.
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'protocol' => 1, 'instance' => $peer['instance'], 'results' => array_map(static fn(array $reference): array => [
                    'status' => 'resolved', 'reference' => $reference, 'url' => $expected[ManagedLink::key($reference)],
                ], $references),
            ], JSON_THROW_ON_ERROR)));
        }];
        // Change immediately after a successful check, then miss the minute tick at the due boundary.
        // Advance only initial due times; successful results keep production retry/freshness scheduling.
        $db->executeStatement('UPDATE ' . DestinationStore::TABLE . ' SET next_refresh = 0');
        $scheduled = $interval + 60.0;
        $previousStart = $scheduled - 60;
        $runs = $processed = 0;
        do {
            $runStart = max(ceil($scheduled / 60) * 60, $previousStart + 60);
            $started = hrtime(true);
            $result = $this->get(RefreshWorker::class)->run();
            $seconds = (hrtime(true) - $started) / 1e9;
            $processed += $result['processed'];
            $scheduled = $runStart + $seconds;
            $previousStart = $runStart;
            ++$runs;
            self::assertSame(0, $result['failed']);
            self::assertGreaterThan(0, $result['processed']);
            fwrite(STDERR, sprintf("\nREFRESH CAPACITY: run %d, %d/%d destinations, %.2fs processing, %.2fs worst-phase freshness.\n", $runs, $processed, $count, $seconds, $scheduled));
            self::assertLessThanOrEqual(10, $runs);
        } while ($processed < $count);
        foreach ($references as $key => $reference) {
            self::assertSame($expected[$key], $store->find($reference)['url']);
            self::assertFalse($cache->has($key), 'Every affected rendered page must be invalidated.');
        }
        self::assertTrue($cache->has('unrelated'));
        self::assertCount(10, array_unique($calls->getArrayCopy()));
        self::assertLessThanOrEqual(300, $scheduled, 'Include the polling interval, worst minute phase, run limits, measured processing and cache invalidation.');
    }
}
