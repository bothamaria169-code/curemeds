-- ============================================================================
--  CureMeds — patient health tracker, enquiries and social posting.
--  Part of database.sql; kept as its own file too so an existing database can
--  be upgraded by importing just this (every statement is IF NOT EXISTS).
-- ============================================================================
SET NAMES utf8mb4;

-- ── banners: the home carousel and shop promo tiles (Admin → Banners) ───
CREATE TABLE IF NOT EXISTS `banners` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(160) NOT NULL,
  `image`         VARCHAR(500) NOT NULL,
  `alt_text`      VARCHAR(200) DEFAULT NULL,
  `link_url`      VARCHAR(255) DEFAULT NULL,
  `aspect`        ENUM('wide','tall') NOT NULL DEFAULT 'wide',
  `show_home`     TINYINT(1) NOT NULL DEFAULT 1,
  `show_shop`     TINYINT(1) NOT NULL DEFAULT 0,
  `show_reseller` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order`    INT NOT NULL DEFAULT 0,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_aspect` (`aspect`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── patient_medications: what each patient takes, and when ───────────────
-- Usually added by staff after a WhatsApp sale; patients can add their own too.
-- `dose_times` is a comma-separated list of 24h times, e.g. "08:00,20:00".
CREATE TABLE IF NOT EXISTS `patient_medications` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `product_id`      INT UNSIGNED DEFAULT NULL,
  `name`            VARCHAR(200) NOT NULL,
  `dosage`          VARCHAR(120) DEFAULT NULL,
  `instructions`    VARCHAR(255) DEFAULT NULL,
  `dose_times`      VARCHAR(120) NOT NULL DEFAULT '08:00',
  `start_date`      DATE NOT NULL,
  `end_date`        DATE DEFAULT NULL,
  `units_left`      INT DEFAULT NULL,
  `units_per_dose`  DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `refill_notified_at` DATETIME DEFAULT NULL,
  `reminders`       TINYINT(1) NOT NULL DEFAULT 1,
  `status`          ENUM('active','paused','completed') NOT NULL DEFAULT 'active',
  `added_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_med_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── dose_logs: one row per scheduled dose the patient answered ───────────
CREATE TABLE IF NOT EXISTS `dose_logs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medication_id` INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `scheduled_for` DATETIME NOT NULL,
  `status`        ENUM('taken','skipped') NOT NULL DEFAULT 'taken',
  `source`        ENUM('portal','email','staff') NOT NULL DEFAULT 'portal',
  `logged_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dose` (`medication_id`, `scheduled_for`),
  KEY `idx_user_time` (`user_id`, `scheduled_for`),
  CONSTRAINT `fk_dose_med` FOREIGN KEY (`medication_id`) REFERENCES `patient_medications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── reminder_log: guarantees each dose is emailed at most once ───────────
CREATE TABLE IF NOT EXISTS `reminder_log` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `medication_id` INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `kind`          ENUM('dose','refill') NOT NULL DEFAULT 'dose',
  `scheduled_for` DATETIME NOT NULL,
  `status`        ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
  `note`          VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder` (`medication_id`, `kind`, `scheduled_for`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_rem_med` FOREIGN KEY (`medication_id`) REFERENCES `patient_medications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── side_effects: what patients report, and how staff responded ──────────
CREATE TABLE IF NOT EXISTS `side_effects` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `medication_id` INT UNSIGNED DEFAULT NULL,
  `symptom`       VARCHAR(160) NOT NULL,
  `severity`      ENUM('mild','moderate','severe') NOT NULL DEFAULT 'mild',
  `started_on`    DATE DEFAULT NULL,
  `details`       TEXT DEFAULT NULL,
  `status`        ENUM('new','reviewed','resolved') NOT NULL DEFAULT 'new',
  `staff_reply`   TEXT DEFAULT NULL,
  `reviewed_by`   INT UNSIGNED DEFAULT NULL,
  `reviewed_at`   DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`, `severity`),
  CONSTRAINT `fk_se_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── health_readings: the patient's own daily check-ins ───────────────────
CREATE TABLE IF NOT EXISTS `health_readings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `recorded_on` DATE NOT NULL,
  `weight_kg`   DECIMAL(5,1) DEFAULT NULL,
  `systolic`    SMALLINT UNSIGNED DEFAULT NULL,
  `diastolic`   SMALLINT UNSIGNED DEFAULT NULL,
  `glucose`     DECIMAL(4,1) DEFAULT NULL,
  `mood`        TINYINT UNSIGNED DEFAULT NULL,
  `energy`      TINYINT UNSIGNED DEFAULT NULL,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_day` (`user_id`, `recorded_on`),
  CONSTRAINT `fk_hr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── enquiries: every "Enquire on WhatsApp" press, so no lead is lost ─────
CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `name`        VARCHAR(120) DEFAULT NULL,
  `phone`       VARCHAR(20)  DEFAULT NULL,
  `items`       TEXT NOT NULL,
  `total`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `source`      VARCHAR(30) NOT NULL DEFAULT 'product',
  `status`      ENUM('new','contacted','sold','closed') NOT NULL DEFAULT 'new',
  `admin_notes` TEXT DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── social_posts: what went to Facebook / Instagram ──────────────────────
CREATE TABLE IF NOT EXISTS `social_posts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform`   VARCHAR(20) NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `status`     ENUM('posted','failed') NOT NULL,
  `remote_id`  VARCHAR(120) DEFAULT NULL,
  `error`      VARCHAR(255) DEFAULT NULL,
  `caption`    TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
