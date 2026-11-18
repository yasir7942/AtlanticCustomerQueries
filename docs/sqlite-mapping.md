# SQLite reference → MariaDB mapping

Source: `reference/inquiries.sqlite`, table `submissions` (0 rows; `form_type` CHECK allows `contact`, `quote`). No production data migration is part of this delivery. The table below is the compatibility contract for a future import or for sites that keep their own SQLite log.

| SQLite column | Type / constraint | MariaDB `submissions` column | API field | Notes |
|---|---|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | — (`id` is new) | — | The source ID is not reused. For an import, use `sqlite-<id>` (or `<site>-<id>`) as `submission_id` so re-running the import is idempotent. |
| `created_at` | TEXT NOT NULL | `source_created_at` DATETIME NULL (UTC) | `submitted_at` | The source timestamp. `received_at` (NOT NULL) is always set by the Inquiry Manager when the request arrives. Values without an offset are read as UTC. |
| `form_type` | TEXT CHECK IN (contact, quote) | `form_type` ENUM(contact, quote, sample, other) | `form_type` | Superset of the source values. |
| `locale` | TEXT DEFAULT 'en' | `locale` VARCHAR(16) DEFAULT 'en' | `locale` | |
| `full_name` | TEXT DEFAULT '' | `full_name` VARCHAR(190) | `full_name` | utf8mb4, Arabic safe |
| `company` | TEXT DEFAULT '' | `company` VARCHAR(190) | `company` | optional |
| `email` | TEXT DEFAULT '' | `email` VARCHAR(190), indexed | `email` | optional; validated when present |
| `phone` | TEXT DEFAULT '' | `phone` VARCHAR(50) | `phone` | string; numbers rejected |
| `country` | TEXT DEFAULT '' | `country` VARCHAR(100) | `country` | |
| `address` | TEXT DEFAULT '' | `address` VARCHAR(500) | `address` | optional |
| `city` | TEXT DEFAULT '' | `city` VARCHAR(120) | `city` | |
| `postcode` | TEXT DEFAULT '' | `postcode` VARCHAR(32) | `postcode` | string (leading zeros kept) |
| `message` | TEXT DEFAULT '' | `message` TEXT | `message` | up to 20,000 chars via the API |
| `products` | TEXT NULL | `products` JSON NULL + `product_count` | `products` | Normalized to an array of `{name, sku, variant, quantity, unit, notes}`. If a source row stores free text, import it as `[{"name": "<text>"}]`. |
| `ip` | TEXT DEFAULT '' | `source_ip` VARCHAR(45) | `source.ip` | Visitor IP seen by the source site. `sender_ip` separately records the server that called the API. |
| `dedupe_key` | TEXT NOT NULL | `source_dedupe_key` VARCHAR(191) NULL, index `(website_id, source_dedupe_key)` | `source.dedupe_key` | The source site's own duplicate detection. Kept for reference; **not** used for API idempotency. |
| `email_sent` | INTEGER DEFAULT 0 | `source_email_sent` TINYINT(1) NULL | `source.email.sent` | The source site's notification email. NULL = unknown. |
| `email_sent_at` | TEXT NULL | `source_email_sent_at` DATETIME NULL (UTC) | `source.email.sent_at` | |
| `email_attempts` | INTEGER DEFAULT 0 | `source_email_attempts` SMALLINT NULL | `source.email.attempts` | |
| `email_error` | TEXT NULL | `source_email_error` VARCHAR(1000) NULL | `source.email.error` | |

## New columns (no SQLite equivalent)

| Column | Purpose |
|---|---|
| `website_id` (FK) | Owning website, derived from the API key |
| `form_id` (FK → `forms`) | Registered form identifier (`form_key`, label, type) — new forms need no schema change |
| `credential_id` (FK) | Key that delivered the inquiry |
| `submission_uid` (ascii_bin) + `payload_hash` | API retry handling: unique `(website_id, submission_uid)` + SHA-256 of the canonical body |
| `received_at` | Receipt time (UTC), drives the inbox, filters and reports |
| `custom_fields` JSON | Form-specific answers with optional labels |
| `source_page_url`, `sender_ip`, `is_test` | Diagnostics |
| `status`, `status_changed_at`, `status_changed_by` | Business workflow (`new`, `open`, `closed`, `spam`), independent of delivery metadata |

## Three separate concerns

1. **API retries** — `submission_uid` + `payload_hash`: guarantees one inquiry per sender submission.
2. **Source metadata** — `source_dedupe_key`, `source_email_*`: what the originating site knew; displayed under *Details*.
3. **Business status** — `status`: what the team has done with the inquiry.

## Indexes and the queries they serve

| Index | Used by |
|---|---|
| `uq_submissions_website_uid (website_id, submission_uid)` | idempotent insert and replay lookup |
| `idx_submissions_received (received_at)` | unrestricted inbox ordering and date ranges (Developers, Directors) |
| `idx_submissions_website_received (website_id, received_at)` | User-scoped inbox (`website_id IN (…)`), website filter, per-site report |
| `idx_submissions_type_received`, `idx_submissions_website_type_received` | type filter and dashboard/report type counts |
| `idx_submissions_status_received` | status filter |
| `idx_submissions_email` | exact email lookups |
| `idx_submissions_source_dedupe` | comparing with a source site's dedupe keys |

Search uses `LIKE` over the contact columns, message, products and custom fields within the already-filtered set. That is adequate for tens of thousands of inquiries per year. Add a FULLTEXT index on `(full_name, company, email, message)` if volume grows well beyond that.

## Removing a website

- **No inquiries**: the website, its forms, assignments and keys are deleted.
- **Has inquiries** (default): the website is **archived**. History stays readable in the inbox and reports, every key is revoked and the API rejects it. `submissions.website_id` is `ON DELETE RESTRICT`, so history cannot be lost by accident.
- **Permanent purge**: available to Developers only after typing the domain to confirm; deletes the inquiries too. Take a backup first.
