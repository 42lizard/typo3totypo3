<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Performance;

use Lizard\Typo3ToTypo3\Exchange\SourceUsage;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\Exchange\UsageReceiver;
use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/** Explicit acceptance workload; separate from the fast CI suite. */
final class SourceCapacityTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testOneMillionOccurrencesReconcileWithinAnHourlyMinuteSchedule(): void
    {
        $records = getenv('EXCHANGE_CAPACITY_SMOKE') === '1' ? 1000 : 100000;
        $instance = PeerConfiguration::uuid();
        $links = $identities = [];
        for ($n = 0; $n < 10000; ++$n) {
            $page = PeerConfiguration::uuid();
            $identities[] = [$n + 2, $page];
            $links[] = '<a href="' . htmlspecialchars((new ManagedLink())->asString(['instance' => $instance, 'page' => $page, 'language' => 0])) . '">Link</a>';
        }
        $db = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $path = \TYPO3\CMS\Core\Core\Environment::getConfigPath() . '/sites/main';
        if (!is_dir($path)) { mkdir($path, 0777, true); }
        file_put_contents($path . '/config.yaml', "rootPageId: 1\nbase: 'https://serving.example/'\nlanguages:\n  - title: English\n    enabled: true\n    languageId: 0\n    base: /\n    locale: en_US.UTF-8\n");
        $this->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getAllSites(false);
        $db->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        foreach (array_chunk($identities, 500) as $chunk) {
            $db->bulkInsert('tx_typo3totypo3_identity', $chunk, ['page_uid', 'uuid']);
            $db->bulkInsert('pages', array_map(static fn(array $row): array => [$row[0], 1, 'Target', '/target-' . $row[0], 1], $chunk), ['uid', 'pid', 'title', 'slug', 'doktype']);
        }
        for ($batch = 0; $batch < $records / 1000; ++$batch) {
            $rows = [];
            for ($n = 0; $n < 1000; ++$n) {
                $uid = $batch * 1000 + $n + 1;
                $rows[] = [$uid, 1, 'text', implode('', array_slice($links, ($uid % 1000) * 10, 10)), $uid % 2 ? 0 : 1, $uid % 2 ? 0 : 1, $uid % 3 === 0 ? 1 : 0, $uid % 3 === 0 ? $uid - 1 : 0];
            }
            $db->bulkInsert('tt_content', $rows, ['uid', 'pid', 'CType', 'bodytext', 'hidden', 't3ver_wsid', 'sys_language_uid', 'l18n_parent']);
        }
        $environment = PeerConfiguration::uuid();
        $remoteEnvironment = PeerConfiguration::uuid();
        $previous = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $environment);
        $channel = ['enabled' => true, 'capability' => 'usage', 'instance' => $instance, 'environment' => $remoteEnvironment,
            'generation' => PeerConfiguration::uuid(), 'sites' => ['main'], 'token' => str_repeat('a', 64), 'endpoint' => 'https://serving.example/typo3-exchange/v2'];
        $local = PeerConfiguration::uuid();
        $this->get(ConnectionStore::class)->save(['enabled' => true, 'instance' => $local, 'incoming' => [], 'outgoing' => [],
            'exchange' => ['environment' => $environment, 'incoming' => [], 'outgoing' => ['usage' => $channel]]], '');
        $grant = array_replace($channel, ['instance' => $local, 'environment' => $environment]);
        $remote = ['environment' => $remoteEnvironment, 'instance' => $instance];
        $receiver = $this->get(UsageReceiver::class);
        $status = new \ArrayObject(['complete' => false]);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['capacity' => static fn($next) => static function ($request) use ($receiver, $remote, $grant, $status, $instance, $remoteEnvironment) {
            usleep(100000);
            $probe = str_ends_with($request->getUri()->getPath(), '/capabilities');
            $payload = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $result = $probe ? [] : $receiver->receive($remote, $grant, $payload);
            if (!$probe && $payload['operation'] === 'complete' && $result['complete']) { $status['complete'] = true; }
            return Create::promiseFor(new Response($probe ? 200 : 202, ['Content-Type' => 'application/json'], json_encode([
                'protocol' => 2, 'instance' => $instance, 'environment' => $remoteEnvironment, 'capabilities' => ['usage'],
            ] + $result, JSON_THROW_ON_ERROR)));
        }];
        $sources = $this->get(SourceUsage::class);
        $worker = $this->get(ExchangeWorker::class);
        $started = microtime(true);
        $runs = 0;
        $scheduled = 0.0;
        $previousStart = -60.0;
        try {
            do {
                ++$runs;
                $runStart = microtime(true);
                $scheduleStart = max($scheduled, $previousStart + 60);
                self::assertSame(0, $worker->run()['failed']);
                $scheduled = $scheduleStart + microtime(true) - $runStart;
                $previousStart = $scheduleStart;
                if ($runs % 10 === 0) { fwrite(STDERR, sprintf("\nSOURCE: run %d, %.2fs scheduled.\n", $runs, $scheduled)); }
                self::assertLessThanOrEqual(60, $runs, 'Full reconciliation must fit sixty once-per-minute invocations.');
            } while (!$status['complete']);
        } finally {
            putenv($previous === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $previous);
        }
        self::assertCount(10000, $this->get(UsageRegistry::class)->usages(\Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration::scope($remote, $grant)));
        $seconds = microtime(true) - $started;
        self::assertLessThanOrEqual(3600, $scheduled);
        self::assertCount(10000, $sources->references($instance));
        self::assertSame($records * 10, (int)$db->count('*', 'tx_typo3totypo3_source_usage', []));
        fwrite(STDERR, sprintf("\nSOURCE CAPACITY: %d records, %d occurrences, %d runs, %.2fs processing, %.2fs conservative scheduled through verified snapshot completion.\n", $records, $records * 10, $runs, $seconds, $scheduled));
    }
}
