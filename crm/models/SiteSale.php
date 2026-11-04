<?php
/**
 * لیست فروش / رزروهای سایت در CRM
 */

class SiteSale
{
    private static $migrated = false;

    private static $fillable = [
        'site_id', 'order_code', 'customer_name', 'customer_mobile', 'host_name',
        'property_title', 'city', 'check_in', 'check_out', 'nights', 'amount',
        'commission', 'status', 'customer_id', 'host_id', 'sold_at', 'raw_payload',
    ];

    public static $statuses = ['pending', 'confirmed', 'cancelled', 'completed', 'refunded'];

    public static function migrate()
    {
        if (self::$migrated) return ['added' => [], 'error' => null];
        $added = [];
        try {
            $db = Database::getInstance();
            $exists = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'site_sales'"
            );
            if (!$exists) {
                $db->execute("CREATE TABLE IF NOT EXISTS `site_sales` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `site_id` VARCHAR(64) DEFAULT NULL,
                    `order_code` VARCHAR(80) DEFAULT NULL,
                    `customer_name` VARCHAR(150) DEFAULT NULL,
                    `customer_mobile` VARCHAR(20) DEFAULT NULL,
                    `host_name` VARCHAR(150) DEFAULT NULL,
                    `property_title` VARCHAR(200) DEFAULT NULL,
                    `city` VARCHAR(80) DEFAULT NULL,
                    `check_in` VARCHAR(20) DEFAULT NULL,
                    `check_out` VARCHAR(20) DEFAULT NULL,
                    `nights` INT UNSIGNED DEFAULT NULL,
                    `amount` BIGINT NOT NULL DEFAULT 0,
                    `commission` BIGINT NOT NULL DEFAULT 0,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',
                    `customer_id` INT UNSIGNED DEFAULT NULL,
                    `host_id` INT UNSIGNED DEFAULT NULL,
                    `sold_at` DATETIME DEFAULT NULL,
                    `raw_payload` MEDIUMTEXT DEFAULT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_site_id` (`site_id`),
                    KEY `idx_order` (`order_code`),
                    KEY `idx_mobile` (`customer_mobile`),
                    KEY `idx_sold` (`sold_at`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $added[] = 'site_sales';
            }
        } catch (Exception $e) {
            Logger::error('SiteSale::migrate failed: ' . $e->getMessage());
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
            $where[] = '(order_code LIKE ? OR customer_name LIKE ? OR customer_mobile LIKE ? OR property_title LIKE ? OR host_name LIKE ?)';
            array_push($bind, $s, $s, $s, $s, $s);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['from_date'])) {
            $g = Jalali::toGregorianString($params['from_date']);
            if ($g) { $where[] = 'sold_at >= ?'; $bind[] = $g; }
        }
        if (!empty($params['to_date'])) {
            $g = Jalali::toGregorianString($params['to_date']);
            if ($g) { $where[] = 'sold_at <= ?'; $bind[] = substr($g, 0, 10) . ' 23:59:59'; }
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM site_sales {$whereSql}", $bind);
        $sum = (int)$db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM site_sales {$whereSql}", $bind);
        $rows = $db->fetchAll(
            "SELECT * FROM site_sales {$whereSql} ORDER BY COALESCE(sold_at, created_at) DESC LIMIT {$offset}, {$limit}",
            $bind
        );
        foreach ($rows as &$r) {
            $r['sold_jalali'] = !empty($r['sold_at'])
                ? Jalali::fromGregorian($r['sold_at'], 'Y/m/d H:i')
                : '-';
        }
        return ['items' => $rows, 'total' => $total, 'sum_amount' => $sum];
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
        if (!empty($filtered['customer_mobile'])) {
            $m = Helpers::sanitizeMobile($filtered['customer_mobile']);
            if ($m !== false) $filtered['customer_mobile'] = $m;
        }
        if (empty($filtered['status'])) $filtered['status'] = 'confirmed';

        if ($siteId) {
            $existing = $db->fetchOne('SELECT id FROM site_sales WHERE site_id = ?', [(string)$siteId]);
            if ($existing) {
                $db->update('site_sales', $filtered, 'id = ?', [(int)$existing['id']]);
                return (int)$existing['id'];
            }
        }
        return $db->insert('site_sales', $filtered);
    }

    public static function getStats()
    {
        self::migrate();
        $db = Database::getInstance();
        return [
            'total'  => (int)$db->fetchColumn('SELECT COUNT(*) FROM site_sales'),
            'amount' => (int)$db->fetchColumn('SELECT COALESCE(SUM(amount),0) FROM site_sales WHERE status IN (\'confirmed\',\'completed\')'),
            'today'  => (int)$db->fetchColumn('SELECT COUNT(*) FROM site_sales WHERE DATE(sold_at) = CURDATE()'),
        ];
    }
}
