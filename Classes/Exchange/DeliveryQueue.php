<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DeliveryQueue
{
    private const PAIR = 'tx_typo3totypo3_delivery_pair';
    private const JOB = 'tx_typo3totypo3_delivery';

    public function __construct(private readonly ConnectionPool $connections) {}

    public function initialize(string $scope): void
    {
        $this->database($scope);
    }

    public function reserveRevision(string $scope): int
    {
        $db = $this->database($scope);
        return $db->transactional(function () use ($db, $scope): int {
            $db->executeStatement('UPDATE ' . self::PAIR . ' SET sequence_number = sequence_number + 1 WHERE scope_key = ? AND sequence_number < 2147483647', [$scope]);
            $revision = (int)$db->select(['sequence_number'], self::PAIR, ['scope_key' => $scope])->fetchOne();
            if ($revision >= 2147483647) { throw new \RuntimeException('Pairing generation exhausted.'); }
            return $revision;
        });
    }

    public function enqueue(string $scope, string $key, array $payload): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($body) > 65536 || $key === '' || strlen($key) > 255) {
            throw new \InvalidArgumentException('Invalid delivery.');
        }
        $db = $this->database($scope);
        $db->transactional(function () use ($db, $scope, $key, $body, $payload): void {
            $revision = $this->reserveRevision($scope);
            $identity = ['scope_key' => $scope, 'message_key' => hash('sha256', $key)];
            $exists = $db->count('*', self::JOB, $identity);
            $data = ['sequence_number' => $revision, 'payload' => $body,
                'snapshot_uuid' => $payload['snapshot'] ?? '', 'completion' => (int)(($payload['operation'] ?? '') === 'complete')];
            if ($exists) { $db->update(self::JOB, $data, $identity); }
            else { $db->insert(self::JOB, $identity + $data + ['created_at' => time()]); }
        });
    }

    public function claim(string $scope, int $limit = 1): ?array
    {
        $db = $this->database($scope);
        return $db->transactional(function () use ($db, $scope, $limit): ?array {
            $token = bin2hex(random_bytes(16));
            if ($db->executeStatement('UPDATE ' . self::PAIR . ' SET lease_token = ?, lease_until = ?'
                . ' WHERE scope_key = ? AND paused = 0 AND next_attempt <= ? AND lease_until <= ?', [$token, time() + 120, $scope, time(), time()]) !== 1) {
                return null;
            }
            $rows = $db->executeQuery('SELECT j.* FROM ' . self::JOB . ' j WHERE j.scope_key = ? AND j.not_before <= ?'
                . ' AND (j.completion = 0 OR NOT EXISTS (SELECT 1 FROM ' . self::JOB
                . ' s WHERE s.scope_key = j.scope_key AND s.snapshot_uuid = j.snapshot_uuid AND s.completion = 0))'
                . ' ORDER BY j.sequence_number LIMIT ' . max(1, min(50, $limit)), [$scope, time()])->fetchAllAssociative();
            if (!$rows) {
                $db->update(self::PAIR, ['lease_token' => '', 'lease_until' => 0], ['scope_key' => $scope, 'lease_token' => $token]);
                return null;
            }
            foreach ($rows as &$row) { $row['payload'] = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR); }
            return ['token' => $token, 'items' => $rows];
        });
    }

    /** HTTP acceptance completes delivery, not destination refresh. */
    public function finish(string $scope, array $claim, int $error = 0, bool $acknowledged = true): void
    {
        $db = $this->database($scope);
        $db->transactional(function () use ($db, $scope, $claim, $error, $acknowledged): void {
            if ($db->executeStatement('UPDATE ' . self::PAIR . ' SET lease_until = 0 WHERE scope_key = ? AND lease_token = ?', [$scope, $claim['token']]) !== 1) { return; }
            $pair = $db->select(['*'], self::PAIR, ['scope_key' => $scope])->fetchAssociative();
            $data = ['lease_token' => '', 'lease_until' => 0];
            if ($error === 0) {
                foreach ($claim['items'] as $item) {
                    $db->delete(self::JOB, ['scope_key' => $scope, 'message_key' => $item['message_key'], 'sequence_number' => $item['sequence_number']]);
                }
                $data += ['attempts' => 0, 'next_attempt' => 0, 'first_failure' => 0, 'last_error' => ''];
                if ($acknowledged) {
                    $data['accepted_at'] = time();
                    $db->insert('tx_typo3totypo3_delivery_history', ['event_key' => bin2hex(random_bytes(16)),
                        'scope_key' => $scope, 'accepted_at' => time(), 'item_count' => count($claim['items'])]);
                }
            } else {
                $attempts = min(16, (int)$pair['attempts'] + 1);
                $data += ['attempts' => $attempts, 'first_failure' => (int)$pair['first_failure'] ?: time(),
                    'next_attempt' => time() + min(3600, 60 * (2 ** ($attempts - 1))),
                    'paused' => (int)in_array($error, [401, 403], true), 'last_error' => in_array($error, [401, 403], true) ? 'denied' : 'transient'];
            }
            $db->update(self::PAIR, $data, ['scope_key' => $scope, 'lease_token' => $claim['token']]);
        });
    }

    /** Application-level incomplete reports must not block other destinations on this pairing. */
    public function defer(string $scope, array $claim): void
    {
        $db = $this->database($scope);
        $db->transactional(function () use ($db, $scope, $claim): void {
            if ($db->executeStatement('UPDATE ' . self::PAIR . ' SET lease_until = 0 WHERE scope_key = ? AND lease_token = ?', [$scope, $claim['token']]) !== 1) { return; }
            foreach ($claim['items'] as $item) {
                $db->update(self::JOB, ['not_before' => time() + 60, 'incomplete' => 1],
                    ['scope_key' => $scope, 'message_key' => $item['message_key'], 'sequence_number' => $item['sequence_number']]);
            }
            $db->update(self::PAIR, ['lease_token' => '', 'lease_until' => 0], ['scope_key' => $scope, 'lease_token' => $claim['token']]);
        });
    }

    public function pruneHistory(): void
    {
        $this->connections->getConnectionForTable('tx_typo3totypo3_delivery_history')
            ->executeStatement('DELETE FROM tx_typo3totypo3_delivery_history WHERE accepted_at < ?', [time() - 604800]);
    }

    public function retry(string $scope): void
    {
        $db = $this->database($scope);
        $db->update(self::PAIR, ['paused' => 0, 'next_attempt' => 0, 'attempts' => 0], ['scope_key' => $scope]);
        $db->update(self::JOB, ['not_before' => 0], ['scope_key' => $scope]);
    }

    public function restart(string $scope): void
    {
        $db = $this->database($scope);
        $db->transactional(function () use ($db, $scope): void {
            $db->update(self::PAIR, ['lease_token' => '', 'lease_until' => 0, 'paused' => 0, 'attempts' => 0,
                'next_attempt' => 0, 'first_failure' => 0, 'last_error' => ''], ['scope_key' => $scope]);
            $db->delete(self::JOB, ['scope_key' => $scope]);
        });
    }

    public function state(string $scope): array
    {
        $db = $this->database($scope);
        $row = $db->select(['*'], self::PAIR, ['scope_key' => $scope])->fetchAssociative();
        $oldest = (int)$db->executeQuery('SELECT MIN(created_at) FROM ' . self::JOB . ' WHERE scope_key = ?', [$scope])->fetchOne();
        $runnable = (int)$db->executeQuery('SELECT MIN(created_at) FROM ' . self::JOB . ' WHERE scope_key = ? AND not_before <= ?', [$scope, time()])->fetchOne();
        $ready = !(bool)$row['paused'] && (int)$row['next_attempt'] <= time() && $runnable > 0;
        return ['paused' => (bool)$row['paused'], 'pending' => (int)$db->count('*', self::JOB, ['scope_key' => $scope]),
            'oldestAge' => $oldest ? max(0, time() - $oldest) : 0,
            'overdue' => $ready && $runnable < time() - 300,
            'longOverdue' => $ready && $runnable < time() - 1800,
            'incomplete' => (int)$db->count('*', self::JOB, ['scope_key' => $scope, 'incomplete' => 1]),
            'nextAttempt' => (int)$row['next_attempt'], 'acceptedAt' => (int)$row['accepted_at'], 'error' => $row['last_error'],
            'needsAttention' => (int)$row['first_failure'] > 0 && (int)$row['first_failure'] <= time() - 604800];
    }

    private function database(string $scope): Connection
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope)) { throw new \InvalidArgumentException('Invalid pairing scope.'); }
        $db = $this->connections->getConnectionForTable(self::PAIR);
        if ($db !== $this->connections->getConnectionForTable(self::JOB)
            || $db !== $this->connections->getConnectionForTable('tx_typo3totypo3_delivery_history')) { throw new \RuntimeException('Delivery tables require one connection.'); }
        if (!$db->count('*', self::PAIR, ['scope_key' => $scope])) {
            try { $db->insert(self::PAIR, ['scope_key' => $scope]); } catch (UniqueConstraintViolationException) {}
        }
        return $db;
    }
}
