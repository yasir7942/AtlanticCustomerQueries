<?php

declare(strict_types=1);

/**
 * Apply pending SQL migrations from database/migrations in filename order.
 *
 * @return list<string> applied versions
 */
function run_migrations(App\Support\Database $db, string $root, ?callable $out = null): array
{
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = array_column($db->all('SELECT version FROM schema_migrations'), 'version');
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    sort($files);
    $applied = [];
    foreach ($files as $file) {
        $version = basename($file, '.sql');
        if (in_array($version, $done, true)) {
            continue;
        }
        $sql = (string) file_get_contents($file);
        // Split on semicolons that end a line; strip -- comments.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $db->pdo()->exec($statement);
            }
        }
        $db->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, utc_now()]);
        $applied[] = $version;
        if ($out) {
            $out("Applied {$version}");
        }
    }
    return $applied;
}
