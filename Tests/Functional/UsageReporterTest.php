<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\DeliveryQueue;
use Lizard\Typo3ToTypo3\Exchange\SourceUsage;
use Lizard\Typo3ToTypo3\Exchange\UsageReporter;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UsageReporterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testReportingExportsOnlyDestinationPresenceAndQueuesRemoval(): void
    {
        $ref = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $db = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $db->insert('tt_content', ['uid' => 1, 'pid' => 1, 'CType' => 'header', 'header' => 'Private draft title', 'header_link' => (new ManagedLink())->asString($ref)]);
        $sources = $this->get(SourceUsage::class);
        $sources->reconcile();
        $scope = hash('sha256', 'usage reporting');
        $reporter = $this->get(UsageReporter::class);
        $queue = $this->get(DeliveryQueue::class);
        $reporter->prepare($scope, $ref['instance']);
        $first = $queue->claim($scope);
        $payload = $first['items'][0]['payload'];
        self::assertSame('report', $payload['operation']);
        self::assertSame(['page', 'language', 'revision', 'present'], array_keys($payload['items'][0]));
        self::assertTrue($payload['items'][0]['present']);
        $revision = $payload['items'][0]['revision'];
        $queue->finish($scope, $first);
        while ($claim = $queue->claim($scope)) { $queue->finish($scope, $claim); }
        $db->update('tt_content', ['header_link' => ''], ['uid' => 1]);
        $sources->changed('tt_content', 1);
        $sources->processChanges();
        $sources->requestReconciliation();
        // A daily full pass must not hold a known last-reference removal for its entire duration.
        $reporter->prepare($scope, $ref['instance']);
        $removal = $queue->claim($scope)['items'][0]['payload']['items'][0];
        self::assertFalse($removal['present']);
        self::assertGreaterThan($revision, $removal['revision']);
    }
}
