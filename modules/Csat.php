<?php
/**
 * modules/Csat.php
 * نظرسنجی رضایت مشتری بعد از پایان گفتگو.
 *
 * - با بستن گفتگو (خودکار، اگر روشن باشد) یا با دکمه‌ی «ارسال نظرسنجی»، یک پیام به مشتری می‌رود:
 *   در بله/تلگرام/روبیکا با دکمه‌های ۱ تا ۵، در گفتگوی سایت با دکمه‌های سریع، در بقیه به‌صورت متن.
 * - پاسخ مشتری (عدد ۱ تا ۵، ستاره، یا کلمه‌ای مثل «عالی») ثبت می‌شود، تشکر فرستاده می‌شود
 *   و گفتگو بسته می‌ماند.
 * - اگر مشتری به‌جای امتیاز پیام دیگری بفرستد، نظرسنجی کنار می‌رود و گفتگو مثل همیشه باز می‌شود
 *   (ادامه‌ی چت). امتیاز به اپراتور مسئول همان گفتگو نسبت داده می‌شود.
 */
class Csat
{
    const DEF = [
        'enabled'     => false,
        'auto_close'  => true,
        'question'    => 'از گفتگو با {agent} چقدر راضی بودید؟ لطفاً عددی از ۱ (ناراضی) تا ۵ (خیلی راضی) بفرستید. اگر سؤال دیگری دارید، همین‌جا بنویسید.',
        'thanks'      => 'ممنون از امتیاز شما 🙏 اگر کار دیگری دارید، همین‌جا بنویسید.',
        'thanks_low'  => 'ممنون که نظرتان را گفتید. متأسفیم که راضی نبودید؛ همکارمان به‌زودی پیگیری می‌کند.',
        'notify_low'  => true,
        'expire_hours'=> 48,
        'channels'    => ['telegram', 'bale', 'roubika', 'webchat', 'instagram', 'instagram_apichi'],
    ];
    const LABELS = [1 => '😞 ۱', 2 => '🙁 ۲', 3 => '😐 ۳', 4 => '🙂 ۴', 5 => '😍 ۵'];

    public static function ready(): bool { return Database::hasTable('eb_csat'); }

    public static function config(): array
    {
        $r = Database::one("SELECT v FROM eb_settings WHERE k = 'desk.csat'");
        $c = json_decode((string)($r['v'] ?? ''), true);
        return is_array($c) ? array_merge(self::DEF, $c) : self::DEF;
    }

    public static function saveConfig(array $in): array
    {
        $c = self::config();
        foreach (['enabled', 'auto_close', 'notify_low'] as $k) if (isset($in[$k])) $c[$k] = !empty($in[$k]);
        foreach (['question', 'thanks', 'thanks_low'] as $k) if (isset($in[$k])) $c[$k] = mb_substr(trim((string)$in[$k]), 0, 600) ?: self::DEF[$k];
        if (isset($in['expire_hours'])) $c['expire_hours'] = max(1, min(240, (int)$in['expire_hours']));
        if (isset($in['channels']) && is_array($in['channels'])) $c['channels'] = array_values(array_intersect(array_map('strval', $in['channels']), self::DEF['channels']));
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('desk.csat', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($c, JSON_UNESCAPED_UNICODE)]);
        return $c;
    }

    /** ارسال نظرسنجی برای یک گفتگو */
    public static function send(int $personId, int $byUserId, bool $auto = false): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'دیتابیس را به‌روزرسانی کنید'];
        $c = self::config();
        if ($auto && empty($c['enabled'])) return ['ok' => false, 'skipped' => true];
        // نظرسنجی تکراری برای همان گفتگو در ۱۲ ساعت اخیر نفرست
        if (Database::one("SELECT id FROM eb_csat WHERE person_id = ? AND sent_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) LIMIT 1", [$personId])) {
            return ['ok' => false, 'error' => 'برای این مشتری در ۱۲ ساعت اخیر نظرسنجی فرستاده شده'];
        }
        $last = Inbox::lastInboundIdentity($personId);
        if (!$last) return ['ok' => false, 'error' => 'این مشتری هنوز پیامی نفرستاده که بشود جوابش را داد'];
        $ch = (string)$last['channel'];
        if (!in_array($ch, $c['channels'], true)) return ['ok' => false, 'error' => 'نظرسنجی برای این کانال خاموش است', 'skipped' => true];
        $agent = (int)(Database::one("SELECT assigned_user_id a FROM eb_persons WHERE id = ?", [$personId])['a'] ?? 0) ?: $byUserId;
        $agentName = $agent ? (ChatAssign::userNames()[$agent] ?? 'همکار ما') : 'همکار ما';
        $text = str_replace(['{agent}', '{اپراتور}'], $agentName, (string)$c['question']);
        $msg = ['text' => $text];
        if (in_array($ch, ['telegram', 'bale', 'roubika'], true)) {
            $msg['buttons'] = [array_map(fn($n) => ['text' => self::LABELS[$n], 'callback' => 'csat:' . $n], [5, 4, 3, 2, 1])];
        }
        $r = Sender::sendToPerson($personId, $msg, [$ch], null, ['identity_id' => (int)$last['id'], 'source' => 'csat']);
        if (empty($r['ok'])) return ['ok' => false, 'error' => 'ارسال نشد: ' . ($r['error'] ?? 'نامشخص')];
        if ($ch === 'webchat' && class_exists('Webchat')) {
            $tok = Database::one("SELECT channel_uid FROM eb_identities WHERE id = ?", [(int)$last['id']])['channel_uid'] ?? '';
            if ($tok) Webchat::pushEvent($tok, 'csat', ['opts' => array_values(array_map(fn($n) => self::LABELS[$n], [5, 4, 3, 2, 1]))]);
        }
        Database::run("UPDATE eb_csat SET status = 'expired' WHERE person_id = ? AND status = 'sent'", [$personId]);
        Database::run("INSERT INTO eb_csat (person_id, agent_id, channel, status, sent_by) VALUES (?, ?, ?, 'sent', ?)", [$personId, $agent ?: null, $ch, $byUserId ?: null]);
        if (class_exists('Desk')) Desk::event($personId, 'csat', 'sent', $byUserId ?: null);
        return ['ok' => true];
    }

    /** تبدیل پاسخ مشتری به امتیاز ۱ تا ۵ (یا null) */
    public static function parse(?string $text, ?string $callback = null): ?int
    {
        if ($callback && preg_match('/^csat:([1-5])$/', $callback, $m)) return (int)$m[1];
        $t = trim((string)$text);
        if ($t === '' || mb_strlen($t) > 24) return null;
        $t = strtr($t, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5']);
        $stars = mb_substr_count($t, '⭐') + mb_substr_count($t, '★') + mb_substr_count($t, '🌟');
        if ($stars >= 1 && $stars <= 5) return $stars;
        if (preg_match('/^\D{0,4}([1-5])\D{0,8}$/u', $t, $m)) return (int)$m[1];
        $words = ['عالی' => 5, 'خیلی خوب' => 5, 'خیلی راضی' => 5, 'راضی' => 4, 'خوب' => 4, 'متوسط' => 3, 'معمولی' => 3, 'بد' => 2, 'ناراضی' => 1, 'خیلی بد' => 1, 'افتضاح' => 1];
        $plain = trim(preg_replace('/[^\p{L}\s]/u', '', $t));
        foreach ($words as $w => $n) if ($plain === $w) return $n;
        return null;
    }

    /**
     * از مسیر دریافت پیام صدا زده می‌شود. اگر پیام پاسخ نظرسنجی بود: ثبت، تشکر و true (پیام مصرف شد).
     * اگر پیام دیگری بود: نظرسنجی کنار می‌رود و false (ادامه‌ی عادی گفتگو).
     */
    public static function onMessage(string $channel, string $channelUid, ?string $text, ?string $callback = null): bool
    {
        if (!self::ready()) return false;
        try {
            $idn = Database::one("SELECT id, person_id FROM eb_identities WHERE channel = ? AND channel_uid = ? LIMIT 1", [$channel, $channelUid]);
            if (!$idn) return false;
            $pid = (int)$idn['person_id'];
            $c = self::config();
            $row = Database::one("SELECT * FROM eb_csat WHERE person_id = ? AND status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL " . (int)$c['expire_hours'] . " HOUR) ORDER BY id DESC LIMIT 1", [$pid]);
            if (!$row) return false;
            $n = self::parse($text, $callback);
            if ($n === null) {
                // مشتری به گفتگو ادامه داد → نظرسنجی کنار می‌رود و گفتگو مثل همیشه باز می‌شود
                Database::run("UPDATE eb_csat SET status = 'skipped' WHERE id = ?", [(int)$row['id']]);
                return false;
            }
            Database::run("UPDATE eb_csat SET status = 'rated', rating = ?, rated_at = NOW() WHERE id = ?", [$n, (int)$row['id']]);
            // گفتگو بسته بماند (پیام امتیاز نباید آن را دوباره باز کند)
            Database::run("UPDATE eb_conv_state SET status_at = DATE_ADD(NOW(), INTERVAL 1 SECOND) WHERE person_id = ? AND status IN ('closed','pending')", [$pid]);
            if (class_exists('Desk')) { Desk::event($pid, 'csat', 'rated|' . $n, null); Desk::bumpCounts(); }
            if (class_exists('ConvIndex')) ConvIndex::touch($pid);
            $thanks = $n <= 2 ? (string)$c['thanks_low'] : (string)$c['thanks'];
            if ($thanks !== '') Sender::sendToPerson($pid, ['text' => $thanks], [$channel], null, ['identity_id' => (int)$idn['id'], 'source' => 'csat']);
            if ($n <= 2 && !empty($c['notify_low']) && class_exists('Notifier')) {
                $who = Database::one("SELECT full_name FROM eb_persons WHERE id = ?", [$pid])['full_name'] ?? '';
                Notifier::push('csat', 'امتیاز پایین (' . $n . ' از ۵) از ' . ($who ?: 'مشتری'), 'برای پیگیری گفتگو را باز کنید', 'index.php#inbox.v' . $pid, $pid);
            }
            return true;
        } catch (Throwable $e) {
            error_log('[csat] ' . $e->getMessage());
            return false;
        }
    }

    /** خلاصه‌ی امتیازها برای گزارش (هر اپراتور) */
    public static function summary(string $from, string $to): array
    {
        if (!self::ready()) return [];
        $out = [];
        foreach (Database::all("SELECT agent_id, COUNT(*) sent, SUM(status = 'rated') rated, AVG(rating) avg_rating, SUM(rating >= 4) good, SUM(rating <= 2) bad
                                FROM eb_csat WHERE sent_at >= ? AND sent_at < ? GROUP BY agent_id", [$from, $to]) as $r) {
            $out[(int)$r['agent_id']] = ['sent' => (int)$r['sent'], 'rated' => (int)$r['rated'], 'avg' => $r['avg_rating'] !== null ? round((float)$r['avg_rating'], 2) : null, 'good' => (int)$r['good'], 'bad' => (int)$r['bad']];
        }
        return $out;
    }
}
