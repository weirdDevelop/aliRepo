<?php
/**
 * core/Viewer.php — نقش «نمایشی» (فقط مشاهده)
 * ==========================================================================
 * برای نشان دادن کارکرد واقعی پنل به مشتری‌های بالقوه، روی یک سیستم تجاری واقعی.
 * کاربر نمایشی همه‌ی بخش‌ها و جزئیات را مثل مدیر می‌بیند، ولی هیچ کاری انجام نمی‌دهد:
 * نه پیامی می‌فرستد، نه تنظیمی را تغییر می‌دهد، نه چیزی می‌سازد یا حذف می‌کند.
 *
 * سه لایه‌ی محافظت (هر کدام به‌تنهایی جلوی تغییر را می‌گیرد):
 *   ۱) دروازه‌ی API: فقط اکشن‌های خواندنی فهرست READ اجرا می‌شوند؛ هر اکشن دیگری
 *      (ارسال، ذخیره، حذف، تست اتصال، آپلود، خروجی CSV و…) پیش از اجرا رد می‌شود.
 *   ۲) قفل دیتابیس: در نشست نمایشی، هر کوئری نوشتنی (INSERT/UPDATE/DELETE/…) که از
 *      Database::run بگذرد بی‌صدا اجرا نمی‌شود؛ حتی «خوانده‌شدن پیام» یا «حضور در چت».
 *   ۳) پوشاندن خروجی: کلیدها، توکن‌ها، رمزها و (به‌طور پیش‌فرض) بخشی از شماره‌ی موبایل و
 *      ایمیل مشتریان در پاسخ‌های API پوشانده می‌شود.
 * کاربر نمایشی در فهرست همکاران، انتقال چت، تخصیص خودکار و گزارش اپراتورها دیده نمی‌شود.
 */
class Viewer
{
    /** اکشن‌های خواندنی مجاز */
    const READ = [
        // داشبورد، صندوق ورودی، گفتگو
        'crm_status', 'crm_kpis', 'crm_person', 'crm_users', 'crm_timeline', 'crm_sync_status', 'crm_find_person',
        'desk_boot', 'desk_list', 'desk_counts', 'desk_thread', 'desk_ping', 'desk_inbox_cfg', 'desk_overview',
        'desk_agent_report', 'desk_audit', 'desk_followup_preview',
        'inbox_conversations', 'inbox_thread', 'inbox_ping', 'inbox_profile', 'inbox_unread_count', 'inbox_agents',
        'inbox_agent_stats', 'inbox_assign_history', 'notifications_poll', 'recent_activity', 'setup_status', 'stats_overview',
        'teamchat_thread', 'teamchat_unread', 'teamchat_users', 'support_status', 'webchat_sessions', 'webchat_settings',
        // مشتریان، گروه‌ها، الگوها
        'roles_list', 'persons_list', 'person_profile', 'person_search_min', 'groups_list', 'tags_list', 'properties_list',
        'templates_list', 'canned_list', 'canned_get', 'my_profile',
        // ارسال، کمپین، صف و تاریخچه
        'send_audience_count', 'sends_list', 'send_detail', 'queue_jobs', 'queue_summary', 'scheduled_list',
        'campaigns_list', 'campaign_get', 'links_list', 'link_detail', 'safir_get', 'voice_available',
        // اتوماسیون، فرم، هوش مصنوعی
        'autoreply_list', 'botnodes_list', 'botsettings_get', 'forms_list', 'form_responses', 'ai_settings', 'aikb_list',
        'flow_list', 'flow_get', 'flow_meta', 'flow_runs',
        // اینستاگرام
        'instagram_get', 'instagram_automation_list', 'instagram_forbidden_get', 'instagram_knowledge_list',
        'instagram_mgmt_status', 'instagram_mgmt_users_list', 'instagram_mgmt_raw_log', 'instagram_orders_list',
        'instagram_orders_items', 'instagram_products_list', 'instagram_phone_gate_get', 'ig_page_list',
        'ig_page_settings_get', 'igpost_list', 'roboclick_accounts_list', 'roboclick_integration_get',
        'roboclick_posts_list', 'roboclick_stories_list', 'roboclick_comments_list', 'roboclick_publish_history',
        'roboclick_stats_today', 'apichi_status', 'apichi_health', 'apichi_log_list', 'apichi_log_get',
        // کانال‌ها، تنظیمات، لاگ
        'channels_status', 'settings_get', 'sms_get', 'email_get', 'proxy_settings', 'v4_proxy_get',
        'operators_list', 'apilog_list', 'notices_list', 'notices_leads', 'notice_leads',
        // آنالیتیکس و سرچ کنسول
        'analytics_overview', 'analytics_audience', 'analytics_engagement', 'analytics_funnels', 'analytics_funnel_stats',
        'analytics_heatmap', 'analytics_heatmap_pages', 'analytics_links', 'analytics_pages_sources',
        'analytics_problem_spots', 'analytics_realtime', 'analytics_sessions', 'analytics_sessions_list',
        'analytics_session_detail', 'analytics_settings_get', 'v4_an_report', 'v4_an_options', 'v4_an_sessions', 'v4_an_views',
        'gsc_state', 'gsc_sites', 'gsc_performance', 'gsc_drill', 'gsc_opportunities', 'gsc_history', 'gsc_sitemaps', 'gsc_urls',
        // ویجت‌ها و مارکتینگ
        'fab_get', 'fab_stats', 'wcx_get',
        'mkt_ready', 'mkt_goals_list', 'mkt_roles_list', 'mkt_members_list', 'mkt_my_member', 'mkt_my_tasks',
        'mkt_users_linkable', 'mkt_content_list', 'mkt_content_detail', 'mkt_pages', 'mkt_finance', 'mkt_reports',
        'agent_get', 'agent_callbacks', 'agent_demand', 'agent_demand_csv', 'agent_log', 'agent_log_detail', 'agent_orders', 'agent_chat_state',
        'agent_kb_list', 'agent_kb_chunks', 'agent_kb_search', 'agent_security', 'agent_surveys', 'agent_report_preview',
        'mkt_budgets_list', 'mkt_seed_status', 'mkt_sendable', 'mktm_get', 'mktm_posts', 'mktm_report', 'mktm_latest',
    ];

    /** اکشن‌های پس‌زمینه‌ی رابط (حضور، ترجیحات نمایش): بدون اجرا «موفق» برمی‌گردند تا خطا نمایش داده نشود */
    const SILENT = ['desk_presence', 'desk_prefs', 'inbox_leave', 'gsc_alert_seen'];

    const MSG = 'این حساب نمایشی است؛ همه‌ی بخش‌ها را می‌بینید، ولی ارسال، ذخیره، حذف یا تغییر تنظیمات در آن غیرفعال است.';

    public static function active(): bool
    {
        return class_exists('Auth') && Auth::isViewer();
    }

    /** پیش از اجرای هر API در index.php صدا زده می‌شود */
    public static function gateApi(string $api): void
    {
        if (!self::active()) return;
        if (in_array($api, self::READ, true)) return;
        // مدیری که «به‌جای» حساب نمایشی وارد شده باید بتواند برگردد
        if ($api === 'desk_impersonate_stop' && Auth::impersonator()) return;
        header('Content-Type: application/json; charset=utf-8');
        if (in_array($api, self::SILENT, true)) exit('{"ok":true,"demo":true}');
        exit(json_encode(['ok' => false, 'demo' => true, 'error' => self::MSG], JSON_UNESCAPED_UNICODE));
    }

    /** خروجی فایل (CSV) برای حساب نمایشی بسته است */
    public static function gateExport(): void
    {
        if (!self::active()) return;
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('در حساب نمایشی، گرفتن خروجی از داده‌ها غیرفعال است.');
    }

    /** آیا شماره و ایمیل مشتریان پوشانده شود؟ (پیش‌فرض: بله) */
    public static function maskPii(): bool
    {
        $p = Auth::permissions();
        return !array_key_exists('mask_pii', $p) || !empty($p['mask_pii']);
    }

    /** پوشاندن داده‌های حساس در پاسخ API */
    public static function mask($data)
    {
        if (!self::active()) return $data;
        return self::walk($data, '', self::maskPii());
    }

    private static function walk($v, string $key, bool $pii)
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) $v[$k] = self::walk($x, is_string($k) ? $k : $key, $pii);
            return $v;
        }
        if (!is_string($v) || $v === '') return $v;
        if (self::secretKey($key)) return '••••••••';
        return self::scrub($v, $pii);
    }

    private static function secretKey(string $k): bool
    {
        if ($k === '' || preg_match('/^(has|is)_|_(set|masked|configured)$/i', $k)) return false;
        return (bool)preg_match('/(^|[_-])(token|secret|password|passwd|pass|pwd|api_?key|apikey|access_key|private_key|cron_key|app_key|account_key|integration_key|client_secret|app_secret|webhook_secret|verify_token|access_token|refresh_token)$/i', $k);
    }

    private static function scrub(string $s, bool $pii): string
    {
        // توکن ربات بله/تلگرام، کلیدهای API و پارامترهای کلید در آدرس‌ها و لاگ‌ها
        $s = preg_replace('/(?<!\d)\d{5,12}:[A-Za-z0-9_\-]{20,}/', '••••••••', $s) ?? $s;
        $s = preg_replace('/\b(rk_|ak_|sk-|sk_|Bearer\s+)[A-Za-z0-9_\-\.]{12,}/', '$1••••••••', $s) ?? $s;
        $s = preg_replace('/([?&](?:key|token|api_key|apikey|access_token|secret|password|pass)=)[^&\s"\'<]+/i', '$1••••••', $s) ?? $s;
        if ($pii) {
            // موبایل ایران: 0912•••••67 / +98912•••••67
            $s = preg_replace_callback('/(?<![\d\/])(\+98|0098|98|0)(9\d{2})(\d{5})(\d{2})(?!\d)/', fn($m) => $m[1] . $m[2] . '•••••' . $m[4], $s) ?? $s;
            // ایمیل: a•••@domain
            $s = preg_replace_callback('/\b([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/', fn($m) => $m[1] . '•••@' . $m[2], $s) ?? $s;
        }
        return $s;
    }

    /** اضافه کردن «viewer» به نقش‌های جدول کاربران (یک‌بار، بدون اجرای کامل به‌روزرسانی دیتابیس) */
    public static function ensureRole(): void
    {
        try {
            $c = Database::one("SHOW COLUMNS FROM eb_users LIKE 'role'");
            if ($c && stripos((string)$c['Type'], 'viewer') === false) {
                Database::pdo()->exec("ALTER TABLE eb_users MODIFY role ENUM('admin','supervisor','operator','viewer') NOT NULL DEFAULT 'operator'");
                Database::forgetSchemaCache();
            }
        } catch (Throwable $e) {
            error_log('[viewer] ' . $e->getMessage());
        }
    }
}
