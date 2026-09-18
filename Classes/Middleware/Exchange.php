<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Middleware;

use Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration;
use Lizard\Typo3ToTypo3\Exchange\NotificationInbox;
use Lizard\Typo3ToTypo3\Exchange\UsageReceiver;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

final class Exchange implements MiddlewareInterface
{
    public const BASE = '/typo3-exchange/v2/';

    public function __construct(
        private readonly ExchangeConfiguration $configuration,
        private readonly PeerConfiguration $peers,
        private readonly NotificationInbox $notifications,
        private readonly UsageReceiver $usage,
        private readonly CachingFrameworkStorage $rateStorage,
        private readonly LockFactory $locks,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, self::BASE)) { return $handler->handle($request); }
        if ($request->getUri()->getScheme() !== 'https') { return $this->response(400); }
        if ($request->getMethod() !== 'POST') { return $this->response(405)->withHeader('Allow', 'POST'); }
        if (!in_array($path, [self::BASE . 'notify', self::BASE . 'capabilities', self::BASE . 'usage'], true)) { return $this->response(404); }
        try {
            $config = $this->configuration->load();
            $channel = null;
            foreach ($config['incoming'] as $name => $candidate) {
                if ($candidate['instance'] === $request->getHeaderLine('X-TYPO3-Peer')
                    && $candidate['environment'] === $request->getHeaderLine('X-TYPO3-Environment')
                    && $candidate['generation'] === $request->getHeaderLine('X-TYPO3-Generation')
                    && $candidate['capability'] === $request->getHeaderLine('X-TYPO3-Capability')
                    && preg_match('/^Bearer ([a-f0-9]{64})$/D', $request->getHeaderLine('Authorization'), $match)
                    && hash_equals($candidate['tokenHash'], hash('sha256', $match[1]))) {
                    $channel = $candidate + ['name' => (string)$name];
                    break;
                }
            }
            if ($channel === null) { return $this->response(401); }
            if (!$channel['enabled']) { return $this->response(403); }
            $scope = ExchangeConfiguration::scope($config, $channel);
            $lock = $this->locks->createLocker('exchange-capability-' . $scope);
            if (!$lock->acquire()) { return $this->response(503); }
            try {
                $factory = new RateLimiterFactory(['id' => 'exchange-v2', 'policy' => 'fixed_window', 'limit' => 120, 'interval' => '1 minute'], $this->rateStorage);
                if (!$factory->create($scope)->consume()->isAccepted()) {
                    return $this->response(429)->withHeader('Retry-After', '60');
                }
            } finally { $lock->release(); }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                return $this->response(415);
            }
            $body = $request->getBody()->read(65537);
            if (strlen($body) > 65536 || (int)$request->getHeaderLine('Content-Length') > 65536) { return $this->response(413); }
            try { $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return $this->response(400); }
            if (!is_array($payload) || ($payload['protocol'] ?? null) !== 2) { return $this->response(400); }
            if ($path === self::BASE . 'capabilities') {
                return $this->response(200, ['capabilities' => ['usage', 'notify'], 'instance' => $config['instance'], 'environment' => $config['environment']]);
            }
            if ($path === self::BASE . 'usage') {
                if ($channel['capability'] !== 'usage') { return $this->response(403); }
                return $this->response(202, $this->usage->receive($config, $channel, $payload)
                    + ['instance' => $config['instance'], 'environment' => $config['environment']]);
            }
            if ($channel['capability'] !== 'notify') { return $this->response(403); }
            // Notification permission alone cannot enable a disabled resolver connection.
            $resolverEnabled = false;
            foreach ($this->peers->load()['outgoing'] ?? [] as $peer) {
                if ($peer['instance'] === $channel['instance'] && ($peer['environment'] ?? '') === $channel['environment'] && $peer['enabled'] === true) { $resolverEnabled = true; }
            }
            if (!$resolverEnabled) { return $this->response(403); }
            if (count($payload) !== 2 || !is_array($payload['items'] ?? null)) { return $this->response(400); }
            foreach ($payload['items'] as $item) {
                if (!is_array($item) || ($item['reference']['instance'] ?? null) !== $channel['instance']) { return $this->response(400); }
            }
            $this->notifications->accept($scope, $payload['items']);
            return $this->response(202, ['instance' => $config['instance'], 'environment' => $config['environment']]);
        } catch (\InvalidArgumentException) {
            return $this->response(400);
        } catch (\Throwable) {
            return $this->response(503); // Never leak credentials, payloads or exception text.
        }
    }

    private function response(int $status, array $data = []): ResponseInterface
    {
        return new JsonResponse(['protocol' => 2] + $data, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
