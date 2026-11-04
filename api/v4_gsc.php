<?php
/**
 * api/v4_gsc.php — سرچ کنسول و ابزارهای سئو (gsc_). از api/v4.php require می‌شود.
 */
require_once __DIR__ . '/../modules/SearchConsole.php';
v4_can('analytics');
$base = rtrim(app_base_url($config), '/');
$admin = ['gsc_config_save', 'gsc_auth_url', 'gsc_connect_service', 'gsc_disconnect', 'gsc_site_set', 'gsc_sitemap_submit', 'gsc_sitemap_delete'];
if (in_array($api, $admin, true)) adminOnly();
@set_time_limit(120);

switch ($api) {
    case 'gsc_state':
        out(['ok' => true, 'state' => SearchConsole::publicState($base), 'alerts' => SearchConsole::connected() ? SearchConsole::alertList() : []]);
    case 'gsc_config_save':
        $vals = [];
        if (isset($in['client_id'])) $vals['client_id'] = trim((string)$in['client_id']);
        if (!empty($in['client_secret'])) $vals['client_secret'] = trim((string)$in['client_secret']);
        if (isset($in['brand_terms'])) $vals['brand_terms'] = mb_substr((string)$in['brand_terms'], 0, 1000);
        if (isset($in['inspect_daily'])) $vals['inspect_daily'] = (string)max(0, min(1900, (int)$in['inspect_daily']));
        if (isset($in['audit_daily'])) $vals['audit_daily'] = (string)max(0, min(3000, (int)$in['audit_daily']));
        if (isset($in['alerts'])) $vals['alerts'] = !empty($in['alerts']) ? '1' : '0';
        SearchConsole::set($vals);
        SearchConsole::clearCache();
        out(['ok' => true, 'state' => SearchConsole::publicState($base)]);
    case 'gsc_auth_url':
        $c = SearchConsole::cfg();
        if ($c['client_id'] === '' || $c['client_secret'] === '') out(['ok' => false, 'error' => 'ابتدا Client ID و Client Secret را ذخیره کنید']);
        $state = bin2hex(random_bytes(16));
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('gsc.oauth_state', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$state . '|' . time()]);
        out(['ok' => true, 'url' => SearchConsole::authUrl($base, $state)]);
    case 'gsc_connect_service':
        $json = (string)($in['json'] ?? '');
        if ($json === '' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) $json = (string)file_get_contents($_FILES['file']['tmp_name']);
        out(SearchConsole::connectService($json));
    case 'gsc_disconnect':
        SearchConsole::disconnect();
        SearchConsole::clearCache();
        out(['ok' => true]);
    case 'gsc_sites':
        out(SearchConsole::sites());
    case 'gsc_site_set':
        $site = trim((string)($in['site'] ?? ''));
        if ($site === '') out(['ok' => false, 'error' => 'سایت انتخاب نشده']);
        SearchConsole::set(['site' => $site]);
        out(['ok' => true]);
    case 'gsc_performance':
        out(SearchConsole::performance($in));
    case 'gsc_drill':
        out(SearchConsole::drill($in));
    case 'gsc_opportunities':
        out(SearchConsole::opportunities($in));
    case 'gsc_history':
        out(['ok' => true, 'rows' => SearchConsole::history()]);
    case 'gsc_sitemaps':
        out(SearchConsole::sitemaps());
    case 'gsc_sitemap_submit':
        out(SearchConsole::submitSitemap((string)($in['url'] ?? '')));
    case 'gsc_sitemap_delete':
        out(SearchConsole::submitSitemap((string)($in['url'] ?? ''), true));
    case 'gsc_sitemap_import':
        out(SearchConsole::importSitemap((string)($in['url'] ?? ''), (int)($in['max'] ?? 5000)));
    case 'gsc_inspect':
        out(SearchConsole::inspect(trim((string)($in['url'] ?? ''))));
    case 'gsc_urls':
        out(SearchConsole::urls($in));
    case 'gsc_urls_delete':
        SearchConsole::deleteUrls((array)($in['ids'] ?? []));
        out(['ok' => true]);
    case 'gsc_audit':
        out(SearchConsole::audit((int)($in['id'] ?? 0)));
    case 'gsc_generate':
        out(SearchConsole::generateSitemap(trim((string)($in['url'] ?? '')), (int)($in['limit'] ?? 300)));
    case 'gsc_alert_seen':
        Database::run("UPDATE eb_gsc_alerts SET seen = 1");
        out(['ok' => true]);
}
