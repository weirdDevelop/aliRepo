<?php
/** api/v42_mkt.php — آمار عملکرد محتوای تقویم (پیشوند mktm_) */
v4_can('marketing');
if (!ContentMetrics::ready()) { SchemaV4::ensure(true); }
switch ($api) {
    case 'mktm_get':
        $id = (int)($in['content_id'] ?? 0);
        $c = Database::one("SELECT id, title, channel, page_id, status, ext_media_id, ext_url, link_code FROM eb_mkt_content WHERE id = ?", [$id]);
        if (!$c) out(['ok' => false, 'error' => 'محتوا پیدا نشد']);
        $h = ContentMetrics::history($id);
        foreach ($h as &$r) $r['captured_local'] = Tz::toLocal($r['captured_at']);
        unset($r);
        out(['ok' => true, 'content' => $c, 'history' => $h, 'latest' => ContentMetrics::latest([$id])[$id] ?? null, 'fields' => ContentMetrics::FIELDS]);
    case 'mktm_save':
        try { $nid = ContentMetrics::save((int)($in['content_id'] ?? 0), (array)($in['m'] ?? []), (int)Auth::id()); out(['ok' => true, 'id' => $nid]); }
        catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }
    case 'mktm_delete':
        ContentMetrics::delete((int)($in['id'] ?? 0)); out(['ok' => true]);
    case 'mktm_link':
        out(['ok' => true] + ContentMetrics::link((int)($in['content_id'] ?? 0), $in));
    case 'mktm_collect':
        out(ContentMetrics::collect((int)($in['content_id'] ?? 0)));
    case 'mktm_posts':
        out(ContentMetrics::pagePosts((int)($in['content_id'] ?? 0)));
    case 'mktm_report':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? $in['from'] : date('Y-m-d', strtotime('-29 days'));
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? $in['to'] : date('Y-m-d');
        out(['ok' => true] + ContentMetrics::report($from, $to));
    case 'mktm_latest':
        out(['ok' => true, 'latest' => ContentMetrics::latest(array_map('intval', (array)($in['ids'] ?? [])))]);
}
out(['ok' => false, 'error' => 'اکشن ناشناخته: ' . $api]);
