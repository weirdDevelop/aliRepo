<?php
/**
 * جاکجاست - لاگ حرفه‌ای تعاملات با API تلفنچی
 * Jakojast - Telefonchy API Log
 *
 * هدف: امکان دیباگ سریع مشکلات اتصال به تلفنچی بدون نیاز به SSH یا
 * دسترسی به لاگ فایل سرور. هر درخواست خروجی (outbound) به تلفنچی و
 * هر webhook دریافتی، با جزئیات کامل (endpoint، پارامترها، کد HTTP،
 * زمان پاسخ، پیام خطا، و خلاصه‌ی پاسخ) این‌جا ثبت می‌شود.
 *
 * توکن هرگز در این جدول ذخیره نمی‌شود (حذف می‌شود قبل از ثبت).
 */

class TelefonchyApiLog
{
    /** @var bool|null کش وضعیت وجود جدول */
    private static $tableChecked = false;

    /**
     * اطمینان از وجود جدول (ایجاد خودکار در صورت نبود - بدون نیاز به migration دستی)
     */
    private static function ensureTable()
    {
        if (self::$tableChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$tableChecked = true; return; }
        try {
            $db = Database::getInstance();
            $db->execute("
                CREATE TABLE IF NOT EXISTS `telefonchy_api_logs` (
                    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `direction`        ENUM('outbound','webhook') NOT NULL DEFAULT 'outbound',
                    `method`           VARCHAR(10)  DEFAULT NULL,
                    `endpoint`         VARCHAR(255) DEFAULT NULL,
                    `full_url`         VARCHAR(500) DEFAULT NULL,
                    `request_params`   JSON         DEFAULT NULL,
                    `http_code`        SMALLINT     DEFAULT NULL,
                    `success`          TINYINT(1)   NOT NULL DEFAULT 0,
                    `error_message`    TEXT         DEFAULT NULL,
                    `response_preview` TEXT         DEFAULT NULL,
                    `duration_ms`      INT          DEFAULT NULL,
                    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_endpoint` (`endpoint`),
                    KEY `idx_success` (`success`),
                    KEY `idx_http_code` (`http_code`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            self::$tableChecked = true;
        } catch (Exception $e) {
            // اگر ساخت جدول شکست خورد، فقط در فایل لاگ عادی می‌نویسیم و برنامه را متوقف نمی‌کنیم
            $__schemaFailed = true; Logger::error('TelefonchyApiLog: failed to ensure table exists: ' . $e->getMessage());
        }
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    /**
     * ثبت یک تعامل با تلفنچی
     *
     * @param array $data {
     *   direction, method, endpoint, full_url, request_params (array),
     *   http_code, success (bool), error_message, response_preview, duration_ms
     * }
     */
    public static function log(array $data)
    {
        self::ensureTable();
        try {
            $db = Database::getInstance();

            // پاک‌سازی توکن از پارامترها و URL قبل از ذخیره (امنیت)
            $params = $data['request_params'] ?? [];
            unset($params['webservice-token'], $params['webservice_token'], $params['token']);

            $fullUrl = $data['full_url'] ?? null;
            if ($fullUrl) {
                $fullUrl = preg_replace('/([?&](?:webservice[_-]token|token)=)[^&]+/i', '$1[REDACTED]', $fullUrl);
            }

            $db->insert('telefonchy_api_logs', [
                'direction'        => $data['direction'] ?? 'outbound',
                'method'           => $data['method'] ?? null,
                'endpoint'         => $data['endpoint'] ?? null,
                'full_url'         => $fullUrl,
                'request_params'   => !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                'http_code'        => $data['http_code'] ?? null,
                'success'          => !empty($data['success']) ? 1 : 0,
                'error_message'    => $data['error_message'] ?? null,
                'response_preview' => isset($data['response_preview']) ? mb_substr((string)$data['response_preview'], 0, 2000) : null,
                'duration_ms'      => $data['duration_ms'] ?? null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            // لاگ‌گیری هرگز نباید باعث شکست عملیات اصلی شود
            Logger::error('TelefonchyApiLog: failed to write log: ' . $e->getMessage());
        }
    }

    /**
     * دریافت لیست لاگ‌ها (برای نمایش در پنل تنظیمات)
     *
     * @param array $params {only_errors, direction, limit, page}
     * @return array {items, total}
     */
    public static function all(array $params = [])
    {
        self::ensureTable();
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['only_errors'])) {
            $where[] = 'success = 0';
        }
        if (!empty($params['direction'])) {
            $where[] = 'direction = ?';
            $bind[] = $params['direction'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limit = min((int)($params['limit'] ?? 50), 200);
        $page = max((int)($params['page'] ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM telefonchy_api_logs {$whereSql}", $bind);

        $items = $db->fetchAll(
            "SELECT * FROM telefonchy_api_logs {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind
        );

        foreach ($items as &$item) {
            if (!empty($item['request_params'])) {
                $item['request_params'] = json_decode($item['request_params'], true);
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * آمار سریع (برای نمایش در بالای صفحه تنظیمات: چند درصد درخواست‌ها موفق بوده‌اند و ...)
     *
     * @return array
     */
    public static function getStats()
    {
        self::ensureTable();
        $db = Database::getInstance();

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM telefonchy_api_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR)");
        $failed = (int)$db->fetchColumn("SELECT COUNT(*) FROM telefonchy_api_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR) AND success = 0");
        $avgDuration = $db->fetchColumn("SELECT AVG(duration_ms) FROM telefonchy_api_logs WHERE created_at >= (NOW() - INTERVAL 24 HOUR) AND success = 1");
        $lastError = $db->fetchOne("SELECT * FROM telefonchy_api_logs WHERE success = 0 ORDER BY id DESC LIMIT 1");

        return [
            'last_24h_total'    => $total,
            'last_24h_failed'   => $failed,
            'last_24h_avg_ms'   => $avgDuration ? round((float)$avgDuration) : null,
            'last_error'        => $lastError,
        ];
    }

    /**
     * پاک کردن همه‌ی لاگ‌ها (دکمه «پاک کردن لاگ‌ها»)
     */
    public static function clear()
    {
        self::ensureTable();
        Database::getInstance()->execute('TRUNCATE TABLE telefonchy_api_logs');
    }

    /**
     * ⚠️ این جدول قبلاً هیچ سقف/پاکسازی نداشت - با هر تماس، وب‌هوک،
     * backfill و... رکورد جدید اضافه می‌شود و با گذشت چند ماه (خصوصاً با
     * ۲۰ کاربر فعال) می‌تواند به میلیون‌ها ردیف برسد و کوئری‌های داشبورد
     * را کند کند. این متد فقط لاگ‌های قدیمی‌تر از N روز را حذف می‌کند و
     * باید دوره‌ای (از cron_sync.php) صدا زده شود.
     *
     * @param int $days
     * @return int تعداد ردیف‌های پاک‌شده
     */
    public static function purgeOlderThan($days = 30)
    {
        self::ensureTable();
        return Database::getInstance()->execute(
            'DELETE FROM telefonchy_api_logs WHERE created_at < (NOW() - INTERVAL ? DAY)',
            [(int)$days]
        );
    }
}
