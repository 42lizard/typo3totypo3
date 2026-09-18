<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\ExchangeClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExchangeClientTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testCapabilityProbeUsesBoundedVerifiedHttpsAndChecksEnvironmentIdentity(): void
    {
        $previous = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        $local = PeerConfiguration::uuid();
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $local);
        try {
            $remote = PeerConfiguration::uuid();
            $remoteEnvironment = PeerConfiguration::uuid();
            $this->get(ConnectionStore::class)->save(['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [], 'outgoing' => [],
                'exchange' => ['environment' => $local, 'incoming' => [], 'outgoing' => ['usage' => [
                    'enabled' => true, 'capability' => 'usage', 'instance' => $remote, 'environment' => $remoteEnvironment,
                    'generation' => PeerConfiguration::uuid(), 'token' => str_repeat('a', 64), 'sites' => [],
                    'endpoint' => 'https://peer.example/typo3-exchange/v2',
                ]]]], '');
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-client-test' => static function ($next) use ($remote, &$remoteEnvironment) { return static function ($request, array $options) use ($remote, &$remoteEnvironment) {
                self::assertSame('https://peer.example/typo3-exchange/v2/capabilities', (string)$request->getUri());
                self::assertFalse($options['allow_redirects']);
                self::assertTrue($options['verify']);
                self::assertLessThanOrEqual(3.0, $options['timeout']);
                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'protocol' => 2, 'instance' => $remote, 'environment' => $remoteEnvironment, 'capabilities' => ['usage'],
                ], JSON_THROW_ON_ERROR)));
            }; }];
            self::assertSame(['usage'], $this->get(ExchangeClient::class)->send('usage', 'capabilities', [])['capabilities']);
            $remoteEnvironment = PeerConfiguration::uuid();
            $this->expectException(\RuntimeException::class);
            $this->get(ExchangeClient::class)->send('usage', 'capabilities', []);
        } finally {
            putenv($previous === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $previous);
        }
    }
}
