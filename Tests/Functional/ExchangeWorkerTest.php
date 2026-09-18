<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExchangeWorkerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testUnsupportedPeerIsNotProbedAgainUntilExplicitRecheck(): void
    {
        $previous = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        $environment = PeerConfiguration::uuid();
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $environment);
        try {
            $remote = PeerConfiguration::uuid();
            $remoteEnvironment = PeerConfiguration::uuid();
            $config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => [],
                'exchange' => ['environment' => $environment, 'incoming' => [], 'outgoing' => ['usage' => [
                    'enabled' => true, 'capability' => 'usage', 'instance' => $remote, 'environment' => $remoteEnvironment,
                    'generation' => PeerConfiguration::uuid(), 'token' => str_repeat('a', 64), 'sites' => [], 'endpoint' => 'https://old.example/typo3-exchange/v2',
                ]]]];
            $this->get(ConnectionStore::class)->save($config, '');
            $calls = new \ArrayObject(['count' => 0, 'supported' => false]);
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['old-peer' => static fn($next) => static function ($request) use ($remote, $remoteEnvironment, $calls) {
                ++$calls['count'];
                if (!$calls['supported']) { return Create::promiseFor(new Response(404)); }
                $probe = str_ends_with($request->getUri()->getPath(), '/capabilities');
                return Create::promiseFor(new Response($probe ? 200 : 202, ['Content-Type' => 'application/json'], json_encode([
                    'protocol' => 2, 'instance' => $remote, 'environment' => $remoteEnvironment, 'capabilities' => ['usage'], 'complete' => true, 'unaccepted' => [],
                ], JSON_THROW_ON_ERROR)));
            }];
            $worker = $this->get(ExchangeWorker::class);
            $worker->run();
            $worker->run();
            self::assertSame(1, $calls['count']);
            $scope = \Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration::scope($config['exchange'], $config['exchange']['outgoing']['usage']);
            self::assertSame('unsupported', $this->get(\Lizard\Typo3ToTypo3\Exchange\CapabilityState::class)->status($scope));
            $calls['supported'] = true;
            $worker->run();
            self::assertSame(1, $calls['count'], 'An upgrade does not silently re-enable a known unsupported capability.');
            self::assertSame(2, $worker->run(recheck: true)['accepted']);
            self::assertSame('supported', $this->get(\Lizard\Typo3ToTypo3\Exchange\CapabilityState::class)->status($scope));
        } finally {
            putenv($previous === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $previous);
        }
    }

    public function testActivatedUsageWorkerDiscoversCapabilityThenCompletesEmptyReconciliation(): void
    {
        $previous = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        $environment = PeerConfiguration::uuid();
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $environment);
        try {
            $remote = PeerConfiguration::uuid();
            $remoteEnvironment = PeerConfiguration::uuid();
            $this->get(ConnectionStore::class)->save(['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => [],
                'exchange' => ['environment' => $environment, 'incoming' => [], 'outgoing' => ['usage' => [
                    'enabled' => true, 'capability' => 'usage', 'instance' => $remote, 'environment' => $remoteEnvironment,
                    'generation' => PeerConfiguration::uuid(), 'token' => str_repeat('a', 64), 'sites' => [],
                    'endpoint' => 'https://peer.example/typo3-exchange/v2',
                ]]]], '');
            $operations = new \ArrayObject();
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-worker-test' => static fn($next) => static function ($request) use ($remote, $remoteEnvironment, $operations) {
                $payload = json_decode((string)$request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $probe = str_ends_with($request->getUri()->getPath(), '/capabilities');
                $operations[] = $probe ? 'capabilities' : $payload['operation'];
                return Create::promiseFor(new Response($probe ? 200 : 202, ['Content-Type' => 'application/json'], json_encode([
                    'protocol' => 2, 'instance' => $remote, 'environment' => $remoteEnvironment,
                    'capabilities' => ['usage'], 'complete' => true, 'unaccepted' => [],
                ], JSON_THROW_ON_ERROR)));
            }];
            $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('tx_typo3totypo3_worker');
            $db->insert('tx_typo3totypo3_worker', ['uid' => 1, 'lease_token' => 'another-process', 'lease_until' => time() + 120]);
            $this->get(ExchangeWorker::class)->run(100);
            self::assertSame([], $operations->getArrayCopy(), 'A concurrent invocation must not probe or deliver.');
            $db->update('tx_typo3totypo3_worker', ['lease_until' => time() - 1], ['uid' => 1]);
            $result = $this->get(ExchangeWorker::class)->run(100);
            self::assertSame(['capabilities', 'stage', 'complete'], $operations->getArrayCopy());
            self::assertSame(2, $result['accepted']);
            self::assertSame(0, $result['failed']);
        } finally {
            putenv($previous === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $previous);
        }
    }
}
