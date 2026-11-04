<?php
/**
 * modules/AnalyticsIngest.php
 * پردازش رویدادهای خام رسیده از eb-pulse.js (فراخوانی از eb-pulse.php).
 *
 * قاعده‌ی طلایی طبق سند معماری: این‌جا فقط insert/update سبک انجام می‌شود،
 * هیچ محاسبه‌ی سنگینی (رول‌آپ، تشخیص رِیج‌کلیک) در مسیر درخواستِ بازدیدکننده
 * اتفاق نمی‌افتد — آن‌ها در cron هستند (AnalyticsRollup.php).
 */

class AnalyticsIngest
{
    /**
     * یک batch از رویدادها را پردازش می‌کند.
     * ورودی مورد انتظار:
     *   visitor_uid, session_uid (کلاینت تولید می‌کند و نگه می‌دارد)
     *   page: {url, title, referrer, utm_source, utm_medium, utm_campaign}
     *   events: [{type, x, y, vw, vh, page_url, meta}, ...]
     *   scroll_depth_pct, time_on_page_sec (وقتی از رویداد خروج صفحه می‌آید)
     */
    public static function process(array $payload, string $ip, ?string $ua): array
    {
        $visitorUid = self::cleanUid($payload['visitor_uid'] ?? '');
        $sessionUid = self::cleanUid($payload['session_uid'] ?? '');
        if ($visitorUid === '' || $sessionUid === '') {
            return ['ok' => false, 'error' => 'شناسه‌ی نامعتبر'];
        }

        $ua = (string)($ua ?? '');
        $agent = class_exists('Links') ? Links::parseUa($ua) : ['device' => null, 'browser' => null, 'os' => null];
        $geo = class_exists('GeoIp') ? GeoIp::lookup($ip) : null;

        $visitorId = self::upsertVisitor($visitorUid);
        $sessionId = self::upsertSession($sessionUid, $visitorId, $payload, $agent, $geo);

        $page = $payload['page'] ?? null;
        if (is_array($page) && !empty($page['url'])) {
            self::recordPageview($sessionId, $page);
        }

        $events = $payload['events'] ?? [];
        if (is_array($events) && $events) {
            self::recordEvents($sessionId, $events);
        }

        return ['ok' => true, 'session_id' => $sessionId];
    }

    private static function cleanUid(string $s): string
    {
        $s = trim($s);
        // فقط حروف/رقم/خط‌تیره — جلوگیری از هر چیز غیرمنتظره در یک شناسه
        return preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $s) ? $s : '';
    }

    private static function upsertVisitor(string $uid): int
    {
        $row = Database::one("SELECT id FROM eb_visitors WHERE visitor_uid=?", [$uid]);
        if ($row) {
            Database::run(
                "UPDATE eb_visitors SET last_seen=NOW(), visits_count=visits_count WHERE id=?",
                [$row['id']]
            );
            return (int)$row['id'];
        }
        Database::run(
            "INSERT INTO eb_visitors (visitor_uid, first_seen, last_seen, visits_count) VALUES (?, NOW(), NOW(), 1)",
            [$uid]
        );
        return Database::lastId();
    }

    /**
     * نشست را با session_uid (تولیدشده در کلاینت، در sessionStorage نگه‌داشته‌شده)
     * پیدا می‌کند یا تازه می‌سازد. session_uid دقیقاً همان چیزی‌ست که هر تبِ باز
     * جدا نگه می‌دارد — پس چند تب هم‌زمان درست از هم تفکیک می‌شوند (بر خلاف
     * تطبیق صرفاً بر اساس visitor+زمان که تب‌های موازی را قاطی می‌کرد).
     *
     * محافظ timeout: اگر همان session_uid با بیش از ۳۰ دقیقه فاصله دوباره
     * دیده شود (مثلاً کلاینت به‌هر دلیل uid را تازه نکرده)، نشست را «تمام‌شده»
     * در نظر می‌گیریم و یکی جدید (با همان uid) دوباره می‌سازیم.
     */
    private static function upsertSession(string $sessionUid, int $visitorId, array $payload, array $agent, ?array $geo): int
    {
        $row = Database::one(
            "SELECT id, last_ping FROM eb_sessions WHERE session_uid=? LIMIT 1",
            [$sessionUid]
        );
        $expired = $row && strtotime($row['last_ping']) < (time() - 1800);
        $page = $payload['page'] ?? [];

        if ($row && !$expired) {
            $sid = (int)$row['id'];
            Database::run("UPDATE eb_sessions SET last_ping=NOW() WHERE id=?", [$sid]);
            if (!empty($page['url'])) {
                Database::run("UPDATE eb_sessions SET exit_page=? WHERE id=?", [$page['url'], $sid]);
            }
            return $sid;
        }

        if ($row && $expired) {
            // uid قدیمی منقضی شده — چون UNIQUE است، اول آزادش کن (نال کن) تا insert جدید تصادم نخورد
            Database::run("UPDATE eb_sessions SET session_uid=NULL WHERE id=?", [$row['id']]);
        }
        if ($row) {
            $sid = (int)$row['id'];
            Database::run("UPDATE eb_sessions SET last_ping=NOW() WHERE id=?", [$sid]);
            if (!empty($page['url'])) {
                Database::run("UPDATE eb_sessions SET exit_page=? WHERE id=?", [$page['url'], $sid]);
            }
            return $sid;
        }

        Database::run(
            "INSERT INTO eb_sessions
                (visitor_id, session_uid, started_at, entry_page, exit_page, referrer,
                 utm_source, utm_medium, utm_campaign, link_code, device, browser, os,
                 country, country_code, city, last_ping" . (class_exists('SiteCtx') && Database::hasColumn('eb_sessions', 'site_id') ? ', site_id' : '') . ")
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()" . (class_exists('SiteCtx') && Database::hasColumn('eb_sessions', 'site_id') ? ', ?' : '') . ")",
            [
                $visitorId,
                $sessionUid,
                $page['url'] ?? null,
                $page['url'] ?? null,
                self::truncate($page['referrer'] ?? null, 500),
                self::truncate($page['utm_source'] ?? null, 150),
                self::truncate($page['utm_medium'] ?? null, 150),
                self::truncate($page['utm_campaign'] ?? null, 150),
                self::truncate($page['link_code'] ?? null, 64),
                $agent['device'] ?? null,
                $agent['browser'] ?? null,
                $agent['os'] ?? null,
                $geo['country'] ?? null,
                $geo['country_code'] ?? null,
                $geo['city'] ?? null,
                ...(class_exists('SiteCtx') && Database::hasColumn('eb_sessions', 'site_id') ? [SiteCtx::rowId()] : []),
            ]
        );
        // مهم: باید بلافاصله بعد از INSERT خوانده شود — هر کوئری دیگر (حتی
        // یک UPDATE بی‌ربط روی جدولی با AUTO_INCREMENT) مقدار lastInsertId
        // را در این محیط/درایور به ۰ بازنشانی می‌کند (تست و تأیید شد).
        $newId = Database::lastId();
        Database::run("UPDATE eb_visitors SET visits_count = visits_count + 1 WHERE id=?", [$visitorId]);
        return $newId;
    }

    private static function recordPageview(int $sessionId, array $page): void
    {
        Database::run(
            "INSERT INTO eb_pageviews (session_id, url, title, scroll_depth_pct, time_on_page_sec, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $sessionId,
                self::truncate($page['url'], 500),
                self::truncate($page['title'] ?? null, 255),
                isset($page['scroll_depth_pct']) ? max(0, min(100, (int)$page['scroll_depth_pct'])) : null,
                isset($page['time_on_page_sec']) ? max(0, (int)$page['time_on_page_sec']) : null,
            ]
        );
        Database::run("UPDATE eb_sessions SET page_count = page_count + 1 WHERE id=?", [$sessionId]);
    }

    /**
     * رویدادهای batch‌شده (کلیک/حرکت/goal/custom) را خام درج می‌کند.
     * نمونه‌برداری از قبل سمت کلاینت اعمال شده (sampling_heatmap/move)، پس
     * این‌جا فقط insert است — بدون هیچ چک اضافه‌ای که کند شود.
     */
    private static function recordEvents(int $sessionId, array $events): void
    {
        $sql = "INSERT INTO eb_events (session_id, type, x, y, viewport_w, viewport_h, page_url, meta_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $allowedTypes = ['click', 'rage_click', 'dead_click', 'move', 'goal', 'custom'];
        $n = 0;
        foreach ($events as $e) {
            if (!is_array($e) || empty($e['type']) || !in_array($e['type'], $allowedTypes, true)) continue;
            if (++$n > 500) break; // سقف دفاعی برای هر batch — جلوی سوءاستفاده/باگ کلاینت
            Database::run($sql, [
                $sessionId,
                $e['type'],
                isset($e['x']) ? max(-1, min(20000, (int)$e['x'])) : null,
                isset($e['y']) ? max(-1, min(20000, (int)$e['y'])) : null,
                isset($e['vw']) ? max(0, min(20000, (int)$e['vw'])) : null,
                isset($e['vh']) ? max(0, min(20000, (int)$e['vh'])) : null,
                self::truncate($e['page_url'] ?? null, 500),
                isset($e['meta']) ? substr(json_encode($e['meta'], JSON_UNESCAPED_UNICODE), 0, 2000) : null,
            ]);
        }
    }

    private static function truncate($v, int $max): ?string
    {
        if ($v === null) return null;
        $v = (string)$v;
        return mb_substr($v, 0, $max);
    }
}
