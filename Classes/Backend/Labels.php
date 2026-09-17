<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Backend;

use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Labels
{
    public const FILE = 'LLL:EXT:typo3_to_typo3/Resources/Private/Language/locallang.xlf:';

    public static function text(string $key, array $arguments = []): string
    {
        $language = $GLOBALS['LANG'] ?? GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        $label = $language->sL(self::FILE . $key);
        return $arguments ? vsprintf($label, $arguments) : $label;
    }
}
