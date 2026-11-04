<?php
/**
 * جاکجاست - مدل تیکت
 * Jakojast - Ticket Model
 *
 * سیستم تیکتینگ با messages JSON، جریان پاسخ، و تغییر وضعیت.
 */

class Ticket
{
    private static $fillable = [
        'title', 'type', 'customer_id', 'host_id', 'staff_id', 'call_id',
        'priority', 'status', 'dept', 'assignee', 'messages',
        'date', 'last_reply',
    ];

    public static $types = ['customer', 'host', 'staff', 'general'];
    public static $priorities = ['high', 'medium', 'low'];
    public static $statuses = ['open', 'in_review', 'answered', 'closed'];
    public static $depts = ['فروش', 'فنی', 'مالی', 'عمومی', 'اداری', 'پشتیبانی'];

    /**
     * لیست تیکت‌ها با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(t.title LIKE ? OR t.assignee LIKE ?)';
            array_push($bind, $search, $search);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 't.status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['priority']) && in_array($params['priority'], self::$priorities, true)) {
            $where[] = 't.priority = ?';
            $bind[] = $params['priority'];
        }
        if (!empty($params['type']) && in_array($params['type'], self::$types, true)) {
            $where[] = 't.type = ?';
            $bind[] = $params['type'];
        }
        if (!empty($params['dept']) && in_array($params['dept'], self::$depts, true)) {
            $where[] = 't.dept = ?';
            $bind[] = $params['dept'];
        }
        if (!empty($params['assignee'])) {
            $where[] = 't.assignee = ?';
            $bind[] = $params['assignee'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM tickets t {$whereSql}", $bind);

        $rows = $db->fetchAll(
            "SELECT t.*,
                    c.name AS customer_name,
                    h.name AS host_name,
                    s.name AS staff_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN hosts h ON h.id = t.host_id
             LEFT JOIN staff s ON s.id = t.staff_id
             {$whereSql}
             ORDER BY
                CASE t.status WHEN 'open' THEN 0 WHEN 'in_review' THEN 1 WHEN 'answered' THEN 2 ELSE 3 END,
                FIELD(t.priority, 'high', 'medium', 'low'),
                t.created_at DESC
             LIMIT {$offset}, {$limit}",
            $bind
        );

        // parse messages JSON
        foreach ($rows as &$r) {
            $r['messages_array'] = json_decode($r['messages'] ?: '[]', true) ?: [];
            $r['message_count'] = count($r['messages_array']);
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d H:i');
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن تیکت با ID + اطلاعات مرتبط
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT t.*,
                    c.name AS customer_name, c.mobile AS customer_mobile,
                    h.name AS host_name, h.mobile AS host_mobile,
                    s.name AS staff_name
             FROM tickets t
             LEFT JOIN customers c ON c.id = t.customer_id
             LEFT JOIN hosts h ON h.id = t.host_id
             LEFT JOIN staff s ON s.id = t.staff_id
             WHERE t.id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;

        $row['messages_array'] = json_decode($row['messages'] ?: '[]', true) ?: [];
        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d H:i');
        return $row;
    }

    /**
     * ایجاد تیکت جدید
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['title'])) Response::validationError('عنوان تیکت الزامی است');

        // اعتبارسنجی type
        if (empty($filtered['type'])) $filtered['type'] = 'general';
        if (!in_array($filtered['type'], self::$types, true)) {
            Response::validationError('نوع تیکت نامعتبر است');
        }

        if (empty($filtered['priority'])) $filtered['priority'] = 'medium';
        if (empty($filtered['status'])) $filtered['status'] = 'open';
        if (empty($filtered['date'])) $filtered['date'] = Jalali::today();

        // اولین پیام را به messages اضافه کن
        $initialMessage = $data['message'] ?? $data['text'] ?? '';
        $messages = [];
        if (!empty($initialMessage)) {
            $messages[] = [
                'from'      => $data['from_name'] ?? 'کاربر',
                'text'      => $initialMessage,
                'date'      => Jalali::now('Y/m/d H:i'),
                'isSupport' => false,
            ];
        }
        $filtered['messages'] = json_encode($messages, JSON_UNESCAPED_UNICODE);
        $filtered['last_reply'] = Jalali::today();

        return $db->insert('tickets', $filtered);
    }

    /**
     * به‌روزرسانی تیکت
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تیکت یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        // messages نباید از این طریق تغییر کند (از addReply استفاده شود)
        unset($filtered['messages']);

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('tickets', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * افزودن پاسخ به تیکت
     */
    public static function addReply($id, $text, $fromName, $isSupport = true)
    {
        $db = Database::getInstance();
        $existing = self::find($id);
        if (!$existing) Response::notFound('تیکت یافت نشد');

        if (empty($text)) Response::validationError('متن پاسخ الزامی است');

        $messages = $existing['messages_array'];
        $messages[] = [
            'from'      => $fromName,
            'text'      => $text,
            'date'      => Jalali::now('Y/m/d H:i'),
            'isSupport' => (bool)$isSupport,
        ];

        $newStatus = $isSupport ? 'answered' : 'in_review';

        $db->update('tickets', [
            'messages'    => json_encode($messages, JSON_UNESCAPED_UNICODE),
            'last_reply'  => Jalali::today(),
            'status'      => $newStatus,
        ], 'id = ?', [(int)$id]);

        return self::find($id);
    }

    /**
     * تغییر وضعیت تیکت
     */
    public static function changeStatus($id, $status)
    {
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('تیکت یافت نشد');

        return Database::getInstance()->update('tickets', ['status' => $status], 'id = ?', [(int)$id]);
    }

    /**
     * انتساب تیکت به شخص
     */
    public static function assign($id, $assignee)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تیکت یافت نشد');

        return Database::getInstance()->update('tickets', ['assignee' => $assignee], 'id = ?', [(int)$id]);
    }

    /**
     * حذف تیکت
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تیکت یافت نشد');
        return Database::getInstance()->delete('tickets', 'id = ?', [(int)$id]);
    }

    /**
     * آمار تیکت‌ها
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM tickets GROUP BY status');
        foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT priority, COUNT(*) AS cnt FROM tickets GROUP BY priority');
        foreach ($rows as $r) $stats['by_priority'][$r['priority']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT type, COUNT(*) AS cnt FROM tickets GROUP BY type');
        foreach ($rows as $r) $stats['by_type'][$r['type']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT dept, COUNT(*) AS cnt FROM tickets WHERE dept IS NOT NULL GROUP BY dept');
        foreach ($rows as $r) $stats['by_dept'][$r['dept']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM tickets');
        $stats['open'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE status = 'open'");
        $stats['urgent'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM tickets WHERE priority = 'high' AND status != 'closed'");

        return $stats;
    }
}
