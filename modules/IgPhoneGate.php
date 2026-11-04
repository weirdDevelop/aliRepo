<?php
/**
 * modules/IgPhoneGate.php
 * گرفتن اجباری شماره تلفن قبل از هر مکالمه‌ی دیگر در دایرکت اینستاگرام — دقیقاً همان
 * رفتاری که چت آنلاین سایت با mode='require_phone' دارد (modules/Webchat.php)، فقط اینجا
 * چون دایرکت اینستاگرام یک ویجت با فرم اختصاصی ندارد (خودِ گفتگوی متنی است)، اولین پیام
 * غیرشماره یک درخواست شماره برمی‌گرداند، و شماره‌ی بعدی با Persons::attachPhone() -که
 * ادغام با پروفایل موجود (اگر آن شماره قبلاً جای دیگری ثبت شده) را هم انجام می‌دهد- ثبت می‌شود.
 */

class IgPhoneGate
{
    public static function isEnabled(): bool
    {
        // تنظیم مخصوص پیج جاری (روشن/خاموش) بر تنظیم کلی مقدم است
        if (class_exists('IgPageSettings')) {
            $pg = IgPageSettings::cur('phone_gate');
            if ($pg === 'on') return true;
            if ($pg === 'off') return false;
        }
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.phone_gate_enabled'");
            // پیش‌فرض روشن (طبق درخواست صریح) — فقط با ذخیره‌ی '0' خاموش می‌شود
            return $row === null || $row['v'] !== '0';
        } catch (Throwable $e) {
            return true;
        }
    }

    public static function setEnabled(bool $on): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.phone_gate_enabled', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$on ? '1' : '0']
        );
    }

    public static function promptText(): string
    {
        if (class_exists('IgPageSettings') && trim((string)IgPageSettings::cur('phone_gate_text')) !== '') return IgPageSettings::cur('phone_gate_text');
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.phone_gate_text'");
            if ($row && trim((string)$row['v']) !== '') return $row['v'];
        } catch (Throwable $e) {}
        return 'سلام 🙏 برای ادامه، لطفاً شماره موبایلت رو برام بفرست (مثلاً 09121234567).';
    }

    public static function setPromptText(string $text): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.phone_gate_text', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$text]
        );
    }

    public static function thankYouText(): string
    {
        if (class_exists('IgPageSettings') && trim((string)IgPageSettings::cur('phone_gate_thanks')) !== '') return IgPageSettings::cur('phone_gate_thanks');
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.phone_gate_thanks'");
            if ($row && trim((string)$row['v']) !== '') return $row['v'];
        } catch (Throwable $e) {}
        return 'ممنون! شماره‌ت ثبت شد ✅ حالا بفرمایید چه کمکی از دستم برمیاد؟';
    }

    public static function setThankYouText(string $text): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.phone_gate_thanks', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$text]
        );
    }

    /**
     * @return bool آیا این پیام توسط گیت مصرف شد؟ (یعنی نباید به فرم/منو/AI برسد)
     */
    public static function handle($driver, string $channelUid, int $personId, string $text): bool
    {
        if (!self::isEnabled()) return false;

        $person = Database::one("SELECT status FROM eb_persons WHERE id = ?", [$personId]);
        if (!$person || $person['status'] === 'member') return false; // قبلاً شماره داده

        // شماره می‌تواند هر جای پیام باشد و با ارقام فارسی («شمارم ۰۹۱۲… هست»)
        $phone = Persons::extractPhone($text) ?: Persons::normalizePhone($text);
        $msg = ['text' => $phone ? self::thankYouText() : self::promptText()];
        if ($phone) Persons::attachPhone($personId, $phone);
        $r = $driver->send(['channel_uid' => $channelUid], $msg);
        if (class_exists('IgCommentAutomation')) IgCommentAutomation::logOut($channelUid, $msg, 'phone_gate', $r);
        return true;
    }
}

/**
 * تنظیمات جداگانه‌ی هر پیج اینستاگرام (eb_ig_page_settings — خودکار ساخته می‌شود).
 *
 * پایپ‌لاین قبل از پردازش هر دایرکت/کامنت IgPageSettings::setCurrent(id پیج) را صدا می‌زند؛
 * ماژول‌ها (پاسخ خودکار، AI، گیت شماره، منو، فرم، اعلان) از همین‌جا تنظیم «پیج جاری» را می‌خوانند.
 * مقدار 'inherit' یعنی «طبق تنظیمات کلی اعلان‌بان».
 *
 * (این کلاس داخل همین فایل است چون همه‌ی ورودی‌هایی که پایپ‌لاین اینستاگرام را اجرا می‌کنند این فایل را require می‌کنند.)
 */
class IgPageSettings
{
    const DEFAULTS = [
        'autoreply_enabled' => '1',        // پاسخ خودکار کلمه‌کلیدی روی این پیج
        'autoreply_global'  => '1',        // قوانین «همه‌ی پیج‌ها» هم اجرا شوند؟ (0 = فقط قوانین مخصوص همین پیج)
        'ai_mode'           => 'inherit',  // inherit | auto | off
        'ai_prompt'         => '',         // دستورالعمل اضافه‌ی AI مخصوص این پیج (لحن، محصول، قوانین)
        'ai_signature'      => '',         // امضای پاسخ AI (خالی = امضای کلی)
        'phone_gate'        => 'inherit',  // inherit | on | off
        'phone_gate_text'   => '',
        'phone_gate_thanks' => '',
        'botmenu_enabled'   => '1',
        'forms_enabled'     => '1',
        'notify_enabled'    => '1',        // اعلان «پیام تازه» در پنل برای این پیج
    ];

    private static ?int $current = null;
    private static array $cache = [];
    private static ?bool $ready = null;

    public static function setCurrent(?int $accountId): void { self::$current = $accountId ?: null; }
    public static function current(): ?int { return self::$current; }

    public static function ensure(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            Database::run("CREATE TABLE IF NOT EXISTS eb_ig_page_settings (
                ig_account_id INT NOT NULL PRIMARY KEY,
                settings_json MEDIUMTEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return self::$ready = true;
        } catch (Throwable $e) {
            error_log('[IgPageSettings] ' . $e->getMessage());
            return self::$ready = false;
        }
    }

    public static function get(int $accountId): array
    {
        if (isset(self::$cache[$accountId])) return self::$cache[$accountId];
        $s = self::DEFAULTS;
        if (self::ensure()) {
            try {
                $row = Database::one("SELECT settings_json FROM eb_ig_page_settings WHERE ig_account_id = ?", [$accountId]);
                $saved = $row ? (json_decode((string)$row['settings_json'], true) ?: []) : [];
                foreach ($saved as $k => $v) if (array_key_exists($k, $s)) $s[$k] = (string)$v;
            } catch (Throwable $e) {}
        }
        return self::$cache[$accountId] = $s;
    }

    public static function save(int $accountId, array $values): array
    {
        if (!self::ensure()) throw new RuntimeException('جدول تنظیمات پیج ساخته نشد');
        $s = self::get($accountId);
        foreach ($values as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) continue;
            $v = is_bool($v) ? ($v ? '1' : '0') : trim((string)$v);
            if (in_array($k, ['ai_mode'], true) && !in_array($v, ['inherit', 'auto', 'off'], true)) $v = 'inherit';
            if ($k === 'phone_gate' && !in_array($v, ['inherit', 'on', 'off'], true)) $v = 'inherit';
            if (substr($k, -8) === '_enabled' || $k === 'autoreply_global') $v = ($v === '1' || $v === 'true' || $v === 'on') ? '1' : '0';
            $s[$k] = mb_substr($v, 0, 4000);
        }
        $json = json_encode($s, JSON_UNESCAPED_UNICODE);
        if (Database::one("SELECT ig_account_id FROM eb_ig_page_settings WHERE ig_account_id = ?", [$accountId])) {
            Database::run("UPDATE eb_ig_page_settings SET settings_json = ?, updated_at = ? WHERE ig_account_id = ?", [$json, date('Y-m-d H:i:s'), $accountId]);
        } else {
            Database::run("INSERT INTO eb_ig_page_settings (ig_account_id, settings_json, updated_at) VALUES (?, ?, ?)", [$accountId, $json, date('Y-m-d H:i:s')]);
        }
        self::$cache[$accountId] = $s;
        return $s;
    }

    /** مقدار یک کلید برای پیج جاری؛ اگر پیجی در جریان نیست null */
    public static function cur(string $key): ?string
    {
        if (!self::$current) return null;
        return self::get(self::$current)[$key] ?? null;
    }

    /** آیا یک قابلیت (کلید *_enabled) روی پیج جاری روشن است؟ بدون پیج جاری = true */
    public static function on(string $key): bool
    {
        $v = self::cur($key);
        return $v === null || $v === '1';
    }
}
