<?php
/**
 * modules/Desk.php
 * لایه‌ی میز پشتیبانی روی صندوق ورودی موجود:
 * وضعیت گفتگو (باز/منتظر مشتری/تعویق/بسته)، اپراتور و تیم، اولویت، ستاره،
 * برچسب گفتگو، یادداشت داخلی، رویدادهای سیستمی، SLA و ترجیحات ظاهر هر کاربر.
 *
 * گفتگو = شخص (مثل Inbox). وضعیت در eb_conv_state نگه داشته می‌شود؛ اگر ردیفی
 * نباشد یعنی «باز». پیام تازه‌ی مشتری بعد از بستن/تعویق، گفتگو را خودکار باز می‌کند
 * (بدون نیاز به دست زدن به مسیر دریافت پیام): وضعیت مؤثر در کوئری محاسبه می‌شود.
 */

class Desk
{
    const STATUSES   = ['open', 'pending', 'snoozed', 'closed'];
    const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private static $ready = false;

    /* ====================== اسکیما ====================== */

    public static function ensure(): void
    {
        if (self::$ready) return;
        $v = Database::one("SELECT v FROM eb_settings WHERE k='desk.schema'");
        if (($v['v'] ?? '') === '4') { self::$ready = true; return; }
        $pdo = Database::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_conv_state (
            person_id INT PRIMARY KEY,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            status_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            snooze_until DATETIME DEFAULT NULL,
            snooze_label VARCHAR(60) DEFAULT NULL,
            assignee_id INT DEFAULT NULL,
            team_id INT DEFAULT NULL,
            priority VARCHAR(10) NOT NULL DEFAULT 'normal',
            starred TINYINT(1) NOT NULL DEFAULT 0,
            labels VARCHAR(500) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_assignee (assignee_id), KEY idx_team (team_id), KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_conv_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            body TEXT,
            mentions VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_person (person_id, created_at), KEY idx_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(7) NOT NULL DEFAULT '#2F7A5E',
            members VARCHAR(500) DEFAULT NULL,
            rule VARCHAR(10) NOT NULL DEFAULT 'manual',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            ['eb_users', 'display_name', "VARCHAR(100) DEFAULT NULL"],
            ['eb_users', 'title', "VARCHAR(100) DEFAULT NULL"],
            ['eb_users', 'color', "VARCHAR(7) DEFAULT NULL"],
            ['eb_users', 'presence', "VARCHAR(10) NOT NULL DEFAULT 'online'"],
            ['eb_users', 'last_seen_at', "DATETIME DEFAULT NULL"],
            ['eb_users', 'ui_prefs', "TEXT"],
        ] as [$t, $c, $d]) {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'")->fetchColumn();
            if (!$has) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $d");
        }
        // نسخه‌ی ۳: فهرست خلاصه‌ی گفتگوها + یکی کردن مسئول چت با eb_persons.assigned_user_id
        ConvIndex::ensureTable();
        Database::forgetSchemaCache();
        if (Database::hasColumn('eb_persons', 'assigned_user_id')) {
            try {
                Database::run("UPDATE eb_persons p INNER JOIN eb_conv_state s ON s.person_id = p.id
                               SET p.assigned_user_id = s.assignee_id, p.assigned_at = COALESCE(p.assigned_at, s.updated_at)
                               WHERE p.assigned_user_id IS NULL AND s.assignee_id IS NOT NULL");
            } catch (Throwable $e) { error_log('[desk] ' . $e->getMessage()); }
        }
        // منبع اولویت (دستی یا قانون کلمه‌ی کلیدی)
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_conv_state' AND COLUMN_NAME = 'prio_src'")->fetchColumn();
            if (!$has) $pdo->exec("ALTER TABLE eb_conv_state ADD COLUMN prio_src VARCHAR(10) DEFAULT NULL");
        } catch (Throwable $e) {}
        Database::forgetSchemaCache();
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('desk.schema', '4') ON DUPLICATE KEY UPDATE v = '4'");
        self::$ready = true;
    }

    /* ====================== تنظیمات ====================== */

    public static function setting(string $k, $def = null)
    {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = ?", ['desk.' . $k]);
        return ($r && $r['v'] !== null && $r['v'] !== '') ? $r['v'] : $def;
    }

    public static function saveSetting(string $k, $v): void
    {
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ['desk.' . $k, (string)$v]);
    }

    /** مهلت اولین پاسخ بر اساس اولویت (دقیقه) */
    public static function slaMinutes(): array
    {
        $d = json_decode((string)self::setting('sla', ''), true);
        return is_array($d) ? $d + ['urgent' => 5, 'high' => 15, 'normal' => 30, 'low' => 120]
                            : ['urgent' => 5, 'high' => 15, 'normal' => 30, 'low' => 120];
    }

    /* ====================== فهرست گفتگو ====================== */

    /** شرط محدودیت گروه اپراتور روی p.id */
    private static function groupCond(array &$params): string
    {
        $g = Auth::allowedGroups();
        if ($g === null) return '';
        if (!$g) $g = [-1];
        foreach ($g as $x) $params[] = (int)$x;
        return "p.id IN (SELECT person_id FROM eb_person_groups WHERE group_id IN (" . implode(',', array_fill(0, count($g), '?')) . "))";
    }

    /**
     * پشتیبان عادی چت‌های همکاران را می‌بیند؟ (تنظیم مدیر؛ پیش‌فرض: خیر — فقط چت‌های خودش و بی‌مسئول‌ها)
     * مدیر و سرپرست همیشه همه را می‌بینند.
     */
    public static function seesOthers(): bool
    {
        if (Auth::isManager() || !Inbox::hasAssign()) return true;
        return self::setting('op_visibility', 'own') === 'all';
    }

    /** آیا کاربر جاری به این گفتگو (از نظر مسئول چت) دسترسی دارد؟ */
    public static function canSee(int $pid): bool
    {
        if (self::seesOthers()) return true;
        $a = (int)(Database::one("SELECT assigned_user_id a FROM eb_persons WHERE id = ?", [$pid])['a'] ?? 0);
        return !$a || $a === (int)Auth::id();
    }

    /** ستون مسئول چت (یکی برای همه‌ی بخش‌ها: eb_persons.assigned_user_id) */
    private static function assignCol(): string
    {
        return Inbox::hasAssign() ? "p.assigned_user_id" : "s.assignee_id";
    }

    /** عبارت SQL وضعیت مؤثر گفتگو */
    private static function effSql(): string
    {
        return "(CASE
              WHEN s.status IS NULL OR s.status = 'open' THEN 'open'
              WHEN s.status = 'snoozed' AND s.snooze_until IS NOT NULL AND s.snooze_until <= NOW() THEN 'open'
              WHEN s.status IN ('closed','pending','snoozed') AND x.last_in_at > s.status_at THEN 'open'
              ELSE s.status END)";
    }

    /** مهلت SLA بر حسب اولویت، به‌صورت عبارت SQL (دقیقه) */
    private static function slaSql(): string
    {
        $m = self::slaMinutes();
        return "(CASE COALESCE(s.priority,'normal') WHEN 'urgent' THEN " . (int)$m['urgent'] . " WHEN 'high' THEN " . (int)$m['high'] . " WHEN 'low' THEN " . (int)$m['low'] . " ELSE " . (int)$m['normal'] . " END)";
    }

    /**
     * FROM و WHERE پایه روی فهرست خلاصه (فقط گفتگوهایی که حداقل یک پیام دریافتی دارند).
     * خروجی: [from, conds[], params[]]
     */
    private static function base(array $f): array
    {
        ConvIndex::sync();
        $params = []; $c = ["x.last_in_id IS NOT NULL"];
        if (!empty($f['channel'])) { $c[] = "x.chans LIKE ?"; $params[] = '%,' . ($f['channel'] === 'instagram_apichi' ? 'instagram' : $f['channel']) . ',%'; }
        if (!empty($f['ig_account_id'])) { $c[] = "x.pages LIKE ?"; $params[] = '%,' . (int)$f['ig_account_id'] . ',%'; }
        if (!empty($f['q'])) {
            $q = trim((string)$f['q']);
            $like = '%' . $q . '%';
            $digits = preg_replace('/\D/', '', strtr($q, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
            $or = ["p.full_name LIKE ?", "p.id IN (SELECT person_id FROM eb_identities WHERE username LIKE ? OR first_name LIKE ?)"];
            array_push($params, $like, ltrim($like, '@'), $like);
            if (strlen($digits) >= 4) { $or[] = "p.phone LIKE ?"; $params[] = '%' . substr($digits, -10) . '%'; }
            $c[] = '(' . implode(' OR ', $or) . ')';
        }
        $gc = self::groupCond($params);
        if ($gc) $c[] = $gc;
        if (Inbox::hasBlock()) $c[] = !empty($f['blocked']) ? "p.blocked_at IS NOT NULL" : "p.blocked_at IS NULL";
        if (!self::seesOthers()) { $c[] = "(p.assigned_user_id IS NULL OR p.assigned_user_id = ?)"; $params[] = (int)Auth::id(); }
        $from = "FROM eb_conv_index x INNER JOIN eb_persons p ON p.id = x.person_id LEFT JOIN eb_conv_state s ON s.person_id = x.person_id";
        return [$from, $c, $params];
    }

    private static function aiSql(): string
    {
        return Database::hasColumn('eb_persons', 'ai_handoff_at') ? "(p.ai_handoff_at IS NOT NULL)" : "0";
    }

    /** شرط نما (view) */
    private static function viewCond(array $f, array &$params): array
    {
        $me = (int)Auth::id();
        $eff = self::effSql(); $as = self::assignCol(); $ai = self::aiSql();
        $c = [];
        $v = $f['view'] ?? 'all';
        if (!empty($f['team'])) { $c[] = "s.team_id = ? AND $eff <> 'closed'"; $params[] = (int)$f['team']; }
        elseif (!empty($f['label'])) { $c[] = "FIND_IN_SET(?, s.labels)"; $params[] = (int)$f['label']; }
        elseif (in_array($v, ['pending', 'snoozed', 'closed'], true)) { $c[] = "$eff = ?"; $params[] = $v; }
        elseif ($v === 'starred') { $c[] = "s.starred = 1"; }
        elseif ($v === 'mentions') { $c[] = "x.person_id IN (SELECT person_id FROM eb_conv_events WHERE type='note' AND FIND_IN_SET(?, mentions) AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY))"; $params[] = $me; }
        elseif ($v === 'everything') { /* همه، با هر وضعیت */ }
        else {
            $c[] = "$eff = 'open'";
            if ($v === 'mine') { $c[] = "$as = ?"; $params[] = $me; }
            if ($v === 'unassigned') $c[] = "$as IS NULL AND NOT $ai";
            if ($v === 'others') { $c[] = "$as IS NOT NULL AND $as <> ?"; $params[] = $me; }
            if ($v === 'ai') $c[] = $ai;
            if ($v === 'sla') $c[] = "x.waiting_since IS NOT NULL AND TIMESTAMPDIFF(MINUTE, x.waiting_since, NOW()) >= " . self::slaSql();
        }
        if (!empty($f['unread'])) $c[] = "x.unread > 0";
        return $c;
    }

    public static function listConversations(array $f, int $page = 1, int $per = 40): array
    {
        self::ensure();
        [$from, $c, $params] = self::base($f);
        $c = array_merge($c, self::viewCond($f, $params));
        $where = 'WHERE ' . implode(' AND ', $c);
        $order = [
            'old'  => 'x.last_in_at ASC',
            'sla'  => 'x.waiting_since IS NULL, x.waiting_since ASC',
            'pri'  => "FIELD(COALESCE(s.priority,'normal'),'urgent','high','normal','low'), x.last_at DESC",
            'unread' => 'x.unread = 0, x.last_at DESC',
        ][$f['sort'] ?? ''] ?? 'x.last_at DESC';
        $per = max(10, min(100, $per));
        $off = max(0, ($page - 1) * $per);
        $as = self::assignCol();
        $rows = Database::all("SELECT x.*, p.full_name, p.phone, p.status AS person_status, " . self::aiSql() . " AS ai_assigned,
                (SELECT CONCAT(i2.channel, '|', COALESCE(NULLIF(TRIM(i2.first_name), ''), ''), '|', COALESCE(NULLIF(TRIM(i2.username), ''), ''))
                   FROM eb_identities i2 WHERE i2.person_id = x.person_id ORDER BY (COALESCE(i2.first_name, '') = '' AND COALESCE(i2.username, '') = ''), i2.last_seen DESC LIMIT 1) AS ident_info,
                " . self::effSql() . " AS eff_status, s.snooze_until, s.snooze_label, $as AS assignee_id, s.team_id,
                COALESCE(s.priority,'normal') AS priority, COALESCE(s.starred,0) AS starred, s.labels
            $from $where ORDER BY $order, x.person_id DESC LIMIT $per OFFSET $off", $params);
        $total = (int)(Database::one("SELECT COUNT(*) c $from $where", $params)['c'] ?? 0);

        // متن و منبع آخرین پیام‌ها با شناسه (کلید اصلی) — بدون GROUP_CONCAT روی کل جدول
        $mids = [];
        foreach ($rows as $r) { if ($r['last_in_id']) $mids[] = (int)$r['last_in_id']; if ($r['last_out_id']) $mids[] = (int)$r['last_out_id']; }
        $msgs = [];
        if ($mids) {
            $src = Inbox::hasSource() ? "source" : "NULL AS source";
            $mt = Database::hasColumn('eb_messages', 'media_type') ? "media_type" : "NULL AS media_type";
            foreach (Database::all("SELECT id, body, $src, $mt, channel FROM eb_messages WHERE id IN (" . implode(',', array_fill(0, count($mids), '?')) . ")", $mids) as $m) $msgs[(int)$m['id']] = $m;
        }
        $pids = array_map(fn($r) => (int)$r['person_id'], $rows);
        $presence = ($pids && class_exists('ChatAssign')) ? ChatAssign::presenceFor($pids, (int)Auth::id()) : [];
        $sla = self::slaMinutes();
        foreach ($rows as &$r) $r = self::shape($r, $sla, $msgs, $presence);
        unset($r);
        return ['conversations' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
    }

    /** شمارش نماها برای ستون صف‌ها (کش کوتاه، چون هر ۱۵ ثانیه صدا زده می‌شود) */
    public static function counts(bool $fresh = false): array
    {
        self::ensure();
        $me = (int)Auth::id();
        $key = 'desk_counts_' . $me . '_' . md5(json_encode([Auth::allowedGroups(), self::seesOthers()]));
        if (!$fresh && class_exists('Cache') && ($c = Cache::get($key)) !== null && (Cache::get('desk_counts') ?? 0) <= ($c['_t'] ?? 0)) return $c;
        [$from, $c, $params] = self::base([]);
        $where = 'WHERE ' . implode(' AND ', $c);
        $eff = self::effSql(); $as = self::assignCol(); $ai = self::aiSql(); $sl = self::slaSql();
        // یک بار پیمایش فهرست خلاصه و شمردن همه‌ی نماها در PHP (به‌جای چند کوئری جدا)
        $out = ['all' => 0, 'mine' => 0, 'unassigned' => 0, 'others' => 0, 'ai' => 0, 'sla' => 0, 'starred' => 0, 'pending' => 0, 'snoozed' => 0, 'closed' => 0, 'unread_convs' => 0, 'everything' => 0, 'teams' => [], 'channels' => [], 'pages' => []];
        $st = Database::run("SELECT $eff AS e, $as AS a, $ai AS ai, (x.waiting_since IS NOT NULL AND TIMESTAMPDIFF(MINUTE, x.waiting_since, NOW()) >= $sl) AS late,
                COALESCE(s.starred,0) AS st, x.unread AS u, s.team_id AS t, x.chans, x.pages $from $where", $params);
        while ($r = $st->fetch()) {
            $out['everything']++;
            $e = $r['e']; $a = (int)$r['a']; $u = (int)$r['u'] > 0;
            if ((int)$r['st']) $out['starred']++;
            if ($r['t'] && $e !== 'closed') $out['teams'][(int)$r['t']] = ($out['teams'][(int)$r['t']] ?? 0) + 1;
            if ($e !== 'open') { if (isset($out[$e])) $out[$e]++; continue; }
            $out['all']++;
            if ($u) $out['unread_convs']++;
            if ($a === $me) $out['mine']++;
            elseif ($a) $out['others']++;
            elseif (!(int)$r['ai']) $out['unassigned']++;
            if ((int)$r['ai']) $out['ai']++;
            if ((int)$r['late']) $out['sla']++;
            foreach (array_filter(explode(',', (string)$r['chans'])) as $ch) { $out['channels'][$ch]['n'] = ($out['channels'][$ch]['n'] ?? 0) + 1; $out['channels'][$ch]['u'] = ($out['channels'][$ch]['u'] ?? 0) + ($u ? 1 : 0); }
            foreach (array_filter(explode(',', (string)$r['pages'])) as $pg) { $out['pages'][$pg]['n'] = ($out['pages'][$pg]['n'] ?? 0) + 1; $out['pages'][$pg]['u'] = ($out['pages'][$pg]['u'] ?? 0) + ($u ? 1 : 0); }
        }
        $out['mentions'] = (int)(Database::one("SELECT COUNT(DISTINCT person_id) c FROM eb_conv_events WHERE type='note' AND FIND_IN_SET(?, mentions) AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)", [$me])['c'] ?? 0);
        $out['building'] = ConvIndex::progress();
        $out['_t'] = microtime(true);
        if (class_exists('Cache')) Cache::set($key, $out, 20);
        return $out;
    }

    /** پس از هر تغییر وضعیت/پیام، شمارنده‌ها دوباره حساب شوند */
    public static function bumpCounts(): void
    {
        if (class_exists('Cache')) Cache::set('desk_counts', microtime(true), 3600);
    }

    const CH_LABEL = ['instagram' => 'کاربر اینستاگرام', 'webchat' => 'بازدیدکننده‌ی سایت', 'bale' => 'کاربر بله', 'bale_safir' => 'کاربر بله', 'telegram' => 'کاربر تلگرام', 'roubika' => 'کاربر روبیکا', 'sms' => 'پیامک', 'whatsapp' => 'کاربر واتساپ'];

    /**
     * ۱.۰.۱۳: نام نمایشی گفتگو — به‌جای «مشتری ۲۹۴۰»: نام پرونده ← نام یا @نام کاربری در کانال ← شماره ← «کاربر اینستاگرام ۲۹۴۰»
     */
    public static function displayName(array $r): string
    {
        $n = trim((string)($r['full_name'] ?? ''));
        if ($n !== '') return $n;
        [$ch, $first, $user] = array_pad(explode('|', (string)($r['ident_info'] ?? '')), 3, '');
        if ($first !== '') return $first . ($user !== '' ? ' (@' . ltrim($user, '@') . ')' : '');
        if ($user !== '') return '@' . ltrim($user, '@');
        if (!empty($r['phone'])) return '';   // شماره را پنل نشان می‌دهد
        $ch = $ch ?: (string)($r['last_channel'] ?? '');
        if (strpos($ch, 'instagram') === 0) $ch = 'instagram';
        return (self::CH_LABEL[$ch] ?? 'مشتری') . ' ' . strtr((string)(int)($r['person_id'] ?? 0), ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    private static function shape(array $r, array $sla, array $msgs = [], array $presence = []): array
    {
        $outLater = $r['last_out_at'] && $r['last_out_at'] >= $r['last_in_at'];
        $wait = null;
        if ($r['eff_status'] === 'open' && $r['waiting_since']) {
            $wait = (int)floor((time() - strtotime($r['waiting_since'])) / 60);
        }
        $target = $sla[$r['priority']] ?? 30;
        $mi = $msgs[(int)$r['last_in_id']] ?? []; $mo = $msgs[(int)$r['last_out_id']] ?? [];
        $lm = $outLater ? $mo : $mi;
        $preview = trim((string)($lm['body'] ?? ''));
        if ($preview === '' && !empty($lm['media_type'])) $preview = in_array($lm['media_type'], ['photo', 'image'], true) ? '🖼 تصویر' : ($lm['media_type'] === 'story_reply' ? '↩️ ریپلای استوری' : '📎 فایل');
        $names = class_exists('ChatAssign') ? ChatAssign::userNames() : [];
        return [
            'person_id'   => (int)$r['person_id'],
            'name'        => self::displayName($r),
            'phone'       => $r['phone'],
            'member'      => $r['person_status'] === 'member',
            'channel'     => $r['last_channel'],
            'channels'    => array_values(array_filter(explode(',', (string)$r['chans']))),
            'ig_account_id' => $r['last_ig_account_id'] ? (int)$r['last_ig_account_id'] : null,
            'status'      => $r['eff_status'],
            'snooze'      => $r['eff_status'] === 'snoozed' ? ($r['snooze_label'] ?: $r['snooze_until']) : null,
            'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : null,
            'assignee_name' => $r['assignee_id'] ? ($names[(int)$r['assignee_id']] ?? null) : null,
            'team_id'     => $r['team_id'] ? (int)$r['team_id'] : null,
            'priority'    => $r['priority'],
            'starred'     => (bool)$r['starred'],
            'labels'      => $r['labels'] ? array_map('intval', explode(',', $r['labels'])) : [],
            'unread'      => (int)$r['unread'],
            'ai'          => (bool)$r['ai_assigned'],
            'last_at'     => $r['last_at'],
            'last_in_at'  => $r['last_in_at'],
            'preview'     => mb_substr($preview, 0, 160),
            'last_dir'    => $outLater ? (!empty($mo['source']) && $mo['source'] !== 'reply' ? 'bot' : 'out') : 'in',
            'last_source' => $outLater ? ($mo['source'] ?? null) : null,
            'waiting_min' => $wait,
            'waiting_since' => $r['eff_status'] === 'open' ? $r['waiting_since'] : null,
            'sla_target'  => $target,
            'sla_left'    => $wait !== null ? $target - $wait : null,
            'viewers'     => $presence[(int)$r['person_id']] ?? [],
        ];
    }

    /* ====================== اولویت خودکار با کلمات کلیدی ====================== */

    const PRANK = ['low' => 0, 'normal' => 1, 'high' => 2, 'urgent' => 3];

    /** قانون‌ها: [{id, name, words:[..], match:'contains'|'word', priority, label, channels:[], active}] */
    public static function prioRules(): array
    {
        $r = json_decode((string)self::setting('prio_rules', ''), true);
        return is_array($r) ? $r : [];
    }

    public static function savePrioRules(array $rules): array
    {
        $clean = self::savePrioRulesPreview($rules);
        self::saveSetting('prio_rules', json_encode($clean, JSON_UNESCAPED_UNICODE));
        return $clean;
    }

    /** پاک‌سازی قانون‌ها بدون ذخیره (برای آزمایش) */
    public static function savePrioRulesPreview(array $rules): array
    {
        $clean = [];
        foreach (array_slice($rules, 0, 40) as $r) {
            if (!is_array($r)) continue;
            $raw = is_array($r['words'] ?? null) ? $r['words'] : preg_split('/[\n,،]+/u', (string)($r['words'] ?? ''));
            $words = array_values(array_unique(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), $raw), fn($w) => $w !== '')));
            if (!$words) continue;
            $clean[] = [
                'id' => preg_replace('/[^a-z0-9]/', '', (string)($r['id'] ?? '')) ?: substr(md5(json_encode($words) . microtime()), 0, 8),
                'name' => mb_substr(trim((string)($r['name'] ?? '')), 0, 60),
                'words' => array_slice($words, 0, 60),
                'match' => ($r['match'] ?? '') === 'word' ? 'word' : 'contains',
                'priority' => in_array($r['priority'] ?? '', self::PRIORITIES, true) ? $r['priority'] : 'high',
                'label' => !empty($r['label']) ? (int)$r['label'] : null,
                'channels' => array_values(array_filter(array_map('strval', (array)($r['channels'] ?? [])))),
                'active' => !isset($r['active']) || !empty($r['active']),
            ];
        }
        return $clean;
    }

    /** یکسان‌سازی متن فارسی برای مقایسه (ی/ک عربی، نیم‌فاصله، حروف بزرگ) */
    public static function normText(string $t): string
    {
        $t = str_replace(['ي', 'ك', 'ة', "\u{200C}", 'أ', 'إ', 'ۀ'], ['ی', 'ک', 'ه', ' ', 'ا', 'ا', 'ه'], $t);
        return mb_strtolower(preg_replace('/\s+/u', ' ', $t));
    }

    /** قانونی که با این متن جور است (اگر چند قانون جور بود، بالاترین اولویت) */
    public static function matchPrio(string $text, string $channel, ?array $rules = null): ?array
    {
        $rules = $rules ?? self::prioRules();
        $t = ' ' . self::normText($text) . ' ';
        $ch = $channel === 'instagram_apichi' ? 'instagram' : $channel;
        $best = null;
        foreach ($rules as $r) {
            if (empty($r['active'])) continue;
            if (!empty($r['channels']) && !in_array($ch, $r['channels'], true)) continue;
            foreach ($r['words'] as $w) {
                $wn = self::normText($w);
                if ($wn === '') continue;
                $hit = $r['match'] === 'word'
                    ? (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($wn, '/') . '(?![\p{L}\p{N}])/u', $t)
                    : mb_strpos($t, $wn) !== false;
                if ($hit) { if (!$best || self::PRANK[$r['priority']] > self::PRANK[$best['priority']]) $best = $r + ['hit' => $w]; break; }
            }
        }
        return $best;
    }

    /** اعمال قانون‌ها روی پیام‌های دریافتی تازه (از ConvIndex::sync صدا زده می‌شود) */
    public static function applyKeywordRules(int $afterId, int $toId): int
    {
        $rules = array_values(array_filter(self::prioRules(), fn($r) => !empty($r['active'])));
        if (!$rules) return 0;
        self::ensure();
        $hasSrc = Database::hasColumn('eb_conv_state', 'prio_src');
        $rows = Database::all("SELECT m.id, m.body, m.channel, i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id
                               WHERE m.id > ? AND m.id <= ? AND m.direction = 'in' AND m.body IS NOT NULL AND m.body <> '' ORDER BY m.id LIMIT 3000", [$afterId, $toId]);
        $n = 0;
        foreach ($rows as $m) {
            $hit = self::matchPrio((string)$m['body'], (string)$m['channel'], $rules);
            if (!$hit) continue;
            $pid = (int)$m['person_id'];
            $cur = Database::one("SELECT priority, labels FROM eb_conv_state WHERE person_id = ?", [$pid]);
            $curP = $cur['priority'] ?? 'normal';
            $labels = !empty($cur['labels']) ? array_map('intval', explode(',', $cur['labels'])) : [];
            $raise = self::PRANK[$hit['priority']] > (self::PRANK[$curP] ?? 1);
            $addLabel = $hit['label'] && !in_array((int)$hit['label'], $labels, true);
            if (!$raise && !$addLabel) continue;
            if ($addLabel) $labels[] = (int)$hit['label'];
            $p = $raise ? $hit['priority'] : $curP;
            Database::run("INSERT INTO eb_conv_state (person_id, status, priority, labels" . ($hasSrc ? ", prio_src" : "") . ")
                           VALUES (?, 'open', ?, ?" . ($hasSrc ? ", ?" : "") . ")
                           ON DUPLICATE KEY UPDATE priority = VALUES(priority), labels = VALUES(labels)" . ($hasSrc && $raise ? ", prio_src = 'rule'" : ""),
                array_merge([$pid, $p, $labels ? implode(',', $labels) : null], $hasSrc ? [$raise ? 'rule' : null] : []));
            self::event($pid, 'prio', $p . '|' . $hit['hit'] . '|' . ($hit['name'] ?? '') . '|' . ($addLabel ? $hit['label'] : ''), null);
            $n++;
        }
        if ($n) self::bumpCounts();
        return $n;
    }

    /* ====================== وضعیت و رویداد ====================== */

    public static function state(int $pid): array
    {
        self::ensure();
        $s = Database::one("SELECT * FROM eb_conv_state WHERE person_id = ?", [$pid]);
        $s = $s ?: ['person_id' => $pid, 'status' => 'open', 'assignee_id' => null, 'team_id' => null, 'priority' => 'normal', 'starred' => 0, 'labels' => null, 'snooze_until' => null, 'snooze_label' => null];
        // مسئول چت از یک منبع واحد (همان که تخصیص خودکار و «بر عهده گرفتن» استفاده می‌کنند)
        if (Inbox::hasAssign()) $s['assignee_id'] = Database::one("SELECT assigned_user_id a FROM eb_persons WHERE id = ?", [$pid])['a'] ?? null;
        return $s;
    }

    /** وضعیت مؤثر یک گفتگو (با در نظر گرفتن پیام تازه‌ی مشتری و پایان تعویق) */
    public static function effectiveStatus(int $pid): string
    {
        $s = self::state($pid);
        $st = $s['status'] ?? 'open';
        if ($st === 'open') return 'open';
        if ($st === 'snoozed' && !empty($s['snooze_until']) && strtotime($s['snooze_until']) <= strtotime((string)(Database::one("SELECT NOW() n")['n'] ?? 'now'))) return 'open';
        $last = Database::one("SELECT MAX(m.created_at) t FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'in'", [$pid]);
        if (!empty($last['t']) && !empty($s['status_at']) && $last['t'] > $s['status_at']) return 'open';
        return $st;
    }

    /**
     * تغییر وضعیت یک یا چند گفتگو. $set: status, snooze_until, snooze_label, assignee_id, team_id,
     * priority, starred, label_add, label_remove
     */
    public static function update(array $pids, array $set): int
    {
        self::ensure();
        $me = (int)Auth::id();
        $n = 0;
        foreach ($pids as $pid) {
            $pid = (int)$pid; if ($pid <= 0) continue;
            $cur = self::state($pid);
            $new = $cur; $events = [];
            if (isset($set['status']) && in_array($set['status'], self::STATUSES, true) && $set['status'] !== $cur['status']) {
                $new['status'] = $set['status'];
                $new['snooze_until'] = null;
                if ($set['status'] === 'snoozed') {
                    $mins = max(1, min(60 * 24 * 60, (int)($set['snooze_minutes'] ?? 0)));
                    // زمان از خود MySQL تا با NOW() کوئری‌ها هم‌منطقه باشد
                    $new['snooze_until'] = !empty($set['snooze_minutes'])
                        ? (Database::one("SELECT DATE_ADD(NOW(), INTERVAL ? MINUTE) t", [$mins])['t'] ?? null)
                        : null;
                }
                $new['snooze_label'] = $set['status'] === 'snoozed' ? mb_substr((string)($set['snooze_label'] ?? ''), 0, 60) : null;
                $events[] = ['status', $set['status'] . ($new['snooze_label'] ? '|' . $new['snooze_label'] : '')];
            }
            if (array_key_exists('assignee_id', $set)) {
                $a = $set['assignee_id'] ? (int)$set['assignee_id'] : null;
                if ($a !== ($cur['assignee_id'] ? (int)$cur['assignee_id'] : null)) {
                    $new['assignee_id'] = $a;
                    if (class_exists('ChatAssign')) ChatAssign::setAssignee($pid, $a, $me, $set['note'] ?? null);
                    if ($a && !array_key_exists('team_id', $set)) {
                        $t = self::teamOfUser($a); if ($t) $new['team_id'] = $t;
                    }
                    $events[] = ['assign', (string)($a ?? 0)];
                }
            }
            if (array_key_exists('team_id', $set)) {
                $t = $set['team_id'] ? (int)$set['team_id'] : null;
                if ($t !== ($cur['team_id'] ? (int)$cur['team_id'] : null)) { $new['team_id'] = $t; $events[] = ['team', (string)($t ?? 0)]; }
            }
            $prioSrc = $cur['prio_src'] ?? null;
            if (isset($set['priority']) && in_array($set['priority'], self::PRIORITIES, true)) { $new['priority'] = $set['priority']; $prioSrc = 'manual'; }
            // بستن گفتگو: اولویتی که قانون کلمه‌ی کلیدی داده بود برای دور بعد به «عادی» برمی‌گردد
            elseif (($set['status'] ?? '') === 'closed' && $prioSrc === 'rule') { $new['priority'] = 'normal'; $prioSrc = null; }
            if (isset($set['starred'])) $new['starred'] = $set['starred'] ? 1 : 0;
            $labels = $cur['labels'] ? array_map('intval', explode(',', $cur['labels'])) : [];
            if (!empty($set['label_add'])) { $labels[] = (int)$set['label_add']; $labels = array_values(array_unique($labels)); }
            if (!empty($set['label_remove'])) $labels = array_values(array_diff($labels, [(int)$set['label_remove']]));
            $new['labels'] = $labels ? implode(',', $labels) : null;

            Database::run(
                "INSERT INTO eb_conv_state (person_id, status, status_at, snooze_until, snooze_label, assignee_id, team_id, priority, starred, labels)
                 VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status),
                   status_at = IF(VALUES(status) <> eb_conv_state.status, NOW(), eb_conv_state.status_at),
                   snooze_until = VALUES(snooze_until), snooze_label = VALUES(snooze_label), assignee_id = VALUES(assignee_id),
                   team_id = VALUES(team_id), priority = VALUES(priority), starred = VALUES(starred), labels = VALUES(labels)",
                [$pid, $new['status'], $new['snooze_until'], $new['snooze_label'], $new['assignee_id'], $new['team_id'], $new['priority'], (int)$new['starred'], $new['labels']]
            );
            if ($prioSrc !== ($cur['prio_src'] ?? null) && Database::hasColumn('eb_conv_state', 'prio_src')) Database::run("UPDATE eb_conv_state SET prio_src = ? WHERE person_id = ?", [$prioSrc, $pid]);
            foreach ($events as [$type, $body]) self::event($pid, $type, $body, $me);
            $n++;
        }
        self::bumpCounts();
        return $n;
    }

    public static function event(int $pid, string $type, string $body, ?int $userId = null, ?string $mentions = null): int
    {
        self::ensure();
        Database::run("INSERT INTO eb_conv_events (person_id, user_id, type, body, mentions) VALUES (?, ?, ?, ?, ?)", [$pid, $userId, $type, $body, $mentions]);
        return Database::lastId();
    }

    /** یادداشت داخلی با منشن (@نام‌کاربری یا @نام نمایشی) */
    public static function note(int $pid, string $body): array
    {
        $body = trim($body);
        if ($body === '') return ['ok' => false, 'error' => 'یادداشت خالی است'];
        $ids = [];
        foreach (self::users() as $u) {
            foreach (array_filter([$u['username'], $u['name'], explode(' ', (string)$u['name'])[0] ?? '']) as $handle) {
                if ($handle !== '' && mb_stripos($body, '@' . $handle) !== false) { $ids[] = (int)$u['id']; break; }
            }
        }
        $ids = array_values(array_unique($ids));
        $id = self::event($pid, 'note', $body, (int)Auth::id(), $ids ? implode(',', $ids) : null);
        if ($ids && class_exists('Notifier')) {
            $names = [];
            foreach (self::users() as $u) if (in_array((int)$u['id'], $ids, true)) $names[] = $u['name'];
            try { Notifier::push('mention', 'منشن در یادداشت داخلی: ' . implode('، ', $names), mb_substr($body, 0, 200), 'index.php#inbox.' . $pid, $pid); } catch (Throwable $e) {}
        }
        return ['ok' => true, 'id' => $id, 'mentions' => $ids];
    }

    /** رویدادها و یادداشت‌های یک گفتگو */
    public static function events(int $pid): array
    {
        self::ensure();
        return Database::all("SELECT id, user_id, type, body, mentions, created_at FROM eb_conv_events WHERE person_id = ? ORDER BY created_at ASC, id ASC LIMIT 300", [$pid]);
    }

    /* ====================== کاربران و تیم‌ها ====================== */

    public static function users(): array
    {
        self::ensure();
        self::ensureAvatar();
        $rows = Database::all("SELECT id, username, role, is_active, display_name, title, color, avatar, presence, last_seen_at FROM eb_users WHERE role <> 'viewer' ORDER BY role = 'admin' DESC, id ASC");
        $palette = ['#1D5C4D', '#8A5A1C', '#3D5A80', '#7A3E48', '#4F6B3A', '#2E6670', '#6B4F7A', '#9A4E2A'];
        $out = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $seen = $r['last_seen_at'] ? strtotime($r['last_seen_at']) : 0;
            $presence = $r['presence'] ?: 'online';
            if (time() - $seen > 600) $presence = 'offline';   // ۱۰ دقیقه بی‌فعالیت = آفلاین
            $out[] = [
                'id' => $id, 'username' => $r['username'], 'name' => $r['display_name'] ?: $r['username'],
                'title' => $r['title'] ?: (['admin' => 'مدیر', 'supervisor' => 'سرپرست'][$r['role']] ?? 'اپراتور'), 'role' => $r['role'],
                'color' => $r['color'] ?: $palette[$id % count($palette)], 'active' => (bool)$r['is_active'],
                'status' => $presence, 'presence' => $r['presence'] ?: 'online', 'last_seen' => $r['last_seen_at'], 'team_id' => self::teamOfUser($id),
                'avatar' => (string)($r['avatar'] ?? ''),
            ];
        }
        return $out;
    }

    /** ۱.۰.۱۳: ستون تصویر پروفایل همکاران */
    public static function ensureAvatar(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try { if (!Database::hasColumn('eb_users', 'avatar')) { Database::pdo()->exec("ALTER TABLE eb_users ADD COLUMN avatar VARCHAR(255) NOT NULL DEFAULT ''"); Database::forgetSchemaCache(); } } catch (Throwable $e) {}
    }

    public static function touch(): void
    {
        try {
            self::ensure();
            Database::run("UPDATE eb_users SET last_seen_at = NOW() WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE))", [(int)Auth::id()]);
        } catch (Throwable $e) {}
    }

    public static function setPresence(string $p): void
    {
        if (!in_array($p, ['online', 'busy', 'away'], true)) return;
        self::ensure();
        Database::run("UPDATE eb_users SET presence = ?, last_seen_at = NOW() WHERE id = ?", [$p, (int)Auth::id()]);
    }

    public static function teams(): array
    {
        self::ensure();
        $rows = Database::all("SELECT * FROM eb_teams ORDER BY id");
        foreach ($rows as &$t) {
            $t['id'] = (int)$t['id'];
            $t['members'] = $t['members'] ? array_map('intval', explode(',', $t['members'])) : [];
        }
        return $rows;
    }

    private static $teamMap = null;
    public static function teamOfUser(int $uid): ?int
    {
        if (self::$teamMap === null) {
            self::$teamMap = [];
            foreach (Database::all("SELECT id, members FROM eb_teams") as $t) {
                foreach (explode(',', (string)$t['members']) as $m) if ($m !== '' && !isset(self::$teamMap[(int)$m])) self::$teamMap[(int)$m] = (int)$t['id'];
            }
        }
        return self::$teamMap[$uid] ?? null;
    }

    public static function saveTeam(array $in): int
    {
        self::ensure();
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('نام تیم لازم است');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : '#2F7A5E';
        $members = implode(',', array_map('intval', (array)($in['members'] ?? [])));
        $rule = in_array($in['rule'] ?? '', ['manual', 'round', 'load'], true) ? $in['rule'] : 'manual';
        if (!empty($in['id'])) {
            Database::run("UPDATE eb_teams SET name=?, color=?, members=?, rule=? WHERE id=?", [$name, $color, $members, $rule, (int)$in['id']]);
            return (int)$in['id'];
        }
        Database::run("INSERT INTO eb_teams (name, color, members, rule) VALUES (?, ?, ?, ?)", [$name, $color, $members, $rule]);
        return Database::lastId();
    }

    /* ====================== ترجیحات ظاهر ====================== */

    public static function prefs(): array
    {
        self::ensure();
        $r = Database::one("SELECT ui_prefs FROM eb_users WHERE id = ?", [(int)Auth::id()]);
        $p = json_decode((string)($r['ui_prefs'] ?? ''), true);
        if (!is_array($p)) {
            $team = json_decode((string)self::setting('default_prefs', ''), true);
            $p = is_array($team) ? $team : [];
        }
        return $p;
    }

    public static function savePrefs(array $p): void
    {
        self::ensure();
        $allowed = ['theme', 'accent', 'custom', 'font', 'dfont', 'radius', 'density', 'fs', 'side', 'surface', 'collapsed', 'motion', 'ib'];
        $clean = array_intersect_key($p, array_flip($allowed));
        // ادغام با ترجیحات قبلی، تا ذخیره‌ی چیدمان صندوق ورودی تنظیمات ظاهر را پاک نکند (و برعکس)
        $old = json_decode((string)(Database::one("SELECT ui_prefs FROM eb_users WHERE id = ?", [(int)Auth::id()])['ui_prefs'] ?? ''), true);
        if (is_array($old)) $clean = array_merge(array_intersect_key($old, array_flip($allowed)), $clean);
        if (isset($clean['ib']) && strlen(json_encode($clean['ib'])) > 2000) unset($clean['ib']);
        Database::run("UPDATE eb_users SET ui_prefs = ? WHERE id = ?", [json_encode($clean, JSON_UNESCAPED_UNICODE), (int)Auth::id()]);
    }
}
