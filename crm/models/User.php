<?php
/**
 * جاکجاست - مدل کاربران سیستم (حساب‌های ورود)
 * Jakojast - User Accounts Model
 *
 * قبلاً هیچ رابط مدیریتی برای کاربران (جدول users) وجود نداشت؛ فقط از
 * طریق seed.php ساخته می‌شدند. این مدل امکان مدیریت کامل کاربران و
 * دسترسی‌های هرکدام را از پنل تنظیمات فراهم می‌کند.
 *
 * سیستم دسترسی: هر کاربر (جز admin که همیشه دسترسی کامل دارد) یک لیست
 * از «کلیدهای دسترسی» دارد (ستون permissions، JSON) که مشخص می‌کند به
 * کدام صفحات/ماژول‌ها اجازه‌ی ورود دارد. نگاه کنید به index.php برای
 * نحوه‌ی اعمال این دسترسی‌ها هنگام روتینگ صفحات.
 */

class User
{
    private static $fillable = ['username', 'full_name', 'email', 'role', 'exten_number', 'is_active'];

    /** کلیدهای دسترسی قابل تعریف - باید با نگاشت صفحات در index.php هماهنگ باشد */
    public static $permissionKeys = [
        'dashboard'    => 'داشبورد اجرایی',
        'customers'    => 'مشتریان',
        'leads'        => 'سرنخ‌های فروش',
        'hosts'        => 'میزبانان',
        'host_leads'   => 'جذب میزبان',
        'staff'        => 'پرسنل',
        'leaves'       => 'مرخصی‌ها',
        'payroll'      => 'حقوق و دستمزد',
        'tickets'      => 'پشتیبانی',
        'chat'         => 'چت داخلی',
        'tasks'        => 'تسک‌ها و پروژه‌ها',
        'reports'      => 'گزارشات',
        'calls'        => 'تماس‌ها',
        'settings'     => 'تنظیمات',
    ];

    /** @var bool کش وجود ستون permissions */
    private static $permColumnChecked = false;

    /**
     * اطمینان از وجود ستون permissions (اضافه‌ی خودکار در صورت نبود)
     */
    private static function ensurePermissionsColumn()
    {
        if (self::$permColumnChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$permColumnChecked = true; return; }
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT permissions FROM users LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `users` ADD COLUMN `permissions` TEXT DEFAULT NULL COMMENT 'لیست کلیدهای دسترسی (JSON) - برای role غیر از admin' AFTER `role`");
                Logger::info('Auto migration: added permissions column to users table');
            }
            try {
                $db->fetchColumn("SELECT last_activity FROM users LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `users` ADD COLUMN `last_activity` DATETIME DEFAULT NULL COMMENT 'آخرین درخواست موفق - برای وضعیت آنلاین/آفلاین' AFTER `last_login`");
                Logger::info('Auto migration: added last_activity column to users table');
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('User::ensurePermissionsColumn failed: ' . $e->getMessage());
        }
        self::$permColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    /**
     * ثبت فعالیت کاربر (برای وضعیت آنلاین) - throttle شده در خودِ کوئری
     * (فقط اگر بیش از ۳۰ ثانیه از آخرین ثبت گذشته باشد UPDATE واقعی انجام
     * می‌شود) تا هر درخواست API باعث نوشتن روی دیتابیس نشود.
     *
     * @param int $userId
     */
    public static function touchActivity($userId)
    {
        try {
            Database::getInstance()->execute(
                "UPDATE users SET last_activity = NOW() WHERE id = ? AND (last_activity IS NULL OR last_activity < NOW() - INTERVAL 30 SECOND)",
                [(int)$userId]
            );
        } catch (\Throwable $e) {
            // ستون شاید هنوز از طریق migrate() ساخته نشده - بی‌اهمیت، دفعه‌ی بعد درست می‌شود
        }
    }

    /**
     * وضعیت آنلاین/آفلاین همه‌ی کاربران فعال (برای «اپراتورهای آنلاین»)
     * آستانه‌ی آنلاین بودن: فعالیت در ۳ دقیقه‌ی اخیر
     *
     * @return array هر آیتم: {id, full_name, username, role, exten_number, is_online, last_activity_jalali}
     */
    public static function getOnlineStatus()
    {
        self::ensurePermissionsColumn();
        $rows = Database::getInstance()->fetchAll(
            "SELECT id, username, full_name, role, exten_number, last_activity
             FROM users WHERE is_active = 1 ORDER BY full_name ASC"
        );
        foreach ($rows as &$r) {
            $r['is_online'] = !empty($r['last_activity']) && strtotime($r['last_activity']) >= (time() - 180);
            $r['last_activity_jalali'] = $r['last_activity'] ? Jalali::fromGregorian($r['last_activity'], 'Y/m/d H:i') : null;
        }
        // آنلاین‌ها اول
        usort($rows, function ($a, $b) { return $b['is_online'] <=> $a['is_online']; });
        return $rows;
    }

    /**
     * اجرای دستی migration (هم برای دکمه‌ی تنظیمات، هم fallback خودکار
     * در Auth::currentUser اگر ستون permissions هنوز ساخته نشده باشد)
     */
    public static function migrate()
    {
        self::ensurePermissionsColumn();
    }

    /**
     * ایجاد خودکار حساب کاربری برای یک پرسنل (طبق درخواست: «همه‌ی پرسنل
     * تبدیل به کاربر اسکریپت بشن»). نام کاربری از کد پرسنلی ساخته می‌شود
     * (اگر تکراری بود، شماره اضافه می‌شود)، رمز عبور تصادفی ۶ رقمی است.
     *
     * ⚠️ رمز فقط همین یک‌بار در خروجی این متد قابل مشاهده است (چون بعد از
     * ذخیره فقط هش آن نگه‌داری می‌شود) - باید همان لحظه به مدیر نمایش
     * داده و توسط او به پرسنل اطلاع داده شود.
     *
     * @param int $staffId
     * @return array {user_id, username, password, staff_name}
     */
    public static function createForStaff($staffId)
    {
        self::ensurePermissionsColumn();
        $db = Database::getInstance();

        $staff = Staff::find($staffId);
        if (!$staff) Response::notFound('پرسنل یافت نشد');

        if (!empty($staff['user_id'])) {
            $existing = self::find($staff['user_id']);
            if ($existing) {
                return ['user_id' => $existing['id'], 'username' => $existing['username'], 'password' => null, 'staff_name' => $staff['name'], 'already_existed' => true];
            }
        }

        // ساخت username از کد پرسنلی (یا از نام اگر کد نداشت)
        $baseUsername = !empty($staff['code']) ? preg_replace('/[^a-zA-Z0-9]/', '', $staff['code']) : self::transliterateFallback($staff['name']);
        $baseUsername = strtolower($baseUsername ?: ('staff' . $staffId));

        $username = $baseUsername;
        $suffix = 1;
        while ($db->fetchColumn('SELECT id FROM users WHERE username = ?', [$username])) {
            $suffix++;
            $username = $baseUsername . $suffix;
        }

        $password = (string)random_int(100000, 999999);

        $userId = self::create([
            'username'     => $username,
            'password'     => $password,
            'full_name'    => $staff['name'],
            'role'         => 'staff',
            'exten_number' => $staff['exten_number'] ?? null,
            'permissions'  => [], // ادمین باید بعداً دسترسی‌ها را تعیین کند
        ]);

        Staff::migrate(); // اطمینان از وجود ستون user_id قبل از UPDATE
        $db->update('staff', ['user_id' => $userId], 'id = ?', [(int)$staffId]);

        return ['user_id' => $userId, 'username' => $username, 'password' => $password, 'staff_name' => $staff['name'], 'already_existed' => false];
    }

    /**
     * ایجاد خودکار حساب کاربری برای همه‌ی پرسنلی که هنوز حساب ندارند
     *
     * @return array  لیست {staff_name, username, password} برای نمایش یک‌باره به ادمین
     */
    public static function bulkCreateForAllStaff()
    {
        $allStaff = Staff::all(['limit' => 1000])['items'] ?? [];
        $results = [];
        foreach ($allStaff as $s) {
            if (!empty($s['user_id'])) continue; // قبلاً حساب دارد
            try {
                $result = self::createForStaff($s['id']);
                if (!$result['already_existed']) $results[] = $result;
            } catch (\Throwable $e) {
                Logger::error('Bulk user creation failed for staff ' . $s['id'] . ': ' . $e->getMessage());
            }
        }
        return $results;
    }

    /**
     * تبدیل نام فارسی به یک رشته‌ی لاتین ساده برای username (fallback وقتی کد پرسنلی نیست)
     */
    private static function transliterateFallback($name)
    {
        // ساده‌سازی: فقط حروف/عدد لاتین احتمالی در نام را نگه می‌دارد؛ اگر
        // چیزی نماند، از یک رشته‌ی تصادفی استفاده می‌شود (نام فارسی است)
        $ascii = preg_replace('/[^a-zA-Z0-9]/', '', $name);
        return $ascii ?: ('user' . random_int(1000, 9999));
    }

    /**
     * لیست همه‌ی کاربران
     */
    public static function all()
    {
        self::ensurePermissionsColumn();
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, username, full_name, email, role, exten_number, permissions, is_active, last_login, created_at
             FROM users ORDER BY id ASC'
        );
        foreach ($rows as &$r) {
            $r['permissions'] = self::decodePermissions($r['permissions']);
        }
        return $rows;
    }

    public static function find($id)
    {
        self::ensurePermissionsColumn();
        $row = Database::getInstance()->fetchOne(
            'SELECT id, username, full_name, email, role, exten_number, permissions, is_active, last_login, created_at
             FROM users WHERE id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;
        $row['permissions'] = self::decodePermissions($row['permissions']);
        return $row;
    }

    private static function decodePermissions($json)
    {
        if (empty($json)) return [];
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * ایجاد کاربر جدید (حساب ورود)
     *
     * @param array $data {username, password, full_name, email, role, exten_number, permissions}
     * @return int  شناسه‌ی کاربر ایجادشده
     */
    public static function create(array $data)
    {
        self::ensurePermissionsColumn();
        $db = Database::getInstance();

        if (empty($data['username']) || empty($data['password']) || empty($data['full_name'])) {
            Response::validationError('نام کاربری، رمز عبور و نام کامل الزامی است');
        }

        $exists = $db->fetchColumn('SELECT id FROM users WHERE username = ?', [$data['username']]);
        if ($exists) Response::validationError('این نام کاربری قبلاً استفاده شده است');

        if (strlen($data['password']) < MIN_PASSWORD_LENGTH) {
            Response::validationError('رمز عبور باید حداقل ' . MIN_PASSWORD_LENGTH . ' کاراکتر باشد');
        }

        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];
        $validKeys = array_keys(self::$permissionKeys);
        $permissions = array_values(array_intersect($permissions, $validKeys));

        $id = $db->insert('users', [
            'username'     => trim($data['username']),
            'password'     => password_hash($data['password'], PASSWORD_BCRYPT),
            'full_name'    => trim($data['full_name']),
            'email'        => $data['email'] ?? null,
            'role'         => in_array($data['role'] ?? '', ['admin', 'manager', 'staff'], true) ? $data['role'] : 'staff',
            'exten_number' => $data['exten_number'] ?? null,
            'permissions'  => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'is_active'    => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);

        return (int)$id;
    }

    /**
     * به‌روزرسانی کاربر (شامل تغییر دسترسی‌ها)
     */
    public static function update($id, array $data)
    {
        self::ensurePermissionsColumn();
        $db = Database::getInstance();
        $existing = self::find($id);
        if (!$existing) Response::notFound('کاربر یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (!empty($filtered['username'])) {
            $dup = $db->fetchColumn('SELECT id FROM users WHERE username = ? AND id != ?', [$filtered['username'], (int)$id]);
            if ($dup) Response::validationError('این نام کاربری قبلاً استفاده شده است');
        }

        if (array_key_exists('permissions', $data)) {
            $permissions = is_array($data['permissions']) ? $data['permissions'] : [];
            $validKeys = array_keys(self::$permissionKeys);
            $filtered['permissions'] = json_encode(array_values(array_intersect($permissions, $validKeys)), JSON_UNESCAPED_UNICODE);
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < MIN_PASSWORD_LENGTH) {
                Response::validationError('رمز عبور باید حداقل ' . MIN_PASSWORD_LENGTH . ' کاراکتر باشد');
            }
            $filtered['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return $db->update('users', $filtered, 'id = ?', [(int)$id]);
    }

    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('کاربر یافت نشد');
        if ($existing['role'] === 'admin') {
            $adminCount = (int)Database::getInstance()->fetchColumn("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            if ($adminCount <= 1) {
                Response::validationError('امکان حذف آخرین کاربر مدیر سیستم وجود ندارد');
            }
        }
        return Database::getInstance()->delete('users', 'id = ?', [(int)$id]);
    }
}
