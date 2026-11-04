<?php
/**
 * channels/SmsDriver.php
 * کانال پیامک. خودش یک provider (شرکت پیامک) انتخاب‌شده را به کار می‌گیرد.
 * رابط ChannelInterface را پیاده می‌کند تا مثل بقیه‌ی کانال‌ها رفتار کند.
 *
 * مثل صفیر، با «شماره» کار می‌کند نه chat_id.
 */

require_once __DIR__ . '/sms/SmsProviderInterface.php';
require_once __DIR__ . '/sms/KavenegarProvider.php';
require_once __DIR__ . '/sms/SmsirProvider.php';
require_once __DIR__ . '/sms/MelipayamakProvider.php';

class SmsDriver implements ChannelInterface
{
    private SmsProviderInterface $provider;

    /** نگاشت نام provider → کلاس */
    private static array $providerMap = [
        'kavenegar'   => KavenegarProvider::class,
        'smsir'       => SmsirProvider::class,
        'melipayamak' => MelipayamakProvider::class,
    ];

    public function __construct(string $providerName, string $apiKey, string $sender = '')
    {
        $class = self::$providerMap[$providerName] ?? null;
        if (!$class) {
            throw new InvalidArgumentException("provider پیامک ناشناخته: {$providerName}");
        }
        $this->provider = new $class($apiKey, $sender);
    }

    public static function availableProviders(): array
    {
        return array_keys(self::$providerMap);
    }

    public function name(): string { return 'sms'; }

    public function capabilities(): array
    {
        // پیامک: فقط متن، انبوه با شماره، OTP، و دریافت (مشروط به خط اختصاصی)
        return ['text', 'bulk_phone', 'otp', 'inbound'];
    }

    /**
     * ارسال. recipient باید 'phone' داشته باشد. پیامک عکس/دکمه ندارد؛
     * اگر دکمه‌ی لینک بود، به انتهای متن اضافه می‌شود.
     */
    public function send(array $recipient, array $message): array
    {
        $phone = $recipient['phone'] ?? null;
        if (!$phone) return ['ok' => false, 'error' => 'no_phone'];
        // ۱.۰.۱۴: ذخیره 09… است؛ وب‌سرویس پیامک همان قالب قبلی (98…) را می‌گیرد
        $phone = Persons::intlPhone($phone);
        if (!$phone) return ['ok' => false, 'error' => 'invalid_phone'];

        $text = $message['text'] ?? '';
        // لینک دکمه‌ها به متن اضافه می‌شود (پیامک دکمه ندارد)
        if (!empty($message['buttons'])) {
            foreach ($message['buttons'] as $row) {
                foreach ((array)$row as $btn) {
                    if (!empty($btn['url'])) {
                        $text .= "\n" . ($btn['text'] ?? '') . ': ' . $btn['url'];
                    }
                }
            }
        }
        if ($text === '') return ['ok' => false, 'error' => 'empty_text'];

        return $this->provider->sendSms($phone, $text);
    }

    /** دسترسی به provider جاری (برای DLR و استعلام تحویل) */
    public function getProvider()
    {
        return $this->provider ?? null;
    }

    /** دریافت پاسخ‌های پیامک (pull) — از provider */
    public function fetchInbound(): array
    {
        return $this->provider->fetchInbound();
    }

    public function handleInbound(array $payload): ?array
    {
        return $this->provider->parseWebhook($payload);
    }

    public function setWebhook(string $url): array
    {
        return ['ok' => false, 'error' => 'پیامک از pull استفاده می‌کند، نه webhook'];
    }
}
