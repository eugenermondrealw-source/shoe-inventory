-- Run this once in your `shoe_inventory` database (phpMyAdmin > SQL tab, or the mysql CLI).
-- Your existing `shoes` and `shoe_sizes` tables are not touched.

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(30)      NOT NULL UNIQUE,
    display_name    VARCHAR(80)      NOT NULL,
    password_hash   VARCHAR(255)     NOT NULL,
    role            ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    failed_attempts INT UNSIGNED     NOT NULL DEFAULT 0,
    locked_until    BIGINT UNSIGNED  NULL,
    last_login      BIGINT UNSIGNED  NULL,
    created_at      BIGINT UNSIGNED  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No default account is created. The first time you open login.php with an empty
-- users table, it shows a one-time "create the first admin" form.
