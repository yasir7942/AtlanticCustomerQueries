# Plain PHP

Forward inquiries from the website's own PHP form handler with a small dependency-free client (PHP 8.0+, cURL or `allow_url_fopen`).

## 1. Where the code goes

| File | Location on the website's server |
|---|---|
| `InquiryClient.php` | a private folder **outside** the web root, e.g. `/home/USER/private/` |
| `inquiry-config.php` | same private folder (holds the key) |
| `contact-handler.php` | the web root, e.g. `public_html/contact-submit.php` (your form's `action`) |
| `retry-outbox.php` | private folder, run from cron |

## 2. Store the key

Create `/home/USER/private/inquiry-config.php` (never inside `public_html`, never in Git):

```php
<?php
return [
    'api_url'    => '{{ENDPOINT}}',
    'api_key'    => 'aiq_…paste the key from this page…',
    'outbox_dir' => __DIR__ . '/inquiry-outbox',
];
```

Environment variables `INQUIRY_API_URL` and `INQUIRY_API_KEY` work too.

## 3. The client

File: `integrations/php/InquiryClient.php`

<!-- include: integrations/php/InquiryClient.php lang=php -->

## 4. Map your form fields

Example handler: `integrations/php/contact-handler.php`. It generates one `submission_id` per form view (kept in the session), so a double click or a browser resubmission reuses the same ID and the inbox shows one inquiry. Map each form field to the contract (`full_name`, `email`, `phone`, `message`, …); put anything else in `fields`. Send phone numbers and postcodes as strings.

<!-- include: integrations/php/contact-handler.php lang=php -->

## 5. Validation failures and retries

- `201` created / `200` replayed: success. Keep sending your existing notification email if you have one.
- `422`: the data needs fixing; `fieldErrors()` lists the fields. Not retried.
- Network error, `429` or `5xx`: the client retries up to 3 times with the **same** `submission_id`, then the handler saves the payload to the outbox. Run `retry-outbox.php` from cron every 10 minutes:

```
*/10 * * * * php /home/USER/private/retry-outbox.php
```

<!-- include: integrations/php/retry-outbox.php lang=php -->

## 6. Send a test inquiry and confirm receipt

From the website's server (SSH) or your computer:

```
INQUIRY_API_URL={{ENDPOINT}} INQUIRY_API_KEY=aiq_… php send-test.php
```

It sends a test inquiry and repeats it with the same ID; both lines must show `ok`, the second with `"replayed": true`. Then:

1. Open the Inquiry Manager inbox: the inquiry appears with a **Test** tag under **{{WEBSITE_NAME}}**.
2. On this API Connect page, **Last successful submission** shows the time, and **Recent API requests** lists `201 created` then `200 duplicate`.

<!-- include: integrations/php/send-test.php lang=php -->
