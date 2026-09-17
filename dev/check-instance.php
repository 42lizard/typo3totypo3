<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

$extensionPath = Composer\InstalledVersions::getInstallPath('42lizard/typo3-to-typo3');
if (realpath($extensionPath ?? '') !== '/opt/typo3-to-typo3') {
    throw new RuntimeException('The extension must use the shared repository mount.');
}
if (getenv('TYPO3_CONTEXT') !== 'Development') {
    throw new RuntimeException('The instance must use the Development context.');
}

echo "Shared extension source and Development context: OK\n";
