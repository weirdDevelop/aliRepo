<?php
/**
 * استعلام‌های سایت جاکجاست → قابل پیگیری در CRM / تبدیل به سرنخ
 */

class SiteInquiry
{
    private static $migrated = false;

    private static $fillable = [
        'site_id', 'name', 'mobile', 'email', 'city', 'message',
        'property_title', 'source', 'status', 'assignee', 'lead_id',
        'customer_id', 'raw_payload', 'inquired_at',
    ];

    public static $statuses = ['new', 'in_progress', 'converted', 'closed', 'spam'];

    public static function migrate()
    {
        if (self::$migrated) return ['added' => [], 'error' => null];
        $added = [];
        try {
            $db = Database::getInstance();
            $exists = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'site_inquiries'"
            );
            if (!$exists) {
                $db->execute("CREATE TABLE IF NOT EXISTS `site_inquiries` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `site_id` VARCHAR(64) DEFAULT NULL,
                    `name` VARCHAR(150) DEFAULT NULL,
                    `mobile` VARCHAR(20) DEFAULT NULL,
                    `email` VARCHAR(150) DEFAULT NULL,
                    `city` VARCHAR(80) DEFAULT NULL,
                    `message` TEXT DEFAULT NULL,
                    `property_title` VARCHAR(200) DEFAULT NULL,
                    `source` VARCHAR(80) DEFAULT 'سایت',
                    `status` VARCHAR(20) NOT NULL DEFAULT 'new',
                    `assignee` VARCHAR(120) DEFAULT NULL,
                    `lead_id` INT UNSIGNED DEFAULT NULL,
                    `customer_id` INT UNSIGNED DEFAULT NULL,
                    `raw_payload` MEDIUMTEXT DEFAULT NULL,
                    `inquired_at` DATETIME DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_site_id` (`site_id`),
                    KEY `idx_mobile` (`mobile`),
                    KEY `idx_status` (`status`),
                    KEY `idx_inquired` (`inquired_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $added[] = 'site_inquiries';
            }
        } catch (Exception $e) {
            Logger::error('SiteInquiry::migrate failed: ' . $e->getMessage());
            self::$migrated = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$migrated = true;
        return ['added' => $added, 'error' => null];
    }

    public static function all(array $params = [])
    {
        self::migrate();
        $db = Database::getInstance();
        $where = [];
        $bind = [];
        if (!empty($params['search'])) {
            $s = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR mobile LIKE ? OR message LIKE ? OR property_title LIKE ?)';
            array_push($bind, $s, $s, $s, $s);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM site_inquiries {$whereSql}", $bind);
        $rows = $db->fetchAll(
            "SELECT * FROM site_inquiries {$whereSql} ORDER BY COALESCE(inquired_at, created_at) DESC LIMIT {$offset}, {$limit}",
            $bind
        );
        foreach ($rows as &$r) {
            $r['inquired_jalali'] = !empty($r['inquired_at'])
                ? Jalali::fromGregorian($r['inquired_at'], 'Y/m/d H:i')
                : Jalali::fromGregorian($r['created_at'], 'Y/m/d H:i');
        }
        return ['items' => $rows, 'total' => $total];
    }

    public static function find($id)
    {
        self::migrate();
        return Database::getInstance()->fetchOne('SELECT * FROM site_inquiries WHERE id = ?', [(int)$id]) ?: null;
    }

    public static function upsertFromSite(array $data)
    {
        self::migrate();
        $db = Database::getInstance();
        $siteId = $data['site_id'] ?? null;
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        if (!empty($filtered['mobile'])) {
            $m = Helpers::sanitizeMobile($filtered['mobile']);
            if ($m !== false) $filtered['mobile'] = $m;
        }
        if (empty($filtered['status'])) $filtered['status'] = 'new';
        if (empty($filtered['source'])) $filtered['source'] = 'سایت';

        if ($siteId) {
            $existing = $db->fetchOne('SELECT id FROM site_inquiries WHERE site_id = ?', [(string)$siteId]);
            if ($existing) {
                $db->update('site_inquiries', $filtered, 'id = ?', [(int)$existing['id']]);
                return (int)$existing['id'];
            }
        }
        return $db->insert('site_inquiries', $filtered);
    }

    public static function updateStatus($id, $status, $assignee = null)
    {
        self::migrate();
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر');
        }
        $data = ['status' => $status];
        if ($assignee !== null) $data['assignee'] = $assignee;
        return Database::getInstance()->update('site_inquiries', $data, 'id = ?', [(int)$id]);
    }

    public static function convertToLead($id)
    {
        self::migrate();
        $inq = self::find($id);
        if (!$inq) Response::notFound('استعلام یافت نشد');
        if (!empty($inq['lead_id'])) {
            return ['lead_id' => (int)$inq['lead_id'], 'already_existed' => true];
        }

        $name = $inq['name'] ?: ('استعلام سایت - ' . ($inq['mobile'] ?: $inq['id']));
        $note = trim(($inq['property_title'] ? ('اقامتگاه: ' . $inq['property_title'] . "\n") : '') . ($inq['message'] ?? ''));
        $leadId = Lead::create([
            'name'        => $name,
            'mobile'      => $inq['mobile'] ?: null,
            'source'      => 'استعلام از سایت',
            'stage'       => 'جدید',
            'status'      => 'جدید',
            'note'        => $note,
            'description' => $note,
            'assignee'    => $inq['assignee'] ?: null,
            'operator'    => $inq['assignee'] ?: null,
            'reference'   => 'استعلام از سایت',
        ]);

        Database::getInstance()->update('site_inquiries', [
            'lead_id' => $leadId,
            'status'  => 'converted',
        ], 'id = ?', [(int)$id]);

        return ['lead_id' => $leadId, 'already_existed' => false];
    }

    public static function getStats()
    {
        self::migrate();
        $db = Database::getInstance();
        return [
            'total' => (int)$db->fetchColumn('SELECT COUNT(*) FROM site_inquiries'),
            'new'   => (int)$db->fetchColumn("SELECT COUNT(*) FROM site_inquiries WHERE status = 'new'"),
            'converted' => (int)$db->fetchColumn("SELECT COUNT(*) FROM site_inquiries WHERE status = 'converted'"),
        ];
    }
}
