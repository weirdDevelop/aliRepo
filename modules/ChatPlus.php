<?php
/**
 * modules/ChatPlus.php — نسخه‌ی ۱.۰.۱۴ جاکجاست: چت و پشتیبانی
 * ==========================================================================
 *  ۱) رفع فیلتر دستی: بلاک، توقف دستیار با تلاش‌های مشکوک، سقف پیام/جستجو و سقف IP چت سایت
 *  ۲، ۸) دکمه‌ها: لینک‌ها، دکمه‌های منو/پاسخ خودکار و دکمه‌های پاسخ سریع دستیار همراه پیام ذخیره می‌شوند
 *  ۳) کارت اقامتگاه‌ها (کروسل) در گفتگوی سایت
 *  ۴، ۵) فهرست نام واحدها (برای تشخیص نام اختصاصی) و جستجوی ارزان‌ترین‌ها
 *  ۶) برچسب «اولویت تماس» روی گفتگوی دارای درخواست تماس
 *  ۷) تلفن گویا: تماس خودکار با پشتیبان‌ها و پخش پیام صوتی
 *  ۹) تماس سریع با پشتیبان (Click to Call) در ویجت
 *  ۱۰) یکسان‌سازی شماره‌های قبلی دیتابیس به 09… و ادغام پروفایل‌های تکراری
 */
class ChatPlus
{
    const SCHEMA = '3';
    const PRIORITY_TAG = 'اولویت تماس';
    const MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    const DEFAULTS = [
        // تماس سریع (مورد ۹)
        'call_enabled'   => '1',
        'call_phone'     => '',
        'call_label'     => 'تماس با ما',
        'call_note'      => 'کارشناسان ما آماده‌ی پاسخ‌گویی هستند',
        'call_fab'       => '1',   // دکمه‌ی کوچک تماس بالای دکمه‌ی گفتگو هم نشان داده شود
        'cb_form'        => '1',   // دکمه‌ی «با من تماس بگیرید» در ویجت (ثبت درخواست تماس)
        // تلفن گویا (مورد ۷)
        'ivr_enabled'    => '0',
        'ivr_phones'     => '',
        'ivr_text'       => 'یک چت در سایت نیاز به رسیدگی تیم پشتیبانی دارد. لطفاً برای بررسی و پاسخ‌گویی اقدام کنید.',
        'ivr_on_callback'=> '1',
        'ivr_on_handoff' => '1',
        'ivr_wait'       => '0',   // دقیقه بی‌پاسخ ماندن گفتگو پیش از تماس؛ ۰ = خاموش
        'ivr_mode'       => 'all', // all: همه‌ی شماره‌ها / first: فقط اولین شماره
        'ivr_cooldown'   => '15',  // دقیقه فاصله‌ی دو تماس برای یک گفتگو
        'ivr_max_hour'   => '20',  // سقف کل تماس‌ها در ساعت
        'ivr_hours_only' => '0',
        // کارت اقامتگاه‌ها (مورد ۳)
        'cards'          => '1',
        'cards_max'      => '3',
        'cards_button'   => 'ثبت رزرو',
        // نام واحدها (مورد ۴)
        'unit_match'     => '1',
        // ۱.۰.۱۶
        'role_label'     => 'پشتیبان هوشمند شما',   // زیر نام دستیار در سربرگ ویجت
        'agent_facts'    => '',    // اطلاعات و لینک‌های ثابتی که دستیار همیشه بداند (لینک اپ، کد تخفیف، شرایط…)
        'area_alias'     => '',    // «سعادت آباد = مرجانی، کاج» در هر خط: جستجوی یک محله، زیرمحله‌ها/نام‌های دیگرش را هم می‌گردد
        'host_diverse'   => '1',   // از هر میزبان/ساختمان یک گزینه
        'cb_on_order'    => '1',   // استعلام/رزرو ثبت‌شده در فهرست درخواست‌های تماس هم بیاید
        'pay_info'       => "پرداخت اقساطی: خرید اقساطی از طریق درگاه‌های «تارا» و «دیجی‌پی» امکان‌پذیر است (در مرحله‌ی پرداخت همان درگاه‌ها را انتخاب کنند).\nکارت‌به‌کارت: ممکن است، ولی فقط با هماهنگی تلفنی تیم پشتیبانی انجام می‌شود؛ مشتری را به تیم تماس ارجاع بده (درخواست تماس: request_callback) و شماره‌ی کارت یا مبلغ نگو.",   // ۲.۰.۷: شیوه‌های پرداخت
        // ۲.۱.۲: خارج از ساعت کاری میزبان‌ها (مثلاً ۲۲ تا ۷) برای امشب/فردا گزینه‌های میزبانِ هماهنگ‌شده اول معرفی شوند
        'ah_enabled'     => '0',
        'ah_from'        => '22:00',
        'ah_to'          => '07:00',
        'ah_hosts'       => '',    // هر خط: کد/شناسه/نام میزبان یا اقامتگاهِ هماهنگ‌شده؛ خالی = همه
        'budget_flex'    => '35',  // تا چند درصد بالاتر از بودجه‌ی مشتری هم جستجو و با «چقدر بیشتر» معرفی شود
    ];

    private static $ready = false;
    private static $cache = null;
    private static $cachePf = '';
    /** کارت‌های جمع‌شده در همین پاسخ دستیار (فقط گفتگوی سایت) */
    public static $turnCards = [];
    public static $turnSorted = false;
    /** درخواست تماس خودکار از ثبت استعلام: برچسب بخورد ولی تلفن گویا زنگ نزند */
    public static $noIvr = false;

    /* ============================ اسکیما ============================ */

    public static function ensure(bool $force = false): void
    {
        if (self::$ready && !$force) return;
        self::$ready = true;
        try {
            if (!$force) {
                $r = Database::one("SELECT v FROM eb_settings WHERE k = 'chatplus.schema'");
                if (($r['v'] ?? '') === self::SCHEMA) return;
            }
            $pdo = Database::pdo();
            $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cp_units (
                space_id VARCHAR(40) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                norm VARCHAR(255) NOT NULL,
                place VARCHAR(190) DEFAULT NULL,
                image VARCHAR(600) DEFAULT NULL,
                url VARCHAR(600) DEFAULT NULL,
                price BIGINT DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_norm (norm(60))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cp_ivr_calls (
                id INT AUTO_INCREMENT PRIMARY KEY,
                person_id INT DEFAULT NULL,
                reason VARCHAR(30) NOT NULL DEFAULT 'callback',
                phone VARCHAR(20) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                error VARCHAR(300) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                done_at DATETIME DEFAULT NULL,
                KEY idx_status (status, id), KEY idx_person (person_id, created_at), KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // ۱.۰.۱۶: کارت اقامتگاه‌هایی که برای هر مشتری آمده (تا کارت پیشنهادهای نوبت‌های قبل هم ساخته شود)
            $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cp_cards (
                person_id INT NOT NULL,
                space_id VARCHAR(40) NOT NULL,
                card_json TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (person_id, space_id), KEY idx_upd (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS eb_cp_ip_allow (
                ip VARCHAR(45) PRIMARY KEY,
                until_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::addColumn('eb_messages', 'payload_json', 'MEDIUMTEXT');
            // ۱.۰.۱۷: دستیار روشن/خاموش برای هر پست + دامنه‌ی کانال/پست سندهای پایگاه دانش
            self::addColumn('eb_ig_post_knowledge', 'agent_mode', "VARCHAR(10) NOT NULL DEFAULT 'inherit'");
            if (class_exists('AgentKb')) { try { AgentKb::ensureScope(); } catch (Throwable $e) {} }
            if (Database::hasTable('eb_agent_state')) self::addColumn('eb_agent_state', 'unfiltered_at', 'DATETIME DEFAULT NULL');
            Database::forgetSchemaCache();
            Database::run("INSERT INTO eb_settings (k, v) VALUES ('chatplus.schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::SCHEMA]);
        } catch (Throwable $e) {
            self::$ready = false;
            error_log('[ChatPlus ensure] ' . $e->getMessage());
        }
    }

    private static function addColumn(string $t, string $c, string $def): void
    {
        if (!Database::hasTable($t) || Database::hasColumn($t, $c)) return;
        try { Database::pdo()->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def"); } catch (Throwable $e) { error_log('[ChatPlus col] ' . $e->getMessage()); }
    }

    /** ستون رفع فیلتر روی eb_agent_state (جدول را ماژول دستیار هنگام نیاز می‌سازد) */
    private static function stateCol(): bool
    {
        if (!Database::hasTable('eb_agent_state')) return false;
        if (!Database::hasColumn('eb_agent_state', 'unfiltered_at')) { self::addColumn('eb_agent_state', 'unfiltered_at', 'DATETIME DEFAULT NULL'); Database::forgetSchemaCache(); }
        return Database::hasColumn('eb_agent_state', 'unfiltered_at');
    }

    public static function hasPayload(): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        if (Database::hasColumn('eb_messages', 'payload_json')) return $has = true;
        self::ensure();   // نصب تازه‌ی پچ: ستون همین حالا ساخته شود
        return $has = Database::hasColumn('eb_messages', 'payload_json');
    }

    /* ============================ تنظیمات ============================ */

    public static function settings(): array
    {
        // ۲.۱.۱: تنظیمات هر سایت جدا؛ کلیدهای ivr_* (کار پس‌زمینه) سراسری می‌مانند
        $pf = class_exists('SiteCtx') ? SiteCtx::pfx() : '';
        if (self::$cache !== null && (self::$cachePf ?? '') === $pf) return self::$cache;
        $s = self::DEFAULTS;
        try {
            foreach (Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'chatplus.%'") as $r) {
                $k = substr((string)$r['k'], 9);
                if (array_key_exists($k, $s)) $s[$k] = (string)$r['v'];
            }
            if ($pf !== '') {
                foreach (Database::all("SELECT k, v FROM eb_settings WHERE k LIKE ?", [$pf . 'chatplus.%']) as $r) {
                    $k = substr((string)$r['k'], strlen($pf) + 9);
                    if (array_key_exists($k, $s) && strpos($k, 'ivr_') !== 0) $s[$k] = (string)$r['v'];
                }
                // سایت غیراصلی: فقط مقدارهای خودش؛ بقیه پیش‌فرض کد (نه از سایت اصلی)
                $own = [];
                foreach (Database::all("SELECT k FROM eb_settings WHERE k LIKE ?", [$pf . 'chatplus.%']) as $r) $own[substr((string)$r['k'], strlen($pf) + 9)] = true;
                foreach (self::DEFAULTS as $k => $def) if (strpos($k, 'ivr_') !== 0 && !isset($own[$k])) $s[$k] = $def;
            }
        } catch (Throwable $e) {}
        if ($s['cards_button'] === 'مشاهده اقامتگاه') $s['cards_button'] = 'ثبت رزرو';   // پیش‌فرض قبلی ۱.۰.۱۴ → خواسته‌ی تازه
        self::$cachePf = $pf;
        return self::$cache = $s;
    }

    public static function get(string $k): string
    {
        return self::settings()[$k] ?? '';
    }

    public static function save(array $in): array
    {
        $bools = ['call_enabled', 'call_fab', 'cb_form', 'ivr_enabled', 'ivr_on_callback', 'ivr_on_handoff', 'ivr_hours_only', 'cards', 'unit_match'];
        $ints = ['ivr_wait' => [0, 240], 'ivr_cooldown' => [1, 1440], 'ivr_max_hour' => [1, 200], 'cards_max' => [1, 10]];
        $errors = [];
        foreach (self::DEFAULTS as $k => $def) {
            if (!array_key_exists($k, $in)) continue;
            $v = is_bool($in[$k]) ? ($in[$k] ? '1' : '0') : trim((string)$in[$k]);
            if (in_array($k, $bools, true)) $v = ($v === '1' || $v === 'true') ? '1' : '0';
            if (isset($ints[$k])) $v = (string)max($ints[$k][0], min($ints[$k][1], (int)Persons::asciiDigits($v)));
            if ($k === 'ivr_mode' && !in_array($v, ['all', 'first'], true)) $v = 'all';
            if ($k === 'call_phone' && $v !== '') {
                // هر خط یک شماره، با عنوان اختیاری: «شرکت: 02191001234» / «پشتیبانی: 09121234567»
                $lines = []; $bad = [];
                foreach (self::parseCallPhones($v) as $row) { if ($row['num'] !== '') $lines[] = ($row['label'] !== '' ? $row['label'] . ': ' : '') . $row['num']; else $bad[] = $row['raw']; }
                if ($bad) $errors[] = 'شماره‌ی نامعتبر کنار گذاشته شد: ' . implode('، ', array_slice($bad, 0, 3));
                if (!$lines && $bad) continue;
                $v = implode("\n", array_slice($lines, 0, 4));
            }
            if ($k === 'ivr_phones') {
                $out = []; $bad = [];
                foreach (self::parseStaff($v) as $row) { if ($row['phone']) $out[] = ($row['name'] !== '' ? $row['name'] . ': ' : '') . $row['phone']; else $bad[] = $row['raw']; }
                if ($bad) $errors[] = 'شماره‌های نامعتبر کنار گذاشته شد: ' . implode('، ', array_slice($bad, 0, 5));
                $v = implode("\n", array_slice($out, 0, 20));
            }
            if (in_array($k, ['call_label', 'cards_button'], true)) $v = mb_substr($v, 0, 40) ?: $def;
            if ($k === 'call_note') $v = mb_substr($v, 0, 120);
            if ($k === 'ivr_text') $v = mb_substr($v, 0, 400) ?: $def;
            if ($k === 'role_label') $v = mb_substr($v, 0, 60) ?: $def;
            if ($k === 'agent_facts' || $k === 'pay_info') $v = mb_substr($v, 0, 3000);
            if ($k === 'area_alias') $v = mb_substr($v, 0, 3000);
            if (in_array($k, ['ah_from', 'ah_to'], true)) $v = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', Persons::asciiDigits($v), $hm) ? sprintf('%02d:%02d', $hm[1], $hm[2]) : $def;
            if ($k === 'ah_hosts') $v = mb_substr($v, 0, 3000);
            if (in_array($k, ['host_diverse', 'cb_on_order', 'ah_enabled'], true)) $v = ($v === '1' || $v === 'true') ? '1' : '0';
            if ($k === 'budget_flex') $v = (string)max(0, min(100, (int)Persons::asciiDigits($v)));
            Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [((class_exists('SiteCtx') && strpos($k, 'ivr_') !== 0) ? SiteCtx::pfx() : '') . 'chatplus.' . $k, $v]);
        }
        self::$cache = null;
        return $errors;
    }

    /** «نام: شماره» در هر خط → [{name, phone, raw}] (شماره به 09… استاندارد می‌شود) */
    public static function parseStaff(string $text): array
    {
        $rows = [];
        foreach (preg_split('/[\r\n,،]+/u', $text) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $phone = Persons::extractPhone($ln) ?: Persons::normalizePhone($ln);
            $name = trim(preg_replace('/[:：\-–]+\s*$/u', '', trim(preg_replace('/[\d۰-۹٠-٩\s\+\-\(\)]{8,}/u', ' ', $ln))));
            $rows[] = ['name' => mb_substr($name, 0, 40), 'phone' => $phone, 'raw' => $ln];
        }
        return $rows;
    }

    /* ======================= ۱۰) یکسان‌سازی شماره‌ها ======================= */

    /**
     * شماره‌های قدیمی (98…، +98…، با فاصله و…) را به 09… تبدیل می‌کند و مشتری‌های تکراری را یکی می‌کند.
     * تکه‌تکه اجرا می‌شود (محدودیت زمان)؛ ادامه از همان‌جا با cron یا دکمه‌ی پنل. قابل‌تکرار و بی‌خطر.
     */
    public static function migratePhones(int $budgetSec = 20): array
    {
        self::ensure();
        $t0 = microtime(true);
        $st = ['persons' => 0, 'fixed' => 0, 'merged' => 0, 'invalid' => 0, 'identities' => 0, 'other' => 0, 'done' => false];
        $prev = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = 'chatplus.phone_mig'")['v'] ?? ''), true) ?: [];
        if (!empty($prev['done'])) return $prev + ['already' => true];
        foreach (['persons', 'fixed', 'merged', 'invalid', 'identities', 'other'] as $k) $st[$k] = (int)($prev[$k] ?? 0);
        $cursor = (int)($prev['cursor'] ?? 0);
        $phase = (string)($prev['phase'] ?? 'persons');
        @set_time_limit(max(30, $budgetSec + 30));

        // ۱) پروفایل‌ها
        while ($phase === 'persons' && microtime(true) - $t0 < $budgetSec) {
            $rows = Database::all("SELECT id, phone, notes FROM eb_persons WHERE id > ? AND phone IS NOT NULL AND phone <> '' ORDER BY id LIMIT 300", [$cursor]);
            if (!$rows) { $phase = 'identities'; $cursor = 0; break; }
            foreach ($rows as $r) {
                $cursor = (int)$r['id']; $st['persons']++;
                $old = (string)$r['phone'];
                $n = Persons::normalizePhone($old);
                if ($n === null) {
                    // نامعتبر/ناقص: به‌عنوان شماره‌ی معتبر نگه داشته نمی‌شود؛ متن اصلی در یادداشت می‌ماند
                    $note = trim((string)$r['notes'] . "\nشماره‌ی نامعتبر قبلی (۱.۰.۱۴): " . $old);
                    Database::run("UPDATE eb_persons SET phone = NULL, notes = ? WHERE id = ?", [$note, $cursor]);
                    $st['invalid']++;
                    continue;
                }
                if ($n === $old) continue;
                $other = Database::one("SELECT id FROM eb_persons WHERE phone = ? AND id <> ?", [$n, $cursor]);
                if ($other) {
                    $keeper = min($cursor, (int)$other['id']); $loser = max($cursor, (int)$other['id']);
                    try {
                        Persons::merge($keeper, $loser);
                        Database::run("UPDATE eb_persons SET phone = ? WHERE id = ?", [$n, $keeper]);
                        $st['merged']++;
                    } catch (Throwable $e) { error_log('[ChatPlus merge] ' . $e->getMessage()); }
                    continue;
                }
                Database::run("UPDATE eb_persons SET phone = ? WHERE id = ?", [$n, $cursor]);
                $st['fixed']++;
            }
        }

        // ۲) هویت‌های شماره‌ای (پیامک، صفیر، تلفن گویا)
        while ($phase === 'identities' && microtime(true) - $t0 < $budgetSec) {
            $rows = Database::all("SELECT id, person_id, channel, channel_uid FROM eb_identities WHERE id > ? AND channel IN ('sms','voice','safir_phone','bale_safir') ORDER BY id LIMIT 300", [$cursor]);
            if (!$rows) { $phase = 'other'; $cursor = 0; break; }
            foreach ($rows as $r) {
                $cursor = (int)$r['id'];
                $uid = (string)$r['channel_uid'];
                if ($r['channel'] === 'bale_safir' && !preg_match('/^(?:\+?98|0098)9\d{9}$/', $uid)) continue;   // chat_id ربات است، نه شماره
                $n = Persons::normalizePhone($uid);
                if ($n === null || $n === $uid) continue;
                $dup = Database::one("SELECT id, person_id FROM eb_identities WHERE channel = ? AND channel_uid = ? AND id <> ?", [$r['channel'], $n, $cursor]);
                try {
                    if ($dup) {
                        Database::run("UPDATE eb_messages SET identity_id = ? WHERE identity_id = ?", [(int)$dup['id'], $cursor]);
                        Database::run("DELETE FROM eb_identities WHERE id = ?", [$cursor]);
                        if ((int)$dup['person_id'] !== (int)$r['person_id']) {
                            $a = (int)$dup['person_id']; $b = (int)$r['person_id'];
                            if (Database::one("SELECT id FROM eb_persons WHERE id = ?", [$b])) Persons::merge(min($a, $b), max($a, $b));
                            $st['merged']++;
                        }
                    } else {
                        Database::run("UPDATE eb_identities SET channel_uid = ? WHERE id = ?", [$n, $cursor]);
                    }
                    $st['identities']++;
                } catch (Throwable $e) { error_log('[ChatPlus ident] ' . $e->getMessage()); }
            }
        }

        // ۳) جدول‌های دیگری که شماره‌ی مشتری دارند
        if ($phase === 'other' && microtime(true) - $t0 < $budgetSec) {
            foreach ([['eb_notice_leads', 'id'], ['eb_agent_callbacks', 'id']] as [$t, $pk]) {
                if (!Database::hasColumn($t, 'phone')) continue;
                foreach (Database::all("SELECT `$pk` id, phone FROM `$t` WHERE phone IS NOT NULL AND phone <> '' AND phone NOT REGEXP '^09[0-9]{9}$' LIMIT 5000") as $r) {
                    $n = Persons::normalizePhone((string)$r['phone']);
                    if ($n !== null) { Database::run("UPDATE `$t` SET phone = ? WHERE `$pk` = ?", [$n, $r['id']]); $st['other']++; }
                }
            }
            $phase = 'done';
        }

        $st['done'] = $phase === 'done';
        $save = $st + ['cursor' => $cursor, 'phase' => $phase, 'at' => date('Y-m-d H:i:s')];
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('chatplus.phone_mig', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($save, JSON_UNESCAPED_UNICODE)]);
        if ($st['merged'] && class_exists('Desk')) { try { Desk::bumpCounts(); } catch (Throwable $e) {} }
        return $save;
    }

    public static function phoneReport(): array
    {
        $mig = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = 'chatplus.phone_mig'")['v'] ?? ''), true) ?: ['done' => false, 'phase' => 'persons'];
        $r = Database::one("SELECT COUNT(*) total, SUM(phone REGEXP '^09[0-9]{9}$') std FROM eb_persons WHERE phone IS NOT NULL AND phone <> ''") ?: [];
        return ['migration' => $mig, 'total' => (int)($r['total'] ?? 0), 'standard' => (int)($r['std'] ?? 0)];
    }

    /** اجرای دوباره (مثلاً بعد از ورود اکسل قدیمی با ابزار دیگر) */
    public static function resetPhoneMigration(): void
    {
        Database::run("DELETE FROM eb_settings WHERE k = 'chatplus.phone_mig'");
    }

    /* ======================= ۱) فیلترها و رفع دستی ======================= */

    /** از این لحظه سقف‌ها از نو شمرده می‌شوند (بعد از رفع فیلتر دستی) */
    public static function since(int $pid): ?string
    {
        if ($pid <= 0) return null;
        try {
            self::ensure();
            if (!self::stateCol()) return null;
            $r = Database::one("SELECT unfiltered_at FROM eb_agent_state WHERE person_id = ?", [$pid]);
            return !empty($r['unfiltered_at']) ? (string)$r['unfiltered_at'] : null;
        } catch (Throwable $e) { return null; }
    }

    /** وضعیت محدودیت‌های یک مشتری: کدام فیلترها الان روی او فعال است */
    public static function filterState(int $pid): array
    {
        $reasons = [];
        try {
            if (class_exists('Inbox') && Inbox::isBlocked($pid)) $reasons['blocked'] = 'بلاک دستی (پاسخ خودکار نمی‌گیرد)';
            if (class_exists('SalesAgent') && class_exists('ApiConnectors')) {
                ApiConnectors::ensure();
                $s = Database::one("SELECT mode, strikes, strike_at FROM eb_agent_state WHERE person_id = ?", [$pid]);
                $paused = Database::one("SELECT id FROM eb_agent_security WHERE person_id = ? AND action = 'paused' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 1", [$pid]);
                $since = self::since($pid);
                if ($s && ($s['mode'] ?? '') === 'human' && $paused && (!$since || $since < (string)(Database::one("SELECT created_at FROM eb_agent_security WHERE id = ?", [(int)$paused['id']])['created_at'] ?? ''))) {
                    $reasons['guard'] = 'دستیار به‌خاطر پیام‌های مشکوک برای این مشتری متوقف شده';
                } elseif ($s && (int)$s['strikes'] > 0 && !empty($s['strike_at']) && strtotime((string)$s['strike_at']) > time() - 86400) {
                    $reasons['strikes'] = 'اخطار امنیتی: ' . (int)$s['strikes'] . ' پیام مشکوک در ۲۴ ساعت';
                }
                $lim = (int)SalesAgent::get('rate_hour');
                if ($lim > 0 && Database::hasColumn('eb_messages', 'source')) {
                    $n = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'out' AND m.source = 'ai' AND m.created_at >= GREATEST(DATE_SUB(NOW(), INTERVAL 1 HOUR), COALESCE(?, '1970-01-01'))", [$pid, $since])['c'] ?? 0);
                    if ($n >= $lim) $reasons['rate'] = 'سقف پیام دستیار در یک ساعت پر شده';
                }
                $cap = (int)SalesAgent::get('search_hour');
                if ($cap > 0) {
                    $n = (int)(Database::one("SELECT COUNT(*) c FROM eb_agent_log WHERE person_id = ? AND kind = 'tool' AND (tool LIKE '%search%' OR tool LIKE '%list%') AND created_at >= GREATEST(DATE_SUB(NOW(), INTERVAL 1 HOUR), COALESCE(?, '1970-01-01')) GROUP BY tool ORDER BY c DESC LIMIT 1", [$pid, $since])['c'] ?? 0);
                    if ($n >= $cap) $reasons['search'] = 'سقف جستجوی ساعتی پر شده';
                }
            }
            if (Database::hasTable('eb_webchat_sessions')) {
                $ips = array_column(Database::all("SELECT DISTINCT ip FROM eb_webchat_sessions WHERE person_id = ? AND ip IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 5", [$pid]), 'ip');
                foreach ($ips as $ip) {
                    if (self::ipAllowed((string)$ip)) continue;
                    $c = (int)(Database::one("SELECT COALESCE(SUM(msg_count),0) c FROM eb_webchat_sessions WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$ip])['c'] ?? 0);
                    if ($c >= 60) { $reasons['ip'] = 'سقف پیام چت سایت برای IP این مشتری پر شده'; break; }
                }
            }
        } catch (Throwable $e) { error_log('[ChatPlus filterState] ' . $e->getMessage()); }
        return ['filtered' => (bool)$reasons, 'reasons' => $reasons];
    }

    /** رفع همه‌ی فیلترها با دست مدیر/پشتیبان */
    public static function unfilter(int $pid, ?int $byUser = null): array
    {
        self::ensure();
        $done = [];
        try {
            if (class_exists('Inbox') && Inbox::isBlocked($pid)) { Inbox::unblock($pid); $done[] = 'رفع بلاک'; }
            if (class_exists('ApiConnectors')) {
                ApiConnectors::ensure();
                $hasU = self::stateCol();
                Database::run("INSERT INTO eb_agent_state (person_id, mode, strikes" . ($hasU ? ", unfiltered_at" : "") . ") VALUES (?, 'ai', 0" . ($hasU ? ", NOW()" : "") . ")
                               ON DUPLICATE KEY UPDATE strikes = 0, strike_at = NULL" . ($hasU ? ", unfiltered_at = NOW()" : ""), [$pid]);
                $done[] = 'صفر شدن اخطارها و سقف‌ها';
                // توقف دستیار: اگر همکاری گفتگو را برنداشته، دستیار دوباره جواب می‌دهد
                $own = (int)(Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$pid])['assigned_user_id'] ?? 0);
                $st = Database::one("SELECT mode FROM eb_agent_state WHERE person_id = ?", [$pid]);
                if (!$own && ($st['mode'] ?? '') === 'human' && class_exists('SalesAgent')) { SalesAgent::resume($pid); $done[] = 'فعال شدن دوباره‌ی دستیار'; }
            }
            if (Database::hasTable('eb_webchat_sessions')) {
                foreach (array_column(Database::all("SELECT DISTINCT ip FROM eb_webchat_sessions WHERE person_id = ? AND ip IS NOT NULL ORDER BY ip LIMIT 5", [$pid]), 'ip') as $ip) {
                    Database::run("INSERT INTO eb_cp_ip_allow (ip, until_at) VALUES (?, DATE_ADD(NOW(), INTERVAL 1 DAY)) ON DUPLICATE KEY UPDATE until_at = VALUES(until_at)", [(string)$ip]);
                }
            }
            if (class_exists('Desk')) Desk::event($pid, 'note', '🔓 فیلترهای این مشتری به‌صورت دستی برداشته شد: ' . implode('، ', $done), $byUser);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'رفع فیلتر کامل نشد: ' . $e->getMessage()];
        }
        return ['ok' => true, 'done' => $done];
    }

    public static function ipAllowed(string $ip): bool
    {
        if ($ip === '') return false;
        try {
            if (!Database::hasTable('eb_cp_ip_allow')) return false;
            return (bool)Database::one("SELECT ip FROM eb_cp_ip_allow WHERE ip = ? AND until_at > NOW()", [$ip]);
        } catch (Throwable $e) { return false; }
    }

    /** فهرست مشتری‌هایی که الان فیلتر/محدودیت دارند (برای صفحه‌ی مدیریت) */
    public static function filteredList(int $limit = 100): array
    {
        $ids = [];
        try {
            if (Database::hasColumn('eb_persons', 'blocked_at')) foreach (Database::all("SELECT id FROM eb_persons WHERE blocked_at IS NOT NULL ORDER BY blocked_at DESC LIMIT 200") as $r) $ids[(int)$r['id']] = 1;
            if (Database::hasTable('eb_agent_state')) foreach (Database::all("SELECT person_id FROM eb_agent_state WHERE strikes > 0 AND strike_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 200") as $r) $ids[(int)$r['person_id']] = 1;
            if (Database::hasTable('eb_agent_security')) foreach (Database::all("SELECT DISTINCT s.person_id FROM eb_agent_security s JOIN eb_agent_state a ON a.person_id = s.person_id AND a.mode = 'human' WHERE s.action = 'paused' AND s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 200") as $r) $ids[(int)$r['person_id']] = 1;
            if (Database::hasTable('eb_agent_log')) {
                $cap = class_exists('SalesAgent') ? (int)SalesAgent::get('search_hour') : 0;
                if ($cap > 0) foreach (Database::all("SELECT person_id FROM eb_agent_log WHERE kind = 'tool' AND person_id > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) GROUP BY person_id, tool HAVING COUNT(*) >= ? LIMIT 100", [$cap]) as $r) $ids[(int)$r['person_id']] = 1;
            }
        } catch (Throwable $e) { error_log('[ChatPlus filteredList] ' . $e->getMessage()); }
        $out = [];
        foreach (array_slice(array_keys($ids), 0, $limit) as $pid) {
            if ($pid <= 0) continue;
            $f = self::filterState($pid);
            if (!$f['filtered']) continue;
            $p = Database::one("SELECT id, full_name, phone FROM eb_persons WHERE id = ?", [$pid]);
            if (!$p) continue;
            $out[] = ['person_id' => $pid, 'name' => trim((string)$p['full_name']) ?: ('مشتری ' . $pid), 'phone' => (string)($p['phone'] ?? ''), 'reasons' => array_values($f['reasons'])];
        }
        return $out;
    }

    /* ======================= ۶) برچسب «اولویت تماس» ======================= */

    public static function priorityTagId(): int
    {
        return class_exists('Groups') ? Groups::ensureTag(self::PRIORITY_TAG) : 0;
    }

    /** درخواست تماس ثبت شد: برچسب، اولویت فوری، بازگشایی گفتگو و تلفن گویا */
    public static function onCallback(int $pid, string $phone = ''): void
    {
        if ($pid <= 0) return;
        try {
            $tag = self::priorityTagId();
            if ($tag && class_exists('Desk')) {
                $st = Desk::state($pid);
                $set = ['label_add' => $tag, 'priority' => 'urgent'];
                if (in_array($st['status'] ?? 'open', ['closed', 'snoozed', 'pending'], true)) $set['status'] = 'open';
                Desk::update([$pid], $set);
                Desk::event($pid, 'note', '📞 درخواست تماس ثبت شد' . ($phone !== '' ? ' — ' . $phone : '') . '؛ برچسب «' . self::PRIORITY_TAG . '» خورد');
            }
        } catch (Throwable $e) { error_log('[ChatPlus onCallback] ' . $e->getMessage()); }
        if (self::get('ivr_on_callback') === '1' && !self::$noIvr) self::ivrTrigger($pid, 'callback');
    }

    /** همه‌ی درخواست‌های تماس این مشتری انجام شد: برچسب برداشته شود */
    public static function onCallbackDone(int $pid): void
    {
        if ($pid <= 0 || !class_exists('Desk')) return;
        try {
            if (Database::one("SELECT id FROM eb_agent_callbacks WHERE person_id = ? AND status = 'open' LIMIT 1", [$pid])) return;
            $tag = (int)(Database::one("SELECT id FROM eb_tags WHERE name = ?", [self::PRIORITY_TAG])['id'] ?? 0);
            if ($tag) Desk::update([$pid], ['label_remove' => $tag, 'priority' => 'normal']);
        } catch (Throwable $e) {}
    }

    /* ======================= ۷) تلفن گویا ======================= */

    public static function staffPhones(): array
    {
        $out = [];
        foreach (self::parseStaff(self::get('ivr_phones')) as $r) if ($r['phone']) $out[$r['phone']] = $r['name'];
        return $out;
    }

    /**
     * گفتگو به پشتیبان نیاز دارد → تماس با شماره‌های تعریف‌شده (در صف؛ با فاصله‌ی زمانی و سقف ساعتی).
     * $reason: callback | handoff | wait | test
     */
    public static function ivrTrigger(int $pid, string $reason): int
    {
        if (self::get('ivr_enabled') !== '1' && $reason !== 'test') return 0;
        if ($reason === 'handoff' && self::get('ivr_on_handoff') !== '1') return 0;
        if (self::get('ivr_hours_only') === '1' && $reason !== 'test' && class_exists('AgentCallbacks') && !AgentCallbacks::inHours()) return 0;
        $phones = self::staffPhones();
        if (!$phones) return 0;
        self::ensure();
        try {
            if ($pid > 0 && $reason !== 'test') {
                $cool = max(1, (int)self::get('ivr_cooldown'));
                if (Database::one("SELECT id FROM eb_cp_ivr_calls WHERE person_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL {$cool} MINUTE) LIMIT 1", [$pid])) return 0;
            }
            $max = max(1, (int)self::get('ivr_max_hour'));
            $n = (int)(Database::one("SELECT COUNT(*) c FROM eb_cp_ivr_calls WHERE reason <> 'test' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")['c'] ?? 0);
            if ($reason !== 'test' && $n >= $max) return 0;
            if (self::get('ivr_mode') === 'first') $phones = array_slice($phones, 0, 1, true);
            $k = 0;
            foreach (array_keys($phones) as $ph) {
                Database::run("INSERT INTO eb_cp_ivr_calls (person_id, reason, phone) VALUES (?, ?, ?)", [$pid > 0 ? $pid : null, $reason, $ph]);
                $k++;
            }
            return $k;
        } catch (Throwable $e) { error_log('[ChatPlus ivr] ' . $e->getMessage()); return 0; }
    }

    /** تماس‌های در صف را برقرار کن (cron هر دقیقه؛ و همان لحظه در مسیرهای پس‌زمینه) */
    public static function ivrTick(int $max = 6): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        try {
            self::ensure();
            if (!Database::hasTable('eb_cp_ivr_calls')) return $out;
            // تماس‌های بی‌پاسخ‌مانده (اختیاری)
            if (self::get('ivr_enabled') === '1' && (int)self::get('ivr_wait') > 0) self::scanWaiting();
            $rows = Database::all("SELECT * FROM eb_cp_ivr_calls WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY id LIMIT " . (int)$max);
            Database::run("UPDATE eb_cp_ivr_calls SET status = 'expired' WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            if (!$rows) return $out;
            $voice = class_exists('ChannelManager') ? ChannelManager::getVoice() : null;
            $text = self::get('ivr_text');
            foreach ($rows as $r) {
                // برداشتن ردیف (تا دو پردازش هم‌زمان دو بار زنگ نزنند)
                $claimed = Database::pdo()->prepare("UPDATE eb_cp_ivr_calls SET status = 'calling' WHERE id = ? AND status = 'pending'");
                $claimed->execute([(int)$r['id']]);
                if ($claimed->rowCount() !== 1) continue;
                if (!$voice) { $res = ['ok' => false, 'error' => 'سرویس تلفن گویا تنظیم نشده (پیامک باید روی ملی‌پیامک باشد)']; }
                else { try { $res = $voice->callSpeech((string)$r['phone'], $text); } catch (Throwable $e) { $res = ['ok' => false, 'error' => $e->getMessage()]; } }
                Database::run("UPDATE eb_cp_ivr_calls SET status = ?, error = ?, done_at = NOW() WHERE id = ?",
                    [!empty($res['ok']) ? 'sent' : 'failed', !empty($res['ok']) ? null : mb_substr((string)($res['error'] ?? 'خطا'), 0, 300), (int)$r['id']]);
                if (!empty($res['ok'])) $out['sent']++; else $out['failed']++;
            }
        } catch (Throwable $e) { error_log('[ChatPlus ivrTick] ' . $e->getMessage()); }
        return $out;
    }

    /** گفتگوهایی که پیام آخرشان از مشتری است و N دقیقه بی‌پاسخ مانده‌اند (و دست دستیار نیستند) */
    private static function scanWaiting(): void
    {
        $min = (int)self::get('ivr_wait');
        if ($min <= 0) return;
        try {
            $rows = Database::all("SELECT i.person_id, MAX(m.id) mid FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id
                WHERE m.direction = 'in' AND m.created_at BETWEEN DATE_SUB(NOW(), INTERVAL " . ($min + 60) . " MINUTE) AND DATE_SUB(NOW(), INTERVAL {$min} MINUTE)
                GROUP BY i.person_id LIMIT 50");
            foreach ($rows as $r) {
                $pid = (int)$r['person_id'];
                if ($pid <= 0) continue;
                if (Database::one("SELECT m.id FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.id > ? LIMIT 1", [$pid, (int)$r['mid']])) continue;   // بعدش پیامی آمده یا رفته
                if (class_exists('Inbox') && Inbox::isBlocked($pid)) continue;
                if (class_exists('SalesAgent') && SalesAgent::enabled() && !SalesAgent::humanOwns($pid)) continue;   // دستیار جواب می‌دهد
                self::ivrTrigger($pid, 'wait');
            }
        } catch (Throwable $e) { error_log('[ChatPlus scanWaiting] ' . $e->getMessage()); }
    }

    public static function ivrLog(int $limit = 40): array
    {
        self::ensure();
        try {
            return Database::all("SELECT c.*, COALESCE(NULLIF(p.full_name,''), p.phone) person FROM eb_cp_ivr_calls c LEFT JOIN eb_persons p ON p.id = c.person_id ORDER BY c.id DESC LIMIT " . max(1, min(200, $limit)));
        } catch (Throwable $e) { return []; }
    }

    /* ======================= ۹) تماس سریع در ویجت ======================= */

    /** «عنوان: شماره» در هر خط → [{label, num, raw}] (ثابت 021… یا موبایل؛ همان‌طور که شماره‌گیر می‌خواهد) */
    public static function parseCallPhones(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n]+/u', $text) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $a = Persons::asciiDigits($ln); $m = [];
            $num = preg_match('/\+?[\d][\d\s\-()]{3,20}\d/', $a, $m) ? preg_replace('/[^\d+]/', '', $m[0]) : '';
            if (!preg_match('/^\+?\d{4,15}$/', $num)) $num = '';
            $label = ($m[0] ?? '') !== '' ? trim(preg_replace('/[:：\-–]+\s*$/u', '', trim(str_replace($m[0], '', $a)))) : '';
            $out[] = ['label' => mb_substr($label, 0, 30), 'num' => $num, 'raw' => $ln];
        }
        return $out;
    }

    public static function publicCall(): ?array
    {
        $s = self::settings();
        $phones = [];
        if ($s['call_enabled'] === '1') foreach (self::parseCallPhones($s['call_phone']) as $r) if ($r['num'] !== '') $phones[] = ['label' => $r['label'], 'num' => $r['num']];
        // ۱.۰.۱۴: «همکاران با من تماس بگیرند» مستقل از تنظیم دستیار فروش
        $cb = $s['cb_form'] === '1';
        if (!$phones && !$cb) return null;
        return ['phone' => $phones[0]['num'] ?? '', 'phones' => $phones, 'label' => $s['call_label'], 'note' => $s['call_note'], 'fab' => $s['call_fab'] === '1', 'callback' => $cb];
    }

    /** فرم «با من تماس بگیرید» ویجت */
    public static function widgetCallback(string $token, string $name, string $rawPhone, string $when): array
    {
        if (!class_exists('Webchat') || !Webchat::validToken($token)) return ['ok' => false, 'error' => 'نشست نامعتبر است'];
        $phone = Persons::normalizePhone($rawPhone);
        if (!$phone) return ['ok' => false, 'error' => 'شماره‌ی موبایل را درست وارد کنید (مثل 09121234567)'];
        $name = mb_substr(trim(strip_tags($name)), 0, 80);
        $when = mb_substr(trim(strip_tags($when)), 0, 80);
        $text = 'درخواست تماس: ' . $phone . ($name !== '' ? ' — ' . $name : '') . ($when !== '' ? ' — زمان مناسب: ' . $when : '');
        $res = Webchat::ingest(['token' => $token, 'text' => $text, 'name' => $name, 'phone' => $phone, 'page_url' => '', 'skip_auto' => true]);
        if (empty($res['ok']) || empty($res['person_id'])) return ['ok' => false, 'error' => $res['error'] ?? 'ثبت نشد'];
        $pid = (int)$res['person_id'];
        if (class_exists('Inbox') && Inbox::isBlocked($pid)) return ['ok' => true];
        $id = 0;
        if (class_exists('AgentCallbacks')) {
            try { $id = AgentCallbacks::add($pid, $phone, $name, $when, 'از دکمه‌ی «با من تماس بگیرید» در گفتگوی سایت', 'webchat'); }
            catch (Throwable $e) { error_log('[ChatPlus cb] ' . $e->getMessage()); }
        }
        if (!$id) self::onCallback($pid, $phone);   // بدون ماژول دستیار هم برچسب و تلفن گویا کار کند
        return ['ok' => true, 'id' => $id];
    }

    /* ======================= ۲، ۸) دکمه‌ها همراه پیام ======================= */

    /** دکمه‌ها/درخواست شماره‌ی پیام را به قالب ذخیره‌ی ویجت تبدیل می‌کند (null = چیزی برای ذخیره نیست) */
    public static function payloadFromMessage(array $message): ?array
    {
        $p = [];
        if (!empty($message['buttons']) && is_array($message['buttons'])) {
            $b = [];
            foreach ($message['buttons'] as $row) foreach ((array)$row as $btn) {
                if (!is_array($btn) || empty($btn['text'])) continue;
                if (!empty($btn['url']) && preg_match('#^https?://#i', (string)$btn['url'])) $b[] = ['t' => mb_substr((string)$btn['text'], 0, 40), 'u' => (string)$btn['url']];
                elseif (!empty($btn['callback'])) $b[] = ['t' => mb_substr((string)$btn['text'], 0, 40), 'c' => mb_substr((string)$btn['callback'], 0, 80)];
            }
            if ($b) $p['buttons'] = array_slice($b, 0, 12);
        }
        if (!empty($message['request_contact'])) $p['contact'] = mb_substr((string)($message['contact_button_text'] ?? '📱 ارسال شماره‌ی موبایل'), 0, 40);
        if (!empty($message['cards']) && is_array($message['cards'])) $p['cards'] = $message['cards'];
        if (!empty($message['qr']) && is_array($message['qr'])) $p['qr'] = array_slice(array_values($message['qr']), 0, 4);
        return $p ?: null;
    }

    public static function setPayload(int $msgId, array $payload): void
    {
        if ($msgId <= 0 || !self::hasPayload()) return;
        try {
            $cur = json_decode((string)(Database::one("SELECT payload_json FROM eb_messages WHERE id = ?", [$msgId])['payload_json'] ?? ''), true) ?: [];
            Database::run("UPDATE eb_messages SET payload_json = ? WHERE id = ?", [json_encode($payload + $cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $msgId]);
        } catch (Throwable $e) {}
    }

    public static function lastOutId(int $iid): int
    {
        return (int)(Database::one("SELECT MAX(id) m FROM eb_messages WHERE identity_id = ? AND direction = 'out'", [$iid])['m'] ?? 0);
    }

    /* ======================= ۳) کارت اقامتگاه‌ها ======================= */

    /**
     * خروجی ابزار جستجو/جزئیات → کارت‌ها (عکس، نام، تاریخ، قیمت، لینک). کلیدهای رایج چند وب‌سرویس را می‌شناسد.
     * @param array $base آدرس پایه‌ی اتصال (برای عکس‌های با مسیر نسبی)
     */
    public static function cardsFrom($data, array $args, string $base = ''): array
    {
        if (!is_array($data)) return [];
        $items = self::findItems($data);
        if ($items === null && (isset($data['title']) || isset($data['name'])) && (isset($data['id']) || isset($data['code']))) $items = [$data];   // جزئیات یک اقامتگاه
        if (!$items) return [];
        $ci = (string)($args['check_in'] ?? ''); $co = (string)($args['check_out'] ?? '');
        $nights = self::nights($ci, $co);
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = (string)($it['id'] ?? $it['code'] ?? $it['space_id'] ?? '');
            $title = trim((string)($it['title'] ?? $it['name'] ?? $it['space_title'] ?? ''));
            $url = self::pickUrl($it, ['booking_url', 'referral_url', 'url', 'link', 'space_url', 'page_url', 'permalink']);
            if ($title === '' || $url === '') continue;
            $img = self::pickImage($it, $base);
            $itCi = (string)($it['check_in'] ?? $ci); $itCo = (string)($it['check_out'] ?? $co);
            $total = self::num($it, ['total_payable_price', 'total_price', 'price_total', 'total_amount', 'total']);
            $night = self::num($it, ['effective_price', 'nightly_price', 'price_per_night', 'price', 'min_price', 'base_price']);
            $price = null; $plabel = '';
            if ($total !== null && $total > 0) { $price = $total; $plabel = $nights ? 'مبلغ کل ' . self::fa($nights) . ' شب' : 'مبلغ کل'; }
            elseif ($night !== null && $night > 0) { $price = $night; $plabel = $nights ? 'هر شب' : 'هر شب از'; }
            $place = trim(implode('، ', array_filter([(string)($it['neighborhood'] ?? $it['hood'] ?? $it['area'] ?? $it['district'] ?? ''), (string)($it['city'] ?? $it['city_name'] ?? '')])));
            $out[] = [
                'id' => $id, 't' => mb_substr($title, 0, 90), 'img' => $img, 'u' => $url,
                'd' => self::dateRange($itCi, $itCo), 'p' => $price, 'pl' => $plabel, 'pf' => $price !== null ? self::fa(number_format($price)) . ' تومان' : '',
                'pl2' => mb_substr($place, 0, 60), 'sort' => $total ?? ($night !== null && $nights ? $night * $nights : $night),
            ];
        }
        return $out;
    }

    /** جمع کردن کارت‌های همین پاسخ (آخرین نتیجه جایگزین قبلی می‌شود؛ تکراری‌ها یکی) */
    public static function collect($data, array $args, array $c, bool $sorted = false, int $pid = 0): void
    {
        if (self::get('cards') !== '1') return;
        $base = (string)($c['base_url'] ?? '');
        $cards = self::cardsFrom($data, $args, $base);
        if (!$cards) return;
        // ۱.۰.۱۶: همه‌ی نتایج همین نوبت جمع می‌شود (نه فقط آخرین جستجو)؛ کارت تازه‌تر جای قبلی همان اقامتگاه
        $by = [];
        foreach (self::$turnCards as $x) $by[$x['id'] !== '' ? $x['id'] : $x['u']] = $x;
        foreach ($cards as $x) $by[$x['id'] !== '' ? $x['id'] : $x['u']] = $x;
        self::$turnCards = array_values($by);
        self::$turnSorted = self::$turnSorted || $sorted;
        if ($pid > 0) {
            try {
                self::ensure();
                foreach ($cards as $x) if ($x['id'] !== '') {
                    $y = $x; unset($y['sort']);
                    Database::run("INSERT INTO eb_cp_cards (person_id, space_id, card_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE card_json = VALUES(card_json), updated_at = NOW()",
                        [$pid, mb_substr($x['id'], 0, 40), json_encode($y, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            } catch (Throwable $e) {}
        }
    }

    /** کارت یک اقامتگاه از نتایج قبلی همین مشتری یا فهرست نام واحدها */
    private static function cachedCard(int $pid, string $id): ?array
    {
        try {
            if ($pid > 0) {
                $r = Database::one("SELECT card_json FROM eb_cp_cards WHERE person_id = ? AND space_id = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)", [$pid, $id]);
                if ($r && ($c = json_decode((string)$r['card_json'], true))) return $c;
            }
            $u = Database::one("SELECT * FROM eb_cp_units WHERE space_id = ?", [$id]);
            if ($u && !empty($u['url'])) return ['id' => (string)$u['space_id'], 't' => (string)$u['title'], 'img' => (string)($u['image'] ?? ''), 'u' => (string)$u['url'],
                'd' => '', 'p' => $u['price'] !== null ? (int)$u['price'] : null, 'pl' => 'هر شب از', 'pf' => $u['price'] ? self::fa(number_format((int)$u['price'])) . ' تومان' : '', 'pl2' => (string)($u['place'] ?? '')];
        } catch (Throwable $e) {}
        return null;
    }

    /** کدهای اقامتگاه (عدد ۵ رقمی به بالا) که در متن آمده، به ترتیب */
    public static function codesIn(string $text): array
    {
        preg_match_all('/(?<![\d.,٫])(\d{5,12})(?![\d.,٫])/', Persons::asciiDigits($text), $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[1] as [$code, $pos]) if (!isset($out[$code])) $out[$code] = $pos;
        return $out;
    }

    /**
     * کارت‌های همین پاسخ — فقط اقامتگاه‌هایی که دستیار در متن پیامش معرفی کرده (با کد یا نام)، به همان ترتیب متن.
     * اگر متن هیچ‌کدام را نام نبرده باشد (مثلاً فقط سؤال پرسیده)، کروسلی فرستاده نمی‌شود.
     */
    public static function takeTurnCards(string $reply = '', int $pid = 0): array
    {
        $c = self::$turnCards; self::$turnCards = []; self::$turnSorted = false;
        $seen = []; $all = [];
        foreach ($c as $x) { $k = $x['id'] !== '' ? $x['id'] : $x['u']; if (isset($seen[$k])) continue; $seen[$k] = 1; unset($x['sort']); $all[] = $x; }
        if (self::get('cards') !== '1' || trim($reply) === '') return [];
        // ۱.۰.۱۶: متن کد اقامتگاه دارد ← فقط همان کدها، به ترتیب متن (کارت از نتایج همین نوبت، نوبت‌های قبل یا فهرست واحدها)
        $codes = self::codesIn($reply);
        if ($codes) {
            $pool = [];
            foreach ($all as $x) if ($x['id'] !== '') $pool[$x['id']] = $x;
            $out = [];
            foreach (array_keys($codes) as $code) {
                $code = (string)$code;
                $card = $pool[$code] ?? self::cachedCard($pid, $code);
                if ($card) { unset($card['sort']); $out[] = $card; }
            }
            return array_slice($out, 0, max(1, (int)self::get('cards_max')));
        }
        if (!$all) return [];
        $txt = Persons::asciiDigits($reply);
        $ntxt = ' ' . self::norm($reply) . ' ';
        $hits = [];
        foreach ($all as $x) {
            $pos = null;
            if (preg_match('/^\d{3,}$/', (string)$x['id']) && preg_match('/(?<!\d)' . preg_quote((string)$x['id'], '/') . '(?!\d)/', $txt, $m, PREG_OFFSET_CAPTURE)) $pos = $m[0][1];
            if ($pos === null && $x['u'] !== '' && ($p = strpos($reply, $x['u'])) !== false) $pos = $p;
            $nt = self::norm($x['t']);
            if ($pos === null && mb_strlen($nt) >= 4 && ($p = mb_strpos($ntxt, ' ' . $nt . ' ')) !== false) $pos = 100000 + $p;
            if ($pos === null) {   // نام کوتاه‌شده: دست‌کم دو واژه‌ی اختصاصی نام (بدون نوع واحد) پشت‌سرهم در متن
                $w = array_values(array_filter(explode(' ', $nt), fn($v) => mb_strlen($v) >= 3 && !in_array($v, self::GENERIC, true)));
                for ($i = 0; $i + 1 < count($w) && $pos === null; $i++) if (($p = mb_strpos($ntxt, ' ' . $w[$i] . ' ' . $w[$i + 1] . ' ')) !== false) $pos = 200000 + $p;
                if ($pos === null && count($w) === 1 && mb_strlen($w[0]) >= 4 && ($p = mb_strpos($ntxt, ' ' . $w[0] . ' ')) !== false) $pos = 300000 + $p;
            }
            if ($pos !== null) $hits[] = [$pos, $x];
        }
        usort($hits, fn($a, $b) => $a[0] <=> $b[0]);
        return array_slice(array_map(fn($h) => $h[1], $hits), 0, max(1, (int)self::get('cards_max')));
    }

    /**
     * ۱.۰.۱۶: وقتی کارت‌ها نمایش داده می‌شوند، خط‌های معرفی همان اقامتگاه‌ها از متن حذف می‌شود (فقط کارت بیاید).
     * توضیح داخل پرانتز هر خط («سرویس مشترک»، «پارکینگ دارد») روی خود کارت می‌نشیند.
     * @return array{0:string,1:array,2:string} [متن پیش از فهرست، کارت‌ها با یادداشت، متن بعد از فهرست (سؤال آخر)]
     */
    public static function stripListed(string $text, array $cards): array
    {
        $ids = []; foreach ($cards as $i => $c) if ($c['id'] !== '') $ids[$c['id']] = $i;
        $titles = []; foreach ($cards as $i => $c) $titles[$i] = self::norm($c['t']);
        $before = []; $after = []; $removed = 0;
        foreach (preg_split('/\n/u', $text) as $ln) {
            $hit = null;
            foreach (array_keys(self::codesIn($ln)) as $code) if (isset($ids[(string)$code])) { $hit = $ids[(string)$code]; break; }
            if ($hit === null && !self::codesIn($ln)) { $n = ' ' . self::norm($ln) . ' '; foreach ($titles as $i => $t) if (mb_strlen($t) >= 6 && mb_strpos($n, ' ' . $t . ' ') !== false) { $hit = $i; break; } }
            if ($hit !== null && mb_strlen($ln) < 400) {
                if (preg_match_all('/[(（]([^)）]{2,80})[)）]/u', $ln, $pm)) $cards[$hit]['n'] = mb_substr(implode('، ', $pm[1]), 0, 120);
                $removed++;
                continue;
            }
            if ($removed) $after[] = $ln; else $before[] = $ln;
        }
        if (!$removed) return [$text, $cards, ''];
        $clean = fn(array $l) => trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $l)));
        $b = $clean($before); $a = $clean($after);
        // «این سه گزینه خالی‌اند:» که حالا به کارت‌ها اشاره می‌کند
        $b = preg_replace('/[:：]\s*$/u', ' 👇', $b);
        if ($b === '') $b = 'این گزینه‌ها را برایتان پیدا کردم 👇';
        return [$b, $cards, $a];
    }

    /** پیام کارت‌ها در گفتگوی سایت (متن جایگزین برای صندوق ورودی و کانال‌های بدون کارت) */
    public static function sendCards(int $pid, int $iid, array $cards, array $chips = []): int
    {
        if (!$cards || $iid <= 0) return 0;
        $lines = [];
        foreach ($cards as $i => $c) $lines[] = self::fa($i + 1) . '. ' . $c['t'] . ($c['pf'] ? ' — ' . $c['pf'] : '') . "\n" . $c['u'];
        $body = '🏡 کارت ' . self::fa(count($cards)) . ' اقامتگاه پیشنهادی' . ($cards[0]['d'] ? ' (' . $cards[0]['d'] . ')' : '') . " — دکمه‌ی «" . (self::get('cards_button') ?: 'ثبت رزرو') . "»:\n" . implode("\n", $lines);
        $payload = ['cards' => $cards, 'btn' => self::get('cards_button') ?: 'ثبت رزرو'];
        if ($chips) $payload['qr'] = array_slice(array_values($chips), 0, 4);
        try {
            $cols = "identity_id, channel, direction, body, status" . (Database::hasColumn('eb_messages', 'source') ? ", source" : "") . (self::hasPayload() ? ", payload_json" : "");
            $vals = [$iid, 'webchat', 'out', $body, 'sent'];
            if (Database::hasColumn('eb_messages', 'source')) $vals[] = 'ai';
            if (self::hasPayload()) $vals[] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            Database::run("INSERT INTO eb_messages ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", $vals);
            $id = (int)Database::lastId();
            if (class_exists('ConvIndex')) { try { ConvIndex::touch($pid); } catch (Throwable $e) {} }
            return $id;
        } catch (Throwable $e) { error_log('[ChatPlus cards] ' . $e->getMessage()); return 0; }
    }

    /* ======================= ۲.۰.۷: کارت اقامتگاه در دایرکت اینستاگرام ======================= */

    public static function isIgChannel(string $ch): bool { return strpos($ch, 'instagram') === 0; }
    public static function cardChannel(string $ch): bool { return $ch === 'webchat' || self::isIgChannel($ch); }

    /** عکس هر کارت را آپلود می‌کند و ویترین اینستاگرام را آماده می‌سازد. فقط وقتی همه‌ی کارت‌ها عکس گرفتند پر برمی‌گردد. */
    public static function igPrepare(int $iid, array $cards, array $profile = []): array
    {
        try {
            $idn = Database::one("SELECT channel, channel_uid, ig_account_id FROM eb_identities WHERE id = ?", [$iid]);
            $why = function ($m) { error_log('[ChatPlus igPrepare] ' . $m); try { Database::run("INSERT INTO eb_settings (k, v) VALUES ('agent.ig_card_error', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [date('Y-m-d H:i') . ' — ' . $m]); } catch (Throwable $e) {} return []; };
            if (!$idn || empty($idn['ig_account_id']) || !class_exists('InstagramRoboclick')) return $why('این گفتگو به پیج اینستاگرامِ متصل با روبوکلیک وصل نیست (ig_account_id خالی است) — ویترین فقط با روبوکلیک ارسال می‌شود');
            $drv = InstagramRoboclick::driverForIdentity($idn);
            if (!$drv || !method_exists($drv, 'sendShowcase')) return $why('درایور روبوکلیک برای این پیج ساخته نشد (توکن/اتصال پیج را بررسی کنید)');
            // ۲.۱.۴: دکمه‌ی «مشاهده» روی کارت همیشه لینک می‌دهد (مگر در تنظیمات خاموش شود)، حتی در پروفایل «فقط کد»
            $btn = (string)($profile['link_mode'] ?? 'limited') !== 'code' || (class_exists('SalesAgent') && SalesAgent::get('ig_card_links') !== '0');
            $out = []; $keep = []; $fail = '';
            foreach (array_slice($cards, 0, 5) as $ci => $c) {
                // ۲.۱.۲: عکس PNG/WebP به JPG تبدیل می‌شود (آپلود اینستاگرام فقط JPG می‌پذیرد)؛ کارتی که عکسش نیامد کنار گذاشته می‌شود، نه کل ویترین
                $tmpF = [];
                $jp = (!empty($c['img']) && preg_match('#^https?://#i', (string)$c['img'])) ? Carousel::prepareJpg((string)$c['img'], $tmpF) : null;
                if (!$jp) { $fail = 'عکس کارت «' . ($c['t'] ?? '') . '» دریافت/تبدیل نشد: ' . ($c['img'] ?? '—'); foreach ($tmpF as $f) @unlink($f); continue; }
                $up = $drv->uploadImage($jp);
                $desc = (strncmp((string)@file_get_contents($jp, false, null, 0, 3), "\xFF\xD8\xFF", 3) === 0 ? 'JPG' : 'غیر JPG') . ' ' . @filesize($jp) . 'B';
                foreach ($tmpF as $f) @unlink($f);
                if (empty($up['ok']) || empty($up['photo'])) { $fail = 'آپلود عکس «' . ($c['t'] ?? '') . '» ناموفق: ' . ($up['error'] ?? '') . ' [' . $desc . ']'; continue; }
                $tok = ['{t}' => (string)$c['t'], '{pf}' => (string)($c['pf'] ?? ''), '{d}' => (string)($c['d'] ?? ''), '{id}' => (string)($c['id'] ?? '')];
                $titleTpl = class_exists('SalesAgent') ? trim((string)SalesAgent::get('ig_title_tpl')) : '';
                $subTpl   = class_exists('SalesAgent') ? trim((string)SalesAgent::get('ig_sub_tpl')) : '';
                $title = trim(strtr($titleTpl !== '' ? $titleTpl : '{t}', $tok));
                if ($title === '') $title = (string)$c['t'];
                // ۲.۱.۴: ظاهر حرفه‌ای — عنوان کوتاه و خوانا؛ زیرعنوان با کد (همیشه کامل)، قیمت و تاریخ؛ اگر جا نشد، تاریخ حذف می‌شود
                $short = function (string $x, int $n) { $x = trim(preg_replace('/\s+/u', ' ', $x)); if (mb_strlen($x) <= $n) return $x; $cut = mb_substr($x, 0, $n - 1); $sp = mb_strrpos($cut, ' '); return rtrim($sp !== false && $sp > $n * 0.5 ? mb_substr($cut, 0, $sp) : $cut, " ،,-–") . '…'; };
                if ($subTpl !== '') $sub = trim(strtr($subTpl, $tok));
                else {
                    $idp = ($c['id'] ?? '') !== '' ? '🔑 کد ' . self::fa($c['id']) : '';
                    $pp  = ($c['pf'] ?? '') !== '' ? '💰 ' . $c['pf'] : '';
                    $dp  = ($c['d'] ?? '') !== '' ? '📅 ' . $c['d'] : '';
                    $sub = implode(' · ', array_filter([$idp, $pp, $dp]));
                    if (mb_strlen($sub) > 80) $sub = implode(' · ', array_filter([$idp, $pp]));
                }
                $title = $short($title, 60);
                $card = ['title' => $title, 'subtitle' => $short($sub, 80), 'photo' => $up['photo']];
                if (!empty($c['u']) && preg_match('#^https?://#i', (string)$c['u']) && (!empty($btn) || (class_exists('SalesAgent') && SalesAgent::get('ig_card_links') !== '0'))) $card['default_action'] = ['type' => 'web_url', 'url' => $c['u']];   // لمس عکس/کارت
                $bts = [];
                for ($bi = 1; $bi <= 2; $bi++) {
                    $bt = class_exists('SalesAgent') ? trim((string)SalesAgent::get("ig_btn{$bi}_text")) : ($bi === 1 ? 'مشاهده اقامتگاه' : '');
                    $bu = class_exists('SalesAgent') ? trim((string)SalesAgent::get("ig_btn{$bi}_url")) : ($bi === 1 ? '{url}' : '');
                    if ($bt === '' || $bu === '') continue;
                    if ($bu === '{url}') { if (!$btn || empty($c['u'])) continue; $bu = $c['u']; }
                    if (!preg_match('#^https?://#i', $bu)) continue;
                    $bts[] = ['type' => 'link', 'title' => mb_substr($bt, 0, 20), 'url' => $bu];
                }
                if (!$bts && !empty($btn) && !empty($c['u']) && preg_match('#^https?://#i', (string)$c['u'])) $bts[] = ['type' => 'link', 'title' => 'مشاهده اقامتگاه', 'url' => $c['u']];   // دکمه‌ای تنظیم نشده بود: حداقل «مشاهده»
                if ($bts) $card['buttons'] = $bts;
                $out[] = $card; $keep[] = $ci;
            }
            if ($fail !== '') { error_log('[ChatPlus igPrepare] ' . $fail); try { Database::run("INSERT INTO eb_settings (k, v) VALUES ('agent.ig_card_error', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [date('Y-m-d H:i') . ' — ' . mb_substr($fail, 0, 300)]); } catch (Throwable $e) {} }
            return $out ? ['drv' => $drv, 'uid' => (string)$idn['channel_uid'], 'cards' => $out, 'channel' => (string)$idn['channel'], 'keep' => $keep] : [];
        } catch (Throwable $e) { error_log('[ChatPlus igPrepare] ' . $e->getMessage()); return []; }
    }

    /** ارسال ویترین و ثبت آن در صندوق ورودی (متن جایگزین برای همکاران) */
    public static function igSend(int $pid, int $iid, array $prep, array $cards): bool
    {
        $r = $prep['drv']->sendShowcase($prep['uid'], $prep['cards']);
        if (empty($r['ok'])) {   // اگر سرویس فیلد لمس‌عکس (default_action) را نپذیرفت، بدون آن دوباره
            $plain = array_map(function ($c) { unset($c['default_action']); return $c; }, $prep['cards']);
            if ($plain !== $prep['cards']) { $r2 = $prep['drv']->sendShowcase($prep['uid'], $plain); if (!empty($r2['ok'])) $r = $r2; }
        }
        $ok = !empty($r['ok']);
        $lines = [];
        foreach ($cards as $i => $c) $lines[] = self::fa($i + 1) . '. ' . $c['t'] . ($c['pf'] ? ' — ' . $c['pf'] : '') . ($c['u'] ? "\n" . $c['u'] : '');
        $body = '🏡 کارت ' . self::fa(count($cards)) . " اقامتگاه پیشنهادی (دایرکت):\n" . implode("\n", $lines);
        try {
            $cols = "identity_id, channel, direction, body, status" . ($ok ? '' : ', error') . (Database::hasColumn('eb_messages', 'source') ? ", source" : "");
            $vals = [$iid, $prep['channel'] ?: 'instagram', 'out', $body, $ok ? 'sent' : 'failed'];
            if (!$ok) $vals[] = mb_substr((string)($r['error'] ?? 'showcase_failed'), 0, 500);
            if (Database::hasColumn('eb_messages', 'source')) $vals[] = 'ai';
            Database::run("INSERT INTO eb_messages ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", $vals);
            if (class_exists('ConvIndex')) { try { ConvIndex::touch($pid); } catch (Throwable $e) {} }
        } catch (Throwable $e) { error_log('[ChatPlus igSend] ' . $e->getMessage()); }
        return $ok;
    }

    private static function findItems(array $data): ?array
    {
        foreach (['items', 'results', 'spaces', 'data'] as $k) {
            if (isset($data[$k]) && is_array($data[$k]) && isset($data[$k][0]) && is_array($data[$k][0])) return $data[$k];
        }
        foreach ($data as $v) if (is_array($v) && !isset($v[0])) { $r = self::findItems($v); if ($r !== null) return $r; }
        return null;
    }

    private static function pickUrl(array $it, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $it[$k] ?? null;
            if (is_string($v) && preg_match('#^https?://\S+$#i', trim($v))) return trim($v);
        }
        foreach (['links', 'urls'] as $k) if (isset($it[$k]) && is_array($it[$k])) { $r = self::pickUrl($it[$k], $keys); if ($r !== '') return $r; }
        return '';
    }

    private static function pickImage(array $it, string $base): string
    {
        $fix = function ($v) use ($base): string {
            if (is_array($v)) $v = $v['url'] ?? $v['src'] ?? $v['path'] ?? $v['large'] ?? $v['medium'] ?? $v['original'] ?? '';
            $v = trim((string)$v);
            if ($v === '') return '';
            if (preg_match('#^https?://#i', $v)) return $v;
            if (strpos($v, '//') === 0) return 'https:' . $v;
            if ($v[0] === '/' && preg_match('#^(https?://[^/]+)#i', $base, $m)) return $m[1] . $v;
            return '';
        };
        foreach (['cover_image', 'cover_url', 'cover', 'main_image', 'image_url', 'image', 'thumbnail_url', 'thumbnail', 'thumb', 'photo_url', 'photo', 'picture', 'img'] as $k) {
            if (!empty($it[$k])) { $u = $fix($it[$k]); if ($u !== '') return $u; }
        }
        foreach (['images', 'photos', 'gallery', 'media', 'pictures'] as $k) {
            if (!empty($it[$k]) && is_array($it[$k])) { $first = reset($it[$k]); $u = $fix($first); if ($u !== '') return $u; }
        }
        return '';
    }

    private static function num(array $it, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $it)) continue;
            $v = $it[$k];
            if (is_array($v)) $v = $v['amount'] ?? $v['value'] ?? $v['total'] ?? null;
            if ($v === null || $v === '') continue;
            $v = preg_replace('/[^\d.]/', '', Persons::asciiDigits((string)$v));
            if ($v !== '' && is_numeric($v)) return (float)$v;
        }
        foreach (['pricing', 'price_info', 'prices'] as $k) if (isset($it[$k]) && is_array($it[$k])) { $r = self::num($it[$k], $keys); if ($r !== null) return $r; }
        return null;
    }

    private static function parseJ(string $d): ?array
    {
        $d = Persons::asciiDigits(trim($d));
        return preg_match('#^(1[34]\d\d)[-/](\d{1,2})[-/](\d{1,2})#', $d, $m) ? [(int)$m[1], (int)$m[2], (int)$m[3]] : null;
    }

    public static function dateRange(string $ci, string $co): string
    {
        $a = self::parseJ($ci); $b = self::parseJ($co);
        if (!$a) return '';
        $fa = fn($x) => self::fa($x[2]) . ' ' . (self::MONTHS[$x[1]] ?? '');
        return $b ? $fa($a) . ' تا ' . $fa($b) : 'ورود ' . $fa($a);
    }

    private static function nights(string $ci, string $co): int
    {
        $a = self::parseJ($ci); $b = self::parseJ($co);
        if (!$a || !$b) return 0;
        $days = fn($x) => $x[0] * 365 + ($x[1] <= 6 ? ($x[1] - 1) * 31 : 186 + ($x[1] - 7) * 30) + $x[2];   // تقریبی کافی برای شمارش شب‌ها
        $n = $days($b) - $days($a);
        return ($n > 0 && $n < 60) ? $n : 0;
    }

    public static function fa($s): string
    {
        return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /* ======================= ۴) نام واحدها ======================= */

    /** هر نتیجه‌ی جستجو/جزئیات: نام و کد واحد در فهرست محلی ذخیره می‌شود (برای تشخیص نام در پیام مشتری) */
    public static function learnUnits($data, string $base = ''): int
    {
        if (!is_array($data)) return 0;
        $items = self::findItems($data);
        if ($items === null && (isset($data['title']) || isset($data['name'])) && (isset($data['id']) || isset($data['code']))) $items = [$data];
        if (!$items) return 0;
        self::ensure();
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $id = trim((string)($it['id'] ?? $it['code'] ?? $it['space_id'] ?? ''));
            $title = trim((string)($it['title'] ?? $it['name'] ?? $it['space_title'] ?? ''));
            if ($id === '' || $title === '' || mb_strlen($id) > 40) continue;
            $place = trim(implode('، ', array_filter([(string)($it['neighborhood'] ?? $it['hood'] ?? $it['area'] ?? ''), (string)($it['city'] ?? $it['city_name'] ?? '')])));
            $price = self::num($it, ['effective_price', 'nightly_price', 'price_per_night', 'price', 'min_price']);
            try {
                Database::run("INSERT INTO eb_cp_units (space_id, title, norm, place, image, url, price) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE title = VALUES(title), norm = VALUES(norm), place = COALESCE(NULLIF(VALUES(place),''), place),
                    image = COALESCE(NULLIF(VALUES(image),''), image), url = COALESCE(NULLIF(VALUES(url),''), url), price = COALESCE(VALUES(price), price)",
                    [$id, mb_substr($title, 0, 255), mb_substr(self::norm($title), 0, 255), mb_substr($place, 0, 190) ?: null,
                     mb_substr(self::pickImage($it, $base), 0, 600) ?: null, mb_substr(self::pickUrl($it, ['url', 'link', 'space_url', 'booking_url', 'referral_url']), 0, 600) ?: null, $price !== null ? (int)$price : null]);
                $n++;
            } catch (Throwable $e) {}
        }
        return $n;
    }

    /** یکسان‌سازی متن فارسی برای مقایسه‌ی نام‌ها */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(Persons::asciiDigits($s));
        $s = strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و', "\u{200c}" => ' ', "\u{200d}" => '', 'ـ' => '']);
        $s = preg_replace('/[\x{064B}-\x{065F}]/u', '', $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** واژه‌های عمومی نوع/امکانات که به‌تنهایی نام یک واحد نیستند */
    const GENERIC = ['ویلا', 'ویلای', 'سوئیت', 'سویت', 'آپارتمان', 'اپارتمان', 'اقامتگاه', 'کلبه', 'سوییسی', 'سوئیسی', 'مبله', 'دربست', 'لوکس', 'استخر', 'استخردار', 'جکوزی', 'بیلیارد',
        'با', 'در', 'و', 'به', 'از', 'یک', 'دو', 'سه', 'خوابه', 'خواب', 'اتاق', 'تهران', 'شمال', 'جنوب', 'غرب', 'شرق', 'مرکز', 'ساحلی', 'جنگلی', 'کوهستانی', 'هتل', 'هاستل', 'خانه', 'منزل', 'واحد', 'طبقه', 'نفر', 'نفره', 'روستایی', 'بومگردی', 'اکو', 'دوبلکس', 'سنتی', 'نوساز'];

    /** واژه‌های رایج گفتگو که نباید به‌عنوان نام واحد حساب شوند */
    const COMMON = ['برای', 'میخوام', 'میخواستم', 'خالیه', 'خالی', 'قیمت', 'قیمتش', 'چنده', 'هفته', 'امروز', 'امشب', 'فردا', 'آخر', 'اخر', 'لطفا', 'سلام', 'داره', 'دارید', 'دارین', 'رزرو',
        'کجاست', 'کنار', 'نزدیک', 'بزرگ', 'کوچک', 'خوب', 'عالی', 'ارزان', 'ارزون', 'تخفیف', 'پارکینگ', 'حیاط', 'باغ', 'دریا', 'ساحل', 'جنگل', 'کوه', 'شهر', 'خیابان', 'کوچه', 'میدان', 'بلوار',
        'ویو', 'منظره', 'نما', 'بالکن', 'تراس', 'آشپزخانه', 'کامل', 'جدید', 'قدیمی', 'زیبا', 'دنج', 'آرام', 'خانوادگی', 'گروهی', 'دوستانه', 'رویایی', 'شیک', 'مدرن', 'vip', 'ویژه'];

    /**
     * نام اختصاصی یک واحد در پیام مشتری؟ (مثلاً «سوئیت لوتوس» یا «ویلا آرامش ۲»)
     * @return array|null {space_id, title, place, score}
     */
    public static function matchUnit(string $text): ?array
    {
        if (self::get('unit_match') !== '1') return null;
        $t = ' ' . self::norm($text) . ' ';
        if (mb_strlen(trim($t)) < 3) return null;
        try {
            self::ensure();
            static $units = null;
            if ($units === null) $units = Database::all("SELECT space_id, title, norm, place FROM eb_cp_units ORDER BY updated_at DESC LIMIT 6000");
        } catch (Throwable $e) { return null; }
        if (!$units) return null;
        static $df = null, $placeW = null;
        if ($df === null) {
            $df = []; $placeW = [];
            foreach ($units as $u) {
                foreach (array_unique(explode(' ', (string)$u['norm'])) as $w) $df[$w] = ($df[$w] ?? 0) + 1;
                foreach (explode(' ', self::norm((string)($u['place'] ?? ''))) as $w) if ($w !== '') $placeW[$w] = 1;
            }
        }
        $msgWords = array_flip(array_filter(explode(' ', trim($t))));
        $best = null; $second = 0;
        foreach ($units as $u) {
            $norm = (string)$u['norm'];
            if ($norm === '') continue;
            $score = 0;
            if (mb_strlen($norm) >= 5 && mb_strpos($t, ' ' . $norm . ' ') !== false) $score = 100 + mb_strlen($norm);   // نام کامل
            else {
                // واژه‌های اختصاصی نام: بدون نوع واحد، امکانات، نام محله/شهر و واژه‌های رایج گفتگو
                $words = array_values(array_filter(array_unique(explode(' ', $norm)), fn($w) => mb_strlen($w) >= 3 && !ctype_digit($w)
                    && !in_array($w, self::GENERIC, true) && !in_array($w, self::COMMON, true) && !isset($placeW[$w])));
                if (!$words) continue;
                $hit = 0; $rare = 0;
                foreach ($words as $w) if (isset($msgWords[$w])) { $hit++; if (($df[$w] ?? 0) <= 1 && mb_strlen($w) >= 4) $rare++; }
                if ($hit && $hit === count($words)) $score = 40 + 10 * $hit;
                elseif ($rare) $score = 25 + 10 * $rare;
            }
            if ($score <= 0) continue;
            if (!$best || $score > $best['score']) { $second = $best['score'] ?? 0; $best = ['space_id' => (string)$u['space_id'], 'title' => (string)$u['title'], 'place' => (string)($u['place'] ?? ''), 'score' => $score]; }
            elseif ($score > $second) $second = $score;
        }
        if (!$best || $best['score'] === $second) return null;   // دو واحد با امتیاز برابر: مطمئن نیستیم
        return $best;
    }

    public static function unitSearch(string $q, int $limit = 8): array
    {
        self::ensure();
        $q = self::norm($q);
        if ($q === '') return [];
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        return Database::all("SELECT space_id, title, place, url, price FROM eb_cp_units WHERE norm LIKE ? OR space_id = ? ORDER BY CHAR_LENGTH(norm) LIMIT " . max(1, min(20, $limit)), [$like, $q]);
    }

    public static function unitsReport(): array
    {
        self::ensure();
        try {
            $r = Database::one("SELECT COUNT(*) n, MAX(updated_at) last FROM eb_cp_units") ?: [];
            $sync = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = 'chatplus.units_sync'")['v'] ?? ''), true) ?: null;
            return ['count' => (int)($r['n'] ?? 0), 'last' => $r['last'] ?? null, 'sync' => $sync, 'recent' => Database::all("SELECT space_id, title, place, price FROM eb_cp_units ORDER BY updated_at DESC LIMIT 12")];
        } catch (Throwable $e) { return ['count' => 0, 'last' => null, 'sync' => null, 'recent' => []]; }
    }

    /** به‌روزرسانی فهرست واحدها از ابزار جستجوی اتصال (صفحه‌به‌صفحه، بدون فیلتر) */
    public static function syncUnits(int $maxPages = 30, int $budgetSec = 40): array
    {
        $tool = self::searchTool();
        if (!$tool) return ['ok' => false, 'error' => 'اتصال API با ابزار «search» پیدا نشد (اتصال جاکجاست را در دستیار فروش فعال کنید)'];
        [$c, $t] = $tool;
        $t0 = microtime(true); $pages = 0; $units = 0; $err = null;
        @set_time_limit($budgetSec + 30);
        for ($p = 1; $p <= $maxPages && microtime(true) - $t0 < $budgetSec; $p++) {
            $r = ApiConnectors::call($c, $t, ['page' => $p], []);
            if (empty($r['ok'])) { $err = $r['error'] ?? 'خطای وب‌سرویس'; break; }
            $n = self::learnUnits($r['data'], (string)$c['base_url']);
            $pages++; $units += $n;
            if ($n === 0) break;
        }
        $res = ['ok' => $err === null || $units > 0, 'pages' => $pages, 'units' => $units, 'error' => $err, 'at' => date('Y-m-d H:i:s')];
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('chatplus.units_sync', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($res, JSON_UNESCAPED_UNICODE)]);
        return $res;
    }

    /** ابزار «search» اولین اتصال فعال: [connector, tool] */
    public static function searchTool(?array $map = null): ?array
    {
        if (!class_exists('ApiConnectors')) return null;
        if ($map) { foreach ($map as $fn => [$c, $t]) if (($t['name'] ?? '') === 'search') return [$c, $t]; return null; }
        try {
            ApiConnectors::ensure();
            foreach (ApiConnectors::all(true) as $c) foreach ((array)($c['tools'] ?? []) as $t) if (($t['name'] ?? '') === 'search') return [$c, $t];
        } catch (Throwable $e) {}
        return null;
    }

    /* ======================= ۵) ارزان‌ترین‌ها ======================= */

    /**
     * چند صفحه‌ی جستجو با همان فیلترها، مرتب بر اساس قیمت (کل بازه اگر تاریخ دارد، وگرنه شبانه).
     * @return array{ok:bool, data?:array, error?:string, pages:int}
     */
    public static function cheapest(array $c, array $t, array $args, array $ctx, int $pages = 10): array
    {
        $all = []; $seen = []; $n = 0; $lastErr = null; $t0 = microtime(true);
        unset($args['page']);
        for ($p = 1; $p <= $pages && microtime(true) - $t0 < 25; $p++) {   // ۱.۰.۱۶: تا ۱۰ صفحه (حداکثر ۲۵ ثانیه)
            $r = ApiConnectors::call($c, $t, $args + ['page' => $p], $ctx);
            if (empty($r['ok'])) { $lastErr = $r['error'] ?? 'خطا'; break; }
            $n++;
            $items = is_array($r['data']) ? (self::findItems($r['data']) ?? []) : [];
            if (!$items) break;
            self::learnUnits($r['data'], (string)$c['base_url']);
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $id = (string)($it['id'] ?? $it['code'] ?? md5(json_encode($it)));
                if (isset($seen[$id])) continue;
                $seen[$id] = 1;
                $all[] = $it;
            }
            if (count($items) < 3) break;
        }
        if (!$all) return $lastErr !== null ? ['ok' => false, 'error' => $lastErr, 'pages' => $n] : ['ok' => true, 'data' => ['items' => [], 'note' => 'با این فیلترها چیزی خالی نبود'], 'pages' => $n];
        $nights = self::nights((string)($args['check_in'] ?? ''), (string)($args['check_out'] ?? ''));
        $key = function ($it) use ($nights) {
            $tot = self::num($it, ['total_payable_price', 'total_price', 'price_total', 'total_amount', 'total']);
            if ($tot !== null && $tot > 0) return $tot;
            $nt = self::num($it, ['effective_price', 'nightly_price', 'price_per_night', 'price', 'min_price', 'base_price']);
            return $nt !== null && $nt > 0 ? $nt * max(1, $nights) : PHP_FLOAT_MAX;
        };
        usort($all, fn($a, $b) => $key($a) <=> $key($b));
        $top = array_slice(array_values(array_filter($all, fn($x) => $key($x) < PHP_FLOAT_MAX)), 0, 5);
        return ['ok' => true, 'pages' => $n, 'data' => ['sorted_by' => 'price_asc', 'checked' => count($all), 'items' => $top,
            'note' => 'این‌ها ارزان‌ترین گزینه‌ها از بین ' . count($all) . ' نتیجه‌ی بررسی‌شده است (از ارزان به گران). از همین ترتیب استفاده کن و قیمت را دقیق بگو.']];
    }

    /* ======================= ۱.۰.۱۶: جستجوی دقیق‌تر محله‌ها، تنوع میزبان، بودجه ======================= */

    /** نام‌های دیگر یک محله: املای با/بی‌فاصله و زیرمحله‌هایی که مدیر تعریف کرده (area_alias) */
    public static function qVariants(string $q): array
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) > 40) return [];
        $out = [];
        $sp = preg_replace('/[\s\x{200c}]+/u', ' ', $q);
        foreach ([$sp, str_replace(' ', "\u{200c}", $sp), str_replace(' ', '', $sp)] as $v) if ($v !== $q) $out[] = $v;
        $nq = self::norm(str_replace(' ', '', $sp));
        foreach (preg_split('/[\r\n]+/u', self::get('area_alias')) as $ln) {
            if (strpos($ln, '=') === false) continue;
            [$a, $b] = array_map('trim', explode('=', $ln, 2));
            $names = array_values(array_filter(array_map('trim', preg_split('/[,،]+/u', $b))));
            $all = array_merge([$a], $names);
            foreach ($all as $n) if (self::norm(str_replace([' ', "\u{200c}"], '', $n)) === $nq) { foreach ($all as $x) if (self::norm(str_replace([' ', "\u{200c}"], '', $x)) !== $nq) $out[] = $x; break; }
        }
        return array_slice(array_values(array_unique($out)), 0, 6);
    }

    /** گروه میزبان/ساختمان هر نتیجه (برای پیشنهاد از میزبان‌های مختلف) */
    private static function hostKey(array $it): string
    {
        foreach (['host_id', 'owner_id', 'host', 'host_name', 'owner', 'owner_name', 'hostname', 'user_id'] as $k) {
            $v = $it[$k] ?? null;
            if (is_array($v)) $v = $v['id'] ?? $v['name'] ?? null;
            if ($v !== null && $v !== '') return 'h:' . (string)$v;
        }
        // بدون شناسه‌ی میزبان: نام بدون شماره‌ی واحد/طبقه (مثل «لاولی روم تهرانپارس ۴۰۲» و «۲۰۳»)
        $stop = ['واحد', 'طبقه', 'اتاق', 'سوئیت', 'سویت', 'آپارتمان', 'اپارتمان', 'یک', 'دو', 'سه', 'تک', 'خواب', 'خوابه', 'دوخواب', 'یکخواب', 'تکخواب', 'سهخواب', 'مبله'];
        $t = implode(' ', array_filter(explode(' ', self::norm((string)($it['title'] ?? $it['name'] ?? ''))), fn($w) => $w !== '' && !ctype_digit($w) && !in_array($w, $stop, true)));
        return 't:' . trim(preg_replace('/\s+/u', ' ', (string)$t));
    }

    /** ۲.۱.۲: اگر الان خارج ساعت کاری میزبان‌هاست و تاریخ ورود امشب/فردا است، ['hosts'=>[…], 'now'=>…, 'to'=>…] وگرنه null */
    private static function afterHours(array $args): ?array
    {
        if (self::get('ah_enabled') !== '1') return null;
        try {
            $now = new DateTime('now', new DateTimeZone('Asia/Tehran'));
            $m = (int)$now->format('G') * 60 + (int)$now->format('i');
            $mm = fn($h) => ((int)substr($h, 0, 2)) * 60 + (int)substr($h, 3, 2);
            $from = $mm(self::get('ah_from') ?: '22:00'); $to = $mm(self::get('ah_to') ?: '07:00');
            $in = $from > $to ? ($m >= $from || $m < $to) : ($m >= $from && $m < $to);
            if (!$in) return null;
            $ci = self::parseJ((string)($args['check_in'] ?? ''));
            if (!$ci || !class_exists('SalesAgent')) return null;
            $t0 = self::g2jNow(0); $t1 = self::g2jNow(1);
            $key = fn($x) => $x[0] * 10000 + $x[1] * 100 + $x[2];
            if ($key($ci) < $key($t0) || $key($ci) > $key($t1)) return null;
            $hosts = [];
            foreach (preg_split('/[\r\n,،]+/u', (string)self::get('ah_hosts')) as $h) { $h = self::norm(trim($h)); if ($h !== '') $hosts[] = $h; }
            return ['hosts' => $hosts, 'now' => self::fa($now->format('H:i')), 'to' => self::get('ah_to') ?: '07:00'];
        } catch (Throwable $e) { return null; }
    }

    private static function g2jNow(int $plusDays): array
    {
        $d = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        if ($plusDays) $d->modify('+' . $plusDays . ' day');
        return SalesAgent::g2j((int)$d->format('Y'), (int)$d->format('n'), (int)$d->format('j'));
    }

    /** آیا میزبان/اقامتگاهِ این نتیجه در فهرست هماهنگ‌شده است؟ (فهرست خالی = همه) */
    private static function hostAgreed(array $it, array $hosts): bool
    {
        if (!$hosts) return true;
        $vals = [];
        foreach (['id', 'code', 'host_id', 'owner_id', 'user_id', 'host', 'host_name', 'owner', 'owner_name', 'title', 'name'] as $k) {
            $v = $it[$k] ?? null;
            if (is_array($v)) $v = ($v['id'] ?? '') . ' ' . ($v['name'] ?? '');
            if ($v !== null && $v !== '') $vals[] = self::norm((string)$v);
        }
        foreach ($hosts as $h) foreach ($vals as $v) if ($v === $h || (mb_strlen($h) >= 4 && mb_strpos($v, $h) !== false)) return true;
        return false;
    }

    /** به خروجی جستجو برای مدل: گروه میزبان، فاصله با بودجه */
    public static function annotate(array $data, array $prefs, array $args): array
    {
        $items = self::findItems($data);
        if (!$items) return $data;
        $nights = self::nights((string)($args['check_in'] ?? ''), (string)($args['check_out'] ?? ''));
        $bn = (int)($prefs['budget_night'] ?? 0); $bt = (int)($prefs['budget_total'] ?? 0);
        if (!$bn && $bt && $nights) $bn = (int)round($bt / $nights);
        $groups = []; $over = 0; $within = 0; $ahN = 0;
        $ah = self::afterHours($args);
        $walk = function (&$node) use (&$walk, $nights, $bn, &$groups, &$over, &$within, $ah, &$ahN) {
            if (!is_array($node)) return;
            if (isset($node['items']) && is_array($node['items'])) {
                foreach ($node['items'] as &$it) {
                    if (!is_array($it)) continue;
                    if ($ah) { if (self::hostAgreed($it, $ah['hosts'])) { $it['after_hours_ok'] = true; $ahN++; } else $it['after_hours_host_reply_in_morning'] = true; }
                    if (self::get('host_diverse') === '1') { $g = self::hostKey($it); $groups[$g] = ($groups[$g] ?? 0) + 1; $it['host_group'] = substr(md5($g), 0, 6); }
                    if ($bn > 0) {
                        $tot = self::num($it, ['total_payable_price', 'total_price', 'price_total', 'total_amount', 'total']);
                        $per = self::num($it, ['effective_price', 'nightly_price', 'price_per_night', 'price', 'min_price']);
                        $night = ($tot && $nights) ? $tot / $nights : $per;
                        if ($night) {
                            $diff = (int)round($night - $bn);
                            if ($diff > 0) { $it['over_budget_per_night'] = $diff; if ($nights) $it['over_budget_total'] = $diff * $nights; $over++; }
                            else { $it['within_budget'] = true; $within++; }
                        }
                    }
                }
                unset($it);
            }
            foreach ($node as $k => &$v) if ($k !== 'items' && is_array($v)) $walk($v);
        };
        $walk($data);
        $notes = [];
        if (self::get('host_diverse') === '1' && $groups && max($groups) > 1) $notes[] = 'بعضی نتایج از یک میزبان/ساختمان‌اند (host_group یکسان)؛ از هر host_group حداکثر یک گزینه پیشنهاد بده تا گزینه‌ها متنوع باشند.';
        if ($bn > 0) $notes[] = $within ? 'بودجه‌ی شبانه‌ی مشتری حدود ' . number_format($bn) . ' تومان است؛ اول گزینه‌های within_budget را پیشنهاد بده.'
            : 'هیچ گزینه‌ای در بودجه‌ی مشتری (' . number_format($bn) . ' تومان هر شب) نبود. نزدیک‌ترین‌ها را با «حدود X تومان بیشتر» (over_budget_total یا over_budget_per_night) دقیق بگو، و پیشنهاد بده همکاران پشتیبانی برایش گزینه‌ی مناسب بودجه پیدا کنند (request_callback).';
        if ($ah) $notes[] = 'الان ساعت ' . $ah['now'] . ' و خارج ساعت کاری میزبان‌هاست؛ استعلام‌ها تا ساعت ' . self::fa($ah['to']) . ' جواب نمی‌گیرند. تاریخ مشتری امشب/فردا است: ' . ($ahN ? 'اول گزینه‌های after_hours_ok را پیشنهاد بده (میزبانشان از قبل هماهنگ کرده و خالی بودن تقویم کافی است)؛ گزینه‌های after_hours_host_reply_in_morning فقط اگر لازم بود و با گفتن اینکه جواب میزبان صبح می‌آید.' : 'گزینه‌ها استعلامی‌اند و تا صبح جواب نمی‌گیرند؛ صادقانه بگو جواب میزبان صبح می‌آید، و اگر مشتری عجله دارد گزینه‌ی رزرو فوری یا درخواست تماس پیشنهاد بده.');
        if ($notes) $data = ['budget_and_variety_note' => implode(' ', $notes)] + $data;
        return $data;
    }

    /** اطلاعات ثابت برای دستیار (لینک اپ، کد تخفیف، شرایط…) */
    public static function factsBlock(): string
    {
        $f = trim(self::get('agent_facts'));
        $out = $f === '' ? '' : "اطلاعات و لینک‌های رسمی که می‌توانی دقیقاً همین‌ها را به مشتری بدهی (چیزی خارج از این‌ها نساز):\n" . $f;
        $pay = trim(self::get('pay_info'));
        if ($pay !== '') $out .= ($out === '' ? '' : "\n\n") . "شیوه‌های پرداخت (وقتی مشتری از اقساط، کارت‌به‌کارت، نحوه‌ی پرداخت پرسید دقیقاً طبق این بگو، حدس نزن):\n" . $pay;
        return $out;
    }
}
