<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use TYPO3\CMS\Core\Http\RequestFactory;

final class ExchangeClient
{
    public function __construct(private readonly ExchangeConfiguration $configuration, private readonly RequestFactory $http) {}

    public function send(string $name, string $operation, array $payload, float $timeout = 3.0, ?string $expectedScope = null, ?string $expectedFingerprint = null): array
    {
        if (!in_array($operation, ['usage', 'notify', 'capabilities'], true) || !is_finite($timeout) || $timeout <= 0 || $timeout > 3) {
            throw new \InvalidArgumentException('Invalid exchange request.');
        }
        $config = $this->configuration->load();
        $channel = $config['outgoing'][$name] ?? null;
        if (!$channel || !$channel['enabled'] || ($operation !== 'capabilities' && $channel['capability'] !== $operation)) {
            throw new \RuntimeException('Capability is not enabled.', 403);
        }
        if ($expectedScope !== null && ExchangeConfiguration::scope($config, $channel) !== $expectedScope) {
            throw new \RuntimeException('Pairing changed while delivery was queued.', 409);
        }
        if ($expectedFingerprint !== null && ExchangeConfiguration::fingerprint($channel) !== $expectedFingerprint) {
            throw new \RuntimeException('Capability configuration changed before delivery.', 409);
        }
        $body = json_encode(['protocol' => 2] + $payload, JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536) { throw new \InvalidArgumentException('Exchange request is too large.'); }
        try {
            $response = $this->http->request($channel['endpoint'] . '/' . $operation, 'POST', [
                'headers' => ['Authorization' => 'Bearer ' . $channel['token'], 'X-TYPO3-Peer' => $config['instance'],
                    'X-TYPO3-Environment' => $config['environment'], 'X-TYPO3-Generation' => $channel['generation'],
                    'X-TYPO3-Capability' => $channel['capability'], 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
                'body' => $body, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => min(1.0, $timeout), 'timeout' => $timeout, 'cookies' => false, 'decode_content' => false,
                'progress' => static function ($total, $downloaded): void {
                    if ($total > 65536 || $downloaded > 65536) { throw new \RuntimeException('Exchange response is too large.'); }
                },
            ]);
            try {
                if ($response->getStatusCode() !== ($operation === 'capabilities' ? 200 : 202)) {
                    throw new \RuntimeException('Exchange request was not accepted.', $response->getStatusCode());
                }
                $body = $response->getBody()->read(65537);
                if (strlen($body) > 65536 || strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new \RuntimeException('Invalid exchange response.');
                }
                $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } finally { $response->getBody()->close(); }
        } catch (\Psr\Http\Client\ClientExceptionInterface|\JsonException) {
            throw new \RuntimeException('Exchange communication failed.');
        }
        if (!is_array($data) || ($data['protocol'] ?? null) !== 2
            || ($data['instance'] ?? null) !== $channel['instance'] || ($data['environment'] ?? null) !== $channel['environment']) {
            throw new \RuntimeException('Unexpected exchange identity or protocol.');
        }
        if ($operation === 'capabilities' && (!is_array($data['capabilities'] ?? null) || !array_is_list($data['capabilities'])
            || array_filter($data['capabilities'], static fn($value): bool => !is_string($value)))) {
            throw new \RuntimeException('Invalid capability response.');
        }
        if ($operation === 'usage' && (!is_bool($data['complete'] ?? null) || !is_array($data['unaccepted'] ?? null))) {
            throw new \RuntimeException('Invalid usage acknowledgment.');
        }
        return $data;
    }
}
