<?php
/**
 * modules/Support.php
 * وضعیت تیم پشتیبانی: آنلاین/آفلاین، ساعت کاری، و ارجاع گفتگو به هوش مصنوعی.
 *
 * چرا این ماژول لازم شد: کارفرما خواست AI «در زمان آفلاین بودن تیم پشتیبانی یا ارجاع
 * دستی» جواب بدهد. تا پیش از این هیچ مفهومی از آنلاین/آفلاین یا ساعت کاری در پروژه
 * وجود نداشت، پس مرزِ «کِی انسان، کِی AI» جایی برای زندگی نداشت.
 *
 * تنظیمات در `eb_settings` با پیشوند `support.` (همان الگوی Brand/Ai).
 *
 * نکته‌ی زمان: پروژه هیچ‌جا `date_default_timezone_set` را صدا نمی‌زند، پس روی هاستِ
 * UTC توابع تاریخِ PHP سه‌ونیم ساعت عقب‌اند. اینجا عمداً به منطقه‌ی زمانیِ سراسری
 * تکیه نمی‌کنیم و ساعت را با `DateTimeZone` صریح حساب می‌کنیم.
 */

class Support
{
    const DEFAULTS = [
        'presence' => 'auto',          // auto | online | offline (اجبار دستی اپراتور)
        'tz'       => 'Asia/Tehran',
        'days'     => '6,0,1,2,3',     // شماره‌ی روز به سبک PHP: ۶=شنبه ۰=یکشنبه … ۵=جمعه
        'from'     => '09:00',
        'to'       => '18:00',
    ];

    /** نام روزها به ترتیب شماره‌ی PHP (0=یکشنبه) */
    const DAY_NAMES = [
        0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه',
        4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه',
    ];

    /** ترتیب نمایش هفته‌ی ایرانی (شنبه اول) */
    const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5];

    /** @var array|null */
    private static $cache = null;

    // ==================== تنظیمات ====================

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'support.%'");
            foreach ($rows as $r) {
                $key = substr($r['k'], 8);   // حذف 'support.'
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) { /* پیش‌فرض‌ها */ }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key): string
    {
        $a = self::all();
        return (string)($a[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function save(array $values): void
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $v = (string)$v;

            // اعتبارسنجی، تا مقدار خراب ساعت کاری را بی‌صدا نشکند
            if ($k === 'presence' && !in_array($v, ['auto','online','offline'], true)) continue;
            if ($k === 'tz'   && !in_array($v, timezone_identifiers_list(), true))      continue;
            if (($k === 'from' || $k === 'to') && !preg_match('/^\d{1,2}:\d{2}$/', $v)) continue;
            if ($k === 'days') {
                $parts = array_filter(array_map('trim', explode(',', $v)), fn($d) => $d !== '' && ctype_digit($d) && (int)$d <= 6);
                $v = implode(',', array_unique($parts));
            }

            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ['support.' . $k, $v]
            );
        }
        self::$cache = null;
    }

    // ==================== آنلاین یا آفلاین ====================

    public static function isOnline(): bool
    {
        return self::status()['online'];
    }

    /**
     * وضعیت کامل، برای UI و برای تصمیم AI.
     * @return array{online:bool, presence:string, reason:string, now:string, window:string, days:array}
     */
    public static function status(): array
    {
        $a = self::all();
        $tz = self::tz();

        try {
            $now = new DateTime('now', $tz);
        } catch (Throwable $e) {
            // اگر ساخت تاریخ شکست خورد، محافظه‌کارانه «آنلاین» می‌گیریم تا AI
            // به‌اشتباه جای انسان جواب ندهد.
            return ['online' => true, 'presence' => $a['presence'], 'reason' => 'زمان قابل محاسبه نبود',
                    'now' => '', 'from' => $a['from'], 'to' => $a['to'], 'tz' => $a['tz'],
                    'window' => '', 'days' => self::days()];
        }

        $out = [
            'presence' => $a['presence'],
            'now'      => $now->format('H:i'),
            'from'     => $a['from'],
            'to'       => $a['to'],
            'tz'       => $a['tz'],
            'window'   => $a['from'] . ' تا ' . $a['to'],
            'days'     => self::days(),
        ];

        if ($a['presence'] === 'online')  return $out + ['online' => true,  'reason' => 'به‌صورت دستی آنلاین شده'];
        if ($a['presence'] === 'offline') return $out + ['online' => false, 'reason' => 'به‌صورت دستی آفلاین شده'];

        $dow = (int)$now->format('w');
        if (!in_array($dow, self::days(), true)) {
            return $out + ['online' => false, 'reason' => (self::DAY_NAMES[$dow] ?? '') . ' روز کاری نیست'];
        }

        $mins = (int)$now->format('G') * 60 + (int)$now->format('i');
        $from = self::toMinutes($a['from']);
        $to   = self::toMinutes($a['to']);

        // بازه‌ی گذرنده از نیمه‌شب (مثلاً ۲۲:۰۰ تا ۰۶:۰۰)
        $inWindow = ($from <= $to)
            ? ($mins >= $from && $mins < $to)
            : ($mins >= $from || $mins < $to);

        return $out + [
            'online' => $inWindow,
            'reason' => $inWindow ? 'در ساعت کاری' : 'بیرون از ساعت کاری',
        ];
    }

    /** روزهای کاری به‌صورت آرایه‌ی عدد */
    public static function days(): array
    {
        $raw = self::get('days');
        $out = [];
        foreach (explode(',', $raw) as $d) {
            $d = trim($d);
            if ($d !== '' && ctype_digit($d)) {
                $n = (int)$d;
                if ($n >= 0 && $n <= 6) $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    private static function tz(): DateTimeZone
    {
        try { return new DateTimeZone(self::get('tz')); }
        catch (Throwable $e) { return new DateTimeZone('Asia/Tehran'); }
    }

    private static function toMinutes(string $hhmm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) return 0;
        return min(23, (int)$m[1]) * 60 + min(59, (int)$m[2]);
    }

    // ==================== ارجاع گفتگو به هوش مصنوعی ====================

    /**
     * ارجاع دستی: از این لحظه AI این گفتگو را جواب می‌دهد، حتی در ساعت کاری.
     * برگشت به انسان با `unassignAi`.
     */
    public static function assignAi(int $personId): bool
    {
        if (!self::hasHandoff()) return false;
        Database::run("UPDATE eb_persons SET ai_handoff_at = NOW() WHERE id = ?", [$personId]);
        if (class_exists('SalesAgent')) SalesAgent::resume($personId);
        return true;
    }

    public static function unassignAi(int $personId): bool
    {
        if (!self::hasHandoff()) return false;
        Database::run("UPDATE eb_persons SET ai_handoff_at = NULL WHERE id = ?", [$personId]);
        return true;
    }

    /** آیا این گفتگو دستی به AI ارجاع شده؟ */
    public static function isAiAssigned(?int $personId): bool
    {
        if (!$personId || !self::hasHandoff()) return false;
        $r = Database::one("SELECT ai_handoff_at FROM eb_persons WHERE id = ?", [$personId]);
        return $r && !empty($r['ai_handoff_at']);
    }

    // ---- گارد ستون (تا قبل از db_upgrade کرش نکنیم) ----

    /** @var bool|null */
    private static $hasHandoff = null;
    public static function hasHandoff(): bool
    {
        if (self::$hasHandoff !== null) return self::$hasHandoff;
        try {
            self::$hasHandoff = Database::hasColumn('eb_persons', 'ai_handoff_at');
        } catch (Throwable $e) { self::$hasHandoff = false; }
        return self::$hasHandoff;
    }
}
