<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\NotificationInbox;
use Lizard\Typo3ToTypo3\Link\DestinationStore;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class NotificationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testNewerNotificationSurvivesAnInFlightRefreshAndReplayDoesNotRequeue(): void
    {
        $reference = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $destinations = $this->get(DestinationStore::class);
        $destinations->record($reference, 'resolved', 'https://peer.example/old');
        $inbox = $this->get(NotificationInbox::class);
        $scope = hash('sha256', 'environment and pairing generation');
        $inbox->accept($scope, [['reference' => $reference, 'revision' => 1]]);
        $claimed = $destinations->claim($reference['instance'], 1);
        self::assertCount(1, $claimed);
        $inbox->accept($scope, [['reference' => $reference, 'revision' => 2]]);
        self::assertSame([], $destinations->claim($reference['instance'], 1), 'A notification must not start an overlapping refresh.');
        self::assertFalse($destinations->finish($claimed[0], ['status' => 'resolved', 'url' => 'https://peer.example/old'], 'peer'));
        $newClaim = $destinations->claim($reference['instance'], 1);
        self::assertCount(1, $newClaim);
        self::assertTrue($destinations->finish($newClaim[0], ['status' => 'resolved', 'url' => 'https://peer.example/new'], 'peer'));
        $inbox->accept($scope, [['reference' => $reference, 'revision' => 2], ['reference' => $reference, 'revision' => 1]]);
        self::assertSame([], $destinations->claim($reference['instance'], 1));
        self::assertSame('https://peer.example/new', $destinations->find($reference)['url']);
    }
    public function testAcceptanceDoesNotCreateUnknownDestinationsOrResumeDeniedOnes(): void
    {
        $reference = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $store = $this->get(DestinationStore::class);
        $inbox = $this->get(NotificationInbox::class);
        $scope = hash('sha256', 'test pairing');
        $inbox->accept($scope, [['reference' => $reference, 'revision' => 1]]);
        self::assertNull($store->find($reference));
        $store->record($reference, 'resolved', 'https://peer.example/');
        $store->deny($reference['instance'], 'denied');
        $inbox->accept($scope, [['reference' => $reference, 'revision' => 2]]);
        self::assertSame('denied', $store->find($reference)['status']);
        self::assertSame([], $store->claim($reference['instance'], 50));
    }

    public function testInvalidBatchCannotPartiallyQueueWork(): void
    {
        $reference = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $store = $this->get(DestinationStore::class);
        $store->record($reference, 'resolved', 'https://peer.example/');
        try {
            $this->get(NotificationInbox::class)->accept(hash('sha256', 'pair'), [
                ['reference' => $reference, 'revision' => 1], ['reference' => $reference, 'revision' => -1],
            ]);
            self::fail('Invalid batch accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $store->claim($reference['instance'], 50));
        }
    }

}
