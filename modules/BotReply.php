<?php
/**
 * modules/BotReply.php
 * ارسال پاسخِ خودکارِ بات + **ثبت آن در تاریخچه‌ی گفتگو**.
 *
 * ریشه‌ی باگ (مورد ۱۰ دور بازخورد): سه موتور خودکار — AutoReply، BotMenu و Forms —
 * مستقیم `$driver->send()` می‌زدند و هیچ ردیفی در `eb_messages` نمی‌ساختند. فقط
 * `Sender::sendToPerson` ثبت می‌کرد. نتیجه: در صندوق ورودی **سؤال بات دیده نمی‌شد**،
 * پس وقتی مشتری «۱» می‌فرستاد، اپراتور نمی‌فهمید این بازخورد روی کدام پیام است.
 *
 * حالا هر سه موتور از این نقطه‌ی واحد عبور می‌کنند: هم می‌فرستند، هم با برچسبِ منبع
 * (`source`) در تاریخچه ثبت می‌کنند. هیچ خطای ثبتی نباید ارسال را بترکاند.
 *
 * وابسته به: ChannelManager (درایور)، Database.
 * عمداً به Sender وابسته نیست — چون webhook و cron لزوماً Sender را بارگذاری نمی‌کنند.
 */

class BotReply
{
    /** منبع‌های شناخته‌شده (برای برچسب در UI) */
    public const SRC_AUTOREPLY = 'autoreply';
    public const SRC_BOTMENU   = 'botmenu';
    public const SRC_FORM      = 'form';
    public const SRC_AI        = 'ai';

    /**
     * ارسال یک پیام خودکار به کاربر و ثبتش در تاریخچه.
     *
     * $channel    : کانال (bale/telegram/roubika/...)
     * $channelUid : شناسه‌ی کاربر در آن کانال
     * $message    : آرایه‌ی پیام داخلی (text/buttons/photo/request_contact/...)
     * $source     : یکی از SRC_* — برای برچسب «پاسخ خودکار / منوی بات / فرم» در گفتگو
     *
     * خروجی: true اگر ارسال موفق بود.
     */
    public static function send(string $channel, string $channelUid, array $message, string $source): bool
    {
        $driver = ChannelManager::get($channel);
        if (!$driver) return false;

        // ۲.۱.۰: پیام با کروسل → Carousel::dispatch مطابق کانال می‌فرستد
        $result = !empty($message['carousel']) && class_exists('Carousel')
            ? Carousel::dispatch($driver, $channel, ['channel_uid' => $channelUid, 'phone' => $channelUid], $message)
            : $driver->send(['channel_uid' => $channelUid, 'phone' => $channelUid], $message);
        $ok = !empty($result['ok']);

        // ثبت در تاریخچه — هرگز نباید ارسال را بترکاند
        try {
            self::log($channel, $channelUid, $message, $source, $ok, $result['error'] ?? null);
        } catch (Throwable $e) { /* بی‌صدا */ }

        return $ok;
    }

    /**
     * ثبت یک پیام خروجیِ خودکار در `eb_messages`.
     * اگر هویتی برای این channel_uid پیدا نشود (نادر)، بی‌صدا رد می‌شود.
     */
    public static function log(
        string $channel,
        string $channelUid,
        array $message,
        string $source,
        bool $ok = true,
        ?string $error = null
    ): void {
        $identity = Database::one(
            "SELECT id FROM eb_identities WHERE channel = ? AND channel_uid = ? LIMIT 1",
            [$channel, $channelUid]
        );
        if (!$identity) return;

        $body   = (string)($message['text'] ?? '');
        // ۲.۱.۰: کروسل — متن جایگزین برای همکاران + کارت‌ها برای ویجت سایت
        if (!empty($message['carousel']) && class_exists('Carousel')) {
            $cc = Carousel::normalize($message['carousel']);
            if ($cc) {
                $body = trim($body . ($body !== '' ? "\n\n" : '') . Carousel::summary($cc));
                $message['cards'] = Carousel::toWebchatCards($cc);
                $message['text'] = $body;
            }
        }
        $status = $ok ? 'sent' : 'failed';
        $err    = $error !== null ? mb_substr($error, 0, 500) : null;

        // رسانه‌ی خروجی (مثلاً عکس پیام پایان فرم)
        $mediaType = !empty($message['photo']) ? 'photo' : (!empty($message['document']) ? 'document' : null);
        $mediaPath = $message['media_rel'] ?? null;

        // اگر متن و رسانه هر دو خالی بودند، چیزی برای نمایش نیست (مثلاً فقط دکمه) —
        // بازهم ثبت می‌کنیم تا ترتیب گفتگو کامل باشد، ولی با متن توضیحی.
        if ($body === '' && $mediaType === null) {
            $body = !empty($message['buttons']) || !empty($message['request_contact'])
                ? '(دکمه)'
                : '';
        }

        // ۱.۰.۱۴: دکمه‌های منو/پاسخ خودکار/فرم در گفتگوی سایت همراه پیام ذخیره می‌شوند (ویجت دکمه نشان می‌دهد)
        $payload = ($channel === 'webchat' && class_exists('ChatPlus') && ChatPlus::hasPayload()) ? ChatPlus::payloadFromMessage($message) : null;
        if ($payload && self::hasSource() && self::hasMedia()) {
            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status, error, media_type, media_path, source, payload_json)
                 VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?)",
                [$identity['id'], $channel, $body === '(دکمه)' ? '' : $body, $status, $err, $mediaType, $mediaPath, $source, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            return;
        }
        if (self::hasSource() && self::hasMedia()) {
            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status, error, media_type, media_path, source)
                 VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?)",
                [$identity['id'], $channel, $body, $status, $err, $mediaType, $mediaPath, $source]
            );
            return;
        }
        if (self::hasMedia()) {
            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status, error, media_type, media_path)
                 VALUES (?, ?, 'out', ?, ?, ?, ?, ?)",
                [$identity['id'], $channel, $body, $status, $err, $mediaType, $mediaPath]
            );
            return;
        }
        // نصب قدیمی بدون ستون‌های تازه (migration اجرا نشده) — حداقل متن ثبت شود
        Database::run(
            "INSERT INTO eb_messages (identity_id, channel, direction, body, status)
             VALUES (?, ?, 'out', ?, ?)",
            [$identity['id'], $channel, $body, $status]
        );
    }

    // ---- guardهای ستون (تا قبل از «به‌روزرسانی دیتابیس» کرش نکنیم) ----

    /** @var bool|null */
    private static $hasSource = null;
    private static function hasSource(): bool
    {
        if (self::$hasSource !== null) return self::$hasSource;
        try {
            self::$hasSource = Database::hasColumn('eb_messages', 'source');
        } catch (Throwable $e) { self::$hasSource = false; }
        return self::$hasSource;
    }

    /** @var bool|null */
    private static $hasMedia = null;
    private static function hasMedia(): bool
    {
        if (self::$hasMedia !== null) return self::$hasMedia;
        try {
            self::$hasMedia = Database::hasColumn('eb_messages', 'media_type');
        } catch (Throwable $e) { self::$hasMedia = false; }
        return self::$hasMedia;
    }
}
