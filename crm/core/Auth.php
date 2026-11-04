<?php
/**
 * جاکجاست - کلاس احراز هویت
 * Jakojast - Authentication Class
 *
 * مدیریت: لاگین، لاگ‌اوت، توکن، نقش‌ها، دسترسی‌ها
 *
 * استراتژی توکن:
 *   - توکن ۶۴ کاراکتری hex تصادفی
 *   - در جدول auth_tokens با انقضای ۸ ساعت ذخیره می‌شود
 *   - در هدر Authorization: Bearer <token> ارسال می‌شود
 *   - در صورت انقضا، توکن از دیتابیس حذف می‌شود
 *
 * استراتژی رمز عبور:
 *   - bcrypt با cost=10 (در Helpers::hashPassword)
 *   - پشتیبانی از مهاجرت MD5 قدیمی (در Helpers::verifyPassword)
 */

class Auth
{
    /** @var array|null */
    private static $currentUser = null;

    /**
     * لاگین کاربر با نام کاربری و رمز عبور
     *
     * @param string $username
     * @param string $password
     * @return array|false  (اطلاعات کاربر + توکن یا false)
     */
    public static function login($username, $password)
    {
        $db = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, password, full_name, email, role, exten_number, is_active
             FROM users WHERE username = ? LIMIT 1',
            [$username]
        );

        if (!$user) {
            Logger::info('Login failed: user not found', ['username' => $username]);
            return false;
        }

        if (!$user['is_active']) {
            Logger::info('Login failed: user inactive', ['username' => $username]);
            return false;
        }

        // بررسی رمز عبور (با پشتیبانی از مهاجرت MD5)
        $result = Helpers::verifyPassword($password, $user['password']);

        if (!$result['matched']) {
            Logger::info('Login failed: wrong password', ['username' => $username]);
            return false;
        }

        // مهاجرت به bcrypt در صورت نیاز
        if ($result['newHash'] !== null) {
            $db->update('users', ['password' => $result['newHash']], 'id = ?', [$user['id']]);
            Logger::info('Password migrated from MD5 to bcrypt', ['user_id' => $user['id']]);
        }

        // به‌روزرسانی last_login
        $db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        // تولید توکن
        $token = self::createToken($user['id']);

        // تنظیم کاربر جاری
        unset($user['password']);
        self::$currentUser = $user;

        Logger::info('Login successful', ['user_id' => $user['id'], 'username' => $username]);

        return [
            'user'  => $user,
            'token' => $token,
        ];
    }

    /**
     * خروج کاربر (حذف توکن از دیتابیس)
     *
     * @return bool
     */
    public static function logout()
    {
        $token = self::extractToken();
        if (!$token) return true;

        Database::getInstance()->delete('auth_tokens', 'token = ?', [self::hashToken($token)]);
        self::$currentUser = null;
        return true;
    }

    /**
     * ایجاد توکن جدید برای کاربر
     *
     * @param int $userId
     * @return string
     */
    private static function createToken($userId)
    {
        $token = Helpers::generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // ⚠️ امنیت: مقدار خامِ توکن هرگز در دیتابیس ذخیره نمی‌شود، فقط
        // هش SHA-256 آن (۶۴ کاراکتر hex - دقیقاً هم‌اندازه‌ی ستون قبلی،
        // بدون نیاز به migration). اگر دیتابیس به هر دلیلی لو برود
        // (بک‌آپ درزکرده و…)، مهاجم نمی‌تواند مستقیم جای کاربران فعال
        // لاگین کند - برخلاف قبل که مقدار خامِ همان چیزی بود که در
        // کوکی/هدر کاربر است.
        Database::getInstance()->insert('auth_tokens', [
            'user_id'    => $userId,
            'token'      => self::hashToken($token),
            'expires_at' => $expiresAt,
            'ip'         => $ip,
            'user_agent' => $ua ? substr($ua, 0, 255) : null,
        ]);

        return $token;
    }

    /**
     * هش کردن توکن برای ذخیره/جست‌وجو در دیتابیس (مقدار خام هرگز ذخیره نمی‌شود)
     * @param string $token
     * @return string
     */
    private static function hashToken($token)
    {
        return hash('sha256', $token);
    }

    /**
     * استخراج توکن از هدر Authorization یا کوکی
     * @return string|null
     */
    public static function extractToken()
    {
        // ۱) هدر Authorization: Bearer xxx
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            return trim($m[1]);
        }
        // ۲) هدر X-Auth-Token
        if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return $_SERVER['HTTP_X_AUTH_TOKEN'];
        }
        // ۳) پارامتر GET/POST token (فقط برای موارد خاص مثل پخش صوت در تگ audio)
        if (!empty($_GET['token'])) {
            return $_GET['token'];
        }
        // ۴) کوکی
        if (!empty($_COOKIE['jakojast_token'])) {
            return $_COOKIE['jakojast_token'];
        }
        return null;
    }

    /**
     * دریافت کاربر جاری (با اعتبارسنجی توکن)
     *
     * @return array|null  (اطلاعات کاربر بدون رمز عبور)
     */
    public static function currentUser()
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        // سیستم یکپارچه: ورود با حساب اعلان‌بان (نشست مشترک) اولویت دارد
        if (defined('CRM_BRIDGED') && CRM_BRIDGED) {
            $bridged = self::fromElanban();
            if ($bridged !== null) {
                self::$currentUser = $bridged;
                if (class_exists('User')) {
                    try { User::touchActivity($bridged['id']); } catch (\Throwable $ignore) {}
                }
                return self::$currentUser;
            }
            // ورود فقط با حساب اعلان‌بان؛ توکن‌ها و کوکی‌های ورود قدیمی CRM دیگر پذیرفته نمی‌شوند
            return null;
        }

        $token = self::extractToken();
        if (!$token) {
            return null;
        }

        // ⚠️ پاکسازی توکن‌های منقضی قبلاً اینجا (روی *هر* درخواست از *هر*
        // کاربر) اجرا می‌شد که با ۲۰ کاربر هم‌زمان یعنی صدها DELETE
        // غیرضروری در دقیقه فقط برای IO/lock دیتابیس. الان این کار فقط
        // به‌صورت دوره‌ای از کرون انجام می‌شود (نگاه کنید به
        // Auth::purgeExpiredTokens و cron_sync.php) - اینجا فقط جست‌وجوی
        // خودِ توکن با شرط expires_at > NOW() کافی است و صحت را تضمین
        // می‌کند؛ صرفاً ردیف‌های منقضی کمی دیرتر از دیتابیس حذف می‌شوند.
        $tokenHash = self::hashToken($token);

        // تلاش با ستون permissions؛ اگر migration هنوز اجرا نشده (ستون
        // وجود ندارد)، به‌جای کرش کل سیستم لاگین، بدون permissions ادامه بده
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT t.user_id, t.expires_at, u.username, u.full_name, u.email, u.role,
                        u.exten_number, u.is_active, u.last_login, u.permissions
                 FROM auth_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token = ? AND t.expires_at > NOW() AND u.is_active = 1
                 LIMIT 1',
                [$tokenHash]
            );
        } catch (Exception $e) {
            // ستون permissions هنوز وجود ندارد (migration اجرا نشده) - آن را
            // بساز تا دفعه‌ی بعد این catch لازم نباشد، و فعلاً بدون آن ادامه بده
            if (class_exists('User')) {
                try { User::migrate(); } catch (\Throwable $ignore) {}
            }
            $row = Database::getInstance()->fetchOne(
                'SELECT t.user_id, t.expires_at, u.username, u.full_name, u.email, u.role,
                        u.exten_number, u.is_active, u.last_login
                 FROM auth_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token = ? AND t.expires_at > NOW() AND u.is_active = 1
                 LIMIT 1',
                [$tokenHash]
            );
            if ($row) $row['permissions'] = null;
        }

        if (!$row) {
            return null;
        }

        $permissions = [];
        if (!empty($row['permissions'])) {
            $decoded = json_decode($row['permissions'], true);
            if (is_array($decoded)) $permissions = $decoded;
        }

        // ⚠️ تمدید خودکار توکن (sliding expiration): چون TOKEN_EXPIRY دقیقاً
        // به اندازه‌ی یک شیفت (۸ ساعت) است و قبلاً هیچ تمدیدی وجود نداشت،
        // کارمندی که درست اول شیفت لاگین می‌کرد دقیقاً نزدیک پایان شیفت -
        // شلوغ‌ترین و حساس‌ترین لحظه‌ی تحویل کار - به‌طور ناگهانی خارج
        // می‌شد. الان اگر کمتر از ۲ ساعت به انقضا مانده باشد، با همین
        // درخواست ۸ ساعت دیگر تمدید می‌شود؛ برای کاربر فعال هیچ‌وقت این
        // اتفاق نمی‌افتد.
        $expiresAt = strtotime($row['expires_at']);
        if ($expiresAt && ($expiresAt - time()) < 2 * 3600) {
            $newExpiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY);
            Database::getInstance()->update('auth_tokens', ['expires_at' => $newExpiresAt], 'token = ?', [$tokenHash]);
        }

        self::$currentUser = [
            'id'           => (int)$row['user_id'],
            'username'     => $row['username'],
            'full_name'    => $row['full_name'],
            'email'        => $row['email'],
            'role'         => $row['role'],
            'exten_number' => $row['exten_number'],
            'is_active'    => (int)$row['is_active'],
            'last_login'   => $row['last_login'],
            'permissions'  => $permissions,
        ];

        // ⚠️ برای قابلیت «اپراتورهای آنلاین» - throttle شده داخل خودِ متد
        if (class_exists('User')) {
            try { User::touchActivity($row['user_id']); } catch (\Throwable $ignore) {}
        }

        return self::$currentUser;
    }


    /** @var bool|null */
    private static $ebReadOnly = false;

    /** آیا کاربر جاری حساب «نمایشی» اعلان‌بان است (فقط مشاهده) */
    public static function isReadOnly()
    {
        self::currentUser();
        return (bool)self::$ebReadOnly;
    }

    /** آدرس پنل اعلان‌بان نسبت به CRM */
    public static function elanbanUrl($q = '')
    {
        $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/crm/index.php'))), '/');
        if (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'api_external') $base = rtrim(dirname($base), '/');
        return $base . '/index.php' . ($q !== '' ? '?' . $q : '');
    }

    /** نگاشت نقش اعلان‌بان به نقش CRM */
    public static function mapRole($ebRole)
    {
        return ['admin' => 'admin', 'supervisor' => 'manager', 'viewer' => 'manager'][$ebRole] ?? 'staff';
    }

    /**
     * دسترسی‌های CRM از مجوزهای اعلان‌بان: صفحه‌های crm_* در permissions_json.pages
     * فهرست خالی در اعلان‌بان یعنی «همه‌ی بخش‌ها» ← null (دسترسی کامل)
     */
    public static function mapPermissions($permsJson)
    {
        $p = json_decode((string)$permsJson, true);
        $pages = is_array($p) ? ($p['pages'] ?? []) : [];
        if (!is_array($pages) || !$pages) return null;
        $out = [];
        foreach ($pages as $pg) {
            if (is_string($pg) && strpos($pg, 'crm_') === 0) $out[] = substr($pg, 4);
        }
        // «داشبورد» در CRM همیشه آزاد است
        return array_values(array_unique($out));
    }

    /**
     * کاربر CRM متناظر با کاربر واردشده‌ی اعلان‌بان.
     * اتصال: users.eb_user_id ← اگر نبود، همان نام کاربری ← اگر نبود، ساخت حساب تازه.
     * نام کامل (full_name) کاربرهای موجود عوض نمی‌شود، چون سرنخ‌ها و تسک‌ها با همین نام به افراد سپرده شده‌اند.
     */
    private static function fromElanban()
    {
        $uid = (int)($_SESSION['eb_uid'] ?? 0);
        if ($uid <= 0) return null;
        $db = Database::getInstance();
        try {
            $eb = $db->fetchOne('SELECT id, username, display_name, role, permissions_json, is_active FROM eb_users WHERE id = ? LIMIT 1', [$uid]);
        } catch (Exception $e) {
            return null;
        }
        if (!$eb || !(int)$eb['is_active']) return null;

        self::ensureBridgeColumn();
        $role = self::mapRole($eb['role']);
        $permsSrc = $eb['permissions_json'] ?? '';
        // ۲.۰.۹: نقش چارت سازمانی اعلان‌بان (بخش‌های نقش و زیرمجموعه‌هایش + «مثل سرپرست»)
        if (!in_array($eb['role'], ['admin', 'viewer'], true)) {
            $org = self::orgRoleFor($uid);
            if ($org) {
                $p0 = json_decode((string)$permsSrc, true); if (!is_array($p0)) $p0 = [];
                $pg = array_values(array_unique(array_merge($org['pages'], (array)($p0['pages'] ?? []))));
                $p0['pages'] = $pg ?: ['dashboard'];
                $permsSrc = json_encode($p0, JSON_UNESCAPED_UNICODE);
                if ($org['is_manager']) $role = 'manager';
            }
        }
        $perms = $eb['role'] === 'admin' ? [] : self::mapPermissions($permsSrc);
        self::$ebReadOnly = ($eb['role'] === 'viewer');

        $u = $db->fetchOne('SELECT * FROM users WHERE eb_user_id = ? LIMIT 1', [$uid]);
        if (!$u) {
            $u = $db->fetchOne('SELECT * FROM users WHERE username = ? AND (eb_user_id IS NULL OR eb_user_id = 0) LIMIT 1', [$eb['username']]);
            if ($u) {
                $db->update('users', ['eb_user_id' => $uid], 'id = ?', [$u['id']]);
            } else {
                $uname = $eb['username'];
                if ($db->fetchColumn('SELECT id FROM users WHERE username = ?', [$uname])) $uname = 'eb' . $uid . '_' . $uname;
                $name = trim((string)($eb['display_name'] ?? '')) !== '' ? $eb['display_name'] : $eb['username'];
                $db->insert('users', [
                    'username'   => mb_substr($uname, 0, 60),
                    'password'   => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    'full_name'  => mb_substr($name, 0, 120),
                    'role'       => $role,
                    'is_active'  => 1,
                    'eb_user_id' => $uid,
                ]);
                $u = $db->fetchOne('SELECT * FROM users WHERE eb_user_id = ? LIMIT 1', [$uid]);
                if (!$u) return null;
            }
        }
        // نقش و دسترسی همیشه از اعلان‌بان (ستون‌ها فقط برای سازگاری با کوئری‌های قدیمی همگام می‌شوند)
        $permJson = $perms === null ? null : json_encode($perms, JSON_UNESCAPED_UNICODE);
        if ($u['role'] !== $role || (array_key_exists('permissions', $u) && $u['permissions'] !== $permJson) || !(int)$u['is_active']) {
            try { $db->update('users', ['role' => $role, 'permissions' => $permJson, 'is_active' => 1], 'id = ?', [$u['id']]); } catch (Exception $e) {}
        }
        return [
            'id'           => (int)$u['id'],
            'username'     => $u['username'],
            'full_name'    => $u['full_name'],
            'email'        => $u['email'] ?? null,
            'role'         => $role,
            'exten_number' => $u['exten_number'] ?? null,
            'is_active'    => 1,
            'last_login'   => $u['last_login'] ?? null,
            'permissions'  => $perms,
            'eb_user_id'   => $uid,
            'eb_role'      => $eb['role'],
            'read_only'    => $eb['role'] === 'viewer',
        ];
    }

    /** نقش چارت کاربر اعلان‌بان: ['pages'=>[...مؤثر], 'is_manager'=>bool] یا null */
    private static function orgRoleFor($uid)
    {
        try {
            $db = Database::getInstance();
            $rid = (int)$db->fetchColumn('SELECT role_id FROM eb_users WHERE id = ?', [(int)$uid]);
            if (!$rid) return null;
            $rows = $db->fetchAll('SELECT id, parent_id, pages_json, is_manager FROM eb_roles');
            $by = []; $kids = [];
            foreach ($rows as $r) { $by[(int)$r['id']] = $r; $kids[(int)$r['parent_id']][] = (int)$r['id']; }
            if (!isset($by[$rid])) return null;
            $ids = [$rid]; $stack = $kids[$rid] ?? [];
            while ($stack) { $c = array_pop($stack); if (in_array($c, $ids, true)) continue; $ids[] = $c; foreach ($kids[$c] ?? [] as $k) $stack[] = $k; }
            $pages = [];
            foreach ($ids as $i) { $x = json_decode((string)$by[$i]['pages_json'], true); if (is_array($x)) $pages = array_merge($pages, $x); }
            return ['pages' => array_values(array_unique($pages)), 'is_manager' => (int)$by[$rid]['is_manager'] === 1];
        } catch (Exception $e) { return null; }
    }

    private static $bridgeColChecked = false;
    private static function ensureBridgeColumn()
    {
        if (self::$bridgeColChecked) return;
        self::$bridgeColChecked = true;
        if (Database::schemaDone(__METHOD__)) return;
        $db = Database::getInstance();
        try {
            $db->fetchColumn('SELECT eb_user_id FROM users LIMIT 1');
        } catch (Exception $e) {
            try {
                $db->execute("ALTER TABLE `users` ADD COLUMN `eb_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'حساب اعلان‌بان' , ADD KEY `idx_eb_user` (`eb_user_id`)");
            } catch (Exception $e2) { Logger::error('eb_user_id column: ' . $e2->getMessage()); return; }
        }
        if (class_exists('User')) { try { User::migrate(); } catch (\Throwable $ignore) {} }
        Database::markSchemaDone(__METHOD__);
    }

    /**
     * بررسی لاگین بودن کاربر
     * @return bool
     */
    public static function check()
    {
        return self::currentUser() !== null;
    }

    /**
     * اگر کاربر لاگین نبود، خطای ۴۰۱ بده
     */
    public static function requireLogin()
    {
        if (!self::check()) {
            Response::unauthorized('برای دسترسی به این بخش باید وارد شوید.');
        }
        return self::currentUser();
    }

    /**
     * بررسی نقش کاربر
     *
     * @param string|array $roles  (نقش یا آرایه‌ای از نقش‌های مجاز)
     * @return bool
     */
    public static function hasRole($roles)
    {
        $user = self::currentUser();
        if (!$user) return false;
        $roles = (array)$roles;
        return in_array($user['role'], $roles, true);
    }

    /**
     * اگر کاربر نقش مورد نظر را نداشت، خطای ۴۰۳ بده
     *
     * @param string|array $roles
     * @return array  (اطلاعات کاربر)
     */
    public static function requireRole($roles)
    {
        $user = self::requireLogin();
        if (!self::hasRole($roles)) {
            Response::forbidden('شما دسترسی لازم برای این عملیات را ندارید.');
        }
        return $user;
    }

    /**
     * اگر کاربر ادمین نیست، خطای ۴۰۳ بده
     * @return array
     */
    public static function requireAdmin()
    {
        return self::requireRole(['admin']);
    }

    /**
     * بررسی دسترسی کاربر به یک ماژول/صفحه‌ی مشخص
     *
     * قانون: نقش admin همیشه دسترسی کامل دارد. نقش‌های دیگر (manager،
     * staff) فقط به ماژول‌هایی دسترسی دارند که صراحتاً در permissions
     * آن‌ها فعال شده باشد. اگر لیست permissions کاربر کاملاً خالی باشد
     * (هیچ‌وقت تنظیم نشده)، برای عدم قفل‌شدن ناگهانی حساب‌های قدیمی،
     * دسترسی کامل در نظر گرفته می‌شود (backward compatibility) - فقط
     * وقتی ادمین صریحاً یک لیست (حتی خالی) برای کاربر ست کند، محدودیت
     * واقعی اعمال می‌شود.
     *
     * @param string $permissionKey
     * @return bool
     */
    public static function hasPermission($permissionKey)
    {
        $user = self::currentUser();
        if (!$user) return false;
        if ($user['role'] === 'admin') return true;

        // اگر migration هنوز اجرا نشده (permissions=null به‌جای [])، یعنی
        // کل سیستم دسترسی هنوز فعال نشده - برای جلوگیری از قفل‌شدن ناگهانی
        // همه‌چیز، دسترسی کامل بده
        if (!array_key_exists('permissions', $user) || $user['permissions'] === null) {
            return true;
        }

        return in_array($permissionKey, $user['permissions'], true);
    }

    /**
     * اگر کاربر دسترسی لازم را نداشت، خطای ۴۰۳ بده (برای استفاده در API)
     */
    public static function requirePermission($permissionKey)
    {
        $user = self::requireLogin();
        if (!self::hasPermission($permissionKey)) {
            Response::forbidden('شما دسترسی لازم برای این بخش را ندارید.');
        }
        return $user;
    }

    /**
     * تغییر رمز عبور کاربر جاری
     *
     * @param int    $userId
     * @param string $newPassword
     * @return bool
     */
    public static function changePassword($userId, $newPassword)
    {
        if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
            return false;
        }
        $hash = Helpers::hashPassword($newPassword);
        Database::getInstance()->update(
            'users',
            ['password' => $hash],
            'id = ?',
            [$userId]
        );
        return true;
    }

    /**
     * پاکسازی دوره‌ای توکن‌های منقضی - جایگزین DELETE قبلی که روی هر
     * درخواست اجرا می‌شد. باید از cron_sync.php صدا زده شود (هر ۵ دقیقه
     * کافی است، نیازی به بیشتر نیست).
     *
     * @return int تعداد ردیف‌های پاک‌شده
     */
    public static function purgeExpiredTokens()
    {
        return Database::getInstance()->delete('auth_tokens', 'expires_at < NOW()');
    }

    /**
     * دریافت لیست نقش‌های موجود در سیستم
     * @return array
     */
    public static function getRoles()
    {
        return [
            'admin'   => 'مدیر سیستم',
            'manager' => 'مدیر',
            'staff'   => 'کارشناس',
        ];
    }

    /**
     * ⚠️ محدودسازی دید: «هر کاربر فقط تماس‌های خودش را ببیند مگر سرپرست
     * تیم یا مدیر کل باشد». از ساختار سازمانیِ از قبل موجود
     * (staff.supervisor_id + Staff::getAllSubordinateIds) استفاده می‌شود -
     * یعنی «سرپرست» یعنی کسی که حداقل یک نفر زیرمجموعه‌اش (مستقیم یا
     * غیرمستقیم) در ساختار پرسنل دارد.
     *
     * @return string[]|null  آرایه‌ی شماره‌داخلی‌های قابل‌مشاهده، یا null
     *                        یعنی بدون محدودیت (admin/manager)
     */
    public static function getVisibleExtens()
    {
        $user = self::currentUser();
        if (!$user) return [];
        if (in_array($user['role'], ['admin', 'manager'], true)) return null; // بدون محدودیت

        $extens = [];
        if (!empty($user['exten_number'])) $extens[] = $user['exten_number'];

        $staff = Database::getInstance()->fetchOne('SELECT id, exten_number FROM staff WHERE user_id = ? LIMIT 1', [$user['id']]);
        // ت۷: داخلیِ ثبت‌شده در پرونده‌ی پرسنلی هم حساب می‌شود (اگر در حساب کاربری خالی بود، جدول تماس برای او خالی می‌ماند)
        if ($staff && !empty($staff['exten_number'])) $extens[] = $staff['exten_number'];
        if ($staff) {
            $subIds = Staff::getAllSubordinateIds($staff['id']);
            if (!empty($subIds)) {
                $placeholders = implode(',', array_fill(0, count($subIds), '?'));
                $rows = Database::getInstance()->fetchAll(
                    "SELECT exten_number FROM staff WHERE id IN ({$placeholders}) AND exten_number IS NOT NULL AND exten_number != ''",
                    $subIds
                );
                foreach ($rows as $r) $extens[] = $r['exten_number'];
            }
        }

        return array_values(array_unique($extens));
    }

    /**
     * همان منطق getVisibleExtens ولی برای سرنخ‌ها - چون leads.assignee/
     * operator نام کامل کاربر (full_name) است، نه شماره داخلی.
     *
     * @return string[]|null  آرایه‌ی full_nameهای قابل‌مشاهده، یا null یعنی بدون محدودیت
     */
    public static function getVisibleAssigneeNames()
    {
        $user = self::currentUser();
        if (!$user) return [];
        if (in_array($user['role'], ['admin', 'manager'], true)) return null; // بدون محدودیت

        $names = [$user['full_name']];

        $staff = Database::getInstance()->fetchOne('SELECT id FROM staff WHERE user_id = ? LIMIT 1', [$user['id']]);
        if ($staff) {
            $subIds = Staff::getAllSubordinateIds($staff['id']);
            if (!empty($subIds)) {
                $placeholders = implode(',', array_fill(0, count($subIds), '?'));
                $rows = Database::getInstance()->fetchAll(
                    "SELECT u.full_name FROM staff s INNER JOIN users u ON u.id = s.user_id WHERE s.id IN ({$placeholders})",
                    $subIds
                );
                foreach ($rows as $r) $names[] = $r['full_name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * آیا exten_number دادهشده در محدوده‌ی دید کاربر فعلی هست؟ - برای
     * جلوگیری از دسترسی مستقیم به جزئیات یک تماس با حدس‌زدن id، حتی اگر
     * آن تماس در لیستِ فیلترشده‌اش نشان داده نشود.
     *
     * @param string|null $extenNumber
     * @return bool
     */
    public static function canViewExten($extenNumber)
    {
        $visible = self::getVisibleExtens();
        if ($visible === null) return true; // بدون محدودیت
        if (empty($extenNumber)) return true; // تماس صف/بدون اپراتور برای همه‌ی اپراتورها قابل مشاهده است
        return in_array($extenNumber, $visible, true);
    }
}
