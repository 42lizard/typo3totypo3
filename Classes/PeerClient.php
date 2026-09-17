<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3;

use Lizard\Typo3ToTypo3\Middleware\Resolve;
use TYPO3\CMS\Core\Http\RequestFactory;

final class PeerClient
{
    public function __construct(private readonly PeerConfiguration $configuration, private readonly RequestFactory $http) {}

    public function resolve(string $peerId, array $urls, float $timeout = 3.0): array
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 3.0) {
            throw new \InvalidArgumentException('Timeout must be greater than zero and at most three seconds.');
        }
        $config = $this->configuration->load();
        $peer = $config['outgoing'][$peerId] ?? null;
        if (!is_array($peer) || ($peer['enabled'] ?? false) !== true
            || !PeerConfiguration::isUuid($peer['instance'] ?? null)
            || !is_string($peer['endpoint'] ?? null) || !str_starts_with($peer['endpoint'], 'https://')
            || !is_string($peer['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $peer['token'])
            || !is_array($peer['origins'] ?? null)
        ) {
            throw new \RuntimeException('The outgoing peer is not configured or enabled.');
        }
        PeerConfiguration::origin($peer['endpoint']);
        if (parse_url($peer['endpoint'], PHP_URL_PATH) !== Resolve::PATH
            || parse_url($peer['endpoint'], PHP_URL_QUERY) !== null
            || parse_url($peer['endpoint'], PHP_URL_FRAGMENT) !== null
        ) {
            throw new \RuntimeException('The peer must use a fixed resolver endpoint.');
        }
        if (!array_is_list($urls) || count($urls) < 1 || count($urls) > Resolve::MAX_BATCH) {
            throw new \InvalidArgumentException('Resolve between 1 and 50 URLs per request.');
        }
        foreach ($urls as $url) {
            if (!is_string($url) || !PeerConfiguration::allowsUrl($url, $peer['origins'])) {
                throw new \InvalidArgumentException('URL does not belong to this configured peer.');
            }
        }
        $body = json_encode(['urls' => $urls], JSON_THROW_ON_ERROR);
        if (strlen($body) > Resolve::MAX_BODY) {
            throw new \InvalidArgumentException('The request exceeds the resolver body limit.');
        }
        try {
            $response = $this->http->request($peer['endpoint'], 'POST', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $peer['token'],
                    'X-TYPO3-Peer' => $config['instance'],
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                ],
                'body' => $body, 'allow_redirects' => false, 'verify' => true,
                'http_errors' => false, 'connect_timeout' => min(1.0, $timeout), 'timeout' => $timeout,
                'cookies' => false, 'decode_content' => false,
                // Bound downloads during transfer, within the same total request timeout.
                'progress' => static function ($total, $downloaded): void {
                    if ($total > Resolve::MAX_BODY || $downloaded > Resolve::MAX_BODY) {
                        throw new \RuntimeException('Peer response exceeds the size limit.');
                    }
                },
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException(match ($status) {
                    401, 403 => 'Peer access denied; check credentials and site grants.',
                    429 => 'Peer rate limit reached; retry later.',
                    default => 'Peer resolver unavailable or returned an unexpected HTTP status.',
                }, $status);
            }
            $body = $response->getBody()->read(Resolve::MAX_BODY + 1);
            $response->getBody()->close();
            if (strlen($body) > Resolve::MAX_BODY
                || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'application/json')
            ) {
                throw new \RuntimeException('Invalid peer response.');
            }
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Psr\Http\Client\ClientExceptionInterface|\JsonException $exception) {
            throw new \RuntimeException('Peer communication failed; no destination status can be inferred.');
        }
        if (!is_array($payload) || ($payload['protocol'] ?? null) !== 1
            || ($payload['instance'] ?? null) !== $peer['instance']
            || !is_array($payload['results'] ?? null) || !array_is_list($payload['results'])
            || count($payload['results']) !== count($urls)
        ) {
            throw new \RuntimeException('Peer returned an invalid response or unexpected instance identity.');
        }
        foreach ($payload['results'] as $result) {
            if (!is_array($result) || !in_array($result['status'] ?? null, ['resolved', 'unavailable', 'unsupported'], true)) {
                throw new \RuntimeException('Peer returned an invalid result.');
            }
            if ($result['status'] === 'resolved'
                && (!is_array($result['reference'] ?? null)
                    || ($result['reference']['instance'] ?? null) !== $peer['instance']
                    || !PeerConfiguration::isUuid($result['reference']['page'] ?? null)
                    || !is_int($result['reference']['language'] ?? null) || $result['reference']['language'] < 0
                    || $result['reference']['language'] > 2147483647
                    || !is_string($result['url'] ?? null)
                    || !PeerConfiguration::allowsUrl($result['url'], $peer['origins']))
            ) {
                throw new \RuntimeException('Peer returned an invalid reference or unapproved URL.');
            }
        }
        return $payload['results'];
    }
}
