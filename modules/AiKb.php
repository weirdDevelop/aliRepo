<?php
/**
 * modules/AiKb.php
 * پایگاه دانشِ هوش مصنوعی — منبعی که پاسخ‌های دستیار از آن تغذیه می‌شود.
 *
 * چرا جدا از «پایگاه دانش» کنسول فرانت؟ آن نسخه (elanban-console.html) فقط دموی
 * فرانت بدون backend است. این ماژول یک منبع دانشِ سبک و واقعی است که مستقیماً به
 * زمینه‌ی AI تزریق می‌شود (بدون embedding/RAG سنگین — چون پروژه composer ندارد).
 *
 * بازیابی بدون کتابخانه: امتیاز هم‌پوشانی کلیدواژه بین «آخرین پیام مشتری» و هر مدخل.
 * مدخل‌های «سنجاق‌شده» (pinned) همیشه می‌آیند (مثل سیاست‌های کلی شرکت)، بقیه فقط
 * وقتی با پیام مشتری هم‌پوشانی دارند. این ساده ولی برای اکثر کسب‌وکارها کافی است.
 *
 * تنظیمات/داده در جدول eb_ai_knowledge. گاردِ hasTable تا قبل از db_upgrade کرش نکند.
 */

class AiKb
{
    /** حداکثر تعداد مدخلی که در هر پاسخ به AI داده می‌شود (کنترل هزینه‌ی توکن) */
    const MAX_HITS = 3;
    /** حداکثر طول محتوای هر مدخل که به AI فرستاده می‌شود */
    const MAX_CHARS = 1200;

    /** کلمات پرتکرار که در امتیازدهی نادیده گرفته می‌شوند (fa + en) */
    const STOPWORDS = [
        'و','در','به','از','که','این','را','با','است','برای','یک','هم','تا','می',
        'شد','شده','های','ها','آن','یا','اگر','بود','هست','دارد','کنم','کنید','سلام',
        'the','a','an','and','or','to','of','in','is','are','for','you','i','it','my',
    ];

    // ==================== CRUD ====================

    public static function all(): array
    {
        if (!self::hasTable()) return [];
        return Database::all(
            "SELECT id, title, content, keywords, pinned, is_active, updated_at, created_at
             FROM eb_ai_knowledge ORDER BY pinned DESC, updated_at DESC, id DESC"
        );
    }

    public static function find(int $id): ?array
    {
        if (!self::hasTable()) return null;
        return Database::one("SELECT * FROM eb_ai_knowledge WHERE id = ?", [$id]);
    }

    /**
     * ذخیره/به‌روزرسانی یک مدخل. عنوان و محتوا لازم‌اند.
     * @throws InvalidArgumentException در صورت خالی‌بودن.
     */
    public static function save(array $d, ?int $id = null): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('ابتدا «به‌روزرسانی دیتابیس» را بزنید تا جدول دانش ساخته شود.');
        }
        $title    = trim((string)($d['title'] ?? ''));
        $content  = trim((string)($d['content'] ?? ''));
        $keywords = trim((string)($d['keywords'] ?? ''));
        $pinned   = !empty($d['pinned']) ? 1 : 0;
        $active   = array_key_exists('is_active', $d) ? (!empty($d['is_active']) ? 1 : 0) : 1;

        if ($title === '')   throw new InvalidArgumentException('عنوان مدخل لازم است');
        if ($content === '') throw new InvalidArgumentException('محتوای مدخل لازم است');

        $title    = mb_substr($title, 0, 255);
        $keywords = mb_substr($keywords, 0, 500);

        if ($id) {
            Database::run(
                "UPDATE eb_ai_knowledge SET title=?, content=?, keywords=?, pinned=?, is_active=? WHERE id=?",
                [$title, $content, $keywords, $pinned, $active, $id]
            );
            return $id;
        }
        Database::run(
            "INSERT INTO eb_ai_knowledge (title, content, keywords, pinned, is_active) VALUES (?, ?, ?, ?, ?)",
            [$title, $content, $keywords, $pinned, $active]
        );
        return Database::lastId();
    }

    public static function delete(int $id): void
    {
        if (!self::hasTable()) return;
        Database::run("DELETE FROM eb_ai_knowledge WHERE id = ?", [$id]);
    }

    /**
     * درون‌ریزی یک متن بلند به‌صورت چند مدخل.
     * متن با خط خالی به بلوک‌ها بریده می‌شود؛ هر بلوک یک مدخل (عنوان = خط اول).
     * فقط متن ساده (txt/md/csv). خروجی: تعداد مدخل ساخته‌شده.
     */
    public static function importText(string $text, string $sourceTitle = ''): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('ابتدا «به‌روزرسانی دیتابیس» را بزنید تا جدول دانش ساخته شود.');
        }
        // نرمال‌سازی خط جدید + حذف BOM
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // بلوک‌ها بر اساس خط خالی
        $blocks = preg_split('/\n[ \t]*\n+/', trim($text));
        $count = 0;
        foreach ($blocks as $block) {
            $block = trim($block);
            if (mb_strlen($block) < 20) continue;   // بلوک‌های خیلی کوتاه را رد کن
            // عنوان: خط اول (کوتاه‌شده)، محتوا: کل بلوک
            $firstLine = trim(strtok($block, "\n"));
            $title = mb_substr($firstLine !== '' ? $firstLine : $block, 0, 80);
            if ($sourceTitle !== '') $title = mb_substr($sourceTitle . ' — ' . $title, 0, 255);
            Database::run(
                "INSERT INTO eb_ai_knowledge (title, content, keywords, pinned, is_active) VALUES (?, ?, '', 0, 1)",
                [$title, mb_substr($block, 0, 20000)]
            );
            $count++;
            if ($count >= 200) break;   // سقف ایمنی برای هر درون‌ریزی
        }
        return $count;
    }

    // ==================== بازیابی برای AI ====================

    /**
     * مرتبط‌ترین مدخل‌ها به یک پرس‌وجو (معمولاً آخرین پیام مشتری).
     * pinnedها همیشه (تا نصف سقف)، بقیه بر اساس امتیاز هم‌پوشانی.
     * @return array<int,array{title:string,content:string}>
     */
    public static function search(string $query, int $limit = self::MAX_HITS): array
    {
        if (!self::hasTable()) return [];
        try {
            $rows = Database::all(
                "SELECT id, title, content, keywords, pinned FROM eb_ai_knowledge WHERE is_active = 1"
            );
        } catch (Throwable $e) { return []; }
        if (!$rows) return [];

        $qTokens = self::tokenize($query);

        $pinned = [];
        $scored = [];
        foreach ($rows as $r) {
            if (!empty($r['pinned'])) { $pinned[] = $r; continue; }
            if (!$qTokens) continue; // بدون پرس‌وجو، فقط pinnedها
            $score = self::score($r, $qTokens);
            if ($score > 0) $scored[] = ['score' => $score, 'row' => $r];
        }

        // مرتب‌سازی مطابق امتیاز (نزولی)
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $out = [];
        // pinnedها اول (تا نصف سقف، حداقل ۱)
        $pinnedCap = max(1, (int)floor($limit / 2));
        foreach (array_slice($pinned, 0, $pinnedCap) as $r) {
            $out[] = self::shape($r);
        }
        foreach ($scored as $s) {
            if (count($out) >= $limit) break;
            $out[] = self::shape($s['row']);
        }
        // اگر جا ماند و pinned بیشتری بود، پرش کن
        if (count($out) < $limit) {
            foreach (array_slice($pinned, $pinnedCap) as $r) {
                if (count($out) >= $limit) break;
                $out[] = self::shape($r);
            }
        }
        return $out;
    }

    /**
     * بلوک متنیِ آماده برای تزریق به system prompt. خالی اگر چیزی مرتبط نبود.
     */
    public static function contextBlock(string $query): string
    {
        $hits = self::search($query);
        if (!$hits) return '';
        $lines = ['دانش سازمان (برای پاسخ استفاده کن؛ اگر ربطی نداشت نادیده بگیر):'];
        foreach ($hits as $h) {
            $lines[] = '• ' . $h['title'] . ': ' . $h['content'];
        }
        return implode("\n", $lines);
    }

    // ==================== کمک‌کننده‌ها ====================

    private static function shape(array $r): array
    {
        return [
            'title'   => (string)($r['title'] ?? ''),
            'content' => mb_substr((string)($r['content'] ?? ''), 0, self::MAX_CHARS),
        ];
    }

    /** امتیاز هم‌پوشانی؛ عنوان و کلیدواژه‌ها وزن بیشتری دارند. */
    private static function score(array $r, array $qTokens): int
    {
        $bodyTokens  = self::tokenize((string)($r['content'] ?? ''));
        $titleTokens = self::tokenize((string)($r['title'] ?? '') . ' ' . (string)($r['keywords'] ?? ''));
        if (!$bodyTokens && !$titleTokens) return 0;

        $bodySet  = array_flip($bodyTokens);
        $titleSet = array_flip($titleTokens);

        $score = 0;
        foreach (array_unique($qTokens) as $t) {
            if (isset($titleSet[$t])) $score += 3;   // تطابق در عنوان/کلیدواژه مهم‌تر
            elseif (isset($bodySet[$t])) $score += 1;
        }
        return $score;
    }

    /** متن → آرایه‌ای از توکن‌های معنادار (بدون علائم، بدون کلمات پرتکرار کوتاه). */
    private static function tokenize(?string $text): array
    {
        if ($text === null || $text === '') return [];
        $text = mb_strtolower($text, 'UTF-8');
        // هر چیزی جز حرف/رقم (فارسی و لاتین) → فاصله
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', trim($text));
        $stop = array_flip(self::STOPWORDS);
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || mb_strlen($p) < 2) continue;
            if (isset($stop[$p])) continue;
            $out[] = $p;
        }
        return $out;
    }

    // ---- گارد جدول (تا قبل از db_upgrade کرش نکنیم) ----
    /** @var bool|null */
    private static $hasTable = null;
    public static function hasTable(): bool
    {
        if (self::$hasTable !== null) return self::$hasTable;
        try {
            self::$hasTable = Database::hasTable('eb_ai_knowledge');
        } catch (Throwable $e) { self::$hasTable = false; }
        return self::$hasTable;
    }
}
