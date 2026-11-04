<?php
/** P-03 in a real browser (Playwright/Chromium). Skipped when node+playwright are unavailable. */
it('browser: operator editor refuses "selected sections" with nothing ticked; saves once a section is ticked', function (Sandbox $sb) {
    exec('node -e "require(\'playwright\')" 2>&1', $o, $rc);
    if ($rc !== 0) { echo "        (skipped: playwright not available)\n"; return; }
    $sb->user('admin_ui', 'admin');
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/browser/operator_scope.js') . ' ' . escapeshellarg($sb->url) . ' admin_ui pw-admin_ui 2>&1';
    $json = (string)shell_exec($cmd);
    $lines = array_values(array_filter(explode("\n", trim($json))));
    $r = json_decode((string)end($lines), true);
    ok(is_array($r), 'bad output: ' . $json);
    eq(0, $r['checkedAfterUntick'] ?? null);
    eq(0, $r['createRequests'] ?? null, 'operator_create must not be sent with an empty restricted scope');
    ok(empty($r['error']), 'script error: ' . ($r['error'] ?? ''));
    eq([], $r['pageErrors'], 'JS errors on page');
    ok(strpos((string)$r['toast'], 'حداقل یک بخش') !== false, 'toast: ' . $r['toast']);
    eq(1, $r['createRequestsAfterTick'], 'positive control: should save once a section is ticked');
    $p = $sb->pdo()->query("SELECT permissions_json FROM eb_users WHERE username = 'trap_user'")->fetchColumn();
    $pages = json_decode((string)$p, true)['pages'] ?? [];
    ok(count($pages) >= 1, 'saved user must have an explicit page list, got ' . $p);
});
