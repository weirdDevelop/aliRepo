<?php
/**
 * channels/ChannelInterface.php
 * قرارداد مشترک همه‌ی کانال‌ها. هر درایور این را پیاده می‌کند.
 *
 * هدف: ماژول‌های کاری (کمپین، ارسال...) فقط با این رابط کار می‌کنند و
 * نمی‌دانند پشت‌صحنه بله است یا پیامک.
 */

interface ChannelInterface
{
    /** نام یکتای کانال: 'bale', 'bale_safir', 'sms', 'email', ... */
    public function name(): string;

    /**
     * قابلیت‌های کانال، مثلاً:
     * ['text', 'photo', 'buttons', 'bulk_phone', 'otp', 'inbound']
     */
    public function capabilities(): array;

    /**
     * ارسال یک پیام به یک گیرنده.
     * $recipient: آرایه‌ای که بسته به کانال یکی از این‌ها را دارد:
     *   ['channel_uid' => '...']  (مثل chat_id) یا ['phone' => '98...'] یا ['email' => '...']
     * $message: ['text' => ..., 'photo' => ..., 'buttons' => [...]]
     * خروجی: ['ok' => bool, 'error' => ?string, 'raw' => mixed]
     */
    public function send(array $recipient, array $message): array;

    /**
     * پردازش رویداد دریافتی (webhook). در صورت معتبر بودن، یک رویداد نرمال‌شده برمی‌گرداند:
     *   ['channel_uid'=>..., 'username'=>..., 'text'=>..., 'phone'=>?, 'type'=>..., 'raw'=>...]
     * در غیر این صورت null.
     */
    public function handleInbound(array $payload): ?array;

    /**
     * تنظیم آدرس دریافت رویداد (webhook) روی پلتفرم.
     * کانال‌های بدون webhook (مثل پیامک) می‌توانند آرایه‌ی خالی برگردانند.
     */
    public function setWebhook(string $url): array;
}
