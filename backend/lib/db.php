<?php


function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $dbDsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), '[1049]') === false) { // 1049 = unknown database
                throw $e;
            }
            $server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
            $server->exec('CREATE DATABASE IF NOT EXISTS ' . DB_NAME);
            $pdo = new PDO($dbDsn, DB_USER, DB_PASS, $options);
        }

        // The schema migrations are ~20 CREATE/ALTER statements. Running them
        // on every API request added latency to every call (and DDL can block
        // on metadata locks behind a busy background worker), so run them once
        // and only again after this file changes (i.e. a new migration).
        $marker  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'createposts_schema_' . md5(DB_HOST . '|' . DB_NAME . '|' . __FILE__);
        $version = (string) @filemtime(__FILE__);
        if (@file_get_contents($marker) !== $version) {
            runSchemaMigrations($pdo);
            @file_put_contents($marker, $version, LOCK_EX);
        }
    }

    return $pdo;
}


function runSchemaMigrations(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            openai_api_key VARCHAR(255) NULL,
            openai_model VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS openai_api_key VARCHAR(255) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS openai_model VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'editor'");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by INT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS publer_api_key VARCHAR(255) NULL");
    // Self-registered (public Register page) accounts start 'pending' until
    // an admin approves them; admin-created accounts are 'active' immediately.
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active'");

    // Make sure at least one admin exists (promote the earliest account).
    // Also force-approve them: if they're the very first user ever, no
    // other admin exists to approve them, so requiring approval here
    // would permanently lock everyone out of a fresh install.
    if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0) {
        $pdo->exec("UPDATE users SET role = 'admin', status = 'active' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) AS t)");
    }

    // Assign any editor without a creator to the earliest admin, so they
    // inherit that admin's websites and settings.
    $pdo->exec(
        "UPDATE users SET created_by = (SELECT id FROM (SELECT MIN(id) AS id FROM users WHERE role = 'admin') AS a)
         WHERE role <> 'admin' AND (created_by IS NULL OR created_by = 0)"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wordpress_sites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            site_name VARCHAR(100) NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            username VARCHAR(100) NOT NULL,
            application_password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_wp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $pdo->exec("ALTER TABLE wordpress_sites ADD COLUMN IF NOT EXISTS ga_property_id VARCHAR(32) NULL");

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS published_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            site_id INT NULL,
            title VARCHAR(255) NOT NULL,
            remote_post_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_pp_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE SET NULL
        ) ENGINE=InnoDB"
    );

    // Who actually clicked publish (may be an editor; user_id stays the owner admin).
    $pdo->exec("ALTER TABLE published_posts ADD COLUMN IF NOT EXISTS posted_by INT NULL");

    // Snapshot of the site's name/url at publish time, so history stays
    // readable even after the site is later removed (see fk_pp_site below).
    $pdo->exec("ALTER TABLE published_posts ADD COLUMN IF NOT EXISTS site_name_snapshot VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE published_posts ADD COLUMN IF NOT EXISTS site_url_snapshot VARCHAR(255) NULL");

    // Pre-existing installs had site_id NOT NULL with ON DELETE CASCADE,
    // which silently deleted a user's entire publish history whenever a
    // site was removed (e.g. deleted and re-added under a new id). Loosen
    // it to SET NULL so history survives. Checked via a
    // cheap information_schema lookup so the ALTERs below only actually
    // run once, the first time this deploys.
    $siteIdNullable = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'published_posts' AND COLUMN_NAME = 'site_id'"
    )->fetchColumn();
    if ($siteIdNullable === 'NO') {
        try {
            $pdo->exec("ALTER TABLE published_posts DROP FOREIGN KEY fk_pp_site");
        } catch (\Throwable $e) {
            // Constraint already gone/renamed — fall through to re-add it below.
        }
        $pdo->exec("ALTER TABLE published_posts MODIFY site_id INT NULL");
        $pdo->exec("ALTER TABLE published_posts ADD CONSTRAINT fk_pp_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE SET NULL");
    }

    // Indexes behind the dashboard statistics (one owner's rows within a
    // date range). The job tables may not exist yet on a brand-new install —
    // their own ensure*() creates them later — so each is best-effort.
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_pp_owner_created ON published_posts (user_id, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_pp_owner_poster ON published_posts (user_id, posted_by, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_pj_owner_created ON publish_jobs (owner_id, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_sj_owner_created ON schedule_jobs (owner_id, created_at)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // table not created yet
        }
    }
}


function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}


function getFlash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }

    return null;
}


// ---------------------------------------------------------------------------
// Per-user app settings (System page) — flexible key/value store
// ---------------------------------------------------------------------------
function ensureSettingsTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    getDb()->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            setting_key VARCHAR(64) NOT NULL,
            setting_value TEXT NULL,
            UNIQUE KEY uniq_user_setting (user_id, setting_key),
            CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
    $ensured = true;
}


function getSetting(int $userId, string $key, ?string $default = null): ?string
{
    ensureSettingsTable();
    $stmt = getDb()->prepare('SELECT setting_value FROM app_settings WHERE user_id = :u AND setting_key = :k');
    $stmt->execute(['u' => $userId, 'k' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}


function setSetting(int $userId, string $key, ?string $value): void
{
    ensureSettingsTable();
    $stmt = getDb()->prepare(
        'INSERT INTO app_settings (user_id, setting_key, setting_value)
         VALUES (:u, :k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['u' => $userId, 'k' => $key, 'v' => $value]);
}


/** Remove a setting entirely, so getSetting() falls back to its default again. */
function deleteSetting(int $userId, string $key): void
{
    ensureSettingsTable();
    getDb()->prepare('DELETE FROM app_settings WHERE user_id = :u AND setting_key = :k')
        ->execute(['u' => $userId, 'k' => $key]);
}
