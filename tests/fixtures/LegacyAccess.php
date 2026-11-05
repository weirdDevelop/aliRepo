<?php
/**
 * FROZEN copy of pre-2.3.0 Auth::permissions / canAccess / canManageCustomers (commit 55f544e),
 * with session/org-role/admin lookups replaced by injected static state. Used only by the
 * differential test proving the new Permissions model makes identical decisions. Do not edit.
 */
final class LegacyAccess
{
    public static string $role = 'operator';
    public static ?string $permsJson = null;
    public static ?array $orgRole = null;   // ['pages' => [...]] or null

    public static function permissions(): array
    {
        $raw = self::$permsJson;
        $p = $raw ? json_decode($raw, true) : [];
        if (!is_array($p)) $p = [];
        // ۲.۰.۹: بخش‌های نقش چارت سازمانی + بخش‌های انتخابی خود کاربر. با نقش، «لیست خالی = همه» نیست.
        $role = self::$orgRole;
        if ($role) {
            $pages = array_values(array_unique(array_merge($role['pages'], (array)($p['pages'] ?? []))));
            $p['pages'] = $pages ?: ['dashboard'];
        }
        return $p;
    }
    public static function canAccess(string $page): bool
    {
        if (in_array(self::$role, ['admin','viewer'], true)) return true;
        // صفحات همیشه‌مجاز برای هر اپراتور
        $always = ['dashboard', 'logout', 'profile', 'crm_me'];
        if (in_array($page, $always, true)) return true;
        // صفحات فقط-admin
        $adminOnly = ['channels', 'settings', 'botsettings', 'apilog', 'ai', 'webchat', 'notices', 'crm_settings'];
        if (in_array($page, $adminOnly, true)) return false;
        $perms = self::permissions();
        if (empty($perms['pages'])) return true; // محدودیتی تعریف نشده
        return in_array($page, $perms['pages'], true);
    }
    public static function canManageCustomers(): bool
    {
        if (in_array(self::$role, ['admin','viewer'], true)) return true;
        $perms = self::permissions();
        // اگر کلید can_delete_customers صریحاً true باشد
        return !empty($perms['can_delete_customers']);
    }
}
