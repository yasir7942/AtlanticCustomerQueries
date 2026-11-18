<?php

/**
 * Loads the plugin from the built ZIP (dist/atlantic-inquiry-connector.zip) inside the WordPress
 * stand-in and runs the documented paths. Prints one JSON object with the observations.
 *
 * php tests/wordpress/harness.php <endpoint> <api_key> <db_name> <work_dir>
 */
[$self, $endpoint, $apiKey, $dbName, $workDir] = $argv + [null, null, null, null, null];
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$cfg = App\Support\Config::fromEnvFile($root)->get('db');
$pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$dbName};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

require __DIR__ . '/wp-stubs.php';
$GLOBALS['wpdb'] = new WPDB_Stub($pdo);
global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS wptest_aiq_outbox');

// 1. Install from the ZIP exactly as WordPress would unpack it.
$zip = new ZipArchive();
if ($zip->open($root . '/dist/atlantic-inquiry-connector.zip') !== true) {
    fwrite(STDERR, "cannot open zip\n");
    exit(1);
}
$zip->extractTo($workDir);
$zip->close();
$pluginFile = $workDir . '/atlantic-inquiry-connector/atlantic-inquiry-connector.php';
$out = ['zip_top_folder' => is_file($pluginFile)];
$header = file_get_contents($pluginFile, false, null, 0, 600);
$out['plugin_header'] = (bool) preg_match('/Plugin Name:\s+Atlantic Inquiry Connector/', $header);
require $pluginFile;
foreach ($GLOBALS['wp_activation'] as $cb) {
    $cb();
}
$out['outbox_table_created'] = (bool) $wpdb->get_var("SHOW TABLES LIKE 'wptest_aiq_outbox'");
$out['cron_scheduled'] = (bool) wp_next_scheduled('aiq_process_outbox');
$out['hooks'] = array_values(array_intersect(array_keys($GLOBALS['wp_hooks']), ['wpcf7_mail_sent', 'wpcf7_mail_failed', 'elementor_pro/forms/new_record', 'elementor_pro/forms/mail_sent']));

// 2. Settings saved through the sanitize callback (what options.php does).
$save = fn (array $input) => update_option('aiq_settings', AIQ_Settings::sanitize($input));
$rules = [
    ['source' => 'cf7', 'form' => '123', 'form_type' => 'contact', 'enabled' => 1, 'map' => "your-name = full_name\nyour-email = email\nyour-message = message\nyour-subject = fields.Subject\nyour-consent = ignore"],
    ['source' => 'elementor', 'form' => 'Quote Request', 'form_type' => 'quote', 'enabled' => 1, 'map' => "name = full_name\nemail = email\ncompany = company\nproduct_1 = products[1].name\nqty_1 = products[1].quantity\nproduct_2 = products[2].name\nqty_2 = products[2].quantity"],
    ['source' => 'cf7', 'form' => '123', 'form_type' => 'quote', 'enabled' => 1, 'map' => ''],
];
$save(['endpoint' => $endpoint, 'api_key' => $apiKey, 'timeout' => 5, 'send_visitor_ip' => 1, 'forward_unmapped' => 1, 'rules' => $rules]);
$out['duplicate_rule_rejected'] = in_array('aiq_dupe', $GLOBALS['wp_settings_errors'], true) && count(AIQ_Settings::get('rules')) === 2;
$save(['endpoint' => $endpoint, 'api_key' => '', 'timeout' => 5, 'send_visitor_ip' => 1, 'forward_unmapped' => 1, 'rules' => $rules]);
$out['blank_key_keeps_stored_key'] = AIQ_Settings::api_key() === $apiKey;
$save(['endpoint' => $endpoint, 'api_key' => 'not-a-key', 'timeout' => 5, 'send_visitor_ip' => 1, 'forward_unmapped' => 1, 'rules' => $rules]);
$out['invalid_key_rejected'] = AIQ_Settings::api_key() === $apiKey && in_array('aiq_key', $GLOBALS['wp_settings_errors'], true);
$out['masked_key'] = AIQ_Settings::masked_key();
$out['masked_key_hides_secret'] = !str_contains($out['masked_key'], substr($apiKey, 22, 30));
$save(['endpoint' => 'http://insecure.example.com/api', 'api_key' => '', 'timeout' => 5, 'send_visitor_ip' => 1, 'forward_unmapped' => 1, 'rules' => $rules]);
$out['http_endpoint_rejected'] = AIQ_Settings::endpoint() === $endpoint;

// 3. Contact Form 7: the real hook names with CF7-shaped objects.
final class WPCF7_Submission
{
    public static ?self $instance = null;

    public function __construct(public array $data, public array $meta)
    {
    }

    public static function get_instance()
    {
        return self::$instance;
    }

    public function get_posted_data($name = '')
    {
        return $name === '' ? $this->data : ($this->data[$name] ?? null);
    }

    public function get_meta($name)
    {
        return $this->meta[$name] ?? null;
    }
}
final class FakeCF7Form
{
    public function __construct(private int $id, private string $title)
    {
    }

    public function id()
    {
        return $this->id;
    }

    public function title()
    {
        return $this->title;
    }

    public function hash()
    {
        return substr(md5((string) $this->id), 0, 7);
    }

    public function in_demo_mode()
    {
        return false;
    }
}
$lastLog = fn () => get_option('aiq_delivery_log', [])[0] ?? null;
$cf7 = function (int $formId, array $data, bool $mailOk = true) {
    WPCF7_Submission::$instance = new WPCF7_Submission($data, ['url' => 'https://wp.example.test/contact/', 'remote_ip' => '203.0.113.50']);
    do_action($mailOk ? 'wpcf7_mail_sent' : 'wpcf7_mail_failed', new FakeCF7Form($formId, 'Contact form ' . $formId));
};
$cf7(123, [
    'your-name' => 'CF7 Visitor',
    'your-email' => 'cf7@example.test',
    'your-message' => "مرحبا\nI need a callback.",
    'your-subject' => 'Callback',
    'your-consent' => '1',
    'preferred-time' => ['Morning', 'Evening'],
    '_wpcf7_unit_tag' => 'wpcf7-f123-p1-o1',
    'g-recaptcha-response' => 'token',
], false);
$log = $lastLog();
$out['cf7'] = ['ok' => $log['ok'] ?? false, 'inquiry_id' => $log['inquiry_id'] ?? null, 'status' => $log['status'] ?? null];
$out['cf7_outbox_empty_after_success'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM wptest_aiq_outbox') === 0;
$sentHeaders = end($GLOBALS['wp_http_log'])['headers'];
$out['cf7_sent_idempotency_header'] = str_starts_with((string) ($sentHeaders['Idempotency-Key'] ?? ''), 'wp-');

$before = count(get_option('aiq_delivery_log', []));
$cf7(999, ['your-name' => 'Not forwarded']);
$out['unconfigured_form_ignored'] = count(get_option('aiq_delivery_log', [])) === $before;

// 4. Elementor Pro: mail_sent then new_record (the order Elementor runs them).
final class FakeElementorRecord
{
    public function __construct(private array $settings, private array $fields)
    {
    }

    public function get_form_settings($key)
    {
        return $this->settings[$key] ?? null;
    }

    public function get($key)
    {
        return $key === 'fields' ? $this->fields : ($key === 'meta' ? ['page_url' => ['value' => 'https://wp.example.test/quote/']] : null);
    }
}
$record = new FakeElementorRecord(['form_name' => 'Quote Request', 'id' => 'a1b2c3'], [
    'name' => ['id' => 'name', 'title' => 'Name', 'value' => 'Elementor Buyer', 'raw_value' => 'Elementor Buyer'],
    'email' => ['id' => 'email', 'title' => 'Email', 'value' => 'buyer@example.test'],
    'company' => ['id' => 'company', 'title' => 'Company', 'value' => 'Buyer Ltd'],
    'product_1' => ['id' => 'product_1', 'title' => 'Product 1', 'value' => 'Gate valve DN80'],
    'qty_1' => ['id' => 'qty_1', 'title' => 'Qty 1', 'value' => '25'],
    'product_2' => ['id' => 'product_2', 'title' => 'Product 2', 'value' => 'Flange kit'],
    'qty_2' => ['id' => 'qty_2', 'title' => 'Qty 2', 'value' => '1,5'],
    'field_9f2' => ['id' => 'field_9f2', 'title' => 'Delivery city', 'value' => 'Sharjah'],
]);
do_action('elementor_pro/forms/mail_sent', [], $record);
do_action('elementor_pro/forms/new_record', $record, null);
$log = $lastLog();
$out['elementor'] = ['ok' => $log['ok'] ?? false, 'inquiry_id' => $log['inquiry_id'] ?? null];

// 5. Custom PHP form helper.
$r = aiq_forward_inquiry([
    'form_type' => 'sample',
    'form_id' => 'theme-sample-form',
    'full_name' => 'Helper Person',
    'fields' => ['swatches' => ['label' => 'Swatches', 'value' => ['Oak', 'Walnut']]],
]);
$out['helper'] = ['ok' => $r['ok'], 'inquiry_id' => $r['inquiry_id'] ?? null];

// 7. Outbox: API unreachable -> queued; later cron run delivers with the same submission_id.
$settings = AIQ_Settings::all();
$settings['endpoint'] = 'http://127.0.0.1:9/api/v1/submissions';
update_option('aiq_settings', $settings);
$r = AIQ_Outbox::submit(['submission_id' => 'wp-outbox-test-1', 'form_type' => 'contact', 'full_name' => 'Queued Person'], 'custom', 'outbox test');
$out['offline_queued'] = $r['queued'] ?? null;
$pending = $wpdb->get_row("SELECT * FROM wptest_aiq_outbox WHERE submission_id = 'wp-outbox-test-1'", ARRAY_A);
$out['offline_row_pending'] = ($pending['status'] ?? '') === 'pending' || ($r['ok'] ?? false);
$wpdb->query("UPDATE wptest_aiq_outbox SET next_attempt_at = '2000-01-01 00:00:00'");
$settings['endpoint'] = $endpoint;
update_option('aiq_settings', $settings);
$out['cron_processed'] = AIQ_Outbox::process_due();
$out['outbox_empty_after_cron'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM wptest_aiq_outbox WHERE submission_id = 'wp-outbox-test-1'") === 0;

// 8. Permanent failure (422) is kept as failed and not retried.
$r = aiq_forward_inquiry(['form_type' => 'complaint', 'full_name' => 'Bad Type']);
$out['invalid_status'] = $r['status'];
$out['invalid_marked_failed'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM wptest_aiq_outbox WHERE status = 'failed'") === 1;
$out['failed_rows'] = count(AIQ_Outbox::failed_rows());

// 6. functions.php snippets loaded too: forms the plugin handles must not be forwarded twice.
define('AIQ_ENDPOINT', $endpoint);
define('AIQ_API_KEY', $apiKey);
require $root . '/integrations/wordpress/functions-snippets.php';
$httpBefore = count($GLOBALS['wp_http_log']);
$cf7(123, ['your-name' => 'Guard Test', 'your-email' => 'guard@example.test', 'your-message' => 'once']);
$out['double_forward_guard_requests'] = count($GLOBALS['wp_http_log']) - $httpBefore;
// Disable the plugin rule: now the snippet (and only the snippet) forwards the form.
$settings = AIQ_Settings::all();
$settings['rules'][0]['enabled'] = 0;
update_option('aiq_settings', $settings);
$httpBefore = count($GLOBALS['wp_http_log']);
$cf7(123, ['your-name' => 'Snippet Path', 'your-email' => 'snippet@example.test', 'your-message' => 'via functions.php']);
$out['snippet_requests'] = count($GLOBALS['wp_http_log']) - $httpBefore;
$out['snippet_status'] = end($GLOBALS['wp_http_log'])['status'];
$settings['rules'][0]['enabled'] = 1;
update_option('aiq_settings', $settings);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
