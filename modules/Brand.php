<?php
/**
 * modules/Brand.php
 * تنظیمات برند (سفیدسازی نام تجاری): نام، لوگو، رنگ اصلی.
 * مقادیر در eb_settings با کلیدهای brand.* ذخیره می‌شوند.
 *
 * این لایه به design system وصل است: رنگ اصلی به‌صورت متغیر CSS تزریق می‌شود.
 */

class Brand
{
    /** پیش‌فرض‌ها — اگر چیزی تنظیم نشده باشد. */
    const DEFAULTS = [
        'name'    => 'اعلان‌بان',
        'tagline' => 'سیستم ارتباط چندکاناله',
        'logo'    => '',          // مسیر فایل لوگو (نسبی به ریشه) یا خالی
        'accent'  => '#1D5C4D',   // رنگ اصلی (سبز کاج، هویت اعلان‌بان)
    ];

    /** @var array|null کش در همان درخواست */
    private static $cache = null;

    /** همه‌ی تنظیمات برند را برمی‌گرداند (با پیش‌فرض‌ها برای موارد خالی). */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'brand.%'");
            foreach ($rows as $r) {
                $key = substr($r['k'], 6); // حذف 'brand.'
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) {
            // اگر جدول/ستون نبود، پیش‌فرض‌ها بمانند
        }
        self::$cache = $out;
        return $out;
    }

    /** یک کلید برند. */
    public static function get(string $key): string
    {
        $all = self::all();
        return $all[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /** ذخیره‌ی یک یا چند کلید برند. فقط کلیدهای مجاز. */
    public static function save(array $values): void
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ['brand.' . $k, (string)$v]
            );
        }
        self::$cache = null; // باطل‌کردن کش
    }

    /**
     * تولید CSS متغیرهای رنگ بر اساس رنگ اصلی برند.
     * accent اصلی + نسخه‌ی روشن (soft) + نسخه‌ی تیره (ink) را می‌سازد.
     */
    public static function accentCss(): string
    {
        $accent = self::get('accent');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = self::DEFAULTS['accent'];
        }
        [$r, $g, $b] = self::hexToRgb($accent);
        [$h, $s] = self::rgbToHsl($r, $g, $b); // h:0-360, s:0-100
        $s2 = min($s + 6, 100);
        // پالت روشن
        $light = sprintf(
            ":root{--brand:hsl(%d %d%% 55%%);--brand-2:hsl(%d %d%% 64%%);--brand-ink:hsl(%d %d%% 44%%);"
          . "--brand-soft:hsl(%d %d%% 95%%);--brand-glow:hsla(%d %d%% 55%% / .22);--accent-ink:var(--brand-ink);}",
            $h, $s,  $h, $s2,  $h, $s,  $h, min($s,80),  $h, $s
        );
        // پالت تیره
        $dark = sprintf(
            "html[data-theme=\"dark\"]{--brand:hsl(%d %d%% 62%%);--brand-2:hsl(%d %d%% 70%%);--brand-ink:hsl(%d %d%% 70%%);"
          . "--brand-soft:hsl(%d %d%% 16%%);--brand-glow:hsla(%d %d%% 55%% / .28);--accent-ink:var(--brand-ink);}",
            $h, $s,  $h, $s2,  $h, $s,  $h, min($s,80),  $h, $s
        );
        return $light . $dark;
    }

    /** RGB (0-255) → HSL. خروجی: [h(0-360), s(0-100), l(0-100)] */
    private static function rgbToHsl(int $r, int $g, int $b): array
    {
        $rf = $r / 255; $gf = $g / 255; $bf = $b / 255;
        $max = max($rf, $gf, $bf); $min = min($rf, $gf, $bf);
        $l = ($max + $min) / 2; $h = 0; $s = 0;
        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            if ($max === $rf)      $h = ($gf - $bf) / $d + ($gf < $bf ? 6 : 0);
            elseif ($max === $gf)  $h = ($bf - $rf) / $d + 2;
            else                   $h = ($rf - $gf) / $d + 4;
            $h /= 6;
        }
        return [(int)round($h * 360), (int)round($s * 100), (int)round($l * 100)];
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
