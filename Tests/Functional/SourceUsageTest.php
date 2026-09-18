<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\Exchange\SourceUsage;
use Lizard\Typo3ToTypo3\Link\ManagedLink;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SourceUsageTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testReconciliationCountsActualLiveAndDraftLinksAndLastRemoval(): void
    {
        $reference = ['instance' => PeerConfiguration::uuid(), 'page' => PeerConfiguration::uuid(), 'language' => 0];
        $url = (new ManagedLink())->asString($reference);
        $db = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $db->insert('tt_content', ['uid' => 1, 'pid' => 1, 'CType' => 'header', 'header_link' => $url, 'hidden' => 1]);
        $db->insert('tt_content', ['uid' => 2, 'pid' => 1, 'CType' => 'text', 'bodytext' => '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Link</a></p>', 't3ver_wsid' => 1, 't3ver_oid' => 1]);
        $sources = $this->get(SourceUsage::class);
        $sources->reconcile();
        self::assertSame([$reference], $sources->references($reference['instance']));
        $db->update('tt_content', ['deleted' => 1], ['uid' => 1]);
        $sources->changed('tt_content', 1);
        $sources->processChanges();
        self::assertSame([$reference], $sources->references($reference['instance']));
        $db->update('tt_content', ['deleted' => 1], ['uid' => 2]);
        $sources->changed('tt_content', 2);
        $sources->processChanges();
        self::assertSame([], $sources->references($reference['instance']));
    }
}
