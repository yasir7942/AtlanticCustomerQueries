<?php

declare(strict_types=1);

/**
 * Test runner.
 *   php -d extension=pdo_mysql -d extension=zip tests/run.php [--filter=text] [--skip-http]
 *
 * Uses DB_TEST_NAME from .env (default atlantic_inquiries_test). That database is DROPPED and
 * recreated on every run; never point it at real data.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib.php';
require $root . '/bin/lib/plugin-builder.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql missing. Run: php -d extension=pdo_mysql tests/run.php\n");
    exit(1);
}

$base = App\Support\Config::fromEnvFile($root);
$testDb = (string) ($base->get('db.test_name') ?: 'atlantic_inquiries_test');
if (!preg_match('/_test$/', $testDb)) {
    fwrite(STDERR, "Refusing to run: DB_TEST_NAME must end in _test (got {$testDb}).\n");
    exit(1);
}
$dbCfg = $base->get('db');
$server = App\Support\Database::connect($dbCfg, '');
$server->pdo()->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$server->pdo()->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

require $root . '/bin/lib/migrations.php';
$config = App\Support\Config::fromEnvFile($root, null, [
    'DB_NAME' => $testDb,
    'APP_URL' => 'http://inquiries.test',
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'API_REQUIRE_HTTPS' => 'true',
    'API_MAX_BODY_BYTES' => '65536',
]);
$db = App\Support\Database::connect($config->get('db'));
run_migrations($db, $root);

// Any PHP notice or warning (e.g. an undefined index in a template) fails the test that caused it.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

T::$root = $root;
T::$app = new App\App($config, $db);
$GLOBALS['TEST_DB_NAME'] = $testDb;
$GLOBALS['TEST_OPTS'] = $opts;

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$filter = isset($opts['filter']) ? (string) $opts['filter'] : null;
$passed = $failed = 0;
$failures = [];
$group = null;
$start = microtime(true);
foreach (T::$tests as [$g, $name, $fn]) {
    if ($filter !== null && stripos($g . ' ' . $name, $filter) === false) {
        continue;
    }
    if ($g !== $group) {
        echo "\n{$g}\n";
        $group = $g;
    }
    try {
        $fn();
        $passed++;
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        $where = $e instanceof TestFailure ? '' : ' [' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        $failures[] = "{$g} › {$name}: {$e->getMessage()}{$where}";
        echo "  FAIL  {$name}\n        {$e->getMessage()}{$where}\n";
    }
}
printf("\n%d passed, %d failed (%.1fs)\n", $passed, $failed, microtime(true) - $start);
if ($failures) {
    echo "\nFailures:\n - " . implode("\n - ", $failures) . "\n";
}
exit($failed > 0 ? 1 : 0);
