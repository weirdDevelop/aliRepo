<?php
/**
 * modules/Inbox.php
 * صندوق ورودی: پیام‌های دریافتی از مشتریان (direction='in')، گروه‌بندی‌شده بر اساس شخص.
 *
 * منطق «خوانده‌نشده» ساده است: یک ستون read_at روی هر پیام دریافتی.
 * هرکس ببیند، برای همه خوانده می‌شود (مستقل از اپراتور — طبق تصمیم محصول).
 *
 * گفتگو = شخص. همه‌ی کانال‌ها و همه‌ی پیج‌های یک مشتری در یک نخ جمع می‌شوند.
 *
 * دور ۳ (کارایی، مورد ۵): کوئری فهرست گفتگو دو مرحله‌ای شد.
 *   قبلاً یک GROUP BY روی همه‌ی پیام‌های ورودی با چند GROUP_CONCAT(... ORDER BY) بود؛
 *   یعنی MySQL برای *هر* مشتری همه‌ی متن پیام‌هایش را مرتب و به هم می‌چسباند، فقط
 *   برای اینکه آخرینش را بردارد. با زیاد شدن پیج‌ها و پیام‌رسان‌ها این کوئری اصلی‌ترین
 *   دلیل کندی بود. حالا:
 *     ۱) فقط person_id + آخرین زمان + شمار خوانده‌نشده (بدون متن) برای همین صفحه
 *     ۲) آخرین پیام همان ۳۰ نفر با یک کوئری IN کوچک
 *   شمارنده‌های تب‌ها هم ۱۵ ثانیه کش می‌شوند و بررسی وجود ستون‌ها یک‌بار در هر درخواست است.
 */

class Inbox
{
    // ---------- بررسی ساختار (یک‌بار در هر درخواست، از کش Database) ----------
    public static function hasReadAt(): bool { return Database::hasColumn('eb_messages', 'read_at'); }
    public static function hasPageUrl(): bool { return Database::hasColumn('eb_messages', 'page_url'); }
    public static function hasDeleted(): bool { return Database::hasColumn('eb_messages', 'deleted_at'); }
    public static function hasSource(): bool { return Database::hasColumn('eb_messages', 'source'); }
    public static function hasMedia(): bool { return Database::hasColumn('eb_messages', 'media_type'); }
    public static function hasSeenAt(): bool { return Database::hasColumn('eb_messages', 'seen_at'); }
    public static function hasSentBy(): bool { return Database::hasColumn('eb_messages', 'sent_by'); }
    public static function hasBlock(): bool { return Database::hasColumn('eb_persons', 'blocked_at'); }
    public static function hasAssign(): bool { return Database::hasColumn('eb_persons', 'assigned_user_id'); }
    public static function hasIgAccounts(): bool { return Database::hasTable('eb_ig_accounts') && Database::hasColumn('eb_identities', 'ig_account_id'); }

    /**
     * فهرست گفتگوها.
     * $filters: channel, unread, blocked, q, ig_account_id, assigned (''|me|none|others|ai)
     */
    public static function conversations(array $filters = [], int $page = 1, int $per = 30): array
    {
        $channel = trim((string)($filters['channel'] ?? ''));
        $unreadOnly = !empty($filters['unread']) && self::hasReadAt();
        $q = trim((string)($filters['q'] ?? ''));
        $showBlocked = !empty($filters['blocked']);
        $assigned = (string)($filters['assigned'] ?? '');

        $conds = ["m.direction='in'"];
        $params = [];
        $needPerson = false;
        if ($channel !== '') { $conds[] = "m.channel = ?"; $params[] = $channel; }
        $igAcc = (int)($filters['ig_account_id'] ?? 0);
        if ($igAcc > 0 && self::hasIgAccounts()) { $conds[] = "i.ig_account_id = ?"; $params[] = $igAcc; }
        if (self::hasDeleted()) $conds[] = "m.deleted_at IS NULL";

        if ($q !== '') {
            $needPerson = true;
            $digits = Persons::asciiDigits($q);
            $conds[] = "(p.full_name LIKE ? OR p.phone LIKE ? OR i.person_id IN (SELECT person_id FROM eb_identities WHERE username LIKE ?))";
            $params[] = "%$q%"; $params[] = '%' . ltrim(preg_replace('/\D+/', '', $digits) ?: $q, '0') . '%'; $params[] = '%' . ltrim($q, '@') . '%';
        }
        if (self::hasBlock()) { $needPerson = true; $conds[] = $showBlocked ? "p.blocked_at IS NOT NULL" : "p.blocked_at IS NULL"; }
        if ($assigned !== '' && self::hasAssign()) {
            $needPerson = true;
            $me = (int)(class_exists('Auth') ? Auth::id() : 0);
            if ($assigned === 'me')     { $conds[] = "p.assigned_user_id = ?"; $params[] = $me; }
            if ($assigned === 'none')   { $conds[] = "p.assigned_user_id IS NULL" . (Database::hasColumn('eb_persons', 'ai_handoff_at') ? " AND p.ai_handoff_at IS NULL" : ''); }
            if ($assigned === 'others') { $conds[] = "p.assigned_user_id IS NOT NULL AND p.assigned_user_id <> ?"; $params[] = $me; }
            if ($assigned === 'ai' && Database::hasColumn('eb_persons', 'ai_handoff_at')) $conds[] = "p.ai_handoff_at IS NOT NULL";
        }
        $gp = [];
        $gc = self::allowedGroupsCond($gp);
        if ($gc !== '') { $conds[] = $gc; $params = array_merge($params, $gp); }
        if (class_exists('Desk') && !Desk::seesOthers()) { $needPerson = true; $conds[] = "(p.assigned_user_id IS NULL OR p.assigned_user_id = ?)"; $params[] = (int)Auth::id(); }

        $where = implode(' AND ', $conds);
        $pJoin = $needPerson ? "INNER JOIN eb_persons p ON p.id = i.person_id" : "";
        $unreadExpr = self::hasReadAt() ? "SUM(m.read_at IS NULL)" : "0";
        $off = max(0, ($page - 1) * $per);

        // ۱) فقط شناسه‌ها و زمان‌ها برای همین صفحه
        $rows = Database::all(
            "SELECT i.person_id, MAX(m.created_at) AS last_at, $unreadExpr AS unread, COUNT(*) AS in_count
             FROM eb_messages m
             INNER JOIN eb_identities i ON i.id = m.identity_id
             $pJoin
             WHERE $where
             GROUP BY i.person_id
             " . ($unreadOnly ? "HAVING unread > 0" : "") . "
             ORDER BY last_at DESC
             LIMIT $per OFFSET $off",
            $params
        );
        $total = (int)(Database::one(
            "SELECT COUNT(DISTINCT i.person_id) c
             FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id $pJoin
             WHERE $where" . ($unreadOnly ? " AND m.read_at IS NULL" : ""),
            $params
        )['c'] ?? 0);

        return ['conversations' => self::decorate($rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
    }

    /** ۲) تکمیل ردیف‌های یک صفحه: نام، آخرین پیام، پیج، مسئول چت، حاضرین */
    private static function decorate(array $rows): array
    {
        if (!$rows) return [];
        $ids = array_map(fn($r) => (int)$r['person_id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $assignCols = self::hasAssign() ? ", assigned_user_id, assigned_at, first_responder_id" : ", NULL AS assigned_user_id, NULL AS assigned_at, NULL AS first_responder_id";
        $aiCol = Database::hasColumn('eb_persons', 'ai_handoff_at') ? ", ai_handoff_at" : ", NULL AS ai_handoff_at";
        $persons = [];
        foreach (Database::all("SELECT id, full_name, phone, status $assignCols $aiCol FROM eb_persons WHERE id IN ($ph)", $ids) as $p) $persons[(int)$p['id']] = $p;

        $delCond = self::hasDeleted() ? "AND m2.deleted_at IS NULL" : "";
        $srcCol = self::hasSource() ? "m.source" : "NULL AS source";
        $mediaCol = self::hasMedia() ? "m.media_type" : "NULL AS media_type";
        $igCols = self::hasIgAccounts() ? ", i.ig_account_id, a.label AS account_label, a.color AS account_color" : ", NULL AS ig_account_id, NULL AS account_label, NULL AS account_color";
        $igJoin = self::hasIgAccounts() ? "LEFT JOIN eb_ig_accounts a ON a.id = i.ig_account_id" : "";
        // آخرین پیام ورودی (کانال/پیج تب) و آخرین پیام هر جهت (پیش‌نمایش و تیک «پاسخ داده شد»)
        $lastIn = []; $lastAny = [];
        foreach (['in' => "AND m2.direction = 'in'", 'any' => ''] as $k => $dirCond) {
            $list = Database::all(
                "SELECT i.person_id, m.body, m.channel, m.direction, $srcCol, $mediaCol, m.created_at $igCols
                 FROM eb_messages m
                 INNER JOIN eb_identities i ON i.id = m.identity_id
                 $igJoin
                 WHERE m.id IN (
                    SELECT MAX(m2.id) FROM eb_messages m2 INNER JOIN eb_identities i2 ON i2.id = m2.identity_id
                    WHERE i2.person_id IN ($ph) $dirCond $delCond GROUP BY i2.person_id)",
                $ids
            );
            foreach ($list as $l) { if ($k === 'in') $lastIn[(int)$l['person_id']] = $l; else $lastAny[(int)$l['person_id']] = $l; }
        }
        $users = ChatAssign::userNames();
        $presence = ChatAssign::presenceFor($ids);

        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['person_id'];
            $p = $persons[$pid] ?? [];
            $li = $lastIn[$pid] ?? [];
            $la = $lastAny[$pid] ?? $li;
            $aid = isset($p['assigned_user_id']) ? (int)$p['assigned_user_id'] : 0;
            $fid = isset($p['first_responder_id']) ? (int)$p['first_responder_id'] : 0;
            $out[] = [
                'person_id' => $pid,
                'full_name' => $p['full_name'] ?? null,
                'phone' => $p['phone'] ?? null,
                'status' => $p['status'] ?? null,
                'last_at' => $r['last_at'],
                'unread' => (int)$r['unread'],
                'in_count' => (int)$r['in_count'],
                'last_body' => self::preview($la),
                'last_dir' => $la['direction'] ?? 'in',
                'last_source' => $la['source'] ?? null,
                'last_channel' => $li['channel'] ?? ($la['channel'] ?? null),
                'last_account_label' => $li['account_label'] ?? null,
                'last_account_color' => $li['account_color'] ?? null,
                'last_account_id' => isset($li['ig_account_id']) ? (int)$li['ig_account_id'] : null,
                'assigned_user_id' => $aid ?: null,
                'assigned_name' => $aid ? ($users[$aid] ?? ('#' . $aid)) : null,
                'first_responder_name' => $fid ? ($users[$fid] ?? null) : null,
                'ai_assigned' => !empty($p['ai_handoff_at']),
                'viewers' => $presence[$pid] ?? [],
            ];
        }
        return $out;
    }

    /** متن پیش‌نمایش یک پیام برای فهرست گفتگو */
    private static function preview(array $m): string
    {
        $b = (string)($m['body'] ?? '');
        $mt = $m['media_type'] ?? null;
        if ($b === '' && $mt) return $mt === 'photo' ? '🖼 تصویر' : ($mt === 'story_reply' ? '↩️ ریپلای استوری' : '📎 فایل');
        return mb_substr($b, 0, 120);
    }

    /** مجموع پیام‌های دریافتیِ خوانده‌نشده (نشانگر منو) — در همان درخواست کش می‌شود */
    private static $unreadCache = null;
    public static function unreadCount(): int
    {
        if (self::$unreadCache !== null) return self::$unreadCache;
        if (!self::hasReadAt()) return self::$unreadCache = 0;
        $gp = [];
        $cond = self::allowedGroupsCond($gp);
        // سریع: از فهرست خلاصه‌ی گفتگوها (به‌جای شمردن همه‌ی پیام‌ها)
        if (class_exists('ConvIndex') && ConvIndex::ready()) {
            try {
                ConvIndex::sync();
                $c2 = $cond !== '' ? ' AND ' . str_replace('i.person_id', 'x.person_id', $cond) : '';
                $b2 = self::hasBlock() ? " AND p.blocked_at IS NULL" : '';
                $row = Database::one("SELECT COALESCE(SUM(x.unread), 0) c FROM eb_conv_index x INNER JOIN eb_persons p ON p.id = x.person_id WHERE x.unread > 0 $b2 $c2", $gp);
                return self::$unreadCache = (int)($row['c'] ?? 0);
            } catch (Throwable $e) {}
        }
        $extra = $cond !== '' ? " AND $cond" : '';
        $blockJoin = self::hasBlock() ? "INNER JOIN eb_persons p ON p.id = i.person_id" : '';
        $blockCond = self::hasBlock() ? " AND p.blocked_at IS NULL" : '';
        try {
            $row = Database::one(
                "SELECT COUNT(*) c FROM eb_messages m
                 INNER JOIN eb_identities i ON i.id = m.identity_id
                 $blockJoin
                 WHERE m.direction='in' AND m.read_at IS NULL $blockCond $extra",
                $gp
            );
            self::$unreadCache = (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            self::$unreadCache = 0;
        }
        return self::$unreadCache;
    }

    private static function forgetUnreadCache(): void
    {
        self::$unreadCache = null;
        Cache::flush('inbox_counts_');
    }

    /**
     * پیام‌های یک گفتگو — **جدیدترین‌ها** (دور ۳، مورد ۳).
     * قبلاً ORDER BY ASC LIMIT 200 بود؛ یعنی در گفتگوهای طولانی *قدیمی‌ترین* ۲۰۰ پیام
     * برمی‌گشت و پیام‌های تازه اصلاً دیده نمی‌شدند. حالا آخرین $limit پیام برمی‌گردد
     * و با $beforeId می‌شود صفحه‌به‌صفحه به عقب رفت («پیام‌های قبلی»).
     * $identityId: فقط پیام‌های یک هویت (مثلاً فقط دایرکت‌های پیج X) — مورد ۶.
     *
     * @return array{messages: array, has_more: bool}
     */
    public static function thread(int $personId, int $limit = 80, bool $includeDeleted = false, int $beforeId = 0, int $identityId = 0, string $channel = ''): array
    {
        $limit = max(10, min(500, $limit));
        $cols = ["m.id", "m.identity_id", "m.channel", "m.direction", "m.body", "m.status", "m.error", "m.created_at"];
        $cols[] = self::hasReadAt() ? "m.read_at" : "NULL AS read_at";
        $cols[] = self::hasMedia() ? "m.media_type, m.media_path" : "NULL AS media_type, NULL AS media_path";
        $cols[] = self::hasSource() ? "m.source" : "NULL AS source";
        $cols[] = self::hasSeenAt() ? "m.seen_at" : "NULL AS seen_at";
        $cols[] = self::hasPageUrl() ? "m.page_url" : "NULL AS page_url";
        $cols[] = self::hasDeleted() ? "m.deleted_at, m.deleted_by" : "NULL AS deleted_at, NULL AS deleted_by";
        $cols[] = self::hasSentBy() ? "m.sent_by" : "NULL AS sent_by";
        $cols[] = Database::hasColumn('eb_messages', 'edited_at') ? "m.edited_at, m.orig_body, (m.ext_id IS NOT NULL) AS has_ext" : "NULL AS edited_at, NULL AS orig_body, 0 AS has_ext";
        $cols[] = self::hasIgAccounts() ? "i.ig_account_id" : "NULL AS ig_account_id";

        $conds = ["i.person_id = ?"]; $params = [$personId];
        if (self::hasDeleted() && !$includeDeleted) $conds[] = "m.deleted_at IS NULL";
        if ($identityId > 0) { $conds[] = "m.identity_id = ?"; $params[] = $identityId; }
        if ($channel !== '') { $conds[] = "m.channel = ?"; $params[] = $channel; }
        if ($beforeId > 0) {
            $b = Database::one("SELECT created_at FROM eb_messages WHERE id = ?", [$beforeId]);
            if ($b) { $conds[] = "(m.created_at < ? OR (m.created_at = ? AND m.id < ?))"; array_push($params, $b['created_at'], $b['created_at'], $beforeId); }
        }
        $rows = Database::all(
            "SELECT " . implode(', ', $cols) . "
             FROM eb_messages m
             INNER JOIN eb_identities i ON i.id = m.identity_id
             WHERE " . implode(' AND ', $conds) . "
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT " . ($limit + 1),
            $params
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $rows = array_reverse($rows);
        $users = $rows ? ChatAssign::userNames() : [];
        foreach ($rows as &$r) {
            $r['sent_by_name'] = !empty($r['sent_by']) ? ($users[(int)$r['sent_by']] ?? null) : null;
        }
        unset($r);
        return ['messages' => $rows, 'has_more' => $hasMore];
    }

    /** کانال‌هایی که ویرایش پیام ارسالی را پشتیبانی می‌کنند و مهلت ویرایش (ساعت) */
    const EDITABLE = ['telegram' => 48, 'bale' => 48, 'roubika' => 48, 'webchat' => 0];

    /** آیا این پیام قابل ویرایش است؟ [bool, دلیل] */
    public static function editable(array $m): array
    {
        if (($m['direction'] ?? '') !== 'out') return [false, 'فقط پیام‌های ارسالی قابل ویرایش‌اند'];
        if (in_array($m['status'] ?? '', ['failed', 'error'], true)) return [false, 'این پیام ارسال نشده بود'];
        if (!empty($m['deleted_at'])) return [false, 'پیام حذف شده است'];
        $ch = (string)($m['channel'] ?? '');
        if (!array_key_exists($ch, self::EDITABLE)) {
            $why = ['instagram' => 'اینستاگرام', 'instagram_apichi' => 'اینستاگرام', 'sms' => 'پیامک', 'email' => 'ایمیل', 'bale_safir' => 'صفیر بله'][$ch] ?? $ch;
            return [false, $why . ' اجازه‌ی ویرایش پیام ارسال‌شده را نمی‌دهد'];
        }
        if ($ch !== 'webchat' && empty($m['ext_id'])) return [false, 'شناسه‌ی این پیام در ' . $ch . ' ثبت نشده (پیام‌های قدیمی‌تر قابل ویرایش نیستند)'];
        $h = self::EDITABLE[$ch];
        if ($h && strtotime((string)$m['created_at']) < strtotime((string)(Database::one("SELECT NOW() n")['n'] ?? 'now')) - $h * 3600) return [false, "ویرایش فقط تا {$h} ساعت بعد از ارسال ممکن است"];
        return [true, null];
    }

    /** ویرایش متن پیام ارسالی در خود پلتفرم و در تاریخچه */
    public static function editMessage(int $id, string $text, int $byUserId): array
    {
        $text = trim($text);
        if ($text === '') return ['ok' => false, 'error' => 'متن خالی است'];
        if (!Database::hasColumn('eb_messages', 'edited_at')) return ['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید'];
        $m = Database::one("SELECT m.*, i.channel_uid, i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id WHERE m.id = ?", [$id]);
        if (!$m) return ['ok' => false, 'error' => 'پیام پیدا نشد'];
        [$ok, $why] = self::editable($m);
        if (!$ok) return ['ok' => false, 'error' => $why];
        if ($text === (string)$m['body']) return ['ok' => true, 'unchanged' => true];
        $ch = $m['channel'];
        if ($ch === 'webchat') {
            if (class_exists('Webchat')) Webchat::pushEvent((string)$m['channel_uid'], 'edit', ['id' => (int)$id, 'text' => $text]);
        } else {
            $driver = ChannelManager::get($ch);
            if (!$driver || !method_exists($driver, 'editText')) return ['ok' => false, 'error' => 'درایور این کانال ویرایش را پشتیبانی نمی‌کند'];
            $r = $driver->editText((string)$m['channel_uid'], (string)$m['ext_id'], $text, !empty($m['media_type']));
            if (empty($r['ok'])) return ['ok' => false, 'error' => 'پلتفرم ویرایش را نپذیرفت: ' . ($r['error'] ?? 'نامشخص')];
        }
        Database::run("UPDATE eb_messages SET orig_body = COALESCE(orig_body, body), body = ?, edited_at = NOW() WHERE id = ?", [$text, $id]);
        if (class_exists('ConvIndex')) ConvIndex::touch((int)$m['person_id']);
        return ['ok' => true];
    }

    /** حذف نرم یک پیام (متن اصلی برای لاگ می‌ماند) */
    public static function deleteMessage(int $messageId, int $byUserId): bool
    {
        if (!self::hasDeleted()) return false;
        Database::run("UPDATE eb_messages SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL", [$byUserId, $messageId]);
        if (class_exists('ConvIndex')) ConvIndex::touch(self::personIdForMessage($messageId));
        return true;
    }

    public static function restoreMessage(int $messageId): bool
    {
        if (!self::hasDeleted()) return false;
        Database::run("UPDATE eb_messages SET deleted_at = NULL, deleted_by = NULL WHERE id = ?", [$messageId]);
        if (class_exists('ConvIndex')) ConvIndex::touch(self::personIdForMessage($messageId));
        return true;
    }

    public static function personIdForMessage(int $messageId): ?int
    {
        $r = Database::one("SELECT i.person_id FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id WHERE m.id = ?", [$messageId]);
        return $r ? (int)$r['person_id'] : null;
    }

    public static function block(int $personId): bool
    {
        if (!self::hasBlock()) return false;
        Database::run("UPDATE eb_persons SET blocked_at = NOW() WHERE id = ?", [$personId]);
        self::forgetUnreadCache();
        return true;
    }

    public static function unblock(int $personId): bool
    {
        if (!self::hasBlock()) return false;
        Database::run("UPDATE eb_persons SET blocked_at = NULL WHERE id = ?", [$personId]);
        self::forgetUnreadCache();
        return true;
    }

    public static function isBlocked(int $personId): bool
    {
        if (!self::hasBlock()) return false;
        $row = Database::one("SELECT blocked_at FROM eb_persons WHERE id = ?", [$personId]);
        return !empty($row['blocked_at']);
    }

    /** علامت‌گذاری پیام‌های دریافتیِ خوانده‌نشده‌ی یک شخص */
    public static function markRead(int $personId): int
    {
        if (!self::hasReadAt()) return 0;
        $st = Database::run(
            "UPDATE eb_messages m
             INNER JOIN eb_identities i ON i.id = m.identity_id
             SET m.read_at = NOW()
             WHERE i.person_id = ? AND m.direction='in' AND m.read_at IS NULL",
            [$personId]
        );
        $n = $st ? $st->rowCount() : 0;
        if ($n) { self::forgetUnreadCache(); if (class_exists('ConvIndex')) ConvIndex::touch($personId); }
        return $n;
    }

    /** کانالی که آخرین پیامِ دریافتیِ شخص از آن آمده */
    public static function lastInboundChannel(int $personId): ?string
    {
        $row = self::lastInboundIdentity($personId);
        return $row['channel'] ?? null;
    }

    /**
     * هویتی که آخرین پیام ورودی از آن آمده (کانال + پیج).
     * بعد از ادغام پیج‌ها یک مشتری چند هویت اینستاگرام دارد؛ پاسخ باید از همان پیجی برود
     * که مشتری آخرین بار به آن پیام داده (پنجره‌ی ۲۴ ساعته‌ی اینستاگرام مال همان پیج است).
     */
    public static function lastInboundIdentity(int $personId): ?array
    {
        $ig = self::hasIgAccounts() ? ", i.ig_account_id" : ", NULL AS ig_account_id";
        return Database::one(
            "SELECT i.id, i.channel, i.channel_uid $ig FROM eb_messages m
             INNER JOIN eb_identities i ON i.id = m.identity_id
             WHERE i.person_id = ? AND m.direction='in'
             ORDER BY m.created_at DESC, m.id DESC LIMIT 1",
            [$personId]
        );
    }

    /**
     * شمار گفتگوها و خوانده‌نشده‌ها به تفکیک کانال و پیج اینستاگرام — ۱۵ ثانیه کش
     * (کلید کش به محدودیت گروه کاربر وابسته است تا اپراتورها عدد هم را نبینند).
     */
    public static function channelCounts(): array
    {
        $gp = [];
        $cond = self::allowedGroupsCond($gp);
        $key = 'inbox_counts_' . md5(json_encode($gp) . '|' . ($cond !== '' ? 'g' : 'all'));
        return Cache::remember($key, 15, function () use ($cond, $gp) {
            $extra = $cond !== '' ? " AND $cond" : '';
            $pJoin = self::hasBlock() ? "INNER JOIN eb_persons p ON p.id = i.person_id" : '';
            $blockCond = self::hasBlock() ? " AND p.blocked_at IS NULL" : '';
            $delCond = self::hasDeleted() ? " AND m.deleted_at IS NULL" : '';
            $unreadExpr = self::hasReadAt() ? "COUNT(DISTINCT CASE WHEN m.read_at IS NULL THEN i.person_id END)" : "0";
            $out = ['channels' => [], 'ig_pages' => []];
            try {
                $rows = Database::all(
                    "SELECT m.channel, COUNT(DISTINCT i.person_id) AS convos, $unreadExpr AS unread
                     FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id $pJoin
                     WHERE m.direction = 'in' $blockCond $delCond $extra
                     GROUP BY m.channel", $gp);
                foreach ($rows as $r) $out['channels'][(string)$r['channel']] = ['convos' => (int)$r['convos'], 'unread' => (int)$r['unread']];
            } catch (Throwable $e) {}
            if (self::hasIgAccounts()) {
                try {
                    $rows = Database::all(
                        "SELECT a.id, a.label, a.color, COUNT(DISTINCT i.person_id) AS convos, $unreadExpr AS unread
                         FROM eb_messages m
                         INNER JOIN eb_identities i ON i.id = m.identity_id
                         $pJoin
                         INNER JOIN eb_ig_accounts a ON a.id = i.ig_account_id
                         WHERE m.direction = 'in' AND m.channel = 'instagram' $blockCond $delCond $extra
                         GROUP BY a.id, a.label, a.color
                         ORDER BY a.id", $gp);
                    foreach ($rows as $r) $out['ig_pages'][] = ['id' => (int)$r['id'], 'label' => $r['label'], 'color' => $r['color'], 'convos' => (int)$r['convos'], 'unread' => (int)$r['unread']];
                } catch (Throwable $e) {}
            }
            return $out;
        });
    }

    /**
     * شرط SQL محدودیت گروه اپراتور (فقط مشتریان گروه‌های مجاز). پارامترها در $paramsRef.
     * برای admin/سرپرست یا بدون محدودیت → رشته‌ی خالی.
     */
    private static function allowedGroupsCond(array &$paramsRef): string
    {
        $paramsRef = [];
        if (!class_exists('Auth')) return '';
        $allowed = Auth::allowedGroups();
        if ($allowed === null) return '';
        if (!$allowed) $allowed = [-1];
        $paramsRef = array_values($allowed);
        return "i.person_id IN (SELECT person_id FROM eb_person_groups WHERE group_id IN (" . implode(',', array_fill(0, count($allowed), '?')) . "))";
    }
}
