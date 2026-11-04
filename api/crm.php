<?php
/**
 * api/crm.php — ۲.۰.۰: سیستم مدیریت یکپارچه (پیشوند crm_)
 * از api/handler.php صدا زده می‌شود ($in، $api، $config، out()، adminOnly()).
 */

function crm_src(array $in): PDO
{
    $c = (array)($in['src'] ?? []);
    try { return CrmBridge::srcConnect($c); }
    catch (Throwable $e) { out(['ok' => false, 'error' => 'اتصال به دیتابیس CRM قبلی برقرار نشد: ' . $e->getMessage()]); }
}

switch ($api) {

case 'crm_status':
    adminOnly();
    out(['ok' => true, 'sync' => CrmBridge::syncStatus()] + CrmBridge::status());

case 'crm_kpis':
    if (!CrmBridge::present()) out(['ok' => true, 'kpis' => null]);
    $k = CrmBridge::kpis();
    // هر کارت فقط برای کسی که آن بخش CRM را می‌بیند
    if ($k) {
        $need = ['calls' => 'crm_calls', 'leads' => 'crm_leads', 'tickets' => 'crm_tickets', 'tasks' => 'crm_tasks', 'leaves' => 'crm_leaves', 'customers' => 'crm_customers', 'host_leads' => 'crm_host_leads'];
        foreach ($need as $kk => $pg) if (!Auth::canAccess($pg)) unset($k[$kk]);
    }
    out(['ok' => true, 'kpis' => $k]);

case 'crm_person':
    if (!CrmBridge::present()) out(['ok' => true, 'crm' => null]);
    if (!Auth::canAccess('inbox') && !Auth::canAccess('persons')) out(['ok' => false, 'error' => 'به این بخش دسترسی ندارید']);
    $phone = (string)($in['phone'] ?? '');
    if (!CrmBridge::last10($phone) && !empty($in['person_id'])) {
        $p = Database::one("SELECT phone FROM eb_persons WHERE id = ?", [(int)$in['person_id']]);
        $phone = (string)($p['phone'] ?? '');
    }
    $r = CrmBridge::byPhone($phone);
    if ($r && !empty($r['found'])) {
        $r['can'] = ['customers' => Auth::canAccess('crm_customers'), 'leads' => Auth::canAccess('crm_leads'), 'calls' => Auth::canAccess('crm_calls'), 'hosts' => Auth::canAccess('crm_hosts'), 'tickets' => Auth::canAccess('crm_tickets')];
        if (!$r['can']['calls']) $r['calls']['recent'] = [];
    }
    out(['ok' => true, 'crm' => $r]);

case 'crm_users':
    adminOnly();
    out(['ok' => true] + CrmBridge::users());

case 'crm_user_link':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    $r = CrmBridge::link((int)($in['eb_id'] ?? 0), (int)($in['crm_id'] ?? 0), (string)($in['exten'] ?? ''), (int)($in['staff_id'] ?? 0));
    out($r + ['users' => CrmBridge::users()]);

case 'crm_install_empty':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    if (CrmBridge::ready()) out(['ok' => false, 'error' => 'جدول‌های CRM از قبل وجود دارند']);
    $r = CrmBridge::installEmpty();
    Auth::audit('crm_install', 'ساخت جدول‌های خالی CRM', (int)Auth::id());
    out($r + ['status' => CrmBridge::status()]);

case 'crm_mig_tables':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    $src = crm_src($in);
    if (CrmBridge::sameDb($src)) out(['ok' => true, 'same' => true, 'tables' => []]);
    try { $t = CrmBridge::srcTables($src); } catch (Throwable $e) { out(['ok' => false, 'error' => $e->getMessage()]); }
    if (!array_filter($t, fn($x) => $x['table'] === 'users') || !array_filter($t, fn($x) => $x['table'] === 'customers'))
        out(['ok' => false, 'error' => 'این دیتابیس شبیه دیتابیس CRM جاکجاست نیست (جدول users یا customers ندارد).', 'tables' => $t]);
    out(['ok' => true, 'same' => false, 'tables' => $t]);

case 'crm_mig_copy':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    @set_time_limit(120);
    $src = crm_src($in);
    if (CrmBridge::sameDb($src)) out(['ok' => false, 'error' => 'مبدأ و مقصد یک دیتابیس‌اند؛ انتقال لازم نیست']);
    $table = (string)($in['table'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) out(['ok' => false, 'error' => 'نام جدول نامعتبر']);
    try { $r = CrmBridge::copyTable($src, $table, max(0, (int)($in['offset'] ?? 0)), !empty($in['replace'])); }
    catch (Throwable $e) { out(['ok' => false, 'error' => $e->getMessage(), 'table' => $table]); }
    out(['ok' => true, 'table' => $table] + $r);

case 'crm_mig_done':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    CrmBridge::afterMigrate();
    Auth::audit('crm_migrate', 'انتقال داده‌های CRM قبلی', (int)Auth::id());
    out(['ok' => true, 'status' => CrmBridge::status()]);

case 'crm_sync':
    if (!Auth::isRealAdmin()) out(['ok' => false, 'error' => 'فقط مدیر']);
    @set_time_limit(60);
    if (!empty($in['reset'])) CrmBridge::syncReset();
    $r = CrmBridge::syncStep(20);
    out(['ok' => true] + $r + ['status' => CrmBridge::syncStatus()]);

case 'crm_sync_status':
    adminOnly();
    out(['ok' => true, 'status' => CrmBridge::syncStatus()]);

case 'crm_timeline':
    // پرونده‌ی یکپارچه: سوابق CRM یک شخص بانک مشتری
    if (!Auth::canAccess('persons') && !Auth::canAccess('inbox')) out(['ok' => false, 'error' => 'به این بخش دسترسی ندارید']);
    $p = Database::one("SELECT id, phone FROM eb_persons WHERE id = ?", [(int)($in['person_id'] ?? 0)]);
    if (!$p) out(['ok' => false, 'error' => 'مشتری پیدا نشد']);
    $allowed = Auth::allowedGroups();
    if ($allowed !== null && !($allowed && Database::one("SELECT 1 FROM eb_person_groups WHERE person_id = ? AND group_id IN (" . implode(',', array_map('intval', $allowed)) . ") LIMIT 1", [(int)$p['id']])))
        out(['ok' => false, 'error' => 'به این مشتری دسترسی ندارید']);
    if (!CrmBridge::present()) out(['ok' => true, 'ready' => false, 'events' => []]);
    $tl = CrmBridge::timeline($p['phone'] ?? null, Auth::canAccess('crm_calls'));
    out(['ok' => true, 'can_calls' => Auth::canAccess('crm_calls'), 'can_customers' => Auth::canAccess('crm_customers'), 'phone' => $p['phone']] + $tl);

case 'crm_find_person':
    // از صفحه‌های CRM به پرونده‌ی یکپارچه: شماره ← شخص (اگر نبود ساخته می‌شود)
    if (!Auth::canAccess('persons')) out(['ok' => false, 'error' => 'به بانک مشتری دسترسی ندارید']);
    $ph = Persons::normalizePhone((string)($in['phone'] ?? ''));
    if ($ph === null) out(['ok' => false, 'error' => 'شماره‌ی موبایل معتبر نیست']);
    $p = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$ph]);
    if (!$p && !Auth::isViewer()) {
        Database::run("INSERT IGNORE INTO eb_persons (phone, status) VALUES (?, 'member')", [$ph]);
        $p = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$ph]);
    }
    out($p ? ['ok' => true, 'id' => (int)$p['id']] : ['ok' => false, 'error' => 'مشتری پیدا نشد']);

default:
    out(['ok' => false, 'error' => 'unknown crm api']);
}
