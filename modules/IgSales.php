<?php
/**
 * modules/IgSales.php
 * نسخه‌ی اول «مدیریت فروش» (مورد ۳ نقشه‌راه): کاتالوگ ساده + ثبت سفارش برای پیگیری
 * اپراتور، بدون اتصال بیرونی. طراحی عمداً ساده و جدا از اینستاگرام نگه داشته شده
 * (هیچ‌جای این کلاس وابسته به دایرکت‌ساز نیست) تا:
 *   ۱) بعداً برای بقیه‌ی کانال‌ها هم قابل‌استفاده باشد،
 *   ۲) وقتی فاز بعد (اتصال به فروشگاه/انبار بیرونی از طریق API) اومد، فقط منبع داده
 *      عوض شود (مثلاً products() به‌جای خواندن از این جدول، از یک API بیرونی بخواند)،
 *      بدون این‌که UI/اکشن‌های API عوض شوند.
 */

class IgSales
{
    // ==================== کاتالوگ ====================

    public static function products(bool $onlyActive = true): array
    {
        $sql = "SELECT * FROM eb_ig_products";
        if ($onlyActive) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY id DESC";
        return Database::all($sql);
    }

    public static function saveProduct(array $p): int
    {
        $id = (int)($p['id'] ?? 0);
        $vals = [
            trim((string)($p['title'] ?? '')),
            trim((string)($p['sku'] ?? '')) ?: null,
            ($p['price'] ?? '') !== '' ? (float)$p['price'] : null,
            ($p['stock'] ?? '') !== '' ? (int)$p['stock'] : null,
            trim((string)($p['image_url'] ?? '')) ?: null,
            !empty($p['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            Database::run(
                "UPDATE eb_ig_products SET title=?, sku=?, price=?, stock=?, image_url=?, is_active=? WHERE id=?",
                array_merge($vals, [$id])
            );
            return $id;
        }
        Database::run(
            "INSERT INTO eb_ig_products (title, sku, price, stock, image_url, is_active) VALUES (?,?,?,?,?,?)",
            $vals
        );
        return (int)Database::lastId();
    }

    public static function deleteProduct(int $id): void
    {
        Database::run("DELETE FROM eb_ig_products WHERE id = ?", [$id]);
    }

    // ==================== سفارش ====================

    public static function orders(?string $status = null, int $limit = 50): array
    {
        if ($status) {
            return Database::all(
                "SELECT o.*, p.full_name, p.phone FROM eb_ig_orders o
                 LEFT JOIN eb_persons p ON p.id = o.person_id
                 WHERE o.status = ? ORDER BY o.id DESC LIMIT $limit",
                [$status]
            );
        }
        return Database::all(
            "SELECT o.*, p.full_name, p.phone FROM eb_ig_orders o
             LEFT JOIN eb_persons p ON p.id = o.person_id
             ORDER BY o.id DESC LIMIT $limit"
        );
    }

    public static function orderItems(int $orderId): array
    {
        return Database::all("SELECT * FROM eb_ig_order_items WHERE order_id = ?", [$orderId]);
    }

    /** @param array $items [['product_id'=>?,'title'=>string,'qty'=>int,'unit_price'=>float], ...] */
    public static function createOrder(?int $personId, array $items, string $notes = ''): int
    {
        $total = 0;
        foreach ($items as $it) $total += ((float)($it['unit_price'] ?? 0)) * ((int)($it['qty'] ?? 1));

        Database::run(
            "INSERT INTO eb_ig_orders (person_id, status, total, notes) VALUES (?, 'pending', ?, ?)",
            [$personId, $total, $notes]
        );
        $orderId = (int)Database::lastId();

        foreach ($items as $it) {
            Database::run(
                "INSERT INTO eb_ig_order_items (order_id, product_id, title, qty, unit_price) VALUES (?,?,?,?,?)",
                [$orderId, $it['product_id'] ?? null, (string)($it['title'] ?? ''), (int)($it['qty'] ?? 1), (float)($it['unit_price'] ?? 0)]
            );
        }
        return $orderId;
    }

    public static function setStatus(int $orderId, string $status): void
    {
        $allowed = ['pending', 'confirmed', 'shipped', 'done', 'canceled'];
        if (!in_array($status, $allowed, true)) return;
        Database::run("UPDATE eb_ig_orders SET status = ? WHERE id = ?", [$status, $orderId]);
    }

    public static function deleteOrder(int $orderId): void
    {
        Database::run("DELETE FROM eb_ig_order_items WHERE order_id = ?", [$orderId]);
        Database::run("DELETE FROM eb_ig_orders WHERE id = ?", [$orderId]);
    }
}
