<?php
/**
 * api/v4.php
 * اکشن‌های ماژول‌های تکمیلی. از api/handler.php برای پیشوندهای igpost_ / flow_ / fab_ / wcx_ / gsc_ / v4_ صدا زده می‌شود
 * ($in، $api، $config، out()، adminOnly() از همان‌جا در دسترس‌اند).
 */
require_once __DIR__ . '/../core/SchemaV4.php';
require_once __DIR__ . '/../core/Tz.php';
SchemaV4::ensure();

function v4_can(string ...$pages): void
{
    if (Auth::isAdmin()) return;
    foreach ($pages as $p) if (Auth::canAccess($p)) return;
    out(['ok' => false, 'error' => 'به این بخش دسترسی ندارید']);
}

$prefix = explode('_', $api, 2)[0];

/* ======================= صف انتشار اینستاگرام ======================= */
if ($prefix === 'igpost') {
    require_once __DIR__ . '/../modules/IgPosts.php';
    v4_can('instagram', 'marketing');
    switch ($api) {
        case 'igpost_list':
            out(['ok' => true, 'posts' => IgPosts::list([
                'ig_account_id' => (int)($in['ig_account_id'] ?? 0),
                'mkt_content_id' => (int)($in['mkt_content_id'] ?? 0),
                'status' => (string)($in['status'] ?? ''),
            ]), 'now_local' => Tz::nowLocal()]);
        case 'igpost_save':
            $r = IgPosts::create([
                'id' => (int)($in['id'] ?? 0),
                'ig_account_id' => (int)($in['ig_account_id'] ?? 0),
                'kind' => (string)($in['kind'] ?? 'post'),
                'media_path' => (string)($in['media_path'] ?? ''),
                'caption' => (string)($in['caption'] ?? ''),
                'run_at' => trim((string)($in['run_at'] ?? '')),
                'mkt_content_id' => (int)($in['mkt_content_id'] ?? 0),
            ]);
            if ($r['ok'] && !empty($in['publish_now'])) {
                $p = IgPosts::publishNow($r['id']);
                out(['ok' => !empty($p['ok']), 'id' => $r['id'], 'error' => $p['error'] ?? null, 'queued' => empty($p['ok'])]);
            }
            out($r);
        case 'igpost_cancel':
            out(['ok' => IgPosts::cancel((int)($in['id'] ?? 0))]);
        case 'igpost_delete':
            IgPosts::delete((int)($in['id'] ?? 0));
            out(['ok' => true]);
        case 'igpost_retry':
            out(['ok' => IgPosts::retry((int)($in['id'] ?? 0))]);
        case 'igpost_publish_now':
            out(IgPosts::publishNow((int)($in['id'] ?? 0)));
    }
}

/* ======================= پروکسی ======================= */
if ($prefix === 'v4') {
    switch ($api) {
        case 'v4_proxy_get':
            adminOnly();
            out(['ok' => true] + NetProxy::publicSettings() + ['types' => NetProxy::TYPES, 'target_labels' => NetProxy::TARGET_LABELS, 'worker' => NetProxy::workerScript()]);
        case 'v4_proxy_save':
            adminOnly();
            $r = NetProxy::saveFromInput($in);
            out($r);
        case 'v4_proxy_test':
            adminOnly();
            out(NetProxy::test((string)($in['target'] ?? 'telegram'), (string)($in['id'] ?? '')));
        case 'v4_proxy_parse':
            adminOnly();
            out(NetProxy::parseLink((string)($in['link'] ?? '')));
    }
    // ---------- آنالیتیکس با فیلتر ----------
    if (strpos($api, 'v4_an_') === 0) {
        v4_can('analytics');
        require_once __DIR__ . '/../modules/AnalyticsQuery.php';
        if (!AnalyticsSettings::isActive() && $api !== 'v4_an_views' && $api !== 'v4_an_views_save') {
            out(['ok' => false, 'inactive' => true, 'error' => 'آنالیتیکس سایت هنوز روشن نشده است']);
        }
        switch ($api) {
            case 'v4_an_report':   out(['ok' => true] + AnalyticsQuery::report($in));
            case 'v4_an_options':  out(['ok' => true, 'options' => AnalyticsQuery::options($in)]);
            case 'v4_an_sessions': out(['ok' => true] + AnalyticsQuery::sessions($in));
            case 'v4_an_views':    out(['ok' => true, 'views' => AnalyticsQuery::savedViews()]);
            case 'v4_an_views_save': out(['ok' => true, 'views' => AnalyticsQuery::saveViews((array)($in['views'] ?? []))]);
        }
    }
}

foreach (['mktm' => 'v42_mkt.php', 'flow' => 'v4_flows.php', 'fab' => 'v4_widgets.php', 'wcx' => 'v4_widgets.php', 'gsc' => 'v4_gsc.php'] as $p => $file) {
    if ($prefix === $p) { require __DIR__ . '/' . $file; break; }
}

out(['ok' => false, 'error' => 'اکشن ناشناخته: ' . $api]);
