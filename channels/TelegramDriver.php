<?php
/**
 * channels/TelegramDriver.php
 * کانال ربات تلگرام — دوقلوی بله. فقط API_BASE فرق می‌کند.
 *
 * هشدار: این فقط «ربات» تلگرام است (امن). ارسال انبوه با شماره
 * (userbot) نقض ToS تلگرام و عامل بن دائم است و پیاده‌سازی نمی‌شود.
 */

class TelegramDriver extends BaseBotDriver
{
    protected string $apiBase = 'https://api.telegram.org';

    public function __construct(string $token)
    {
        parent::__construct($token);
        // سرور Bot API شخصی یا رله (برای سرورهای داخل ایران)
        if (class_exists('NetProxy')) { try { $this->apiBase = NetProxy::telegramApiBase(); } catch (Throwable $e) {} }
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'buttons', 'inbound', 'request_contact'];
    }
}
