<?php
/**
 * channels/sms/SmsProviderInterface.php
 * قرارداد مشترک همه‌ی شرکت‌های پیامک.
 *
 * هر provider یک شرکت است (کاوه‌نگار، sms.ir، ملی‌پیامک).
 * کاربر یکی را انتخاب و کلید/خطش را وارد می‌کند.
 */

interface SmsProviderInterface
{
    /** نام یکتا: 'kavenegar', 'smsir', 'melipayamak' */
    public function name(): string;

    /**
     * ارسال یک پیامک ساده.
     * $phone: شماره (فرمت 98...)؛ خود provider در صورت نیاز تبدیل می‌کند.
     * $text: متن پیام.
     * خروجی: ['ok'=>bool, 'error'=>?string, 'raw'=>mixed]
     */
    public function sendSms(string $phone, string $text): array;

    /**
     * دریافت پیام‌های ورودی (پاسخ کاربران) — فقط خط اختصاصی.
     * بسته به provider یا با pull (دوره‌ای) یا از payload وب‌هوک.
     * خروجی: آرایه‌ای از ['phone'=>..., 'text'=>..., 'received_at'=>..., 'raw'=>...]
     * اگر پشتیبانی نشود یا چیزی نباشد: آرایه‌ی خالی.
     */
    public function fetchInbound(): array;

    /**
     * اگر provider وب‌هوک دارد، payload دریافتی را نرمال می‌کند.
     * در غیر این صورت null.
     */
    public function parseWebhook(array $payload): ?array;
}
