<?php

declare(strict_types=1);

/**
 * Apply database migrations.
 *   php bin/migrate.php                # uses DB_* from .env
 *   php bin/migrate.php --create-db    # also CREATE DATABASE IF NOT EXISTS (local XAMPP)
 *   php bin/migrate.php --print-sql    # print the combined SQL for phpMyAdmin import (no DB access)
 */
require __DIR__ . '/lib/bootstrap.php';

$opts = cli_options($argv);
$config = App\Support\Config::fromEnvFile($root);
$cfg = $config->get('db');

if (isset($opts['print-sql'])) {
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    sort($files);
    echo "-- Combined migrations for phpMyAdmin import. Select your database first.\n";
    echo "CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
    foreach ($files as $f) {
        echo file_get_contents($f), "\n";
        echo "INSERT INTO schema_migrations (version, applied_at) VALUES ('" . basename($f, '.sql') . "', UTC_TIMESTAMP());\n\n";
    }
    exit(0);
}

try {
    if (isset($opts['create-db'])) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $cfg['name'])) {
            throw new RuntimeException('DB_NAME may only contain letters, digits and underscores.');
        }
        $server = App\Support\Database::connect($cfg, '');
        $server->pdo()->exec('CREATE DATABASE IF NOT EXISTS `' . $cfg['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        echo "Database {$cfg['name']} is present.\n";
    }
    $db = App\Support\Database::connect($cfg);
    $applied = run_migrations($db, $root, fn (string $m) => print($m . "\n"));
    echo $applied ? 'Done.' . "\n" : "Nothing to migrate; schema is up to date.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
