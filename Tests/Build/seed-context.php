<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;

$testingDatabase = match (getenv('TYPO3_CONTEXT') . ':' . getenv('TYPO3_PATH_APP')) {
    'Testing:/var/www/html/var/exchange-testing' => 'db_testing',
    'Testing/PeerC:/var/www/html/var/exchange-testing-c' => 'db_testing_c',
    default => null,
};
if ($testingDatabase === null) {
    throw new RuntimeException('Only the isolated Testing context may be seeded.');
}
$loader = require '/var/www/html/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$db = $container->get(ConnectionPool::class)->getConnectionForTable('pages');
if ($db->getDatabase() !== $testingDatabase) {
    throw new RuntimeException('Refusing to seed a database outside the selected Testing context.');
}
if (!$db->count('*', 'pages', ['uid' => 1])) {
    $db->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Testing root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
}
