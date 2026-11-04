<?php
/**
 * channels/sms/MelipayamakProvider.php
 * ملی‌پیامک — ارسال ساده با curl مستقیم (بدون composer).
 *
 * API (REST): https://rest.payamak-panel.com/api/SendSMS/SendSMS
 * پارامترها: username, password, to, from, text
 * دریافت پاسخ: GetMessages برای خط اختصاصی.
 *
 * تنظیمات لازم: username, password, sender (خط اختصاصی)
 *
 * نکته: ملی‌پیامک به‌جای api_key از username/password استفاده می‌کند.
 * برای یکدستی با رابط، apiKey را به‌صورت "username:password" می‌گیریم.
 */

class MelipayamakProvider implements SmsProviderInterface
{
    private string $username;
    private string $password;
    private string $sender;

    public function __construct(string $apiKey, string $sender = '')
    {
        // apiKey به فرمت "username:password"
        $parts = explode(':', $apiKey, 2);
        $this->username = $parts[0] ?? '';
        $this->password = $parts[1] ?? '';
        $this->sender   = $sender;
    }

    public function name(): string { return 'melipayamak'; }

    private function call(string $path, array $params): array
    {
        $url = "https://rest.payamak-panel.com/api/SendSMS/{$path}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['content-type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['http' => $code, 'body' => json_decode($res, true), 'raw' => $res, 'curl_error' => $err];
    }

    public function sendSms(string $phone, string $text): array
    {
        // طبق کد آزموده‌شده‌ی واقعی: SendSMS با username/password/to/from/text/isflash
        $r = $this->call('SendSMS', [
            'username' => $this->username,
            'password' => $this->password,
            'to'       => $phone,
            'from'     => $this->sender,
            'text'     => $text,
            'isflash'  => 'false',
        ]);

        // پاسخ ملی‌پیامک: { "Value": "...", "RetStatus": 1, "StrRetStatus": "Ok" }
        // RetStatus == 1 یعنی موفق. Value در موفقیت یک شناسه‌ی عددی بلند است.
        // اگر خطای شبکه/کرل بود، body ممکن است null باشد.
        $body = $r['body'];
        $ok = false;
        $error = null;
        $msgId = null;

        if (is_array($body)) {
            $status = $body['RetStatus'] ?? null;
            $ok = ((string)$status === '1');
            if (!$ok) {
                $error = $body['StrRetStatus'] ?? ('RetStatus=' . var_export($status, true));
            } else {
                $msgId = (string)($body['Value'] ?? '');
            }
        } else {
            // گاهی پاسخ فقط یک رشته/عدد است (نه JSON)
            $raw = trim((string)$r['raw'], " \t\n\r\"");
            // عدد بلند (≥15 رقم) = شناسه‌ی پیام موفق
            if (preg_match('/^\d{10,}$/', $raw)) {
                $ok = true; $msgId = $raw;
            } else {
                $error = $r['curl_error'] ?: ('پاسخ غیرمنتظره: ' . mb_substr($raw, 0, 80));
            }
        }

        return ['ok' => $ok, 'error' => $error, 'msg_id' => $msgId, 'raw' => $r];
    }

    /**
     * استعلام وضعیت تحویل (DLR) از ملی‌پیامک با recId.
     * بر اساس الگوی REST ملی‌پیامک: GetDeliveries2.
     * خروجی: 'delivered' | 'failed' | 'pending' | null (نامشخص)
     * نکته: ساختار دقیق پاسخ باید روی سرور واقعی تأیید شود؛ منطق محافظه‌کارانه است.
     */
    public function deliveryStatus(string $msgId): ?string
    {
        $r = $this->call('GetDeliveries2', [
            'username' => $this->username,
            'password' => $this->password,
            'recId'    => $msgId,
        ]);
        $body = $r['body'];
        if (!is_array($body)) return null;
        $val = (string)($body['Value'] ?? '');
        // ملی‌پیامک معمولاً وضعیت را به‌صورت کد برمی‌گرداند: 1=رسید, 2=نرسید
        if ($val === '' ) return 'pending';
        if (strpos($val, '1') === 0) return 'delivered';
        if (strpos($val, '2') === 0) return 'failed';
        return 'pending';
    }

    public function fetchInbound(): array
    {
        // ملی‌پیامک: GetMessages برای دریافت پیام‌های ورودی خط اختصاصی
        if ($this->username === '') return [];
        $r = $this->call('GetMessages', [
            'username' => $this->username,
            'password' => $this->password,
            'location' => 1,   // 1 = inbox
            'count'    => 100,
        ]);
        $rows = $r['body']['Value'] ?? null;
        if (!is_array($rows)) return [];
        $out = [];
        foreach ($rows as $e) {
            // ساختار هر ردیف بسته به پاسخ ملی‌پیامک
            $out[] = [
                'phone'       => $e['From'] ?? ($e['from'] ?? ''),
                'text'        => $e['Text'] ?? ($e['text'] ?? ''),
                'received_at' => $e['DateTime'] ?? null,
                'raw'         => $e,
            ];
        }
        return $out;
    }

    public function parseWebhook(array $payload): ?array
    {
        // ملی‌پیامک عمدتاً pull است؛ وب‌هوک استاندارد عمومی ندارد.
        return null;
    }
}
