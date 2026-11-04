<?php
/**
 * جاکجاست - مدیریت مراجع سرنخ‌ها (منبع ورودی مشتری: سایت، اینستاگرام، معرف، ...)
 * Jakojast - Lead Reference/Source Management
 *
 * ⚠️ قبلاً این لیست فقط یک آرایه‌ی ثابت در config/data_lists.php بود که
 * فقط با ویرایش کد قابل تغییر بود. الان یک جدول واقعی دیتابیس است که از
 * پنل (بدون نیاز به دسترسی سرور) قابل مدیریت است.
 *
 * نام جدول عمداً `lead_references` است نه `references` چون REFERENCES
 * یک کلمه‌ی کلیدی رزرو‌شده در SQL است و باعث خطای syntax می‌شود.
 */

class Reference
{
    /** @var bool کش وضعیت وجود جدول */
    private static $tableChecked = false;

    /**
     * اطمینان از وجود جدول (auto-migration، بدون نیاز به SQL دستی)
     */
    private static function ensureTable()
    {
        if (self::$tableChecked) return;
        if (Database::schemaDone(__METHOD__)) { self::$tableChecked = true; return; }
        try {
            Database::getInstance()->execute("
                CREATE TABLE IF NOT EXISTS `lead_references` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`        VARCHAR(120) NOT NULL,
                    `code`        VARCHAR(40)  DEFAULT NULL,
                    `type`        VARCHAR(40)  DEFAULT NULL COMMENT 'مشتری|همکار|تامین‌کننده|سایر',
                    `description` TEXT         DEFAULT NULL,
                    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
                    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            $__schemaFailed = true; Logger::error('Reference::ensureTable failed: ' . $e->getMessage());
        }
        self::$tableChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    /**
     * لیست کامل مراجع (برای صفحه‌ی مدیریت تنظیمات)
     *
     * @param bool $activeOnly فقط مراجع فعال
     * @return array
     */
    public static function all($activeOnly = false)
    {
        self::ensureTable();
        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        return Database::getInstance()->fetchAll("SELECT * FROM lead_references {$where} ORDER BY name ASC");
    }

    /**
     * فقط نام مراجعِ فعال - برای پر کردن دراپ‌داون سرنخ‌ها
     * (جایگزین Lead::getGuestReferences قدیمی)
     *
     * @return array<string>
     */
    public static function getActiveNames()
    {
        self::ensureTable();
        $rows = Database::getInstance()->fetchAll("SELECT name FROM lead_references WHERE is_active = 1 ORDER BY name ASC");
        return array_column($rows, 'name');
    }

    public static function find($id)
    {
        self::ensureTable();
        return Database::getInstance()->fetchOne("SELECT * FROM lead_references WHERE id = ?", [(int)$id]);
    }

    public static function create(array $data)
    {
        self::ensureTable();
        $name = trim($data['name'] ?? '');
        if (empty($name)) Response::validationError('نام مرجع الزامی است');

        $exists = Database::getInstance()->fetchColumn("SELECT id FROM lead_references WHERE name = ?", [$name]);
        if ($exists) Response::validationError('مرجعی با این نام قبلاً ثبت شده است');

        return Database::getInstance()->insert('lead_references', [
            'name'        => $name,
            'code'        => trim($data['code'] ?? '') ?: null,
            'type'        => trim($data['type'] ?? '') ?: null,
            'description' => trim($data['description'] ?? '') ?: null,
            'is_active'   => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
        ]);
    }

    public static function update($id, array $data)
    {
        self::ensureTable();
        $existing = self::find($id);
        if (!$existing) Response::notFound('مرجع یافت نشد');

        $filtered = [];
        foreach (['name', 'code', 'type', 'description', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }
        if (isset($filtered['name'])) {
            $filtered['name'] = trim($filtered['name']);
            if (empty($filtered['name'])) Response::validationError('نام مرجع الزامی است');
            $dup = Database::getInstance()->fetchColumn("SELECT id FROM lead_references WHERE name = ? AND id != ?", [$filtered['name'], (int)$id]);
            if ($dup) Response::validationError('مرجعی با این نام قبلاً ثبت شده است');
        }
        if (isset($filtered['is_active'])) $filtered['is_active'] = (int)(bool)$filtered['is_active'];
        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        return Database::getInstance()->update('lead_references', $filtered, 'id = ?', [(int)$id]);
    }

    public static function delete($id)
    {
        self::ensureTable();
        $existing = self::find($id);
        if (!$existing) Response::notFound('مرجع یافت نشد');
        // حذف واقعی نمی‌کنیم - غیرفعال می‌کنیم تا سرنخ‌های قدیمی که به
        // این مرجع اشاره دارند بی‌معنی نشوند
        return Database::getInstance()->update('lead_references', ['is_active' => 0], 'id = ?', [(int)$id]);
    }

    /**
     * انتقال یک‌باره‌ی لیست ثابت قدیمی به جدول (فقط اگر جدول خالی باشد)
     * - برای اینکه نصب‌های موجود بلافاصله بعد از آپدیت، مراجع خالی نبینند
     */
    public static function seedFromLegacyListIfEmpty(array $legacyNames)
    {
        self::ensureTable();
        $count = (int)Database::getInstance()->fetchColumn("SELECT COUNT(*) FROM lead_references");
        if ($count > 0) return;
        foreach ($legacyNames as $name) {
            try {
                Database::getInstance()->insert('lead_references', ['name' => $name, 'is_active' => 1]);
            } catch (\Throwable $e) {
                // نادیده بگیر (مثلاً نام تکراری) و ادامه بده
            }
        }
    }
}
