-- =============================================================================
-- CreatePosts — clean database schema for a fresh install
-- =============================================================================
-- This mirrors what functions.php bootstraps automatically at runtime
-- (getDb() + the various ensure*Table() helpers), with all incremental
-- ALTER TABLE ... ADD COLUMN changes folded into the base CREATE TABLE
-- statements. You do NOT need to run this by hand — visiting the app once
-- against an empty database (DB_NAME in config.local.php) creates everything
-- automatically. This file exists for a one-shot manual install, backups,
-- or documentation. Keep it in sync if the schema in functions.php changes.
--
-- To import by hand, select the target database first (e.g. in phpMyAdmin)
-- and import this file into it — there is no CREATE DATABASE / USE here,
-- because shared hosting (cPanel) prefixes database names and doesn't allow
-- creating databases from SQL.
--
-- After importing, register a user through the app (the Register page) — the
-- first account created is automatically promoted to role = 'admin'.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- users — accounts. role = 'admin' or 'editor'; editors are scoped to the
-- admin that created them via created_by (enforced in app code, no FK so an
-- admin can be deleted without being blocked by their former editors).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password        VARCHAR(255) NOT NULL,
    openai_api_key  VARCHAR(255) NULL,
    openai_model    VARCHAR(100) NULL,
    role            VARCHAR(20) NOT NULL DEFAULT 'editor',
    created_by      INT NULL,
    publer_api_key  VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- wordpress_sites — WordPress sites connected by an admin (via Application
-- Passwords), used for publishing and analytics.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wordpress_sites (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    user_id                INT NOT NULL,
    site_name              VARCHAR(100) NOT NULL,
    site_url               VARCHAR(255) NOT NULL,
    rest_style             VARCHAR(10) NOT NULL DEFAULT 'pretty',
    username               VARCHAR(100) NOT NULL,
    application_password   VARCHAR(255) NOT NULL,
    ga_property_id         VARCHAR(32) NULL,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- published_posts — log of posts this app published to WordPress sites.
-- user_id is the owning admin; posted_by is whoever actually clicked publish
-- (may be an editor).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS published_posts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    site_id             INT NULL,
    title               VARCHAR(255) NOT NULL,
    remote_post_id      INT NOT NULL,
    status              VARCHAR(20) NOT NULL,
    posted_by           INT NULL,
    site_name_snapshot  VARCHAR(100) NULL,
    site_url_snapshot   VARCHAR(255) NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pp_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- post_categories — WordPress categories, either global (site_id NULL) or
-- scoped to one site; one can be marked default.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    site_id     INT NULL,
    is_default  TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cat_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- publer_page_sites — maps a Publer Facebook page to a WordPress site, so the
-- page's first comment can link to that site's latest app-published post.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS publer_page_sites (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    publer_page_id  VARCHAR(64) NOT NULL,
    site_id         INT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_page (user_id, publer_page_id),
    CONSTRAINT fk_pps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pps_site FOREIGN KEY (site_id) REFERENCES wordpress_sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- openai_usage — self-tracked token/cost log from paraphrase calls, used to
-- estimate OpenAI spend on the System page.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS openai_usage (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    model               VARCHAR(100) NOT NULL,
    prompt_tokens       INT NOT NULL DEFAULT 0,
    completion_tokens   INT NOT NULL DEFAULT 0,
    cost_usd            DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_openai_user (user_id)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- app_settings — flexible per-user key/value store (System page defaults,
-- GA measurement id, timeslot cursors per workspace, active Publer workspace,
-- OpenAI pricing overrides, etc).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    setting_key     VARCHAR(64) NOT NULL,
    setting_value   TEXT NULL,
    UNIQUE KEY uniq_user_setting (user_id, setting_key),
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- publer_timeslots — an admin's recurring posting times for "Use Timeslot".
-- workspace_id NULL = applies to all of the admin's Publer workspaces;
-- days is a comma-separated list of 0(Sun)..6(Sat), '' = every day.
-- Times are Europe/Berlin wall-clock time.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS publer_timeslots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    workspace_id    VARCHAR(64) NULL,
    slot_time       VARCHAR(5) NOT NULL,
    days            VARCHAR(20) NOT NULL DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- publish_jobs — background queue for "Publish to Websites". One row per submit;
-- the worker (publish_worker.php, via cron or an HTTP kick) publishes to each
-- site in site_ids, updating done/results as it goes. state: queued|processing|
-- done|error. featured_image_path points at a persisted upload under uploads/jobs.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS publish_jobs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    owner_id            INT NOT NULL,
    title               VARCHAR(512) NOT NULL,
    content             MEDIUMTEXT NOT NULL,
    status              VARCHAR(20) NOT NULL DEFAULT 'publish',
    category_id         INT NOT NULL DEFAULT 0,
    paraphrase          TINYINT(1) NOT NULL DEFAULT 0,
    paraphrase_tone     VARCHAR(32) NOT NULL DEFAULT 'neutral',
    featured_image_path VARCHAR(512) NULL,
    featured_image_name VARCHAR(255) NULL,
    site_ids            TEXT NOT NULL,
    total               INT NOT NULL DEFAULT 0,
    done                INT NOT NULL DEFAULT 0,
    state               VARCHAR(20) NOT NULL DEFAULT 'queued',
    results             MEDIUMTEXT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at          DATETIME NULL,
    finished_at         DATETIME NULL,
    CONSTRAINT fk_pj_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
