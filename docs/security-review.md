# Security review

Scope: the PHP application, intake API, connectors and deployment configuration in this repository. Each item says how the control works, where it lives, and which automated test exercises it (`tests/`).

## Unauthorized cross-website access

- **Single scope source.** `App\Domain\Scope` resolves which websites the viewer may read, freshly on every request (no session caching). Developers and Directors: all; Users: rows in `website_user`.
- **Every read path goes through `SubmissionQuery`**, which always adds `website_id IN (scope)` (or `1=0` for no assignments): inbox list, search, filters, detail page, status change, dashboard counts, daily trend, reports, both CSV exports and filter dropdowns. A website filter outside the scope returns nothing.
- Detail URLs outside scope return **404** (not 403), so record existence is not revealed.
- Revoking an assignment takes effect on the next request.
- Tests: `W2ScopeTest` (direct URL, status POST, search, website filter, dropdown options, dashboard, reports, report CSV, inbox CSV, revocation).
- API: the website is derived from the key only; `website_id` in the body is rejected as an unknown field (`W1…a submitted website_id cannot redirect`).

## Privilege escalation

- Server-side permission checks at two layers: route abilities in `App\App::routes()` (via `Gate::allows`) and again inside each domain service (`UserService`, `WebsiteService`, `CredentialService`). Navigation hides what the role cannot use, but hiding is never relied on.
- `Gate::grantableRoles`: Directors can grant only Director/User; `Gate::canManageUser`: Directors cannot modify, reset or deactivate Developers.
- Last active Developer cannot be demoted or deactivated (checked inside a transaction with `SELECT … FOR UPDATE`). Users cannot change their own status.
- Denied attempts are written to the audit log with outcome `denied`.
- Tests: `W3RolesTest` (Director creates Director, manages assignments; 9 Developer-only endpoints return 403 and change nothing; cannot create/edit/reset/promote to Developer; last-Developer guard; deactivation ends sessions), `W2…User cannot reach management pages`.

## SQL injection

- All queries use PDO prepared statements with native (non-emulated) prepares and bound parameters (`App\Support\Database`). Dynamic SQL fragments are built only from fixed column names and placeholders. `LIKE` input is escaped (`%`, `_`, `\`), and the submission-ID comparison is only added when the term matches the ID pattern.
- Strict SQL mode and `utf8mb4` are set per connection.
- Tests: search with `100%_\` returns a normal empty result; Arabic search works.

## Stored XSS (messages, names, custom fields, products)

- Templates escape every dynamic value with `e()` (`htmlspecialchars`, `ENT_QUOTES|ENT_SUBSTITUTE`, UTF-8). Multi-line text uses `nl2br(e(…))`. No user content is ever inserted unescaped.
- Integration guides are rendered by a small Markdown renderer that escapes everything and allows only `http(s)` links.
- Content-Security-Policy: `default-src 'self'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'; form-action 'self'` — no inline scripts or styles are used, so an injection could not execute even if escaping failed.
- Bidi-override characters (U+202A–U+202E) and control characters are stripped on intake; RTL text renders with `dir`/`<bdi>` isolation.
- CSV exports neutralize formula injection (`=`, `+`, `-`, `@` prefixed with `'`).
- Shopify relay never echoes visitor input into its `application/liquid` response (prevents Liquid injection).
- Tests: `W5…HTML/script … is escaped` (detail, inbox, dashboard), CSP header present, `UnitTest` Markdown/CSV.

## CSRF

- Every browser `POST` requires a per-session 256-bit token (`App\Support\Csrf`), checked before routing, including sign-in and sign-out. Failure → 419 page.
- Session cookie `SameSite=Lax` as a second layer; state changes are never performed on `GET`.
- The API is exempt (no cookies; bearer-key authenticated).
- WordPress plugin: settings via the Settings API (`options.php` nonce + `manage_options`); custom admin actions use `check_admin_referer` + `current_user_can`.
- Tests: `W5…CSRF` (forged and missing token, login form).

## Sessions and authentication

- Passwords: bcrypt (cost 12), 12–72 bytes; only hashes stored. Temporary passwords are random, shown once, and force a change before any other protected route (enforced in the kernel).
- Session cookie: `HttpOnly`, `Secure` on HTTPS (`SESSION_SECURE`), `SameSite=Lax`, strict mode, stored in `storage/sessions` (outside the web root). Session ID regenerated on sign-in and password change; idle timeout (`SESSION_IDLE_MINUTES`).
- `users.session_version` is compared on every request: a password reset, own password change (other sessions) or deactivation invalidates existing sessions immediately.
- Sign-in: generic error message, dummy-hash verification for unknown emails (timing), throttled on failures per IP (30/15 min) and per account (5/15 min); successful sign-ins are never counted.
- "Remember me" (checked by default, `REMEMBER_DAYS`, default 30): a separate `aiq_remember` cookie (`HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`) holding a random selector and validator; only `SHA-256(validator)` is stored in `remember_tokens`. The token is accepted only while the account is active and its `session_version` is unchanged, and the device gets a fresh session. Signing out deletes the device's token. Password resets, own password changes and deactivation delete all of the account's tokens (own change re-remembers the current device). `REMEMBER_DAYS=0` removes the option.
- Open-redirect safe: the post-login "intended" URL must be a same-app relative path.
- Tests: `W4` (temporary password flow, reset ends sessions, own change keeps current session), `W3` deactivation, `W5` login throttling.

## Exposed credentials

- API keys: `aiq_<id>_<secret>`; only `SHA-256(secret)` is stored, compared with `hash_equals`. The full key is shown once (one-time session value) and never logged. Rotation with 24 h grace or immediate; revocation is immediate; archiving revokes all keys.
- All connector examples keep the key server-side: environment variables / private config outside the web root (PHP, Laravel, CodeIgniter), server-only env + `import 'server-only'` (Next.js, never `NEXT_PUBLIC_`), `wp-config.php` constant or masked option (WordPress), private config for the Shopify relay (nothing in Liquid).
- `.env`, source, vendor, storage and the SQLite reference are outside `public_html` in production; locally the root `.htaccess` rewrites everything into `public/`.
- Tests: `W1` (only the verifier stored, key shown once), `W4` (rotation/revocation/expiry, no secret in audit log), `W6` (Apache: `.env`, `composer.json`, `src/`, `storage/`, `reference/*.sqlite`, migrations, `bin/` not served), WordPress harness (masked key, blank field keeps key, invalid key rejected).

## Excessive API traffic and input limits

- Fixed-window counters in MariaDB (`rate_limits`), suitable for shared hosting: per IP before authentication (`API_IP_LIMIT_PER_MINUTE`), per IP for failed authentication (20/min), per website after authentication (configurable). `429` with `Retry-After`.
- Body size checked from `Content-Length` and while reading (`API_MAX_BODY_BYTES`, default 64 KB) → `413`. JSON depth ≤ 16; field length, product (200) and custom field (100) limits → `422`. Search terms ≤ 200 characters.
- Tests: `W1` (throttling, failed-auth throttling, 413/415/400/422).

## Audit and logging

- Business audit log (`audit_log`): sign-in success/failure/denied, sign-out, account create/update/role change/(de)activation/password reset/password change, assignments, website create/update/connection change/(de)activation/archive/delete/purge, key create/rotate/revoke, connector download, inquiry status changes and CSV exports, access-denied events. Each row: actor, action, target, outcome, IP, UTC time. Readable by Developers and Directors.
- `AuditLog` drops keys named `password`, `secret`, `token`, `key`, `message`, `body` from details; no inquiry content is logged.
- Technical diagnostics (`api_request_log`, Developers only): status, result code, key id (public part), submission ID, sender IP, size, duration, names of invalid fields — never bodies or secrets. Pruned after `DIAGNOSTICS_RETENTION_DAYS`.
- Application error log (`storage/logs`) records exception class/message/location only; `APP_DEBUG=false` hides details from visitors.
- Tests: `W1…diagnostics record outcomes without bodies or keys`, `W4` (no secrets in audit), `W5` (no passwords in audit).

## Headers and transport

`Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, `Cache-Control: no-store` on app pages, `Strict-Transport-Security` on HTTPS. `.htaccess` forces HTTPS for browsers, disables directory listings and blocks dotfiles and data/config extensions.

## Residual risks and recommendations

| Item | Status / recommendation |
|---|---|
| Multi-factor authentication | Not implemented. Recommended next step for Developer accounts. |
| Self-service password reset | Not offered by design (no mail dependency); Directors/Developers reset passwords with temporary passwords. |
| Inquiry status changes | Any role that can read an inquiry can set its status (new/open/closed/spam). This is not in the permission table; it is an assumption to confirm. |
| Fixed-window rate limit | Allows a short burst of up to 2× the limit across a window boundary. Adequate for form traffic. |
| Public form endpoints on the websites | The Next.js route, Shopify relay and site handlers are public by nature. They use honeypots; add CAPTCHA or Turnstile and platform rate limiting if spam appears. Per-website API limits cap the impact on the inbox. |
| WordPress key in the options table | Only when not defined in `wp-config.php`; the UI recommends the constant. |
| Personal data retention | Inquiries, audit log (emails, IPs) and CSV exports contain personal data. Define a retention period. Exports are audited. Backups are written with mode 600 and should be stored encrypted off-server. |
| Shared hosting | Relies on Hostinger's account isolation for `storage/sessions` and `.env`. Keep `.env` at 600. |
