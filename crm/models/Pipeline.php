<?php
/**
 * جاکجاست - پیگیری مرحله‌ای شماره‌ها (Pipeline)
 *
 * هر شماره‌ی خارجی (۱۰ رقم آخر) یک ردیف در call_numbers دارد: نام، مرحله، مسئول پیگیری.
 * هر تغییر مرحله در call_number_stage_log ثبت می‌شود (چه دستی چه خودکار).
 *
 * قوانین خودکار (processNewCalls، از کرون و هنگام باز شدن صفحه):
 *  - تماس پاسخ‌داده‌شده (ورودی/خروجی) → «در حال پیگیری» (اگر شماره مرحله ندارد، «عدم پاسخ» یا «عدم رزرو» است)
 *  - تماس ورودی بی‌پاسخ → «عدم پاسخ» (اگر شماره مرحله ندارد یا «عدم رزرو» است)
 *  - هیچ شماره‌ای بیش از N روز (پیش‌فرض ۳) در مراحل میانی نمی‌ماند → خودکار «عدم رزرو» (expireStale)
 */
class Pipeline
{
    public static $stages = [
        'no_answer'    => 'عدم پاسخ',
        'followup'     => 'در حال پیگیری',
        'host'         => 'میزبان',
        'link_sent'    => 'ارسال لینک اقامتگاه',
        'confirmed'    => 'تایید شده',
        'reserved'     => 'رزرو',
        'not_reserved' => 'عدم رزرو',
    ];
    /** «میزبان» مرحله‌ی میانی نیست: مهلت ۳ روزه‌ی بستن خودکار ندارد (جذب میزبان زمان‌بر است) */
    public static $openStages = ['no_answer', 'followup', 'link_sent', 'confirmed'];
    public static $roles = ['guest' => 'مهمان', 'host' => 'میزبان'];
    private static $schemaOk = false;

    /** ستون‌های تازه‌ی ۲.۰.۸ (مرجع، نقش، تماس گرفته شد) — خودکار */
    public static function ensureSchema()
    {
        if (self::$schemaOk) return;
        self::$schemaOk = true;
        if (Database::schemaDone(__METHOD__)) return;
        $db = Database::getInstance();
        $failed = false;
        foreach ([
            'ref_source'     => "VARCHAR(80) DEFAULT NULL",
            'ref_role'       => "VARCHAR(10) DEFAULT NULL",
            'called_back_at' => "DATETIME DEFAULT NULL",
            'called_back_by' => "VARCHAR(120) DEFAULT NULL",
        ] as $col => $def) {
            try { $db->fetchColumn("SELECT `{$col}` FROM call_numbers LIMIT 1"); }
            catch (\Throwable $e) {
                try { $db->execute("ALTER TABLE call_numbers ADD COLUMN `{$col}` {$def}"); }
                catch (\Throwable $e2) { $failed = true; Logger::error('Pipeline::ensureSchema ' . $col . ': ' . $e2->getMessage()); }
            }
        }
        if (!$failed) Database::markSchemaDone(__METHOD__);
    }

    public static function norm($n)
    {
        $d = preg_replace('/\D+/', '', Helpers::toEnglishDigits((string)$n));
        return strlen($d) >= 10 ? substr($d, -10) : null;
    }

    public static function maxDays()
    {
        return max(1, (int)Report::getSetting('pipeline_max_days', 3));
    }

    public static function missedAlertSeconds()
    {
        return max(1, (int)Report::getSetting('missed_alert_minutes', 30)) * 60;
    }

    public static function isManager($user = null)
    {
        $user = $user ?: Auth::currentUser();
        return $user && in_array($user['role'], ['admin', 'manager'], true);
    }

    public static function get($phone10)
    {
        self::ensureSchema();
        return Database::getInstance()->fetchOne('SELECT * FROM call_numbers WHERE phone10 = ?', [$phone10]);
    }

    private static function ensureRow($phone10, $phone)
    {
        Database::getInstance()->execute(
            'INSERT IGNORE INTO call_numbers (phone10, phone) VALUES (?, ?)', [$phone10, '0' . $phone10]
        );
        return self::get($phone10);
    }

    /** تغییر مرحله + ثبت در لاگ */
    public static function setStage($phone10, $toStage, $opts = [])
    {
        if ($toStage !== null && !isset(self::$stages[$toStage])) throw new InvalidArgumentException('مرحله نامعتبر');
        $db = Database::getInstance();
        $row = self::ensureRow($phone10, $opts['phone'] ?? null);
        $from = $row['stage'] ?? null;
        $at = $opts['at'] ?? date('Y-m-d H:i:s');
        if ($from === $toStage && empty($opts['force_log'])) return false;
        $newCycle = in_array($toStage, self::$openStages, true) && !in_array($from, self::$openStages, true);
        $db->execute(
            'UPDATE call_numbers SET stage = ?, stage_since = ?' . ($newCycle ? ', cycle_started_at = ?' : '') . ' WHERE phone10 = ?',
            $newCycle ? [$toStage, $at, $at, $phone10] : [$toStage, $at, $phone10]
        );
        $user = $opts['user'] ?? null;
        $db->execute(
            'INSERT INTO call_number_stage_log (phone10, from_stage, to_stage, user_id, user_name, is_auto, reason, call_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$phone10, $from, $toStage, $user['id'] ?? null, $user['full_name'] ?? ($opts['user_name'] ?? null),
             empty($opts['auto']) ? 0 : 1, isset($opts['reason']) ? mb_substr($opts['reason'], 0, 255) : null, $opts['call_id'] ?? null, $at]
        );
        return true;
    }

    /** پردازش تماس‌های جدید و اعمال قوانین خودکار. idempotent است. */
    public static function processNewCalls($maxRows = 3000)
    {
        $db = Database::getInstance();
        $lock = $db->fetchColumn("SELECT GET_LOCK('jj_pipeline', 0)");
        if (!$lock) return ['skipped' => 'locked'];
        $stats = ['calls' => 0, 'changed' => 0];
        try {
            $cp = Report::getSetting('pipeline_checkpoint');
            if (!$cp) $cp = date('Y-m-d H:i:s', time() - self::maxDays() * 86400);
            $since = date('Y-m-d H:i:s', strtotime($cp) - 7200);
            $runStart = date('Y-m-d H:i:s');
            $calls = $db->fetchAll(
                "SELECT c.id, c.type, c.status, c.call_source, c.call_dest, c.started_at, c.exten_number, c.time_talk, s.user_id AS staff_user_id
                 FROM calls c LEFT JOIN staff s ON s.exten_number = c.exten_number AND c.exten_number <> ''
                 WHERE c.started_at >= ? AND c.type IN ('inbound','outbound') AND c.status IN ('answered','no_answer','busy','failed')
                 ORDER BY c.started_at ASC LIMIT " . (int)$maxRows,
                [$since]
            );
            foreach ($calls as $c) {
                $ext = $c['type'] === 'outbound' ? $c['call_dest'] : $c['call_source'];
                $p10 = self::norm($ext);
                if (!$p10) continue;
                $stats['calls']++;
                $row = self::get($p10);
                if ($row && $row['last_event_at'] && $row['last_event_at'] >= $c['started_at']) continue;
                if (!$row) $row = self::ensureRow($p10, $ext);
                $stage = $row['stage'];
                // ت۸: تماسی که «پاسخ داده شد» ثبت شده ولی هیچ مکالمه‌ای نداشته (مثلاً پیغام‌گیر/IVR) پاسخ‌داده‌شده حساب نمی‌شود
                $answered = $c['status'] === 'answered' && (int)($c['time_talk'] ?? 0) > 0;
                $dirTxt = $c['type'] === 'inbound' ? 'ورودی' : 'خروجی';
                $to = null; $reason = null;
                if ($answered && in_array($stage, [null, '', 'no_answer', 'not_reserved'], true)) {
                    $to = 'followup'; $reason = "تماس {$dirTxt} پاسخ داده شد";
                } elseif (!$answered && $c['type'] === 'inbound' && in_array($stage, [null, '', 'not_reserved'], true)) {
                    $to = 'no_answer'; $reason = 'تماس ورودی بی‌پاسخ';
                }
                if ($to && self::setStage($p10, $to, ['auto' => 1, 'reason' => $reason, 'call_id' => $c['id'], 'at' => $c['started_at'], 'user_name' => 'سیستم'])) $stats['changed']++;
                $upd = 'UPDATE call_numbers SET last_event_at = ?, last_call_id = ?, last_direction = ?, last_status = ?';
                $b = [$c['started_at'], $c['id'], $c['type'], $c['status']];
                // پاسخ‌دهنده مسئول پیگیری می‌شود اگر هنوز کسی تعیین نشده
                if ($answered && empty($row['assigned_user_id']) && !empty($c['staff_user_id'])) {
                    $upd .= ', assigned_user_id = ?, assigned_at = ?';
                    array_push($b, $c['staff_user_id'], $c['started_at']);
                }
                // ت۵: تماس خروجی بعد از یک بی‌پاسخی = «تماس گرفته شد» (خودکار)
                if ($c['type'] === 'outbound' && $stage === 'no_answer') {
                    $upd .= ', called_back_at = ?, called_back_by = ?';
                    array_push($b, $c['started_at'], 'تماس خروجی (خودکار)');
                }
                $b[] = $p10;
                $db->execute($upd . ' WHERE phone10 = ?', $b);
            }
            if (count($calls) < $maxRows) {
                $db->execute('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['pipeline_checkpoint', $runStart]);
            } elseif ($calls) {
                $db->execute('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['pipeline_checkpoint', end($calls)['started_at']]);
            }
            $stats['expired'] = self::expireStale();
            $stats['auto_assigned'] = self::autoAssign();
        } finally {
            $db->fetchColumn("SELECT RELEASE_LOCK('jj_pipeline')");
        }
        return $stats;
    }

    /** شماره‌هایی که بیش از N روز در مراحل میانی مانده‌اند → عدم رزرو (خودکار) */
    public static function expireStale()
    {
        $db = Database::getInstance();
        $days = self::maxDays();
        $rows = $db->fetchAll(
            "SELECT phone10, stage FROM call_numbers WHERE stage IN ('no_answer','followup','link_sent','confirmed')
             AND cycle_started_at < DATE_SUB(NOW(), INTERVAL {$days} DAY) LIMIT 500"
        );
        foreach ($rows as $r) {
            self::setStage($r['phone10'], 'not_reserved', ['auto' => 1, 'user_name' => 'سیستم',
                'reason' => "بیش از {$days} روز در مرحله‌ی «" . self::$stages[$r['stage']] . "» ماند (بستن خودکار)"]);
        }
        return count($rows);
    }

    /** شرط دید کاربر غیرمدیر: شماره‌های خودش + بدون مسئول */
    private static function visibilitySql($user, &$bind)
    {
        if (self::isManager($user)) return '';
        $ids = self::aliasIds((int)$user['id']);
        foreach ($ids as $i) $bind[] = $i;
        return ' AND (n.assigned_user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') OR n.assigned_user_id IS NULL)';
    }

    /** ت۱۰: حساب‌های هم‌نام (مثلاً حساب CRM و حساب متصل‌شده) یک نفر حساب می‌شوند */
    public static function aliasIds($uid)
    {
        $uid = (int)$uid;
        try {
            $ids = Database::getInstance()->fetchAll(
                'SELECT u2.id FROM users u1 JOIN users u2 ON TRIM(u2.full_name) = TRIM(u1.full_name) WHERE u1.id = ?', [$uid]);
            $out = array_map(function ($r) { return (int)$r['id']; }, $ids);
        } catch (\Throwable $e) { $out = []; }
        if (!in_array($uid, $out, true)) $out[] = $uid;
        return array_values(array_unique($out));
    }

    /** ت۱۰: فهرست مسئول‌ها بدون تکرار (هر نام یک بار؛ حساب فعال‌تر ترجیح داده می‌شود) */
    public static function assignableUsers()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT id, full_name, role, exten_number, last_activity FROM users
             WHERE is_active = 1 AND username <> 'admin' ORDER BY (exten_number IS NULL OR exten_number = ''), last_activity DESC, id ASC");
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $k = preg_replace('/\s+/u', ' ', trim($r['full_name']));
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true; $out[] = ['id' => (int)$r['id'], 'full_name' => $k, 'role' => $r['role']];
        }
        usort($out, function ($a, $b) { return strcmp($a['full_name'], $b['full_name']); });
        return $out;
    }

    private static function assigneeSql($user, $a, &$w, &$bind)
    {
        if ($a === 'me') { $ids = self::aliasIds((int)$user['id']); }
        elseif ($a === 'none') { $w .= ' AND n.assigned_user_id IS NULL'; return; }
        elseif (ctype_digit((string)$a)) { $ids = self::aliasIds((int)$a); }
        else return;
        $w .= ' AND n.assigned_user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $i) $bind[] = $i;
    }

    public static function counts($user, $assignee = '')
    {
        $bind = [];
        $w = self::visibilitySql($user, $bind);
        self::assigneeSql($user, $assignee, $w, $bind);
        $days = self::maxDays();
        $rows = Database::getInstance()->fetchAll(
            "SELECT n.stage, COUNT(*) AS cnt,
                    SUM(n.stage IN ('no_answer','followup','link_sent','confirmed') AND n.cycle_started_at < DATE_SUB(NOW(), INTERVAL " . ($days * 24 - 24) . " HOUR)) AS warn
             FROM call_numbers n WHERE n.stage IS NOT NULL {$w} GROUP BY n.stage", $bind
        );
        $out = [];
        foreach (self::$stages as $k => $l) $out[$k] = ['label' => $l, 'count' => 0, 'warn' => 0];
        foreach ($rows as $r) if (isset($out[$r['stage']])) { $out[$r['stage']]['count'] = (int)$r['cnt']; $out[$r['stage']]['warn'] = (int)$r['warn']; }
        return $out;
    }

    public static function listByStage($user, $stage, $params = [])
    {
        self::ensureSchema();
        $db = Database::getInstance();
        $bind = [$stage];
        $w = 'n.stage = ?' . self::visibilitySql($user, $bind);
        $a = $params['assignee'] ?? '';
        self::assigneeSql($user, $a, $w, $bind);
        // ت۸: در «در حال پیگیری» می‌شود فقط آن‌هایی را دید که آخرین تماسشان بی‌پاسخ است (یا فقط پاسخ‌داده‌شده‌ها)
        if (($params['last'] ?? '') === 'missed') $w .= " AND n.last_status IS NOT NULL AND n.last_status <> 'answered'";
        elseif (($params['last'] ?? '') === 'answered') $w .= " AND n.last_status = 'answered'";
        if (!empty($params['search'])) {
            $s = Helpers::toEnglishDigits(trim($params['search']));
            $w .= ' AND (n.name LIKE ? OR n.phone10 LIKE ?)';
            $bind[] = '%' . $s . '%'; $bind[] = '%' . ltrim(preg_replace('/\D+/', '', $s), '0') . '%';
        }
        $limit = min(300, max(1, (int)($params['limit'] ?? 200)));
        $order = in_array($stage, ['reserved', 'not_reserved'], true) ? 'n.stage_since DESC' : 'n.cycle_started_at ASC';
        $rows = $db->fetchAll(
            "SELECT n.*, u.full_name AS assigned_name,
                    TIMESTAMPDIFF(SECOND, n.stage_since, NOW()) AS in_stage_seconds,
                    TIMESTAMPDIFF(SECOND, n.cycle_started_at, NOW()) AS cycle_seconds
             FROM call_numbers n LEFT JOIN users u ON u.id = n.assigned_user_id
             WHERE {$w} ORDER BY {$order} LIMIT {$limit}", $bind
        );
        $maxSec = self::maxDays() * 86400;
        $ids = [];
        foreach ($rows as &$r) {
            $r['stage_label'] = self::$stages[$r['stage']] ?? $r['stage'];
            $r['phone'] = '0' . $r['phone10'];
            $r['is_open'] = in_array($r['stage'], self::$openStages, true);
            $r['remaining_seconds'] = $r['is_open'] ? $maxSec - (int)$r['cycle_seconds'] : null;
            $r['stage_since_jalali'] = $r['stage_since'] ? Jalali::fromGregorian($r['stage_since'], 'Y/m/d H:i') : null;
            $r['last_event_jalali'] = $r['last_event_at'] ? Jalali::fromGregorian($r['last_event_at'], 'Y/m/d H:i') : null;
            $ids[] = $r['phone10'];
        }
        unset($r);
        // ت۲: «قدیمی» = از تماس دوم به بعد یا مشتری CRM
        $info = self::oldInfo($ids);
        foreach ($rows as &$r) {
            $i = $info[$r['phone10']] ?? ['calls' => 0, 'customer' => false];
            $r['call_count'] = $i['calls'];
            $r['is_old'] = $i['calls'] >= 2 || $i['customer'];
            $r['ref_role_label'] = self::$roles[$r['ref_role'] ?? ''] ?? null;
        }
        unset($r);
        // برای رزرو / عدم رزرو: کل مسیر مراحل
        if (!empty($params['with_history']) && $ids) {
            $hist = self::historyFor($ids);
            foreach ($rows as &$r) $r['history'] = $hist[$r['phone10']] ?? [];
            unset($r);
        }
        return $rows;
    }

    public static function historyFor(array $phone10s)
    {
        if (!$phone10s) return [];
        $in = implode(',', array_fill(0, count($phone10s), '?'));
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM call_number_stage_log WHERE phone10 IN ({$in}) ORDER BY created_at ASC, id ASC", array_values($phone10s)
        );
        $out = [];
        foreach ($rows as $r) {
            $r['from_label'] = $r['from_stage'] ? (self::$stages[$r['from_stage']] ?? $r['from_stage']) : null;
            $r['to_label'] = $r['to_stage'] ? (self::$stages[$r['to_stage']] ?? $r['to_stage']) : null;
            $r['jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d H:i');
            $out[$r['phone10']][] = $r;
        }
        return $out;
    }

    public static function canAccess($user, $row)
    {
        if (self::isManager($user)) return true;
        return !$row || empty($row['assigned_user_id']) || in_array((int)$row['assigned_user_id'], self::aliasIds((int)$user['id']), true);
    }

    public static function setName($phone10, $name, $source = 'manual')
    {
        self::ensureRow($phone10, null);
        Database::getInstance()->execute('UPDATE call_numbers SET name = ?, name_source = ? WHERE phone10 = ?',
            [$name !== '' ? mb_substr($name, 0, 150) : null, $name !== '' ? $source : null, $phone10]);
    }

    public static function assign($phone10, $userId, $byUser)
    {
        $db = Database::getInstance();
        $row = self::ensureRow($phone10, null);
        $db->execute('UPDATE call_numbers SET assigned_user_id = ?, assigned_by = ?, assigned_at = NOW() WHERE phone10 = ?',
            [$userId ?: null, $byUser['id'], $phone10]);
        $toName = $userId ? $db->fetchColumn('SELECT full_name FROM users WHERE id = ?', [$userId]) : 'بدون مسئول';
        // ارجاع: اگر مرحله ندارد یا بسته است، وارد «در حال پیگیری» شود
        $stage = $row['stage'];
        if ($userId && !in_array($stage, self::$openStages, true) && $stage !== 'reserved') {
            self::setStage($phone10, 'followup', ['user' => $byUser, 'reason' => 'ارجاع به ' . $toName]);
        } else {
            $db->execute('INSERT INTO call_number_stage_log (phone10, from_stage, to_stage, user_id, user_name, is_auto, reason) VALUES (?,?,?,?,?,0,?)',
                [$phone10, $stage, $stage, $byUser['id'], $byUser['full_name'], 'ارجاع به ' . $toName]);
        }
    }

    /** شمار تماس‌های هر شماره و اینکه مشتری CRM است یا نه */
    public static function oldInfo(array $phone10s)
    {
        $phone10s = array_values(array_unique(array_filter($phone10s)));
        if (!$phone10s) return [];
        $db = Database::getInstance();
        $in = implode(',', array_fill(0, count($phone10s), '?'));
        $out = [];
        foreach ($phone10s as $p) $out[$p] = ['calls' => 0, 'customer' => false];
        foreach ([['inbound', 'call_source'], ['outbound', 'call_dest']] as [$t, $col]) {
            foreach ($db->fetchAll("SELECT RIGHT({$col}, 10) AS p, COUNT(*) AS n FROM calls WHERE type = '{$t}' AND RIGHT({$col}, 10) IN ({$in}) GROUP BY p", $phone10s) as $r) {
                $out[$r['p']]['calls'] += (int)$r['n'];
            }
        }
        try {
            foreach ($db->fetchAll("SELECT RIGHT(mobile, 10) AS p FROM customers WHERE RIGHT(mobile, 10) IN ({$in})", $phone10s) as $r) $out[$r['p']]['customer'] = true;
        } catch (\Throwable $e) {}
        return $out;
    }

    public static function refOptions()
    {
        $names = [];
        try { foreach (Reference::all(true) as $r) $names[] = $r['name']; } catch (\Throwable $e) {}
        if (!$names) $names = ['اینستاگرام', 'گوگل / سایت', 'معرفی دوستان', 'تبلیغات', 'قبلاً مشتری بوده', 'سایر'];
        return $names;
    }

    /** ت۱: مرجع (منبع آشنایی) و نقش (مهمان / میزبان) */
    public static function setRef($phone10, $source, $role)
    {
        self::ensureSchema();
        self::ensureRow($phone10, null);
        $source = trim((string)$source);
        $role = isset(self::$roles[$role]) ? $role : null;
        Database::getInstance()->execute('UPDATE call_numbers SET ref_source = ?, ref_role = ? WHERE phone10 = ?',
            [$source !== '' ? mb_substr($source, 0, 80) : null, $role, $phone10]);
    }

    /** ت۵: برچسب «تماس گرفته شد» */
    public static function setCalledBack($phone10, $on, $user)
    {
        self::ensureSchema();
        self::ensureRow($phone10, null);
        Database::getInstance()->execute('UPDATE call_numbers SET called_back_at = ?, called_back_by = ? WHERE phone10 = ?',
            $on ? [date('Y-m-d H:i:s'), $user['full_name'] ?? null, $phone10] : [null, null, $phone10]);
    }

    /** ۲.۱.۲: نام‌های انتخاب‌شده برای ارجاع خودکار (خالی = همه‌ی پشتیبان‌های غیرمدیر) */
    public static function autoAssignPool()
    {
        $v = json_decode((string)Report::getSetting('pipeline_auto_assign_users', ''), true);
        $out = [];
        foreach ((array)$v as $n) { $n = preg_replace('/\s+/u', ' ', trim((string)$n)); if ($n !== '') $out[] = $n; }
        return array_values(array_unique($out));
    }

    /** فقط پشتیبان‌ها: اگر فهرست دستی تنظیم شده همان؛ وگرنه مدیرهای چارت سازمانی (نقش «مثل سرپرست» یا دارای زیرمجموعه) کنار گذاشته می‌شوند */
    private static function filterAutoPool(array $users)
    {
        $pool = self::autoAssignPool();
        if ($pool) {
            return array_values(array_filter($users, function ($u) use ($pool) {
                return in_array(preg_replace('/\s+/u', ' ', trim($u['full_name'])), $pool, true);
            }));
        }
        $mgr = [];
        try {
            $db = Database::getInstance();
            foreach ($db->fetchAll("SELECT u.username FROM eb_users u LEFT JOIN eb_roles r ON r.id = u.role_id
                WHERE u.role IN ('admin','manager') OR r.is_manager = 1 OR (r.id IS NOT NULL AND EXISTS (SELECT 1 FROM eb_roles c WHERE c.parent_id = r.id))") as $r) $mgr[strtolower($r['username'])] = true;
        } catch (\Throwable $e) { /* بدون چارت سازمانی */ }
        return array_values(array_filter($users, function ($u) use ($mgr) { return empty($mgr[strtolower($u['username'])]); }));
    }

    public static function autoAssignMinutes()
    {
        $v = Report::getSetting('pipeline_auto_assign_minutes', 20);
        return max(0, (int)$v);
    }

    /**
     * ت۶: شماره‌های بدون مسئول که N دقیقه (پیش‌فرض ۲۰) از آخرین تماسشان گذشته و سرپرست ارجاع نداده،
     * خودکار به پشتیبان آنلاینی که کمترین پیگیری باز را دارد ارجاع می‌شوند. اگر کسی آنلاین نباشد، دور بعد دوباره امتحان می‌شود.
     */
    public static function autoAssign()
    {
        $min = self::autoAssignMinutes();
        if ($min <= 0) return 0;
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT phone10 FROM call_numbers WHERE assigned_user_id IS NULL AND stage IN ('no_answer','followup')
             AND last_event_at IS NOT NULL AND last_event_at <= DATE_SUB(NOW(), INTERVAL {$min} MINUTE)
             AND last_event_at >= DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY last_event_at ASC LIMIT 50");
        if (!$rows) return 0;
        $online = $db->fetchAll(
            "SELECT id, username, full_name FROM users WHERE is_active = 1 AND username <> 'admin' AND role NOT IN ('admin','manager')
             AND last_activity >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) ORDER BY (exten_number IS NULL OR exten_number = ''), id");
        $online = self::filterAutoPool($online);
        if (!$online) return 0;
        $load = [];
        foreach ($online as $u) {
            $load[$u['id']] = (int)$db->fetchColumn("SELECT COUNT(*) FROM call_numbers WHERE assigned_user_id = ? AND stage IN ('no_answer','followup','link_sent','confirmed')", [$u['id']]);
        }
        $names = array_column($online, 'full_name', 'id');
        $n = 0;
        foreach ($rows as $r) {
            asort($load);
            $uid = (int)array_key_first($load);
            $db->execute('UPDATE call_numbers SET assigned_user_id = ?, assigned_by = NULL, assigned_at = NOW() WHERE phone10 = ? AND assigned_user_id IS NULL', [$uid, $r['phone10']]);
            $st = $db->fetchColumn('SELECT stage FROM call_numbers WHERE phone10 = ?', [$r['phone10']]);
            $db->execute('INSERT INTO call_number_stage_log (phone10, from_stage, to_stage, user_id, user_name, is_auto, reason) VALUES (?,?,?,NULL,?,1,?)',
                [$r['phone10'], $st, $st, 'سیستم', "ارجاع خودکار به {$names[$uid]} (بعد از {$min} دقیقه بدون ارجاع)"]);
            $load[$uid]++; $n++;
        }
        return $n;
    }

    /** نام نمایشی برای فهرستی از شماره‌ها: نام تعریف‌شده > مشتری > سرنخ */
    public static function namesFor(array $phone10s)
    {
        $phone10s = array_values(array_unique(array_filter($phone10s)));
        if (!$phone10s) return [];
        self::ensureSchema();
        $in = implode(',', array_fill(0, count($phone10s), '?'));
        $out = [];
        foreach (Database::getInstance()->fetchAll("SELECT phone10, name, stage, assigned_user_id, ref_source, ref_role FROM call_numbers WHERE phone10 IN ({$in})", $phone10s) as $r) {
            $out[$r['phone10']] = $r;
        }
        return $out;
    }

    /**
     * نام‌گذاری هوشمند: شماره‌های بی‌نام تماس‌ها را با نام کاربران ثبت‌نام‌شده در سایت
     * (دیتابیس لاراول، فقط خواندنی)، سپس مشتریان و سرنخ‌های CRM پر می‌کند.
     */
    public static function smartFillNames()
    {
        $db = Database::getInstance();
        // ۱) همه‌ی شماره‌های خارجی تماس‌ها در call_numbers
        $db->execute("INSERT IGNORE INTO call_numbers (phone10, phone)
            SELECT DISTINCT RIGHT(x.num, 10), CONCAT('0', RIGHT(x.num, 10)) FROM (
              SELECT CASE WHEN type = 'outbound' THEN call_dest ELSE call_source END AS num FROM calls WHERE type IN ('inbound','outbound')
            ) x WHERE x.num REGEXP '^(\\\\+?98|0)?9[0-9]{9}$'");
        $empty = [];
        foreach ($db->fetchAll("SELECT phone10 FROM call_numbers WHERE name IS NULL OR name = ''") as $r) $empty[$r['phone10']] = true;
        $stats = ['numbers' => (int)$db->fetchColumn('SELECT COUNT(*) FROM call_numbers'), 'empty_before' => count($empty), 'site' => 0, 'customer' => 0, 'lead' => 0];
        $put = function ($p10, $name, $src) use (&$empty, &$stats, $db) {
            $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
            if ($name === '' || !isset($empty[$p10]) || mb_strlen($name) < 2 || preg_match('/^[\d\s+]+$/', $name)) return;
            $db->execute("UPDATE call_numbers SET name = ?, name_source = ? WHERE phone10 = ? AND (name IS NULL OR name = '')", [mb_substr($name, 0, 150), $src, $p10]);
            unset($empty[$p10]); $stats[$src]++;
        };
        // ۲) سایت (لاراول)
        $site = self::sitePdo();
        if ($site) {
            $st = $site->query("SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)), ''), name) AS nm, phone, mobile FROM users WHERE deleted_at IS NULL");
            while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                foreach ([$u['mobile'], $u['phone']] as $ph) { $p = self::norm($ph); if ($p) $put($p, $u['nm'], 'site'); }
            }
            // رزروها و درخواست‌های رزرو سایت (مهمانانی که حساب کاربری ندارند)
            foreach (['bookings', 'enquiries'] as $tbl) {
                try {
                    $st = $site->query("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) AS nm, phone FROM {$tbl} WHERE phone IS NOT NULL AND phone <> '' ORDER BY id DESC");
                    while ($u = $st->fetch(PDO::FETCH_ASSOC)) { $p = self::norm($u['phone']); if ($p) $put($p, $u['nm'], 'site'); }
                } catch (\Throwable $e) { Logger::error("Pipeline site {$tbl}: " . $e->getMessage()); }
            }
        } else {
            $stats['site_error'] = 'اتصال به دیتابیس سایت برقرار نشد';
        }
        // ۳) مشتریان و سرنخ‌های CRM
        foreach ($db->fetchAll('SELECT name, mobile FROM customers WHERE mobile IS NOT NULL') as $c) { $p = self::norm($c['mobile']); if ($p) $put($p, $c['name'], 'customer'); }
        foreach ($db->fetchAll("SELECT name, mobile FROM leads WHERE mobile IS NOT NULL AND name NOT LIKE 'تماس%' ORDER BY id DESC") as $l) { $p = self::norm($l['mobile']); if ($p) $put($p, $l['name'], 'lead'); }
        $stats['still_empty'] = count($empty);
        return $stats;
    }

    private static function sitePdo()
    {
        // appCode/.env سایت: از پوشه‌ی CRM رو به بالا جستجو می‌شود (CRM حالا داخل پوشه‌ی اعلان‌بان است)
        $f = null;
        for ($d = dirname(__DIR__), $i = 0; $i < 6 && $d && $d !== dirname($d); $i++, $d = dirname($d)) {
            if (is_readable($d . '/appCode/.env')) { $f = $d . '/appCode/.env'; break; }
        }
        if (!$f) return null;
        $env = [];
        foreach (file($f) as $l) if (preg_match('/^(DB_\w+)=(.*)$/', trim($l), $m)) $env[$m[1]] = trim($m[2], "\"'");
        try {
            return new PDO("mysql:host={$env['DB_HOST']};port=" . ($env['DB_PORT'] ?? 3306) . ";dbname={$env['DB_DATABASE']};charset=utf8mb4",
                $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            Logger::error('Pipeline site DB connect failed: ' . $e->getMessage());
            return null;
        }
    }

    /** هشدارها برای زنگوله‌ی هدر */
    public static function alerts($user)
    {
        $visible = Auth::getVisibleExtens();
        $missed = Call::getMissedCallAlerts(24, $visible);
        $overdueMissed = array_values(array_filter($missed, function ($a) { return !empty($a['is_overdue']); }));
        $bind = [];
        $w = self::visibilitySql($user, $bind);
        $days = self::maxDays();
        $warnRows = Database::getInstance()->fetchAll(
            "SELECT n.phone10, n.name, n.stage, TIMESTAMPDIFF(SECOND, n.cycle_started_at, NOW()) AS cycle_seconds
             FROM call_numbers n WHERE n.stage IN ('followup','link_sent','confirmed')
             AND n.cycle_started_at < DATE_SUB(NOW(), INTERVAL " . ($days * 24 - 24) . " HOUR) {$w}
             ORDER BY n.cycle_started_at ASC LIMIT 20", $bind
        );
        foreach ($warnRows as &$r) { $r['stage_label'] = self::$stages[$r['stage']]; $r['remaining_seconds'] = $days * 86400 - (int)$r['cycle_seconds']; $r['phone'] = '0' . $r['phone10']; }
        unset($r);
        return [
            'missed_overdue' => array_slice(array_map(function ($a) {
                return ['id' => $a['id'], 'phone' => $a['call_source'], 'name' => (mb_strpos((string)$a['display_name'], 'تماس') === 0 ? null : $a['display_name']), 'elapsed_seconds' => $a['elapsed_seconds'], 'jalali' => $a['jalali_started_at']];
            }, $overdueMissed), 0, 20),
            'missed_overdue_count' => count($overdueMissed),
            'missed_open_count' => count($missed),
            'stage_warn' => $warnRows,
            'deadline_minutes' => (int)(self::missedAlertSeconds() / 60),
        ];
    }
}
