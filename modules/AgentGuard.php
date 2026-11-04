<?php
/**
 * modules/AgentGuard.php — محافظ دستیار در برابر مهندسی پرامپت و بیرون کشیدن داده
 * ==========================================================================
 * لایه‌ها:
 *   ۱) ورودی: هر پیام مشتری با الگوهای شناخته‌شده‌ی حمله (فارسی و انگلیسی) امتیاز می‌گیرد:
 *      نادیده گرفتن دستورها، درخواست پرامپت/تنظیمات، ادعای مدیر/برنامه‌نویس، نقش‌بازی و حالت توسعه،
 *      درخواست اطلاعات بقیه‌ی مشتری‌ها/میزبان‌ها، توکن و کلید، کاوش ابزارها، ترفند کدگذاری.
 *      - امتیاز ≥ «هشدار»: ثبت رویداد، اعلان به تیم، و یک یادآور امنیتی به دستیار
 *      - امتیاز ≥ «مسدود»: پیام اصلاً به مدل نمی‌رسد؛ پاسخ امن ثابت فرستاده می‌شود
 *      - تکرار (پیش‌فرض ۳ بار در ۲۴ ساعت): دستیار برای این مشتری متوقف و گفتگو به همکار سپرده می‌شود
 *   ۲) خروجی: اگر پاسخ دستیار شامل «کد نگهبان» دستور سیستم، تکه‌های دستور سیستم، توکن/کلید یا نام
 *      داخلی ابزارها باشد، فرستاده نمی‌شود و رویداد ثبت می‌شود.
 *   ۳) ابزارها (در SalesAgent): پیگیری رزرو فقط برای کدهای همین گفتگو و پیامک فقط به شماره‌ای که خود
 *      مشتری در گفتگو نوشته — تا با گفتگو نشود اطلاعات رزرو دیگران را گرفت یا به شماره‌ی دیگران پیامک زد.
 */
class AgentGuard
{
    /** [الگو، امتیاز، برچسب] */
    const RULES = [
        ['/(ignore|disregard|forget|override)\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier|your|system)\s+(instructions?|rules|prompts?|messages?)/i', 6, 'نادیده گرفتن دستورها'],
        ['/(دستور|دستورات|دستورهای|دستورالعمل|قوانین|قانون|قواعد|پرامپت|prompt|محدودیت)\S*\s*(قبلی|بالا|اولیه|سیستمی|سیستم|خودت|ت)?\S*\s*(را|رو)?\s*(نادیده|فراموش|کنار\s*بذار|کنار\s*بگذار|بی\s*خیال|ignore|دور\s*بزن|حذف\s*کن)/u', 6, 'نادیده گرفتن دستورها'],
        ['/(system\s*prompt|initial\s*prompt|hidden\s*prompt|your\s+instructions|instructions\s+(you|were)\s+given|developer\s+message)/i', 5, 'درخواست پرامپت سیستم'],
        ['/(پرامپت|prompt|دستور\s*العمل|سیستم\s*پرامپت|متن\s*اولیه|پیام\s*سیستم|پیام\s*های\s*سیستمی)\S*\s*(خودت|اولیه|سیستمی|سیستم|داخلی|محرمانه|مخفی)?\S*\s*(رو|را)?\s*(بگو|بنویس|نشون|نشان|کپی|لیست|بفرست|چاپ|تکرار|افشا|لو\s*بده|کامل|برعکس)/u', 5, 'درخواست پرامپت سیستم'],
        ['/(قوانین|قواعد|تنظیمات|دستورات|دستورهای|محدودیت\s*ها)(ت|ات|تو|\s+خودت|\s+(داخلی|سیستمی|اولیه|محرمانه|مخفی|پنهان))\S*\s*(رو|را)?\s*(بگو|بنویس|نشون|نشان|کپی|لیست|بفرست|چاپ|تکرار|افشا|لو\s*بده|کامل|برعکس)/u', 5, 'درخواست پرامپت سیستم'],
        ['/(you\s+are\s+now|act\s+as\s+(an?\s+)?(admin|developer|system|unrestricted|dan)|pretend\s+(to\s+be|you\s+are)|\bDAN\b|developer\s*mode|jail\s*break|god\s*mode|sudo\s+mode)/i', 4, 'تغییر نقش / حالت توسعه'],
        ['/(از\s*(این|الان)\s*به\s*بعد\s+(تو|شما)\s|تو\s+(الان|دیگه|دیگر)\s+\S+\s+(هستی|نیستی)|نقش\s*(خودت|ت)\s*(رو|را)?\s*(عوض|تغییر)|حالت\s*(توسعه|دولوپر|ادمین|مدیر|دیباگ|debug)|بدون\s*محدودیت\s*جواب)/u', 4, 'تغییر نقش / حالت توسعه'],
        ['/(من\s+(مدیر|ادمین|برنامه\s*نویس|توسعه\s*دهنده|سازنده|پشتیبان\s*فنی|مالک|صاحب)\s*(سیستم|سایت|تو|این|شما|جاکجاست|ربات)?(\s|$)|i\s*\'?a?m\s+(the\s+)?(admin|developer|owner|your\s+creator)|authorized\s+(by|as)\s+(admin|anthropic|openai))/iu', 3, 'ادعای مدیر/برنامه‌نویس'],
        ['/(لیست|فهرست|دیتابیس|database|همه|تمام|بقیه|سایر)\S*\s*(ی\s*)?(اطلاعات|شماره|مشخصات|آدرس|تلفن)?\S*\s*(ی\s*)?(مشتری|مشتریان|کاربر|کاربران|میزبان|میزبانان|میزبانا|مهمان|مهمانان|رزروها|رزرو\s*ها|سفارش\s*ها|سفارشا)/u', 3, 'درخواست اطلاعات دیگران'],
        ['/(مشتری|مشتریان|کاربران|میزبان\s*ها|میزبانان|میزبانا|مهمان\s*ها|رزرو\s*ها|رزروها|سفارش\s*ها)\S*\s*(ی\s*)?(دیگه|دیگر|بقیه|سایر)/u', 3, 'درخواست اطلاعات دیگران'],
        ['/(api\s*key|access\s*token|bearer|secret\s*key|password|webhook|server\s*ip|توکن|کلید\s*(api|ای\s*پی\s*آی|دسترسی|وب\s*سرویس|سرویس)|رمز\s*(عبور|ادمین|پنل)|وب\s*هوک|آی\s*پی\s*سرور|سرور\s*تون)/iu', 3, 'درخواست کلید و اطلاعات فنی'],
        ['/((list|show|name|call|invoke)\s+(your\s+|the\s+)?(tools|functions)|function_call|tool_call|"role"\s*:\s*"(system|tool)"|ابزار\s*(ها)?\S*\s*(ت|هات|ی\s*که\s*داری)\s*(رو|را)?\s*(بگو|لیست|نام|چیه)|تابع\s*(ها)?\s*(ت|ی\s*داخلی))/iu', 3, 'کاوش ابزارهای داخلی'],
        ['/(base64|rot13|hex\s*(encode|decode)|reverse\s+the\s+text|به\s*صورت\s*(کد|رمز)\s*(شده|نگاری)|برعکس\s*بنویس|حرف\s*به\s*حرف\s*(بنویس|بگو)|با\s*فاصله\s*بین\s*حروف)/iu', 2, 'ترفند کدگذاری'],
        ['/(<\/?\s*(system|assistant|instructions?)\s*>|\[\s*(system|INST)\s*\]|###\s*(system|instruction)|<\|im_start\|>|<\|system\|>|BEGIN\s+SYSTEM)/iu', 5, 'تزریق نشانه‌های سیستمی'],
        // ۱.۰.۱۳: آمار داخلی کسب‌وکار و برداشت انبوه داده
        ['/(چند\s*(تا)?\s*(استعلام|رزرو|سفارش|فروش|مشتری|مهمان|پیام|درخواست)\S*\s*(امروز|امشب|این\s*هفته|این\s*ماه|دیروز)?\s*(داشتیم|داشتین|داشتید|دارید|دارین|داریم|ثبت\s*شده|اومده|آمده)|آمار\s*(فروش|رزرو|استعلام|سایت|مشتری|درآمد)|گزارش\s*(فروش|مالی|درآمد|رزروها|استعلام\s*ها)|درآمد\s*(امروز|ماه|سایت)|میزان\s*فروش)/u', 3, 'درخواست آمار داخلی'],
        ['/((لیست|فهرست|اکسل|excel|csv|json|خروجی|دامپ|dump)\s*(کامل|همه|تمام|کل)?\s*(ی\s*)?(اقامتگاه|واحد|ویلا|سوئیت|آپارتمان|قیمت|کد)\S*|(همه|تمام|کل)\s*(ی\s*)?(اقامتگاه|واحد|ویلا|سوئیت|کد)\S*\s*(سایت|تون|رو|را)\s*(بده|بفرست|لیست|بنویس|میخوام|می\s*خوام)|scrap|crawl|اسکرپ|کرال)/iu', 3, 'برداشت انبوه داده'],
        ['/(تخفیف\s*(۱۰۰|100|صد)\s*(درصد|%)|رایگان\s*(ثبت|رزرو)\s*کن|بدون\s*پرداخت\s*(ثبت|رزرو|تایید)|وضعیت\s*(رو|را)\s*(پرداخت\s*شده|paid))/u', 3, 'دور زدن پرداخت'],
    ];
    const DEFAULTS = ['guard_enabled' => '1', 'guard_warn' => '3', 'guard_block' => '6', 'guard_strikes' => '3',
        'guard_reply' => 'من دستیار فروش هستم و فقط می‌تونم در پیدا کردن، استعلام و رزرو اقامتگاه کمکتون کنم 🙂 برای چه تاریخی و چند نفر دنبال اقامتگاه هستید؟'];

    /**
     * بررسی پیام مشتری.
     * @return array{score:int, labels:array, action:string}  action: ok | warn | block
     */
    public static function inspect(string $text): array
    {
        $t = self::flatten($text);
        $score = 0; $labels = [];
        foreach (self::RULES as [$re, $w, $label]) {
            if (preg_match($re, $t)) { $score += $w; $labels[$label] = true; }
        }
        // پیام خیلی بلند با ساختار دستوری (چند خط «قانون:»، «Rule»، شماره‌گذاری) — الگوی رایج تزریق
        if (mb_strlen($text) > 900 && preg_match_all('/^\s*(\d+[\.\)]|-|\*|rule|قانون)/mui', $text) >= 6) { $score += 2; $labels['پیام دستوری طولانی'] = true; }
        $warn = (int)self::opt('guard_warn'); $block = (int)self::opt('guard_block');
        $action = $score >= $block ? 'block' : ($score >= $warn ? 'warn' : 'ok');
        return ['score' => $score, 'labels' => array_keys($labels), 'action' => $action];
    }

    /** یکسان‌سازی برای دور زدن نشدن با حروف عربی، فاصله‌ی مجازی و اعداد */
    private static function flatten(string $t): string
    {
        $t = strtr($t, ['ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ', "\u{200b}" => '', "\u{200d}" => '', 'ـ' => '', 'آ' => 'آ']);
        $t = preg_replace('/[\x{064B}-\x{065F}]/u', '', $t);
        return preg_replace('/\s+/u', ' ', $t);
    }

    /**
     * بررسی پاسخ دستیار پیش از ارسال.
     * $core: بخش‌های محرمانه‌ی دستور سیستم (بدون پایگاه دانش)؛ $canary: کد نگهبان
     * @return string|null دلیل نشت، یا null
     */
    public static function leak(string $out, string $core, string $canary, array $secrets = []): ?string
    {
        if ($canary !== '' && stripos($out, $canary) !== false) return 'کد نگهبان دستور سیستم در پاسخ بود';
        foreach ($secrets as $s) if ($s !== '' && mb_strlen($s) >= 8 && strpos($out, $s) !== false) return 'کلید یا توکن در پاسخ بود';
        if (preg_match('/\b(jkws_[A-Za-z0-9]{6,}|sk-[A-Za-z0-9]{10,}|rk_[A-Za-z0-9]{10,}|Bearer\s+[A-Za-z0-9._\-]{10,})/', $out)) return 'الگوی توکن در پاسخ بود';
        if (preg_match('/\b[a-z0-9]+__[a-z0-9_]+\b|handoff_to_human|save_customer|tool_calls?|needs_confirmation/', $out)) return 'نام ابزار داخلی در پاسخ بود';
        if (preg_match('/<\s*\/?\s*(think|tool[ _]?calls?|toolquote|function[ _]?calls?)\b/i', $out)) return 'متن فکر یا فراخوانی ابزار در پاسخ بود';
        // هم‌پوشانی ۷ کلمه‌ای با دستور سیستم
        $grams = function (string $s): array {
            $w = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s)));
            $g = [];
            for ($i = 0; $i + 7 <= count($w); $i++) $g[implode(' ', array_slice($w, $i, 7))] = true;
            return $g;
        };
        $hit = count(array_intersect_key($grams($out), $grams($core)));
        if ($hit >= 2) return 'بخشی از دستور سیستم در پاسخ تکرار شده بود';
        return null;
    }

    /** ثبت رویداد + اعلان + شمارش تکرار. @return bool آیا دستیار برای این مشتری متوقف شد */
    public static function record(?int $pid, string $kind, int $score, array $labels, string $text, string $action, bool $strike = true): bool
    {
        $paused = false;
        try {
            ApiConnectors::ensure();
            Database::run("INSERT INTO eb_agent_security (person_id, kind, score, matched, text, action) VALUES (?,?,?,?,?,?)",
                [$pid, $kind, $score, mb_substr(implode('، ', $labels), 0, 500), mb_substr($text, 0, 4000), $action]);
            $evId = Database::lastId();
            if ($pid && $pid > 0 && !$strike) {
                if (class_exists('Notifier')) Notifier::push('security', '⚠️ پیام مشکوک به دستیار فروش', implode('، ', $labels) . ' — «' . mb_substr($text, 0, 120) . '»', 'index.php#inbox.v' . $pid, $pid);
                return false;
            }
            if ($pid && $pid > 0) {
                $st = Database::one("SELECT strikes, strike_at FROM eb_agent_state WHERE person_id = ?", [$pid]);
                $n = ($st && $st['strike_at'] && strtotime($st['strike_at']) > time() - 86400) ? (int)$st['strikes'] + 1 : 1;
                Database::run("INSERT INTO eb_agent_state (person_id, mode, strikes, strike_at) VALUES (?, 'ai', ?, NOW()) ON DUPLICATE KEY UPDATE strikes = VALUES(strikes), strike_at = NOW()", [$pid, $n]);
                $lim = (int)self::opt('guard_strikes');
                if ($lim > 0 && $n >= $lim) {
                    Database::run("UPDATE eb_agent_state SET mode = 'human' WHERE person_id = ?", [$pid]);
                    Database::run("UPDATE eb_agent_security SET action = 'paused' WHERE id = ?", [$evId]);
                    $paused = true;
                }
                if (class_exists('Notifier')) {
                    Notifier::push('security', $paused ? '🛑 دستیار برای یک مشتری متوقف شد (تلاش مکرر برای نفوذ)' : '⚠️ تلاش برای فریب دستیار فروش',
                        implode('، ', $labels) . ' — «' . mb_substr($text, 0, 120) . '»', 'index.php#inbox.v' . $pid, $pid);
                }
            }
        } catch (Throwable $e) {}
        return $paused;
    }

    public static function opt(string $k): string
    {
        $v = class_exists('SalesAgent') ? SalesAgent::get($k) : '';
        return $v !== '' ? $v : (self::DEFAULTS[$k] ?? '');
    }

    public static function events(int $limit = 100): array
    {
        ApiConnectors::ensure();
        return Database::all("SELECT s.*, COALESCE(NULLIF(p.full_name,''), CONCAT('مشتری ', p.id)) person FROM eb_agent_security s LEFT JOIN eb_persons p ON p.id = s.person_id ORDER BY s.id DESC LIMIT " . (int)$limit);
    }

    /** کد نگهبان ثابت هر نصب */
    public static function canary(): string
    {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = 'agent.canary'");
        if (!empty($r['v'])) return (string)$r['v'];
        $c = 'CNRY-' . strtoupper(bin2hex(random_bytes(5)));
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('agent.canary', ?) ON DUPLICATE KEY UPDATE v = v", [$c]);
        return $c;
    }
}
