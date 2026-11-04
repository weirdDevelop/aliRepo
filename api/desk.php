<?php
/**
 * api/desk.php — اکشن‌های پنل جدید (میز پشتیبانی، ترجیحات، تیم‌ها).
 * از api/handler.php برای ?api=desk_* صدا زده می‌شود؛ $in و out() آنجا تعریف شده‌اند.
 */

require_once __DIR__ . '/../modules/Desk.php';
Desk::ensure();
Desk::touch();

/** فهرست کانال‌های واقعی و وضعیت تنظیم */
function desk_channels(): array
{
    $map = ['instagram' => 'instagram', 'telegram' => 'telegram', 'bale' => 'bale', 'roubika' => 'roubika', 'safir' => 'bale_safir', 'sms' => 'sms', 'email' => 'email', 'webchat' => 'webchat'];
    $out = [];
    foreach ($map as $k => $real) {
        $ok = false;
        try {
            if ($k === 'instagram') $ok = (class_exists('InstagramApichi') && InstagramApichi::isConfigured()) || ChannelManager::isConfigured('instagram');
            else $ok = ChannelManager::isConfigured($real);
        } catch (Throwable $e) {}
        $out[$k] = $ok;
    }
    return $out;
}

function desk_ig_pages(): array
{
    if (!Inbox::hasIgAccounts()) return [];
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['label'], 'user' => ltrim((string)$r['username'], '@'), 'color' => $r['color'],
        'active' => (bool)$r['is_active'], 'provider' => $r['provider'],
    ], Database::all("SELECT id, label, color, username, is_active, provider FROM eb_ig_accounts ORDER BY is_active DESC, id"));
}

switch ($api) {

case 'desk_boot':
    $me = null;
    foreach (Desk::users() as $u) if ($u['id'] === (int)Auth::id()) $me = $u;
    $perms = Auth::permissions();
    $pages = [];
    foreach (['dashboard','inbox','instagram','webchat','send','safir','campaigns','scheduled','queue','sendlog','persons','groups','botbuilder','autoreply','forms','ai','templates','links','notices','support','marketing','stats','analytics','apilog','settings','channels','profile','botsettings','callbacks'] as $pg) {
        if (Auth::canAccess($pg)) $pages[] = $pg;
    }
    // ۲.۰.۰: بخش‌های CRM (کلید crm_*)
    if (class_exists('CrmBridge') && CrmBridge::present() && CrmBridge::ready()) {
        foreach (array_keys(CrmBridge::PERMS) as $k) if (Auth::canAccess('crm_' . $k)) $pages[] = 'crm_' . $k;
        $pages[] = 'crm_me';
    }
    $sup = class_exists('Support') ? Support::status() : ['online' => true];
    out(['ok' => true,
        'me' => ($me ?: ['id' => (int)Auth::id(), 'name' => $_SESSION['eb_name'] ?? 'کاربر', 'username' => '', 'title' => Auth::isViewer() ? 'نمایشی' : 'کاربر', 'role' => Auth::role(), 'color' => '#3D5A80', 'active' => true, 'status' => 'online', 'presence' => 'online', 'last_seen' => null, 'team_id' => null]) + ['is_admin' => Auth::isAdmin(), 'is_viewer' => Auth::isViewer(), 'agent_on' => class_exists('SalesAgent') && SalesAgent::enabled(), 'agent_lock' => class_exists('SalesAgent') && SalesAgent::locksOperators(), 'is_manager' => Auth::isManager(), 'sees_others' => Desk::seesOthers(), 'can_delete' => Auth::canManageCustomers(), 'prefs' => Desk::prefs()],
        'csat' => class_exists('Csat') ? ['enabled' => (bool)Csat::config()['enabled'], 'auto_close' => (bool)Csat::config()['auto_close']] : null,
        'pages' => $pages,
        'sites' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'], 'domain' => $x['domain'], 'color' => $x['color'], 'is_default' => (int)$x['is_default'], 'is_active' => (int)$x['is_active']], SiteCtx::all()),
        'site_current' => SiteCtx::id(),
        'users' => Desk::users(),
        'teams' => Desk::teams(),
        'tags' => Groups::allTags(),
        'groups' => Groups::allGroups(),
        'ig_pages' => desk_ig_pages(),
        'channels' => desk_channels(),
        'counts' => Desk::counts(),
        'sla' => Desk::slaMinutes(),
        'unread' => Inbox::unreadCount(),
        'support' => ['online' => (bool)($sup['online'] ?? true)],
        'ai_ready' => class_exists('SalesAgent') ? SalesAgent::aiOrAgent() : (class_exists('Ai') && Ai::isReady()),
        'brand' => Brand::all(),
        'now' => Database::one("SELECT NOW() n")['n'] ?? null,
    ]);

case 'desk_list':
    out(['ok' => true] + Desk::listConversations([
        'view' => (string)($in['view'] ?? 'all'), 'channel' => (string)($in['channel'] ?? ''),
        'ig_account_id' => (int)($in['ig_account_id'] ?? 0), 'q' => trim((string)($in['q'] ?? '')),
        'sort' => (string)($in['sort'] ?? ''), 'team' => (int)($in['team'] ?? 0), 'label' => (int)($in['label'] ?? 0),
        'unread' => !empty($in['unread']), 'blocked' => !empty($in['blocked']),
    ], max(1, (int)($in['page'] ?? 1))) + ['counts' => Desk::counts(), 'unread_total' => Inbox::unreadCount(), 'now' => Database::one("SELECT NOW() n")['n'] ?? null]);

case 'desk_counts':
    out(['ok' => true, 'counts' => Desk::counts(), 'unread_total' => Inbox::unreadCount()]);

case 'desk_thread':
    $pid = (int)($in['person_id'] ?? 0);
    if (!$pid) out(['ok' => false, 'error' => 'شناسه‌ی مشتری لازم است']);
    inbox_assert_access($pid);
    $beforeId = (int)($in['before_id'] ?? 0);
    $identF = (int)($in['identity_id'] ?? 0);
    $th = Inbox::thread($pid, max(20, min(200, (int)($in['limit'] ?? 60))), !empty($in['include_deleted']), $beforeId, $identF);
    $me = (int)Auth::id();
    // کامنت‌های اینستاگرامِ همین بازه که به‌صورت پیام ثبت نشده‌اند (خط زمانی یکپارچه)
    $activity = [];
    if (!$identF && class_exists('Persons') && method_exists('Persons', 'igComments')) {
        $comments = Persons::igComments($pid, 100);
        if ($comments) {
            $from = (!empty($th['has_more']) && $th['messages']) ? $th['messages'][0]['created_at'] : null;
            $to = $beforeId && $th['messages'] ? end($th['messages'])['created_at'] : null;
            $logged = [];
            foreach ($th['messages'] as $m) if (($m['media_type'] ?? '') === 'ig_comment') $logged[trim(preg_replace('/^💬 کامنت زیر پست: /u', '', (string)$m['body']))] = true;
            foreach ($comments as $c) {
                if ($from && $c['created_at'] < $from) continue;
                if ($to && $c['created_at'] > $to) continue;
                if (isset($logged[trim((string)$c['body'])])) continue;
                $activity[] = ['kind' => 'ig_comment'] + $c;
            }
        }
    }
    // رویدادها و یادداشت‌های همین بازه‌ی زمانی
    $evFrom = (!empty($th['has_more']) && $th['messages']) ? $th['messages'][0]['created_at'] : null;
    $evTo = $beforeId && $th['messages'] ? end($th['messages'])['created_at'] : null;
    $events = array_values(array_filter(Desk::events($pid), fn($e) => (!$evFrom || $e['created_at'] >= $evFrom) && (!$evTo || $e['created_at'] <= $evTo)));
    if ($beforeId) out(['ok' => true, 'messages' => $th['messages'], 'has_more' => $th['has_more'], 'events' => $events, 'activity' => $activity]);

    $prof = Persons::profile($pid);
    if (!$prof) out(['ok' => false, 'error' => 'مشتری یافت نشد']);
    Inbox::markRead($pid);
    ChatAssign::ping($pid, $me);
    $prof['blocked'] = Inbox::isBlocked($pid);
    // ۱.۰.۱۴: فیلترهای فعال (بلاک، توقف دستیار، سقف پیام/جستجو، سقف IP) برای دکمه‌ی «رفع فیلتر»
    if (class_exists('ChatPlus')) { try { $prof['filter'] = ChatPlus::filterState($pid); } catch (Throwable $e) {} }
    $prof['ai_assigned'] = class_exists('Support') ? Support::isAiAssigned($pid) : false;
    $prof['identities'] = inbox_identities($pid, true);
    if (class_exists('Forms') && method_exists('Forms', 'responsesForPerson')) {
        try { $prof['form_responses'] = Forms::responsesForPerson($pid, 5); } catch (Throwable $e) {}
    }
    try {
        $prof['orders'] = Database::all("SELECT id, status, total, notes, created_at FROM eb_ig_orders WHERE person_id = ? ORDER BY created_at DESC LIMIT 10", [$pid]);
    } catch (Throwable $e) { $prof['orders'] = []; }
    $last = Inbox::lastInboundIdentity($pid);
    out(['ok' => true, 'person' => $prof, 'messages' => $th['messages'], 'has_more' => $th['has_more'], 'activity' => $activity, 'events' => $events,
         'state' => Desk::state($pid) + ['effective' => Desk::effectiveStatus($pid)],
         'assign' => ChatAssign::state($pid, $me),
         'reply_channel' => $last['channel'] ?? null, 'reply_identity_id' => isset($last['id']) ? (int)$last['id'] : null,
         'ai_ready' => class_exists('SalesAgent') ? SalesAgent::aiOrAgent() : (class_exists('Ai') && Ai::isReady()), 'ai_mode' => class_exists('Ai') ? Ai::modeFor($pid) : null,
         'unread_total' => Inbox::unreadCount(), 'now' => Database::one("SELECT NOW() n")['n'] ?? null]);

case 'desk_ping':
    // حضور در گفتگوی باز + پیام تازه + وضعیت تخصیص (هر ۱۵ ثانیه)
    $pid = (int)($in['person_id'] ?? 0);
    if (!$pid) out(['ok' => false]);
    inbox_assert_access($pid);
    $me = (int)Auth::id();
    ChatAssign::ping($pid, $me, !empty($in['typing']));
    $lastId = (int)($in['last_id'] ?? 0);
    $newer = $lastId ? (int)(Database::one("SELECT COUNT(*) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.id > ?", [$pid, $lastId])['c'] ?? 0) : 0;
    out(['ok' => true, 'assign' => ChatAssign::state($pid, $me), 'new_messages' => $newer]);

case 'desk_set':
    $ids = array_values(array_filter(array_map('intval', (array)($in['person_ids'] ?? [($in['person_id'] ?? 0)]))));
    if (!$ids) out(['ok' => false, 'error' => 'گفتگویی انتخاب نشده']);
    foreach ($ids as $pid) inbox_assert_access($pid);
    $set = is_array($in['set'] ?? null) ? $in['set'] : [];
    if (isset($set['assignee_id']) && $set['assignee_id']) {
        $u = Database::one("SELECT id FROM eb_users WHERE id = ? AND is_active = 1 AND role <> 'viewer'", [(int)$set['assignee_id']]);
        if (!$u) out(['ok' => false, 'error' => 'اپراتور پیدا نشد یا غیرفعال است']);
    }
    // دستیار فروش روشن: فقط مدیر و سرپرست مسئول گفتگو را تعیین می‌کنند
    if (array_key_exists('assignee_id', $set) && !ChatAssign::unrestricted() && class_exists('SalesAgent') && array_filter($ids, fn($x) => SalesAgent::locksFor((int)$x))) out(['ok' => false, 'error' => SalesAgent::LOCK_MSG]);
    // پشتیبان عادی فقط چتِ بی‌مسئول یا مال خودش را جابه‌جا می‌کند (مدیر و سرپرست آزادند)
    if (array_key_exists('assignee_id', $set) && !ChatAssign::unrestricted() && Inbox::hasAssign()) {
        foreach ($ids as $pid) {
            $cur = (int)(Database::one("SELECT assigned_user_id a FROM eb_persons WHERE id = ?", [$pid])['a'] ?? 0);
            if ($cur && $cur !== (int)Auth::id()) out(['ok' => false, 'error' => 'این چت بر عهده‌ی ' . (ChatAssign::userNames()[$cur] ?? 'همکار دیگری') . ' است؛ اول آن را بر عهده بگیرید']);
        }
    }
    $n = Desk::update($ids, $set);
    // نظرسنجی رضایت بعد از بستن یک گفتگو (اگر روشن است و اپراتور در ۳ روز اخیر به آن پاسخ داده)
    $csat = null;
    if (count($ids) === 1 && ($set['status'] ?? '') === 'closed' && empty($set['no_csat']) && class_exists('Csat') && Csat::ready()) {
        $cc = Csat::config();
        if (!empty($cc['enabled']) && !empty($cc['auto_close'])) {
            $human = Database::one("SELECT 1 FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'out' AND m.sent_by IS NOT NULL AND m.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) LIMIT 1", [$ids[0]]);
            if ($human) $csat = Csat::send($ids[0], (int)Auth::id(), true);
        }
    }
    out(['ok' => true, 'updated' => $n, 'state' => count($ids) === 1 ? Desk::state($ids[0]) : null, 'csat' => $csat]);

case 'desk_note':
    $pid = (int)($in['person_id'] ?? 0);
    inbox_assert_access($pid);
    out(Desk::note($pid, (string)($in['body'] ?? '')));

case 'desk_presence':
    Desk::setPresence((string)($in['presence'] ?? 'online'));
    out(['ok' => true]);

case 'desk_prefs':
    Desk::savePrefs(is_array($in['prefs'] ?? null) ? $in['prefs'] : []);
    out(['ok' => true]);

case 'desk_team_save':
    adminOnly();
    try { out(['ok' => true, 'id' => Desk::saveTeam($in), 'teams' => Desk::teams()]); }
    catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

case 'desk_team_delete':
    adminOnly();
    Database::run("DELETE FROM eb_teams WHERE id = ?", [(int)($in['id'] ?? 0)]);
    Database::run("UPDATE eb_conv_state SET team_id = NULL WHERE team_id = ?", [(int)($in['id'] ?? 0)]);
    out(['ok' => true, 'teams' => Desk::teams()]);

case 'desk_settings_save':
    adminOnly();
    if (isset($in['sla']) && is_array($in['sla'])) {
        $sla = [];
        foreach (Desk::PRIORITIES as $p) $sla[$p] = max(1, min(10080, (int)($in['sla'][$p] ?? 30)));
        Desk::saveSetting('sla', json_encode($sla));
    }
    if (isset($in['default_prefs']) && is_array($in['default_prefs'])) Desk::saveSetting('default_prefs', json_encode($in['default_prefs'], JSON_UNESCAPED_UNICODE));
    out(['ok' => true]);

case 'desk_avatar_upload':
    // ۱.۰.۱۳: تصویر پروفایل همکار (خودش یا مدیر برای دیگران)
    $uid = (int)($_POST['id'] ?? Auth::id());
    if ($uid !== (int)Auth::id()) adminOnly();
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) out(['ok' => false, 'error' => 'فایلی آپلود نشد']);
    if (($_FILES['image']['size'] ?? 0) > 1024 * 1024) out(['ok' => false, 'error' => 'حجم تصویر باید کمتر از ۱ مگابایت باشد']);
    $info = @getimagesize($_FILES['image']['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) out(['ok' => false, 'error' => 'فقط PNG، JPG یا WebP']);
    $dir = rtrim($config['paths']['uploads'] ?? (__DIR__ . '/../uploads/'), '/') . '/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'avatar_' . $uid . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . $name)) out(['ok' => false, 'error' => 'ذخیره‌ی فایل ناموفق بود']);
    Desk::ensureAvatar();
    Database::run("UPDATE eb_users SET avatar = ? WHERE id = ?", ['uploads/' . $name, $uid]);
    if (class_exists('Cache')) Cache::forget('users_names');
    out(['ok' => true, 'avatar' => 'uploads/' . $name]);

case 'desk_user_profile':
    // نام نمایشی، عنوان و رنگ کاربر جاری (یا هر کاربر توسط مدیر)
    $uid = (int)($in['id'] ?? Auth::id());
    if ($uid !== (int)Auth::id()) adminOnly();
    if (!empty($in['remove_avatar'])) { Desk::ensureAvatar(); Database::run("UPDATE eb_users SET avatar = '' WHERE id = ?", [$uid]); }
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : null;
    $dn = mb_substr(trim((string)($in['display_name'] ?? '')), 0, 100) ?: null;
    Database::run("UPDATE eb_users SET display_name = ?, title = ?, color = COALESCE(?, color) WHERE id = ?",
        [$dn, mb_substr(trim((string)($in['title'] ?? '')), 0, 100) ?: null, $color, $uid]);
    if ($uid === (int)Auth::id() && session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['eb_name'] = $dn ?: (Database::one("SELECT username FROM eb_users WHERE id = ?", [$uid])['username'] ?? '');
        session_write_close();
    }
    if (class_exists('Cache')) Cache::forget('users_names');
    out(['ok' => true]);

case 'desk_csat_send':
    $pid = (int)($in['person_id'] ?? 0);
    inbox_assert_access($pid);
    out(Csat::send($pid, (int)Auth::id(), false));

case 'desk_inbox_cfg':
    out(['ok' => true, 'visibility' => Desk::setting('op_visibility', 'own'), 'prio_rules' => Desk::prioRules(), 'csat' => Csat::config(),
         'followups' => Followups::rules(), 'followup_stats' => Followups::stats(), 'sla' => Desk::slaMinutes(), 'is_admin' => Auth::isAdmin()]);

case 'desk_inbox_cfg_save':
    adminOnly();
    $res = ['ok' => true];
    if (isset($in['visibility'])) { Desk::saveSetting('op_visibility', $in['visibility'] === 'all' ? 'all' : 'own'); Desk::bumpCounts(); }
    if (isset($in['prio_rules']) && is_array($in['prio_rules'])) $res['prio_rules'] = Desk::savePrioRules($in['prio_rules']);
    if (isset($in['csat']) && is_array($in['csat'])) $res['csat'] = Csat::saveConfig($in['csat']);
    if (isset($in['followups']) && is_array($in['followups'])) $res['followups'] = Followups::saveRules($in['followups']);
    out($res);

case 'desk_prio_test':
    // آزمایش قانون‌ها روی یک متن نمونه
    $hit = Desk::matchPrio((string)($in['text'] ?? ''), (string)($in['channel'] ?? 'instagram'), isset($in['rules']) && is_array($in['rules']) ? array_map(fn($r) => $r + ['active' => true], Desk::savePrioRulesPreview($in['rules'])) : null);
    out(['ok' => true, 'hit' => $hit]);

case 'desk_followup_preview':
    adminOnly();
    $rules = Followups::rules();
    $rule = null;
    foreach ($rules as $r) if ($r['id'] === ($in['id'] ?? '')) $rule = $r;
    if (!$rule) out(['ok' => false, 'error' => 'اول قانون را ذخیره کنید']);
    $c = Followups::candidates($rule + ['active' => true], 20);
    out(['ok' => true, 'count' => count($c), 'items' => array_map(fn($x) => ['person_id' => (int)$x['person_id'], 'name' => $x['full_name'], 'last_in_at' => $x['last_in_at'], 'channel' => $x['last_channel']], $c)]);

case 'desk_agent_report':
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? $in['from'] : date('Y-m-d', strtotime('-6 days'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? $in['to'] : date('Y-m-d');
    try { out(['ok' => true, 'mine_only' => !Auth::isManager()] + AgentReport::build($from, $to, Auth::isManager() ? null : (int)Auth::id())); }
    catch (InvalidArgumentException $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

case 'desk_overview':
    // نمای کلی صندوق ورودی (وقتی گفتگویی باز نیست)
    $c = Desk::counts();
    $wait = Desk::listConversations(['view' => 'all', 'sort' => 'sla'], 1, 10)['conversations'];
    $wait = array_values(array_filter($wait, fn($x) => $x['waiting_min'] !== null));
    $agents = Auth::isManager() || Desk::seesOthers() ? ChatAssign::agents(0) : [];
    $today = Database::one("SELECT COUNT(*) r, COUNT(DISTINCT i.person_id) c FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id WHERE m.direction = 'out' AND m.sent_by = ? AND m.created_at >= CURDATE()", [(int)Auth::id()]) ?: [];
    $inToday = (int)(Database::one("SELECT COUNT(*) c FROM eb_conv_index WHERE last_in_at >= CURDATE()")['c'] ?? 0);
    out(['ok' => true, 'counts' => $c, 'waiting' => array_slice($wait, 0, 6), 'agents' => $agents, 'me_today' => ['replies' => (int)($today['r'] ?? 0), 'chats' => (int)($today['c'] ?? 0)], 'convs_today' => $inToday,
         'csat_today' => class_exists('Csat') ? (Csat::summary(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'))) : [], 'now' => Database::one("SELECT NOW() n")['n'] ?? null]);

case 'desk_impersonate':
    // مدیر ← ورود به‌جای اپراتور/سرپرست (از ویرایش پروفایل اپراتور)
    $r = Auth::impersonate((int)($in['id'] ?? 0));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    out($r);

case 'desk_impersonate_stop':
    $r = Auth::stopImpersonating();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    out($r);

case 'desk_audit':
    adminOnly();
    $uid = (int)($in['user_id'] ?? 0);
    try {
        $rows = Database::all("SELECT a.*, " . Auth::nameSql('u') . " AS by_name FROM eb_audit a LEFT JOIN eb_users u ON u.id = a.user_id"
            . ($uid ? " WHERE a.target_user_id = ? OR a.user_id = ?" : "") . " ORDER BY a.id DESC LIMIT 50", $uid ? [$uid, $uid] : []);
    } catch (Throwable $e) { $rows = []; }
    out(['ok' => true, 'rows' => $rows]);

default:
    out(['ok' => false, 'error' => 'اکشن ناشناخته: ' . $api]);
}
