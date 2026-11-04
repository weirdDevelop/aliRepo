<?php
/**
 * modules/BotMenu.php
 * بات‌ساز: درخت منوی چندمرحله‌ای با دکمه.
 *
 * هر «گره» (node) یک node_key یکتا دارد، یک متن، و چند دکمه.
 * هر دکمه یک «مقصد» دارد: node_key گره دیگری (goto) یا لینک (url).
 * وقتی کاربر دکمه‌ای می‌زند که callback_data='nav:KEY' است،
 * گره مقصد فرستاده می‌شود. /start گرهِ ریشه را می‌فرستد.
 *
 * این مستقل از AutoReply است و قبل از آن بررسی می‌شود (اولویت با منو).
 */

class BotMenu
{
    /** همه‌ی گره‌ها (برای یک کانال یا همه) */
    public static function all(?string $channel = null): array
    {
        $sc = class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_bot_nodes') : '';   // ۲.۱.۱: گره‌های سایتِ جاری + مشترک
        if ($channel) {
            return Database::all(
                "SELECT * FROM eb_bot_nodes WHERE (channel IS NULL OR channel = ?){$sc} ORDER BY is_root DESC, id",
                [$channel]
            );
        }
        return Database::all("SELECT * FROM eb_bot_nodes WHERE 1=1{$sc} ORDER BY is_root DESC, id");
    }

    public static function find(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_bot_nodes WHERE id = ?", [$id]);
    }

    /** پیدا کردن گره بر اساس key (با ترجیح کانال خاص بر همه‌کاناله) */
    public static function findByKey(string $key, ?string $channel = null): ?array
    {
        $sc = class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_bot_nodes') : '';
        $ord = $sc !== '' ? ' ORDER BY (site_id IS NULL) ASC' : '';   // گرهٔ مخصوص سایت بر گرهٔ مشترک می‌چربد
        $node = Database::one(
            "SELECT * FROM eb_bot_nodes WHERE node_key = ? AND channel = ?{$sc}{$ord} LIMIT 1",
            [$key, $channel]
        );
        if ($node) return $node;
        return Database::one(
            "SELECT * FROM eb_bot_nodes WHERE node_key = ? AND channel IS NULL{$sc}{$ord} LIMIT 1",
            [$key]
        );
    }

    /** گرهِ ریشه (که با /start فرستاده می‌شود) */
    public static function root(?string $channel = null): ?array
    {
        $sc = class_exists('SiteCtx') ? SiteCtx::scopeSql('eb_bot_nodes') : '';
        $ord = $sc !== '' ? ' ORDER BY (site_id IS NULL) ASC' : '';
        $node = Database::one(
            "SELECT * FROM eb_bot_nodes WHERE is_root = 1 AND channel = ?{$sc}{$ord} LIMIT 1",
            [$channel]
        );
        if ($node) return $node;
        return Database::one("SELECT * FROM eb_bot_nodes WHERE is_root = 1 AND channel IS NULL{$sc}{$ord} LIMIT 1");
    }

    public static function save(array $data, ?int $id = null): int
    {
        $key      = trim($data['node_key'] ?? '');
        $channel  = !empty($data['channel']) ? $data['channel'] : null;
        $title    = trim($data['title'] ?? '');
        $text     = (string)($data['text'] ?? '');
        $buttons  = $data['buttons_json'] ?? null;
        $rules    = $data['rules_json'] ?? null;
        $isRoot   = !empty($data['is_root']) ? 1 : 0;
        $awaitsField = !empty($data['awaits_property_field']) ? (string)$data['awaits_property_field'] : null;
        $hasRules = self::hasRulesColumn();

        // فقط یک گره ریشه برای هر کانال
        $siteOn = class_exists('SiteCtx') && SiteCtx::multi() && Database::hasColumn('eb_bot_nodes', 'site_id');
        $siteVal = $siteOn ? SiteCtx::scopeValue($data['site_scope'] ?? '') : null;
        if ($isRoot) {
            // ۲.۱.۱: فقط یک ریشه برای هر کانال «در هر محدوده» (مشترک یا یک سایت)
            Database::run(
                "UPDATE eb_bot_nodes SET is_root = 0 WHERE is_root = 1 AND " .
                ($channel === null ? "channel IS NULL" : "channel = ?") .
                ($siteOn ? ($siteVal === null ? " AND site_id IS NULL" : " AND site_id = " . (int)$siteVal) : ''),
                $channel === null ? [] : [$channel]
            );
        }

        $hasAwaits = self::hasAwaitsFieldColumn();
        $hasCards  = self::ensureCardsColumn();
        $cols = ['node_key', 'channel', 'title', 'text', 'buttons_json'];
        $vals = [$key, $channel, $title, $text, $buttons];
        if ($hasRules)   { $cols[] = 'rules_json';              $vals[] = $rules; }
        if ($hasAwaits)  { $cols[] = 'awaits_property_field';   $vals[] = $awaitsField; }
        if ($hasCards && array_key_exists('cards_json', $data)) { $cols[] = 'cards_json';              $vals[] = class_exists('Carousel') ? Carousel::toJson($data['cards_json'] ?? null) : null; }
        if ($siteOn && array_key_exists('site_scope', $data)) { $cols[] = 'site_id'; $vals[] = $siteVal; }
        $cols[] = 'is_root'; $vals[] = $isRoot;

        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c=?", $cols));
            Database::run("UPDATE eb_bot_nodes SET $set WHERE id=?", array_merge($vals, [$id]));
            return $id;
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        Database::run("INSERT INTO eb_bot_nodes (" . implode(', ', $cols) . ") VALUES ($placeholders)", $vals);
        return Database::lastId();
    }

    /** @var bool|null کش وجود ستون rules_json (تا قبل از migration کرش نکند) */
    private static $hasRules = null;
    public static function hasRulesColumn(): bool
    {
        if (self::$hasRules !== null) return self::$hasRules;
        try {
            $row = Database::one("SHOW COLUMNS FROM eb_bot_nodes LIKE 'rules_json'");
            self::$hasRules = (bool)$row;
        } catch (Throwable $e) { self::$hasRules = false; }
        return self::$hasRules;
    }

    /** @var bool|null ۲.۱.۰: ستون کارت‌های کروسل (خودکار ساخته می‌شود) */
    private static $hasCards = null;
    public static function ensureCardsColumn(): bool
    {
        if (self::$hasCards !== null) return self::$hasCards;
        try {
            if (!Database::one("SHOW COLUMNS FROM eb_bot_nodes LIKE 'cards_json'")) {
                Database::run("ALTER TABLE eb_bot_nodes ADD COLUMN cards_json MEDIUMTEXT NULL");
                if (method_exists('Database', 'forgetSchemaCache')) Database::forgetSchemaCache();
            }
            self::$hasCards = true;
        } catch (Throwable $e) { self::$hasCards = false; }
        return self::$hasCards;
    }

    /** @var bool|null */
    private static $hasAwaitsField = null;
    public static function hasAwaitsFieldColumn(): bool
    {
        if (self::$hasAwaitsField !== null) return self::$hasAwaitsField;
        try {
            $row = Database::one("SHOW COLUMNS FROM eb_bot_nodes LIKE 'awaits_property_field'");
            self::$hasAwaitsField = (bool)$row;
        } catch (Throwable $e) { self::$hasAwaitsField = false; }
        return self::$hasAwaitsField;
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_bot_nodes WHERE id = ?", [$id]);
    }

    /**
     * وارد کردن یک سناریوی کامل با یک JSON (به‌جای ساختن دستی هر گره از UI).
     * هر عنصر: {node_key, channel?, title?, text, buttons?:[{text,goto}], rules?:[{contains,goto}], is_root?}
     * گره‌هایی با همان node_key+channel قبلاً وجود داشته باشند، آپدیت می‌شوند (نه تکراری) —
     * یعنی وارد کردن دوباره‌ی همان JSON بعد از ویرایش دستی چیزی را از بین نمی‌برد.
     * @return array{created:int, updated:int, errors:string[]}
     */
    public static function importScenario(array $nodes): array
    {
        $created = 0; $updated = 0; $errors = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) { $errors[] = 'یک آیتم نامعتبر رد شد'; continue; }
            $key = trim((string)($n['node_key'] ?? ''));
            if ($key === '' || !preg_match('/^[a-z0-9_]+$/i', $key)) {
                $errors[] = "کلید نامعتبر یا خالی: " . ($key ?: '(خالی)');
                continue;
            }
            $channel = !empty($n['channel']) ? (string)$n['channel'] : null;
            $existing = $channel === null
                ? Database::one("SELECT id FROM eb_bot_nodes WHERE node_key = ? AND channel IS NULL", [$key])
                : Database::one("SELECT id FROM eb_bot_nodes WHERE node_key = ? AND channel = ?", [$key, $channel]);

            self::save([
                'node_key'     => $key,
                'channel'      => $channel,
                'title'        => (string)($n['title'] ?? ''),
                'text'         => (string)($n['text'] ?? ''),
                'buttons_json' => !empty($n['buttons']) ? json_encode($n['buttons'], JSON_UNESCAPED_UNICODE) : null,
                'rules_json'   => !empty($n['rules']) ? json_encode($n['rules'], JSON_UNESCAPED_UNICODE) : null,
                'is_root'      => !empty($n['is_root']) ? 1 : 0,
                'awaits_property_field' => $n['awaits_property_field'] ?? null,
                'cards_json'   => $n['cards'] ?? null,
            ], $existing['id'] ?? null);

            $existing ? $updated++ : $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * پردازش ورودی کاربر. اگر منو فعال شد، true.
     * - متن '/start' → گره ریشه
     * - callback 'nav:KEY' → گره KEY
     * خروجی پیام با دکمه‌ها به کاربر فرستاده می‌شود.
     */
    public static function handle(string $channel, string $channelUid, ?string $text, ?string $callbackData = null): bool
    {
        $node = null;

        if ($callbackData !== null && strpos($callbackData, 'nav:') === 0) {
            $key = substr($callbackData, 4);
            $node = self::findByKey($key, $channel);
        } elseif ($text !== null && (trim($text) === '/start' || trim($text) === 'منو' || trim($text) === 'شروع')) {
            $node = self::root($channel);
        } elseif ($text !== null && trim($text) !== '') {
            // شاخه‌بندی شرطی ساده: اگر متن شامل کلمه‌ی یک قاعده بود، به node مقصد برو
            $node = self::matchRule($channel, $text);
        }

        if (!$node) return false;

        $driver = ChannelManager::get($channel);
        if (!$driver) return false;

        $message = self::nodeToMessage($node);
        // ارسال + ثبت در تاریخچه (مورد ۱۰): تا سؤال/منوی بات در صندوق ورودی دیده شود
        BotReply::send($channel, $channelUid, $message, BotReply::SRC_BOTMENU);

        // لوکاپ خودکار اقامتگاه: اگر این گره برای همین منظور علامت خورده، از همین لحظه
        // منتظر اسم اقامتگاه می‌مانیم (PropertyLookup::handleIncoming پیام بعدی را می‌گیرد).
        if (!empty($node['awaits_property_field']) && class_exists('PropertyLookup')) {
            PropertyLookup::setPending($channel, $channelUid, $node['awaits_property_field']);
        }

        return true;
    }

    /**
     * یک node را به آرایه‌ی پیام (text + buttons/contact) تبدیل می‌کند.
     * هم در handle و هم در شبیه‌ساز (تست داخل پنل) استفاده می‌شود.
     */
    public static function nodeToMessage(array $node): array
    {
        $message = ['text' => $node['text'] ?? ''];
        if (!empty($node['cards_json']) && class_exists('Carousel') && ($cc = Carousel::normalize($node['cards_json']))) $message['carousel'] = $cc;
        $btns = json_decode($node['buttons_json'] ?? '[]', true);
        if (is_array($btns) && $btns) {
            $rows = [];
            $needContact = false;
            foreach ($btns as $b) {
                if (!empty($b['contact'])) {
                    $needContact = true;
                    $message['contact_button_text'] = $b['text'] ?? '📱 اشتراک شماره';
                } elseif (!empty($b['url'])) {
                    $rows[] = ['text' => $b['text'] ?? 'لینک', 'url' => $b['url']];
                } elseif (!empty($b['goto'])) {
                    $rows[] = ['text' => $b['text'] ?? '...', 'callback' => 'nav:' . $b['goto']];
                }
            }
            if ($needContact) $message['request_contact'] = true;
            if ($rows) $message['buttons'] = Templates::normalizeButtons($rows);
        }
        return $message;
    }

    /**
     * بررسی قواعد شرطی همه‌ی nodeهای یک کانال در برابر متن کاربر.
     * قاعده: {contains: "کلمه", goto: "node_key"}. اولین تطابق برنده است.
     * خروجی: node مقصد یا null.
     */
    public static function matchRule(string $channel, string $text): ?array
    {
        if (!self::hasRulesColumn()) return null;
        $text = mb_strtolower(trim($text));
        if ($text === '') return null;
        $nodes = self::all($channel);
        foreach ($nodes as $n) {
            $rules = json_decode($n['rules_json'] ?? '[]', true);
            if (!is_array($rules)) continue;
            foreach ($rules as $r) {
                $kw = mb_strtolower(trim($r['contains'] ?? ''));
                $goto = trim($r['goto'] ?? '');
                if ($kw !== '' && $goto !== '' && mb_strpos($text, $kw) !== false) {
                    $target = self::findByKey($goto, $channel);
                    if ($target) return $target;
                }
            }
        }
        return null;
    }

    /**
     * شبیه‌سازی پاسخ بات به یک ورودی (برای تست داخل پنل، بدون ارسال واقعی).
     * $input: ['text'=>..., 'callback'=>...]. خروجی: ['node'=>?, 'message'=>?] یا null اگر بات جوابی ندارد.
     */
    public static function simulate(?string $channel, array $input): ?array
    {
        $node = null;
        $cb = $input['callback'] ?? null;
        $text = $input['text'] ?? null;
        if ($cb !== null && strpos($cb, 'nav:') === 0) {
            $node = self::findByKey(substr($cb, 4), $channel);
        } elseif ($text !== null && in_array(trim($text), ['/start','منو','شروع'], true)) {
            $node = self::root($channel);
        } elseif ($text !== null && trim($text) !== '') {
            $node = self::matchRule($channel ?? '', $text);
        }
        if (!$node) return null;
        return ['node' => $node, 'message' => self::nodeToMessage($node)];
    }
}
