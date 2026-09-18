<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Configuration;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** One encrypted, versioned configuration per environment. No credentials enter TYPO3 record history. */
final class ConnectionStore
{
    public const TABLE = 'tx_typo3totypo3_connections';

    public function __construct(private readonly ConnectionPool $connections, private readonly CacheManager $cache) {}

    private function key(): string
    {
        $secret = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('TYPO3 encryption key is missing or invalid.');
        }
        return hash_hmac('sha256', 'typo3-exchange:' . (string)Environment::getContext(), $secret, true);
    }

    public function hasConfiguration(): bool
    {
        return $this->connections->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []) > 0;
    }

    public function read(): array
    {
        $key = $this->key();
        $row = $this->connections->getConnectionForTable(self::TABLE)->select(['payload', 'revision'], self::TABLE,
            ['environment_id' => hash('sha256', $key)])->fetchAssociative();
        if (!$row) {
            return ['config' => ['enabled' => false, 'instance' => '', 'outgoing' => [], 'incoming' => [], 'publicAliases' => []], 'revision' => ''];
        }
        $bytes = base64_decode($row['payload'], true);
        $plain = $bytes !== false && strlen($bytes) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key) : false;
        if ($plain === false) {
            throw new \RuntimeException('Connection configuration cannot be decrypted.');
        }
        return ['config' => json_decode($plain, true, 32, JSON_THROW_ON_ERROR), 'revision' => $row['revision']];
    }

    public function save(array $config, string $revision): void
    {
        self::validate($config);
        $current = $this->read();
        if ($current['revision'] !== $revision || ($revision !== '' && $config['instance'] !== $current['config']['instance'])) {
            throw new \RuntimeException('Configuration changed; reload before saving.');
        }
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $data = ['payload' => base64_encode($nonce . sodium_crypto_secretbox(json_encode($config, JSON_THROW_ON_ERROR), $nonce, $key)),
            'revision' => bin2hex(random_bytes(16))];
        $db = $this->connections->getConnectionForTable(self::TABLE);
        $id = hash('sha256', $key);
        if ($revision === '') {
            $db->insert(self::TABLE, ['environment_id' => $id] + $data);
        } elseif ($db->update(self::TABLE, $data, ['environment_id' => $id, 'revision' => $revision]) !== 1) {
            throw new \RuntimeException('Configuration changed; reload before saving.');
        }
        $this->cache->flushCachesInGroup('pages');
    }

    public function import(): void
    {
        if ($this->read()['revision'] !== '') {
            throw new \RuntimeException('Configuration is already initialized.');
        }
        $this->save(PeerConfiguration::legacy(false), '');
    }

    public static function validate(array $config): void
    {
        if (isset($config['exchange'])) {
            if (!is_array($config['exchange'])) { throw new \InvalidArgumentException('Invalid exchange configuration.'); }
            \Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration::validate($config['exchange']);
        }
        if (!is_bool($config['enabled'] ?? null) || !PeerConfiguration::isUuid($config['instance'] ?? null)) {
            throw new \InvalidArgumentException('Invalid local identity.');
        }
        $seen = [];
        foreach (['outgoing', 'incoming', 'publicAliases'] as $section) {
            if (!is_array($config[$section] ?? []) || count($config[$section] ?? []) > 100) {
                throw new \InvalidArgumentException('Invalid connection list.');
            }
        }
        foreach ($config['outgoing'] ?? [] as $name => $peer) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $name)
                || !is_array($peer) || !is_bool($peer['enabled'] ?? null) || !PeerConfiguration::isUuid($peer['instance'] ?? null)
                || isset($seen[$peer['instance']]) || !preg_match('/^[a-f0-9]{64}$/D', $peer['token'] ?? '')
                || !is_array($peer['origins'] ?? null) || !$peer['origins'] || count($peer['origins']) > 100) {
                throw new \InvalidArgumentException('Invalid outgoing connection.');
            }
            if (isset($peer['environment']) && !PeerConfiguration::isUuid($peer['environment'])) {
                throw new \InvalidArgumentException('Invalid resolver environment.');
            }
            $seen[$peer['instance']] = true;
            $endpoint = $peer['endpoint'] ?? '';
            PeerConfiguration::origin($endpoint);
            if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || parse_url($endpoint, PHP_URL_PATH) !== '/typo3-exchange/v1/resolve'
                || parse_url($endpoint, PHP_URL_QUERY) !== null || parse_url($endpoint, PHP_URL_FRAGMENT) !== null) {
                throw new \InvalidArgumentException('Invalid fixed HTTPS endpoint.');
            }
            foreach ($peer['origins'] as $origin) { self::validateOrigin($origin); }
        }
        foreach ($config['incoming'] ?? [] as $instance => $grant) {
            if (!PeerConfiguration::isUuid($instance) || !is_array($grant) || !is_bool($grant['enabled'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $grant['tokenHash'] ?? '')
                || !is_array($grant['sites'] ?? null) || count($grant['sites']) > 100
                || !is_int($grant['requestsPerMinute'] ?? null) || $grant['requestsPerMinute'] < 1 || $grant['requestsPerMinute'] > 10000) {
                throw new \InvalidArgumentException('Invalid incoming grant.');
            }
            foreach ($grant['sites'] as $site) {
                if (!is_string($site) || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/D', $site)) {
                    throw new \InvalidArgumentException('Invalid site identifier.');
                }
            }
        }
        foreach ($config['publicAliases'] ?? [] as $old => $new) {
            self::validateOrigin($old);
            self::validateOrigin($new);
        }
    }

    private static function validateOrigin(mixed $origin): void
    {
        if (!is_string($origin)) { throw new \InvalidArgumentException('Invalid origin.'); }
        PeerConfiguration::origin($origin);
        if (!in_array(parse_url($origin, PHP_URL_PATH), [null, '', '/'], true)
            || parse_url($origin, PHP_URL_QUERY) !== null || parse_url($origin, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('Origins must not contain paths or suffixes.');
        }
    }
}
