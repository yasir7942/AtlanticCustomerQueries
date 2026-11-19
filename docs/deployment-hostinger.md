# Deploying to Hostinger shared hosting

No root access, Node.js, Redis or long-running worker is needed. The app runs as plain PHP behind Hostinger's LiteSpeed web server. Rate limiting and sessions use MariaDB and files. Scheduled tasks (backups) use hPanel cron jobs.

## 1. Directory layout

Only the entry point, `.htaccess` and static assets are web-accessible. Everything else sits beside `public_html`, not inside it:

```
/home/u123456789/domains/inquiries.example.com/
├── public_html/                 ← contents of the repository's public/ folder ONLY
│   ├── index.php
│   ├── .htaccess
│   └── assets/ (app.css, app.js)
└── inquiry-app/                 ← everything else
    ├── .env                     (chmod 600)
    ├── bin/  database/  docs/  integrations/  src/  templates/
    ├── dist/atlantic-inquiry-connector.zip
    ├── storage/logs/  storage/sessions/  storage/backups/   (writable)
    ├── vendor/                  (built on your computer, see step 3)
    └── composer.json
```

`public_html/index.php` looks for the application in `../inquiry-app`. If you choose another folder name, edit the `$candidates` list at the top of `index.php`. Do **not** upload `reference/` (the source SQLite file), `tests/`, or a local `.env`.

## 2. hPanel preparation

1. **Domain/subdomain**: hPanel → Domains → Subdomains → create `inquiries` (or use a dedicated domain). Hostinger creates `domains/<name>/public_html`.
2. **PHP**: hPanel → Advanced → PHP Configuration → select **PHP 8.2 or newer** (8.1 is the minimum). Under *PHP extensions* make sure `pdo_mysql`, `mbstring`, `openssl` and `json` are enabled (`zip` is optional; a prebuilt plugin ZIP ships in `dist/`). Recommended options: `display_errors` Off, `expose_php` Off.
3. **SSL**: hPanel → Security → SSL → install the free certificate for the subdomain and enable **Force HTTPS**. The `.htaccess` also redirects browser traffic to HTTPS. API calls must use `https://` directly, because a redirect would drop the POST body; the API returns `403 https_required` on plain HTTP.
4. **Database**: hPanel → Databases → Management → create a database and user (Hostinger prefixes both, e.g. `u123456789_inquiries`). Note the password. Host is `localhost`.

## 3. Build locally (works without SSH/Composer on the server)

On your computer, in the project folder:

```
composer install --no-dev --optimize-autoloader
php -d extension=zip bin/build-wordpress-plugin.php
```

This creates `vendor/` (only Composer's autoloader — the app has no runtime packages) and `dist/atlantic-inquiry-connector.zip`. Zip the project for upload, excluding `tests/`, `reference/`, `.env` and `storage/*/` contents.

## 4. Upload

- **File Manager** (no SSH): upload the zip into `domains/inquiries.example.com/`, extract, rename the folder to `inquiry-app`, then **move** the contents of `inquiry-app/public/` into `public_html/` (replace Hostinger's default `index.php`/`default.php`). Delete the now-empty `inquiry-app/public/`.
- **SSH/SFTP** (Premium/Business plans): same layout; `rsync`/`scp` works, or `git clone` into `inquiry-app` and run `composer install --no-dev -o` there (Hostinger provides `composer`).

Check that `inquiry-app/storage/logs`, `storage/sessions` and `storage/backups` are writable by your account (default 755 folders are fine because PHP runs as your user).

## 5. Configure `.env`

Create `inquiry-app/.env` from `.env.example`, then set its permissions to **600** (File Manager → Permissions):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://inquiries.example.com
APP_NAME="Inquiry Manager"
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u123456789_inquiries
DB_USER=u123456789_inquiries
DB_PASS=the-database-password
SESSION_SECURE=true
SESSION_IDLE_MINUTES=480
API_REQUIRE_HTTPS=true
API_MAX_BODY_BYTES=65536
API_IP_LIMIT_PER_MINUTE=300
TRUSTED_PROXIES=
```

Leave `TRUSTED_PROXIES` empty unless you put a CDN/proxy in front of the app (then list its IPs so client IPs and HTTPS are read from `X-Forwarded-*`).

## 6. Create the tables

- **With SSH**: `cd ~/domains/inquiries.example.com/inquiry-app && php bin/migrate.php`
- **Without SSH**: on your computer run `php bin/migrate.php --print-sql > schema.sql`, then hPanel → Databases → **phpMyAdmin** → select the database → **Import** → `schema.sql`.
- Alternative without SSH: hPanel → Advanced → **Cron Jobs** → custom command `/usr/bin/php /home/u123456789/domains/inquiries.example.com/inquiry-app/bin/migrate.php`, every minute; wait one minute, check the cron output, then **delete** the cron job.

Migrations are recorded in `schema_migrations`; running them again does nothing.

## 7. Create the first Developer (one time, no web setup page)

- **With SSH**:
  ```
  php bin/create-developer.php --name="Your Name" --email=you@example.com --temporary
  ```
  It prints a temporary password; sign in and choose your own.
- **Without SSH** — on your computer:
  ```
  php bin/create-developer.php --sql --name="Your Name" --email=you@example.com
  ```
  Copy the printed SQL into phpMyAdmin → **SQL** tab → Go. The comment above the SQL contains the temporary password. The `INSERT` only works while no Developer exists, so the statement is useless afterwards. Sign in at `https://inquiries.example.com/login` and you will be asked to set a new password.

Further accounts are created in the app (**Users**). There is no public signup.

**Forgotten password.** A Director or Developer can reset anyone they manage under **Users**. If you are locked out yourself (for example the only Developer), use SSH in the application folder:
```
php bin/reset-password.php --email=you@example.com            # prompts for a new password
php bin/reset-password.php --email=you@example.com --temporary # prints a one-time password
```
This signs the account out on every device, clears the sign-in lockout and writes an audit entry.

## 8. Cron jobs (hPanel → Advanced → Cron Jobs)

| Schedule | Command |
|---|---|
| Daily 02:15 | `/usr/bin/php /home/u123456789/domains/inquiries.example.com/inquiry-app/bin/backup.php --keep=14` |

Rate-limit counters and API diagnostics are pruned automatically during normal requests, so no other cron job is needed.

## 9. Backups and restore

- `bin/backup.php` writes `storage/backups/inquiries-YYYYMMDD-HHMMSS.sql.gz` (outside `public_html`, chmod 600) and keeps the newest 14. Download one regularly (File Manager → Download) and keep copies off the server.
- Hostinger's own backups (hPanel → Files → Backups) cover files and databases on most plans; use them as a second layer.
- **Restore**: decompress the `.sql.gz` (7-Zip or `gunzip`), then phpMyAdmin → Import, or `mysql -u USER -p DB < file.sql` over SSH. The dump drops and recreates each table.
- Before a destructive change (website purge, upgrade) take a manual backup: `php bin/backup.php` over SSH, or run the cron job once.

## 10. Verify the deployment

1. `https://inquiries.example.com/login` shows the sign-in page over HTTPS (http redirects to https).
2. `https://inquiries.example.com/.env`, `/composer.json`, `/src/App.php` all return 404 (they are not in `public_html`).
3. `curl -i https://inquiries.example.com/api/v1/submissions` returns `405` JSON.
4. Sign in, register a website, create a key, and send the test request from the API Connect page; it appears in the inbox and under **Last successful submission**.
5. Set `APP_DEBUG=false` and confirm an error page shows no stack trace. Errors are written to `inquiry-app/storage/logs/app-YYYY-MM-DD.log` and `php-error.log`.

## 11. Updating

Back up, upload the changed files (keep `.env` and `storage/`), copy `public/` changes into `public_html/`, rebuild `vendor/` locally if `composer.json` changed, then run migrations (step 6). There is no cache to clear. If Hostinger's OPcache serves old code for a minute, hPanel → Advanced → PHP Configuration → toggle the PHP version or wait.

## Notes for LiteSpeed (Hostinger)

- `.htaccess` rewrite rules, `Options -Indexes` and `FilesMatch` deny rules are honoured by LiteSpeed.
- The `Authorization` header is passed to PHP via the `E=HTTP_AUTHORIZATION` rewrite rule; the app also reads `REDIRECT_HTTP_AUTHORIZATION`.
- If you see `500` after uploading `.htaccess`, remove the `<IfModule lsapi_module>` block (some plans disallow `php_flag`) and set `display_errors` in hPanel instead.
