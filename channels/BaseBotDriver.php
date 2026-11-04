<?php
/**
 * channels/BaseBotDriver.php
 * پایه‌ی مشترک برای کانال‌هایی که معماری ربات تلگرام را کپی کرده‌اند:
 * بله، روبیکا، تلگرام. هر سه همان sendMessage/sendPhoto/webhook را دارند،
 * فقط API_BASE و توکن فرق می‌کند.
 *
 * زیرکلاس‌ها فقط $apiBase و name() را تعریف می‌کنند.
 */

abstract class BaseBotDriver implements ChannelInterface
{
    protected string $apiBase;   // مثل 'https://tapi.bale.ai'
    protected string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * ساخت URL فراخوانی. الگوی پیش‌فرض خانواده‌ی تلگرام/بله است.
     * روبیکا این را override می‌کند (الگوی متفاوت: /v3/{token}/{method}).
     */
    protected function buildUrl(string $method): string
    {
        return "{$this->apiBase}/bot{$this->token}/{$method}";
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'buttons', 'inbound'];
    }

    /**
     * گرفتن اطلاعات روزآمد یک چت/کاربر از پلتفرم (برای به‌روزرسانی پروفایل).
     * خروجی نرمال‌شده: ['username'=>?, 'first_name'=>?, 'last_name'=>?] یا null.
     */
    public function getChat(string $chatId): ?array
    {
        $r = $this->call('getChat', ['chat_id' => $chatId]);
        if (empty($r['ok']) || empty($r['result'])) return null;
        $c = $r['result'];
        return [
            'username'   => $c['username'] ?? null,
            'first_name' => $c['first_name'] ?? ($c['title'] ?? null),
            'last_name'  => $c['last_name'] ?? null,
        ];
    }

    /**
     * گزینه‌های پروکسیِ ضدفیلترینگ برای این کانال (اگر ادمین در تنظیمات کانال‌ها
     * فعالش کرده باشد). خالی = بدون پروکسی. محافظت‌شده تا نبودِ ماژول/جدول
     * تنظیمات، ارسال معمولی را نشکند.
     */
    protected function proxyOptions(): array
    {
        if (!class_exists('NetProxy')) return [];
        try { return NetProxy::curlOptionsFor($this->name()); }
        catch (Throwable $e) { return []; }
    }

    /** فراخوانی پایه‌ی API ربات (سازگار با خانواده‌ی تلگرام) */
    protected function call(string $method, array $params = [], bool $isFile = false): array
    {
        $url = $this->buildUrl($method);
        $build = function (array $proxyOpts) use ($url, $params, $isFile) {
            $ch = curl_init($url);
            curl_setopt_array($ch, ($isFile ? [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $params,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
            ] : [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]) + $proxyOpts);
            return $ch;
        };
        // با پروکسی: اگر پروکسی اول وصل نشد، بعدی امتحان می‌شود (NetProxy::exec)
        if (class_exists('NetProxy')) {
            try { [$res] = NetProxy::exec($this->name(), $build); }
            catch (Throwable $e) { $ch = $build([]); $res = curl_exec($ch); curl_close($ch); }
        } else {
            $ch = $build([]); $res = curl_exec($ch); curl_close($ch);
        }
        return json_decode((string)$res, true) ?? ['ok' => false, 'error' => 'invalid_response'];
    }

    /**
     * دانلود یک فایل/عکسِ دریافتی از پلتفرم با file_id (الگوی خانواده‌ی تلگرام:
     * getFile → file_path → دانلود از /file/bot{token}/{file_path}).
     * از همان پروکسیِ کانال (در صورت فعال‌بودن) استفاده می‌کند.
     * خروجی: ['bytes'=>string, 'mime'=>string|null] یا null در صورت شکست.
     * روبیکا این متد را ندارد (الگوی دریافت فایلش فرق دارد) — اینجا فقط برای
     * خانواده‌ی تلگرام/بله (که از همین کلاس مستقیم استفاده می‌کنند) کار می‌کند.
     */
    public function fetchFile(string $fileId): ?array
    {
        if (trim($fileId) === '') return null;

        $meta = $this->call('getFile', ['file_id' => $fileId]);
        $filePath = $meta['result']['file_path'] ?? null;
        if (!$filePath) return null;

        $url = "{$this->apiBase}/file/bot{$this->token}/{$filePath}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_MAXFILESIZE    => 15 * 1024 * 1024, // سقف امنیتی ۱۵ مگابایت
        ] + $this->proxyOptions());
        $bytes = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($bytes === false || $code < 200 || $code >= 300 || $bytes === '') return null;
        return ['bytes' => $bytes, 'mime' => $ctype ?: null, 'ext' => pathinfo($filePath, PATHINFO_EXTENSION)];
    }

    public function send(array $recipient, array $message): array
    {
        $chatId = $recipient['channel_uid'] ?? null;
        if (!$chatId) {
            return ['ok' => false, 'error' => 'no_channel_uid'];
        }

        $replyMarkup = null;
        // درخواست اشتراک شماره (reply keyboard) — با دکمه‌های شیشه‌ای قابل ترکیب نیست،
        // پس اگر خواسته شد، اولویت دارد.
        if (!empty($message['request_contact'])) {
            $replyMarkup = json_encode([
                'keyboard' => [[
                    ['text' => $message['contact_button_text'] ?? '📱 اشتراک شماره', 'request_contact' => true],
                ]],
                'resize_keyboard'   => true,
                'one_time_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE);
        } elseif (!empty($message['buttons'])) {
            // تبدیل کلید داخلی 'callback' به 'callback_data' که بله/تلگرام می‌خواهند
            $kb = [];
            foreach ($message['buttons'] as $row) {
                $cells = [];
                foreach ((array)$row as $btn) {
                    if (!empty($btn['url'])) {
                        $cells[] = ['text' => $btn['text'] ?? '', 'url' => $btn['url']];
                    } elseif (isset($btn['callback'])) {
                        $cells[] = ['text' => $btn['text'] ?? '', 'callback_data' => $btn['callback']];
                    } elseif (isset($btn['callback_data'])) {
                        $cells[] = ['text' => $btn['text'] ?? '', 'callback_data' => $btn['callback_data']];
                    }
                }
                if ($cells) $kb[] = $cells;
            }
            $replyMarkup = json_encode(['inline_keyboard' => $kb], JSON_UNESCAPED_UNICODE);
        }

        // عکس‌دار یا فقط متن
        if (!empty($message['photo']) && file_exists($message['photo'])) {
            $params = [
                'chat_id' => (string) $chatId,
                'photo'   => new CURLFile(realpath($message['photo'])),
                'caption' => $message['text'] ?? '',
            ];
            if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
            $r = $this->call('sendPhoto', $params, true);
        } else {
            $params = ['chat_id' => (string) $chatId, 'text' => $message['text'] ?? ''];
            if ($replyMarkup) $params['reply_markup'] = $replyMarkup;
            $r = $this->call('sendMessage', $params);
        }

        $ok = !empty($r['ok']);
        $err = $ok ? null : ($r['description'] ?? 'send_failed');
        if (class_exists('ApiLog')) {
            ApiLog::record($this->name(), isset($params['photo']) ? 'sendPhoto' : 'sendMessage',
                $params, $r, null, $ok, $err);
        }
        return ['ok' => $ok, 'error' => $err, 'raw' => $r];
    }

    /** ویرایش متن پیامی که قبلاً فرستاده شده (بله/تلگرام: editMessageText، برای عکس editMessageCaption) */
    public function editText(string $chatId, string $messageId, string $text, bool $caption = false): array
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId];
        if ($caption) $params['caption'] = $text; else $params['text'] = $text;
        $r = $this->call($caption ? 'editMessageCaption' : 'editMessageText', $params);
        $ok = !empty($r['ok']);
        if (class_exists('ApiLog')) ApiLog::record($this->name(), $caption ? 'editMessageCaption' : 'editMessageText', $params, $r, null, $ok, $ok ? null : ($r['description'] ?? null));
        return ['ok' => $ok, 'error' => $ok ? null : ($r['description'] ?? 'edit_failed')];
    }

    public function handleInbound(array $payload): ?array
    {
        // کلیک دکمه‌ی inline → callback_query
        if (!empty($payload['callback_query'])) {
            $cb = $payload['callback_query'];
            $from = $cb['from'] ?? [];
            $chat = $cb['message']['chat'] ?? [];
            return [
                'channel_uid'   => (string) ($chat['id'] ?? $from['id'] ?? ''),
                'username'      => $from['username'] ?? null,
                'first_name'    => $from['first_name'] ?? null,
                'last_name'     => $from['last_name'] ?? null,
                'text'          => null,
                'callback_data' => $cb['data'] ?? null,
                'type'          => 'callback',
                'raw'           => $cb,
            ];
        }

        $msg = $payload['message'] ?? null;
        if (!$msg) return null;

        $from = $msg['from'] ?? [];
        $chat = $msg['chat'] ?? [];

        // اگر کاربر شماره به اشتراک گذاشت
        $phone = null;
        if (!empty($msg['contact']['phone_number'])) {
            $phone = $msg['contact']['phone_number'];
        }

        // استخراج رسانه (عکس/فایل) برای ذخیره در تاریخچه
        $mediaType = null; $mediaId = null;
        if (!empty($msg['photo']) && is_array($msg['photo'])) {
            // تلگرام/بله آرایه‌ای از سایزها می‌دهند؛ آخری بزرگ‌ترین است
            $last = end($msg['photo']);
            $mediaId = $last['file_id'] ?? null;
            $mediaType = 'photo';
        } elseif (!empty($msg['document']['file_id'])) {
            $mediaId = $msg['document']['file_id'];
            $mediaType = 'document';
        }

        return [
            'channel_uid' => (string) ($chat['id'] ?? $from['id'] ?? ''),
            'username'    => $from['username'] ?? null,
            'first_name'  => $from['first_name'] ?? null,
            'last_name'   => $from['last_name'] ?? null,
            'text'        => $msg['text'] ?? ($msg['caption'] ?? null),
            'phone'       => $phone,
            'type'        => isset($msg['photo']) ? 'photo' : (isset($msg['contact']) ? 'contact' : (isset($msg['document']) ? 'document' : 'text')),
            'media_type'  => $mediaType,
            'media_id'    => $mediaId,
            'raw'         => $msg,
        ];
    }

    /**
     * تنظیم webhook. الگوی پیش‌فرض خانواده‌ی تلگرام/بله (setWebhook).
     * روبیکا این را override می‌کند (updateBotEndpoint).
     */
    public function setWebhook(string $url): array
    {
        return $this->call('setWebhook', ['url' => $url]);
    }

    /** ۱.۰.۱۳: وضعیت وب‌هوک (آدرس، خطای آخر، پیام‌های در صف) برای عیب‌یابی */
    public function webhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }
}
