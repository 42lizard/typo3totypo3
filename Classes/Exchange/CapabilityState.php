<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class CapabilityState
{
    private const TABLE = 'tx_typo3totypo3_capability';

    public function __construct(
        private readonly ExchangeClient $client,
        private readonly DeliveryQueue $queue,
        private readonly UsageReporter $reporter,
        private readonly DestinationChanges $changes,
        private readonly ConnectionPool $connections,
    ) {}

    public function check(array $config, string $name, bool $force = false): string
    {
        $channel = $config['outgoing'][$name];
        $scope = ExchangeConfiguration::scope($config, $channel);
        $fingerprint = ExchangeConfiguration::fingerprint($channel);
        $db = $this->connections->getConnectionForTable(self::TABLE);
        try { $db->insert(self::TABLE, ['scope_key' => $scope]); } catch (UniqueConstraintViolationException) {}
        $state = $db->select(['*'], self::TABLE, ['scope_key' => $scope])->fetchAssociative();
        if ($state['configuration_hash'] !== $fingerprint) {
            $this->queue->restart($scope);
            $this->reporter->restart($scope);
            $this->changes->restart($scope);
            $state = ['status' => 'unknown', 'attempts' => 0, 'next_check' => 0];
            $db->update(self::TABLE, $state + ['configuration_hash' => $fingerprint], ['scope_key' => $scope]);
        }
        if (!$channel['enabled']) {
            $db->update(self::TABLE, ['status' => 'disabled'], ['scope_key' => $scope]);
            return 'disabled';
        }
        if (!$force && ($state['status'] !== 'unknown' || (int)$state['next_check'] > time())) { return $state['status']; }
        try {
            $response = $this->client->send($name, 'capabilities', [], expectedScope: $scope, expectedFingerprint: $fingerprint);
            $status = in_array($channel['capability'], $response['capabilities'], true) ? 'supported' : 'unsupported';
            $data = ['status' => $status, 'checked_at' => time(), 'attempts' => 0, 'next_check' => 0];
        } catch (\RuntimeException $error) {
            $status = match ($error->getCode()) { 404, 405 => 'unsupported', 401, 403 => 'denied', default => 'unknown' };
            $attempts = min(16, (int)$state['attempts'] + 1);
            $data = ['status' => $status, 'checked_at' => time(), 'attempts' => $attempts, 'next_check' => time() + min(3600, 60 * (2 ** ($attempts - 1)))];
        }
        $db->update(self::TABLE, $data, ['scope_key' => $scope, 'configuration_hash' => $fingerprint]);
        return $status;
    }

    public function requestCheck(string $scope): void
    {
        $this->connections->getConnectionForTable(self::TABLE)->update(self::TABLE,
            ['status' => 'unknown', 'next_check' => 0], ['scope_key' => $scope]);
    }

    public function status(string $scope): string
    {
        return $this->connections->getConnectionForTable(self::TABLE)->select(['status'], self::TABLE, ['scope_key' => $scope])->fetchOne() ?: 'unknown';
    }
}
