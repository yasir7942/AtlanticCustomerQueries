<?php

/**
 * Minimal WordPress API stand-ins, enough to load the plugin outside WordPress and fire the
 * real CF7 / Elementor hook names. $wpdb is backed by MariaDB (the test database), HTTP is real.
 * This validates plugin logic and delivery; it is not a substitute for a real WordPress install.
 */

define('ABSPATH', __DIR__ . '/fake-wp/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['wp_hooks'] = [];
$GLOBALS['wp_options'] = [];
$GLOBALS['wp_cron'] = [];
$GLOBALS['wp_settings_errors'] = [];
$GLOBALS['wp_activation'] = [];

class WP_Error
{
    public function __construct(private string $code = '', private string $message = '')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }
}

final class WPDB_Stub
{
    public string $prefix = 'wptest_';
    public int $insert_id = 0;

    public function __construct(private PDO $pdo)
    {
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return (string) preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? null;
            return match ($m[0]) {
                '%d' => (string) (int) $v,
                '%f' => (string) (float) $v,
                default => $this->pdo->quote((string) $v),
            };
        }, $query);
    }

    public function query(string $sql)
    {
        return $this->pdo->exec($sql);
    }

    public function insert(string $table, array $data, $format = null)
    {
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", array_keys($data)));
        $vals = implode(', ', array_fill(0, count($data), '?'));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$vals})");
            $stmt->execute(array_values($data));
        } catch (PDOException $e) {
            $this->insert_id = 0;
            return false;
        }
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }

    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null)
    {
        $set = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(fn ($c) => "`{$c}` = ?", array_keys($where)));
        $stmt = $this->pdo->prepare("UPDATE `{$table}` SET {$set} WHERE {$cond}");
        $stmt->execute(array_merge(array_values($data), array_values($where)));
        return $stmt->rowCount();
    }

    public function delete(string $table, array $where, $format = null)
    {
        $cond = implode(' AND ', array_map(fn ($c) => "`{$c}` = ?", array_keys($where)));
        $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE {$cond}");
        $stmt->execute(array_values($where));
        return $stmt->rowCount();
    }

    public function get_row(string $sql, $output = null)
    {
        $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public function get_results(string $sql, $output = null)
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_col(string $sql)
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function get_var(string $sql)
    {
        $v = $this->pdo->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}

function add_action($hook, $cb, $priority = 10, $args = 1)
{
    $GLOBALS['wp_hooks'][$hook][] = [$cb, $priority, $args];
    return true;
}
function add_filter($hook, $cb, $priority = 10, $args = 1)
{
    return add_action($hook, $cb, $priority, $args);
}
function do_action($hook, ...$args)
{
    $hooks = $GLOBALS['wp_hooks'][$hook] ?? [];
    usort($hooks, fn ($a, $b) => $a[1] <=> $b[1]);
    foreach ($hooks as [$cb, , $n]) {
        $cb(...array_slice($args, 0, $n));
    }
}
function apply_filters($hook, $value, ...$args)
{
    $hooks = $GLOBALS['wp_hooks'][$hook] ?? [];
    usort($hooks, fn ($a, $b) => $a[1] <=> $b[1]);
    foreach ($hooks as [$cb, , $n]) {
        $value = $cb(...array_slice(array_merge([$value], $args), 0, $n));
    }
    return $value;
}
function register_activation_hook($file, $cb)
{
    $GLOBALS['wp_activation'][] = $cb;
}
function register_deactivation_hook($file, $cb)
{
}
function plugin_dir_path($file)
{
    return rtrim(dirname($file), '/\\') . '/';
}
function plugin_basename($file)
{
    return basename(dirname($file)) . '/' . basename($file);
}
function is_admin()
{
    return false;
}
function get_option($name, $default = false)
{
    return $GLOBALS['wp_options'][$name] ?? $default;
}
function update_option($name, $value, $autoload = null)
{
    $GLOBALS['wp_options'][$name] = $value;
    return true;
}
function delete_option($name)
{
    unset($GLOBALS['wp_options'][$name]);
    return true;
}
function set_transient($k, $v, $ttl = 0)
{
    return update_option('_transient_' . $k, $v);
}
function get_transient($k)
{
    return get_option('_transient_' . $k);
}
function delete_transient($k)
{
    return delete_option('_transient_' . $k);
}
function wp_next_scheduled($hook)
{
    return $GLOBALS['wp_cron'][$hook][0] ?? false;
}
function wp_schedule_event($ts, $recurrence, $hook)
{
    $GLOBALS['wp_cron'][$hook] = [$ts, $recurrence];
    return true;
}
function wp_schedule_single_event($ts, $hook, $args = [])
{
    $GLOBALS['wp_cron']['single'][] = [$ts, $hook, $args];
    return true;
}
function wp_clear_scheduled_hook($hook)
{
    unset($GLOBALS['wp_cron'][$hook]);
}
function current_time($type, $gmt = false)
{
    return gmdate('Y-m-d H:i:s');
}
function wp_json_encode($data, $flags = 0, $depth = 512)
{
    return json_encode($data, $flags, $depth);
}
function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}
function wp_remote_post($url, $args = [])
{
    $headers = [];
    foreach ($args['headers'] ?? [] as $k => $v) {
        $headers[] = $k . ': ' . $v;
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $args['body'] ?? '', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int) ($args['timeout'] ?? 5), CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return new WP_Error('http_request_failed', $err);
    }
    $GLOBALS['wp_http_log'][] = ['url' => $url, 'status' => $code, 'headers' => $args['headers'] ?? []];
    return ['response' => ['code' => $code], 'body' => $body, 'headers' => $respHeaders];
}
function wp_remote_retrieve_response_code($r)
{
    return $r['response']['code'] ?? '';
}
function wp_remote_retrieve_body($r)
{
    return $r['body'] ?? '';
}
function wp_remote_retrieve_header($r, $h)
{
    return $r['headers'][strtolower($h)] ?? '';
}
function home_url($path = '')
{
    return 'https://wp.example.test' . $path;
}
function admin_url($path = '')
{
    return 'https://wp.example.test/wp-admin/' . $path;
}
function wp_generate_uuid4()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
function get_locale()
{
    return 'en_GB';
}
function determine_locale()
{
    return get_locale();
}
function is_email($e)
{
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : false;
}
function wp_http_validate_url($u)
{
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : false;
}
function wp_get_referer()
{
    return false;
}
function sanitize_text_field($s)
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $s)));
}
function sanitize_textarea_field($s)
{
    return trim(strip_tags((string) $s));
}
function sanitize_title($s)
{
    return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s), '-'));
}
function sanitize_key($s)
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s));
}
function wp_unslash($v)
{
    return $v;
}
function esc_url_raw($u, $protocols = null)
{
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}
function absint($v)
{
    return abs((int) $v);
}
function add_settings_error($setting, $code, $message, $type = 'error')
{
    $GLOBALS['wp_settings_errors'][] = $code;
}
function get_current_user_id()
{
    return 1;
}
