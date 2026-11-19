<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Builds a ready-to-paste prompt for an AI coding agent (e.g. Claude Code) that is run inside a
 * website's own codebase and wires its inquiry forms to the Intake API.
 *
 * The prompt contains everything the agent needs (endpoint, contract, limits, retry rules,
 * platform guidance) but never the API key itself: the key is put into the website's server
 * environment by a person, so it is not sent to an AI service or written into a repository.
 */
final class IntegrationPrompt
{
    /**
     * @param array{name:string,domain:string,rate_limit_per_minute:int|string} $site
     * @param ?string $keyHint masked key of the newest active key, e.g. "aiq_ab12cd34ef56gh78_…wxyz"
     */
    public static function build(
        array $site,
        string $platform,
        string $endpoint,
        string $connectUrl,
        int $maxBodyBytes,
        ?string $keyHint,
    ): string {
        $platformLabel = WebsiteService::PLATFORMS[$platform] ?? $platform;
        $name = $site['name'];
        $domain = $site['domain'];
        $rate = (int) $site['rate_limit_per_minute'];
        $kb = (int) floor($maxBodyBytes / 1024);
        $keyLine = $keyHint !== null
            ? "The key for this website looks like `{$keyHint}` (masked here; the full value is only in the server environment)."
            : 'No key has been generated yet; I will create one on the Connect page and put it into the server environment.';
        $platformNotes = self::platformNotes($platform, $endpoint, $connectUrl);

        return <<<PROMPT
        # Task: connect this website's inquiry forms to the Atlantic Inquiry Manager

        You are working in the codebase of the website **{$name}** (`{$domain}`), built with **{$platformLabel}**.
        Every customer inquiry submitted on this site (contact, quote, sample request) must also be delivered,
        **server-side**, to our central Inquiry Manager through its HTTP API. The site's current behaviour
        (emails, database records, thank-you pages, redirects) must keep working exactly as it does now.

        ## The most important rule: send in the background, never in the customer's request

        When the customer clicks **Submit**, the website does its normal work exactly as today (validate,
        send its email, save to its own database) and answers the customer **immediately**. Sending a copy
        to the Inquiry Manager happens **separately, in the background** (queue, job, after-response hook,
        cron), in parallel with or after that normal work:

        - The customer must **never wait** for the Inquiry Manager: no API call before the response is sent.
        - The customer must **never see** whether the Inquiry Manager call succeeded or failed: the page,
          message, redirect and HTTP status are the same in both cases. No error message, no delay.
        - A slow, failing or unreachable Inquiry Manager must not change anything for the customer or for the
          site's own email and database. The inquiry is kept and delivered later by the retry job.
        - The only thing done during the customer's request is saving the payload to a local outbox (one fast
          local insert) and handing it to the background mechanism.

        ## How to work

        1. **Explore first.** Find every form that collects customer inquiries: contact forms, quote/RFQ/price
           requests, cart or product enquiries, sample requests. Ignore newsletter sign-ups, login/registration,
           checkout, search, comments and job applications.
        2. **Show me a plan** as a table before changing code: form, file(s), current handler, proposed `form_type`,
           `form_id`, and the field-to-API mapping. Then implement it.
        3. **Ask me only** if a form's purpose is unclear, before adding a new third-party dependency, or before
           running a database migration against a non-local database.
        4. Run the project's existing linters, type checks, build and tests, and add tests for the new code
           (with the HTTP call mocked; never call the real API from automated tests).
        5. Finish with the report described under **Deliverables**.

        ## Inquiry Manager API

        - Endpoint: `POST {$endpoint}`
        - Headers: `Authorization: Bearer <key>`, `Content-Type: application/json`, `Accept: application/json`
        - Optional header: `Idempotency-Key: <same value as submission_id>`
        - Server-to-server only, HTTPS only. Keep TLS certificate verification on.
        - Limits: body at most {$maxBodyBytes} bytes (~{$kb} KB); {$rate} requests per minute for this website.
        - The key decides which website an inquiry belongs to; never send a website ID.

        ### Configuration (environment, never in code or git)

        - `INQUIRY_API_URL={$endpoint}`
        - `INQUIRY_API_KEY=` the per-website key. {$keyLine}
        - Add both names (with an empty key) to `.env.example` or the project's equivalent, and read them only
          in server-side code. Do **not** ask me to paste the key into this chat, and never print, log or commit it.

        ### JSON body

        | Field | Rules |
        |---|---|
        | `submission_id` | **Required.** 1-128 chars from `A-Z a-z 0-9 . _ : -`. Generate once per submission, e.g. `<form_id>-<uuid v4>`, and reuse it unchanged on every retry. |
        | `form_type` | **Required.** One of `contact`, `quote`, `sample`, `other`. |
        | `form_id` | Stable slug per form, max 100 chars, e.g. `contact-page`, `product-quote`. |
        | `form_name` | Human-readable form name, max 150 chars. |
        | `locale` | Language of the page, e.g. `en`, `ar`, `en-GB` (default `en`). |
        | `full_name`, `company`, `email`, `phone`, `country`, `address`, `city`, `postcode` | Optional strings; max lengths 190, 190, 190, 50, 100, 500, 120, 32. `email` must be valid. Send `phone` and `postcode` as **strings**, never numbers. Join first + last name into `full_name`. |
        | `message` | Free text, max 20,000 chars, newlines kept. |
        | `products` | For quote/sample forms: array (max 200) of `{ "name", "sku", "variant", "quantity", "unit", "notes" }`; each item needs `name` or `sku`; `quantity` is a number 0-1,000,000,000. |
        | `fields` | Every other answer (subject, budget, delivery date, consent, dropdowns...): object of max 100 keys. Value: string (max 5,000), number, boolean, null, list of max 50 scalars, or `{ "label": "Label shown to staff", "value": ... }`. Prefer the labelled form. |
        | `source` | `{ "page_url": "<page the form was on>", "ip": "<visitor IP>", "email": { "sent": true, "sent_at": "<ISO 8601>", "attempts": 1, "error": null } }`. Include `email` only if the site sends its own notification and knows the result. |
        | `test` | `true` only for test submissions. |

        - Unknown top-level keys are rejected (422), so anything without a dedicated field goes into `fields`.
        - Omit empty values instead of sending empty strings. At least one of name, company, email, phone,
          message, products or fields must be present.
        - Do **not** send `submitted_at` (a fresh timestamp on a retry would make the payload differ, see 409).
        - File uploads are not supported: never send file contents. Put the file names in `fields` if useful.
        - Only forward submissions that passed the site's own validation and spam protection (captcha, honeypot).

        Example:

        ```json
        {
          "submission_id": "product-quote-0b7e5c1e-6f0a-4b8e-9a51-3d2f1c7a9e10",
          "form_type": "quote",
          "form_id": "product-quote",
          "form_name": "Product quote",
          "locale": "en",
          "full_name": "Sara Ahmed",
          "company": "Example Trading LLC",
          "email": "sara@example.com",
          "phone": "+971 50 000 0000",
          "country": "United Arab Emirates",
          "message": "Please quote delivery to Jebel Ali.",
          "products": [ { "name": "Ball valve DN50", "sku": "BV-50", "quantity": 120, "unit": "pcs" } ],
          "fields": { "deadline": { "label": "Needed by", "value": "2026-12-01" } },
          "source": { "page_url": "https://{$domain}/quote", "ip": "203.0.113.10" }
        }
        ```

        ### Responses and what to do

        | Status | Body `error.code` | Action |
        |---|---|---|
        | 201 | (`"status": "created"`, `inquiry_id`) | Done. |
        | 200 + header `Idempotent-Replayed: true` | (`"status": "duplicate"`) | Done; it was already stored. |
        | 400 | `invalid_json` | Bug: fix the code. Do not retry. |
        | 401 | `invalid_credentials` (+ `reason`) | Key missing, wrong or revoked. Do not retry; log it for the admin. |
        | 403 | `website_inactive`, `https_required` | Do not retry. |
        | 409 | `idempotency_conflict` | Same `submission_id` was used with a different payload. Do not retry; a new inquiry needs a new ID. |
        | 413 | `payload_too_large` | Shorten (e.g. truncate `message`). Do not retry unchanged. |
        | 415 | `unsupported_media_type` | Send `Content-Type: application/json`. |
        | 422 | `validation_failed` + `fields` (e.g. `{"email": "..."}`) | Mapping bug. Do not retry; log the field names. |
        | 429 | `rate_limited` + `Retry-After` header | Retry later with the **same ID and identical body**. |
        | 5xx, timeout, network error | `server_error` | Retry with the **same ID and identical body**, exponential backoff. |

        Errors look like `{ "ok": false, "error": { "code": "...", "message": "...", "fields": { ... } } }`.

        ## Requirements

        1. **Background only.** Follow the rule above: the API is called from a queue worker, a job, an
           after-response hook or cron, never inline before the customer's response. Use a short timeout there
           too (connect 5 s, total 10 s). Prove it in a test: the form's response is returned without any HTTP
           call to the Inquiry Manager having been made, and is identical when the API is down.
        2. **No lost inquiries.** Store the exact payload before sending (a small outbox: database table or
           files outside the web root). Mark it delivered on 200/201, retry 429/5xx/network errors from a
           scheduled job (cron, queue worker or the platform's scheduler) with backoff for up to 24 hours, and
           mark other 4xx as failed with the error code. Retries resend the stored body byte-for-byte.
        3. **Double submits are stored once.** Generate `submission_id` once per form submission. Where the form
           can be re-posted (double click, back button), create the ID when the form is rendered (hidden input or
           client state), validate it server-side against `^[A-Za-z0-9._:-]{1,128}$`, and replace it with a fresh
           server-side ID if invalid.
        4. **Secrets stay on the server.** The key must never appear in browser JavaScript, HTML, templates sent
           to the browser, Shopify Liquid, client bundles, logs or git. No `NEXT_PUBLIC_`/`VITE_`-style public
           env vars for it.
        5. **Privacy in logs.** Log only the HTTP status, `error.code`, failing field names and `submission_id`.
           Never log the key or the inquiry content (names, emails, phones, messages).
        6. **Small and idiomatic.** One reusable client/service, one place for mapping per form, the framework's
           usual HTTP client and conventions. No changes to unrelated code.

        ## Platform guidance: {$platformLabel}

        {$platformNotes}

        ## Verify

        1. With the key in the environment, send a test from the server shell (run it twice: `201`, then `200`
           with `"replayed": true`):
           ```
           curl -sS -X POST "\$INQUIRY_API_URL" -H "Authorization: Bearer \$INQUIRY_API_KEY" \
             -H "Content-Type: application/json" \
             -d '{"submission_id":"setup-test-1","form_type":"contact","full_name":"Connection test","test":true}'
           ```
        2. Submit each connected form once on a staging or live page with test data.
        3. Check the **Recent API requests** list on the Connect page: {$connectUrl}

        ## Deliverables

        - The code changes, with tests.
        - The table of connected forms with their `form_type`, `form_id` and field mapping.
        - The exact environment variables and any cron/scheduler/queue-worker entry I must add on the server.
        - Anything I must do by hand (install a plugin, set a setting, deploy), as a short numbered list.
        - How to check the outbox for failed deliveries and how to retry them.
        PROMPT;
    }

    private static function platformNotes(string $platform, string $endpoint, string $connectUrl): string
    {
        return match ($platform) {
            'laravel' => <<<'MD'
            - Config: add `'inquiry_manager' => ['url' => env('INQUIRY_API_URL'), 'key' => env('INQUIRY_API_KEY'), 'timeout' => 10]`
              to `config/services.php`; elsewhere use `config('services.inquiry_manager.*')`, never `env()` (it breaks with `config:cache`).
            - Client: `App\Services\InquiryManagerClient` using the `Http` facade (`withToken()`, `acceptJson()`,
              `timeout(10)`, `connectTimeout(5)`), returning a small result object (status, code, fields, inquiry_id).
            - Background delivery: a queued job `SendInquiryToManager implements ShouldQueue` holding the stored
              payload, dispatched from the existing form controllers/Livewire components after their current logic
              (email, local save); the controller then returns its normal response at once. Use `$tries`,
              `backoff()` and `release($retryAfter)` on 429; `fail()` on other 4xx. If the queue driver is `sync`,
              dispatch with `->afterResponse()` (never a plain sync dispatch, which would make the customer wait),
              and tell me to configure a real queue (`database` driver + `php artisan queue:work` under a process
              manager, or `queue:work --stop-when-empty` from the scheduler on shared hosting).
            - Keep a record of each payload (e.g. an `inquiry_outbox` table with a migration) so failed jobs can be
              inspected and retried; `failed_jobs` alone is acceptable only if the payload is preserved there.
            - Tests: Pest/PHPUnit feature tests with `Http::fake()` and `Queue::fake()`.
            MD,
            'codeigniter' => <<<'MD'
            - CodeIgniter 4: config class `app/Config/InquiryManager.php` reading `env('INQUIRY_API_URL')` and
              `env('INQUIRY_API_KEY')` (set them in the server's `.env`, which must not be web-accessible).
            - Client: `app/Libraries/InquiryClient.php` using `service('curlrequest', ['http_errors' => false,
              'timeout' => 10, 'connect_timeout' => 5])`; keep `verify` on.
            - Outbox: migration for an `inquiry_outbox` table (submission_id unique, payload JSON, status,
              attempts, next_attempt_at, last_status, last_code). In the controller, after the existing logic, only
              insert the payload; the controller returns its normal response unchanged.
            - Background delivery: a Spark command `php spark inquiry:send` that sends due rows (with backoff), run
              by cron every minute; give me the cron line. Optionally also send right after the response: register
              a `post_system` event that calls `fastcgi_finish_request()` (or `litespeed_finish_request()` on
              LiteSpeed/Hostinger) first and only then sends that request's rows; if neither function exists, skip
              it and leave delivery to cron.
            - If this is CodeIgniter 3 instead, use a library in `application/libraries`, native cURL and a
              CLI-only controller for the retry job.
            - Tests: CI4 feature/unit tests with the HTTP client mocked.
            MD,
            'nextjs' => <<<'MD'
            - The browser form posts to the site's own server code only: a Route Handler (`app/api/inquiry/route.ts`)
              or a Server Action (`'use server'`). With the Pages Router use `pages/api/inquiry.ts`.
            - Put the API call in `lib/inquiry-api.ts` starting with `import 'server-only'`; read
              `process.env.INQUIRY_API_URL` / `INQUIRY_API_KEY` there. Never use a `NEXT_PUBLIC_` prefix.
            - Use `fetch` with `cache: 'no-store'` and `AbortSignal.timeout(10_000)`.
            - Create a `clientSubmissionId` with `crypto.randomUUID()` when the form mounts, send it with the form,
              and build `submission_id` from it on the server (validate the pattern). Regenerate it after each
              successful submit.
            - Background delivery: the route/action does the site's own work (email, own database), responds to the
              browser right away, and schedules the API call with `after()` from `next/server` (Next.js 15.1+), or
              `waitUntil()` from `@vercel/functions` on older versions on Vercel. The browser's response never
              depends on the API result.
            - Durable retry: save the payload (the site's database or a KV/queue service) before scheduling; inside
              the background task retry 429/5xx/network errors a couple of times (honour `Retry-After`), and resend
              anything still undelivered from a scheduled route (e.g. Vercel Cron) protected by a `CRON_SECRET`
              header check. If there is no storage available, tell me.
            - Tests: unit-test the mapping and the client with `fetch` mocked.
            MD,
            'wordpress' => <<<MD
            - Preferred: our ready-made plugin **Atlantic Inquiry Connector**. I will download it from {$connectUrl}?platform=wordpress
              and install it (version 1.1 or newer). It saves each submission in a local outbox during the request
              and delivers it in the background after the visitor's response has been sent (or via WP-Cron), retries
              with WP-Cron, and already supports **Contact Form 7** and **Elementor Pro** forms through its
              settings screen. The visitor never waits for it or sees its result. The key goes into `wp-config.php`:
              `define( 'AIQ_ENDPOINT', '{$endpoint}' );` and `define( 'AIQ_API_KEY', '…' );` (I add the key myself).
            - For CF7 / Elementor Pro forms: do not write code. Give me, per form, the plugin rule to enter under
              **Settings → Inquiry Connector → Forms to forward**: the form (CF7 ID or title / Elementor form name)
              and the field map, one line per field, e.g. `your-name = full_name`, `your-subject = fields.Subject`,
              `qty-1 = products[1].quantity`, `your-consent = ignore`.
            - For every other form (WPForms, Gravity Forms, Fluent Forms, Formidable, Ninja Forms, WooCommerce
              enquiry plugins, theme forms): add a must-use plugin `wp-content/mu-plugins/inquiry-forwarding.php`
              that hooks the form plugin's after-submission action and calls
              `aiq_forward_inquiry( array \$payload )`. It adds `submission_id`, saves to the outbox and sends in the
              background after the response, with retries. Never pass `array( 'sync' => true )` from a form handler.
              Guard every call with `function_exists( 'aiq_forward_inquiry' )`, and use
              `aiq_connector_handles( 'cf7'|'elementor', \$form )` to skip forms the plugin already forwards, so
              nothing is sent twice. Sanitize with WordPress functions (`sanitize_text_field`, `sanitize_email`,
              `sanitize_textarea_field`).
            - Do not add webhook actions that post directly to the API (that would expose the key and duplicate data).
            - If the plugin cannot be used, implement the same background pattern: save to a custom outbox table
              during the request, then send with `wp_remote_post()` (timeout 10) from a `shutdown` hook after
              `fastcgi_finish_request()` / `litespeed_finish_request()`, or from a WP-Cron event; never inline.
              Key from a `wp-config.php` constant.
            - On low-traffic sites, tell me to add a real cron calling `wp-cron.php` every 5 minutes.
            MD,
            'shopify' => <<<'MD'
            - Shopify themes cannot hold secrets, and the built-in `{% form 'contact' %}` only emails the store owner.
              The supported path is a **Shopify app proxy**: storefront form → `https://<store>/apps/inquiries/submit`
              (Shopify signs the request) → a relay on a server we control that verifies the signature, holds the
              key and calls the API.
            - If this repository is a **theme**: add a section (e.g. `sections/inquiry-form.liquid`) with a normal
              HTML form posting to `/apps/inquiries/submit` with fields `contact[name]`, `contact[email]`,
              `contact[phone]`, `contact[company]`, `contact[body]`, `inquiry_type` (contact/quote/sample/other),
              `products[0][title|sku|variant|quantity]` for product enquiries and `fields[Label]` for extra answers,
              plus a hidden `submission_id` generated once per page view. No key or secret in Liquid or JavaScript.
              Tell me that the relay from the Inquiry Manager repository (`integrations/shopify/app-proxy`) must be
              deployed and the app proxy configured.
            - If this repository is a **Shopify app** (Remix / React Router template): add the app proxy route that
              verifies the request with `authenticate.public.appProxy(request)`, maps the form fields as above,
              calls the API from the server with the key from the app's environment, and returns a small Liquid or
              HTML confirmation. Configure `[app_proxy]` in `shopify.app.toml` (prefix `apps`, subpath `inquiries`).
            - Background delivery: the proxy route only verifies the signature, saves the payload and immediately
              returns the thank-you response to the shopper; the API call runs afterwards (a job/queue, or after the
              response), and undelivered payloads are resent from a scheduled job.
            MD,
            default => <<<'MD'
            - Create one client class, e.g. `InquiryClient.php`, using cURL (`CURLOPT_TIMEOUT` 10,
              `CURLOPT_CONNECTTIMEOUT` 5, certificate verification on), returning status, error code and field errors.
            - Read the URL and key with `getenv()` or from a config file **outside the web root** (and blocked by
              `.htaccess` if it must live inside), never from a file that can be downloaded.
            - In each form handler, after the existing validation, mail sending and local saving, build the payload
              and only write it to an outbox (a database table, or JSON files in a non-public directory written with
              `LOCK_EX`). The handler then shows its normal success page/redirect unchanged.
            - Background delivery: `register_shutdown_function()` that first calls `fastcgi_finish_request()` (or
              `litespeed_finish_request()` on LiteSpeed/Hostinger) so the customer's page is complete, then
              `ignore_user_abort(true)` and sends the outbox item. If neither function exists, do not send in the
              request at all; leave it to the cron script.
            - Add `send-outbox.php` (CLI only: exit unless `PHP_SAPI === 'cli'`) that sends due items with backoff;
              give me the cron line (every minute, e.g. Hostinger hPanel → Advanced → Cron Jobs).
            - Escape all output and keep the existing success/redirect flow unchanged.
            MD,
        };
    }
}
