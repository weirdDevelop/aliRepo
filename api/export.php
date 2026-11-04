<?php
/**
 * api/export.php
 * خروجی/پشتیبان‌گیری داده به‌صورت CSV. از index.php با ?export=NAME صدا زده می‌شود.
 * چون فایل دانلودی است (نه JSON)، هدر خودش را می‌فرستد.
 * دسترسی: کاربر باید لاگین باشد (در index.php بررسی شده).
 */

$what = $_GET['export'] ?? '';

/** ارسال یک آرایه به‌صورت فایل CSV با BOM (تا اکسل فارسی درست بخواند). */
function sendCsv(string $filename, array $headers, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // BOM برای اکسل
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
}

switch ($what) {
    case 'customers':
        // همان فیلترهای persons_list را می‌پذیرد (از طریق GET) تا «خروجی همین نمای فیلترشده» ممکن باشد.
        $conds = []; $params = [];
        $q = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? '';
        $hasPhone = $_GET['has_phone'] ?? '';
        $groupId = !empty($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
        $tagId = !empty($_GET['tag_id']) ? (int)$_GET['tag_id'] : 0;

        if ($q !== '') { $conds[] = "(p.full_name LIKE ? OR p.phone LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($status === 'member' || $status === 'anonymous') { $conds[] = "p.status = ?"; $params[] = $status; }
        if ($hasPhone === 'yes') $conds[] = "p.phone IS NOT NULL AND p.phone <> ''";
        if ($hasPhone === 'no')  $conds[] = "(p.phone IS NULL OR p.phone = '')";
        if ($groupId) { $conds[] = "p.id IN (SELECT person_id FROM eb_person_groups WHERE group_id = ?)"; $params[] = $groupId; }
        if ($tagId)   { $conds[] = "p.id IN (SELECT person_id FROM eb_person_tags WHERE tag_id = ?)"; $params[] = $tagId; }

        // محدودیت گروه اپراتور
        $allowed = Auth::allowedGroups();
        if ($allowed !== null) {
            if (!$allowed) $allowed = [-1];
            $ph = implode(',', array_fill(0, count($allowed), '?'));
            $conds[] = "p.id IN (SELECT person_id FROM eb_person_groups WHERE group_id IN ($ph))";
            foreach ($allowed as $g) $params[] = $g;
        }

        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $persons = Database::all(
            "SELECT p.id, p.full_name, p.phone, p.status, p.notes, p.created_at,
                (SELECT GROUP_CONCAT(DISTINCT channel) FROM eb_identities WHERE person_id=p.id) channels,
                (SELECT GROUP_CONCAT(g.name) FROM eb_groups g INNER JOIN eb_person_groups pg ON pg.group_id=g.id WHERE pg.person_id=p.id) `groups`,
                (SELECT GROUP_CONCAT(t.name) FROM eb_tags t INNER JOIN eb_person_tags pt ON pt.tag_id=t.id WHERE pt.person_id=p.id) `tags`,
                (SELECT SUM(m.direction='out') FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE i.person_id=p.id) sent,
                (SELECT SUM(m.direction='in') FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE i.person_id=p.id) received
             FROM eb_persons p $where ORDER BY p.id DESC",
            $params
        );

        $rows = [];
        foreach ($persons as $p) {
            $rows[] = [
                $p['id'],
                $p['full_name'] ?? '',
                $p['phone'] ?? '',
                $p['status'] === 'member' ? 'عضو' : 'ناشناس',
                $p['channels'] ?? '',
                $p['groups'] ?? '',
                $p['tags'] ?? '',
                (int)($p['sent'] ?? 0),
                (int)($p['received'] ?? 0),
                $p['notes'] ?? '',
                $p['created_at'] ?? '',
            ];
        }
        sendCsv(
            'customers_' . date('Y-m-d') . '.csv',
            ['شناسه', 'نام', 'موبایل', 'وضعیت', 'کانال‌ها', 'گروه‌ها', 'برچسب‌ها', 'ارسالی', 'دریافتی', 'یادداشت', 'تاریخ ایجاد'],
            $rows
        );
        exit;

    case 'messages':
        // خروجی پیام‌های یک مشتری خاص (تاریخچه‌ی کامل گفتگو)
        $pid = (int)($_GET['person_id'] ?? 0);
        if (!$pid) { http_response_code(400); exit('person_id لازم است'); }
        // محدودیت گروه اپراتور
        $allowed = Auth::allowedGroups();
        if ($allowed !== null) {
            $ok = $allowed ? Database::one(
                "SELECT 1 FROM eb_person_groups WHERE person_id=? AND group_id IN (".implode(',',array_fill(0,count($allowed),'?')).")",
                array_merge([$pid], $allowed)
            ) : null;
            if (!$ok) { http_response_code(403); exit('دسترسی ندارید'); }
        }
        $msgs = Database::all(
            "SELECT m.created_at, m.channel, m.direction, m.body, m.status
             FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id
             WHERE i.person_id = ? ORDER BY m.created_at ASC",
            [$pid]
        );
        $rows = [];
        foreach ($msgs as $m) {
            $rows[] = [
                $m['created_at'],
                $m['channel'],
                $m['direction'] === 'out' ? 'ارسالی' : 'دریافتی',
                $m['body'] ?? '',
                $m['status'] ?? '',
            ];
        }
        sendCsv('messages_' . $pid . '_' . date('Y-m-d') . '.csv',
            ['تاریخ', 'کانال', 'جهت', 'متن', 'وضعیت'], $rows);
        exit;

    case 'notice_leads':
        $noticeId = (int)($_GET['notice_id'] ?? 0);
        $rows = [];
        foreach (Notices::leadsForExport($noticeId) as $l) {
            $rows[] = [
                $l['created_at'],
                $l['notice_title'] ?? '',
                $l['phone'] ?? '',
                $l['discount_code'] ?? '',
                $l['full_name'] ?? '',
                $l['page_url'] ?? '',
            ];
        }
        sendCsv(
            'notice_leads_' . date('Y-m-d') . '.csv',
            ['تاریخ', 'اعلان', 'موبایل', 'کد تخفیف', 'نام مشتری (اگر شناخته‌شده)', 'صفحه'],
            $rows
        );
        exit;

    case 'analytics_sessions':
        // مورد ۹: خروجی خام برای پشتیبان‌گیری/بررسی دستی — قبل از پاک‌سازی خودکار
        // یا هر وقت خودت خواستی، تا اطلاعات ریز (نه فقط رول‌آپ روزانه) از دست نرود.
        $days = max(1, min(730, (int)($_GET['days'] ?? 90)));
        $rows = Database::all(
            "SELECT s.id, s.started_at, v.visitor_uid, s.entry_page, s.exit_page, s.referrer,
                    s.utm_source, s.utm_medium, s.utm_campaign, s.link_code,
                    s.device, s.browser, s.os, s.country, s.city, s.duration_sec, s.page_count
             FROM eb_sessions s JOIN eb_visitors v ON v.id = s.visitor_id
             WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY s.id DESC",
            [$days]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r['started_at'], $r['visitor_uid'], $r['entry_page'], $r['exit_page'], $r['referrer'],
                $r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['link_code'],
                $r['device'], $r['browser'], $r['os'], $r['country'], $r['city'], $r['duration_sec'], $r['page_count'],
            ];
        }
        sendCsv(
            'analytics_sessions_' . date('Y-m-d') . '.csv',
            ['تاریخ', 'شناسه بازدیدکننده', 'صفحه ورود', 'صفحه خروج', 'ارجاع‌دهنده', 'utm_source', 'utm_medium', 'utm_campaign', 'کد لینک رهگیری', 'دستگاه', 'مرورگر', 'سیستم‌عامل', 'کشور', 'شهر', 'مدت (ثانیه)', 'تعداد صفحه'],
            $out
        );
        exit;

    default:
        http_response_code(404);
        exit('نوع خروجی ناشناخته');
}
