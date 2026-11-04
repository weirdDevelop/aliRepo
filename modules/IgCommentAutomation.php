<?php
/**
 * modules/IgCommentAutomation.php
 * اتوماسیون «کامنت کن X تا برات بفرستم» با گزینه‌ی فالو اجباری — سبک ManyChat/دایرکتم.
 *
 * جریان کار (بدون فالو اجباری):
 *   کامنت با کلمه‌ی X → (اختیاری) ریپلای عمومی زیر همان کامنت → دایرکت خصوصی با محتوای اصلی.
 *
 * جریان کار (با فالو اجباری):
 *   کامنت با کلمه‌ی X → ریپلای عمومی (اختیاری) → دایرکت «اول فالو کن، بعد بنویس <عبارت>»
 *   → یک ردیف eb_ig_gate_pending ساخته می‌شود → وقتی کاربر همان عبارت را در دایرکت تایپ کرد
 *   → followStatus() چک می‌شود → اگر فالو کرده: محتوای اصلی ارسال می‌شود، وگرنه یادآوری.
 *
 * ⚠️ محدودیت شناخته‌شده و آگاهانه: تشخیص «فالو کردم» با دکمه (postback) انجام نمی‌شود، چون
 * فرمت تحویل کلیک دکمه در وب‌هوک دایرکت‌ساز مستند/مشاهده‌نشده است (درست مثل رویداد کامنت که
 * تا قبل از دیدن یک نمونه‌ی واقعی حدس نزدیم). به‌جایش از عبارت متنی استفاده می‌شود، چون این
 * مسیر (دریافت متن دایرکت) کاملاً تأییدشده و پایدار است.
 */

class IgCommentAutomation
{
    /** @var bool|null کش: آیا ستون ig_account_id روی eb_ig_comment_rules هست؟ */
    private static $hasAccountScope = null;
    private static function hasAccountScope(): bool
    {
        if (self::$hasAccountScope !== null) return self::$hasAccountScope;
        try {
            self::$hasAccountScope = Database::hasColumn('eb_ig_comment_rules', 'ig_account_id');
        } catch (Throwable $e) { self::$hasAccountScope = false; }
        return self::$hasAccountScope;
    }

    // ==================== CRUD قوانین ====================

    // ==================== ثبت پیام‌های خودکار در تاریخچه‌ی گفتگو ====================
    /**
     * زمینه‌ی کامنت در حال پردازش (پایپ‌لاین قبل از matchAndRun تنظیم می‌کند).
     * تا وقتی بات واقعاً به کامنت‌گذار پیامی نفرستاده، هیچ پروفایل یا گفتگویی برایش ساخته نمی‌شود
     * (کامنت‌های عادی صندوق ورودی را شلوغ نمی‌کنند)؛ اولین ارسال خودکار، پروفایل را می‌سازد و
     * خود کامنت را هم به‌عنوان پیام ورودی در گفتگو می‌گذارد تا پشتیبان بفهمد پیام بات جواب چه بود.
     * @var array|null {uid, account_id, username, name, text, media_id, logged}
     */
    private static $commentCtx = null;
    public static function setCommentContext(?array $ctx): void { self::$commentCtx = $ctx ? $ctx + ['logged' => false] : null; }

    /**
     * ثبت یک پیام خودکار خروجی اینستاگرام در eb_messages (همان جایی که صندوق ورودی می‌خواند).
     * $source: comment_dm | comment_reply | follow_gate | phone_gate | ai_comment
     * هیچ خطایی نباید ارسال را خراب کند.
     */
    public static function logOut(string $channelUid, array $message, string $source, $result = null): void
    {
        if ($channelUid === '' || !class_exists('BotReply')) return;
        try {
            $ok = is_array($result) ? !empty($result['ok']) : ($result === null ? true : (bool)$result);
            $err = is_array($result) && empty($result['ok']) ? (string)($result['error'] ?? 'send_failed') : null;
            $ctx = self::$commentCtx;
            $identity = Database::one("SELECT id FROM eb_identities WHERE channel = 'instagram' AND channel_uid = ? LIMIT 1", [$channelUid]);
            if (!$identity && $ctx && $ctx['uid'] === $channelUid && class_exists('Persons')) {
                Persons::ingestIdentity('instagram', ['channel_uid' => $channelUid, 'username' => $ctx['username'] ?? null, 'first_name' => $ctx['name'] ?? null]);
                if (!empty($ctx['account_id'])) {
                    Database::run("UPDATE eb_identities SET ig_account_id = ? WHERE channel = 'instagram' AND channel_uid = ? AND ig_account_id IS NULL", [(int)$ctx['account_id'], $channelUid]);
                }
                $identity = Database::one("SELECT id FROM eb_identities WHERE channel = 'instagram' AND channel_uid = ? LIMIT 1", [$channelUid]);
            }
            if (!$identity) return;
            // خود کامنت، یک‌بار، قبل از اولین پاسخ خودکار
            if ($ctx && $ctx['uid'] === $channelUid && empty($ctx['logged']) && trim((string)$ctx['text']) !== '') {
                try {
                    Database::run(
                        "INSERT INTO eb_messages (identity_id, channel, direction, body, status, media_type, media_path) VALUES (?, 'instagram', 'in', ?, 'received', 'ig_comment', ?)",
                        [$identity['id'], '💬 کامنت زیر پست: ' . $ctx['text'], $ctx['media_id'] ?: null]
                    );
                } catch (Throwable $e) {
                    Database::run("INSERT INTO eb_messages (identity_id, channel, direction, body, status) VALUES (?, 'instagram', 'in', ?, 'received')", [$identity['id'], '💬 کامنت زیر پست: ' . $ctx['text']]);
                }
                self::$commentCtx['logged'] = true;
            }
            if ($source === 'comment_reply') $message['text'] = '💬 پاسخ عمومی زیر کامنت: ' . (string)($message['text'] ?? '');
            BotReply::log('instagram', $channelUid, $message, $source, $ok, $err);
        } catch (Throwable $e) { /* ثبت تاریخچه هرگز نباید ارسال را بترکاند */ }
    }

    public static function all(): array
    {
        return Database::all("SELECT * FROM eb_ig_comment_rules ORDER BY id DESC");
    }

    public static function find(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_ig_comment_rules WHERE id = ?", [$id]);
    }

    public static function save(array $r): int
    {
        $id = (int)($r['id'] ?? 0);
        $accId = isset($r['ig_account_id']) && $r['ig_account_id'] !== '' ? (int)$r['ig_account_id'] : null;
        $vals = [
            trim((string)($r['name'] ?? '')) ?: 'بدون‌نام',
            trim((string)($r['media_id'] ?? '')) ?: null,
            trim((string)($r['keyword'] ?? '')) ?: null,
            in_array($r['match_mode'] ?? 'contains', ['contains', 'exact'], true) ? ($r['match_mode'] ?? 'contains') : 'contains',
            (string)($r['public_reply_text'] ?? ''),
            !empty($r['require_follow']) ? 1 : 0,
            (string)($r['follow_prompt_text'] ?? ''),
            trim((string)($r['confirm_phrase'] ?? '')) ?: 'فالو کردم',
            (string)($r['not_yet_text'] ?? ''),
            (string)($r['dm_text'] ?? ''),
            !empty($r['is_active']) ? 1 : 0,
        ];
        $extraCols = ''; $extraQs = '';
        if (self::hasAccountScope()) { $vals[] = $accId; }
        if (self::hasConditionColumns()) {
            $vals[] = trim((string)($r['exclude_keywords'] ?? '')) ?: null;
            $vals[] = !empty($r['once_per_user']) ? 1 : 0;
        }
        if (self::hasTriggerColumns()) {
            $vals[] = !empty($r['trigger_comment']) ? 1 : 0;
            $vals[] = !empty($r['trigger_story_reply']) ? 1 : 0;
            $vals[] = trim((string)($r['story_id'] ?? '')) ?: null;
            $vals[] = trim((string)($r['start_at'] ?? '')) ?: null;
            $vals[] = trim((string)($r['end_at'] ?? '')) ?: null;
        }
        if ($id > 0) {
            $sql = "UPDATE eb_ig_comment_rules SET name=?, media_id=?, keyword=?, match_mode=?, public_reply_text=?,
                 require_follow=?, follow_prompt_text=?, confirm_phrase=?, not_yet_text=?, dm_text=?, is_active=?"
                 . (self::hasAccountScope() ? ", ig_account_id=?" : "")
                 . (self::hasConditionColumns() ? ", exclude_keywords=?, once_per_user=?" : "")
                 . (self::hasTriggerColumns() ? ", trigger_comment=?, trigger_story_reply=?, story_id=?, start_at=?, end_at=?" : "")
                 . " WHERE id=?";
            Database::run($sql, array_merge($vals, [$id]));
            return $id;
        }
        $cols = "(name, media_id, keyword, match_mode, public_reply_text, require_follow, follow_prompt_text,
              confirm_phrase, not_yet_text, dm_text, is_active"
              . (self::hasAccountScope() ? ", ig_account_id" : "")
              . (self::hasConditionColumns() ? ", exclude_keywords, once_per_user" : "")
              . (self::hasTriggerColumns() ? ", trigger_comment, trigger_story_reply, story_id, start_at, end_at" : "") . ")";
        $placeholders = count($vals);
        $qs = "(" . implode(',', array_fill(0, $placeholders, '?')) . ")";
        Database::run("INSERT INTO eb_ig_comment_rules $cols VALUES $qs", $vals);
        return (int)Database::lastId();
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_ig_comment_rules WHERE id = ?", [$id]);
    }

    public static function toggle(int $id, bool $active): void
    {
        Database::run("UPDATE eb_ig_comment_rules SET is_active = ? WHERE id = ?", [$active ? 1 : 0, $id]);
    }

    // ==================== اجرا ====================

    /**
     * اولین قاعده‌ی فعالی که با این کامنت جور دربیاید را پیدا و اجرا می‌کند.
     * اگر هیچ قاعده‌ی ثابتی مچ نشد ولی برای این پست «دانش» ثبت شده، به AI فالبک می‌کند
     * (مورد ۱ درخواست: کلمه‌ی ثابت → پاسخ از پیش‌نوشته؛ هرچیز دیگر → پاسخ هوشمند بر
     * اساس دانشِ همان پست، با رعایت «موضوعات ممنوعه» مثل قیمت — مورد ۲).
     * @return bool آیا قاعده‌ای اجرا شد؟
     */
    public static function matchAndRun($driver, array $event, ?int $igAccountId = null): bool
    {
        $mediaId = (string)($event['media_id'] ?? '');
        $text    = mb_strtolower(trim((string)($event['text'] ?? '')));
        $senderId = (string)($event['channel_uid'] ?? '');
        $rules = self::activeRules($igAccountId);

        foreach ($rules as $rule) {
            if (!self::ruleMatches($rule, 'comment', $mediaId, $text, $senderId)) continue;
            self::run($driver, $rule, $event);
            Database::run("UPDATE eb_ig_comment_rules SET hit_count = hit_count + 1 WHERE id = ?", [$rule['id']]);
            if (self::hasConditionColumns() && !empty($rule['once_per_user']) && $senderId !== '') {
                try { Database::run("INSERT INTO eb_ig_rule_fires (rule_id, sender_id) VALUES (?, ?)", [$rule['id'], $senderId]); }
                catch (Throwable $e) {}
            }
            return true;
        }

        // ۱.۰.۱۷: دستیار فروش کامنت را مثل گفتگوی سایت جواب می‌دهد (پاسخ عمومی کوتاه + دایرکت کامل)، با دانش همان پست و کانال
        if (class_exists('SalesAgent')) {
            try { if (SalesAgent::handleComment($driver, $event, $igAccountId)) return true; }
            catch (Throwable $e) { error_log('[SalesAgent comment] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
        }
        return self::aiFallback($driver, $event);
    }

    /**
     * ۱.۰.۱۷: پروفایل و گفتگوی کامنت‌گذار (برای پاسخ دستیار): هویت را می‌سازد/پیدا می‌کند و خود کامنت را یک بار
     * به‌عنوان پیام ورودی در گفتگو ثبت می‌کند تا دستیار و پشتیبان بدانند جواب چه بود.
     * @return array{0:int,1:int}|null [identity_id, person_id]
     */
    public static function ensureCommenter(string $uid, string $text, string $mediaId, ?int $igAccountId = null): ?array
    {
        if ($uid === '' || !class_exists('Persons')) return null;
        $ctx = self::$commentCtx;
        $identity = Database::one("SELECT id, person_id FROM eb_identities WHERE channel = 'instagram' AND channel_uid = ? LIMIT 1", [$uid]);
        if (!$identity) {
            Persons::ingestIdentity('instagram', ['channel_uid' => $uid, 'username' => $ctx['username'] ?? null, 'first_name' => $ctx['name'] ?? null]);
            $identity = Database::one("SELECT id, person_id FROM eb_identities WHERE channel = 'instagram' AND channel_uid = ? LIMIT 1", [$uid]);
        }
        if (!$identity) return null;
        $acc = $igAccountId ?: (int)($ctx['account_id'] ?? 0);
        if ($acc) { try { Database::run("UPDATE eb_identities SET ig_account_id = ? WHERE id = ? AND ig_account_id IS NULL", [$acc, $identity['id']]); } catch (Throwable $e) {} }
        if (!($ctx && $ctx['uid'] === $uid && !empty($ctx['logged'])) && trim($text) !== '') {
            try {
                Database::run("INSERT INTO eb_messages (identity_id, channel, direction, body, status, media_type, media_path) VALUES (?, 'instagram', 'in', ?, 'received', 'ig_comment', ?)",
                    [$identity['id'], '💬 کامنت زیر پست: ' . $text, $mediaId !== '' ? $mediaId : null]);
            } catch (Throwable $e) {
                try { Database::run("INSERT INTO eb_messages (identity_id, channel, direction, body, status) VALUES (?, 'instagram', 'in', ?, 'received')", [$identity['id'], '💬 کامنت زیر پست: ' . $text]); } catch (Throwable $e2) {}
            }
            if ($ctx && $ctx['uid'] === $uid) self::$commentCtx['logged'] = true;
            else self::$commentCtx = ['uid' => $uid, 'account_id' => $acc, 'text' => $text, 'media_id' => $mediaId, 'logged' => true];
        }
        try { Database::run("UPDATE eb_ig_comments SET person_id = COALESCE(person_id, ?) WHERE sender_id = ? OR sender_id = ?", [(int)$identity['person_id'], $uid, preg_replace('/^rc\d+_/', '', $uid)]); } catch (Throwable $e) {}
        if (class_exists('ConvIndex')) { try { ConvIndex::touch((int)$identity['person_id']); } catch (Throwable $e) {} }
        return [(int)$identity['id'], (int)$identity['person_id']];
    }

    /** ۱.۰.۱۷: تنظیم دستیار برای هر پست: inherit (طبق تنظیم کلی) | on | off */
    public static function postMode(string $mediaId): string
    {
        if ($mediaId === '') return 'inherit';
        try {
            if (!Database::hasColumn('eb_ig_post_knowledge', 'agent_mode')) return 'inherit';
            $r = Database::one("SELECT agent_mode FROM eb_ig_post_knowledge WHERE media_id = ?", [$mediaId]);
            return in_array($r['agent_mode'] ?? '', ['on', 'off'], true) ? $r['agent_mode'] : 'inherit';
        } catch (Throwable $e) { return 'inherit'; }
    }

    public static function savePost(string $mediaId, string $title, string $text, string $mode): void
    {
        $mode = in_array($mode, ['on', 'off'], true) ? $mode : 'inherit';
        if (!Database::hasColumn('eb_ig_post_knowledge', 'agent_mode')) {
            try { Database::pdo()->exec("ALTER TABLE eb_ig_post_knowledge ADD COLUMN agent_mode VARCHAR(10) NOT NULL DEFAULT 'inherit'"); Database::forgetSchemaCache(); } catch (Throwable $e) {}
        }
        Database::run("INSERT INTO eb_ig_post_knowledge (media_id, title, knowledge_text, agent_mode) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), knowledge_text = VALUES(knowledge_text), agent_mode = VALUES(agent_mode)",
            [mb_substr($mediaId, 0, 64), mb_substr($title, 0, 255) ?: null, $text, $mode]);
    }

    /**
     * فیچری که تو ویدیوهای آموزشی سرویس‌دهنده دیدیم و نداشتیم: قانون می‌تواند به‌جای
     * کامنت، با ریپلای روی یک استوری (یا هر استوری) هم فعال شود. کاملاً از همان
     * موتور run()/ruleMatches() استفاده می‌کند، فقط نوع رویداد و شناسه‌ی محدوده فرق دارد.
     * @param array $event باید channel_uid, text, story_id (اختیاری، برای «فقط یک استوری») داشته باشد
     */
    public static function matchAndRunForStoryReply($driver, array $event, ?int $igAccountId = null): bool
    {
        $storyId = (string)($event['story_id'] ?? '');
        $text    = mb_strtolower(trim((string)($event['text'] ?? '')));
        $senderId = (string)($event['channel_uid'] ?? '');
        $rules = self::activeRules($igAccountId);

        foreach ($rules as $rule) {
            if (!self::ruleMatches($rule, 'story_reply', $storyId, $text, $senderId)) continue;
            self::run($driver, $rule, $event);
            Database::run("UPDATE eb_ig_comment_rules SET hit_count = hit_count + 1 WHERE id = ?", [$rule['id']]);
            if (self::hasConditionColumns() && !empty($rule['once_per_user']) && $senderId !== '') {
                try { Database::run("INSERT INTO eb_ig_rule_fires (rule_id, sender_id) VALUES (?, ?)", [$rule['id'], $senderId]); }
                catch (Throwable $e) {}
            }
            return true;
        }
        return false; // برای ریپلای استوری فالبک AI نداریم؛ چون «دانش هر پست» مخصوص پست است نه استوری
    }

    private static function activeRules(?int $igAccountId): array
    {
        return self::hasAccountScope()
            ? Database::all(
                "SELECT * FROM eb_ig_comment_rules WHERE is_active = 1 AND (ig_account_id IS NULL OR ig_account_id = ?) ORDER BY id ASC",
                [$igAccountId]
              )
            : Database::all("SELECT * FROM eb_ig_comment_rules WHERE is_active = 1 ORDER BY id ASC");
    }

    // ==================== دانش هر پست + پاسخ هوشمند AI (کلمه‌ی «متغیر») ====================

    public static function listPostKnowledge(): array
    {
        return Database::all("SELECT * FROM eb_ig_post_knowledge ORDER BY id DESC");
    }

    public static function getPostKnowledge(string $mediaId): ?array
    {
        if ($mediaId === '') return null;
        return Database::one("SELECT * FROM eb_ig_post_knowledge WHERE media_id = ?", [$mediaId]);
    }

    public static function savePostKnowledge(string $mediaId, string $title, string $text): void
    {
        Database::run(
            "INSERT INTO eb_ig_post_knowledge (media_id, title, knowledge_text) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE title=VALUES(title), knowledge_text=VALUES(knowledge_text)",
            [$mediaId, $title, $text]
        );
    }

    public static function deletePostKnowledge(int $id): void
    {
        Database::run("DELETE FROM eb_ig_post_knowledge WHERE id = ?", [$id]);
    }

    /** کلمات ممنوعه در پاسخ عمومی (مثلاً قیمت) — کاما جدا */
    public static function forbiddenTopics(): array
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.comment_forbidden_topics'");
            $v = $row['v'] ?? 'قیمت,هزینه,مبلغ,تخفیف';
        } catch (Throwable $e) { $v = 'قیمت,هزینه,مبلغ,تخفیف'; }
        return array_filter(array_map('trim', explode(',', $v)));
    }

    public static function setForbiddenTopics(string $csv): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.comment_forbidden_topics', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$csv]
        );
    }

    public static function forbiddenRedirectText(): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.comment_forbidden_redirect_text'");
            if ($row && trim((string)$row['v']) !== '') return $row['v'];
        } catch (Throwable $e) {}
        return 'برات دایرکت فرستادم، اونجا رو ببین 💌';
    }

    public static function setForbiddenRedirectText(string $text): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('channel.instagram.comment_forbidden_redirect_text', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [$text]
        );
    }

    private static function hasForbiddenTopic(string $lowerText): bool
    {
        foreach (self::forbiddenTopics() as $topic) {
            $topic = mb_strtolower(trim($topic));
            if ($topic !== '' && mb_strpos($lowerText, $topic) !== false) return true;
        }
        return false;
    }

    /**
     * وقتی هیچ قاعده‌ی ثابتی مچ نشد: اگر برای این پست دانشی ثبت شده، AI با توجه به همان
     * دانش جواب می‌سازد. اگر کامنت درباره‌ی یک موضوع ممنوعه (مثلاً قیمت) بود، پاسخ واقعی
     * فقط در دایرکت خصوصی می‌رود (private_reply)، نه زیر کامنت — و چون این هم اولین
     * تماس با این کاربر است (فقط کامنت گذاشته، دایرکت نداده)، طبق همان درسی که قبلاً یاد
     * گرفتیم، حتماً از private_reply می‌رود نه dm عادی.
     */
    private static function aiFallback($driver, array $event): bool
    {
        if (!class_exists('Ai')) return false;
        $mediaId = (string)($event['media_id'] ?? '');
        $commentId = (string)($event['comment_id'] ?? '');
        $text = trim((string)($event['text'] ?? ''));
        if ($mediaId === '' || $commentId === '' || $text === '') return false;

        $knowledge = self::getPostKnowledge($mediaId);
        if (!$knowledge || trim((string)$knowledge['knowledge_text']) === '') return false; // برای این پست دانشی نداریم، خودکار پاسخ نده

        $forbidden = self::hasForbiddenTopic(mb_strtolower($text));

        $system = "تو دستیار پاسخ به کامنت‌های اینستاگرام یک کسب‌وکار هستی. فقط بر اساس دانشِ زیر"
            . " (که مخصوص همین پست است) جواب بده؛ اگر جواب توی این دانش نبود، مؤدبانه بگو"
            . " اطلاعات دقیق‌تر رو در دایرکت می‌فرستی. کوتاه و فارسی جواب بده."
            . "\n\nدانش پست:\n" . $knowledge['knowledge_text'];

        $r = Ai::chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $text],
        ]);
        if (empty($r['ok']) || trim((string)($r['text'] ?? '')) === '') return false;
        $reply = trim($r['text']);

        $uid = (string)($event['channel_uid'] ?? '');
        if ($forbidden) {
            $r1 = $driver->sendCommentReply($commentId, self::forbiddenRedirectText());
            self::logOut($uid, ['text' => self::forbiddenRedirectText()], 'comment_reply', $r1);
            $msg = ['text' => mb_substr($reply, 0, 1000)];
            $r2 = $driver->sendPrivateReply($commentId, $msg);
            self::logOut($uid, $msg, 'ai_comment', $r2);
        } else {
            $r1 = $driver->sendCommentReply($commentId, mb_substr($reply, 0, 2200));
            self::logOut($uid, ['text' => mb_substr($reply, 0, 2200)], 'comment_reply', $r1);
        }
        return true;
    }

    private static function ruleMatches(array $rule, string $kind, string $scopeId, string $lowerText, string $senderId = ''): bool
    {
        $hasTrig = self::hasTriggerColumns();

        // نوع تریگر: قانون‌های قدیمی (از قبل از این فیچر) فقط trigger_comment=1 دارند،
        // یعنی پیش‌فرض کاملاً سازگار با قبل می‌ماند.
        if ($hasTrig) {
            if ($kind === 'comment' && empty($rule['trigger_comment'])) return false;
            if ($kind === 'story_reply' && empty($rule['trigger_story_reply'])) return false;
        } elseif ($kind === 'story_reply') {
            return false; // بدون migration، ریپلای استوری هنوز پشتیبانی نمی‌شود
        }

        // محدوده: برای کامنت همان media_id (پست)، برای ریپلای استوری همان story_id
        if ($kind === 'comment') {
            if (!empty($rule['media_id']) && $rule['media_id'] !== $scopeId) return false;
        } else {
            if ($hasTrig && !empty($rule['story_id']) && $rule['story_id'] !== $scopeId) return false;
        }

        // بازه‌ی زمانی فعال‌بودن قانون (مثل خودِ پنل سرویس‌دهنده: تاریخ شروع/پایان اختیاری)
        if ($hasTrig) {
            $now = date('Y-m-d H:i:s');
            if (!empty($rule['start_at']) && $now < $rule['start_at']) return false;
            if (!empty($rule['end_at']) && $now > $rule['end_at']) return false;
        }

        // کلمات ممنوعه‌ی این قانون: اگر هرکدام توی کامنت باشد، این قانون اصلاً مچ نمی‌شود
        // (حتی اگر کلیدواژه‌ی اصلی هم توش باشد) — برای رد کردن حالت‌های خاص از یک قانون عمومی.
        if (self::hasConditionColumns() && !empty($rule['exclude_keywords'])) {
            foreach (self::splitWords($rule['exclude_keywords']) as $ex) {
                if ($ex !== '' && mb_strpos($lowerText, $ex) !== false) return false;
            }
        }

        $kw = trim(mb_strtolower((string)($rule['keyword'] ?? '')));
        if ($kw === '') {
            // کلیدواژه خالی = هر کامنتی/ریپلایی (زیر آن پست/استوری، یا همه)
        } else {
            // چند کلیدواژه با کاما = کافی‌ست یکی از آن‌ها باشد (OR)
            $words = self::splitWords($kw);
            $hit = false;
            foreach ($words as $w) {
                if ($w === '') continue;
                if ($rule['match_mode'] === 'exact' ? ($lowerText === $w) : (mb_strpos($lowerText, $w) !== false)) { $hit = true; break; }
            }
            if (!$hit) return false;
        }

        // «فقط یک‌بار برای هر کاربر»: اگر این قانون قبلاً برای همین کاربر اجرا شده، دیگر اجرا نشود
        if (self::hasConditionColumns() && !empty($rule['once_per_user']) && $senderId !== '') {
            try {
                $seen = Database::one("SELECT id FROM eb_ig_rule_fires WHERE rule_id=? AND sender_id=? LIMIT 1", [$rule['id'], $senderId]);
                if ($seen) return false;
            } catch (Throwable $e) {}
        }

        return true;
    }

    /** @var bool|null کش: آیا ستون‌های trigger_comment/trigger_story_reply/start_at/end_at هستند؟ */
    private static $hasTriggerColumns = null;
    private static function hasTriggerColumns(): bool
    {
        if (self::$hasTriggerColumns !== null) return self::$hasTriggerColumns;
        try {
            self::$hasTriggerColumns = Database::hasColumn('eb_ig_comment_rules', 'trigger_story_reply');
        } catch (Throwable $e) { self::$hasTriggerColumns = false; }
        return self::$hasTriggerColumns;
    }

    /** تقسیم رشته‌ی «کلمه۱, کلمه۲» به آرایه‌ی تمیزشده */
    private static function splitWords(string $csv): array
    {
        return array_values(array_filter(array_map(
            fn($w) => trim(mb_strtolower($w)),
            explode(',', $csv)
        ), fn($w) => $w !== ''));
    }

    /** @var bool|null کش: آیا ستون‌های شرط‌های پیشرفته (exclude_keywords/once_per_user) هستند؟ */
    private static $hasConditionColumns = null;
    private static function hasConditionColumns(): bool
    {
        if (self::$hasConditionColumns !== null) return self::$hasConditionColumns;
        try {
            self::$hasConditionColumns = Database::hasColumn('eb_ig_comment_rules', 'once_per_user');
        } catch (Throwable $e) { self::$hasConditionColumns = false; }
        return self::$hasConditionColumns;
    }

    /** ساخت دکمه‌ی تأیید فالو از روی یک قانون — هم موقع اول (run) هم موقع «هنوز فالو نکردی» (resolveGate) استفاده می‌شود */
    private static function followButton(array $rule): array
    {
        return [['text' => mb_substr((string)$rule['confirm_phrase'], 0, 20), 'payload' => (string)$rule['confirm_phrase']]];
    }

    private static function run($driver, array $rule, array $event): void
    {
        $commentId = (string)($event['comment_id'] ?? '');
        $channelUid = (string)($event['channel_uid'] ?? '');

        if (trim((string)$rule['public_reply_text']) !== '' && $commentId !== '') {
            $r = $driver->sendCommentReply($commentId, (string)$rule['public_reply_text']);
            self::logOut($channelUid, ['text' => (string)$rule['public_reply_text']], 'comment_reply', $r);
        }

        // ⚠️ نکته‌ی کشف‌شده: اولین دایرکتی که خطاب به یک کامنت‌گذار می‌رود باید از اکشن
        // private_reply (با comment_id) برود، نه یک dm عادی (با recipient_id). چون این
        // شخص هنوز هیچ دایرکتی به ما نداده، از نگاه متا «پنجره‌ی پیام‌رسانی» بازی با او
        // باز نشده؛ private_reply همان استثنای رسمی است که اجازه می‌دهد بدون آن پنجره هم
        // پیام برود (دقیقاً همان چیزی که مستندات دایرکت‌ساز برای پاسخ به کامنت طراحی کرده).
        // یک dm ساده در این حالت یا رد می‌شود یا queue می‌شود ولی هرگز تحویل نمی‌گیرد —
        // همان چیزی که در تست واقعی دیدیم (لاگ /send داشتیم ولی پیام واقعاً نمی‌رسید).
        // ⚠️ اما این فقط برای «کامنت» صادق است: وقتی تریگر ریپلای استوری است، comment_id
        // اصلاً وجود ندارد — و اتفاقاً لازم هم نیست، چون خودِ ریپلای استوری یعنی شخص همین
        // الان یک دایرکت واقعی به ما داده، پس پنجره‌ی پیام‌رسانی از قبل باز است. self::dmSend
        // همین انتخاب را خودکار انجام می‌دهد.
        if (!empty($rule['require_follow'])) {
            // ⚠️ رفع باگ: قبلاً بدون چک، همیشه پیام «فالو کن» فرستاده می‌شد — حتی به کسی که
            // از قبل واقعاً فالو بود. حالا اول وضعیت واقعی چک می‌شود؛ اگر از قبل فالو است،
            // مستقیم محتوای اصلی می‌رود، بدون مزاحمت گیت.
            $already = $driver->followStatus($channelUid, true);
            // درایور خام 'is_follower' برمی‌گرداند و آداپتور کامنت 'follows' — هر دو پذیرفته می‌شوند
            if (!empty($already['ok']) && (!empty($already['follows']) || !empty($already['is_follower']))) {
                if (trim((string)$rule['dm_text']) !== '') {
                    self::dmSend($driver, $commentId, $channelUid, ['text' => $rule['dm_text']]);
                }
                return;
            }

            $prompt = trim((string)$rule['follow_prompt_text']) !== ''
                ? $rule['follow_prompt_text']
                : ('اول پیجمون رو فالو کن، بعد دکمه‌ی زیر رو بزن 🙏');

            self::dmSend($driver, $commentId, $channelUid, [
                'text' => $prompt,
                'buttons' => self::followButton($rule),
            ], 'follow_gate');

            Database::run(
                "INSERT INTO eb_ig_gate_pending (channel_uid, rule_id, comment_id) VALUES (?, ?, ?)",
                [$channelUid, $rule['id'], $commentId]
            );
            return;
        }

        if (trim((string)$rule['dm_text']) !== '') {
            self::dmSend($driver, $commentId, $channelUid, ['text' => $rule['dm_text']]);
        }
    }

    /** دایرکت می‌فرستد: اگر comment_id داشته باشیم از private_reply (اولین تماس با کامنت‌گذار)،
     *  وگرنه (مثلاً تریگر ریپلای استوری، یا خودِ dm) از send معمولی — چون پنجره از قبل باز است. */
    private static function dmSend($driver, string $commentId, string $channelUid, array $message, string $source = 'comment_dm'): array
    {
        if ($commentId !== '' && method_exists($driver, 'sendPrivateReply')) {
            $r = $driver->sendPrivateReply($commentId, $message);
        } else {
            $r = $driver->send(['channel_uid' => $channelUid], $message);
        }
        self::logOut($channelUid, $message, $source, is_array($r) ? $r : ['ok' => (bool)$r]);
        return is_array($r) ? $r : ['ok' => (bool)$r];
    }

    /**
     * برای رویدادهای dm صدا زده می‌شود، قبل از فرم/منو/پاسخ‌خودکار/AI.
     * اگر این کاربر منتظر تأیید فالو بود و متنش با عبارت تأیید جور بود، فالو را چک
     * و نتیجه را می‌فرستد.
     * @return bool آیا این پیام مصرف شد؟ (یعنی پایپ‌لاین بعدی نباید اجرا شود)
     */
    public static function handleDmForGate($driver, string $channelUid, string $text): bool
    {
        $pending = Database::one(
            "SELECT * FROM eb_ig_gate_pending WHERE channel_uid = ? ORDER BY id DESC LIMIT 1",
            [$channelUid]
        );
        if (!$pending) return false;

        $rule = self::find((int)$pending['rule_id']);
        if (!$rule) { Database::run("DELETE FROM eb_ig_gate_pending WHERE id = ?", [$pending['id']]); return false; }

        $confirmPhrase = mb_strtolower(trim((string)$rule['confirm_phrase']));
        if ($confirmPhrase !== '' && mb_strpos(mb_strtolower(trim($text)), $confirmPhrase) === false) {
            return false; // منتظریم، ولی این پیام ربطی به تأیید فالو نداشت — بذار بقیه‌ی پایپ‌لاین جوابش بده
        }

        self::resolveGate($driver, $channelUid, $pending, $rule);
        return true;
    }

    /**
     * فیچر اختصاصی سرویس‌دهنده: تأیید فالو با یک کلیک واقعی روی دکمه (رویداد
     * button_click)، نه با حدس‌زدن متن. چون سرویس‌دهنده کلیک دکمه را واقعاً و
     * مستند تحویل می‌دهد (بخش ۳.۶ مستندات)، اینجا نیازی به «عبارت تأیید
     * متنی» نیست — همان لحظه‌ی کلیک، تأیید قطعی است.
     * @return bool آیا این کلیک مصرف شد؟
     */
    public static function handleButtonClickForGate($driver, string $channelUid, string $payload): bool
    {
        $pending = Database::one(
            "SELECT * FROM eb_ig_gate_pending WHERE channel_uid = ? ORDER BY id DESC LIMIT 1",
            [$channelUid]
        );
        if (!$pending) return false;

        $rule = self::find((int)$pending['rule_id']);
        if (!$rule) { Database::run("DELETE FROM eb_ig_gate_pending WHERE id = ?", [$pending['id']]); return false; }

        $confirmPhrase = trim((string)$rule['confirm_phrase']);
        // payload دکمه دقیقاً همان عبارت تأیید است (طبق ساخت دکمه در run())
        if ($confirmPhrase !== '' && trim($payload) !== $confirmPhrase) return false;

        self::resolveGate($driver, $channelUid, $pending, $rule);
        return true;
    }

    /** چک واقعی فالو و ارسال محتوای اصلی یا یادآوری — مشترک بین تأیید متنی و تأیید با دکمه */
    private static function resolveGate($driver, string $channelUid, array $pending, array $rule): void
    {
        $status = $driver->followStatus($channelUid, true);
        // رفع باگ: اینجا (کلیک دکمه / تأیید متنی) درایور خام پاس داده می‌شود که کلید 'is_follower'
        // برمی‌گرداند، نه 'follows' (فقط آداپتور کامنت 'follows' دارد) — قبلاً همیشه «هنوز فالو نکردی» می‌گفت.
        if (!empty($status['ok']) && (!empty($status['follows']) || !empty($status['is_follower']))) {
            if (trim((string)$rule['dm_text']) !== '') {
                $r = $driver->send(['channel_uid' => $channelUid], ['text' => $rule['dm_text']]);
                self::logOut($channelUid, ['text' => $rule['dm_text']], 'comment_dm', $r);
            }
            Database::run("DELETE FROM eb_ig_gate_pending WHERE id = ?", [$pending['id']]);
        } else {
            // ⚠️ رفع باگ: قبلاً این پیام بدون دکمه می‌رفت — یعنی اگر واقعاً فالو کرده بود ولی
            // چک هنوز آن را ندیده بود (تأخیر معمول سمت اینستاگرام/سرویس‌دهنده)، کاربر گیر می‌افتاد
            // و راهی برای امتحان دوباره نداشت. حالا همان دکمه دوباره همراهش می‌رود.
            $notYet = trim((string)$rule['not_yet_text']) !== ''
                ? $rule['not_yet_text']
                : 'هنوز فالو نکردی 🙈 فالو کن، بعد دوباره دکمه‌ی زیر رو بزن.';
            $msg = ['text' => $notYet, 'buttons' => self::followButton($rule)];
            $r = $driver->send(['channel_uid' => $channelUid], $msg);
            self::logOut($channelUid, $msg, 'follow_gate', $r);
            // ردیف pending نگه داشته می‌شود تا دوباره امتحان کند
        }
    }

    /** پاک‌سازی حالت‌های معلق قدیمی (cron) — اگر کسی هیچ‌وقت تأیید نکرد، برای همیشه معطل نماند */
    public static function cleanupPending(int $hours = 48): void
    {
        try {
            Database::run("DELETE FROM eb_ig_gate_pending WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)", [$hours]);
        } catch (Throwable $e) {}
    }
}
