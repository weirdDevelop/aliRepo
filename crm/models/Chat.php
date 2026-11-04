<?php
/**
 * جاکجاست - مدل چت
 * Jakojast - Chat Model
 *
 * چت داخلی با Long Polling:
 *   - getMessages: اگر last_id ارسال شود، تا ۲۵ ثانیه منتظر پیام جدید می‌ماند
 *   - sendMessage: درج پیام و بازگشت آن
 *
 * کانال‌های پیش‌فرض: عمومی، مالی، پشتیبانی، فروش، میزبانان
 */

class Chat
{
    public static $channels = ['عمومی', 'مالی', 'پشتیبانی', 'فروش', 'میزبانان', 'فنی'];

    /**
     * دریافت پیام‌های یک کانال
     *
     * @param string $channel  نام کانال
     * @param int    $lastId   فقط پیام‌های با id > این مقدار برگردانده می‌شوند
     * @param bool   $longPoll  اگر true، تا CHAT_POLL_TIMEOUT ثانیه منتظر می‌ماند
     * @return array  لیست پیام‌ها
     */
    public static function getMessages($channel, $lastId = 0, $longPoll = false)
    {
        $db = Database::getInstance();
        $lastId = (int)$lastId;

        if (!$longPoll) {
            // حالت عادی: ۵۰ پیام آخر
            $rows = $db->fetchAll(
                'SELECT * FROM chat_messages
                 WHERE channel = ?
                 ORDER BY id DESC
                 LIMIT 50',
                [$channel]
            );
            return array_reverse($rows);  // قدیمی‌ترین اول
        }

        // حالت Long Polling: تا CHAT_POLL_TIMEOUT ثانیه منتظر پیام جدید
        $startTime = time();
        $timeout = CHAT_POLL_TIMEOUT;
        $interval = CHAT_POLL_INTERVAL;

        while (time() - $startTime < $timeout) {
            $rows = $db->fetchAll(
                'SELECT * FROM chat_messages
                 WHERE channel = ? AND id > ?
                 ORDER BY id ASC',
                [$channel, $lastId]
            );

            if (!empty($rows)) {
                return $rows;
            }

            // منتظر بمان قبل از بررسی مجدد
            sleep($interval);

            // جلوگیری از timeout در PHP (هر بار connection را فعال نگه می‌داریم)
            if (!headers_sent()) {
                echo ' ';
                ob_flush();
                flush();
            }
        }

        // timeout - پیام جدیدی نبود
        return [];
    }

    /**
     * ارسال پیام
     */
    public static function sendMessage($channel, $userId, $userName, $text, $isMe = false)
    {
        $db = Database::getInstance();

        if (empty($text)) Response::validationError('متن پیام خالی است');
        if (empty($channel)) $channel = 'عمومی';

        // پاک‌سازی متن (اجازه emoji و کاراکترهای فارسی، حذف تگ‌های HTML)
        $text = trim($text);
        $text = strip_tags($text);  // حذف تگ‌های HTML برای جلوگیری از XSS
        if (mb_strlen($text) > 2000) {
            Response::validationError('طول پیام نباید بیشتر از ۲۰۰۰ کاراکتر باشد');
        }

        $id = $db->insert('chat_messages', [
            'channel'     => $channel,
            'user_id'     => $userId ?: null,
            'user_name'   => $userName ?: 'کاربر ناشناس',
            'text'        => $text,
            'is_me'       => $isMe ? 1 : 0,
            'time'        => date('H:i'),
        ]);

        return $db->fetchOne('SELECT * FROM chat_messages WHERE id = ?', [$id]);
    }

    /**
     * دریافت لیست کانال‌ها با تعداد پیام هر کدام
     */
    public static function getChannelsWithCounts()
    {
        $db = Database::getInstance();
        $channels = [];
        foreach (self::$channels as $ch) {
            $count = (int)$db->fetchColumn(
                'SELECT COUNT(*) FROM chat_messages WHERE channel = ?',
                [$ch]
            );
            $lastMessage = $db->fetchOne(
                'SELECT * FROM chat_messages WHERE channel = ? ORDER BY id DESC LIMIT 1',
                [$ch]
            );
            $channels[] = [
                'name'         => $ch,
                'message_count'=> $count,
                'last_message' => $lastMessage ? $lastMessage['text'] : null,
                'last_time'    => $lastMessage ? $lastMessage['time'] : null,
                'last_user'    => $lastMessage ? $lastMessage['user_name'] : null,
            ];
        }
        return $channels;
    }

    /**
     * دریافت آخرین ID پیام در یک کانال (برای client-side long polling)
     */
    public static function getLastMessageId($channel)
    {
        $id = Database::getInstance()->fetchColumn(
            'SELECT MAX(id) FROM chat_messages WHERE channel = ?',
            [$channel]
        );
        return (int)$id ?: 0;
    }

    /**
     * دریافت کاربران آنلاین (بر اساس آخرین پیام در ۵ دقیقه اخیر)
     */
    public static function getOnlineUsers()
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT DISTINCT user_name, user_id
             FROM chat_messages
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                AND user_id IS NOT NULL
             ORDER BY user_name"
        );
        return $rows;
    }

    /**
     * آمار چت
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT channel, COUNT(*) AS cnt FROM chat_messages GROUP BY channel');
        foreach ($rows as $r) $stats['by_channel'][$r['channel']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM chat_messages');
        $stats['today'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM chat_messages WHERE DATE(created_at) = CURDATE()');
        $stats['active_users'] = (int)$db->fetchColumn(
            'SELECT COUNT(DISTINCT user_id) FROM chat_messages WHERE user_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );

        return $stats;
    }

    /**
     * حذف پیام (فقط مدیر)
     */
    public static function deleteMessage($id)
    {
        $db = Database::getInstance();
        $msg = $db->fetchOne('SELECT * FROM chat_messages WHERE id = ?', [(int)$id]);
        if (!$msg) Response::notFound('پیام یافت نشد');
        return $db->delete('chat_messages', 'id = ?', [(int)$id]);
    }
}
