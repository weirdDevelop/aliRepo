<?php
/**
 * channels/sms/SmsirProvider.php
 * sms.ir — ارسال انبوه و دریافت پاسخ با curl مستقیم (بدون composer).
 *
 * ارسال:  POST https://api.sms.ir/v1/send/bulk
 *         هدر: X-API-KEY ، بدنه JSON: lineNumber, messageText, mobiles[]
 * دریافت: GET  https://api.sms.ir/v1/receive/latest?count=100
 *
 * تنظیمات لازم: api_key، line_number (خط اختصاصی)
 */

class SmsirProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $line;

    public function __construct(string $apiKey, string $line = '')
    {
        $this->apiKey = $apiKey;
        $this->line   = $line;
    }

    public function name(): string { return 'smsir'; }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init('https://api.sms.ir/v1/' . ltrim($path, '/'));
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http' => $code, 'body' => json_decode($res, true), 'raw' => $res];
    }

    public function sendSms(string $phone, string $text): array
    {
        // sms.ir شماره را با 0 می‌خواهد (09...). از 98 به 0 تبدیل می‌کنیم.
        $local = preg_replace('/^98/', '0', $phone);
        $r = $this->request('POST', 'send/bulk', [
            'lineNumber'   => $this->line !== '' ? (int)$this->line : null,
            'messageText'  => $text,
            'mobiles'      => [$local],
            'sendDateTime' => null,
        ]);
        // ساختار موفقیت sms.ir: status == 1
        $status = $r['body']['status'] ?? 0;
        $ok = ($status == 1);
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['message'] ?? 'sms_failed'), 'raw' => $r];
    }

    public function fetchInbound(): array
    {
        $r = $this->request('GET', 'receive/latest?count=100');
        $items = $r['body']['data'] ?? null;
        if (!is_array($items)) return [];
        $out = [];
        foreach ($items as $e) {
            $num = $e['number'] ?? ($e['mobile'] ?? '');
            $out[] = [
                'phone'       => $num,
                'text'        => $e['messageText'] ?? ($e['message'] ?? ''),
                'received_at' => $e['receivedDateTime'] ?? null,
                'raw'         => $e,
            ];
        }
        return $out;
    }

    public function parseWebhook(array $payload): ?array
    {
        return null; // sms.ir با pull کار می‌کند
    }
}
