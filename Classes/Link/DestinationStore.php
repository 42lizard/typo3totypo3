<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DestinationStore
{
    public const TABLE = 'tx_typo3totypo3_destination';
    // Leave room for minute-aligned scheduling and two bounded refresh runs at the accepted load.
    private const REFRESH_INTERVAL = 120;

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
            'url' => $url ?? $previous['url'] ?? '', 'checked_at' => time(),
            'generation' => bin2hex(random_bytes(16)), 'lease_token' => '', 'lease_until' => 0,
            'next_refresh' => time() + self::REFRESH_INTERVAL, 'attempts' => 0, 'refresh_paused' => 0, 'refresh_error' => ''];
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

    public static function peerTag(string $instance): string
    {
        return 'exchange_peer_' . $instance;
    }

    public static function reference(array $row): array
    {
        return ['instance' => $row['instance_uuid'], 'page' => $row['page_uuid'], 'language' => (int)$row['language_id']];
    }

    /** Lease a small batch. Competing workers and newer save-time resolutions win via generation checks. */
    public function claim(string $instance, int $limit): array
    {
        $db = $this->connections->getConnectionForTable(self::TABLE);
        $now = time();
        $rows = $db->executeQuery('SELECT * FROM ' . self::TABLE
            . ' WHERE instance_uuid = ? AND refresh_paused = 0 AND next_refresh <= ? AND lease_until <= ?'
            . ' ORDER BY next_refresh, reference_key LIMIT ' . max(1, min(50, $limit)), [$instance, $now, $now])->fetchAllAssociative();
        $claimed = [];
        foreach ($rows as $row) {
            $token = bin2hex(random_bytes(16));
            if ($db->executeStatement('UPDATE ' . self::TABLE . ' SET lease_token = ?, lease_until = ?'
                . ' WHERE reference_key = ? AND generation = ? AND lease_until <= ? AND refresh_paused = 0 AND next_refresh <= ?',
                [$token, $now + 120, $row['reference_key'], $row['generation'], $now, $now]) === 1) {
                $row['lease_token'] = $token;
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    /** Only verified unavailable responses suppress links; transport failures preserve confirmed negative states too. */
    public function finish(array $row, ?array $result, string $peerHash): bool
    {
        $reference = self::reference($row);
        $status = $result['status'] ?? ($row['status'] === 'resolved' ? 'stale' : $row['status']);
        $attempts = $result === null ? min(16, (int)$row['attempts'] + 1) : 0;
        $url = $status === 'resolved' && $result !== null ? $result['url'] : $row['url'];
        $data = ['status' => $status, 'url' => $url, 'checked_at' => time(),
            'next_refresh' => time() + ($attempts ? min(3600, 60 * (2 ** ($attempts - 1))) : self::REFRESH_INTERVAL),
            'attempts' => $attempts, 'lease_token' => '', 'lease_until' => 0,
            'peer_hash' => $peerHash, 'refresh_error' => $result === null ? 'transient' : ''];
        $updated = $this->connections->getConnectionForTable(self::TABLE)->update(self::TABLE, $data,
            ['reference_key' => $row['reference_key'], 'generation' => $row['generation'], 'lease_token' => $row['lease_token']]);
        if (!$updated) {
            // A newer invalidation keeps this lease until the old request finishes.
            // Release only our own token; retain the newer due time and generation.
            $this->connections->getConnectionForTable(self::TABLE)->update(self::TABLE,
                ['lease_token' => '', 'lease_until' => 0],
                ['reference_key' => $row['reference_key'], 'lease_token' => $row['lease_token']]);
        }
        if ($updated && ($status !== $row['status'] || $url !== $row['url'])) {
            $this->cache->flushCachesInGroupByTag('pages', self::tag($reference));
        }
        return $updated === 1;
    }

    /** Denial concerns the connection, including destinations outside the current bounded batch. */
    public function deny(string $instance, string $peerHash): void
    {
        $this->connections->getConnectionForTable(self::TABLE)->update(self::TABLE,
            ['status' => 'denied', 'refresh_paused' => 1, 'refresh_error' => 'denied', 'peer_hash' => $peerHash,
                'generation' => bin2hex(random_bytes(16)), 'lease_token' => '', 'lease_until' => 0, 'checked_at' => time()],
            ['instance_uuid' => $instance]);
        $this->cache->flushCachesInGroupByTag('pages', self::peerTag($instance));
    }

    /** A corrected connection rechecks each identity; it does not restore clickability without verification. */
    public function resume(string $instance, string $peerHash, bool $force = false): void
    {
        $this->connections->getConnectionForTable(self::TABLE)->executeStatement('UPDATE ' . self::TABLE
            . ' SET refresh_paused = 0, next_refresh = 0, attempts = 0'
            . ' WHERE instance_uuid = ? AND refresh_paused = 1' . ($force ? '' : ' AND peer_hash <> ?'),
            $force ? [$instance] : [$instance, $peerHash]);
    }
}
