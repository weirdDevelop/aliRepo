<?php
/**
 * api/chatplus.php — ۱.۰.۱۴: صفحه‌ی «چت و پشتیبانی» (پیشوند cp_)
 * از api/handler.php صدا زده می‌شود ($in، $api، $config، out()، adminOnly()).
 */
ChatPlus::ensure();

switch ($api) {

case 'cp_get':
    adminOnly();
    $voice = class_exists('ChannelManager') && ChannelManager::voiceAvailable();
    out(['ok' => true, 'settings' => ChatPlus::settings(), 'defaults' => ChatPlus::DEFAULTS,
         'voice_ready' => $voice, 'cb_enabled' => class_exists('SalesAgent') && SalesAgent::get('cb_enabled') === '1',
         'webchat' => class_exists('Webchat') && Webchat::isEnabled(),
         'staff' => ChatPlus::parseStaff(ChatPlus::get('ivr_phones')), 'ivr_log' => ChatPlus::ivrLog(30),
         'filters' => ChatPlus::filteredList(60), 'units' => ChatPlus::unitsReport(), 'phones' => ChatPlus::phoneReport(),
         'search_tool' => (bool)ChatPlus::searchTool(), 'priority_tag' => ChatPlus::PRIORITY_TAG]);

case 'cp_save':
    adminOnly();
    $errors = ChatPlus::save((array)($in['settings'] ?? []));
    out(['ok' => true, 'settings' => ChatPlus::settings(), 'warnings' => $errors, 'staff' => ChatPlus::parseStaff(ChatPlus::get('ivr_phones'))]);

case 'cp_filters':
    // مدیر و هر پشتیبانی که صندوق ورودی دارد (رفع فیلتر هر گفتگو با inbox_unfilter و محدودیت گروه اپراتور)
    if (!Auth::isAdmin() && !Auth::canAccess('inbox')) out(['ok' => false, 'error' => 'به این بخش دسترسی ندارید']);
    $rows = ChatPlus::filteredList(100);
    $allowed = Auth::allowedGroups();
    if ($allowed !== null) {
        $rows = array_values(array_filter($rows, function ($r) use ($allowed) {
            if (!$allowed) return false;
            $ph = implode(',', array_fill(0, count($allowed), '?'));
            return (bool)Database::one("SELECT 1 FROM eb_person_groups WHERE person_id = ? AND group_id IN ($ph) LIMIT 1", array_merge([(int)$r['person_id']], $allowed));
        }));
    }
    out(['ok' => true, 'rows' => $rows]);

case 'cp_unfilter_all':
    // ۱.۰.۱۴: رفع فیلتر همه‌ی مشتری‌های بلاک/متوقف‌شده با یک کلیک (فقط مدیر)
    adminOnly();
    $n = 0;
    foreach (ChatPlus::filteredList(500) as $r) { $x = ChatPlus::unfilter((int)$r['person_id'], (int)Auth::id()); if (!empty($x['ok'])) $n++; }
    out(['ok' => true, 'count' => $n, 'rows' => ChatPlus::filteredList(100)]);

case 'cp_ivr_test':
    adminOnly();
    $ph = trim((string)($in['phone'] ?? ''));
    $voice = ChannelManager::getVoice();
    if (!$voice) out(['ok' => false, 'error' => 'تلفن گویا فعال نیست: سرویس پیامک باید ملی‌پیامک باشد (همان حساب، تماس صوتی هم دارد)']);
    $targets = $ph !== '' ? [Persons::normalizePhone($ph)] : array_keys(ChatPlus::staffPhones());
    $targets = array_values(array_filter($targets));
    if (!$targets) out(['ok' => false, 'error' => 'شماره‌ی معتبری برای تماس آزمایشی نیست']);
    @set_time_limit(120);
    $res = [];
    foreach (array_slice($targets, 0, 3) as $t) {
        $r = $voice->callSpeech($t, ChatPlus::get('ivr_text'));
        Database::run("INSERT INTO eb_cp_ivr_calls (person_id, reason, phone, status, error, done_at) VALUES (NULL, 'test', ?, ?, ?, NOW())",
            [$t, !empty($r['ok']) ? 'sent' : 'failed', !empty($r['ok']) ? null : mb_substr((string)($r['error'] ?? ''), 0, 300)]);
        $res[] = ['phone' => $t, 'ok' => !empty($r['ok']), 'error' => $r['error'] ?? null];
    }
    out(['ok' => (bool)array_filter($res, fn($x) => $x['ok']), 'results' => $res, 'log' => ChatPlus::ivrLog(30),
         'error' => array_filter($res, fn($x) => $x['ok']) ? null : ($res[0]['error'] ?? 'تماس برقرار نشد')]);

case 'cp_units_sync':
    adminOnly();
    $r = ChatPlus::syncUnits((int)($in['pages'] ?? 30));
    out($r + ['units_report' => ChatPlus::unitsReport()]);

case 'cp_units_find':
    adminOnly();
    $q = trim((string)($in['q'] ?? ''));
    out(['ok' => true, 'rows' => ChatPlus::unitSearch($q, 12), 'match' => $q !== '' ? ChatPlus::matchUnit($q) : null]);

case 'cp_phones_run':
    adminOnly();
    if (!empty($in['restart'])) ChatPlus::resetPhoneMigration();
    $r = ChatPlus::migratePhones(25);
    out(['ok' => true, 'result' => $r, 'report' => ChatPlus::phoneReport()]);
}

out(['ok' => false, 'error' => 'اکشن ناشناخته: ' . $api]);
