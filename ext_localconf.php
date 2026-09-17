<?php

declare(strict_types=1);

use Lizard\Typo3ToTypo3\Link;
use TYPO3\CMS\Frontend\Typolink\TypolinkBuilderInterface;

$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']['exchange'] = Link\ManagedLink::class;
$GLOBALS['TYPO3_CONF_VARS']['FE']['typolinkBuilder']['exchange'] = interface_exists(TypolinkBuilderInterface::class)
    ? Link\LinkBuilder14::class : Link\LinkBuilder13::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = Link\LinkFieldHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'][\Lizard\Typo3ToTypo3\Backend\EditWarnings::class] = [
    'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck::class],
];
