<?php
namespace Eb\Organization;

/**
 * Organization context — public facade (2.4.0, docs/architecture/domain-boundaries.md §2).
 *
 * Units form a tree (division/department/team/unit). People belong to units through effective-dated
 * memberships (role member|manager|deputy, inclusive [valid_from, valid_to]); rows are never deleted,
 * so history is complete. The manager of a unit is its active 'manager' membership (max one at a time);
 * an acting manager is a dated delegation. Moves that would create a cycle are refused.
 * Permissions: org.unit.manage / org.member.manage, checked with Auth::canIn at the affected unit(s)
 * (so a role granted on a department's subtree lets someone manage only that subtree).
 * Every change: one transaction + outbox event (→ audit) + unit change history.
 */
final class Api
{
    /** @var callable(string, ?int): bool */
    public static $authorize = null;

    public static function today(): string
    {
        try { return (new \DateTime('now', \Tz::zone()))->format('Y-m-d'); } catch (\Throwable $e) { return date('Y-m-d'); }
    }

    private static function authorize(string $key, ?int $unitId): void
    {
        $fn = self::$authorize ?? [\Auth::class, 'canIn'];
        if (!$fn($key, $unitId)) throw new \RestError(403, 'forbidden', 'دسترسی لازم برای این کار در این واحد را ندارید', null, ['permission' => $key, 'unit_id' => $unitId]);
    }

    /** For other contexts (ADR-002: no direct table reads): is this an active unit? */
    public static function isActiveUnit(int $id): bool
    {
        return (bool)\Database::one("SELECT id FROM eb_org_units WHERE id = ? AND status = 'active'", [$id]);
    }

    /** For other contexts: unit names by id. @return array<int,string> */
    public static function unitNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $out = [];
        foreach (\Database::all("SELECT id, name FROM eb_org_units WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids) as $r) $out[(int)$r['id']] = $r['name'];
        return $out;
    }

    /** @return array<int,?int> */
    public static function parentMap(): array
    {
        $m = [];
        foreach (\Database::all("SELECT id, parent_id FROM eb_org_units") as $r) $m[(int)$r['id']] = $r['parent_id'] === null ? null : (int)$r['parent_id'];
        return $m;
    }

    private static function unitRow(int $id): array
    {
        $u = \Database::one("SELECT * FROM eb_org_units WHERE id = ?", [$id]);
        if (!$u) throw new \RestError(404, 'not_found', 'واحد پیدا نشد');
        return $u;
    }

    private static function userNames(): array
    {
        $m = [];
        foreach (\Database::all("SELECT id, username, display_name, is_active FROM eb_users") as $u) {
            $m[(int)$u['id']] = ['id' => (int)$u['id'], 'name' => trim((string)$u['display_name']) !== '' ? $u['display_name'] : $u['username'], 'active' => (int)$u['is_active']];
        }
        return $m;
    }

    /** Manager and acting manager of a unit on a date. */
    public static function managerOf(int $unitId, ?string $date = null): array
    {
        $date = $date ?? self::today();
        $m = \Database::one("SELECT user_id FROM eb_org_memberships WHERE unit_id = ? AND role = 'manager' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?) ORDER BY valid_from DESC LIMIT 1", [$unitId, $date, $date]);
        $d = \Database::one("SELECT to_user_id FROM eb_org_delegations WHERE unit_id = ? AND revoked_at IS NULL AND valid_from <= ? AND valid_to >= ? ORDER BY id DESC LIMIT 1", [$unitId, $date, $date]);
        return ['manager' => $m ? (int)$m['user_id'] : null, 'acting' => $d ? (int)$d['to_user_id'] : null];
    }

    /** Whole chart as of a date: units with manager, acting manager and active members. */
    public static function tree(?string $date = null, bool $includeArchived = false): array
    {
        $date = $date ?? self::today();
        if (!Rules::validDate($date)) throw new \RestError(400, 'validation_error', 'تاریخ نامعتبر است (YYYY-MM-DD)', 'date');
        $names = self::userNames();
        $units = \Database::all("SELECT id, pid, name, type, parent_id, status, sort_order FROM eb_org_units " . ($includeArchived ? '' : "WHERE status = 'active' ") . "ORDER BY sort_order, name");
        $mem = [];
        foreach (\Database::all("SELECT id, unit_id, user_id, role, valid_from, valid_to, is_primary FROM eb_org_memberships WHERE valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)", [$date, $date]) as $r) {
            $mem[(int)$r['unit_id']][] = ['membership_id' => (int)$r['id'], 'user' => $names[(int)$r['user_id']] ?? ['id' => (int)$r['user_id'], 'name' => '#' . $r['user_id'], 'active' => 0],
                                          'role' => $r['role'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'is_primary' => (int)$r['is_primary']];
        }
        $acting = [];
        foreach (\Database::all("SELECT unit_id, to_user_id, valid_to FROM eb_org_delegations WHERE revoked_at IS NULL AND valid_from <= ? AND valid_to >= ?", [$date, $date]) as $d) {
            $acting[(int)$d['unit_id']] = ['user' => $names[(int)$d['to_user_id']] ?? null, 'until' => $d['valid_to']];
        }
        foreach ($units as &$u) {
            $u['id'] = (int)$u['id']; $u['parent_id'] = $u['parent_id'] === null ? null : (int)$u['parent_id'];
            $u['members'] = $mem[$u['id']] ?? [];
            $mgr = array_values(array_filter($u['members'], fn($m) => $m['role'] === 'manager'));
            $u['manager'] = $mgr ? $mgr[0]['user'] : null;
            $u['acting_manager'] = $acting[$u['id']] ?? null;
        }
        return ['date' => $date, 'units' => $units];
    }

    public static function unit(int $id): array
    {
        $u = self::unitRow($id);
        $names = self::userNames();
        $u['id'] = (int)$u['id']; $u['parent_id'] = $u['parent_id'] === null ? null : (int)$u['parent_id'];
        $u['memberships'] = array_map(function ($r) use ($names) {
            return ['id' => (int)$r['id'], 'user' => $names[(int)$r['user_id']] ?? ['id' => (int)$r['user_id'], 'name' => '#' . $r['user_id']], 'role' => $r['role'],
                    'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'is_primary' => (int)$r['is_primary'], 'source' => $r['source']];
        }, \Database::all("SELECT * FROM eb_org_memberships WHERE unit_id = ? ORDER BY (valid_to IS NULL) DESC, valid_from DESC, id DESC", [$id]));
        $u['delegations'] = array_map(fn($d) => ['id' => (int)$d['id'], 'from' => $names[(int)$d['from_user_id']] ?? null, 'to' => $names[(int)$d['to_user_id']] ?? null,
                                                  'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'], 'reason' => $d['reason'], 'revoked' => $d['revoked_at'] !== null],
                                    \Database::all("SELECT * FROM eb_org_delegations WHERE unit_id = ? ORDER BY id DESC", [$id]));
        $u['changes'] = \Database::all("SELECT change_type, old_value, new_value, by_user_id, at FROM eb_org_changes WHERE unit_id = ? ORDER BY id DESC LIMIT 100", [$id]);
        $u['manager_now'] = self::managerOf($id);
        $u['path'] = array_reverse(Rules::ancestors(self::parentMap(), $id));
        return $u;
    }

    /** Reporting line of a user on a date: their primary unit's manager, then managers up the tree. */
    public static function reportingChain(int $userId, ?string $date = null): array
    {
        $date = $date ?? self::today();
        $m = \Database::one("SELECT m.unit_id FROM eb_org_memberships m JOIN eb_org_units u ON u.id = m.unit_id AND u.status = 'active'
                             WHERE m.user_id = ? AND m.valid_from <= ? AND (m.valid_to IS NULL OR m.valid_to >= ?)
                             ORDER BY m.is_primary DESC, m.valid_from ASC, m.id ASC LIMIT 1", [$userId, $date, $date]);
        if (!$m) return [];
        $parents = self::parentMap(); $names = self::userNames();
        $chain = [];
        foreach (array_merge([(int)$m['unit_id']], Rules::ancestors($parents, (int)$m['unit_id'])) as $unitId) {
            $mg = self::managerOf($unitId, $date);
            $who = $mg['acting'] ?? $mg['manager'];
            if ($who === null || $who === $userId) continue;              // a manager reports to the next level up
            if ($chain && end($chain)['user']['id'] === $who) continue;
            $chain[] = ['unit_id' => $unitId, 'user' => $names[$who] ?? ['id' => $who], 'acting' => $mg['acting'] !== null];
        }
        return $chain;
    }

    public static function createUnit(array $in, int $actor): array
    {
        // authorise first (at the parent), so callers without the permission learn nothing from validation
        $parent = isset($in['parent_id']) && $in['parent_id'] !== null && $in['parent_id'] !== '' ? (int)$in['parent_id'] : null;
        self::authorize('org.unit.manage', $parent);
        if ($parent !== null) {
            $p = self::unitRow($parent);
            if ($p['status'] !== 'active') throw new \RestError(422, 'rule_violation', 'واحد والد بایگانی شده است', 'parent_id');
        }
        $name = trim((string)($in['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new \RestError(400, 'validation_error', 'نام واحد ۲ تا ۱۲۰ نویسه باشد', 'name');
        $type = (string)($in['type'] ?? 'department');
        if (!in_array($type, Rules::TYPES, true)) throw new \RestError(400, 'validation_error', 'نوع واحد نامعتبر است', 'type');
        return self::tx(function () use ($name, $type, $parent, $actor, $in) {
            \Database::run("INSERT INTO eb_org_units (pid, name, type, parent_id, status, sort_order, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [\Ulid::generate(), $name, $type, $parent, (int)($in['sort_order'] ?? 0), $actor]);
            $id = \Database::lastId();
            self::change($id, 'created', null, $name, $actor);
            \Outbox::record('org.unit.created', ['unit_id' => $id, 'name' => $name, 'parent_id' => $parent, 'by_user_id' => $actor], 'org.unit:' . $id);
            return self::unit($id);
        });
    }

    public static function updateUnit(int $id, array $in, int $actor): array
    {
        $u = self::unitRow($id);
        if ($u['status'] !== 'active') throw new \RestError(422, 'rule_violation', 'واحد بایگانی‌شده ویرایش نمی‌شود؛ اول بازگردانید');
        $oldParent = $u['parent_id'] === null ? null : (int)$u['parent_id'];
        self::authorize('org.unit.manage', $id);
        $moving = array_key_exists('parent_id', $in);
        $newParent = $moving ? (($in['parent_id'] === null || $in['parent_id'] === '') ? null : (int)$in['parent_id']) : $oldParent;
        if ($moving && $newParent !== $oldParent) {
            if ($newParent !== null) {
                $p = self::unitRow($newParent);
                if ($p['status'] !== 'active') throw new \RestError(422, 'rule_violation', 'واحد مقصد بایگانی شده است', 'parent_id');
            }
            if (Rules::wouldCycle(self::parentMap(), $id, $newParent)) {
                throw new \RestError(422, 'rule_violation', 'این جابه‌جایی چرخه می‌سازد: یک واحد نمی‌تواند زیرمجموعه‌ی خودش یا زیرمجموعه‌هایش شود', 'parent_id');
            }
            self::authorize('org.unit.manage', $newParent);   // must also be allowed at the destination
        }
        $name = array_key_exists('name', $in) ? trim((string)$in['name']) : $u['name'];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new \RestError(400, 'validation_error', 'نام واحد ۲ تا ۱۲۰ نویسه باشد', 'name');
        $type = array_key_exists('type', $in) ? (string)$in['type'] : $u['type'];
        if (!in_array($type, Rules::TYPES, true)) throw new \RestError(400, 'validation_error', 'نوع واحد نامعتبر است', 'type');
        return self::tx(function () use ($id, $u, $name, $type, $newParent, $oldParent, $actor, $in) {
            \Database::run("UPDATE eb_org_units SET name = ?, type = ?, parent_id = ?, sort_order = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                [$name, $type, $newParent, (int)($in['sort_order'] ?? $u['sort_order']), $id]);
            if ($name !== $u['name']) self::change($id, 'renamed', $u['name'], $name, $actor);
            if ($type !== $u['type']) self::change($id, 'type', $u['type'], $type, $actor);
            if ($newParent !== $oldParent) {
                self::change($id, 'moved', (string)$oldParent, (string)$newParent, $actor);
                \Outbox::record('org.unit.moved', ['unit_id' => $id, 'from' => $oldParent, 'to' => $newParent, 'by_user_id' => $actor], 'org.unit:' . $id);
            } else {
                \Outbox::record('org.unit.updated', ['unit_id' => $id, 'name' => $name, 'by_user_id' => $actor], 'org.unit:' . $id);
            }
            return self::unit($id);
        });
    }

    public static function archiveUnit(int $id, int $actor): array
    {
        $u = self::unitRow($id);
        if ($u['status'] !== 'active') throw new \RestError(409, 'conflict', 'واحد قبلاً بایگانی شده است');
        self::authorize('org.unit.manage', $id);
        $kids = (int)\Database::one("SELECT COUNT(*) c FROM eb_org_units WHERE parent_id = ? AND status = 'active'", [$id])['c'];
        $today = self::today();
        $members = (int)\Database::one("SELECT COUNT(*) c FROM eb_org_memberships WHERE unit_id = ? AND (valid_to IS NULL OR valid_to >= ?)", [$id, $today])['c'];
        if ($kids || $members) throw new \RestError(409, 'conflict', 'اول زیرواحدها را جابه‌جا/بایگانی و عضویت‌ها را پایان دهید', null, ['active_children' => $kids, 'active_members' => $members]);
        return self::tx(function () use ($id, $actor) {
            \Database::run("UPDATE eb_org_units SET status = 'archived', archived_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            self::change($id, 'archived', null, null, $actor);
            \Outbox::record('org.unit.archived', ['unit_id' => $id, 'by_user_id' => $actor], 'org.unit:' . $id);
            return self::unit($id);
        });
    }

    public static function restoreUnit(int $id, int $actor): array
    {
        $u = self::unitRow($id);
        if ($u['status'] === 'active') throw new \RestError(409, 'conflict', 'واحد فعال است');
        self::authorize('org.unit.manage', $id);
        if ($u['parent_id'] !== null && self::unitRow((int)$u['parent_id'])['status'] !== 'active') throw new \RestError(422, 'rule_violation', 'اول واحد والد را بازگردانید');
        return self::tx(function () use ($id, $actor) {
            \Database::run("UPDATE eb_org_units SET status = 'active', archived_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
            self::change($id, 'restored', null, null, $actor);
            \Outbox::record('org.unit.restored', ['unit_id' => $id, 'by_user_id' => $actor], 'org.unit:' . $id);
            return self::unit($id);
        });
    }

    public static function addMember(int $unitId, array $in, int $actor): array
    {
        $u = self::unitRow($unitId);
        if ($u['status'] !== 'active') throw new \RestError(422, 'rule_violation', 'واحد بایگانی شده است');
        self::authorize('org.member.manage', $unitId);
        $userId = (int)($in['user_id'] ?? 0);
        if (!\Database::one("SELECT id FROM eb_users WHERE id = ?", [$userId])) throw new \RestError(400, 'validation_error', 'کاربر پیدا نشد', 'user_id');
        $role = (string)($in['role'] ?? 'member');
        if (!in_array($role, Rules::MEMBER_ROLES, true)) throw new \RestError(400, 'validation_error', 'نقش در واحد نامعتبر است', 'role');
        $from = (string)($in['valid_from'] ?? self::today());
        $to = isset($in['valid_to']) && $in['valid_to'] !== '' && $in['valid_to'] !== null ? (string)$in['valid_to'] : null;
        if (!Rules::validDate($from)) throw new \RestError(400, 'validation_error', 'تاریخ شروع نامعتبر است', 'valid_from');
        if ($to !== null && (!Rules::validDate($to) || $to < $from)) throw new \RestError(400, 'validation_error', 'تاریخ پایان نامعتبر است', 'valid_to');
        foreach (\Database::all("SELECT valid_from, valid_to, role FROM eb_org_memberships WHERE unit_id = ? AND user_id = ?", [$unitId, $userId]) as $r) {
            if (Rules::overlaps($from, $to, $r['valid_from'], $r['valid_to'])) throw new \RestError(409, 'conflict', 'این شخص در این بازه قبلاً عضو این واحد است', 'valid_from');
        }
        if ($role === 'manager') {
            foreach (\Database::all("SELECT user_id, valid_from, valid_to FROM eb_org_memberships WHERE unit_id = ? AND role = 'manager'", [$unitId]) as $r) {
                if (Rules::overlaps($from, $to, $r['valid_from'], $r['valid_to'])) throw new \RestError(409, 'conflict', 'این واحد در این بازه مدیر دارد؛ اول دوره‌ی مدیر فعلی را پایان دهید', 'role', ['manager_user_id' => (int)$r['user_id']]);
            }
        }
        $primary = !empty($in['is_primary']) ? 1 : 0;
        return self::tx(function () use ($unitId, $userId, $role, $from, $to, $primary, $actor, $in) {
            if ($primary) \Database::run("UPDATE eb_org_memberships SET is_primary = 0 WHERE user_id = ? AND (valid_to IS NULL OR valid_to >= ?)", [$userId, $from]);
            \Database::run("INSERT INTO eb_org_memberships (unit_id, user_id, role, valid_from, valid_to, is_primary, source, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())",
                [$unitId, $userId, $role, $from, $to, $primary, $in['_source'] ?? null, $actor]);
            $mid = \Database::lastId();
            \Outbox::record('org.member.added', ['unit_id' => $unitId, 'user_id' => $userId, 'role' => $role, 'membership_id' => $mid, 'from' => $from, 'to' => $to, 'by_user_id' => $actor], 'org.unit:' . $unitId);
            return ['id' => $mid, 'unit_id' => $unitId, 'user_id' => $userId, 'role' => $role, 'valid_from' => $from, 'valid_to' => $to, 'is_primary' => $primary];
        });
    }

    /** End a membership on a date (inclusive last day). Rows are never deleted. */
    public static function endMember(int $membershipId, ?string $lastDay, int $actor): array
    {
        $m = \Database::one("SELECT * FROM eb_org_memberships WHERE id = ?", [$membershipId]);
        if (!$m) throw new \RestError(404, 'not_found', 'عضویت پیدا نشد');
        self::authorize('org.member.manage', (int)$m['unit_id']);
        $lastDay = $lastDay ?? self::today();
        if (!Rules::validDate($lastDay)) throw new \RestError(400, 'validation_error', 'تاریخ پایان نامعتبر است', 'valid_to');
        if ($lastDay < $m['valid_from']) throw new \RestError(422, 'rule_violation', 'تاریخ پایان قبل از شروع عضویت است', 'valid_to');
        if ($m['valid_to'] !== null && $m['valid_to'] <= $lastDay) throw new \RestError(409, 'conflict', 'این عضویت تا این تاریخ قبلاً پایان یافته است');
        return self::tx(function () use ($m, $lastDay, $actor) {
            \Database::run("UPDATE eb_org_memberships SET valid_to = ?, ended_by = ?, ended_at = UTC_TIMESTAMP() WHERE id = ?", [$lastDay, $actor, $m['id']]);
            \Outbox::record('org.member.ended', ['unit_id' => (int)$m['unit_id'], 'user_id' => (int)$m['user_id'], 'membership_id' => (int)$m['id'], 'role' => $m['role'], 'last_day' => $lastDay, 'by_user_id' => $actor], 'org.unit:' . $m['unit_id']);
            return ['id' => (int)$m['id'], 'valid_to' => $lastDay];
        });
    }

    /** Acting manager for a period: the unit's manager on valid_from delegates to another user. */
    public static function delegate(int $unitId, array $in, int $actor): array
    {
        self::unitRow($unitId);
        self::authorize('org.unit.manage', $unitId);
        $from = (string)($in['valid_from'] ?? self::today()); $to = (string)($in['valid_to'] ?? '');
        if (!Rules::validDate($from) || !Rules::validDate($to) || $to < $from) throw new \RestError(400, 'validation_error', 'بازه‌ی جانشینی نامعتبر است (شروع و پایان لازم است)', 'valid_to');
        $mgr = self::managerOf($unitId, $from)['manager'];
        if ($mgr === null) throw new \RestError(422, 'rule_violation', 'این واحد در تاریخ شروع مدیر ندارد؛ جانشین برای مدیر تعیین می‌شود');
        $toUser = (int)($in['to_user_id'] ?? 0);
        if (!\Database::one("SELECT id FROM eb_users WHERE id = ? AND is_active = 1", [$toUser])) throw new \RestError(400, 'validation_error', 'کاربر جانشین پیدا نشد یا غیرفعال است', 'to_user_id');
        if ($toUser === $mgr) throw new \RestError(422, 'rule_violation', 'مدیر نمی‌تواند جانشین خودش باشد', 'to_user_id');
        foreach (\Database::all("SELECT valid_from, valid_to FROM eb_org_delegations WHERE unit_id = ? AND revoked_at IS NULL", [$unitId]) as $d) {
            if (Rules::overlaps($from, $to, $d['valid_from'], $d['valid_to'])) throw new \RestError(409, 'conflict', 'در این بازه جانشین دیگری تعیین شده است');
        }
        return self::tx(function () use ($unitId, $mgr, $toUser, $from, $to, $in, $actor) {
            \Database::run("INSERT INTO eb_org_delegations (unit_id, from_user_id, to_user_id, valid_from, valid_to, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())",
                [$unitId, $mgr, $toUser, $from, $to, mb_substr(trim((string)($in['reason'] ?? '')), 0, 255) ?: null, $actor]);
            $id = \Database::lastId();
            \Outbox::record('org.delegation.created', ['unit_id' => $unitId, 'user_id' => $toUser, 'from_user_id' => $mgr, 'from' => $from, 'to' => $to, 'by_user_id' => $actor], 'org.unit:' . $unitId);
            return ['id' => $id, 'unit_id' => $unitId, 'from_user_id' => $mgr, 'to_user_id' => $toUser, 'valid_from' => $from, 'valid_to' => $to];
        });
    }

    public static function revokeDelegation(int $id, int $actor): array
    {
        $d = \Database::one("SELECT * FROM eb_org_delegations WHERE id = ?", [$id]);
        if (!$d) throw new \RestError(404, 'not_found', 'جانشینی پیدا نشد');
        if ($d['revoked_at'] !== null) throw new \RestError(409, 'conflict', 'قبلاً لغو شده است');
        self::authorize('org.unit.manage', (int)$d['unit_id']);
        return self::tx(function () use ($d, $actor) {
            \Database::run("UPDATE eb_org_delegations SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE id = ?", [$actor, $d['id']]);
            \Outbox::record('org.delegation.revoked', ['unit_id' => (int)$d['unit_id'], 'user_id' => (int)$d['to_user_id'], 'delegation_id' => (int)$d['id'], 'by_user_id' => $actor], 'org.unit:' . $d['unit_id']);
            return ['id' => (int)$d['id'], 'revoked' => true];
        });
    }

    private static function change(int $unitId, string $type, ?string $old, ?string $new, int $actor): void
    {
        \Database::run("INSERT INTO eb_org_changes (unit_id, change_type, old_value, new_value, by_user_id, at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())",
            [$unitId, $type, $old === null ? null : mb_substr($old, 0, 255), $new === null ? null : mb_substr($new, 0, 255), $actor]);
    }

    /** @internal shared with Import */
    public static function tx(callable $fn)
    {
        $pdo = \Database::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
