<?php

declare(strict_types=1);

// CLI-only fixture control, deliberately outside the web root.
use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\ExchangeWorker;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Lizard\Typo3ToTypo3\PageIdentity;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$testingDatabase = match (getenv('TYPO3_CONTEXT') . ':' . getenv('TYPO3_PATH_APP')) {
    'Testing:/var/www/html/var/exchange-testing' => 'db_testing',
    'Testing/PeerC:/var/www/html/var/exchange-testing-c' => 'db_testing_c',
    default => null,
};
if (PHP_SAPI !== 'cli' || $testingDatabase === null) {
    throw new RuntimeException('This fixture requires the isolated DDEV Testing context.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$level = ob_get_level();
$loader = require '/var/www/html/vendor/autoload.php';
if (($input['operation'] ?? '') === 'rollback') { require __DIR__ . '/rollback-bootstrap.php'; }
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
Bootstrap::initializeBackendAuthentication();
$db = $container->get(ConnectionPool::class)->getConnectionForTable('pages');
if ($db->getDatabase() !== $testingDatabase) { throw new RuntimeException('Refusing non-test database.'); }
$backupPath = getenv('TYPO3_PATH_APP') . '/paired-fixture-backup.json';
$activationPath = getenv('TYPO3_PATH_APP') . '/config/system/exchange-environment.php';
$saveBackup = static function (array $backup) use ($backupPath): void {
    file_put_contents($backupPath, json_encode($backup, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($backupPath, 0600);
};
$backup = is_file($backupPath) ? json_decode(file_get_contents($backupPath), true, flags: JSON_THROW_ON_ERROR) : null;
$store = $container->get(ConnectionStore::class);
$change = static function (array $data) use ($container): DataHandler {
    $handler = GeneralUtility::makeInstance(DataHandler::class);
    $handler->start($data, []);
    $handler->process_datamap();
    if ($handler->errorLog) { throw new RuntimeException('Fixture DataHandler operation failed.'); }
    return $handler;
};
$result = [];
switch ($input['operation']) {
    case 'export-connections':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $result = ['rows' => $db->select(['*'], ConnectionStore::TABLE, [])->fetchAllAssociative()];
        break;
    case 'database-clone':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $db->executeStatement('DELETE FROM ' . ConnectionStore::TABLE);
        foreach ($input['rows'] as $row) { $db->insert(ConnectionStore::TABLE, $row); }
        $network = new ArrayObject(['calls' => 0]);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['clone-guard' => static fn($next) => static function () use ($network) {
            ++$network['calls'];
            throw new RuntimeException('A database clone must not attempt HTTP.');
        }];
        $blocked = 0;
        foreach ([
            static fn() => (new \Lizard\Typo3ToTypo3\PeerConfiguration())->load(),
            static fn() => (new \Lizard\Typo3ToTypo3\PeerClient(new \Lizard\Typo3ToTypo3\PeerConfiguration(),
                GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\RequestFactory::class)))->resolve('peer', [$input['url']]),
            static fn() => $container->get(RefreshWorker::class)->run(),
        ] as $attempt) {
            try { $attempt(); } catch (RuntimeException) { ++$blocked; }
        }
        $result = ['copied' => $store->hasConfiguration(), 'activeRevision' => $store->read()['revision'],
            'blocked' => $blocked, 'networkCalls' => $network['calls']];
        break;
    case 'ready':
        if ($backup !== null || $store->hasConfiguration()) { throw new RuntimeException('Testing configuration is occupied; recover the previous fixture first.'); }
        $result = ['database' => $db->getDatabase(), 'context' => getenv('TYPO3_CONTEXT'),
            'keyFingerprint' => hash('sha256', $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])];
        break;
    case 'request':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        if (!in_array($input['origin'], ['https://t3exchange-v13-testing.ddev.site', 'https://t3exchange-v14-testing.ddev.site', 'https://t3exchange-v14-testing-c.ddev.site'], true)
            || !in_array($input['path'], ['/typo3-exchange/v1/resolve', '/typo3-exchange/v2/capabilities'], true)) {
            throw new RuntimeException('Only fixed Testing endpoints may be requested.');
        }
        $response = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\RequestFactory::class)->request($input['origin'] . $input['path'], 'POST', [
            'headers' => $input['headers'] + ['Content-Type' => 'application/json'],
            'body' => json_encode($input['payload'], JSON_THROW_ON_ERROR),
            'http_errors' => false, 'allow_redirects' => false, 'timeout' => 5, 'verify' => true,
        ]);
        $result = ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
        break;
    case 'configure':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $store->save($input['config'], $store->read()['revision']);
        break;
    case 'rollback':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $state = $store->read();
        $store->save($state['config'], $state['revision']);
        $db->executeStatement('UPDATE ' . DestinationStore::TABLE . ' SET next_refresh = 0');
        $refresh = $container->get(RefreshWorker::class)->run();
        $result = ['source' => (new ReflectionClass(ConnectionStore::class))->getFileName(),
            'preserved' => $store->read()['config']['exchange'] === $state['config']['exchange'],
            'failed' => $refresh['failed'],
            'url' => $container->get(\Lizard\Typo3ToTypo3\Link\LocalRenderer::class)->url($input['reference'], new \TYPO3\CMS\Core\Http\ServerRequest(), 'Link')];
        $middleware = GeneralUtility::makeInstance(\Lizard\Typo3ToTypo3\Middleware\Resolve::class);
        $request = (new \TYPO3\CMS\Core\Http\ServerRequest($input['origin'] . '/typo3-exchange/v1/resolve', 'POST'))
            ->withHeader('Authorization', 'Bearer ' . $input['token'])->withHeader('X-TYPO3-Peer', $input['caller'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode(['references' => [$input['ownReference']]], JSON_THROW_ON_ERROR)));
        $response = $middleware->process($request, new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface { return new \TYPO3\CMS\Core\Http\Response(null, 404); }
        });
        $result['legacyStatus'] = $response->getStatusCode();
        $result['legacyResponse'] = (string)$response->getBody();
        break;
    case 'consume-legacy':
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = ['legacy-response' => static fn($next) => static fn($request) => \GuzzleHttp\Promise\Create::promiseFor(
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $input['response']))];
        $state = $store->read();
        $client = new \Lizard\Typo3ToTypo3\PeerClient(new \Lizard\Typo3ToTypo3\PeerConfiguration(), GeneralUtility::makeInstance(\TYPO3\CMS\Core\Http\RequestFactory::class));
        $rejected = false;
        try { $client->refresh('peer', [$input['reference']]); }
        catch (RuntimeException) { $rejected = true; }
        $legacyConfig = $state['config'];
        unset($legacyConfig['outgoing']['peer']['environment']);
        $legacyConfig['exchange']['incoming']['notify']['enabled'] = false;
        try {
            $store->save($legacyConfig, $state['revision']);
            $resolved = $client->refresh('peer', [$input['reference']]);
            $result = ['boundRejected' => $rejected, 'url' => $resolved[0]['url']];
        } finally { $store->save($state['config'], $store->read()['revision']); }
        break;
    case 'setup':
        if ($backup !== null || $store->hasConfiguration()) { throw new RuntimeException('Testing configuration is occupied; recover the previous fixture first.'); }
        $backup = ['activation' => is_file($activationPath) ? file_get_contents($activationPath) : null, 'tables' => [], 'page' => 0, 'content' => 0];
        foreach ($db->createSchemaManager()->listTableNames() as $table) {
            if (str_starts_with($table, 'tx_typo3totypo3_')) { $backup['tables'][$table] = $db->select(['*'], $table, [])->fetchAllAssociative(); }
        }
        $saveBackup($backup);
        foreach ($backup['tables'] as $table => $_) { $db->executeStatement('DELETE FROM ' . $db->quoteIdentifier($table)); }
        file_put_contents($activationPath, "<?php\nreturn '" . $input['config']['exchange']['environment'] . "';\n");
        $store->save($input['config'], '');
        $db->insert('pages', ['pid' => 1, 'title' => 'Paired exchange fixture', 'slug' => '/paired-exchange', 'doktype' => 1]);
        $backup['page'] = (int)$db->lastInsertId();
        $saveBackup($backup);
        $result = ['instance' => $input['config']['instance'], 'page' => (new PageIdentity($container->get(ConnectionPool::class)))->forPage($backup['page']), 'language' => 0];
        break;
    case 'link':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $container->get(DestinationStore::class)->record($input['reference'], 'resolved', $input['url']);
        $handler = $change(['tt_content' => ['NEWpaired' => ['pid' => 1, 'CType' => 'header', 'header' => 'Paired source', 'header_link' => (new ManagedLink())->asString($input['reference'])]]]);
        $backup['content'] = (int)$handler->substNEWwithIDs['NEWpaired'];
        $saveBackup($backup);
        break;
    case 'change':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $change(['pages' => [$backup['page'] => $input['fields']]]);
        break;
    case 'remove':
        if (!$backup) { throw new RuntimeException('Missing test fixture.'); }
        $change(['tt_content' => [$backup['content'] => ['header_link' => '']]]);
        break;
    case 'sync':
        $result = $container->get(ExchangeWorker::class)->run();
        break;
    case 'refresh':
        $container->get(RefreshWorker::class)->run();
        $row = $container->get(DestinationStore::class)->find($input['reference']);
        $result = ['status' => $row['status'] ?? '', 'url' => $row['url'] ?? ''];
        break;
    case 'usage':
        $result = ['present' => (int)$db->count('*', 'tx_typo3totypo3_usage', ['present' => 1])];
        break;
    case 'cleanup':
        if ($backup) {
            if ($backup['content']) { $db->delete('tt_content', ['uid' => $backup['content']]); }
            if ($backup['page']) { $db->delete('pages', ['uid' => $backup['page']]); }
            foreach ($backup['tables'] as $table => $rows) {
                $db->executeStatement('DELETE FROM ' . $db->quoteIdentifier($table));
                foreach ($rows as $row) { $db->insert($table, $row); }
            }
            if ($backup['activation'] === null) { if (is_file($activationPath)) { unlink($activationPath); } }
            else { file_put_contents($activationPath, $backup['activation']); }
            unlink($backupPath);
        }
        break;
    default: throw new InvalidArgumentException('Unknown fixture operation.');
}
while (ob_get_level() > $level) { ob_end_clean(); }
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
