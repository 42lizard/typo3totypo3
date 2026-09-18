<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Functional;

use Lizard\Typo3ToTypo3\PageIdentity;
use Lizard\Typo3ToTypo3\PageResolver;
use Lizard\Typo3ToTypo3\PeerConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageLifecycleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'fluid_styled_content'];
    protected array $testExtensionsToLoad = ['42lizard/typo3-to-typo3'];

    public function testCoreCopyDeleteRecreateAndRestoreKeepTheCorrectDestinationIdentity(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Report.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->create('en');
        $path = Environment::getConfigPath() . '/sites/main';
        mkdir($path, 0777, true);
        file_put_contents($path . '/config.yaml', "rootPageId: 1\nbase: 'https://lifecycle.example/'\nlanguages:\n  - title: English\n    enabled: true\n    languageId: 0\n    base: /\n    locale: en_US.UTF-8\n");
        $this->get(SiteFinder::class)->getAllSites(false);
        $change = static function (array $data, array $commands = []): DataHandler {
            $handler = GeneralUtility::makeInstance(DataHandler::class);
            $handler->start($data, $commands);
            $handler->process_datamap();
            $handler->process_cmdmap();
            self::assertSame([], $handler->errorLog);
            return $handler;
        };
        $instance = PeerConfiguration::uuid();
        $identity = $this->get(PageIdentity::class);
        $reference = static fn(int $uid): array => ['instance' => $instance, 'page' => $identity->forPage($uid), 'language' => 0];
        $resolver = $this->get(PageResolver::class);
        $resolve = static fn(array $ref): array => $resolver->resolve($ref, true, ['main'], $instance);
        $new = $change(['pages' => ['NEWoriginal' => ['pid' => 1, 'title' => 'Original', 'slug' => '/original', 'doktype' => 1, 'hidden' => 0]]]);
        $uid = (int)$new->substNEWwithIDs['NEWoriginal'];
        $original = $reference($uid);
        self::assertSame('resolved', $resolve($original)['status']);
        $copy = $change([], ['pages' => [$uid => ['copy' => 1]]]);
        $copyUid = (int)$copy->copyMappingArray_merged['pages'][$uid];
        $change(['pages' => [$copyUid => ['hidden' => 0]]]);
        $copied = $reference($copyUid);
        self::assertNotSame($original, $copied);
        self::assertSame('resolved', $resolve($copied)['status']);
        $change([], ['pages' => [$uid => ['delete' => 1]]]);
        self::assertSame(['status' => 'unavailable'], $resolve($original));
        $replacement = $change(['pages' => ['NEWreplacement' => ['pid' => 1, 'title' => 'Replacement', 'slug' => '/original', 'doktype' => 1, 'hidden' => 0]]]);
        $recreated = $reference((int)$replacement->substNEWwithIDs['NEWreplacement']);
        self::assertNotSame($original, $recreated);
        self::assertSame('resolved', $resolve($recreated)['status']);
        self::assertSame(['status' => 'unavailable'], $resolve($original), 'Reusing a readable URL must not retarget the deleted identity.');
        $change([], ['pages' => [$uid => ['undelete' => 1]]]);
        self::assertSame($original, $reference($uid));
        self::assertSame($original, $resolve($original)['reference']);
        self::assertSame($recreated, $resolve($recreated)['reference']);
        $otherSite = Environment::getConfigPath() . '/sites/other';
        mkdir($otherSite, 0777, true);
        file_put_contents($otherSite . '/config.yaml', "rootPageId: 2\nbase: 'https://other-site.example/'\nlanguages:\n  - title: English\n    enabled: true\n    languageId: 0\n    base: /\n    locale: en_US.UTF-8\n");
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('runtime')->flush();
        $change([], ['pages' => [$uid => ['move' => 2]]]);
        self::assertSame(['status' => 'unavailable'], $resolve($original), 'Moving into another site does not carry the old site grant along.');
        self::assertSame($original, $resolver->resolve($original, true, ['other'], $instance)['reference']);
        $change([], ['pages' => [$uid => ['move' => 1]]]);
        self::assertSame($original, $resolve($original)['reference']);
    }
}
