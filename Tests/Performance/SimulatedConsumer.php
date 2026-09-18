<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Performance;

use Lizard\Typo3ToTypo3\Configuration\ConnectionStore;
use Lizard\Typo3ToTypo3\Exchange\NotificationInbox;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\Link\RefreshWorker;
use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Information\Typo3Version;

/** An isolated consumer database with production receipt, refresh, and cache-tag invalidation. */
final class SimulatedConsumer
{
    private readonly Connection $db;
    private readonly DestinationStore $destinations;
    private readonly NotificationInbox $inbox;
    private readonly ConnectionStore $config;
    private readonly VariableFrontend $cache;
    private readonly RefreshWorker $worker;
    private readonly array $references;

    public function __construct(string $databaseName, string $databasePath, Connection $serving, array $serverConfig, array $peer, array $identities, RequestFactory $http)
    {
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$databaseName] = ['driver' => 'pdo_sqlite', 'path' => $databasePath];
        $this->db = (new ConnectionPool())->getConnectionByName($databaseName);
        $schema = $serving->createSchemaManager();
        foreach ([DestinationStore::TABLE, NotificationInbox::TABLE, ConnectionStore::TABLE] as $table) {
            foreach ($this->db->getDatabasePlatform()->getCreateTableSQL($schema->introspectTable($table)) as $sql) { $this->db->executeStatement($sql); }
        }
        $pool = new class($this->db) extends ConnectionPool {
            public function __construct(private readonly Connection $db) {}
            public function getConnectionForTable(string $tableName): Connection { return $this->db; }
        };
        $cacheManager = new CacheManager();
        $backend = (new Typo3Version())->getMajorVersion() >= 14
            ? new TransientMemoryBackend()
            : new TransientMemoryBackend('Testing');
        $this->cache = new VariableFrontend('pages', $backend);
        $cacheManager->registerCache($this->cache, ['pages']);
        $this->destinations = new DestinationStore($pool, $cacheManager);
        $this->inbox = new NotificationInbox($pool);
        $this->config = new ConnectionStore($pool, $cacheManager);
        $this->config->save(['enabled' => true, 'instance' => $peer['instance'], 'incoming' => [],
            'outgoing' => ['server' => ['enabled' => true, 'instance' => $serverConfig['instance'], 'environment' => $serverConfig['exchange']['environment'],
                'token' => str_repeat('c', 64), 'endpoint' => 'https://serving.example/typo3-exchange/v1/resolve', 'origins' => ['https://serving.example']]]], '');
        $this->references = array_map(static fn(array $identity): array => ['instance' => $serverConfig['instance'], 'page' => $identity[1], 'language' => 0], $identities);
        $this->db->transactional(function () use ($identities): void {
            foreach ($this->references as $n => $reference) {
                $this->destinations->record($reference, 'resolved', 'https://serving.example/target-' . ($identities[$n][0] - 2));
            }
        });
        $configuration = new PeerConfiguration();
        $this->worker = new RefreshWorker($this->destinations, $configuration, new PeerClient($configuration, $http));
        $this->primeCache();
    }

    public function receive(array $items): void
    {
        $this->inbox->accept(hash('sha256', 'performance-serving-pair'), $items);
    }

    public function primeCache(): void
    {
        foreach ($this->references as $reference) {
            $this->cache->set(ManagedLink::key($reference), 'rendered page containing old link', [DestinationStore::tag($reference)]);
        }
    }

    public function refresh(): array
    {
        $original = GeneralUtility::getContainer();
        $store = $this->config;
        GeneralUtility::setContainer(new class($original, $store) implements ContainerInterface {
            public function __construct(private readonly ContainerInterface $original, private readonly ConnectionStore $store) {}
            public function has(string $id): bool { return $id === ConnectionStore::class || $this->original->has($id); }
            public function get(string $id): mixed { return $id === ConnectionStore::class ? $this->store : $this->original->get($id); }
        });
        try { return $this->worker->run(); }
        finally { GeneralUtility::setContainer($original); }
    }

    public function complete(array $expected, bool $requireCacheFlush): bool
    {
        foreach ($this->references as $reference) {
            if (!isset($expected[$reference['page']])) { continue; }
            $row = $this->destinations->find($reference);
            if ((int)$row['next_refresh'] === 0 || $row['status'] !== 'resolved' || $row['url'] !== $expected[$reference['page']]
                || ($requireCacheFlush && $this->cache->has(ManagedLink::key($reference)))) { return false; }
        }
        return true;
    }
}
