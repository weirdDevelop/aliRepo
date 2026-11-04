<?php
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
        // نشست نمایشی: هیچ کوئری نوشتنی اجرا نشود
        if (self::isViewer() && class_exists('Database')) Database::$readOnly = true;
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
        if (self::isAdmin()) return true;
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
        if (self::isAdmin()) return true;
        $perms = self::permissions();
        // اگر کلید can_delete_customers صریحاً true باشد
        return !empty($perms['can_delete_customers']);
    }

    /** آیا دسترسی به داده‌ی مشتریان محدود به گروه خاصی است؟ */
    public static function hasGroupRestriction(): bool
    {
        return self::allowedGroups() !== null;
    }
}
