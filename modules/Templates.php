<?php
/**
 * modules/Templates.php
 * الگوهای پیام قابل استفاده مجدد. مستقل از کانال — یک الگو روی هر کانالی
 * که قابلیت‌اش را داشته باشد ارسال می‌شود.
 *
 * buttons_json: آرایه‌ای از ردیف‌ها، هر دکمه { text, url } یا { text, callback }
 *   مثال: [[{"text":"سایت","url":"https://..."}],[{"text":"تایید","callback":"ok"}]]
 */

class Templates
{
    public static function all(): array
    {
        return Database::all("SELECT * FROM eb_templates ORDER BY created_at DESC");
    }

    public static function find(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_templates WHERE id = ?", [$id]);
    }

    /** ۲.۱.۰: ستون کارت‌های کروسل (خودکار ساخته می‌شود) */
    public static function ensureCardsColumn(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            if (!Database::one("SHOW COLUMNS FROM eb_templates LIKE 'cards_json'")) {
                Database::run("ALTER TABLE eb_templates ADD COLUMN cards_json MEDIUMTEXT NULL");
                if (method_exists('Database', 'forgetSchemaCache')) Database::forgetSchemaCache();
            }
            return $ok = true;
        } catch (Throwable $e) { return $ok = false; }
    }

    public static function save(string $name, string $body, ?string $buttonsJson = null, ?string $mediaPath = null, ?int $id = null, $cards = null): int
    {
        // اعتبارسنجی JSON دکمه‌ها (اگر داده شده)
        if ($buttonsJson !== null && trim($buttonsJson) !== '') {
            $decoded = json_decode($buttonsJson, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('JSON دکمه‌ها نامعتبر است');
            }
        } else {
            $buttonsJson = null;
        }

        if ($id) {
            Database::run(
                "UPDATE eb_templates SET name = ?, body = ?, buttons_json = ?, media_path = COALESCE(?, media_path) WHERE id = ?",
                [$name, $body, $buttonsJson, $mediaPath, $id]
            );
            if ($cards !== false && self::ensureCardsColumn()) Database::run("UPDATE eb_templates SET cards_json = ? WHERE id = ?", [Carousel::toJson($cards), $id]);
            return $id;
        }
        Database::run(
            "INSERT INTO eb_templates (name, body, buttons_json, media_path) VALUES (?, ?, ?, ?)",
            [$name, $body, $buttonsJson, $mediaPath]
        );
        $nid = Database::lastId();
        if ($cards && self::ensureCardsColumn()) Database::run("UPDATE eb_templates SET cards_json = ? WHERE id = ?", [Carousel::toJson($cards), $nid]);
        return $nid;
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_templates WHERE id = ?", [$id]);
    }

    /**
     * تبدیل یک الگو (یا متن خام) به ساختار پیام داخلی که درایورها می‌فهمند:
     *   ['text'=>..., 'photo'=>?, 'buttons'=>?]
     * فرمت دکمه‌ها همان فرمت داخلی است؛ هر درایور خودش به فرمت پلتفرمش ترجمه می‌کند.
     */
    public static function toMessage(array $template): array
    {
        $msg = ['text' => $template['body'] ?? ''];
        if (!empty($template['media_path']) && file_exists($template['media_path'])) {
            $msg['photo'] = $template['media_path'];
        }
        if (!empty($template['cards_json']) && ($cc = Carousel::normalize($template['cards_json']))) $msg['carousel'] = $cc;
        if (!empty($template['buttons_json'])) {
            $btns = json_decode($template['buttons_json'], true);
            if (is_array($btns)) {
                $msg['buttons'] = self::normalizeButtons($btns);
            }
        }
        return $msg;
    }

    /**
     * نرمال‌سازی دکمه‌ها به فرمت داخلی استاندارد: آرایه‌ای از ردیف‌ها،
     * هر دکمه ['text'=>..., 'url'=>...] یا ['text'=>..., 'callback'=>...].
     * هم آرایه‌ی تخت (یک ردیف) و هم آرایه‌ی ردیف‌ها را می‌پذیرد.
     */
    public static function normalizeButtons(array $raw): array
    {
        // اگر اولین عنصر، خودش آرایه‌ای از دکمه‌هاست → آرایه‌ی ردیف‌ها
        $isRows = isset($raw[0]) && is_array($raw[0]) && !isset($raw[0]['text']);
        $rows = $isRows ? $raw : [$raw];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $cells = [];
            foreach ($row as $btn) {
                if (!is_array($btn) || empty($btn['text'])) continue;
                if (!empty($btn['url'])) {
                    $cells[] = ['text' => $btn['text'], 'url' => $btn['url']];
                } elseif (!empty($btn['callback'])) {
                    $cells[] = ['text' => $btn['text'], 'callback' => $btn['callback']];
                }
            }
            if ($cells) $out[] = $cells;
        }
        return $out;
    }
}
