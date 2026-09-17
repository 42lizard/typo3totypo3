<?php

// Only loaded by additional.php in Testing context.
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
    'driver' => 'mysqli',
    'host' => 'db',
    'port' => 3306,
    'dbname' => 'db_testing',
    'user' => 'db',
    'password' => 'db',
];
