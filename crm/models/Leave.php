<?php
/**
 * جاکجاست - مدل مرخصی
 * Jakojast - Leave Model
 */

class Leave
{
    private static $fillable = [
        'staff_id', 'type', 'from_date', 'to_date', 'days',
        'reason', 'status', 'approved_by', 'approved_at',
        'leave_mode', 'from_time', 'to_time', 'hours',
    ];

    public static $types = ['استحقاقی', 'اضطراری', 'بدون حقوق', 'بیماری', ' ازدواج'];
    public static $statuses = ['pending', 'approved', 'rejected'];

    /** @var bool کش وجود ستون‌های مرخصی ساعتی */
    private static $hourlyColumnsChecked = false;

    /**
     * افزودن ستون‌های مرخصی ساعتی (leave_mode, from_time, to_time, hours)
     */
    private static function ensureHourlyColumns()
    {
        if (self::$hourlyColumnsChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$hourlyColumnsChecked = true; return; }
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT leave_mode FROM leaves LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `leaves`
                    ADD COLUMN `leave_mode` VARCHAR(10) NOT NULL DEFAULT 'daily' COMMENT 'daily|hourly' AFTER `type`,
                    ADD COLUMN `from_time` VARCHAR(5) DEFAULT NULL COMMENT 'HH:MM - فقط برای مرخصی ساعتی',
                    ADD COLUMN `to_time` VARCHAR(5) DEFAULT NULL COMMENT 'HH:MM - فقط برای مرخصی ساعتی',
                    ADD COLUMN `hours` DECIMAL(4,1) DEFAULT NULL COMMENT 'تعداد ساعت - فقط برای مرخصی ساعتی'");
                Logger::info('Auto migration: added hourly-leave columns to leaves table');
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Leave::ensureHourlyColumns failed: ' . $e->getMessage());
        }
        self::$hourlyColumnsChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    public static function migrate()
    {
        self::ensureHourlyColumns();
    }

    /**
     * لیست مرخصی‌ها با فیلتر
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(s.name LIKE ? OR l.reason LIKE ?)';
            array_push($bind, $search, $search);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'l.status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['type']) && in_array($params['type'], self::$types, true)) {
            $where[] = 'l.type = ?';
            $bind[] = $params['type'];
        }
        if (!empty($params['staff_id'])) {
            $where[] = 'l.staff_id = ?';
            $bind[] = (int)$params['staff_id'];
        }
        if (!empty($params['unit'])) {
            $where[] = 's.unit = ?';
            $bind[] = $params['unit'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM leaves l LEFT JOIN staff s ON s.id = l.staff_id {$whereSql}",
            $bind
        );

        $rows = $db->fetchAll(
            "SELECT l.*, s.name AS staff_name, s.unit AS staff_unit, s.code AS staff_code,
                    u.full_name AS approver_name
             FROM leaves l
             LEFT JOIN staff s ON s.id = l.staff_id
             LEFT JOIN users u ON u.id = l.approved_by
             {$whereSql}
             ORDER BY
                CASE l.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
                l.created_at DESC
             LIMIT {$offset}, {$limit}",
            $bind
        );

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن مرخصی با ID
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT l.*, s.name AS staff_name, s.unit AS staff_unit, s.code AS staff_code,
                    u.full_name AS approver_name
             FROM leaves l
             LEFT JOIN staff s ON s.id = l.staff_id
             LEFT JOIN users u ON u.id = l.approved_by
             WHERE l.id = ? LIMIT 1',
            [(int)$id]
        );
        return $row ?: null;
    }

    /**
     * ایجاد درخواست مرخصی
     */
    public static function create(array $data)
    {
        self::ensureHourlyColumns();
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['staff_id'])) Response::validationError('انتخاب پرسنل الزامی است');
        if (empty($filtered['from_date'])) Response::validationError('تاریخ الزامی است');
        if (empty($filtered['type'])) $filtered['type'] = 'استحقاقی';

        $isHourly = ($filtered['leave_mode'] ?? 'daily') === 'hourly';

        if ($isHourly) {
            // مرخصی ساعتی: فقط یک روز، با بازه‌ی ساعت شروع/پایان
            if (empty($filtered['from_time']) || empty($filtered['to_time'])) {
                Response::validationError('برای مرخصی ساعتی، ساعت شروع و پایان الزامی است');
            }
            $filtered['to_date'] = $filtered['from_date'];
            $filtered['hours'] = self::calculateHours($filtered['from_time'], $filtered['to_time']);
            if ($filtered['hours'] <= 0) {
                Response::validationError('ساعت پایان باید بعد از ساعت شروع باشد');
            }
            // برای سازگاری با گزارش‌های قدیمی که فقط days را می‌خوانند
            $filtered['days'] = round($filtered['hours'] / 8, 2);
        } else {
            if (empty($filtered['to_date'])) Response::validationError('تاریخ پایان الزامی است');
            // محاسبه تعداد روزها اگر ارسال نشده
            if (empty($filtered['days'])) {
                $filtered['days'] = self::calculateDays($filtered['from_date'], $filtered['to_date']);
            }
        }

        // بررسی وجود پرسنل
        $staff = Staff::find((int)$filtered['staff_id']);
        if (!$staff) Response::notFound('پرسنل یافت نشد');

        if (empty($filtered['status'])) $filtered['status'] = 'pending';

        return $db->insert('leaves', $filtered);
    }

    /**
     * محاسبه تعداد ساعت بین دو زمان (HH:MM)
     */
    private static function calculateHours($fromTime, $toTime)
    {
        $from = strtotime($fromTime);
        $to = strtotime($toTime);
        if (!$from || !$to || $to <= $from) return 0;
        return round(($to - $from) / 3600, 1);
    }

    /**
     * محاسبه تعداد روزها بین دو تاریخ شمسی
     */
    private static function calculateDays($fromJalali, $toJalali)
    {
        $from = Jalali::toGregorianString($fromJalali);
        $to = Jalali::toGregorianString($toJalali);
        if (!$from || !$to) return 1;
        $diff = (strtotime($to) - strtotime($from)) / 86400;
        return max(1, $diff + 1);
    }

    /**
     * تأیید / رد درخواست مرخصی
     */
    public static function changeStatus($id, $status, $approverId)
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            Response::validationError('وضعیت نامعتبر است. فقط approved یا rejected مجاز است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('درخواست مرخصی یافت نشد');

        Database::getInstance()->update('leaves', [
            'status'      => $status,
            'approved_by' => $approverId,
            'approved_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$id]);

        // اگر تأیید شد و پرسنل فعال است، وضعیت او را به «مرخصی» تغییر بده
        if ($status === 'approved') {
            $today = Jalali::today();
            if ($existing['from_date'] <= $today && $existing['to_date'] >= $today) {
                Database::getInstance()->update(
                    'staff',
                    ['status' => 'leave'],
                    'id = ?',
                    [(int)$existing['staff_id']]
                );
            }
        }

        return self::find($id);
    }

    /**
     * حذف درخواست مرخصی
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('درخواست مرخصی یافت نشد');
        return Database::getInstance()->delete('leaves', 'id = ?', [(int)$id]);
    }

    /**
     * مرخصی‌های یک پرسنل
     */
    public static function getByStaff($staffId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM leaves WHERE staff_id = ? ORDER BY created_at DESC',
            [(int)$staffId]
        );
    }

    /**
     * آمار مرخصی‌ها
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt, COALESCE(SUM(days), 0) AS total_days FROM leaves GROUP BY status');
        foreach ($rows as $r) {
            $stats['by_status'][$r['status']] = [
                'count' => (int)$r['cnt'],
                'days'  => (int)$r['total_days'],
            ];
        }

        $rows = $db->fetchAll('SELECT type, COUNT(*) AS cnt FROM leaves GROUP BY type');
        foreach ($rows as $r) $stats['by_type'][$r['type']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM leaves');
        $stats['pending'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM leaves WHERE status = 'pending'");
        $stats['total_days'] = (int)$db->fetchColumn("SELECT COALESCE(SUM(days), 0) FROM leaves WHERE status = 'approved'");

        return $stats;
    }
}
