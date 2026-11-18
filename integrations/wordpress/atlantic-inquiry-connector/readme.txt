=== Atlantic Inquiry Connector ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0

Forwards Contact Form 7, Elementor Pro and custom PHP form submissions to the Inquiry Manager.

== Description ==

* Server-to-server: the website key stays on the WordPress server (wp-config.php constant or masked option).
* Contact Form 7: hooks wpcf7_mail_sent / wpcf7_mail_failed (after CF7 mail; emails are unchanged).
* Elementor Pro Forms: hooks elementor_pro/forms/new_record (after the form's actions, including Email).
* Custom PHP forms: call aiq_forward_inquiry( $data ).
* Durable outbox table with WP-Cron retries using the same submission ID (no duplicates).
* Settings are restricted to administrators (manage_options) and protected with nonces.

== Installation ==

1. Plugins > Add New > Upload Plugin, choose atlantic-inquiry-connector.zip, Install, Activate.
2. In wp-config.php (above "That's all, stop editing"):
   define( 'AIQ_ENDPOINT', 'https://inquiries.example.com/api/v1/submissions' );
   define( 'AIQ_API_KEY', 'aiq_...' );
   (Or enter both on Settings > Inquiry Connector.)
3. Settings > Inquiry Connector: add one rule per form, map fields, Save.
4. Click "Send test inquiry" and confirm it appears in the Inquiry Manager inbox with a Test tag.
5. Submit the real form once and check the inbox and the "Recent deliveries" table.

== Choosing one method per form ==

Forward each form either with a rule in this plugin or with a functions.php snippet, never both.
The bundled snippets call aiq_connector_handles() and skip forms that already have a rule.
