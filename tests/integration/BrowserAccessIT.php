<?php
/** 2.3.0 role editor in a real browser (Playwright/Chromium). Skipped when node+playwright are unavailable. */
it('browser: admin creates a role in the UI, assigns it, effective view shows it, operator menu gains the page', function (Sandbox $sb) {
    exec('node -e "require(\'playwright\')" 2>&1', $o, $rc);
    if ($rc !== 0) { echo "        (skipped: playwright not available)\n"; return; }
    $sb->php("Migrations::run(false, false, 'it'); return 1;");
    $sb->pdo()->exec("DELETE FROM eb_perm_grants; DELETE FROM eb_perm_role_keys; DELETE FROM eb_perm_roles");
    $sb->user('admin_acc', 'admin');
    $sb->user('op_acc', 'operator', ['pages' => ['inbox']]);
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/browser/access_roles.js') . ' ' . escapeshellarg($sb->url) . ' admin_acc pw-admin_acc op_acc pw-op_acc 2>&1';
    $lines = array_values(array_filter(explode("\n", trim((string)shell_exec($cmd)))));
    $r = json_decode((string)end($lines), true);
    ok(is_array($r), 'bad output: ' . implode("\n", $lines));
    ok(empty($r['error']), 'script error: ' . ($r['error'] ?? ''));
    eq([], $r['pageErrors'], 'JS errors');
    eq(true, $r['emptyState'], 'empty state');
    eq(true, $r['adminOnlyDisabled'], 'admin-only key must be disabled');
    eq(true, $r['roleListed']); eq(true, $r['assigned']); eq(true, $r['effectiveShowsRole']);
    ok(in_array('templates', $r['operatorPageAllow'], true), 'operator menu: ' . json_encode($r['operatorPageAllow']));
    ok(!in_array('send', $r['operatorPageAllow'], true), 'nothing else opened');
});
