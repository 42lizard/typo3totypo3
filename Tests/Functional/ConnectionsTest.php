<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Backend\ConnectionsController;
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Masterminds\HTML5;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Http\RequestHandler;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ConnectionsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];
    private string $previousConfig;
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $this->previousConfig = getenv('TYPO3_EXCHANGE_CONFIG') ?: '';
        $this->config = ['enabled' => true, 'instance' => PeerConfiguration::uuid(), 'outgoing' => ['peer' => [
            'enabled' => true, 'instance' => PeerConfiguration::uuid(), 'endpoint' => 'https://peer.example/typo3-exchange/v1/resolve',
            'origins' => ['https://peer.example'], 'token' => str_repeat('a', 64),
        ]], 'incoming' => [], 'publicAliases' => []];
        $path = $this->getInstancePath() . '/legacy-peer.json';
        file_put_contents($path, json_encode($this->config, JSON_THROW_ON_ERROR));
        putenv('TYPO3_EXCHANGE_CONFIG=' . $path);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () {
            throw new \LogicException('Viewing and saving connections must not contact peers.');
        }];
    }

    protected function tearDown(): void
    {
        putenv('TYPO3_EXCHANGE_CONFIG=' . $this->previousConfig);
        parent::tearDown();
    }

    private function request(string $method = 'GET', array $body = []): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/exchange/connections', $method, 'php://input', [], [
            'HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'SERVER_PORT' => '443',
            'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => $this->getInstancePath() . '/typo3/index.php',
        ]))->withAttribute('applicationType', 2)->withAttribute('route', new Route('/module/exchange/connections', [
            'packageName' => '42lizard/typo3-to-typo3', '_identifier' => ConnectionsController::MODULE,
        ]));
        $request = $request->withAttribute('normalizedParams', \TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        if ($method === 'POST') {
            $body += ['csrf' => $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('exchange-connections'),
                'revision' => $this->get(ConnectionStore::class)->read()['revision']];
            $request = $request->withParsedBody($body);
        }
        return $request;
    }

    #[Test]
    public function importPreservesIdentityEncryptsSecretsAndDoesNotFallBackAfterAClone(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        self::assertSame($this->config, (new PeerConfiguration())->load());
        $payload = $this->get(ConnectionPool::class)->getConnectionForTable(ConnectionStore::TABLE)
            ->select(['payload'], ConnectionStore::TABLE, [])->fetchOne();
        self::assertStringNotContainsString(str_repeat('a', 64), $payload);
        self::assertStringNotContainsString('peer.example', $payload);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('different-key', 8);
        self::assertSame('', $store->read()['revision']);
        $this->expectException(\RuntimeException::class);
        (new PeerConfiguration())->load();
    }

    #[Test]
    public function importingDisabledConfigurationDoesNotEnableConnections(): void
    {
        $this->config['enabled'] = false;
        file_put_contents(getenv('TYPO3_EXCHANGE_CONFIG'), json_encode($this->config, JSON_THROW_ON_ERROR));
        $store = $this->get(ConnectionStore::class);
        $store->import();
        self::assertFalse($store->read()['config']['enabled']);
        $this->expectException(\RuntimeException::class);
        (new PeerConfiguration())->load();
    }

    #[Test]
    public function settingsInitializeAndDisableConnectionsAndCannotChangeEstablishedIdentity(): void
    {
        $store = $this->get(ConnectionStore::class);
        $controller = $this->get(ConnectionsController::class);
        $response = $controller->handleRequest($this->request('POST', ['action' => 'settings',
            'instance' => $this->config['instance'], 'enabled' => '1', 'aliases' => 'https://old.example=https://current.example']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['https://old.example' => 'https://current.example'], $store->read()['config']['publicAliases']);
        $response = $controller->handleRequest($this->request('POST', ['action' => 'settings', 'instance' => PeerConfiguration::uuid()]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->config['instance'], $store->read()['config']['instance']);
        self::assertFalse($store->read()['config']['enabled']);
    }

    #[Test]
    public function aDifferentApplicationContextCannotActivateCopiedConnections(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $context = \TYPO3\CMS\Core\Core\Environment::getContext();
        $changeContext = static function ($context): void {
            \TYPO3\CMS\Core\Core\Environment::initialize($context, true, true,
                \TYPO3\CMS\Core\Core\Environment::getProjectPath(), \TYPO3\CMS\Core\Core\Environment::getPublicPath(),
                \TYPO3\CMS\Core\Core\Environment::getVarPath(), \TYPO3\CMS\Core\Core\Environment::getConfigPath(),
                \TYPO3\CMS\Core\Core\Environment::getCurrentScript(), PHP_OS);
        };
        try {
            $changeContext(new \TYPO3\CMS\Core\Core\ApplicationContext('Development/Clone'));
            self::assertSame('', $store->read()['revision']);
            try { (new PeerConfiguration())->load(); self::fail('Copied credentials activated'); } catch (\RuntimeException) {}
        } finally {
            $changeContext($context);
        }
        self::assertSame($this->config, (new PeerConfiguration())->load());
    }

    #[Test]
    public function corruptedCiphertextFailsClosed(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $this->get(ConnectionPool::class)->getConnectionForTable(ConnectionStore::TABLE)
            ->executeStatement('UPDATE ' . ConnectionStore::TABLE . " SET payload = 'corrupted'");
        $this->expectException(\RuntimeException::class);
        (new PeerConfiguration())->load();
    }

    #[Test]
    public function staleEditsAndReimportsCannotOverwriteCredentials(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $state = $store->read();
        $changed = $this->config;
        $changed['outgoing']['peer']['token'] = str_repeat('b', 64);
        $store->save($changed, $state['revision']);
        try { $store->save($this->config, $state['revision']); self::fail('Stale edit accepted'); } catch (\RuntimeException) {}
        try { $store->import(); self::fail('Reimport accepted'); } catch (\RuntimeException) {}
        self::assertSame(str_repeat('b', 64), $store->read()['config']['outgoing']['peer']['token']);
    }

    #[Test]
    public function adminFormsNeverRedisplaySavedSecretsAndEditorsCannotUseActions(): void
    {
        $this->get(ConnectionStore::class)->import();
        $controller = $this->get(ConnectionsController::class);
        $response = $controller->handleRequest($this->request());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString(str_repeat('a', 64), (string)$response->getBody());
        self::assertStringContainsString('Outgoing connections', (string)$response->getBody());
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame(403, $controller->handleRequest($this->request('POST', ['csrf' => 'forged', 'action' => 'removeOutgoing', 'name' => 'peer']))->getStatusCode());
        $request = $this->request('POST', ['action' => 'removeOutgoing', 'name' => 'peer']);
        $this->setUpBackendUser(2);
        self::assertSame(403, $controller->handleRequest($this->request())->getStatusCode());
        self::assertSame(403, $controller->handleRequest($request)->getStatusCode());
        self::assertArrayHasKey('peer', $this->get(ConnectionStore::class)->read()['config']['outgoing']);
    }

    #[Test]
    public function outgoingTokenIsGeneratedOnceAndIncomingTokenIsHashed(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $controller = $this->get(ConnectionsController::class);
        $response = $controller->handleRequest($this->request('POST', ['action' => 'outgoing', 'name' => 'second',
            'instance' => PeerConfiguration::uuid(), 'endpoint' => 'https://second.example/typo3-exchange/v1/resolve',
            'origins' => 'https://second.example', 'enabled' => '1']));
        self::assertSame(200, $response->getStatusCode());
        $token = $store->read()['config']['outgoing']['second']['token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertStringContainsString($token, (string)$response->getBody());
        self::assertStringNotContainsString($token, (string)$controller->handleRequest($this->request())->getBody());
        $remote = PeerConfiguration::uuid();
        $response = $controller->handleRequest($this->request('POST', ['action' => 'incoming', 'instance' => $remote,
            'peerToken' => $token, 'sites' => 'main', 'rate' => '120', 'enabled' => '1']));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(hash('sha256', $token), $store->read()['config']['incoming'][$remote]['tokenHash']);
        self::assertStringNotContainsString($token, (string)$response->getBody());
        self::assertStringNotContainsString(hash('sha256', $token), (string)$response->getBody());
        $GLOBALS['BE_USER']->user['lang'] = 'de';
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');
        self::assertStringContainsString('Instanzverbindungen', (string)$controller->handleRequest($this->request())->getBody());
    }

    #[Test]
    public function renderedConnectionFormsSubmitThroughBackendRouting(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $handler = $this->get(RequestHandler::class);
        $newOutgoing = "//form[input[@name='action' and @value='outgoing']][last()]";
        $request = $this->connectionFormRequest($newOutgoing, ['name' => 'second',
            'instance' => PeerConfiguration::uuid(), 'endpoint' => 'https://second.example/typo3-exchange/v1/resolve',
            'origins' => 'https://second.example']);
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode(), 'Saving a rendered form must not redirect to login.');
        $token = $store->read()['config']['outgoing']['second']['token'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertStringContainsString($token, (string)$response->getBody());
        $outgoing = "//form[input[@name='action' and @value='outgoing'] and input[@name='name' and @value='second']]";
        $response = $handler->handle($this->connectionFormRequest($outgoing));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($token, $store->read()['config']['outgoing']['second']['token']);
        self::assertStringNotContainsString($token, (string)$response->getBody());

        $replacement = str_repeat('b', 64);
        $response = $handler->handle($this->connectionFormRequest($outgoing, [], $replacement));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($replacement, $store->read()['config']['outgoing']['second']['token']);
        self::assertStringNotContainsString($replacement, (string)$response->getBody());
        $response = $handler->handle($this->connectionFormRequest($outgoing, ['rotate' => '1']));
        self::assertSame(200, $response->getStatusCode());
        $rotated = $store->read()['config']['outgoing']['second']['token'];
        self::assertNotSame($replacement, $rotated);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $rotated);
        self::assertStringContainsString($rotated, (string)$response->getBody());

        $remote = PeerConfiguration::uuid();
        $incoming = "//form[input[@name='action' and @value='incoming']][last()]";
        $response = $handler->handle($this->connectionFormRequest($incoming, [
            'instance' => $remote, 'sites' => 'main', 'rate' => '120',
        ], $rotated));
        self::assertSame(200, $response->getStatusCode());
        $hash = $store->read()['config']['incoming'][$remote]['tokenHash'];
        self::assertSame(hash('sha256', $rotated), $hash);
        self::assertStringNotContainsString($rotated, (string)$response->getBody());
        self::assertStringNotContainsString($hash, (string)$response->getBody());

        $incoming = "//form[input[@name='action' and @value='incoming'] and input[@name='instance' and @value='$remote']]";
        $response = $handler->handle($this->connectionFormRequest($incoming));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($hash, $store->read()['config']['incoming'][$remote]['tokenHash']);
        $response = $handler->handle($this->connectionFormRequest($incoming, [], $replacement));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(hash('sha256', $replacement), $store->read()['config']['incoming'][$remote]['tokenHash']);

        $state = $store->read();
        self::assertSame(403, $handler->handle($this->connectionFormRequest($outgoing, ['csrf' => 'forged']))->getStatusCode());
        $request = $this->connectionFormRequest($outgoing)->withQueryParams(['token' => 'forged']);
        self::assertSame(302, $handler->handle($request)->getStatusCode());
        self::assertSame(302, $handler->handle($request->withQueryParams([]))->getStatusCode());
        self::assertSame($state, $store->read());
    }

    private function connectionFormRequest(string $selector, array $values = [], string $credential = ''): ServerRequest
    {
        $response = $this->get(ConnectionsController::class)->handleRequest($this->request());
        $document = (new HTML5(['disable_html_ns' => true]))->loadHTML((string)$response->getBody());
        $xpath = new \DOMXPath($document);
        $form = $xpath->query($selector)->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $body = [];
        foreach ($xpath->query('.//input[@name] | .//textarea[@name]', $form) as $field) {
            if ($field->getAttribute('type') === 'checkbox' && !$field->hasAttribute('checked')) {
                continue;
            }
            $body[$field->getAttribute('name')] = match ($field->getAttribute('type')) {
                'password' => $credential,
                default => $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value'),
            };
        }
        $uri = new Uri('https://typo3-testing.local' . $form->getAttribute('action'));
        parse_str($uri->getQuery(), $query);
        $request = $this->request()->withMethod('POST')->withUri($uri)
            ->withHeader('Referer', (string)$uri)->withHeader('Sec-Fetch-Dest', 'iframe')
            ->withQueryParams($query)->withParsedBody(array_replace($body, $values));
        return $request->withAttribute('route', $this->get(Router::class)->matchResult($request)->getRoute());
    }

    #[Test]
    public function invalidEndpointsAndArrayInputsAreRejectedWithoutSecretEcho(): void
    {
        $store = $this->get(ConnectionStore::class);
        $store->import();
        $controller = $this->get(ConnectionsController::class);
        foreach (['http://peer.example/typo3-exchange/v1/resolve', 'https://peer.example/redirect', 'https://user:pass@peer.example/typo3-exchange/v1/resolve'] as $endpoint) {
            $response = $controller->handleRequest($this->request('POST', ['action' => 'outgoing', 'name' => 'peer',
                'instance' => $this->config['outgoing']['peer']['instance'], 'endpoint' => $endpoint,
                'origins' => 'https://peer.example', 'peerToken' => str_repeat('c', 64), 'enabled' => '1']));
            self::assertSame(400, $response->getStatusCode());
            self::assertStringNotContainsString(str_repeat('c', 64), (string)$response->getBody());
        }
        self::assertSame(400, $controller->handleRequest($this->request('POST', ['action' => ['removeOutgoing']]))->getStatusCode());
        self::assertSame(str_repeat('a', 64), $store->read()['config']['outgoing']['peer']['token']);
    }
}
