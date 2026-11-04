<?php
/**
 * modules/AnalyticsQuery.php
 * گزارش آنالیتیکس با فیلتر: بازه‌ی دلخواه (از/تا)، مقایسه با دوره‌ی قبل، و فیلتر دستگاه، مرورگر،
 * سیستم‌عامل، کشور، شهر، کانال ورود، منبع، کمپین UTM، صفحه، صفحه‌ی ورود، لینک رهگیری، نوع بازدیدکننده.
 *
 * همه‌چیز از داده‌ی خام نشست‌ها (eb_sessions / eb_pageviews) حساب می‌شود، چون رول‌آپ روزانه فیلتر ندارد.
 * تاریخ‌ها به ساعت محلی (Tz) گروه‌بندی می‌شوند تا «امروز» در پنل همان امروزِ کاربر باشد.
 */
class AnalyticsQuery
{
    const MAX_DAYS = 400;

    /** کانال ورود (دسته‌بندی کلی) */
    const CHANNEL_SQL = "CASE
        WHEN s.link_code IS NOT NULL AND s.link_code <> '' THEN 'لینک رهگیری'
        WHEN s.utm_medium IN ('cpc','ppc','paid','ads','cpm') THEN 'تبلیغات'
        WHEN s.utm_medium IN ('email','newsletter') THEN 'ایمیل'
        WHEN s.utm_medium IN ('sms') THEN 'پیامک'
        WHEN s.utm_source IS NOT NULL AND s.utm_source <> '' THEN 'کمپین'
        WHEN s.referrer IS NULL OR s.referrer = '' THEN 'مستقیم'
        WHEN s.referrer REGEXP 'google\\\\.|bing\\\\.|yahoo\\\\.|duckduckgo|yandex|ecosia|search\\\\.' THEN 'جستجو'
        WHEN s.referrer REGEXP 'instagram|t\\\\.me|telegram|facebook|fb\\\\.|twitter|x\\\\.com|linkedin|aparat|youtube|youtu\\\\.be|pinterest|tiktok|bale\\\\.ai|rubika|eitaa' THEN 'شبکه‌های اجتماعی'
        ELSE 'ارجاعی' END";

    /** منبع مشخص */
    const SOURCE_SQL = "CASE
        WHEN s.utm_source IS NOT NULL AND s.utm_source <> '' THEN LOWER(s.utm_source)
        WHEN s.referrer IS NULL OR s.referrer = '' THEN '(مستقیم)'
        ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer,'https://',''),'http://',''), '/', 1), ':', 1)) END";

    const FILTER_KEYS = ['device', 'browser', 'os', 'country', 'city', 'channel', 'source', 'utm_source', 'utm_medium', 'utm_campaign', 'page', 'entry', 'exit', 'link_code', 'visitor', 'identified', 'min_duration', 'min_pages'];

    /* ---------------- بازه و فیلتر ---------------- */

    /** بازه‌ی محلی [from, to] (Y-m-d) و معادل دیتابیسی آن */
    public static function range(array $in): array
    {
        $today = substr(Tz::nowLocal(), 0, 10);
        $to = self::date($in['to'] ?? '') ?: $today;
        $from = self::date($in['from'] ?? '');
        if (!$from) {
            $days = max(1, min(self::MAX_DAYS, (int)($in['days'] ?? 30)));
            $from = gmdate('Y-m-d', strtotime($to . ' 00:00:00 UTC') - ($days - 1) * 86400);
        }
        if ($from > $to) [$from, $to] = [$to, $from];
        $span = (int)round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400) + 1;
        if ($span > self::MAX_DAYS) { $from = gmdate('Y-m-d', strtotime($to . ' UTC') - (self::MAX_DAYS - 1) * 86400); $span = self::MAX_DAYS; }
        $pTo = gmdate('Y-m-d', strtotime($from . ' UTC') - 86400);
        $pFrom = gmdate('Y-m-d', strtotime($pTo . ' UTC') - ($span - 1) * 86400);
        return [
            'from' => $from, 'to' => $to, 'days' => $span,
            'db_from' => Tz::toDb($from . ' 00:00:00'), 'db_to' => Tz::toDb($to . ' 23:59:59'),
            'prev_from' => $pFrom, 'prev_to' => $pTo,
            'prev_db_from' => Tz::toDb($pFrom . ' 00:00:00'), 'prev_db_to' => Tz::toDb($pTo . ' 23:59:59'),
        ];
    }

    private static function date($s): ?string
    {
        $s = strtr(trim((string)$s), ['/' => '-']);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    public static function filters(array $in): array
    {
        $f = [];
        $src = is_array($in['filters'] ?? null) ? $in['filters'] : $in;
        foreach (self::FILTER_KEYS as $k) {
            $v = $src[$k] ?? '';
            if (is_array($v)) $v = array_values(array_filter(array_map('strval', $v), fn($x) => $x !== ''));
            else $v = trim((string)$v);
            if ($v !== '' && $v !== []) $f[$k] = $v;
        }
        return $f;
    }

    /** WHERE روی نشست‌ها (s) و بازدیدکننده (v) */
    private static function where(array $f, string $dbFrom, string $dbTo): array
    {
        $w = ['s.started_at BETWEEN ? AND ?']; $p = [$dbFrom, $dbTo];
        // ۲.۱.۱: فقط نشست‌های سایتِ انتخاب‌شده (با یک سایت اثری ندارد)
        if (class_exists('SiteCtx') && SiteCtx::multi() && Database::hasColumn('eb_sessions', 'site_id')) $w[] = SiteCtx::sessCond('s');
        $in = function (string $col, $val) use (&$w, &$p) {
            $vals = (array)$val;
            $w[] = $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
            foreach ($vals as $x) $p[] = $x;
        };
        foreach (['device' => 's.device', 'browser' => 's.browser', 'os' => 's.os', 'country' => 's.country', 'city' => 's.city', 'utm_source' => 's.utm_source', 'utm_medium' => 's.utm_medium', 'utm_campaign' => 's.utm_campaign', 'link_code' => 's.link_code'] as $k => $col) {
            if (isset($f[$k])) $in($col, $f[$k]);
        }
        if (isset($f['channel'])) $in('(' . self::CHANNEL_SQL . ')', $f['channel']);
        if (isset($f['source'])) $in('(' . self::SOURCE_SQL . ')', $f['source']);
        if (isset($f['page'])) { $w[] = 'EXISTS (SELECT 1 FROM eb_pageviews pf WHERE pf.session_id = s.id AND pf.url LIKE ?)'; $p[] = '%' . self::like((string)(is_array($f['page']) ? $f['page'][0] : $f['page'])) . '%'; }
        if (isset($f['entry'])) { $w[] = 's.entry_page LIKE ?'; $p[] = '%' . self::like((string)(is_array($f['entry']) ? $f['entry'][0] : $f['entry'])) . '%'; }
        if (isset($f['exit'])) { $w[] = 's.exit_page LIKE ?'; $p[] = '%' . self::like((string)(is_array($f['exit']) ? $f['exit'][0] : $f['exit'])) . '%'; }
        if (($f['visitor'] ?? '') === 'new') $w[] = 'v.first_seen >= DATE_SUB(s.started_at, INTERVAL 30 MINUTE)';
        if (($f['visitor'] ?? '') === 'returning') $w[] = 'v.first_seen < DATE_SUB(s.started_at, INTERVAL 30 MINUTE)';
        if (($f['identified'] ?? '') === '1') $w[] = 'v.person_id IS NOT NULL';
        if (($f['identified'] ?? '') === '0') $w[] = 'v.person_id IS NULL';
        if (isset($f['min_duration'])) { $w[] = 's.duration_sec >= ?'; $p[] = (int)$f['min_duration']; }
        if (isset($f['min_pages'])) { $w[] = 's.page_count >= ?'; $p[] = (int)$f['min_pages']; }
        return [implode(' AND ', $w), $p];
    }

    private static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    private static function base(): string
    {
        return "FROM eb_sessions s JOIN eb_visitors v ON v.id = s.visitor_id";
    }

    /* ---------------- گزارش کامل ---------------- */

    public static function report(array $in): array
    {
        $r = self::range($in);
        $f = self::filters($in);
        $off = Tz::diff();
        [$w, $p] = self::where($f, $r['db_from'], $r['db_to']);
        $B = self::base();

        $kpi = self::kpi($w, $p);
        [$pw, $pp] = self::where($f, $r['prev_db_from'], $r['prev_db_to']);
        $prev = self::kpi($pw, $pp);

        // روند: ساعتی برای بازه‌ی ۱ تا ۲ روز، وگرنه روزانه
        $hourly = $r['days'] <= 2;
        $bucket = $hourly
            ? "DATE_FORMAT(DATE_ADD(s.started_at, INTERVAL {$off} SECOND), '%Y-%m-%d %H:00')"
            : "DATE(DATE_ADD(s.started_at, INTERVAL {$off} SECOND))";
        $rows = Database::all("SELECT {$bucket} b, COUNT(*) sessions, COUNT(DISTINCT s.visitor_id) visitors, SUM(s.page_count) pageviews,
                                      SUM(s.page_count <= 1) bounces, AVG(s.duration_sec) dur
                               {$B} WHERE {$w} GROUP BY b ORDER BY b", $p);
        $byB = []; foreach ($rows as $x) $byB[(string)$x['b']] = $x;
        $series = ['labels' => [], 'sessions' => [], 'visitors' => [], 'pageviews' => [], 'bounce' => [], 'duration' => []];
        $start = strtotime($r['from'] . ' 00:00:00 UTC'); $end = strtotime($r['to'] . ' 23:59:59 UTC');
        for ($t = $start; $t <= $end; $t += $hourly ? 3600 : 86400) {
            $k = $hourly ? gmdate('Y-m-d H:00', $t) : gmdate('Y-m-d', $t);
            $x = $byB[$k] ?? null;
            $series['labels'][] = $k;
            $series['sessions'][] = (int)($x['sessions'] ?? 0);
            $series['visitors'][] = (int)($x['visitors'] ?? 0);
            $series['pageviews'][] = (int)($x['pageviews'] ?? 0);
            $series['bounce'][] = $x && $x['sessions'] ? round($x['bounces'] / $x['sessions'] * 100, 1) : 0;
            $series['duration'][] = (int)round((float)($x['dur'] ?? 0));
        }

        $dim = function (string $expr, int $limit = 12) use ($B, $w, $p) {
            return array_map(fn($x) => ['k' => $x['k'] === null || $x['k'] === '' ? 'نامعلوم' : $x['k'], 'sessions' => (int)$x['c'], 'visitors' => (int)$x['u'], 'bounce' => $x['c'] ? round($x['b'] / $x['c'] * 100, 1) : 0, 'duration' => (int)round((float)$x['d'])],
                Database::all("SELECT {$expr} k, COUNT(*) c, COUNT(DISTINCT s.visitor_id) u, SUM(s.page_count <= 1) b, AVG(s.duration_sec) d {$B} WHERE {$w} GROUP BY k ORDER BY c DESC LIMIT {$limit}", $p));
        };

        $pages = Database::all(
            "SELECT pv.url, MAX(pv.title) title, COUNT(*) views, COUNT(DISTINCT pv.session_id) sessions, AVG(pv.time_on_page_sec) avg_time, AVG(pv.scroll_depth_pct) avg_scroll
             FROM eb_pageviews pv JOIN eb_sessions s ON s.id = pv.session_id JOIN eb_visitors v ON v.id = s.visitor_id
             WHERE {$w} GROUP BY pv.url ORDER BY views DESC LIMIT 25", $p);

        $heat = Database::all(
            "SELECT (DAYOFWEEK(DATE_ADD(s.started_at, INTERVAL {$off} SECOND)) % 7) dw, HOUR(DATE_ADD(s.started_at, INTERVAL {$off} SECOND)) h, COUNT(*) c
             {$B} WHERE {$w} GROUP BY dw, h", $p);
        // DAYOFWEEK: 1=یکشنبه … 7=شنبه ← با %7: شنبه=0، یکشنبه=1 …
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($heat as $x) $grid[(int)$x['dw']][(int)$x['h']] = (int)$x['c'];

        return [
            'range' => $r, 'filters' => $f, 'hourly' => $hourly,
            'kpi' => $kpi, 'prev' => $prev, 'series' => $series,
            'channels' => $dim('(' . self::CHANNEL_SQL . ')', 10),
            'sources' => $dim('(' . self::SOURCE_SQL . ')', 15),
            'campaigns' => array_map(fn($x) => ['source' => $x['utm_source'], 'medium' => $x['utm_medium'], 'campaign' => $x['utm_campaign'], 'sessions' => (int)$x['c'], 'visitors' => (int)$x['u']],
                Database::all("SELECT s.utm_source, s.utm_medium, s.utm_campaign, COUNT(*) c, COUNT(DISTINCT s.visitor_id) u {$B} WHERE {$w} AND s.utm_campaign IS NOT NULL AND s.utm_campaign <> '' GROUP BY s.utm_source, s.utm_medium, s.utm_campaign ORDER BY c DESC LIMIT 15", $p)),
            'links' => $dim("NULLIF(s.link_code, '')", 10),
            'pages' => array_map(fn($x) => ['url' => $x['url'], 'title' => $x['title'], 'views' => (int)$x['views'], 'sessions' => (int)$x['sessions'], 'avg_time' => (int)round((float)$x['avg_time']), 'avg_scroll' => (int)round((float)$x['avg_scroll'])], $pages),
            'entry' => $dim('s.entry_page', 12),
            'exit' => $dim('s.exit_page', 12),
            'devices' => $dim('s.device', 6),
            'browsers' => $dim('s.browser', 10),
            'os' => $dim('s.os', 10),
            'countries' => $dim('s.country', 15),
            'cities' => $dim('s.city', 15),
            'heat' => $grid,
        ];
    }

    private static function kpi(string $w, array $p): array
    {
        $B = self::base();
        $k = Database::one("SELECT COUNT(*) sessions, COUNT(DISTINCT s.visitor_id) visitors, COALESCE(SUM(s.page_count),0) pageviews,
                                   COALESCE(AVG(s.duration_sec),0) dur, COALESCE(SUM(s.page_count <= 1),0) bounces,
                                   COUNT(DISTINCT CASE WHEN v.person_id IS NOT NULL THEN s.visitor_id END) identified,
                                   COUNT(DISTINCT CASE WHEN v.first_seen >= DATE_SUB(s.started_at, INTERVAL 30 MINUTE) THEN s.visitor_id END) new_visitors
                            {$B} WHERE {$w}", $p) ?: [];
        $s = (int)($k['sessions'] ?? 0);
        return [
            'sessions' => $s, 'visitors' => (int)($k['visitors'] ?? 0), 'pageviews' => (int)($k['pageviews'] ?? 0),
            'avg_duration' => (int)round((float)($k['dur'] ?? 0)),
            'bounce_rate' => $s ? round($k['bounces'] / $s * 100, 1) : 0,
            'pages_per_session' => $s ? round($k['pageviews'] / $s, 2) : 0,
            'identified' => (int)($k['identified'] ?? 0), 'new_visitors' => (int)($k['new_visitors'] ?? 0),
        ];
    }

    /** مقادیر موجود برای فیلترها در این بازه */
    public static function options(array $in): array
    {
        $r = self::range($in);
        $B = self::base(); $p = [$r['db_from'], $r['db_to']];
        $col = function (string $expr, int $limit = 60) use ($B, $p) {
            return array_values(array_filter(array_column(Database::all("SELECT {$expr} k, COUNT(*) c {$B} WHERE s.started_at BETWEEN ? AND ? GROUP BY k ORDER BY c DESC LIMIT {$limit}", $p), 'k'), fn($x) => $x !== null && $x !== ''));
        };
        return [
            'device' => $col('s.device'), 'browser' => $col('s.browser'), 'os' => $col('s.os'),
            'country' => $col('s.country'), 'city' => $col('s.city', 80),
            'channel' => $col('(' . self::CHANNEL_SQL . ')'), 'source' => $col('(' . self::SOURCE_SQL . ')', 80),
            'utm_source' => $col('s.utm_source'), 'utm_medium' => $col('s.utm_medium'), 'utm_campaign' => $col('s.utm_campaign'),
            'link_code' => $col('s.link_code'),
        ];
    }

    /** فهرست نشست‌ها با همان فیلترها (صفحه‌بندی‌شده) */
    public static function sessions(array $in): array
    {
        $r = self::range($in);
        $f = self::filters($in);
        [$w, $p] = self::where($f, $r['db_from'], $r['db_to']);
        $page = max(1, (int)($in['page'] ?? 1)); $per = 50;
        $total = (int)(Database::one("SELECT COUNT(*) c " . self::base() . " WHERE {$w}", $p)['c'] ?? 0);
        $rows = Database::all(
            "SELECT s.id, s.started_at, s.entry_page, s.exit_page, s.referrer, s.utm_source, s.utm_campaign, s.link_code, s.device, s.browser, s.os,
                    s.country, s.city, s.duration_sec, s.page_count, v.visitor_uid, v.person_id, v.visits_count, p.full_name, (" . self::CHANNEL_SQL . ") channel
             " . self::base() . " LEFT JOIN eb_persons p ON p.id = v.person_id
             WHERE {$w} ORDER BY s.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $p);
        foreach ($rows as &$x) $x['started_local'] = Tz::toLocal($x['started_at']);
        unset($x);
        return ['sessions' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
    }

    /* ---------------- نماهای ذخیره‌شده (برای هر کاربر) ---------------- */

    public static function savedViews(): array
    {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = ?", ['an.views.' . (int)Auth::id()]);
        $v = json_decode((string)($r['v'] ?? '[]'), true);
        return is_array($v) ? $v : [];
    }

    public static function saveViews(array $views): array
    {
        $clean = [];
        foreach (array_slice($views, 0, 30) as $v) {
            if (!is_array($v) || trim((string)($v['name'] ?? '')) === '') continue;
            $clean[] = ['name' => mb_substr(trim($v['name']), 0, 60), 'days' => (int)($v['days'] ?? 0), 'from' => self::date($v['from'] ?? '') ?: '', 'to' => self::date($v['to'] ?? '') ?: '', 'filters' => self::filters(['filters' => $v['filters'] ?? []])];
        }
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['an.views.' . (int)Auth::id(), json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return $clean;
    }
}
