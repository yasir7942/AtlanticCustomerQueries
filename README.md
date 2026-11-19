# Inquiry Manager

One inbox for contact inquiries, quotation requests and sample requests from many websites. Plain PHP + MariaDB, built for Hostinger shared hosting (no root, Node.js, Redis or background worker). Developed locally with XAMPP on Windows.

- **Inbox** with search, website/type/status/date filters, pagination, CSV export; detail pages with products, quantities and custom answers (Arabic/RTL-safe).
- **Dashboard and reports**: totals for a period, per website, per type, daily trend in the viewer's time zone; every count links to the matching inbox filter and all numbers come from the same query builder.
- **Roles**: Developer, Director, User, enforced on the server (see the matrix below). No public signup.
- **Intake API** `POST /api/v1/submissions` with per-website bearer keys, idempotent retries, clear errors and throttling.
- **Connectors**: plain PHP, Laravel, CodeIgniter 4, Next.js App Router, WordPress plugin (Contact Form 7, Elementor Pro, custom forms) plus `functions.php` snippets, Shopify app proxy relay.

## Contents

| Path | What |
|---|---|
| `public/` | the only web root: `index.php`, `.htaccess`, `robots.txt`, `favicon.ico`, `assets/` |
| `src/` | application code (`App\` PSR-4) |
| `templates/` | server-rendered views |
| `database/migrations/` | MariaDB schema |
| `database/fixtures/seed.php` | synthetic demo data |
| `bin/` | CLI: `migrate.php`, `create-developer.php`, `reset-password.php`, `backup.php`, `build-wordpress-plugin.php` |
| `integrations/` | connector source for each platform; WordPress plugin source |
| `dist/atlantic-inquiry-connector.zip` | installable WordPress plugin |
| `docs/` | [API](docs/api.md), [SQLite mapping](docs/sqlite-mapping.md), [Hostinger deployment](docs/deployment-hostinger.md), [security review](docs/security-review.md), [integration guides](docs/integrations/) |
| `tests/` | test runner, end-to-end tests, WordPress stand-in harness |
| `reference/inquiries.sqlite` | supplied compatibility reference (never deploy) |

## Requirements

| | Version / notes |
|---|---|
| PHP | **8.1+** (tested on 8.4.27). Extensions: `pdo_mysql`, `mbstring`, `json`, `openssl`, `session`, `ctype`; `curl` for the PHP connector; `zip` optional (plugin ZIP rebuild; a prebuilt ZIP is included) |
| Database | MariaDB **10.4+** (tested on 10.4.28) or MySQL 8.0+; InnoDB, `utf8mb4`. No time-zone tables needed. |
| Composer | 2.x, for the autoloader only (no runtime packages) |
| Web server | Apache or LiteSpeed with `mod_rewrite` / `.htaccess` |
| Node.js | only to run the Next.js connector test locally (22+, tested on 24) |
| WordPress plugin | WordPress 6.0+, PHP 7.4+ |

## Local setup (Windows + XAMPP)

The project lives in `D:\xampp\htdocs\AtlanticCustomerQueries`. The root `.htaccess` rewrites every request into `public/`, so `.env`, source and the SQLite reference are not reachable over HTTP.

1. **Enable `pdo_mysql`** in `D:\xampp\php\php.ini` (it is currently commented out): change `;extension=pdo_mysql` to `extension=pdo_mysql` (optionally also `extension=zip`), save, then restart Apache in the XAMPP Control Panel. Until then, run CLI commands with `php -d extension=pdo_mysql …` as shown below.
2. Install the autoloader and create `.env`:
   ```
   cd D:\xampp\htdocs\AtlanticCustomerQueries
   composer install
   copy .env.example .env
   ```
   The defaults match XAMPP (`root`, no password, database `atlantic_inquiries`, `APP_URL=http://localhost/AtlanticCustomerQueries`).
3. Create the database and tables:
   ```
   php -d extension=pdo_mysql bin/migrate.php --create-db
   ```
4. Create the first Developer (prompts for a password, or add `--temporary`):
   ```
   php -d extension=pdo_mysql bin/create-developer.php --name="Your Name" --email=you@example.com
   ```
5. Optional demo data (3 websites, ~60 synthetic inquiries, a Director and a User with password `demo passphrase 1`):
   ```
   php -d extension=pdo_mysql database/fixtures/seed.php
   ```
6. Open **http://localhost/AtlanticCustomerQueries/** and sign in.

Without Apache you can also run `php -d extension=pdo_mysql -S 127.0.0.1:8000 -t public tests/router.php` with `APP_URL=http://127.0.0.1:8000` in `.env`.

### Run the tests

```
php -d extension=zip tests/run.php
```

(Add `-d extension=pdo_mysql` too if it is not enabled in `php.ini`; drop `-d extension=zip` once `zip` is enabled.)

The runner **drops and recreates** `DB_TEST_NAME` (default `atlantic_inquiries_test`; it refuses names that do not end in `_test`), migrates it, and runs unit, workflow and end-to-end tests. The end-to-end tests start PHP's built-in server on ports 8765/8766, call it over real HTTP, run Node for the Next.js route, and probe Apache at `http://localhost/AtlanticCustomerQueries` if it is running. `--filter=text` runs a subset. Any PHP warning fails the test that caused it.

## Using the application

### Roles

| Capability | Developer | Director | User |
|---|---|---|---|
| Read inquiries, dashboards, reports, CSV | All websites | All websites | Assigned websites only |
| Create and manage accounts | All roles | Directors and Users | Own profile only |
| Assign websites to Users | Yes | Yes | No |
| Edit website display name / business details | Yes | Yes | No |
| Register, deactivate, archive/delete websites | Yes | No | No |
| Connection settings, keys, API Connect page, connector downloads | Yes | No | No |
| Business audit log | Yes | Yes | No |
| Technical connection diagnostics | Yes | No | No |
| Own password and time zone | Yes | Yes | Yes |

The sign-in page has a show/hide (eye) button on the password field and a **Remember me** box, ticked by default, that keeps the device signed in for `REMEMBER_DAYS` (default 30; `0` removes the option). Signing out, a password reset, deactivation or changing your password ends remembered sign-ins on other devices.

New accounts can get a **temporary password** (shown once; must be changed at first sign-in before anything else). Password resets, deactivation and password changes sign out existing sessions. A new User sees nothing until a website is assigned. Removing an assignment applies on the next page load. The last active Developer cannot be demoted or deactivated.

### Connecting a website

1. **Websites → Register website** (Developer): name, domain, platform.
2. **API Connect**: generate a key — it is shown **once**. Copy it to the website's server (never into browser code).
3. Follow the platform guide on the same page (also in `docs/integrations/`). WordPress sites can download the plugin ZIP there.
4. Send the test request; check **Last successful submission** and **Recent API requests**, and the inquiry in the inbox.

Keys can be rotated (old key keeps working 24 h, or is revoked immediately) and revoked. Deactivating a website rejects submissions with `403`. Removing a website **archives** it when it has inquiries (history stays, keys revoked); a permanent purge requires typing the domain.

### Time zones

Timestamps are stored in UTC. Each profile has an IANA time zone (default `Asia/Dubai`; `Asia/Karachi` and all others selectable). Display, date filters and daily totals use the viewer's zone, and local date boundaries are converted to UTC before querying. Example verified by tests: an inquiry received at 19:30 UTC shows as **23:30 on 18 Nov** for a Dubai viewer and **00:30 on 19 Nov** for a Karachi viewer, and falls inside the corresponding single-day filters and daily totals.

## Deploying to Hostinger

See [docs/deployment-hostinger.md](docs/deployment-hostinger.md). Summary: `public/` contents → `public_html/`; everything else → a sibling folder `inquiry-app/` (with `.env` at 600, `vendor/` built locally); tables via `bin/migrate.php` (SSH) or phpMyAdmin import of `php bin/migrate.php --print-sql`; first Developer via `bin/create-developer.php` (SSH) or its `--sql` output pasted into phpMyAdmin; daily `bin/backup.php` cron.

## Security

See [docs/security-review.md](docs/security-review.md): scope enforcement, role checks, prepared statements, output escaping and CSP, CSRF tokens, session hardening, hashed keys and passwords, rate limiting, input limits, audit trail, and the residual risks.

## Assumptions

- Any role that can read an inquiry may change its business status (new/open/closed/spam). The permission table did not cover this; restricting it is a one-line change in `InboxController::status`.
- Directors see the Websites list and Developer accounts read-only; they cannot open API Connect or diagnostics.
- The Inquiry Manager does not send notification emails; source websites keep their own form emails (connectors never interfere with them) and can report delivery status in `source.email`.
- Removing a website with history archives it by default; permanent purge is a separate, confirmed Developer action.
- No data migration from the SQLite reference was required; `docs/sqlite-mapping.md` defines how each field maps.
- Search uses `LIKE` across contact fields, message, products and custom answers; adequate for tens of thousands of inquiries per year (see the mapping doc for scaling notes).
