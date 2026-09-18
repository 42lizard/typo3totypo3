<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Middleware\Exchange;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExchangeEndpointTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];
    private string|false $previousEnvironment;
    private array $config;
    private array $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnvironment = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        $environment = PeerConfiguration::uuid();
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . $environment);
        $this->channel = ['enabled' => true, 'capability' => 'notify', 'instance' => PeerConfiguration::uuid(),
            'environment' => PeerConfiguration::uuid(), 'generation' => PeerConfiguration::uuid(),
            'tokenHash' => hash('sha256', str_repeat('a', 64)), 'sites' => []];
        $this->config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'incoming' => [],
            'outgoing' => ['peer' => ['enabled' => true, 'instance' => $this->channel['instance'], 'environment' => $this->channel['environment'],
                'token' => str_repeat('b', 64), 'endpoint' => 'https://peer.example/typo3-exchange/v1/resolve', 'origins' => ['https://peer.example']]],
            'exchange' => ['environment' => $environment, 'incoming' => ['notifications' => $this->channel], 'outgoing' => []]];
        $this->get(ConnectionStore::class)->save($this->config, '');
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnvironment === false ? 'TYPO3_EXCHANGE_ENVIRONMENT' : 'TYPO3_EXCHANGE_ENVIRONMENT=' . $this->previousEnvironment);
        parent::tearDown();
    }

    private function request(array $payload, string $token = '', ?string $generation = null, string $path = 'notify'): ResponseInterface
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
        rewind($stream);
        $request = new ServerRequest('https://local.example/typo3-exchange/v2/' . $path, 'POST', $stream);
        $request = $request->withHeader('Content-Type', 'application/json')->withHeader('Authorization', 'Bearer ' . ($token ?: str_repeat('a', 64)))
            ->withHeader('X-TYPO3-Peer', $this->channel['instance'])->withHeader('X-TYPO3-Environment', $this->channel['environment'])
            ->withHeader('X-TYPO3-Generation', $generation ?? $this->channel['generation'])->withHeader('X-TYPO3-Capability', $this->channel['capability']);
        return $this->get(Exchange::class)->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface { return new JsonResponse([], 404); }
        });
    }

    public function testAuthorizedNotificationQueuesRefreshButWrongCredentialAndGenerationDoNot(): void
    {
        $reference = ['instance' => $this->channel['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $destinations = $this->get(DestinationStore::class);
        $destinations->record($reference, 'resolved', 'https://peer.example/');
        $payload = ['protocol' => 2, 'items' => [['reference' => $reference, 'revision' => 1]]];
        self::assertSame(401, $this->request($payload, str_repeat('c', 64))->getStatusCode());
        self::assertSame(401, $this->request($payload, generation: PeerConfiguration::uuid())->getStatusCode());
        self::assertSame([], $destinations->claim($reference['instance'], 1));
        self::assertSame(202, $this->request($payload)->getStatusCode());
        self::assertCount(1, $destinations->claim($reference['instance'], 1));
    }

    public function testCopiedDatabaseCannotActivateExchangeWithAnotherDeploymentIdentity(): void
    {
        putenv('TYPO3_EXCHANGE_ENVIRONMENT=' . PeerConfiguration::uuid());
        self::assertSame(503, $this->request(['protocol' => 2, 'items' => []])->getStatusCode());
    }

    public function testSnapshotCompletionCannotReuseARevokedSiteGrant(): void
    {
        $store = $this->get(ConnectionStore::class);
        $this->channel['capability'] = 'usage';
        $this->channel['sites'] = ['main'];
        $this->config['exchange']['incoming']['notifications'] = $this->channel;
        $store->save($this->config, $store->read()['revision']);
        $snapshot = PeerConfiguration::uuid();
        self::assertSame(202, $this->request(['protocol' => 2, 'operation' => 'stage', 'snapshot' => $snapshot, 'revision' => 1, 'items' => []], path: 'usage')->getStatusCode());
        $this->channel['sites'] = [];
        $this->config['exchange']['incoming']['notifications'] = $this->channel;
        $store->save($this->config, $store->read()['revision']);
        self::assertSame(400, $this->request(['protocol' => 2, 'operation' => 'complete', 'snapshot' => $snapshot, 'revision' => 1, 'count' => 0, 'digest' => hash('sha256', '')], path: 'usage')->getStatusCode());
    }
    public function testUsagePermissionIsIndependentAndUnknownPagesAreNotRegistered(): void
    {
        $payload = ['protocol' => 2, 'operation' => 'report', 'items' => [
            ['page' => PeerConfiguration::uuid(), 'language' => 0, 'revision' => 1, 'present' => true],
        ]];
        self::assertSame(403, $this->request($payload, path: 'usage')->getStatusCode());
        $this->channel['capability'] = 'usage';
        $this->channel['sites'] = ['main'];
        $this->config['exchange']['incoming']['notifications'] = $this->channel;
        $store = $this->get(ConnectionStore::class);
        $store->save($this->config, $store->read()['revision']);
        $response = $this->request($payload, path: 'usage');
        self::assertSame(202, $response->getStatusCode());
        $result = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($result['complete']);
        self::assertSame([0], $result['unaccepted']);
        $scope = \Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration::scope($this->config['exchange'], $this->channel);
        self::assertSame([], $this->get(\Lizard\Typo3ToTypo3\Exchange\UsageRegistry::class)->usages($scope));
    }

    public function testNotificationCannotSmugglePerLinkDetailsIntoInvalidation(): void
    {
        $reference = ['instance' => $this->channel['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $store = $this->get(DestinationStore::class);
        $store->record($reference, 'resolved', 'https://peer.example/');
        $reference['query'] = 'private-source=123';
        self::assertSame(400, $this->request(['protocol' => 2, 'items' => [['reference' => $reference, 'revision' => 1]]])->getStatusCode());
        unset($reference['query']);
        self::assertSame([], $store->claim($reference['instance'], 1));
    }

    public function testNotificationCannotTargetAResolverForAnotherEnvironmentOfTheSameInstance(): void
    {
        $store = $this->get(ConnectionStore::class);
        $this->config['outgoing']['peer']['environment'] = PeerConfiguration::uuid();
        $store->save($this->config, $store->read()['revision']);
        $reference = ['instance' => $this->channel['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $this->get(DestinationStore::class)->record($reference, 'resolved', 'https://peer.example/');
        self::assertSame(403, $this->request(['protocol' => 2, 'items' => [['reference' => $reference, 'revision' => 1]]])->getStatusCode());
        self::assertSame([], $this->get(DestinationStore::class)->claim($reference['instance'], 1));
    }

}
