<?php
require_once EB_ROOT_DIR . '/core/ApiPolicy.php';

$GLOBALS['__pages'] = fn(array $allowed) => fn(string $p) => in_array($p, $allowed, true);

test('authz: admins pass everything, including admin-only and unknown actions', function () {
    $can = ($GLOBALS['__pages'])([]);
    foreach ([['*'], ['send'], [], null] as $p) eq(true, ApiPolicy::decide($p, true, $can)['allow']);
});
test('authz: page-scoped action needs one of its pages', function () {
    eq(true,  ApiPolicy::decide(['send', 'queue'], false, ($GLOBALS['__pages'])(['queue']))['allow']);
    eq(false, ApiPolicy::decide(['send', 'queue'], false, ($GLOBALS['__pages'])(['inbox']))['allow']);
    eq('no_page_access', ApiPolicy::decide(['send'], false, ($GLOBALS['__pages'])(['inbox']))['reason']);
});
test('authz: unused and unknown actions are admin-only (default deny)', function () {
    $can = ($GLOBALS['__pages'])(['inbox', 'send', 'settings']);
    eq('admin_only', ApiPolicy::decide([], false, $can)['reason']);
    eq('unknown_action', ApiPolicy::decide(null, false, $can)['reason']);
});
test('authz: map — dangerous actions are scoped as expected', function () {
    $m = ApiPolicy::map();
    eq([], $m['send_bulk'], 'dead bulk-send endpoint must be admin-only');
    eq(['send.page.view'], $m['send_enqueue']);
    eq(['settings.page.view'], $m['settings_get'], 'cron key must not be readable outside settings');
    eq(['templates.page.view'], $m['template_delete']);
    eq(['instagram.page.view'], $m['instagram_automation_save']);
    ok(!in_array('*', $m['campaign_create'], true));
});
test('authz: map — self-service profile actions stay open to everyone', function () {
    foreach (['my_password_change', 'my_profile', 'desk_user_profile', 'desk_boot', 'notifications_poll'] as $a)
        eq(['*'], ApiPolicy::map()[$a], $a);
});
test('authz: every server action is in the map (no silent default-deny for new code)', function () {
    $acts = [];
    foreach (glob(EB_ROOT_DIR . '/api/*.php') as $f) {
        if (basename($f) === 'export.php') continue;
        preg_match_all("/\n\s*case '([a-z0-9_]+)':/", file_get_contents($f), $m);
        $acts = array_merge($acts, $m[1]);
    }
    eq([], array_values(array_diff(array_unique($acts), array_keys(ApiPolicy::map()))), 'run php tools/gen_api_policy.php');
});
test('authz: committed map matches the generator (UI changed → regenerate)', function () {
    exec('cd ' . escapeshellarg(EB_ROOT_DIR) . ' && php tools/gen_api_policy.php --check 2>&1', $out, $rc);
    eq(0, $rc, implode("\n", $out));
});
test('authz: exports are page-scoped', function () {
    eq(false, ApiPolicy::decideExport('customers', false, ($GLOBALS['__pages'])(['inbox.page.view']))['allow']);
    eq(true,  ApiPolicy::decideExport('messages', false, ($GLOBALS['__pages'])(['inbox.page.view']))['allow']);
    eq(false, ApiPolicy::decideExport('nonexistent', false, ($GLOBALS['__pages'])(['inbox.page.view']))['allow']);
});
test('authz: index.php enforces after CSRF and the viewer gate, before the handler', function () {
    $s = file_get_contents(EB_ROOT_DIR . '/index.php');
    $v = strpos($s, 'Viewer::gateApi('); $a = strpos($s, 'ApiPolicy::enforceApi('); $h = strpos($s, "require __DIR__ . '/api/handler.php'");
    ok($v !== false && $a !== false && $h !== false && $v < $a && $a < $h);
    ok(strpos($s, 'ApiPolicy::enforceExport(') < strpos($s, "require __DIR__ . '/api/export.php'"));
});
test('authz: generated map is semantically identical to 2.2.0 (page → <page>.page.view)', function () {
    foreach (ApiPolicy::map() as $action => $keys) {
        foreach ($keys as $k) ok($k === '*' || preg_match('/^[a-z0-9_]+\.page\.view$/', $k) === 1, "$action has unexpected key $k");
    }
    eq(28, count(array_filter(ApiPolicy::map(), fn($k) => $k === ['*'])), 'any-user actions changed');
});
