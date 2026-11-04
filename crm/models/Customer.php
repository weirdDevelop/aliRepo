<?php
/**
 * جاکجاست - مدل مشتری
 * Jakojast - Customer Model
 *
 * عملیات CRUD روی جدول customers + یادداشت‌های مشتری.
 */

class Customer
{
    /** @var array ستون‌های قابل پر شدن mass-assignable */
    private static $fillable = [
        'name', 'mobile', 'phone', 'email', 'city', 'address', 'level',
        'reservations', 'total_spend', 'last_purchase', 'last_call_at',
        'status', 'join_date', 'score', 'avatar', 'birthday', 'notes',
        // فیلدهای سبک یاس مبله + همگام‌سازی سایت
        'favorite_day', 'loyalty_percent', 'avg_purchase', 'preferred_channel',
        'site_user_id',
    ];

    private static $yasMigrated = false;

    /**
     * ستون‌های تحلیلی مشتری (روز محبوب، وفاداری، میانگین خرید، ...)
     */
    public static function ensureYasColumns()
    {
        if (self::$yasMigrated) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$yasMigrated = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            $cols = [
                'favorite_day' => "ALTER TABLE `customers` ADD COLUMN `favorite_day` VARCHAR(20) DEFAULT NULL COMMENT 'روز محبوب خرید/رزرو' AFTER `notes`",
                'loyalty_percent' => "ALTER TABLE `customers` ADD COLUMN `loyalty_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'درصد وفاداری' AFTER `favorite_day`",
                'avg_purchase' => "ALTER TABLE `customers` ADD COLUMN `avg_purchase` BIGINT NOT NULL DEFAULT 0 COMMENT 'میانگین مبلغ خرید' AFTER `loyalty_percent`",
                'preferred_channel' => "ALTER TABLE `customers` ADD COLUMN `preferred_channel` VARCHAR(50) DEFAULT NULL COMMENT 'کانال ترجیحی' AFTER `avg_purchase`",
                'site_user_id' => "ALTER TABLE `customers` ADD COLUMN `site_user_id` VARCHAR(64) DEFAULT NULL COMMENT 'شناسه کاربر در سایت' AFTER `preferred_channel`",
            ];
            foreach ($cols as $col => $sql) {
                try {
                    $db->fetchColumn("SELECT `{$col}` FROM customers LIMIT 1");
                } catch (Exception $e) {
                    $db->execute($sql);
                    $added[] = "customers.{$col}";
                }
            }
            try {
                $db->fetchColumn("SELECT site_user_id FROM customers WHERE site_user_id IS NOT NULL LIMIT 1");
            } catch (Exception $e) { /* ignore */ }
            try {
                $db->execute("ALTER TABLE `customers` ADD INDEX `idx_site_user` (`site_user_id`)");
            } catch (Exception $e) { /* already exists */ }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Customer::ensureYasColumns failed: ' . $e->getMessage());
            self::$yasMigrated = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$yasMigrated = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    public static function migrate()
    {
        return self::ensureYasColumns();
    }

    /** @var array سطح‌های مجاز */
    public static $levels = ['عادی', 'برنز', 'نقره‌ای', 'طلایی', 'VIP'];

    /** @var array وضعیت‌های مجاز */
    public static $statuses = ['active', 'inactive', 'blocked'];

    /**
     * دریافت لیست مشتریان با فیلتر و صفحه‌بندی
     *
     * @param array $params  {search, status, level, city, sort, page, limit}
     * @return array  {items, total}
     */
    public static function all(array $params = [])
    {
        self::ensureYasColumns();
        $db = Database::getInstance();

        $where = [];
        $bind = [];

        // جستجو
        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR mobile LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $bind[] = $search; $bind[] = $search; $bind[] = $search; $bind[] = $search;
        }
        // فیلتر وضعیت
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        // فیلتر سطح
        if (!empty($params['level']) && in_array($params['level'], self::$levels, true)) {
            $where[] = 'level = ?';
            $bind[] = $params['level'];
        }
        // فیلتر شهر
        if (!empty($params['city'])) {
            $where[] = 'city LIKE ?';
            $bind[] = '%' . $params['city'] . '%';
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        // مرتب‌سازی
        $sortMap = [
            'name' => 'name ASC',
            'reservations' => 'reservations DESC',
            'spend' => 'total_spend DESC',
            'date' => 'created_at DESC',
            'score' => 'score DESC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date'] ?? 'created_at DESC';

        // صفحه‌بندی
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // شمارش کل
        $total = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM customers {$whereSql}",
            $bind
        );

        // دریافت آیتم‌ها
        $rows = $db->fetchAll(
            "SELECT * FROM customers {$whereSql} ORDER BY {$sort} LIMIT {$offset}, {$limit}",
            $bind
        );

        // تبدیل تاریخ‌ها به شمسی برای نمایش
        foreach ($rows as &$r) {
            $r['last_call_jalali'] = !empty($r['last_call_at'])
                ? Jalali::fromGregorian($r['last_call_at'], 'Y/m/d H:i')
                : '-';
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * دریافت یک مشتری با ID
     *
     * @param int $id
     * @return array|null
     */
    public static function find($id)
    {
        self::ensureYasColumns();
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT * FROM customers WHERE id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;

        $row['last_call_jalali'] = !empty($row['last_call_at'])
            ? Jalali::fromGregorian($row['last_call_at'], 'Y/m/d H:i')
            : '-';
        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d');

        return $row;
    }

    /**
     * ایجاد مشتری جدید
     *
     * @param array $data
     * @return int  (ID جدید)
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();

        // فیلتر فقط فیلدهای مجاز
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) {
                $filtered[$f] = $data[$f];
            }
        }

        // اعتبارسنجی
        if (empty($filtered['name'])) {
            Response::validationError('نام مشتری الزامی است');
        }

        // نرمال‌سازی شماره تماس (موبایل یا ثابت — برای همگام‌سازی سایت)
        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) {
                Response::validationError('شماره تماس نامعتبر است');
            }
            $filtered['mobile'] = $mobile;
        }

        // اعتبارسنجی ایمیل
        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        // بررسی تکراری بودن موبایل
        if (!empty($filtered['mobile'])) {
            $exists = $db->fetchColumn(
                'SELECT id FROM customers WHERE mobile = ? LIMIT 1',
                [$filtered['mobile']]
            );
            if ($exists) {
                Response::validationError('مشتری با این شماره موبایل قبلاً ثبت شده است');
            }
        }

        // تنظیم پیش‌فرض‌ها
        if (empty($filtered['level'])) $filtered['level'] = 'عادی';
        if (empty($filtered['status'])) $filtered['status'] = 'active';
        if (empty($filtered['join_date'])) $filtered['join_date'] = Jalali::today();
        if (empty($filtered['reservations'])) $filtered['reservations'] = 0;
        if (empty($filtered['total_spend'])) $filtered['total_spend'] = 0;
        if (empty($filtered['score'])) $filtered['score'] = 0;

        return $db->insert('customers', $filtered);
    }

    /**
     * به‌روزرسانی مشتری
     *
     * @param int   $id
     * @param array $data
     * @return int  (تعداد رکوردهای به‌روزرسانی شده)
     */
    public static function update($id, array $data)
    {
        $db = Database::getInstance();

        // بررسی وجود
        $existing = self::find($id);
        if (!$existing) {
            Response::notFound('مشتری یافت نشد');
        }

        // فیلتر فیلدهای مجاز
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) {
                $filtered[$f] = $data[$f];
            }
        }

        // نرمال‌سازی شماره تماس (موبایل یا ثابت)
        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) {
                Response::validationError('شماره تماس نامعتبر است');
            }
            $filtered['mobile'] = $mobile;

            // بررسی تکراری بودن با مشتری دیگر
            $exists = $db->fetchColumn(
                'SELECT id FROM customers WHERE mobile = ? AND id != ? LIMIT 1',
                [$filtered['mobile'], (int)$id]
            );
            if ($exists) {
                Response::validationError('مشتری دیگری با این موبایل وجود دارد');
            }
        }

        // اعتبارسنجی ایمیل
        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        if (empty($filtered)) {
            Response::validationError('داده‌ای برای به‌روزرسانی ارسال نشده است');
        }

        return $db->update('customers', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * حذف نرم مشتری (status = blocked)
     *
     * @param int $id
     * @return int
     */
    public static function softDelete($id)
    {
        $existing = self::find($id);
        if (!$existing) {
            Response::notFound('مشتری یافت نشد');
        }
        return Database::getInstance()->update(
            'customers',
            ['status' => 'blocked'],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * حذف واقعی مشتری
     *
     * @param int $id
     * @return int
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) {
            Response::notFound('مشتری یافت نشد');
        }
        return Database::getInstance()->delete(
            'customers',
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * دریافت یادداشت‌های مشتری
     *
     * @param int $customerId
     * @return array
     */
    public static function getNotes($customerId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT n.*, u.full_name AS user_name
             FROM customer_notes n
             LEFT JOIN users u ON u.id = n.user_id
             WHERE n.customer_id = ?
             ORDER BY n.created_at DESC',
            [(int)$customerId]
        );
    }

    /**
     * افزودن یادداشت به مشتری
     *
     * @param int    $customerId
     * @param int    $userId
     * @param string $text
     * @return int  (ID یادداشت)
     */
    public static function addNote($customerId, $userId, $text)
    {
        $existing = self::find($customerId);
        if (!$existing) {
            Response::notFound('مشتری یافت نشد');
        }
        if (empty($text)) {
            Response::validationError('متن یادداشت الزامی است');
        }

        // پاک‌سازی متن
        $text = Helpers::sanitizeString($text);

        return Database::getInstance()->insert('customer_notes', [
            'customer_id' => (int)$customerId,
            'user_id'     => $userId,
            'text'        => $text,
        ]);
    }

    /**
     * دریافت تیکت‌های مشتری
     *
     * @param int $customerId
     * @return array
     */
    public static function getTickets($customerId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, title, type, priority, status, dept, assignee, date, last_reply
             FROM tickets
             WHERE customer_id = ?
             ORDER BY created_at DESC',
            [(int)$customerId]
        );
    }

    /**
     * دریافت تماس‌های مشتری (بر اساس شماره موبایل)
     *
     * @param int $customerId
     * @return array
     */
    /**
     * دریافت همه‌ی تماس‌های یک مشتری بر اساس شماره موبایل
     *
     * ⚠️ رفع مشکل «یکسان‌سازی تماس‌ها»: قبلاً با تطبیق دقیق رشته‌ای
     * (call_source = mobile) کار می‌کرد که اگر شماره در تماس با پیشوند
     * متفاوت ذخیره شده بود (مثلاً 98912... به‌جای 0912...)، آن تماس اصلاً
     * در تاریخچه‌ی مشتری نشان داده نمی‌شد. الان بر اساس ۱۰ رقم آخر شماره
     * مقایسه می‌شود که مستقل از پیشوند (0/98/+98/0098) است.
     *
     * @param int $customerId
     * @return array
     */
    public static function getCalls($customerId)
    {
        $customer = self::find($customerId);
        if (!$customer || empty($customer['mobile'])) return [];

        $last10 = substr(preg_replace('/\D+/', '', $customer['mobile']), -10);
        if (strlen($last10) < 10) return [];

        return Database::getInstance()->fetchAll(
            "SELECT c.id, c.cuid, c.call_source, c.call_dest, c.type, c.status, c.time_wait, c.time_talk,
                    c.started_at, c.jalali_started_at, c.exten_name, c.file_record, c.lead_id,
                    s.name AS staff_name
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             WHERE RIGHT(c.call_source, 10) = ? OR RIGHT(c.call_dest, 10) = ?
             ORDER BY c.started_at DESC
             LIMIT 100",
            [$last10, $last10]
        );
    }

    /**
     * آمار کلی مشتریان
     *
     * @return array
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        // تعداد کل به تفکیک وضعیت
        $rows = $db->fetchAll(
            'SELECT status, COUNT(*) AS cnt FROM customers GROUP BY status'
        );
        foreach ($rows as $r) {
            $stats['by_status'][$r['status']] = (int)$r['cnt'];
        }

        // تعداد به تفکیک سطح
        $rows = $db->fetchAll(
            'SELECT level, COUNT(*) AS cnt FROM customers GROUP BY level'
        );
        foreach ($rows as $r) {
            $stats['by_level'][$r['level']] = (int)$r['cnt'];
        }

        // مجموع خرید
        $stats['total_spend'] = (int)$db->fetchColumn(
            'SELECT COALESCE(SUM(total_spend), 0) FROM customers'
        );

        // مجموع رزروها
        $stats['total_reservations'] = (int)$db->fetchColumn(
            'SELECT COALESCE(SUM(reservations), 0) FROM customers'
        );

        return $stats;
    }

    /**
     * یافتن مشتری بر اساس شماره موبایل
     *
     * @param string $mobile
     * @return array|null
     */
    public static function findByMobile($mobile)
    {
        $mobile = Helpers::sanitizeMobile($mobile);
        if ($mobile === false) return null;

        return Database::getInstance()->fetchOne(
            'SELECT * FROM customers WHERE mobile = ? LIMIT 1',
            [$mobile]
        );
    }

    /**
     * به‌روزرسانی آخرین تماس مشتری
     *
     * @param int       $id
     * @param string    $datetime  (Y-m-d H:i:s)
     * @return int
     */
    public static function updateLastCall($id, $datetime = null)
    {
        return Database::getInstance()->update(
            'customers',
            ['last_call_at' => $datetime ?: date('Y-m-d H:i:s')],
            'id = ?',
            [(int)$id]
        );
    }
}
