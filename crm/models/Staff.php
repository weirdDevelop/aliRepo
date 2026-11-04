<?php
/**
 * جاکجاست - مدل پرسنل
 * Jakojast - Staff Model
 *
 * مدیریت پرسنل با ساختار سازمانی (supervisor_id خودارجاع)،
 * حضور و غیاب، و اطلاعات شغلی.
 */

class Staff
{
    private static $fillable = [
        'code', 'name', 'role', 'unit', 'supervisor_id', 'status',
        'base_salary', 'avatar', 'mobile', 'email', 'national_code',
        'hire_date', 'exten_number',
    ];

    public static $statuses = ['active', 'leave', 'absent'];
    public static $units = ['مدیریت', 'مالی', 'پشتیبانی', 'فروش', 'میزبانان', 'بازاریابی', 'فنی', 'منابع انسانی'];

    /** @var bool کش وجود ستون user_id */
    private static $userIdColumnChecked = false;

    /**
     * اطمینان از وجود ستون user_id (اتصال هر پرسنل به حساب کاربری ورودش)
     */
    private static function ensureUserIdColumn()
    {
        if (self::$userIdColumnChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$userIdColumnChecked = true; return; }
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT user_id FROM staff LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `staff` ADD COLUMN `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'حساب کاربری متصل (برای ورود به سیستم)' AFTER `id`");
                Logger::info('Auto migration: added user_id column to staff table');
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Staff::ensureUserIdColumn failed: ' . $e->getMessage());
        }
        self::$userIdColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    public static function migrate()
    {
        self::ensureUserIdColumn();
        self::ensureMultiSessionAttendance();
    }

    /**
     * لیست پرسنل با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR code LIKE ? OR mobile LIKE ? OR email LIKE ? OR role LIKE ?)';
            array_push($bind, $search, $search, $search, $search, $search);
        }
        if (!empty($params['unit']) && in_array($params['unit'], self::$units, true)) {
            $where[] = 'unit = ?';
            $bind[] = $params['unit'];
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['supervisor_id'])) {
            $where[] = 'supervisor_id = ?';
            $bind[] = (int)$params['supervisor_id'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = [
            'name' => 'name ASC',
            'code' => 'code ASC',
            'unit' => 'unit ASC',
            'salary' => 'base_salary DESC',
            'date' => 'hire_date DESC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'name'] ?? 'name ASC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM staff {$whereSql}", $bind);

        // JOIN با خودش برای گرفتن نام سرپرست
        $rows = $db->fetchAll(
            "SELECT s.*, sup.name AS supervisor_name
             FROM staff s
             LEFT JOIN staff sup ON sup.id = s.supervisor_id
             {$whereSql}
             ORDER BY {$sort}
             LIMIT {$offset}, {$limit}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['hire_date_jalali'] = $r['hire_date'];
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن پرسنل با ID + اطلاعات سرپرست + زیرمجموعه‌ها
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT s.*, sup.name AS supervisor_name
             FROM staff s
             LEFT JOIN staff sup ON sup.id = s.supervisor_id
             WHERE s.id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;

        // دریافت زیرمجموعه‌ها
        $subordinates = $db->fetchAll(
            'SELECT id, code, name, role, unit, status FROM staff WHERE supervisor_id = ? ORDER BY name',
            [(int)$id]
        );
        $row['subordinates'] = $subordinates;

        return $row;
    }

    /**
     * ایجاد پرسنل جدید
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['name'])) Response::validationError('نام پرسنل الزامی است');
        if (empty($filtered['code'])) Response::validationError('کد پرسنلی الزامی است');

        // بررسی تکراری نبودن کد
        $exists = $db->fetchColumn('SELECT id FROM staff WHERE code = ?', [$filtered['code']]);
        if ($exists) Response::validationError('کد پرسنلی تکراری است');

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizeMobile($filtered['mobile']);
            if ($mobile === false) Response::validationError('موبایل نامعتبر است');
            $filtered['mobile'] = $mobile;
        }

        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        if (!empty($filtered['supervisor_id'])) {
            // بررسی اینکه سرپرست وجود دارد و چرخه ایجاد نمی‌کند
            $filtered['supervisor_id'] = (int)$filtered['supervisor_id'];
            if ($filtered['supervisor_id'] > 0) {
                $sup = self::find($filtered['supervisor_id']);
                if (!$sup) Response::validationError('سرپرست انتخاب شده وجود ندارد');
            } else {
                $filtered['supervisor_id'] = null;
            }
        } else {
            $filtered['supervisor_id'] = null;
        }

        if (empty($filtered['status'])) $filtered['status'] = 'active';
        if (!isset($filtered['base_salary'])) $filtered['base_salary'] = 0;
        if (empty($filtered['hire_date'])) $filtered['hire_date'] = Jalali::today();

        return $db->insert('staff', $filtered);
    }

    /**
     * به‌روزرسانی پرسنل
     */
    public static function update($id, array $data)
    {
        $db = Database::getInstance();
        $existing = self::find($id);
        if (!$existing) Response::notFound('پرسنل یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (!empty($filtered['code'])) {
            $exists = $db->fetchColumn(
                'SELECT id FROM staff WHERE code = ? AND id != ?',
                [$filtered['code'], (int)$id]
            );
            if ($exists) Response::validationError('کد پرسنلی تکراری است');
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizeMobile($filtered['mobile']);
            if ($mobile === false) Response::validationError('موبایل نامعتبر است');
            $filtered['mobile'] = $mobile;
        }

        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        // جلوگیری از چرخه: supervisor_id نباید خود پرسنل یا یکی از زیرمجموعه‌هایش باشد
        if (array_key_exists('supervisor_id', $filtered)) {
            $supId = (int)$filtered['supervisor_id'];
            if ($supId === (int)$id) {
                Response::validationError('پرسنل نمی‌تواند سرپرست خودش باشد');
            }
            if ($supId > 0) {
                // بررسی چرخه: آیا این supervisor_id در زیرمجموعه‌های پرسنل فعلی هست؟
                if (self::isSubordinate($supId, (int)$id)) {
                    Response::validationError('امکان ایجاد چرخه در ساختار سازمانی وجود ندارد');
                }
                $filtered['supervisor_id'] = $supId;
            } else {
                $filtered['supervisor_id'] = null;
            }
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return $db->update('staff', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * بررسی اینکه آیا targetId زیرمجموعه‌ای از staffId است (برای جلوگیری از چرخه)
     */
    private static function isSubordinate($targetId, $staffId)
    {
        $db = Database::getInstance();
        // گرفتن همه زیرمجموعه‌های staffId به‌صورت بازگشتی (با limit ۱۰ سطح برای جلوگیری از infinite loop)
        $visited = [];
        $queue = [$staffId];
        $maxDepth = 10;
        while (!empty($queue) && $maxDepth > 0) {
            $current = array_shift($queue);
            if (in_array($current, $visited, true)) continue;
            $visited[] = $current;
            $children = $db->fetchAll(
                'SELECT id FROM staff WHERE supervisor_id = ?',
                [(int)$current]
            );
            foreach ($children as $c) {
                if ((int)$c['id'] === (int)$targetId) return true;
                $queue[] = (int)$c['id'];
            }
            $maxDepth--;
        }
        return false;
    }

    /**
     * همه‌ی زیرمجموعه‌های یک سرپرست به‌صورت بازگشتی (نه فقط سطح اول) -
     * برای محدودسازی دید («هر کاربر فقط تماس‌ها/سرنخ‌های خودش را ببیند
     * مگر سرپرست یا مدیر کل باشد»). همان الگوی BFS ضدحلقه‌ی isSubordinate.
     *
     * @param int $staffId
     * @return int[]  آرایه‌ی id های زیرمجموعه (بدون خودِ $staffId)
     */
    public static function getAllSubordinateIds($staffId)
    {
        $db = Database::getInstance();
        $visited = [];
        $result = [];
        $queue = [(int)$staffId];
        $maxDepth = 10;
        while (!empty($queue) && $maxDepth > 0) {
            $current = array_shift($queue);
            if (in_array($current, $visited, true)) continue;
            $visited[] = $current;
            $children = $db->fetchAll('SELECT id FROM staff WHERE supervisor_id = ?', [$current]);
            foreach ($children as $c) {
                $cid = (int)$c['id'];
                $result[] = $cid;
                $queue[] = $cid;
            }
            $maxDepth--;
        }
        return $result;
    }

    /**
     * تغییر وضعیت پرسنل
     */
    public static function changeStatus($id, $status)
    {
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('پرسنل یافت نشد');
        return Database::getInstance()->update('staff', ['status' => $status], 'id = ?', [(int)$id]);
    }

    /**
     * حذف پرسنل
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('پرسنل یافت نشد');
        // ابتدا زیرمجموعه‌ها را به null تغییر بده
        Database::getInstance()->update('staff', ['supervisor_id' => null], 'supervisor_id = ?', [(int)$id]);
        return Database::getInstance()->delete('staff', 'id = ?', [(int)$id]);
    }

    /**
     * ساختار درختی سازمانی (org chart)
     */
    public static function getOrgChart()
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT id, code, name, role, unit, supervisor_id, status, exten_number
             FROM staff ORDER BY id'
        );

        if (empty($rows)) return [];

        $map = [];
        foreach ($rows as $r) {
            $map[$r['id']] = [
                'id'           => (int)$r['id'],
                'code'         => $r['code'],
                'name'         => $r['name'],
                'role'         => $r['role'],
                'unit'         => $r['unit'],
                'status'       => $r['status'],
                'exten_number' => $r['exten_number'],
                'supervisor_id'=> $r['supervisor_id'] ? (int)$r['supervisor_id'] : null,
                'children'     => [],
            ];
        }

        $roots = [];
        foreach ($map as $id => &$node) {
            if ($node['supervisor_id'] && isset($map[$node['supervisor_id']])) {
                $map[$node['supervisor_id']]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return $roots;
    }

    /** @var bool کش وضعیت رفع محدودیت یک‌بار در روز */
    private static $attendanceMultiSessionChecked = false;

    /**
     * حذف محدودیت «فقط یک رکورد در روز» از جدول attendance
     *
     * ⚠️ رفع باگ گزارش‌شده: «دو بار ورود/خروج زدم ولی فقط آخری نشون داده
     * شد». علتش این بود که جدول attendance یک UNIQUE KEY روی
     * (staff_id, date) داشت - یعنی دیتابیس اصلاً اجازه نمی‌داد بیش از
     * یک رکورد در روز برای هر نفر ذخیره شود؛ هر بار ثبت جدید، رکورد قبلی
     * همون روز را بازنویسی می‌کرد. الان چند نوبت ورود/خروج در یک روز
     * (مثلاً رفت‌وآمد بین وعده‌های کاری) به‌صورت چند رکورد جدا ذخیره می‌شود.
     */
    private static function ensureMultiSessionAttendance()
    {
        if (self::$attendanceMultiSessionChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$attendanceMultiSessionChecked = true; return; }
        try {
            $db = Database::getInstance();
            $indexes = $db->fetchAll("SHOW INDEX FROM attendance WHERE Key_name = 'uniq_staff_date'");
            if (!empty($indexes)) {
                $db->execute("ALTER TABLE `attendance` DROP INDEX `uniq_staff_date`");
                Logger::info('Auto migration: removed one-record-per-day restriction from attendance table');
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Staff::ensureMultiSessionAttendance failed: ' . $e->getMessage());
        }
        self::$attendanceMultiSessionChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    /**
     * حضور و غیاب روزانه (چند نوبت در روز پشتیبانی می‌شود)
     */
    public static function getAttendance($staffId, $month = null, $year = null)
    {
        self::ensureMultiSessionAttendance();
        $db = Database::getInstance();
        $params = [(int)$staffId];

        $sql = 'SELECT * FROM attendance WHERE staff_id = ?';
        if ($month && $year) {
            // تبدیل ماه شمسی به میلادی برای فیلتر (تقریبی)
            $sql .= ' AND MONTH(date) = ? AND YEAR(date) = ?';
            $params[] = $month;
            $params[] = $year;
        }
        $sql .= ' ORDER BY date DESC, in_time DESC, id DESC LIMIT 200';

        return $db->fetchAll($sql, $params);
    }

    /**
     * وضعیت لحظه‌ای پرسنل: آیا الان یک نوبت کاری باز دارد (ورود زده،
     * هنوز خروج نزده)؟ برای تعیین اینکه دکمه‌ی «ورود» نشان داده شود یا «خروج»
     *
     * @param int $staffId
     * @return array|null  رکورد نوبت باز، یا null اگر نوبت بازی نیست
     */
    public static function getOpenAttendanceSession($staffId)
    {
        self::ensureMultiSessionAttendance();
        return Database::getInstance()->fetchOne(
            "SELECT * FROM attendance WHERE staff_id = ? AND date = CURDATE() AND in_time IS NOT NULL AND out_time IS NULL ORDER BY id DESC LIMIT 1",
            [(int)$staffId]
        );
    }

    /**
     * ثبت ورود/خروج - هر «ورود» یک نوبت جدید می‌سازد؛ هر «خروج» آخرین
     * نوبت باز همان روز را می‌بندد.
     */
    public static function logAttendance($staffId, $type, $time = null)
    {
        self::ensureMultiSessionAttendance();
        $db = Database::getInstance();
        $existing = self::find($staffId);
        if (!$existing) Response::notFound('پرسنل یافت نشد');

        $today = date('Y-m-d');
        $time = $time ?: date('H:i:s');

        if ($type === 'in') {
            // همیشه یک نوبت جدید بساز (نه بازنویسی روی نوبت قبلی)
            $openSession = self::getOpenAttendanceSession($staffId);
            if ($openSession) {
                Response::validationError('یک نوبت کاری باز دارید که هنوز خروج نزده‌اید. اول خروج بزنید.');
            }
            return $db->insert('attendance', [
                'staff_id' => (int)$staffId,
                'date'     => $today,
                'in_time'  => $time,
                'status'   => 'present',
            ]);
        } else {
            // بستن آخرین نوبت باز همان روز
            $openSession = self::getOpenAttendanceSession($staffId);
            if ($openSession) {
                return $db->update('attendance', ['out_time' => $time], 'id = ?', [$openSession['id']]);
            }
            // اگر نوبت بازی نبود (مثلاً یادش رفته ورود بزند)، یک رکورد
            // فقط با خروج ثبت می‌شود تا داده گم نشود
            return $db->insert('attendance', [
                'staff_id' => (int)$staffId,
                'date'     => $today,
                'out_time' => $time,
                'status'   => 'present',
                'note'     => 'خروج بدون ثبت ورود قبلی',
            ]);
        }
    }

    /**
     * تیکت‌های پرسنل
     */
    public static function getTickets($staffId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, title, type, priority, status, dept, date, last_reply
             FROM tickets WHERE staff_id = ? ORDER BY created_at DESC',
            [(int)$staffId]
        );
    }

    /**
     * تسک‌های پرسنل
     */
    public static function getTasks($staffId)
    {
        $db = Database::getInstance();
        // staff.name به عنوان assignee در tasks
        $staff = self::find($staffId);
        if (!$staff) return [];
        return $db->fetchAll(
            'SELECT id, title, priority, status, project, start_date, end_date
             FROM tasks WHERE assignee = ?
             ORDER BY FIELD(status, "new","in_progress","review","done","cancelled"), end_date ASC',
            [$staff['name']]
        );
    }

    /**
     * آمار پرسنل
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM staff GROUP BY status');
        foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT unit, COUNT(*) AS cnt FROM staff GROUP BY unit');
        foreach ($rows as $r) $stats['by_unit'][$r['unit']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff');
        $stats['total_salary'] = (int)$db->fetchColumn('SELECT COALESCE(SUM(base_salary), 0) FROM staff WHERE status = "active"');
        $stats['avg_salary'] = $stats['total'] > 0
            ? (int)($stats['total_salary'] / $stats['total'])
            : 0;

        return $stats;
    }
}
