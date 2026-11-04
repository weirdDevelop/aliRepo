<?php
/**
 * جاکجاست - مشاهده‌ی خام رویدادهای webhook تلفنچی
 * Jakojast - Raw Telefonchy Webhook Events Viewer
 *
 * هدف: جدول `telefonchy_events` از قبل هر webhook خام (raw_body کامل) را
 * ذخیره می‌کرد، ولی هیچ API/UI ای برای دیدنش وجود نداشت - یعنی تنها راه
 * دیدن اینکه «تلفنچی واقعاً چی می‌فرسته» یک کوئری مستقیم روی دیتابیس
 * سرور بود. این مدل دقیقاً همان رکوردهای خام را (بدون هیچ تفسیر یا
 * پردازشی) از پنل قابل‌مشاهده می‌کند - برای پاسخ قطعی به سؤال «تلفنچی
 * دقیقاً چه ساختاری می‌فرستد» بدون نیاز به SSH یا phpMyAdmin.
 *
 * 📖 مرتبط: docs/TELEFONCHY_API.md بخش ۵ (وب‌هوک)
 */

class TelefonchyEvent
{
    /**
     * لیست آخرین رویدادهای خام دریافتی (جدیدترین اول)
     *
     * @param array $params {page, limit, cuid (فیلتر اختیاری بر اساس یک تماس مشخص)}
     * @return array {items, total}
     */
    public static function all(array $params = [])
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['cuid'])) {
            $where[] = 'call_cuid = ?';
            $bind[] = $params['cuid'];
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limit = min((int)($params['limit'] ?? 20), 100);
        $page = max((int)($params['page'] ?? 1), 1);
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM telefonchy_events {$whereSql}", $bind);

        $items = $db->fetchAll(
            "SELECT id, event_type, call_cuid, payload, received_at
             FROM telefonchy_events {$whereSql}
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind
        );

        // payload از قبل JSON معتبر ذخیره شده - همون‌طور که هست (رشته) برمی‌گردانیم
        // تا فرانت‌اند خودش pretty-print کند؛ raw_body را عمداً در لیست
        // برنمی‌گردانیم (ممکن است حجیم باشد)، فقط با endpoint جزئیات قابل دسترس است.
        return ['items' => $items, 'total' => $total];
    }

    /**
     * جزئیات کامل یک رویداد (شامل raw_body خام دقیقاً همان‌طور که از
     * تلفنچی رسیده - حتی اگر JSON معتبر نبوده باشد)
     *
     * @param int $id
     * @return array|null
     */
    public static function find($id)
    {
        return Database::getInstance()->fetchOne(
            "SELECT * FROM telefonchy_events WHERE id = ?",
            [(int)$id]
        );
    }

    /**
     * پاک کردن همه‌ی رویدادهای خام ذخیره‌شده
     */
    public static function clear()
    {
        Database::getInstance()->execute('TRUNCATE TABLE telefonchy_events');
    }
}
