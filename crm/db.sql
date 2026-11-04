-- =====================================================================
--   جاکجاست (Jakojast) - سیستم مدیریت یکپارچه
--   اسکریپت ساخت دیتابیس - نسخه 3.0
-- =====================================================================
--   این فایل توسط install.php به‌صورت خودکار اجرا می‌شود.
--   در صورت اجرای دستی، مطمئن شوید دیتابیس jakojast از قبل ساخته شده:
--     CREATE DATABASE jakojast CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+03:30';

-- =====================================================================
--   ۱) کاربران سیستم (users)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(60)  NOT NULL,
    `password`      VARCHAR(255) NOT NULL COMMENT 'bcrypt hash (with MD5 migration support)',
    `full_name`     VARCHAR(120) NOT NULL,
    `email`         VARCHAR(150) DEFAULT NULL,
    `role`          VARCHAR(40)  NOT NULL DEFAULT 'staff' COMMENT 'admin|manager|staff',
    `permissions`   TEXT         DEFAULT NULL COMMENT 'لیست کلیدهای دسترسی (JSON) - برای role غیر از admin',
    `exten_number`  VARCHAR(20)  DEFAULT NULL COMMENT 'شماره داخلی تلفنچی',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login`    DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    KEY `idx_role` (`role`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۲) توکن‌های احراز هویت (auth_tokens)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `token`         CHAR(64)     NOT NULL,
    `expires_at`    DATETIME     NOT NULL,
    `ip`            VARCHAR(45)  DEFAULT NULL,
    `user_agent`    VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_user` (`user_id`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۳) مشتریان (customers)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `customers` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150) NOT NULL,
    `mobile`        VARCHAR(20)  DEFAULT NULL,
    `phone`         VARCHAR(20)  DEFAULT NULL,
    `email`         VARCHAR(150) DEFAULT NULL,
    `city`          VARCHAR(80)  DEFAULT NULL,
    `address`       VARCHAR(255) DEFAULT NULL,
    `level`         VARCHAR(30)  NOT NULL DEFAULT 'عادی' COMMENT 'VIP|طلایی|نقره‌ای|برنز|عادی',
    `reservations`  INT          NOT NULL DEFAULT 0,
    `total_spend`   BIGINT       NOT NULL DEFAULT 0,
    `last_purchase` VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی آخرین خرید',
    `last_call_at`  DATETIME     DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active|inactive|blocked',
    `join_date`     VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی عضویت',
    `score`         INT          NOT NULL DEFAULT 0,
    `avatar`        VARCHAR(255) DEFAULT NULL,
    `birthday`      VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی تولد',
    `notes`         TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mobile` (`mobile`),
    KEY `idx_status` (`status`),
    KEY `idx_level` (`level`),
    KEY `idx_city` (`city`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۴) یادداشت‌های مشتری (customer_notes) - جدول تکمیلی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `customer_notes` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id`   INT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED DEFAULT NULL,
    `text`          TEXT         NOT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_notes_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۵) میزبانان (hosts)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `hosts` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150) NOT NULL,
    `mobile`        VARCHAR(20)  DEFAULT NULL,
    `email`         VARCHAR(150) DEFAULT NULL,
    `national_code` VARCHAR(20)  DEFAULT NULL,
    `sheba`         VARCHAR(40)  DEFAULT NULL,
    `city`          VARCHAR(80)  DEFAULT NULL,
    `properties`    INT          NOT NULL DEFAULT 0 COMMENT 'تعداد اقامتگاه‌ها',
    `total_revenue` BIGINT       NOT NULL DEFAULT 0,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'active|inactive|pending|blocked',
    `rating`        DECIMAL(2,1) NOT NULL DEFAULT 0.0,
    `level`         VARCHAR(30)  NOT NULL DEFAULT 'برنز',
    `join_date`     VARCHAR(20)  DEFAULT NULL,
    `avatar`        VARCHAR(255) DEFAULT NULL,
    `notes`         TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mobile` (`mobile`),
    KEY `idx_status` (`status`),
    KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۶) تسویه‌حساب میزبانان (host_settlements) - جدول تکمیلی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `host_settlements` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `host_id`       INT UNSIGNED NOT NULL,
    `amount`        BIGINT       NOT NULL,
    `request_date`  VARCHAR(20)  DEFAULT NULL,
    `paid_date`     VARCHAR(20)  DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|rejected',
    `note`          TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_host` (`host_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_settle_host` FOREIGN KEY (`host_id`) REFERENCES `hosts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۷) پرسنل (staff)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `staff` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED DEFAULT NULL COMMENT 'حساب کاربری متصل (برای ورود به سیستم)',
    `code`          VARCHAR(20)  NOT NULL,
    `name`          VARCHAR(150) NOT NULL,
    `role`          VARCHAR(80)  DEFAULT NULL COMMENT 'سمت شغلی',
    `unit`          VARCHAR(60)  DEFAULT NULL COMMENT 'واحد سازمانی',
    `supervisor_id` INT UNSIGNED DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT 'active|leave|absent',
    `base_salary`   BIGINT       NOT NULL DEFAULT 0,
    `avatar`        VARCHAR(255) DEFAULT NULL,
    `mobile`        VARCHAR(20)  DEFAULT NULL,
    `email`         VARCHAR(150) DEFAULT NULL,
    `national_code` VARCHAR(20)  DEFAULT NULL,
    `hire_date`     VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی شروع به کار',
    `exten_number`  VARCHAR(20)  DEFAULT NULL COMMENT 'شماره داخلی تلفنچی',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_code` (`code`),
    KEY `idx_unit` (`unit`),
    KEY `idx_status` (`status`),
    KEY `idx_supervisor` (`supervisor_id`),
    CONSTRAINT `fk_staff_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۸) حضور و غیاب (attendance) - جدول تکمیلی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `attendance` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id`      INT UNSIGNED NOT NULL,
    `date`          DATE         NOT NULL,
    `in_time`       TIME         DEFAULT NULL,
    `out_time`      TIME         DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'present' COMMENT 'present|late|absent|leave',
    `note`          VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_staff_date` (`staff_id`, `date`),
    KEY `idx_date` (`date`),
    CONSTRAINT `fk_attend_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۹) ارزیابی عملکرد پرسنل (staff_evaluations) - جدول تکمیلی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `staff_evaluations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id`      INT UNSIGNED NOT NULL,
    `month`         VARCHAR(30)  NOT NULL COMMENT 'مثال: اردیبهشت ۱۴۰۳',
    `score`         DECIMAL(5,2) NOT NULL DEFAULT 0,
    `evaluator`     VARCHAR(120) DEFAULT NULL,
    `goals`         VARCHAR(255) DEFAULT NULL,
    `strengths`     VARCHAR(255) DEFAULT NULL,
    `weaknesses`    VARCHAR(255) DEFAULT NULL,
    `note`          TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_staff` (`staff_id`),
    CONSTRAINT `fk_eval_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۰) مرخصی‌ها (leaves)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `leaves` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id`      INT UNSIGNED NOT NULL,
    `type`          VARCHAR(30)  NOT NULL DEFAULT 'استحقاقی' COMMENT 'استحقاقی|اضطراری|بدون حقوق',
    `from_date`     VARCHAR(20)  NOT NULL,
    `to_date`       VARCHAR(20)  NOT NULL,
    `days`          DECIMAL(4,1) NOT NULL DEFAULT 1,
    `reason`        TEXT         DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
    `approved_by`   INT UNSIGNED DEFAULT NULL,
    `approved_at`   DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_staff` (`staff_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_leave_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_leave_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۱) حقوق و دستمزد (salaries)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `salaries` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_id`      INT UNSIGNED NOT NULL,
    `month`         VARCHAR(30)  NOT NULL,
    `base`          BIGINT       NOT NULL DEFAULT 0 COMMENT 'حقوق پایه',
    `housing`       BIGINT       NOT NULL DEFAULT 0 COMMENT 'حق مسکن',
    `bonus_food`    BIGINT       NOT NULL DEFAULT 0 COMMENT 'بن کارانه',
    `grocery`       BIGINT       NOT NULL DEFAULT 0 COMMENT 'بن خواربار',
    `performance`   BIGINT       NOT NULL DEFAULT 0 COMMENT 'پاداش عملکرد',
    `overtime`      BIGINT       NOT NULL DEFAULT 0 COMMENT 'اضافه کاری',
    `insurance`     BIGINT       NOT NULL DEFAULT 0 COMMENT 'بیمه',
    `tax`           BIGINT       NOT NULL DEFAULT 0 COMMENT 'مالیات',
    `loan`          BIGINT       NOT NULL DEFAULT 0 COMMENT 'وام',
    `net`           BIGINT       NOT NULL DEFAULT 0 COMMENT 'خالص پرداختی',
    `paid`          TINYINT(1)   NOT NULL DEFAULT 0,
    `paid_date`     DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_staff` (`staff_id`),
    KEY `idx_month` (`month`),
    CONSTRAINT `fk_salary_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۲) تماس‌ها (calls) - با یکپارچگی تلفنچی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `calls` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cuid`              VARCHAR(80)  NOT NULL COMMENT 'شناسه یکتای تماس تلفنچی',
    `call_source`       VARCHAR(30)  DEFAULT NULL COMMENT 'منبع تماس: inbound|outbound|internal',
    `call_dest`         VARCHAR(30)  DEFAULT NULL COMMENT 'مقصد تماس',
    `type`              VARCHAR(20)  DEFAULT NULL COMMENT 'inbound|outbound|missed|internal',
    `status`            VARCHAR(20)  DEFAULT NULL COMMENT 'answered|no_answer|busy|failed',
    `time_wait`         INT          NOT NULL DEFAULT 0 COMMENT 'ثانیه انتظار',
    `time_talk`         INT          NOT NULL DEFAULT 0 COMMENT 'ثانیه مکالمه',
    `started_at`        DATETIME     DEFAULT NULL COMMENT 'زمان شروع به میلادی',
    `jalali_started_at` VARCHAR(30)  DEFAULT NULL COMMENT 'زمان شروع به شمسی (محاسبه شده)',
    `trunk`             VARCHAR(60)  DEFAULT NULL,
    `exten_name`        VARCHAR(120) DEFAULT NULL,
    `exten_number`      VARCHAR(30)  DEFAULT NULL,
    `file_record`       VARCHAR(255) DEFAULT NULL COMMENT 'نام فایل صوتی در تلفنچی',
    `file_id`           VARCHAR(120) DEFAULT NULL COMMENT 'شناسه فایل صوتی (لازم برای دانلود از GET /calls/record)',
    `contact_id`        INT UNSIGNED DEFAULT NULL COMMENT 'ارجاع به مشتری تطبیق یافته',
    `user_id`           INT UNSIGNED DEFAULT NULL COMMENT 'ارجاع به کاربر/پرسنل مربوطه',
    `notes`             TEXT         DEFAULT NULL,
    `imported_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cuid` (`cuid`),
    KEY `idx_started` (`started_at`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_source` (`call_source`),
    KEY `idx_dest` (`call_dest`),
    KEY `idx_exten_num` (`exten_number`),
    KEY `idx_contact` (`contact_id`),
    KEY `idx_jalali` (`jalali_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۳) رویدادهای تلفنچی (telefonchy_events) - وب‌هوک
-- =====================================================================
CREATE TABLE IF NOT EXISTS `telefonchy_events` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`    VARCHAR(60)  DEFAULT NULL,
    `call_cuid`     VARCHAR(80)  DEFAULT NULL,
    `payload`       JSON         DEFAULT NULL,
    `raw_body`      LONGTEXT     DEFAULT NULL,
    `received_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_event_type` (`event_type`),
    KEY `idx_call_cuid` (`call_cuid`),
    KEY `idx_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۳ب) لاگ حرفه‌ای هر تماس با API تلفنچی (درخواست/پاسخ/خطا/زمان‌بندی)
--   برای دیباگ سریع مشکلات اتصال بدون نیاز به دسترسی به لاگ فایل سرور
-- =====================================================================
CREATE TABLE IF NOT EXISTS `telefonchy_api_logs` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction`      ENUM('outbound','webhook') NOT NULL DEFAULT 'outbound' COMMENT 'outbound=ما درخواست زدیم / webhook=تلفنچی به ما زد',
    `method`         VARCHAR(10)   DEFAULT NULL,
    `endpoint`       VARCHAR(255) DEFAULT NULL COMMENT 'مسیر نسبی (بدون توکن)',
    `full_url`       VARCHAR(500) DEFAULT NULL COMMENT 'آدرس کامل درخواست (بدون توکن)',
    `request_params` JSON         DEFAULT NULL COMMENT 'پارامترهای ارسالی (توکن حذف شده)',
    `http_code`      SMALLINT     DEFAULT NULL,
    `success`        TINYINT(1)   NOT NULL DEFAULT 0,
    `error_message`  TEXT         DEFAULT NULL,
    `response_preview` TEXT       DEFAULT NULL COMMENT 'خلاصه پاسخ (حداکثر ۲۰۰۰ کاراکتر) فقط برای دیباگ',
    `duration_ms`    INT          DEFAULT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_endpoint` (`endpoint`),
    KEY `idx_success` (`success`),
    KEY `idx_http_code` (`http_code`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۴) سرنخ‌های فروش (leads)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `leads` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150) NOT NULL,
    `mobile`        VARCHAR(20)  DEFAULT NULL,
    `source`        VARCHAR(60)  DEFAULT NULL COMMENT 'اینستاگرام|گوگل|معرفی|تبلیغات',
    `stage`         VARCHAR(30)  NOT NULL DEFAULT 'جدید' COMMENT 'جدید|تماس اول|مذاکره|قرارداد|باخته|برنده',
    `assignee`      VARCHAR(120) DEFAULT NULL,
    `next_contact`  VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی تماس بعدی',
    `value`         BIGINT       NOT NULL DEFAULT 0,
    `note`          TEXT         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_stage` (`stage`),
    KEY `idx_source` (`source`),
    KEY `idx_assignee` (`assignee`),
    KEY `idx_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۵) سرنخ‌های میزبان (host_leads) - با فیلدهای جدید
-- =====================================================================
CREATE TABLE IF NOT EXISTS `host_leads` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(150) NOT NULL,
    `mobile`        VARCHAR(20)  DEFAULT NULL,
    `source`        VARCHAR(60)  DEFAULT NULL COMMENT 'مرجع: اینستاگرام|گوگل|معرفی|تبلیغات',
    `stage`         VARCHAR(30)  DEFAULT 'جذب اولیه' COMMENT 'مرحله قیف فروش (legacy)',
    `status`        VARCHAR(30)  NOT NULL DEFAULT 'جذب اولیه' COMMENT 'وضعیت: جذب اولیه|بی پاسخ|عدم تمایل',
    `note`          TEXT         DEFAULT NULL COMMENT 'توضیحات',
    `location`      VARCHAR(150) DEFAULT NULL COMMENT 'لوکیشن',
    `region`        VARCHAR(80)  DEFAULT NULL COMMENT 'منطقه',
    `action_date`   VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ اقدام (شمسی)',
    `city`          VARCHAR(80)  DEFAULT NULL COMMENT 'شهر',
    `property_type` VARCHAR(60)  DEFAULT NULL COMMENT 'نوع اقامتگاه: ویلا|آپارتمان|کلبه|بوم‌گردی|سوئیت',
    `last_contact`  VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی آخرین تماس',
    `assignee`      VARCHAR(120) DEFAULT NULL COMMENT 'مسئول جذب',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_stage` (`stage`),
    KEY `idx_city` (`city`),
    KEY `idx_mobile` (`mobile`),
    KEY `idx_assignee` (`assignee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۶) تیکت‌ها (tickets)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `tickets` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255) NOT NULL,
    `type`          VARCHAR(20)  DEFAULT 'customer' COMMENT 'customer|host|staff',
    `customer_id`   INT UNSIGNED DEFAULT NULL,
    `host_id`       INT UNSIGNED DEFAULT NULL,
    `staff_id`      INT UNSIGNED DEFAULT NULL,
    `call_id`       INT UNSIGNED DEFAULT NULL,
    `priority`      VARCHAR(20)  NOT NULL DEFAULT 'medium' COMMENT 'high|medium|low',
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'open' COMMENT 'open|in_review|answered|closed',
    `dept`          VARCHAR(60)  DEFAULT NULL COMMENT 'فروش|فنی|مالی|عمومی|اداری',
    `assignee`      VARCHAR(120) DEFAULT NULL,
    `messages`      JSON         DEFAULT NULL COMMENT 'آرایه‌ای از پیام‌ها',
    `date`          VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی ایجاد',
    `last_reply`    VARCHAR(20)  DEFAULT NULL COMMENT 'تاریخ شمسی آخرین پاسخ',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_priority` (`priority`),
    KEY `idx_type` (`type`),
    KEY `idx_customer` (`customer_id`),
    KEY `idx_host` (`host_id`),
    KEY `idx_staff` (`staff_id`),
    CONSTRAINT `fk_tkt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tkt_host` FOREIGN KEY (`host_id`) REFERENCES `hosts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tkt_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tkt_call` FOREIGN KEY (`call_id`) REFERENCES `calls` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۷) پروژه‌ها (projects) - جدول تکمیلی
-- =====================================================================
CREATE TABLE IF NOT EXISTS `projects` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120) NOT NULL,
    `description`   TEXT         DEFAULT NULL,
    `progress`      INT          NOT NULL DEFAULT 0 COMMENT 'درصد پیشرفت 0-100',
    `members`       JSON         DEFAULT NULL COMMENT 'آرایه‌ای از نام اعضا',
    `deadline`      VARCHAR(20)  DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۸) تسک‌ها (tasks)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `tasks` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`         VARCHAR(255) NOT NULL,
    `description`   TEXT         DEFAULT NULL,
    `priority`      VARCHAR(20)  NOT NULL DEFAULT 'medium' COMMENT 'high|medium|low',
    `project_id`    INT UNSIGNED DEFAULT NULL,
    `project`       VARCHAR(120) DEFAULT NULL COMMENT 'نام پروژه (legacy)',
    `assignee`      VARCHAR(120) DEFAULT NULL,
    `start_date`    VARCHAR(20)  DEFAULT NULL,
    `end_date`      VARCHAR(20)  DEFAULT NULL,
    `status`        VARCHAR(20)  NOT NULL DEFAULT 'new' COMMENT 'new|in_progress|review|done|cancelled',
    `subtasks`      JSON         DEFAULT NULL,
    `comments`      JSON         DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_priority` (`priority`),
    KEY `idx_assignee` (`assignee`),
    KEY `idx_project` (`project_id`),
    CONSTRAINT `fk_task_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۱۹) پیام‌های چت داخلی (chat_messages)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel`       VARCHAR(60)  NOT NULL DEFAULT 'عمومی',
    `user_id`       INT UNSIGNED DEFAULT NULL,
    `user_name`     VARCHAR(120) NOT NULL,
    `user_avatar`   VARCHAR(255) DEFAULT NULL,
    `text`          TEXT         NOT NULL,
    `is_me`         TINYINT(1)   NOT NULL DEFAULT 0,
    `time`          VARCHAR(10)  DEFAULT NULL COMMENT 'HH:MM',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_channel` (`channel`),
    KEY `idx_created` (`created_at`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   ۲۰) تنظیمات سیستم (settings)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key_name`      VARCHAR(80)  NOT NULL,
    `value`         TEXT         DEFAULT NULL,
    `updated_at`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_key` (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--   داده‌های اولیه - کاربر پیش‌فرض admin/123
--   هش bcrypt برای رمز "123" (با cost=10)
-- =====================================================================
--   هش bcrypt معتبر برای رمز "123" (تولید شده با cost=10)
--   در صورتی که از install.php استفاده کنید، این هش به‌صورت خودکار بازنویسی می‌شود.
-- (سیستم یکپارچه) کاربر پیش‌فرض ساخته نمی‌شود؛ کاربران از اعلان‌بان می‌آیند.


-- =====================================================================
--   تنظیمات اولیه سیستم
-- =====================================================================
INSERT INTO `settings` (`key_name`, `value`) VALUES
    ('system_name', 'جاکجاست'),
    ('primary_color', '#e50241'),
    ('telefonchy_service_id', ''),
    ('telefonchy_token', ''),
    ('telefonchy_base_url', 'https://api.telefonchy.com'),
    ('last_sync_at', NULL),
    ('commission_rate', '20'),
    ('min_settlement_amount', '1000000'),
    ('settlement_day', '15'),
    ('app_version', '3.0.0'),
    ('installed_at', NOW())
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

-- =====================================================================
--   کانال‌های چت پیش‌فرض
-- =====================================================================
INSERT INTO `chat_messages` (`channel`, `user_name`, `text`, `time`) VALUES
    ('عمومی', 'سیستم', 'به چت داخلی جاکجاست خوش آمدید 👋', date_format(now(), '%H:%i'))
ON DUPLICATE KEY UPDATE `text` = `text`;

SET FOREIGN_KEY_CHECKS = 1;
-- =====================================================================
--   پایان اسکریپت db.sql
--   مجموعاً ۲۰ جدول ایجاد شد.
-- =====================================================================
