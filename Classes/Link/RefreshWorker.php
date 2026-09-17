<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Lizard\Typo3ToTypo3\PeerClient;
use Lizard\Typo3ToTypo3\PeerConfiguration;

final class RefreshWorker
{
    public function __construct(
        private readonly DestinationStore $destinations,
        private readonly PeerConfiguration $configuration,
        private readonly PeerClient $client,
    ) {}

    public function run(int $limit = 5000, ?string $resume = null): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Refresh limit must be between 1 and 10000.');
        }
        $config = $this->configuration->load();
        $peers = $config['outgoing'] ?? [];
        if ($resume !== null && !isset($peers[$resume])) {
            throw new \InvalidArgumentException('Unknown peer to resume.');
        }
        $active = [];
        $instances = [];
        foreach ($peers as $id => $peer) {
            if (!is_array($peer) || !PeerConfiguration::isUuid($peer['instance'] ?? null)) {
                throw new \RuntimeException('Invalid outgoing peer identity.');
            }
            if (isset($instances[$peer['instance']])) {
                throw new \RuntimeException('Configure exactly one outgoing peer per instance identity.');
            }
            $instances[$peer['instance']] = true;
        }
        foreach ($peers as $id => $peer) {
            $hash = hash('sha256', json_encode([$config['instance'], $peer], JSON_THROW_ON_ERROR));
            if (($peer['enabled'] ?? false) !== true) {
                $this->destinations->deny($peer['instance'], $hash);
                continue;
            }
            $this->destinations->resume($peer['instance'], $hash, $resume === (string)$id);
            $active[$id] = ['instance' => $peer['instance'], 'hash' => $hash];
        }
        $processed = 0;
        $failed = 0;
        $deadline = microtime(true) + 45;
        // Round-robin batches prevent an unavailable peer consuming the entire run budget.
        while ($active && $processed < $limit && microtime(true) < $deadline) {
            foreach ($active as $id => $peer) {
                if ($processed >= $limit || microtime(true) >= $deadline) {
                    break;
                }
                $rows = $this->destinations->claim($peer['instance'], min(50, $limit - $processed));
                if (!$rows) {
                    unset($active[$id]);
                    continue;
                }
                $processed += count($rows);
                try {
                    $results = $this->client->refresh((string)$id, array_map(DestinationStore::reference(...), $rows),
                        max(0.001, min(3.0, $deadline - microtime(true))));
                    foreach ($results as $result) {
                        if ($result['status'] === 'unsupported') {
                            // Our references are valid. Unsupported is not proof of disappearance.
                            throw new \RuntimeException('Peer did not support identity refresh.');
                        }
                    }
                } catch (\Throwable $exception) {
                    ++$failed;
                    unset($active[$id]);
                    if (in_array($exception->getCode(), [401, 403], true)) {
                        $this->destinations->deny($peer['instance'], $peer['hash']);
                        continue;
                    }
                    foreach ($rows as $row) {
                        $this->destinations->finish($row, null, $peer['hash']);
                    }
                    continue;
                }
                foreach ($rows as $index => $row) {
                    $this->destinations->finish($row, $results[$index], $peer['hash']);
                }
            }
        }
        return ['processed' => $processed, 'failed' => $failed];
    }
}
