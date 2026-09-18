<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Core\Environment;

/** Deployment activation is deliberately outside database exports. */
final class ExchangeConfiguration
{
    public function __construct(private readonly PeerConfiguration $peers) {}

    public static function deploymentIdentity(): string
    {
        $value = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        if ($value === false) {
            $path = Environment::getConfigPath() . '/system/exchange-environment.php';
            $value = is_file($path) ? require $path : '';
        }
        return PeerConfiguration::isUuid($value) ? $value : '';
    }

    public static function initializeDeployment(): string
    {
        $identity = self::deploymentIdentity();
        if ($identity !== '') { return $identity; }
        if (getenv('TYPO3_EXCHANGE_ENVIRONMENT') !== false) {
            throw new \RuntimeException('Configured deployment identity is invalid.');
        }
        $path = Environment::getConfigPath() . '/system/exchange-environment.php';
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot initialize deployment identity.');
        }
        try {
            $identity = PeerConfiguration::uuid();
            $contents = "<?php\nreturn '" . $identity . "';\n";
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist deployment identity.');
            }
        } finally { fclose($handle); }
        return $identity;
    }

    public static function initializeClone(string $previous): string
    {
        $configured = getenv('TYPO3_EXCHANGE_ENVIRONMENT');
        if ($configured !== false) {
            if (!PeerConfiguration::isUuid($configured) || $configured === $previous) {
                throw new \RuntimeException('Set a distinct deployment environment before initializing this clone.');
            }
            return $configured;
        }
        $path = Environment::getConfigPath() . '/system/exchange-environment.php';
        $temporary = tempnam(dirname($path), '.exchange-');
        if ($temporary === false) { throw new \RuntimeException('Cannot prepare deployment identity.'); }
        try {
            $identity = PeerConfiguration::uuid();
            $contents = "<?php\nreturn '" . $identity . "';\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot persist deployment identity.');
            }
            return $identity;
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    public static function fingerprint(array $channel): string
    {
        return hash('sha256', json_encode($channel, JSON_THROW_ON_ERROR));
    }

    public function load(): array
    {
        $config = $this->peers->load();
        $exchange = $config['exchange'] ?? [];
        self::validate($exchange);
        if (self::deploymentIdentity() === '' || ($exchange['environment'] ?? '') !== self::deploymentIdentity()) {
            throw new \RuntimeException('Exchange environment is not activated.');
        }
        return $exchange + ['instance' => $config['instance']];
    }

    public static function validate(array $exchange): void
    {
        if (!$exchange) { return; }
        if (!PeerConfiguration::isUuid($exchange['environment'] ?? null)) {
            throw new \InvalidArgumentException('Invalid exchange environment.');
        }
        foreach (['incoming', 'outgoing'] as $direction) {
            if (!is_array($exchange[$direction] ?? null) || count($exchange[$direction]) > 100) {
                throw new \InvalidArgumentException('Invalid capability list.');
            }
            $seen = [];
            foreach ($exchange[$direction] as $name => $channel) {
                if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $name)
                    || !is_array($channel) || !is_bool($channel['enabled'] ?? null)
                    || !in_array($channel['capability'] ?? null, ['usage', 'notify'], true)
                    || !PeerConfiguration::isUuid($channel['instance'] ?? null)
                    || !PeerConfiguration::isUuid($channel['environment'] ?? null)
                    || !PeerConfiguration::isUuid($channel['generation'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $channel[$direction === 'incoming' ? 'tokenHash' : 'token'] ?? '')
                    || !is_array($channel['sites'] ?? null) || count($channel['sites']) > 100) {
                    throw new \InvalidArgumentException('Invalid capability configuration.');
                }
                $identity = $channel['environment'] . ':' . $channel['capability'];
                if (isset($seen[$identity])) { throw new \InvalidArgumentException('Duplicate capability.'); }
                $seen[$identity] = true;
                foreach ($channel['sites'] as $site) {
                    if (!is_string($site) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $site)) {
                        throw new \InvalidArgumentException('Invalid capability site.');
                    }
                }
                if ($direction === 'outgoing') {
                    $url = $channel['endpoint'] ?? '';
                    PeerConfiguration::origin($url);
                    if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_PATH) !== '/typo3-exchange/v2'
                        || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
                        throw new \InvalidArgumentException('Invalid fixed capability endpoint.');
                    }
                }
            }
        }
    }

    public static function scope(array $config, array $channel): string
    {
        return hash('sha256', implode(':', [$config['environment'], $channel['instance'],
            $channel['environment'], $channel['generation'], $channel['capability']]));
    }
}
