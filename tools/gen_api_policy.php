<?php
/**
 * tools/gen_api_policy.php — regenerate core/ApiPolicy.map.php (dev tool; not deployed).
 * Usage (repo root):  php tools/gen_api_policy.php          # writes the map
 *                     php tools/gen_api_policy.php --check  # exit 1 if the committed map is stale
 *
 * Rule: an API action may be called by a user who may open a page whose UI code references
 * that action (the same Auth::canAccess() rule used to render pages). Derived from code:
 *   1. every action = a `case 'name':` label in api/*.php
 *   2. every UI file → the page key(s) it belongs to (see attribute())
 *   3. any quoted string literal in a UI file equal to an action name counts as a reference
 *      (catches api('x'), must('x'), L().api(c ? 'a' : 'b'), personAct('x'), …).
 *      Over-matching can only widen access, never break a UI flow.
 * Result per action: ['*'] any logged-in user · [permission keys, 2.3.0: '<page>.page.view'] · [] administrators only.
 */
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/core/Permissions.php';

// Pages every logged-in user may open (mirror of Auth::canAccess $always)
const ALWAYS = ['dashboard', 'logout', 'profile', 'crm_me'];
// Files shared by every page → '*'
const GLOBAL_FILES = ['assets/core.js', 'assets/live.js', 'assets/icons.js', 'assets/jdate.js', 'assets/hscroll.js', 'assets/tour.js',
    'views/app.php', 'views/layout.php', 'views/_auth.php', 'views/login.php', 'views/license.php',
    // CRM bridge bundle: profile side-panels everywhere; its admin actions are re-checked server-side (api/crm.php isRealAdmin)
    'assets/p-crm.js'];
// The settings bundle hosts the admin-only "settings" page and the self-service "me" tab.
// Self-service actions verified to act only on Auth::id() (or require admin for another user).
const SETTINGS_SELF_SERVICE = ['my_password_change', 'my_username_change', 'my_profile', 'desk_user_profile', 'desk_avatar_upload', 'desk_boot', 'desk_prefs'];
// Included sub-views → the pages that embed them. 2.4.1: detected from `require __DIR__ . '/../x.php'`
// in views/pages/* (the hand-written list missed templates and autoreply → their carousel editor got 403).
function embedded(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = ['views/carousel-editor.php' => ['instagram', 'botbuilder']];   // instagram embeds it via its own loader
    foreach (glob('views/pages/*.php') as $f) {
        if (!preg_match('#^views/pages/([a-z_]+)(\.js)?\.php$#', $f, $pm)) continue;
        preg_match_all("#(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*'/\.\./([a-z_-]+\.php)'#", file_get_contents($f), $m);
        foreach ($m[1] as $sub) {
            $k = 'views/' . $sub;
            $map[$k] = array_values(array_unique(array_merge($map[$k] ?? [], [$pm[1]])));
            sort($map[$k]);
        }
    }
    return $map;
}

function actions(): array {
    $a = [];
    foreach (glob('api/*.php') as $f) {
        if ($f === 'api/export.php') continue;   // CSV exports use ?export=, gated by ApiPolicy::EXPORTS
        preg_match_all("/\n\s*case '([a-z0-9_]+)':/", file_get_contents($f), $m);
        foreach ($m[1] as $n) $a[$n] = true;
    }
    ksort($a);
    return array_keys($a);
}

/** UI file → list of page keys ('*' = global) */
function attribute(string $f): array {
    if (in_array($f, GLOBAL_FILES, true)) return ['*'];
    $emb = embedded(); if (isset($emb[$f])) return $emb[$f];
    if (preg_match('#^views/pages/([a-z_]+)(\.js)?\.php$#', $f, $m)) return [$m[1]];
    if (preg_match('#^assets/p-[a-z]+\.js$#', $f)) {
        $s = file_get_contents($f);
        preg_match_all("/\breg\('([a-z_-]+)',\s*\{([^\n]*)/", $s, $m, PREG_SET_ORDER);
        $pages = [];
        foreach ($m as [, $slug, $def]) {
            $pages[] = preg_match("/\bperm:'([a-z_]+)'/", $def, $p) ? $p[1] : $slug;
        }
        return array_values(array_unique($pages));
    }
    return [];
}

$acts = actions();
$set = array_fill_keys($acts, []);
$ui = array_merge(glob('assets/*.js'), glob('views/*.php'), glob('views/pages/*.php'));
sort($ui);
$unattributed = [];
foreach ($ui as $f) {
    $pages = attribute($f);
    $s = file_get_contents($f);
    preg_match_all("/['\"]([a-z0-9_]+)['\"]/", $s, $m);
    $lits = array_unique($m[1]);
    // 2.4.1: names built at runtime — api('igpost_' + act) with igpAct('retry') elsewhere in the same file.
    // For each literal prefix followed by `+`, count prefix+literal for every literal in the file.
    preg_match_all("/['\"]([a-z0-9]+_)['\"]\s*\+/", $s, $pm);
    foreach (array_unique($pm[1]) as $pre) foreach ($lits as $l) $lits[] = $pre . $l;
    $refs = array_intersect(array_unique($lits), $acts);
    if (!$refs) continue;
    if (!$pages) { $unattributed[] = $f; continue; }
    foreach ($refs as $a) {
        if ($f === 'assets/p-settings.js') {
            $set[$a][] = in_array($a, SETTINGS_SELF_SERVICE, true) ? '*' : Permissions::pageKey('settings');
            continue;
        }
        foreach ($pages as $p) $set[$a][] = ($p === '*' || in_array($p, ALWAYS, true)) ? '*' : Permissions::pageKey($p);
    }
}
if ($unattributed) { fwrite(STDERR, "UI files referencing actions but not attributed to a page: " . implode(', ', $unattributed) . "\n"); exit(2); }

$out = "<?php\n// GENERATED by tools/gen_api_policy.php — do not edit by hand; edit the generator and re-run.\n"
     . "// action => ['*'] any logged-in user | [permission keys] user needs one of them (Auth::can) | [] admins only\nreturn [\n";
foreach ($set as $a => $pages) {
    $pages = array_values(array_unique($pages));
    if (in_array('*', $pages, true)) $pages = ['*'];
    sort($pages);
    $out .= "    " . var_export($a, true) . " => [" . implode(', ', array_map(fn($p) => var_export($p, true), $pages)) . "],\n";
}
$out .= "];\n";

$target = 'core/ApiPolicy.map.php';
if (in_array('--check', $argv, true)) {
    $same = is_file($target) && file_get_contents($target) === $out;
    echo $same ? "api policy map is up to date\n" : "api policy map is STALE — run: php tools/gen_api_policy.php\n";
    exit($same ? 0 : 1);
}
file_put_contents($target, $out);
$c = ['*' => 0, 'pages' => 0, 'admin' => 0];
foreach ($set as $p) { $p = array_unique($p); in_array('*', $p, true) ? $c['*']++ : ($p ? $c['pages']++ : $c['admin']++); }
echo "wrote $target: " . count($set) . " actions — any-user {$c['*']}, page-scoped {$c['pages']}, admin-only {$c['admin']}\n";
