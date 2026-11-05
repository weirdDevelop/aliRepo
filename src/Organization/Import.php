<?php
namespace Eb\Organization;

/**
 * Import of existing structures into org units (2.4.0, docs/architecture/data-ownership.md §3).
 *   roles  — org-chart roles (eb_roles tree, users via eb_users.role_id)
 *   teams  — inbox routing teams (eb_teams, members "1,2,3")
 *   crm    — CRM staff units (staff.unit free text; staff → CRM users → eb_users via users.eb_user_id)
 * preview() only reads. apply() creates units/memberships for the chosen sources, tagged with
 * source/source_id so a second run skips what exists. Nothing is merged or deleted; the old
 * structures keep working unchanged (permissions and inbox routing still use them).
 */
final class Import
{
    const SOURCES = ['roles', 'teams', 'crm'];

    private static function hasTable(string $t): bool
    {
        return (bool)\Database::one("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]);
    }
    private static function hasColumn(string $t, string $c): bool
    {
        return (bool)\Database::one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$t, $c]);
    }

    /** @return array{units: array, issues: array, unmatched_staff: array, possible_duplicates: array, summary: array} */
    public static function preview(): array
    {
        $users = [];
        foreach (\Database::all("SELECT id, username, display_name FROM eb_users") as $u) $users[(int)$u['id']] = trim((string)$u['display_name']) !== '' ? $u['display_name'] : $u['username'];
        $existing = [];
        foreach (\Database::all("SELECT source, source_id, id FROM eb_org_units WHERE source IS NOT NULL") as $r) $existing[$r['source'] . ':' . $r['source_id']] = (int)$r['id'];
        $plan = []; $issues = []; $unmatched = [];

        // A) org-chart roles
        if (self::hasTable('eb_roles')) {
            $nodes = \Database::all("SELECT id, name, parent_id, is_manager FROM eb_roles ORDER BY sort_order, id");
            $parentOf = [];
            foreach ($nodes as $n) $parentOf[(int)$n['id']] = $n['parent_id'] === null ? null : (int)$n['parent_id'];
            $byRole = [];
            if (self::hasColumn('eb_users', 'role_id')) foreach (\Database::all("SELECT id, role_id FROM eb_users WHERE role_id IS NOT NULL AND is_active = 1") as $u) $byRole[(int)$u['role_id']][] = (int)$u['id'];
            foreach ($nodes as $n) {
                $id = (int)$n['id']; $p = $parentOf[$id];
                $broken = $p !== null && (!array_key_exists($p, $parentOf) || in_array($id, Rules::ancestors($parentOf, $p), true) || $p === $id);
                if ($broken) $issues[] = ['source' => 'roles', 'source_id' => (string)$id, 'message' => 'والد این نقش وجود ندارد یا چرخه دارد؛ به‌صورت واحد ریشه وارد می‌شود'];
                if ((int)$n['is_manager']) $issues[] = ['source' => 'roles', 'source_id' => (string)$id, 'message' => '«' . $n['name'] . '» پرچم «مثل سرپرست» دارد؛ مدیر واحد خودکار تعیین نمی‌شود — بعد از ورود، مدیر را دستی انتخاب کنید'];
                $plan[] = ['source' => 'roles', 'source_id' => (string)$id, 'name' => $n['name'], 'type' => 'unit',
                           'parent' => (!$broken && $p !== null) ? 'roles:' . $p : null, 'depth' => count(Rules::ancestors($parentOf, $id)),
                           'members' => array_map(fn($u) => ['user_id' => $u, 'name' => $users[$u] ?? ('#' . $u)], $byRole[$id] ?? [])];
            }
        }
        // B) inbox teams
        if (self::hasTable('eb_teams')) {
            foreach (\Database::all("SELECT id, name, members FROM eb_teams ORDER BY id") as $t) {
                $mem = [];
                foreach (array_filter(explode(',', (string)$t['members']), 'strlen') as $m) {
                    if (isset($users[(int)$m])) $mem[] = ['user_id' => (int)$m, 'name' => $users[(int)$m]];
                    else $issues[] = ['source' => 'teams', 'source_id' => (string)$t['id'], 'message' => 'عضو #' . (int)$m . ' تیم «' . $t['name'] . '» کاربر موجودی نیست و وارد نمی‌شود'];
                }
                $plan[] = ['source' => 'teams', 'source_id' => (string)$t['id'], 'name' => $t['name'], 'type' => 'team', 'parent' => null, 'depth' => 0, 'members' => $mem];
            }
        }
        // C) CRM staff units
        if (self::hasTable('staff') && self::hasTable('users') && self::hasColumn('users', 'eb_user_id')) {
            $units = [];
            foreach (\Database::all("SELECT s.id, s.code, s.name, s.unit, s.status, u.eb_user_id FROM staff s LEFT JOIN users u ON u.id = s.user_id ORDER BY s.id") as $s) {
                $unit = trim((string)$s['unit']);
                if ($unit === '') { $unmatched[] = ['staff_code' => $s['code'], 'name' => $s['name'], 'reason' => 'واحد ندارد']; continue; }
                $key = substr(sha1(Rules::normName($unit)), 0, 40);
                $units[$key] = $units[$key] ?? ['source' => 'crm', 'source_id' => $key, 'name' => preg_replace('/\s+/u', ' ', $unit), 'type' => 'department', 'parent' => null, 'depth' => 0, 'members' => []];
                $eb = (int)($s['eb_user_id'] ?? 0);
                if ($eb && isset($users[$eb])) $units[$key]['members'][] = ['user_id' => $eb, 'name' => $users[$eb]];
                else $unmatched[] = ['staff_code' => $s['code'], 'name' => $s['name'], 'unit' => $unit, 'reason' => 'حساب کاربری اعلان‌بان ندارد'];
            }
            foreach ($units as $u) $plan[] = $u;
        }
        // possible duplicates across sources (never merged automatically)
        $byName = [];
        foreach ($plan as $p) $byName[Rules::normName($p['name'])][] = $p['source'] . ':' . $p['source_id'];
        $dups = [];
        foreach ($byName as $n => $refs) if (count($refs) > 1) $dups[] = ['name' => $n, 'items' => $refs];
        foreach ($plan as &$p) {
            $p['already_imported'] = isset($existing[$p['source'] . ':' . $p['source_id']]);
            $p['members'] = array_values(array_unique($p['members'], SORT_REGULAR));
        }
        unset($p);
        $summary = [];
        foreach (self::SOURCES as $s) {
            $items = array_filter($plan, fn($p) => $p['source'] === $s);
            $summary[$s] = ['units' => count($items), 'new_units' => count(array_filter($items, fn($p) => !$p['already_imported'])),
                            'members' => array_sum(array_map(fn($p) => count($p['members']), $items))];
        }
        return ['units' => $plan, 'issues' => $issues, 'unmatched_staff' => $unmatched, 'possible_duplicates' => $dups, 'summary' => $summary];
    }

    public static function apply(array $sources, int $actor): array
    {
        $sources = array_values(array_intersect(self::SOURCES, $sources));
        if (!$sources) throw new \RestError(400, 'validation_error', 'حداقل یک منبع انتخاب کنید', 'sources');
        $fn = Api::$authorize ?? [\Auth::class, 'canIn'];
        if (!$fn('org.unit.manage', null)) throw new \RestError(403, 'forbidden', 'ورود ساختار فقط با دسترسی مدیریت همه‌ی واحدها ممکن است');
        $plan = self::preview();
        $today = Api::today();
        return Api::tx(function () use ($plan, $sources, $actor, $today) {
            $created = 0; $members = 0; $skipped = 0;
            $items = array_values(array_filter($plan['units'], fn($p) => in_array($p['source'], $sources, true)));
            usort($items, fn($a, $b) => $a['depth'] <=> $b['depth']);       // parents before children
            $idOf = [];
            foreach (\Database::all("SELECT source, source_id, id FROM eb_org_units WHERE source IS NOT NULL") as $r) $idOf[$r['source'] . ':' . $r['source_id']] = (int)$r['id'];
            foreach ($items as $p) {
                $ref = $p['source'] . ':' . $p['source_id'];
                if (isset($idOf[$ref])) { $skipped++; $unitId = $idOf[$ref]; }
                else {
                    $parentId = $p['parent'] !== null ? ($idOf[$p['parent']] ?? null) : null;
                    \Database::run("INSERT INTO eb_org_units (pid, name, type, parent_id, status, source, source_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                        [\Ulid::generate(), mb_substr($p['name'], 0, 120), $p['type'], $parentId, $p['source'], $p['source_id'], $actor]);
                    $unitId = $idOf[$ref] = \Database::lastId();
                    \Database::run("INSERT INTO eb_org_changes (unit_id, change_type, old_value, new_value, by_user_id, at) VALUES (?, 'imported', NULL, ?, ?, UTC_TIMESTAMP())", [$unitId, $ref, $actor]);
                    $created++;
                }
                foreach ($p['members'] as $m) {
                    $has = \Database::one("SELECT id FROM eb_org_memberships WHERE unit_id = ? AND user_id = ? AND (valid_to IS NULL OR valid_to >= ?)", [$unitId, $m['user_id'], $today]);
                    if ($has) continue;
                    \Database::run("INSERT INTO eb_org_memberships (unit_id, user_id, role, valid_from, valid_to, is_primary, source, created_by, created_at) VALUES (?, ?, 'member', ?, NULL, 0, ?, ?, UTC_TIMESTAMP())",
                        [$unitId, $m['user_id'], $today, 'import:' . $p['source'], $actor]);
                    $members++;
                }
            }
            \Outbox::record('org.import.applied', ['sources' => $sources, 'units_created' => $created, 'units_skipped' => $skipped, 'memberships_created' => $members, 'by_user_id' => $actor], 'org.import');
            return ['sources' => $sources, 'units_created' => $created, 'units_skipped' => $skipped, 'memberships_created' => $members];
        });
    }
}
