<?php
/**
 * core/Schema.php
 * تعریف کامل جداول و مهاجرت ستون‌ها در یک جا.
 * هم install.php و هم endpoint تعمیر دیتابیس از این استفاده می‌کنند.
 * همه‌ی CREATEها IF NOT EXISTS و همه‌ی ALTERها امن (بررسی وجود ستون) هستند،
 * پس اجرای چندباره بی‌خطر است.
 */

class Schema
{
    public static function ensure(): void
    {
        $pdo = Database::pdo();
        $schema = <<<SQL
        CREATE TABLE IF NOT EXISTS eb_users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(100) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          role ENUM('admin','operator') NOT NULL DEFAULT 'operator',
          permissions_json TEXT,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_settings (
          k VARCHAR(100) PRIMARY KEY,
          v MEDIUMTEXT,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_persons (
          id INT AUTO_INCREMENT PRIMARY KEY,
          full_name VARCHAR(255) DEFAULT NULL,
          phone VARCHAR(20) DEFAULT NULL UNIQUE,
          status ENUM('member','anonymous') NOT NULL DEFAULT 'anonymous',
          notes TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_identities (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT NOT NULL,
          channel VARCHAR(50) NOT NULL,
          channel_uid VARCHAR(190) NOT NULL,
          username VARCHAR(150) DEFAULT NULL,
          first_name VARCHAR(150) DEFAULT NULL,
          raw_json TEXT,
          first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
          last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_channel_uid (channel, channel_uid),
          KEY idx_person (person_id),
          CONSTRAINT fk_identity_person FOREIGN KEY (person_id)
            REFERENCES eb_persons(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_messages (
          id INT AUTO_INCREMENT PRIMARY KEY,
          identity_id INT DEFAULT NULL,
          channel VARCHAR(50) NOT NULL,
          direction ENUM('out','in') NOT NULL DEFAULT 'out',
          body TEXT,
          status VARCHAR(30) DEFAULT 'sent',
          error VARCHAR(500) DEFAULT NULL,
          send_id INT DEFAULT NULL,
          read_at DATETIME DEFAULT NULL,
          source VARCHAR(20) DEFAULT NULL,
          campaign_id INT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_identity (identity_id),
          KEY idx_campaign (campaign_id),
          KEY idx_send (send_id),
          KEY idx_created (created_at),
          KEY idx_dir_read (direction, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_sends (
          id INT AUTO_INCREMENT PRIMARY KEY,
          kind VARCHAR(30) NOT NULL DEFAULT 'bulk',
          title VARCHAR(255) DEFAULT NULL,
          body TEXT,
          channels VARCHAR(255) DEFAULT NULL,
          mode VARCHAR(40) DEFAULT NULL,
          total INT DEFAULT 0,
          sent INT DEFAULT 0,
          failed INT DEFAULT 0,
          meta_json TEXT,
          created_by INT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_kind (kind),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_send_targets (
          id INT AUTO_INCREMENT PRIMARY KEY,
          send_id INT NOT NULL,
          person_id INT DEFAULT NULL,
          channel VARCHAR(50) DEFAULT NULL,
          recipient VARCHAR(120) DEFAULT NULL,
          status VARCHAR(30) DEFAULT 'sent',
          error VARCHAR(500) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_send (send_id),
          KEY idx_person (person_id),
          KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_groups (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          color VARCHAR(7) DEFAULT '#8b5cf6',
          description TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_person_groups (
          group_id INT NOT NULL,
          person_id INT NOT NULL,
          PRIMARY KEY (group_id, person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_tags (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(100) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_person_tags (
          tag_id INT NOT NULL,
          person_id INT NOT NULL,
          PRIMARY KEY (tag_id, person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_templates (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          body TEXT,
          buttons_json TEXT,
          media_path VARCHAR(500),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_campaigns (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          channels VARCHAR(255),
          filter_json TEXT,
          template_id INT DEFAULT NULL,
          status ENUM('draft','scheduled','sending','sent','cancelled') DEFAULT 'draft',
          stats_json TEXT,
          scheduled_at DATETIME DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_auto_replies (
          id INT AUTO_INCREMENT PRIMARY KEY,
          channel VARCHAR(50) DEFAULT NULL,
          trigger_keyword VARCHAR(255) NOT NULL,
          trigger_type ENUM('exact','contains','starts_with','regex') DEFAULT 'exact',
          response_json TEXT,
          priority INT DEFAULT 0,
          is_active TINYINT(1) DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE IF NOT EXISTS eb_scheduled (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          send_type VARCHAR(20) NOT NULL DEFAULT 'chat',
          template_id INT DEFAULT NULL,
          text TEXT,
          buttons_json TEXT,
          media_path VARCHAR(500) DEFAULT NULL,
          filter_json TEXT,
          phones_text TEXT,
          phone_mode VARCHAR(20) DEFAULT 'safir_then_sms',
          channel_priority VARCHAR(255) DEFAULT 'bale,telegram,roubika',
          run_at DATETIME NOT NULL,
          status ENUM('pending','sending','sent','cancelled') DEFAULT 'pending',
          sent_count INT DEFAULT 0,
          failed_count INT DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_status_run (status, run_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_queue_jobs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          send_id INT DEFAULT NULL,
          title VARCHAR(255) DEFAULT NULL,
          message_json MEDIUMTEXT,
          channel_priority VARCHAR(255) DEFAULT 'bale,telegram,roubika',
          total INT DEFAULT 0,
          processed INT DEFAULT 0,
          sent INT DEFAULT 0,
          failed INT DEFAULT 0,
          status ENUM('queued','running','done','cancelled') DEFAULT 'queued',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          finished_at DATETIME DEFAULT NULL,
          KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_queue (
          id INT AUTO_INCREMENT PRIMARY KEY,
          job_id INT NOT NULL,
          person_id INT NOT NULL,
          status ENUM('pending','sending','sent','failed') DEFAULT 'pending',
          channel VARCHAR(50) DEFAULT NULL,
          error VARCHAR(500) DEFAULT NULL,
          claim_token VARCHAR(40) DEFAULT NULL,
          claimed_at DATETIME DEFAULT NULL,
          processed_at DATETIME DEFAULT NULL,
          KEY idx_job_status (job_id, status),
          KEY idx_claim (claim_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_webhook_events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          channel VARCHAR(40) NOT NULL,
          event_key VARCHAR(120) NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_channel_event (channel, event_key),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_bot_settings (
          k VARCHAR(100) PRIMARY KEY,
          v MEDIUMTEXT,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        CREATE TABLE IF NOT EXISTS eb_campaign_steps (
          id INT AUTO_INCREMENT PRIMARY KEY,
          campaign_id INT NOT NULL,
          step_order INT NOT NULL DEFAULT 1,
          template_id INT DEFAULT NULL,
          text TEXT,
          buttons_json TEXT,
          delay_hours INT NOT NULL DEFAULT 72,
          only_nonresponders TINYINT(1) NOT NULL DEFAULT 1,
          KEY idx_campaign (campaign_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_campaign_runs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          campaign_id INT NOT NULL,
          step_id INT NOT NULL,
          person_id INT NOT NULL,
          status VARCHAR(20) DEFAULT 'sent',
          responded TINYINT(1) NOT NULL DEFAULT 0,
          run_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_campaign (campaign_id),
          KEY idx_step (step_id),
          KEY idx_person (person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_links (
          id INT AUTO_INCREMENT PRIMARY KEY,
          code VARCHAR(20) NOT NULL UNIQUE,
          title VARCHAR(255) DEFAULT NULL,
          target_url TEXT NOT NULL,
          category VARCHAR(60) DEFAULT NULL,
          clicks INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_code (code),
          KEY idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_link_clicks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          link_id INT NOT NULL,
          person_id INT DEFAULT NULL,
          ip VARCHAR(45) DEFAULT NULL,
          user_agent VARCHAR(255) DEFAULT NULL,
          referer VARCHAR(500) DEFAULT NULL,
          device VARCHAR(20) DEFAULT NULL,
          browser VARCHAR(40) DEFAULT NULL,
          os VARCHAR(40) DEFAULT NULL,
          clicked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_link (link_id),
          KEY idx_person (person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_bot_nodes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          node_key VARCHAR(60) NOT NULL,
          channel VARCHAR(50) DEFAULT NULL,
          title VARCHAR(255) DEFAULT NULL,
          text TEXT,
          buttons_json TEXT,
          rules_json TEXT,
          is_root TINYINT(1) NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_key_channel (node_key, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_sms_dlr (
          id INT AUTO_INCREMENT PRIMARY KEY,
          provider VARCHAR(40) NOT NULL,
          msg_id VARCHAR(80) NOT NULL,
          phone VARCHAR(40) DEFAULT NULL,
          person_id INT DEFAULT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'pending',
          checked_at DATETIME DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_status (status),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_forms (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          trigger_keyword VARCHAR(100) DEFAULT NULL,
          questions_json TEXT,
          finish_text TEXT,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_form_sessions (
          id INT AUTO_INCREMENT PRIMARY KEY,
          form_id INT NOT NULL,
          channel VARCHAR(50) NOT NULL,
          channel_uid VARCHAR(120) NOT NULL,
          person_id INT DEFAULT NULL,
          step INT NOT NULL DEFAULT 0,
          answers_json TEXT,
          status VARCHAR(20) NOT NULL DEFAULT 'active',
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_lookup (channel, channel_uid, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_form_responses (
          id INT AUTO_INCREMENT PRIMARY KEY,
          form_id INT NOT NULL,
          person_id INT DEFAULT NULL,
          channel VARCHAR(50) DEFAULT NULL,
          channel_uid VARCHAR(120) DEFAULT NULL,
          answers_json TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_form (form_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_api_log (
          id INT AUTO_INCREMENT PRIMARY KEY,
          channel VARCHAR(40) DEFAULT NULL,
          action VARCHAR(60) DEFAULT NULL,
          request_data MEDIUMTEXT,
          response_data MEDIUMTEXT,
          http_code INT DEFAULT NULL,
          ok TINYINT(1) DEFAULT NULL,
          error VARCHAR(500) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_channel (channel),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- لوکاپ خودکار اقامتگاه: کاربر اسم اقامتگاه را می‌فرستد، بات فیلد موردنظر (قیمت،
        -- ساعت ورود/خروج، حداقل روز رزرو ازقبل) را از همین جدول جواب می‌دهد.
        CREATE TABLE IF NOT EXISTS eb_properties (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(190) NOT NULL,
          price VARCHAR(255) DEFAULT NULL,
          checkin_time VARCHAR(100) DEFAULT NULL,
          checkout_time VARCHAR(100) DEFAULT NULL,
          min_advance_days VARCHAR(100) DEFAULT NULL,
          notes TEXT,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- وضعیت میانی «منتظر اسم اقامتگاهم تا فیلد X را جواب بدهم» — بین لحظه‌ای که گره
        -- awaits_property_field فرستاده شد تا لحظه‌ای که کاربر اسم را می‌فرستد.
        CREATE TABLE IF NOT EXISTS eb_property_lookup_pending (
          id INT AUTO_INCREMENT PRIMARY KEY,
          channel VARCHAR(50) NOT NULL,
          channel_uid VARCHAR(190) NOT NULL,
          field VARCHAR(30) NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_who (channel, channel_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


        -- (کلیدواژه‌ی از پیش تعیین‌شده) را مچ نکردند — مورد ۱ درخواست کاربر: «کلمه‌ی ثابت»
        -- در برابر «کلمه‌ی متغیر» (یعنی هرچیز دیگری که کاربر بنویسد و باید با توجه به
        -- محتوای همان پست جواب داده شود، نه یک متن از پیش‌نوشته).
        CREATE TABLE IF NOT EXISTS eb_ig_post_knowledge (
          id INT AUTO_INCREMENT PRIMARY KEY,
          media_id VARCHAR(64) NOT NULL,
          title VARCHAR(255) DEFAULT NULL,
          knowledge_text TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_media (media_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


        -- نگه داشته می‌شود چون کامنت زیر یک پست است، نه بخشی از نخ دایرکتِ یک شخص —
        -- طبق تصمیم صریح: دایرکت‌ها در صندوق ورودی، کامنت‌ها در صفحه‌ی مدیریت اینستاگرام.
        CREATE TABLE IF NOT EXISTS eb_ig_comments (
          id INT AUTO_INCREMENT PRIMARY KEY,
          comment_id VARCHAR(64) NOT NULL,
          media_id VARCHAR(64) DEFAULT NULL,
          sender_id VARCHAR(64) DEFAULT NULL,
          sender_username VARCHAR(190) DEFAULT NULL,
          body TEXT,
          person_id INT DEFAULT NULL,
          replied_at DATETIME DEFAULT NULL,
          replied_type VARCHAR(10) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_comment (comment_id),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- قانون خودکار «کامنت کن X تا برات بفرستم»، با گزینه‌ی فالو اجباری (سبک ManyChat).
        -- media_id خالی یعنی روی همه‌ی پست‌ها؛ keyword خالی یعنی هر کامنتی.
        CREATE TABLE IF NOT EXISTS eb_ig_comment_rules (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(190) NOT NULL,
          media_id VARCHAR(64) DEFAULT NULL,
          keyword VARCHAR(190) DEFAULT NULL,
          match_mode ENUM('contains','exact') NOT NULL DEFAULT 'contains',
          public_reply_text TEXT,
          require_follow TINYINT(1) NOT NULL DEFAULT 0,
          follow_prompt_text TEXT,
          confirm_phrase VARCHAR(190) DEFAULT 'فالو کردم',
          not_yet_text TEXT,
          dm_text TEXT,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          hit_count INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- وضعیت میانی «منتظر تأیید فالو»: بین لحظه‌ای که DM گیت فرستاده شد تا لحظه‌ای که
        -- کاربر عبارت تأیید را در دایرکت تایپ کند. کوتاه‌عمر است (پاک‌سازی در cron).
        CREATE TABLE IF NOT EXISTS eb_ig_gate_pending (
          id INT AUTO_INCREMENT PRIMARY KEY,
          channel_uid VARCHAR(64) NOT NULL,
          rule_id INT NOT NULL,
          comment_id VARCHAR(64) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_uid (channel_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- مورد ۳ (فروش) — نسخه‌ی اول داخلی: فقط کاتالوگ ساده + ثبت سفارش برای پیگیری
        -- اپراتور. طراحی عمداً ساده نگه داشته شده تا فاز بعد (اتصال به فروشگاه/انبار
        -- بیرونی از طریق API) بدون شکستن این جدول‌ها جایگزین/تکمیل شود.
        CREATE TABLE IF NOT EXISTS eb_ig_products (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          sku VARCHAR(100) DEFAULT NULL,
          price DECIMAL(14,2) DEFAULT NULL,
          stock INT DEFAULT NULL,
          image_url VARCHAR(500) DEFAULT NULL,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_ig_orders (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person_id INT DEFAULT NULL,
          status ENUM('pending','confirmed','shipped','done','canceled') NOT NULL DEFAULT 'pending',
          total DECIMAL(14,2) DEFAULT 0,
          notes TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_person (person_id),
          KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_ig_order_items (
          id INT AUTO_INCREMENT PRIMARY KEY,
          order_id INT NOT NULL,
          product_id INT DEFAULT NULL,
          title VARCHAR(255) NOT NULL,
          qty INT NOT NULL DEFAULT 1,
          unit_price DECIMAL(14,2) DEFAULT 0,
          KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_mkt_goals (
          id INT AUTO_INCREMENT PRIMARY KEY,
          period ENUM('month','week','day','campaign') NOT NULL DEFAULT 'month',
          title VARCHAR(255) NOT NULL,
          metric VARCHAR(100) DEFAULT NULL,
          target_value DECIMAL(14,2) DEFAULT 0,
          current_value DECIMAL(14,2) DEFAULT 0,
          owner_id INT DEFAULT NULL,
          due_date DATE DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_period (period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_mkt_roles (
          id INT AUTO_INCREMENT PRIMARY KEY,
          parent_id INT DEFAULT NULL,
          title VARCHAR(255) NOT NULL,
          level ENUM('strategic','specialist','executive') NOT NULL DEFAULT 'specialist',
          duties TEXT,
          sort_order INT DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_mkt_members (
          id INT AUTO_INCREMENT PRIMARY KEY,
          role_id INT DEFAULT NULL,
          full_name VARCHAR(255) NOT NULL,
          email VARCHAR(255) DEFAULT NULL,
          phone VARCHAR(30) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_role (role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_mkt_content (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          channel VARCHAR(40) DEFAULT NULL,
          content_type VARCHAR(40) DEFAULT NULL,
          status ENUM('idea','producing','review','ready','published') NOT NULL DEFAULT 'idea',
          assignee_id INT DEFAULT NULL,
          plan_date DATE DEFAULT NULL,
          plan_time VARCHAR(5) DEFAULT NULL,
          scenario TEXT,
          published_at DATETIME DEFAULT NULL,
          sort_order INT DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_status (status),
          KEY idx_plan (plan_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_ai_knowledge (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          content MEDIUMTEXT,
          keywords VARCHAR(500) DEFAULT NULL,
          pinned TINYINT(1) NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_webchat_sessions (
          id INT AUTO_INCREMENT PRIMARY KEY,
          token CHAR(32) NOT NULL,
          person_id INT DEFAULT NULL,
          identity_id INT DEFAULT NULL,
          ip VARCHAR(45) DEFAULT NULL,
          user_agent VARCHAR(255) DEFAULT NULL,
          page_url VARCHAR(500) DEFAULT NULL,
          referer VARCHAR(500) DEFAULT NULL,
          device VARCHAR(20) DEFAULT NULL,
          browser VARCHAR(40) DEFAULT NULL,
          os VARCHAR(40) DEFAULT NULL,
          identified TINYINT(1) NOT NULL DEFAULT 0,
          msg_count INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_wc_token (token),
          KEY idx_wc_person (person_id),
          KEY idx_wc_ip (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_webchat_outbox (
          id INT AUTO_INCREMENT PRIMARY KEY,
          token CHAR(32) NOT NULL,
          payload_json MEDIUMTEXT,
          source VARCHAR(20) DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          delivered_at DATETIME DEFAULT NULL,
          KEY idx_wc_out (token, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_canned_replies (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          body TEXT NOT NULL,
          category VARCHAR(80) DEFAULT NULL,
          sort_order INT DEFAULT 0,
          created_by INT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_team_messages (
          id INT AUTO_INCREMENT PRIMARY KEY,
          sender_id INT NOT NULL,
          recipient_id INT DEFAULT NULL,
          body TEXT NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          read_at DATETIME DEFAULT NULL,
          KEY idx_pair (sender_id, recipient_id),
          KEY idx_recipient (recipient_id, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_notices (
          id INT AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(255) NOT NULL,
          type VARCHAR(20) NOT NULL DEFAULT 'popup',
          headline VARCHAR(255) DEFAULT NULL,
          body TEXT,
          image_url VARCHAR(500) DEFAULT NULL,
          button_text VARCHAR(100) DEFAULT NULL,
          button_url VARCHAR(500) DEFAULT NULL,
          bg_color VARCHAR(7) DEFAULT NULL,
          text_color VARCHAR(7) DEFAULT NULL,
          accent_color VARCHAR(7) DEFAULT NULL,
          corner_radius INT NOT NULL DEFAULT 16,
          font_family VARCHAR(150) DEFAULT NULL,
          delay_seconds INT NOT NULL DEFAULT 3,
          frequency VARCHAR(20) NOT NULL DEFAULT 'once_per_session',
          url_contains VARCHAR(255) DEFAULT NULL,
          url_exclude VARCHAR(255) DEFAULT NULL,
          starts_at DATETIME DEFAULT NULL,
          ends_at DATETIME DEFAULT NULL,
          priority INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          impressions INT NOT NULL DEFAULT 0,
          clicks INT NOT NULL DEFAULT 0,
          capture_phone TINYINT(1) NOT NULL DEFAULT 0,
          phone_label VARCHAR(150) DEFAULT NULL,
          phone_button_text VARCHAR(100) DEFAULT NULL,
          discount_mode VARCHAR(20) NOT NULL DEFAULT 'none',
          discount_static_code VARCHAR(60) DEFAULT NULL,
          discount_prefix VARCHAR(20) DEFAULT NULL,
          success_headline VARCHAR(255) DEFAULT NULL,
          success_note VARCHAR(255) DEFAULT NULL,
          leads_count INT NOT NULL DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS eb_notice_leads (
          id INT AUTO_INCREMENT PRIMARY KEY,
          notice_id INT NOT NULL,
          phone VARCHAR(20) NOT NULL,
          discount_code VARCHAR(60) DEFAULT NULL,
          page_url VARCHAR(500) DEFAULT NULL,
          person_id INT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          KEY idx_notice (notice_id),
          KEY idx_phone (phone),
          KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        SQL;
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            if ($stmt !== '') $pdo->exec($stmt);
        }
        // مهاجرت ستون‌های جدید برای نصب‌های قدیمی
        self::addColumn($pdo, 'eb_messages', 'error', "VARCHAR(500) DEFAULT NULL");
        self::addColumn($pdo, 'eb_messages', 'send_id', "INT DEFAULT NULL");
        self::addColumn($pdo, 'eb_users', 'permissions_json', "TEXT");
        self::addColumn($pdo, 'eb_scheduled', 'send_type', "VARCHAR(20) NOT NULL DEFAULT 'chat'");
        self::addColumn($pdo, 'eb_scheduled', 'phones_text', "TEXT");
        self::addColumn($pdo, 'eb_scheduled', 'phone_mode', "VARCHAR(20) DEFAULT 'safir_then_sms'");
        // ستون‌های تازه‌ی اعلان سایت (استایل بهتر + دریافت شماره/کد تخفیف) — برای نصب‌های قدیمی‌تر
        self::addColumn($pdo, 'eb_notices', 'accent_color', "VARCHAR(7) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'corner_radius', "INT NOT NULL DEFAULT 16");
        self::addColumn($pdo, 'eb_notices', 'font_family', "VARCHAR(150) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'capture_phone', "TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumn($pdo, 'eb_notices', 'phone_label', "VARCHAR(150) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'phone_button_text', "VARCHAR(100) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'discount_mode', "VARCHAR(20) NOT NULL DEFAULT 'none'");
        self::addColumn($pdo, 'eb_notices', 'discount_static_code', "VARCHAR(60) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'discount_prefix', "VARCHAR(20) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'success_headline', "VARCHAR(255) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'success_note', "VARCHAR(255) DEFAULT NULL");
        self::addColumn($pdo, 'eb_notices', 'leads_count', "INT NOT NULL DEFAULT 0");
        self::addColumn($pdo, 'eb_forms', 'finish_image', "VARCHAR(500) DEFAULT NULL");
        self::addColumn($pdo, 'eb_mkt_content', 'fields_json', "TEXT");
        self::addColumn($pdo, 'eb_mkt_content', 'budget', "DECIMAL(14,0) DEFAULT 0");
        self::addColumn($pdo, 'eb_mkt_content', 'spent', "DECIMAL(14,0) DEFAULT 0");
        // پیج/اکانت شرکت برای هر محتوا (تفکیک تقویم بین چند پیج)
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_mkt_pages (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(120) NOT NULL,
              platform VARCHAR(40) DEFAULT NULL,
              handle VARCHAR(120) DEFAULT NULL,
              color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
              ig_account_id INT DEFAULT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_ig (ig_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::addColumn($pdo, 'eb_mkt_content', 'page_id', "INT DEFAULT NULL");
        self::ensureBudgetsTable($pdo);
        self::ensureCrewStepsTables($pdo);
        // پنل جدید: وضعیت گفتگو، تیم‌ها، یادداشت‌ها و ترجیحات کاربر
        if (!class_exists('Desk')) require_once __DIR__ . '/../modules/Desk.php';
        try { Database::run("DELETE FROM eb_settings WHERE k='desk.schema'"); Desk::ensure(); } catch (Throwable $e) {}
        // صف انتشار اینستاگرام، سناریوساز، دکمه‌های شناور، سرچ کنسول
        require_once __DIR__ . '/SchemaV4.php';
        SchemaV4::ensure(true);
        self::addColumn($pdo, 'eb_mkt_members', 'user_id', "INT DEFAULT NULL");
        self::addColumn($pdo, 'eb_mkt_members', 'department', "VARCHAR(40) DEFAULT 'marketing'");
        // صندوق ورودی: زمان خواندن پیام دریافتی (مستقل از اپراتور)
        self::addColumn($pdo, 'eb_messages', 'read_at', "DATETIME DEFAULT NULL");
        // رسانه در پیام‌ها (عکس/فایل) — برای نمایش و ارسال در چت
        self::addColumn($pdo, 'eb_messages', 'media_type', "VARCHAR(20) DEFAULT NULL");  // photo | document | null
        self::addColumn($pdo, 'eb_messages', 'media_path', "VARCHAR(500) DEFAULT NULL");  // مسیر نسبی فایل (ارسالی) یا file_id (دریافتی)
        // بلاک‌کردن یک مشتری/چت
        self::addColumn($pdo, 'eb_persons', 'blocked_at', "DATETIME DEFAULT NULL");
        // شاخه‌بندی شرطی ساده‌ی بات: آرایه‌ای از {contains, goto} روی هر node
        self::addColumn($pdo, 'eb_bot_nodes', 'rules_json', "TEXT");
        // مورد لوکاپ خودکار اقامتگاه: وقتی این گره فرستاده می‌شود، یعنی منتظر اسم اقامتگاه
        // است تا همین فیلد را از eb_properties جواب بدهد (خالی = گره معمولی، بدون لوکاپ).
        self::addColumn($pdo, 'eb_bot_nodes', 'awaits_property_field', "VARCHAR(30) DEFAULT NULL");
        // پاسخ خودکار رویدادی: نوع تریگر 'on_contact' (اشتراک شماره) باید پذیرفته شود.
        // ENUM قدیمی این مقدار را رد می‌کرد؛ ستون را به VARCHAR گسترش می‌دهیم (idempotent).
        self::widenTriggerType($pdo);

        // ---- دور بازخورد ۱۳ موردی: موارد ۲، ۶، ۸، ۱۰ ----
        // مورد ۱۰: منبع پیام خروجی (autoreply|botmenu|form|reply|bulk) تا در گفتگو برچسب بخورد
        self::addColumn($pdo, 'eb_messages', 'source', "VARCHAR(20) DEFAULT NULL");
        // مورد ۸: شمارش خوانده‌نشده روی هر لود صفحه اجرا می‌شود → ایندکس لازم دارد
        self::addIndex($pdo, 'eb_messages', 'idx_dir_read', '(direction, read_at)');
        // مورد ۶: claim اتمیک صف — وضعیت واسط 'sending' + توکن مالکیت
        self::addColumn($pdo, 'eb_queue', 'claim_token', "VARCHAR(40) DEFAULT NULL");
        self::addColumn($pdo, 'eb_queue', 'claimed_at', "DATETIME DEFAULT NULL");
        self::addIndex($pdo, 'eb_queue', 'idx_claim', '(claim_token)');
        self::widenQueueStatus($pdo);

        // ---- دور بازخورد ۱۳ موردی: موارد ۵ و ۱۱ (لینک رهگیری) ----
        // مورد ۵: دسته‌بندی لینک (استوری/ریلز/سایت/…) برای جداسازی
        self::addColumn($pdo, 'eb_links', 'category', "VARCHAR(60) DEFAULT NULL");
        self::addIndex($pdo, 'eb_links', 'idx_category', '(category)');
        // مورد ۱۱: اطلاعات بیشتر بازدیدکننده در هر کلیک
        self::addColumn($pdo, 'eb_link_clicks', 'referer', "VARCHAR(500) DEFAULT NULL");
        self::addColumn($pdo, 'eb_link_clicks', 'device',  "VARCHAR(20) DEFAULT NULL");
        self::addColumn($pdo, 'eb_link_clicks', 'browser', "VARCHAR(40) DEFAULT NULL");
        self::addColumn($pdo, 'eb_link_clicks', 'os',      "VARCHAR(40) DEFAULT NULL");

        // ---- دور بازخورد ۱۳ موردی: مورد ۱۲ (پشتیبانی هوش مصنوعی) ----
        // ارجاع دستی یک گفتگو به AI؛ NULL = دست انسان است.
        self::addColumn($pdo, 'eb_persons', 'ai_handoff_at', "DATETIME DEFAULT NULL");

        // ---- دور بازخورد ۱۳ موردی: مورد ۱۳ (چت‌بات سایت) ----
        // نصب‌های قدیمی که جدول را داشتند ولی ستون‌های تازه را نه (idempotent).
        self::addColumn($pdo, 'eb_webchat_sessions', 'page_url', "VARCHAR(500) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_sessions', 'referer',  "VARCHAR(500) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_sessions', 'device',   "VARCHAR(20) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_sessions', 'browser',  "VARCHAR(40) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_sessions', 'os',       "VARCHAR(40) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_sessions', 'identified', "TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumn($pdo, 'eb_webchat_sessions', 'msg_count',  "INT NOT NULL DEFAULT 0");
        self::addIndex($pdo, 'eb_webchat_sessions', 'idx_wc_ip', '(ip, created_at)');
        self::addColumn($pdo, 'eb_webchat_outbox', 'source', "VARCHAR(20) DEFAULT NULL");
        self::addColumn($pdo, 'eb_webchat_outbox', 'delivered_at', "DATETIME DEFAULT NULL");

        // ---- مورد ۵: تیک خوانده‌شده برای پیام‌های پشتیبانی در گفتگوی آنلاین سایت ----
        // seen_at یعنی «بازدیدکننده این پیام خروجی را با ویجت باز/فعال دیده».
        // مستقل از read_at (که برای پیام دریافتی/سمت اپراتور استفاده می‌شود) تا معنا قاطی نشود.
        self::addColumn($pdo, 'eb_messages', 'seen_at', "DATETIME DEFAULT NULL");

        // ---- مورد ۱: کدام صفحه‌ی سایت را باز داشت وقتی این پیام را در گفتگوی آنلاین فرستاد ----
        self::addColumn($pdo, 'eb_messages', 'page_url', "VARCHAR(500) DEFAULT NULL");

        // ---- مورد ۳: حذف پیام با لاگ (soft-delete — متن اصلی برای لاگ همان‌جا می‌ماند) ----
        self::addColumn($pdo, 'eb_messages', 'deleted_at', "DATETIME DEFAULT NULL");
        self::addColumn($pdo, 'eb_messages', 'deleted_by', "INT DEFAULT NULL");

        // مورد ۶ نیازی به جدول تازه ندارد — eb_link_clicks از قبل هر کلیک را با
        // clicked_at ثبت می‌کند (بالاتر در این فایل تعریف شده)؛ فقط باید روزانه جمع بزنیم.

        // ---- مورد ۲/۵: فید اعلان داخل پنل (پیام تازه، فرم تکمیل‌شده) ----
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_notifications (
              id INT AUTO_INCREMENT PRIMARY KEY,
              type VARCHAR(30) NOT NULL DEFAULT 'general',
              title VARCHAR(255) NOT NULL,
              body VARCHAR(500) DEFAULT NULL,
              link VARCHAR(255) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::ensureAnalyticsTables($pdo);
        self::ensureRoboclickTables($pdo);
        // پاپ‌آپ فقط برای پیام‌های واقعاً خوانده‌نشده: باید بدانیم اعلان مال کدام شخص است
        self::addColumn($pdo, 'eb_notifications', 'person_id', "INT DEFAULT NULL");
        self::ensureInboxTeamTables($pdo);
        // ۱.۰.۱۴: کارت‌ها و دکمه‌های پیام، فهرست واحدها، تلفن گویا، رفع فیلتر + یکسان‌سازی شماره‌ها (۰۹…)
        if (class_exists('ChatPlus')) {
            ChatPlus::ensure(true);
            try { ChatPlus::migratePhones(8); } catch (Throwable $e) { error_log('[schema phones] ' . $e->getMessage()); }
        }
    }

    /**
     * دور ۳ صندوق ورودی: تخصیص چت به پشتیبان + نقش سرپرست + ایندکس‌های کارایی.
     * - eb_persons.assigned_user_id / assigned_at : چت الان بر عهده‌ی کیست
     * - eb_persons.first_responder_id             : اولین پشتیبانی که جواب داد (برچسب «شروع‌کننده»)
     * - eb_messages.sent_by                       : کدام کاربر این پیام را فرستاد (آمار پاسخ هر پشتیبان)
     * - eb_assign_log                             : سابقه‌ی بر عهده گرفتن / انتقال / سپردن به AI
     * - eb_chat_presence                          : چه کسی همین الان این چت را باز کرده (اعلام «X در حال پاسخ‌گویی است»)
     */
    private static function ensureInboxTeamTables($pdo): void
    {
        try { $pdo->exec("ALTER TABLE eb_users MODIFY role ENUM('admin','supervisor','operator','viewer') NOT NULL DEFAULT 'operator'"); } catch (Throwable $e) {}
        self::addColumn($pdo, 'eb_persons', 'assigned_user_id', "INT DEFAULT NULL");
        self::addColumn($pdo, 'eb_persons', 'assigned_at', "DATETIME DEFAULT NULL");
        self::addColumn($pdo, 'eb_persons', 'first_responder_id', "INT DEFAULT NULL");
        self::addColumn($pdo, 'eb_messages', 'sent_by', "INT DEFAULT NULL");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_assign_log (
              id INT AUTO_INCREMENT PRIMARY KEY,
              person_id INT NOT NULL,
              action VARCHAR(20) NOT NULL,
              from_user_id INT DEFAULT NULL,
              to_user_id INT DEFAULT NULL,
              by_user_id INT DEFAULT NULL,
              note VARCHAR(255) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_person (person_id, id),
              KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_chat_presence (
              person_id INT NOT NULL,
              user_id INT NOT NULL,
              typing TINYINT(1) NOT NULL DEFAULT 0,
              seen_at DATETIME NOT NULL,
              PRIMARY KEY (person_id, user_id),
              KEY idx_seen (seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // ---- کارایی (مورد ۵): ایندکس‌های ترکیبی برای کوئری‌های پرتکرار صندوق ورودی/مخاطبان ----
        self::addIndex($pdo, 'eb_messages', 'idx_ident_dir_created', '(identity_id, direction, created_at)');
        self::addIndex($pdo, 'eb_messages', 'idx_dir_created', '(direction, created_at)');
        self::addIndex($pdo, 'eb_messages', 'idx_sent_by', '(sent_by, created_at)');
        self::addIndex($pdo, 'eb_identities', 'idx_person_channel', '(person_id, channel)');
        self::addIndex($pdo, 'eb_identities', 'idx_channel_username', '(channel, username)');
        self::addIndex($pdo, 'eb_identities', 'idx_ig_account', '(ig_account_id)');
        self::addIndex($pdo, 'eb_persons', 'idx_assigned', '(assigned_user_id)');
        self::addIndex($pdo, 'eb_persons', 'idx_updated', '(updated_at)');
        self::addIndex($pdo, 'eb_person_groups', 'idx_pg_person', '(person_id, group_id)');
        self::addIndex($pdo, 'eb_ig_comments', 'idx_sender', '(ig_account_id, sender_id)');
        self::addIndex($pdo, 'eb_ig_comments', 'idx_username', '(sender_username)');
        self::addIndex($pdo, 'eb_ig_comments', 'idx_person', '(person_id)');
        self::addIndex($pdo, 'eb_notifications', 'idx_person', '(person_id)');
    }

    /**
     * سرویس‌دهنده (سرویس‌دهنده): چون یک اکانت می‌تواند چند پیج اینستاگرام وصل کند
     * (برخلاف دایرکت‌ساز که تک‌پیج و توی eb_settings بود)، یک جدول واقعی لازم
     * است: هر ردیف = یک پیج، با Account Key خودش و یک لیبل/رنگ برای نمایش در
     * اینباکس. ig_account_id روی eb_identities مشخص می‌کند پیام یک مخاطب از
     * کدام پیج آمده (چون IGSCOPED ID هر واقعاً هر کاربر به‌ازای هر پیج فرق دارد،
     * تداخل بین پیج‌ها اتفاق نمی‌افتد).
     */
    private static function ensureRoboclickTables($pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_ig_accounts (
              id INT AUTO_INCREMENT PRIMARY KEY,
              provider VARCHAR(20) NOT NULL DEFAULT 'roboclick',
              label VARCHAR(100) NOT NULL,
              color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
              username VARCHAR(150) DEFAULT NULL,
              account_key VARCHAR(190) NOT NULL,
              webhook_secret VARCHAR(190) DEFAULT NULL,
              roboclick_account_id VARCHAR(100) DEFAULT NULL,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::addColumn($pdo, 'eb_identities', 'ig_account_id', "INT DEFAULT NULL");
        self::addIndex($pdo, 'eb_identities', 'idx_ig_account', '(ig_account_id)');
        // اتصال از طریق یار API: igAccountId همان پیج در یار API (provider='apichi')
        self::addColumn($pdo, 'eb_ig_accounts', 'apichi_account_id', "VARCHAR(50) DEFAULT NULL");
        self::addIndex($pdo, 'eb_ig_accounts', 'idx_apichi_account', '(provider, apichi_account_id)');
        // اتوماسیون کامنت: خالی = روی همه‌ی پیج‌ها (شامل دایرکت‌ساز)؛ مقداردار = فقط همان پیج سرویس‌دهنده
        self::addColumn($pdo, 'eb_ig_comment_rules', 'ig_account_id', "INT DEFAULT NULL");
        // شرط‌های پیشرفته‌ی اتوماسیون کامنت: کلمات مستثنا + فقط یک‌بار برای هر کاربر
        self::addColumn($pdo, 'eb_ig_comment_rules', 'exclude_keywords', "VARCHAR(255) DEFAULT NULL");
        self::addColumn($pdo, 'eb_ig_comment_rules', 'once_per_user', "TINYINT(1) NOT NULL DEFAULT 0");
        // فیچرهایی که تو ویدیوهای واقعی پنل سرویس‌دهنده دیدیم و نداشتیم: تریگر با ریپلای استوری
        // (نه فقط کامنت) + بازه‌ی زمانی فعال‌بودن قانون (تاریخ شروع/پایان)
        self::addColumn($pdo, 'eb_ig_comment_rules', 'trigger_comment', "TINYINT(1) NOT NULL DEFAULT 1");
        self::addColumn($pdo, 'eb_ig_comment_rules', 'trigger_story_reply', "TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumn($pdo, 'eb_ig_comment_rules', 'story_id', "VARCHAR(64) DEFAULT NULL");
        self::addColumn($pdo, 'eb_ig_comment_rules', 'start_at', "DATETIME DEFAULT NULL");
        self::addColumn($pdo, 'eb_ig_comment_rules', 'end_at', "DATETIME DEFAULT NULL");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_ig_rule_fires (
              id INT AUTO_INCREMENT PRIMARY KEY,
              rule_id INT NOT NULL,
              sender_id VARCHAR(190) NOT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_rule_sender (rule_id, sender_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // تا بشود کامنت‌های هر پیج سرویس‌دهنده را جدا دید (جزئیات پیج/پست، نه فقط دایرکت‌ساز)
        self::addColumn($pdo, 'eb_ig_comments', 'ig_account_id', "INT DEFAULT NULL");

        // ---- اتصال پیج با OAuth+PKCE سرویس‌دهنده (CreateInstagramAuthLinkV2/CompleteInstagramConnection) ----
        // codeVerifier باید فقط سمت سرور بماند تا لحظه‌ی تکمیل (چند دقیقه)؛ یک جدول موقت کافی است.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_ig_oauth_pending (
              state VARCHAR(64) PRIMARY KEY,
              code_verifier VARCHAR(190) NOT NULL,
              external_user_id VARCHAR(100) NOT NULL,
              admin_id INT DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::addColumn($pdo, 'eb_ig_accounts', 'roboclick_external_user_id', "VARCHAR(100) DEFAULT NULL");
    }

    /**
     * جدول‌های ماژول «آنالیتیکس حرفه‌ای» (فاز ۱+۲: آنالیتیکس وب + رفتار/هیت‌مپ).
     * طبق analytics-architecture.md — همه‌چیز قفل پشت AnalyticsSettings::isActive()
     * است؛ ساختن جدول‌های خالی این‌جا بی‌ضرر است (مورد اصول پروژه: «هر مشتری
     * نخواست، بهش نمی‌دیم» یعنی کد/جدول هست ولی بدون فعال‌سازی هیچ داده‌ای نمی‌گیرد).
     */
    private static function ensureAnalyticsTables($pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_visitors (
              id INT AUTO_INCREMENT PRIMARY KEY,
              visitor_uid CHAR(32) NOT NULL,
              first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
              last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
              visits_count INT NOT NULL DEFAULT 1,
              person_id INT DEFAULT NULL,
              UNIQUE KEY uniq_visitor_uid (visitor_uid),
              KEY idx_person (person_id),
              KEY idx_last_seen (last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_sessions (
              id INT AUTO_INCREMENT PRIMARY KEY,
              visitor_id INT NOT NULL,
              started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              ended_at DATETIME DEFAULT NULL,
              entry_page VARCHAR(500) DEFAULT NULL,
              exit_page VARCHAR(500) DEFAULT NULL,
              referrer VARCHAR(500) DEFAULT NULL,
              utm_source VARCHAR(150) DEFAULT NULL,
              utm_medium VARCHAR(150) DEFAULT NULL,
              utm_campaign VARCHAR(150) DEFAULT NULL,
              device VARCHAR(20) DEFAULT NULL,
              browser VARCHAR(40) DEFAULT NULL,
              os VARCHAR(40) DEFAULT NULL,
              country VARCHAR(80) DEFAULT NULL,
              country_code VARCHAR(4) DEFAULT NULL,
              city VARCHAR(120) DEFAULT NULL,
              link_code VARCHAR(64) DEFAULT NULL,
              duration_sec INT NOT NULL DEFAULT 0,
              page_count INT NOT NULL DEFAULT 0,
              last_ping DATETIME DEFAULT CURRENT_TIMESTAMP,
              session_uid CHAR(40) DEFAULT NULL,
              KEY idx_visitor (visitor_id),
              KEY idx_started (started_at),
              KEY idx_last_ping (last_ping),
              UNIQUE KEY uniq_session_uid (session_uid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_pageviews (
              id INT AUTO_INCREMENT PRIMARY KEY,
              session_id INT NOT NULL,
              url VARCHAR(500) NOT NULL,
              title VARCHAR(255) DEFAULT NULL,
              scroll_depth_pct TINYINT UNSIGNED DEFAULT NULL,
              time_on_page_sec INT DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_session (session_id),
              KEY idx_url (url(190)),
              KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // کوتاه‌عمر — پاک‌سازی خودکار طبق analytics.retention_days_raw در cron.
        // کلیک/رِیج‌کلیک/کلیک‌مرده/حرکت خام این‌جا می‌آید؛ بعد از تجمیع در
        // eb_heatmap_grid و eb_stats_daily، دیگر لازم نیست.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_events (
              id INT AUTO_INCREMENT PRIMARY KEY,
              session_id INT NOT NULL,
              type VARCHAR(20) NOT NULL,
              x SMALLINT DEFAULT NULL,
              y SMALLINT DEFAULT NULL,
              viewport_w SMALLINT DEFAULT NULL,
              viewport_h SMALLINT DEFAULT NULL,
              page_url VARCHAR(500) DEFAULT NULL,
              meta_json TEXT,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_session (session_id),
              KEY idx_type_created (type, created_at),
              KEY idx_page (page_url(190))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // رول‌آپ روزانه — منبع نمودارهای طولانی‌مدت، برای همیشه می‌ماند
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_stats_daily (
              day DATE NOT NULL,
              pageviews INT NOT NULL DEFAULT 0,
              sessions INT NOT NULL DEFAULT 0,
              visitors_new INT NOT NULL DEFAULT 0,
              visitors_returning INT NOT NULL DEFAULT 0,
              avg_duration_sec INT NOT NULL DEFAULT 0,
              bounce_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
              PRIMARY KEY (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // هیت‌مپ به grid تجمیع می‌شود، نه نقطه‌به‌نقطه‌ی خام دائمی — وگرنه حجم منفجر می‌شود
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_heatmap_grid (
              id INT AUTO_INCREMENT PRIMARY KEY,
              page_url_hash CHAR(32) NOT NULL,
              page_url VARCHAR(500) DEFAULT NULL,
              viewport_bucket VARCHAR(10) NOT NULL DEFAULT 'desktop',
              grid_x SMALLINT NOT NULL,
              grid_y SMALLINT NOT NULL,
              kind VARCHAR(10) NOT NULL,
              hits INT NOT NULL DEFAULT 0,
              day DATE NOT NULL,
              UNIQUE KEY uniq_cell (page_url_hash, viewport_bucket, grid_x, grid_y, kind, day),
              KEY idx_page (page_url_hash),
              KEY idx_day (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // تشخیص خودکار رِیج‌کلیک/کلیک‌مرده — «نقاط مشکل‌دار» بدون نیاز اسکرول کل هیت‌مپ
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_problem_spots (
              id INT AUTO_INCREMENT PRIMARY KEY,
              page_url VARCHAR(500) NOT NULL,
              kind VARCHAR(20) NOT NULL,
              hits INT NOT NULL DEFAULT 0,
              last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_page (page_url(190)),
              KEY idx_kind (kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_funnels (
              id INT AUTO_INCREMENT PRIMARY KEY,
              name VARCHAR(255) NOT NULL,
              steps_json TEXT,
              is_active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS eb_funnel_hits (
              id INT AUTO_INCREMENT PRIMARY KEY,
              funnel_id INT NOT NULL,
              session_id INT NOT NULL,
              step_index INT NOT NULL DEFAULT 0,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_funnel_session_step (funnel_id, session_id, step_index),
              KEY idx_funnel (funnel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // مهاجرت‌های idempotent برای نصب‌های قدیمی‌تری که این جدول‌ها را قبلاً
        // بدون یکی-دو ستون تازه ساخته باشند (سازگاری به‌مرور)
        self::addColumn($pdo, 'eb_sessions', 'last_ping', "DATETIME DEFAULT CURRENT_TIMESTAMP");
        self::addColumn($pdo, 'eb_sessions', 'link_code', "VARCHAR(64) DEFAULT NULL");
    }

    /** جدول بودجه‌ی دوره‌ای هر کانال (ماهانه/کمپینی) را در صورت نبود می‌سازد. */
    private static function ensureBudgetsTable($pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_mkt_budgets (
              id INT AUTO_INCREMENT PRIMARY KEY,
              period VARCHAR(7) NOT NULL,
              channel VARCHAR(40) DEFAULT NULL,
              amount DECIMAL(14,0) NOT NULL DEFAULT 0,
              note VARCHAR(255) DEFAULT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_period_channel (period, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** جدول‌های عوامل (crew) و مراحل رودمپ هر محتوا را در صورت نبود می‌سازد. */
    private static function ensureCrewStepsTables($pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_mkt_content_crew (
              id INT AUTO_INCREMENT PRIMARY KEY,
              content_id INT NOT NULL,
              member_id INT DEFAULT NULL,
              prod_role VARCHAR(60) NOT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_content (content_id),
              KEY idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS eb_mkt_content_steps (
              id INT AUTO_INCREMENT PRIMARY KEY,
              content_id INT NOT NULL,
              step_name VARCHAR(120) NOT NULL,
              assignee_id INT DEFAULT NULL,
              due_date DATE DEFAULT NULL,
              done_at DATETIME DEFAULT NULL,
              sort_order INT DEFAULT 0,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              KEY idx_content (content_id),
              KEY idx_assignee (assignee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * trigger_type را در صورت ENUM‌بودن به VARCHAR(20) تبدیل می‌کند تا مقادیر تازه
     * (مثل on_contact) پذیرفته شوند. اگر از قبل VARCHAR باشد، کاری نمی‌کند.
     */
    private static function widenTriggerType($pdo): void
    {
        $row = $pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_auto_replies'
                   AND COLUMN_NAME = 'trigger_type'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && strtolower($row['DATA_TYPE']) === 'enum') {
            $pdo->exec(
                "ALTER TABLE eb_auto_replies
                 MODIFY COLUMN trigger_type VARCHAR(20) NOT NULL DEFAULT 'exact'"
            );
        }
    }

    /**
     * وضعیت eb_queue را در صورت نبودِ مقدار 'sending' گسترش می‌دهد.
     * این مقدار برای «claim اتمیک» لازم است: ردیف قبل از ارسال از pending به sending
     * می‌رود تا worker دوم (cron یا هل مرورگر) همان ردیف را برندارد و پیام دوبار نرود.
     * idempotent: اگر از قبل شامل sending باشد، کاری نمی‌کند.
     */
    private static function widenQueueStatus($pdo): void
    {
        $row = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_queue'
                   AND COLUMN_NAME = 'status'"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        if (stripos((string)$row['COLUMN_TYPE'], "'sending'") !== false) return;
        $pdo->exec(
            "ALTER TABLE eb_queue
             MODIFY COLUMN status ENUM('pending','sending','sent','failed') DEFAULT 'pending'"
        );
    }

    /** افزودن ایندکس در صورت نبود (امن و قابل تکرار). */
    private static function addIndex($pdo, string $table, string $name, string $cols): void
    {
        try {
            $exists = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$name}'"
            )->fetchColumn();
            if (!$exists) $pdo->exec("ALTER TABLE {$table} ADD INDEX {$name} {$cols}");
        } catch (Throwable $e) { /* ایندکس اختیاری است؛ نبودش نباید نصب را بترکاند */ }
    }

    private static function addColumn($pdo, string $table, string $col, string $def): void
    {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
        )->fetchColumn();
        if (!$exists) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
}
