<?php
/**
 * modules/AgentProfiles.php — پروفایل برند دستیار فروش
 * ==========================================================================
 * هر پیج اینستاگرام یا کانال می‌تواند برند و رفتار خودش را داشته باشد:
 *   - نام برند و شخصیت (دستیار خودش را «دستیار فروش {برند}» معرفی می‌کند)
 *   - پایگاه دانش: عمومی، خصوصیِ همین پروفایل، هر دو، یا هیچ
 *   - اتصال‌های API مجاز (مثلاً پیج یک برند دیگر هم از موجودی جاکجاست بفروشد)
 *   - نحوه‌ی ارسال لینک (محدودیت لینک در اینستاگرام): لینک کامل / حداکثر N لینک / فقط کد
 *   - نام‌هایی که نباید در گفتگو بیاید (مثلاً «جاکجاست» در پیج برند دیگر) — سرور هم جایگزین می‌کند
 * هدف‌ها (targets): «ig:<شناسه‌ی پیج>» یا «ch:<کانال>». اگر هیچ پروفایلی هدف نداشت، پیش‌فرض.
 */
class AgentProfiles
{
    const DEFAULT_PROMPT = "گرم، محترمانه (رسمی‌ـ‌صمیمی)، کوتاه و دقیق به فارسی جواب بده.\nهدفت این است که مشتری گزینه‌ی مناسبش را پیدا کند و خریدش را کامل کند؛ قدم‌به‌قدم جلو برو و در هر پیام فقط یک سؤال بپرس.\nگفتگو را جلو ببر (پیشنهاد، استعلام، پرداخت یا پیگیری) ولی بدون برچسب «قدم بعدی»؛ مشتری را هیچ‌وقت بدون جایگزین رها نکن.";
    const LINK_MODES = ['link' => 'همه‌ی لینک‌ها', 'limited' => 'حداکثر چند لینک', 'code' => 'فقط کد (بدون لینک)'];
    const PERSONAS = ['ai' => 'دستیار هوشمند (می‌گوید هوش مصنوعی است)', 'human' => 'پشتیبان با نام (به ربات بودن اشاره نمی‌کند)'];
    const KB_MODES = ['both' => 'عمومی + خصوصی', 'general' => 'فقط عمومی', 'own' => 'فقط خصوصی', 'none' => 'بدون پایگاه دانش'];

    private static $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        ApiConnectors::ensure();
        return self::$cache = array_map([self::class, 'hydrate'], Database::all("SELECT * FROM eb_agent_profiles ORDER BY is_default DESC, id"));
    }

    public static function get(int $id): ?array
    {
        foreach (self::all() as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    private static function hydrate(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['targets'] = json_decode((string)($r['targets_json'] ?? ''), true) ?: [];
        $r['connectors'] = array_map('intval', json_decode((string)($r['connectors_json'] ?? ''), true) ?: []);
        $r['link_max'] = max(0, (int)$r['link_max']);
        $r['agent_name'] = trim((string)($r['agent_name'] ?? ''));
        $r['persona'] = ($r['persona'] ?? 'ai') === 'human' ? 'human' : 'ai';
        $r['avatar'] = (string)($r['avatar'] ?? '');
        $r['sms_links'] = (int)($r['sms_links'] ?? 1);
        foreach (['allow_checkout', 'is_default', 'is_active'] as $k) $r[$k] = (int)$r[$k];
        unset($r['targets_json'], $r['connectors_json']);
        return $r;
    }

    public static function save(array $in, ?int $id = null): int
    {
        ApiConnectors::ensure();
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('نام پروفایل لازم است');
        $targets = array_values(array_unique(array_filter(array_map('strval', (array)($in['targets'] ?? [])), fn($t) => preg_match('/^(ig:\d+|ch:[a-z_]+|cm:\d+|cm:all)$/', $t))));
        // هر هدف فقط به یک پروفایل
        foreach (self::all() as $p) {
            if ($id && $p['id'] === $id) continue;
            $dup = array_intersect($p['targets'], $targets);
            if ($dup) throw new InvalidArgumentException('«' . implode('، ', $dup) . '» قبلاً به پروفایل «' . $p['name'] . '» وصل است');
        }
        $vals = [
            'name' => mb_substr($name, 0, 120), 'brand' => mb_substr(trim((string)($in['brand'] ?? '')), 0, 120),
            'prompt' => (string)($in['prompt'] ?? ''),
            'kb_mode' => array_key_exists($in['kb_mode'] ?? '', self::KB_MODES) ? $in['kb_mode'] : 'both',
            'connectors_json' => json_encode(array_values(array_map('intval', (array)($in['connectors'] ?? [])))),
            'link_mode' => array_key_exists($in['link_mode'] ?? '', self::LINK_MODES) ? $in['link_mode'] : 'limited',
            'link_max' => max(0, min(10, (int)($in['link_max'] ?? 1))),
            'allow_checkout' => !empty($in['allow_checkout']) ? 1 : 0,
            'avoid_names' => mb_substr(trim((string)($in['avoid_names'] ?? '')), 0, 255) ?: null,
            'agent_name' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['agent_name'] ?? ''))), 0, 60),
            'persona' => array_key_exists($in['persona'] ?? '', self::PERSONAS) ? $in['persona'] : 'ai',
            'avatar' => preg_match('#^uploads/[A-Za-z0-9_.-]+$#', (string)($in['avatar'] ?? '')) ? (string)$in['avatar'] : '',
            'sms_links' => !array_key_exists('sms_links', $in) || !empty($in['sms_links']) ? 1 : 0,
            'targets_json' => json_encode($targets),
            'is_active' => !array_key_exists('is_active', $in) || !empty($in['is_active']) ? 1 : 0,
        ];
        if ($id) {
            Database::run("UPDATE eb_agent_profiles SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals))) . " WHERE id = ?", array_merge(array_values($vals), [$id]));
        } else {
            Database::run("INSERT INTO eb_agent_profiles (" . implode(', ', array_keys($vals)) . ") VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", array_values($vals));
            $id = Database::lastId();
        }
        if (!empty($in['is_default'])) {
            Database::run("UPDATE eb_agent_profiles SET is_default = (id = ?)", [$id]);
        }
        self::$cache = null;
        return $id;
    }

    public static function delete(int $id): void
    {
        $p = self::get($id);
        if ($p && $p['is_default']) throw new InvalidArgumentException('پروفایل پیش‌فرض حذف نمی‌شود؛ اول پروفایل دیگری را پیش‌فرض کنید');
        Database::run("DELETE FROM eb_agent_profiles WHERE id = ?", [$id]);
        Database::run("UPDATE eb_agent_kb_docs SET profile_id = NULL, is_active = 0 WHERE profile_id = ?", [$id]);
        self::$cache = null;
    }

    /** پروفایل مناسب یک گفتگو (بر اساس پیج اینستاگرام یا کانال) */
    public static function resolve(string $channel, int $identityId = 0, ?int $forceId = null, bool $comment = false): array
    {
        $all = array_values(array_filter(self::all(), fn($p) => $p['is_active']));
        if ($forceId) foreach ($all as $p) if ($p['id'] === $forceId) return $p;
        $keys = [];
        if ($identityId > 0) {
            try {
                $i = Database::one("SELECT channel, ig_account_id FROM eb_identities WHERE id = ?", [$identityId]);
                // ۱.۰.۱۷: کامنت‌ها می‌توانند پروفایل جدا داشته باشند (کامنت‌های یک پیج، یا همه‌ی کامنت‌ها)؛ وگرنه پروفایل همان پیج
                if ($comment && !empty($i['ig_account_id'])) $keys[] = 'cm:' . (int)$i['ig_account_id'];
                if ($comment) $keys[] = 'cm:all';
                if (!empty($i['ig_account_id'])) $keys[] = 'ig:' . (int)$i['ig_account_id'];
                if (!empty($i['channel'])) $channel = (string)$i['channel'];
            } catch (Throwable $e) {}
        }
        if (strpos($channel, 'instagram') === 0) $channel = 'instagram';
        if ($channel !== '') $keys[] = 'ch:' . $channel;
        foreach ($keys as $k) foreach ($all as $p) if (in_array($k, $p['targets'], true)) return $p;
        foreach ($all as $p) if ($p['is_default']) return $p;
        return $all[0] ?? ['id' => 0, 'name' => 'پیش‌فرض', 'brand' => '', 'prompt' => self::DEFAULT_PROMPT, 'kb_mode' => 'both', 'connectors' => [],
            'link_mode' => 'link', 'link_max' => 3, 'allow_checkout' => 1, 'avoid_names' => null, 'targets' => [], 'is_default' => 1, 'is_active' => 1,
            'agent_name' => '', 'persona' => 'ai', 'avatar' => '', 'sms_links' => 1];
    }

    /** بخش پروفایل در دستور سیستم */
    public static function promptBlock(array $p): string
    {
        $brand = trim((string)$p['brand']);
        $lines = [];
        $lines[] = self::identityLine($p);
        $pr = trim((string)$p['prompt']);
        if ($pr !== '') $lines[] = str_replace('{brand}', $brand, $pr);
        $avoid = self::avoidList($p);
        if ($avoid) $lines[] = 'در این گفتگو هرگز این نام‌ها را نیاور (حتی اگر در خروجی ابزارها بود): ' . implode('، ', $avoid) . ($brand !== '' ? "؛ به‌جایش «{$brand}» بگو." : '.');
        switch ($p['link_mode']) {
            case 'link':
                $lines[] = 'در این گفتگو لینک مجاز است: برای هر گزینه‌ای که معرفی می‌کنی، لینک همان نتیجه (booking_url یا referral_url) را در همان خط یا خط بعدش بفرست تا مشتری عکس‌ها و جزئیات را ببیند.';
                break;
            case 'code':
                $lines[] = !empty($p['sms_links'])
                    ? 'محدودیت لینک: در این گفتگو هیچ لینکی نفرست. هر گزینه را با «کد»، اسم کوتاه، محله و قیمت معرفی کن. لینک کدهایی که معرفی می‌کنی خودکار برای موبایل مشتری پیامک می‌شود و سیستم خودش یک خط در این باره اضافه می‌کند؛ تو درباره‌ی پیامک چیزی ننویس و send_links را صدا نزن.'
                    : 'محدودیت لینک: در این گفتگو هیچ لینکی نفرست. هر گزینه را با «کد»، عنوان، منطقه و قیمت معرفی کن. اگر مشتری لینک یا عکس خواست، شماره‌ی موبایلش را بگیر و لینک‌ها را با ابزار ارسال پیامکی لینک (send_links) بفرست.';
                break;
            case 'limited':
                $n = max(0, (int)$p['link_max']);
                $lines[] = $n > 0 ? "محدودیت لینک: در هر پیام حداکثر {$n} لینک بفرست؛ بقیه‌ی گزینه‌ها را فقط با کد، عنوان و قیمت معرفی کن و اگر لینک همه را خواست، با send_links پیامک کن."
                                  : 'محدودیت لینک: لینک اقامتگاه نفرست؛ با کد معرفی کن و در صورت درخواست با send_links پیامک کن.';
                break;
        }
        $lines[] = !empty($p['allow_checkout']) ? 'لینک پرداخت (checkout_url) را می‌توانی بفرستی.' : 'لینک پرداخت را در گفتگو نفرست؛ بگو لینک پرداخت برایش پیامک شد.';
        return implode("\n", $lines);
    }

    /** نام و شیوه‌ی معرفی دستیار */
    public static function identityLine(array $p): string
    {
        $brand = trim((string)($p['brand'] ?? ''));
        $name = trim((string)($p['agent_name'] ?? ''));
        $team = $brand !== '' ? "تیم {$brand}" : 'تیم ما';
        if (($p['persona'] ?? 'ai') === 'human') {
            $who = $name !== '' ? "«{$name}» از {$team}" : "یکی از کارشناسان فروش {$team}";
            return "تو {$who} هستی" . ($name !== '' ? "؛ اگر لازم شد خودت را فقط «{$name}» معرفی کن" : '') . ". مثل یک کارشناس فروش واقعی، طبیعی و گرم بنویس و لازم نیست بگویی ربات یا هوش مصنوعی هستی."
                . ' ولی اگر مشتری صادقانه و مستقیم پرسید با ربات/هوش مصنوعی صحبت می‌کند یا انسان، دروغ نگو: بگو دستیار هوشمند ' . $team . ' هستی و اگر بخواهد، همکار انسانی هم در دسترس است (handoff_to_human).';
        }
        $label = $name !== '' ? "«{$name}»، دستیار هوشمند فروش {$team}" : ($brand !== '' ? "«دستیار فروش {$brand}»" : '«دستیار فروش»');
        return "تو {$label} هستی و وانمود نمی‌کنی انسان هستی.";
    }

    /**
     * حذف معرفی تکراری از اول پاسخ («سلام؛ دستیار فروش جاکجاست هستم.») وقتی قبلاً در گفتگو معرفی شده.
     */
    public static function stripIntro(string $text, array $p): string
    {
        $names = array_filter([trim((string)($p['agent_name'] ?? '')), trim((string)($p['brand'] ?? ''))]);
        $alt = ['دستیار\s*(?:هوشمند\s*)?(?:فروش|پشتیبانی)?'];
        foreach ($names as $n) $alt[] = preg_quote($n, '/');
        $who = '(?:' . implode('|', $alt) . ')';
        $re = '/^\s*(?:سلام[^\n.!؟?]{0,25}?[،,؛!.]?\s*)?(?:من\s+)?[^\n.!؟?]{0,25}?' . $who . '[^\n.!؟?]{0,40}?هستم\s*[.!،؛]?\s*/u';
        $out = preg_replace($re, '', $text, 1);
        $out = is_string($out) ? ltrim($out) : $text;
        return mb_strlen($out) >= 8 ? $out : $text;
    }

    /**
     * ۲.۰.۴: اگر برای دستیار «نام» تعریف شده، در معرفیِ اولین پاسخ همان نام گفته شود
     * (مدل گاهی می‌نویسد «دستیار فروش جاکجاست» و نام را نمی‌آورد).
     */
    public static function fixIntro(string $text, array $p): string
    {
        $name = trim((string)($p['agent_name'] ?? ''));
        if ($name === '' || mb_stripos($text, $name) !== false) return $text;
        $brand = trim((string)($p['brand'] ?? ''));
        $team = $brand !== '' ? "تیم {$brand}" : 'تیم ما';
        $human = ($p['persona'] ?? 'ai') === 'human';
        $self = $human ? "من {$name} از {$team} هستم" : "من {$name}، دستیار هوشمند فروش {$team} هستم";
        $re = '/من\s+(?:یک\s+)?(?:دستیار|پشتیبان|کارشناس|همکار)[^\n.!؟?]{0,50}?هستم/u';
        $out = preg_replace($re, $self, $text, 1, $n);
        if (!$n || !is_string($out)) return $text;
        return $out;
    }

    public static function avoidList(array $p): array
    {
        $l = array_values(array_filter(array_map('trim', preg_split('/[,،\n]/u', (string)($p['avoid_names'] ?? '')))));
        usort($l, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // «جاکجاست» قبل از «جاکجا»
        return $l;
    }

    /**
     * اجرای سمت سرور سیاست لینک و نام‌های ممنوع روی متن نهایی.
     * $keep: آدرس‌هایی که همیشه مجازند (لینک پرداخت)
     */
    public static function enforce(string $text, array $p, array $keep = []): string
    {
        $re = '#https?://[^\s<>"\'«»]+#u';
        $mode = (string)$p['link_mode'];
        $max = $mode === 'code' ? 0 : ($mode === 'limited' ? max(0, (int)$p['link_max']) : PHP_INT_MAX);
        $isPay = fn(string $u) => !empty($p['allow_checkout']) && (in_array($u, $keep, true) || preg_match('#/(checkout|pay|payment)(/|\?|$)#i', $u));
        if ($max !== PHP_INT_MAX) {
            $seen = 0;
            $out = [];
            foreach (preg_split("/\n/u", $text) as $line) {
                $removed = false;
                $line = preg_replace_callback($re, function ($m) use (&$seen, $max, $isPay, &$removed) {
                    $u = rtrim($m[0], '.,،؛)!؟?');
                    $tail = substr($m[0], strlen($u));
                    if ($isPay($u)) return $m[0];
                    if ($seen < $max) { $seen++; return $m[0]; }
                    $removed = true;
                    return $tail;
                }, $line);
                if ($removed) {
                    $t = trim(preg_replace('/[\p{So}\p{Sk}\x{200c}\x{200d}\x{fe0f}:：\-–—•🔗👉]+/u', ' ', $line));
                    $raw = rtrim($line);
                    if ($t === '' || (mb_strlen($t) < 30 && preg_match('/(لینک|رزرو|مشاهده|link)\s*$/u', $t)) || (mb_strlen($t) < 40 && preg_match('/[:：]\s*$/u', $raw))) continue;
                }
                $out[] = $line;
            }
            $text = implode("\n", $out);
        }
        $avoid = self::avoidList($p);
        if ($avoid) {
            $brand = trim((string)$p['brand']);
            $parts = preg_split('#(https?://[^\s<>"\'«»]+)#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            foreach ($parts as $i => $part) {
                if ($i % 2 === 1) continue; // خود لینک دست نمی‌خورد
                foreach ($avoid as $name) $part = str_replace($name, $brand, $part);
                $parts[$i] = $part;
            }
            $text = implode('', $parts);
        }
        return trim(preg_replace("/\n{3,}/u", "\n\n", self::humanize($text)));
    }

    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    /** ۱.۰.۹: برچسب‌های رباتی حذف و تاریخ‌های 1405-08-07 خوانا («۷ آبان») شوند */
    public static function humanize(string $t): string
    {
        $parts = preg_split('#(https?://[^\s<>"\'«»]+)#u', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1) continue;
            $p = preg_replace('/^[ \t]*قدم\s*(?:\x{200c})?\s*بعدی\s*[:：]\s*/mu', '', $p);
            // جمله‌های اعلام کار بعدی و راهنمای شکل جواب (حس ربات)
            $p = preg_replace('/[ \t]*[^.!؟?\n]*(?:بعد|پس)\s+از\s+(?:پاسخ|جواب|دریافت|گفتن|اینکه)[^.!؟?\n]*(?:جستجو|می‌فرستم|میفرستم|ارسال می|پیشنهاد می)[^.!؟?\n]*[.!]?/u', '', $p);
            $p = preg_replace('/[ \t]*[^.!؟?\n]*(?:جستجو می‌کنم|جستجو میکنم|می‌گردم)[^.!؟?\n]*(?:می‌فرستم|میفرستم|پیشنهاد می‌دهم)[^.!؟?\n]*[.!]?/u', '', $p);
            $p = preg_replace('/\s*\((?:جواب|پاسخ)\s*[:：][^)\n]{0,60}\)/u', '', $p);
            $p = preg_replace('/[ \t]+$/mu', '', $p);   // فاصله‌ی انتهای خط (Markdown)
            $fa = fn($n) => strtr((string)(int)$n, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
            // ۱۴۰۵-۰۸-۰۷ تا/→ ۱۴۰۵-۰۸-۰۹ (همان ماه) = «۷ تا ۹ آبان»
            $p = preg_replace_callback('/\b(1[34]\d\d)[-\/](\d{1,2})[-\/](\d{1,2})\s*(?:تا|→|->|–|-)\s*(1[34]\d\d)[-\/](\d{1,2})[-\/](\d{1,2})\b/u', function ($m) use ($fa) {
                $m1 = (int)$m[2]; $m2 = (int)$m[5];
                if ($m1 < 1 || $m1 > 12 || $m2 < 1 || $m2 > 12) return $m[0];
                return $m1 === $m2 && $m[1] === $m[4] ? $fa($m[3]) . ' تا ' . $fa($m[6]) . ' ' . self::MONTHS[$m1 - 1]
                    : $fa($m[3]) . ' ' . self::MONTHS[$m1 - 1] . ' تا ' . $fa($m[6]) . ' ' . self::MONTHS[$m2 - 1];
            }, $p);
            $p = preg_replace_callback('/\b(1[34]\d\d)[-\/](\d{1,2})[-\/](\d{1,2})\b/u', function ($m) use ($fa) {
                $mo = (int)$m[2];
                return $mo >= 1 && $mo <= 12 ? $fa($m[3]) . ' ' . self::MONTHS[$mo - 1] : $m[0];
            }, $p);
            $parts[$i] = $p;
        }
        return implode('', $parts);
    }
}
