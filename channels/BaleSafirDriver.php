<?php
/**
 * channels/BaleSafirDriver.php
 * کانال صفیر بله — ارسال پیام انبوه با شماره تماس.
 *
 * تفاوت بنیادی با ربات بله: صفیر با «شماره موبایل» کار می‌کند، نه chat_id.
 * می‌تواند به کسانی پیام دهد که هرگز با ربات تعامل نداشته‌اند.
 *
 * احراز هویت: هدر api-access-key (کلید سازمان) + bot_id صفیر.
 * این‌ها با توکن ربات بله فرق دارند و جدا تنظیم می‌شوند.
 *
 * منبع منطق: broadcast.php (نسخه‌ی آزموده‌شده).
 */

class BaleSafirDriver implements ChannelInterface
{
    const API_BASE = 'https://safir.bale.ai/api/v3';

    private string $accessKey;
    private string $botId;

    public function __construct(string $accessKey, string $botId)
    {
        $this->accessKey = $accessKey;
        $this->botId     = $botId;
    }

    public function name(): string
    {
        return 'bale_safir';
    }

    public function capabilities(): array
    {
        // ویژگی کلیدی: ارسال انبوه با شماره. دریافت پاسخ ندارد.
        return ['text', 'photo', 'buttons', 'bulk_phone', 'otp'];
    }

    /** فراخوانی پایه‌ی API صفیر */
    private function call(string $endpoint, array $payload): array
    {
        $ch = curl_init(self::API_BASE . '/' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'api-access-key: ' . $this->accessKey,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['http_code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
    }

    /** آپلود فایل به صفیر و دریافت file_id */
    public function uploadFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) return null;
        $ch = curl_init(self::API_BASE . '/upload_file');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile(realpath($filePath))],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['api-access-key: ' . $this->accessKey],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $d = json_decode($res, true);
        if (is_array($d)) {
            foreach (['file_id', 'id', 'data'] as $k) {
                if (isset($d[$k]) && is_string($d[$k])) return $d[$k];
                if (isset($d[$k]['file_id'])) return $d[$k]['file_id'];
                if (isset($d[$k]['id']))      return $d[$k]['id'];
            }
            if (isset($d['result']['file_id'])) return $d['result']['file_id'];
        }
        return null;
    }

    /**
     * ارسال به یک گیرنده. صفیر با شماره کار می‌کند،
     * پس recipient باید 'phone' داشته باشد (فرمت 98...).
     */
    public function send(array $recipient, array $message): array
    {
        $phone = $recipient['phone'] ?? null;
        if (!$phone) {
            return ['ok' => false, 'error' => 'no_phone'];
        }
        $phone = Persons::intlPhone($phone);   // ۱.۰.۱۴: وب‌سرویس صفیر قالب 98… می‌گیرد؛ ذخیره 09… است
        if (!$phone) {
            return ['ok' => false, 'error' => 'invalid_phone'];
        }

        // ساخت message_data
        $fileId = $message['file_id'] ?? null;
        if (!empty($message['photo']) && file_exists($message['photo']) && !$fileId) {
            $fileId = $this->uploadFile($message['photo']);
            if ($fileId === null) {
                return ['ok' => false, 'error' => 'upload_failed'];
            }
        }

        // طبق مستندات رسمی صفیر: عکس با file_id داخل message، و text همان کپشن است.
        // ساختار: message_data.message.{text, file_id?}
        $inner = ['text' => $message['text'] ?? ''];
        if ($fileId) {
            $inner['file_id'] = $fileId;
        }
        if (!empty($message['buttons'])) {
            $inner['reply_markup'] = ['inline_keyboard' => $message['buttons']];
        }
        $msgData = ['message' => $inner];

        $payload = [
            'request_id'   => bin2hex(random_bytes(8)),
            'bot_id'       => (int) $this->botId,
            'phone_number' => (string) $phone,
            'message_data' => $msgData,
        ];

        $r = $this->call('send_message', $payload);

        $ok = false;
        if (isset($r['body']) && is_array($r['body'])) {
            if (!empty($r['body']['ok']) || !empty($r['body']['success'])) $ok = true;
            if ($r['http_code'] >= 200 && $r['http_code'] < 300 && empty($r['body']['error'])) $ok = true;
        } elseif ($r['http_code'] >= 200 && $r['http_code'] < 300) {
            $ok = true;
        }
        $err = $ok ? null : ($r['body']['error'] ?? ('http_' . $r['http_code']));
        if (class_exists('ApiLog')) {
            ApiLog::record('bale_safir', 'send_message', $payload, $r['body'] ?? $r['raw'] ?? null, $r['http_code'] ?? null, $ok, $err);
        }
        return ['ok' => $ok, 'error' => $err, 'raw' => $r];
    }

    /**
     * دریافت پاسخ صفیر.
     *
     * ⚠️ محدودیت تأییدنشده: مستندات رسمی صفیر (docs.bale.ai/safir) فقط سرویس‌های
     * «ارسال» را به‌صورت عمومی مستند کرده‌اند (send_message / upload_file / OTP).
     * صفحه‌ی محصول از «تعامل دوطرفه» و «رهگیری پیام» حرف می‌زند، ولی ساختار دقیقِ
     * رویدادِ دریافت (مسیر، نام فیلد شماره، فرمت) به‌صورت رسمی در دسترس نبود.
     *
     * این پیاده‌سازی محافظتی است: اگر پلتفرم روزی پی‌لودی با یکی از الگوهای محتمل
     * بفرستد که شامل «شماره» باشد، آن را استخراج و نرمال می‌کند تا merge هویت طلایی
     * انجام شود و پیام در تاریخچه ثبت شود. اگر هیچ الگوی شناخته‌شده‌ای نبود، null
     * برمی‌گرداند و هیچ آسیبی نمی‌زند (نه merge اشتباه، نه پیام جعلی در تاریخچه).
     *
     * وقتی پی‌لود واقعی صفیر در دست بود، فقط استخراج‌گرِ زیر را با ساختار قطعی جایگزین کنید.
     */
    public function handleInbound(array $payload): ?array
    {
        // جست‌وجوی شماره در کلیدهای محتملِ صفیر/خانواده‌ی بله
        $phone = $payload['phone_number']
            ?? $payload['phone']
            ?? ($payload['from']['phone_number'] ?? null)
            ?? ($payload['message']['phone_number'] ?? null)
            ?? ($payload['data']['phone_number'] ?? null)
            ?? null;

        // بدون شماره، صفیر هیچ شناسه‌ی پایداری ندارد (chat_id ندارد) → چیزی ثبت نکن
        if (!$phone) return null;

        $norm = class_exists('Persons') ? Persons::normalizePhone($phone) : null;
        if (!$norm) return null;

        // استخراج متن از الگوهای محتمل
        $text = $payload['text']
            ?? ($payload['message']['text'] ?? null)
            ?? ($payload['message_data']['message']['text'] ?? null)
            ?? ($payload['data']['text'] ?? null)
            ?? null;

        // استخراج file_id در صورت وجود رسانه
        $mediaId = $payload['file_id']
            ?? ($payload['message']['file_id'] ?? null)
            ?? ($payload['message_data']['message']['file_id'] ?? null)
            ?? null;

        return [
            // برای صفیر، شناسه‌ی کانال همان شماره‌ی نرمال‌شده است (نه chat_id)
            'channel_uid' => $norm,
            'username'    => null,
            'first_name'  => $payload['from']['first_name'] ?? null,
            'last_name'   => $payload['from']['last_name'] ?? null,
            'text'        => $text,
            'phone'       => $norm,
            'type'        => $mediaId ? 'photo' : ($text !== null ? 'text' : 'contact'),
            'media_type'  => $mediaId ? 'photo' : null,
            'media_id'    => $mediaId,
            'raw'         => $payload,
        ];
    }

    /**
     * صفیر setWebhook به سبک ربات ندارد (آدرس دریافت در پنل سازمانی تنظیم می‌شود، نه با API).
     * پردازشِ پاسخِ دریافتی در handleInbound انجام می‌شود.
     */
    public function setWebhook(string $url): array
    {
        return ['ok' => false, 'error' => 'صفیر setWebhook با API ندارد؛ آدرس دریافت در پنل صفیر تنظیم می‌شود'];
    }
}
