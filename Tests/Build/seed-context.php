<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;

if (getenv('TYPO3_CONTEXT') !== 'Testing' || getenv('TYPO3_PATH_APP') !== '/var/www/html/var/exchange-testing') {
    throw new RuntimeException('Only the isolated Testing context may be seeded.');
}
$loader = require '/var/www/html/vendor/autoload.php';
SystemEnvironmentBuilder::run(1, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($loader);
$db = $container->get(ConnectionPool::class)->getConnectionForTable('pages');
if ($db->getDatabase() !== 'db_testing') {
    throw new RuntimeException('Refusing to seed a database other than db_testing.');
}
if (!$db->count('*', 'pages', ['uid' => 1])) {
    $db->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Testing root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
}
