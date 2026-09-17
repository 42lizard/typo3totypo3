<?php

declare(strict_types=1);

use Lizard\Typo3ToTypo3\Backend\ReportController;
use TYPO3\CMS\Core\Information\Typo3Version;

return [
    'exchange_links' => [
        'parent' => (new Typo3Version())->getMajorVersion() >= 14 ? 'content_status' : 'web',
        'access' => 'user',
        'path' => '/module/exchange/links',
        'iconIdentifier' => 'actions-link',
        'labels' => ['title' => \Lizard\Typo3ToTypo3\Backend\Labels::FILE . 'module.title', 'description' => \Lizard\Typo3ToTypo3\Backend\Labels::FILE . 'module.description'],
        'routes' => ['_default' => ['target' => ReportController::class . '::handleRequest']],
    ],
];
