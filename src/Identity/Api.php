<?php
namespace Eb\Identity;

/**
 * Identity & Access — public facade (2.3.0, ADR-006).
 * Roles are named sets of permission keys; grants give a role to a user at a scope.
 * Only scope 'tenant' is accepted until Organization (2.4.0) and Work (2.5.0) define unit/project scopes.
 * Every change is one transaction with an outbox event (→ audit log, see bootstrap.php).
 */
final class Api
{
    public static function roleKeysFor(int $uid): array
    {
        return array_column(\Database::all(\Permissions::ROLE_KEYS_SQL, [$uid]), 'perm_key');
    }

    /** Effective keys of any user + where each came from (legacy settings or which roles). */
    public static function effectiveFor(int $uid): array
    {
        $u = \Database::one("SELECT id, username, role, permissions_json, is_active FROM eb_users WHERE id = ?", [$uid]);
        if (!$u) throw new \RestError(404, 'not_found', 'کاربر پیدا نشد');
        $orgPages = null;
        if (!in_array($u['role'], ['admin', 'viewer'], true) && class_exists('OrgRoles')) {
            try { $rid = \OrgRoles::userRoleId($uid); if ($rid && \OrgRoles::get($rid)) $orgPages = \OrgRoles::effectivePages($rid); } catch (\Throwable $e) {}
        }
        $perms = \Permissions::decodeLegacy($u['permissions_json']);
        $legacy = \Permissions::effective($u['role'], $perms, $orgPages, []);
        $roles = \Database::all("SELECT r.id, r.name, k.perm_key FROM eb_perm_grants g JOIN eb_perm_roles r ON r.id = g.role_id
                                 JOIN eb_perm_role_keys k ON k.role_id = r.id
                                 WHERE g.user_id = ? AND g.scope_type = 'tenant' AND (g.expires_at IS NULL OR g.expires_at > UTC_TIMESTAMP())", [$uid]);
        $sources = [];
        foreach ($legacy as $k) $sources[$k][] = 'legacy';
        foreach ($roles as $r) $sources[$r['perm_key']][] = 'role:' . $r['name'];
        $keys = \Permissions::effective($u['role'], $perms, $orgPages, array_column($roles, 'perm_key'));
        $pages = [];
        foreach (Catalog::all() as $e) {
            if (!preg_match('/^([a-z0-9_]+)\.page\.view$/', $e['key'], $m)) continue;
            $pages[$m[1]] = \Permissions::allowsPage($keys, $m[1]);
        }
        $scoped = [];
        if (self::orgReady()) {
            $rows = \Database::all("SELECT g.role_id, r.name role_name, g.scope_type, g.scope_id FROM eb_perm_grants g JOIN eb_perm_roles r ON r.id = g.role_id
                                     WHERE g.user_id = ? AND g.scope_type IN ('org_unit', 'org_unit_tree') ORDER BY g.scope_id", [$uid]);
            $unitNames = \Eb\Organization\Api::unitNames(array_column($rows, 'scope_id'));
            foreach ($rows as $g) {
                $scoped[] = ['role_id' => (int)$g['role_id'], 'role' => $g['role_name'], 'scope_type' => $g['scope_type'], 'unit_id' => (int)$g['scope_id'], 'unit' => $unitNames[(int)$g['scope_id']] ?? null];
            }
        }
        return ['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role'], 'org_role' => $orgPages !== null],
                'keys' => $keys, 'sources' => $sources, 'pages' => $pages, 'scoped' => $scoped,
                'can_delete_customers' => \Permissions::allows($keys, 'customer.person.delete')];
    }

    public static function listRoles(): array
    {
        $roles = \Database::all("SELECT r.id, r.pid, r.name, r.description, r.updated_at,
                                        (SELECT COUNT(*) FROM eb_perm_grants g WHERE g.role_id = r.id) members
                                 FROM eb_perm_roles r ORDER BY r.name");
        $keys = [];
        foreach (\Database::all("SELECT role_id, perm_key FROM eb_perm_role_keys ORDER BY perm_key") as $k) $keys[(int)$k['role_id']][] = $k['perm_key'];
        foreach ($roles as &$r) { $r['id'] = (int)$r['id']; $r['members'] = (int)$r['members']; $r['keys'] = $keys[$r['id']] ?? []; }
        return $roles;
    }

    public static function getRole(int $id): array
    {
        foreach (self::listRoles() as $r) if ($r['id'] === $id) return $r;
        throw new \RestError(404, 'not_found', 'نقش پیدا نشد');
    }

    public static function createRole(array $in, int $actor): array
    {
        [$name, $desc, $keys] = self::validateRole($in, true);
        return self::tx(function () use ($name, $desc, $keys, $actor) {
            if (\Database::one("SELECT id FROM eb_perm_roles WHERE name = ?", [$name])) throw new \RestError(409, 'conflict', 'نقشی با این نام وجود دارد', 'name');
            \Database::run("INSERT INTO eb_perm_roles (pid, name, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [\Ulid::generate(), $name, $desc, $actor]);
            $id = \Database::lastId();
            foreach ($keys as $k) \Database::run("INSERT INTO eb_perm_role_keys (role_id, perm_key) VALUES (?, ?)", [$id, $k]);
            \Outbox::record('identity.role.created', ['role_id' => $id, 'name' => $name, 'keys' => $keys, 'by_user_id' => $actor], 'role:' . $id);
            return self::getRole($id);
        });
    }

    public static function updateRole(int $id, array $in, int $actor): array
    {
        self::getRole($id);
        [$name, $desc, $keys] = self::validateRole($in, false);
        return self::tx(function () use ($id, $name, $desc, $keys, $actor) {
            if ($name !== null) {
                if (\Database::one("SELECT id FROM eb_perm_roles WHERE name = ? AND id <> ?", [$name, $id])) throw new \RestError(409, 'conflict', 'نقشی با این نام وجود دارد', 'name');
                \Database::run("UPDATE eb_perm_roles SET name = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$name, $id]);
            }
            if ($desc !== null) \Database::run("UPDATE eb_perm_roles SET description = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$desc, $id]);
            if ($keys !== null) {
                \Database::run("DELETE FROM eb_perm_role_keys WHERE role_id = ?", [$id]);
                foreach ($keys as $k) \Database::run("INSERT INTO eb_perm_role_keys (role_id, perm_key) VALUES (?, ?)", [$id, $k]);
                \Database::run("UPDATE eb_perm_roles SET updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            }
            \Outbox::record('identity.role.updated', ['role_id' => $id, 'name' => $name, 'keys' => $keys, 'by_user_id' => $actor], 'role:' . $id);
            return self::getRole($id);
        });
    }

    public static function deleteRole(int $id, int $actor): void
    {
        $r = self::getRole($id);
        if ($r['members'] > 0) throw new \RestError(409, 'conflict', 'این نقش به ' . $r['members'] . ' کاربر داده شده؛ اول از آن‌ها بگیرید', null, ['members' => $r['members']]);
        self::tx(function () use ($id, $r, $actor) {
            \Database::run("DELETE FROM eb_perm_role_keys WHERE role_id = ?", [$id]);
            \Database::run("DELETE FROM eb_perm_roles WHERE id = ?", [$id]);
            \Outbox::record('identity.role.deleted', ['role_id' => $id, 'name' => $r['name'], 'by_user_id' => $actor], 'role:' . $id);
            return null;
        });
    }

    /** Replace a user's tenant-scope role grants. */
    public static function setUserRoles(int $uid, array $roleIds, int $actor): array
    {
        if ($uid === $actor) throw new \RestError(422, 'rule_violation', 'هیچ‌کس نمی‌تواند به خودش نقش بدهد یا از خودش بگیرد (تفکیک وظایف)');
        if (!\Database::one("SELECT id FROM eb_users WHERE id = ?", [$uid])) throw new \RestError(404, 'not_found', 'کاربر پیدا نشد');
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        foreach ($roleIds as $rid) self::getRole($rid);
        self::tx(function () use ($uid, $roleIds, $actor) {
            $before = array_map('intval', array_column(\Database::all("SELECT role_id FROM eb_perm_grants WHERE user_id = ? AND scope_type = 'tenant'", [$uid]), 'role_id'));
            \Database::run("DELETE FROM eb_perm_grants WHERE user_id = ? AND scope_type = 'tenant'", [$uid]);
            foreach ($roleIds as $rid) {
                \Database::run("INSERT INTO eb_perm_grants (user_id, role_id, scope_type, scope_id, granted_by, granted_at) VALUES (?, ?, 'tenant', 0, ?, UTC_TIMESTAMP())", [$uid, $rid, $actor]);
            }
            sort($before);
            $after = $roleIds; sort($after);
            if ($before !== $after) {
                \Outbox::record('identity.grants.changed', ['user_id' => $uid, 'before' => $before, 'after' => $after, 'by_user_id' => $actor], 'user:' . $uid);
            }
            return null;
        });
        return self::effectiveFor($uid);
    }

    public static function users(): array
    {
        $rows = \Database::all("SELECT id, username, role, is_active FROM eb_users ORDER BY username");
        $g = []; $sc = [];
        foreach (\Database::all("SELECT user_id, role_id, scope_type, scope_id FROM eb_perm_grants") as $r) {
            if ($r['scope_type'] === 'tenant') $g[(int)$r['user_id']][] = (int)$r['role_id'];
            else $sc[(int)$r['user_id']][] = ['role_id' => (int)$r['role_id'], 'scope_type' => $r['scope_type'], 'unit_id' => (int)$r['scope_id']];
        }
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_active'] = (int)$r['is_active']; $r['role_ids'] = $g[$r['id']] ?? []; $r['scoped'] = $sc[$r['id']] ?? []; }
        return $rows;
    }

    private static function orgReady(): bool
    {
        return class_exists('Migrations') && \Migrations::isApplied('0004_organization');
    }

    /**
     * 2.4.0: replace a user's unit-scoped grants. Each: {role_id, scope_type: org_unit|org_unit_tree, unit_id}.
     * Page permissions inside such roles have no effect (pages are tenant-wide); data permissions apply at that unit.
     */
    public static function setUserScopedGrants(int $uid, array $grants, int $actor): array
    {
        if ($uid === $actor) throw new \RestError(422, 'rule_violation', 'هیچ‌کس نمی‌تواند به خودش نقش بدهد یا از خودش بگیرد (تفکیک وظایف)');
        if (!self::orgReady()) throw new \RestError(503, 'unavailable', 'چارت سازمانی هنوز ساخته نشده است (به‌روزرسانی دیتابیس)');
        if (!\Database::one("SELECT id FROM eb_users WHERE id = ?", [$uid])) throw new \RestError(404, 'not_found', 'کاربر پیدا نشد');
        $clean = [];
        foreach ($grants as $i => $g) {
            if (!is_array($g)) throw new \RestError(400, 'validation_error', 'grants نامعتبر است', 'grants');
            $type = (string)($g['scope_type'] ?? '');
            if (!in_array($type, ['org_unit', 'org_unit_tree'], true)) throw new \RestError(400, 'validation_error', 'scope_type باید org_unit یا org_unit_tree باشد', "grants[$i].scope_type");
            $unit = (int)($g['unit_id'] ?? 0);
            if (!\Eb\Organization\Api::isActiveUnit($unit)) throw new \RestError(400, 'validation_error', 'واحد پیدا نشد یا بایگانی است', "grants[$i].unit_id");
            self::getRole((int)($g['role_id'] ?? 0));
            $clean[(int)$g['role_id'] . ':' . $type . ':' . $unit] = [(int)$g['role_id'], $type, $unit];
        }
        self::tx(function () use ($uid, $clean, $actor) {
            $before = \Database::all("SELECT role_id, scope_type, scope_id FROM eb_perm_grants WHERE user_id = ? AND scope_type <> 'tenant' ORDER BY role_id, scope_type, scope_id", [$uid]);
            \Database::run("DELETE FROM eb_perm_grants WHERE user_id = ? AND scope_type <> 'tenant'", [$uid]);
            foreach ($clean as [$rid, $type, $unit]) {
                \Database::run("INSERT INTO eb_perm_grants (user_id, role_id, scope_type, scope_id, granted_by, granted_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())", [$uid, $rid, $type, $unit, $actor]);
            }
            $after = array_map(fn($c) => ['role_id' => $c[0], 'scope_type' => $c[1], 'scope_id' => $c[2]], array_values($clean));
            \Outbox::record('identity.grants.changed', ['user_id' => $uid, 'scoped' => true, 'before' => count($before), 'after' => count($after), 'by_user_id' => $actor], 'user:' . $uid);
            return null;
        });
        return self::effectiveFor($uid);
    }

    /** @return array{0:?string,1:?string,2:?array} */
    private static function validateRole(array $in, bool $create): array
    {
        $name = array_key_exists('name', $in) ? trim((string)$in['name']) : null;
        if ($create || $name !== null) {
            if ($name === null || mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new \RestError(400, 'validation_error', 'نام نقش ۲ تا ۱۰۰ نویسه باشد', 'name');
        }
        $desc = array_key_exists('description', $in) ? mb_substr(trim((string)$in['description']), 0, 500) : null;
        $keys = null;
        if ($create || array_key_exists('keys', $in)) {
            if (!is_array($in['keys'] ?? null)) throw new \RestError(400, 'validation_error', 'keys باید فهرست باشد', 'keys');
            $keys = array_values(array_unique(array_map('strval', $in['keys'])));
            foreach ($keys as $k) {
                if (!isset(Catalog::byKey()[$k])) throw new \RestError(400, 'validation_error', 'مجوز ناشناخته: ' . $k, 'keys', ['key' => $k]);
                if (!Catalog::grantable($k)) throw new \RestError(422, 'rule_violation', 'این مجوز فقط برای مدیر است و در نقش قرار نمی‌گیرد: ' . $k, 'keys', ['key' => $k]);
            }
            sort($keys);
        }
        return [$name, $desc, $keys];
    }

    private static function tx(callable $fn)
    {
        $pdo = \Database::pdo();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
