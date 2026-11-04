<?php
/**
 * جاکجاست - مدل سرنخ میهمان (Guest Leads)
 * Jakojast - Guest Lead Model
 *
 * سرنخ میهمان = درخواست رزرو اقامتگاه از سوی مشتری
 * فیلدها: تاریخ، اپراتور، وضعیت، مرجع، نام مشتری، شماره تماس،
 *         محل زندگی، محلات درخواستی، نوع اقامتگاه، قیمت_min/max،
 *         اقامتگاه‌های معرفی شده، امکانات، توضیحات
 */

class Lead
{
    // فیلدهای قابل mass-assign (شامل فیلدهای جدید و قدیمی)
    private static $fillable = [
        // فیلدهای قدیمی (backward compatibility)
        'name', 'mobile', 'source', 'stage', 'assignee',
        'next_contact', 'value', 'note',
        // فیلدهای جدید (سرنخ میهمان)
        'operator', 'status', 'reference', 'residence',
        'requested_neighborhoods', 'property_type', 'min_price', 'max_price',
        'recommended_properties', 'amenities_basic', 'amenities_special',
        'description', 'date',
    ];

    // مراحل قدیمی (backward compatibility)
    public static $stages = ['جدید', 'تماس اول', 'مذاکره', 'قرارداد', 'باخته', 'برنده'];
    public static $sources = ['اینستاگرام', 'گوگل', 'معرفی', 'تبلیغات', 'تماس ورودی', 'دیگر'];

    /**
     * بارگذاری لیست‌های داده‌ای از فایل config
     */
    private static function loadDataLists()
    {
        $listFile = ROOT_PATH . '/config/data_lists.php';
        if (file_exists($listFile) && !isset($GLOBALS['JJ_OPERATORS'])) {
            require_once $listFile;
        }
    }

    /** @var bool|null cache برای بررسی ستون‌ها */
    private static $columnCheckCache = null;

    /**
     * بررسی وجود ستون‌های جدید در جدول leads
     * (برای backward compatibility اگر migration اجرا نشده)
     *
     * @return bool
     */
    private static function hasNewColumns()
    {
        if (self::$columnCheckCache !== null) return self::$columnCheckCache;
        try {
            $db = Database::getInstance();
            $db->fetchColumn("SELECT operator FROM leads LIMIT 1");
            self::$columnCheckCache = true;
        } catch (Exception $e) {
            self::$columnCheckCache = false;
        }
        return self::$columnCheckCache;
    }

    /**
     * نسخه‌ی عمومی autoMigrate برای فراخوانی از دکمه‌ی «به‌روزرسانی جداول
     * دیتابیس» در تنظیمات (چون autoMigrate اصلی private است)
     */
    public static function runAutoMigrate()
    {
        self::$columnCheckCache = null; // اجبار به بررسی دوباره
        self::autoMigrate();
    }

    /**
     * تبدیل یک‌باره‌ی مقادیر قدیمی وضعیت سرنخ میهمان (۶ وضعیت کلی) به
     * پایپ‌لاین جدید و دقیق‌تر (۹ مرحله، مطابق جریان واقعی رزرو).
     *
     * ⚠️ نکته‌ی مهم: این تبدیل مستقل از `hasNewColumns()` اجرا می‌شود
     * چون آن پرچم فقط برای «آیا ستون‌های operator/status/... اضافه
     * شده‌اند یا نه» است، نه برای «آیا مقادیرِ داخل status به‌روز است».
     * روی نصب‌های موجود، ستون‌ها از قبل هستند ولی مقدارشان قدیمی است.
     * با یک کلید در جدول settings فقط یک‌بار در کل عمر برنامه اجرا می‌شود.
     */
    private static function migrateOldStatusValues()
    {
        if (!class_exists('Report')) return;
        if (Report::getSetting('leads_status_pipeline_v2_migrated')) return;

        try {
            $db = Database::getInstance();
            $map = [
                'درخواست رزرو'        => 'جدید',
                'ارسال لینک اقامتگاه' => 'اقامتگاه پیشنهاد شد',
                'پیگیری مشتری'        => 'تماس گرفته شد',
                'ثبت رزرو'            => 'رزرو نهایی شد',
                'کنسلی درخواست'       => 'منصرف شد',
                'نظرسنجی'             => 'رزرو نهایی شد',
            ];
            foreach ($map as $old => $new) {
                $db->execute('UPDATE leads SET status = ? WHERE status = ?', [$new, $old]);
            }
            Report::setSetting('leads_status_pipeline_v2_migrated', '1');
            Logger::info('Lead status pipeline migrated to v2 (9-stage) successfully');
        } catch (\Throwable $e) {
            Logger::error('Lead status pipeline v2 migration failed: ' . $e->getMessage());
        }
    }

    /**
     * اطمینان از وجود جدول تاریخچه‌ی تغییر وضعیت سرنخ (بدون نیاز به
     * migration دستی - مثل الگوی TelefonchyApiLog)
     */
    private static function ensureStatusHistoryTable()
    {
        if (Database::schemaDone(__METHOD__)) return;
        try {
            Database::getInstance()->execute("
                CREATE TABLE IF NOT EXISTS `lead_status_history` (
                    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `lead_id`     INT UNSIGNED NOT NULL,
                    `old_status`  VARCHAR(60)  DEFAULT NULL,
                    `new_status`  VARCHAR(60)  NOT NULL,
                    `changed_by`  VARCHAR(120) DEFAULT NULL,
                    `note`        TEXT         DEFAULT NULL,
                    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_lead` (`lead_id`),
                    KEY `idx_created` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            $__schemaFailed = true; Logger::error('ensureStatusHistoryTable failed: ' . $e->getMessage());
        }
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
    }

    /**
     * ثبت یک ردیف در تاریخچه‌ی تغییر وضعیت (برای پاسخ به نیاز «تاریخچه‌ی
     * رفتار سرنخ» - هر تغییر وضعیت با چه‌کسی/چه‌زمانی ثبت می‌شود)
     *
     * @param int         $leadId
     * @param string|null $oldStatus
     * @param string      $newStatus
     * @param string|null $note
     */
    public static function logStatusChange($leadId, $oldStatus, $newStatus, $note = null)
    {
        if ($oldStatus === $newStatus) return; // بدون تغییر واقعی، ثبت نکن
        self::ensureStatusHistoryTable();
        try {
            $changedBy = null;
            if (class_exists('Auth')) {
                $user = Auth::currentUser();
                $changedBy = $user['full_name'] ?? $user['username'] ?? null;
            }
            Database::getInstance()->insert('lead_status_history', [
                'lead_id'    => (int)$leadId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => $changedBy,
                'note'       => $note,
            ]);
        } catch (\Throwable $e) {
            Logger::error('logStatusChange failed: ' . $e->getMessage());
        }
    }

    /**
     * تاریخچه‌ی کامل تغییرات وضعیت یک سرنخ (جدیدترین اول)
     *
     * @param int $leadId
     * @return array
     */
    public static function getStatusHistory($leadId)
    {
        self::ensureStatusHistoryTable();
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM lead_status_history WHERE lead_id = ? ORDER BY id DESC',
            [(int)$leadId]
        );
        foreach ($rows as &$r) {
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d H:i');
        }
        return $rows;
    }

    /**
     * اجرای خودکار migration اگر ستون‌های جدید وجود ندارند
     */
    private static function autoMigrate()
    {
        self::migrateOldStatusValues();
        if (self::hasNewColumns()) return;

        $migrationFile = ROOT_PATH . '/update_leads_schema.sql';
        if (!file_exists($migrationFile)) return;

        Logger::info('Auto migration: starting - adding new columns to leads table');

        try {
            $db = Database::getInstance();
            $sql = file_get_contents($migrationFile);
            // حذف کامنت‌ها
            $sql = preg_replace('/--.*$/m', '', $sql);
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            $executed = 0;
            $errors = 0;
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if (empty($stmt) || stripos($stmt, 'ALTER TABLE') === false) continue;
                try {
                    $db->execute($stmt);
                    $executed++;
                } catch (Exception $e) {
                    // اگر ستون قبلاً وجود دارد (خطای 1060)، نادیده بگیر
                    $errors++;
                }
            }

            // پاک کردن cache برای بررسی مجدد
            self::$columnCheckCache = null;

            Logger::info("Auto migration: completed - {$executed} statements executed, {$errors} skipped");

        } catch (Exception $e) {
            Logger::error('Auto migration failed: ' . $e->getMessage());
        }
    }

    /**
     * دریافت لیست اپراتورها
     */
    /**
     * لیست اپراتورها (پرسنل پشتیبانی) برای انتخاب در فرم سرنخ
     *
     * ⚠️ رفع باگ: قبلاً این متد یک لیست کاملاً ثابت و فرضی از
     * config/data_lists.php برمی‌گرداند (نام‌های نمونه از راه‌اندازی
     * اولیه که هیچ ربطی به پرسنل واقعی نداشتند). سپس به «همه‌ی پرسنل
     * فعال» تغییر کرد، ولی طبق بازخورد، اپراتور سرنخ باید فقط از واحد
     * پشتیبانی باشد (نه مثلاً مالی یا فنی) - الان دقیقاً همین فیلتر
     * اعمال می‌شود.
     *
     * @return array
     */
    public static function getOperators()
    {
        try {
            $names = Database::getInstance()->fetchAll(
                "SELECT name FROM staff WHERE status = 'active' AND unit = 'پشتیبانی' ORDER BY name ASC"
            );
            $names = array_column($names, 'name');
            if (!empty($names)) return $names;
        } catch (\Throwable $e) {
            Logger::error('Lead::getOperators failed to read staff table: ' . $e->getMessage());
        }

        // fallback فقط برای نصب‌های خیلی تازه که هنوز هیچ پرسنل پشتیبانی ثبت نشده
        self::loadDataLists();
        return $GLOBALS['JJ_OPERATORS'] ?? [];
    }

    /**
     * دریافت لیست وضعیت‌های سرنخ میهمان
     */
    public static function getGuestStatuses()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_GUEST_STATUSES'] ?? [
            'جدید', 'تماس گرفته شد', 'اقامتگاه پیشنهاد شد',
            'در انتظار تأیید میزبان', 'پیش‌فاکتور ارسال شد',
            'در انتظار پرداخت', 'رزرو نهایی شد', 'منصرف شد', 'بی‌پاسخ',
        ];
    }

    /**
     * وضعیت‌هایی که رسیدن سرنخ بهشون یعنی «نیاز به پیگیری بعدی» دارد -
     * برای هشدار نرم در UI (نه اجبار سخت) که اپراتور next_contact را پر کند
     */
    public static function getGuestStatusesNeedingFollowup()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_GUEST_STATUSES_NEEDING_FOLLOWUP'] ?? [
            'در انتظار تأیید میزبان', 'پیش‌فاکتور ارسال شد', 'در انتظار پرداخت', 'بی‌پاسخ',
        ];
    }

    /**
     * وضعیت‌های پایانی پایپ‌لاین (رزرو نهایی شد / منصرف شد)
     */
    public static function getGuestStatusesTerminal()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_GUEST_STATUSES_TERMINAL'] ?? ['رزرو نهایی شد', 'منصرف شد'];
    }

    /**
     * دریافت لیست مراجع
     *
     * ⚠️ الان از جدول واقعی `lead_references` می‌خواند (نه لیست ثابت
     * قدیمی در config/data_lists.php). اولین بار که این جدول خالی باشد،
     * خودکار از همان لیست قدیمی پر می‌شود تا نصب‌های موجود مرجع خالی نبینند.
     */
    public static function getGuestReferences()
    {
        self::loadDataLists();
        $legacy = $GLOBALS['JJ_GUEST_REFERENCES'] ?? [
            'تماس از سایت', 'استعلام از سایت', 'اینستاگرام', 'سرریز یاس',
            'معرف', 'قدیمی', 'دیوار', 'رینگرو', 'بیکاشی', 'پور', 'بله'
        ];
        if (class_exists('Reference')) {
            Reference::seedFromLegacyListIfEmpty($legacy);
            $names = Reference::getActiveNames();
            if (!empty($names)) return $names;
        }
        return $legacy;
    }

    /**
     * دریافت لیست انواع اقامتگاه
     */
    public static function getPropertyTypes()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_PROPERTY_TYPES'] ?? ['آپارتمان', 'ویلا', 'سوئیت', 'کلبه', 'هتل'];
    }

    /**
     * دریافت لیست محلات
     *
     * ⚠️ بهبود: قبلاً این لیست کاملاً ثابت بود (فقط از config/data_lists.php)
     * و هیچ راهی برای اضافه‌کردن محل جدید وجود نداشت. الان علاوه بر آن
     * لیست پایه، تمام محلاتی که قبلاً در سرنخ‌های واقعی ثبت شده‌اند هم
     * اضافه می‌شوند - یعنی هر محل جدیدی که کاربر از فرم اضافه کند، از
     * همان سرنخ بعدی در این لیست ظاهر می‌شود (بدون نیاز به جدول/تنظیمات جدا).
     *
     * @return array
     */
    public static function getNeighborhoods()
    {
        self::loadDataLists();
        $base = $GLOBALS['JJ_NEIGHBORHOODS'] ?? [];

        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT DISTINCT requested_neighborhoods FROM leads WHERE requested_neighborhoods IS NOT NULL AND requested_neighborhoods != '' AND requested_neighborhoods != '[]'"
            );
            $fromDb = [];
            foreach ($rows as $r) {
                $decoded = json_decode($r['requested_neighborhoods'], true);
                if (is_array($decoded)) $fromDb = array_merge($fromDb, $decoded);
            }
            $merged = array_values(array_unique(array_merge($base, $fromDb)));
            sort($merged, SORT_STRING | SORT_FLAG_CASE);
            return $merged;
        } catch (\Throwable $e) {
            Logger::error('Lead::getNeighborhoods failed to read leads table: ' . $e->getMessage());
            return $base;
        }
    }

    /**
     * دریافت لیست امکانات پایه
     */
    public static function getAmenitiesBasic()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_AMENITIES_BASIC'] ?? [];
    }

    /**
     * دریافت لیست امکانات ویژه
     */
    public static function getAmenitiesSpecial()
    {
        self::loadDataLists();
        return $GLOBALS['JJ_AMENITIES_SPECIAL'] ?? [];
    }

    /**
     * لیست سرنخ‌ها با فیلتر و صفحه‌بندی
     */
    public static function all(array $params = [])
    {
        self::autoMigrate();
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            $where[] = '(name LIKE ? OR mobile LIKE ? OR operator LIKE ? OR reference LIKE ? OR description LIKE ? OR residence LIKE ?)';
            array_push($bind, $search, $search, $search, $search, $search, $search);
        }

        // فیلتر بر اساس وضعیت جدید
        if (!empty($params['status'])) {
            $where[] = 'status = ?';
            $bind[] = $params['status'];
        }
        // فیلتر بر اساس مرحله قدیمی
        if (!empty($params['stage']) && in_array($params['stage'], self::$stages, true)) {
            $where[] = 'stage = ?';
            $bind[] = $params['stage'];
        }
        // فیلتر بر اساس منبع قدیمی
        if (!empty($params['source']) && in_array($params['source'], self::$sources, true)) {
            $where[] = 'source = ?';
            $bind[] = $params['source'];
        }
        // فیلتر بر اساس مرجع جدید
        if (!empty($params['reference'])) {
            $where[] = 'reference = ?';
            $bind[] = $params['reference'];
        }
        // فیلتر بر اساس اپراتور
        if (!empty($params['operator'])) {
            $where[] = 'operator = ?';
            $bind[] = $params['operator'];
        }
        // فیلتر بر اساس نوع اقامتگاه
        if (!empty($params['property_type'])) {
            $where[] = 'property_type = ?';
            $bind[] = $params['property_type'];
        }

        // ⚠️ محدودسازی دید: «هر کاربر فقط سرنخ‌های خودش را ببیند مگر
        // سرپرست یا مدیر کل باشد» - نگاه کنید به Auth::getVisibleAssigneeNames.
        // null یعنی بدون محدودیت؛ آرایه (حتی خالی) یعنی فقط همین‌ها. سرنخ‌های
        // هنوز-برداشته‌نشده (بدون اپراتور) هم همیشه دیده می‌شوند - وگرنه
        // کارشناس‌های محدودشده هیچ‌وقت سرنخ جدید برای claim کردن نمی‌بینند
        // (نگاه کنید به دکمه‌ی «🖐 برداشتن» در v4).
        if (array_key_exists('visible_assignees', $params) && $params['visible_assignees'] !== null) {
            if (empty($params['visible_assignees'])) {
                $where[] = "(operator IS NULL OR operator = '') AND (assignee IS NULL OR assignee = '')";
            } else {
                $placeholders = implode(',', array_fill(0, count($params['visible_assignees']), '?'));
                $where[] = "(operator IN ({$placeholders}) OR assignee IN ({$placeholders}) OR ((operator IS NULL OR operator = '') AND (assignee IS NULL OR assignee = '')))";
                foreach ($params['visible_assignees'] as $name) $bind[] = $name;
                foreach ($params['visible_assignees'] as $name) $bind[] = $name;
            }
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = [
            'date' => 'created_at DESC',
            'updated' => 'COALESCE(updated_at, created_at) DESC',
            'value' => 'value DESC',
            'name' => 'name ASC',
            'next_contact' => 'next_contact ASC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date'] ?? 'created_at DESC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM leads {$whereSql}", $bind);

        $rows = $db->fetchAll(
            "SELECT * FROM leads {$whereSql} ORDER BY {$sort} LIMIT {$offset}, {$limit}",
            $bind
        );

        // parse JSON fields
        foreach ($rows as &$r) {
            $r['requested_neighborhoods_array'] = json_decode($r['requested_neighborhoods'] ?: '[]', true) ?: [];
            $r['recommended_properties_array'] = json_decode($r['recommended_properties'] ?: '[]', true) ?: [];
            $r['amenities_basic_array'] = json_decode($r['amenities_basic'] ?: '[]', true) ?: [];
            $r['amenities_special_array'] = json_decode($r['amenities_special'] ?: '[]', true) ?: [];
            $r['created_jalali'] = Jalali::fromGregorian($r['created_at'], 'Y/m/d');
            // ⚠️ ستون جدید «آخرین به‌روزرسانی» - برای سرنخ‌هایی که هنوز
            // هیچ ویرایشی نخورده‌اند (updated_at خالی)، همان تاریخ ثبت
            // اولیه نمایش داده می‌شود (نه یک مقدار خالی/گمراه‌کننده)
            $r['updated_jalali'] = Jalali::fromGregorian($r['updated_at'] ?: $r['created_at'], 'Y/m/d H:i');
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن سرنخ با ID
     */
    public static function find($id)
    {
        self::autoMigrate();
        $row = Database::getInstance()->fetchOne('SELECT * FROM leads WHERE id = ?', [(int)$id]);
        if (!$row) return null;

        $row['requested_neighborhoods_array'] = json_decode($row['requested_neighborhoods'] ?: '[]', true) ?: [];
        $row['recommended_properties_array'] = json_decode($row['recommended_properties'] ?: '[]', true) ?: [];
        $row['amenities_basic_array'] = json_decode($row['amenities_basic'] ?: '[]', true) ?: [];
        $row['amenities_special_array'] = json_decode($row['amenities_special'] ?: '[]', true) ?: [];
        $row['created_jalali'] = Jalali::fromGregorian($row['created_at'], 'Y/m/d');
        return $row;
    }

    /**
     * ایجاد سرنخ جدید
     */
    public static function create(array $data)
    {
        self::autoMigrate();
        $db = Database::getInstance();
        $hasNew = self::hasNewColumns();

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (empty($filtered['name'])) {
            Response::validationError('نام مشتری الزامی است');
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizeMobile($filtered['mobile']);
            if ($mobile === false) {
                // شماره‌های تلفن ثابت/داخلی (مثلاً از سرنخ‌های تلفنی) موبایل
                // معتبر ایرانی نیستند ولی هنوز شماره‌ی تماس معتبری‌اند؛
                // به‌جای رد کردن کامل، فقط ارقام را نگه می‌داریم
                $digitsOnly = preg_replace('/\D+/', '', $filtered['mobile']);
                $filtered['mobile'] = $digitsOnly ?: $filtered['mobile'];
            } else {
                $filtered['mobile'] = $mobile;
            }
        }

        // تبدیل آرایه‌ها به JSON
        $jsonFields = ['requested_neighborhoods', 'recommended_properties', 'amenities_basic', 'amenities_special'];
        foreach ($jsonFields as $f) {
            if (isset($filtered[$f]) && is_array($filtered[$f])) {
                $filtered[$f] = json_encode($filtered[$f], JSON_UNESCAPED_UNICODE);
            }
        }

        // پیش‌فرض‌ها
        if (empty($filtered['stage'])) $filtered['stage'] = 'جدید';
        if (empty($filtered['source'])) $filtered['source'] = $filtered['reference'] ?? 'دیگر';
        if (empty($filtered['date'])) $filtered['date'] = Jalali::today();
        if (empty($filtered['status'])) $filtered['status'] = 'جدید';

        // همگام‌سازی source = reference و assignee = operator برای backward compatibility
        if (!empty($filtered['reference'])) $filtered['source'] = $filtered['reference'];
        if (!empty($filtered['operator'])) $filtered['assignee'] = $filtered['operator'];
        if (!empty($filtered['description'])) $filtered['note'] = $filtered['description'];

        // اگر ستون‌های جدید وجود ندارند، فقط ستون‌های قدیمی را درج کن
        if (!$hasNew) {
            $oldOnly = ['name', 'mobile', 'source', 'stage', 'assignee', 'next_contact', 'value', 'note'];
            $filtered = array_intersect_key($filtered, array_flip($oldOnly));
        }

        return $db->insert('leads', $filtered);
    }

    /**
     * به‌روزرسانی سرنخ
     */
    public static function update($id, array $data)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ یافت نشد');

        $hasNew = self::hasNewColumns();

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        if (!empty($filtered['mobile'])) {
            $mobile = Helpers::sanitizeMobile($filtered['mobile']);
            if ($mobile === false) {
                // شماره‌های تلفن ثابت/داخلی (مثلاً از سرنخ‌های تلفنی) موبایل
                // معتبر ایرانی نیستند ولی هنوز شماره‌ی تماس معتبری‌اند؛
                // به‌جای رد کردن کامل، فقط ارقام را نگه می‌داریم
                $digitsOnly = preg_replace('/\D+/', '', $filtered['mobile']);
                $filtered['mobile'] = $digitsOnly ?: $filtered['mobile'];
            } else {
                $filtered['mobile'] = $mobile;
            }
        }

        // تبدیل آرایه‌ها به JSON
        $jsonFields = ['requested_neighborhoods', 'recommended_properties', 'amenities_basic', 'amenities_special'];
        foreach ($jsonFields as $f) {
            if (isset($filtered[$f]) && is_array($filtered[$f])) {
                $filtered[$f] = json_encode($filtered[$f], JSON_UNESCAPED_UNICODE);
            }
        }

        // همگام‌سازی backward compatibility
        if (!empty($filtered['reference'])) $filtered['source'] = $filtered['reference'];
        if (!empty($filtered['operator'])) $filtered['assignee'] = $filtered['operator'];
        if (isset($filtered['description'])) $filtered['note'] = $filtered['description'];

        // اگر ستون‌های جدید وجود ندارند، فقط ستون‌های قدیمی را به‌روزرسانی کن
        if (!$hasNew) {
            $oldOnly = ['name', 'mobile', 'source', 'stage', 'assignee', 'next_contact', 'value', 'note'];
            $filtered = array_intersect_key($filtered, array_flip($oldOnly));
        }

        if (empty($filtered)) Response::validationError('داده‌ای ارسال نشده');

        // ⚠️ اعتبارسنجی وضعیت جدید در برابر پایپ‌لاین شناخته‌شده - جلوگیری
        // از ثبت یک رشته‌ی دلخواه/تایپویی که هیچ‌جای پایپ‌لاین نیست
        $statusChanged = array_key_exists('status', $filtered) && $filtered['status'] !== ($existing['status'] ?? null);
        if ($statusChanged && !in_array($filtered['status'], self::getGuestStatuses(), true)) {
            Response::validationError('وضعیت نامعتبر است: ' . $filtered['status']);
        }

        $result = Database::getInstance()->update('leads', $filtered, 'id = ?', [(int)$id]);

        if ($statusChanged) {
            self::logStatusChange($id, $existing['status'] ?? null, $filtered['status']);
        }

        return $result;
    }

    /**
     * ⚠️ رفع مشکل race condition در برداشتن سرنخ‌های جدید (بدون اپراتور):
     * تا قبل از این، تنها راه assign کردن یک سرنخ باز کردن فرم کامل ویرایش
     * و ذخیره‌ی معمولی بود - اگر دو اپراتور هم‌زمان یک سرنخ تازه را باز
     * می‌کردند و هرکدام خودشان را انتخاب می‌کردند، آخرین ذخیره بی‌سروصدا
     * برنده می‌شد و اولی گم می‌شد (بدون هیچ خطا یا هشداری).
     *
     * این متد یک UPDATE اتمیک با شرط «هنوز کسی برنداشته» انجام می‌دهد.
     * چون InnoDB روی ردیف مورد UPDATE قفل می‌گیرد، اگر دو درخواست دقیقاً
     * هم‌زمان برسند، دومی تا تمام‌شدن اولی صبر می‌کند و بعد شرط WHERE را
     * در برابر مقدار *به‌روز* (نه مقدار لحظه‌ی خواندنش) چک می‌کند - یعنی
     * اگر اولی موفق شده باشد، دومی حتماً ۰ ردیف را آپدیت می‌کند و متوجه
     * می‌شود که دیر رسیده، به‌جای این‌که بی‌خبر رویش overwrite کند.
     *
     * @param int $id
     * @param string $operatorName  (نام کامل کاربر لاگین‌کرده - از سمت سرور، نه از ورودی کاربر)
     * @return array {claimed: bool, lead: array|null, message: string}
     */
    public static function claim($id, $operatorName)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ یافت نشد');

        if (empty($operatorName)) {
            Response::validationError('نام اپراتور مشخص نیست');
        }

        $hasNew = self::hasNewColumns();
        $currentAssignee = $hasNew ? ($existing['operator'] ?: $existing['assignee']) : $existing['assignee'];

        // سرنخِ از قبل برداشته‌شده: بلافاصله (بدون تلاش برای UPDATE) پیام واضح بده
        if (!empty($currentAssignee)) {
            return [
                'claimed' => false,
                'lead'    => $existing,
                'message' => $currentAssignee === $operatorName
                    ? 'این سرنخ همین الان هم مال شماست'
                    : "این سرنخ قبلاً توسط «{$currentAssignee}» برداشته شده",
            ];
        }

        $fields = $hasNew ? ['operator' => $operatorName, 'assignee' => $operatorName] : ['assignee' => $operatorName];
        $affected = Database::getInstance()->update(
            'leads',
            $fields,
            'id = ? AND (' . ($hasNew ? '(operator IS NULL OR operator = \'\') AND ' : '') . '(assignee IS NULL OR assignee = \'\'))',
            [(int)$id]
        );

        if ($affected === 0) {
            // بین لحظه‌ی چک بالا و همین UPDATE، یک درخواست دیگر برنده شد
            $fresh = self::find($id);
            $freshAssignee = $hasNew ? (($fresh['operator'] ?? null) ?: ($fresh['assignee'] ?? null)) : ($fresh['assignee'] ?? null);
            return [
                'claimed' => false,
                'lead'    => $fresh,
                'message' => "این سرنخ همین چند لحظه پیش توسط «{$freshAssignee}» برداشته شد",
            ];
        }

        Logger::info('Lead claimed', ['lead_id' => $id, 'operator' => $operatorName]);

        return [
            'claimed' => true,
            'lead'    => self::find($id),
            'message' => 'سرنخ با موفقیت برداشته شد',
        ];
    }

    /**
     * تغییر مرحله سرنخ (backward compatibility)
     */
    public static function changeStage($id, $stage)
    {
        if (!in_array($stage, self::$stages, true)) {
            Response::validationError('مرحله نامعتبر است');
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ یافت نشد');
        return Database::getInstance()->update('leads', ['stage' => $stage], 'id = ?', [(int)$id]);
    }

    /**
     * تغییر وضعیت سرنخ میهمان
     */
    public static function changeStatus($id, $status)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ یافت نشد');
        if (!in_array($status, self::getGuestStatuses(), true)) {
            Response::validationError('وضعیت نامعتبر است: ' . $status);
        }
        $result = Database::getInstance()->update('leads', ['status' => $status], 'id = ?', [(int)$id]);
        self::logStatusChange($id, $existing['status'] ?? null, $status);
        return $result;
    }

    /**
     * حذف سرنخ
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('سرنخ یافت نشد');
        return Database::getInstance()->delete('leads', 'id = ?', [(int)$id]);
    }

    /**
     * تبدیل سرنخ به مشتری
     */
    public static function convertToCustomer($leadId)
    {
        $db = Database::getInstance();
        $lead = self::find($leadId);
        if (!$lead) Response::notFound('سرنخ یافت نشد');

        if ($lead['stage'] === 'برنده') {
            Response::validationError('این سرنخ قبلاً تبدیل شده است');
        }

        if (!empty($lead['mobile'])) {
            $existing = Customer::findByMobile($lead['mobile']);
            if ($existing) {
                Response::validationError('مشتری با این موبایل قبلاً ثبت شده است: ' . $existing['name']);
            }
        }

        $db->beginTransaction();
        try {
            $customerId = $db->insert('customers', [
                'name'         => $lead['name'],
                'mobile'       => $lead['mobile'],
                'city'         => $lead['residence'] ?? null,
                'level'        => 'عادی',
                'status'       => 'active',
                'join_date'    => Jalali::today(),
                'total_spend'  => 0,
                'reservations' => 0,
                'score'        => 0,
            ]);
            $db->update('leads', ['stage' => 'برنده'], 'id = ?', [(int)$leadId]);
            $db->commit();
            return $customerId;
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * آمار سرنخ‌ها
     */
    public static function getStats()
    {
        self::autoMigrate();
        $db = Database::getInstance();
        $stats = [];
        $hasNew = self::hasNewColumns();

        // بر اساس وضعیت جدید (فقط اگر ستون status وجود دارد)
        if ($hasNew) {
            try {
                $rows = $db->fetchAll('SELECT status, COUNT(*) AS cnt FROM leads WHERE status IS NOT NULL GROUP BY status');
                foreach ($rows as $r) $stats['by_status'][$r['status']] = (int)$r['cnt'];
            } catch (Exception $e) { $stats['by_status'] = []; }
        } else {
            $stats['by_status'] = [];
        }

        // بر اساس مرحله قدیمی
        try {
            $rows = $db->fetchAll('SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total FROM leads GROUP BY stage');
            foreach ($rows as $r) {
                $stats['by_stage'][$r['stage']] = [
                    'count' => (int)$r['cnt'],
                    'value' => (int)$r['total'],
                ];
            }
        } catch (Exception $e) { $stats['by_stage'] = []; }

        // بر اساس منبع
        try {
            if ($hasNew) {
                $rows = $db->fetchAll('SELECT COALESCE(reference, source) AS ref, COUNT(*) AS cnt FROM leads GROUP BY ref');
            } else {
                $rows = $db->fetchAll('SELECT source AS ref, COUNT(*) AS cnt FROM leads GROUP BY source');
            }
            foreach ($rows as $r) $stats['by_reference'][$r['ref']] = (int)$r['cnt'];
        } catch (Exception $e) { $stats['by_reference'] = []; }

        // بر اساس اپراتور (فقط اگر ستون operator وجود دارد)
        if ($hasNew) {
            try {
                $rows = $db->fetchAll('SELECT operator, COUNT(*) AS cnt FROM leads WHERE operator IS NOT NULL GROUP BY operator');
                foreach ($rows as $r) $stats['by_operator'][$r['operator']] = (int)$r['cnt'];
            } catch (Exception $e) { $stats['by_operator'] = []; }
        } else {
            $stats['by_operator'] = [];
        }

        $stats['total_value'] = (int)$db->fetchColumn('SELECT COALESCE(SUM(value), 0) FROM leads');
        $stats['won_count'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM leads WHERE stage = 'برنده'");
        $stats['conversion_rate'] = 0;
        $total = (int)$db->fetchColumn('SELECT COUNT(*) FROM leads');
        if ($total > 0) {
            $stats['conversion_rate'] = round(($stats['won_count'] / $total) * 100, 1);
        }

        return $stats;
    }

    /**
     * دریافت لیست مسئولین (backward compatibility + operators)
     */
    public static function getAssignees()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DISTINCT COALESCE(operator, assignee) AS assignee FROM leads WHERE COALESCE(operator, assignee) IS NOT NULL AND COALESCE(operator, assignee) != '' ORDER BY assignee"
        );
        $fromDb = array_column($rows, 'assignee');
        $fromConfig = self::getOperators();
        return array_unique(array_merge($fromConfig, $fromDb));
    }
}
