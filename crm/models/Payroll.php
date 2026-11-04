<?php
/**
 * جاکجاست - مدل حقوق و دستمزد
 * Jakojast - Payroll Model
 *
 * حقوق با ۱۲ مؤلفه:
 *   ایاب و ذهاب (base + housing + bonus_food + grocery + performance + overtime)
 *   - کسورات (insurance + tax + loan)
 *   = خالص پرداختی (net)
 */

class Payroll
{
    private static $fillable = [
        'staff_id', 'month', 'base', 'housing', 'bonus_food', 'grocery',
        'performance', 'overtime', 'insurance', 'tax', 'loan', 'net', 'paid', 'paid_date',
    ];

    /**
     * لیست فیش‌های حقوقی با فیلتر
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(s.name LIKE ? OR s.code LIKE ? OR p.month LIKE ?)';
            array_push($bind, $search, $search, $search);
        }
        if (!empty($params['month'])) {
            $where[] = 'p.month = ?';
            $bind[] = $params['month'];
        }
        if (isset($params['paid'])) {
            $where[] = 'p.paid = ?';
            $bind[] = (int)$params['paid'];
        }
        if (!empty($params['staff_id'])) {
            $where[] = 'p.staff_id = ?';
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
            "SELECT COUNT(*) FROM salaries p LEFT JOIN staff s ON s.id = p.staff_id {$whereSql}",
            $bind
        );

        $rows = $db->fetchAll(
            "SELECT p.*, s.name AS staff_name, s.code AS staff_code, s.unit AS staff_unit, s.role AS staff_role
             FROM salaries p
             LEFT JOIN staff s ON s.id = p.staff_id
             {$whereSql}
             ORDER BY p.month DESC, s.name ASC
             LIMIT {$offset}, {$limit}",
            $bind
        );

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن با ID
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT p.*, s.name AS staff_name, s.code AS staff_code, s.unit AS staff_unit,
                    s.role AS staff_role, s.mobile AS staff_mobile, s.base_salary AS staff_base
             FROM salaries p
             LEFT JOIN staff s ON s.id = p.staff_id
             WHERE p.id = ? LIMIT 1',
            [(int)$id]
        );
        return $row ?: null;
    }

    /**
     * ایجاد رکورد حقوق
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['staff_id'])) Response::validationError('پرسنل الزامی است');
        if (empty($filtered['month'])) Response::validationError('ماه الزامی است');

        $staff = Staff::find((int)$filtered['staff_id']);
        if (!$staff) Response::notFound('پرسنل یافت نشد');

        // اگر base ارسال نشده، از حقوق پایه پرسنل استفاده کن
        if (empty($filtered['base'])) $filtered['base'] = (int)$staff['base_salary'];

        // محاسبه net اگر ارسال نشده
        if (!isset($filtered['net'])) {
            $filtered['net'] = self::calculateNet($filtered);
        }

        if (!isset($filtered['paid'])) $filtered['paid'] = 0;

        return $db->insert('salaries', $filtered);
    }

    /**
     * محاسبه خالص پرداختی
     */
    private static function calculateNet($data)
    {
        $earnings = (int)($data['base'] ?? 0)
            + (int)($data['housing'] ?? 0)
            + (int)($data['bonus_food'] ?? 0)
            + (int)($data['grocery'] ?? 0)
            + (int)($data['performance'] ?? 0)
            + (int)($data['overtime'] ?? 0);
        $deductions = (int)($data['insurance'] ?? 0)
            + (int)($data['tax'] ?? 0)
            + (int)($data['loan'] ?? 0);
        return $earnings - $deductions;
    }

    /**
     * به‌روزرسانی
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('فیش حقوقی یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        // اگر یک مؤلفه عوض شد، net را دوباره محاسبه کن
        $recomputeComponents = ['base', 'housing', 'bonus_food', 'grocery', 'performance', 'overtime', 'insurance', 'tax', 'loan'];
        $needRecompute = false;
        foreach ($recomputeComponents as $c) {
            if (array_key_exists($c, $filtered)) {
                $needRecompute = true;
                break;
            }
        }
        if ($needRecompute && !array_key_exists('net', $filtered)) {
            $merged = array_merge($existing, $filtered);
            $filtered['net'] = self::calculateNet($merged);
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('salaries', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * پرداخت فیش
     */
    public static function markPaid($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('فیش حقوقی یافت نشد');
        return Database::getInstance()->update('salaries', [
            'paid' => 1,
            'paid_date' => date('Y-m-d H:i:s'),
        ], 'id = ?', [(int)$id]);
    }

    /**
     * پرداخت گروهی فیش‌های یک ماه
     */
    public static function payAllMonth($month)
    {
        $db = Database::getInstance();
        $count = $db->update(
            'salaries',
            ['paid' => 1, 'paid_date' => date('Y-m-d H:i:s')],
            'month = ? AND paid = 0',
            [$month]
        );
        return $count;
    }

    /**
     * حذف
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('فیش حقوقی یافت نشد');
        return Database::getInstance()->delete('salaries', 'id = ?', [(int)$id]);
    }

    /**
     * فیش‌های یک پرسنل
     */
    public static function getByStaff($staffId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM salaries WHERE staff_id = ? ORDER BY month DESC',
            [(int)$staffId]
        );
    }

    /**
     * تولید فیش حقوقی برای ماه جاری برای همه پرسنل فعال
     *
     * @param string $month  مثلاً «اردیبهشت ۱۴۰۳»
     * @return int  تعداد فیش‌های ایجاد شده
     */
    public static function generateForMonth($month)
    {
        $db = Database::getInstance();

        // بررسی اینکه برای این ماه قبلاً فیش ساخته شده یا نه
        $existingCount = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM salaries WHERE month = ?',
            [$month]
        );
        if ($existingCount > 0) {
            Response::validationError("برای ماه {$month} قبلاً {$existingCount} فیش ایجاد شده است");
        }

        // دریافت همه پرسنل فعال
        $staffList = $db->fetchAll(
            'SELECT id, base_salary FROM staff WHERE status = ?',
            ['active']
        );

        if (empty($staffList)) {
            Response::validationError('پرسنل فعالی برای تولید فیش وجود ندارد');
        }

        $count = 0;
        $db->beginTransaction();
        try {
            foreach ($staffList as $s) {
                $base = (int)$s['base_salary'];
                $housing = (int)round($base * 0.1);  // ۱۰٪ حقوق پایه
                $bonusFood = 800000;
                $grocery = 500000;
                $insurance = (int)round($base * 0.07);  // ۷٪
                $tax = (int)round($base * 0.05);  // ۵٪

                $net = $base + $housing + $bonusFood + $grocery - $insurance - $tax;

                $db->insert('salaries', [
                    'staff_id'   => (int)$s['id'],
                    'month'      => $month,
                    'base'       => $base,
                    'housing'    => $housing,
                    'bonus_food' => $bonusFood,
                    'grocery'    => $grocery,
                    'performance'=> 0,
                    'overtime'   => 0,
                    'insurance'  => $insurance,
                    'tax'        => $tax,
                    'loan'       => 0,
                    'net'        => $net,
                    'paid'       => 0,
                ]);
                $count++;
            }
            $db->commit();
            return $count;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * آمار حقوق
     */
    public static function getStats($month = null)
    {
        $db = Database::getInstance();
        $stats = [];

        $whereMonth = $month ? 'WHERE month = ?' : '';
        $params = $month ? [$month] : [];

        $stats['total_records'] = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM salaries {$whereMonth}",
            $params
        );
        $stats['total_net'] = (int)$db->fetchColumn(
            "SELECT COALESCE(SUM(net), 0) FROM salaries {$whereMonth}",
            $params
        );
        $stats['total_paid'] = (int)$db->fetchColumn(
            "SELECT COALESCE(SUM(net), 0) FROM salaries {$whereMonth} AND paid = 1",
            $month ? array_merge($params, []) : []
        );
        $stats['paid_count'] = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM salaries {$whereMonth} AND paid = 1",
            $month ? array_merge($params, []) : []
        );
        $stats['unpaid_count'] = $stats['total_records'] - $stats['paid_count'];

        // ماه‌های موجود
        $months = $db->fetchAll(
            'SELECT DISTINCT month, COUNT(*) AS cnt, COALESCE(SUM(net), 0) AS total
             FROM salaries GROUP BY month ORDER BY month DESC LIMIT 12'
        );
        $stats['months'] = $months;

        return $stats;
    }

    /**
     * لیست ماه‌های موجود
     */
    public static function getMonths()
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT DISTINCT month FROM salaries ORDER BY month DESC'
        );
        return array_column($rows, 'month');
    }
}
