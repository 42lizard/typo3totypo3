<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\DeliveryQueue;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DeliveryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testBackoffSurvivesRestartedWorkersAndHistoryPruningKeepsOrdering(): void
    {
        $queue = $this->get(DeliveryQueue::class);
        $scope = hash('sha256', 'outage');
        $healthy = hash('sha256', 'healthy');
        $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('tx_typo3totypo3_delivery_pair');
        $queue->enqueue($scope, 'destination', ['items' => []]);
        foreach ([60, 120, 240, 480, 960, 1920, 3600, 3600] as $delay) {
            $claim = $queue->claim($scope);
            self::assertNotNull($claim);
            $queue->finish($scope, $claim, 503);
            self::assertEqualsWithDelta(time() + $delay, $queue->state($scope)['nextAttempt'], 1);
            self::assertNull($queue->claim($scope));
            $db->update('tx_typo3totypo3_delivery_pair', ['next_attempt' => time() - 1], ['scope_key' => $scope]);
        }
        $db->update('tx_typo3totypo3_delivery_pair', ['first_failure' => time() - 604801, 'next_attempt' => time() + 3600], ['scope_key' => $scope]);
        self::assertTrue($queue->state($scope)['needsAttention']);
        self::assertFalse($queue->state($scope)['overdue'], 'Scheduled backoff is distinguished from runnable overdue work.');
        self::assertFalse($queue->state($scope)['longOverdue']);
        $db->update('tx_typo3totypo3_delivery', ['created_at' => time() - 1801], ['scope_key' => $scope]);
        $db->update('tx_typo3totypo3_delivery_pair', ['next_attempt' => 0], ['scope_key' => $scope]);
        self::assertTrue($queue->state($scope)['longOverdue']);
        $queue->enqueue($healthy, 'destination', ['items' => []]);
        $claim = $queue->claim($healthy);
        self::assertNotNull($claim, 'An unavailable pair must not block another pair.');
        $queue->finish($healthy, $claim);
        $revision = $queue->reserveRevision($healthy);
        $db->executeStatement('UPDATE tx_typo3totypo3_delivery_history SET accepted_at = ?', [time() - 604801]);
        $queue->pruneHistory();
        self::assertSame(0, (int)$db->count('*', 'tx_typo3totypo3_delivery_history', []));
        self::assertGreaterThan($revision, $queue->reserveRevision($healthy));
        self::assertSame(1, $queue->state($scope)['pending']);
    }

    public function testAcknowledgmentCannotDropNewerCoalescedWorkAndDenialPausesPair(): void
    {
        $queue = $this->get(DeliveryQueue::class);
        $scope = hash('sha256', 'notification pair');
        $queue->enqueue($scope, 'destination', ['protocol' => 2, 'items' => [['revision' => 1]]]);
        $first = $queue->claim($scope);
        self::assertCount(1, $first['items']);
        self::assertNull($queue->claim($scope), 'Only one delivery per pairing may be active.');
        $queue->enqueue($scope, 'destination', ['protocol' => 2, 'items' => [['revision' => 2]]]);
        $queue->finish($scope, $first);
        $second = $queue->claim($scope);
        self::assertSame(2, $second['items'][0]['payload']['items'][0]['revision']);
        $queue->finish($scope, $second, 403);
        self::assertNull($queue->claim($scope));
        self::assertTrue($queue->state($scope)['paused']);
        $queue->enqueue($scope, 'another', ['protocol' => 2]);
        self::assertNull($queue->claim($scope), 'Adding work cannot restore revoked permission.');
    }
    public function testIncompleteSnapshotWaitsWithoutBlockingLaterReports(): void
    {
        $queue = $this->get(DeliveryQueue::class);
        $scope = hash('sha256', 'usage pair');
        $snapshot = \Lizard\Typo3ToTypo3\PeerConfiguration::uuid();
        $queue->enqueue($scope, 'stage', ['operation' => 'stage', 'snapshot' => $snapshot]);
        $queue->enqueue($scope, 'complete', ['operation' => 'complete', 'snapshot' => $snapshot]);
        $queue->enqueue($scope, 'report', ['operation' => 'report']);
        $stage = $queue->claim($scope);
        self::assertSame('stage', $stage['items'][0]['payload']['operation']);
        $queue->defer($scope, $stage);
        $report = $queue->claim($scope);
        self::assertSame('report', $report['items'][0]['payload']['operation']);
        $queue->finish($scope, $report);
        self::assertNull($queue->claim($scope));
        self::assertSame(1, $queue->state($scope)['incomplete']);
    }

}
