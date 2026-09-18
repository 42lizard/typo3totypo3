<?php

declare(strict_types=1);

return [
    'frontend' => [
        '42lizard/typo3-to-typo3/exchange' => [
            'target' => \Lizard\Typo3ToTypo3\Middleware\Exchange::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
        '42lizard/typo3-to-typo3/resolve' => [
            'target' => \Lizard\Typo3ToTypo3\Middleware\Resolve::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
