<?php
/**
 * جاکجاست - کلاینت API اعلان‌بان
 * Jakojast - Elanban API Client
 *
 * هدف: گرفتن تاریخچه‌ی چت (اینستاگرام + وب‌چت سایت + سایر کانال‌های
 * اعلان‌بان) یک شخص بر اساس شماره تلفن، برای نمایش کنار تماس‌ها در
 * پروفایل مشتری/سرنخ.
 *
 * دقیقاً همان الگوی api_integration/TelefonchyAPI.php: کلاینت مجزا با
 * توکن از جدول settings، تایم‌اوت کوتاه (چون این درخواست همزمان با باز
 * شدن یک تب توی پروفایل مشتری می‌آید و نباید کاربر را معطل کند)، و
 * لاگ کامل هر تعامل در ElanbanApiLog برای دیباگ بدون نیاز به SSH.
 *
 * کلید تطبیق: شماره تلفن، با همان الگوی ۱۰ رقم آخر که در کل جاکجاست
 * استفاده می‌شود ($last10 = substr(preg_replace('/\D+/','',$mobile), -10)).
 * سمت اعلان‌بان هم با Persons::normalizePhone() نرمال می‌کند؛ فرمت
 * نهایی فرقی نمی‌کند چون فقط ۱۰ رقم آخر مقایسه می‌شود.
 */

class ElanbanAPI
{
    /** @var string|null توکن مشترک داخلی (نه توکن عمومی/کاربری) */
    private $token;

    /** @var string آدرس پایه‌ی نصب اعلان‌بان، بدون / انتهایی */
    private $baseUrl;

    /** @var int timeout درخواست (ثانیه) - عمداً کوتاه، این یک تب داخل صفحه‌ی پروفایل است */
    private $timeout = 6;

    public function __construct()
    {
        // سیستم یکپارچه: اعلان‌بان پوشه‌ی والد است؛ آدرس و کلید خودکار (بدون تنظیم دستی)
        if (defined('CRM_BRIDGED') && CRM_BRIDGED && defined('CRM_BRIDGE_KEY') && CRM_BRIDGE_KEY !== '') {
            $this->token   = CRM_BRIDGE_KEY;
            $this->baseUrl = self::bridgeBaseUrl();
            return;
        }
        $this->token   = Report::getSetting('elanban_token');
        $this->baseUrl = rtrim(Report::getSetting('elanban_base_url') ?: '', '/');
    }

    /** آدرس اعلان‌بان: base_url در config اعلان‌بان، وگرنه از همین درخواست */
    public static function bridgeBaseUrl()
    {
        // ۲.۰.۲: در درخواست وب، همان دامنه‌ای که کاربر با آن وارد شده (مثلاً www) — نه base_url، که ممکن است به www ریدایرکت (301) شود
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '' && PHP_SAPI !== 'cli') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                  || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $dir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/crm/x.php')));
            if (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'api_external') $dir = dirname($dir);
            return ($https ? 'https://' : 'http://') . $host . rtrim($dir, '/');
        }
        return (defined('EB_BASE_URL') && EB_BASE_URL !== '') ? EB_BASE_URL : '';
    }

    /** گزینه‌های cURL مشترک: دنبال کردن ریدایرکت (مثلاً بدون www ← www) بدون تبدیل POST به GET */
    public static function curlRedirectOpts()
    {
        $o = [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3];
        if (defined('CURLOPT_POSTREDIR')) $o[CURLOPT_POSTREDIR] = 7;   // 301/302/303 همچنان POST
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) $o[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        return $o;
    }

    public function isConfigured()
    {
        return !empty($this->token) && !empty($this->baseUrl);
    }

    /**
     * دریافت پروفایل/تاریخچه‌ی چت یک شخص بر اساس شماره تلفن.
     *
     * @param string $mobile شماره خام (هر فرمتی - نرمال‌سازی داخل همین متد انجام می‌شود)
     * @return array {
     *   success: bool,
     *   found:   bool (فقط وقتی success=true معتبر است)،
     *   data:    array|null {person_id, full_name, phone_status, top_channel,
     *                         message_count, last_activity, identities[], recent_messages[]},
     *   message: string (پیام خطا در صورت success=false)
     * }
     */
    public function lookupByPhone($mobile)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'found' => false, 'data' => null,
                     'message' => 'آدرس یا توکن اعلان‌بان تنظیم نشده است.'];
        }

        $last10 = substr(preg_replace('/\D+/', '', (string)$mobile), -10);
        if (strlen($last10) < 10) {
            return ['success' => false, 'found' => false, 'data' => null,
                     'message' => 'شماره موبایل نامعتبر است.'];
        }

        return $this->request('GET', '/api/internal_lookup.php', ['phone' => $last10]);
    }

    /**
     * اجرای درخواست HTTP + لاگ کامل (موفق یا ناموفق) در ElanbanApiLog.
     */
    private function request($method, $endpoint, array $query = [])
    {
        $url = $this->baseUrl . $endpoint;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, self::curlRedirectOpts());
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'X-Internal-Token: ' . $this->token,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($curlErrNo !== 0) {
            Logger::error('Elanban API connection error', ['endpoint' => $endpoint, 'error' => $error]);
            ElanbanApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'http_code' => $httpCode, 'success' => false,
                'error_message' => "خطای اتصال (curl #{$curlErrNo}): {$error}",
                'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'found' => false, 'data' => null,
                     'message' => 'خطا در اتصال به اعلان‌بان: ' . $error];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Logger::error('Elanban API invalid JSON', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'body' => substr((string)$body, 0, 500)]);
            ElanbanApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'http_code' => $httpCode, 'success' => false,
                'error_message' => 'پاسخ JSON نامعتبر بود',
                'response_preview' => substr((string)$body, 0, 500), 'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'found' => false, 'data' => null,
                     'message' => "پاسخ نامعتبر از اعلان‌بان (HTTP {$httpCode})."];
        }

        if ($httpCode !== 200 || empty($decoded['ok'])) {
            $message = $decoded['error'] ?? "خطا از سمت اعلان‌بان (HTTP {$httpCode})";
            Logger::error('Elanban API error', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'message' => $message]);
            ElanbanApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'http_code' => $httpCode, 'success' => false, 'error_message' => $message,
                'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'found' => false, 'data' => null, 'message' => $message];
        }

        ElanbanApiLog::log([
            'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
            'http_code' => $httpCode, 'success' => true, 'duration_ms' => $durationMs,
            'response_preview' => !empty($decoded['found']) ? 'found person_id=' . ($decoded['data']['person_id'] ?? '?') : 'not found',
        ]);

        return [
            'success' => true,
            'found'   => !empty($decoded['found']),
            'data'    => $decoded['data'] ?? null,
            'message' => '',
        ];
    }

    public function setTimeout($seconds)
    {
        $this->timeout = (int)$seconds;
    }
}
