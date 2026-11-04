<?php
/**
 * modules/AutoReply.php
 * موتور پاسخ خودکار چندکاناله. روی پیام دریافتی از هر کانالی کار می‌کند.
 *
 * قانون در eb_auto_replies:
 *   channel        : محدود به یک کانال خاص یا NULL برای همه‌ی کانال‌ها
 *   trigger_keyword: کلمه/الگوی محرک
 *   trigger_type   : exact | contains | starts_with | regex
 *   response_json  : { "text": "...", "buttons": [...], "request_contact": bool }
 *   priority       : عدد بزرگ‌تر = اولویت بالاتر
 *   is_active      : 0/1
 *
 * این ماژول از وب‌هوک صدا زده می‌شود (پس از ثبت پیام در بانک مشتری).
 */

class AutoReply
{
    private static ?bool $hasPageCol = null;

    /** ستون ig_account_id (قانون مخصوص یک پیج اینستاگرام) — خودکار اضافه می‌شود */
    private static function flowsAvailable(): bool
    {
        if (class_exists('Flows')) return true;
        $f = __DIR__ . '/Flows.php';
        if (!is_file($f)) return false;
        require_once $f;
        return true;
    }

    private static function flowEvent(string $type, string $channel, string $channelUid, string $text): void
    {
        if (!self::flowsAvailable()) return;
        try {
            $pid = (int)(Database::one("SELECT person_id FROM eb_identities WHERE channel = ? AND channel_uid = ? LIMIT 1", [$channel, $channelUid])['person_id'] ?? 0);
            Flows::onEvent($type, ['person_id' => $pid, 'channel' => $channel, 'channel_uid' => $channelUid, 'text' => $text]);
        } catch (Throwable $e) {}
    }

    public static function hasPageColumn(): bool
    {
        if (self::$hasPageCol !== null) return self::$hasPageCol;
        try {
            if (!Database::all("SHOW COLUMNS FROM eb_auto_replies LIKE 'ig_account_id'")) {
                Database::run("ALTER TABLE eb_auto_replies ADD COLUMN ig_account_id INT DEFAULT NULL");
            }
            return self::$hasPageCol = true;
        } catch (Throwable $e) {
            return self::$hasPageCol = false;
        }
    }

    /** فهرست قوانین (برای UI) — $accountId: فقط قوانین یک پیج */
    public static function all(?int $accountId = null): array
    {
        if (self::hasPageColumn()) {
            $sql = "SELECT r.*, a.label AS page_label, a.color AS page_color FROM eb_auto_replies r LEFT JOIN eb_ig_accounts a ON a.id = r.ig_account_id";
            $p = [];
            $w = [];
            if ($accountId) { $w[] = "r.ig_account_id = ?"; $p[] = $accountId; }
            $sc = class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_auto_replies', 'r.site_id') : '';   // ۲.۱.۱
            if ($sc !== '') $w[] = ltrim(substr($sc, 5));
            if ($w) $sql .= " WHERE " . implode(' AND ', $w);
            try { return Database::all($sql . " ORDER BY r.priority DESC, r.id DESC", $p); } catch (Throwable $e) {}
        }
        $sc = class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_auto_replies') : '';
        return Database::all(
            "SELECT * FROM eb_auto_replies WHERE 1=1{$sc} ORDER BY priority DESC, id DESC"
        );
    }

    /**
     * شرط SQL قوانین قابل‌اجرا با توجه به «پیج جاری» اینستاگرام:
     *  - بدون پیج جاری (کانال‌های دیگر): فقط قوانین بدون پیج
     *  - با پیج جاری: قوانین همین پیج + (اگر در تنظیمات پیج اجازه داده شده) قوانین «همه‌ی پیج‌ها»
     * @return array{0:string,1:array,2:string} [where, params, orderPrefix]
     */
    private static function pageScope(): array
    {
        if (!self::hasPageColumn()) return ['', [], ''];
        $cur = class_exists('IgPageSettings') ? IgPageSettings::current() : null;
        if (!$cur) return [' AND ig_account_id IS NULL', [], ''];
        $global = IgPageSettings::cur('autoreply_global') !== '0';
        return $global
            ? [' AND (ig_account_id IS NULL OR ig_account_id = ?)', [$cur], 'CASE WHEN ig_account_id IS NULL THEN 1 ELSE 0 END, ']
            : [' AND ig_account_id = ?', [$cur], ''];
    }

    public static function save(array $data, ?int $id = null): int
    {
        $response = json_encode([
            'text'            => $data['text'] ?? '',
            'buttons'         => $data['buttons'] ?? null,
            'request_contact' => !empty($data['request_contact']),
            'carousel'        => class_exists('Carousel') ? (Carousel::normalize($data['carousel'] ?? null) ?: null) : null,
        ], JSON_UNESCAPED_UNICODE);

        $type = $data['trigger_type'] ?? 'exact';
        // نوع رویدادیِ on_contact کلمه‌ی کلیدی ندارد؛ یک مقدار ثابت بی‌اثر می‌گذاریم
        // تا قید NOT NULL ستون نقض نشود و ادمین مجبور به وارد کردن کلمه نباشد.
        $keyword = ($type === 'on_contact')
            ? '__contact__'
            : (string)($data['trigger_keyword'] ?? '');

        $pageId = !empty($data['ig_account_id']) ? (int)$data['ig_account_id'] : null;
        if ($pageId) $data['channel'] = 'instagram'; // قانون مخصوص پیج فقط برای اینستاگرام معنی دارد

        if ($id) {
            if (self::hasPageColumn() && array_key_exists('ig_account_id', $data)) {
                Database::run("UPDATE eb_auto_replies SET ig_account_id = ? WHERE id = ?", [$pageId, $id]);
            }
            Database::run(
                "UPDATE eb_auto_replies SET channel=?, trigger_keyword=?, trigger_type=?, response_json=?, priority=?, is_active=? WHERE id=?",
                [
                    $data['channel'] ?: null,
                    $keyword,
                    $type,
                    $response,
                    (int)($data['priority'] ?? 0),
                    !empty($data['is_active']) ? 1 : 0,
                    $id,
                ]
            );
            self::applySite($id, $data);
            return $id;
        }
        Database::run(
            "INSERT INTO eb_auto_replies (channel, trigger_keyword, trigger_type, response_json, priority, is_active)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['channel'] ?: null,
                $keyword,
                $type,
                $response,
                (int)($data['priority'] ?? 0),
                isset($data['is_active']) ? (int)!empty($data['is_active']) : 1,
            ]
        );
        $newId = Database::lastId();
        if ($pageId && self::hasPageColumn()) Database::run("UPDATE eb_auto_replies SET ig_account_id = ? WHERE id = ?", [$pageId, $newId]);
        self::applySite($newId, $data);
        return $newId;
    }

    /** ۲.۱.۱: محدودهٔ سایت قانون (site_scope: all|site) */
    private static function applySite(int $id, array $data): void
    {
        if (!array_key_exists('site_scope', $data) || !class_exists('SiteCtx') || !SiteCtx::multi() || !Database::hasColumn('eb_auto_replies', 'site_id')) return;
        Database::run("UPDATE eb_auto_replies SET site_id = ? WHERE id = ?", [SiteCtx::scopeValue($data['site_scope']), $id]);
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_auto_replies WHERE id = ?", [$id]);
    }

    /**
     * آیا متن با قانون تطبیق دارد؟
     * توجه: نوع 'on_contact' یک تریگر رویدادی است (نه متنی) و اینجا هرگز تطبیق نمی‌دهد؛
     * پردازش آن جداگانه در handleContact() انجام می‌شود تا با پیام‌های متنی قاطی نشود.
     */
    private static function matches(string $text, string $keyword, string $type): bool
    {
        switch ($type) {
            case 'exact':       return $text === $keyword;
            case 'contains':    return $keyword !== '' && mb_stripos($text, $keyword) !== false;
            case 'starts_with': return $keyword !== '' && mb_strpos($text, $keyword) === 0;
            case 'regex':       return @preg_match($keyword, $text) === 1;
            default:            return false; // on_contact و انواع ناشناخته در مسیر متنی رد می‌شوند
        }
    }

    /**
     * پاسخ خودکار رویدادیِ «اشتراک شماره».
     * وقتی کاربر شماره‌اش را به اشتراک گذاشت صدا زده می‌شود (نه با کلمه‌ی کلیدی).
     * قواعدِ فعالِ نوع 'on_contact' برای این کانال (یا همه‌کاناله) را به ترتیب اولویت
     * بررسی و اولین مورد را می‌فرستد.
     *
     * $channel    : کانالی که رویداد از آن آمده
     * $channelUid : شناسه‌ی گیرنده در آن کانال (برای ارسال پاسخ)
     *
     * خروجی: true اگر قانونی پیدا و پاسخ ارسال شد.
     */
    public static function handleContact(string $channel, string $channelUid): bool
    {
        // سناریوهایی که با «اشتراک شماره» شروع می‌شوند
        self::flowEvent('contact_shared', $channel, $channelUid, '');
        [$pw, $pp, $po] = self::pageScope();
        $pw .= class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_auto_replies') : '';   // ۲.۱.۱
        $rules = Database::all(
            "SELECT * FROM eb_auto_replies
             WHERE is_active = 1 AND trigger_type = 'on_contact'
                   AND (channel IS NULL OR channel = ?){$pw}
             ORDER BY {$po}priority DESC, id DESC",
            array_merge([$channel], $pp)
        );
        if (!$rules) return false;

        $driver = ChannelManager::get($channel);
        if (!$driver) return false;

        foreach ($rules as $rule) {
            $resp = json_decode($rule['response_json'], true) ?: [];
            $text = $resp['text'] ?? '';
            // اگر متنی برای پاسخ تعریف نشده، این قاعده را رد کن (چیزی برای ارسال نیست)
            if ($text === '' && empty($resp['buttons']) && empty($resp['carousel'])) continue;

            $message = ['text' => $text];
            if (!empty($resp['carousel'])) $message['carousel'] = $resp['carousel'];
            if (!empty($resp['buttons']) && is_array($resp['buttons'])) {
                $message['buttons'] = Templates::normalizeButtons($resp['buttons']);
            }
            // در پاسخِ «دریافت شماره» معمولاً دیگر درخواست شماره معنی ندارد، ولی اگر ادمین
            // عمداً ست کرده باشد و کانال پشتیبانی کند، رعایت می‌کنیم.
            if (!empty($resp['request_contact'])
                && in_array('request_contact', $driver->capabilities(), true)) {
                $message['request_contact'] = true;
            }

            $recipient = ['channel_uid' => $channelUid, 'phone' => $channelUid];
            // ارسال + ثبت در تاریخچه (مورد ۱۰): قبلاً مستقیم driver->send بود و
            // پاسخ بات در صندوق ورودی دیده نمی‌شد.
            BotReply::send($channel, $channelUid, $message, BotReply::SRC_AUTOREPLY);
            return true; // اولین تطبیق کافی است
        }
        return false;
    }

    /**
     * بررسی و اجرای پاسخ خودکار برای یک پیام دریافتی.
     *
     * $channel     : کانالی که پیام از آن آمده
     * $channelUid  : شناسه‌ی گیرنده در آن کانال (برای ارسال پاسخ)
     * $text        : متن پیام دریافتی
     *
     * خروجی: true اگر قانونی تطبیق و پاسخ ارسال شد.
     */
    public static function handle(string $channel, string $channelUid, ?string $text): bool
    {
        if ($text === null || $text === '') return false;

        // پاسخ به نظرسنجی رضایت (عدد ۱ تا ۵) قبل از هر چیز؛ پیام دیگر یعنی ادامه‌ی گفتگو
        if (class_exists('Csat')) {
            try { if (Csat::onMessage($channel, $channelUid, $text)) return true; } catch (Throwable $e) {}
        }

        // سناریوساز: اجرای منتظر پاسخ یا سناریویی که با این پیام شروع می‌شود، اولویت دارد
        if (self::flowsAvailable()) {
            try { if (Flows::onMessage($channel, $channelUid, $text)) return true; } catch (Throwable $e) { /* سناریو نباید پاسخ خودکار را بشکند */ }
        }

        // قوانین فعالِ مربوط به این کانال یا همه‌کاناله، به ترتیب اولویت
        // پیج جاری اینستاگرام: اگر پاسخ خودکار برای این پیج خاموش است، هیچ قانونی اجرا نمی‌شود
        if (class_exists('IgPageSettings') && !IgPageSettings::on('autoreply_enabled')) return false;
        [$pw, $pp, $po] = self::pageScope();
        $pw .= class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_auto_replies') : '';   // ۲.۱.۱
        $rules = Database::all(
            "SELECT * FROM eb_auto_replies
             WHERE is_active = 1 AND (channel IS NULL OR channel = ?){$pw}
             ORDER BY {$po}priority DESC, id DESC",
            array_merge([$channel], $pp)
        );

        foreach ($rules as $rule) {
            if (!self::matches($text, $rule['trigger_keyword'], $rule['trigger_type'])) {
                continue;
            }

            $resp = json_decode($rule['response_json'], true) ?: [];
            $message = ['text' => $resp['text'] ?? ''];
            if (!empty($resp['carousel'])) $message['carousel'] = $resp['carousel'];

            // دکمه‌ها (با نرمال‌سازی فرمت داخلی)
            if (!empty($resp['buttons']) && is_array($resp['buttons'])) {
                $message['buttons'] = Templates::normalizeButtons($resp['buttons']);
            }

            // درخواست اشتراک شماره (فقط کانال‌هایی که پشتیبانی می‌کنند)
            $driver = ChannelManager::get($channel);
            if (!$driver) return false;

            if (!empty($resp['request_contact'])
                && in_array('request_contact', $driver->capabilities(), true)) {
                $message['request_contact'] = true;
            }

            // کانال‌های شماره‌ای (پیامک/صفیر) به 'phone' نیاز دارند، بقیه به 'channel_uid'.
            // هر دو را می‌فرستیم تا هر درایور آنچه را لازم دارد بردارد.
            // ارسال از مسیر BotReply تا پاسخ در تاریخچه‌ی گفتگو ثبت شود (مورد ۱۰).
            BotReply::send($channel, $channelUid, $message, BotReply::SRC_AUTOREPLY);
            return true; // اولین تطبیق کافی است
        }

        return false;
    }
}
