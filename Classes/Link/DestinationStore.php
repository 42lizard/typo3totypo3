<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DestinationStore
{
    public const TABLE = 'tx_typo3totypo3_destination';

    public function __construct(private readonly ConnectionPool $connections, private readonly CacheManager $cache) {}

    public static function tag(array $reference): string
    {
        return 'exchange_' . ManagedLink::key($reference);
    }

    public function find(array $reference): ?array
    {
        return $this->connections->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['reference_key' => ManagedLink::key($reference)])->fetchAssociative() ?: null;
    }

    /** Shared by save-time resolution and later refresh jobs. Preserve the last URL on failure. */
    public function record(array $reference, string $status, ?string $url = null): void
    {
        if (!(new ManagedLink())->resolveHandlerData($reference)['valid']
            || !in_array($status, ['resolved', 'stale', 'unavailable', 'denied'], true)
            || ($status === 'resolved' && $url === null)) {
            throw new \InvalidArgumentException('Invalid destination state.');
        }
        if ($url !== null) {
            PeerConfiguration::origin($url);
            if (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
                throw new \InvalidArgumentException('Destination URLs must not contain per-link suffixes.');
            }
        }
        $connection = $this->connections->getConnectionForTable(self::TABLE);
        $key = ['reference_key' => ManagedLink::key($reference)];
        $previous = $this->find($reference);
        $data = ['instance_uuid' => $reference['instance'], 'page_uuid' => $reference['page'],
            'language_id' => $reference['language'], 'status' => $status,
            'url' => $url ?? $previous['url'] ?? '', 'checked_at' => time()];
        if ($previous === null) {
            try {
                $connection->insert(self::TABLE, $key + $data);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $connection->update(self::TABLE, $data, $key);
            }
        } else {
            $connection->update(self::TABLE, $data, $key);
        }
        if ($previous === null || $previous['status'] !== $status || $previous['url'] !== $data['url']) {
            $this->cache->flushCachesInGroupByTag('pages', self::tag($reference));
        }
    }
}
