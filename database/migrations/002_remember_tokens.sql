-- "Remember me" sign-in tokens. Only SHA-256 of the validator is stored.
-- A token is valid only while users.session_version still equals the stored value.

CREATE TABLE remember_tokens (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    selector        CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    validator_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_version INT UNSIGNED NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL,
    last_used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    KEY idx_remember_expires (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
