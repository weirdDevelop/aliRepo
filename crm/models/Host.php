<?php
/**
 * جاکجاست - مدل میزبان
 * Jakojast - Host Model
 */

class Host
{
    private static $fillable = [
        'name', 'mobile', 'email', 'national_code', 'sheba', 'city',
        'properties', 'total_revenue', 'status', 'rating', 'level',
        'join_date', 'avatar', 'notes', 'site_host_id',
    ];

    public static $statuses = ['active', 'inactive', 'pending', 'blocked'];
    public static $levels = ['برنز', 'نقره‌ای', 'طلایی', 'VIP'];

    private static $siteColMigrated = false;

    public static function migrate()
    {
        if (self::$siteColMigrated) return ['added' => [], 'error' => null];
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn('SELECT site_host_id FROM hosts LIMIT 1');
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `hosts` ADD COLUMN `site_host_id` VARCHAR(64) DEFAULT NULL COMMENT 'شناسه میزبان در سایت' AFTER `notes`");
                try { $db->execute("ALTER TABLE `hosts` ADD INDEX `idx_site_host` (`site_host_id`)"); } catch (Exception $e2) {}
                $added[] = 'hosts.site_host_id';
            }
        } catch (Exception $e) {
            self::$siteColMigrated = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$siteColMigrated = true;
        return ['added' => $added, 'error' => null];
    }

    /**
     * لیست میزبانان با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        self::migrate();
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR mobile LIKE ? OR email LIKE ? OR national_code LIKE ? OR city LIKE ?)';
            array_push($bind, $search, $search, $search, $search, $search);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['level']) && in_array($params['level'], self::$levels, true)) {
            $where[] = 'level = ?';
            $bind[] = $params['level'];
        }
        if (!empty($params['city'])) {
            $where[] = 'city LIKE ?';
            $bind[] = '%' . $params['city'] . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = [
            'name' => 'name ASC',
            'revenue' => 'total_revenue DESC',
            'rating' => 'rating DESC',
            'date' => 'created_at DESC',
            'properties' => 'properties DESC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date'] ?? 'created_at DESC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM hosts {$whereSql}",
            $bind
        );

        $rows = $db->fetchAll(
            "SELECT * FROM hosts {$whereSql} ORDER BY {$sort} LIMIT {$offset}, {$limit}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }

        // مانده‌ی هر میزبان (کل درآمد منهای پرداخت‌شده) - برای نمایش
        // سریع در کارت لیست بدون نیاز به باز کردن پروفایل هر میزبان
        if (!empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $paidRows = $db->fetchAll(
                "SELECT host_id, COALESCE(SUM(amount), 0) AS paid
                 FROM host_settlements WHERE host_id IN ({$placeholders}) AND status = 'paid'
                 GROUP BY host_id",
                $ids
            );
            $paidMap = array_column($paidRows, 'paid', 'host_id');
            foreach ($rows as &$r) {
                $paid = (int)($paidMap[$r['id']] ?? 0);
                $r['balance'] = max(0, (int)($r['total_revenue'] ?? 0) - $paid);
            }
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن میزبان با ID
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT * FROM hosts WHERE id = ?', [(int)$id]);
        if (!$row) return null;

        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d');
        return $row;
    }

    /**
     * ایجاد میزبان جدید
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['name'])) {
            Response::validationError('نام میزبان الزامی است');
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) {
                Response::validationError('شماره تماس نامعتبر است');
            }
            $filtered['mobile'] = $mobile;
            // بررسی تکراری نبودن
            $exists = $db->fetchColumn('SELECT id FROM hosts WHERE mobile = ?', [$filtered['mobile']]);
            if ($exists) {
                Response::validationError('میزبان با این موبایل قبلاً ثبت شده است');
            }
        }

        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        // پیش‌فرض‌ها
        if (empty($filtered['status'])) $filtered['status'] = 'pending';
        if (empty($filtered['level'])) $filtered['level'] = 'برنز';
        if (empty($filtered['join_date'])) $filtered['join_date'] = Jalali::today();
        if (!isset($filtered['properties'])) $filtered['properties'] = 0;
        if (!isset($filtered['total_revenue'])) $filtered['total_revenue'] = 0;
        if (!isset($filtered['rating'])) $filtered['rating'] = 0;

        return $db->insert('hosts', $filtered);
    }

    /**
     * به‌روزرسانی میزبان
     */
    public static function update($id, array $data)
    {
        $db = Database::getInstance();
        $existing = self::find($id);
        if (!$existing) Response::notFound('میزبان یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) Response::validationError('شماره تماس نامعتبر است');
            $filtered['mobile'] = $mobile;
            $exists = $db->fetchColumn(
                'SELECT id FROM hosts WHERE mobile = ? AND id != ?',
                [$filtered['mobile'], (int)$id]
            );
            if ($exists) Response::validationError('میزبان دیگری با این شماره وجود دارد');
        }

        if (!empty($filtered['email']) && !Helpers::validateEmail($filtered['email'])) {
            Response::validationError('ایمیل نامعتبر است');
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return $db->update('hosts', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * تغییر وضعیت میزبان (تأیید/مسدود/غیرفعال)
     */
    public static function changeStatus($id, $status)
    {
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('میزبان یافت نشد');

        return Database::getInstance()->update(
            'hosts',
            ['status' => $status],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * حذف میزبان
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('میزبان یافت نشد');
        return Database::getInstance()->delete('hosts', 'id = ?', [(int)$id]);
    }

    /**
     * خلاصه‌ی مالی میزبان: کل درآمد، جمع پرداخت‌شده، مانده
     *
     * ⚠️ رفع کمبود: قبلاً تسویه‌حساب‌ها از دیتابیس خوانده می‌شدند
     * (Host::getSettlements) ولی هیچ‌جا در پروفایل میزبان نمایش داده
     * نمی‌شدند و «مانده» اصلاً محاسبه نمی‌شد.
     *
     * @param int $hostId
     * @return array {total_revenue, total_paid, total_pending, balance}
     */
    public static function getBalanceSummary($hostId)
    {
        $host = self::find($hostId);
        if (!$host) Response::notFound('میزبان یافت نشد');

        $db = Database::getInstance();
        $totalPaid = (int)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM host_settlements WHERE host_id = ? AND status = 'paid'",
            [(int)$hostId]
        );
        $totalPending = (int)$db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM host_settlements WHERE host_id = ? AND status = 'pending'",
            [(int)$hostId]
        );
        $totalRevenue = (int)($host['total_revenue'] ?? 0);

        return [
            'total_revenue' => $totalRevenue,
            'total_paid'    => $totalPaid,
            'total_pending' => $totalPending,
            // مانده = چیزی که از کل درآمد هنوز پرداخت نشده
            'balance'       => max(0, $totalRevenue - $totalPaid),
        ];
    }

    /**
     * تسویه‌حساب‌های میزبان
     */
    public static function getSettlements($hostId)
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT * FROM host_settlements WHERE host_id = ? ORDER BY created_at DESC',
            [(int)$hostId]
        );
        foreach ($rows as &$r) {
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }
        return $rows;
    }

    /**
     * تغییر وضعیت یک تسویه‌حساب (تایید پرداخت یا رد کردن)
     */
    public static function updateSettlementStatus($settlementId, $status)
    {
        if (!in_array($status, ['paid', 'rejected', 'pending'], true)) {
            Response::validationError('وضعیت نامعتبر است');
        }
        $data = ['status' => $status];
        if ($status === 'paid') $data['paid_date'] = Jalali::today();
        return Database::getInstance()->update('host_settlements', $data, 'id = ?', [(int)$settlementId]);
    }

    /**
     * افزودن درخواست تسویه
     */
    public static function addSettlement($hostId, $amount, $note = '')
    {
        $existing = self::find($hostId);
        if (!$existing) Response::notFound('میزبان یافت نشد');
        if ($amount <= 0) Response::validationError('مبلغ باید بزرگتر از صفر باشد');

        return Database::getInstance()->insert('host_settlements', [
            'host_id'       => (int)$hostId,
            'amount'        => (int)$amount,
            'request_date'  => Jalali::today(),
            'status'        => 'pending',
            'note'          => $note,
        ]);
    }

    /**
     * تیکت‌های میزبان
     */
    public static function getTickets($hostId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT id, title, type, priority, status, dept, assignee, date, last_reply
             FROM tickets WHERE host_id = ? ORDER BY created_at DESC',
            [(int)$hostId]
        );
    }

    /**
     * آمار میزبانان
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM hosts GROUP BY status');
        foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT level, COUNT(*) AS cnt FROM hosts GROUP BY level');
        foreach ($rows as $r) $stats['by_level'][$r['level']] = (int)$r['cnt'];

        $stats['total_revenue'] = (int)$db->fetchColumn('SELECT COALESCE(SUM(total_revenue), 0) FROM hosts');
        $stats['total_properties'] = (int)$db->fetchColumn('SELECT COALESCE(SUM(properties), 0) FROM hosts');
        $stats['avg_rating'] = round((float)$db->fetchColumn('SELECT COALESCE(AVG(rating), 0) FROM hosts WHERE rating > 0'), 1);

        return $stats;
    }

    /**
     * یافتن میزبان با موبایل
     */
    public static function findByMobile($mobile)
    {
        $mobile = Helpers::sanitizePhone($mobile);
        if ($mobile === false) return null;
        return Database::getInstance()->fetchOne(
            'SELECT * FROM hosts WHERE mobile = ? LIMIT 1',
            [$mobile]
        );
    }
}
