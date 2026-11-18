<?php

declare(strict_types=1);

/**
 * Logical backup of the application database to storage/backups/ (gzip-compressed SQL).
 * Works on shared hosting without mysqldump; schedule it with an hPanel cron job:
 *   php /home/USER/domains/DOMAIN/inquiry-app/bin/backup.php --keep=14
 *
 * Restore: gunzip the file and import it with phpMyAdmin or `mysql DB < file.sql`.
 */
require __DIR__ . '/lib/bootstrap.php';

$opts = cli_options($argv);
$keep = max(1, (int) ($opts['keep'] ?? 14));
$config = App\Support\Config::fromEnvFile($root);
$db = App\Support\Database::connect($config->get('db'));
$pdo = $db->pdo();

$dir = $root . '/storage/backups';
$file = $dir . '/inquiries-' . gmdate('Ymd-His') . '.sql.gz';
$gz = gzopen($file, 'wb6');
if ($gz === false) {
    fwrite(STDERR, "Cannot write {$file}\n");
    exit(1);
}
gzwrite($gz, "-- Inquiry Manager backup " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET time_zone='+00:00';\n\n");
$tables = array_column($db->all('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'), 'Tables_in_' . $config->get('db.name'));
foreach ($tables as $table) {
    $create = $db->one("SHOW CREATE TABLE `{$table}`");
    gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create['Create Table'] . ";\n\n");
    $stmt = $pdo->query("SELECT * FROM `{$table}`");
    $batch = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $batch[] = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
        if (count($batch) === 200) {
            gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }
    if ($batch) {
        gzwrite($gz, "INSERT INTO `{$table}` VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    gzwrite($gz, "\n");
}
gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);
@chmod($file, 0600);

$all = glob($dir . '/inquiries-*.sql.gz') ?: [];
sort($all);
foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) {
    @unlink($old);
}
echo 'Backup written: ' . basename($file) . ' (' . number_format((int) filesize($file)) . " bytes)\n";
