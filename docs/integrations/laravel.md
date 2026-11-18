# Laravel (10, 11, 12)

Inquiries are forwarded by a queued job that carries the full payload, including a stable `submission_id`, so every retry sends the identical request and the inbox shows one inquiry.

## 1. Where the code goes

| File | Location in the Laravel project |
|---|---|
| `app/Services/InquiryForwarder.php` | same path |
| `app/Jobs/ForwardInquiry.php` | same path |
| `app/Http/Controllers/InquiryController.php` | same path (or merge into your controller) |
| `config/services.php` | add the `inquiry` entry |

## 2. Store the key

In the server's `.env` (not committed):

```
INQUIRY_API_URL={{ENDPOINT}}
INQUIRY_API_KEY=aiq_…
QUEUE_CONNECTION=database
```

<!-- include: integrations/laravel/config-services-snippet.php lang=php -->

Run `php artisan config:cache` after changing `.env` in production. The key is only read on the server; never pass it to Blade views or Vite (`VITE_` variables are public).

## 3. Service and job

<!-- include: integrations/laravel/app/Services/InquiryForwarder.php lang=php -->

<!-- include: integrations/laravel/app/Jobs/ForwardInquiry.php lang=php -->

## 4. Map form fields

The controller validates the form, maps fields to the contract, and dispatches the job. The form includes a hidden `inquiry_token` (a UUID created when the form is shown) so a double submit reuses the same ID.

<!-- include: integrations/laravel/app/Http/Controllers/InquiryController.php lang=php -->

In the Blade form: `<input type="hidden" name="inquiry_token" value="{{ $token }}">` plus `@csrf`.

## 5. Validation failures and retries

- `422`/`401`/`403`: the job fails without retrying and logs only the field names.
- `429` honours `Retry-After`; `5xx` and network errors retry with backoff (1 min → 12 h, 8 tries) using the same payload.
- `409`: the token was already stored with different content (edited resubmission); logged, not retried.

**Shared hosting without a worker:** create the jobs table (`php artisan queue:table` on Laravel 10, included by default on 11+; then `php artisan migrate`) and run the scheduler from cron (`* * * * * php /path/artisan schedule:run`) with the `queue:work --stop-when-empty` schedule shown above. As a simpler alternative, call `ForwardInquiry::dispatchSync($payload)` — the visitor then waits for the API call.

## 6. Send a test inquiry and confirm receipt

```
php artisan tinker
>>> dispatch_sync(new App\Jobs\ForwardInquiry(['submission_id' => App\Services\InquiryForwarder::newSubmissionId(), 'form_type' => 'contact', 'full_name' => 'Laravel test', 'test' => true]));
```

The inquiry appears in the inbox with a **Test** tag under **{{WEBSITE_NAME}}**, and this page's **Recent API requests** shows `201 created`.
