<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Integration;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConnectionsTest extends TestCase
{
    public function testDatabaseConfigurationIsUsedByHttpsResolverAndRevocation(): void
    {
        $level = ob_get_level();
        if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing') {
            throw new \RuntimeException('Use only the isolated Testing context.');
        }
        $loader = require '/var/www/html/vendor/autoload.php';
        SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($loader);
        $store = $container->get(ConnectionStore::class);
        $db = $container->get(ConnectionPool::class)->getConnectionForTable(ConnectionStore::TABLE);
        if ($db->getDatabase() !== 'db_testing' || $store->hasConfiguration()) {
            throw new \RuntimeException('Requires an empty Testing connection configuration.');
        }
        $cleanup = static function () use ($db): void { $db->executeStatement('DELETE FROM ' . ConnectionStore::TABLE); };
        register_shutdown_function($cleanup);
        try {
            $legacy = PeerConfiguration::legacy();
            $store->import();
            self::assertSame($legacy, (new PeerConfiguration())->load());
            $state = $store->read();
            $config = $state['config'];
            $origin = getenv('EXCHANGE_TEST_ORIGIN');
            $token = bin2hex(random_bytes(32));
            $config['outgoing']['self'] = ['enabled' => true, 'instance' => $config['instance'],
                'endpoint' => $origin . '/typo3-exchange/v1/resolve', 'origins' => [$origin], 'token' => $token];
            $config['incoming'][$config['instance']] = ['enabled' => true, 'sites' => ['main'], 'tokenHash' => hash('sha256', $token), 'requestsPerMinute' => 120];
            $store->save($config, $state['revision']);
            $client = new PeerClient(new PeerConfiguration(), $container->get(RequestFactory::class));
            self::assertSame('resolved', $client->resolve('self', [$origin . '/'])[0]['status']);
            $config['incoming'][$config['instance']]['enabled'] = false;
            $store->save($config, $store->read()['revision']);
            try { $client->resolve('self', [$origin . '/']); self::fail('Revoked grant accepted'); }
            catch (\RuntimeException $exception) { self::assertSame(403, $exception->getCode()); }
            $config['incoming'][$config['instance']]['enabled'] = true;
            $store->save($config, $store->read()['revision']);
            self::assertSame('resolved', $client->resolve('self', [$origin . '/'])[0]['status']);
        } finally {
            $cleanup();
            while (ob_get_level() > $level) { ob_end_clean(); }
        }
    }
}
