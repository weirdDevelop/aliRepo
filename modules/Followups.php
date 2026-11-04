<?php
/**
 * modules/Followups.php
 * پیام پیگیری خودکار: «اگر X ساعت از آخرین پیام مشتری گذشت و شرط‌ها برقرار بود، این پیام را بفرست».
 *
 * زمان‌بندی همیشه نسبت به «آخرین پیام مشتری» حساب می‌شود؛ چون پنجره‌ی ۲۴ ساعته‌ی اینستاگرام
 * (و پیام‌رسان‌های مشابه) از همان لحظه شروع می‌شود. پیش‌فرض ۲۳ ساعت است تا پیام قبل از بسته شدن
 * پنجره برسد. برای هر پیام مشتری، هر قانون حداکثر یک بار اجرا می‌شود (eb_followup_log).
 */
class Followups
{
    /** کانال‌هایی که فقط تا ۲۴ ساعت بعد از پیام مشتری اجازه‌ی پیام می‌دهند */
    const WINDOW24 = ['instagram'];

    public static function rules(): array
    {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = 'desk.followups'");
        $d = json_decode((string)($r['v'] ?? ''), true);
        return is_array($d) ? $d : [];
    }

    public static function saveRules(array $rules): array
    {
        $clean = [];
        foreach (array_slice($rules, 0, 20) as $r) {
            if (!is_array($r)) continue;
            $text = trim((string)($r['text'] ?? ''));
            if ($text === '') continue;
            $raw = is_array($r['keywords'] ?? null) ? $r['keywords'] : preg_split('/[\n,،]+/u', (string)($r['keywords'] ?? ''));
            $clean[] = [
                'id' => preg_replace('/[^a-z0-9]/', '', (string)($r['id'] ?? '')) ?: substr(md5($text . microtime()), 0, 8),
                'name' => mb_substr(trim((string)($r['name'] ?? '')), 0, 60) ?: 'پیگیری',
                'active' => !empty($r['active']),
                'after_hours' => max(0.25, min(720, (float)($r['after_hours'] ?? 23))),
                'who_last' => in_array($r['who_last'] ?? '', ['us', 'customer', 'any'], true) ? $r['who_last'] : 'us',
                'statuses' => array_values(array_intersect((array)($r['statuses'] ?? ['open', 'pending']), ['open', 'pending', 'snoozed', 'closed'])) ?: ['open', 'pending'],
                'channels' => array_values(array_filter(array_map('strval', (array)($r['channels'] ?? [])))),
                'keywords' => array_values(array_filter(array_map(fn($w) => mb_substr(trim((string)$w), 0, 60), $raw), fn($w) => $w !== '')),
                'label' => !empty($r['label']) ? (int)$r['label'] : null,
                'skip_label' => !empty($r['skip_label']) ? (int)$r['skip_label'] : null,
                'text' => mb_substr($text, 0, 1000),
            ];
        }
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('desk.followups', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        return $clean;
    }

    /** گفتگوهایی که الان شرایط یک قانون را دارند */
    public static function candidates(array $rule, int $limit = 50): array
    {
        if (!ConvIndex::ready()) return [];
        ConvIndex::sync();
        $after = (int)round($rule['after_hours'] * 60);
        $chs = $rule['channels'] ?: [];
        // فقط گفتگوهایی که همین حالا به آستانه رسیده‌اند (حداکثر ۶ ساعت عقب‌تر)، تا با روشن کردن قانون، پیام به گفتگوهای قدیمی نرود
        $grace = 360;
        $c = ["x.last_in_at IS NOT NULL", "x.last_in_at <= DATE_SUB(NOW(), INTERVAL $after MINUTE)", "x.last_in_at > DATE_SUB(NOW(), INTERVAL " . ($after + $grace) . " MINUTE)"];
        $p = [];
        // پنجره‌ی ۲۴ ساعته‌ی اینستاگرام: بعد از ۲۳ ساعت و ۵۰ دقیقه دیگر پیام نرود
        $c[] = "(x.last_channel NOT IN ('" . implode("','", self::WINDOW24) . "') OR x.last_in_at > DATE_SUB(NOW(), INTERVAL 1430 MINUTE))";
        if ($rule['who_last'] === 'us') $c[] = "x.last_out_at IS NOT NULL AND x.last_out_at > x.last_in_at";
        if ($rule['who_last'] === 'customer') $c[] = "(x.last_out_at IS NULL OR x.last_in_at >= x.last_out_at)";
        if ($chs) { $c[] = "x.last_channel IN (" . implode(',', array_fill(0, count($chs), '?')) . ")"; array_push($p, ...$chs); }
        if (Inbox::hasBlock()) $c[] = "pp.blocked_at IS NULL";
        $eff = "(CASE WHEN s.status IS NULL OR s.status = 'open' THEN 'open'
                      WHEN s.status = 'snoozed' AND s.snooze_until IS NOT NULL AND s.snooze_until <= NOW() THEN 'open'
                      WHEN s.status IN ('closed','pending','snoozed') AND x.last_in_at > s.status_at THEN 'open' ELSE s.status END)";
        $c[] = "$eff IN (" . implode(',', array_fill(0, count($rule['statuses']), '?')) . ")"; array_push($p, ...$rule['statuses']);
        if ($rule['label']) { $c[] = "FIND_IN_SET(?, s.labels)"; $p[] = (int)$rule['label']; }
        if ($rule['skip_label']) { $c[] = "(s.labels IS NULL OR NOT FIND_IN_SET(?, s.labels))"; $p[] = (int)$rule['skip_label']; }
        $c[] = "NOT EXISTS (SELECT 1 FROM eb_followup_log f WHERE f.rule_id = ? AND f.person_id = x.person_id AND f.anchor_msg_id = x.last_in_id)"; $p[] = $rule['id'];
        $rows = Database::all("SELECT x.person_id, x.last_in_id, x.last_in_at, x.last_channel, pp.full_name
            FROM eb_conv_index x INNER JOIN eb_persons pp ON pp.id = x.person_id LEFT JOIN eb_conv_state s ON s.person_id = x.person_id
            WHERE " . implode(' AND ', $c) . " ORDER BY x.last_in_at LIMIT " . (int)$limit * 3, $p);
        $out = [];
        foreach ($rows as $r) {
            if ($rule['keywords']) {
                $txt = (string)(Database::one("SELECT GROUP_CONCAT(body SEPARATOR ' ') t FROM (SELECT m.body FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id WHERE i.person_id = ? AND m.direction = 'in' ORDER BY m.id DESC LIMIT 5) q", [(int)$r['person_id']])['t'] ?? '');
                $t = Desk::normText($txt); $ok = false;
                foreach ($rule['keywords'] as $w) if (($wn = Desk::normText($w)) !== '' && mb_strpos($t, $wn) !== false) { $ok = true; break; }
                if (!$ok) continue;
            }
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** از کران (هر دقیقه) */
    public static function tick(int $max = 40): int
    {
        if (!Database::hasTable('eb_followup_log')) return 0;
        $sent = 0;
        foreach (self::rules() as $rule) {
            if (empty($rule['active'])) continue;
            foreach (self::candidates($rule, $max) as $r) {
                $pid = (int)$r['person_id'];
                // قفل یک‌باره: اگر کران هم‌زمان دیگری همین را برداشته باشد، اینجا رد می‌شود
                $ins = Database::run("INSERT IGNORE INTO eb_followup_log (rule_id, person_id, anchor_msg_id) VALUES (?, ?, ?)", [$rule['id'], $pid, (int)$r['last_in_id']]);
                if (!$ins || $ins->rowCount() !== 1) continue;
                $ident = Database::one("SELECT identity_id FROM eb_messages WHERE id = ?", [(int)$r['last_in_id']]);
                $res = Sender::sendToPerson($pid, ['text' => $rule['text']], [(string)$r['last_channel'], 'telegram', 'bale', 'roubika', 'webchat'], null,
                    ['identity_id' => (int)($ident['identity_id'] ?? 0), 'source' => 'followup']);
                if (empty($res['ok'])) {
                    Database::run("UPDATE eb_followup_log SET ok = 0, error = ? WHERE rule_id = ? AND person_id = ? AND anchor_msg_id = ?", [mb_substr((string)($res['error'] ?? ''), 0, 300), $rule['id'], $pid, (int)$r['last_in_id']]);
                    continue;
                }
                Desk::event($pid, 'followup', $rule['name'], null);
                $sent++;
            }
        }
        if ($sent) { ConvIndex::sync(); Desk::bumpCounts(); }
        return $sent;
    }

    /** آمار ارسال هر قانون (۷ روز) */
    public static function stats(): array
    {
        if (!Database::hasTable('eb_followup_log')) return [];
        $o = [];
        foreach (Database::all("SELECT rule_id, SUM(ok = 1) ok, SUM(ok = 0) fail FROM eb_followup_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY rule_id") as $r) $o[$r['rule_id']] = ['ok' => (int)$r['ok'], 'fail' => (int)$r['fail']];
        return $o;
    }
}
