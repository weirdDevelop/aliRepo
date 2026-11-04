<?php
/**
 * جاکجاست - لاگ تعاملات با API اعلان‌بان
 * Jakojast - Elanban API Log
 *
 * دقیقاً همان الگوی models/TelefonchyApiLog.php - جدول خودش را در
 * صورت نبود می‌سازد (idempotent، بدون نیاز به migration دستی).
 * توکن هرگز ذخیره نمی‌شود.
 */

class ElanbanApiLog
{
    /** @var bool کش وضعیت وجود جدول */
    private static $tableChecked = false;

    private static function ensureTable()
    {
        if (self::$tableChecked) return;
        try {
            $db = Database::getInstance();
            $db->execute("
                CREATE TABLE IF NOT EXISTS `elanban_api_logs` (
                    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `method`           VARCHAR(10)  DEFAULT NULL,
                    `endpoint`         VARCHAR(255) DEFAULT NULL,
                    `full_url`         VARCHAR(500) DEFAULT NULL,
                    `http_code`        SMALLINT     DEFAULT NULL,
                    `success`          TINYINT(1)   NOT NULL DEFAULT 0,
                    `error_message`    TEXT         DEFAULT NULL,
                    `response_preview` TEXT         DEFAULT NULL,
                    `duration_ms`      INT          DEFAULT NULL,
                    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_success` (`success`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$tableChecked = true;
        } catch (Exception $e) {
            Logger::error('ElanbanApiLog: failed to ensure table exists: ' . $e->getMessage());
        }
    }

    public static function log(array $data)
    {
        self::ensureTable();
        try {
            $db = Database::getInstance();

            $fullUrl = $data['full_url'] ?? null;
            if ($fullUrl) {
                $fullUrl = preg_replace('/([?&]token=)[^&]+/i', '$1[REDACTED]', $fullUrl);
            }

            $db->insert('elanban_api_logs', [
                'method'           => $data['method'] ?? null,
                'endpoint'         => $data['endpoint'] ?? null,
                'full_url'         => $fullUrl,
                'http_code'        => $data['http_code'] ?? null,
                'success'          => !empty($data['success']) ? 1 : 0,
                'error_message'    => $data['error_message'] ?? null,
                'response_preview' => isset($data['response_preview']) ? mb_substr((string)$data['response_preview'], 0, 2000) : null,
                'duration_ms'      => $data['duration_ms'] ?? null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            Logger::error('ElanbanApiLog: failed to write log: ' . $e->getMessage());
        }
    }

    public static function all(array $params = [])
    {
        self::ensureTable();
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['only_errors'])) {
            $where[] = 'success = 0';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limit = min((int)($params['limit'] ?? 50), 200);
        $page = max((int)($params['page'] ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM elanban_api_logs {$whereSql}", $bind);
        $items = $db->fetchAll(
            "SELECT * FROM elanban_api_logs {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind
        );

        return ['items' => $items, 'total' => $total];
    }

    public static function clear()
    {
        self::ensureTable();
        Database::getInstance()->execute('TRUNCATE TABLE elanban_api_logs');
    }

    /** پاک‌سازی لاگ‌های قدیمی (برای cron، هم‌الگوی TelefonchyApiLog::purgeOlderThan) */
    public static function purgeOlderThan($days = 30)
    {
        self::ensureTable();
        return Database::getInstance()->execute(
            'DELETE FROM elanban_api_logs WHERE created_at < (NOW() - INTERVAL ? DAY)',
            [(int)$days]
        );
    }
}
