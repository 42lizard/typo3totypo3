<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Exchange;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class UsageReporter
{
    private const STATE = 'tx_typo3totypo3_report_state';
    private const REFERENCE = 'tx_typo3totypo3_report_reference';

    public function __construct(private readonly SourceUsage $sources, private readonly DeliveryQueue $queue, private readonly ConnectionPool $connections) {}

    public function restart(string $scope): void
    {
        $db = $this->connections->getConnectionForTable(self::STATE);
        $db->transactional(function () use ($db, $scope): void {
            $db->delete(self::REFERENCE, ['scope_key' => $scope]);
            $db->update(self::STATE, ['snapshot_at' => 0], ['scope_key' => $scope]);
        });
    }

    public function prepare(string $scope, string $instance): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $scope) || !PeerConfiguration::isUuid($instance)) {
            throw new \InvalidArgumentException('Invalid report target.');
        }
        $db = $this->connections->getConnectionForTable(self::STATE);
        foreach ([self::REFERENCE, 'tx_typo3totypo3_delivery', 'tx_typo3totypo3_delivery_pair'] as $table) {
            if ($db !== $this->connections->getConnectionForTable($table)) { throw new \RuntimeException('Report publication requires one connection.'); }
        }
        try { $db->insert(self::STATE, ['scope_key' => $scope]); } catch (UniqueConstraintViolationException) {}
        // Create ordering state outside the transaction to avoid a concurrent unique-key error inside it.
        $this->queue->initialize($scope);
        $db->transactional(function () use ($db, $scope, $instance): void {
            $db->executeStatement('UPDATE ' . self::STATE . ' SET snapshot_at = snapshot_at WHERE scope_key = ?', [$scope]);
            $source = $this->sources->snapshot($instance);
            if ($source === null) { return; }
            $references = $source['references'];
            $state = $db->select(['*'], self::STATE, ['scope_key' => $scope])->fetchAssociative();
            $previous = [];
            foreach ($db->select(['page_uuid', 'language_id'], self::REFERENCE, ['scope_key' => $scope])->fetchAllAssociative() as $row) {
                $previous[$row['page_uuid'] . ':' . $row['language_id']] = ['page' => $row['page_uuid'], 'language' => (int)$row['language_id']];
            }
            $current = [];
            foreach ($references as $ref) { $current[$ref['page'] . ':' . $ref['language']] = ['page' => $ref['page'], 'language' => $ref['language']]; }
            $added = array_diff_key($current, $previous);
            $removed = $source['established'] ? array_diff_key($previous, $current) : [];
            if ($added || $removed) {
                $revision = $this->queue->reserveRevision($scope);
                $items = [];
                foreach ($added as $ref) { $items[] = $ref + ['revision' => $revision, 'present' => true]; }
                foreach ($removed as $ref) { $items[] = $ref + ['revision' => $revision, 'present' => false]; }
                foreach (array_chunk($items, 50) as $index => $chunk) {
                    $this->queue->enqueue($scope, 'report:' . $revision . ':' . $index, ['operation' => 'report', 'items' => $chunk]);
                }
                foreach ($added as $ref) { $db->insert(self::REFERENCE, ['scope_key' => $scope, 'page_uuid' => $ref['page'], 'language_id' => $ref['language']]); }
                foreach ($removed as $ref) { $db->delete(self::REFERENCE, ['scope_key' => $scope, 'page_uuid' => $ref['page'], 'language_id' => $ref['language']]); }
            }
            if ($source['complete'] && (int)$state['snapshot_at'] <= time() - 86400) {
                // Abandon old incomplete snapshots without publishing their absence conclusions.
                $db->executeStatement("DELETE FROM tx_typo3totypo3_delivery WHERE scope_key = ? AND snapshot_uuid <> ''", [$scope]);
                $revision = $this->queue->reserveRevision($scope);
                $snapshot = PeerConfiguration::uuid();
                $base = ['snapshot' => $snapshot, 'revision' => $revision];
                foreach (array_chunk(array_values($current), 50) ?: [[]] as $index => $items) {
                    $this->queue->enqueue($scope, $snapshot . ':' . $index, $base + ['operation' => 'stage', 'items' => $items]);
                }
                $hash = hash_init('sha256');
                foreach ($current as $ref) { hash_update($hash, $ref['page'] . ':' . $ref['language'] . "\n"); }
                $this->queue->enqueue($scope, $snapshot . ':complete', $base + ['operation' => 'complete', 'count' => count($current), 'digest' => hash_final($hash)]);
                $db->update(self::STATE, ['snapshot_at' => time()], ['scope_key' => $scope]);
            }
        });
    }
}
