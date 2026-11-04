<?php
/**
 * جاکجاست - مدل پروژه
 * Jakojast - Project Model
 */

class Project
{
    private static $fillable = ['name', 'description', 'progress', 'members', 'deadline'];

    /**
     * لیست پروژه‌ها
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR description LIKE ?)';
            array_push($bind, $search, $search);
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM projects {$whereSql}", $bind);

        $rows = $db->fetchAll(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) AS task_count,
                    (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = 'done') AS done_count
             FROM projects p
             {$whereSql}
             ORDER BY p.created_at DESC
             LIMIT {$offset}, {$limit}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['members_array'] = json_decode($r['members'] ?: '[]', true) ?: [];
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
        }

        return ['items' => $rows, 'total' => $total];
    }

    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM tasks WHERE project_id = p.id) AS task_count,
                    (SELECT COUNT(*) FROM tasks WHERE project_id = p.id AND status = "done") AS done_count
             FROM projects p WHERE p.id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;
        $row['members_array'] = json_decode($row['members'] ?: '[]', true) ?: [];
        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d');
        return $row;
    }

    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['name'])) Response::validationError('نام پروژه الزامی است');

        // members می‌تواند array یا string باشد
        if (isset($filtered['members']) && is_array($filtered['members'])) {
            $filtered['members'] = json_encode($filtered['members'], JSON_UNESCAPED_UNICODE);
        } elseif (!isset($filtered['members'])) {
            $filtered['members'] = '[]';
        }

        if (!isset($filtered['progress'])) $filtered['progress'] = 0;
        $filtered['progress'] = max(0, min(100, (int)$filtered['progress']));

        return $db->insert('projects', $filtered);
    }

    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('پروژه یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (isset($filtered['members']) && is_array($filtered['members'])) {
            $filtered['members'] = json_encode($filtered['members'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($filtered['progress'])) {
            $filtered['progress'] = max(0, min(100, (int)$filtered['progress']));
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('projects', $filtered, 'id = ?', [(int)$id]);
    }

    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('پروژه یافت نشد');
        // پروژه‌های تسک‌ها به null تبدیل می‌شوند (FK ON DELETE SET NULL)
        return Database::getInstance()->delete('projects', 'id = ?', [(int)$id]);
    }

    /**
     * تسک‌های یک پروژه
     */
    public static function getTasks($projectId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM tasks WHERE project_id = ? ORDER BY FIELD(status, "new","in_progress","review","done","cancelled"), end_date ASC',
            [(int)$projectId]
        );
    }
}
