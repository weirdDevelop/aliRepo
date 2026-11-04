<?php
/**
 * modules/Personalize.php
 * شخصی‌سازی متن پیام بر اساس «شخص» گیرنده (per-person).
 *
 * توکن‌های پشتیبانی‌شده در متن پیام:
 *   {name} یا {نام}        → نام کامل شخص (اگر نباشد → «مشتری»)
 *   {firstname} {نام_کوچک} → اولین کلمه‌ی نام
 *   {phone} یا {موبایل}    → شماره‌ی موبایل (اگر نباشد → خالی)
 *   {link:CODE}            → لینک رهگیری شخصی: BASE/r.php?c=CODE&p=PERSON_ID
 *                            (کلیک به همین شخص نسبت داده می‌شود)
 *
 * طراحی: این تابع در Sender::sendToPerson صدا زده می‌شود (که خودش شخص را لود
 * می‌کند)، پس همه‌ی مسیرهای ارسال (انبوه، صف، کمپین، زمان‌بند، پاسخ صندوق)
 * خودکار شخصی‌سازی می‌شوند، بدون تغییر در آن‌ها.
 *
 * نکته: کش سبک per-request برای داده‌ی شخص تا در ارسال انبوه کوئری تکراری نزنیم.
 */

class Personalize
{
    /** آدرس پایه برای ساخت لینک رهگیری؛ از بوت‌استرپ ست می‌شود */
    private static ?string $baseUrl = null;

    /** کش داده‌ی شخص در همین درخواست: person_id → ['name'=>, 'phone'=>] */
    private static array $cache = [];

    /** ست‌کردن آدرس پایه (یک‌بار در بوت‌استرپ هر نقطه‌ی ورود) */
    public static function setBaseUrl(?string $url): void
    {
        self::$baseUrl = $url ? rtrim($url, '/') : null;
    }

    /**
     * آیا این متن اصلاً توکنی دارد؟ (برای صرفه‌جویی از کوئری در ارسال انبوه)
     */
    public static function hasTokens(?string $text): bool
    {
        if ($text === null || $text === '') return false;
        return strpos($text, '{') !== false && (bool)preg_match(
            '/\{(name|firstname|phone|نام|نام_کوچک|موبایل|link:[^}]+)\}/u',
            $text
        );
    }

    /**
     * اعمال شخصی‌سازی روی یک رشته‌ی متنی برای یک شخص.
     * اگر $text توکن نداشته باشد، بدون هیچ کوئری همان متن برمی‌گردد.
     */
    public static function text(?string $text, int $personId): string
    {
        $text = (string)$text;
        if (!self::hasTokens($text)) return $text;

        $p = self::personData($personId);
        $name  = $p['name'] !== '' ? $p['name'] : 'مشتری';
        $first = $name !== '' ? preg_split('/\s+/u', trim($name))[0] : 'مشتری';
        $phone = $p['phone'];

        // ۱) لینک رهگیری شخصی: {link:CODE}
        if (strpos($text, '{link:') !== false) {
            $text = preg_replace_callback(
                '/\{link:([A-Za-z0-9_-]+)\}/u',
                function ($m) use ($personId) {
                    return self::trackingUrl($m[1], $personId);
                },
                $text
            );
        }

        // ۲) توکن‌های ساده
        $map = [
            '{name}'       => $name,
            '{نام}'        => $name,
            '{firstname}'  => $first,
            '{نام_کوچک}'   => $first,
            '{phone}'      => $phone,
            '{موبایل}'     => $phone,
        ];
        return strtr($text, $map);
    }

    /**
     * اعمال شخصی‌سازی روی کل پیام (متن + کپشن دکمه‌ها + url دکمه‌ها).
     * فرمت پیام همان فرمت داخلی Sender است.
     */
    public static function message(array $message, int $personId): array
    {
        if (isset($message['text'])) {
            $message['text'] = self::text($message['text'], $personId);
        }
        // دکمه‌ها: متن و url آن‌ها هم می‌توانند توکن داشته باشند
        if (!empty($message['buttons']) && is_array($message['buttons'])) {
            foreach ($message['buttons'] as &$row) {
                if (!is_array($row)) continue;
                foreach ($row as &$btn) {
                    if (!is_array($btn)) continue;
                    if (isset($btn['text'])) $btn['text'] = self::text($btn['text'], $personId);
                    if (isset($btn['url']))  $btn['url']  = self::text($btn['url'], $personId);
                }
                unset($btn);
            }
            unset($row);
        }
        return $message;
    }

    /** ساخت URL لینک رهگیری شخصی برای یک کد و شخص */
    public static function trackingUrl(string $code, int $personId): string
    {
        $base = self::$baseUrl ?? '';
        $prefix = $base !== '' ? $base . '/' : '';
        return $prefix . 'r.php?c=' . rawurlencode($code) . '&p=' . $personId;
    }

    /** داده‌ی موردنیاز شخص با کش per-request */
    private static function personData(int $personId): array
    {
        if (isset(self::$cache[$personId])) return self::$cache[$personId];
        $row = Database::one(
            "SELECT full_name, phone FROM eb_persons WHERE id = ?",
            [$personId]
        );
        $data = [
            'name'  => trim((string)($row['full_name'] ?? '')),
            'phone' => (string)($row['phone'] ?? ''),
        ];
        self::$cache[$personId] = $data;
        return $data;
    }

    /** پاک‌کردن کش (مثلاً اگر در همان درخواست نام تغییر کرد) */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * وقتی شخص مشخص نیست (مثلاً شماره‌ای که هنوز در بانک مشتری نیست):
     * توکن نام→«مشتری»، موبایل→همان شماره‌ی در دست، لینک→بدون p (عمومی).
     */
    public static function stripTokens(?string $text, ?string $phone = null): string
    {
        $text = (string)$text;
        if (!self::hasTokens($text)) return $text;
        if (strpos($text, '{link:') !== false) {
            $text = preg_replace_callback(
                '/\{link:([A-Za-z0-9_-]+)\}/u',
                function ($m) {
                    $base = self::$baseUrl ?? '';
                    $prefix = $base !== '' ? $base . '/' : '';
                    return $prefix . 'r.php?c=' . rawurlencode($m[1]);
                },
                $text
            );
        }
        return strtr($text, [
            '{name}' => 'مشتری', '{نام}' => 'مشتری',
            '{firstname}' => 'مشتری', '{نام_کوچک}' => 'مشتری',
            '{phone}' => (string)$phone, '{موبایل}' => (string)$phone,
        ]);
    }
}
