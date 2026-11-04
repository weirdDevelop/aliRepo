<?php
/**
 * modules/OrgRoles.php — ۲.۰.۹ — چارت سازمانی و نقش‌های قابل تعریف
 * ==========================================================================
 * هر «نقش» یک گره در درخت است (مدیر مالی، مدیر عملیات ← فروش / میزبان، مدیر مارکتینگ ← مدیر سوشال ← زیرگروه‌ها…).
 * - هر نقش لیست بخش‌های مجاز خودش را دارد (pages_json).
 * - هر نقشِ بالاتر، بخش‌های همه‌ی زیرمجموعه‌هایش را هم دارد (مدیر عملیات هرچه فروش و میزبان می‌بینند را می‌بیند).
 * - نقشی که «is_manager» دارد مثل سرپرست رفتار می‌کند (همه‌ی مشتریان، جابه‌جایی چت).
 * - هر کاربر یک نقش (eb_users.role_id) دارد؛ بخش‌های انتخابی خودش (اگر داشته باشد) به دسترسی نقش اضافه می‌شود.
 * نقش «admin» و «viewer» سیستم دست‌نخورده می‌ماند.
 */
class OrgRoles
{
    private static $ready = false;
    private static $cache = null;

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            if (!Database::hasTable('eb_roles')) {
                Database::run("CREATE TABLE IF NOT EXISTS eb_roles (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(120) NOT NULL,
                    parent_id INT UNSIGNED DEFAULT NULL,
                    pages_json TEXT,
                    is_manager TINYINT(1) NOT NULL DEFAULT 0,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id), KEY idx_parent (parent_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                Database::forgetSchemaCache();
            }
            if (!Database::hasColumn('eb_users', 'role_id')) {
                Database::run("ALTER TABLE eb_users ADD COLUMN role_id INT UNSIGNED DEFAULT NULL");
                Database::forgetSchemaCache();
            }
        } catch (Throwable $e) { /* بدون جدول، نقش‌ها نادیده گرفته می‌شوند */ }
    }

    public static function available(): bool
    {
        self::ensure();
        return Database::hasTable('eb_roles') && Database::hasColumn('eb_users', 'role_id');
    }

    /** همه‌ی نقش‌ها (آرایه‌ی ساده با pages به‌صورت آرایه) */
    public static function all(): array
    {
        if (!self::available()) return [];
        if (self::$cache !== null) return self::$cache;
        $rows = Database::all("SELECT * FROM eb_roles ORDER BY sort_order, id");
        foreach ($rows as &$r) {
            $p = json_decode((string)$r['pages_json'], true);
            $r['pages'] = is_array($p) ? array_values($p) : [];
            $r['id'] = (int)$r['id'];
            $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
            $r['is_manager'] = (int)$r['is_manager'];
            unset($r['pages_json']);
        }
        unset($r);
        return self::$cache = $rows;
    }

    public static function reset(): void { self::$cache = null; }

    public static function get(int $id): ?array
    {
        foreach (self::all() as $r) if ($r['id'] === $id) return $r;
        return null;
    }

    /** شناسه‌ی همه‌ی زیرمجموعه‌ها (مستقیم و غیرمستقیم) */
    public static function descendants(int $id): array
    {
        $kids = [];
        foreach (self::all() as $r) $kids[(int)$r['parent_id']][] = $r['id'];
        $out = []; $stack = $kids[$id] ?? [];
        while ($stack) {
            $c = array_pop($stack);
            if (in_array($c, $out, true)) continue;
            $out[] = $c;
            foreach ($kids[$c] ?? [] as $k) $stack[] = $k;
        }
        return $out;
    }

    /** بخش‌های مؤثر یک نقش = بخش‌های خودش + همه‌ی زیرمجموعه‌ها */
    public static function effectivePages(int $id): array
    {
        $by = [];
        foreach (self::all() as $r) $by[$r['id']] = $r;
        if (!isset($by[$id])) return [];
        $pages = $by[$id]['pages'];
        foreach (self::descendants($id) as $d) $pages = array_merge($pages, $by[$d]['pages'] ?? []);
        return array_values(array_unique($pages));
    }

    /** نقش کاربر (role_id) یا null */
    public static function userRoleId(int $uid): ?int
    {
        if (!self::available()) return null;
        $r = Database::one("SELECT role_id FROM eb_users WHERE id=?", [$uid]);
        return !empty($r['role_id']) ? (int)$r['role_id'] : null;
    }

    public static function save(array $in): array
    {
        if (!self::available()) return ['ok' => false, 'error' => 'جدول نقش‌ها ساخته نشد'];
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if ($name === '') return ['ok' => false, 'error' => 'نام نقش را بنویسید'];
        $id = (int)($in['id'] ?? 0);
        $parent = !empty($in['parent_id']) ? (int)$in['parent_id'] : null;
        if ($parent !== null && !self::get($parent)) return ['ok' => false, 'error' => 'نقش بالادست پیدا نشد'];
        if ($id && $parent !== null && ($parent === $id || in_array($parent, self::descendants($id), true))) {
            return ['ok' => false, 'error' => 'نقش را نمی‌شود زیر خودش یا زیرمجموعه‌ی خودش گذاشت'];
        }
        $pages = array_values(array_unique(array_filter(array_map('strval', (array)($in['pages'] ?? [])), function ($p) {
            return preg_match('/^[a-z0-9_]{2,40}$/', $p);
        })));
        $json = json_encode($pages, JSON_UNESCAPED_UNICODE);
        $mgr = !empty($in['is_manager']) ? 1 : 0;
        if ($id) {
            if (!self::get($id)) return ['ok' => false, 'error' => 'نقش پیدا نشد'];
            Database::run("UPDATE eb_roles SET name=?, parent_id=?, pages_json=?, is_manager=? WHERE id=?", [$name, $parent, $json, $mgr, $id]);
        } else {
            $max = Database::one("SELECT COALESCE(MAX(sort_order),0)+1 n FROM eb_roles WHERE " . ($parent === null ? "parent_id IS NULL" : "parent_id=" . (int)$parent));
            Database::run("INSERT INTO eb_roles (name, parent_id, pages_json, is_manager, sort_order) VALUES (?,?,?,?,?)", [$name, $parent, $json, $mgr, (int)($max['n'] ?? 1)]);
            $id = Database::lastId();
        }
        self::reset();
        return ['ok' => true, 'id' => $id];
    }

    public static function delete(int $id): array
    {
        $r = self::get($id);
        if (!$r) return ['ok' => false, 'error' => 'نقش پیدا نشد'];
        // زیرمجموعه‌ها یک پله بالا می‌روند؛ کاربران بی‌نقش می‌شوند
        Database::run("UPDATE eb_roles SET parent_id=? WHERE parent_id=?", [$r['parent_id'], $id]);
        Database::run("UPDATE eb_users SET role_id=NULL WHERE role_id=?", [$id]);
        Database::run("DELETE FROM eb_roles WHERE id=?", [$id]);
        self::reset();
        return ['ok' => true];
    }

    public static function setUserRole(int $uid, ?int $roleId): void
    {
        if (!self::available()) return;
        if ($roleId !== null && !self::get($roleId)) $roleId = null;
        Database::run("UPDATE eb_users SET role_id=? WHERE id=?", [$roleId, $uid]);
    }

    /** چارت نمونه (فقط وقتی هیچ نقشی نیست) */
    public static function seed(): array
    {
        if (!self::available()) return ['ok' => false, 'error' => 'جدول نقش‌ها ساخته نشد'];
        if (self::all()) return ['ok' => false, 'error' => 'چارت از قبل دارد'];
        $mk = function (string $name, ?int $parent, array $pages, int $mgr = 0) {
            $r = self::save(['name' => $name, 'parent_id' => $parent, 'pages' => $pages, 'is_manager' => $mgr]);
            return (int)$r['id'];
        };
        $base = ['dashboard', 'inbox', 'persons'];
        $fin = $mk('مدیر مالی', null, array_merge(['dashboard', 'stats', 'crm_payroll', 'crm_reports', 'crm_leaves'], []), 1);
        $ops = $mk('مدیر عملیات', null, array_merge($base, ['callbacks', 'crm_tickets', 'crm_tasks', 'crm_chat', 'crm_staff', 'crm_leaves']), 1);
        $mk('فروش', $ops, array_merge($base, ['callbacks', 'crm_calls', 'crm_leads', 'crm_customers', 'crm_tasks', 'crm_chat']));
        $mk('میزبان', $ops, array_merge($base, ['crm_hosts', 'crm_host_leads', 'crm_tickets', 'crm_chat']));
        $mkt = $mk('مدیر مارکتینگ', null, array_merge($base, ['send', 'campaigns', 'scheduled', 'sendlog', 'groups', 'templates', 'links', 'stats']), 1);
        $soc = $mk('مدیر سوشال', $mkt, array_merge($base, ['instagram', 'send', 'campaigns', 'scheduled', 'templates']), 1);
        $mk('محتوا و کپشن', $soc, ['dashboard', 'instagram', 'templates', 'scheduled']);
        $mk('دایرکت و کامنت', $soc, ['dashboard', 'inbox', 'instagram', 'persons']);
        $seo = $mk('مدیر سئو', $mkt, ['dashboard', 'marketing', 'analytics', 'stats', 'links'], 1);
        $mk('سئوی محتوا', $seo, ['dashboard', 'marketing', 'links']);
        $mk('سئوی فنی', $seo, ['dashboard', 'analytics', 'stats']);
        return ['ok' => true];
    }
}
