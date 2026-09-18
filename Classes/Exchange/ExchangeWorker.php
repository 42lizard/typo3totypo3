<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

final class ExchangeWorker
{
    public function __construct(
        private readonly ExchangeConfiguration $configuration,
        private readonly SourceUsage $sources,
        private readonly UsageRegistry $registry,
        private readonly UsageReporter $reporter,
        private readonly DestinationChanges $changes,
        private readonly CapabilityState $capabilities,
        private readonly DeliveryQueue $queue,
        private readonly ExchangeClient $client,
        private readonly \TYPO3\CMS\Core\Cache\CacheManager $cache,
        private readonly \TYPO3\CMS\Core\Database\ConnectionPool $connections,
    ) {}

    public function run(int $limit = 10000, bool $recheck = false): array
    {
        // ponytail: one bounded worker per deployment; shard by capability only if measured throughput requires it.
        $db = $this->connections->getConnectionForTable('tx_typo3totypo3_worker');
        try { $db->insert('tx_typo3totypo3_worker', ['uid' => 1]); }
        catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {}
        $token = bin2hex(random_bytes(16));
        if ($db->executeStatement('UPDATE tx_typo3totypo3_worker SET lease_token = ?, lease_until = ? WHERE uid = 1 AND lease_until <= ?',
            [$token, time() + 120, time()]) !== 1) { return ['accepted' => 0, 'checked' => 0, 'failed' => 0, 'deferred' => 0]; }
        try {
            $cursor = (int)$db->select(['peer_cursor'], 'tx_typo3totypo3_worker', ['uid' => 1])->fetchOne();
            $db->update('tx_typo3totypo3_worker', ['peer_cursor' => ($cursor + 1) % 100], ['uid' => 1, 'lease_token' => $token]);
            return $this->execute($limit, $recheck, $cursor);
        } finally {
            $db->update('tx_typo3totypo3_worker', ['lease_token' => '', 'lease_until' => 0], ['uid' => 1, 'lease_token' => $token]);
        }
    }

    private function execute(int $limit, bool $recheck, int $cursor): array
    {
        $result = ['accepted' => 0, 'checked' => 0, 'failed' => 0, 'deferred' => 0];
        $deadline = microtime(true) + 45;
        try { $config = $this->configuration->load(); }
        catch (\RuntimeException) { return $result; }
        // A scheduler may reuse the PHP process; start with fresh resolver-visible page data.
        $this->cache->getCache('runtime')->flush();
        $this->changes->beginRun();
        $channels = $config['outgoing'];
        if ($channels) {
            $offset = $cursor % count($channels);
            $channels = array_slice($channels, $offset, null, true) + array_slice($channels, 0, $offset, true);
        }
        $this->sources->processChanges(1000, microtime(true) + 5);
        $this->sources->reconcile(2000, deadline: microtime(true) + 10);
        $this->changes->processRechecks();
        $this->changes->processHints();
        $this->queue->pruneHistory();
        $this->registry->expireSnapshots();
        $prepared = [];
        $skip = [];
        $remaining = max(1, min(100000, $limit));
        do {
            $progress = 0;
            foreach ($channels as $name => $channel) {
                if ($remaining <= 0 || microtime(true) >= $deadline - 0.1) { break 2; }
                if (isset($skip[$name])) { continue; }
                $scope = ExchangeConfiguration::scope($config, $channel);
                try {
                    if (!isset($prepared[$name])) {
                        if ($this->capabilities->check($config, $name, $recheck) !== 'supported') { $skip[$name] = true; continue; }
                        if ($channel['capability'] === 'usage') { $this->reporter->prepare($scope, $channel['instance']); }
                        $prepared[$name] = true;
                    }
                    if ($channel['capability'] === 'notify') {
                        $checked = $this->changes->scan($config, $name, min(50, $remaining));
                        $result['checked'] += $checked;
                        $progress += $checked;
                        $remaining -= $checked;
                    }
                    if (microtime(true) >= $deadline - 0.1) { break 2; }
                    $claim = $this->queue->claim($scope, $channel['capability'] === 'notify' ? 50 : 1);
                    if ($claim === null) { continue; }
                    ++$progress;
                    --$remaining;
                    try {
                        $payload = $claim['items'][0]['payload'];
                        if ($channel['capability'] === 'notify') {
                            $payload = ['items' => []];
                            $current = $this->configuration->load();
                            if (!isset($current['outgoing'][$name]) || ExchangeConfiguration::scope($current, $current['outgoing'][$name]) !== $scope) {
                                throw new \RuntimeException('Pairing changed.', 409);
                            }
                            foreach ($claim['items'] as $job) {
                                foreach ($job['payload']['items'] as $item) {
                                    if ($this->changes->mayNotify($current, $current['outgoing'][$name], $item['reference'])) { $payload['items'][] = $item; }
                                }
                            }
                            if (!$payload['items']) { $this->queue->finish($scope, $claim, acknowledged: false); continue; }
                        }
                        $response = $this->client->send($name, $channel['capability'], $payload, max(0.01, min(3.0, $deadline - microtime(true))), $scope, ExchangeConfiguration::fingerprint($channel));
                        if ($channel['capability'] === 'usage' && !$response['complete']) {
                            $this->queue->defer($scope, $claim);
                            ++$result['deferred'];
                        } else {
                            $this->queue->finish($scope, $claim);
                            $result['accepted'] += count($claim['items']);
                        }
                    } catch (\Throwable $error) {
                        $this->queue->finish($scope, $claim, (int)$error->getCode() ?: 503);
                        ++$result['failed'];
                        $skip[$name] = true;
                    }
                } catch (\Throwable) {
                    ++$result['failed'];
                    $skip[$name] = true;
                }
            }
        } while ($progress > 0 && $remaining > 0 && microtime(true) < $deadline - 0.1);
        return $result;
    }
}
