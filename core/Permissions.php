<?php
/**
 * core/Permissions.php — permission keys, effective permission sets, checks (2.3.0, ADR-006).
 *
 * Key format: <context>.<resource>.<action>, e.g. `inbox.page.view`, `customer.person.delete`.
 * Wildcards in GRANTED keys: `*` (everything) and per-segment `*` (e.g. `*.page.view`).
 *
 * Phase A (this release): a user's effective keys =
 *   (1) the LEGACY projection — computed from the fields the existing operator editor and
 *       org-chart editor write (eb_users.role, permissions_json, org-role pages). It reproduces the
 *       pre-2.3.0 Auth::canAccess/canManageCustomers decisions exactly (proved by an exhaustive
 *       differential test against a frozen copy of the old code), so nobody gains or loses access; plus
 *   (2) keys from roles granted in the new role editor (eb_perm_grants → eb_perm_role_keys).
 * Phase B (later): legacy fields are materialised as roles and the old editor retires.
 *
 * Rules that hold regardless of roles (parity with pre-2.3.0):
 *   - admin and viewer hold `*` (viewer writes are blocked elsewhere: Viewer, Database::$readOnly);
 *   - ALWAYS pages are open to every logged-in user;
 *   - ADMIN_ONLY pages are closed to every non-admin, whatever roles say;
 *   - keys marked admin_only in the catalogue cannot be put into roles.
 * Pure: no DB, no session. Callers supply the inputs.
 */
final class Permissions
{
    const ALWAYS = ['dashboard', 'logout', 'profile', 'crm_me'];
    /** Keys from roles granted to a user (tenant scope, not expired). Used by Elanban Auth and the CRM bridge. */
    const ROLE_KEYS_SQL = "SELECT DISTINCT k.perm_key FROM eb_perm_grants g JOIN eb_perm_role_keys k ON k.role_id = g.role_id
                           WHERE g.user_id = ? AND g.scope_type = 'tenant' AND (g.expires_at IS NULL OR g.expires_at > UTC_TIMESTAMP())";
    const ADMIN_ONLY = ['channels', 'settings', 'botsettings', 'apilog', 'ai', 'webchat', 'notices', 'crm_settings'];

    public static function pageKey(string $page): string
    {
        return $page . '.page.view';
    }

    /**
     * Effective keys.
     * @param string      $role       eb_users.role (admin|supervisor|operator|viewer)
     * @param array       $perms      decoded eb_users.permissions_json (pages, groups, can_delete_customers, …)
     * @param array|null  $orgPages   effective pages of the user's org-chart role, or null if none
     * @param string[]    $roleKeys   keys from new-model roles granted to the user
     * @return string[]
     */
    public static function effective(string $role, array $perms, ?array $orgPages, array $roleKeys = []): array
    {
        if ($role === 'admin' || $role === 'viewer') return ['*'];
        $keys = [];
        // Emptiness is judged on the raw list, exactly like pre-2.3.0 (a list holding only junk values
        // was "restricted", not "everything"); only string entries can become keys.
        $ownRaw = (array)($perms['pages'] ?? []);
        if ($orgPages !== null) {
            $pages = array_values(array_unique(array_merge($orgPages, $ownRaw), SORT_REGULAR));
            if (!$pages) $pages = ['dashboard'];
            foreach ($pages as $p) if (is_string($p)) $keys[] = self::pageKey($p);
        } elseif (empty($perms['pages'])) {
            $keys[] = '*.page.view';                 // "no sections ticked" = all non-admin sections
        } else {
            foreach ($ownRaw as $p) if (is_string($p)) $keys[] = self::pageKey($p);
        }
        if (!empty($perms['can_delete_customers'])) $keys[] = 'customer.person.delete';
        foreach ($roleKeys as $k) if (is_string($k) && self::validKey($k, true)) $keys[] = $k;
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }

    /** permissions_json → array, decoded exactly like pre-2.3.0 Auth::permissions(). */
    public static function decodeLegacy(?string $raw): array
    {
        $p = $raw ? json_decode($raw, true) : [];
        return is_array($p) ? $p : [];
    }

    /** Does the key set allow $key? */
    public static function allows(array $keys, string $key): bool
    {
        if (in_array('*', $keys, true)) return true;
        if (preg_match('/^([a-z0-9_]+)\.page\.view$/', $key, $m)) {
            if (in_array($m[1], self::ALWAYS, true)) return true;
            if (in_array($m[1], self::ADMIN_ONLY, true)) return false;
        }
        foreach ($keys as $g) {
            if ($g === $key) return true;
            if (strpos($g, '*') !== false && self::wildMatch($g, $key)) return true;
        }
        return false;
    }

    public static function allowsPage(array $keys, string $page): bool
    {
        return self::allows($keys, self::pageKey($page));
    }

    public static function validKey(string $k, bool $allowWildcard = false): bool
    {
        $seg = $allowWildcard ? '([a-z0-9_]+|\*)' : '[a-z0-9_]+';
        return (bool)preg_match('/^' . $seg . '\.' . $seg . '\.' . $seg . '$/', $k);
    }

    private static function wildMatch(string $granted, string $key): bool
    {
        $g = explode('.', $granted); $k = explode('.', $key);
        if (count($g) !== count($k)) return false;
        foreach ($g as $i => $s) if ($s !== '*' && $s !== $k[$i]) return false;
        return true;
    }
}
