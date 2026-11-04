<?php
/**
 * modules/Analytics.php
 * هسته‌ی صفحه‌ی «آنالیتیکس» — گزارش‌هایی که از داده‌ی موجود (eb_messages,
 * eb_link_clicks) قابل استخراج‌اند، بدون هیچ جدول یا ردیابی تازه.
 *
 * نسخه‌ی اول عمداً محدود به دو چیز است که واقعاً از داده‌ی فعلی درمی‌آید:
 *   ۱) تعامل با مشتری‌ها — چه کسی زیاد پیام می‌گیرد ولی جواب نمی‌دهد (مورد ۳).
 *   ۲) روند روزانه‌ی کلیک لینک‌های رهگیری در سطح کل سایت، نه فقط یک لینک (مورد ۲).
 *
 * هیت‌مپ رفتار بازدیدکننده و آمار کامل شبیه استس‌فا (مورد ۴ و ۵) اینجا نیست —
 * آن‌ها به یک ردیاب تازه روی سایت مشتری نیاز دارند (طراحی جدا، فاز بعدی).
 */

class Analytics
{
    /**
     * تعامل با مشتری‌ها در $days روز اخیر: چند پیام ما فرستادیم، چند پیام او
     * فرستاد، آخرین پیام هرکدام کِی بود. برای فهرست «بیشترین تعامل» و برای
     * پرچم «بی‌پاسخ» (آخرین حرف را ما زدیم و مشتری هنوز جواب نداده).
     *
     * @return array{rows: array, days:int}
     */
    public static function engagement(int $days = 30, int $limit = 100): array
    {
        $days = max(1, min(365, $days));
        $blockCond = (class_exists('Inbox') && Inbox::hasBlock()) ? "AND p.blocked_at IS NULL" : "";

        $rows = Database::all(
            "SELECT p.id, p.full_name, p.phone,
                    SUM(m.direction='out') AS out_count,
                    SUM(m.direction='in')  AS in_count,
                    MAX(CASE WHEN m.direction='in'  THEN m.created_at END) AS last_in,
                    MAX(CASE WHEN m.direction='out' THEN m.created_at END) AS last_out
             FROM eb_persons p
             JOIN eb_identities i ON i.person_id = p.id
             JOIN eb_messages m   ON m.identity_id = i.id
             WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) {$blockCond}
             GROUP BY p.id, p.full_name, p.phone
             HAVING out_count > 0
             ORDER BY out_count DESC
             LIMIT ?",
            [$limit]
        );

        foreach ($rows as &$r) {
            $r['out_count'] = (int)$r['out_count'];
            $r['in_count']  = (int)$r['in_count'];
            // بی‌پاسخ: آخرین حرف را ما زده‌ایم (یا اصلاً هیچ‌وقت جواب نداده) و او هنوز چیزی نگفته
            $r['unanswered'] = $r['last_out'] !== null
                && ($r['last_in'] === null || strtotime($r['last_out']) > strtotime($r['last_in']));
        }
        unset($r);

        return ['rows' => $rows, 'days' => $days];
    }

    /**
     * روند روزانه‌ی کلیک همه‌ی لینک‌های رهگیری با هم (نه یک لینک خاص) — برای
     * دیدن کلی «سایت چقدر بازدید/کلیک از کانال‌های ارسالی می‌گیرد».
     * @return array{days:string[], counts:int[], top_links: array}
     */
    public static function linkClicksOverview(int $days = 30): array
    {
        $days = max(1, min(180, $days));

        $rows = Database::all(
            "SELECT DATE(clicked_at) d, COUNT(*) c FROM eb_link_clicks
             WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(clicked_at)",
            [$days - 1]
        );
        $byDay = [];
        foreach ($rows as $r) { $byDay[$r['d']] = (int)$r['c']; }

        $labels = []; $counts = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = $d;
            $counts[] = $byDay[$d] ?? 0;
        }

        $topLinks = Database::all(
            "SELECT l.id, l.title, l.code, l.target_url, COUNT(c.id) c
             FROM eb_link_clicks c JOIN eb_links l ON l.id = c.link_id
             WHERE c.clicked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY l.id, l.title, l.code, l.target_url
             ORDER BY c DESC LIMIT 8",
            [$days - 1]
        );

        return ['days' => $labels, 'counts' => $counts, 'top_links' => $topLinks];
    }
}
