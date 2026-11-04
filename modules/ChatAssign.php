<?php
/**
 * modules/ChatAssign.php
 * تخصیص گفتگو به پشتیبان (دور ۳ صندوق ورودی — موارد ۲، ۲-۱، ۲-۲).
 *
 * مفاهیم:
 *  - مسئول چت (eb_persons.assigned_user_id): پشتیبانی که الان چت بر عهده‌ی اوست.
 *    اولین پاسخ یک پشتیبان به چتِ بی‌مسئول، خودکار آن را بر عهده‌اش می‌گذارد.
 *  - شروع‌کننده (first_responder_id): اولین پشتیبانی که به این مشتری جواب داد — هرگز عوض نمی‌شود.
 *  - انتقال: پشتیبان (مثلاً آخر شیفت یا بیرون از ساعت کاری) چت را به همکار دیگر یا به AI می‌سپارد.
 *  - بر عهده گرفتن (takeover): اگر پشتیبان وارد چتی شد که مال همکار دیگری است، پیام
 *    «X در حال پاسخ‌گویی است» می‌بیند و فقط با «بر عهده گرفتن» می‌تواند جواب بدهد
 *    (ثبت در سابقه + اطلاع به مسئول قبلی در چت داخلی تیم).
 *  - مدیر و سرپرست از این محدودیت معاف‌اند: بدون بر عهده گرفتن هم می‌توانند پاسخ بدهند.
 *  - حضور (eb_chat_presence): هر کسی که چت را باز دارد هر ~۲۰ ثانیه ping می‌زند؛
 *    دیگران «X همین الان این چت را باز کرده / در حال نوشتن است» را می‌بینند.
 *
 * همه‌ی متدها در برابر نبودِ ستون/جدول (قبل از db_upgrade) بی‌صدا رفتار پیش‌فرض دارند.
 */

class ChatAssign
{
    const PRESENCE_TTL = 45; // ثانیه

    public static function ready(): bool
    {
        return Database::hasColumn('eb_persons', 'assigned_user_id') && Database::hasTable('eb_assign_log');
    }

    /** آیا کاربر فعلی محدودیت «بر عهده گرفتن» ندارد؟ (مدیر و سرپرست) */
    public static function unrestricted(): bool
    {
        return class_exists('Auth') && Auth::isManager();
    }

    private static $names = null;
    /** نگاشت id → نام کاربری همه‌ی کاربران پنل (کش ۶۰ ثانیه) */
    public static function userNames(): array
    {
        if (self::$names !== null) return self::$names;
        return self::$names = Cache::remember('users_names', 60, function () {
            $m = [];
            try { foreach (Database::all("SELECT id, " . Auth::nameSql('') . " AS username FROM eb_users") as $u) $m[(int)$u['id']] = $u['username']; } catch (Throwable $e) {}
            return $m;
        });
    }

    /** پشتیبان‌های فعال برای انتقال (به‌جز خودم) */
    public static function agents(int $excludeId = 0): array
    {
        $rows = Database::all("SELECT id, " . Auth::nameSql('') . " AS username, username AS login, role FROM eb_users WHERE is_active = 1 AND role <> 'viewer' ORDER BY username");
        $open = [];
        if (self::ready()) {
            foreach (Database::all("SELECT assigned_user_id u, COUNT(*) c FROM eb_persons WHERE assigned_user_id IS NOT NULL GROUP BY assigned_user_id") as $r) $open[(int)$r['u']] = (int)$r['c'];
        }
        $online = [];
        if (Database::hasTable('eb_chat_presence')) {
            foreach (Database::all("SELECT DISTINCT user_id FROM eb_chat_presence WHERE seen_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)") as $r) $online[(int)$r['user_id']] = true;
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($id === $excludeId) continue;
            $out[] = ['id' => $id, 'username' => $r['username'], 'role' => $r['role'], 'open' => $open[$id] ?? 0, 'online' => !empty($online[$id])];
        }
        return $out;
    }

    // ==================== حضور ====================
    public static function ping(int $personId, int $userId, bool $typing = false): void
    {
        if (!$personId || !$userId || !Database::hasTable('eb_chat_presence')) return;
        try {
            Database::run(
                "INSERT INTO eb_chat_presence (person_id, user_id, typing, seen_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE typing = VALUES(typing), seen_at = NOW()",
                [$personId, $userId, $typing ? 1 : 0]
            );
            // پاک‌سازی گاه‌به‌گاه
            if (mt_rand(1, 50) === 1) Database::run("DELETE FROM eb_chat_presence WHERE seen_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        } catch (Throwable $e) {}
    }

    public static function leave(int $personId, int $userId): void
    {
        if (!Database::hasTable('eb_chat_presence')) return;
        try { Database::run("DELETE FROM eb_chat_presence WHERE person_id = ? AND user_id = ?", [$personId, $userId]); } catch (Throwable $e) {}
    }

    /** @return array<int, array<int, array{user_id:int,name:string,typing:bool}>> */
    public static function presenceFor(array $personIds, int $excludeUser = 0): array
    {
        if (!$personIds || !Database::hasTable('eb_chat_presence')) return [];
        $ph = implode(',', array_fill(0, count($personIds), '?'));
        $names = self::userNames();
        $out = [];
        try {
            $rows = Database::all(
                "SELECT person_id, user_id, typing FROM eb_chat_presence
                 WHERE person_id IN ($ph) AND seen_at >= DATE_SUB(NOW(), INTERVAL " . self::PRESENCE_TTL . " SECOND)",
                array_values($personIds)
            );
            foreach ($rows as $r) {
                if ((int)$r['user_id'] === $excludeUser) continue;
                $out[(int)$r['person_id']][] = ['user_id' => (int)$r['user_id'], 'name' => $names[(int)$r['user_id']] ?? ('#' . $r['user_id']), 'typing' => (bool)$r['typing']];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    // ==================== وضعیت ====================
    public static function state(int $personId, int $meId): array
    {
        $names = self::userNames();
        $st = ['ready' => self::ready(), 'assigned_user_id' => null, 'assigned_name' => null, 'assigned_at' => null,
               'first_responder_name' => null, 'ai_assigned' => false, 'mine' => false,
               'unrestricted' => self::unrestricted(), 'can_reply' => true, 'viewers' => [], 'history' => []];
        if (self::ready()) {
            $p = Database::one("SELECT assigned_user_id, assigned_at, first_responder_id FROM eb_persons WHERE id = ?", [$personId]);
            if ($p) {
                $aid = (int)($p['assigned_user_id'] ?? 0);
                $st['assigned_user_id'] = $aid ?: null;
                $st['assigned_name'] = $aid ? ($names[$aid] ?? ('#' . $aid)) : null;
                $st['assigned_at'] = $p['assigned_at'];
                $st['first_responder_name'] = !empty($p['first_responder_id']) ? ($names[(int)$p['first_responder_id']] ?? null) : null;
                $st['mine'] = $aid && $aid === $meId;
                // پشتیبان عادی فقط وقتی می‌تواند مستقیم جواب بدهد که چت بی‌مسئول یا مال خودش باشد
                $st['can_reply'] = !$aid || $aid === $meId || $st['unrestricted'];
                // دستیار فروش روشن: پشتیبان فقط گفتگوی سپرده‌شده به خودش را جواب می‌دهد
                if (!$st['unrestricted'] && $aid !== $meId && class_exists('SalesAgent') && SalesAgent::locksFor($personId)) {
                    $st['can_reply'] = false;
                    $st['agent_lock'] = SalesAgent::LOCK_MSG;
                }
            }
            $st['history'] = self::history($personId, 15);
        }
        $st['ai_assigned'] = class_exists('Support') && Support::isAiAssigned($personId);
        $st['viewers'] = self::presenceFor([$personId], $meId)[$personId] ?? [];
        return $st;
    }

    public static function history(int $personId, int $limit = 30): array
    {
        if (!Database::hasTable('eb_assign_log')) return [];
        $names = self::userNames();
        $rows = Database::all("SELECT * FROM eb_assign_log WHERE person_id = ? ORDER BY id DESC LIMIT " . (int)$limit, [$personId]);
        foreach ($rows as &$r) {
            $r['from_name'] = !empty($r['from_user_id']) ? ($names[(int)$r['from_user_id']] ?? null) : null;
            $r['to_name'] = !empty($r['to_user_id']) ? ($names[(int)$r['to_user_id']] ?? null) : null;
            $r['by_name'] = !empty($r['by_user_id']) ? ($names[(int)$r['by_user_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    private static function log(int $personId, string $action, ?int $from, ?int $to, ?int $by, ?string $note = null): void
    {
        if (class_exists('Desk')) Desk::bumpCounts();   // شمارنده‌های «چت‌های من/همکاران» فوراً تازه شوند
        if (!Database::hasTable('eb_assign_log')) return;
        try {
            Database::run("INSERT INTO eb_assign_log (person_id, action, from_user_id, to_user_id, by_user_id, note) VALUES (?, ?, ?, ?, ?, ?)",
                [$personId, $action, $from ?: null, $to ?: null, $by ?: null, $note !== null ? mb_substr($note, 0, 255) : null]);
        } catch (Throwable $e) {}
    }

    private static function personLabel(int $personId): string
    {
        $p = Database::one("SELECT full_name, phone FROM eb_persons WHERE id = ?", [$personId]);
        $n = trim((string)($p['full_name'] ?? ''));
        if ($n === '' && !empty($p['phone'])) $n = '0' . substr($p['phone'], 2);
        if ($n === '') {
            $u = Database::one("SELECT username FROM eb_identities WHERE person_id = ? AND username IS NOT NULL AND username <> '' LIMIT 1", [$personId]);
            $n = !empty($u['username']) ? '@' . $u['username'] : ('مخاطب #' . $personId);
        }
        return $n;
    }

    /** اطلاع به یک همکار در چت داخلی تیم (اگر ماژول هست) */
    private static function tell(int $fromId, int $toId, string $text): void
    {
        if (!$toId || $toId === $fromId || !class_exists('TeamChat') || !TeamChat::hasTable()) return;
        try { TeamChat::send($fromId, $toId, $text); } catch (Throwable $e) {}
    }

    // ==================== عملیات ====================
    /**
     * بر عهده گرفتن چت.
     * $force=false و چت مال کس دیگری باشد → ['ok'=>false,'taken'=>true,'by'=>name] تا UI تأیید بگیرد.
     */
    public static function claim(int $personId, int $userId, bool $force = false): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'برای این قابلیت ابتدا «به‌روزرسانی دیتابیس» را بزنید'];
        $p = Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$personId]);
        if (!$p) return ['ok' => false, 'error' => 'مشتری یافت نشد'];
        $cur = (int)($p['assigned_user_id'] ?? 0);
        if ($cur === $userId) return ['ok' => true, 'already' => true];
        if (class_exists('SalesAgent') && SalesAgent::locksFor($personId) && !self::unrestricted()) return ['ok' => false, 'error' => SalesAgent::LOCK_MSG];
        $names = self::userNames();
        if ($cur && !$force) return ['ok' => false, 'taken' => true, 'by' => $names[$cur] ?? ('#' . $cur), 'error' => 'این چت بر عهده‌ی ' . ($names[$cur] ?? 'همکار دیگری') . ' است'];
        Database::run("UPDATE eb_persons SET assigned_user_id = ?, assigned_at = NOW() WHERE id = ?", [$userId, $personId]);
        if (class_exists('Support') && Support::isAiAssigned($personId)) Support::unassignAi($personId);
        self::log($personId, $cur ? 'takeover' : 'claim', $cur ?: null, $userId, $userId);
        if ($cur) self::tell($userId, $cur, '🔁 گفتگوی «' . self::personLabel($personId) . '» را بر عهده گرفتم. (صندوق ورودی ← index.php#inbox.v' . $personId . ')');
        return ['ok' => true];
    }

    /** رها کردن چت (بی‌مسئول شدن) */
    public static function release(int $personId, int $userId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید'];
        $p = Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$personId]);
        $cur = (int)($p['assigned_user_id'] ?? 0);
        if ($cur && $cur !== $userId && !self::unrestricted()) return ['ok' => false, 'error' => 'فقط مسئول چت یا مدیر/سرپرست می‌تواند آن را رها کند'];
        Database::run("UPDATE eb_persons SET assigned_user_id = NULL, assigned_at = NULL WHERE id = ?", [$personId]);
        self::log($personId, 'release', $cur ?: null, null, $userId);
        if (class_exists('SalesAgent')) SalesAgent::resume($personId);   // رها شد = دوباره با دستیار
        return ['ok' => true];
    }

    /**
     * انتقال چت به همکار ($to = user id) یا به هوش مصنوعی ($to = 'ai').
     * فقط مسئول فعلی، چت بی‌مسئول، یا مدیر/سرپرست.
     */
    public static function transfer(int $personId, $to, int $byUserId, ?string $note = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید'];
        $p = Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$personId]);
        if (!$p) return ['ok' => false, 'error' => 'مشتری یافت نشد'];
        $cur = (int)($p['assigned_user_id'] ?? 0);
        if ($cur && $cur !== $byUserId && !self::unrestricted()) return ['ok' => false, 'error' => 'این چت مال شما نیست؛ اول آن را بر عهده بگیرید'];
        if (!$cur || $cur !== $byUserId) { if (class_exists('SalesAgent') && SalesAgent::locksFor($personId) && !self::unrestricted()) return ['ok' => false, 'error' => SalesAgent::LOCK_MSG]; }
        $label = self::personLabel($personId);
        if ($to === 'ai') {
            if (!class_exists('Support') || !Support::assignAi($personId)) return ['ok' => false, 'error' => 'سپردن به AI در دسترس نیست (دیتابیس را به‌روزرسانی کنید)'];
            Database::run("UPDATE eb_persons SET assigned_user_id = NULL, assigned_at = NULL WHERE id = ?", [$personId]);
            self::log($personId, 'to_ai', $cur ?: null, null, $byUserId, $note);
            return ['ok' => true, 'to' => 'ai'];
        }
        $toId = (int)$to;
        $u = $toId ? Database::one("SELECT id, " . Auth::nameSql('') . " AS username FROM eb_users WHERE id = ? AND is_active = 1 AND role <> 'viewer'", [$toId]) : null;
        if (!$u) return ['ok' => false, 'error' => 'همکار مقصد پیدا نشد'];
        Database::run("UPDATE eb_persons SET assigned_user_id = ?, assigned_at = NOW() WHERE id = ?", [$toId, $personId]);
        if (class_exists('Support') && Support::isAiAssigned($personId)) Support::unassignAi($personId);
        self::log($personId, 'transfer', $cur ?: null, $toId, $byUserId, $note);
        self::tell($byUserId, $toId, '📨 گفتگوی «' . $label . '» به تو سپرده شد' . ($note ? ' — یادداشت: ' . $note : '') . '. (index.php#inbox.v' . $personId . ')');
        return ['ok' => true, 'to' => $u['username']];
    }

    /**
     * تعیین مستقیم مسئول چت از میز پشتیبانی (بررسی دسترسی در API انجام می‌شود).
     * $to = null یعنی بی‌مسئول.
     */
    public static function setAssignee(int $personId, ?int $to, int $byUserId, ?string $note = null): void
    {
        if (!self::ready()) return;
        $p = Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$personId]);
        if (!$p) return;
        $cur = (int)($p['assigned_user_id'] ?? 0);
        if ($cur === (int)$to) return;
        if ($to) {
            Database::run("UPDATE eb_persons SET assigned_user_id = ?, assigned_at = NOW() WHERE id = ?", [$to, $personId]);
            if (class_exists('Support') && Support::isAiAssigned($personId)) Support::unassignAi($personId);
            self::log($personId, $to === $byUserId ? ($cur ? 'takeover' : 'claim') : 'transfer', $cur ?: null, $to, $byUserId, $note);
            if ($to !== $byUserId) self::tell($byUserId, $to, '📨 گفتگوی «' . self::personLabel($personId) . '» به تو سپرده شد' . ($note ? ' — یادداشت: ' . $note : '') . '. (index.php#inbox.v' . $personId . ')');
            elseif ($cur) self::tell($byUserId, $cur, '🔁 گفتگوی «' . self::personLabel($personId) . '» را بر عهده گرفتم. (index.php#inbox.v' . $personId . ')');
        } else {
            Database::run("UPDATE eb_persons SET assigned_user_id = NULL, assigned_at = NULL WHERE id = ?", [$personId]);
            self::log($personId, 'release', $cur ?: null, null, $byUserId, $note);
        }
    }

    /** انتقال همه‌ی چت‌های باز من (آخر شیفت / بیرون از ساعت کاری) */
    public static function transferAll(int $fromUserId, $to, int $byUserId, ?string $note = null): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید'];
        if ($fromUserId !== $byUserId && !self::unrestricted()) return ['ok' => false, 'error' => 'دسترسی ندارید'];
        $ids = array_map(fn($r) => (int)$r['id'], Database::all("SELECT id FROM eb_persons WHERE assigned_user_id = ?", [$fromUserId]));
        $n = 0; $err = null;
        foreach ($ids as $pid) {
            $r = self::transfer($pid, $to, $byUserId, $note ?: 'انتقال گروهی (پایان شیفت)');
            if (!empty($r['ok'])) $n++; else $err = $r['error'] ?? null;
        }
        return ['ok' => $n > 0 || !$ids, 'moved' => $n, 'total' => count($ids), 'error' => $n ? null : $err];
    }

    /**
     * قبل از ارسال پاسخ اپراتور: آیا مجاز است؟ (پشتیبان عادی در چتِ همکار → خیر)
     * @return array{ok:bool, error?:string, taken_by?:string}
     */
    public static function guardReply(int $personId, int $userId): array
    {
        if (!self::ready() || self::unrestricted()) return ['ok' => true];
        $p = Database::one("SELECT assigned_user_id FROM eb_persons WHERE id = ?", [$personId]);
        $cur = (int)($p['assigned_user_id'] ?? 0);
        // دستیار فروش روشن: پشتیبان فقط به گفتگویی که مدیر/سرپرست به او سپرده پاسخ می‌دهد
        if ($cur !== $userId && class_exists('SalesAgent') && SalesAgent::locksFor($personId)) return ['ok' => false, 'error' => SalesAgent::LOCK_MSG];
        if ($cur && $cur !== $userId) {
            $n = self::userNames()[$cur] ?? 'همکار دیگری';
            return ['ok' => false, 'taken_by' => $n, 'error' => "این چت بر عهده‌ی {$n} است و در حال پاسخ‌گویی است. برای پاسخ، اول «بر عهده گرفتن» را بزنید."];
        }
        return ['ok' => true];
    }

    /** بعد از ارسال موفق پاسخ اپراتور: تخصیص خودکار + شروع‌کننده */
    public static function afterReply(int $personId, int $userId): void
    {
        if (!self::ready() || !$userId) return;
        try {
            $p = Database::one("SELECT assigned_user_id, first_responder_id FROM eb_persons WHERE id = ?", [$personId]);
            if (!$p) return;
            if (empty($p['first_responder_id'])) Database::run("UPDATE eb_persons SET first_responder_id = ? WHERE id = ? AND first_responder_id IS NULL", [$userId, $personId]);
            // مدیر/سرپرست با پاسخ‌دادن، چت را از مسئولش نمی‌گیرد؛ فقط اگر چت بی‌مسئول بود مال پاسخ‌دهنده می‌شود
            if (empty($p['assigned_user_id'])) {
                Database::run("UPDATE eb_persons SET assigned_user_id = ?, assigned_at = NOW() WHERE id = ? AND assigned_user_id IS NULL", [$userId, $personId]);
                self::log($personId, 'auto', null, $userId, $userId, 'اولین پاسخ');
            }
        } catch (Throwable $e) {}
    }

    /**
     * آمار پاسخ‌گویی هر پشتیبان در یک بازه.
     * replies: تعداد پیام‌های ارسالی دستی · chats: تعداد گفتگوهای متمایز پاسخ‌داده‌شده
     * started: گفتگوهایی که او شروع‌کننده‌اش بود · open: چت‌های الان بر عهده‌اش
     */
    public static function agentStats(int $days = 1): array
    {
        $days = max(1, min(365, $days));
        $names = self::userNames();
        $stats = [];
        foreach ($names as $id => $n) $stats[$id] = ['user_id' => $id, 'username' => $n, 'replies' => 0, 'chats' => 0, 'started' => 0, 'open' => 0];
        if (Database::hasColumn('eb_messages', 'sent_by')) {
            $rows = Database::all(
                "SELECT m.sent_by u, COUNT(*) replies, COUNT(DISTINCT i.person_id) chats
                 FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id
                 WHERE m.direction = 'out' AND m.sent_by IS NOT NULL AND m.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                 GROUP BY m.sent_by");
            foreach ($rows as $r) { $id = (int)$r['u']; if (!isset($stats[$id])) continue; $stats[$id]['replies'] = (int)$r['replies']; $stats[$id]['chats'] = (int)$r['chats']; }
        }
        if (self::ready()) {
            foreach (Database::all("SELECT assigned_user_id u, COUNT(*) c FROM eb_persons WHERE assigned_user_id IS NOT NULL GROUP BY assigned_user_id") as $r) if (isset($stats[(int)$r['u']])) $stats[(int)$r['u']]['open'] = (int)$r['c'];
            foreach (Database::all("SELECT to_user_id u, COUNT(DISTINCT person_id) c FROM eb_assign_log WHERE action IN ('auto','claim') AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY) GROUP BY to_user_id") as $r) if (isset($stats[(int)$r['u']])) $stats[(int)$r['u']]['started'] = (int)$r['c'];
        }
        $out = array_values($stats);
        usort($out, fn($a, $b) => $b['replies'] <=> $a['replies']);
        return $out;
    }
}
