<?php

declare(strict_types=1);

use GuzzleHttp\Promise\Create;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

if (getenv('TYPO3_CONTEXT') !== 'Development' || !in_array(getenv('DDEV_SITENAME'), ['t3exchange-v13', 't3exchange-v14'], true)) {
    throw new RuntimeException('Use the disposable DDEV instances.');
}
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/vendor/bin/typo3';
$loader = require '/var/www/html/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
Bootstrap::initializeBackendAuthentication();
$connections = $container->get(ConnectionPool::class);
$db = $connections->getConnectionForTable('tt_content');
$store = $container->get(DestinationStore::class);
$codec = $container->get(TypoLinkCodecService::class);
$config = (new PeerConfiguration())->load();
$peer = reset($config['outgoing']);
$origin = $peer['origins'][0];
$fixtures = [];
$references = [];
$originalDestinations = $connections->getConnectionForTable(DestinationStore::TABLE)
    ->select(['*'], DestinationStore::TABLE, [])->fetchAllAssociativeIndexed();
$checks = 0;
$handlers = $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] ?? [];
$cleaned = false;
$cleanup = static function () use (&$cleaned, &$fixtures, &$references, $connections, $db, $handlers, $originalDestinations): void {
    if ($cleaned) {
        return;
    }
    $cleaned = true;
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = $handlers;
    foreach ($fixtures as $uid) {
        $db->delete('tt_content', ['uid' => $uid]);
        $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->delete('tx_typo3totypo3_link_outcome', ['table_name' => 'tt_content', 'record_uid' => $uid]);
    }
    foreach ($references as $reference) {
        $key = ManagedLink::key($reference);
        $destinationConnection = $connections->getConnectionForTable(DestinationStore::TABLE);
        if (isset($originalDestinations[$key])) {
            $destinationConnection->update(DestinationStore::TABLE, $originalDestinations[$key], ['reference_key' => $key]);
        } else {
            $destinationConnection->delete(DestinationStore::TABLE, ['reference_key' => $key]);
        }
    }
    $container = GeneralUtility::getContainer();
    $container->get(CacheManager::class)->flushCaches();
};
register_shutdown_function($cleanup);
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    ++$checks;
    echo "OK: $label\n";
}
function save(array $map): DataHandler
{
    $handler = GeneralUtility::makeInstance(DataHandler::class);
    $handler->start(['tt_content' => $map], []);
    $handler->process_datamap();
    check(!$handler->errorLog, 'DataHandler save succeeds: ' . implode('; ', $handler->errorLog));
    return $handler;
}
function fixture(string $link): int
{
    global $db, $fixtures;
    $db->insert('tt_content', ['pid' => 1, 'CType' => 'header', 'header' => 'Exchange link test', 'header_link' => $link]);
    $fixtures[] = $uid = (int)$db->lastInsertId();
    return $uid;
}
function value(int $id): string
{
    global $db;
    return $db->select(['header_link'], 'tt_content', ['uid' => $id])->fetchOne();
}
try {
    // Real HTTPS call to the other major version, through the real DataHandler pipeline.
    $id = fixture('');
    $original = $origin . '/?tracking=abc%20def#heading _blank test-class "A quoted title"';
    save([$id => ['header_link' => $original]]);
    $managed = value($id);
    check(str_starts_with($managed, 't3://exchange?'), 'Readable peer URL becomes a managed link');
    $details = GeneralUtility::makeInstance(LinkService::class)->resolve($codec->decode($managed)['url']);
    $references[] = $details;
    check($details['instance'] === $peer['instance'] && $details['language'] === 0, 'Verified remote identity and language retained');
    check($details['query'] === 'tracking=abc%20def' && $details['fragment'] === 'heading', 'Query and fragment survive the parser round trip');
    check($codec->decode($managed)['title'] === 'A quoted title', 'Typolink attributes survive encoding');
    $outcome = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['*'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $id, 'table_name' => 'tt_content'])->fetchAssociative();
    check($outcome && $outcome['value_hash'] === hash('sha256', $managed), 'Durable outcome matches the actual saved value');

    $GLOBALS['LANG'] = $container->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    $element = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\LinkElement::class);
    $explain = new ReflectionMethod($element, 'getLinkExplanation');
    $explanation = $explain->invoke($element, $managed);
    check(!str_contains($explanation['text'], 'not implemented') && str_contains($explanation['text'], $origin), 'Backend link field displays the readable managed destination');

    // Any HTTP attempt during rendering or saving an existing reference is a hard test failure.
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () { throw new LogicException('Unexpected HTTP'); }];
    $request = (new ServerRequest(getenv('DDEV_PRIMARY_URL')))->withAttribute('frontend.cache.collector', new CacheDataCollector());
    $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
    $cObj->setRequest($request);
    $render = static fn() => $cObj->typoLink('Read <em>more</em>', ['parameter' => $managed]);
    $html = $render();
    check(str_contains($html, 'href="' . $origin . '/?tracking=abc%20def#heading"'), 'Core typolink renders the cached URL and suffix');
    check(str_contains($html, 'target="_blank"') && str_contains($html, 'class="test-class"') && str_contains($html, 'title="A quoted title"') && str_contains($html, 'Read <em>more</em>'), 'Core typolink preserves text and attributes');
    check(count($request->getAttribute('frontend.cache.collector')->getCacheTags()) > 0, 'Rendering registers destination cache dependencies');
    $container->get(CacheManager::class)->flushCaches();
    check($render() === $html, 'Rendering after cache clear uses persistent data without HTTP');
    save([$id => ['header_link' => $managed . ' ']]);
    check(value($id) === $managed, 'Existing managed reference survives a peer outage');
    $editedParts = $codec->decode($managed);
    $editedParts['title'] = 'Edited title while offline';
    save([$id => ['header_link' => $codec->encode($editedParts)]]);
    check($codec->decode(value($id))['title'] === $editedParts['title'], 'Managed link attributes remain editable without contacting the peer');
    save([$id => ['header_link' => $managed]]);
    $pageCache = $container->get(CacheManager::class)->getCache('pages');
    $pageCache->set('exchange_test_affected', 'old output', [DestinationStore::tag($details)]);
    $pageCache->set('exchange_test_unrelated', 'unrelated output', ['unrelated_exchange_test']);
    $store->record($details, 'stale');
    check(!$pageCache->has('exchange_test_affected') && $pageCache->has('exchange_test_unrelated'), 'Destination state change invalidates only dependent page cache entries');
    check($render() === $html, 'Transient outage keeps the last known URL');
    foreach (['unavailable', 'denied'] as $state) {
        $store->record($details, $state);
        check($render() === 'Read <em>more</em>', $state . ' destination renders plain text');
        check(value($id) === $managed, 'Unavailable state retains the stable stored reference');
    }
    $store->record($details, 'resolved', $origin . '/moved');
    check(str_contains($render(), '/moved?tracking=abc%20def#heading'), 'Refreshed destination changes rendering without rewriting content');

    // Controlled transport responses test duplicate saves, language, failures, and timeout options.
    $fake = new stdClass();
    $fake->calls = [];
    $fake->status = 200;
    $fake->resultStatus = 'resolved';
    $fake->delay = 0;
    $fake->reference = ['instance' => $peer['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 2];
    $references[] = $fake->reference;
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function ($request, array $options) use ($fake, $peer) {
        $urls = json_decode((string)$request->getBody(), true)['urls'];
        $fake->calls[] = ['urls' => $urls, 'timeout' => $options['timeout']];
        if ($fake->delay) { usleep($fake->delay); }
        return Create::promiseFor(new JsonResponse(['protocol' => 1, 'instance' => $peer['instance'], 'results' => array_map(static fn($url) => ['status' => $fake->resultStatus, 'reference' => $fake->reference, 'url' => $url], $urls)], $fake->status));
    }];
    $a = fixture(''); $b = fixture('');
    save([$a => ['header_link' => $origin . '/dedup'], $b => ['header_link' => $origin . '/dedup']]);
    check(count($fake->calls) === 1, 'Duplicate URLs across records resolve only once per save');
    check(str_contains(value($a), 'language=2'), 'Resolved non-default language is retained');
    $fake->calls = [];
    $fake->delay = 200000;
    save([$a => ['header_link' => $origin . '/budget-a'], $b => ['header_link' => $origin . '/budget-b']]);
    check(count($fake->calls) === 2 && $fake->calls[1]['timeout'] < $fake->calls[0]['timeout'] - 0.15, 'Later records receive only the remaining aggregate timeout');
    $fake->calls = [];
    $fake->delay = 3050000;
    save([$a => ['header_link' => $origin . '/exhaust-a'], $b => ['header_link' => $origin . '/exhaust-b']]);
    check(count($fake->calls) === 1 && value($b) === $origin . '/exhaust-b', 'Exhausted save budget prevents another network call and retains the URL');
    $fake->delay = 0;
    $new = save(['NEWexchange' => ['pid' => 1, 'CType' => 'header', 'header' => 'New exchange fixture', 'header_link' => $origin . '/new-record']]);
    $fixtures[] = $newUid = (int)$new->substNEWwithIDs['NEWexchange'];
    check(str_starts_with(value($newUid), 't3://exchange?'), 'New records convert through DataHandler');
    $newOutcome = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['value_hash'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $newUid, 'table_name' => 'tt_content'])->fetchOne();
    check($newOutcome === hash('sha256', value($newUid)), 'New record outcome uses its assigned database UID');
    foreach ([503 => 'pending', 403 => 'denied'] as $httpStatus => $expected) {
        $fake->status = $httpStatus;
        $url = $origin . '/failure-' . $httpStatus;
        save([$a => ['header_link' => $url]]);
        check(value($a) === $url, 'HTTP ' . $httpStatus . ' preserves the original URL');
        $result = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['status'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $a, 'table_name' => 'tt_content'])->fetchOne();
        check($result === $expected, 'HTTP ' . $httpStatus . ' records the correct retry/access outcome');
    }
    $fake->status = 200;
    $fake->resultStatus = 'unsupported';
    save([$a => ['header_link' => $origin . '/unsupported']]);
    check(value($a) === $origin . '/unsupported', 'Unsupported URLs remain unchanged');
    $before = count($fake->calls);
    save([$a => ['header_link' => 'https://unconfigured.example/path']]);
    check(value($a) === 'https://unconfigured.example/path' && count($fake->calls) === $before, 'Unconfigured URLs remain unchanged without HTTP');
    $result = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['status'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $a, 'table_name' => 'tt_content'])->fetchOne();
    check($result === false, 'Replacing a managed/failed link removes obsolete outcome data');
    $originalTca = $GLOBALS['TCA'];
    $schemas = $container->get(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class);
    try {
        $fake->resultStatus = 'resolved';
        $GLOBALS['TCA']['tt_content']['columns']['subheader']['config'] = ['type' => 'link'];
        $schemas->rebuild($GLOBALS['TCA']);
        $fake->calls = [];
        save([$a => ['header_link' => $origin . '/batch-one', 'subheader' => $origin . '/batch-two']]);
        check(count($fake->calls) === 1 && count($fake->calls[0]['urls']) === 2, 'Multiple authorized link fields use one batched request');
        $GLOBALS['TCA']['tt_content']['types']['header']['columnsOverrides']['header_link']['config']['allowedTypes'] = ['url'];
        $schemas->rebuild($GLOBALS['TCA']);
        $before = count($fake->calls);
        save([$a => ['header_link' => $origin . '/allowed-url-only']]);
        check(value($a) === $origin . '/allowed-url-only' && count($fake->calls) === $before, 'Record-type allowedTypes override is respected before conversion');
        $GLOBALS['TCA']['tt_content']['types']['header']['columnsOverrides']['header_link']['config'] = ['allowedTypes' => ['url', 'exchange'], 'max' => 100];
        $schemas->rebuild($GLOBALS['TCA']);
        save([$a => ['header_link' => $origin . '/long']]);
        check(value($a) === $origin . '/long', 'Overlong managed reference retains the original URL instead of being truncated');
    } finally {
        $GLOBALS['TCA'] = $originalTca;
        $schemas->rebuild($originalTca);
    }
    save([$a => ['header_link' => 'https://unconfigured.example/path']]);
    $before = count($fake->calls);
    $adminUser = $GLOBALS['BE_USER'];
    $restricted = clone $adminUser;
    $restricted->user['admin'] = 0;
    $restricted->user['uid'] = 2147483000;
    $restricted->groupData['tables_modify'] = '';
    $restricted->groupData['webmounts'] = '';
    $restricted->workspace = 0;
    $restrictedHandler = GeneralUtility::makeInstance(DataHandler::class);
    $restrictedHandler->start(['tt_content' => [$a => ['header_link' => $origin . '/forbidden']]], [], $restricted);
    $restrictedHandler->process_datamap();
    check(value($a) === 'https://unconfigured.example/path' && count($fake->calls) === $before, 'Unauthorized record edits neither convert nor contact a peer');
    $malformed = 't3://exchange?instance=bad&page=bad&language[]=0';
    check($cObj->typoLink('Invalid', ['parameter' => $malformed]) === 'Invalid', 'Malformed managed reference renders plain text');
    check(GeneralUtility::makeInstance(LinkService::class)->asString($details) === $codec->decode($managed)['url'], 'Managed serializer round trips through the core link service');
    echo "Passed $checks link-field checks.\n";
} finally {
    $cleanup();
}
