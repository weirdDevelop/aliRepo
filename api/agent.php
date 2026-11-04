<?php
/**
 * api/agent.php — اکشن‌های دستیار فروش و اتصال‌های API (پیشوندهای agent_ و connector_)
 * از api/handler.php صدا زده می‌شود ($in، $api، $config، out()، adminOnly()).
 */
ApiConnectors::ensure();

$whUrl = fn(array $c) => rtrim(app_base_url($config), '/') . '/webhook/connector.php?c=' . $c['slug'];

switch ($api) {

case 'agent_get':
    adminOnly();
    $cons = array_map(fn($c) => ApiConnectors::publicRow($c) + ['webhook_url' => $whUrl($c)], ApiConnectors::all());
    $stats = Database::one("SELECT
        (SELECT COUNT(*) FROM eb_agent_orders) orders,
        (SELECT COUNT(*) FROM eb_agent_orders WHERE status IN ('paid','completed','confirmed')) paid,
        (SELECT COUNT(*) FROM eb_agent_log WHERE kind = 'tool' AND created_at >= CURDATE()) tools_today,
        (SELECT COUNT(*) FROM eb_agent_state WHERE mode = 'human') waiting") ?: [];
    $aiMsgs = Database::hasColumn('eb_messages', 'source') ? (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE source = 'ai' AND direction = 'out' AND created_at >= CURDATE()")['c'] ?? 0) : 0;
    [$jd, $wd] = SalesAgent::todayJalali();
    // قیف ۷ روز اخیر (تعداد مشتری‌های یکتا)
    $f = fn(string $w) => (int)(Database::one("SELECT COUNT(DISTINCT person_id) c FROM eb_agent_log WHERE person_id > 0 AND ok = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND $w")['c'] ?? 0);
    $funnel = [
        'chats' => Database::hasColumn('eb_messages', 'source') ? (int)(Database::one("SELECT COUNT(DISTINCT i.person_id) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE m.source = 'ai' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0) : 0,
        'offers' => $f("kind = 'tool' AND (tool LIKE '%search' OR tool LIKE '%send_links')"),
        'quotes' => $f("kind = 'tool' AND tool LIKE '%quote'"),
        'orders' => (int)(Database::one("SELECT COUNT(*) c FROM eb_agent_orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0),
        'paid' => (int)(Database::one("SELECT COUNT(*) c FROM eb_agent_orders WHERE status IN ('paid','completed','confirmed') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")['c'] ?? 0),
    ];
    $sec = (int)(Database::one("SELECT COUNT(*) c FROM eb_agent_security WHERE kind <> 'sim' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")['c'] ?? 0);
    $rating = Database::one("SELECT AVG(rating) a, COUNT(*) n FROM eb_agent_surveys WHERE rating IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)") ?: [];
    $pages = [];
    try { $pages = Database::all("SELECT id, COALESCE(NULLIF(label,''), username) name, username FROM eb_ig_accounts ORDER BY id"); } catch (Throwable $e) {}
    out(['ok' => true, 'settings' => SalesAgent::settings(), 'defaults' => SalesAgent::allDefaults(), 'ai_ready' => count(Ai::activeProviders()) > 0,
         'connectors' => $cons, 'presets' => array_map(fn($p) => ['slug' => $p['slug'], 'name' => $p['name']], array_values(ApiConnectors::presets())),
         'profiles' => AgentProfiles::all(), 'ig_pages' => $pages, 'kb_docs' => AgentKb::docs(),
         'stats' => $stats + ['ai_msgs_today' => $aiMsgs, 'security_24h' => $sec, 'rating_avg' => $rating['a'] !== null ? round((float)$rating['a'], 1) : null, 'rating_n' => (int)($rating['n'] ?? 0)],
         'funnel' => $funnel, 'today' => $jd . ' ' . $wd, 'callbacks' => AgentCallbacks::all('open', 30)]);

case 'agent_funnel_list':
    // ۲.۱.۲: فهرست پشت هر باکس قیف فروش (۷ روز اخیر) — همان تعریفِ شمارنده‌ها
    adminOnly();
    $stg = (string)($in['stage'] ?? '');
    $nm = "COALESCE(NULLIF(p.full_name,''), NULLIF(p.phone,''), CONCAT('#', p.id))";
    $rows = [];
    if ($stg === 'orders' || $stg === 'paid') {
        $w = $stg === 'paid' ? "AND o.status IN ('paid','completed','confirmed')" : '';
        $rows = Database::all("SELECT o.id, o.person_id, $nm AS person, p.phone, o.ref, o.title, o.status, o.amount, o.created_at AS at
            FROM eb_agent_orders o LEFT JOIN eb_persons p ON p.id = o.person_id
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) $w ORDER BY o.created_at DESC LIMIT 300");
        $kind = 'order';
    } elseif ($stg === 'offers' || $stg === 'quotes') {
        $w = $stg === 'quotes' ? "l.tool LIKE '%quote'" : "(l.tool LIKE '%search' OR l.tool LIKE '%send_links')";
        $rows = Database::all("SELECT l.person_id, $nm AS person, p.phone, MAX(l.created_at) AS at, COUNT(*) AS n, SUBSTRING_INDEX(GROUP_CONCAT(l.summary ORDER BY l.id DESC SEPARATOR '\n'), '\n', 1) AS summary
            FROM eb_agent_log l LEFT JOIN eb_persons p ON p.id = l.person_id
            WHERE l.person_id > 0 AND l.ok = 1 AND l.kind = 'tool' AND $w AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY l.person_id, p.full_name, p.phone, p.id ORDER BY at DESC LIMIT 300");
        $kind = 'person';
    } else {
        if (!Database::hasColumn('eb_messages', 'source')) out(['ok' => true, 'kind' => 'person', 'rows' => []]);
        $rows = Database::all("SELECT i.person_id, $nm AS person, p.phone, MAX(m.created_at) AS at, COUNT(*) AS n
            FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id LEFT JOIN eb_persons p ON p.id = i.person_id
            WHERE m.source = 'ai' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY i.person_id, p.full_name, p.phone, p.id ORDER BY at DESC LIMIT 300");
        $kind = 'person';
    }
    out(['ok' => true, 'kind' => $kind, 'rows' => $rows]);

case 'agent_save':
    adminOnly();
    SalesAgent::save(is_array($in['settings'] ?? null) ? $in['settings'] : []);
    out(['ok' => true, 'settings' => SalesAgent::settings()]);

case 'connector_save':
    adminOnly();
    try {
        $id = ApiConnectors::save($in, !empty($in['id']) ? (int)$in['id'] : null);
        $c = ApiConnectors::get($id);
        out(['ok' => true, 'connector' => ApiConnectors::publicRow($c) + ['webhook_url' => $whUrl($c)]]);
    } catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

case 'connector_delete':
    adminOnly();
    ApiConnectors::delete((int)($in['id'] ?? 0));
    out(['ok' => true]);

case 'connector_preset':
    adminOnly();
    $p = ApiConnectors::presets()[(string)($in['key'] ?? '')] ?? null;
    if (!$p) out(['ok' => false, 'error' => 'الگو پیدا نشد']);
    $ex = ApiConnectors::bySlug($p['slug']);
    try {
        if ($ex) {
            // فقط ابزارها و راهنما از الگو به‌روز می‌شوند؛ توکن، راز و آدرس دست نمی‌خورد
            $id = ApiConnectors::save(['slug' => $ex['slug'], 'name' => $ex['name'], 'base_url' => $ex['base_url'], 'auth_type' => $ex['auth_type'], 'auth_name' => $ex['auth_name'],
                'headers' => $ex['headers'], 'instructions' => $p['instructions'], 'tools' => $p['tools'], 'channel_map' => $p['channel_map'], 'test_path' => $ex['test_path'] ?: $p['test_path'],
                'webhook' => array_merge($p['webhook'], array_filter((array)$ex['webhook'])), 'is_active' => $ex['is_active']], $ex['id']);
        } else {
            $id = ApiConnectors::save($p + ['token' => (string)($in['token'] ?? '')]);
        }
    } catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }
    $c = ApiConnectors::get($id);
    out(['ok' => true, 'updated' => (bool)$ex, 'connector' => ApiConnectors::publicRow($c) + ['webhook_url' => $whUrl($c)]]);

case 'connector_test':
    adminOnly();
    $c = ApiConnectors::get((int)($in['id'] ?? 0));
    if (!$c) out(['ok' => false, 'error' => 'اتصال پیدا نشد']);
    $r = ApiConnectors::test($c);
    ApiConnectors::log(null, $c['id'], 'test', $r['path'] ?? null, null, $r['ok'], $r['http'] ?? null, $r['ok'] ? 'آزمایش اتصال موفق' : ('آزمایش ناموفق: ' . ($r['error'] ?? '')), $r['data'] ?? null, $r['ms'] ?? null);
    out(['ok' => true, 'result' => $r]);

case 'connector_tool_test':
    adminOnly();
    $c = ApiConnectors::get((int)($in['id'] ?? 0));
    if (!$c) out(['ok' => false, 'error' => 'اتصال پیدا نشد']);
    $t = null;
    foreach ($c['tools'] as $x) if ($x['name'] === ($in['tool'] ?? '')) $t = $x;
    if (!$t) out(['ok' => false, 'error' => 'ابزار پیدا نشد']);
    if (!empty($t['side_effect']) || !empty($t['confirm'])) out(['ok' => false, 'error' => 'این ابزار پیامک می‌فرستد یا سفارش ثبت می‌کند؛ از اینجا آزمایش نمی‌شود. با «آزمایش گفتگو» امتحانش کنید.']);
    $args = is_array($in['args'] ?? null) ? $in['args'] : (json_decode((string)($in['args'] ?? '{}'), true) ?: []);
    $r = ApiConnectors::call($c, $t, $args, []);
    ApiConnectors::log(null, $c['id'], 'test', $t['name'], $args, $r['ok'], $r['http'] ?? null, $r['ok'] ? 'آزمایش ابزار' : ('خطا: ' . $r['error']), $r['data'], $r['ms'] ?? null);
    out(['ok' => true, 'result' => $r]);

case 'agent_simulate':
    adminOnly();
    $sid = -(1000000 + (int)Auth::id());
    if (!empty($in['reset'])) { Database::run("DELETE FROM eb_agent_state WHERE person_id = ?", [$sid]); out(['ok' => true]); }
    $msgs = [];
    foreach ((array)($in['messages'] ?? []) as $m) {
        $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $txt = trim((string)($m['content'] ?? ''));
        if ($txt !== '') $msgs[] = ['role' => $role, 'content' => mb_substr($txt, 0, 2000)];
    }
    if (!$msgs || end($msgs)['role'] !== 'user') out(['ok' => false, 'error' => 'یک پیام از طرف مشتری بنویسید']);
    if (!count(Ai::activeProviders())) out(['ok' => false, 'error' => 'اول در «هوش مصنوعی» یک پروایدر فعال تنظیم کنید']);
    @set_time_limit(150);
    $marker = count(array_filter($msgs, fn($m) => $m['role'] === 'user'));
    $profile = AgentProfiles::resolve((string)($in['channel'] ?? 'instagram'), 0, (int)($in['profile_id'] ?? 0) ?: null);
    $g = SalesAgent::guardInbound(0, end($msgs)['content'], true);
    if ($g['action'] === 'block') out(['ok' => true, 'text' => AgentGuard::opt('guard_reply'), 'steps' => [['tool' => 'guard', 'args' => [], 'ok' => false, 'summary' => 'مسدود شد (امتیاز ' . $g['score'] . '): ' . implode('، ', $g['labels'])]], 'profile' => $profile['name']]);
    $ctx = ['person_id' => 0, 'state_id' => $sid, 'identity_id' => 0, 'channel' => (string)($in['channel'] ?? 'instagram'), 'profile' => $profile,
        'marker' => $marker, 'dry' => !empty($in['dry']) || !isset($in['dry']), 'query' => end($msgs)['content'],
        'introduced' => (bool)array_filter($msgs, fn($m) => $m['role'] === 'assistant')];
    if ($g['action'] === 'warn') $ctx['caution'] = $g['labels'];
    $r = SalesAgent::run($msgs, $ctx);
    $steps = $r['steps'];
    if ($g['action'] === 'warn') array_unshift($steps, ['tool' => 'guard', 'args' => [], 'ok' => false, 'summary' => 'هشدار (امتیاز ' . $g['score'] . '): ' . implode('، ', $g['labels'])]);
    out(['ok' => $r['ok'], 'text' => $r['text'], 'steps' => $steps, 'chips' => $r['chips'] ?? [], 'error' => $r['error'] ?? null, 'profile' => $profile['name']]);

/* ---------------- پروفایل برندها ---------------- */
case 'agent_profile_save':
    adminOnly();
    try { $id = AgentProfiles::save($in, !empty($in['id']) ? (int)$in['id'] : null); out(['ok' => true, 'id' => $id, 'profiles' => AgentProfiles::all()]); }
    catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

case 'agent_profile_delete':
    adminOnly();
    try { AgentProfiles::delete((int)($in['id'] ?? 0)); out(['ok' => true]); }
    catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

/* ---------------- پایگاه دانش ---------------- */
// ۱.۰.۱۷: پست‌های اینستاگرام — دستیار روشن/خاموش، دانش پست، تعداد سندهای مخصوص پست
case 'agent_posts':
    adminOnly();
    AgentKb::ensureScope();
    $posts = [];
    try {
        $hasMode = Database::hasColumn('eb_ig_post_knowledge', 'agent_mode');
        foreach (Database::all("SELECT media_id, title, knowledge_text" . ($hasMode ? ", agent_mode" : "") . " FROM eb_ig_post_knowledge") as $k)
            $posts[$k['media_id']] = ['media_id' => $k['media_id'], 'title' => (string)$k['title'], 'knowledge' => (string)$k['knowledge_text'], 'mode' => $k['agent_mode'] ?? 'inherit', 'comments' => 0, 'last' => null, 'page' => null];
        foreach (Database::all("SELECT c.media_id, COUNT(*) n, MAX(c.created_at) last, MAX(c.ig_account_id) acc FROM eb_ig_comments c WHERE c.media_id IS NOT NULL AND c.media_id <> '' AND c.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY c.media_id ORDER BY last DESC LIMIT 60") as $c) {
            $posts[$c['media_id']] = ($posts[$c['media_id']] ?? ['media_id' => $c['media_id'], 'title' => '', 'knowledge' => '', 'mode' => 'inherit']) + [];
            $posts[$c['media_id']]['comments'] = (int)$c['n']; $posts[$c['media_id']]['last'] = $c['last']; $posts[$c['media_id']]['page'] = $c['acc'] ? (int)$c['acc'] : null;
            if (empty($posts[$c['media_id']]['sample'])) $posts[$c['media_id']]['sample'] = (string)(Database::one("SELECT body FROM eb_ig_comments WHERE media_id = ? ORDER BY id DESC LIMIT 1", [$c['media_id']])['body'] ?? '');
        }
        foreach (Database::all("SELECT media_id, COUNT(*) n FROM eb_agent_kb_docs WHERE media_id IS NOT NULL GROUP BY media_id") as $d) if (isset($posts[$d['media_id']])) $posts[$d['media_id']]['docs'] = (int)$d['n'];
    } catch (Throwable $e) { error_log('[agent_posts] ' . $e->getMessage()); }
    uasort($posts, fn($a, $b) => strcmp((string)($b['last'] ?? ''), (string)($a['last'] ?? '')));
    out(['ok' => true, 'posts' => array_values($posts)]);

case 'agent_post_save':
    adminOnly();
    $md = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($in['media_id'] ?? '')));
    if ($md === '') out(['ok' => false, 'error' => 'شناسه‌ی پست لازم است']);
    IgCommentAutomation::savePost($md, trim((string)($in['title'] ?? '')), (string)($in['knowledge'] ?? ''), (string)($in['mode'] ?? 'inherit'));
    out(['ok' => true]);

case 'agent_kb_list':
    adminOnly();
    out(['ok' => true, 'docs' => AgentKb::docs()]);

case 'agent_kb_upload':
    adminOnly();
    $files = [];
    if (!empty($_FILES['files']['tmp_name']) && is_array($_FILES['files']['tmp_name'])) {
        foreach ($_FILES['files']['tmp_name'] as $i => $tmp) $files[] = ['tmp' => $tmp, 'name' => $_FILES['files']['name'][$i], 'size' => $_FILES['files']['size'][$i]];
    } elseif (!empty($_FILES['file']['tmp_name'])) $files[] = ['tmp' => $_FILES['file']['tmp_name'], 'name' => $_FILES['file']['name'], 'size' => $_FILES['file']['size']];
    $text = (string)($in['text'] ?? '');
    $profileId = (int)($in['profile_id'] ?? 0) ?: null;
    // ۱.۰.۱۷: دامنه‌ی کانال و پست
    $kbCh = (string)($in['channel'] ?? '') ?: null; $kbMd = trim((string)($in['media_id'] ?? '')) ?: null;
    $done = []; $errs = [];
    foreach ($files as $f) {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['md', 'markdown', 'txt'], true)) { $errs[] = $f['name'] . ': فقط md یا txt'; continue; }
        if ($f['size'] > 3 * 1024 * 1024) { $errs[] = $f['name'] . ': بزرگ‌تر از ۳ مگابایت'; continue; }
        if (!is_uploaded_file($f['tmp'])) { $errs[] = $f['name'] . ': آپلود نشد'; continue; }
        try { $r = AgentKb::import('', (string)file_get_contents($f['tmp']), $profileId, (string)$f['name'], $kbCh, $kbMd); $done[] = $f['name'] . ' (' . $r['chunks'] . ' بخش)'; }
        catch (InvalidArgumentException $e) { $errs[] = $f['name'] . ': ' . $e->getMessage(); }
    }
    if ($text !== '') {
        try { $r = AgentKb::import((string)($in['title'] ?? ''), $text, $profileId, '', $kbCh, $kbMd); $done[] = 'متن (' . $r['chunks'] . ' بخش)'; }
        catch (InvalidArgumentException $e) { $errs[] = $e->getMessage(); }
    }
    out(['ok' => (bool)$done, 'done' => $done, 'errors' => $errs, 'error' => $done ? null : ($errs ? implode(' · ', $errs) : 'فایلی دریافت نشد'), 'docs' => AgentKb::docs()]);

case 'agent_kb_update':
    adminOnly();
    AgentKb::updateDoc((int)($in['id'] ?? 0), $in);
    out(['ok' => true, 'docs' => AgentKb::docs()]);

case 'agent_kb_delete':
    adminOnly();
    AgentKb::deleteDoc((int)($in['id'] ?? 0));
    out(['ok' => true, 'docs' => AgentKb::docs()]);

case 'agent_kb_chunks':
    adminOnly();
    out(['ok' => true, 'chunks' => AgentKb::chunks((int)($in['id'] ?? 0))]);

case 'agent_kb_pin':
    adminOnly();
    AgentKb::pin((int)($in['id'] ?? 0), !empty($in['on']));
    out(['ok' => true]);

case 'agent_kb_search':
    adminOnly();
    $profile = AgentProfiles::resolve('', 0, (int)($in['profile_id'] ?? 0) ?: null);
    AgentKb::$scope = ['channel' => (string)($in['channel'] ?? ''), 'media_id' => trim((string)($in['media_id'] ?? ''))];
    $r = AgentKb::search((string)($in['q'] ?? ''), $profile);
    $sh = fn($c) => ['heading' => $c['heading'] ?: $c['title'], 'title' => $c['title'], 'len' => (int)$c['len'], 'score' => $c['score'] ?? null, 'preview' => mb_substr($c['content'], 0, 220)];
    out(['ok' => true, 'pinned' => array_map($sh, $r['pinned']), 'hits' => array_map($sh, $r['hits']), 'profile' => $profile['name']]);

/* ---------------- امنیت و نظرسنجی ---------------- */
case 'agent_security':
    adminOnly();
    out(['ok' => true, 'rows' => AgentGuard::events(150)]);

case 'agent_guard_test':
    adminOnly();
    out(['ok' => true] + AgentGuard::inspect((string)($in['text'] ?? '')));

case 'agent_surveys':
    adminOnly();
    out(['ok' => true, 'rows' => Database::all("SELECT s.*, o.ref, o.title, COALESCE(NULLIF(p.full_name,''), CONCAT('مشتری ', p.id)) person FROM eb_agent_surveys s
        LEFT JOIN eb_agent_orders o ON o.id = s.order_id LEFT JOIN eb_persons p ON p.id = s.person_id ORDER BY s.id DESC LIMIT 200")]);

case 'agent_report_preview':
    adminOnly();
    out(['ok' => true, 'text' => AgentLifecycle::buildReport()]);

case 'agent_report_send':
    adminOnly();
    $chats = AgentLifecycle::reportChats();
    if (!$chats) out(['ok' => false, 'error' => 'اول شناسه‌ی چت بله‌ی مدیر را وارد و ذخیره کنید']);
    if (!ChannelManager::get('bale')) out(['ok' => false, 'error' => 'ربات بله در «اتصال کانال‌ها» تنظیم نشده است']);
    $n = AgentLifecycle::sendReport($chats);
    out(['ok' => $n > 0, 'sent' => $n, 'total' => count($chats), 'error' => $n ? null : 'ارسال نشد؛ مدیر باید قبلاً یک پیام به ربات بله داده باشد']);

case 'agent_bale_contacts':
    adminOnly();
    out(['ok' => true, 'rows' => Database::all("SELECT i.channel_uid uid, COALESCE(NULLIF(p.full_name,''), NULLIF(i.first_name,''), i.username, i.channel_uid) name, i.username, i.last_seen
        FROM eb_identities i LEFT JOIN eb_persons p ON p.id = i.person_id WHERE i.channel = 'bale' ORDER BY i.last_seen DESC LIMIT 40")]);

case 'agent_lifecycle_run':
    adminOnly();
    out(['ok' => true, 'result' => AgentLifecycle::tick()]);

case 'agent_log':
    adminOnly();
    $w = []; $p = [];
    if (!empty($in['person_id'])) { $w[] = 'l.person_id = ?'; $p[] = (int)$in['person_id']; }
    if (!empty($in['before'])) { $w[] = 'l.id < ?'; $p[] = (int)$in['before']; }
    if (!empty($in['kind'])) { $w[] = 'l.kind = ?'; $p[] = (string)$in['kind']; }
    if (isset($in['ok']) && $in['ok'] !== '') { $w[] = 'l.ok = ?'; $p[] = (int)$in['ok']; }
    $rows = Database::all("SELECT l.id, l.person_id, l.connector_id, l.kind, l.tool, l.args_json, l.ok, l.http, l.summary, l.ms, l.created_at,
        c.name connector, COALESCE(NULLIF(pp.full_name,''), CONCAT('مشتری ', pp.id)) person
        FROM eb_agent_log l LEFT JOIN eb_api_connectors c ON c.id = l.connector_id LEFT JOIN eb_persons pp ON pp.id = l.person_id"
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY l.id DESC LIMIT 80", $p);
    out(['ok' => true, 'rows' => $rows, 'has_more' => count($rows) === 80]);

case 'agent_log_detail':
    adminOnly();
    $r = Database::one("SELECT * FROM eb_agent_log WHERE id = ?", [(int)($in['id'] ?? 0)]);
    out($r ? ['ok' => true, 'row' => $r] : ['ok' => false, 'error' => 'پیدا نشد']);

case 'agent_orders':
    adminOnly();
    $rows = Database::all("SELECT o.id, o.ref, o.ref2, o.title, o.status, o.amount, o.checkout_url, o.person_id, o.created_at, o.updated_at, o.check_in, o.check_out, o.rating, o.reminded_at, o.arrival_at, o.survey_at, c.name connector,
        COALESCE(NULLIF(p.full_name,''), CONCAT('مشتری ', p.id)) person
        FROM eb_agent_orders o LEFT JOIN eb_api_connectors c ON c.id = o.connector_id LEFT JOIN eb_persons p ON p.id = o.person_id
        ORDER BY o.id DESC LIMIT 200");
    out(['ok' => true, 'rows' => $rows]);

case 'agent_chat_state':
    $pid = (int)($in['person_id'] ?? 0);
    out(['ok' => true, 'enabled' => SalesAgent::enabled(), 'state' => $pid ? SalesAgent::state($pid)['mode'] : null]);

case 'agent_base_url':
    adminOnly();
    if (array_key_exists('url', $in)) {
        $u = rtrim(trim((string)$in['url']), '/');
        if ($u !== '' && !preg_match('#^https?://[^\s]+$#i', $u)) out(['ok' => false, 'error' => 'آدرس نامعتبر است']);
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('app.base_url_override', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$u]);
    }
    $o = Database::one("SELECT v FROM eb_settings WHERE k = 'app.base_url_override'");
    out(['ok' => true, 'override' => (string)($o['v'] ?? ''), 'config' => (string)($config['base_url'] ?? '')]);

case 'agent_webhook_info':
    adminOnly();
    $d = ChannelManager::get((string)($in['channel'] ?? 'bale'));
    if (!$d || !method_exists($d, 'webhookInfo')) out(['ok' => false, 'error' => 'این کانال وب‌هوک ربات ندارد']);
    $r = $d->webhookInfo();
    // آدرس شامل secret است؛ فقط بخش دامنه و مسیر نشان داده شود
    $res = $r['result'] ?? $r['data'] ?? $r;
    if (is_array($res) && isset($res['url'])) $res['url'] = preg_replace('/secret=[^&]+/', 'secret=…', (string)$res['url']);
    out(['ok' => true, 'info' => $res]);

case 'agent_demand':
    $f = array_intersect_key($in, array_flip(['area', 'type', 'class', 'group', 'channel', 'city', 'amenity']));
    out(['ok' => true] + AgentDemand::summary((int)($in['days'] ?? 30), array_map('strval', $f)));

case 'agent_demand_csv':
    out(['ok' => true, 'csv' => AgentDemand::csv((int)($in['days'] ?? 30))]);

case 'agent_callbacks':
    out(['ok' => true, 'rows' => AgentCallbacks::all((string)($in['status'] ?? 'open')), 'open' => AgentCallbacks::openCount()]);

case 'agent_callback_done':
    if (Auth::isViewer()) out(['ok' => false, 'error' => 'دسترسی ندارید']);
    AgentCallbacks::done((int)($in['id'] ?? 0), (int)Auth::id(), trim((string)($in['result'] ?? '')));
    out(['ok' => true, 'rows' => AgentCallbacks::all('open'), 'open' => AgentCallbacks::openCount()]);

case 'agent_takeover_all':
    // ۱.۰.۸: همه‌ی گفتگوهای دارای مسئول (در کانال‌های دستیار) به دستیار سپرده شوند
    if (!Auth::isManager()) out(['ok' => false, 'error' => 'فقط مدیر یا سرپرست']);
    if (!ChatAssign::ready()) out(['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید']);
    $rows = Database::all("SELECT p.id, p.assigned_user_id u,
        (SELECT i.channel FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = p.id AND m.direction = 'in' ORDER BY m.id DESC LIMIT 1) ch
        FROM eb_persons p WHERE p.assigned_user_id IS NOT NULL");
    $pick = array_values(array_filter($rows, fn($r) => $r['ch'] !== null && SalesAgent::channelOn((string)$r['ch'])));
    if (!empty($in['dry'])) {
        $by = [];
        foreach ($pick as $r) $by[(int)$r['u']] = ($by[(int)$r['u']] ?? 0) + 1;
        $names = [];
        foreach (Database::all("SELECT id, " . Auth::nameSql('') . " AS n FROM eb_users") as $u) $names[(int)$u['id']] = $u['n'];
        arsort($by);
        out(['ok' => true, 'total' => count($pick), 'by' => array_map(fn($u, $n) => ['name' => $names[$u] ?? ('#' . $u), 'n' => $n], array_keys($by), array_values($by))]);
    }
    @set_time_limit(300);
    $n = 0;
    foreach ($pick as $r) { SalesAgent::takeOver((int)$r['id'], (int)Auth::id(), 'سپردن گروهی به دستیار فروش'); $n++; }
    out(['ok' => true, 'moved' => $n]);

case 'agent_resume':
    if (!Auth::isManager()) out(['ok' => false, 'error' => 'فقط مدیر یا سرپرست']);
    $pid = (int)($in['person_id'] ?? 0);
    if (ChatAssign::ready()) Database::run("UPDATE eb_persons SET assigned_user_id = NULL, assigned_at = NULL WHERE id = ?", [$pid]);
    SalesAgent::resume($pid);
    out(['ok' => true]);

default:
    out(['ok' => false, 'error' => 'API ناشناخته: ' . $api]);
}
