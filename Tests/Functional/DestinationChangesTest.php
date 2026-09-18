<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\DeliveryQueue;
use Lizard\Typo3ToTypo3\Exchange\DestinationChanges;
use Lizard\Typo3ToTypo3\Exchange\ExchangeConfiguration;
use Lizard\Typo3ToTypo3\Exchange\UsageRegistry;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DestinationChangesTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testAncestorHintsPrioritizeDescendantsAndDraftTranslationsMapToLivePage(): void
    {
        $db = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable('pages');
        foreach ([[1, 0, 0, 0, 0], [2, 1, 0, 0, 0], [3, 2, 0, 0, 0], [4, 0, 0, 2, 0], [5, 0, 4, 0, 1]] as $row) {
            $db->insert('pages', array_combine(['uid', 'pid', 't3ver_oid', 'l10n_parent', 't3ver_wsid'], $row));
        }
        $ids = [];
        foreach ([1, 2, 3] as $uid) {
            $ids[$uid] = PeerConfiguration::uuid();
            $db->insert('tx_typo3totypo3_identity', ['page_uid' => $uid, 'uuid' => $ids[$uid]]);
            $db->insert('tx_typo3totypo3_observation', ['usage_scope' => hash('sha256', 'usage'), 'notification_scope' => hash('sha256', 'notify'),
                'page_uuid' => $ids[$uid], 'language_id' => 0, 'checked_at' => time(), 'priority' => 0]);
        }
        $changes = $this->get(DestinationChanges::class);
        $changes->requestRecheck(1);
        $changes->processHints();
        self::assertSame(3, (int)$db->count('*', 'tx_typo3totypo3_observation', ['priority' => 1, 'checked_at' => 0]));
        $db->executeStatement('UPDATE tx_typo3totypo3_observation SET priority = 0, checked_at = ?', [time()]);
        $changes->requestRecheck(5);
        $changes->processHints();
        self::assertSame(1, (int)$db->select(['priority'], 'tx_typo3totypo3_observation', ['page_uuid' => $ids[2]])->fetchOne());
        self::assertSame(0, (int)$db->select(['priority'], 'tx_typo3totypo3_observation', ['page_uuid' => $ids[1]])->fetchOne());
    }

    public function testKnownUnavailableDestinationNotifiesOnlyWhileItsSiteGrantRemainsValid(): void
    {
        $consumer = PeerConfiguration::uuid();
        $environment = PeerConfiguration::uuid();
        $incoming = ['enabled' => true, 'instance' => $consumer, 'environment' => $environment, 'generation' => PeerConfiguration::uuid(), 'capability' => 'usage', 'sites' => ['main']];
        $outgoing = $incoming;
        $outgoing['capability'] = 'notify';
        $outgoing['generation'] = PeerConfiguration::uuid();
        $config = ['instance' => PeerConfiguration::uuid(), 'environment' => PeerConfiguration::uuid(), 'incoming' => ['usage' => $incoming], 'outgoing' => ['notify' => $outgoing]];
        $page = PeerConfiguration::uuid();
        $scope = ExchangeConfiguration::scope($config, $incoming);
        $this->get(UsageRegistry::class)->report($scope, [['page' => $page, 'language' => 0, 'present' => true, 'revision' => 1, 'site' => 'main']]);
        $changes = $this->get(DestinationChanges::class);
        $changes->scan($config, 'notify');
        $notificationScope = ExchangeConfiguration::scope($config, $outgoing);
        $claim = $this->get(DeliveryQueue::class)->claim($notificationScope);
        self::assertCount(1, $claim['items']);
        $reference = ['instance' => $config['instance'], 'page' => $page, 'language' => 0];
        self::assertSame($reference, $claim['items'][0]['payload']['items'][0]['reference']);
        self::assertSame(['reference', 'revision'], array_keys($claim['items'][0]['payload']['items'][0]));
        self::assertTrue($changes->mayNotify($config, $outgoing, $reference));
        $config['incoming']['usage']['sites'] = [];
        self::assertFalse($changes->mayNotify($config, $outgoing, $reference), 'Queued messages must be reauthorized before delivery.');
    }
}
