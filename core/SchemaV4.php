<?php
/**
 * core/SchemaV4.php
 * جدول‌های ماژول‌های تکمیلی: صف انتشار اینستاگرام، سناریوساز، دکمه‌های شناور، سرچ کنسول و نقشه‌ی سایت.
 * هم از «به‌روزرسانی دیتابیس» صدا زده می‌شود و هم به‌صورت تنبل از ماژول‌ها (با پرچم نسخه)،
 * تا اگر مدیر دکمه‌ی به‌روزرسانی را نزد، چیزی از کار نیفتد.
 */
class SchemaV4
{
    const VERSION = '1.0.17';
    private static $done = false;

    public static function ensure(bool $force = false): void
    {
        if (self::$done && !$force) return;
        self::$done = true;
        if (!$force) {
            try {
                $r = Database::one("SELECT v FROM eb_settings WHERE k='schema.v4'");
                if (($r['v'] ?? '') === self::VERSION) return;
            } catch (Throwable $e) { return; }
            // نسخه عوض شده: کل ساختار (شامل جدول‌ها و ستون‌های تخصیص چت) یک‌بار خودکار به‌روز شود
            if (is_file(__DIR__ . '/Schema.php')) {
                @set_time_limit(300);
                require_once __DIR__ . '/Schema.php';
                try { Schema::ensure(); } catch (Throwable $e) { error_log('[schema] ' . $e->getMessage()); self::ensure(true); }
                Database::forgetSchemaCache();
                return;
            }
        }
        $pdo = Database::pdo();
        $sql = [
            // صف انتشار پست و استوری اینستاگرام (زمان‌بندی‌شده یا فوری)
            "CREATE TABLE IF NOT EXISTS eb_ig_posts (
              id INT AUTO_INCREMENT PRIMARY KEY,
              ig_account_id INT NOT NULL,
              kind VARCHAR(10) NOT NULL DEFAULT 'post',
              media_path VARCHAR(500) NOT NULL,
              caption TEXT,
              run_at DATETIME NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'pending',
              attempts INT NOT NULL DEFAULT 0,
              error VARCHAR(500) DEFAULT NULL,
              result_json TEXT,
              mkt_content_id INT DEFAULT NULL,
              created_by INT DEFAULT NULL,
              claimed_at DATETIME DEFAULT NULL,
              published_at DATETIME DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_due (status, run_at),
              KEY idx_acc (ig_account_id),
              KEY idx_mkt (mkt_content_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // سناریوها (گراف گره‌ها) و اجراها
            "CREATE TABLE IF NOT EXISTS eb_flows (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(160) NOT NULL,
              description VARCHAR(500) DEFAULT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 0,
              trigger_type VARCHAR(40) NOT NULL DEFAULT 'manual',
              trigger_json TEXT,
              graph_json MEDIUMTEXT,
              runs INT NOT NULL DEFAULT 0,
              errors INT NOT NULL DEFAULT 0,
              last_run_at DATETIME DEFAULT NULL,
              next_run_at DATETIME DEFAULT NULL,
              created_by INT DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_trigger (is_active, trigger_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS eb_flow_runs (
              id INT AUTO_INCREMENT PRIMARY KEY,
              flow_id INT NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'running',
              person_id INT DEFAULT NULL,
              channel VARCHAR(30) DEFAULT NULL,
              trigger_type VARCHAR(40) DEFAULT NULL,
              ctx_json MEDIUMTEXT,
              stack_json TEXT,
              current_node VARCHAR(60) DEFAULT NULL,
              resume_at DATETIME DEFAULT NULL,
              wait_for VARCHAR(30) DEFAULT NULL,
              steps INT NOT NULL DEFAULT 0,
              error VARCHAR(500) DEFAULT NULL,
              log_json MEDIUMTEXT,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_flow (flow_id, id),
              KEY idx_wait (status, resume_at),
              KEY idx_person_wait (person_id, status, wait_for)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // آمار کلیک دکمه‌های شناور
            "CREATE TABLE IF NOT EXISTS eb_fab_clicks (
              id INT AUTO_INCREMENT PRIMARY KEY,
              button_key VARCHAR(40) NOT NULL,
              kind VARCHAR(30) DEFAULT NULL,
              page_url VARCHAR(500) DEFAULT NULL,
              device VARCHAR(10) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_btn (button_key, created_at),
              KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            // سرچ کنسول: کش پاسخ‌ها، عکس روزانه، نقشه‌ی سایت و نتایج بررسی آدرس‌ها
            "CREATE TABLE IF NOT EXISTS eb_gsc_cache (
              k VARCHAR(64) PRIMARY KEY,
              v MEDIUMTEXT,
              expires_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS eb_gsc_daily (
              site VARCHAR(190) NOT NULL,
              day DATE NOT NULL,
              clicks INT NOT NULL DEFAULT 0,
              impressions INT NOT NULL DEFAULT 0,
              ctr DECIMAL(7,4) NOT NULL DEFAULT 0,
              position DECIMAL(7,2) NOT NULL DEFAULT 0,
              PRIMARY KEY (site, day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS eb_gsc_urls (
              id INT AUTO_INCREMENT PRIMARY KEY,
              site VARCHAR(190) NOT NULL,
              url VARCHAR(700) NOT NULL,
              url_hash CHAR(32) NOT NULL,
              source VARCHAR(20) NOT NULL DEFAULT 'sitemap',
              sitemap VARCHAR(500) DEFAULT NULL,
              lastmod VARCHAR(40) DEFAULT NULL,
              http_status INT DEFAULT NULL,
              title VARCHAR(300) DEFAULT NULL,
              meta_desc VARCHAR(500) DEFAULT NULL,
              h1 VARCHAR(300) DEFAULT NULL,
              canonical VARCHAR(700) DEFAULT NULL,
              robots VARCHAR(120) DEFAULT NULL,
              words INT DEFAULT NULL,
              load_ms INT DEFAULT NULL,
              issues_json TEXT,
              verdict VARCHAR(30) DEFAULT NULL,
              coverage VARCHAR(190) DEFAULT NULL,
              last_crawl DATETIME DEFAULT NULL,
              inspected_at DATETIME DEFAULT NULL,
              crawled_at DATETIME DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_site_url (site, url_hash),
              KEY idx_verdict (site, verdict)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS eb_gsc_alerts (
              id INT AUTO_INCREMENT PRIMARY KEY,
              site VARCHAR(190) NOT NULL,
              kind VARCHAR(40) NOT NULL,
              title VARCHAR(300) NOT NULL,
              detail TEXT,
              seen TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_site (site, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        // رویدادهای امنیتی (مثل ورود مدیر به‌جای کاربر)
        $sql[] = "CREATE TABLE IF NOT EXISTS eb_audit (
              id INT AUTO_INCREMENT PRIMARY KEY,
              user_id INT DEFAULT NULL,
              target_user_id INT DEFAULT NULL,
              action VARCHAR(40) NOT NULL,
              detail VARCHAR(500) DEFAULT NULL,
              ip VARCHAR(45) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_target (target_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        // نظرسنجی رضایت مشتری بعد از بستن گفتگو
        $sql[] = "CREATE TABLE IF NOT EXISTS eb_csat (
              id INT AUTO_INCREMENT PRIMARY KEY,
              person_id INT NOT NULL,
              agent_id INT DEFAULT NULL,
              channel VARCHAR(30) DEFAULT NULL,
              status VARCHAR(12) NOT NULL DEFAULT 'sent',
              rating TINYINT DEFAULT NULL,
              comment TEXT,
              sent_by INT DEFAULT NULL,
              sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              rated_at DATETIME DEFAULT NULL,
              KEY idx_person (person_id, status), KEY idx_agent (agent_id, rated_at), KEY idx_sent (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        // پیام‌های پیگیری خودکار
        $sql[] = "CREATE TABLE IF NOT EXISTS eb_followup_log (
              id INT AUTO_INCREMENT PRIMARY KEY,
              rule_id VARCHAR(20) NOT NULL,
              person_id INT NOT NULL,
              anchor_msg_id INT NOT NULL,
              ok TINYINT(1) NOT NULL DEFAULT 1,
              error VARCHAR(300) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_once (rule_id, person_id, anchor_msg_id), KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        // آمار عملکرد محتوای تقویم (دستی یا خودکار؛ هر ردیف یک عکس از آمار در یک زمان)
        $sql[] = "CREATE TABLE IF NOT EXISTS eb_mkt_metrics (
              id INT AUTO_INCREMENT PRIMARY KEY,
              content_id INT NOT NULL,
              captured_at DATETIME NOT NULL,
              source VARCHAR(10) NOT NULL DEFAULT 'manual',
              views INT DEFAULT NULL, reach INT DEFAULT NULL, impressions INT DEFAULT NULL,
              likes INT DEFAULT NULL, comments INT DEFAULT NULL, shares INT DEFAULT NULL, saves INT DEFAULT NULL,
              clicks INT DEFAULT NULL, follows INT DEFAULT NULL, dms INT DEFAULT NULL, leads INT DEFAULT NULL,
              sales INT DEFAULT NULL, revenue DECIMAL(16,0) DEFAULT NULL, watch_sec INT DEFAULT NULL,
              note VARCHAR(500) DEFAULT NULL, created_by INT DEFAULT NULL,
              KEY idx_content (content_id, captured_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        foreach ($sql as $s) { try { $pdo->exec($s); } catch (Throwable $e) { error_log('[schema v4] ' . $e->getMessage()); } }

        // زمان‌بندی پیام: وضعیت‌های تازه (در صف / ناموفق) و پیوند به صف ارسال
        try { $pdo->exec("ALTER TABLE eb_scheduled MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'"); } catch (Throwable $e) {}
        self::addColumn($pdo, 'eb_scheduled', 'queue_job_id', 'INT DEFAULT NULL');
        self::addColumn($pdo, 'eb_scheduled', 'error', 'VARCHAR(500) DEFAULT NULL');
        self::addColumn($pdo, 'eb_scheduled', 'claimed_at', 'DATETIME DEFAULT NULL');
        // ویرایش پیام: شناسه‌ی پیام در پلتفرم، زمان ویرایش و متن اصلی؛ منبع پیام خودکار
        self::addColumn($pdo, 'eb_messages', 'ext_id', 'VARCHAR(80) DEFAULT NULL');
        self::addColumn($pdo, 'eb_messages', 'edited_at', 'DATETIME DEFAULT NULL');
        self::addColumn($pdo, 'eb_messages', 'orig_body', 'TEXT');
        self::addColumn($pdo, 'eb_messages', 'source', 'VARCHAR(20) DEFAULT NULL');
        // پیوند محتوای تقویم به پست واقعی (برای آمار خودکار)
        self::addColumn($pdo, 'eb_mkt_content', 'ext_media_id', 'VARCHAR(80) DEFAULT NULL');
        self::addColumn($pdo, 'eb_mkt_content', 'ext_url', 'VARCHAR(500) DEFAULT NULL');
        self::addColumn($pdo, 'eb_mkt_content', 'link_code', 'VARCHAR(60) DEFAULT NULL');
        // نام نمایشی کاربران (اگر لایه‌ی میز کار هنوز ساخته نشده باشد)
        self::addColumn($pdo, 'eb_users', 'display_name', 'VARCHAR(100) DEFAULT NULL');

        try {
            Database::run("INSERT INTO eb_settings (k, v) VALUES ('schema.v4', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::VERSION]);
        } catch (Throwable $e) {}
        Database::forgetSchemaCache();
    }

    private static function addColumn($pdo, string $table, string $col, string $def): void
    {
        try {
            $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'")->fetchColumn();
            if (!$exists) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        } catch (Throwable $e) {}
    }
}
