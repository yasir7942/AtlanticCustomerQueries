<?php

declare(strict_types=1);

$G = 'Backup: bin/backup.php dump restores to identical data';

test($G, 'backup then restore keeps every table row and Unicode intact', function () {
    $db = T::db();
    $tables = ['users', 'websites', 'website_user', 'api_credentials', 'forms', 'submissions', 'audit_log'];
    $before = [];
    foreach ($tables as $t) {
        $before[$t] = (int) $db->value("SELECT COUNT(*) FROM `{$t}`");
    }
    $arabic = $db->value("SELECT message FROM submissions WHERE locale = 'ar' AND message <> '' ORDER BY id LIMIT 1");
    $dir = T::$root . '/storage/backups';
    $existing = glob($dir . '/inquiries-*.sql.gz') ?: [];
    $proc = proc_open([PHP_BINARY, ...php_ext_args('pdo_mysql'), T::$root . '/bin/backup.php', '--keep=50'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, T::$root, array_merge(getenv(), ['DB_NAME' => $GLOBALS['TEST_DB_NAME']]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    eq(0, proc_close($proc), $out);
    $new = array_values(array_diff(glob($dir . '/inquiries-*.sql.gz') ?: [], $existing));
    eq(1, count($new), 'one new backup file');
    $sql = gzdecode((string) file_get_contents($new[0]));
    contains('CREATE TABLE `submissions`', $sql);
    // Restore over the test database.
    $db->pdo()->exec($sql);
    foreach ($tables as $t) {
        eq($before[$t], (int) $db->value("SELECT COUNT(*) FROM `{$t}`"), "rows in {$t}");
    }
    eq($arabic, $db->value("SELECT message FROM submissions WHERE locale = 'ar' AND message <> '' ORDER BY id LIMIT 1"), 'Arabic preserved');
    unlink($new[0]);
});
