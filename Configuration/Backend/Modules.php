<?php

declare(strict_types=1);

use Lizard\Typo3ToTypo3\Backend\ReportController;
use TYPO3\CMS\Core\Information\Typo3Version;

return [
    'exchange_connections' => [
        'parent' => 'system',
        'access' => 'admin',
        'path' => '/module/exchange/connections',
        'iconIdentifier' => 'actions-link',
        'labels' => ['title' => \Lizard\Typo3ToTypo3\Backend\Labels::FILE . 'connections.title'],
        'routes' => ['_default' => ['target' => \Lizard\Typo3ToTypo3\Backend\ConnectionsController::class . '::handleRequest']],
    ],
    'exchange_links' => [
        'parent' => (new Typo3Version())->getMajorVersion() >= 14 ? 'content' : 'web',
        'access' => 'user',
        'path' => '/module/exchange/links',
        'iconIdentifier' => 'actions-link',
        'labels' => ['title' => \Lizard\Typo3ToTypo3\Backend\Labels::FILE . 'module.title', 'description' => \Lizard\Typo3ToTypo3\Backend\Labels::FILE . 'module.description'],
        'routes' => ['_default' => ['target' => ReportController::class . '::handleRequest']],
    ],
];
