<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Middleware;

use Lizard\Typo3ToTypo3\PageResolver;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

final class Resolve implements MiddlewareInterface
{
    public const PATH = '/typo3-exchange/v1/resolve';
    public const MAX_BATCH = 50;
    public const MAX_BODY = 65536;

    public function __construct(
        private readonly PeerConfiguration $configuration,
        private readonly PageResolver $resolver,
        private readonly CachingFrameworkStorage $rateStorage,
        private readonly LockFactory $locks,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::PATH) {
            return $handler->handle($request);
        }
        try {
            // TYPO3's normalized request URI respects the deployment's trusted proxy configuration.
            if ($request->getUri()->getScheme() !== 'https') {
                return $this->error(400, 'https_required');
            }
            if ($request->getMethod() !== 'POST') {
                return $this->error(405, 'method_not_allowed')->withHeader('Allow', 'POST');
            }
            $config = $this->configuration->load();
            $peerId = $request->getHeaderLine('X-TYPO3-Peer');
            $grant = $config['incoming'][$peerId] ?? null;
            $authorization = $request->getHeaderLine('Authorization');
            if (!is_array($grant) || !is_string($grant['tokenHash'] ?? null)
                || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $matches)
                || !hash_equals($grant['tokenHash'], hash('sha256', $matches[1]))
            ) {
                return $this->error(401, 'unauthorized')->withHeader('WWW-Authenticate', 'Bearer');
            }
            if (($grant['enabled'] ?? false) !== true || empty($grant['sites']) || !is_array($grant['sites'])) {
                return $this->error(403, 'forbidden');
            }
            if (!$this->consume($peerId, max(1, min(10000, (int)($grant['requestsPerMinute'] ?? 120))))) {
                return $this->error(429, 'rate_limited')->withHeader('Retry-After', '60');
            }
            if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                return $this->error(415, 'json_required');
            }
            $body = $request->getBody()->read(self::MAX_BODY + 1);
            if (strlen($body) > self::MAX_BODY || (int)$request->getHeaderLine('Content-Length') > self::MAX_BODY) {
                return $this->error(413, 'body_too_large');
            }
            try {
                $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->error(400, 'invalid_json');
            }
            if (!is_array($payload) || count($payload) !== 1
                || (!isset($payload['urls']) && !isset($payload['references']))
            ) {
                return $this->error(400, 'invalid_batch');
            }
            $byReference = isset($payload['references']);
            $items = $payload[$byReference ? 'references' : 'urls'];
            if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > self::MAX_BATCH) {
                return $this->error(400, 'invalid_batch');
            }
            $results = [];
            foreach ($items as $item) {
                $results[] = $this->resolver->resolve($item, $byReference, $grant['sites'], $config['instance'], $config['publicAliases'] ?? []);
            }
            return $this->response(['protocol' => 1, 'instance' => $config['instance'], 'results' => $results]);
        } catch (\Throwable $exception) {
            // Do not leak URLs, authorization headers, configuration secrets or exception traces.
            error_log('TYPO3 exchange resolver failed: ' . $exception::class);
            return $this->error(503, 'resolver_unavailable');
        }
    }

    private function consume(string $peer, int $limit): bool
    {
        $lock = $this->locks->createLocker('typo3-exchange-rate-' . hash('sha256', $peer));
        if (!$lock->acquire()) {
            return false;
        }
        try {
            $factory = new RateLimiterFactory([
                'id' => 'typo3-exchange', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute',
            ], $this->rateStorage);
            return $factory->create($peer)->consume()->isAccepted();
        } finally {
            $lock->release();
        }
    }

    private function error(int $status, string $error): ResponseInterface
    {
        return $this->response(['protocol' => 1, 'error' => $error], $status);
    }

    private function response(array $payload, int $status = 200): ResponseInterface
    {
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_BODY) {
            $payload = ['protocol' => 1, 'error' => 'response_too_large'];
            $status = 413;
        }
        return new JsonResponse($payload, $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
