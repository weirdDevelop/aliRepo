<?php
require_once __DIR__ . '/Permissions.php';   // 2.3.0: Auth is used by entry points without the autoloader
/**
 * core/Auth.php
 * احراز هویت ساده مبتنی بر session با سه نقش: admin (مدیر)، supervisor (سرپرست)، operator (پشتیبان).
 * سرپرست: دسترسی صفحه‌ها مثل پشتیبان (طبق مجوزها) ولی همه‌ی مشتریان را می‌بیند و در صندوق ورودی
 * محدودیت «بر عهده گرفتن چت» ندارد؛ می‌تواند چت‌ها را بین پشتیبان‌ها جابه‌جا کند.
 * نمایشی (viewer): همه‌چیز را مثل مدیر می‌بیند ولی هیچ کاری انجام نمی‌دهد (core/Viewer.php).
 */

class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // 2.1.6 (R-13): کوکی نشست httponly + samesite=Lax (+ secure روی HTTPS)؛ شناسه‌ی نشستِ ساختگی پذیرفته نمی‌شود.
            // همان مقادیری که crm/config.php برای همین نشست مشترک می‌گذارد.
            if (!headers_sent()) {
                ini_set('session.use_strict_mode', '1');
                ini_set('session.use_only_cookies', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_samesite', 'Lax');
                if (self::isHttps()) ini_set('session.cookie_secure', '1');
            }
            session_start();
        }
        self::refresh();
        // نشست نمایشی: هیچ کوئری نوشتنی اجرا نشود
        if (self::isViewer() && class_exists('Database')) Database::$readOnly = true;
    }

    /**
     * 2.1.6 (P-04): نقش و دسترسی‌ها در هر درخواست از eb_users خوانده می‌شوند، نه فقط هنگام ورود.
     * قبلاً تغییر دسترسی یا غیرفعال کردن یک کاربر تا خروج/ورود بعدی او اثر نداشت.
     * کاربر حذف‌شده یا غیرفعال همان لحظه از نشست خارج می‌شود. در حالت «ورود به‌جای»
     * (impersonation) دسترسی‌های کاربرِ هدف خوانده می‌شود، مثل قبل.
     */
    private static function refresh(): void
    {
        $uid = (int)($_SESSION['eb_uid'] ?? 0);
        if ($uid <= 0 || !class_exists('Database', false)) return;
        try {
            $u = Database::one("SELECT role, permissions_json, is_active FROM eb_users WHERE id = ?", [$uid]);
        } catch (Throwable $e) {
            return;   // DB not reachable: keep the session as-is rather than logging everyone out
        }
        if (!$u || !(int)($u['is_active'] ?? 1)) {
            self::audit('session_revoked', $u ? 'user deactivated' : 'user deleted', $uid, $uid);
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            return;
        }
        $_SESSION['eb_role'] = $u['role'];
        $_SESSION['eb_perms'] = $u['permissions_json'] ?? null;
    }

    /** درخواست روی HTTPS است؟ X-Forwarded-Proto فقط وقتی trust_proxy روشن است (مثل r.php). */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
        $cfg = $GLOBALS['config'] ?? null;
        if (is_array($cfg) && !empty($cfg['trust_proxy'])) {
            return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    /** تلاش برای ورود؛ true در صورت موفقیت */
    public static function attempt(string $username, string $password): bool
    {
        $user = Database::one(
            "SELECT * FROM eb_users WHERE username = ? AND is_active = 1",
            [$username]
        );
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['eb_uid']  = (int) $user['id'];
        $_SESSION['eb_role'] = $user['role'];
        $_SESSION['eb_name'] = trim((string)($user['display_name'] ?? '')) !== '' ? $user['display_name'] : $user['username'];
        $_SESSION['eb_perms'] = $user['permissions_json'] ?? null;
        // جلوگیری از session fixation
        session_regenerate_id(true);
        return true;
    }

    /** عبارت SQL نام نمایشی (اگر خالی بود، نام کاربری) برای جدول با نام مستعار $alias */
    public static function nameSql(string $alias = 'u'): string
    {
        $a = $alias !== '' ? $alias . '.' : '';
        return "COALESCE(NULLIF(TRIM({$a}display_name), ''), {$a}username)";
    }

    /** نام نمایشی کاربر جاری (از دیتابیس، تا تغییر نام بدون خروج و ورود دوباره دیده شود) */
    public static function name(): string
    {
        static $n = null;
        if ($n !== null) return $n;
        $n = (string)($_SESSION['eb_name'] ?? '');
        if (self::id()) {
            try {
                $r = Database::one("SELECT " . self::nameSql('') . " n FROM eb_users WHERE id = ?", [self::id()]);
                if (!empty($r['n'])) $n = $r['n'];
            } catch (Throwable $e) {}
        }
        return $n !== '' ? $n : 'کاربر';
    }

    /**
     * ورود مدیر به‌جای یک کاربر دیگر (برای بررسی پنل از دید او یا کار به‌جای او).
     * حساب اصلی مدیر در نشست نگه داشته می‌شود تا با stopImpersonating برگردد.
     */
    public static function impersonate(int $uid): array
    {
        $orig = $_SESSION['eb_imp'] ?? null;
        if (!$orig && !self::isRealAdmin()) return ['ok' => false, 'error' => 'فقط مدیر می‌تواند به‌جای کاربران وارد شود'];
        $u = Database::one("SELECT * FROM eb_users WHERE id = ? AND is_active = 1", [$uid]);
        if (!$u) return ['ok' => false, 'error' => 'کاربر پیدا نشد یا غیرفعال است'];
        if ($orig && (int)$orig['uid'] === $uid) return self::stopImpersonating();
        if ((int)$u['id'] === (int)self::id()) return ['ok' => false, 'error' => 'الان با همین حساب وارد شده‌اید'];
        if ($u['role'] === 'admin') return ['ok' => false, 'error' => 'ورود به‌جای مدیر دیگر مجاز نیست'];
        if (!$orig) $_SESSION['eb_imp'] = ['uid' => (int)self::id(), 'role' => self::role(), 'name' => $_SESSION['eb_name'] ?? '', 'perms' => $_SESSION['eb_perms'] ?? null];
        $_SESSION['eb_uid']  = (int)$u['id'];
        $_SESSION['eb_role'] = $u['role'];
        $_SESSION['eb_name'] = trim((string)($u['display_name'] ?? '')) !== '' ? $u['display_name'] : $u['username'];
        $_SESSION['eb_perms'] = $u['permissions_json'] ?? null;
        session_regenerate_id(true);
        self::audit('impersonate', 'ورود مدیر «' . ($_SESSION['eb_imp']['name'] ?? '') . '» به‌جای «' . $_SESSION['eb_name'] . '»', (int)$_SESSION['eb_imp']['uid'], (int)$u['id']);
        return ['ok' => true, 'as' => $_SESSION['eb_name']];
    }

    /** بازگشت به حساب مدیر */
    public static function stopImpersonating(): array
    {
        $o = $_SESSION['eb_imp'] ?? null;
        if (!$o) return ['ok' => false, 'error' => 'در حالت ورود به‌جای کاربر نیستید'];
        $as = (int)self::id();
        $_SESSION['eb_uid'] = (int)$o['uid'];
        $_SESSION['eb_role'] = $o['role'];
        $_SESSION['eb_name'] = $o['name'];
        $_SESSION['eb_perms'] = $o['perms'];
        unset($_SESSION['eb_imp']);
        session_regenerate_id(true);
        self::audit('impersonate_end', 'پایان ورود به‌جای کاربر', (int)$o['uid'], $as);
        return ['ok' => true];
    }

    /** اگر مدیر به‌جای کاربر دیگری وارد شده: [uid, name] حساب اصلی */
    public static function impersonator(): ?array
    {
        $o = $_SESSION['eb_imp'] ?? null;
        return $o ? ['uid' => (int)$o['uid'], 'name' => (string)$o['name']] : null;
    }

    /** ثبت رویداد امنیتی (جدول eb_audit) */
    public static function audit(string $action, string $detail, ?int $by = null, ?int $target = null): void
    {
        try {
            Database::run("INSERT INTO eb_audit (user_id, target_user_id, action, detail, ip) VALUES (?, ?, ?, ?, ?)",
                [$by, $target, $action, mb_substr($detail, 0, 500), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) {}
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['eb_uid']);
    }

    public static function id(): ?int
    {
        return $_SESSION['eb_uid'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['eb_role'] ?? null;
    }

    /** مدیر — حساب نمایشی هم برای «دیدن» مثل مدیر رفتار می‌کند (نوشتن در Viewer بسته است) */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin' || self::role() === 'viewer';
    }

    /** مدیر واقعی (نه نمایشی) */
    public static function isRealAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** حساب نمایشی (فقط مشاهده) */
    public static function isViewer(): bool
    {
        return self::role() === 'viewer';
    }

    public static function isSupervisor(): bool
    {
        return self::role() === 'supervisor';
    }

    /** مدیر یا سرپرست — بدون محدودیت تخصیص چت در صندوق ورودی */
    public static function isManager(): bool
    {
        return self::isAdmin() || self::isSupervisor() || self::orgRoleManager();
    }

    /** ۲.۰.۹: نقش چارت سازمانی کاربر (یا null) و پرچم «مثل سرپرست» */
    private static $orgRole = false;
    public static function orgRole(): ?array
    {
        if (self::$orgRole !== false) return self::$orgRole;
        self::$orgRole = null;
        $uid = self::id();
        if (!$uid || self::role() === 'admin' || self::role() === 'viewer') return null;
        try {
            $rid = OrgRoles::userRoleId((int)$uid);
            if ($rid) {
                $r = OrgRoles::get($rid);
                if ($r) self::$orgRole = ['id' => $rid, 'name' => $r['name'], 'is_manager' => (int)$r['is_manager'], 'pages' => OrgRoles::effectivePages($rid)];
            }
        } catch (Throwable $e) {}
        return self::$orgRole;
    }
    public static function orgRoleManager(): bool
    {
        $r = self::orgRole();
        return $r && !empty($r['is_manager']);
    }

    /** نام فارسی نقش */
    public static function roleLabel(?string $role = null): string
    {
        $role = $role ?? self::role();
        return ['admin' => 'مدیر', 'supervisor' => 'سرپرست', 'operator' => 'پشتیبان', 'viewer' => 'نمایشی'][$role] ?? 'پشتیبان';
    }

    /** اگر وارد نشده، به صفحه‌ی لاگین برو */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: index.php?page=login');
            exit;
        }
    }

    /** اگر نقش کافی نیست، خطای دسترسی */
    public static function requireRole(string $role): void
    {
        self::requireLogin();
        if ($role === 'admin' && !self::isAdmin()) {
            http_response_code(403);
            exit('دسترسی غیرمجاز');
        }
    }

    /** مجوزهای اپراتور فعلی (آرایه). admin نامحدود است. */
    public static function permissions(): array
    {
        $raw = $_SESSION['eb_perms'] ?? null;
        $p = $raw ? json_decode($raw, true) : [];
        if (!is_array($p)) $p = [];
        // ۲.۰.۹: بخش‌های نقش چارت سازمانی + بخش‌های انتخابی خود کاربر. با نقش، «لیست خالی = همه» نیست.
        $role = self::orgRole();
        if ($role) {
            $pages = array_values(array_unique(array_merge($role['pages'], (array)($p['pages'] ?? []))));
            $p['pages'] = $pages ?: ['dashboard'];
        }
        return $p;
    }

    /**
     * آیا کاربر فعلی به یک صفحه/قابلیت دسترسی دارد؟
     * admin همیشه بله. operator بر اساس لیست pages در مجوزها؛
     * اگر لیستی تعریف نشده، یعنی دسترسی کامل (سازگاری با اپراتورهای قدیمی).
     */
    public static function canAccess(string $page): bool
    {
        // 2.3.0: همان تصمیم قبلی (اثبات‌شده با تست تفاضلی) + نقش‌های تازه‌ی ویرایشگر دسترسی — core/Permissions.php
        return Permissions::allowsPage(self::keys(), $page);
    }

    /** 2.3.0: کلید مجوز (مثل work.task.approve) — ADR-006 */
    public static function can(string $key): bool
    {
        return Permissions::allows(self::keys(), $key);
    }

    private static ?array $scopedCache = null;
    private static ?array $unitParents = null;

    /**
     * 2.4.0: کلید مجوز برای داده‌ای که متعلق به یک واحد سازمانی است (نقش‌های دامنه‌دار: یک واحد، یا واحد و زیرمجموعه‌ها).
     * $unitId = null یعنی بدون واحد → همان can(). صفحه‌ها هرگز با نقش دامنه‌دار باز نمی‌شوند.
     */
    public static function canIn(string $key, ?int $unitId): bool
    {
        if ($unitId === null || self::can($key)) return self::can($key);
        $uid = (int)(self::id() ?? 0);
        if ($uid <= 0 || !class_exists('Migrations') || !Migrations::isApplied('0004_organization')) return false;
        if (self::$scopedCache === null || (self::$scopedCache['uid'] ?? 0) !== $uid) {
            $rows = [];
            try { $rows = Database::all(Permissions::SCOPED_KEYS_SQL, [$uid]); } catch (Throwable $e) { error_log('[auth] scoped keys: ' . $e->getMessage()); }
            self::$scopedCache = ['uid' => $uid, 'rows' => $rows];
        }
        if (!self::$scopedCache['rows']) return false;
        if (self::$unitParents === null) {
            self::$unitParents = [];
            try { foreach (Database::all("SELECT id, parent_id FROM eb_org_units") as $r) self::$unitParents[(int)$r['id']] = $r['parent_id'] === null ? null : (int)$r['parent_id']; }
            catch (Throwable $e) {}
        }
        $anc = []; $seen = [$unitId => true]; $p = self::$unitParents[$unitId] ?? null;
        while ($p !== null && !isset($seen[$p])) { $anc[] = $p; $seen[$p] = true; $p = self::$unitParents[$p] ?? null; }
        return Permissions::allowsInUnit(self::keys(), self::$scopedCache['rows'], $key, $unitId, $anc);
    }

    /** Test/long-running helper: forget per-request permission caches. */
    public static function forgetPermissionCache(): void
    {
        self::$keysCache = null; self::$scopedCache = null; self::$unitParents = null;
    }

    private static ?array $keysCache = null;
    private static string $keysSig = '';

    /** کلیدهای مؤثر کاربر فعلی: پروژکشن تنظیمات قدیمی + نقش‌های اعطاشده. در هر درخواست یک‌بار. */
    public static function keys(): array
    {
        $uid = (int)(self::id() ?? 0);
        if ($uid <= 0) return [];
        $sig = $uid . '|' . self::role() . '|' . (string)($_SESSION['eb_perms'] ?? '');
        if (self::$keysCache !== null && self::$keysSig === $sig) return self::$keysCache;
        $role = (string)self::role();
        $org = self::orgRole();
        $roleKeys = [];
        // بدون autoloader (چند ورودی قدیمی) Migrations در دسترس نیست → فقط پروژکشن قدیمی (همان رفتار قبل)
        if (!in_array($role, ['admin', 'viewer'], true) && class_exists('Migrations') && Migrations::isApplied('0003_permissions')) {
            try { $roleKeys = array_column(Database::all(Permissions::ROLE_KEYS_SQL, [$uid]), 'perm_key'); }
            catch (Throwable $e) { error_log('[auth] role keys: ' . $e->getMessage()); }
        }
        self::$keysSig = $sig;
        return self::$keysCache = Permissions::effective($role, Permissions::decodeLegacy($_SESSION['eb_perms'] ?? null), $org ? $org['pages'] : null, $roleKeys);
    }

    /**
     * گروه‌های مجاز برای اپراتور فعلی.
     * خروجی: آرایه‌ی group_id، یا null یعنی «همه‌ی گروه‌ها» (بدون محدودیت).
     */
    public static function allowedGroups(): ?array
    {
        if (self::isManager()) return null; // مدیر و سرپرست همه‌ی مشتریان را می‌بینند
        $perms = self::permissions();
        if (empty($perms['groups'])) return null; // بدون محدودیت
        return array_map('intval', $perms['groups']);
    }

    /** آیا کاربر فعلی اجازه‌ی حذف/مدیریت مخاطبان را دارد؟ admin همیشه. */
    public static function canManageCustomers(): bool
    {
        // 2.3.0: کلید customer.person.delete (از can_delete_customers قدیمی یا نقش تازه)
        return self::can('customer.person.delete');
    }

    /** آیا دسترسی به داده‌ی مشتریان محدود به گروه خاصی است؟ */
    public static function hasGroupRestriction(): bool
    {
        return self::allowedGroups() !== null;
    }
}
