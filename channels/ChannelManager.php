<?php
/**
 * channels/ChannelManager.php
 * ثبت و انتخاب درایورها. توکن هر کانال از eb_settings خوانده می‌شود.
 *
 * تنظیمات در eb_settings با کلیدهای:
 *   channel.bale.token, channel.telegram.token, channel.roubika.token
 * ذخیره می‌شوند.
 */

require_once __DIR__ . '/ChannelInterface.php';
require_once __DIR__ . '/BaseBotDriver.php';
require_once __DIR__ . '/BaleBotDriver.php';
require_once __DIR__ . '/TelegramDriver.php';
require_once __DIR__ . '/RoubikaDriver.php';
require_once __DIR__ . '/BaleSafirDriver.php';
require_once __DIR__ . '/SmsDriver.php';
require_once __DIR__ . '/EmailDriver.php';
require_once __DIR__ . '/VoiceDriver.php';
require_once __DIR__ . '/WebchatDriver.php';
require_once __DIR__ . '/InstagramDriver.php';
require_once __DIR__ . '/InstagramRoboclickDriver.php';
require_once __DIR__ . '/InstagramApichiDriver.php';

class ChannelManager
{
    /**
     * override موقت درایور «instagram» — برای وب‌هوک چندپیجی سرویس‌دهنده: قبل از
     * صدا زدن پایپ‌لاین مشترک (Forms/BotMenu/AutoReply/Ai/IgPhoneGate که همه
     * داخل خودشان ChannelManager::get('instagram') را صدا می‌زنند)، همین یک
     * خط override ست می‌شود تا پاسخ از پیج درستِ سرویس‌دهنده برود، نه از کانال
     * عمومی instagram (دایرکت‌ساز/OAuth تک‌پیج). این تنها راهی بود که پایپ‌لاین
     * فرم→منو→پاسخ‌خودکار→AI را بدون تغییر امضای همه‌ی آن ماژول‌ها هم برای
     * چندپیجی به‌کار گرفت.
     */
    private static ?ChannelInterface $override = null;

    public static function setInstagramOverride(?ChannelInterface $driver): void
    {
        self::$override = $driver;
    }

    /** نگاشت نام کانال → کلاس درایور (کانال‌های تک‌توکنه‌ی خانواده‌ی ربات) */
    private static array $map = [
        'bale'     => BaleBotDriver::class,
        'telegram' => TelegramDriver::class,
        'roubika'  => RoubikaDriver::class,
    ];

    /** کش درایورهای ساخته‌شده */
    private static array $cache = [];

    /** فهرست کانال‌های پشتیبانی‌شده (شامل صفیر و پیامک که موارد خاص‌اند) */
    public static function available(): array
    {
        return array_merge(array_keys(self::$map), ['bale_safir', 'sms', 'email', 'webchat', 'instagram']);
    }

    /** خواندن یک تنظیم از دیتابیس */
    private static function setting(string $key): ?string
    {
        $row = Database::one("SELECT v FROM eb_settings WHERE k = ?", [$key]);
        return $row['v'] ?? null;
    }

    /** ذخیره‌ی توکن یک کانال */
    public static function saveToken(string $channel, string $token): void
    {
        Database::run(
            "INSERT INTO eb_settings (k, v) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            ["channel.{$channel}.token", $token]
        );
        unset(self::$cache[$channel]);
    }

    /** آیا کانال توکن تنظیم‌شده دارد؟ */
    public static function isConfigured(string $channel): bool
    {
        if ($channel === 'bale_safir') {
            return !empty(self::setting('channel.bale_safir.access_key'))
                && !empty(self::setting('channel.bale_safir.bot_id'));
        }
        if ($channel === 'sms') {
            return !empty(self::setting('channel.sms.provider'))
                && !empty(self::setting('channel.sms.api_key'));
        }
        if ($channel === 'email') {
            return !empty(self::setting('channel.email.host'))
                && !empty(self::setting('channel.email.from'));
        }
        if ($channel === 'webchat') {
            // توکن ندارد؛ «تنظیم‌شده» یعنی کلید فعال‌سازی روشن است.
            // کلید عمداً `webchat.enabled` است (نه `channel.webchat.enabled`) تا همان
            // کلیدی باشد که ماژول Webchat می‌نویسد — یک منبع حقیقت، بدون drift.
            return self::setting('webchat.enabled') === '1';
        }
        if ($channel === 'instagram') {
            return class_exists('InstagramAuth') && InstagramAuth::isConnected();
        }
        return !empty(self::setting("channel.{$channel}.token"));
    }

    /** ذخیره‌ی تنظیمات پیامک */
    public static function saveSms(string $provider, string $apiKey, string $sender): void
    {
        foreach (['provider'=>$provider, 'api_key'=>$apiKey, 'sender'=>$sender] as $k=>$v) {
            Database::run(
                "INSERT INTO eb_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["channel.sms.{$k}", $v]
            );
        }
        unset(self::$cache['sms']);
    }

    /** ذخیره‌ی تنظیمات ایمیل (SMTP) */
    public static function saveEmail(array $cfg): void
    {
        foreach (['host','port','user','pass','from','secure'] as $k) {
            if (!array_key_exists($k, $cfg)) continue;
            Database::run(
                "INSERT INTO eb_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["channel.email.{$k}", (string)$cfg[$k]]
            );
        }
        unset(self::$cache['email']);
    }

    /**
     * درایور تلفن گویا. فعلاً فقط وقتی provider پیامک «ملی‌پیامک» باشد فعال است
     * (چون تلفن گویا روی همان حساب ملی‌پیامک کار می‌کند).
     */
    public static function getVoice(): ?VoiceDriver
    {
        $provider = self::setting('channel.sms.provider');
        $key      = self::setting('channel.sms.api_key');
        if ($provider !== 'melipayamak' || !$key) return null;
        return new VoiceDriver($key);
    }

    public static function voiceAvailable(): bool
    {
        return self::getVoice() !== null;
    }

    /** ذخیره‌ی تنظیمات صفیر (دو پارامتر) */
    public static function saveSafir(string $accessKey, string $botId): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.bale_safir.access_key',?)
             ON DUPLICATE KEY UPDATE v=VALUES(v)", [$accessKey]
        );
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.bale_safir.bot_id',?)
             ON DUPLICATE KEY UPDATE v=VALUES(v)", [$botId]
        );
        unset(self::$cache['bale_safir']);
    }

    /**
     * گرفتن درایور یک کانال. اگر تنظیم نباشد یا کانال ناشناخته باشد، null.
     */
    public static function get(string $channel): ?ChannelInterface
    {
        if ($channel === 'instagram' && self::$override !== null) {
            return self::$override;
        }
        if (isset(self::$cache[$channel])) {
            return self::$cache[$channel];
        }

        // صفیر: مورد خاص با دو پارامتر
        if ($channel === 'bale_safir') {
            $key = self::setting('channel.bale_safir.access_key');
            $bid = self::setting('channel.bale_safir.bot_id');
            if (!$key || !$bid) return null;
            $driver = new BaleSafirDriver($key, $bid);
            self::$cache[$channel] = $driver;
            return $driver;
        }

        // پیامک: مورد خاص با provider + کلید + خط
        if ($channel === 'sms') {
            $provider = self::setting('channel.sms.provider');
            $key      = self::setting('channel.sms.api_key');
            $sender   = self::setting('channel.sms.sender') ?? '';
            if (!$provider || !$key) return null;
            $driver = new SmsDriver($provider, $key, $sender);
            self::$cache[$channel] = $driver;
            return $driver;
        }

        // ایمیل: مورد خاص با تنظیمات SMTP
        if ($channel === 'email') {
            $host = self::setting('channel.email.host');
            $from = self::setting('channel.email.from');
            if (!$host || !$from) return null;
            $driver = new EmailDriver([
                'host'   => $host,
                'port'   => self::setting('channel.email.port') ?: 587,
                'user'   => self::setting('channel.email.user') ?: '',
                'pass'   => self::setting('channel.email.pass') ?: '',
                'from'   => $from,
                'secure' => self::setting('channel.email.secure') ?: 'tls',
            ]);
            self::$cache[$channel] = $driver;
            return $driver;
        }

        // گفتگوی آنلاین سایت: توکن بیرونی ندارد؛ فقط کلید فعال‌بودن.
        // اگر خاموش باشد null برمی‌گردد تا هیچ مسیر ارسالی به آن نرود.
        if ($channel === 'webchat') {
            if (self::setting('webchat.enabled') !== '1') return null;
            $driver = new WebchatDriver();
            self::$cache[$channel] = $driver;
            return $driver;
        }

        // اینستاگرام: از این پس فقط دو مسیر — سرویس‌دهنده (چندپیج، از طریق
        // ChannelManager::setInstagramOverride، این شاخه اصلاً صدا زده نمی‌شود)
        // یا OAuth مستقیم متا (تک‌پیج، برای وقتی کسی سرویس‌دهنده وصل نکرده باشد).
        // دایرکت‌ساز (سرویس ریسلر) کامل حذف شد.
        if ($channel === 'instagram') {
            if (!class_exists('InstagramAuth') || !InstagramAuth::isConnected()) return null;
            $driver = new InstagramDriver(InstagramAuth::get('access_token'), InstagramAuth::get('ig_user_id'));
            self::$cache[$channel] = $driver;
            return $driver;
        }

        if (!isset(self::$map[$channel])) {
            return null;
        }
        $token = self::setting("channel.{$channel}.token");
        if (!$token) {
            return null;
        }
        $class = self::$map[$channel];
        $driver = new $class($token);
        self::$cache[$channel] = $driver;
        return $driver;
    }

    /** فهرست کانال‌های فعال (توکن‌دار) به‌صورت درایور */
    public static function activeDrivers(): array
    {
        $out = [];
        foreach (self::available() as $ch) {
            $d = self::get($ch);
            if ($d) $out[$ch] = $d;
        }
        return $out;
    }
}
