<?php
/**
 * جاکجاست - مدل تسک
 * Jakojast - Task Model
 *
 * مدیریت تسک‌ها با ۵ وضعیت کانبان: new, in_progress, review, done, cancelled
 */

class Task
{
    private static $fillable = [
        'title', 'description', 'priority', 'project_id', 'project',
        'assignee', 'start_date', 'end_date', 'status',
        'subtasks', 'comments',
    ];

    public static $statuses = ['new', 'in_progress', 'review', 'done', 'cancelled'];
    public static $priorities = ['high', 'medium', 'low'];

    // عنوان فارسی وضعیت‌ها برای کانبان
    public static $statusLabels = [
        'new'         => '🆕 جدید',
        'in_progress' => '🔄 در حال انجام',
        'review'      => '👀 در انتظار بررسی',
        'done'        => '✅ انجام شده',
        'cancelled'   => '❌ لغو شده',
    ];

    /**
     * لیست تسک‌ها با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(title LIKE ? OR description LIKE ? OR assignee LIKE ?)';
            array_push($bind, $search, $search, $search);
        }
        if (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        if (!empty($params['priority']) && in_array($params['priority'], self::$priorities, true)) {
            $where[] = 'priority = ?';
            $bind[] = $params['priority'];
        }
        if (!empty($params['project_id'])) {
            $where[] = 'project_id = ?';
            $bind[] = (int)$params['project_id'];
        }
        if (!empty($params['assignee'])) {
            $where[] = 'assignee = ?';
            $bind[] = $params['assignee'];
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = [
            'date' => 'created_at DESC',
            'priority' => 'FIELD(priority, "high", "medium", "low")',
            'deadline' => 'end_date ASC',
            'title' => 'title ASC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date'] ?? 'created_at DESC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(500, max(1, (int)($params['limit'] ?? 50)));  // limit بالاتر برای کانبان
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM tasks {$whereSql}", $bind);

        $rows = $db->fetchAll(
            "SELECT * FROM tasks {$whereSql} ORDER BY {$sort} LIMIT {$offset}, {$limit}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['subtasks_array'] = json_decode($r['subtasks'] ?: '[]', true) ?: [];
            $r['comments_array'] = json_decode($r['comments'] ?: '[]', true) ?: [];
            $r['subtask_progress'] = self::calcSubtaskProgress($r['subtasks_array']);
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن تسک با ID
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT t.*, p.name AS project_name
             FROM tasks t
             LEFT JOIN projects p ON p.id = t.project_id
             WHERE t.id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;
        $row['subtasks_array'] = json_decode($row['subtasks'] ?: '[]', true) ?: [];
        $row['comments_array'] = json_decode($row['comments'] ?: '[]', true) ?: [];
        $row['subtask_progress'] = self::calcSubtaskProgress($row['subtasks_array']);
        return $row;
    }

    /**
     * ایجاد تسک
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['title'])) Response::validationError('عنوان تسک الزامی است');

        if (empty($filtered['priority'])) $filtered['priority'] = 'medium';
        if (empty($filtered['status'])) $filtered['status'] = 'new';

        // subtasks و comments به JSON تبدیل کن
        if (isset($filtered['subtasks']) && is_array($filtered['subtasks'])) {
            $filtered['subtasks'] = json_encode($filtered['subtasks'], JSON_UNESCAPED_UNICODE);
        } elseif (!isset($filtered['subtasks'])) {
            $filtered['subtasks'] = '[]';
        }
        if (isset($filtered['comments']) && is_array($filtered['comments'])) {
            $filtered['comments'] = json_encode($filtered['comments'], JSON_UNESCAPED_UNICODE);
        } elseif (!isset($filtered['comments'])) {
            $filtered['comments'] = '[]';
        }

        if (empty($filtered['project_id'])) $filtered['project_id'] = null;
        if (empty($filtered['start_date'])) $filtered['start_date'] = Jalali::today();

        return $db->insert('tasks', $filtered);
    }

    /**
     * به‌روزرسانی تسک
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تسک یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (isset($filtered['subtasks']) && is_array($filtered['subtasks'])) {
            $filtered['subtasks'] = json_encode($filtered['subtasks'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($filtered['comments']) && is_array($filtered['comments'])) {
            $filtered['comments'] = json_encode($filtered['comments'], JSON_UNESCAPED_UNICODE);
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('tasks', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * تغییر وضعیت (برای drag & drop در کانبان)
     */
    public static function changeStatus($id, $status)
    {
        if (!in_array($status, self::$statuses, true)) {
            Response::validationError('وضعیت نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('تسک یافت نشد');

        return Database::getInstance()->update('tasks', ['status' => $status], 'id = ?', [(int)$id]);
    }

    /**
     * حذف
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تسک یافت نشد');
        return Database::getInstance()->delete('tasks', 'id = ?', [(int)$id]);
    }

    /**
     * افزودن کامنت
     */
    public static function addComment($id, $text, $user)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تسک یافت نشد');

        $comments = $existing['comments_array'];
        $comments[] = [
            'user' => $user,
            'text' => $text,
            'date' => Jalali::now('Y/m/d H:i'),
        ];

        return Database::getInstance()->update(
            'tasks',
            ['comments' => json_encode($comments, JSON_UNESCAPED_UNICODE)],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * تغییر وضعیت زیرتسک
     */
    public static function toggleSubtask($taskId, $subtaskIndex, $checked)
    {
        $existing = self::find($taskId);
        if (!$existing) Response::notFound('تسک یافت نشد');

        $subtasks = $existing['subtasks_array'];
        if (!isset($subtasks[$subtaskIndex])) {
            Response::notFound('زیرتسک یافت نشد');
        }
        $subtasks[$subtaskIndex]['done'] = (bool)$checked;

        return Database::getInstance()->update(
            'tasks',
            ['subtasks' => json_encode($subtasks, JSON_UNESCAPED_UNICODE)],
            'id = ?',
            [(int)$taskId]
        );
    }

    /**
     * محاسبه درصد پیشرفت زیرتسک‌ها
     */
    private static function calcSubtaskProgress($subtasks)
    {
        if (empty($subtasks)) return 0;
        $done = count(array_filter($subtasks, fn($s) => !empty($s['done'])));
        return (int)(($done / count($subtasks)) * 100);
    }

    /**
     * آمار تسک‌ها
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM tasks GROUP BY status');
        foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT priority, COUNT(*) AS cnt FROM tasks GROUP BY priority');
        foreach ($rows as $r) $stats['by_priority'][$r['priority']] = (int)$r['cnt'];

        $rows = $db->fetchAll('SELECT project, COUNT(*) AS cnt FROM tasks WHERE project IS NOT NULL GROUP BY project');
        foreach ($rows as $r) $stats['by_project'][$r['project']] = (int)$r['cnt'];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM tasks');
        $stats['done'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM tasks WHERE status = 'done'");
        $stats['completion_rate'] = $stats['total'] > 0
            ? round(($stats['done'] / $stats['total']) * 100, 1)
            : 0;

        return $stats;
    }

    /**
     * دریافت تسک‌های یک نفر
     */
    public static function getByAssignee($assignee)
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT * FROM tasks WHERE assignee = ? ORDER BY FIELD(status, "new","in_progress","review","done","cancelled"), end_date ASC',
            [$assignee]
        );
        foreach ($rows as &$r) {
            $r['subtasks_array'] = json_decode($r['subtasks'] ?: '[]', true) ?: [];
            $r['subtask_progress'] = self::calcSubtaskProgress($r['subtasks_array']);
        }
        return $rows;
    }

    /**
     * دریافت لیست مسئولین (برای فیلتر)
     */
    public static function getAssignees()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DISTINCT assignee FROM tasks WHERE assignee IS NOT NULL AND assignee != '' ORDER BY assignee"
        );
        return array_column($rows, 'assignee');
    }
}
