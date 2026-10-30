-- ============================================================================
--  CureMeds — schema
--  Health products and supplements sold through WhatsApp enquiries, with a
--  patient portal (phone + PIN) for medication reminders and side-effect reports.
--  The health-tracker tables are in health.sql, which is appended below.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── users ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `email`           VARCHAR(180) DEFAULT NULL UNIQUE,  -- optional for patients; reminders need it
  `password`        VARCHAR(255) DEFAULT NULL,         -- staff sign in with email + password
  `role`            ENUM('customer','reseller','editor','admin') NOT NULL DEFAULT 'customer',
  `phone`           VARCHAR(20)  DEFAULT NULL UNIQUE,  -- digits only (27XXXXXXXXX): the patient sign-in
  -- Patient portal: phone + PIN
  `pin_hash`        VARCHAR(255) DEFAULT NULL,
  `pin_failed`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `pin_locked_until` DATETIME DEFAULT NULL,
  `date_of_birth`   DATE DEFAULT NULL,
  `gender`          VARCHAR(20) DEFAULT NULL,
  `conditions`      TEXT DEFAULT NULL,        -- what the patient says they are managing
  `allergies`       TEXT DEFAULT NULL,
  `doctor_name`     VARCHAR(120) DEFAULT NULL,
  `emergency_name`  VARCHAR(120) DEFAULT NULL,
  `emergency_phone` VARCHAR(20)  DEFAULT NULL,
  `reminders_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `patient_notes`   TEXT DEFAULT NULL,        -- staff only, never shown to the patient
  `last_login_at`   DATETIME DEFAULT NULL,
  `avatar`          VARCHAR(500) DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  -- Reseller programme
  `reseller_status` ENUM('none','pending','approved','suspended') NOT NULL DEFAULT 'none',
  `reseller_tier`   ENUM('starter','bronze','silver','gold') NOT NULL DEFAULT 'starter',
  `reseller_code`   VARCHAR(16)  DEFAULT NULL UNIQUE,
  `business_name`   VARCHAR(160) DEFAULT NULL,
  `id_number`       VARCHAR(20)  DEFAULT NULL,
  `trading_area`    VARCHAR(120) DEFAULT NULL,
  `sponsor_id`      INT UNSIGNED DEFAULT NULL,
  `applied_at`      DATETIME DEFAULT NULL,
  `approved_at`     DATETIME DEFAULT NULL,
  -- Password reset
  `reset_token`     VARCHAR(64) DEFAULT NULL,
  `reset_expires`   DATETIME DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_phone` (`phone`),
  KEY `idx_role` (`role`),
  KEY `idx_reseller` (`reseller_status`),
  KEY `idx_sponsor` (`sponsor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── brands (the suppliers whose price lists we carry) ────────────────────
CREATE TABLE IF NOT EXISTS `brands` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `slug`       VARCHAR(140) NOT NULL UNIQUE,
  `tagline`    VARCHAR(200) DEFAULT NULL,
  `blurb`      TEXT DEFAULT NULL,
  `logo`       VARCHAR(500) DEFAULT NULL,
  `accent`     VARCHAR(10)  NOT NULL DEFAULT '#1557D0',
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── categories ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `slug`       VARCHAR(140) NOT NULL UNIQUE,
  `parent_id`  INT UNSIGNED DEFAULT NULL,
  `icon`       VARCHAR(60)  DEFAULT NULL,
  `blurb`      VARCHAR(255) DEFAULT NULL,
  `image`      VARCHAR(500) DEFAULT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── products ─────────────────────────────────────────────────────────────
-- `retail_price`   what a walk-up customer pays.
-- `reseller_price` what an approved reseller pays. NULL = fall back to the
--                  tier discount off retail (see RESELLER_TIERS in constants).
-- `rrp`            the price the supplier recommends the reseller sells at;
--                  drives the "you make Rx per unit" figure.
CREATE TABLE IF NOT EXISTS `products` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`    INT UNSIGNED DEFAULT NULL,
  `brand_id`       INT UNSIGNED DEFAULT NULL,
  `name`           VARCHAR(200) NOT NULL,
  `slug`           VARCHAR(220) NOT NULL UNIQUE,
  `short_desc`     TEXT DEFAULT NULL,
  `description`    LONGTEXT DEFAULT NULL,
  `usage_notes`    TEXT DEFAULT NULL,
  `type`           ENUM('simple','variable') NOT NULL DEFAULT 'simple',
  `retail_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price`     DECIMAL(10,2) DEFAULT NULL,
  `reseller_price` DECIMAL(10,2) DEFAULT NULL,
  `rrp`            DECIMAL(10,2) DEFAULT NULL,
  `pack_size`      VARCHAR(60)  DEFAULT NULL,
  `sku`            VARCHAR(80)  DEFAULT NULL UNIQUE,
  `stock_status`   ENUM('in_stock','low_stock','out_of_stock','on_backorder') NOT NULL DEFAULT 'in_stock',
  `stock_qty`      INT NOT NULL DEFAULT 0,
  `min_reseller_qty` INT NOT NULL DEFAULT 6,
  `featured_image` VARCHAR(500) DEFAULT NULL,
  `is_featured`    TINYINT(1) NOT NULL DEFAULT 0,
  `is_sale`        TINYINT(1) NOT NULL DEFAULT 0,
  `is_bestseller`  TINYINT(1) NOT NULL DEFAULT 0,
  `is_new`         TINYINT(1) NOT NULL DEFAULT 0,
  `status`         ENUM('published','draft','archived') NOT NULL DEFAULT 'published',
  `tags`           VARCHAR(500) DEFAULT NULL,
  `benefits`       TEXT DEFAULT NULL,   -- one bullet per line
  `view_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `sold_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `meta_title`     VARCHAR(200) DEFAULT NULL,
  `meta_desc`      VARCHAR(320) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`),
  KEY `idx_category` (`category_id`),
  KEY `idx_brand` (`brand_id`),
  KEY `idx_featured` (`is_featured`),
  KEY `idx_status` (`status`),
  KEY `idx_stock` (`stock_status`),
  FULLTEXT KEY `ft_search` (`name`,`short_desc`,`tags`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── product_variants (flavour / scent / size options) ────────────────────
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`       INT UNSIGNED NOT NULL,
  `option_label`     VARCHAR(60) NOT NULL DEFAULT 'Option',
  `option_value`     VARCHAR(80) NOT NULL,
  `swatch_hex`       VARCHAR(10)  DEFAULT NULL,
  `image`            VARCHAR(500) DEFAULT NULL,
  `additional_price` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `stock_qty`        INT NOT NULL DEFAULT 0,
  `sku`              VARCHAR(80) DEFAULT NULL,
  `sort_order`       INT NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_variant_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── product_gallery ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_gallery` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `image`      VARCHAR(500) NOT NULL,
  `caption`    VARCHAR(200) DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_gallery_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── orders ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `orders` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number`     VARCHAR(40) NOT NULL UNIQUE,
  `user_id`          INT UNSIGNED DEFAULT NULL,
  `order_type`       ENUM('retail','reseller') NOT NULL DEFAULT 'retail',
  `customer_name`    VARCHAR(120) DEFAULT NULL,
  `customer_email`   VARCHAR(180) DEFAULT NULL,
  `customer_phone`   VARCHAR(20)  DEFAULT NULL,
  `subtotal`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `shipping_amount`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `est_resale_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00, -- reseller orders: what the line-up is worth at RRP
  `coupon_code`      VARCHAR(60) DEFAULT NULL,
  `status`           ENUM('pending','confirmed','packing','ready','shipped','delivered','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method`   ENUM('eft','payfast','cash') NOT NULL DEFAULT 'eft',
  `payment_status`   ENUM('unpaid','paid','refunded','failed') NOT NULL DEFAULT 'unpaid',
  `fulfilment`       ENUM('delivery','collection','runner') NOT NULL DEFAULT 'delivery',
  `pickup_point_id`  INT UNSIGNED DEFAULT NULL,
  `shipping_address` TEXT DEFAULT NULL,
  `pop_path`         VARCHAR(255) DEFAULT NULL,
  `notes`            TEXT DEFAULT NULL,
  `admin_notes`      TEXT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── order_items ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `product_id`   INT UNSIGNED DEFAULT NULL,
  `variant_id`   INT UNSIGNED DEFAULT NULL,
  `name`         VARCHAR(220) NOT NULL,
  `variant_text` VARCHAR(120) DEFAULT NULL,
  `sku`          VARCHAR(80) DEFAULT NULL,
  `qty`          INT NOT NULL DEFAULT 1,
  `unit_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `unit_rrp`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `image`        VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── order_events (the timeline shown to the customer) ────────────────────
CREATE TABLE IF NOT EXISTS `order_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `status`     VARCHAR(30) NOT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `fk_event_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── pickup_points (depots + Runner handover points) ──────────────────────
CREATE TABLE IF NOT EXISTS `pickup_points` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(140) NOT NULL,
  `address`    VARCHAR(255) NOT NULL,
  `city`       VARCHAR(80)  DEFAULT NULL,
  `province`   VARCHAR(60)  DEFAULT NULL,
  `hours`      VARCHAR(140) DEFAULT NULL,
  `fee`        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── reviews ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`  INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `rating`      TINYINT NOT NULL DEFAULT 5,
  `title`       VARCHAR(140) DEFAULT NULL,
  `comment`     TEXT DEFAULT NULL,
  `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_product` (`user_id`,`product_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_approved` (`is_approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── wishlist ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wishlist` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `added_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_product` (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── stock_alerts (notify me when back in stock) ──────────────────────────
CREATE TABLE IF NOT EXISTS `stock_alerts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `email`      VARCHAR(180) NOT NULL,
  `notified`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_email` (`product_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── coupons ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(60) NOT NULL UNIQUE,
  `description`    VARCHAR(200) DEFAULT NULL,
  `discount_type`  ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `discount_value` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `min_order`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `applies_to`     ENUM('all','retail','reseller') NOT NULL DEFAULT 'all',
  `max_uses`       INT DEFAULT NULL,
  `used_count`     INT NOT NULL DEFAULT 0,
  `expiry_date`    DATE DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── blogs (the Learn hub) ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `blogs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(250) NOT NULL,
  `slug`        VARCHAR(270) NOT NULL UNIQUE,
  `image`       VARCHAR(500) DEFAULT NULL,
  `excerpt`     TEXT DEFAULT NULL,
  `content`     LONGTEXT DEFAULT NULL,
  `category`    VARCHAR(60) NOT NULL DEFAULT 'General',
  `author_id`   INT UNSIGNED NOT NULL,
  `read_mins`   TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `status`      ENUM('published','draft') NOT NULL DEFAULT 'published',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_slug` (`slug`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ── testimonials ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `role`       VARCHAR(120) DEFAULT NULL,
  `location`   VARCHAR(120) DEFAULT NULL,
  `quote`      TEXT NOT NULL,
  `rating`     TINYINT NOT NULL DEFAULT 5,
  `avatar`     VARCHAR(500) DEFAULT NULL,
  `is_reseller` TINYINT(1) NOT NULL DEFAULT 0,
  `status`     ENUM('published','draft') NOT NULL DEFAULT 'published',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── faqs ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `faqs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `question`   VARCHAR(255) NOT NULL,
  `answer`     TEXT NOT NULL,
  `topic`      VARCHAR(60) NOT NULL DEFAULT 'Ordering',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status`     ENUM('published','draft') NOT NULL DEFAULT 'published',
  PRIMARY KEY (`id`),
  KEY `idx_topic` (`topic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── contact_messages ─────────────────────────────────────────────────────
-- Saved BEFORE email is attempted, so an SMTP outage never loses an enquiry.
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(180) NOT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `subject`    VARCHAR(200) DEFAULT NULL,
  `message`    TEXT NOT NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── settings (key/value) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(80) NOT NULL,
  `value`    TEXT DEFAULT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── newsletter ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(180) NOT NULL UNIQUE,
  `name`            VARCHAR(120) DEFAULT NULL,
  `audience`        ENUM('all','retail','reseller') NOT NULL DEFAULT 'all',
  `status`          ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
  `token`           VARCHAR(64) NOT NULL,
  `source`          VARCHAR(40) NOT NULL DEFAULT 'footer',
  `subscribed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` DATETIME DEFAULT NULL,
  `consent_ip`      VARCHAR(45) DEFAULT NULL,
  `consent_text`    VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletter_campaigns` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject`    VARCHAR(255) NOT NULL,
  `body_html`  LONGTEXT NOT NULL,
  `audience`   ENUM('all','retail','reseller') NOT NULL DEFAULT 'all',
  `status`     ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
  `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_at`    DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `template`      VARCHAR(30) NOT NULL DEFAULT 'custom',
  `template_data` LONGTEXT DEFAULT NULL,
  `preheader`     VARCHAR(200) DEFAULT NULL,
  `total_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per recipient per campaign; sending works through this queue in
-- batches. (NewsletterService::ensureSchema() creates all of this on an
-- existing database too, so upgrades need no manual import.)
CREATE TABLE IF NOT EXISTS `newsletter_sends` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`   INT UNSIGNED NOT NULL,
  `subscriber_id` INT UNSIGNED NOT NULL,
  `email`         VARCHAR(180) NOT NULL,
  `status`        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `error`         VARCHAR(255) DEFAULT NULL,
  `sent_at`       DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_sub` (`campaign_id`, `subscriber_id`),
  KEY `idx_campaign_status` (`campaign_id`, `status`)
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

-- ── offline_sales: sold outside the website (counter, events, cash/card) ──
CREATE TABLE IF NOT EXISTS `offline_sales` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sold_on`        DATE NOT NULL,
  `customer_name`  VARCHAR(120) DEFAULT NULL,
  `customer_phone` VARCHAR(20)  DEFAULT NULL,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `payment_method` VARCHAR(20)  NOT NULL DEFAULT 'cash',
  `channel`        VARCHAR(20)  NOT NULL DEFAULT 'counter',
  `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `notes`          VARCHAR(1000) DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sold_on` (`sold_on`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offline_sale_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`    INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `name`       VARCHAR(220) NOT NULL,
  `pack_size`  VARCHAR(60) DEFAULT NULL,
  `qty`        INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `fk_osi_sale` FOREIGN KEY (`sale_id`) REFERENCES `offline_sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
