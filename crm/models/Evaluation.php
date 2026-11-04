<?php
/**
 * جاکجاست - مدل ارزیابی عملکرد پرسنل
 * Jakojast - Staff Evaluation Model
 */

class Evaluation
{
    private static $fillable = [
        'staff_id', 'month', 'score', 'evaluator',
        'goals', 'strengths', 'weaknesses', 'note',
    ];

    /**
     * لیست ارزیابی‌ها
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['staff_id'])) {
            $where[] = 'e.staff_id = ?';
            $bind[] = (int)$params['staff_id'];
        }
        if (!empty($params['month'])) {
            $where[] = 'e.month LIKE ?';
            $bind[] = '%' . $params['month'] . '%';
        }
        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(s.name LIKE ? OR e.evaluator LIKE ? OR e.month LIKE ?)';
            array_push($bind, $search, $search, $search);
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn(
            "SELECT COUNT(*) FROM staff_evaluations e LEFT JOIN staff s ON s.id = e.staff_id {$whereSql}",
            $bind
        );

        $rows = $db->fetchAll(
            "SELECT e.*, s.name AS staff_name, s.code AS staff_code, s.unit AS staff_unit, s.role AS staff_role
             FROM staff_evaluations e
             LEFT JOIN staff s ON s.id = e.staff_id
             {$whereSql}
             ORDER BY e.created_at DESC
             LIMIT {$offset}, {$limit}",
            $bind
        );

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن با ID
     */
    public static function find($id)
    {
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT e.*, s.name AS staff_name, s.code AS staff_code, s.unit AS staff_unit
             FROM staff_evaluations e
             LEFT JOIN staff s ON s.id = e.staff_id
             WHERE e.id = ? LIMIT 1',
            [(int)$id]
        );
        return $row ?: null;
    }

    /**
     * ایجاد ارزیابی
     */
    public static function create(array $data)
    {
        $db = Database::getInstance();
        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['staff_id'])) Response::validationError('پرسنل الزامی است');
        if (empty($filtered['month'])) Response::validationError('ماه ارزیابی الزامی است');

        $staff = Staff::find((int)$filtered['staff_id']);
        if (!$staff) Response::notFound('پرسنل یافت نشد');

        // اعتبارسنجی امتیاز
        if (isset($filtered['score'])) {
            $filtered['score'] = (float)$filtered['score'];
            if ($filtered['score'] < 0 || $filtered['score'] > 100) {
                Response::validationError('امتیاز باید بین ۰ و ۱۰۰ باشد');
            }
        } else {
            $filtered['score'] = 0;
        }

        return $db->insert('staff_evaluations', $filtered);
    }

    /**
     * به‌روزرسانی
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('ارزیابی یافت نشد');

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (isset($filtered['score'])) {
            $filtered['score'] = (float)$filtered['score'];
            if ($filtered['score'] < 0 || $filtered['score'] > 100) {
                Response::validationError('امتیاز باید بین ۰ و ۱۰۰ باشد');
            }
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('staff_evaluations', $filtered, 'id = ?', [(int)$id]);
    }

    /**
     * حذف
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('ارزیابی یافت نشد');
        return Database::getInstance()->delete('staff_evaluations', 'id = ?', [(int)$id]);
    }

    /**
     * ارزیابی‌های یک پرسنل
     */
    public static function getByStaff($staffId)
    {
        return Database::getInstance()->fetchAll(
            'SELECT * FROM staff_evaluations WHERE staff_id = ? ORDER BY created_at DESC',
            [(int)$staffId]
        );
    }

    /**
     * میانگین امتیاز پرسنل
     */
    public static function getAverageScore($staffId)
    {
        $avg = Database::getInstance()->fetchColumn(
            'SELECT COALESCE(AVG(score), 0) FROM staff_evaluations WHERE staff_id = ?',
            [(int)$staffId]
        );
        return round((float)$avg, 1);
    }

    /**
     * آمار کلی ارزیابی‌ها
     */
    public static function getStats()
    {
        $db = Database::getInstance();
        $stats = [];

        $stats['total'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff_evaluations');
        $stats['avg_score'] = round((float)$db->fetchColumn('SELECT COALESCE(AVG(score), 0) FROM staff_evaluations'), 1);

        // توزیع امتیاز
        $stats['excellent'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff_evaluations WHERE score >= 90');
        $stats['good'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff_evaluations WHERE score >= 75 AND score < 90');
        $stats['average'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff_evaluations WHERE score >= 60 AND score < 75');
        $stats['poor'] = (int)$db->fetchColumn('SELECT COUNT(*) FROM staff_evaluations WHERE score < 60');

        // بهترین امتیازها
        $stats['top_performers'] = $db->fetchAll(
            'SELECT e.staff_id, s.name, s.code, s.unit, AVG(e.score) AS avg_score, COUNT(*) AS eval_count
             FROM staff_evaluations e
             LEFT JOIN staff s ON s.id = e.staff_id
             GROUP BY e.staff_id
             ORDER BY avg_score DESC
             LIMIT 5'
        );

        return $stats;
    }
}
