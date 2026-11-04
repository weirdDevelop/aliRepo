<?php
/**
 * گزارش‌های فردی پرسنل / اپراتور
 * برای انتقال گزارش‌های سیستم قدیم (مثلاً بله) و ثبت دستی گزارش روزانه
 */

class StaffReport
{
    private static $migrated = false;

    private static $fillable = [
        'staff_id', 'report_date', 'source', 'title', 'content', 'metrics_json', 'created_by',
    ];

    public static $sources = ['bale', 'manual', 'import', 'other'];

    public static $sourceLabels = [
        'bale'   => 'بله',
        'manual' => 'دستی',
        'import' => 'ایمپورت',
        'other'  => 'سایر',
    ];

    public static function migrate()
    {
        if (self::$migrated) return ['added' => [], 'error' => null];
        $added = [];
        try {
            $db = Database::getInstance();
            $exists = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'staff_reports'"
            );
            if (!$exists) {
                $db->execute("CREATE TABLE IF NOT EXISTS `staff_reports` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `staff_id` INT UNSIGNED NOT NULL,
                    `report_date` VARCHAR(20) NOT NULL COMMENT 'تاریخ شمسی گزارش',
                    `source` VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'bale|manual|import|other',
                    `title` VARCHAR(200) DEFAULT NULL,
                    `content` MEDIUMTEXT NOT NULL,
                    `metrics_json` TEXT DEFAULT NULL COMMENT 'معیارهای عددی اختیاری JSON',
                    `created_by` INT UNSIGNED DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_staff` (`staff_id`),
                    KEY `idx_date` (`report_date`),
                    KEY `idx_source` (`source`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $added[] = 'staff_reports';
            }
        } catch (Exception $e) {
            Logger::error('StaffReport::migrate failed: ' . $e->getMessage());
            self::$migrated = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$migrated = true;
        return ['added' => $added, 'error' => null];
    }

    public static function forStaff($staffId, $limit = 50)
    {
        self::migrate();
        $rows = Database::getInstance()->fetchAll(
            "SELECT r.*, u.full_name AS author_name
             FROM staff_reports r
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.staff_id = ?
             ORDER BY r.report_date DESC, r.id DESC
             LIMIT " . (int)$limit,
            [(int)$staffId]
        );
        foreach ($rows as &$r) {
            $r['source_label'] = self::$sourceLabels[$r['source']] ?? $r['source'];
            $r['metrics'] = $r['metrics_json'] ? (json_decode($r['metrics_json'], true) ?: []) : [];
        }
        return $rows;
    }

    public static function all(array $params = [])
    {
        self::migrate();
        $where = [];
        $bind = [];
        if (!empty($params['staff_id'])) {
            $where[] = 'r.staff_id = ?';
            $bind[] = (int)$params['staff_id'];
        }
        if (!empty($params['source']) && in_array($params['source'], self::$sources, true)) {
            $where[] = 'r.source = ?';
            $bind[] = $params['source'];
        }
        if (!empty($params['search'])) {
            $where[] = '(r.title LIKE ? OR r.content LIKE ? OR s.name LIKE ?)';
            $q = '%' . $params['search'] . '%';
            array_push($bind, $q, $q, $q);
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $db = Database::getInstance();
        $total = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM staff_reports r LEFT JOIN staff s ON s.id = r.staff_id {$whereSql}",
            $bind
        );
        $rows = $db->fetchAll(
            "SELECT r.*, s.name AS staff_name, s.exten_number, u.full_name AS author_name
             FROM staff_reports r
             LEFT JOIN staff s ON s.id = r.staff_id
             LEFT JOIN users u ON u.id = r.created_by
             {$whereSql}
             ORDER BY r.report_date DESC, r.id DESC
             LIMIT {$offset}, {$limit}",
            $bind
        );
        foreach ($rows as &$r) {
            $r['source_label'] = self::$sourceLabels[$r['source']] ?? $r['source'];
        }
        return ['items' => $rows, 'total' => $total];
    }

    public static function create(array $data)
    {
        self::migrate();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        if (empty($filtered['staff_id'])) Response::validationError('پرسنل الزامی است');
        if (empty($filtered['content'])) Response::validationError('متن گزارش الزامی است');
        if (empty($filtered['report_date'])) $filtered['report_date'] = Jalali::today();
        if (empty($filtered['source']) || !in_array($filtered['source'], self::$sources, true)) {
            $filtered['source'] = 'manual';
        }
        if (empty($filtered['title'])) {
            $filtered['title'] = 'گزارش ' . (self::$sourceLabels[$filtered['source']] ?? '') . ' — ' . $filtered['report_date'];
        }
        if (isset($filtered['metrics_json']) && is_array($filtered['metrics_json'])) {
            $filtered['metrics_json'] = json_encode($filtered['metrics_json'], JSON_UNESCAPED_UNICODE);
        }
        $user = Auth::currentUser();
        if ($user && empty($filtered['created_by'])) {
            $filtered['created_by'] = (int)$user['id'];
        }
        return Database::getInstance()->insert('staff_reports', $filtered);
    }

    public static function delete($id)
    {
        self::migrate();
        return Database::getInstance()->delete('staff_reports', 'id = ?', [(int)$id]);
    }

    /**
     * ایمپورت دسته‌ای گزارش‌ها
     * هر آیتم: {staff_id|staff_name|exten, report_date, title?, content, source?}
     */
    public static function importBatch(array $items)
    {
        self::migrate();
        $created = 0; $skipped = 0; $errors = [];
        $db = Database::getInstance();
        foreach ($items as $i => $item) {
            if (!is_array($item)) { $skipped++; continue; }
            $staffId = (int)($item['staff_id'] ?? 0);
            if (!$staffId && !empty($item['exten'])) {
                $staffId = (int)$db->fetchColumn(
                    'SELECT id FROM staff WHERE exten_number = ? LIMIT 1',
                    [$item['exten']]
                );
            }
            if (!$staffId && !empty($item['staff_name'])) {
                $staffId = (int)$db->fetchColumn(
                    'SELECT id FROM staff WHERE name = ? LIMIT 1',
                    [trim($item['staff_name'])]
                );
            }
            if (!$staffId || empty($item['content'])) {
                $skipped++;
                $errors[] = 'ردیف ' . ($i + 1) . ': پرسنل یا متن نامعتبر';
                continue;
            }
            try {
                self::create([
                    'staff_id'    => $staffId,
                    'report_date' => $item['report_date'] ?? Jalali::today(),
                    'source'      => $item['source'] ?? 'import',
                    'title'       => $item['title'] ?? null,
                    'content'     => $item['content'],
                    'metrics_json'=> $item['metrics'] ?? null,
                ]);
                $created++;
            } catch (Exception $e) {
                $skipped++;
                $errors[] = 'ردیف ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }
        return compact('created', 'skipped', 'errors');
    }
}
