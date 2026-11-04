-- سیستم یکپارچه ۲.۰.۰: جدول‌هایی که CRM استفاده می‌کند ولی در db.sql نبودند (روی سرور قبلی دستی ساخته شده بودند)
CREATE TABLE IF NOT EXISTS `call_numbers` (
    `phone10`          CHAR(10)     NOT NULL,
    `phone`            VARCHAR(20)  DEFAULT NULL,
    `name`             VARCHAR(150) DEFAULT NULL,
    `name_source`      VARCHAR(20)  DEFAULT NULL,
    `stage`            VARCHAR(30)  DEFAULT NULL,
    `stage_since`      DATETIME     DEFAULT NULL,
    `cycle_started_at` DATETIME     DEFAULT NULL,
    `assigned_user_id` INT UNSIGNED DEFAULT NULL,
    `assigned_by`      INT UNSIGNED DEFAULT NULL,
    `assigned_at`      DATETIME     DEFAULT NULL,
    `last_event_at`    DATETIME     DEFAULT NULL,
    `last_call_id`     INT UNSIGNED DEFAULT NULL,
    `last_direction`   VARCHAR(20)  DEFAULT NULL,
    `last_status`      VARCHAR(20)  DEFAULT NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`phone10`),
    KEY `idx_stage` (`stage`),
    KEY `idx_assigned` (`assigned_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `call_number_stage_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone10`    CHAR(10)     NOT NULL,
    `from_stage` VARCHAR(30)  DEFAULT NULL,
    `to_stage`   VARCHAR(30)  DEFAULT NULL,
    `user_id`    INT UNSIGNED DEFAULT NULL,
    `user_name`  VARCHAR(120) DEFAULT NULL,
    `is_auto`    TINYINT(1)   NOT NULL DEFAULT 0,
    `reason`     VARCHAR(255) DEFAULT NULL,
    `call_id`    INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_phone` (`phone10`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
