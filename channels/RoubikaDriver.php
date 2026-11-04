<?php
/**
 * channels/RoubikaDriver.php
 * کانال ربات روبیکا — از خانواده‌ی تلگرام است ولی چند تفاوت ساختاری دارد:
 *
 *   ۱. آدرس:    https://botapi.rubika.ir/v3/{token}/{method}   (بدون پیشوند bot)
 *   ۲. دکمه:    inline_keypad.rows[].buttons[] با id/type/button_text
 *               (به‌جای inline_keyboard خانواده‌ی تلگرام)
 *   ۳. webhook: متد updateBotEndpoint (به‌جای setWebhook)
 *   ۴. دریافت:  پیام در update.new_message (به‌جای message)
 *
 * بقیه‌ی منطق (call/curl) از BaseBotDriver به ارث می‌رسد.
 */

class RoubikaDriver extends BaseBotDriver
{
    protected string $apiBase = 'https://botapi.rubika.ir';

    public function name(): string
    {
        return 'roubika';
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'buttons', 'inbound'];
    }

    /** الگوی URL روبیکا: /v3/{token}/{method} */
    protected function buildUrl(string $method): string
    {
        return "{$this->apiBase}/v3/{$this->token}/{$method}";
    }

    /**
     * ارسال — ساختار روبیکا با خانواده‌ی تلگرام فرق دارد:
     * بدنه JSON با chat_id/text، و دکمه‌ها در inline_keypad.rows[].buttons[].
     */
    public function send(array $recipient, array $message): array
    {
        $chatId = $recipient['channel_uid'] ?? null;
        if (!$chatId) {
            return ['ok' => false, 'error' => 'no_channel_uid'];
        }

        $text = $message['text'] ?? '';

        // مستندات رسمی روبیکا فقط دکمه‌ی Simple را تأیید می‌کند.
        // دکمه‌های لینک‌دار را به‌صورت امن به انتهای متن اضافه می‌کنیم،
        // و دکمه‌های Simple (callback) را به‌صورت inline_keypad می‌فرستیم.
        $simpleButtons = [];
        if (!empty($message['buttons'])) {
            foreach ($message['buttons'] as $row) {
                if (!is_array($row)) continue;
                foreach ($row as $btn) {
                    if (!empty($btn['url'])) {
                        $text .= "\n" . ($btn['text'] ?? '') . ': ' . $btn['url'];
                    } elseif (!empty($btn['text'])) {
                        $simpleButtons[] = $btn; // دکمه‌ی Simple/callback
                    }
                }
            }
        }

        $payload = [
            'chat_id' => (string) $chatId,
            'text'    => $text,
        ];
        if ($simpleButtons) {
            $payload['inline_keypad'] = $this->toRubikaKeypad([$simpleButtons]);
        }

        // عکس: اگر مسیر فایل بود، با الگوی سه‌مرحله‌ای آپلود و با sendFile ارسال می‌شود
        if (!empty($message['photo']) && file_exists($message['photo'])) {
            $fileId = $this->uploadFile($message['photo']);
            if ($fileId) {
                $filePayload = [
                    'chat_id' => (string) $chatId,
                    'file_id' => $fileId,
                    'text'    => $text,
                ];
                if ($simpleButtons) {
                    $filePayload['inline_keypad'] = $this->toRubikaKeypad([$simpleButtons]);
                }
                $r = $this->call('sendFile', $filePayload);
                $ok = (isset($r['status']) && $r['status'] === 'OK') || !empty($r['ok']);
                return ['ok' => $ok, 'error' => $r['status_det'] ?? ($r['error'] ?? null), 'raw' => $r];
            }
            // اگر آپلود شکست خورد، حداقل متن را بفرست (پیام از دست نرود)
        }

        $r = $this->call('sendMessage', $payload);

        // ساختار موفقیت روبیکا: status == 'OK'
        $ok = (isset($r['status']) && $r['status'] === 'OK') || !empty($r['ok']);
        return ['ok' => $ok, 'error' => $r['status_det'] ?? ($r['error'] ?? null), 'raw' => $r];
    }

    /** ویرایش متن پیام ارسال‌شده (API ربات روبیکا: editMessageText) */
    public function editText(string $chatId, string $messageId, string $text, bool $caption = false): array
    {
        $r = $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text]);
        $ok = (isset($r['status']) && $r['status'] === 'OK') || !empty($r['ok']);
        return ['ok' => $ok, 'error' => $ok ? null : ($r['status_det'] ?? ($r['error'] ?? 'edit_failed'))];
    }

    /**
     * تبدیل دکمه‌های Simple به ساختار inline_keypad روبیکا.
     * فقط type=Simple (طبق مستندات رسمی).
     */
    private function toRubikaKeypad(array $buttons): array
    {
        $rows = [];
        $counter = 1;
        foreach ($buttons as $row) {
            if (!is_array($row)) continue;
            $cells = [];
            foreach ($row as $btn) {
                $cells[] = [
                    'id'          => (string) ($btn['callback'] ?? ('b' . $counter)),
                    'type'        => 'Simple',
                    'button_text' => $btn['text'] ?? 'دکمه',
                ];
                $counter++;
            }
            if ($cells) $rows[] = ['buttons' => $cells];
        }
        return ['rows' => $rows];
    }

    /**
     * دریافت — روبیکا دو ساختار رویداد دارد (نام endpointها در مستندات
     * جابه‌جا برچسب خورده، پس هر دو ساختار را تشخیص می‌دهیم):
     *   - update.new_message : پیام/رویداد معمولی
     *   - inline_message     : کلیک روی دکمه‌ی شیشه‌ای
     */
    public function handleInbound(array $payload): ?array
    {
        // حالت ۱: پیام معمولی
        if (isset($payload['update']['new_message'])) {
            $u   = $payload['update'];
            $msg = $u['new_message'];
            $chatId = (string) ($u['chat_id'] ?? $msg['sender_id'] ?? '');
            $info = $this->fetchChatInfo($chatId); // نام کاربر را جدا می‌گیریم
            return [
                'channel_uid' => $chatId,
                'username'    => $info['username'] ?? null,
                'first_name'  => $info['first_name'] ?? null,
                'last_name'   => $info['last_name'] ?? null,
                'text'        => $msg['text'] ?? null,
                'phone'       => null, // روبیکا اشتراک شماره‌ی استاندارد بله را ندارد
                'type'        => 'text',
                'raw'         => $u,
            ];
        }

        // حالت ۲: کلیک روی دکمه‌ی شیشه‌ای
        if (isset($payload['inline_message'])) {
            $im = $payload['inline_message'];
            return [
                'channel_uid' => (string) ($im['chat_id'] ?? $im['sender_id'] ?? ''),
                'username'    => null,
                'first_name'  => null,
                'last_name'   => null,
                'text'        => $im['text'] ?? null,
                'phone'       => null,
                'type'        => 'callback',
                'button_id'   => $im['aux_data']['button_id'] ?? null,
                'raw'         => $im,
            ];
        }

        return null;
    }

    /**
     * گرفتن اطلاعات چت (نام کاربر) با متد getChat روبیکا.
     * چون webhook روبیکا نام نمی‌دهد، جدا می‌پرسیم. در صورت خطا، آرایه‌ی خالی.
     */
    private function fetchChatInfo(string $chatId): array
    {
        if ($chatId === '') return [];
        $r = $this->call('getChat', ['chat_id' => $chatId]);
        $chat = $r['data']['chat'] ?? ($r['chat'] ?? null);
        if (!is_array($chat)) return [];
        return [
            'username'   => $chat['username'] ?? null,
            'first_name' => $chat['first_name'] ?? ($chat['first'] ?? null),
            'last_name'  => $chat['last_name'] ?? null,
        ];
    }

    /**
     * ارسال فایل/عکس روبیکا — الگوی سه‌مرحله‌ای:
     *   ۱) requestSendFile → گرفتن upload_url
     *   ۲) POST فایل به upload_url → گرفتن file_id
     *   ۳) sendFile با file_id
     * خروجی: file_id یا null
     */
    public function uploadFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) return null;

        // مرحله ۱: درخواست آدرس آپلود
        $req = $this->call('requestSendFile', ['type' => 'Image']);
        $uploadUrl = $req['data']['upload_url'] ?? ($req['upload_url'] ?? null);
        if (!$uploadUrl) return null;

        // مرحله ۲: آپلود فایل به آدرس دریافتی
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new CURLFile(realpath($filePath))],
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $d = json_decode($res, true);
        return $d['data']['file_id'] ?? ($d['file_id'] ?? null);
    }

    /** روبیکا webhook را با updateBotEndpoints (با s) و نوع رویداد تنظیم می‌کند */
    public function setWebhook(string $url): array
    {
        // طبق مستندات: متد updateBotEndpoints، فیلد type از نوع UpdateEndpointTypeEnum.
        // نوع ReceiveUpdate برای دریافت پیام‌ها.
        return $this->call('updateBotEndpoints', [
            'url'  => $url,
            'type' => 'ReceiveUpdate',
        ]);
    }
}
