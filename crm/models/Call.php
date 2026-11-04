<?php
/**
 * جاکجاست - مدل تماس‌ها
 * Jakojast - Call Model
 *
 * مدیریت تماس‌های دریافت شده از تلفنچی (CDR) با فیلتر، صفحه‌بندی،
 * و تطبیق با مشتریان/پرسنل.
 */

class Call
{
    private static $fillable = [
        'cuid', 'call_source', 'call_dest', 'type', 'status',
        'time_wait', 'time_talk', 'started_at', 'jalali_started_at',
        'trunk', 'exten_name', 'exten_number', 'file_record', 'file_id',
        'contact_id', 'lead_id', 'user_id', 'notes', 'call_category', 'call_label',
    ];

    /** نوع‌های معتبر طبقه‌بندی تماس (مهمان/میزبان/...) */
    public static $callCategories = ['guest', 'host', 'recruitment', 'spam', 'internal', 'unclassified'];

    /** برچسب فارسی هر دسته - برای نمایش در UI */
    public static $callCategoryLabels = [
        'guest'        => '🏠 مهمان',
        'host'         => '🔑 میزبان',
        'recruitment'  => '💼 جذب نیرو',
        'spam'         => '🚫 اسپم/تبلیغاتی',
        'internal'     => '👥 داخلی',
        'unclassified' => '❔ طبقه‌بندی‌نشده',
    ];

    /**
     * لیبل عملیاتی پشتیبانی روی هر تماس
     * (جدا از دسته‌بندی مهمان/میزبان — برای پیگیری فروش/استعلام)
     */
    public static $callLabels = [
        'none', 'price_inquiry', 'monthly_inquiry', 'unconfirmed',
        'converted_sale', 'callback', 'info', 'complaint',
    ];

    public static $callLabelLabels = [
        'none'             => '— بدون لیبل',
        'price_inquiry'    => '💰 استعلام قیمت',
        'monthly_inquiry'  => '📅 استعلام ماهانه',
        'unconfirmed'      => '⏳ تایید نشده',
        'converted_sale'   => '✅ تبدیل به فروش',
        'callback'         => '📞 نیاز به تماس مجدد',
        'info'             => 'ℹ️ اطلاعاتی',
        'complaint'        => '⚠️ شکایت',
    ];

    /** مهلت رسیدگی به تماس بی‌پاسخ (ثانیه) — پیش‌فرض ۲۰ دقیقه */
    const MISSED_ALERT_SECONDS = 1200;

    /** @var bool|null کش وضعیت وجود ستون file_id (برای auto-migration) */
    private static $fileIdColumnChecked = false;
    private static $aiAnalysisColumnChecked = false;
    private static $staleRingingColumnChecked = false;
    private static $callLabelColumnChecked = false;

    /**
     * اطمینان از وجود ستون file_id (که برای دانلود صدا طبق مستندات جدید
     * تلفنچی لازم است: GET /calls/record?file_id=...&cuid=...)
     * اگر وجود نداشت، خودکار اضافه می‌شود - نیازی به SQL دستی نیست.
     */
    private static function ensureFileIdColumn()
    {
        if (self::$fileIdColumnChecked) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$fileIdColumnChecked = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT file_id FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls` ADD COLUMN `file_id` VARCHAR(120) DEFAULT NULL COMMENT 'شناسه فایل صوتی (برای دانلود ضبط تماس)' AFTER `file_record`");
                Logger::info('Auto migration: added file_id column to calls table');
                $added[] = 'calls.file_id';
            }
            try {
                $db->fetchColumn("SELECT lead_id FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls` ADD COLUMN `lead_id` INT UNSIGNED DEFAULT NULL COMMENT 'سرنخ ایجادشده از این تماس (در صورت تبدیل)' AFTER `contact_id`");
                Logger::info('Auto migration: added lead_id column to calls table');
                $added[] = 'calls.lead_id';
            }
            try {
                $db->fetchColumn("SELECT transcript FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `transcript` MEDIUMTEXT DEFAULT NULL COMMENT 'متن پیاده‌شده‌ی مکالمه (Whisper)',
                    ADD COLUMN `transcript_status` VARCHAR(20) DEFAULT NULL COMMENT 'pending|processing|done|failed',
                    ADD COLUMN `transcribed_at` DATETIME DEFAULT NULL");
                Logger::info('Auto migration: added transcript columns to calls table');
                $added[] = 'calls.transcript(+status,+transcribed_at)';
            }
            try {
                $db->fetchColumn("SELECT call_category FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls` ADD COLUMN `call_category` VARCHAR(20) NOT NULL DEFAULT 'unclassified' COMMENT 'guest|host|recruitment|spam|internal|unclassified' AFTER `type`");
                $db->execute("ALTER TABLE `calls` ADD INDEX `idx_call_category` (`call_category`)");
                Logger::info('Auto migration: added call_category column to calls table');
                $added[] = 'calls.call_category';
            }
            $labelMig = self::ensureCallLabelColumn();
            $added = array_merge($added, $labelMig['added']);
            // ⚠️ ردیابی تلاش‌های backfill صدا: چون enrichment لحظه‌ای بعد از
            // hangup فقط یک‌بار و best-effort است (نگاه کنید به
            // CallSyncManager::ingestHangupEvent)، اگر GET /calls در همان
            // لحظه هنوز فایل صوتی را در پاسخ نداشته باشد (تلفنچی معمولاً چند
            // ثانیه تا چند دقیقه بعد از پایان تماس فایل را پردازش/آپلود
            // می‌کند)، file_id برای همیشه خالی می‌ماند. این دو ستون برای
            // retry دوره‌ای (با سقف تلاش) در cron_sync.php استفاده می‌شوند.
            try {
                $db->fetchColumn("SELECT file_id_attempts FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `file_id_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تعداد تلاش برای پیدا کردن file_id صدا',
                    ADD COLUMN `file_id_last_attempt` DATETIME DEFAULT NULL COMMENT 'آخرین زمان تلاش backfill صدا'");
                Logger::info('Auto migration: added file_id_attempts/file_id_last_attempt columns to calls table');
                $added[] = 'calls.file_id_attempts(+last_attempt)';
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Call::ensureFileIdColumn failed: ' . $e->getMessage());
            self::$fileIdColumnChecked = true;
            // ⚠️ عمداً throw نمی‌شود: این متد از مسیرهای عادی زیادی (create,
            // find, findMissingRecordings, ...) صدا زده می‌شود و نباید یک
            // خطای migration کل آن درخواست را بی‌دلیل خراب کند. فقط پیام
            // خطا را برمی‌گردانیم تا Call::migrate() (که فقط از دکمه‌ی
            // ادمین صدا زده می‌شود) بتواند آن را واضح نمایش دهد.
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$fileIdColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    /**
     * تماس‌های «یتیمِ صدا»: قطعاً مکالمه انجام شده (time_talk > 0) ولی هنوز
     * file_id نداریم - یعنی هر بار که کاربر می‌خواهد پخش کند، خطای «فایل
     * صوتی یافت نشد» می‌بیند. فقط تماس‌های ۳ روز اخیر (فایل‌های قدیمی‌تر از
     * تلفنچی معمولاً دیگر منقضی/غیرقابل‌دسترس‌اند) و کمتر از سقف تلاش.
     *
     * @param int $limit
     * @param int $maxAttempts
     * @return array
     */
    public static function findMissingRecordings($limit = 30, $maxAttempts = 6)
    {
        self::ensureFileIdColumn();
        return Database::getInstance()->fetchAll(
            "SELECT id, cuid, started_at, file_id_attempts
             FROM calls
             WHERE time_talk > 0
               AND file_id IS NULL
               AND started_at >= (NOW() - INTERVAL 3 DAY)
               AND file_id_attempts < ?
             ORDER BY started_at DESC
             LIMIT " . (int)$limit,
            [(int)$maxAttempts]
        );
    }

    /**
     * ثبت یک تلاش backfill صدا (چه موفق چه ناموفق) - برای رعایت سقف تلاش
     *
     * @param int $callId
     */
    public static function markRecordingAttempt($callId)
    {
        Database::getInstance()->execute(
            'UPDATE `calls` SET `file_id_attempts` = `file_id_attempts` + 1, `file_id_last_attempt` = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), (int)$callId]
        );
    }

    /**
     * ریست شمارنده‌ی تلاش صدا - وقتی کاربر دستی دکمه‌ی «🔄 بارگذاری مجدد
     * صدا» را می‌زند. چون سقف تلاش خودکار (پیش‌فرض ۶ بار) ممکن است برای
     * تماس‌های قدیمی‌تر (که در چند دور تست/دیپلوی قبلی امتحان شده‌اند) از
     * قبل پر شده باشد، این تماس دیگر توسط backfillMissingRecordings
     * خودکار چک نمی‌شود. با ریست کردن، اگر تلاش دستی هم موفق نشد (چون
     * تلفنچی هنوز فایل را آماده نکرده)، سیستم خودکار دوباره چند بار دیگر
     * (در کرون‌های بعدی) امتحان می‌کند - دقیقاً همان چیزی که خواسته شد:
     * «سیستم هم باید خودکار این‌کار را انجام بدهد».
     *
     * @param int $callId
     */
    public static function resetRecordingAttempts($callId)
    {
        self::ensureFileIdColumn();
        Database::getInstance()->execute(
            'UPDATE `calls` SET `file_id_attempts` = 0, `file_id_last_attempt` = NULL WHERE id = ?',
            [(int)$callId]
        );
    }

    /**
     * migration ستون‌های ردیابی «تماس‌های گیرکرده روی ringing»
     *
     * ⚠️ باگ ریشه‌ای: وب‌هوک `ringing` وضعیت تماس را موقتاً `ringing`
     * می‌گذارد به این امید که وب‌هوک `hangup` بعداً آن را با وضعیت نهایی
     * (answered/no_answer/...) جایگزین کند. اگر hangup به هر دلیلی هرگز
     * نرسد (قطعی شبکه، خطای موقت، ری‌استارت سرور و...)، آن تماس برای
     * همیشه با وضعیت «در حال زنگ خوردن» در دیتابیس گیر می‌کند - حتی اگر
     * واقعاً ساعت‌ها/روزها پیش تمام شده باشد.
     */
    private static function ensureStaleRingingColumns()
    {
        if (self::$staleRingingColumnChecked) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$staleRingingColumnChecked = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT status_reconcile_attempts FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `status_reconcile_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'تعداد تلاش برای اصلاح تماس گیرکرده روی ringing',
                    ADD COLUMN `status_reconcile_last_attempt` DATETIME DEFAULT NULL");
                Logger::info('Auto migration: added status_reconcile_* columns to calls table');
                $added[] = 'calls.status_reconcile_attempts(+last_attempt)';
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Call::ensureStaleRingingColumns failed: ' . $e->getMessage());
            self::$staleRingingColumnChecked = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$staleRingingColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    /**
     * تماس‌های گیرکرده روی «در حال زنگ خوردن» - یعنی hangup برایشان هرگز
     * پردازش نشده. فقط آن‌هایی که بیش از ۲۰ دقیقه از started_at آن‌ها
     * گذشته (هیچ تماس واقعی این‌قدر در حالت ringing نمی‌ماند) و کمتر از
     * سقف تلاش هستند.
     *
     * @param int $minutesThreshold
     * @param int $limit
     * @param int $maxAttempts
     * @return array
     */
    public static function findStaleRingingCalls($minutesThreshold = 20, $limit = 30, $maxAttempts = 8)
    {
        self::ensureStaleRingingColumns();
        return Database::getInstance()->fetchAll(
            "SELECT id, cuid, started_at, status_reconcile_attempts
             FROM calls
             WHERE status = 'ringing'
               AND started_at <= (NOW() - INTERVAL ? MINUTE)
               AND status_reconcile_attempts < ?
             ORDER BY started_at ASC
             LIMIT " . (int)$limit,
            [(int)$minutesThreshold, (int)$maxAttempts]
        );
    }

    /**
     * ثبت یک تلاش اصلاح وضعیت (چه موفق چه ناموفق) - برای رعایت سقف تلاش
     *
     * @param int $callId
     */
    /** تلفنچی 404 داده: «رها شد» - شمارنده‌ی تلاش = 99 تا findStaleRingingCalls دیگر انتخابش نکند */
    public static function giveUpStatusReconcile($callId)
    {
        self::ensureStaleRingingColumns();
        Database::getInstance()->execute(
            'UPDATE `calls` SET `status_reconcile_attempts` = 99, `status_reconcile_last_attempt` = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), (int)$callId]
        );
    }

    /** نرمال‌سازی شماره‌ی ایران: فقط رقم، +98/0098/98 → 0. نامعتبر → null */
    public static function normalizeLeadPhone($number)
    {
        $d = preg_replace('/\D+/', '', (string)$number);
        if (strpos($d, '0098') === 0) $d = '0' . substr($d, 4);
        elseif (strlen($d) === 12 && strpos($d, '98') === 0) $d = '0' . substr($d, 2);
        elseif (strlen($d) === 10 && $d[0] !== '0') $d = '0' . $d;
        return preg_match('/^0[1-9]\d{9}$/', $d) ? $d : null;
    }

    public static function markStatusReconcileAttempt($callId)
    {
        self::ensureStaleRingingColumns();
        Database::getInstance()->execute(
            'UPDATE `calls` SET `status_reconcile_attempts` = `status_reconcile_attempts` + 1, `status_reconcile_last_attempt` = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), (int)$callId]
        );
    }

    /**
     * migration خودکار ستون‌های تحلیل هوشمند تماس (طبق مستندات رسمی
     * «تحلیل هوشمند تماس» تلفنچی). نتیجه‌ی کامل به‌صورت JSON خام در
     * ai_analysis ذخیره می‌شود (چون فیلدهایش ممکن است در آینده تغییر کند)
     * و ai_analysis_status/progress جدا نگه داشته می‌شوند تا بدون
     * json_decode هم بشود فیلتر/نمایش سریع انجام داد.
     */
    private static function ensureAiAnalysisColumns()
    {
        if (self::$aiAnalysisColumnChecked) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$aiAnalysisColumnChecked = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT ai_analysis_status FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `ai_analysis_status` VARCHAR(20) DEFAULT NULL COMMENT 'requesting|queued|transcribing|analyzing|reconciling|completed|failed',
                    ADD COLUMN `ai_analysis_progress` TINYINT UNSIGNED DEFAULT NULL COMMENT 'درصد پیشرفت تحلیل (۰-۱۰۰)',
                    ADD COLUMN `ai_analysis` LONGTEXT DEFAULT NULL COMMENT 'پاسخ کامل JSON تحلیل هوشمند (summary/outcome/transcript/...)',
                    ADD COLUMN `ai_analysis_requested_at` DATETIME DEFAULT NULL,
                    ADD COLUMN `ai_analysis_updated_at` DATETIME DEFAULT NULL,
                    ADD INDEX `idx_ai_analysis_status` (`ai_analysis_status`)");
                Logger::info('Auto migration: added ai_analysis_* columns to calls table');
                $added[] = 'calls.ai_analysis_status(+progress,+ai_analysis,+requested_at,+updated_at)';
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Call::ensureAiAnalysisColumns failed: ' . $e->getMessage());
            self::$aiAnalysisColumnChecked = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$aiAnalysisColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    /**
     * ذخیره/به‌روزرسانی نتیجه‌ی تحلیل هوشمند یک تماس (چه هنوز در حال
     * پردازش باشد چه کامل‌شده - هر بار poll هم همین‌جا ذخیره می‌شود تا
     * دفعه‌ی بعد نیازی به زدن دوباره‌ی تلفنچی برای نمایش نباشد)
     *
     * @param int $callId
     * @param array $responseData  (کل بدنه‌ی JSON پاسخ تلفنچی)
     * @param string|null $status
     * @param bool $isNewRequest  (اگر true، ai_analysis_requested_at هم ست می‌شود)
     */
    public static function saveAiAnalysis($callId, array $responseData, $status, $isNewRequest = false)
    {
        self::ensureAiAnalysisColumns();
        $fields = [
            'ai_analysis_status'   => $status,
            'ai_analysis_progress' => isset($responseData['progress']) ? (int)$responseData['progress'] : null,
            'ai_analysis'          => json_encode($responseData, JSON_UNESCAPED_UNICODE),
            'ai_analysis_updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($isNewRequest) {
            $fields['ai_analysis_requested_at'] = date('Y-m-d H:i:s');
        }
        Database::getInstance()->update('calls', $fields, 'id = ?', [(int)$callId]);
    }

    /**
     * دریافت وضعیت/نتیجه‌ی ذخیره‌شده‌ی تحلیل یک تماس (بدون زدن به تلفنچی)
     *
     * @param int $callId
     * @return array|null
     */
    public static function getAiAnalysis($callId)
    {
        self::ensureAiAnalysisColumns();
        $row = Database::getInstance()->fetchOne(
            'SELECT ai_analysis_status, ai_analysis_progress, ai_analysis, ai_analysis_requested_at, ai_analysis_updated_at
             FROM calls WHERE id = ? LIMIT 1',
            [(int)$callId]
        );
        if (!$row) return null;
        $row['ai_analysis'] = $row['ai_analysis'] ? json_decode($row['ai_analysis'], true) : null;
        return $row;
    }

    /**
     * اجرای دستی migration (برای دکمه‌ی «به‌روزرسانی جداول دیتابیس» در تنظیمات)
     */
    /**
     * migration کامل جدول calls - برای دکمه‌ی ادمین «به‌روزرسانی جداول
     * دیتابیس». برخلاف قبل که فقط void برمی‌گرداند، الان دقیقاً می‌گوید
     * چه ستون‌هایی تازه ساخته شدند (added) و اگر جایی خطا داد، پیام دقیق
     * خطا (errors) - تا نتیجه در پنل تنظیمات قابل‌مشاهده و شفاف باشد.
     *
     * @return array {added: string[], errors: string[]}
     */
    /**
     * ستون لیبل عملیاتی پشتیبانی (استعلام قیمت / تبدیل به فروش / ...)
     */
    private static function ensureCallLabelColumn()
    {
        if (self::$callLabelColumnChecked) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$callLabelColumnChecked = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT call_label FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `call_label` VARCHAR(30) NOT NULL DEFAULT 'none'
                    COMMENT 'price_inquiry|monthly_inquiry|unconfirmed|converted_sale|callback|info|complaint|none'
                    AFTER `call_category`");
                $db->execute("ALTER TABLE `calls` ADD INDEX `idx_call_label` (`call_label`)");
                Logger::info('Auto migration: added call_label column to calls table');
                $added[] = 'calls.call_label';
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Call::ensureCallLabelColumn failed: ' . $e->getMessage());
            self::$callLabelColumnChecked = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$callLabelColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    public static function migrate()
    {
        $added = [];
        $errors = [];
        foreach ([self::ensureFileIdColumn(), self::ensureCallLabelColumn(), self::ensureLiveStatusColumn(), self::ensureAiAnalysisColumns(), self::ensureStaleRingingColumns()] as $r) {
            $added = array_merge($added, $r['added']);
            if ($r['error']) $errors[] = $r['error'];
        }
        // ستون پیامک بی‌پاسخ (در SmsService هم auto-migrate می‌شود)
        try {
            SmsService::ensureMissedSmsColumn();
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        return ['added' => $added, 'errors' => $errors];
    }

    private static $summaryChecked = false;

    /** ت۱۱: ستون خلاصه‌ی یک‌جمله‌ای هر تماس (خودکار) */
    public static function ensureSummaryColumn()
    {
        if (self::$summaryChecked) return;
        self::$summaryChecked = true;
        if (Database::schemaDone(__METHOD__)) return;
        $db = Database::getInstance();
        try {
            try { $db->fetchColumn('SELECT summary_line FROM calls LIMIT 1'); }
            catch (\Throwable $e) { $db->execute('ALTER TABLE `calls` ADD COLUMN `summary_line` VARCHAR(255) DEFAULT NULL'); }
            Database::markSchemaDone(__METHOD__);
        } catch (\Throwable $e) { Logger::error('Call::ensureSummaryColumn failed: ' . $e->getMessage()); }
    }

    public static function setSummary($id, $text)
    {
        self::ensureSummaryColumn();
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        Database::getInstance()->execute('UPDATE calls SET summary_line = ? WHERE id = ?', [$text !== '' ? mb_substr($text, 0, 255) : null, (int)$id]);
    }

    public static function summaryOf($id)
    {
        self::ensureSummaryColumn();
        return Database::getInstance()->fetchColumn('SELECT summary_line FROM calls WHERE id = ?', [(int)$id]);
    }

    /** عبارت SQL شماره‌ی خارجی (۱۰ رقم آخر) */
    const NUM_EXPR = "RIGHT(IF(c.type = 'outbound', c.call_dest, c.call_source), 10)";

    public static $types = ['inbound', 'outbound', 'missed', 'internal'];
    public static $statuses = ['answered', 'no_answer', 'busy', 'failed', 'ringing'];

    /**
     * لیست تماس‌ها با فیلتر و صفحه‌بندی (DataTables server-side)
     *
     * @param array $params  {search, type, status, from_date, to_date, exten_number, contact_id, page, limit, sort}
     * @return array  {items, total}
     */
    public static function all(array $params = [])
    {
        self::ensureCallLabelColumn();
        self::ensureSummaryColumn();
        Pipeline::ensureSchema();
        $db = Database::getInstance();
        $where = [];
        $bind = [];

        // جستجو
        if (!empty($params['search'])) {
            $search = '%' . $params['search'] . '%';
            // ⚠️ رفع باگ: exten_name/exten_number بدون c. مبهم بودند چون
            // هم calls و هم staff (که همیشه JOIN می‌شود) ستونی به همین نام
            // دارند - نگاه کنید به توضیح کامل در فیلتر «شماره داخلی» پایین.
            $where[] = '(c.cuid LIKE ? OR c.call_source LIKE ? OR c.call_dest LIKE ? OR c.exten_name LIKE ? OR c.exten_number LIKE ?)';
            array_push($bind, $search, $search, $search, $search, $search);
        }

        // فیلتر نوع
        // ⚠️ رفع باگ: "missed" هیچ‌وقت یک مقدار واقعی ستون type نیست
        // (type فقط inbound/outbound/internal می‌گیرد). «بی‌پاسخ» در واقع
        // یعنی status IN ('no_answer','busy','failed')، مستقل از جهت تماس.
        if (!empty($params['type']) && $params['type'] === 'missed') {
            $where[] = "c.status IN ('no_answer','busy','failed')";
            // تماس بی‌پاسخی که بعداً با همان شماره تماس موفق داشته از لیست حذف می‌شود
            // (include_followed_up=1 برای دیدن همه)
            if (empty($params['include_followed_up'])) {
                $fg = !empty($params['from_date']) ? Jalali::toGregorianString($params['from_date']) : null;
                $tg = !empty($params['to_date']) ? Jalali::toGregorianString($params['to_date']) : null;
                $where[] = self::idInSql(self::openMissedIds($fg ? substr($fg, 0, 10) . ' 00:00:00' : null, $tg ? substr($tg, 0, 10) . ' 23:59:59' : null));
            }
        } elseif (!empty($params['type']) && in_array($params['type'], self::$types, true)) {
            $where[] = 'c.type = ?';
            $bind[] = $params['type'];
        }

        // فیلتر وضعیت
        // ⚠️ 'unresolved' / 'registered' وضعیت واقعی ستون نیستند؛ pseudo-filter:
        // unresolved = بدون مشتری/سرنخ | registered = وصل‌شده به مشتری یا سرنخ
        //
        // ⚠️ رفع باگ: تمام ستون‌های این بخش (status, contact_id, lead_id)
        // بدون c. نوشته شده بودند. چون leads هم ستون status دارد و همیشه
        // LEFT JOIN می‌شود، «status = ?» هم دقیقاً همان خطای Ambiguous
        // Column را می‌داد (فقط هنوز کسی رویش کلیک نکرده بود که دیده شود).
        if (!empty($params['status']) && $params['status'] === 'unresolved') {
            $where[] = 'c.contact_id IS NULL AND c.lead_id IS NULL';
        } elseif (!empty($params['status']) && $params['status'] === 'registered') {
            $where[] = '(c.contact_id IS NOT NULL OR c.lead_id IS NOT NULL)';
        } elseif (!empty($params['status']) && in_array($params['status'], self::$statuses, true)) {
            $where[] = 'c.status = ?';
            $bind[] = $params['status'];
        }

        // فیلتر دسته‌بندی تماس (مهمان/میزبان/جذب نیرو/اسپم/داخلی/طبقه‌بندی‌نشده)
        if (!empty($params['category']) && in_array($params['category'], self::$callCategories, true)) {
            $where[] = 'c.call_category = ?';
            $bind[] = $params['category'];
        }

        // فیلتر لیبل عملیاتی (استعلام قیمت / تبدیل به فروش / ...)
        if (!empty($params['label']) && in_array($params['label'], self::$callLabels, true)) {
            $where[] = 'c.call_label = ?';
            $bind[] = $params['label'];
        }

        // ⚠️ رفع باگ واقعی (گزارش‌شده از production): «Column 'exten_number'
        // in WHERE is ambiguous». چون این کوئری همیشه با
        // `LEFT JOIN staff s ON s.exten_number = c.exten_number` همراه است
        // و هم calls و هم staff ستونی به نام exten_number دارند، هر ارجاع
        // بدون پیشوند جدول (چه اینجا چه در فیلتر visible_extens پایین) در
        // MySQL مبهم است و کل کوئری را با خطای SQL می‌شکند - این دقیقاً
        // چیزی بود که مسیر «پایش زنده‌ی تماس‌ها» (GET /api/calls/live) را
        // برای کاربران محدودشده (v11) کاملاً خراب کرده بود.
        if (!empty($params['exten_number']) && $params['exten_number'] === 'none') {
            $where[] = "(c.exten_number IS NULL OR c.exten_number = '')";
        } elseif (!empty($params['exten_number'])) {
            // ت۱۰: یک اپراتور ممکن است چند داخلی داشته باشد → «۱۰۱,۱۰۲»
            $exs = array_values(array_filter(explode(',', (string)$params['exten_number']), 'strlen'));
            $where[] = 'c.exten_number IN (' . implode(',', array_fill(0, count($exs), '?')) . ')';
            foreach ($exs as $x) $bind[] = $x;
        }

        // فیلتر تاریخ شمسی (از/تا)
        if (!empty($params['from_date'])) {
            $fromGregorian = Jalali::toGregorianString($params['from_date']);
            if ($fromGregorian) {
                $where[] = 'c.started_at >= ?';
                $bind[] = substr($fromGregorian, 0, 10) . ' 00:00:00';
            }
        }
        if (!empty($params['to_date'])) {
            $toGregorian = Jalali::toGregorianString($params['to_date']);
            if ($toGregorian) {
                $where[] = 'c.started_at <= ?';
                $bind[] = substr($toGregorian, 0, 10) . ' 23:59:59';
            }
        }

        // فیلتر contact_id
        if (!empty($params['contact_id'])) {
            $where[] = 'c.contact_id = ?';
            $bind[] = (int)$params['contact_id'];
        }

        // فیلتر فقط تماس‌های با فایل صوتی
        if (!empty($params['has_recording'])) {
            $where[] = 'c.file_record IS NOT NULL AND c.file_record != ""';
        }

        // ⚠️ محدودسازی دید: «هر کاربر فقط تماس‌های خودش را ببیند مگر
        // سرپرست یا مدیر کل باشد» - نگاه کنید به Auth::getVisibleExtens.
        // null یعنی بدون محدودیت؛ آرایه (حتی خالی) یعنی فقط همین‌ها.
        if (array_key_exists('visible_extens', $params) && $params['visible_extens'] !== null) {
            // ⚠️ رفع باگ: تماس‌های بی‌پاسخِ بدون اپراتور (صف) برای همه‌ی اپراتورها دیده شوند
            $ve = $params['visible_extens'] ?: ['__none__'];
            $placeholders = implode(',', array_fill(0, count($ve), '?'));
            $where[] = "(c.exten_number IN ({$placeholders}) OR ((c.exten_number IS NULL OR c.exten_number = '') AND c.status IN ('no_answer','busy','failed','ringing')))";
            foreach ($ve as $ext) $bind[] = $ext;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // ت۱۱: حالت «شماره‌های یکتا» — از هر شماره فقط آخرین تماس (با همین فیلترها)
        $unique = !empty($params['unique']);
        $innerWhere = $whereSql; $innerBind = $bind;
        $uniqueIds = null;

        // مرتب‌سازی
        $sortMap = [
            'date_desc' => 'c.started_at DESC',
            'date_asc'  => 'c.started_at ASC',
            'duration'  => 'c.time_talk DESC',
            'wait'      => 'c.time_wait DESC',
        ];
        $sort = $sortMap[$params['sort'] ?? 'date_desc'] ?? 'c.started_at DESC';

        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(500, max(1, (int)($params['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // ⚠️ باید `FROM calls c` باشد چون شرط‌های WHERE با پیشوند c. نوشته
        // شده‌اند (رفع ambiguous column). قبلاً `FROM calls` بدون alias بود و
        // با هر فیلتر type/status/date کل صفحه با Exception خالی می‌شد.
        if ($unique) {
            $num = self::NUM_EXPR;
            $grp = "IF(CHAR_LENGTH({$num}) = 10, {$num}, CONCAT('id', c.id))";
            $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM (SELECT 1 AS x FROM calls c {$whereSql} GROUP BY {$grp}) t", $bind);
            $mids = $db->fetchAll("SELECT MAX(c.id) AS mid, MAX(c.started_at) AS ms FROM calls c {$whereSql} GROUP BY {$grp}
                                   ORDER BY ms " . (($params['sort'] ?? '') === 'date_asc' ? 'ASC' : 'DESC') . " LIMIT {$offset}, {$limit}", $bind);
            $uniqueIds = array_map('intval', array_column($mids, 'mid'));
            // صفحه‌ی فعلی فقط همین شناسه‌هاست؛ ترتیب همان ترتیب بالا
            $whereSql = 'WHERE c.id IN (' . ($uniqueIds ? implode(',', $uniqueIds) : '0') . ')';
            $bind = [];
            $offset = 0;
        } else {
            $total = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql}", $bind);
        }

        $rows = $db->fetchAll(
            "SELECT c.*,
                    cust.name AS contact_name,
                    l.name AS lead_name,
                    u.full_name AS user_name,
                    s.name AS staff_name, s.id AS staff_id
             FROM calls c
             LEFT JOIN customers cust ON cust.id = c.contact_id
             LEFT JOIN leads l ON l.id = c.lead_id
             LEFT JOIN users u ON u.id = c.user_id
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             {$whereSql}
             ORDER BY " . ($unique ? 'c.started_at DESC, c.id DESC' : $sort) . "
             LIMIT {$offset}, {$limit}",
            $bind
        );

        // نام تعریف‌شده و مرحله‌ی پیگیری هر شماره
        $p10s = array_map(function ($r) { return Pipeline::norm($r['type'] === 'outbound' ? $r['call_dest'] : $r['call_source']); }, $rows);
        $names = Pipeline::namesFor($p10s);
        $info = Pipeline::oldInfo($p10s);
        $cnt = [];
        if ($unique && $p10s) {
            $u = array_values(array_unique(array_filter($p10s)));
            if ($u) {
                $in = implode(',', array_fill(0, count($u), '?'));
                $num = self::NUM_EXPR;
                foreach ($db->fetchAll("SELECT t.num, COUNT(*) AS n FROM (SELECT {$num} AS num FROM calls c {$innerWhere}) t WHERE t.num IN ({$in}) GROUP BY t.num", array_merge($innerBind, $u)) as $x) $cnt[$x['num']] = (int)$x['n'];
            }
        }
        foreach ($rows as &$r) {
            $k = Pipeline::norm($r['type'] === 'outbound' ? $r['call_dest'] : $r['call_source']);
            $n = $names[$k] ?? null;
            $r['number_name'] = $n['name'] ?? null;
            $r['number_stage'] = $n['stage'] ?? null;
            $r['p10'] = $k;
            $r['ref_source'] = $n['ref_source'] ?? null;
            $r['ref_role'] = $n['ref_role'] ?? null;
            $r['is_old'] = $k ? (($info[$k]['calls'] ?? 0) >= 2 || !empty($info[$k]['customer'])) : false;
            $r['calls_in_range'] = $unique ? ($cnt[$k] ?? 1) : null;
        }
        unset($r);

        // تبدیل تاریخ‌ها و محاسبه مدت زمان فارسی
        foreach ($rows as &$r) {
            if (!empty($r['started_at'])) {
                $r['jalali_started_at'] = Jalali::fromGregorian($r['started_at'], 'Y/m/d H:i');
            }
            $r['talk_formatted'] = self::formatDuration($r['time_talk']);
            $r['wait_formatted'] = self::formatDuration($r['time_wait']);
        }

        return ['items' => $rows, 'total' => $total];
    }

    /**
     * یافتن تماس با ID
     */
    public static function find($id)
    {
        self::ensureCallLabelColumn();
        $db = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT c.*,
                    cust.name AS contact_name, cust.mobile AS contact_mobile, cust.email AS contact_email,
                    u.full_name AS user_name,
                    s.id AS staff_id, s.name AS staff_name, s.unit AS staff_unit
             FROM calls c
             LEFT JOIN customers cust ON cust.id = c.contact_id
             LEFT JOIN users u ON u.id = c.user_id
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             WHERE c.id = ? LIMIT 1',
            [(int)$id]
        );
        if (!$row) return null;

        if (!empty($row['started_at'])) {
            $row['jalali_started_at'] = Jalali::fromGregorian($row['started_at'], 'Y/m/d H:i:s');
        }
        $row['talk_formatted'] = self::formatDuration($row['time_talk']);
        $row['wait_formatted'] = self::formatDuration($row['time_wait']);
        return $row;
    }

    /**
     * یافتن تماس با cuid (شناسه یکتای تلفنچی)
     */
    public static function findByCuid($cuid)
    {
        return Database::getInstance()->fetchOne(
            'SELECT * FROM calls WHERE cuid = ? LIMIT 1',
            [$cuid]
        ) ?: null;
    }

    /**
     * ایجاد/به‌روزرسانی تماس (برای CallSyncManager)
     * اگر cuid قبلاً وجود داشت، آپدیت می‌کند؛ در غیر این صورت insert.
     *
     * @param array $data
     * @return array  {id, is_new}
     */
    public static function upsert(array $data)
    {
        self::ensureFileIdColumn();
        $db = Database::getInstance();

        if (empty($data['cuid'])) {
            Response::validationError('cuid الزامی است');
        }

        $filtered = [];
        foreach (self::$fillable as $f) {
            if (array_key_exists($f, $data)) $filtered[$f] = $data[$f];
        }

        // محاسبه jalali_started_at اگر started_at وجود دارد
        if (!empty($filtered['started_at']) && empty($filtered['jalali_started_at'])) {
            $filtered['jalali_started_at'] = Jalali::fromGregorian($filtered['started_at'], 'Y/m/d H:i:s');
        }

        // بررسی وجود قبلی
        $existing = self::findByCuid($filtered['cuid']);

        if ($existing) {
            // به‌روزرسانی
            $db->update('calls', $filtered, 'cuid = ?', [$filtered['cuid']]);
            return ['id' => (int)$existing['id'], 'is_new' => false];
        }

        // ایجاد جدید
        $id = $db->insert('calls', $filtered);
        return ['id' => (int)$id, 'is_new' => true];
    }

    /**
     * به‌روزرسانی یادداشت تماس
     */
    public static function updateNotes($id, $notes)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تماس یافت نشد');
        return Database::getInstance()->update(
            'calls',
            ['notes' => $notes],
            'id = ?',
            [(int)$id]
        );
    }

    /**
     * حذف تماس
     */
    public static function delete($id)
    {
        $existing = self::find($id);
        if (!$existing) Response::notFound('تماس یافت نشد');
        return Database::getInstance()->delete('calls', 'id = ?', [(int)$id]);
    }

    /**
     * تخصیص contact_id بر اساس شماره مبدا
     *
     * @param int    $callId
     * @param string $mobile  شماره تماس گیرنده
     * @return int|null  (ID مشتری یافت شده یا null)
     */
    public static function assignContactByMobile($callId, $mobile)
    {
        if (empty($mobile)) return null;

        $customer = Customer::findByMobile($mobile);
        if (!$customer) return null;

        Database::getInstance()->update(
            'calls',
            ['contact_id' => (int)$customer['id']],
            'id = ?',
            [(int)$callId]
        );

        // به‌روزرسانی last_call_at مشتری
        Customer::updateLastCall($customer['id']);

        return (int)$customer['id'];
    }

    /**
     * تنظیم دستی دسته‌بندی یک تماس (مهمان/میزبان/جذب نیرو/اسپم/داخلی).
     * این تنظیم دستی همیشه اولویت دارد و هیچ منطق خودکاری آن را دوباره
     * عوض نمی‌کند (نگاه کنید به autoClassifyCategory - فقط وقتی دسته‌بندی
     * فعلی هنوز 'unclassified' باشد اجرا می‌شود).
     *
     * @param int    $id
     * @param string $category
     * @return string
     */
    public static function setCategory($id, $category)
    {
        if (!in_array($category, self::$callCategories, true)) {
            Response::validationError('دسته‌بندی نامعتبر است: ' . $category);
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('تماس یافت نشد');

        Database::getInstance()->update('calls', ['call_category' => $category], 'id = ?', [(int)$id]);
        return $category;
    }

    /**
     * تنظیم لیبل عملیاتی تماس (استعلام قیمت، تبدیل به فروش، ...)
     *
     * @param int    $id
     * @param string $label
     * @return string
     */
    public static function setLabel($id, $label)
    {
        self::ensureCallLabelColumn();
        if (!in_array($label, self::$callLabels, true)) {
            Response::validationError('لیبل نامعتبر است: ' . $label);
        }
        $existing = self::find($id);
        if (!$existing) Response::notFound('تماس یافت نشد');

        Database::getInstance()->update('calls', ['call_label' => $label], 'id = ?', [(int)$id]);
        return $label;
    }

    /**
     * تماس‌های بی‌پاسخ اخیر که هنوز «حل‌شده» علامت نخورده‌اند —
     * برای هشدار و تایمر ۲۰ دقیقه‌ای روی داشبورد تماس‌ها.
     *
     * @param int        $withinHours
     * @param array|null $visibleExtens
     * @return array
     */
    /**
     * شرط SQL: تماس بی‌پاسخِ c «پیگیری‌شده» نیست - یعنی بعد از آن هیچ تماس
     * پاسخ‌داده‌شده‌ای (ورودی یا خروجی) با همان شماره‌ی خارجی ثبت نشده است.
     * (شماره‌ها در ورود به‌صورت نرمال ذخیره می‌شوند؛ تطبیق دقیق → استفاده از ایندکس)
     */
    /**
     * شناسه‌ی تماس‌های بی‌پاسخِ «پیگیری‌نشده» در یک بازه: تماس بی‌پاسخی که بعد از
     * آن تماس پاسخ‌داده‌شده‌ای (ورودی یا خروجی) با همان شماره‌ی نرمال‌شده ثبت شده،
     * پیگیری‌شده حساب می‌شود. محاسبه در PHP با دو کوئری ایندکس‌دار (به‌جای
     * زیرکوئری وابسته که روی بازه‌های بزرگ ده‌ها ثانیه طول می‌کشید).
     *
     * @param string|null $fromG  Y-m-d H:i:s میلادی (null = بدون حد)
     * @param string|null $toG
     * @param array $statuses
     * @return int[]
     */
    public static function openMissedIds($fromG = null, $toG = null, array $statuses = ['no_answer', 'busy', 'failed'], $extraWhere = '', array $extraBind = [])
    {
        $db = Database::getInstance();
        $w = []; $b = [];
        if ($fromG) { $w[] = 'started_at >= ?'; $b[] = $fromG; }
        if ($toG)   { $w[] = 'started_at <= ?'; $b[] = $toG; }
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $missed = $db->fetchAll(
            "SELECT id, type, call_source, call_dest, started_at FROM calls c WHERE c.status IN ({$in})"
            . ($w ? ' AND ' . implode(' AND ', $w) : '') . ($extraWhere ? " AND {$extraWhere}" : ''),
            array_merge($statuses, $b, $extraBind)
        );
        if (!$missed) return [];
        $norm = function ($n) { $d = preg_replace('/\D+/', '', (string)$n); return strlen($d) >= 8 ? substr($d, -10) : null; };
        $minStart = min(array_column($missed, 'started_at'));
        $lastAns = [];
        foreach ($db->fetchAll("SELECT call_source, call_dest, started_at FROM calls WHERE status = 'answered' AND started_at > ?", [$minStart]) as $a) {
            foreach ([$a['call_source'], $a['call_dest']] as $n) {
                $k = $norm($n);
                if ($k !== null && (!isset($lastAns[$k]) || $a['started_at'] > $lastAns[$k])) $lastAns[$k] = $a['started_at'];
            }
        }
        $ids = [];
        foreach ($missed as $m) {
            $k = $norm($m['type'] === 'outbound' ? $m['call_dest'] : $m['call_source']);
            if ($k === null || !isset($lastAns[$k]) || $lastAns[$k] <= $m['started_at']) $ids[] = (int)$m['id'];
        }
        return $ids;
    }

    /** شرط SQL «فقط بی‌پاسخ‌های پیگیری‌نشده» بر اساس فهرست شناسه‌ها */
    public static function idInSql(array $ids, $alias = 'c')
    {
        return $ids ? "{$alias}.id IN (" . implode(',', array_map('intval', $ids)) . ')' : '1 = 0';
    }

    public static function getMissedCallAlerts($withinHours = 12, $visibleExtens = null)
    {
        self::ensureLiveStatusColumn();
        self::ensureCallLabelColumn();
        $db = Database::getInstance();
        $hours = max(1, min(72, (int)$withinHours));

        $where = "WHERE c.type = 'inbound'
                  AND c.status IN ('no_answer','busy','failed','ringing')
                  AND c.started_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                  AND (c.live_status IS NULL OR c.live_status != 'resolved')
                  AND " . self::idInSql(self::openMissedIds(date('Y-m-d H:i:s', time() - $hours * 3600), null, ['no_answer','busy','failed','ringing'], "c.type = 'inbound'"));
        $bind = [];

        if ($visibleExtens !== null) {
            if (empty($visibleExtens)) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($visibleExtens), '?'));
            // ⚠️ رفع باگ: تماس‌های بی‌پاسخی که به هیچ داخلی‌ای وصل نشده‌اند (صف) هم باید
            // برای اپراتورها دیده شوند - قبلاً فقط تماس‌های داخلی خودشان را می‌دیدند
            $where .= " AND (c.exten_number IN ({$placeholders}) OR c.exten_number IS NULL OR c.exten_number = '')";
            foreach ($visibleExtens as $ext) $bind[] = $ext;
        }
        $deadline = Pipeline::missedAlertSeconds();

        $rows = $db->fetchAll(
            "SELECT c.id, c.cuid, c.call_source, c.call_dest, c.status, c.started_at,
                    c.jalali_started_at, c.live_status, c.exten_number, c.call_label,
                    s.name AS staff_name, cust.name AS contact_name, l.name AS lead_name,
                    TIMESTAMPDIFF(SECOND, c.started_at, NOW()) AS elapsed_seconds
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             LEFT JOIN customers cust ON cust.id = c.contact_id
             LEFT JOIN leads l ON l.id = c.lead_id
             {$where}
             ORDER BY c.started_at ASC
             LIMIT 300",
            $bind
        );

        foreach ($rows as &$r) {
            $elapsed = (int)($r['elapsed_seconds'] ?? 0);
            $r['elapsed_seconds'] = $elapsed;
            $r['deadline_seconds'] = $deadline;
            $r['remaining_seconds'] = max(0, $deadline - $elapsed);
            $r['is_overdue'] = $elapsed >= $deadline;
        }
        unset($r);
        // هر شماره فقط یک‌بار (قدیمی‌ترین تماس باز) + تعداد دفعات تماس
        $seen = []; $dedup = [];
        foreach ($rows as $r) {
            $k = Pipeline::norm($r['call_source']) ?: ('id' . $r['id']);
            if (isset($seen[$k])) { $dedup[$seen[$k]]['attempts']++; continue; }
            $r['attempts'] = 1; $seen[$k] = count($dedup); $dedup[] = $r;
        }
        $rows = $dedup;
        $names = Pipeline::namesFor(array_map(function ($r) { return Pipeline::norm($r['call_source']); }, $rows));
        foreach ($rows as &$r) {
            $n = $names[Pipeline::norm($r['call_source'])] ?? null;
            $r['number_name'] = $n['name'] ?? null;
            $r['assigned_user_id'] = $n['assigned_user_id'] ?? null;
            $r['display_name'] = ($n['name'] ?? null) ?: ($r['contact_name'] ?: ($r['lead_name'] ?: ($r['call_source'] ?: '-')));
        }
        unset($r);

        return $rows;
    }

    /**
     * تشخیص خودکار دسته‌بندی یک تماس بر اساس نوع و شماره‌ی طرف مقابل.
     *
     * ⚠️ محدودیت شناخته‌شده: فقط می‌تواند «داخلی» (بر اساس نوع تماس) و
     * «میزبان» (تطبیق مستقیم با جدول hosts) را با اطمینان تشخیص بدهد.
     * تشخیص «مهمان» جدا در CallSyncManager انجام می‌شود (وقتی سرنخ واقعاً
     * ساخته/وصل می‌شود). «جذب نیرو» و «اسپم» اصلاً قابل تشخیص خودکار
     * نیستند - همیشه باید دستی توسط اپراتور انتخاب شوند.
     *
     * @param string      $type            inbound|outbound|internal
     * @param string|null $externalNumber  شماره‌ی طرف مقابل (نه داخلی خودمان)
     * @return string یکی از Call::$callCategories
     */
    public static function autoClassifyCategory($type, $externalNumber)
    {
        if ($type === 'internal') return 'internal';
        if (empty($externalNumber)) return 'unclassified';

        try {
            $host = Database::getInstance()->fetchOne(
                'SELECT id FROM hosts WHERE mobile = ? LIMIT 1',
                [$externalNumber]
            );
            if ($host) return 'host';
        } catch (\Throwable $e) {
            Logger::error('autoClassifyCategory host lookup failed: ' . $e->getMessage());
        }

        return 'unclassified';
    }

    /**
     * آمار تماس‌ها
     *
     * @param string $fromDate  (Y-m-d شمسی یا میلادی)
     * @param string $toDate
     * @return array
     */
    public static function getStats($fromDate = null, $toDate = null, $visibleExtens = null)
    {
        $db = Database::getInstance();
        $where = [];
        $bind = [];
        // ت۷: آمار هم مثل جدول، فقط تماس‌های قابل دید کاربر را می‌شمارد (قبلاً کل شرکت را نشان می‌داد)
        if ($visibleExtens !== null) {
            $ve = $visibleExtens ?: ['__none__'];
            $where[] = "(c.exten_number IN (" . implode(',', array_fill(0, count($ve), '?')) . ") OR ((c.exten_number IS NULL OR c.exten_number = '') AND c.status IN ('no_answer','busy','failed','ringing')))";
            foreach ($ve as $x) $bind[] = $x;
        }

        // پیشوند c. برای سازگاری با کوئری JOIN آمار داخلی‌ها
        if ($fromDate) {
            $g = Jalali::toGregorianString($fromDate);
            if ($g) { $where[] = 'c.started_at >= ?'; $bind[] = substr($g, 0, 10) . ' 00:00:00'; }
        }
        if ($toDate) {
            $g = Jalali::toGregorianString($toDate);
            if ($g) { $where[] = 'c.started_at <= ?'; $bind[] = substr($g, 0, 10) . ' 23:59:59'; }
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stats = [];
        $stats['total'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql}", $bind);
        $stats['inbound'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.type = ?", array_merge($bind, ['inbound']));
        $stats['outbound'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.type = ?", array_merge($bind, ['outbound']));
        // ⚠️ رفع باگ: "بی‌پاسخ" بر اساس status محاسبه می‌شود، نه type
        // (type هیچ‌وقت مقدار 'missed' نمی‌گیرد؛ قبلاً همیشه ۰ نشان می‌داد)
        $stats['missed'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.status IN ('no_answer','busy','failed')", $bind);
        try {
            $fg = $fromDate ? Jalali::toGregorianString($fromDate) : null;
            $tg = $toDate ? Jalali::toGregorianString($toDate) : null;
            $stats['missed_open'] = count(self::openMissedIds($fg ? substr($fg, 0, 10) . ' 00:00:00' : null, $tg ? substr($tg, 0, 10) . ' 23:59:59' : null));
        } catch (\Throwable $e) { Logger::error('missed_open stats failed: ' . $e->getMessage()); }
        $stats['answered'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.status = ?", array_merge($bind, ['answered']));
        $stats['no_answer'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.status = ?", array_merge($bind, ['no_answer']));
        $stats['busy'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.status = ?", array_merge($bind, ['busy']));
        $stats['internal'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.type = ?", array_merge($bind, ['internal']));
        $stats['total_talk_time'] = (int)$db->fetchColumn("SELECT COALESCE(SUM(c.time_talk), 0) FROM calls c {$whereSql}", $bind);
        $stats['total_wait_time'] = (int)$db->fetchColumn("SELECT COALESCE(SUM(c.time_wait), 0) FROM calls c {$whereSql}", $bind);
        $stats['avg_talk_time'] = $stats['answered'] > 0 ? (int)($stats['total_talk_time'] / $stats['answered']) : 0;
        $stats['answer_rate'] = $stats['total'] > 0 ? round(($stats['answered'] / $stats['total']) * 100, 1) : 0;
        $stats['with_recording'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.file_record IS NOT NULL AND c.file_record != ''", $bind);
        $stats['unique_callers'] = (int)$db->fetchColumn("SELECT COUNT(DISTINCT c.call_source) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.type = 'inbound'", $bind);
        $stats['unresolved_leads'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.contact_id IS NULL AND c.lead_id IS NULL", $bind);
        $stats['registered'] = (int)$db->fetchColumn("SELECT COUNT(*) FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " (c.contact_id IS NOT NULL OR c.lead_id IS NOT NULL)", $bind);

        // توزیع ساعتی (برای نمودار میزان تماس در طول روز)
        $stats['by_hour'] = $db->fetchAll(
            "SELECT HOUR(c.started_at) AS hour, COUNT(*) AS cnt
             FROM calls c {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.started_at IS NOT NULL
             GROUP BY HOUR(c.started_at) ORDER BY hour",
            $bind
        );

        // توزیع بر اساس داخلی - نام واقعی اپراتور (از جدول staff) به‌جای
        // نام خامی که در تلفنچی ثبت شده (که ممکن است متفاوت/قدیمی باشد)
        $rows = $db->fetchAll(
            "SELECT c.exten_number, c.exten_name, s.name AS staff_name, s.id AS staff_id,
                    COUNT(*) AS cnt, SUM(c.time_talk) AS total_talk
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             {$whereSql} " . ($whereSql ? 'AND' : 'WHERE') . " c.exten_number IS NOT NULL
             GROUP BY c.exten_number, c.exten_name, s.name, s.id
             ORDER BY cnt DESC
             LIMIT 10",
            $bind
        );
        $stats['by_extension'] = $rows;

        return $stats;
    }

    /**
     * گزارش عملکرد اپراتورها با بازه‌بندی ساعتی / روزانه / هفتگی
     *
     * @param string      $period   hourly|daily|weekly
     * @param string|null $fromDate شمسی Y/m/d
     * @param string|null $toDate   شمسی Y/m/d
     * @param string|null $exten    فیلتر یک داخلی خاص
     * @return array{period:string,from:string|null,to:string|null,operators:array,buckets:array}
     */
    public static function getOperatorMetrics($period = 'daily', $fromDate = null, $toDate = null, $exten = null)
    {
        $period = in_array($period, ['hourly', 'daily', 'weekly'], true) ? $period : 'daily';
        $db = Database::getInstance();
        $where = ['c.exten_number IS NOT NULL', "c.exten_number != ''"];
        $bind = [];

        if ($fromDate) {
            $g = Jalali::toGregorianString($fromDate);
            if ($g) { $where[] = 'c.started_at >= ?'; $bind[] = substr($g, 0, 10) . ' 00:00:00'; }
        } else {
            // پیش‌فرض: ۷ روز اخیر برای daily/weekly، امروز برای hourly
            if ($period === 'hourly') {
                $where[] = 'c.started_at >= CURDATE()';
            } else {
                $where[] = 'c.started_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
            }
        }
        if ($toDate) {
            $g = Jalali::toGregorianString($toDate);
            if ($g) { $where[] = 'c.started_at <= ?'; $bind[] = substr($g, 0, 10) . ' 23:59:59'; }
        }
        if ($exten !== null && $exten !== '') {
            $where[] = 'c.exten_number = ?';
            $bind[] = $exten;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        if ($period === 'hourly') {
            $bucketExpr = "LPAD(HOUR(c.started_at), 2, '0')";
            $bucketLabel = 'ساعت';
        } elseif ($period === 'weekly') {
            $bucketExpr = "YEARWEEK(c.started_at, 1)";
            $bucketLabel = 'هفته';
        } else {
            $bucketExpr = "DATE(c.started_at)";
            $bucketLabel = 'روز';
        }

        $rows = $db->fetchAll(
            "SELECT c.exten_number,
                    COALESCE(s.name, c.exten_name, c.exten_number) AS operator_name,
                    s.id AS staff_id,
                    {$bucketExpr} AS bucket,
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.status = 'answered' THEN 1 ELSE 0 END) AS answered,
                    SUM(CASE WHEN c.status IN ('no_answer','busy','failed') THEN 1 ELSE 0 END) AS missed,
                    SUM(CASE WHEN c.type = 'inbound' THEN 1 ELSE 0 END) AS inbound,
                    SUM(CASE WHEN c.type = 'outbound' THEN 1 ELSE 0 END) AS outbound,
                    COALESCE(SUM(c.time_talk), 0) AS talk_seconds,
                    COALESCE(SUM(c.time_wait), 0) AS wait_seconds,
                    COALESCE(AVG(CASE WHEN c.status = 'answered' THEN c.time_talk END), 0) AS avg_talk
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             {$whereSql}
             GROUP BY c.exten_number, operator_name, s.id, bucket
             ORDER BY bucket ASC, total DESC",
            $bind
        );

        $operators = [];
        $bucketsSet = [];
        foreach ($rows as $r) {
            $ext = $r['exten_number'];
            $bucket = (string)$r['bucket'];
            $bucketsSet[$bucket] = true;
            if (!isset($operators[$ext])) {
                $operators[$ext] = [
                    'exten_number'  => $ext,
                    'operator_name' => $r['operator_name'],
                    'staff_id'      => $r['staff_id'] ? (int)$r['staff_id'] : null,
                    'total'         => 0,
                    'answered'      => 0,
                    'missed'        => 0,
                    'inbound'       => 0,
                    'outbound'      => 0,
                    'talk_seconds'  => 0,
                    'wait_seconds'  => 0,
                    'answer_rate'   => 0,
                    'buckets'       => [],
                ];
            }
            $item = [
                'bucket'       => $bucket,
                'total'        => (int)$r['total'],
                'answered'     => (int)$r['answered'],
                'missed'       => (int)$r['missed'],
                'inbound'      => (int)$r['inbound'],
                'outbound'     => (int)$r['outbound'],
                'talk_seconds' => (int)$r['talk_seconds'],
                'wait_seconds' => (int)$r['wait_seconds'],
                'avg_talk'     => (int)round((float)$r['avg_talk']),
                'answer_rate'  => (int)$r['total'] > 0
                    ? round(((int)$r['answered'] / (int)$r['total']) * 100, 1)
                    : 0,
            ];
            // برچسب فارسی سطل
            if ($period === 'hourly') {
                $item['bucket_label'] = Helpers::toPersianDigits($bucket) . ':۰۰';
            } elseif ($period === 'daily') {
                $item['bucket_label'] = Jalali::fromGregorian($bucket . ' 00:00:00', 'Y/m/d');
            } else {
                $item['bucket_label'] = 'هفته ' . Helpers::toPersianDigits($bucket);
            }
            $operators[$ext]['buckets'][] = $item;
            $operators[$ext]['total'] += $item['total'];
            $operators[$ext]['answered'] += $item['answered'];
            $operators[$ext]['missed'] += $item['missed'];
            $operators[$ext]['inbound'] += $item['inbound'];
            $operators[$ext]['outbound'] += $item['outbound'];
            $operators[$ext]['talk_seconds'] += $item['talk_seconds'];
            $operators[$ext]['wait_seconds'] += $item['wait_seconds'];
        }

        foreach ($operators as &$op) {
            $op['answer_rate'] = $op['total'] > 0
                ? round(($op['answered'] / $op['total']) * 100, 1)
                : 0;
            $op['talk_formatted'] = self::formatDuration($op['talk_seconds']);
        }
        unset($op);

        // مرتب‌سازی اپراتورها بر اساس تعداد تماس
        $opsList = array_values($operators);
        usort($opsList, function ($a, $b) { return $b['total'] <=> $a['total']; });

        $buckets = array_keys($bucketsSet);
        sort($buckets);

        return [
            'period'       => $period,
            'period_label' => $bucketLabel,
            'from'         => $fromDate,
            'to'           => $toDate,
            'operators'    => $opsList,
            'buckets'      => $buckets,
        ];
    }

    /**
     * فرمت‌بندی مدت زمان (ثانیه → MM:SS یا HH:MM:SS)
     */
    public static function formatDuration($seconds)
    {
        $seconds = (int)$seconds;
        if ($seconds < 60) return '00:' . str_pad($seconds, 2, '0', STR_PAD_LEFT);
        if ($seconds < 3600) {
            $m = (int)($seconds / 60);
            $s = $seconds % 60;
            return str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
        }
        $h = (int)($seconds / 3600);
        $m = (int)(($seconds % 3600) / 60);
        $s = $seconds % 60;
        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
    }

    /**
     * دریافت آخرین تماس برای یک شماره موبایل
     */
    public static function getLastCallByMobile($mobile)
    {
        return Database::getInstance()->fetchOne(
            'SELECT * FROM calls WHERE call_source = ? OR call_dest = ? ORDER BY started_at DESC LIMIT 1',
            [$mobile, $mobile]
        ) ?: null;
    }

    /**
     * لیست شماره‌های داخلی موجود (برای فیلتر)
     */
    public static function getExtensions()
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT DISTINCT exten_number, exten_name
             FROM calls
             WHERE exten_number IS NOT NULL AND exten_number != ''
             ORDER BY exten_number"
        );
        return $rows;
    }

    /**
     * ⚠️ فیچر جدید: «خط‌روند هر تماس» - به‌جای نمایش صدها ردیف تکراری
     * برای یک شماره (که چند بار در روز تماس گرفته)، فقط شماره‌های یکتای
     * امروز را برمی‌گرداند (حداکثر ۱۰۰ تا، بر اساس آخرین تماس هر شماره)
     * - دقیقاً مثل یک CRM واقعی. کلیک روی هرکدام باید به
     * Call::getHistoryByNumber() برود که کل تاریخچه (نه فقط امروز) را
     * نشان می‌دهد.
     *
     * از همان الگوی گروه‌بندی بر اساس شماره‌ی خارجی استفاده می‌کند که
     * قبلاً در autoGenerateLeadsFromRecentCalls() جا افتاده بود (ورودی →
     * call_source، خروجی → call_dest، نرمال‌سازی با ۱۰ رقم آخر).
     *
     * @param int $limit
     * @return array
     */
    public static function getTodayUniqueNumbers($limit = 100, $visibleExtens = null, $direction = '')
    {
        self::ensureFileIdColumn();
        $db = Database::getInstance();

        $where = "WHERE c.started_at >= CURDATE() AND c.type IN ('inbound','outbound')";
        $bind = [];
        if ($visibleExtens !== null) {
            $ve = $visibleExtens ?: ['__none__'];
            $placeholders = implode(',', array_fill(0, count($ve), '?'));
            $where .= " AND (c.exten_number IN ({$placeholders}) OR c.exten_number IS NULL OR c.exten_number = '')";
            foreach ($ve as $ext) $bind[] = $ext;
        }

        $rows = $db->fetchAll(
            "SELECT c.id, c.type, c.status, c.call_source, c.call_dest, c.time_talk,
                    c.started_at, c.jalali_started_at, c.contact_id, c.lead_id,
                    s.name AS staff_name, cust.name AS contact_name, l.name AS lead_name
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             LEFT JOIN customers cust ON cust.id = c.contact_id
             LEFT JOIN leads l ON l.id = c.lead_id
             {$where}
             ORDER BY c.started_at DESC",
            $bind
        );

        $byNumber = [];
        foreach ($rows as $r) {
            $external = ($r['type'] === 'outbound') ? $r['call_dest'] : $r['call_source'];
            $last10 = substr(preg_replace('/\D+/', '', (string)$external), -10);
            if (strlen($last10) < 10) continue;
            if (!isset($byNumber[$last10])) {
                $byNumber[$last10] = [
                    'number' => $external, 'phone10' => $last10,
                    'name' => $r['contact_name'] ?: $r['lead_name'],
                    'contact_id' => $r['contact_id'], 'lead_id' => $r['lead_id'],
                    'calls_today' => 0, 'answered_today' => 0,
                    'inbound_today' => 0, 'outbound_today' => 0,
                    'inbound_answered' => 0, 'outbound_answered' => 0,
                    'last_call' => $r,
                ];
            }
            $b = &$byNumber[$last10];
            $b['calls_today']++;
            $b[$r['type'] . '_today']++;
            if ($r['status'] === 'answered') { $b['answered_today']++; $b[$r['type'] . '_answered']++; }
            if (empty($b['name']) && ($r['contact_name'] || $r['lead_name'])) $b['name'] = $r['contact_name'] ?: $r['lead_name'];
            unset($b);
        }
        $names = Pipeline::namesFor(array_keys($byNumber));
        foreach ($byNumber as $k => &$b) {
            if (!empty($names[$k]['name'])) $b['name'] = $names[$k]['name'];
            $b['stage'] = $names[$k]['stage'] ?? null;
            $b['stage_label'] = $b['stage'] ? (Pipeline::$stages[$b['stage']] ?? $b['stage']) : null;
        }
        unset($b);
        $result = array_values($byNumber);
        if ($direction === 'inbound' || $direction === 'outbound') {
            $result = array_values(array_filter($result, function ($r) use ($direction) { return $r[$direction . '_today'] > 0; }));
        }
        return array_slice($result, 0, $limit);
    }

    /**
     * تمام تاریخچه‌ی تماس با یک شماره‌ی مشخص (نه فقط امروز) - برای صفحه‌ی
     * «جزئیات شماره» که از Call::getTodayUniqueNumbers() باز می‌شود.
     * همان منطق نرمال‌سازی Customer::getCalls (۱۰ رقم آخر).
     *
     * @param string $number
     * @param int $limit
     * @return array {calls: array, name: string|null, contact_id: int|null, lead_id: int|null, stats: array}
     */
    public static function getHistoryByNumber($number, $limit = 200, $visibleExtens = null)
    {
        self::ensureFileIdColumn();
        self::ensureSummaryColumn();
        $last10 = substr(preg_replace('/\D+/', '', (string)$number), -10);
        if (strlen($last10) < 10) {
            return ['calls' => [], 'name' => null, 'contact_id' => null, 'lead_id' => null, 'stats' => []];
        }

        $db = Database::getInstance();
        $where = 'WHERE (RIGHT(c.call_source, 10) = ? OR RIGHT(c.call_dest, 10) = ?)';
        $bind = [$last10, $last10];
        // ⚠️ همان محدودیت دید v11 - کاربر محدودشده فقط تماس‌های این شماره
        // را که خودش/زیرمجموعه‌اش پاسخ داده دیده، نه کل تاریخچه‌ی شرکت
        if ($visibleExtens !== null) {
            $ve = $visibleExtens ?: ['__none__'];
            $placeholders = implode(',', array_fill(0, count($ve), '?'));
            $where .= " AND (c.exten_number IN ({$placeholders}) OR c.exten_number IS NULL OR c.exten_number = '')";
            foreach ($ve as $ext) $bind[] = $ext;
        }

        $calls = $db->fetchAll(
            "SELECT c.*, s.name AS staff_name, cust.name AS contact_name, l.name AS lead_name
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             LEFT JOIN customers cust ON cust.id = c.contact_id
             LEFT JOIN leads l ON l.id = c.lead_id
             {$where}
             ORDER BY c.started_at DESC
             LIMIT " . (int)$limit,
            $bind
        );

        foreach ($calls as &$cc) {
            if (!empty($cc['started_at']) && empty($cc['jalali_started_at'])) $cc['jalali_started_at'] = Jalali::fromGregorian($cc['started_at'], 'Y/m/d H:i');
            if (!isset($cc['talk_formatted'])) $cc['talk_formatted'] = self::formatDuration($cc['time_talk'] ?? 0);
        }
        unset($cc);
        $name = null; $contactId = null; $leadId = null;
        foreach ($calls as $c) {
            if (!empty($c['contact_name'])) { $name = $c['contact_name']; $contactId = $c['contact_id']; break; }
            if (!empty($c['lead_name']) && !$name) { $name = $c['lead_name']; $leadId = $c['lead_id']; }
        }

        $answeredCount = count(array_filter($calls, fn($c) => $c['status'] === 'answered'));
        $stats = [
            'total_calls'    => count($calls),
            'answered_calls' => $answeredCount,
            'first_call_at'  => $calls ? end($calls)['jalali_started_at'] : null,
            'last_call_at'   => $calls ? $calls[0]['jalali_started_at'] : null,
        ];

        return ['calls' => $calls, 'name' => $name, 'contact_id' => $contactId, 'lead_id' => $leadId, 'stats' => $stats];
    }

    /**
     * ⚠️ نسخه‌ی کامل‌تر برای صفحه‌ی «جزئیات شماره» - علاوه بر تاریخچه‌ی
     * تماس (که getHistoryByNumber می‌دهد)، این‌ها را هم اضافه می‌کند:
     *   - پروفایل کامل مشتری (اگر این شماره متعلق به یک مشتری باشد)
     *   - تمام سرنخ‌های مرتبط با این شماره (نه فقط آخرین)
     *   - تیکت‌های پشتیبانی مرتبط (از طریق customer_id یافت‌شده)
     *   - آمار خلاصه (مجموع/میانگین مدت مکالمه، نرخ پاسخ‌گویی)
     *
     * @param string $number
     * @param array|null $visibleExtens
     * @return array
     */
    public static function getFullNumberProfile($number, $visibleExtens = null)
    {
        $history = self::getHistoryByNumber($number, 200, $visibleExtens);
        $last10 = substr(preg_replace('/\D+/', '', (string)$number), -10);
        $db = Database::getInstance();

        // پروفایل کامل مشتری (اگر متصل است)
        $customer = null;
        if ($last10 && strlen($last10) === 10) {
            $customer = $db->fetchOne(
                "SELECT * FROM customers WHERE RIGHT(mobile, 10) = ? OR RIGHT(phone, 10) = ? LIMIT 1",
                [$last10, $last10]
            );
        }

        // تمام سرنخ‌های مرتبط با این شماره (نه فقط آخرین)
        $leads = [];
        if ($last10 && strlen($last10) === 10) {
            $leads = $db->fetchAll(
                "SELECT id, name, stage, assignee, source, value, note, next_contact, created_at
                 FROM leads WHERE RIGHT(mobile, 10) = ? ORDER BY created_at DESC",
                [$last10]
            );
        }

        // تیکت‌های پشتیبانی مرتبط (از طریق مشتری یافت‌شده)
        $tickets = [];
        if ($customer) {
            $tickets = $db->fetchAll(
                "SELECT id, title, type, priority, status, dept, assignee, date, last_reply
                 FROM tickets WHERE customer_id = ? ORDER BY created_at DESC",
                [$customer['id']]
            );
        }

        // آمار خلاصه: مجموع/میانگین مدت مکالمه، نرخ پاسخ‌گویی، میانگین زمان انتظار
        $calls = $history['calls'];
        $totalTalk = array_sum(array_column($calls, 'time_talk'));
        $answeredCalls = array_filter($calls, fn($c) => $c['status'] === 'answered');
        $waitTimes = array_filter(array_column($calls, 'time_wait'), fn($w) => $w !== null);

        $history['stats']['total_talk_seconds'] = $totalTalk;
        $history['stats']['avg_talk_seconds'] = count($answeredCalls) ? (int)round($totalTalk / count($answeredCalls)) : 0;
        $history['stats']['answer_rate'] = count($calls) ? round(count($answeredCalls) / count($calls) * 100) : 0;
        $history['stats']['avg_wait_seconds'] = count($waitTimes) ? (int)round(array_sum($waitTimes) / count($waitTimes)) : 0;

        $history['customer'] = $customer;
        $history['leads'] = $leads;
        $history['tickets'] = $tickets;

        return $history;
    }

    /**
     * تبدیل یک تماس به سرنخ (لید) جدید
     * شماره‌ی مشتری از روی نوع تماس تعیین می‌شود:
     *   - تماس ورودی (inbound): شماره‌ی تماس‌گیرنده = call_source
     *   - تماس خروجی (outbound): شماره‌ای که با آن تماس گرفته شده = call_dest
     *
     * اگر این تماس قبلاً تبدیل شده باشد (calls.lead_id پر است)، به‌جای
     * ساخت سرنخ تکراری، همان سرنخ قبلی برگردانده می‌شود.
     *
     * @param int         $callId
     * @param string|null $name    نام مشتری (اگر ندهید، از روی شماره ساخته می‌شود)
     * @return array {lead_id, already_existed}
     */
    public static function convertToLead($callId, $name = null)
    {
        self::ensureFileIdColumn();
        $call = self::find($callId);
        if (!$call) Response::notFound('تماس یافت نشد');

        if (!empty($call['lead_id'])) {
            return ['lead_id' => (int)$call['lead_id'], 'already_existed' => true];
        }

        $mobile = ($call['type'] === 'outbound') ? $call['call_dest'] : $call['call_source'];
        if (empty($mobile)) {
            Response::validationError('این تماس شماره‌ی معتبری برای ساخت سرنخ ندارد');
        }

        $leadName = $name ?: ('تماس ' . ($call['jalali_started_at'] ?? '') . ' - ' . $mobile);

        $note = sprintf(
            "ایجاد شده خودکار از تماس #%d (%s)\nنوع: %s | وضعیت: %s | مدت مکالمه: %s\nتاریخ تماس: %s",
            $call['id'],
            $call['cuid'],
            $call['type'] === 'inbound' ? 'ورودی' : ($call['type'] === 'outbound' ? 'خروجی' : $call['type']),
            $call['status'],
            $call['talk_formatted'] ?? self::formatDuration($call['time_talk']),
            $call['jalali_started_at'] ?? '-'
        );

        $leadId = Lead::create([
            'name'      => $leadName,
            'mobile'    => $mobile,
            'source'    => 'تلفنچی',
            'reference' => 'تماس تلفنی',
            'note'      => $note,
            // ⚠️ رفع باگ: اپراتور/پرسنلِ پاسخ‌گو باید خودکار از داخلی تماس
            // تشخیص داده شود (Call::find() این را با join روی staff.exten_number
            // برمی‌گرداند - نگاه کنید به staff_name)
            'operator'  => $call['staff_name'] ?? null,
        ]);

        Database::getInstance()->update('calls', ['lead_id' => (int)$leadId], 'id = ?', [(int)$callId]);

        return ['lead_id' => (int)$leadId, 'already_existed' => false];
    }

    /**
     * تبدیل خودکار و دسته‌ای تماس‌ها به سرنخ (طبق درخواست: اگر امروز
     * ۳۰۰ تماس از ۷۰ شماره‌ی یکتا بوده، همان ۷۰ شماره باید ۷۰ سرنخ بشوند،
     * نه ۳۰۰ سرنخ تکراری).
     *
     * منطق:
     *   - فقط تماس‌هایی که به مشتری شناخته‌شده (contact_id) وصل نیستند و
     *     قبلاً به سرنخ تبدیل نشده‌اند (lead_id خالی) در نظر گرفته می‌شوند
     *   - شماره‌ی خارجی هر تماس بر اساس نوعش تعیین می‌شود (ورودی → مبدا،
     *     خروجی → مقصد)
     *   - شماره‌های کوتاه (زیر ۸ رقم) به‌عنوان داخلی سازمانی رد می‌شوند
     *   - تماس‌های هم‌شماره گروه‌بندی می‌شوند و فقط یک سرنخ برای کل گروه
     *     ساخته می‌شود؛ همه‌ی آن تماس‌ها به همان سرنخ لینک می‌شوند
     *
     * این متد هم به‌صورت خودکار بعد از هر sync موفق فراخوانی می‌شود
     * (نگاه کنید به CallSyncManager) و هم می‌تواند دستی از دکمه‌ی
     * «تبدیل خودکار تماس‌های بدون سرنخ» اجرا شود.
     *
     * @param string|null $sinceDateGregorian  Y-m-d - اگر ندهید، کل جدول را بررسی می‌کند
     * @return array {created_leads, linked_calls, unique_numbers_seen}
     */
    public static function autoGenerateLeadsFromRecentCalls($sinceDateGregorian = null)
    {
        self::ensureFileIdColumn();
        $db = Database::getInstance();

        $where = "WHERE c.contact_id IS NULL AND c.lead_id IS NULL AND c.type IN ('inbound','outbound')";
        $bind = [];
        if (!empty($sinceDateGregorian)) {
            $where .= " AND c.started_at >= ?";
            $bind[] = $sinceDateGregorian . ' 00:00:00';
        }

        $rows = $db->fetchAll(
            "SELECT c.id, c.type, c.call_source, c.call_dest, c.status, c.time_talk,
                    c.started_at, c.jalali_started_at, c.exten_number, s.name AS staff_name
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             {$where}",
            $bind
        );

        // گروه‌بندی بر اساس شماره‌ی خارجیِ نرمال‌شده
        $byNumber = [];
        $invalidNumbers = [];
        foreach ($rows as $r) {
            $external = ($r['type'] === 'outbound') ? $r['call_dest'] : $r['call_source'];
            $external = trim((string)$external);
            $digits = preg_replace('/\D+/', '', $external);
            if (strlen($digits) < 8) continue; // احتمالاً داخلی سازمانی است، نه شماره‌ی واقعی مشتری
            $normalized = self::normalizeLeadPhone($digits);
            if ($normalized === null) { $invalidNumbers[$digits] = true; continue; } // شماره‌ی نامعتبر (مثلاً ۲۳ رقمی) → بدون سرنخ
            $byNumber[$normalized][] = $r;
        }

        if ($invalidNumbers) {
            Logger::info('Auto lead generation: skipped invalid numbers', ['count' => count($invalidNumbers), 'sample' => array_slice(array_keys($invalidNumbers), 0, 3)]);
        }
        $createdLeads = 0;
        $linkedCalls = 0;

        foreach ($byNumber as $number => $calls) {
            usort($calls, function ($a, $b) {
                return strtotime($b['started_at'] ?? '1970-01-01') <=> strtotime($a['started_at'] ?? '1970-01-01');
            });
            $latest = $calls[0];
            $answeredCount = count(array_filter($calls, fn($c) => $c['status'] === 'answered'));
            // اپراتوری که آخرین بار با این شماره صحبت کرده، به‌عنوان اپراتور
            // پیش‌فرض سرنخ در نظر گرفته می‌شود (اگر داخلی به پرسنلی وصل باشد)
            $operatorName = $latest['staff_name'] ?? null;

            $note = sprintf(
                "ایجاد خودکار از %d تماس با این شماره (%d تا پاسخ داده شده) | آخرین تماس: %s",
                count($calls), $answeredCount, $latest['jalali_started_at'] ?? '-'
            );

            try {
                $leadId = Lead::create([
                    'name'      => 'تماس - ' . $number,
                    'mobile'    => $number,
                    'source'    => 'تلفنچی',
                    'reference' => 'تماس تلفنی (خودکار)',
                    'note'      => $note,
                    'operator'  => $operatorName,
                ]);
            } catch (\Throwable $e) {
                Logger::error('Auto lead generation failed for number ' . $number . ': ' . $e->getMessage());
                continue;
            }

            $ids = array_column($calls, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->execute("UPDATE calls SET lead_id = ? WHERE id IN ({$placeholders})", array_merge([(int)$leadId], $ids));

            $createdLeads++;
            $linkedCalls += count($ids);
        }

        if ($createdLeads > 0) {
            Logger::info('Auto lead generation completed', ['created_leads' => $createdLeads, 'linked_calls' => $linkedCalls]);
        }

        return ['created_leads' => $createdLeads, 'linked_calls' => $linkedCalls, 'unique_numbers_seen' => count($byNumber)];
    }

    /**
     * تضمین وجود سرنخ برای یک شماره‌ی ناشناس (اگر قبلاً سرنخی با همین
     * شماره هست، همان استفاده می‌شود؛ در غیر این‌صورت ساخته می‌شود).
     *
     * ⚠️ نکته‌ی معماری (بعد از رفع باگ): این متد از **سه** جای مختلف صدا
     * زده می‌شود، نه فقط لحظه‌ی زنگ خوردن:
     *   ۱) `CallSyncManager::ingestRingingEvent()` - لحظه‌ی زنگ خوردن
     *      (سریع‌ترین حالت، ولی تضمینی برای رسیدن این رویداد نیست)
     *   ۲) `CallSyncManager::processCall()` - از هم `ingestHangupEvent`
     *      و هم `runSync` صدا زده می‌شود؛ این لایه‌ی دوم/اصلی است چون
     *      hangup برخلاف ringing تقریباً همیشه می‌رسد
     *   ۳) به‌صورت دستی از دکمه‌ی «تبدیل به سرنخ» در جزئیات تماس
     * صدا زدن تکراری‌اش برای یک تماس مشکلی ایجاد نمی‌کند چون اول
     * سرنخ موجود با همان شماره را پیدا می‌کند.
     *
     * ⚠️ طبق تصمیم صریح کارفرما، از این نسخه تماس‌های خروجیِ ناشناس هم
     * سرنخ می‌گیرند (قبلاً فقط ورودی). پارامتر $type برای این است که
     * برچسب/یادداشتِ سرنخ ساخته‌شده دقیق باشد (نه همیشه «تماس ورودی»،
     * حتی وقتی واقعاً ما زنگ زده‌ایم).
     *
     * @param int    $callId
     * @param string $mobile
     * @param string $type    inbound|outbound (فقط برای برچسب‌گذاری دقیق)
     * @return int|null شناسه‌ی سرنخ ساخته/پیداشده
     */
    public static function ensureLeadForRingingCall($callId, $mobile, $type = 'inbound')
    {
        $digits = preg_replace('/\D+/', '', (string)$mobile);
        if (strlen($digits) < 8) return null; // شماره‌ی داخلی/نامعتبر
        $normalized = self::normalizeLeadPhone($digits);
        if ($normalized === null) {
            Logger::info('Lead skipped: invalid phone number', ['call_id' => (int)$callId, 'number' => substr($digits, 0, 30)]);
            return null;
        }
        $mobile = $digits = $normalized;

        // اگر قبلاً سرنخی با همین شماره (بدون توجه به پیشوند) هست، همان را استفاده کن
        $last10 = substr($digits, -10);
        $existingLead = Database::getInstance()->fetchOne(
            "SELECT id FROM leads WHERE RIGHT(REPLACE(mobile,' ',''), 10) = ? ORDER BY id DESC LIMIT 1",
            [$last10]
        );

        if ($existingLead) {
            $leadId = (int)$existingLead['id'];
        } else {
            $isOutbound = ($type === 'outbound');
            $leadId = Lead::create([
                'name'      => ($isOutbound ? 'تماس خروجی - ' : 'تماس ورودی - ') . $mobile,
                'mobile'    => $mobile,
                'source'    => 'تلفنچی',
                'reference' => $isOutbound ? 'تماس تلفنی خروجی (خودکار)' : 'تماس تلفنی (لحظه‌ی زنگ خوردن)',
                'note'      => $isOutbound
                    ? 'این سرنخ خودکار ساخته شده چون اپراتور با شماره‌ای تماس گرفته که در سیستم شناخته‌شده نبود.'
                    : 'این سرنخ خودکار و بلافاصله در لحظه‌ی دریافت تماس ساخته شده (قبل از پاسخ‌گویی).',
            ]);
        }

        Database::getInstance()->update('calls', ['lead_id' => $leadId], 'id = ?', [(int)$callId]);
        return $leadId;
    }

    /**
     * به‌روزرسانی وضعیت زنده‌ی یک تماس توسط تیم CRM (مستقل از وضعیت
     * واقعی تلفنچی) - برای هماهنگی تیم روی این‌که چه کسی دارد به این
     * تماس/سرنخ رسیدگی می‌کند
     *
     * @param int    $callId
     * @param string $liveStatus
     * @param string|null $note
     * @return bool
     */
    public static function updateLiveStatus($callId, $liveStatus, $note = null)
    {
        self::ensureLiveStatusColumn();
        $validStatuses = ['new', 'being_handled', 'needs_callback', 'resolved'];
        if (!in_array($liveStatus, $validStatuses, true)) {
            Response::validationError('وضعیت نامعتبر است');
        }

        $currentUser = Auth::currentUser();
        $data = [
            'live_status'         => $liveStatus,
            'live_status_by'      => $currentUser['full_name'] ?? null,
            'live_status_updated' => date('Y-m-d H:i:s'),
        ];
        if ($note !== null) $data['live_status_note'] = $note;

        return Database::getInstance()->update('calls', $data, 'id = ?', [(int)$callId]);
    }

    /** @var bool کش وجود ستون‌های وضعیت زنده */
    private static $liveStatusColumnChecked = false;

    /**
     * افزودن ستون‌های پایش زنده (live_status, live_status_by, ...)
     */
    private static function ensureLiveStatusColumn()
    {
        if (self::$liveStatusColumnChecked) return ['added' => [], 'error' => null];
        if (Database::schemaDone(__METHOD__)) { self::$liveStatusColumnChecked = true; return ['added' => [], 'error' => null]; }
        $added = [];
        try {
            $db = Database::getInstance();
            try {
                $db->fetchColumn("SELECT live_status FROM calls LIMIT 1");
            } catch (Exception $e) {
                $db->execute("ALTER TABLE `calls`
                    ADD COLUMN `live_status` VARCHAR(20) DEFAULT 'new' COMMENT 'new|being_handled|needs_callback|resolved',
                    ADD COLUMN `live_status_by` VARCHAR(120) DEFAULT NULL,
                    ADD COLUMN `live_status_note` VARCHAR(255) DEFAULT NULL,
                    ADD COLUMN `live_status_updated` DATETIME DEFAULT NULL");
                Logger::info('Auto migration: added live_status columns to calls table');
                $added[] = 'calls.live_status(+by,+note,+updated)';
            }
        } catch (Exception $e) {
            $__schemaFailed = true; Logger::error('Call::ensureLiveStatusColumn failed: ' . $e->getMessage());
            self::$liveStatusColumnChecked = true;
            return ['added' => $added, 'error' => $e->getMessage()];
        }
        self::$liveStatusColumnChecked = true;
        if (empty($__schemaFailed)) Database::markSchemaDone(__METHOD__);
        return ['added' => $added, 'error' => null];
    }

    /**
     * لیست تماس‌های زنده/اخیر برای «پایش زنده» (تماس‌های در حال زنگ خوردن
     * یا تمام‌شده در ۳۰ دقیقه‌ی اخیر)
     *
     * @return array
     */
    public static function getLiveCalls()
    {
        self::ensureLiveStatusColumn();
        return Database::getInstance()->fetchAll(
            "SELECT c.*, s.name AS staff_name, l.id AS existing_lead_id, l.name AS lead_name, cust.name AS customer_name
             FROM calls c
             LEFT JOIN staff s ON s.exten_number = c.exten_number
             LEFT JOIN leads l ON l.id = c.lead_id
             LEFT JOIN customers cust ON cust.id = c.contact_id
             WHERE c.started_at >= (NOW() - INTERVAL 30 MINUTE)
             ORDER BY c.status = 'ringing' DESC, c.started_at DESC
             LIMIT 50"
        );
    }

    /**
     * استخراج متن مکالمه از فایل صوتی (Whisper، آفلاین و رایگان)
     *
     * @param int $callId
     * @return array {success, text, message}
     */
    public static function transcribe($callId)
    {
        self::ensureFileIdColumn();
        $call = self::find($callId);
        if (!$call) Response::notFound('تماس یافت نشد');

        if (empty($call['file_record'])) {
            return ['success' => false, 'message' => 'این تماس ضبط نشده، فایل صوتی برای تبدیل وجود ندارد.'];
        }
        // ⚠️ رفع باگ گمراه‌کننده: قبلاً اینجا همیشه پیام ثابت «سرویس هنوز
        // نصب نشده» نمایش داده می‌شد، حتی وقتی provider انتخابی یک سرویس
        // ابری (Groq/GapGPT/OpenAI) بود و علت واقعی فقط «کلید API وارد
        // نشده» بود - که هیچ ربطی به «نصب» ندارد و کاربر را گیج می‌کرد.
        // الان از همان پیام دقیق و مخصوص هر provider استفاده می‌شود که
        // TranscriptionService::transcribeFile() تولید می‌کند.
        if (!TranscriptionService::isAvailable()) {
            return ['success' => false, 'message' => TranscriptionService::getUnavailableMessage()];
        }

        Database::getInstance()->update('calls', ['transcript_status' => 'processing'], 'id = ?', [(int)$callId]);

        $tempFile = TranscriptionService::downloadCallRecordingToTemp($call);
        if (!$tempFile) {
            Database::getInstance()->update('calls', ['transcript_status' => 'failed'], 'id = ?', [(int)$callId]);
            return ['success' => false, 'message' => 'دانلود فایل صوتی از تلفنچی ناموفق بود.'];
        }

        $result = TranscriptionService::transcribeFile($tempFile);
        @unlink($tempFile); // پاک‌سازی فایل موقت

        if ($result['success']) {
            Database::getInstance()->update('calls', [
                'transcript'        => $result['text'],
                'transcript_status' => 'done',
                'transcribed_at'    => date('Y-m-d H:i:s'),
            ], 'id = ?', [(int)$callId]);
        } else {
            Database::getInstance()->update('calls', ['transcript_status' => 'failed'], 'id = ?', [(int)$callId]);
        }

        return $result;
    }

    /**
     * لیست تماس‌های ضبط‌شده‌ای که هنوز متن پیاده نشده‌اند (برای پردازش
     * دسته‌ای از cron - نگاه کنید به cron/transcribe_calls.php)
     *
     * @param int $limit
     * @return array
     */
    public static function getPendingTranscriptions($limit = 5)
    {
        return Database::getInstance()->fetchAll(
            "SELECT * FROM calls
             WHERE file_record IS NOT NULL AND file_record != ''
               AND (transcript_status IS NULL OR transcript_status = 'pending')
             ORDER BY started_at DESC
             LIMIT " . (int)$limit
        );
    }
}
