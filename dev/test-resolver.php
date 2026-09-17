<?php

declare(strict_types=1);

// Run only in the disposable DDEV instances. Fixtures and the temporary grant are removed in finally.
use Lizard\Typo3ToTypo3\Middleware\Resolve;
use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use GuzzleHttp\Promise\Create;
use Symfony\Component\Yaml\Yaml;

if (getenv('TYPO3_CONTEXT') !== 'Development' || !in_array(getenv('DDEV_SITENAME'), ['t3exchange-v13', 't3exchange-v14'], true)) {
    throw new RuntimeException('This check requires one of the dedicated development instances.');
}
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/vendor/bin/typo3';
$loader = require '/var/www/html/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$http = $container->get(RequestFactory::class);
$connections = $container->get(ConnectionPool::class);
$db = $connections->getConnectionForTable('pages');
$cache = $container->get(CacheManager::class);
$configPath = getenv('TYPO3_EXCHANGE_CONFIG');
$originalConfig = file_get_contents($configPath);
$sitePath = '/var/www/html/config/sites/main/config.yaml';
$originalSite = file_get_contents($sitePath);
$config = json_decode($originalConfig, true, 32, JSON_THROW_ON_ERROR);
$testPeer = PeerConfiguration::uuid();
$testToken = bin2hex(random_bytes(32));
$config['incoming'][$testPeer] = ['enabled' => true, 'tokenHash' => hash('sha256', $testToken), 'sites' => ['main'], 'requestsPerMinute' => 120];
$origin = getenv('DDEV_PRIMARY_URL');
$fixtures = [];
$checks = 0;
$originalHttpHandlers = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? [];
$cleaned = false;
$cleanup = static function () use (&$cleaned, &$fixtures, $configPath, $originalConfig, $sitePath, $originalSite, $db, $connections, $cache, $originalHttpHandlers): void {
    if ($cleaned) {
        return;
    }
    $cleaned = true;
    file_put_contents($configPath, $originalConfig, LOCK_EX);
    file_put_contents($sitePath, $originalSite);
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $originalHttpHandlers;
    foreach (array_reverse($fixtures) as $id) {
        $db->delete('pages', ['uid' => $id]);
        $connections->getConnectionForTable('tx_typo3totypo3_identity')->delete('tx_typo3totypo3_identity', ['page_uid' => $id]);
    }
    $cache->flushCaches();
};
register_shutdown_function($cleanup);

function check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    ++$checks;
    echo "OK: $description\n";
}

function saveConfig(): void
{
    global $configPath, $config;
    file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX);
}

function request(array|string $payload, array $options = []): array
{
    global $http, $origin, $testPeer, $testToken;
    $headers = array_replace([
        'Authorization' => 'Bearer ' . $testToken,
        'X-TYPO3-Peer' => $testPeer,
        'Content-Type' => 'application/json',
    ], $options['headers'] ?? []);
    $response = $http->request(($options['origin'] ?? $origin) . Resolve::PATH, $options['method'] ?? 'POST', [
        'headers' => $headers,
        'body' => is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR),
        'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 5,
    ]);
    $body = (string)$response->getBody();
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Expected a JSON response, received HTTP ' . $response->getStatusCode());
    }
    return [$response->getStatusCode(), $decoded, $response->getHeaderLine('Cache-Control')];
}

function resolveUrl(string $url): array
{
    [$status, $body] = request(['urls' => [$url]]);
    if ($status !== 200) {
        throw new RuntimeException('Resolver returned HTTP ' . $status . ': ' . json_encode($body));
    }
    return $body['results'][0];
}

function page(array $values = []): int
{
    global $db, $fixtures;
    $db->insert('pages', array_replace([
        'pid' => 1, 'title' => 'Exchange integration fixture',
        'slug' => '/exchange-test-' . bin2hex(random_bytes(6)),
        'doktype' => 1, 'hidden' => 0, 'deleted' => 0, 'tstamp' => time(), 'crdate' => time(),
    ], $values));
    $id = (int)$db->lastInsertId();
    $fixtures[] = $id;
    return $id;
}

function changePage(int $id, array $values): void
{
    global $db, $cache;
    $db->update('pages', $values, ['uid' => $id]);
    $cache->flushCaches();
}

try {
    saveConfig();
    $root = resolveUrl($origin . '/');
    check($root['status'] === 'resolved', 'public root resolves');
    check(PeerConfiguration::isUuid($root['reference']['page']), 'destination identity is a UUID');
    check(resolveUrl($origin . '/')['reference'] === $root['reference'], 'repeat lookups retain identity');
    $suffix = '?campaign=email&utm_source=test#contact';
    check(resolveUrl($origin . '/' . $suffix)['url'] === $origin . '/' . $suffix, 'ordinary query parameters and fragment survive');
    check(resolveUrl(str_replace('https:', 'http:', $origin) . '/')['status'] === 'resolved', 'readable HTTP URL resolves to canonical HTTPS');
    foreach (['?id=1', '?ADMCMD_previewWS=1', '?tx_news_pi1[news]=1', '?access_token=secret'] as $suffix) {
        check(resolveUrl($origin . '/' . $suffix)['status'] === 'unsupported', 'unsupported query rejected: ' . explode('=', $suffix)[0]);
    }
    check(resolveUrl($origin . '/does-not-exist-' . bin2hex(random_bytes(4)))['status'] === 'unavailable', 'missing route is explicitly unavailable');
    check(resolveUrl('https://unconfigured.invalid/')['status'] === 'unavailable', 'unconfigured domain is not fetched');
    check(resolveUrl('https://admin:secret@example.test/')['status'] === 'unsupported', 'credential-bearing URLs rejected');
    [$status, , $cacheControl] = request(['urls' => [$origin . '/']]);
    check($status === 200 && str_contains($cacheControl, 'no-store'), 'API responses cannot be cached');
    check(request(['urls' => [$origin . '/']], ['headers' => ['Authorization' => '']])[0] === 401, 'missing credential denied');
    check(request(['urls' => [$origin . '/']], ['headers' => ['Authorization' => 'Bearer ' . str_repeat('0', 64)]])[0] === 401, 'wrong credential denied');
    check(request(['urls' => [$origin . '/']], ['headers' => ['X-TYPO3-Peer' => PeerConfiguration::uuid()]])[0] === 401, 'credential cannot authenticate another peer');
    $otherPeer = array_key_first(json_decode($originalConfig, true)['incoming']);
    check(request(['urls' => [$origin . '/']], ['headers' => ['X-TYPO3-Peer' => $otherPeer]])[0] === 401, 'valid token cannot use another existing peers grant');
    check(request(['urls' => [$origin . '/']], ['method' => 'GET'])[0] === 405, 'GET cannot resolve destinations');
    check(request('{}', ['headers' => ['Content-Type' => 'text/plain']])[0] === 415, 'non-JSON content rejected');
    check(request('{')[0] === 400, 'malformed JSON rejected');
    check(request(['urls' => array_fill(0, 51, $origin . '/')])[0] === 400, 'oversized batch rejected');
    check(request(str_repeat(' ', 65537))[0] === 413, 'oversized request body rejected');
    check(request(['urls' => [$origin . '/']], ['origin' => str_replace('https:', 'http:', $origin)])[0] === 400, 'plain HTTP API calls rejected');

    $id = page();
    $slug = $db->select(['slug'], 'pages', ['uid' => $id])->fetchOne();
    $initial = resolveUrl($origin . $slug);
    check($initial['status'] === 'resolved', 'new public page resolves');
    $renamed = $slug . '-renamed';
    changePage($id, ['slug' => $renamed]);
    check(resolveUrl($origin . $renamed)['reference'] === $initial['reference'], 'renaming preserves stable identity');
    [$status, $body] = request(['references' => [$initial['reference']]]);
    check($status === 200 && $body['results'][0]['url'] === $origin . $renamed, 'identity refresh returns renamed URL');
    $copy = page(['slug' => $slug . '-copy']);
    check(resolveUrl($origin . $slug . '-copy')['reference'] !== $initial['reference'], 'new page receives distinct identity');
    foreach ([['hidden' => 1], ['fe_group' => '99'], ['starttime' => time() + 3600], ['endtime' => time() - 3600], ['deleted' => 1], ['t3ver_wsid' => 1], ['doktype' => 4]] as $restriction) {
        changePage($id, $restriction);
        [$status, $body] = request(['references' => [$initial['reference']]]);
        check($status === 200 && $body['results'][0] === ['status' => 'unavailable'], 'no metadata for page restriction: ' . array_key_first($restriction));
        changePage($id, ['hidden' => 0, 'fe_group' => '', 'starttime' => 0, 'endtime' => 0, 'deleted' => 0, 't3ver_wsid' => 0, 'doktype' => 1]);
    }
    check(resolveUrl($origin . $renamed)['reference'] === $initial['reference'], 'restoring original page restores identity');
    $parent = page(['hidden' => 1, 'extendToSubpages' => 1]);
    changePage($id, ['pid' => $parent]);
    check(resolveUrl($origin . $renamed)['status'] === 'unavailable', 'inherited hidden ancestor blocks access');
    changePage($parent, ['hidden' => 0, 'fe_group' => '99']);
    check(resolveUrl($origin . $renamed)['status'] === 'unavailable', 'inherited group restriction blocks access');
    changePage($parent, ['fe_group' => '', 'extendToSubpages' => 0]);
    check(resolveUrl($origin . $renamed)['reference'] === $initial['reference'], 'moving a page retains identity');
    $site = Yaml::parse($originalSite);
    $site['languages'][] = [
        'title' => 'German test', 'enabled' => true, 'languageId' => 1, 'base' => '/de/',
        'locale' => 'de_DE.UTF-8', 'navigationTitle' => 'Deutsch', 'flag' => 'de',
        'fallbackType' => 'fallback', 'fallbacks' => '0',
    ];
    file_put_contents($sitePath, Yaml::dump($site, 10, 2));
    $cache->flushCaches();
    $germanReference = array_replace($initial['reference'], ['language' => 1]);
    check(request(['references' => [$germanReference]])[1]['results'][0] === ['status' => 'unavailable'], 'configured fallback cannot replace a missing selected translation');
    $translated = page(['pid' => $parent, 'sys_language_uid' => 1, 'l10n_parent' => $id, 'slug' => $slug . '-de']);
    $cache->flushCaches();
    $german = resolveUrl($origin . '/de' . $slug . '-de');
    check($german['status'] === 'resolved' && $german['reference'] === $germanReference, 'translated URL preserves selected language and page identity');
    changePage($translated, ['hidden' => 1]);
    check(request(['references' => [$germanReference]])[1]['results'][0] === ['status' => 'unavailable'], 'hidden translation is unavailable without fallback');
    file_put_contents($sitePath, $originalSite);
    $cache->flushCaches();
    $wrongLanguage = array_replace($initial['reference'], ['language' => 999]);
    check(request(['references' => [$wrongLanguage]])[1]['results'][0]['status'] === 'unavailable', 'missing language never silently falls back');
    $wrongInstance = array_replace($initial['reference'], ['instance' => PeerConfiguration::uuid()]);
    check(request(['references' => [$wrongInstance]])[1]['results'][0]['status'] !== 'resolved', 'foreign instance reference rejected');

    $config['incoming'][$testPeer]['sites'] = ['another-site'];
    saveConfig();
    check(resolveUrl($origin . '/') === ['status' => 'unavailable'], 'site grant checked without disclosing metadata');
    check(request(['references' => [$initial['reference']]])[1]['results'][0] === ['status' => 'unavailable'], 'site grant also checked on identity refresh');
    $config['incoming'][$testPeer]['enabled'] = false;
    saveConfig();
    check(request(['urls' => [$origin . '/']])[0] === 403, 'disabled grant denies access');
    $config['incoming'][$testPeer]['enabled'] = true;
    $config['incoming'][$testPeer]['sites'] = ['main'];
    $config['incoming'][$testPeer]['requestsPerMinute'] = 1;
    $testPeer = PeerConfiguration::uuid();
    $config['incoming'][$testPeer] = array_values($config['incoming'])[count($config['incoming']) - 1];
    saveConfig();
    check(request(['urls' => [$origin . '/']])[0] === 200 && request(['urls' => [$origin . '/']])[0] === 429, 'per-peer rate limit enforced');

    $client = new PeerClient(new PeerConfiguration(), $http);
    foreach ($config['outgoing'] as $name => $peer) {
        $result = $client->resolve($name, [$peer['origins'][0] . '/'])[0];
        check($result['status'] === 'resolved' && $result['reference']['instance'] === $peer['instance'], 'real cross-version authenticated client: ' . $name);
        try {
            $client->resolve($name, ['https://unconfigured.invalid/']);
            throw new LogicException('Unconfigured URL was accepted.');
        } catch (InvalidArgumentException) {
            check(true, 'client rejects unconfigured origins before networking');
        }
        $fake = new stdClass();
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = [
            'exchange-test' => static fn ($next) => static function ($request, array $options) use ($fake) {
                $fake->uri = (string)$request->getUri();
                $fake->options = $options;
                return Create::promiseFor($fake->response);
            },
        ];
        $testClient = new PeerClient(new PeerConfiguration(), $http);
        $valid = ['protocol' => 1, 'instance' => $peer['instance'], 'results' => [$result]];
        $fake->response = new JsonResponse($valid);
        check($testClient->resolve($name, [$peer['origins'][0] . '/'])[0] === $result, 'client accepts a validated peer response');
        check($fake->uri === $peer['endpoint'] && $fake->options['verify'] === true && $fake->options['allow_redirects'] === false && $fake->options['timeout'] === 3.0 && $fake->options['decode_content'] === false, 'client pins endpoint, TLS verification, redirects, decompression and deadline');
        try {
            $fake->options['progress'](65537, 0);
            throw new LogicException('Oversized transfer accepted.');
        } catch (RuntimeException) {
            check(true, 'client aborts oversized transfers');
        }
        foreach ([401, 403, 429, 503, 302, 404] as $status) {
            $fake->response = new JsonResponse(['protocol' => 1, 'error' => 'test'], $status);
            try {
                $testClient->resolve($name, [$peer['origins'][0] . '/']);
                throw new LogicException('Bad HTTP status was accepted.');
            } catch (RuntimeException) {
                check(true, 'client rejects HTTP ' . $status . ' without inferring page deletion');
            }
        }
        $badOrigin = $valid;
        $badOrigin['results'][0]['url'] = 'https://unconfigured.invalid/';
        $badUuid = $valid;
        $badUuid['results'][0]['reference']['page'] = 'invalid';
        foreach ([$badOrigin, $badUuid, array_replace($valid, ['instance' => PeerConfiguration::uuid()]), array_replace($valid, ['results' => []]), ['unrelated' => 'json']] as $bad) {
            $fake->response = new JsonResponse($bad);
            try {
                $testClient->resolve($name, [$peer['origins'][0] . '/']);
                throw new LogicException('Invalid response was accepted.');
            } catch (RuntimeException) {
                check(true, 'client rejects invalid identity, URL or response schema');
            }
        }
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $originalHttpHandlers;
    }
} finally {
    $cleanup();
}
echo "$checks checks passed. Fixtures and temporary grants removed.\n";
