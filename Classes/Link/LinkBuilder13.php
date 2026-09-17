<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Typolink\ExternalUrlLinkBuilder;
use TYPO3\CMS\Frontend\Typolink\LinkResultInterface;

final class LinkBuilder13 extends ExternalUrlLinkBuilder
{
    public function build(array &$linkDetails, string $linkText, string $target, array $conf): LinkResultInterface
    {
        $linkDetails['url'] = GeneralUtility::makeInstance(LocalRenderer::class)->url($linkDetails, $this->contentObjectRenderer->getRequest(), $linkText);
        return parent::build($linkDetails, $linkText, $target, $conf);
    }
}
