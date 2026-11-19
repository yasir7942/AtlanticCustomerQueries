# WordPress

Two integration methods. Choose **one per form**:

| Method | Best for |
|---|---|
| **Plugin** (download above) | most sites: settings screen, field mapping, durable retry queue, test button |
| **Snippets** in a child theme's `functions.php` | sites where installing plugins is restricted, or one-off custom code |

Both forward from the WordPress server. The key lives in `wp-config.php` and never appears in pages or JavaScript. Existing form emails keep working: Contact Form 7 is forwarded after its mail step (`wpcf7_mail_sent` / `wpcf7_mail_failed`), and Elementor Pro after its actions (`elementor_pro/forms/new_record`).

## Plugin

### 1. Install

1. Download the ZIP above (`atlantic-inquiry-connector.zip`).
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the ZIP → **Install Now** → **Activate**.

### 2. Store the key

Add to `wp-config.php` above the line `/* That's all, stop editing! */`:

```
define( 'AIQ_ENDPOINT', '{{ENDPOINT}}' );
define( 'AIQ_API_KEY', 'aiq_…' );
```

Alternatively enter both under **Settings → Inquiry Connector**: the key is stored in the options table and shown only masked (`aiq_ab12cd34…wxyz`); leaving the field blank keeps the stored key. Only administrators (`manage_options`) can see the page, and every action is nonce-protected.

### 3. Target a form and map its fields

**Settings → Inquiry Connector → Forms to forward** — one row per form:

- **Contact Form 7**: Form = the form ID from the shortcode (`[contact-form-7 id="123" …]` → `123`), the hash ID, or the exact title. Field names are the names in the form template, e.g. `[text* your-name]` → `your-name`.
- **Elementor Pro**: Form = the widget's **Form Name** (Content → Form Fields → Form Name). Field names are each field's **ID** (Advanced tab).

Field map, one line per field:

```
your-name = full_name
your-email = email
your-phone = phone
your-company = company
your-message = message
your-subject = fields.Subject
product-1 = products[1].name
qty-1 = products[1].quantity
your-consent = ignore
```

Targets: `full_name company email phone country address city postcode message`, `products[N].name|sku|variant|quantity|unit|notes`, `fields.Your label`, `ignore`. Unmapped fields are sent as custom answers labelled with the field name or Elementor field title (switch off under Options). Mapping two fields to `full_name` joins them (first + last name). Only one rule per form is allowed.

### 4. Background delivery and retries

The visitor never waits for the Inquiry Manager: during the form submission the plugin only saves the inquiry to a local outbox table. The form's own email and thank-you message work exactly as before. The inquiry is sent after the visitor has received the response (PHP-FPM or LiteSpeed, as on Hostinger), or by WP-Cron within 5 minutes where the response cannot be finished early. Whether delivery succeeds or fails is never shown to the visitor. `429`, `5xx` and network errors are retried by WP-Cron every 5 minutes with the same `submission_id` (up to 10 attempts). `4xx` results are marked **failed** and listed on the settings page with a Retry button; failed rows are removed after 14 days. WP-Cron runs on page visits; on low-traffic sites add a real cron in hPanel: `wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`.

### 5. Test and confirm receipt

1. **Settings → Inquiry Connector → Send test inquiry** → "Test inquiry delivered (inquiry #…)".
2. Submit the real form once on the site.
3. Check the plugin's **Recent deliveries** table, the inbox (**{{WEBSITE_NAME}}**), and this page's **Recent API requests**.

### Custom PHP forms

From a theme or plugin form handler:

<!-- include: integrations/wordpress/atlantic-inquiry-connector/includes/functions.php lang=php -->

## Snippets for a child theme's functions.php (no plugin)

Use these for a form only when the plugin does **not** have a rule for it. They call `aiq_connector_handles()` and skip forms the plugin already forwards, so a site that has both cannot double-forward. Edit the form IDs/names and maps marked `EDIT`.

<!-- include: integrations/wordpress/functions-snippets.php lang=php -->

## Avoiding double forwarding

- One method per form: either a plugin rule, or a snippet entry, or a custom `aiq_forward_inquiry()` call.
- Do not also add a CF7/Elementor "webhook" action pointing at the Inquiry Manager — webhooks would need the key in a less protected place, and would send a second copy.
- If two copies arrive anyway they have different `submission_id`s; disable one method and archive the duplicate.
