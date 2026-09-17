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
$GLOBALS['LANG'] = $container->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
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
$sitePath = '/var/www/html/config/sites/main/config.yaml';
$originalSite = file_get_contents($sitePath);
$cleaned = false;
$cleanup = static function () use (&$cleaned, &$fixtures, &$references, $connections, $db, $handlers, $originalDestinations, $sitePath, $originalSite): void {
    if ($cleaned) {
        return;
    }
    $cleaned = true;
    file_put_contents($sitePath, $originalSite);
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
function body(int $id): string
{
    global $db;
    return $db->executeQuery('SELECT bodytext FROM tt_content WHERE uid = ?', [$id])->fetchOne();
}
try {
    $id = fixture('');
    $html = '<p>Before &amp; after <a href="' . $origin . '/?campaign=a&amp;other=b#contact" target="_blank" title="A &amp; B" class="external" rel="nofollow"><strong>Read</strong> more</a> <a href="https://elsewhere.example/">Other</a></p>';
    save([$id => ['CType' => 'text', 'bodytext' => $html]]);
    $saved = body($id);
    check(str_contains($saved, 't3://exchange?'), 'RTE anchor is converted through DataHandler');
    $anchors = \Lizard\Typo3ToTypo3\Link\RteLinks::anchors($saved);
    $details = GeneralUtility::makeInstance(LinkService::class)->resolve($anchors[0]['url']);
    $references[] = $details;
    check(str_replace(htmlspecialchars($anchors[0]['url'], ENT_QUOTES), $origin . '/?campaign=a&amp;other=b#contact', $saved) === $html, 'Only the verified href changes; text, inline markup and attributes stay identical');

    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function () { throw new LogicException('Unexpected HTTP during rendering'); }];
    save([$id => ['bodytext' => $saved . '<p>Edited while offline</p>']]);
    check(str_contains(body($id), htmlspecialchars($anchors[0]['url'], ENT_QUOTES)), 'Existing managed RTE links survive offline editing and core HTML transformations');
    $request = (new ServerRequest(getenv('DDEV_PRIMARY_URL')))->withAttribute('frontend.cache.collector', new CacheDataCollector());
    $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
    $cObj->setRequest($request);
    // Same anchor processing used by fluid_styled_content's lib.parseFunc_RTE.
    $parseConfig = ['allowTags' => '*', 'tags.' => ['a' => 'TEXT', 'a.' => ['current' => 1, 'typolink.' => [
        'parameter.' => ['data' => 'parameters:href'], 'title.' => ['data' => 'parameters:title'],
        'ATagParams.' => ['data' => 'parameters:allParams'], 'target.' => ['data' => 'parameters:target'],
    ]]]];
    $render = static fn() => $cObj->parseFunc($saved, $parseConfig);
    $rendered = $render();
    check(str_contains($rendered, $origin . '/?campaign=a&amp;other=b#contact') && str_contains($rendered, '<strong>Read</strong> more'), 'Standard RTE parseFunc renders the readable URL and nested text without HTTP');
    check(str_contains($rendered, 'class="external"') && str_contains($rendered, 'nofollow'), 'RTE rendering retains class and rel attributes');
    $store->record($details, 'stale');
    check($render() === $rendered, 'Stale RTE destinations retain the last URL');
    $store->record($details, 'unavailable');
    check(!str_contains($render(), $origin) && str_contains($render(), '<strong>Read</strong> more'), 'Unavailable RTE destinations keep inline text without a managed anchor');

    // Token boundaries, comments, raw text and newline mapping, independently of core's RTE transformations.
    $literal = "<!-- <a href='https://ignored.example/'> -->\r\n<script>var x = \"<a href='https://ignored.example/'>\";</script><p title='x > y'>ä <a\r\n href='" . $origin . "/?a=1&amp;b=2' title='a > b'>Text</a></p>";
    $tokens = \Lizard\Typo3ToTypo3\Link\RteLinks::anchors($literal);
    check(count($tokens) === 1 && $tokens[0]['url'] === $origin . '/?a=1&b=2', 'HTML5 tokenizer ignores comments and raw text, and decodes entities');
    $rewritten = \Lizard\Typo3ToTypo3\Link\RteLinks::replace($literal, $tokens, [0 => 't3://exchange?test=1&other=2']);
    check($rewritten === str_replace("href='" . $origin . "/?a=1&amp;b=2'", 'href="t3://exchange?test=1&amp;other=2"', $literal), 'Token replacement preserves CRLF, non-ASCII text, quote style and unrelated attributes');

    $fake = new stdClass();
    $fake->calls = [];
    $fake->delay = 0;
    $fake->reference = ['instance' => $peer['instance'], 'page' => PeerConfiguration::uuid(), 'language' => 1];
    $references[] = $fake->reference;
    $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['exchange-test' => static fn($next) => static function ($request, array $options) use ($fake, $peer) {
        $urls = json_decode((string)$request->getBody(), true)['urls'];
        $fake->calls[] = ['urls' => $urls, 'timeout' => $options['timeout']];
        if ($fake->delay) { usleep($fake->delay); }
        return Create::promiseFor(new JsonResponse(['protocol' => 1, 'instance' => $peer['instance'], 'results' => array_map(static fn($url) => str_contains($url, '/unsupported') ? ['status' => 'unsupported'] : ['status' => 'resolved', 'reference' => $fake->reference, 'url' => $url], $urls)]));
    }];
    $mixed = '<p><a href="' . $origin . '/shared">One</a><a href="' . $origin . '/shared">Two</a><a href="' . $origin . '/unsupported">Keep</a><a href="t3://page?uid=1">Local</a></p>';
    save([$id => ['header_link' => $origin . '/shared', 'bodytext' => $mixed]]);
    check(count($fake->calls) === 1 && count($fake->calls[0]['urls']) === 2, 'RTE and link fields batch and deduplicate together');
    check(substr_count(body($id), 't3://exchange?') === 2 && str_contains(body($id), $origin . '/unsupported') && str_contains(body($id), 't3://page?uid=1'), 'Mixed RTE links convert only verified peers');
    $outcome = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['*'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $id, 'field_name' => 'bodytext'])->fetchAssociative();
    check($outcome['status'] === 'unsupported' && $outcome['value_hash'] === hash('sha256', body($id)), 'Partial resolution persists the failure and final field hash');
    $before = count($fake->calls);
    save([$id => ['CType' => 'html', 'bodytext' => '<a href="' . $origin . '/raw">Raw HTML</a>']]);
    check(body($id) === '<a href="' . $origin . '/raw">Raw HTML</a>' && count($fake->calls) === $before, 'Record-type richtext override leaves raw HTML fields untouched');
    $other = fixture('');
    $fake->calls = [];
    $fake->delay = 200000;
    save([$other => ['header_link' => $origin . '/budget-link'], $id => ['CType' => 'text', 'bodytext' => '<a href="' . $origin . '/budget-rte">Budget</a>']]);
    check(count($fake->calls) === 2 && $fake->calls[1]['timeout'] < $fake->calls[0]['timeout'] - 0.15, 'Link fields and RTE fields share the remaining save deadline across records');
    $fake->delay = 0;
    $originalTca = $GLOBALS['TCA'];
    $preset = '/var/www/html/config/exchange-rte-' . bin2hex(random_bytes(6)) . '.yaml';
    file_put_contents($preset, "allowedTypes: url\n");
    $GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['exchange_restricted_test'] = $preset;
    $schemas = $container->get(\TYPO3\CMS\Core\Schema\TcaSchemaFactory::class);
    try {
        $GLOBALS['TCA']['tt_content']['types']['text']['columnsOverrides']['bodytext']['config']['richtextConfiguration'] = 'exchange_restricted_test';
        $schemas->rebuild($GLOBALS['TCA']);
        $before = count($fake->calls);
        $restrictedHtml = '<p><a href="' . $origin . '/restricted">Restricted</a></p>';
        save([$id => ['bodytext' => $restrictedHtml]]);
        check(body($id) === $restrictedHtml && count($fake->calls) === $before, 'Effective RTE preset allowedTypes prevents disallowed managed links');
    } finally {
        $GLOBALS['TCA'] = $originalTca;
        $schemas->rebuild($originalTca);
        unset($GLOBALS['TYPO3_CONF_VARS']['RTE']['Presets']['exchange_restricted_test']);
        unlink($preset);
    }
    save([$id => ['CType' => 'text', 'bodytext' => '<p><a href="' . $origin . '/copy-source">Copy</a></p>']]);
    $sourceBody = body($id);
    $before = count($fake->calls);
    $copy = GeneralUtility::makeInstance(DataHandler::class);
    $copy->start([], ['tt_content' => [$id => ['copy' => 1]]]);
    $copy->process_cmdmap();
    $copiedUid = (int)($copy->copyMappingArray_merged['tt_content'][$id] ?? 0);
    if ($copiedUid) { $fixtures[] = $copiedUid; }
    check(!$copy->errorLog && $copiedUid > 0 && body($copiedUid) === $sourceBody && count($fake->calls) === $before, 'Copying a record preserves managed RTE links without network calls');

    $site = \Symfony\Component\Yaml\Yaml::parse($originalSite);
    $site['languages'][] = ['title' => 'German test', 'enabled' => true, 'languageId' => 27, 'base' => '/test-de/', 'locale' => 'de_DE.UTF-8', 'navigationTitle' => 'Deutsch', 'flag' => 'de', 'fallbackType' => 'strict'];
    file_put_contents($sitePath, \Symfony\Component\Yaml\Yaml::dump($site, 10, 2));
    $container->get(CacheManager::class)->flushCaches();
    $container->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->siteConfigurationChanged();
    $localize = GeneralUtility::makeInstance(DataHandler::class);
    $localize->start([], ['tt_content' => [$id => ['localize' => 27]]]);
    $localize->process_cmdmap();
    $translationUid = (int)$db->executeQuery('SELECT uid FROM tt_content WHERE l18n_parent = ? AND sys_language_uid = 27', [$id])->fetchOne();
    if ($translationUid) { $fixtures[] = $translationUid; }
    check(!$localize->errorLog && $translationUid > 0 && \Lizard\Typo3ToTypo3\Link\RteLinks::anchors(body($translationUid))[0]['url'] === \Lizard\Typo3ToTypo3\Link\RteLinks::anchors($sourceBody)[0]['url'] && count($fake->calls) === $before, 'Localizing content retains managed links and the selected destination language');

    $backendUser = $GLOBALS['BE_USER'];
    $workspaceUser = clone $backendUser;
    $workspaceUser->workspace = 12345;
    $workspaceUser->workspaceRec = ['uid' => 12345, 'title' => 'Integration test', 'freeze' => 0, 'live_edit' => 0];
    $GLOBALS['BE_USER'] = $workspaceUser;
    try {
        $draft = save(['NEWdraft' => ['pid' => 1, 'CType' => 'text', 'header' => 'Draft fixture', 'bodytext' => '<p><a href="' . $origin . '/draft">Draft</a></p>']]);
        $draftUid = (int)($draft->substNEWwithIDs['NEWdraft'] ?? 0);
        if ($draftUid) { $fixtures[] = $draftUid; }
        $draftRecord = $db->executeQuery('SELECT * FROM tt_content WHERE uid = ?', [$draftUid])->fetchAssociative();
        check($draftRecord && (int)$draftRecord['t3ver_wsid'] === 12345 && str_contains($draftRecord['bodytext'], 't3://exchange?') && body($id) === $sourceBody, 'Workspace save converts only the draft and leaves live content unchanged');
        $draftOutcome = $connections->getConnectionForTable('tx_typo3totypo3_link_outcome')->select(['workspace_id'], 'tx_typo3totypo3_link_outcome', ['record_uid' => $draftUid, 'field_name' => 'bodytext'])->fetchOne();
        check((int)$draftOutcome === 12345, 'RTE outcome retains the draft workspace scope');
    } finally {
        $GLOBALS['BE_USER'] = $backendUser;
    }
    echo "Passed $checks RTE checks.\n";
} finally {
    $cleanup();
}
