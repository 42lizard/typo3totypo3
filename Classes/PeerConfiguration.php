<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3;

final class PeerConfiguration
{
    public function load(): array
    {
        $store = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\Lizard\Typo3ToTypo3\Configuration\ConnectionStore::class);
        $state = $store->read();
        if ($state['revision'] !== '' || $store->hasConfiguration()) {
            $config = $state['config'];
            if (($config['enabled'] ?? false) !== true || !self::isUuid($config['instance'] ?? null)) {
                throw new \RuntimeException('Connections are not enabled in this environment.');
            }
            return $config;
        }
        return self::legacy();
    }

    /** Compatibility until an administrator explicitly imports the protected legacy file. */
    public static function legacy(bool $requireEnabled = true): array
    {
        $path = getenv('TYPO3_EXCHANGE_CONFIG');
        if (!$path || !is_readable($path)) {
            throw new \RuntimeException('Peer configuration is not enabled.');
        }
        $config = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config) || !is_bool($config['enabled'] ?? null) || ($requireEnabled && $config['enabled'] !== true)
            || !self::isUuid($config['instance'] ?? null)
        ) {
            throw new \RuntimeException('Peer configuration is invalid or disabled.');
        }
        return $config;
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function origin(string $url): string
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Invalid URL.');
        }
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('/^[a-zA-Z0-9.-]+$/D', $parts['host'])
        ) {
            throw new \InvalidArgumentException('An absolute HTTP(S) URL without credentials is required.');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        return $parts['scheme'] . '://' . strtolower($parts['host']) . ':' . $port;
    }

    /** Explicit, one-hop origin replacement; this never contacts the old host. */
    public static function canonicalUrl(string $url, array $aliases): string
    {
        $origin = self::origin($url);
        foreach ($aliases as $old => $new) {
            if (!is_string($old) || !is_string($new)) {
                throw new \InvalidArgumentException('Public aliases must map origins to origins.');
            }
            foreach ([$old, $new] as $value) {
                self::origin($value);
                if (!in_array(parse_url($value, PHP_URL_PATH), [null, '', '/'], true)
                    || parse_url($value, PHP_URL_QUERY) !== null || parse_url($value, PHP_URL_FRAGMENT) !== null) {
                    throw new \InvalidArgumentException('Public aliases must contain origins only.');
                }
            }
            if ($origin === self::origin($old)) {
                $target = new \TYPO3\CMS\Core\Http\Uri($new);
                return (string)(new \TYPO3\CMS\Core\Http\Uri($url))
                    ->withScheme($target->getScheme())->withHost($target->getHost())->withPort($target->getPort());
            }
        }
        return $url;
    }

    public static function allowsUrl(string $url, array $origins): bool
    {
        $origin = self::origin($url);
        foreach ($origins as $allowed) {
            if (is_string($allowed) && hash_equals(self::origin($allowed), $origin)) {
                return true;
            }
        }
        return false;
    }
}
