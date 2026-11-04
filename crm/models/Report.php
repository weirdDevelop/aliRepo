<?php
/**
 * جاکجاست - مدل گزارش‌ها و داشبورد
 * Jakojast - Report Model
 *
 * این مدل تمام محاسبات مربوط به داشبورد را انجام می‌دهد:
 *   - KPIها (تعداد مشتریان، میزبانان، پرسنل، درآمد، رضایت، رشد)
 *   - نمودار درآمد ۱۲ ماه اخیر
 *   - نمودار رزروهای ماهانه
 *   - فعالیت‌های اخیر
 *   - هشدارهای سیستم
 *   - نمودار سازمانی
 */

class Report
{
    /**
     * محاسبه KPIهای داشبورد
     *
     * @return array
     */
    public static function getKPIs()
    {
        $db = Database::getInstance();

        // تعداد مشتریان فعال
        $totalCustomers = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM customers WHERE status = ?',
            ['active']
        );

        // تعداد میزبانان فعال
        $totalHosts = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM hosts WHERE status = ?',
            ['active']
        );

        // تعداد پرسنل فعال
        $totalStaff = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM staff WHERE status = ?',
            ['active']
        );

        // درآمد ماه جاری (از مجموع خرید مشتریان در ماه جاری شمسی)
        // نکته: چون last_purchase به شمسی ذخیره شده، محاسبه دقیق ماه جاری پیچیده است.
        // برای نسخه اولیه، مجموع total_spend تمام مشتریان را برمی‌گردانیم.
        $monthlyRevenue = (int)$db->fetchColumn(
            'SELECT COALESCE(SUM(total_spend), 0) FROM customers'
        );

        // میانگین رضایت میزبانان
        $avgRating = (float)$db->fetchColumn(
            'SELECT COALESCE(AVG(rating), 0) FROM hosts WHERE rating > 0'
        );

        // نرخ رشد ماهانه (محاسبه از تغییر تعداد مشتریان در ۳۰ روز اخیر)
        $lastMonthCustomers = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $prevMonthCustomers = (int)$db->fetchColumn(
            'SELECT COUNT(*) FROM customers WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $growthRate = $prevMonthCustomers > 0
            ? round((($lastMonthCustomers - $prevMonthCustomers) / $prevMonthCustomers) * 100, 1)
            : ($lastMonthCustomers > 0 ? 100 : 0);

        // تعداد سرنخ‌های فعال
        $activeLeads = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM leads WHERE stage NOT IN (?, ?)",
            ['برنده', 'باخته']
        );

        // تعداد تیکت‌های باز
        $openTickets = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE status != ?",
            ['closed']
        );

        // تعداد تماس‌های امروز
        $todayCalls = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM calls WHERE DATE(started_at) = CURDATE()"
        );

        return [
            'totalCustomers'    => $totalCustomers,
            'totalHosts'        => $totalHosts,
            'totalStaff'        => $totalStaff,
            'monthlyRevenue'    => $monthlyRevenue,
            'avgRating'         => round($avgRating, 1),
            'growthRate'        => $growthRate,
            'activeLeads'       => $activeLeads,
            'openTickets'       => $openTickets,
            'todayCalls'        => $todayCalls,
            'lastMonthCustomers'=> $lastMonthCustomers,
            'prevMonthCustomers'=> $prevMonthCustomers,
        ];
    }

    /**
     * داده نمودار درآمد ۱۲ ماه اخیر
     * چون داده‌های فروش به صورت aggregate ذخیره نشده، نمودار را بر اساس
     * created_at مشتریان و total_spend آنها در هر ماه می‌سازیم.
     *
     * @return array  [{month: 'فروردین', value: 12000000}, ...]
     */
    public static function getRevenueChart()
    {
        $db = Database::getInstance();

        // دریافت داده‌های ۱۲ ماه اخیر بر اساس created_at مشتریان
        $rows = $db->fetchAll(
            "SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COALESCE(SUM(total_spend), 0) AS total
             FROM customers
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY ym"
        );

        // ساخت آرایه ۱۲ ماهه با مقدار ۰ برای ماه‌های بدون داده
        $result = [];
        $now = time();
        $monthsData = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime("-$i months", $now);
            $ym = date('Y-m', $ts);
            $jalaliMonth = (int)Jalali::fromGregorian(date('Y-m-d', $ts), 'm');
            $monthsData[$ym] = [
                'month' => Jalali::monthName($jalaliMonth),
                'value' => 0,
            ];
        }
        // پر کردن با داده‌های واقعی
        foreach ($rows as $r) {
            if (isset($monthsData[$r['ym']])) {
                $monthsData[$r['ym']]['value'] = (int)$r['total'];
            }
        }

        return array_values($monthsData);
    }

    /**
     * داده نمودار رزروهای ماهانه (۱۲ ماه اخیر)
     * بر اساس تعداد مشتریان جدید در هر ماه
     *
     * @return array  [{month: 'فروردین', value: 50}, ...]
     */
    public static function getReservationsChart()
    {
        $db = Database::getInstance();

        $rows = $db->fetchAll(
            "SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COUNT(*) AS cnt
             FROM customers
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY ym"
        );

        $result = [];
        $now = time();
        $monthsData = [];
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime("-$i months", $now);
            $ym = date('Y-m', $ts);
            $jalaliMonth = (int)Jalali::fromGregorian(date('Y-m-d', $ts), 'm');
            $monthsData[$ym] = [
                'month' => Jalali::monthName($jalaliMonth),
                'value' => 0,
            ];
        }
        foreach ($rows as $r) {
            if (isset($monthsData[$r['ym']])) {
                $monthsData[$r['ym']]['value'] = (int)$r['cnt'];
            }
        }

        return array_values($monthsData);
    }

    /**
     * فعالیت‌های اخیر سیستم
     * از جداول مختلف (تماس‌ها، تیکت‌ها، مشتریان جدید، تسک‌ها) آخرین رویدادها را جمع‌آوری می‌کند.
     *
     * @param int $limit  تعداد فعالیت‌ها
     * @return array
     */
    public static function getActivities($limit = 20)
    {
        $db = Database::getInstance();
        $activities = [];

        // ۱) مشتریان جدید (۵ مورد اخیر)
        try {
            $rows = $db->fetchAll(
                "SELECT 'customer_new' AS type, name, created_at
                 FROM customers ORDER BY created_at DESC LIMIT 5"
            );
            foreach ($rows as $r) {
                $activities[] = [
                    'icon'  => '👤',
                    'color' => '#6f42c1',
                    'text'  => 'مشتری جدید: ' . $r['name'],
                    'time'  => $r['created_at'],
                ];
            }
        } catch (Exception $e) {}

        // ۲) میزبانان جدید
        try {
            $rows = $db->fetchAll(
                "SELECT 'host_new' AS type, name, created_at
                 FROM hosts ORDER BY created_at DESC LIMIT 5"
            );
            foreach ($rows as $r) {
                $activities[] = [
                    'icon'  => '🏠',
                    'color' => '#28a745',
                    'text'  => 'میزبان جدید: ' . $r['name'],
                    'time'  => $r['created_at'],
                ];
            }
        } catch (Exception $e) {}

        // ۳) تیکت‌های جدید
        try {
            $rows = $db->fetchAll(
                "SELECT 'ticket_new' AS type, title, created_at
                 FROM tickets ORDER BY created_at DESC LIMIT 5"
            );
            foreach ($rows as $r) {
                $activities[] = [
                    'icon'  => '🎫',
                    'color' => '#e50241',
                    'text'  => 'تیکت جدید: ' . $r['title'],
                    'time'  => $r['created_at'],
                ];
            }
        } catch (Exception $e) {}

        // ۴) تسک‌های انجام شده
        try {
            $rows = $db->fetchAll(
                "SELECT 'task_done' AS type, title, updated_at
                 FROM tasks WHERE status = ? ORDER BY updated_at DESC LIMIT 5",
                ['done']
            );
            foreach ($rows as $r) {
                $activities[] = [
                    'icon'  => '✅',
                    'color' => '#28a745',
                    'text'  => 'تسک انجام شد: ' . $r['title'],
                    'time'  => $r['updated_at'],
                ];
            }
        } catch (Exception $e) {}

        // ۵) تماس‌های اخیر
        try {
            $rows = $db->fetchAll(
                "SELECT 'call_in' AS type, call_source, started_at, type AS call_type
                 FROM calls ORDER BY started_at DESC LIMIT 5"
            );
            foreach ($rows as $r) {
                $icon = ($r['call_type'] ?? '') === 'inbound' ? '📞' : '📱';
                $activities[] = [
                    'icon'  => $icon,
                    'color' => '#17a2b8',
                    'text'  => 'تماس ' . ($r['call_type'] === 'inbound' ? 'ورودی' : 'خروجی') . ': ' . ($r['call_source'] ?? '-'),
                    'time'  => $r['started_at'],
                ];
            }
        } catch (Exception $e) {}

        // ۶) سرنخ‌های برنده
        try {
            $rows = $db->fetchAll(
                "SELECT 'lead_won' AS type, name, updated_at
                 FROM leads WHERE stage = ? ORDER BY updated_at DESC LIMIT 3",
                ['برنده']
            );
            foreach ($rows as $r) {
                $activities[] = [
                    'icon'  => '🎯',
                    'color' => '#28a745',
                    'text'  => 'سرنخ برنده شد: ' . $r['name'],
                    'time'  => $r['updated_at'],
                ];
            }
        } catch (Exception $e) {}

        // مرتب‌سازی بر اساس زمان (نزولی)
        usort($activities, function ($a, $b) {
            $ta = strtotime($a['time']) ?: 0;
            $tb = strtotime($b['time']) ?: 0;
            return $tb - $ta;
        });

        // محدود کردن به $limit مورد
        $activities = array_slice($activities, 0, $limit);

        // تبدیل زمان به فرمت "x دقیقه پیش" برای نمایش
        foreach ($activities as &$a) {
            $a['timeAgo'] = Helpers::timeAgo($a['time']);
            // اضافه کردن زمان شمسی هم
            $a['timeJalali'] = Jalali::fromGregorian($a['time'], 'Y/m/d H:i');
        }

        return $activities;
    }

    /**
     * هشدارهای سیستم
     *
     * @return array
     */
    public static function getAlerts()
    {
        $db = Database::getInstance();
        $alerts = [];

        // ۱) میزبانان با تسویه معوق
        try {
            $pendingSettlements = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM host_settlements WHERE status = ?",
                ['pending']
            );
            if ($pendingSettlements > 0) {
                $alerts[] = [
                    'type'   => 'danger',
                    'icon'   => '⚠️',
                    'text'   => "{$pendingSettlements} تسویه میزبان در انتظار پرداخت",
                    'page'   => 'payroll',
                    'action' => 'مشاهده',
                ];
            }
        } catch (Exception $e) {}

        // ۲) تیکت‌های باز بیش از ۴۸ ساعت
        try {
            $oldTickets = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM tickets
                 WHERE status != ? AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)",
                ['closed']
            );
            if ($oldTickets > 0) {
                $alerts[] = [
                    'type'   => 'warning',
                    'icon'   => '🎫',
                    'text'   => "{$oldTickets} تیکت بیش از ۴۸ ساعت بدون پاسخ",
                    'page'   => 'tickets',
                    'action' => 'مشاهده',
                ];
            }
        } catch (Exception $e) {}

        // ۳) درخواست مرخصی در انتظار
        try {
            $pendingLeaves = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM leaves WHERE status = ?",
                ['pending']
            );
            if ($pendingLeaves > 0) {
                $alerts[] = [
                    'type'   => 'info',
                    'icon'   => '🌴',
                    'text'   => "{$pendingLeaves} درخواست مرخصی در انتظار تأیید",
                    'page'   => 'leaves',
                    'action' => 'بررسی',
                ];
            }
        } catch (Exception $e) {}

        // ۴) میزبانان در انتظار تأیید
        try {
            $pendingHosts = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM hosts WHERE status = ?",
                ['pending']
            );
            if ($pendingHosts > 0) {
                $alerts[] = [
                    'type'   => 'warning',
                    'icon'   => '🏠',
                    'text'   => "{$pendingHosts} میزبان در انتظار تأیید",
                    'page'   => 'hosts',
                    'action' => 'مشاهده',
                ];
            }
        } catch (Exception $e) {}

        // ۵) سرنخ‌های بدون تماس در ۷ روز اخیر
        try {
            $coldLeads = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM leads
                 WHERE stage NOT IN (?, ?)
                 AND (next_contact IS NULL OR next_contact < CURDATE())",
                ['برنده', 'باخته']
            );
            if ($coldLeads > 0) {
                $alerts[] = [
                    'type'   => 'warning',
                    'icon'   => '🎯',
                    'text'   => "{$coldLeads} سرنخ نیاز به پیگیری دارد",
                    'page'   => 'leads',
                    'action' => 'پیگیری',
                ];
            }
        } catch (Exception $e) {}

        // ۶) تنطیمات تلفنچی ناقص
        try {
            $telefonchyToken = $db->fetchColumn(
                "SELECT value FROM settings WHERE key_name = ?",
                ['telefonchy_token']
            );
            if (empty($telefonchyToken)) {
                $alerts[] = [
                    'type'   => 'info',
                    'icon'   => '📞',
                    'text'   => 'تنظیمات تلفنچی تکمیل نشده است',
                    'page'   => 'settings',
                    'action' => 'تنظیم',
                ];
            }
        } catch (Exception $e) {}

        return $alerts;
    }

    /**
     * داده نمودار سازمانی (درخت سلسله مراتبی پرسنل)
     *
     * @return array  [{id, name, role, unit, supervisor_id, supervisor_name, children: [...]}]
     */
    public static function getOrgChart()
    {
        $db = Database::getInstance();

        try {
            $rows = $db->fetchAll(
                "SELECT id, code, name, role, unit, supervisor_id
                 FROM staff
                 WHERE status = ?
                 ORDER BY id",
                ['active']
            );
        } catch (Exception $e) {
            return [];
        }

        if (empty($rows)) return [];

        // ساخت نگاشت id → staff
        $map = [];
        foreach ($rows as $r) {
            $map[$r['id']] = [
                'id'           => (int)$r['id'],
                'code'         => $r['code'],
                'name'         => $r['name'],
                'role'         => $r['role'],
                'unit'         => $r['unit'],
                'supervisor_id'=> $r['supervisor_id'] ? (int)$r['supervisor_id'] : null,
                'children'     => [],
            ];
        }

        // ساخت درخت
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

    /**
     * آمار کلی برای صفحه گزارش‌ها
     *
     * @param string $fromDate  (Y-m-d)
     * @param string $toDate    (Y-m-d)
     * @return array
     */
    public static function getSalesReport($fromDate = null, $toDate = null)
    {
        $db = Database::getInstance();

        $where = '';
        $params = [];
        if ($fromDate && $toDate) {
            $where = 'WHERE created_at BETWEEN ? AND ?';
            $params = [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'];
        }

        // گزارش فروش ماهانه (۶ ماه اخیر)
        $rows = $db->fetchAll(
            "SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS ym,
                COUNT(*) AS new_customers,
                COALESCE(SUM(total_spend), 0) AS revenue
             FROM customers
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY ym DESC"
        );

        $salesData = [];
        foreach ($rows as $r) {
            $jalaliMonth = (int)Jalali::fromGregorian($r['ym'] . '-01', 'm');
            $jalaliYear = (int)Jalali::fromGregorian($r['ym'] . '-01', 'Y');
            $salesData[] = [
                'month'         => Jalali::monthName($jalaliMonth) . ' ' . $jalaliYear,
                'newCustomers'  => (int)$r['new_customers'],
                'revenue'       => (int)$r['revenue'],
            ];
        }

        return $salesData;
    }

    /**
     * تعداد رکوردهای هر جدول (برای نمایش در داشبورد)
     *
     * @return array
     */
    public static function getTableCounts()
    {
        $db = Database::getInstance();
        $tables = [
            'customers', 'hosts', 'staff', 'leads', 'host_leads',
            'tickets', 'tasks', 'calls', 'chat_messages', 'leaves', 'salaries',
        ];

        $counts = [];
        foreach ($tables as $t) {
            try {
                $counts[$t] = (int)$db->fetchColumn("SELECT COUNT(*) FROM `{$t}`");
            } catch (Exception $e) {
                $counts[$t] = 0;
            }
        }
        return $counts;
    }

    /**
     * خلاصه آماری برای کارت‌های داشبورد
     * شامل تغییرات نسبت به ماه قبل
     *
     * @return array
     */
    public static function getDashboardSummary()
    {
        $kpi = self::getKPIs();
        $tableCounts = self::getTableCounts();

        return [
            'kpi'          => $kpi,
            'tableCounts'  => $tableCounts,
            'systemName'   => self::getSetting('system_name', 'جاکجاست'),
            'currentDate'  => Jalali::now('Y/m/d'),
            'currentTime'  => date('H:i'),
            'dayName'      => Jalali::dayName(date('Y-m-d H:i:s')),
        ];
    }

    /**
     * دریافت یک تنظیم از جدول settings
     *
     * @param string $key
     * @param mixed  $default
     * @return string|null
     */
    public static function getSetting($key, $default = null)
    {
        try {
            $val = Database::getInstance()->fetchColumn(
                'SELECT value FROM settings WHERE key_name = ?',
                [$key]
            );
            return $val !== null && $val !== '' ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * ذخیره یک تنظیم در جدول settings
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    public static function setSetting($key, $value)
    {
        try {
            Database::getInstance()->execute(
                'INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)',
                [$key, (string)$value]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
