<?php
/**
 * api/v4_flows.php — سناریوساز (flow_). از api/v4.php require می‌شود.
 */
require_once __DIR__ . '/../modules/Flows.php';
v4_can('botbuilder', 'autoreply');

switch ($api) {
    case 'flow_list':
        out(['ok' => true, 'flows' => Flows::all()]);
    case 'flow_get':
        $f = Flows::get((int)($in['id'] ?? 0));
        if (!$f) out(['ok' => false, 'error' => 'سناریو پیدا نشد']);
        $base = rtrim(app_base_url($config), '/');
        out(['ok' => true, 'flow' => $f, 'hook_url' => $base . '/flow-hook.php?id=' . (int)$f['id']]);
    case 'flow_meta':
        // داده‌های لازم برای فرم گره‌ها: کانال‌ها، گروه‌ها، برچسب‌ها، اپراتورها، تیم‌ها، فرم‌ها، پیج‌ها، سناریوها
        $forms = [];
        try { $forms = Database::all("SELECT id, name FROM eb_forms ORDER BY id DESC LIMIT 100"); } catch (Throwable $e) {}
        $pages = [];
        try { $pages = Database::all("SELECT id, COALESCE(NULLIF(label,''), username) name FROM eb_ig_accounts WHERE is_active = 1"); } catch (Throwable $e) {}
        $users = Database::all("SELECT id, " . Auth::nameSql('') . " name FROM eb_users WHERE is_active = 1 AND role <> 'viewer' ORDER BY id");
        $teams = [];
        try { $teams = Database::all("SELECT id, name FROM eb_teams ORDER BY name"); } catch (Throwable $e) {}
        out(['ok' => true, 'groups' => Groups::allGroups(), 'tags' => Groups::allTags(), 'users' => $users, 'teams' => $teams, 'forms' => $forms, 'ig_pages' => $pages,
             'flows' => array_map(fn($f) => ['id' => $f['id'], 'name' => $f['name']], Flows::all()), 'ai_ready' => class_exists('Ai') && Ai::isReady()]);
    case 'flow_save':
        $r = Flows::save([
            'id' => (int)($in['id'] ?? 0), 'name' => (string)($in['name'] ?? ''), 'description' => (string)($in['description'] ?? ''),
            'graph' => $in['graph'] ?? [], 'is_active' => !empty($in['is_active']),
        ]);
        out($r);
    case 'flow_toggle':
        out(Flows::setActive((int)($in['id'] ?? 0), !empty($in['on'])));
    case 'flow_delete':
        Flows::delete((int)($in['id'] ?? 0));
        out(['ok' => true]);
    case 'flow_duplicate':
        out(Flows::duplicate((int)($in['id'] ?? 0)));
    case 'flow_runs':
        out(['ok' => true] + Flows::runs((int)($in['id'] ?? 0), (int)($in['limit'] ?? 50)));
    case 'flow_run':
        $r = Flows::run((int)($in['id'] ?? 0));
        out($r ? ['ok' => true, 'run' => $r] : ['ok' => false, 'error' => 'اجرا پیدا نشد']);
    case 'flow_run_cancel':
        Flows::cancelRun((int)($in['id'] ?? 0));
        out(['ok' => true]);
    case 'flow_test':
        // اجرای آزمایشی برای یک مخاطب (حتی اگر سناریو خاموش باشد)
        $pids = array_map('intval', (array)($in['person_ids'] ?? []));
        if (!empty($in['group_id']) && class_exists('Sender')) $pids = array_merge($pids, Sender::resolveAudience(['group_id' => (int)$in['group_id']]));
        out(Flows::runManual((int)($in['id'] ?? 0), $pids, (array)($in['vars'] ?? [])));
    case 'flow_new_key':
        out(['ok' => true, 'key' => bin2hex(random_bytes(16))]);
}
