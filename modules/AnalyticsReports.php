<?php
/**
 * modules/AnalyticsReports.php
 * کوئری‌های سمت خواندن برای صفحه‌ی ادمین آنالیتیکس — نمای کلی، لحظه‌ای،
 * صفحات/منابع، مخاطبان، هیت‌مپ، قیف. هیچ‌کدام این‌ها چیزی نمی‌نویسند.
 */

class AnalyticsReports
{
    /**
     * خلاصه‌ی «امروز» — مورد ۷: چند بازدیدکننده‌ی یکتا امروز وارد شدند، چند
     * نفرشان از یک لینک رهگیری آمدند (خودکار از r.php نشانه‌گذاری می‌شود)،
     * و کدام لینک‌ها بیشترین ورودی امروز را داشته‌اند.
     */
    public static function todaySummary(): array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $uniqueVisitors = Database::one(
            "SELECT COUNT(DISTINCT visitor_id) c FROM {$SA} WHERE DATE(started_at) = CURDATE()"
        )['c'] ?? 0;

        $sessionsToday = Database::one(
            "SELECT COUNT(*) c FROM {$SA} WHERE DATE(started_at) = CURDATE()"
        )['c'] ?? 0;

        $viaLink = Database::one(
            "SELECT COUNT(*) c FROM {$SA} WHERE DATE(started_at) = CURDATE() AND link_code IS NOT NULL"
        )['c'] ?? 0;

        $topLinks = Database::all(
            "SELECT s.link_code, COUNT(*) c, l.title
             FROM {$S} s
             LEFT JOIN eb_links l ON l.code = s.link_code
             WHERE DATE(s.started_at) = CURDATE() AND s.link_code IS NOT NULL
             GROUP BY s.link_code, l.title ORDER BY c DESC LIMIT 10"
        );

        return [
            'unique_visitors' => (int)$uniqueVisitors,
            'sessions' => (int)$sessionsToday,
            'via_link' => (int)$viaLink,
            'via_direct_or_other' => max(0, (int)$sessionsToday - (int)$viaLink),
            'top_links' => $topLinks,
        ];
    }

    // ==================== نشست‌ها (مورد ۳: رفتار هر بازدیدکننده به‌صورت جدا) ====================

    /** فهرست نشست‌های اخیر برای انتخاب و دیدن جزئیات هرکدام */
    public static function recentSessions(int $days = 30, int $limit = 100): array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $days = max(1, min(180, $days));
        return Database::all(
            "SELECT s.id, s.started_at, s.entry_page, s.exit_page, s.referrer,
                    s.utm_source, s.link_code, s.device, s.browser, s.country, s.city,
                    s.duration_sec, s.page_count, v.visitor_uid, v.person_id, p.full_name
             FROM {$S} s
             JOIN eb_visitors v ON v.id = s.visitor_id
             LEFT JOIN eb_persons p ON p.id = v.person_id
             WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY s.id DESC LIMIT ?",
            [$days, $limit]
        );
    }

    /**
     * جزئیات کامل یک نشست: از کجا آمده، کدام صفحات را به چه ترتیبی دیده،
     * چقدر روی هرکدام مانده، چقدر اسکرول کرده، و چند کلیک/رِیج‌کلیک/کلیک‌مرده
     * روی هر صفحه داشته — یعنی «سفر» کامل همان یک بازدیدکننده.
     */
    public static function sessionDetail(int $sessionId): ?array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $session = Database::one(
            "SELECT s.*, v.visitor_uid, v.person_id, p.full_name, p.phone
             FROM {$S} s
             JOIN eb_visitors v ON v.id = s.visitor_id
             LEFT JOIN eb_persons p ON p.id = v.person_id
             WHERE s.id = ?",
            [$sessionId]
        );
        if (!$session) return null;

        $pageviews = Database::all(
            "SELECT id, url, title, scroll_depth_pct, time_on_page_sec, created_at
             FROM eb_pageviews WHERE session_id = ? ORDER BY id ASC",
            [$sessionId]
        );

        $eventCounts = Database::all(
            "SELECT page_url, type, COUNT(*) c FROM eb_events
             WHERE session_id = ? GROUP BY page_url, type",
            [$sessionId]
        );
        $byPage = [];
        foreach ($eventCounts as $e) {
            $byPage[$e['page_url']][$e['type']] = (int)$e['c'];
        }
        foreach ($pageviews as &$pv) {
            $pv['events'] = $byPage[$pv['url']] ?? [];
        }
        unset($pv);

        return ['session' => $session, 'pageviews' => $pageviews];
    }

    /** خلاصه‌ی کارتی + روند روزانه برای نمودار خط */
    public static function overview(int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $rows = Database::all(
            "SELECT day, pageviews, sessions, visitors_new, visitors_returning, avg_duration_sec, bounce_rate
             FROM eb_stats_daily WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)" . (SiteCtx::multi() && Database::hasColumn('eb_stats_daily', 'site_id') ? ' AND site_id = ' . SiteCtx::dailyId() : '') . " ORDER BY day ASC",
            [$days - 1]
        );
        $byDay = [];
        foreach ($rows as $r) { $byDay[$r['day']] = $r; }

        $labels = []; $pv = []; $ses = []; $newV = []; $retV = [];
        $totalPv = 0; $totalSes = 0; $totalNew = 0; $sumBounce = 0; $sumDur = 0; $cnt = 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $r = $byDay[$d] ?? null;
            $labels[] = $d;
            $pv[]   = (int)($r['pageviews'] ?? 0);
            $ses[]  = (int)($r['sessions'] ?? 0);
            $newV[] = (int)($r['visitors_new'] ?? 0);
            $retV[] = (int)($r['visitors_returning'] ?? 0);
            if ($r) {
                $totalPv += (int)$r['pageviews'];
                $totalSes += (int)$r['sessions'];
                $totalNew += (int)$r['visitors_new'];
                $sumBounce += (float)$r['bounce_rate'];
                $sumDur += (int)$r['avg_duration_sec'];
                $cnt++;
            }
        }

        return [
            'days' => $labels,
            'pageviews' => $pv,
            'sessions' => $ses,
            'visitors_new' => $newV,
            'visitors_returning' => $retV,
            'summary' => [
                'pageviews' => $totalPv,
                'sessions' => $totalSes,
                'visitors_new' => $totalNew,
                'avg_bounce_rate' => $cnt ? round($sumBounce / $cnt, 1) : 0,
                'avg_duration_sec' => $cnt ? (int)round($sumDur / $cnt) : 0,
            ],
        ];
    }

    /** چند نفر همین الان روی سایت‌اند (نشستی که در ۵ دقیقه‌ی اخیر پینگ زده) */
    public static function realtime(): array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $active = Database::all(
            "SELECT s.id, s.exit_page AS current_page, s.device, s.browser, s.country, s.city, s.last_ping
             FROM {$S} s
             WHERE s.last_ping >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY s.last_ping DESC LIMIT 200"
        );
        $byPage = [];
        foreach ($active as $a) {
            $p = $a['current_page'] ?: '—';
            $byPage[$p] = ($byPage[$p] ?? 0) + 1;
        }
        arsort($byPage);
        return [
            'count' => count($active),
            'visitors' => $active,
            'top_pages' => array_slice($byPage, 0, 10, true),
        ];
    }

    /** صفحات برتر/ورودی/خروجی + منابع ترافیک + UTM */
    public static function pagesAndSources(int $days = 30): array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $days = max(1, min(180, $days));

        $topPages = Database::all(
            "SELECT url, COUNT(*) views, AVG(time_on_page_sec) avg_time, AVG(scroll_depth_pct) avg_scroll
             FROM eb_pageviews WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY url ORDER BY views DESC LIMIT 15",
            [$days]
        );
        $entryPages = Database::all(
            "SELECT entry_page url, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND entry_page IS NOT NULL
             GROUP BY entry_page ORDER BY c DESC LIMIT 10",
            [$days]
        );
        $exitPages = Database::all(
            "SELECT exit_page url, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND exit_page IS NOT NULL
             GROUP BY exit_page ORDER BY c DESC LIMIT 10",
            [$days]
        );

        $sources = Database::all(
            "SELECT
                CASE
                    WHEN utm_source IS NOT NULL AND utm_source <> '' THEN utm_source
                    WHEN referrer IS NULL OR referrer = '' THEN 'مستقیم'
                    WHEN referrer LIKE '%google.%' THEN 'گوگل'
                    WHEN referrer LIKE '%instagram.%' THEN 'اینستاگرام'
                    WHEN referrer LIKE '%t.me%' OR referrer LIKE '%telegram%' THEN 'تلگرام'
                    ELSE 'ارجاعی — سایر'
                END AS source,
                COUNT(*) c
             FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY source ORDER BY c DESC",
            [$days]
        );

        $campaigns = Database::all(
            "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) c
             FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND utm_campaign IS NOT NULL AND utm_campaign <> ''
             GROUP BY utm_source, utm_medium, utm_campaign ORDER BY c DESC LIMIT 15",
            [$days]
        );

        return compact('topPages', 'entryPages', 'exitPages', 'sources', 'campaigns');
    }

    /** دستگاه/مرورگر/سیستم‌عامل/جغرافیا + لیست بازدیدکننده‌های شناسایی‌شده */
    public static function audience(int $days = 30): array
    {
        $S = SiteCtx::sess(); $SA = SiteCtx::sessAs();
        $days = max(1, min(180, $days));
        $devices = Database::all(
            "SELECT COALESCE(device,'نامعلوم') d, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY d ORDER BY c DESC",
            [$days]
        );
        $browsers = Database::all(
            "SELECT COALESCE(browser,'نامعلوم') b, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY b ORDER BY c DESC LIMIT 10",
            [$days]
        );
        $os = Database::all(
            "SELECT COALESCE(os,'نامعلوم') o, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY o ORDER BY c DESC LIMIT 10",
            [$days]
        );
        $countries = Database::all(
            "SELECT COALESCE(country,'نامعلوم') country, country_code, COUNT(*) c FROM {$SA}
             WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY country, country_code ORDER BY c DESC LIMIT 15",
            [$days]
        );

        $identified = Database::all(
            "SELECT v.id, v.visitor_uid, v.person_id, p.full_name, p.phone, v.visits_count, v.last_seen
             FROM eb_visitors v JOIN eb_persons p ON p.id = v.person_id
             WHERE v.last_seen >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY v.last_seen DESC LIMIT 50",
            [$days]
        );

        return compact('devices', 'browsers', 'os', 'countries', 'identified');
    }

    /** گرید هیت‌مپ یک صفحه (برای رندر overlay در پنل) */
    public static function heatmapFor(string $pageUrl, string $bucket = 'desktop', int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $hash = md5($pageUrl);
        $rows = Database::all(
            "SELECT grid_x, grid_y, kind, SUM(hits) hits FROM eb_heatmap_grid
             WHERE page_url_hash = ? AND viewport_bucket = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY grid_x, grid_y, kind",
            [$hash, $bucket, $days]
        );
        return ['cell_size' => AnalyticsRollup::GRID_CELL_PX, 'cells' => $rows];
    }

    /** فهرست صفحاتی که هیت‌مپ برایشان داده دارند (برای dropdown انتخاب صفحه) */
    public static function heatmapPages(int $days = 30): array
    {
        $days = max(1, min(180, $days));
        return Database::all(
            "SELECT page_url, MAX(page_url_hash) page_url_hash, SUM(hits) total
             FROM eb_heatmap_grid WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY page_url ORDER BY total DESC LIMIT 30",
            [$days]
        );
    }

    public static function problemSpots(int $limit = 20): array
    {
        return Database::all(
            "SELECT page_url, kind, hits, last_seen FROM eb_problem_spots
             ORDER BY hits DESC LIMIT ?",
            [$limit]
        );
    }

    // ==================== قیف تبدیل ====================

    public static function funnels(): array
    {
        $rows = Database::all("SELECT * FROM eb_funnels ORDER BY id DESC");
        foreach ($rows as &$r) { $r['steps'] = json_decode($r['steps_json'] ?? '[]', true) ?: []; }
        unset($r);
        return $rows;
    }

    /** ذخیره‌ی قیف: steps = [{label, url_contains}, ...] به ترتیب مرحله */
    public static function saveFunnel(?int $id, string $name, array $steps): int
    {
        $clean = [];
        foreach ($steps as $s) {
            $label = trim((string)($s['label'] ?? ''));
            $url = trim((string)($s['url_contains'] ?? ''));
            if ($label === '' || $url === '') continue;
            $clean[] = ['label' => $label, 'url_contains' => $url];
        }
        if (count($clean) < 2) throw new InvalidArgumentException('قیف حداقل به دو مرحله نیاز دارد');
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

        if ($id) {
            Database::run("UPDATE eb_funnels SET name=?, steps_json=? WHERE id=?", [$name, $json, $id]);
            return $id;
        }
        Database::run("INSERT INTO eb_funnels (name, steps_json) VALUES (?, ?)", [$name, $json]);
        return Database::lastId();
    }

    public static function deleteFunnel(int $id): void
    {
        Database::run("DELETE FROM eb_funnels WHERE id=?", [$id]);
        Database::run("DELETE FROM eb_funnel_hits WHERE funnel_id=?", [$id]);
    }

    /**
     * محاسبه‌ی drop-off هر مرحله. چون eb_funnel_hits از قبل پر نمی‌شود (نیاز
     * به یک کرون جدا برای تطبیق session→funnel دارد)، این‌جا مستقیم و زنده از
     * روی eb_pageviews محاسبه می‌کنیم — برای اندازه‌ی داده‌ی این فاز کافی است؛
     * اگر بعداً حجم بالا رفت، می‌شود پیش‌محاسبه‌شده در cron ذخیره کرد.
     */
    public static function funnelStats(int $funnelId, int $days = 30): array
    {
        $days = max(1, min(180, $days));
        $f = Database::one("SELECT * FROM eb_funnels WHERE id=?", [$funnelId]);
        if (!$f) return ['steps' => []];
        $steps = json_decode($f['steps_json'] ?? '[]', true) ?: [];
        if (!$steps) return ['steps' => []];

        // مجموعه‌ی session_id هایی که تا هر مرحله رسیده‌اند (تجمعی، نه لزوماً به ترتیب زمانی دقیق —
        // برای MVP کافی است؛ نسخه‌ی دقیق‌تر ترتیب زمانی هر session را هم چک می‌کند)
        $prevSet = null;
        $out = [];
        foreach ($steps as $i => $s) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s['url_contains']) . '%';
            $rows = Database::all(
                "SELECT DISTINCT session_id FROM eb_pageviews
                 WHERE url LIKE ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$like, $days]
            );
            $set = array_column($rows, 'session_id');
            if ($prevSet !== null) {
                $set = array_values(array_intersect($set, $prevSet));
            }
            $out[] = ['label' => $s['label'], 'count' => count($set)];
            $prevSet = $set;
        }

        $first = $out[0]['count'] ?? 0;
        foreach ($out as &$row) {
            $row['pct_of_first'] = $first > 0 ? round(($row['count'] / $first) * 100, 1) : 0;
        }
        unset($row);

        return ['steps' => $out];
    }
}
