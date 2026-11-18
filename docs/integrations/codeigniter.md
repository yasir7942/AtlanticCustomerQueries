# CodeIgniter 4

## 1. Where the code goes

| File | Location in the CodeIgniter 4 project |
|---|---|
| `app/Config/Inquiry.php` | same path |
| `app/Libraries/InquiryForwarder.php` | same path |
| `app/Controllers/Inquiry.php` | same path (or merge into your controller) |
| `app/Commands/InquiryRetry.php` | same path (`php spark inquiry:retry`) |

Routes (`app/Config/Routes.php`):

```
$routes->get('contact', 'Inquiry::new');
$routes->post('contact', 'Inquiry::create');
```

## 2. Store the key

In the server's `.env` (outside `public/`, not committed):

```
inquiry.apiUrl = {{ENDPOINT}}
inquiry.apiKey = aiq_…
```

<!-- include: integrations/codeigniter/app/Config/Inquiry.php lang=php -->

## 3. Library

Uses CodeIgniter's CURLRequest. Each call retries network errors, `429` and `5xx` up to 3 times with the same `submission_id`.

<!-- include: integrations/codeigniter/app/Libraries/InquiryForwarder.php lang=php -->

## 4. Map form fields

The form view stores a token in the session when it is shown, so a double submit reuses the same `submission_id`. Keep CodeIgniter's CSRF filter enabled for the form.

<!-- include: integrations/codeigniter/app/Controllers/Inquiry.php lang=php -->

## 5. Validation failures and retries

- `422`: redirect back with the API's field messages; nothing was stored, so the corrected form gets a new token.
- Still failing after retries: the payload is written to `writable/inquiry-outbox/` and `php spark inquiry:retry` delivers it later (cron every 10 minutes).

<!-- include: integrations/codeigniter/app/Commands/InquiryRetry.php lang=php -->

## 6. Send a test inquiry and confirm receipt

```
php spark shell   # or a temporary route
(new \App\Libraries\InquiryForwarder())->send(['submission_id' => \App\Libraries\InquiryForwarder::newSubmissionId(), 'form_type' => 'contact', 'full_name' => 'CI4 test', 'test' => true]);
```

Check the inbox for a **Test** inquiry from **{{WEBSITE_NAME}}** and this page's **Recent API requests**.
