<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Integration;

use GuzzleHttp\Promise\Create;
use Symfony\Component\Yaml\Yaml;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use RuntimeException;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use stdClass;


#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RefreshTest extends TestCase
{
    private int $outputBufferLevel;

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function testDestinationRefreshLifecycle(): void
    {
        $this->outputBufferLevel = ob_get_level();
        if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing' || !in_array(getenv('DDEV_SITENAME'), ['t3exchange-v13', 't3exchange-v14'], true)) {
            throw new RuntimeException('Use only the isolated Testing-context DDEV instances.');
        }
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/vendor/bin/typo3';
        $loader = require '/var/www/html/vendor/autoload.php';
        SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($loader);
        $connections = $container->get(ConnectionPool::class);
        $db = $connections->getConnectionForTable('pages');
        $dest = $connections->getConnectionForTable(DestinationStore::TABLE);
        $store = $container->get(DestinationStore::class);
        $worker = $container->get(RefreshWorker::class);
        $cache = $container->get(CacheManager::class);
        $client = new PeerClient(new PeerConfiguration(), $container->get(RequestFactory::class));
        $path = getenv('TYPO3_EXCHANGE_CONFIG');
        $originalConfig = file_get_contents($path);
        $sitePath = '/var/www/html/var/exchange-testing/config/sites/main/config.yaml';
        $originalSite = file_get_contents($sitePath);
        $contentId = null;
        $config = json_decode($originalConfig, true, 32, JSON_THROW_ON_ERROR);
        $origin = getenv('EXCHANGE_TEST_ORIGIN');
        $token = bin2hex(random_bytes(32));
        $config['incoming'][$config['instance']] = ['enabled' => true, 'tokenHash' => hash('sha256', $token), 'sites' => ['main'], 'requestsPerMinute' => 120];
        $config['outgoing'] = ['self' => ['enabled' => true, 'instance' => $config['instance'], 'endpoint' => $origin . '/typo3-exchange/v1/resolve', 'origins' => [$origin, 'https://old.invalid'], 'token' => $token]];
        $config['publicAliases'] = ['https://old.invalid' => $origin];
        $handlers = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? [];
        $fixtures = [];
        $references = [];
        $originalDestinations = $dest->select(['*'], DestinationStore::TABLE, ['instance_uuid' => $config['instance']])->fetchAllAssociative();
        $cleaned = false;
        $cleanup = static function () use (&$cleaned, &$fixtures, &$references, $db, $dest, $connections, $cache, $path, $originalConfig, $handlers, $originalDestinations, $sitePath, $originalSite, &$contentId): void {
            if ($cleaned) { return; }
            $cleaned = true;
            file_put_contents($path, $originalConfig, LOCK_EX);
            file_put_contents($sitePath, $originalSite);
            if ($contentId !== null) {
                $connections->getConnectionForTable('tt_content')->delete('tt_content', ['uid' => $contentId]);
            }
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $handlers;
            foreach (array_reverse($fixtures) as $id) {
                $db->delete('pages', ['uid' => $id]);
                $connections->getConnectionForTable('tx_typo3totypo3_identity')->delete('tx_typo3totypo3_identity', ['page_uid' => $id]);
            }
            foreach ($references as $reference) {
                $dest->delete(DestinationStore::TABLE, ['reference_key' => ManagedLink::key($reference)]);
            }
            foreach ($originalDestinations as $row) {
                $dest->update(DestinationStore::TABLE, $row, ['reference_key' => $row['reference_key']]);
            }
            $cache->flushCaches();
        };
        register_shutdown_function($cleanup);

        $due = static function (array $reference) use (&$dest): void
        {
            $dest->update(DestinationStore::TABLE, ['next_refresh' => 0], ['reference_key' => ManagedLink::key($reference)]);
        };
        $saveConfig = static function () use (&$path, &$config): void
        {
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR), LOCK_EX);
        };
        try {
            $dest->update(DestinationStore::TABLE, ['next_refresh' => time() + 86400], ['instance_uuid' => $config['instance']]);
            $saveConfig();
            $slug = '/refresh-test-' . bin2hex(random_bytes(6));
            $db->insert('pages', ['pid' => 1, 'title' => 'Refresh fixture', 'slug' => $slug, 'doktype' => 1]);
            $fixtures[] = $id = (int)$db->lastInsertId();
            $initial = $client->resolve('self', [$origin . $slug])[0];
            self::assertTrue($initial['status'] === 'resolved', 'Real HTTPS resolver establishes destination');
            $references[] = $ref = $initial['reference'];
            $store->record($ref, 'resolved', $initial['url']);
            self::assertTrue($worker->run()['processed'] === 0, 'Fresh destinations are not immediately refreshed');
            $alias = $client->resolve('self', ['https://old.invalid' . $slug . '?utm_source=x#section'])[0];
            self::assertTrue($alias['reference'] === $ref && $alias['url'] === $origin . $slug . '?utm_source=x#section', 'Explicit old origin resolves locally and preserves suffixes');
            $config['publicAliases'] = ['https://old.invalid' => 'https://unconfigured.invalid'];
            $saveConfig();
            self::assertTrue($client->resolve('self', ['https://old.invalid' . $slug])[0]['status'] === 'unavailable', 'Alias target still requires a configured granted site');
            $config['publicAliases'] = ['https://old.invalid' => $origin];
            $saveConfig();
            $managed = (new ManagedLink())->asString($ref + ['query' => 'utm_source=x%20y', 'fragment' => 'heading']);
            $contentDb = $connections->getConnectionForTable('tt_content');
            $contentDb->insert('tt_content', ['pid' => 1, 'CType' => 'header', 'header_link' => $managed]);
            $contentId = (int)$contentDb->lastInsertId();
            $request = (new ServerRequest($origin))->withAttribute('frontend.cache.collector', new CacheDataCollector());
            $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
            $cObj->setRequest($request);
            $render = static fn() => $cObj->typoLink('Read more', ['parameter' => $managed]);
            $render();
            $tags = array_map(static fn($tag) => $tag->name, $request->getAttribute('frontend.cache.collector')->getCacheTags());
            self::assertTrue(in_array(DestinationStore::peerTag($ref['instance']), $tags, true), 'Rendering registers peer dependency for connection-wide denial');
            $db->update('pages', ['slug' => $slug . '-renamed'], ['uid' => $id]);
            $cache->flushCaches();
            $pageCache = $cache->getCache('pages');
            $pageCache->set('exchange_refresh_affected', 'old output', [DestinationStore::tag($ref)]);
            $pageCache->set('exchange_refresh_other', 'other output', ['exchange_unrelated']);
            $due($ref);
            self::assertTrue($worker->run()['processed'] === 1, 'Due destination refreshes by identity over HTTPS');
            self::assertTrue($store->find($ref)['url'] === $origin . $slug . '-renamed', 'Renamed page updates the persistent canonical URL');
            self::assertTrue(!$pageCache->has('exchange_refresh_affected') && $pageCache->has('exchange_refresh_other'), 'Refresh invalidates dependent page cache only');
            self::assertTrue(str_contains($render(), $slug . '-renamed?utm_source=x%20y#heading'), 'Rendering uses renamed URL with unchanged per-link suffixes');
            $db->update('pages', ['hidden' => 1], ['uid' => $id]);
            $cache->flushCaches();
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'unavailable' && $render() === 'Read more', 'Confirmed hidden page becomes non-clickable');
            $db->update('pages', ['hidden' => 0], ['uid' => $id]);
            $cache->flushCaches();
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'resolved' && str_contains($render(), '<a'), 'Restored page becomes clickable again');

            $db->insert('pages', ['pid' => 1, 'title' => 'Refresh parent', 'slug' => $slug . '-parent', 'doktype' => 1]);
            $fixtures[] = $parent = (int)$db->lastInsertId();
            $db->update('pages', ['pid' => $parent], ['uid' => $id]);
            $cache->flushCaches();
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'resolved', 'Page-tree move retains the verified identity');
            $db->update('pages', ['fe_group' => '99', 'extendToSubpages' => 1], ['uid' => $parent]);
            $cache->flushCaches();
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'unavailable', 'Inherited access restriction removes clickability');
            $db->update('pages', ['fe_group' => ''], ['uid' => $parent]);
            $cache->flushCaches();
            $due($ref);
            $worker->run();
            $site = Yaml::parse($originalSite);
            $site['languages'][] = ['title' => 'German test', 'enabled' => true, 'languageId' => 1, 'base' => '/de/',
                'locale' => 'de_DE.UTF-8', 'navigationTitle' => 'Deutsch', 'flag' => 'de', 'fallbackType' => 'fallback', 'fallbacks' => '0'];
            file_put_contents($sitePath, Yaml::dump($site, 10, 2));
            $cache->flushCaches();
            $references[] = $translatedRef = array_replace($ref, ['language' => 1]);
            $store->record($translatedRef, 'resolved', $origin . '/de/old');
            $due($translatedRef);
            $worker->run();
            self::assertTrue($store->find($translatedRef)['status'] === 'unavailable', 'Missing selected translation never falls back during refresh');
            $db->insert('pages', ['pid' => $parent, 'title' => 'Translation', 'slug' => $slug . '-de', 'doktype' => 1,
                'sys_language_uid' => 1, 'l10n_parent' => $id]);
            $fixtures[] = $translated = (int)$db->lastInsertId();
            $cache->flushCaches();
            $due($translatedRef);
            $worker->run();
            self::assertTrue($store->find($translatedRef)['url'] === $origin . '/de' . $slug . '-de', 'Newly available translation restores exact-language URL');
            $db->update('pages', ['slug' => $slug . '-de-renamed'], ['uid' => $translated]);
            $cache->flushCaches();
            $due($translatedRef);
            $worker->run();
            self::assertTrue($store->find($translatedRef)['url'] === $origin . '/de' . $slug . '-de-renamed', 'Translated rename refreshes without changing selected language');
            $dest->delete(DestinationStore::TABLE, ['reference_key' => ManagedLink::key($translatedRef)]);
            file_put_contents($sitePath, $originalSite);
            $cache->flushCaches();

            // Deterministic transport faults and races use the real client and persisted worker state.
            $fake = (object)['status' => 200, 'mode' => 'resolved', 'calls' => [], 'suffix' => '', 'onRequest' => null, 'origin' => $origin];
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function ($request, array $options) use ($fake, $origin, $config) {
                $refs = json_decode((string)$request->getBody(), true)['references'];
                $fake->calls[] = ['refs' => $refs, 'endpoint' => (string)$request->getUri(), 'options' => $options];
                if ($fake->onRequest) { ($fake->onRequest)(); }
                if ($fake->mode === 'timeout') { throw new RuntimeException('Simulated timeout'); }
                $results = array_map(static function ($reference) use ($fake, $origin) {
                    if ($fake->mode === 'wrong-identity') { $reference['page'] = PeerConfiguration::uuid(); }
                    if ($fake->mode === 'wrong-language') { ++$reference['language']; }
                    return ['status' => in_array($fake->mode, ['unavailable', 'unsupported'], true) ? $fake->mode : 'resolved',
                        'reference' => $reference, 'url' => $fake->origin . '/verified' . $fake->suffix];
                }, $refs);
                return Create::promiseFor(new JsonResponse(['protocol' => 1, 'instance' => $config['instance'], 'results' => $results], $fake->status));
            }];
            foreach ([503, 429, 302] as $status) {
                $fake->status = $status;
                $due($ref);
                $worker->run();
                self::assertTrue($store->find($ref)['status'] === 'stale' && str_contains($render(), '-renamed'), 'HTTP ' . $status . ' retains the last verified URL');
            }
            $fake->status = 200;
            foreach (['timeout', 'wrong-identity', 'wrong-language', 'unsupported'] as $mode) {
                $fake->mode = $mode;
                $due($ref);
                $worker->run();
                self::assertTrue($store->find($ref)['status'] === 'stale' && str_contains($store->find($ref)['url'], '-renamed'), $mode . ' cannot retarget or remove a verified destination');
            }
            $fake->mode = 'resolved';
            $fake->suffix = '?unexpected=1';
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'stale', 'Refresh rejects per-link suffixes in canonical URLs');
            $fake->suffix = '';
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'resolved' && (int)$store->find($ref)['attempts'] === 0, 'Successful refresh resets backoff and stale state');
            $last = end($fake->calls);
            self::assertTrue($last['refs'] === [$ref] && $last['options']['verify'] === true && $last['options']['allow_redirects'] === false && $last['options']['timeout'] <= 3, 'Refresh uses explicit identity, verified TLS, no redirects, and bounded timeout');

            $fake->mode = 'unavailable';
            $fake->suffix = '?untrusted=1';
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['url'] === $origin . '/verified', 'Unavailable response cannot replace last verified URL with extra payload data');
            $fake->suffix = '';
            $fake->mode = 'timeout';
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'unavailable' && $render() === 'Read more', 'Outage never restores a confirmed unavailable link');
            self::assertTrue((int)$store->find($ref)['next_refresh'] > time(), 'Transient errors schedule exponential backoff');
            $fake->mode = 'resolved';
            $fake->onRequest = static fn() => $store->record($ref, 'resolved', $origin . '/newer-save');
            $due($ref);
            $worker->run();
            self::assertTrue($store->find($ref)['url'] === $origin . '/newer-save', 'Late refresh cannot overwrite a newer save-time resolution');
            $fake->onRequest = null;
            $due($ref);
            $claim = $store->claim($ref['instance'], 1);
            self::assertTrue(count($claim) === 1 && $store->claim($ref['instance'], 1) === [], 'Lease prevents duplicate concurrent refresh');
            $dest->update(DestinationStore::TABLE, ['lease_until' => time() - 1], ['reference_key' => ManagedLink::key($ref)]);
            $replacement = $store->claim($ref['instance'], 1);
            self::assertTrue(count($replacement) === 1 && !$store->finish($claim[0], null, 'test'), 'Expired lease is reclaimable and superseded worker cannot finish');
            $store->finish($replacement[0], null, 'test');

            for ($i = 0; $i < 52; ++$i) {
                $references[] = $extra = ['instance' => $ref['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 2];
                $store->record($extra, 'resolved', $origin . '/old');
                $due($extra);
            }
            $due($ref);
            $fake->calls = [];
            self::assertTrue($worker->run(51)['processed'] === 51 && count($fake->calls) === 2, 'Worker respects run limit and fifty-reference batch bound');
            self::assertTrue(count($fake->calls[0]['refs']) === 50 && count($fake->calls[1]['refs']) === 1, 'Batches contain explicit identities including language');
            $pageCache->set('exchange_refresh_peer', 'old output', [DestinationStore::peerTag($ref['instance'])]);
            $fake->status = 403;
            $due($ref);
            $worker->run(1);
            self::assertTrue($store->find($extra)['status'] === 'denied' && $store->find($ref)['status'] === 'denied', 'Credential denial suppresses every destination for that peer beyond the current batch');
            self::assertTrue(!$pageCache->has('exchange_refresh_peer') && $render() === 'Read more', 'Connection denial invalidates peer-tagged output');
            self::assertTrue($worker->run()['processed'] === 0, 'Denied peer stays paused without repeated requests');
            $fake->status = 200;
            $worker->run(1, 'self');
            $deniedCount = (int)$dest->executeQuery('SELECT COUNT(*) FROM ' . DestinationStore::TABLE . ' WHERE instance_uuid = ? AND status = ? AND next_refresh < ?', [$ref['instance'], 'denied', time() + 3600])->fetchOne();
            self::assertTrue($deniedCount === 52, 'Administrator resume restores only individually verified destinations');
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'resolved', 'Resumed peer eventually verifies remaining destinations');
            $due($ref);
            $fake->status = 401;
            $worker->run(1);
            $config['outgoing']['self']['token'] = bin2hex(random_bytes(32));
            $config['outgoing']['self']['endpoint'] = 'https://new-api.invalid/typo3-exchange/v1/resolve';
            $config['outgoing']['self']['origins'][] = 'https://new-public.invalid';
            $fake->origin = 'https://new-public.invalid';
            $saveConfig();
            $fake->status = 200;
            $worker->run();
            self::assertTrue($store->find($ref)['status'] === 'resolved' && end($fake->calls)['endpoint'] === $config['outgoing']['self']['endpoint'], 'Explicit credential and API endpoint correction resumes identity checks');
            self::assertTrue($store->find($ref)['url'] === 'https://new-public.invalid/verified', 'Explicit public domain update preserves managed identity');
            $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () { throw new LogicException('Frontend HTTP forbidden'); }];
            $cache->flushCaches();
            self::assertTrue(str_contains($render(), '/verified?utm_source=x%20y#heading'), 'Persistent refreshed data renders without HTTP after cache clear');
            self::assertTrue($contentDb->select(['header_link'], 'tt_content', ['uid' => $contentId])->fetchOne() === $managed, 'Refresh preserves the stored identity and per-link suffixes');

        } finally {
            $cleanup();
        }
    }
}
