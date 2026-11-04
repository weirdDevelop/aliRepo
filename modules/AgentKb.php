<?php
/**
 * modules/AgentKb.php — پایگاه دانش دستیار فروش (RAG)
 * ==========================================================================
 * فایل‌های Markdown یا متنی آپلود می‌شوند، بر اساس سرفصل‌ها (#، ##، ###) به «بخش» تقسیم و
 * برای هر پیام مشتری، مرتبط‌ترین بخش‌ها با امتیاز BM25 (بدون نیاز به سرویس embedding) پیدا و
 * به دستور سیستم اضافه می‌شوند. بخش‌های «سنجاق‌شده» (مثل باید و نباید، موارد ارجاع به انسان)
 * همیشه همراه دستیارند.
 *
 * دامنه: سند عمومی (profile_id خالی) برای همه‌ی پروفایل‌ها، سند خصوصی فقط برای همان پروفایل.
 * هر پروفایل انتخاب می‌کند: عمومی + خصوصی، فقط عمومی، فقط خصوصی، یا بدون پایگاه دانش.
 */
class AgentKb
{
    const CHUNK = 1400;        // حداکثر طول هر بخش
    const BUDGET = 3600;       // سقف کاراکتر بخش‌های بازیابی‌شده در هر پاسخ
    const PIN_BUDGET = 3200;   // سقف کاراکتر بخش‌های سنجاق‌شده
    const TOP = 4;
    const STOP = ['و','در','به','از','که','این','آن','را','رو','با','است','هست','برای','یک','هم','تا','می','شد','شده','ها','های','یا','اگر','بود',
                  'دارد','دارید','داره','کنم','کنید','کنه','سلام','من','شما','ما','او','چه','چی','چند','کی','آیا','باشه','باشد','نه','بله','ولی','اما','پس',
                  'خیلی','همه','هر','دیگه','دیگر','بعد','قبل','روی','زیر','اون','اینه','the','a','an','and','or','to','of','in','is','for','you','i','it'];

    /**
     * ۱.۰.۱۷: دامنه‌ی گفتگوی جاری برای بازیابی — کانال (webchat، instagram = دایرکت، ig_comment = کامنت، telegram…)
     * و پست اینستاگرام (media_id). سند بدون کانال/پست برای همه است؛ سند کانال‌دار فقط همان کانال؛ سند پست‌دار فقط همان پست.
     */
    public static $scope = ['channel' => '', 'media_id' => ''];
    const CHANNELS = ['webchat' => 'گفتگوی سایت', 'instagram' => 'دایرکت اینستاگرام', 'ig_comment' => 'کامنت اینستاگرام', 'telegram' => 'تلگرام', 'bale' => 'بله', 'roubika' => 'روبیکا', 'sms' => 'پیامک'];

    public static function ensureScope(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            ApiConnectors::ensure();
            if (!Database::hasColumn('eb_agent_kb_docs', 'channel')) {
                Database::pdo()->exec("ALTER TABLE eb_agent_kb_docs ADD COLUMN channel VARCHAR(30) DEFAULT NULL, ADD COLUMN media_id VARCHAR(64) DEFAULT NULL, ADD KEY idx_scope (channel, media_id)");
                Database::forgetSchemaCache();
            }
            return $ok = Database::hasColumn('eb_agent_kb_docs', 'channel');
        } catch (Throwable $e) { error_log('[AgentKb scope] ' . $e->getMessage()); return $ok = false; }
    }

    /** کانال گفتگو → کلید دامنه‌ی پایگاه دانش */
    public static function channelKey(string $channel, bool $comment = false): string
    {
        if ($comment) return 'ig_comment';
        if (strpos($channel, 'instagram') === 0) return 'instagram';
        if ($channel === 'bale_safir') return 'bale';
        return $channel;
    }

    /* ======================= نرمال‌سازی ======================= */

    public static function norm(string $t): string
    {
        $t = strtr($t, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ؤ' => 'و', 'ئ' => 'ی',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            "\u{200c}" => ' ', "\u{200d}" => '']);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);
        $t = mb_strtolower($t);
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t));
    }

    public static function tokens(string $t): array
    {
        static $stop = null;
        if ($stop === null) { $stop = []; foreach (self::STOP as $w) $stop[self::norm($w)] = true; }
        $out = [];
        foreach (explode(' ', self::norm($t)) as $w) {
            if ($w === '' || mb_strlen($w) < 2 || isset($stop[$w])) continue;
            // ریشه‌یابی سبک: جمع و پسوندهای رایج
            foreach (['هایی', 'های', 'ها', 'ترین', 'تر', 'ان'] as $suf) {
                if (mb_strlen($w) > mb_strlen($suf) + 2 && mb_substr($w, -mb_strlen($suf)) === $suf) { $w = mb_substr($w, 0, -mb_strlen($suf)); break; }
            }
            $out[] = $w;
        }
        return $out;
    }

    /* ======================= ورود سند ======================= */

    /** @return array{id:int, chunks:int} */
    public static function import(string $title, string $text, ?int $profileId, string $filename = '', ?string $channel = null, ?string $mediaId = null): array
    {
        ApiConnectors::ensure();
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/^\x{FEFF}/u', '', $text);
        if (trim($text) === '') throw new InvalidArgumentException('فایل خالی است');
        $title = trim($title) !== '' ? trim($title) : (preg_match('/^#\s+(.+)$/mu', $text, $m) ? trim($m[1]) : 'سند بی‌نام');
        $chunks = self::chunk($text);
        if (!$chunks) throw new InvalidArgumentException('متنی برای ذخیره پیدا نشد');
        Database::run("INSERT INTO eb_agent_kb_docs (profile_id, title, filename, chars, chunks) VALUES (?,?,?,?,?)",
            [$profileId ?: null, mb_substr($title, 0, 200), mb_substr($filename, 0, 200) ?: null, mb_strlen($text), count($chunks)]);
        $docId = Database::lastId();
        if (($channel || $mediaId) && self::ensureScope()) self::setScope($docId, $channel, $mediaId);
        foreach ($chunks as $c) {
            $pin = preg_match('/(باید|نباید|ارجاع|قواعد|قوانین کلیدی|خط قرمز|ممنوع)/u', $c['heading']) ? 1 : 0;
            $terms = implode(' ', array_merge(self::tokens($c['heading']), self::tokens($c['heading']), self::tokens($c['content'])));
            Database::run("INSERT INTO eb_agent_kb_chunks (doc_id, heading, content, terms, len, pinned) VALUES (?,?,?,?,?,?)",
                [$docId, mb_substr($c['heading'], 0, 400), $c['content'], $terms, mb_strlen($c['content']), $pin]);
        }
        return ['id' => $docId, 'chunks' => count($chunks)];
    }

    /** تقسیم Markdown بر اساس سرفصل‌ها؛ بخش‌های بلند در مرز پاراگراف/سطر جدول شکسته می‌شوند */
    public static function chunk(string $text): array
    {
        $stack = [];
        $cur = ['heading' => '', 'lines' => []];
        $sections = [];
        $flush = function () use (&$cur, &$sections) {
            $body = trim(implode("\n", $cur['lines']));
            if ($body !== '' && preg_replace('/[\s\-—|>*_]+/u', '', $body) !== '') $sections[] = ['heading' => $cur['heading'], 'content' => $body];
            $cur['lines'] = [];
        };
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(#{1,4})\s+(.+?)\s*#*\s*$/u', $line, $m)) {
                $flush();
                $lvl = strlen($m[1]);
                $stack = array_slice($stack, 0, $lvl - 1);
                $stack[$lvl - 1] = trim($m[2]);
                // سرفصل سطح ۱ (عنوان سند) در مسیر بخش‌ها تکرار نمی‌شود
                $cur['heading'] = implode(' › ', array_filter(array_slice($stack, 1, null, true)));
                if ($cur['heading'] === '' && $lvl > 1) $cur['heading'] = trim($m[2]);
                continue;
            }
            if (preg_match('/^\s*---+\s*$/', $line)) continue;
            $cur['lines'][] = $line;
        }
        $flush();
        $out = [];
        foreach ($sections as $s) {
            if (mb_strlen($s['content']) <= self::CHUNK) { $out[] = $s; continue; }
            $buf = '';
            foreach (preg_split("/\n(?=\n|\||\d+\.|- |\* )/u", $s['content']) as $piece) {
                if ($buf !== '' && mb_strlen($buf) + mb_strlen($piece) > self::CHUNK) { $out[] = ['heading' => $s['heading'], 'content' => trim($buf)]; $buf = ''; }
                $buf .= ($buf === '' ? '' : "\n") . $piece;
                while (mb_strlen($buf) > self::CHUNK * 1.5) { $out[] = ['heading' => $s['heading'], 'content' => trim(mb_substr($buf, 0, self::CHUNK))]; $buf = mb_substr($buf, self::CHUNK); }
            }
            if (trim($buf) !== '') $out[] = ['heading' => $s['heading'], 'content' => trim($buf)];
        }
        return $out;
    }

    /* ======================= بازیابی ======================= */

    private static function scopeSql(array $profile): ?array
    {
        $pid = (int)($profile['id'] ?? 0);
        switch ($profile['kb_mode'] ?? 'both') {
            case 'none': return null;
            case 'general': return ['d.profile_id IS NULL', []];
            case 'own': return $pid ? ['d.profile_id = ?', [$pid]] : null;
            default: return $pid ? ['(d.profile_id IS NULL OR d.profile_id = ?)', [$pid]] : ['d.profile_id IS NULL', []];
        }
    }

    /** @return array{pinned:array, hits:array} */
    public static function search(string $query, array $profile, int $top = self::TOP): array
    {
        ApiConnectors::ensure();
        $sc = self::scopeSql($profile);
        if (!$sc) return ['pinned' => [], 'hits' => []];
        // ۱.۰.۱۷: سندهای مخصوص کانال یا پست، فقط در همان کانال/پست
        $extra = ''; $xp = [];
        if (self::ensureScope()) {
            $ch = (string)(self::$scope['channel'] ?? ''); $md = (string)(self::$scope['media_id'] ?? '');
            $extra = " AND (d.channel IS NULL OR d.channel = ?) AND (d.media_id IS NULL OR d.media_id = ?)";
            $xp = [$ch, $md];
        }
        $rows = Database::all("SELECT c.id, c.heading, c.content, c.terms, c.len, c.pinned, d.title" . ($extra ? ", d.media_id doc_media" : "") . " FROM eb_agent_kb_chunks c JOIN eb_agent_kb_docs d ON d.id = c.doc_id
                               WHERE d.is_active = 1 AND {$sc[0]}{$extra}", array_merge($sc[1], $xp));
        // بخش‌های سند همین پست همیشه همراه‌اند (کوتاه‌ترها اول)
        if ($extra) foreach ($rows as &$rw) if (!empty($rw['doc_media'])) $rw['pinned'] = 1;
        unset($rw);
        if (!$rows) return ['pinned' => [], 'hits' => []];
        $pinned = []; $used = 0;
        foreach ($rows as $r) if ((int)$r['pinned'] && $used + (int)$r['len'] <= self::PIN_BUDGET) { $pinned[] = $r; $used += (int)$r['len']; }
        $q = array_unique(self::tokens($query));
        if (!$q) return ['pinned' => $pinned, 'hits' => []];
        // BM25
        $N = count($rows); $df = []; $docs = []; $avg = 0;
        foreach ($rows as $i => $r) {
            $tf = array_count_values(explode(' ', (string)$r['terms']));
            $docs[$i] = $tf; $avg += array_sum($tf);
            foreach ($q as $t) if (isset($tf[$t]) || self::prefixHit($tf, $t)) $df[$t] = ($df[$t] ?? 0) + 1;
        }
        $avg = max(1, $avg / $N);
        $k1 = 1.4; $b = 0.75; $scores = [];
        foreach ($docs as $i => $tf) {
            if ((int)$rows[$i]['pinned']) continue;
            $len = array_sum($tf); $s = 0.0;
            foreach ($q as $t) {
                $f = $tf[$t] ?? self::prefixHit($tf, $t) * 0.6;
                if (!$f) continue;
                $idf = log(1 + ($N - ($df[$t] ?? 0) + 0.5) / (($df[$t] ?? 0) + 0.5));
                $s += $idf * ($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * $len / $avg));
            }
            if ($s > 0.4) $scores[$i] = $s;
        }
        arsort($scores);
        $hits = []; $used = 0;
        foreach (array_slice(array_keys($scores), 0, $top * 2, true) as $i) {
            if (count($hits) >= $top) break;
            if ($used + (int)$rows[$i]['len'] > self::BUDGET && $hits) continue;
            $hits[] = $rows[$i] + ['score' => round($scores[$i], 2)]; $used += (int)$rows[$i]['len'];
        }
        return ['pinned' => $pinned, 'hits' => $hits];
    }

    /** تطبیق پیشوندی برای کلمات چسبیده (مثلاً «سوئیتش» ← «سوئیت») */
    private static function prefixHit(array $tf, string $t): int
    {
        if (mb_strlen($t) < 4) return 0;
        $n = 0;
        foreach ($tf as $w => $c) if (strlen($w) > strlen($t) && strpos($w, $t) === 0 && mb_strlen($w) - mb_strlen($t) <= 2) $n += $c;
        return $n;
    }

    public static function contextBlock(string $query, array $profile): string
    {
        $r = self::search($query, $profile);
        $post = self::postBlock();
        if (!$r['pinned'] && !$r['hits']) return $post;
        $brand = trim((string)($profile['brand'] ?? ''));
        $fmt = fn($c) => '### ' . ($c['heading'] ?: $c['title']) . "\n" . $c['content'];
        $out = "پایگاه دانش" . ($brand !== '' ? " {$brand}" : '') . " (راهنمای رفتار و پاسخ‌ها؛ قیمت، موجودی و قوانین هر واحد را فقط از خروجی ابزارها بگو. علامت ⚠️ یعنی نامطمئن؛ به‌عنوان قطعی نگو):";
        if ($r['pinned']) $out .= "\n\n[همیشه رعایت کن]\n" . implode("\n\n", array_map($fmt, $r['pinned']));
        if ($r['hits']) $out .= "\n\n[مرتبط با پیام مشتری]\n" . implode("\n\n", array_map($fmt, $r['hits']));
        return $out . ($post !== '' ? "\n\n" . $post : '');
    }

    /** ۱.۰.۱۷: «دانش پست» (متن ثبت‌شده برای همان پست در اینستاگرام ← دانش هر پست) */
    public static function postBlock(): string
    {
        $md = (string)(self::$scope['media_id'] ?? '');
        if ($md === '' || !class_exists('IgCommentAutomation')) return '';
        try { $k = IgCommentAutomation::getPostKnowledge($md); } catch (Throwable $e) { return ''; }
        $t = trim((string)($k['knowledge_text'] ?? ''));
        return $t === '' ? '' : 'دانش همین پست اینستاگرام' . (!empty($k['title']) ? ' («' . $k['title'] . '»)' : '') . " (مشتری درباره‌ی این پست پیام داده؛ اول بر اساس همین جواب بده):\n" . mb_substr($t, 0, 3000);
    }

    /* ======================= مدیریت ======================= */

    public static function docs(): array
    {
        ApiConnectors::ensure();
        return Database::all("SELECT d.*, p.name profile_name, (SELECT COUNT(*) FROM eb_agent_kb_chunks c WHERE c.doc_id = d.id AND c.pinned = 1) pinned
                              FROM eb_agent_kb_docs d LEFT JOIN eb_agent_profiles p ON p.id = d.profile_id ORDER BY d.id DESC");
    }

    public static function chunks(int $docId): array
    {
        return Database::all("SELECT id, heading, content, len, pinned FROM eb_agent_kb_chunks WHERE doc_id = ? ORDER BY id", [$docId]);
    }

    public static function deleteDoc(int $id): void
    {
        Database::run("DELETE FROM eb_agent_kb_chunks WHERE doc_id = ?", [$id]);
        Database::run("DELETE FROM eb_agent_kb_docs WHERE id = ?", [$id]);
    }

    public static function setScope(int $docId, ?string $channel, ?string $mediaId): void
    {
        $channel = $channel !== null && array_key_exists($channel, self::CHANNELS) ? $channel : null;
        $mediaId = $mediaId !== null ? (preg_replace('/[^A-Za-z0-9_\-]/', '', trim($mediaId)) ?: null) : null;
        Database::run("UPDATE eb_agent_kb_docs SET channel = ?, media_id = ? WHERE id = ?", [$channel, $mediaId ? mb_substr($mediaId, 0, 64) : null, $docId]);
    }

    public static function updateDoc(int $id, array $in): void
    {
        if ((array_key_exists('channel', $in) || array_key_exists('media_id', $in)) && self::ensureScope()) {
            $cur = Database::one("SELECT channel, media_id FROM eb_agent_kb_docs WHERE id = ?", [$id]) ?: [];
            self::setScope($id, array_key_exists('channel', $in) ? ((string)$in['channel'] ?: null) : ($cur['channel'] ?? null),
                array_key_exists('media_id', $in) ? ((string)$in['media_id'] ?: null) : ($cur['media_id'] ?? null));
        }
        if (array_key_exists('is_active', $in)) Database::run("UPDATE eb_agent_kb_docs SET is_active = ? WHERE id = ?", [!empty($in['is_active']) ? 1 : 0, $id]);
        if (array_key_exists('profile_id', $in)) Database::run("UPDATE eb_agent_kb_docs SET profile_id = ? WHERE id = ?", [(int)$in['profile_id'] ?: null, $id]);
        if (array_key_exists('title', $in) && trim((string)$in['title']) !== '') Database::run("UPDATE eb_agent_kb_docs SET title = ? WHERE id = ?", [mb_substr(trim((string)$in['title']), 0, 200), $id]);
    }

    public static function pin(int $chunkId, bool $on): void
    {
        Database::run("UPDATE eb_agent_kb_chunks SET pinned = ? WHERE id = ?", [$on ? 1 : 0, $chunkId]);
    }
}
