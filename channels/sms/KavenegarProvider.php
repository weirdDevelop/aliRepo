<?php
/**
 * channels/sms/KavenegarProvider.php
 * کاوه‌نگار — ارسال ساده با curl مستقیم (بدون composer).
 *
 * API: https://api.kavenegar.com/v1/{API-KEY}/sms/send.json
 * پارامترها: receptor, sender, message
 * دریافت پاسخ: متد receive (pull) برای خط اختصاصی.
 *
 * تنظیمات لازم: api_key، sender (خط اختصاصی)
 */

class KavenegarProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $sender;

    public function __construct(string $apiKey, string $sender = '')
    {
        $this->apiKey = $apiKey;
        $this->sender = $sender;
    }

    public function name(): string { return 'kavenegar'; }

    private function call(string $method, array $params): array
    {
        $url = "https://api.kavenegar.com/v1/{$this->apiKey}/{$method}.json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $code, 'body' => json_decode($res, true), 'raw' => $res];
    }

    public function sendSms(string $phone, string $text): array
    {
        $r = $this->call('sms/send', [
            'receptor' => $phone,
            'sender'   => $this->sender,
            'message'  => $text,
        ]);
        // ساختار موفقیت کاوه‌نگار: return.status == 200
        $status = $r['body']['return']['status'] ?? 0;
        $ok = ($status == 200);
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['return']['message'] ?? 'sms_failed'), 'raw' => $r];
    }

    public function fetchInbound(): array
    {
        // کاوه‌نگار: متد receive با line number و isRead — pull برای خط اختصاصی
        if ($this->sender === '') return [];
        $r = $this->call('sms/receive', ['linenumber' => $this->sender, 'isread' => 0]);
        $entries = $r['body']['entries'] ?? null;
        if (!is_array($entries)) return [];
        $out = [];
        foreach ($entries as $e) {
            $out[] = [
                'phone'       => $e['sender'] ?? '',
                'text'        => $e['message'] ?? '',
                'received_at' => isset($e['date']) ? date('Y-m-d H:i:s', (int)$e['date']) : null,
                'raw'         => $e,
            ];
        }
        return $out;
    }

    public function parseWebhook(array $payload): ?array
    {
        // کاوه‌نگار وب‌هوک هم دارد ولی روش pull رایج‌تر است؛ فعلاً null.
        return null;
    }
}
