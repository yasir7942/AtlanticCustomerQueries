# Intake API v1

`POST /api/v1/submissions` — server-to-server only. Each website has its own key; the key decides which website an inquiry belongs to. A website ID in the body is rejected (`422`, unknown field).

## Request

| Header | Value |
|---|---|
| `Authorization` | `Bearer aiq_<16-char key id>_<43-char secret>` |
| `Content-Type` | `application/json` (UTF-8) |
| `Idempotency-Key` | optional; same value as `submission_id`, or used instead of it |

Limits: body ≤ 64 KB (`API_MAX_BODY_BYTES`); per-website requests per minute (set on the website, default 60); 300 requests per minute per sending IP before authentication; 20 failed authentications per minute per IP.

### Body

| Field | Type | Rules |
|---|---|---|
| `submission_id` | string | **Required** (or `Idempotency-Key`). 1–128 chars `A-Z a-z 0-9 . _ : -`. Stable for the inquiry: reuse it on every retry. Unique per website. |
| `form_type` | string | **Required**: `contact`, `quote`, `sample`, `other` |
| `form_id` | string | Form identifier, ≤ 100 chars (letters/digits/space `. _ : -`). Default = `form_type`. New IDs are registered automatically. |
| `form_name` | string | Human label for the form, ≤ 150 |
| `submitted_at` | string | Optional source timestamp, ISO 8601 (`2026-11-18T19:30:00Z` or with offset). Stored separately from the server's receipt time. Only send it if your retries resend the stored payload unchanged. |
| `locale` | string | Language tag, e.g. `en`, `ar`, `en-GB`. Default `en`. |
| `full_name`, `company`, `email`, `phone`, `country`, `address`, `city`, `postcode` | string | All optional. Max 190/190/190/50/100/500/120/32. `email` must be valid if present. `phone` and `postcode` must be **strings** (numbers are rejected to protect leading zeros and `+`). |
| `message` | string | ≤ 20,000 chars; newlines kept |
| `products` | array | ≤ 200 items: `{ "name", "sku", "variant", "quantity", "unit", "notes" }`; each needs `name` or `sku`; `quantity` number (or numeric string) 0–1e9 |
| `fields` | object | Custom answers, ≤ 100 keys (key ≤ 100 chars). Value: string ≤ 5000, number, boolean, null, list of ≤ 50 scalars, or `{ "label": "Shown label", "value": … }` |
| `source` | object | `{ "page_url": "https://…", "ip": "visitor IP", "dedupe_key": "…", "email": { "sent": bool, "sent_at": ISO, "attempts": int, "error": "…" } }` — the originating site's own metadata |
| `test` | boolean | `true` marks the inquiry as a test (tagged in the inbox) |

At least one of name, company, email, phone, message, products or fields must be present. Unknown top-level keys are rejected so mapping mistakes surface immediately.

### Examples

Contact inquiry:

```json
{
  "submission_id": "contact-7f3c9a1e-1b2d-4c5e-9f00-112233445566",
  "form_type": "contact",
  "form_id": "contact-page",
  "locale": "en",
  "full_name": "Sara Ahmed",
  "email": "sara@example.com",
  "phone": "+971 50 000 0000",
  "message": "Please call me about bulk pricing.",
  "source": { "page_url": "https://example.com/contact", "ip": "203.0.113.10" }
}
```

Multi-product quote:

```json
{
  "submission_id": "quote-2c9e1d7a-0000-4000-8000-000000000001",
  "form_type": "quote",
  "form_id": "quote-cart",
  "company": "Gulf Build Co.",
  "email": "buyer@gulfbuild.example",
  "country": "United Arab Emirates",
  "message": "Delivery to Jebel Ali, please include lead time.",
  "products": [
    { "name": "Ball valve DN50", "sku": "BV-50", "quantity": 120, "unit": "pcs" },
    { "name": "PVC pipe 110mm", "sku": "PVC-110", "quantity": 2.5, "unit": "km", "notes": "Grey" }
  ]
}
```

Sample request with custom fields (Arabic is stored and shown as sent):

```json
{
  "submission_id": "sample-5d1f0c2b-0000-4000-8000-000000000002",
  "form_type": "sample",
  "form_id": "swatch-request",
  "form_name": "Swatch request",
  "locale": "ar",
  "full_name": "ليلى حداد",
  "phone": "+962 7 0000 0000",
  "fields": {
    "swatches": { "label": "Swatches", "value": ["Sand", "Charcoal"] },
    "delivery_by": "2026-12-01",
    "needs_invoice": true,
    "project_size_m2": 420
  }
}
```

## Responses

`201 Created` — stored:

```json
{ "ok": true, "status": "created", "replayed": false, "inquiry_id": 1042,
  "submission_id": "contact-7f3c…", "received_at": "2026-11-18T19:30:02Z" }
```

`200 OK` + header `Idempotent-Replayed: true` — the same `submission_id` with the same payload was already stored (for example a retry after a timeout). The body is the original result with `"status": "duplicate", "replayed": true`. No second inquiry is created.

Errors always look like `{ "ok": false, "error": { "code": "…", "message": "…", … } }`:

| Status | `error.code` | Meaning | Retry? |
|---|---|---|---|
| 400 | `invalid_json` | Body is not a UTF-8 JSON object | No |
| 401 | `invalid_credentials` (+ `reason`: `missing_credentials`, `malformed_credentials`, `unknown_key`, `revoked_key`, `expired_key`) | Key missing or not valid | No |
| 403 | `website_inactive` | Website deactivated or archived | No |
| 403 | `https_required` | Plain HTTP while `API_REQUIRE_HTTPS=true` | No |
| 409 | `idempotency_conflict` | `submission_id` already used with a **different** payload | No — use a new ID for a new inquiry |
| 413 | `payload_too_large` (+ `max_bytes`) | Body over the limit | No |
| 415 | `unsupported_media_type` | Not `application/json` | No |
| 422 | `validation_failed` (+ `fields`: `{ "email": "…", "products[1].quantity": "…" }`) | Field errors | No — fix the mapping |
| 429 | `rate_limited` + `Retry-After` header | Too many requests | Yes, same ID, after the delay |
| 500 | `server_error` | Unexpected error | Yes, same ID, with backoff |

## Idempotency rules

- Uniqueness is per website: `(website, submission_id)` is a unique key in MariaDB, so concurrent retries cannot create two rows.
- The payload fingerprint is SHA-256 over the JSON body with object keys sorted, so key order does not matter, but any value change does.
- Generate the ID once per form submission and keep it with the payload until a `2xx` arrives. Connectors that rebuild the payload on each attempt must not include changing values such as a fresh `submitted_at`.
- The source site's own `dedupe_key` and email-delivery metadata (`source`) are stored for reference only; they do not affect API retries or the inquiry's business status (`new`, `open`, `closed`, `spam`).

## Test from the command line

```
curl -X POST https://inquiries.example.com/api/v1/submissions \
  -H "Authorization: Bearer $INQUIRY_API_KEY" -H "Content-Type: application/json" \
  -d '{"submission_id":"curl-test-1","form_type":"contact","full_name":"curl test","test":true}'
```

Run it twice: `201` then `200` with `"replayed": true`.
