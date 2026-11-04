<?php
/**
 * modules/JakojastLookup.php
 * کلاینت طرف اعلان‌بان برای پرسیدن وضعیت سرنخ/مشتری/تماس از جاکجاست
 * (جهت برعکسِ api/internal_lookup.php - آنجا جاکجاست از ما می‌پرسد،
 * اینجا ما از جاکجاست می‌پرسیم).
 *
 * تنظیمات از config.php خوانده می‌شود (نه eb_settings)، چون سمت جاکجاست
 * هم توکنش را در config.php نگه می‌دارد - یکسان‌سازی محل نگهداری راز.
 * کلیدهای لازم در config.php:
 *   'jakojast_base_url'      => آدرس پایه‌ی نصب جاکجاست (بدون / انتها)
 *   'jakojast_outgoing_token' => باید برابر با 'توکن ورودی' که در
 *                                 تنظیمات جاکجاست ثبت شده باشد
 *
 * لاگ با ApiLog موجود (channel='jakojast_lookup') - همان کلاسی که برای
 * بقیه‌ی سرویس‌های خارجی (صفیر، پیامک، ...) استفاده می‌شود.
 */

class JakojastLookup
{
    private static function baseUrl(): ?string
    {
        global $config;
        $url = is_array($config ?? null) ? ($config['jakojast_base_url'] ?? null) : null;
        return $url ? rtrim($url, '/') : null;
    }

    private static function token(): ?string
    {
        global $config;
        return is_array($config ?? null) ? ($config['jakojast_outgoing_token'] ?? null) : null;
    }

    public static function isConfigured(): bool
    {
        return (bool) self::baseUrl() && (bool) self::token();
    }

    /**
     * @param string|null $phone
     * @return array {configured:bool, found:bool, data:array|null, error:?string}
     */
    public static function lookupByPhone(?string $phone): array
    {
        // ۲.۰.۰: CRM داخل همین سیستم است؛ مستقیم از دیتابیس (بدون درخواست شبکه و توکن)
        if (class_exists('CrmBridge') && CrmBridge::present() && CrmBridge::ready()) {
            return CrmBridge::legacyLookup($phone);
        }
        if (!$phone) {
            return ['configured' => self::isConfigured(), 'found' => false, 'data' => null, 'error' => null];
        }
        if (!self::isConfigured()) {
            return ['configured' => false, 'found' => false, 'data' => null, 'error' => null];
        }

        $url = self::baseUrl() . '/api_external/elanban_lookup.php?' . http_build_query(['phone' => $phone]);
        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,   // کوتاه عمداً - این جزئی از باز شدن پروفایل در پنل است
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => ['X-Internal-Token: ' . self::token(), 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($errNo !== 0) {
            ApiLog::record('jakojast_lookup', 'lookup_by_phone', ['phone' => $phone], null, $httpCode, false, $err);
            return ['configured' => true, 'found' => false, 'data' => null, 'error' => 'خطا در اتصال به جاکجاست: ' . $err];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $msg = is_array($decoded) ? ($decoded['error'] ?? 'خطای نامشخص') : 'پاسخ نامعتبر';
            ApiLog::record('jakojast_lookup', 'lookup_by_phone', ['phone' => $phone], $decoded, $httpCode, false, $msg);
            return ['configured' => true, 'found' => false, 'data' => null, 'error' => $msg];
        }

        ApiLog::record('jakojast_lookup', 'lookup_by_phone', ['phone' => $phone], ['found' => $decoded['found']], $httpCode, true, null);

        return [
            'configured' => true,
            'found'      => !empty($decoded['found']),
            'data'       => $decoded['data'] ?? null,
            'error'      => null,
        ];
    }
}
