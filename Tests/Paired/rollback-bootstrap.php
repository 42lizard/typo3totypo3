<?php

declare(strict_types=1);

// Select the archived extension only in this CLI process. Shared Composer files and
// the Development/web installation are never modified.
$baseline = '/var/www/html/var/exchange-testing/rollback-extension';
if (!is_file($baseline . '/composer.json')) { throw new RuntimeException('Export rollback baseline before running this test.'); }
$loader->setPsr4('Lizard\\Typo3ToTypo3\\', $baseline . '/Classes');
$artifact = require '/var/www/html/vendor/typo3/PackageArtifact.php';
$packages = unserialize($artifact['packageObjects']);
$package = $packages['typo3_to_typo3'];
(new ReflectionProperty($package, 'packagePath'))->setValue($package, $baseline . '/');
(new ReflectionProperty($package, 'isRelativePackagePath'))->setValue($package, false);
$artifact['packageObjects'] = serialize($packages);
$artifact['identifier'] = 'exchange-rollback-65bed0f';
file_put_contents($baseline . '/PackageArtifact.php', '<?php return ' . var_export($artifact, true) . ';');
$installed = Composer\InstalledVersions::getRawData();
$installed['versions']['typo3/cms-composer-installers']['install_path'] = $baseline . '/cms-composer-installers';
$installed['versions']['42lizard/typo3-to-typo3']['install_path'] = $baseline;
Composer\InstalledVersions::reload($installed);
