<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Link;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Typolink\ExternalUrlLinkBuilder;
use TYPO3\CMS\Frontend\Typolink\LinkResultInterface;

final class LinkBuilder14 extends ExternalUrlLinkBuilder
{
    public function buildLink(array $linkDetails, array $configuration, ServerRequestInterface $request, string $linkText = ''): LinkResultInterface
    {
        $linkDetails['url'] = GeneralUtility::makeInstance(LocalRenderer::class)->url($linkDetails, $request, $linkText);
        return parent::buildLink($linkDetails, $configuration, $request, $linkText);
    }
}
