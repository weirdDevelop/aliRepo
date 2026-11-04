<?php
/**
 * جاکجاست - مدل سرنخ میزبان
 * Jakojast - Host Lead Model
 *
 * با فیلدهای جدید: location, region, action_date, city, property_type
 * + فیلد status جدید: جذب اولیه | بی پاسخ | عدم تمایل
 * + فیلد stage (legacy) برای قیف فروش قدیمی
 */

class HostLead
{
    private static $fillable = [
        'name', 'mobile', 'source', 'stage', 'status',
        'note', 'location', 'region', 'action_date',
        'city', 'property_type', 'last_contact', 'assignee',
    ];

    // مراحل قدیمی قیف فروش
    public static $stages = ['جذب اولیه', 'مذاکره', 'ارسال مدارک', 'بازدید اقامتگاه', 'قرارداد', 'فعالسازی'];

    // وضعیت‌های جدید (طبق درخواست کاربر)
    public static $statuses = ['جذب اولیه', 'بی پاسخ', 'عدم تمایل'];

    public static $sources = ['اینستاگرام', 'گوگل', 'معرفی', 'تبلیغات', 'تماس ورودی', 'جستجوی مکان', 'دیگر'];

    /** منبع پیش‌فرض ایمپورت از پوشه mizban-lead / Search Place */
    public const SOURCE_MIZBAN_SEARCH = 'جستجوی مکان';

    public static $propertyTypes = ['ویلا', 'آپارتمان', 'کلبه', 'بوم‌گردی', 'سوئیت', 'خانه روستایی', 'ویلا باغ'];

    /**
     * لیست سرنخ‌های میزبان با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR mobile LIKE ? OR note LIKE ? OR location LIKE ? OR city LIKE ?)';
            array_push($bind, $search, $search, $search, $search, $search);
        }
        if (!empty($params['stage']) && in_array($params['stage'], self::$stages, true)) {
            $where[] = 'stage = ?';
            $bind[] = $params['stage'];
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['source']) && in_array($params['source'], self::$sources, true)) {
            $where[] = 'source = ?';
            $bind[] = $params['source'];
        }
        if (!empty($params['city'])) {
            $where[] = 'city LIKE ?';
            $bind[] = '%' . $params['city'] . '%';
        }
        if (!empty($params['property_type']) && in_array($params['property_type'], self::$propertyTypes, true)) {
            $where[] = 'property_type = ?';
            $bind[] = $params['property_type'];
        }
        if (!empty($params['assignee'])) {
            $where[] = 'assignee = ?';
            $bind[] = $params['assignee'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = [
            'date' => 'created_at DESC',
            'name' => 'name ASC',
            'action_date' => 'action_date DESC',
            'last_contact' => 'last_contact DESC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date'] ?? 'created_at DESC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM host_leads {$whereSql}", $bind);

        $rows = $db->fetchAll(
            "SELECT * FROM host_leads {$whereSql} ORDER BY {$sort} LIMIT {$offset}, {$limit}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن سرنخ میزبان با ID
     */
    public static function find($id)
    {
        $row = Database::getInstance()->fetchOne('SELECT * FROM host_leads WHERE id = ?', [(int)$id]);
        if (!$row) return null;
        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d');
        return $row;
    }

    /**
     * ایجاد سرنخ میزبان جدید
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['name'])) {
            Response::validationError('نام میزبان بالقوه الزامی است');
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) Response::validationError('شماره تماس نامعتبر است');
            $filtered['mobile'] = $mobile;
        }

        // پیش‌فرض‌ها
        if (empty($filtered['stage'])) $filtered['stage'] = 'جذب اولیه';
        if (empty($filtered['status'])) $filtered['status'] = 'جذب اولیه';
        if (empty($filtered['source'])) $filtered['source'] = 'دیگر';
        if (empty($filtered['action_date'])) $filtered['action_date'] = Jalali::today();
        if (empty($filtered['last_contact'])) $filtered['last_contact'] = Jalali::today();

        return $db->insert('host_leads', $filtered);
    }

    /**
     * به‌روزرسانی سرنخ میزبان
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ میزبان یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizePhone($filtered['mobile']);
            if ($mobile === false) Response::validationError('شماره تماس نامعتبر است');
            $filtered['mobile'] = $mobile;
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('host_leads', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * یافتن سرنخ میزبان با شماره تماس (موبایل یا ثابت)
     */
    public static function findByMobile($mobile)
    {
        $phone = Helpers::sanitizePhone($mobile);
        if ($phone === false) return null;
        return Database::getInstance()->fetchOne(
            'SELECT * FROM host_leads WHERE mobile = ? LIMIT 1',
            [$phone]
        ) ?: null;
    }

    /**
     * ایمپورت بانک لید میزبان از پوشه mizban-lead (Search Place)
     * فایل ترجیحی: mizban-lead/contacts.json — در غیر این صورت contacts.sql
     *
     * @param bool $updateExisting اگر true باشد نام/یادداشت رکوردهای تکراری به‌روز می‌شود
     * @return array {imported, updated, skipped, invalid, total}
     */
    public static function importFromMizbanLeadBank($updateExisting = false)
    {
        $contacts = self::loadMizbanContacts();
        if (empty($contacts)) {
            Response::validationError('فایل contacts در پوشه mizban-lead یافت نشد یا خالی است');
        }

        $db = Database::getInstance();
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $invalid = 0;
        $today = Jalali::today();

        foreach ($contacts as $row) {
            $phone = Helpers::sanitizePhone($row['phone'] ?? '');
            if ($phone === false) {
                $invalid++;
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = 'میزبان بالقوه ' . $phone;
            }
            $noteParts = ['ایمپورت از بانک لید جستجوی مکان (mizban-lead)'];
            if (!empty($row['notes'])) {
                $noteParts[] = trim((string)$row['notes']);
            }
            if (!empty($row['external_id'])) {
                $noteParts[] = 'شناسه خارجی: ' . (int)$row['external_id'];
            }
            $note = implode(' | ', $noteParts);

            $existing = $db->fetchOne('SELECT id, name, note FROM host_leads WHERE mobile = ? LIMIT 1', [$phone]);
            if ($existing) {
                if ($updateExisting) {
                    $patch = ['source' => self::SOURCE_MIZBAN_SEARCH];
                    // نام پیش‌فرض را با نام واقعی جایگزین کن؛ نام دستی کاربر را بازنویسی نکن
                    if (!empty($row['name']) && (strpos($existing['name'], 'میزبان بالقوه ') === 0 || $existing['name'] === $phone)) {
                        $patch['name'] = $name;
                    }
                    if (!empty($row['notes']) && (empty($existing['note']) || strpos((string)$existing['note'], 'ایمپورت از بانک لید') !== false)) {
                        $patch['note'] = $note;
                    }
                    $db->update('host_leads', $patch, 'id = ?', [(int)$existing['id']]);
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $db->insert('host_leads', [
                'name'         => $name,
                'mobile'       => $phone,
                'source'       => self::SOURCE_MIZBAN_SEARCH,
                'stage'        => 'جذب اولیه',
                'status'       => 'جذب اولیه',
                'note'         => $note,
                'action_date'  => $today,
                'last_contact' => null,
            ]);
            $imported++;
        }

        return [
            'imported' => $imported,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'invalid'  => $invalid,
            'total'    => count($contacts),
        ];
    }

    /**
     * خواندن لیست مخاطبین از mizban-lead/contacts.json یا contacts.sql
     * @return array<int, array{phone:string,name:?string,notes:?string,external_id?:int}>
     */
    public static function loadMizbanContacts()
    {
        $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mizban-lead' . DIRECTORY_SEPARATOR;
        $jsonPath = $base . 'contacts.json';
        if (is_file($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true);
            return is_array($data) ? $data : [];
        }

        $sqlPath = $base . 'contacts.sql';
        if (!is_file($sqlPath)) return [];

        $sql = file_get_contents($sqlPath);
        if ($sql === false || $sql === '') return [];

        $out = [];
        $seen = [];
        // (id, 'phone', NULL|'name', NULL|'notes', ...
        if (!preg_match_all(
            "/\((\d+),\s*'([^']*)',\s*(NULL|'((?:\\\\'|[^'])*)'),\s*(NULL|'((?:\\\\'|[^'])*)')/u",
            $sql,
            $matches,
            PREG_SET_ORDER
        )) {
            return [];
        }

        foreach ($matches as $m) {
            $phone = Helpers::sanitizePhone($m[2]);
            if ($phone === false || isset($seen[$phone])) continue;
            $seen[$phone] = true;
            $name = ($m[3] === 'NULL') ? null : stripcslashes($m[4]);
            $notes = ($m[5] === 'NULL') ? null : stripcslashes($m[6]);
            $out[] = [
                'external_id' => (int)$m[1],
                'phone'       => $phone,
                'name'        => $name,
                'notes'       => $notes,
            ];
        }
        return $out;
    }

    /**
     * تغییر وضعیت سرنخ میزبان (جذب اولیه/بی پاسخ/عدم تمایل)
     */
    public static function changeStatus($id, $status)
    {
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر است. مقادیر مجاز: ' . implode('، ', self::$statuses));
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ میزبان یافت نشد');

        return Database::getInstance()->update(
            'host_leads',
            ['status' => $status],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * تغییر مرحله سرنخ میزبان (قیف قدیمی)
     */
    public static function changeStage($id, $stage)
    {
        if (!in_array($stage, self::$stages, true)) {
            Response::validationError('مرحله نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ میزبان یافت نشد');

        return Database::getInstance()->update(
            'host_leads',
            ['stage' => $stage],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * ثبت تماس با سرنخ میزبان (به‌روزرسانی last_contact)
     */
    public static function logContact($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ میزبان یافت نشد');

        return Database::getInstance()->update(
            'host_leads',
            [
                'last_contact' => Jalali::today(),
                'action_date'  => Jalali::today(),
            ],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * حذف سرنخ میزبان
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ میزبان یافت نشد');
        return Database::getInstance()->delete('host_leads', 'id = ?', [(int)$id]);
    }

    /**
     * تبدیل سرنخ میزبان به میزبان واقعی
     *
     * @param int $hostLeadId
     * @return int  (ID میزبان جدید)
     */
    public static function convertToHost($hostLeadId)
    {
        $db = Database::getInstance();
        $lead = self::find($hostLeadId);
        if (!$lead) Response::notFound('سرنخ میزبان یافت نشد');

        if ($lead['stage'] === 'فعالسازی') {
            Response::validationError('این سرنخ قبلاً تبدیل شده است');
        }

        if (!empty($lead['mobile'])) {
            $existing = Host::findByMobile($lead['mobile']);
            if ($existing) {
                Response::validationError('میزبان با این موبایل قبلاً ثبت شده است: ' . $existing['name']);
            }
        }

        $db->beginTransaction();
        try {
            $hostId = $db->insert('hosts', [
                'name'        => $lead['name'],
                'mobile'      => $lead['mobile'],
                'city'        => $lead['city'],
                'status'      => 'pending',  // در انتظار تأیید
                'level'       => 'برنز',
                'join_date'   => Jalali::today(),
                'properties'  => 0,
                'total_revenue' => 0,
                'rating'      => 0,
                'notes'       => 'تبدیل از سرنخ. توضیحات اولیه: ' . ($lead['note'] ?? ''),
            ]);

            // به‌روزرسانی سرنخ به مرحله «فعالسازی»
            $db->update('host_leads', ['stage' => 'فعالسازی'], 'id = ?', [(int)$hostLeadId]);

            $db->commit();
            return $hostId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * آمار سرنخ‌های میزبان
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        // بر اساس وضعیت جدید
        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM host_leads GROUP BY status');
        foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];

        // بر اساس مرحله قدیمی
        $rows = $db->fetchAll('SELECT stage, COUNT(*) AS cnt FROM host_leads GROUP BY stage');
        foreach ($rows as $r) $stats['by_stage'][$r['stage']] = (int)$r['cnt'];

        // بر اساس منبع
        $rows = $db->fetchAll('SELECT source, COUNT(*) AS cnt FROM host_leads GROUP BY source');
        foreach ($rows as $r) $stats['by_source'][$r['source']] = (int)$r['cnt'];

        // بر اساس نوع اقامتگاه
        $rows = $db->fetchAll('SELECT property_type, COUNT(*) AS cnt FROM host_leads GROUP BY property_type');
        foreach ($rows as $r) $stats['by_property_type'][$r['property_type']] = (int)$r['cnt'];

        // بر اساس شهر
        $rows = $db->fetchAll('SELECT city, COUNT(*) AS cnt FROM host_leads WHERE city IS NOT NULL AND city != "" GROUP BY city ORDER BY cnt DESC LIMIT 10');
        foreach ($rows as $r) $stats['by_city'][$r['city']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM host_leads');
        $stats['converted'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM host_leads WHERE stage = 'فعالسازی'");
        $stats['conversion_rate'] = $stats['total'] > 0
            ? round(($stats['converted'] / $stats['total']) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * دریافت لیست مسئولین جذب (برای فیلتر)
     */
    public static function getAssignees()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DISTINCT assignee FROM host_leads WHERE assignee IS NOT NULL AND assignee != '' ORDER BY assignee"
        );
        return array_column($rows, 'assignee');
    }

    /**
     * دریافت لیست شهرهای موجود (برای فیلتر)
     */
    public static function getCities()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DISTINCT city FROM host_leads WHERE city IS NOT NULL AND city != '' ORDER BY city"
        );
        return array_column($rows, 'city');
    }
}
