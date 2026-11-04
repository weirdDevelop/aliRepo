<?php
/**
 * modules/AnalyticsRollup.php
 * کارهای سنگین آنالیتیکس — همه در cron، هرگز در مسیر درخواستِ بازدیدکننده:
 *   ۱) رول‌آپ روزانه (eb_stats_daily) از eb_sessions/eb_pageviews — همیشه
 *      «امروز» و «دیروز» را دوباره حساب می‌کند (idempotent، بدون شمارش دوبل)
 *   ۲) تجمیع هیت‌مپ (eb_heatmap_grid) از eb_events خام — به‌صورت افزایشی با
 *      یک نشانگرِ «تا کجا پردازش شده» در eb_settings (نه بازشمارش کل تاریخچه)
 *   ۳) تشخیص رِیج‌کلیک/کلیک‌مرده → eb_problem_spots
 *   ۴) پاک‌سازی eb_events خام قدیمی‌تر از retention_days_raw
 *
 * فراخوانی: از cron.php، با گیت زمانی (حداقل هر ~۵۵ دقیقه یک‌بار) تا هر
 * اجرای دقیقه‌ای cron این محاسبات نسبتاً سنگین را دوباره‌وباره نزند.
 */

class AnalyticsRollup
{
    const GRID_CELL_PX = 40; // اندازه‌ی هر خانه‌ی گرید هیت‌مپ (پیکسل)

    /**
     * نقطه‌ی ورود از cron.php — خودش تصمیم می‌گیرد که کار کند یا نه (گیت ساعتی).
     * از پنل (دکمه‌ی «اجرای دستی») هم می‌شود با $force=true صدا زد تا گیت
     * زمانی نادیده گرفته شود — برای تست فوری بدون منتظر ماندن برای cron واقعی.
     */
    public static function run(bool $force = false): string
    {
        if (!class_exists('AnalyticsSettings') || !AnalyticsSettings::isActive()) {
            return 'analytics: غیرفعال — رد شد';
        }

        if (!$force) {
            $last = Database::one("SELECT v FROM eb_settings WHERE k='analytics.last_rollup_at'");
            $lastAt = $last['v'] ?? null;
            if ($lastAt && strtotime($lastAt) > time() - 3300) { // کمتر از ۵۵ دقیقه از اجرای قبلی
                return 'analytics: زود است، رد شد';
            }
        }

        $statsN = self::rollupStatsDaily(3);
        $heatN = self::rollupHeatmap();
        $cleaned = self::cleanupOldEvents();
        $cleanedSessions = self::cleanupOldSessions();

        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES ('analytics.last_rollup_at', NOW())
             ON DUPLICATE KEY UPDATE v = NOW()"
        );

        return "analytics: stats_days={$statsN} heatmap_cells={$heatN} cleaned_events={$cleaned} cleaned_sessions={$cleanedSessions}";
    }

    /**
     * eb_stats_daily را برای $daysBack روز اخیر دوباره از صفر می‌سازد (نه
     * افزایشی) — چون eb_sessions/eb_pageviews خودشان پاک‌سازی نمی‌شوند، این
     * کار همیشه امن و بدون شمارش دوبل است.
     */
    private static function rollupStatsDaily(int $daysBack): int
    {
        $n = 0;
        // ۲.۱.۱: هر سایت یک ردیف روزانه (site_id ۰ = سایت اصلی). با یک سایت، رفتار قبلی عیناً حفظ می‌شود.
        $multi = class_exists('SiteCtx') && SiteCtx::multi() && Database::hasColumn('eb_stats_daily', 'site_id');
        $targets = [['id' => 0, 'cond' => '1=1']];
        if ($multi) {
            $def = SiteCtx::defaultId();
            $targets = [['id' => 0, 'cond' => "(s.site_id IS NULL OR s.site_id = {$def})"]];
            foreach (SiteCtx::all() as $st) if (empty($st['is_default'])) $targets[] = ['id' => (int)$st['id'], 'cond' => 's.site_id = ' . (int)$st['id']];
        }
        for ($i = 0; $i < $daysBack; $i++) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
          foreach ($targets as $tg) {
            $agg = Database::one(
                "SELECT
                    COUNT(DISTINCT s.id) AS sessions,
                    COALESCE(SUM(pv.cnt), 0) AS pageviews,
                    AVG(TIMESTAMPDIFF(SECOND, s.started_at, COALESCE(s.last_ping, s.started_at))) AS avg_dur,
                    SUM(CASE WHEN pv.cnt <= 1 THEN 1 ELSE 0 END) AS bounces
                 FROM eb_sessions s
                 LEFT JOIN (
                    SELECT session_id, COUNT(*) cnt FROM eb_pageviews
                    WHERE DATE(created_at) = ? GROUP BY session_id
                 ) pv ON pv.session_id = s.id
                 WHERE DATE(s.started_at) = ? AND " . $tg['cond'],
                [$day, $day]
            );

            $newVisitors = $multi
                ? (Database::one(
                    "SELECT COUNT(DISTINCT v.id) c FROM eb_visitors v JOIN eb_sessions s ON s.visitor_id = v.id
                     WHERE DATE(v.first_seen) = ? AND DATE(s.started_at) = ? AND " . $tg['cond'],
                    [$day, $day]
                )['c'] ?? 0)
                : (Database::one(
                    "SELECT COUNT(*) c FROM eb_visitors WHERE DATE(first_seen) = ?",
                    [$day]
                )['c'] ?? 0);

            $sessions = (int)($agg['sessions'] ?? 0);
            $pageviews = (int)($agg['pageviews'] ?? 0);
            $bounces = (int)($agg['bounces'] ?? 0);
            $returning = max(0, $sessions - (int)$newVisitors);
            $bounceRate = $sessions > 0 ? round(($bounces / $sessions) * 100, 2) : 0;

            $cols = $multi ? "day, site_id, pageviews" : "day, pageviews";
            $ph = $multi ? "?, ?, ?" : "?, ?";
            $vals = $multi ? [$day, $tg['id'], $pageviews] : [$day, $pageviews];
            Database::run(
                "INSERT INTO eb_stats_daily
                    ($cols, sessions, visitors_new, visitors_returning, avg_duration_sec, bounce_rate)
                 VALUES ($ph, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    pageviews=VALUES(pageviews), sessions=VALUES(sessions),
                    visitors_new=VALUES(visitors_new), visitors_returning=VALUES(visitors_returning),
                    avg_duration_sec=VALUES(avg_duration_sec), bounce_rate=VALUES(bounce_rate)",
                array_merge($vals, [$sessions, (int)$newVisitors, $returning, (int)round($agg['avg_dur'] ?? 0), $bounceRate])
            );
            $n++;
          }
        }
        return $n;
    }

    /**
     * پنجره‌ی جدید eb_events (کلیک/حرکت) را از آخرین نشانگر تا الان می‌گیرد،
     * به گرید ۴۰×۴۰ پیکسلی تجمیع می‌کند، و نقاط مشکل‌دار را جدا ثبت می‌کند.
     * چرا نشانگر و نه «همه‌ی eb_events»؟ چون بعد از پاک‌سازی رویدادهای قدیمی
     * (بخش cleanupOldEvents)، بازشمارش کل تاریخچه ممکن نیست و لازم هم نیست —
     * هر رویداد دقیقاً یک‌بار در پنجره‌ی خودش شمرده می‌شود.
     */
    private static function rollupHeatmap(): int
    {
        $markRow = Database::one("SELECT v FROM eb_settings WHERE k='analytics.heatmap_mark_id'");
        $sinceId = (int)($markRow['v'] ?? 0);

        $rows = Database::all(
            "SELECT id, type, x, y, viewport_w, page_url, created_at FROM eb_events
             WHERE id > ? AND type IN ('click','move','rage_click','dead_click')
             ORDER BY id ASC LIMIT 20000",
            [$sinceId]
        );
        if (!$rows) return 0;

        $cells = 0;
        $maxId = $sinceId;
        $problemCounts = []; // ['url|kind' => count]

        foreach ($rows as $r) {
            $maxId = max($maxId, (int)$r['id']);
            if ($r['x'] === null || $r['y'] === null || !$r['page_url']) continue;

            $bucket = ((int)($r['viewport_w'] ?? 1024)) >= 900 ? 'desktop' : 'mobile';
            $gx = intdiv((int)$r['x'], self::GRID_CELL_PX);
            $gy = intdiv((int)$r['y'], self::GRID_CELL_PX);
            $day = date('Y-m-d', strtotime($r['created_at']));
            $urlHash = md5($r['page_url']);
            $kind = $r['type'] === 'move' ? 'move' : 'click'; // رِیج/مرده هم زیر «click» گرید می‌رود، جدا هم شمرده می‌شود

            Database::run(
                "INSERT INTO eb_heatmap_grid (page_url_hash, page_url, viewport_bucket, grid_x, grid_y, kind, hits, day)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1",
                [$urlHash, self::truncateUrl($r['page_url']), $bucket, $gx, $gy, $kind, $day]
            );
            $cells++;

            if (in_array($r['type'], ['rage_click', 'dead_click'], true)) {
                $key = $r['page_url'] . '|' . $r['type'];
                $problemCounts[$key] = ($problemCounts[$key] ?? 0) + 1;
            }
        }

        foreach ($problemCounts as $key => $count) {
            [$url, $kind] = explode('|', $key, 2);
            $existing = Database::one(
                "SELECT id FROM eb_problem_spots WHERE page_url=? AND kind=? AND last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [self::truncateUrl($url), $kind]
            );
            if ($existing) {
                Database::run(
                    "UPDATE eb_problem_spots SET hits = hits + ?, last_seen = NOW() WHERE id=?",
                    [$count, $existing['id']]
                );
            } else {
                Database::run(
                    "INSERT INTO eb_problem_spots (page_url, kind, hits, last_seen) VALUES (?, ?, ?, NOW())",
                    [self::truncateUrl($url), $kind, $count]
                );
            }
        }

        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES ('analytics.heatmap_mark_id', ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [(string)$maxId]
        );

        return $cells;
    }

    private static function cleanupOldEvents(): int
    {
        $days = (int)(class_exists('AnalyticsSettings') ? AnalyticsSettings::get('retention_days_raw') : 90);
        $days = max(7, min(365, $days ?: 90));
        $stmt = Database::run(
            "DELETE FROM eb_events WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        return $stmt->rowCount();
    }

    /**
     * مورد ۹: پاک‌سازی خودکار eb_sessions/eb_pageviews خام قدیمی — طبق اصل
     * «رویداد خام کوتاه‌عمر، رول‌آپ درازعمر» در سند معماری. eb_stats_daily
     * (که همیشگی است) قبلاً این روزها را در rollupStatsDaily حساب کرده،
     * پس حذف خودِ ردیف‌های خام هیچ عددی را از داشبورد گم نمی‌کند — فقط
     * جزئیات ریز (کدام URL دقیقاً، کدام بازدیدکننده دقیقاً) از بین می‌رود.
     * حداقل ۳۰ روز نگه‌داری اجباری است تا با بازه‌ی گزارش‌های ۳۰ روزه تداخل نکند.
     */
    private static function cleanupOldSessions(): int
    {
        $days = (int)(class_exists('AnalyticsSettings') ? AnalyticsSettings::get('retention_days_sessions') : 180);
        $days = max(30, min(730, $days ?: 180));

        $oldSessionIds = Database::all(
            "SELECT id FROM eb_sessions WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000",
            [$days]
        );
        if (!$oldSessionIds) return 0;
        $ids = array_column($oldSessionIds, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));

        Database::run("DELETE FROM eb_pageviews WHERE session_id IN ($ph)", $ids);
        $stmt = Database::run("DELETE FROM eb_sessions WHERE id IN ($ph)", $ids);
        return $stmt->rowCount();
    }

    private static function truncateUrl(?string $u): string
    {
        return mb_substr((string)$u, 0, 500);
    }
}
