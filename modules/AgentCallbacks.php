<?php
/**
 * modules/AgentCallbacks.php — درخواست تماس (۱.۰.۹)
 * ==========================================================================
 * دستیار فروش وقتی مشتری بخواهد (یا پیشنهاد تماس را بپذیرد) با ابزار request_callback یک درخواست
 * تماس ثبت می‌کند: اعلان در پنل، پیام بله به مدیران گزارش، و فهرست «درخواست‌های تماس» در صفحه‌ی
 * دستیار تا همکاران تماس بگیرند و «تماس گرفته شد» را بزنند.
 */
class AgentCallbacks
{
    public static function add(int $pid, string $phone, string $name, string $when, string $note, string $channel): int
    {
        ApiConnectors::ensure();
        $phone = Persons::normalizePhone($phone) ?? preg_replace('/^(?:\+?98)(9\d{9})$/', '0$1', $phone);   // ۱.۰.۱۴: همیشه 09…
        // درخواست باز تکراری برای همین مشتری: به‌روزرسانی، نه ردیف تازه
        $open = $pid > 0 ? Database::one("SELECT id FROM eb_agent_callbacks WHERE person_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$pid]) : null;
        $vals = [mb_substr($phone, 0, 20), mb_substr($name, 0, 120), mb_substr($when, 0, 120), mb_substr($note, 0, 500), mb_substr($channel, 0, 30)];
        if ($open) {
            Database::run("UPDATE eb_agent_callbacks SET phone = ?, name = ?, preferred = ?, note = ?, channel = ?, created_at = NOW() WHERE id = ?", array_merge($vals, [(int)$open['id']]));
            $id = (int)$open['id'];
        } else {
            Database::run("INSERT INTO eb_agent_callbacks (person_id, phone, name, preferred, note, channel) VALUES (?, ?, ?, ?, ?, ?)", array_merge([$pid ?: null], $vals));
            $id = (int)Database::lastId();
        }
        $who = $name !== '' ? $name : 'مشتری';
        $title = '📞 درخواست تماس: ' . $who . ' — ' . $phone;
        $body = trim(($when !== '' ? 'زمان: ' . $when . ' · ' : '') . $note);
        if (class_exists('Notifier')) Notifier::push('ai_callback', $title, $body ?: null, 'index.php#inbox.v' . $pid, $pid ?: null);
        if (SalesAgent::get('cb_bale') === '1' && class_exists('ChannelManager')) {
            $d = ChannelManager::get('bale');
            if ($d) foreach (AgentLifecycle::reportChats() as $c) {
                try { $d->send(['channel_uid' => $c], ['text' => $title . ($when !== '' ? "\n⏰ " . $when : '') . ($note !== '' ? "\n📝 " . $note : '')]); } catch (Throwable $e) {}
            }
        }
        ApiConnectors::log($pid ?: null, null, 'tool', 'request_callback', ['phone' => $phone, 'when' => $when], true, null, 'درخواست تماس #' . $id, ['note' => $note], null);
        // ۱.۰.۱۴: برچسب «اولویت تماس» روی گفتگو + تماس تلفن گویا با پشتیبان‌ها
        if (class_exists('ChatPlus')) { try { ChatPlus::onCallback($pid, $phone); } catch (Throwable $e) { error_log('[callback label] ' . $e->getMessage()); } }
        return $id;
    }

    /** ساعت کاری؟ (طبق تنظیم ساعت کاری پشتیبانی) */
    public static function inHours(): bool
    {
        if (class_exists('Support') && method_exists('Support', 'isOnline')) {
            try { return (bool)Support::isOnline(); } catch (Throwable $e) {}
        }
        return true;
    }

    public static function all(string $status = 'open', int $limit = 100): array
    {
        ApiConnectors::ensure();
        $w = $status === 'all' ? '1=1' : 'c.status = ' . Database::pdo()->quote($status === 'done' ? 'done' : 'open');
        return Database::all("SELECT c.*, COALESCE(NULLIF(p.full_name,''), c.name) person, " . Auth::nameSql('u') . " AS done_name
            FROM eb_agent_callbacks c LEFT JOIN eb_persons p ON p.id = c.person_id LEFT JOIN eb_users u ON u.id = c.done_by
            WHERE {$w} ORDER BY c.status = 'open' DESC, c.id DESC LIMIT " . max(1, min(500, $limit)));
    }

    public static function done(int $id, int $userId, string $result = ''): void
    {
        ApiConnectors::ensure();
        Database::run("UPDATE eb_agent_callbacks SET status = 'done', done_by = ?, done_at = NOW(), result = ? WHERE id = ?", [$userId ?: null, mb_substr($result, 0, 255) ?: null, $id]);
        // ۱.۰.۱۴: آخرین درخواست باز این مشتری انجام شد → برچسب «اولویت تماس» برداشته شود
        $pid = (int)(Database::one("SELECT person_id FROM eb_agent_callbacks WHERE id = ?", [$id])['person_id'] ?? 0);
        if ($pid && class_exists('ChatPlus')) ChatPlus::onCallbackDone($pid);
        if (class_exists('Desk')) Desk::bumpCounts();
    }

    public static function openCount(): int
    {
        try { ApiConnectors::ensure(); return (int)(Database::one("SELECT COUNT(*) c FROM eb_agent_callbacks WHERE status = 'open'")['c'] ?? 0); } catch (Throwable $e) { return 0; }
    }
}
