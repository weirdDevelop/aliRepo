<?php
/**
 * channels/WebchatDriver.php
 * کانال «گفتگوی آنلاین سایت» (مورد ۱۳ دور بازخورد).
 *
 * تفاوت این کانال با بله/تلگرام: مقصد یک سرور بیرونی نیست؛ خودِ مرورگرِ بازدیدکننده
 * است که با polling پیام‌ها را از `chat.php` می‌گیرد. پس `send()` هیچ درخواست HTTP
 * بیرونی نمی‌زند — فقط اعتبار نشست را بررسی می‌کند و اجازه می‌دهد لایه‌ی بالاتر
 * (Sender / BotReply) پیام را در `eb_messages` ثبت کند. ویجت همان ردیف‌ها را می‌خواند.
 *
 * چرا از eb_messages می‌خوانیم و نه از eb_webchat_outbox؟
 *   تا «یک منبع حقیقت» داشته باشیم؛ اگر پیام هم در outbox و هم در messages ثبت شود،
 *   بازدیدکننده هر پاسخ را دو بار می‌بیند. `eb_webchat_outbox` فقط برای رویدادهای
 *   کنترلی (اعلان سیستمی مثل «به هوش مصنوعی سپرده شد») استفاده می‌شود.
 *
 * channel_uid = توکن نشست چت (۳۲ کاراکتر هگز) که در localStorage مرورگر می‌ماند.
 */

class WebchatDriver implements ChannelInterface
{
    public function name(): string
    {
        return 'webchat';
    }

    public function capabilities(): array
    {
        // photo: اپراتور می‌تواند عکس بفرستد (ویجت آن را نشان می‌دهد)
        // inbound: پیام از سمت بازدیدکننده می‌آید
        return ['text', 'photo', 'inbound', 'buttons', 'cards', 'request_contact'];
    }

    /**
     * «ارسال» به بازدیدکننده = مطمئن شدن از اینکه نشست چتش هنوز وجود دارد.
     * ثبت پیام در تاریخچه کارِ Sender/BotReply است؛ ویجت با poll آن را می‌گیرد.
     */
    public function send(array $recipient, array $message): array
    {
        $token = (string)($recipient['channel_uid'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'error' => 'شناسه‌ی نشست چت سایت خالی است'];
        }

        $text  = trim((string)($message['text'] ?? ''));
        $photo = $message['photo'] ?? null;
        // ۱.۰.۱۴: پیام فقط‌دکمه (منوی بات) هم مجاز است؛ ویجت دکمه‌ها را نشان می‌دهد
        $hasButtons = !empty($message['buttons']) || !empty($message['request_contact']) || !empty($message['cards']);
        if ($text === '' && !$photo && !$hasButtons) {
            return ['ok' => false, 'error' => 'متن پیام خالی است'];
        }

        try {
            $row = Database::one(
                "SELECT id, last_seen FROM eb_webchat_sessions WHERE token = ? LIMIT 1",
                [$token]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'جدول چت سایت آماده نیست — دیتابیس را به‌روزرسانی کنید'];
        }

        if (!$row) {
            return ['ok' => false, 'error' => 'نشست چت سایت پیدا نشد (بازدیدکننده پنجره را پاک کرده است)'];
        }

        // نشست‌های خیلی قدیمی: پیام ثبت می‌شود ولی به بازدیدکننده هشدار می‌دهیم که شاید نبیند.
        $stale = false;
        if (!empty($row['last_seen'])) {
            $stale = (strtotime((string)$row['last_seen']) < time() - 7 * 86400);
        }

        return [
            'ok'    => true,
            'raw'   => ['session_id' => (int)$row['id'], 'stale' => $stale],
            'error' => null,
        ];
    }

    /**
     * نرمال‌سازی پیام دریافتی از ویجت به قالب مشترک کانال‌ها.
     * $payload: ['token'=>..., 'text'=>..., 'name'=>..., 'phone'=>..., 'email'=>...]
     */
    public function handleInbound(array $payload): ?array
    {
        $token = trim((string)($payload['token'] ?? ''));
        if ($token === '') return null;

        $text = (string)($payload['text'] ?? '');
        return [
            'channel_uid' => $token,
            'username'    => null,
            'first_name'  => trim((string)($payload['name'] ?? '')) ?: null,
            'text'        => $text,
            'phone'       => trim((string)($payload['phone'] ?? '')) ?: null,
            'type'        => 'message',
            'raw'         => $payload,
        ];
    }

    /** این کانال webhook ندارد — ویجت خودش poll می‌کند. */
    public function setWebhook(string $url): array
    {
        return ['ok' => true, 'error' => null, 'raw' => 'webchat needs no webhook'];
    }
}
