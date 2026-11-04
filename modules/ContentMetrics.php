<?php
/**
 * modules/ContentMetrics.php
 * گزارش عملکرد محتوای تقویم: بازدید، لایک، کامنت، اشتراک، ذخیره، کلیک، سرنخ و فروش.
 *
 * هر ثبت یک «عکس» از آمار در یک لحظه است (دستی یا خودکار)، تا رشد آمار در طول زمان دیده شود.
 * آمار خودکار:
 *  - کامنت‌ها و دایرکت‌های اتوماسیون کامنت از جدول کامنت‌های همین پنل (برای پست اینستاگرامی که به محتوا وصل شده)
 *  - کلیک لینک رهگیری (اگر کد لینک به محتوا وصل شده باشد)
 *  - لایک، کامنت و بازدید از سرویس اینستاگرام، اگر سرویس‌دهنده این اعداد را در فهرست پست‌ها برگرداند
 * بقیه (مثل بازدید پست تلگرام، ریچ، فروش) دستی ثبت می‌شود.
 */
class ContentMetrics
{
    const FIELDS = ['views', 'reach', 'impressions', 'likes', 'comments', 'shares', 'saves', 'clicks', 'follows', 'dms', 'leads', 'sales', 'revenue', 'watch_sec'];

    public static function ready(): bool { return Database::hasTable('eb_mkt_metrics'); }

    public static function history(int $contentId): array
    {
        return Database::all("SELECT m.*, " . Auth::nameSql('u') . " AS by_name FROM eb_mkt_metrics m LEFT JOIN eb_users u ON u.id = m.created_by WHERE m.content_id = ? ORDER BY m.captured_at DESC, m.id DESC LIMIT 200", [$contentId]);
    }

    /** آخرین مقدار هر شاخص (ستون به ستون؛ اگر آخرین ثبت یک شاخص را نداشت، از ثبت قبلی) */
    public static function latest(array $contentIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $contentIds))));
        if (!$ids || !self::ready()) return $out;
        foreach (array_chunk($ids, 300) as $ch) {
            foreach (Database::all("SELECT * FROM eb_mkt_metrics WHERE content_id IN (" . implode(',', $ch) . ") ORDER BY content_id, captured_at DESC, id DESC") as $r) {
                $c = (int)$r['content_id'];
                if (!isset($out[$c])) $out[$c] = ['captured_at' => $r['captured_at'], 'n' => 0];
                $out[$c]['n']++;
                foreach (self::FIELDS as $f) if (!isset($out[$c][$f]) && $r[$f] !== null) $out[$c][$f] = $f === 'revenue' ? (float)$r[$f] : (int)$r[$f];
            }
        }
        return $out;
    }

    public static function save(int $contentId, array $in, ?int $by, string $source = 'manual'): int
    {
        if (!Database::one("SELECT id FROM eb_mkt_content WHERE id = ?", [$contentId])) throw new InvalidArgumentException('محتوا پیدا نشد');
        $vals = []; $any = false;
        foreach (self::FIELDS as $f) {
            $v = $in[$f] ?? null;
            if ($v === '' || $v === null) { $vals[$f] = null; continue; }
            $v = preg_replace('/[^\d.]/', '', strtr((string)$v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٬'=>'',','=>'']));
            if ($v === '') { $vals[$f] = null; continue; }
            $vals[$f] = $f === 'revenue' ? (float)$v : (int)$v; $any = true;
        }
        if (!$any) throw new InvalidArgumentException('حداقل یکی از اعداد را وارد کنید');
        $at = !empty($in['captured_at']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$in['captured_at']) ? Tz::toDb(substr((string)$in['captured_at'], 0, 16) . (strlen((string)$in['captured_at']) <= 10 ? ' 12:00' : '') . ':00') : null;
        Database::run("INSERT INTO eb_mkt_metrics (content_id, captured_at, source, " . implode(', ', self::FIELDS) . ", note, created_by)
                       VALUES (?, COALESCE(?, NOW()), ?, " . implode(', ', array_fill(0, count(self::FIELDS), '?')) . ", ?, ?)",
            array_merge([$contentId, $at, $source], array_values($vals), [mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null, $by]));
        return Database::lastId();
    }

    public static function delete(int $id): void { Database::run("DELETE FROM eb_mkt_metrics WHERE id = ?", [$id]); }

    /** وصل کردن محتوا به پست واقعی و لینک رهگیری */
    public static function link(int $contentId, array $in): array
    {
        $mid = trim((string)($in['ext_media_id'] ?? ''));
        $url = trim((string)($in['ext_url'] ?? ''));
        // اگر لینک پست اینستاگرام داده شد و شناسه نه، شورت‌کد را نگه می‌داریم (برای تطبیق با فهرست پست‌ها)
        if ($mid === '' && preg_match('~instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)~', $url, $m)) $mid = 'sc:' . $m[1];
        $code = trim((string)($in['link_code'] ?? ''));
        if (preg_match('~/r\.php\?c=([A-Za-z0-9_-]+)|/r/([A-Za-z0-9_-]+)~', $code, $m)) $code = $m[1] ?: $m[2];
        Database::run("UPDATE eb_mkt_content SET ext_media_id = ?, ext_url = ?, link_code = ? WHERE id = ?", [$mid ?: null, mb_substr($url, 0, 500) ?: null, mb_substr($code, 0, 60) ?: null, $contentId]);
        return ['ext_media_id' => $mid ?: null, 'ext_url' => $url ?: null, 'link_code' => $code ?: null];
    }

    /** پیج اینستاگرامِ این محتوا (از پیج تقویم یا پست زمان‌بندی‌شده) */
    private static function igAccountFor(array $c): ?int
    {
        if (!empty($c['page_id'])) {
            $p = Database::one("SELECT ig_account_id FROM eb_mkt_pages WHERE id = ?", [(int)$c['page_id']]);
            if (!empty($p['ig_account_id'])) return (int)$p['ig_account_id'];
        }
        if (Database::hasTable('eb_ig_posts')) {
            $p = Database::one("SELECT ig_account_id FROM eb_ig_posts WHERE mkt_content_id = ? ORDER BY id DESC LIMIT 1", [(int)$c['id']]);
            if (!empty($p['ig_account_id'])) return (int)$p['ig_account_id'];
        }
        return null;
    }

    /** پست‌های اخیر پیج این محتوا (برای انتخاب و تطبیق) */
    public static function pagePosts(int $contentId): array
    {
        $c = Database::one("SELECT * FROM eb_mkt_content WHERE id = ?", [$contentId]);
        if (!$c) return ['ok' => false, 'error' => 'محتوا پیدا نشد'];
        $acc = self::igAccountFor($c);
        if (!$acc) return ['ok' => false, 'error' => 'پیج اینستاگرامِ این محتوا مشخص نیست. در تقویم، پیجی را که به اینستاگرام وصل است برای محتوا انتخاب کنید.'];
        $driver = class_exists('InstagramRoboclick') ? InstagramRoboclick::driverForAccountId($acc) : null;
        if (!$driver) return ['ok' => false, 'error' => 'اتصال این پیج اینستاگرام فعال نیست'];
        $r = $driver->listPosts(null, 30);
        if (empty($r['ok'])) return ['ok' => false, 'error' => 'فهرست پست‌ها از سرویس اینستاگرام نیامد: ' . ($r['error'] ?? '')];
        $posts = array_map(fn($p) => InstagramRoboclick::normalizeMedia($p) + ['metrics' => self::metricsFrom($p)], $r['posts'] ?? []);
        foreach ($posts as &$p) unset($p['raw']);
        return ['ok' => true, 'posts' => $posts, 'ig_account_id' => $acc];
    }

    /** خواندن اعداد لایک/کامنت/بازدید از پاسخ سرویس (هر کلیدی که باشد) */
    public static function metricsFrom(array $item): array
    {
        $flat = [];
        $walk = function ($a, $depth) use (&$walk, &$flat) {
            foreach ($a as $k => $v) {
                if (is_array($v)) { if ($depth < 3) $walk($v, $depth + 1); continue; }
                if (is_numeric($v)) $flat[strtolower(str_replace(['_', '-'], '', (string)$k))] = (int)$v;
            }
        };
        $walk($item, 0);
        $map = [
            'likes' => ['likecount', 'likescount', 'likes', 'like'],
            'comments' => ['commentscount', 'commentcount', 'comments'],
            'views' => ['viewcount', 'viewscount', 'views', 'playcount', 'plays', 'videoviewcount', 'videoplaycount'],
            'reach' => ['reach', 'reachcount'],
            'impressions' => ['impressions', 'impressioncount'],
            'shares' => ['sharecount', 'sharescount', 'shares'],
            'saves' => ['savecount', 'savedcount', 'saved', 'saves'],
        ];
        $out = [];
        foreach ($map as $f => $keys) foreach ($keys as $k) if (isset($flat[$k])) { $out[$f] = $flat[$k]; break; }
        return $out;
    }

    /** جمع‌آوری خودکار؛ اگر چیزی پیدا شد یک ثبت «خودکار» می‌سازد */
    public static function collect(int $contentId): array
    {
        $c = Database::one("SELECT * FROM eb_mkt_content WHERE id = ?", [$contentId]);
        if (!$c) return ['ok' => false, 'error' => 'محتوا پیدا نشد'];
        $m = []; $notes = [];
        $mid = (string)($c['ext_media_id'] ?? '');
        // ۱) سرویس اینستاگرام: پیدا کردن پست (با شناسه، شورت‌کد یا متن کپشن)
        $acc = self::igAccountFor($c);
        if ($acc && ($c['channel'] === 'instagram' || $mid !== '' || !empty($c['page_id']))) {
            $pp = self::pagePosts($contentId);
            if (!empty($pp['ok'])) {
                $hit = null;
                foreach ($pp['posts'] as $p) {
                    if ($mid !== '' && ($p['id'] === $mid || ('sc:' . $p['shortcode']) === $mid || ($p['permalink'] && $c['ext_url'] && rtrim($p['permalink'], '/') === rtrim((string)$c['ext_url'], '/')))) { $hit = $p; break; }
                }
                if (!$hit && $mid === '' && Database::hasTable('eb_ig_posts')) {
                    $cap = (string)(Database::one("SELECT caption FROM eb_ig_posts WHERE mkt_content_id = ? AND status = 'published' ORDER BY id DESC LIMIT 1", [$contentId])['caption'] ?? '');
                    $key = mb_substr(trim($cap), 0, 40);
                    if ($key !== '') foreach ($pp['posts'] as $p) if (mb_strpos((string)$p['caption'], $key) === 0) { $hit = $p; break; }
                }
                if ($hit) {
                    if ($mid === '' || strpos($mid, 'sc:') === 0) { Database::run("UPDATE eb_mkt_content SET ext_media_id = ?, ext_url = COALESCE(ext_url, ?) WHERE id = ?", [$hit['id'], $hit['permalink'] ?: null, $contentId]); $mid = $hit['id']; }
                    $m += $hit['metrics'];
                    if (!$hit['metrics']) $notes[] = 'سرویس اینستاگرام برای این پست عدد لایک/بازدید برنگرداند';
                } else $notes[] = 'پست این محتوا در ۳۰ پست اخیر پیج پیدا نشد؛ لینک یا شناسه‌ی پست را وارد کنید';
            } else $notes[] = $pp['error'] ?? '';
        }
        // ۲) کامنت‌ها و دایرکت‌های اتوماسیون کامنت در همین پنل
        if ($mid !== '' && strpos($mid, 'sc:') !== 0 && Database::hasTable('eb_ig_comments')) {
            $r = Database::one("SELECT COUNT(*) c, SUM(replied_type IN ('dm','both')) d FROM eb_ig_comments WHERE media_id = ?", [$mid]);
            if ((int)($r['c'] ?? 0) > 0) { $m['comments'] = max((int)($m['comments'] ?? 0), (int)$r['c']); $m['dms'] = (int)$r['d']; }
        }
        // ۳) کلیک لینک رهگیری
        if (!empty($c['link_code']) && Database::hasTable('eb_links')) {
            $l = Database::one("SELECT clicks FROM eb_links WHERE code = ?", [$c['link_code']]);
            if ($l) $m['clicks'] = (int)$l['clicks']; else $notes[] = 'لینک رهگیری با این کد پیدا نشد';
        }
        if (!$m) return ['ok' => false, 'error' => 'آمار خودکاری پیدا نشد' . ($notes ? ': ' . implode(' · ', array_filter($notes)) : '. پست یا لینک رهگیری را به محتوا وصل کنید.'), 'notes' => $notes];
        // اگر چیزی نسبت به آخرین ثبت خودکار عوض نشده، ثبت تکراری نساز
        $last = Database::one("SELECT * FROM eb_mkt_metrics WHERE content_id = ? AND source = 'auto' ORDER BY id DESC LIMIT 1", [$contentId]);
        $same = $last && !array_filter(array_keys($m), fn($k) => (int)$last[$k] !== (int)$m[$k]);
        if (!$same) self::save($contentId, $m, null, 'auto');
        return ['ok' => true, 'metrics' => $m, 'unchanged' => $same, 'notes' => array_values(array_filter($notes))];
    }

    /** کران: هر محتوای منتشرشده‌ی ۴۵ روز اخیر، روزی یک بار */
    public static function cronTick(int $max = 8): int
    {
        if (!self::ready()) return 0;
        $rows = Database::all("SELECT c.id FROM eb_mkt_content c
            WHERE c.status = 'published' AND COALESCE(c.published_at, c.plan_date) >= DATE_SUB(NOW(), INTERVAL 45 DAY)
              AND (c.ext_media_id IS NOT NULL OR c.link_code IS NOT NULL OR c.channel = 'instagram')
              AND NOT EXISTS (SELECT 1 FROM eb_mkt_metrics m WHERE m.content_id = c.id AND m.source = 'auto' AND m.captured_at >= DATE_SUB(NOW(), INTERVAL 20 HOUR))
            ORDER BY c.id DESC LIMIT " . (int)$max);
        $n = 0;
        foreach ($rows as $r) { try { if (!empty(self::collect((int)$r['id'])['ok'])) $n++; } catch (Throwable $e) {} }
        return $n;
    }

    /** گزارش تجمیعی برای بازه (بر اساس تاریخ انتشار) به تفکیک پیج/کانال + برترین محتواها */
    public static function report(string $from, string $to): array
    {
        $rows = Database::all("SELECT c.id, c.title, c.channel, c.content_type, c.page_id, c.plan_date, c.published_at, c.budget, " . (Database::hasColumn('eb_mkt_content', 'spent') ? "c.spent" : "0 AS spent") . ", p.name AS page_name, p.color AS page_color
            FROM eb_mkt_content c LEFT JOIN eb_mkt_pages p ON p.id = c.page_id
            WHERE c.status = 'published' AND DATE(COALESCE(c.published_at, c.plan_date)) BETWEEN ? AND ?", [$from, $to]);
        $lat = self::latest(array_column($rows, 'id'));
        $groups = []; $items = []; $tot = array_fill_keys(self::FIELDS, 0); $tot['contents'] = 0; $tot['measured'] = 0;
        foreach ($rows as $r) {
            $m = $lat[(int)$r['id']] ?? null;
            $gk = $r['page_id'] ? 'p' . $r['page_id'] : 'c' . ($r['channel'] ?: '-');
            if (!isset($groups[$gk])) $groups[$gk] = ['key' => $gk, 'name' => $r['page_name'] ?: ($r['channel'] ?: 'بدون کانال'), 'color' => $r['page_color'], 'channel' => $r['channel'], 'contents' => 0, 'measured' => 0] + array_fill_keys(self::FIELDS, 0);
            $groups[$gk]['contents']++; $tot['contents']++;
            if ($m) {
                $groups[$gk]['measured']++; $tot['measured']++;
                foreach (self::FIELDS as $f) if (isset($m[$f])) { $groups[$gk][$f] += $m[$f]; $tot[$f] += $m[$f]; }
            }
            $eng = $m ? (($m['likes'] ?? 0) + ($m['comments'] ?? 0) + ($m['shares'] ?? 0) + ($m['saves'] ?? 0)) : 0;
            $base = $m ? ($m['reach'] ?? $m['views'] ?? 0) : 0;
            $items[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'channel' => $r['channel'], 'type' => $r['content_type'], 'page' => $r['page_name'], 'page_color' => $r['page_color'],
                'date' => $r['published_at'] ? Tz::toLocal($r['published_at']) : $r['plan_date'], 'metrics' => $m, 'engagement' => $eng,
                'er' => $base ? round($eng * 100 / $base, 2) : null, 'cost' => (float)($r['spent'] ?: $r['budget'] ?: 0)];
        }
        foreach ($groups as &$g) { $base = $g['reach'] ?: $g['views']; $g['er'] = $base ? round(($g['likes'] + $g['comments'] + $g['shares'] + $g['saves']) * 100 / $base, 2) : null; }
        unset($g);
        usort($items, fn($a, $b) => ($b['engagement'] <=> $a['engagement']) ?: (($b['metrics']['views'] ?? 0) <=> ($a['metrics']['views'] ?? 0)));
        return ['from' => $from, 'to' => $to, 'total' => $tot, 'groups' => array_values($groups), 'items' => $items];
    }
}
