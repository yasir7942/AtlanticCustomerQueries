-- Inquiry Manager: initial schema.
-- Target: MariaDB 10.4+ (also MySQL 8.0+). InnoDB, utf8mb4 for full Unicode (Arabic, emoji).
-- All DATETIME columns hold UTC. The application sets the session time_zone to '+00:00'.
-- Statements are separated by a semicolon at the end of a line (see bin/migrate.php).

CREATE TABLE users (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                 VARCHAR(120) NOT NULL,
    email                VARCHAR(190) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    role                 ENUM('developer','director','user') NOT NULL DEFAULT 'user',
    status               ENUM('active','inactive') NOT NULL DEFAULT 'active',
    timezone             VARCHAR(64)  NOT NULL DEFAULT 'Asia/Dubai',
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
    session_version      INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at        DATETIME NULL,
    password_changed_at  DATETIME NULL,
    created_by           INT UNSIGNED NULL,
    created_at           DATETIME NOT NULL,
    updated_at           DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE websites (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                  VARCHAR(120) NOT NULL,
    domain                VARCHAR(190) NOT NULL,
    platform              ENUM('php','laravel','codeigniter','nextjs','wordpress','shopify') NOT NULL,
    status                ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    business_contact      VARCHAR(120) NULL,
    business_email        VARCHAR(190) NULL,
    notes                 TEXT NULL,
    rate_limit_per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    last_submission_at    DATETIME NULL,
    archived_at           DATETIME NULL,
    created_by            INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL,
    updated_at            DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_websites_domain (domain),
    KEY idx_websites_status_name (status, name),
    CONSTRAINT fk_websites_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which websites a User (role = user) may read. Directors and Developers read all websites.
CREATE TABLE website_user (
    user_id     INT UNSIGNED NOT NULL,
    website_id  INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, website_id),
    KEY idx_website_user_website (website_id),
    CONSTRAINT fk_website_user_user    FOREIGN KEY (user_id)     REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_website_user_website FOREIGN KEY (website_id)  REFERENCES websites (id) ON DELETE CASCADE,
    CONSTRAINT fk_website_user_by      FOREIGN KEY (assigned_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Site-scoped bearer keys. Token format: aiq_<key_id>_<secret>. Only SHA-256(secret) is stored.
CREATE TABLE api_credentials (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    website_id   INT UNSIGNED NOT NULL,
    key_id       CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    secret_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_four    CHAR(4)  NOT NULL,
    label        VARCHAR(80) NULL,
    status       ENUM('active','revoked') NOT NULL DEFAULT 'active',
    expires_at   DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL,
    revoked_at   DATETIME NULL,
    revoked_by   INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_credentials_key_id (key_id),
    KEY idx_api_credentials_website (website_id, status),
    CONSTRAINT fk_api_credentials_website FOREIGN KEY (website_id) REFERENCES websites (id) ON DELETE CASCADE,
    CONSTRAINT fk_api_credentials_created FOREIGN KEY (created_by) REFERENCES users (id)    ON DELETE SET NULL,
    CONSTRAINT fk_api_credentials_revoked FOREIGN KEY (revoked_by) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Form identifiers are registered automatically on first submission, so a new form needs no schema change.
CREATE TABLE forms (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    website_id    INT UNSIGNED NOT NULL,
    form_key      VARCHAR(100) NOT NULL,
    form_type     ENUM('contact','quote','sample','other') NOT NULL,
    label         VARCHAR(150) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forms_website_key (website_id, form_key),
    CONSTRAINT fk_forms_website FOREIGN KEY (website_id) REFERENCES websites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE submissions (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    website_id             INT UNSIGNED NOT NULL,
    form_id                INT UNSIGNED NULL,
    credential_id          INT UNSIGNED NULL,
    -- API retry handling (new): sender's stable ID + hash of the original JSON body.
    submission_uid         VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    form_type              ENUM('contact','quote','sample','other') NOT NULL,
    -- Timing: receipt time is always set by this server; the source timestamp is optional.
    received_at            DATETIME NOT NULL,
    source_created_at      DATETIME NULL,
    locale                 VARCHAR(16)  NOT NULL DEFAULT 'en',
    -- Contact columns (typed, searchable). Phone and postcode are strings by design.
    full_name              VARCHAR(190) NOT NULL DEFAULT '',
    company                VARCHAR(190) NOT NULL DEFAULT '',
    email                  VARCHAR(190) NOT NULL DEFAULT '',
    phone                  VARCHAR(50)  NOT NULL DEFAULT '',
    country                VARCHAR(100) NOT NULL DEFAULT '',
    address                VARCHAR(500) NOT NULL DEFAULT '',
    city                   VARCHAR(120) NOT NULL DEFAULT '',
    postcode               VARCHAR(32)  NOT NULL DEFAULT '',
    message                TEXT NOT NULL,
    -- Form-specific data, validated by the application before storage.
    products               JSON NULL,
    product_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    custom_fields          JSON NULL,
    -- Source-side metadata carried over from the originating site (SQLite: ip, dedupe_key, email_*).
    source_ip              VARCHAR(45)  NOT NULL DEFAULT '',
    source_page_url        VARCHAR(2048) NULL,
    source_dedupe_key      VARCHAR(191) NULL,
    source_email_sent      TINYINT(1) NULL,
    source_email_sent_at   DATETIME NULL,
    source_email_attempts  SMALLINT UNSIGNED NULL,
    source_email_error     VARCHAR(1000) NULL,
    is_test                TINYINT(1) NOT NULL DEFAULT 0,
    -- Business workflow status (independent of delivery and retry metadata).
    status                 ENUM('new','open','closed','spam') NOT NULL DEFAULT 'new',
    status_changed_at      DATETIME NULL,
    status_changed_by      INT UNSIGNED NULL,
    sender_ip              VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_submissions_website_uid (website_id, submission_uid),
    KEY idx_submissions_received (received_at),
    KEY idx_submissions_website_received (website_id, received_at),
    KEY idx_submissions_type_received (form_type, received_at),
    KEY idx_submissions_website_type_received (website_id, form_type, received_at),
    KEY idx_submissions_status_received (status, received_at),
    KEY idx_submissions_email (email),
    KEY idx_submissions_source_dedupe (website_id, source_dedupe_key),
    CONSTRAINT fk_submissions_website    FOREIGN KEY (website_id)        REFERENCES websites (id)        ON DELETE RESTRICT,
    CONSTRAINT fk_submissions_form       FOREIGN KEY (form_id)           REFERENCES forms (id)           ON DELETE SET NULL,
    CONSTRAINT fk_submissions_credential FOREIGN KEY (credential_id)     REFERENCES api_credentials (id) ON DELETE SET NULL,
    CONSTRAINT fk_submissions_status_by  FOREIGN KEY (status_changed_by) REFERENCES users (id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Business audit log (readable by Developers and Directors). Never stores passwords, secrets or inquiry bodies.
CREATE TABLE audit_log (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at   DATETIME NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_label   VARCHAR(190) NOT NULL DEFAULT '',
    action        VARCHAR(64)  NOT NULL,
    target_type   VARCHAR(32)  NOT NULL DEFAULT '',
    target_id     VARCHAR(64)  NULL,
    target_label  VARCHAR(190) NOT NULL DEFAULT '',
    outcome       ENUM('success','denied','failure') NOT NULL,
    ip            VARCHAR(45)  NOT NULL DEFAULT '',
    details       JSON NULL,
    PRIMARY KEY (id),
    KEY idx_audit_occurred (occurred_at),
    KEY idx_audit_actor (actor_user_id, occurred_at),
    KEY idx_audit_action (action, occurred_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Technical connection diagnostics (Developers only). Metadata only, never request bodies or keys.
CREATE TABLE api_request_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at    DATETIME NOT NULL,
    website_id     INT UNSIGNED NULL,
    credential_id  INT UNSIGNED NULL,
    key_id         CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
    http_status    SMALLINT UNSIGNED NOT NULL,
    result_code    VARCHAR(40) NOT NULL,
    submission_uid VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    submission_id  BIGINT UNSIGNED NULL,
    ip             VARCHAR(45) NOT NULL DEFAULT '',
    user_agent     VARCHAR(255) NULL,
    payload_bytes  INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms    INT UNSIGNED NOT NULL DEFAULT 0,
    error_fields   VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_api_log_website (website_id, occurred_at),
    KEY idx_api_log_occurred (occurred_at),
    CONSTRAINT fk_api_log_website    FOREIGN KEY (website_id)    REFERENCES websites (id)        ON DELETE SET NULL,
    CONSTRAINT fk_api_log_credential FOREIGN KEY (credential_id) REFERENCES api_credentials (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window counters for login and API throttling (works on shared hosting without Redis).
CREATE TABLE rate_limits (
    bucket       CHAR(64) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits         INT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
