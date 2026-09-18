<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UsageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testSnapshotCannotCompleteUnderAChangedGrant(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'changed grant');
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 1, [], 'old-grant');
        $this->expectException(\InvalidArgumentException::class);
        $registry->complete($scope, $snapshot, 1, 0, hash('sha256', ''), 'new-grant');
    }

    public function testExpiredPartialSnapshotKeepsConfirmedUsageAndRejectsDelayedChunks(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'expired snapshot');
        $page = PeerConfiguration::uuid();
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 2, []);
        $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('tx_typo3totypo3_usage_pair');
        $db->update('tx_typo3totypo3_usage_pair', ['snapshot_updated' => time() - 86401], ['scope_key' => $scope]);
        $registry->expireSnapshots();
        self::assertCount(1, $registry->usages($scope));
        $this->expectException(\InvalidArgumentException::class);
        $registry->stage($scope, $snapshot, 2, []);
    }

    public function testNewerRemovalWinsOverDelayedRegistrationAndPartialSnapshot(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'paired environments');
        $page = PeerConfiguration::uuid();
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        self::assertCount(1, $registry->usages($scope));
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 3, 'present' => false, 'site' => 'main']]);
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 2, 'present' => true, 'site' => 'main']]);
        self::assertSame([], $registry->usages($scope));
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 4, 'present' => true, 'site' => 'main']]);
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 5, [['page' => PeerConfiguration::uuid(), 'language' => 0, 'site' => 'main']]);
        self::assertCount(1, $registry->usages($scope), 'Partial snapshot must not change confirmed usage.');
    }

    public function testCompleteSnapshotRemovesAbsentUsageButCannotOverwriteNewerChanges(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'paired environments');
        $old = PeerConfiguration::uuid();
        $new = PeerConfiguration::uuid();
        $registry->report($scope, [['page' => $old, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 2, []);
        $registry->report($scope, [['page' => $new, 'language' => 0, 'revision' => 3, 'present' => true, 'site' => 'main']]);
        $registry->complete($scope, $snapshot, 2, 0, hash('sha256', ''));
        $rows = $registry->usages($scope);
        self::assertCount(1, $rows);
        self::assertSame($new, $rows[0]['page_uuid']);
        $registry->report($scope, [['page' => $old, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        self::assertCount(1, $registry->usages($scope));
    }
    public function testCompletedEmptySnapshotRejectsDelayedPreviouslyUnknownReferences(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'empty snapshot');
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 9, []);
        $registry->complete($scope, $snapshot, 9, 0, hash('sha256', ''));
        $registry->report($scope, [['page' => PeerConfiguration::uuid(), 'language' => 0, 'revision' => 8, 'present' => true, 'site' => 'main']]);
        self::assertSame([], $registry->usages($scope));
    }

    public function testIncompleteCompletionDoesNotRemoveUsageAndCanBeRetried(): void
    {
        $registry = $this->get(UsageRegistry::class);
        $scope = hash('sha256', 'snapshot retry');
        $page = PeerConfiguration::uuid();
        $registry->report($scope, [['page' => $page, 'language' => 0, 'revision' => 1, 'present' => true, 'site' => 'main']]);
        $snapshot = PeerConfiguration::uuid();
        $registry->stage($scope, $snapshot, 2, []);
        try {
            $registry->complete($scope, $snapshot, 2, 1, hash('sha256', $page . ":0\n"));
            self::fail('Incomplete snapshot must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $registry->usages($scope));
        }
        $registry->stage($scope, $snapshot, 2, [['page' => $page, 'language' => 0, 'site' => 'main']]);
        $registry->complete($scope, $snapshot, 2, 1, hash('sha256', $page . ":0\n"));
        self::assertCount(1, $registry->usages($scope));
        $registry->complete($scope, $snapshot, 2, 1, hash('sha256', $page . ":0\n"));
        self::assertCount(1, $registry->usages($scope));
    }

}
