<?php
/**
 * جاکجاست - مدیر همگام‌سازی تماس‌ها
 * Jakojast - Call Sync Manager
 *
 * 📖 مرجع کامل و همیشه‌به‌روز: docs/TELEFONCHY_API.md
 *
 * بازنویسی شده بر اساس مستندات رسمی و به‌روز «دریافت لیست تماس‌ها»ی تلفنچی
 * (panel.telefonchy.com/webservice/v1/calls):
 *   - برخلاف نسخه‌های قبلی، API واقعاً فیلتر تاریخ دارد: date_from/date_to
 *     (شمسی، فرمت "Y/m/d H:i") — پس دیگر نیازی به pagination کورکورانه
 *     برای فیلتر تاریخ نیست.
 *   - شناسه هر تماس: cuid (نه call_id)
 *   - call_source و call_dest رشته‌ی ساده (شماره) هستند، نه آبجکت تودرتو
 *   - type: incoming | outgoing
 *   - status نمونه‌ی دیده‌شده: "ANSWERED" (احتمالاً BUSY/NO-ANSWER/CONGESTION هم هست)
 *   - API خودش started_at (میلادی) و jalali_started_at (شمسی) را می‌دهد؛
 *     دیگر نیازی به تبدیل دستی شمسی/میلادی در این‌جا نیست.
 *   - file_record همان‌جا در پاسخ لیست تماس‌ها می‌آید (نیازی به درخواست
 *     جداگانه برای دیدن نام فایل صوتی نیست)
 */

class CallSyncManager
{
    /** @var TelefonchyAPI */
    private $api;

    private $maxPages = 200;

    public function __construct()
    {
        $this->api = new TelefonchyAPI();
    }

    /**
     * همگام‌سازی تماس‌ها در یک بازه‌ی تاریخ (میلادی Y-m-d ورودی از خود سیستم)
     * که مستقیماً به فیلتر رسمی date_from/date_to (شمسی) تلفنچی تبدیل می‌شود.
     *
     * @param string|null $fromDateGregorian  Y-m-d
     * @param string|null $toDateGregorian    Y-m-d
     * @return array
     */
    public function sync($fromDateGregorian = null, $toDateGregorian = null)
    {
        if (!$this->api->isConfigured()) {
            return ['success' => false, 'message' => 'تنظیمات تلفنچی کامل نیست. token و service_id را در تنظیمات وارد کنید.'];
        }

        $filters = [];
        if (!empty($fromDateGregorian)) {
            $jFrom = Jalali::fromGregorian($fromDateGregorian . ' 00:00', 'Y/m/d H:i');
            if ($jFrom && $jFrom !== '-') $filters['date_from'] = $jFrom;
        }
        if (!empty($toDateGregorian)) {
            $jTo = Jalali::fromGregorian($toDateGregorian . ' 23:59', 'Y/m/d H:i');
            if ($jTo && $jTo !== '-') $filters['date_to'] = $jTo;
        }

        return $this->runSync($filters, false);
    }

    /**
     * همگام‌سازی افزایشی: از آخرین sync موفق تا الان (با فیلتر رسمی date_from)
     * + بک‌آپ اضافه: اگر به تماس تکراری برخورد کردیم هم متوقف می‌شویم
     *
     * @return array
     */
    /**
     * همگام‌سازی اپراتورها/داخلی‌ها از GET /services (یک درخواست):
     *  - نگاشت داخلی→نام تلفنچی در تنظیم telefonchy_extens ذخیره می‌شود
     *  - پرسنلی که داخلی ندارند و نامشان با نام داخلی تلفنچی منطبق است، خودکار وصل می‌شوند
     *  - نام پرسنل (منبع اصلی) بازنویسی نمی‌شود؛ مغایرت‌ها در خروجی گزارش می‌شوند
     */
    public function syncOperators()
    {
        $res = $this->api->getServiceInfo();
        if (empty($res['success'])) return ['success' => false, 'message' => $res['message'] ?? 'خطا'];
        $extens = [];
        foreach ((array)($res['data'] ?? []) as $svc) {
            foreach ((array)($svc['extens'] ?? []) as $e) {
                if (!empty($e['number'])) $extens[(string)$e['number']] = trim((string)($e['name'] ?? ''));
            }
        }
        if (!$extens) return ['success' => false, 'message' => 'لیست داخلی‌ها خالی بود'];
        Report::setSetting('telefonchy_extens', json_encode($extens, JSON_UNESCAPED_UNICODE));
        Report::setSetting('telefonchy_extens_synced_at', date('Y-m-d H:i:s'));

        $norm = function ($n) {
            $n = str_replace(['ي', 'ك', "\u{200C}", 'ـ'], ['ی', 'ک', ' ', ''], (string)$n);
            return array_values(array_filter(preg_split('/\s+/u', trim($n))));
        };
        $db = Database::getInstance();
        $staff = $db->fetchAll("SELECT id, name, exten_number FROM staff");
        $byExten = [];
        foreach ($staff as $st) if (!empty($st['exten_number'])) $byExten[(string)$st['exten_number']] = $st;
        $linked = []; $mismatch = []; $unmapped = [];
        foreach ($extens as $num => $tName) {
            if (isset($byExten[$num])) {
                if ($norm($byExten[$num]['name']) !== $norm($tName)) $mismatch[] = ['exten' => $num, 'telefonchy' => $tName, 'staff' => $byExten[$num]['name']];
                continue;
            }
            $tTok = $norm($tName);
            $cands = array_filter($staff, function ($st) use ($norm, $tTok) {
                return empty($st['exten_number']) && $tTok && !array_diff($tTok, $norm($st['name']));
            });
            if (count($cands) === 1) {
                $st = reset($cands);
                $db->update('staff', ['exten_number' => $num], 'id = ?', [(int)$st['id']]);
                $linked[] = ['exten' => $num, 'staff' => $st['name']];
            } else {
                $unmapped[] = ['exten' => $num, 'telefonchy' => $tName];
            }
        }
        // نام داخلی در تماس‌های بدون نام (یا با نام قدیمی) از نام فعلی تلفنچی پر شود
        $fixedNames = 0;
        foreach ($extens as $num => $tName) {
            if ($tName === '') continue;
            $fixedNames += (int)$db->execute("UPDATE calls SET exten_name = ? WHERE exten_number = ? AND (exten_name IS NULL OR exten_name = '' OR exten_name = exten_number)", [$tName, $num]);
        }
        $out = ['success' => true, 'extens' => count($extens), 'linked' => $linked, 'mismatch' => $mismatch, 'unmapped' => $unmapped, 'call_names_fixed' => $fixedNames];
        Logger::info('Telefonchy operators synced', $out);
        return $out;
    }

    /** فاصله‌ی عقب‌ماندگی که بیش از آن «حالت جبران» فعال می‌شود */
    const CATCHUP_THRESHOLD_HOURS = 3;

    /** آیا sync از «الان» بیش از آستانه عقب است؟ */
    public function isCatchingUp()
    {
        $cursor = Report::getSetting('sync_cursor_at') ?: Report::getSetting('last_sync_at');
        return $cursor && (time() - strtotime($cursor)) > self::CATCHUP_THRESHOLD_HOURS * 3600;
    }

    /**
     * sync افزایشی برای کرون، با «حالت جبران» خودکار: وقتی cursor بیش از ۳ ساعت
     * عقب است، حداکثر ۲ دسته‌ی ۷ صفحه‌ای با ۶۱ ثانیه مکث بین دسته‌ها اجرا می‌شود
     * (هر دسته ≤ ۷ درخواست در دقیقه، زیر سقف ۸؛ کل اجرا < ۳ دقیقه). پس از
     * رسیدن به زمان حال، خودکار به حالت عادی (۳ صفحه در هر اجرا) برمی‌گردد.
     */
    public function syncIncrementalAuto()
    {
        if (!$this->isCatchingUp()) {
            $r = $this->syncIncremental();
            $r['mode'] = 'normal';
            return $r;
        }
        $agg = null;
        for ($batch = 1; $batch <= 2; $batch++) {
            if ($batch > 1) sleep(61);
            $r = $this->syncIncremental(7);
            if ($agg === null) { $agg = $r; } else {
                foreach (['total_fetched', 'new_count', 'updated_count', 'contacts_matched', 'auto_leads_created'] as $k) {
                    if (isset($r[$k])) $agg[$k] = ($agg[$k] ?? 0) + $r[$k];
                }
                $agg['success'] = !empty($agg['success']) && !empty($r['success']);
            }
            if (empty($r['success']) || !Report::getSetting('sync_cursor_at') || !$this->isCatchingUp()) break;
        }
        $agg['mode'] = 'catch-up';
        $agg['batches'] = $batch > 2 ? 2 : $batch;
        Logger::info('Telefonchy sync catch-up run', ['batches' => $agg['batches'], 'cursor' => Report::getSetting('sync_cursor_at'), 'last_sync_at' => Report::getSetting('last_sync_at')]);
        return $agg;
    }

    public function syncIncremental($pageCap = 3)
    {
        if (!$this->api->isConfigured()) {
            return ['success' => false, 'message' => 'تنظیمات تلفنچی کامل نیست. token و service_id را در تنظیمات وارد کنید.'];
        }

        $lastSync = Report::getSetting('last_sync_at');
        $cursor = Report::getSetting('sync_cursor_at'); // ادامه‌ی پنجره‌ی نیمه‌تمامِ اجرای قبل
        $fromGregorian = $cursor
            ? date('Y-m-d H:i', strtotime($cursor))
            : ($lastSync
            ? date('Y-m-d H:i', strtotime($lastSync . ' -1 hour')) // ۱ ساعت عقب‌تر برای جلوگیری از miss شدن
            : date('Y-m-d H:i', strtotime('-7 days')));

        $jFrom = Jalali::fromGregorian($fromGregorian, 'Y/m/d H:i');
        $filters = $jFrom ? ['date_from' => $jFrom] : [];

        // پنجره‌ی زمانی به‌صورت صعودی صفحه‌به‌صفحه پیموده می‌شود و تماس‌های
        // موجود هم به‌روز می‌شوند (دیگر با اولین cuid تکراری متوقف نمی‌شود).
        // سقف صفحه در هر اجرا برای رعایت محدودیت نرخ API؛ ادامه در اجرای بعدی.
        return $this->runSync($filters, true, (int)$pageCap);
    }

    /**
     * هسته‌ی اصلی همگام‌سازی: صفحه‌به‌صفحه بر اساس paginator واقعی API
     *
     * @param array $filters        فیلترهای رسمی date_from/date_to/... برای ارسال به API
     * @param bool  $stopOnExisting توقف به محض برخورد با cuid تکراری (برای sync افزایشی)
     * @return array
     */
    private function runSync(array $filters, $incremental, $pageCap = null)
    {
        Logger::info('Telefonchy call sync started', ['filters' => $filters, 'incremental' => $incremental]);
        $runStartedAt = date('Y-m-d H:i:s');
        $maxPages = $pageCap ?: $this->maxPages;
        $completed = false;
        $maxProcessedStartedAt = null;

        $totalFetched = 0;
        $newCount = 0;
        $updatedCount = 0;
        $contactsMatched = 0;
        $page = 1;
        $stop = false;

        while (!$stop) {
            $response = $this->api->getCalls(array_merge($filters, ['page' => $page, 'sort' => $incremental ? 'created_at ASC' : 'created_at DESC']));

            if (!$response['success'] && !empty($response['rate_limited'])) {
                // سقف نرخ محلی: خطا نیست؛ cursor ذخیره می‌شود و اجرای بعدی ادامه می‌دهد
                Logger::info('Telefonchy sync paused by local rate limit', ['page' => $page]);
                if ($page === 1) return ['success' => true, 'rate_limited' => true, 'total_fetched' => 0, 'new_count' => 0, 'updated_count' => 0];
                break;
            }
            if (!$response['success']) {
                Logger::error('Telefonchy API call failed during sync', ['page' => $page, 'message' => $response['message'] ?? 'unknown']);
                if ($page === 1) {
                    return ['success' => false, 'message' => $response['message']];
                }
                break;
            }

            // ⚠️ ساختار واقعی (کشف‌شده با curl دستی، متفاوت از همه‌ی
            // مستندات قبلی): پاسخ یک لایه‌ی تودرتوی اضافه دارد:
            //   response.data = { status:"success", data:[...calls...],
            //                      pagination:{current_page, total_pages,
            //                      total_items, items_per_page, has_more} }
            // یعنی لیست واقعی تماس‌ها در response.data.data است (نه
            // response.data.calls)، و اطلاعات صفحه‌بندی در
            // response.data.pagination است (نه response.paginator).
            $outer = $response['data'] ?? null;
            if ($outer === null) {
                break; // یعنی واقعاً هیچ نتیجه‌ای نیست
            }
            $calls = is_array($outer) ? ($outer['data'] ?? []) : [];

            // لایه‌ی محافظتی: فقط عناصری که واقعاً آرایه هستند پردازش شوند
            // (اگر تلفنچی ساختار غیرمنتظره‌ای برگرداند، سایر آیتم‌ها را رد کن
            // نه این‌که کل sync متوقف شود)
            $calls = array_filter($calls, 'is_array');

            if (empty($calls)) {
                $completed = true;
                break;
            }

            foreach ($calls as $callData) {
                $totalFetched++;

                try {
                    $result = $this->processCall($callData);
                } catch (\Throwable $e) {
                    // اگر پردازش یک تماس خاص خطا داد (مثلاً فرمت غیرمنتظره)، کل
                    // همگام‌سازی متوقف نشود؛ فقط همین یکی رد شود و در لاگ ثبت شود
                    Logger::error('Telefonchy sync: failed to process one call', [
                        'cuid' => $callData['cuid'] ?? $callData['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    TelefonchyApiLog::log([
                        'direction'     => 'outbound',
                        'endpoint'      => '/calls (پردازش تماس - خطای داخلی)',
                        'success'       => false,
                        'error_message' => 'خطا در پردازش تماس cuid=' . ($callData['cuid'] ?? $callData['id'] ?? '?') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' (خط ' . $e->getLine() . ')',
                    ]);
                    continue;
                }

                if (!empty($result['started_at']) && ($maxProcessedStartedAt === null || $result['started_at'] > $maxProcessedStartedAt)) {
                    $maxProcessedStartedAt = $result['started_at'];
                }

                if ($result['is_new']) {
                    $newCount++;
                } else {
                    $updatedCount++;
                }
                if (!empty($result['contact_matched'])) {
                    $contactsMatched++;
                }
            }

            // اطلاعات صفحه‌بندی واقعی داخل outer.pagination است (نه
            // response.paginator که در مستندات آمده بود ولی در پاسخ واقعی
            // اصلاً وجود نداشت)
            $pagination = is_array($outer) ? ($outer['pagination'] ?? null) : null;
            if (!$stop) {
                if ($pagination && isset($pagination['has_more']) && !$pagination['has_more']) {
                    $stop = true; $completed = true;
                } elseif ($pagination && isset($pagination['total_pages']) && $page >= (int)$pagination['total_pages']) {
                    $stop = true; $completed = true;
                } else {
                    $page++;
                    if ($page > $maxPages) $stop = true;
                }
            }
        }

        // last_sync_at: اگر کل پنجره پیموده شد = زمان شروع همین اجرا؛ وگرنه
        // (سقف صفحه/خطا) = آخرین تماسِ پردازش‌شده تا اجرای بعدی از همان‌جا ادامه دهد.
        if (!$incremental || $completed) {
            Report::setSetting('last_sync_at', $runStartedAt);
            if ($incremental) Report::setSetting('sync_cursor_at', '');
        } elseif ($maxProcessedStartedAt !== null) {
            Report::setSetting('sync_cursor_at', $maxProcessedStartedAt);
        }

        // تبدیل خودکار تماس‌های بدون مشتری/سرنخ شناخته‌شده به سرنخ (شبکه‌ی
        // ایمنیِ نهایی برای هر تماسی که به هر دلیل از مسیر بلادرنگ وب‌هوک
        // جا مانده باشد).
        //
        // ⚠️ باگ رفع‌شده: قبلاً این متد فقط وقتی `$newCount > 0` بود صدا
        // زده می‌شد («فقط اگر همین sync تماس جدید آورد»). اما در عملیات
        // واقعی، وب‌هوک تقریباً همه‌ی تماس‌ها را قبل از رسیدن این کرون
        // دوره‌ای در جدول `calls` درج می‌کند؛ پس وقتی این تابع دوباره از
        // تلفنچی همان تماس‌ها را می‌گرفت، `Call::upsert()` آن‌ها را
        // «تکراری» (`is_new = false`) تشخیص می‌داد و `$newCount` تقریباً
        // همیشه صفر می‌ماند. نتیجه: این شبکه‌ی ایمنی در عمل تقریباً هیچ‌وقت
        // اجرا نمی‌شد - فقط در بک‌فیل اولیه (دیتابیس خالی) کار می‌کرد. اگر
        // یک تماس به هر دلیلی (رویداد ringing دیرتر از hangup رسیده، خطای
        // موقت، ...) در لحظه سرنخ نگرفته بود، برای همیشه بدون سرنخ می‌ماند.
        //
        // رفع شد: این متد حالا مستقل از newCount و در هر اجرای sync صدا
        // زده می‌شود؛ خودش داخلاً فقط روی تماس‌های واقعاً یتیم
        // (contact_id و lead_id هر دو خالی) کوئری می‌زند، پس هزینه‌ی
        // اضافه‌اش ناچیز است.
        $autoLeadResult = ['created_leads' => 0, 'linked_calls' => 0];
        try {
            $autoLeadResult = Call::autoGenerateLeadsFromRecentCalls();
        } catch (\Throwable $e) {
            Logger::error('Auto lead generation after sync failed: ' . $e->getMessage());
            try {
                TelefonchyApiLog::log([
                    'direction'     => 'outbound',
                    'endpoint'      => '/sync (شبکه‌ی ایمنی تولید خودکار سرنخ)',
                    'success'       => false,
                    'error_message' => get_class($e) . ': ' . $e->getMessage() . ' (خط ' . $e->getLine() . ')',
                ]);
            } catch (\Throwable $ignore) {}
        }

        Logger::info('Telefonchy call sync completed', [
            'total' => $totalFetched, 'new' => $newCount, 'updated' => $updatedCount, 'contacts' => $contactsMatched,
            'auto_leads' => $autoLeadResult['created_leads'],
        ]);

        $leadMsg = $autoLeadResult['created_leads'] > 0 ? " و {$autoLeadResult['created_leads']} سرنخ جدید ساخته شد" : '';

        return [
            'success'          => true,
            'total_fetched'    => $totalFetched,
            'new_count'        => $newCount,
            'updated_count'    => $updatedCount,
            'contacts_matched' => $contactsMatched,
            'auto_leads_created' => $autoLeadResult['created_leads'],
            'message'          => "همگام‌سازی انجام شد. {$newCount} تماس جدید، {$updatedCount} به‌روزرسانی شد{$leadMsg}.",
        ];
    }

    /**
     * [DEPRECATED] این متد برای ساختار قدیمی (فرضی) webhook بود. ساختار واقعی
     * webhook تلفنچی طبق مستندات رسمی «دریافت ایونت تماس» فرق دارد:
     * رویداد به‌صورت {uid, rid, message, data:{cuid, src, dest, type, event, ...}}
     * می‌آید. از ingestRingingEvent() و ingestHangupEvent() استفاده کنید.
     *
     * @param array $eventData
     * @return array
     * @deprecated
     */
    public function ingestSingleCall(array $eventData)
    {
        $result = $this->processCall($eventData);
        Logger::info('Telefonchy CDR ingested (legacy path)', ['cuid' => $eventData['cuid'] ?? null, 'is_new' => $result['is_new']]);
        return $result;
    }

    /**
     * نرمال‌سازی شماره‌ی موبایل ایرانی که ممکن است صفر ابتدایی‌اش گم شده باشد.
     *
     * ⚠️ باگ واقعی کشف‌شده با نمونه‌ی خام webhook: در رویداد "ringing"،
     * تلفنچی شماره‌ها را به‌صورت عدد JSON می‌فرستد (نه رشته) - مثلاً
     * `"src": 9124912072` به‌جای `"src": "09124912072"`. چون یک عدد JSON
     * نمی‌تواند صفر ابتدایی داشته باشد، صفر ابتدایی شماره‌ی موبایل ایرانی
     * همیشه از بین می‌رفت. این باعث می‌شد `Customer::findByMobile()` و
     * تشخیص «سرنخ تکراری» شکست بخورند (چون شماره‌ی ذخیره‌شده از این مسیر
     * با شماره‌ی ۱۱ رقمی معمول یکی نبود) و برای هر تماس یک سرنخ *جدید*
     * (به‌جای پیدا کردن سرنخ قبلی) ساخته شود.
     *
     * این متد فقط الگوی مشخصِ «۱۰ رقم، شروع با ۹» (موبایل ایرانی بدون صفر)
     * را اصلاح می‌کند؛ شماره‌های داخلی (۳ تا ۶ رقمی) و شماره‌های ثابت را
     * دست‌نخورده برمی‌گرداند.
     *
     * @param mixed $number
     * @return string|null
     */
    private function normalizeIranianMobile($number)
    {
        // 2.1.5: shared normaliser. Real numbers → canonical (09…/0…/+…), so +98/0098 and
        // Persian digits now match the customer records; extensions/codes are kept as digits.
        return PhoneNumber::callParty($number);
    }

    /**
     * تبدیل یک تاریخ/ساعت UTC (بدون منطقه‌زمانی، طبق نمونه‌ی واقعی
     * starttime/endtime رویداد hangup) به وقت محلی تهران.
     *
     * ⚠️ کشف‌شده با نمونه‌ی خام: برخلاف رویداد "ringing" که فیلدهای
     * datetime/date را از قبل به وقت تهران می‌دهد، رویداد "hangup"
     * فیلدهای starttime/endtime را به وقت UTC می‌دهد (فیلد "date" خودش
     * هم در hangup به وقت تهران است، ولی معادل زمان پایان تماس است، نه
     * شروع آن - برای همین از starttime با تبدیل منطقه‌زمانی استفاده
     * می‌کنیم تا started_at واقعی و دقیق باشد).
     *
     * @param string|null $utcDateTimeStr
     * @return string|null  فرمت Y-m-d H:i:s به وقت تهران
     */
    private function convertUtcToTehran($utcDateTimeStr)
    {
        if (empty($utcDateTimeStr)) return null;
        try {
            $dt = new \DateTime($utcDateTimeStr, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone('Asia/Tehran'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * تشخیص «لگ تکراری و خراب» تماس‌های خروجی.
     *
     * ⚠️ کشف مهم با مقایسه‌ی نمونه‌های خام واقعی: تلفنچی برای **هر تماس
     * خروجی واقعی، دو رویداد hangup جدا با cuid متفاوت** می‌فرستد:
     *   ۱) لگِ داخلی/اصلی (تمیز): src = داخلی واقعی (مثلاً 326153)،
     *      dst = شماره‌ی مشتری تمیز، و هیچ‌وقت فیلد `entry_point` ندارد
     *      (فقط `outgoing_point`).
     *   ۲) لگِ برنامه‌ی داخلی تلفنچی (خراب): src همیشه یک عدد ثابت
     *      (مثلاً "09108849423" در همه‌ی نمونه‌ها یکی بود)، `dst` و
     *      `did` هر دو یک رشته‌ی بهم‌چسبیده‌ی بی‌معنی هستند (DID و شماره‌ی
     *      مقصد بدون جداکننده به هم چسبیده‌اند)، و **همیشه هم
     *      `entry_point` هم `outgoing_point` را همزمان دارد**.
     * این الگو در تمام نمونه‌های بررسی‌شده بدون استثنا برقرار بود. لگ
     * دوم هیچ اطلاعات جدیدی نسبت به لگ اول ندارد و فقط داده‌ی بی‌معنی
     * وارد calls/leads می‌کند (سرنخ با شماره‌ی ۲۰+ رقمی!)، پس کاملاً
     * نادیده گرفته می‌شود (نه رکورد calls برایش ساخته می‌شود، نه سرنخ).
     *
     * @param string      $type             inbound|outbound|internal
     * @param array       $eventPayloadData
     * @return bool
     */
    private function isDuplicateOutgoingAppLeg($type, array $eventPayloadData)
    {
        return $type === 'outbound' && !empty($eventPayloadData['entry_point']);
    }

    /**
     * تشخیص داخلی (اپراتور/پرسنل) درگیر در تماس، مستقیم از payload
     * webhook - بدون نیاز به منتظر ماندن برای سینک دوره‌ای.
     *
     * منطق: داخلی‌ها همیشه کوتاه هستند (۳ تا ۶ رقم)؛ شماره‌ی موبایل/DID
     * طولانی‌تر است. هر دو طرف تماس (src و dst/dest) را چک می‌کنیم -
     * برای خروجی معمولاً src داخلی است، برای ورودی (فقط بعد از پاسخ/در
     * hangup) معمولاً dst داخلی است؛ اما به‌جای فرض جهت، هر دو را با
     * جدول `staff` واقعی تطبیق می‌دهیم تا مطمئن باشیم.
     *
     * @param array $candidates  آرایه‌ای از شماره‌های خام برای چک (نه لزوماً نرمال‌شده)
     * @return array {number, name} - اگر پیدا نشد هر دو null
     */
    private function resolveExtension(array $candidates)
    {
        $shortCandidates = [];
        foreach ($candidates as $num) {
            if (empty($num)) continue;
            $digits = preg_replace('/\D+/', '', (string)$num);
            if ($digits !== '' && strlen($digits) <= 6) {
                $shortCandidates[] = $digits;
            }
        }
        if (empty($shortCandidates)) return ['number' => null, 'name' => null];

        foreach ($shortCandidates as $digits) {
            $staff = Database::getInstance()->fetchOne(
                'SELECT name FROM staff WHERE exten_number = ? LIMIT 1',
                [$digits]
            );
            if ($staff) return ['number' => $digits, 'name' => $staff['name']];
        }

        // هیچ پرسنلی هنوز به این داخلی وصل نشده، ولی چون کوتاه است
        // احتمالاً واقعاً داخلی است - شماره را بدون نام نگه می‌داریم
        return ['number' => $shortCandidates[0], 'name' => null];
    }

    /**
     * تلاش برای اتصال تماس به مشتری شناخته‌شده، و در صورت عدم تطابق،
     * ساخت خودکار سرنخ برای شماره‌ی ناشناس. این منطق قبلاً در سه جای
     * مختلف (ringing، hangup، processCall) کپی-پیست شده بود؛ حالا یک‌جا
     * نگه‌داری می‌شود.
     *
     * ⚠️ طبق تصمیم صریح کارفرما: از این نسخه به بعد، هم تماس‌های ورودی
     * هم خروجیِ ناشناس خودکار سرنخ می‌گیرند (قبلاً فقط ورودی بود). فقط
     * تماس‌های داخلی (internal) کاملاً مستثنی هستند.
     *
     * ⚠️ نکته‌ی حیاتی: پارامتر $externalNumber باید از قبل توسط فراخوان
     * بر اساس جهت تماس انتخاب شده باشد - برای ورودی همان call_source
     * (تماس‌گیرنده)، برای خروجی call_dest (کسی که با او تماس گرفته شده).
     * دادن call_source برای یک تماس خروجی اشتباه است چون در آن حالت
     * call_source داخلی خودِ اپراتور است، نه شماره‌ی مشتری.
     *
     * @param int         $callDbId
     * @param string      $type            inbound|outbound|internal
     * @param string|null $externalNumber  شماره‌ی طرف مقابل (نه شماره‌ی داخلی خودمان)
     * @param array|null  $existingBefore  رکورد قبلی تماس (برای جلوگیری از کار تکراری)
     * @return bool  آیا به مشتری شناخته‌شده وصل شد
     */
    private function attemptContactAndLeadLinking($callDbId, $type, $externalNumber, $existingBefore = null)
    {
        if (!$callDbId) return false;

        // ⚠️ طبقه‌بندی خودکار تماس (call_category) - فقط اگر قبلاً دستی یا
        // خودکار چیزی غیر از 'unclassified' تنظیم نشده باشد، تا هیچ‌وقت
        // یک انتخاب دستی اپراتور (مثلاً 'spam'/'recruitment') را بازنویسی نکند.
        $currentCategory = $existingBefore['call_category'] ?? null;
        if (empty($currentCategory) || $currentCategory === 'unclassified') {
            $category = Call::autoClassifyCategory($type, $externalNumber);
            if ($category !== 'unclassified') {
                Database::getInstance()->update('calls', ['call_category' => $category], 'id = ?', [(int)$callDbId]);
            }
        }

        if ($type === 'internal' || empty($externalNumber)) return false;

        $alreadyHasContact = !empty($existingBefore['contact_id']);
        $alreadyHasLead = !empty($existingBefore['lead_id']);

        $contactMatched = $alreadyHasContact;
        if (!$alreadyHasContact) {
            $contactId = Call::assignContactByMobile($callDbId, $externalNumber);
            if ($contactId) $contactMatched = true;
        }

        $leadCreated = false;
        if (!$contactMatched && !$alreadyHasLead) {
            try {
                $leadCreated = (bool)Call::ensureLeadForRingingCall($callDbId, $externalNumber, $type);
            } catch (\Throwable $e) {
                Logger::error('Contact/lead linking failed: ' . $e->getMessage());
                try {
                    TelefonchyApiLog::log([
                        'direction'     => 'webhook',
                        'endpoint'      => '/webhook.php (اتصال مخاطب/ساخت سرنخ)',
                        'success'       => false,
                        'error_message' => get_class($e) . ': ' . $e->getMessage() . ' (خط ' . $e->getLine() . ')',
                    ]);
                } catch (\Throwable $ignore) {}
            }
        }

        // ⚠️ اگر این تماس همین الان به مشتری/سرنخ وصل شد و دسته‌بندی‌اش
        // هنوز 'unclassified' مانده بود (یعنی میزبان شناخته‌شده نبود)،
        // یعنی قطعاً یک تماس «مهمان» است - دسته‌بندی نهایی می‌شود.
        if (($contactMatched || $leadCreated || $alreadyHasContact || $alreadyHasLead)
            && (empty($currentCategory) || $currentCategory === 'unclassified')) {
            $freshCategory = Database::getInstance()->fetchColumn('SELECT call_category FROM calls WHERE id = ?', [(int)$callDbId]);
            if ($freshCategory === 'unclassified') {
                Database::getInstance()->update('calls', ['call_category' => 'guest'], 'id = ?', [(int)$callDbId]);
            }
        }

        return $contactMatched;
    }

    /**
     * پردازش رویداد "ringing" - ساختار واقعی و تأیید‌شده با نمونه‌ی خام:
     * {service_id, cuid, unique_id, src (عدد!), dest (عدد!), type, client_ip,
     *  event:"ringing", datetime (وقت تهران), trunk_number, trunk_incoming/
     *  trunk_outgoing, webservice_token, events_hook, app_name, date}
     *
     * @param array $eventPayloadData  کل payload وب‌هوک (بدون هیچ لایه‌ی تودرتو)
     * @return array {id, is_new}
     */
    public function ingestRingingEvent(array $eventPayloadData)
    {
        $cuid = $eventPayloadData['cuid'] ?? $eventPayloadData['unique_id'] ?? null;
        if (empty($cuid)) {
            return ['id' => null, 'is_new' => false];
        }

        $type = $this->mapCallType($eventPayloadData['type'] ?? null);

        // ⚠️ لگ تکراری/خراب اپ داخلی تلفنچی - کاملاً نادیده گرفته می‌شود
        // (نگاه کنید به داک‌بلاک isDuplicateOutgoingAppLeg)
        if ($this->isDuplicateOutgoingAppLeg($type, $eventPayloadData)) {
            Logger::info('Telefonchy ringing: ignoring duplicate outgoing app-leg', ['cuid' => $cuid]);
            return ['id' => null, 'is_new' => false, 'ignored' => 'outgoing app-leg (فوروارد تماس ورودی)'];
        }

        $startedAt = $this->normalizeWebhookDate($eventPayloadData['datetime'] ?? $eventPayloadData['date'] ?? null);

        // ⚠️ رفع باگ صفر ابتدایی: src/dest در ringing به‌صورت عدد JSON
        // می‌آیند (نگاه کنید به داک‌بلاک normalizeIranianMobile)
        $source = $this->normalizeIranianMobile($eventPayloadData['src'] ?? null);
        $dest   = $this->normalizeIranianMobile($eventPayloadData['dest'] ?? null);

        // ⚠️ داخلی مستقیم از payload (نگاه کنید به resolveExtension) - در
        // ringing فقط برای خروجی معمولاً پیدا می‌شود (src کوتاه)؛ برای
        // ورودی هنوز مشخص نیست چون تماس هنوز به داخلی خاصی وصل نشده.
        $exten = $this->resolveExtension([$eventPayloadData['src'] ?? null, $eventPayloadData['dest'] ?? null]);

        $data = [
            'cuid'              => (string)$cuid,
            'call_source'       => $source,
            'call_dest'         => $dest,
            'type'              => $type,
            'status'            => 'ringing', // موقت - با hangup به‌روزرسانی می‌شود
            'started_at'        => $startedAt,
            'jalali_started_at' => $startedAt ? Jalali::fromGregorian($startedAt, 'Y/m/d H:i:s') : null,
            'trunk'             => $eventPayloadData['trunk_number'] ?? null,
        ];
        // فقط وقتی واقعاً پیدا شد اضافه کن - تا یک رویداد بعدیِ دیرآمده
        // با مقدار null مقدار درستِ قبلی را پاک نکند
        if ($exten['number'] !== null) {
            $data['exten_number'] = $exten['number'];
            $data['exten_name'] = $exten['name'];
        }

        $existingBefore = Call::findByCuid($data['cuid']);
        $result = Call::upsert($data);

        if ($result['id']) {
            // ⚠️ تبدیل فوری به سرنخ همان لحظه‌ی زنگ خوردن (بدون منتظر ماندن
            // تا قطع تماس). برای ورودی، طرف ناشناس همان src است؛ برای
            // خروجی، طرف ناشناس dest است (src برای خروجی داخلیِ خودمان است).
            $externalNumber = ($type === 'outbound') ? $dest : $source;
            $this->attemptContactAndLeadLinking($result['id'], $type, $externalNumber, $existingBefore);
        }

        return $result;
    }

    /**
     * پردازش رویداد "hangup" - ساختار واقعی کاملاً متفاوت از "ringing" است
     * (کشف‌شده با نمونه‌ی خام؛ قبلاً به‌اشتباه فرض شده بود این رویداد
     * جزئیاتی ندارد و باید بلافاصله GET /call زده شود):
     * {starttime, endtime (هر دو UTC!), src, dst (نه dest!), type,
     *  disposition ("ANSWERED"|"NO ANSWER"|...), duration, billsec, wait,
     *  record (نام فایل ضبط، اگر ضبط شده باشد), unique_id, cuid,
     *  entry_point/outgoing_point, did, webservice_token, service_id,
     *  events_hook, trunk_number, trunk_incoming, event:"hangup", date}
     *
     * چون این رویداد خودش duration/billsec/wait/disposition/record را
     * می‌دهد، دیگر برای ثبت اصلی تماس نیازی به درخواست جداگانه به تلفنچی
     * نیست (سریع‌تر و مقاوم‌تر در برابر خطای شبکه). فقط برای `file_id`
     * (لازم برای دانلود فایل صوتی) - که در payload وب‌هوک نیست - یک تلاش
     * best-effort و غیرمسدودکننده انجام می‌شود، و فقط وقتی تماس واقعاً
     * ضبط شده باشد.
     *
     * @param array $eventPayloadData
     * @return array {id, is_new, enriched}
     */
    public function ingestHangupEvent(array $eventPayloadData)
    {
        $cuid = $eventPayloadData['cuid'] ?? $eventPayloadData['unique_id'] ?? null;
        if (empty($cuid)) {
            return ['id' => null, 'is_new' => false, 'enriched' => false];
        }
        $cuid = (string)$cuid;

        $type = $this->mapCallType($eventPayloadData['type'] ?? null);

        // ⚠️ لگ تکراری/خراب اپ داخلی تلفنچی - کاملاً نادیده گرفته می‌شود
        // (نگاه کنید به داک‌بلاک isDuplicateOutgoingAppLeg). این دقیقاً
        // همان چیزی است که باعث می‌شد dst/did به‌شکل بی‌معنیِ به‌هم‌چسبیده
        // (مثل "98219109459909364453659") در calls/leads ذخیره شود.
        if ($this->isDuplicateOutgoingAppLeg($type, $eventPayloadData)) {
            Logger::info('Telefonchy hangup: ignoring duplicate outgoing app-leg', ['cuid' => $cuid]);
            return ['id' => null, 'is_new' => false, 'enriched' => false, 'ignored' => 'outgoing app-leg (فوروارد تماس ورودی)'];
        }

        $existingBefore = Call::findByCuid($cuid);

        // ⚠️ نکته‌ی حیاتی: مقصد در رویداد hangup کلیدش `dst` است، نه
        // `dest` (که فقط در ringing استفاده می‌شود) - این دو رویداد
        // ساختار کاملاً جدایی دارند، سهواً یک‌سان فرض نشوند.
        $source = $this->normalizeIranianMobile($eventPayloadData['src'] ?? null);
        $dest   = $this->normalizeIranianMobile($eventPayloadData['dst'] ?? $eventPayloadData['dest'] ?? null);

        // ⚠️ داخلی مستقیم از payload (نگاه کنید به resolveExtension) -
        // برای ورودی معمولاً dst (کسی که پاسخ داده)، برای خروجی معمولاً
        // src (کسی که زنگ زده) کوتاه و در جدول staff پیدا می‌شود.
        $exten = $this->resolveExtension([$eventPayloadData['src'] ?? null, $eventPayloadData['dst'] ?? $eventPayloadData['dest'] ?? null]);

        $billsec = (int)($eventPayloadData['billsec'] ?? 0); // مدت واقعی مکالمه
        $wait    = (int)($eventPayloadData['wait'] ?? 0);    // مدت زنگ خوردن/انتظار

        $status = $this->mapCallStatus($eventPayloadData['disposition'] ?? null, ['time_talk' => $billsec]);

        // starttime به UTC است؛ تبدیل به وقت تهران. اگر موجود نبود، از
        // رکورد قبلی (همان که رویداد ringing ساخته) یا از date استفاده می‌شود.
        $startedAt = $this->convertUtcToTehran($eventPayloadData['starttime'] ?? null)
            ?? ($existingBefore['started_at'] ?? null)
            ?? $this->normalizeWebhookDate($eventPayloadData['date'] ?? null);

        $fileRecord = $eventPayloadData['record'] ?? null;

        $data = [
            'cuid'              => $cuid,
            'call_source'       => $source,
            'call_dest'         => $dest,
            'type'              => $type,
            'status'            => $status,
            'time_wait'         => $wait,
            'time_talk'         => $billsec,
            'started_at'        => $startedAt,
            'jalali_started_at' => $startedAt ? Jalali::fromGregorian($startedAt, 'Y/m/d H:i:s') : null,
            'trunk'             => $eventPayloadData['trunk_number'] ?? null,
            'file_record'       => $fileRecord,
        ];
        if ($exten['number'] !== null) {
            $data['exten_number'] = $exten['number'];
            $data['exten_name'] = $exten['name'];
        }

        $result = Call::upsert($data);
        $callDbId = $result['id'];

        if ($callDbId) {
            $externalNumber = ($type === 'outbound') ? $dest : $source;
            $this->attemptContactAndLeadLinking($callDbId, $type, $externalNumber, $existingBefore);
        }

        // ⚠️ رفع باگ حیاتی: قبلاً اینجا از api->getCallInfo() (تک تماسی،
        // GET /call?call_id=) استفاده می‌شد، ولی طبق لاگ‌های واقعی این
        // endpoint همیشه (نه گاه‌به‌گاه) با کد ۵۰۰ و «پاسخ JSON نامعتبر»
        // شکست می‌خورد - یعنی file_id هیچ‌وقت برای هیچ تماسی ست نمی‌شد و
        // در نتیجه نه پخش صدا نه تبدیل به متن هیچ‌وقت کار نمی‌کرد. الان
        // به‌جایش از همان GET /calls (لیست) که قطعاً کار می‌کند و در
        // runSync/همگام‌سازی دوره‌ای همیشه موفق بوده استفاده می‌کنیم.
        // ⚠️ دیگر /calls را همزمان با hangup صدا نمی‌زنیم: تماس هنوز در لیست
        // تلفنچی نیامده (علت خطاهای «cuid پیدا نشد»). file_id از وب‌هوک CDR
        // می‌رسد؛ باقی‌مانده‌ها توسط backfillMissingRecordings() در کرون.
        $enriched = false;

        // ⚠️ تبدیل خودکار و فوری صدا به متن، بلافاصله بعد از قطع تماس.
        // فقط وقتی روش فعلی 'vosk' است این کار را می‌کنیم - چون Vosk سبک و
        // سریع است (چند ثانیه)، برخلاف Whisper محلی که چند ده ثانیه طول
        // می‌کشد و می‌تواند فشار زیادی روی CPU بگذارد اگر برای هر تماس
        // بلافاصله اجرا شود (برای Whisper همچنان از صف/کرون دوره‌ای
        // استفاده می‌شود - نگاه کنید به cron/transcribe_calls.php).
        //
        // اجرا به‌صورت پس‌زمینه (exec با & در انتها) است تا خودِ وب‌هوک
        // معطل تمام‌شدن تبدیل صدا به متن نماند و سریع 200 برگرداند.
        //
        // ⚠️ اگر exec() روی این سرور غیرفعال باشد (disable_functions در
        // php.ini - رایج روی سرورهایی که برای امنیت سخت‌گیرانه شده‌اند)،
        // این تریگر فوری امکان‌پذیر نیست؛ به‌جایش تماس همچنان توسط صف
        // دوره‌ای (cron/transcribe_calls.php) با تأخیر چند دقیقه‌ای پردازش
        // می‌شود - نه خطا، فقط دیگر «فوری» نیست.
        if ($enriched && $callDbId && TranscriptionService::getProvider() === 'vosk' && TranscriptionService::isExecEnabled()) {
            try {
                $phpBinary = PHP_BINARY ?: 'php';
                $script = ROOT_PATH . '/cron/transcribe_calls.php';
                $logFile = ROOT_PATH . '/logs/transcribe.log';
                $cmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($script)
                    . ' --call_id=' . (int)$callDbId
                    . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
                exec($cmd);
            } catch (\Throwable $e) {
                Logger::error('Failed to spawn background transcription: ' . $e->getMessage());
            }
        }

        return ['id' => $callDbId, 'is_new' => $result['is_new'], 'enriched' => $enriched];
    }

    /**
     * ذخیره‌ی مستقیم وب‌هوک CDR (بدون رفت‌وبرگشت به /calls).
     * فیلدهای مستند: call_id, object_id, exten, type, trunk, status,
     * call_source, call_dest, time_wait, time_talk, record_id, created_at,
     * ended_at, file_id (+ تحمل نام‌های جایگزین cuid/unique_id/src/dst/...)
     * تطبیق: اول با cuid/unique_id/call_id، بعد با src/dest در بازه‌ی ±۳ دقیقه.
     */
    public function ingestCdrEvent(array $d)
    {
        $key = (string)($d['cuid'] ?? $d['unique_id'] ?? $d['call_id'] ?? '');

        $rawSrc = $d['call_source'] ?? $d['src'] ?? null;
        $rawDst = $d['call_dest'] ?? $d['dst'] ?? $d['dest'] ?? null;
        if (is_array($rawSrc)) $rawSrc = $rawSrc['number'] ?? null;
        if (is_array($rawDst)) $rawDst = $rawDst['number'] ?? null;
        $src = $this->normalizeIranianMobile($rawSrc);
        $dst = $this->normalizeIranianMobile($rawDst);

        // created_at/ended_at در CDR شمسی هستند (مثلاً "1405-08-07 19:49:45")
        $toGreg = function ($v) {
            if (empty($v)) return null;
            if (preg_match('/^1[34]\d\d[-\/]/', (string)$v)) return Jalali::toGregorianString(str_replace('-', '/', (string)$v));
            return $this->normalizeWebhookDate($v);
        };
        $startedAt = $toGreg($d['started_at'] ?? $d['created_at'] ?? null)
            ?? $this->convertUtcToTehran($d['starttime'] ?? null)
            ?? $this->normalizeWebhookDate($d['date'] ?? null);

        $db = Database::getInstance();
        $existing = $key !== '' ? Call::findByCuid($key) : null;
        if (!$existing && $startedAt && ($src || $dst)) {
            // 2.1.5: include the legacy (pre-normalisation) forms so rows stored before the
            // normaliser change are still found and no duplicate call row is created on backfill.
            $nums = array_values(array_unique(array_map('strval', array_filter([
                $src, $dst, PhoneNumber::legacyCallParty($rawSrc), PhoneNumber::legacyCallParty($rawDst),
            ], function ($v) { return $v !== null && $v !== ''; }))));
            $ph = implode(', ', array_fill(0, count($nums), '?'));
            $existing = $db->fetchOne(
                "SELECT * FROM calls
                  WHERE started_at BETWEEN (? - INTERVAL 3 MINUTE) AND (? + INTERVAL 3 MINUTE)
                    AND (call_source IN ({$ph}) OR call_dest IN ({$ph}))
                  ORDER BY ABS(TIMESTAMPDIFF(SECOND, started_at, ?)) LIMIT 1",
                array_merge([$startedAt, $startedAt], $nums, $nums, [$startedAt])
            ) ?: null;
        }

        $cuid = $existing['cuid'] ?? $key;
        if ($cuid === '') {
            return ['id' => null, 'is_new' => false, 'matched' => false];
        }

        // تبدیل به همان ساختار ردیف /calls که processCall() می‌شناسد
        $row = $d;
        $row['cuid'] = $cuid;
        unset($row['id']);
        if (!isset($row['call_source']) && $rawSrc !== null) $row['call_source'] = $rawSrc;
        if (!isset($row['call_dest']) && $rawDst !== null)   $row['call_dest'] = $rawDst;
        if (!isset($row['status']) && isset($d['disposition'])) $row['status'] = $d['disposition'];
        if (!isset($row['time_talk']) && isset($d['billsec']))  $row['time_talk'] = $d['billsec'];
        if (!isset($row['time_wait']) && isset($d['wait']))     $row['time_wait'] = $d['wait'];
        if (!isset($row['file_record']) && !empty($d['record'])) $row['file_record'] = $d['record'];
        // record_id در CDR فقط پرچم 0/1 است، نه نام فایل - نام فایل mp3 را از hangup نگه می‌داریم
        if (!isset($row['file_record'])) $row['file_record'] = $existing['file_record'] ?? null;
        // file_id = "0" یعنی ضبط ندارد
        $fid = isset($d['file_id']) ? (string)$d['file_id'] : '';
        $row['file_id'] = ($fid !== '' && $fid !== '0') ? $fid : ($existing['file_id'] ?? null);
        if ($row['file_id'] === '0') $row['file_id'] = null;
        // داخلی: CDR شکل {"exten":{"id","caller_id"}, "contact":{...name}} دارد؛ processCall انتظار exten.number/name دارد
        $extId = is_array($d['exten'] ?? null) ? (string)($d['exten']['id'] ?? $d['exten']['number'] ?? '') : (string)($d['exten'] ?? '');
        $extName = null;
        foreach (['call_dest', 'call_source'] as $side) {
            $c = $d['contact'][$side] ?? null;
            if (is_array($c) && ($c['type'] ?? '') === 'exten' && !empty($c['name']) && $c['name'] !== '0') { $extName = $c['name']; break; }
        }
        if ($extId === '' || $extId === '0') { $extId = $existing['exten_number'] ?? null; }
        $row['exten'] = [
            'number' => $extId ?: null,
            'name'   => $extName ?? ($existing['exten_name'] ?? null),
        ];
        if (!isset($row['trunk']) && isset($d['trunk_number'])) $row['trunk'] = $d['trunk_number'];
        unset($row['created_at']);
        if ($startedAt) $row['started_at'] = $startedAt;
        unset($row['jalali_started_at']);
        // اگر رکورد قبلی (ringing/hangup) زمان شروع دارد، آن را نگه می‌داریم
        if ($existing && !empty($existing['started_at'])) $row['started_at'] = $existing['started_at'];

        $r = $this->processCall($row);
        return ['id' => $r['id'], 'is_new' => $r['is_new'], 'matched' => (bool)$existing];
    }

    /**
     * ⚠️ رفع مشکل «تماس‌های گیرکرده روی در حال زنگ خوردن»:
     *
     * وب‌هوک `ringing` وضعیت را موقتاً `ringing` می‌گذارد به امید این‌که
     * `hangup` بعداً آن را نهایی کند. اگر `hangup` هرگز نرسد (قطعی شبکه،
     * ری‌استارت سرور، و...)، آن تماس تا ابد با وضعیت اشتباه در دیتابیس
     * می‌ماند - حتی اگر واقعاً مدت‌ها پیش تمام شده باشد.
     *
     * این متد صریحاً `Call::findStaleRingingCalls()` را کوئری می‌زند و
     * برای هرکدام با `GET /call?call_id=cuid` وضعیت واقعی و نهایی را از
     * تلفنچی می‌گیرد و از همان مسیر معمولی sync (`processCall`) کامل
     * به‌روزرسانی می‌کند (status/مدت/صدا/همه‌چیز) - دقیقاً همان چیزی که
     * اگر hangup به‌موقع رسیده بود اتفاق می‌افتاد.
     *
     * ⚠️ باگ جدیِ نسخه‌ی قبلی (رفع‌شده): این متد بدون هیچ فاصله‌ای بین
     * درخواست‌ها، تا ۳۰-۱۰۰ درخواست GET /call را پشت‌سرهم به تلفنچی
     * می‌زد. این حجم burst سیستم ضدسوءاستفاده/rate-limit خودِ تلفنچی را
     * فعال کرد: هم پاسخ‌های /call به HTML/خطا (نه JSON) تبدیل شدند، هم
     * endpointهای کاملاً بی‌ربط دیگر (مثل /services) به‌طور موقت ۴۰۱
     * گرفتند - یعنی توکن واقعاً خراب نشده بود، مسدودسازی موقت بود. الان:
     * (۱) بین هر درخواست یک مکث کوتاه هست، (۲) اگر ۳ خطای پشت‌سرهم رخ دهد
     * (نشانه‌ی rate-limit/مسدودی)، بقیه‌ی این اجرا را متوقف می‌کند و به
     * اجرای بعدی کرون واگذار می‌کند - به‌جای اصرار و بدتر کردن وضعیت.
     *
     * @param int $limit
     * @param int $delayMicroseconds  فاصله بین هر درخواست (پیش‌فرض ۳۰۰ میلی‌ثانیه)
     * @return array {checked, fixed, stopped_early}
     */
    public function reconcileStaleRingingCalls($limit = 15, $delayMicroseconds = 300000)
    {
        $stale = Call::findStaleRingingCalls(20, $limit);
        $checked = 0;
        $fixed = 0;
        $consecutiveFailures = 0;
        $stoppedEarly = false;

        foreach ($stale as $call) {
            $checked++;
            $succeeded = false;
            try {
                $result = $this->reconcileSingleCallByCuid($call['cuid']);
                if ($result) $fixed++;
                $succeeded = true;
            } catch (\Throwable $e) {
                Logger::error('reconcileStaleRingingCalls: failed for cuid=' . $call['cuid'] . ': ' . $e->getMessage());
            } finally {
                Call::markStatusReconcileAttempt($call['id']);
            }

            $consecutiveFailures = $succeeded ? 0 : ($consecutiveFailures + 1);
            if ($consecutiveFailures >= 3) {
                Logger::error('reconcileStaleRingingCalls: ۳ خطای پشت‌سرهم - احتمال rate-limit تلفنچی، توقف زودهنگام این اجرا', ['checked_so_far' => $checked]);
                $stoppedEarly = true;
                break;
            }

            // فاصله‌ی کوتاه بین درخواست‌ها تا هیچ‌وقت burst نزنیم
            if ($delayMicroseconds > 0) usleep($delayMicroseconds);
        }

        if ($checked > 0) {
            Logger::info('reconcileStaleRingingCalls completed', ['checked' => $checked, 'fixed' => $fixed, 'stopped_early' => $stoppedEarly]);
        }

        return ['checked' => $checked, 'fixed' => $fixed, 'stopped_early' => $stoppedEarly];
    }

    /**
     * اصلاح وضعیت یک تماس مشخص از روی cuid (هم برای بازه‌ی بالا، هم برای
     * دکمه‌ی دستی «بررسی مجدد وضعیت» در جزئیات تماس استفاده می‌شود).
     * از GET /call استفاده می‌کند (نه لیست) چون فقط یک تماس مشخص لازم است.
     *
     * @param string $cuid
     * @return bool  true اگر وضعیت واقعاً از ringing خارج شد
     */
    /**
     * اصلاح وضعیت یک تماس مشخص از روی cuid (هم برای بازه‌ی بالا، هم برای
     * دکمه‌ی دستی «بررسی مجدد وضعیت»/«بارگذاری مجدد صدا» در جزئیات تماس
     * استفاده می‌شود).
     *
     * ⚠️ رفع باگ کشف‌شده: برای بعضی تماس‌های خاص (مشاهده‌شده با یک cuid
     * مشخص که مدت‌ها - حتی بیش از ۱۲ ساعت - است روی همین خطا گیر کرده)،
     * خودِ endpoint تکی `GET /call?call_id=...` سمت تلفنچی مدام با
     * httpCode=500 و بدنه‌ی غیر-JSON جواب می‌دهد؛ انگار خودِ تلفنچی برای
     * آن رکورد مشخص روی این مسیر مشکل دارد (نه یک rate-limit موقت، چون
     * حتی بعد از فاصله‌های چند دقیقه‌ای هم تکرار می‌شود). به‌جای تسلیم
     * شدن، الان یک fallback دارد: اگر GET /call تکی شکست خورد، با
     * `GET /calls` (لیست) ولی این‌بار با بازه‌ی تاریخ *کوچک و دقیق*
     * (started_at ± ۱۵ دقیقه، هم date_from هم date_to) دوباره امتحان
     * می‌کند - چون این بازه‌ی محدود، فارغ از تعداد کل تماس‌های امروز،
     * معمولاً همان چند رکورد اطراف را برمی‌گرداند.
     *
     * @param string $cuid
     * @return bool  true اگر وضعیت واقعاً از ringing خارج شد
     */
    public function reconcileSingleCallByCuid($cuid)
    {
        $api = new TelefonchyAPI();
        $before = Call::findByCuid($cuid);
        $wasRinging = $before && $before['status'] === 'ringing';

        $info = $api->getCallInfo($cuid);
        $callData = ($info['success'] && !empty($info['data'])) ? $info['data'] : null;

        // تلفنچی صریحاً گفته این تماس وجود ندارد (404) → دیگر هر اجرا تکرار نشود
        if (!$callData && $before && (int)($info['api_code'] ?? 0) === 404) {
            Call::giveUpStatusReconcile($before['id']);
            Logger::info('reconcileSingleCallByCuid: Telefonchy 404, marked as given up', ['cuid' => $cuid]);
            return false;
        }

        if (!$callData && $before && !empty($before['started_at'])) {
            Logger::info('reconcileSingleCallByCuid: GET /call تکی شکست خورد، تلاش با لیست بازه‌ی محدود', ['cuid' => $cuid]);
            $callData = $this->findCallInNarrowDateWindow($cuid, $before['started_at']);
        }

        if (!$callData) return false;

        $result = $this->processCall($callData);
        if (!$result['id']) return false;

        $after = Call::findByCuid($cuid);
        return $wasRinging && $after && $after['status'] !== 'ringing';
    }

    /**
     * fallback برای reconcileSingleCallByCuid: جست‌وجوی یک تماس مشخص با
     * `GET /calls` در یک بازه‌ی تاریخ کوچک و دقیق (نه صفحه‌ی اول لیست کلی
     * که برای تماس‌های قدیمی‌تر از چند ساعت اصلاً کار نمی‌کند، چون
     * ده‌ها/صدها تماس جدیدتر جلویش را می‌گیرند).
     *
     * @param string $cuid
     * @param string $startedAt
     * @return array|null
     */
    private function findCallInNarrowDateWindow($cuid, $startedAt)
    {
        try {
            $fromG = date('Y-m-d H:i', strtotime($startedAt . ' -15 minutes'));
            $toG = date('Y-m-d H:i', strtotime($startedAt . ' +15 minutes'));
            $jFrom = Jalali::fromGregorian($fromG, 'Y/m/d H:i');
            $jTo = Jalali::fromGregorian($toG, 'Y/m/d H:i');
            if (!$jFrom) return null;

            $response = $this->api->getCalls(['date_from' => $jFrom, 'date_to' => $jTo, 'sort' => 'created_at DESC', 'page' => 1]);
            if (!$response['success']) return null;

            $outer = $response['data'] ?? null;
            $calls = is_array($outer) ? ($outer['data'] ?? []) : [];

            foreach (array_filter($calls, 'is_array') as $callData) {
                $rowCuid = (string)($callData['cuid'] ?? $callData['unique_id'] ?? $callData['id'] ?? '');
                if ($rowCuid !== '' && $rowCuid === (string)$cuid) {
                    return $callData;
                }
            }
        } catch (\Throwable $e) {
            Logger::error('findCallInNarrowDateWindow failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * ⚠️ رفع مشکل «صدا دریافت نمی‌شود» / «فایل صوتی یافت نشد»:
     *
     * ریشه‌ی مشکل: در لحظه‌ی وب‌هوک `hangup`، تلاش برای گرفتن file_id از
     * طریق enrichCallViaListLookup() فقط **یک‌بار** و به‌صورت best-effort
     * انجام می‌شود (نگاه کنید به ingestHangupEvent). اما تلفنچی معمولاً
     * فایل صوتی را با چند ثانیه تا چند دقیقه تأخیر نسبت به لحظه‌ی قطع تماس
     * پردازش/آپلود می‌کند - یعنی درست همان لحظه که ما GET /calls را
     * می‌زنیم، ممکن است فایل هنوز در پاسخ API نباشد. نتیجه: file_id برای
     * همیشه NULL می‌ماند و اپراتور موقع پخش صدا با خطای «تماس/فایل یافت
     * نشد» مواجه می‌شود، چون آن تلاش یک‌باره از دست رفته و هیچ retry‌یی
     * برایش وجود نداشت.
     *
     * ⚠️ چرا cron_sync عادی (syncIncremental) این را خودش رفع نمی‌کند:
     * آن تابع با اولین cuid تکراری که ببیند کل حلقه را متوقف می‌کند
     * (stopOnExisting) - و چون وب‌هوک تقریباً همیشه قبل از رسیدن کرون
     * دوره‌ای رکورد را ساخته، همان اولین رکورد (جدیدترین تماس) معمولاً
     * «تکراری» تشخیص داده می‌شود و کل sync همان‌جا متوقف می‌شود؛ تماس‌های
     * قدیمی‌تر که هنوز file_id ندارند اصلاً دوباره بررسی نمی‌شوند.
     *
     * این متد کاملاً مستقل از آن منطق، صریحاً روی «تماس‌های یتیمِ صدا»
     * (Call::findMissingRecordings) کوئری می‌زند و برای هرکدام دوباره
     * enrichCallViaListLookup را با پنجره‌ی زمانی امن (۱۰ دقیقه قبل تا الان)
     * امتحان می‌کند. هر بار (چه موفق چه ناموفق) شمارنده‌ی تلاش را بالا
     * می‌برد تا بعد از سقف مشخص (پیش‌فرض ۶ تلاش ≈ نیم ساعت با کرون هر
     * ۵ دقیقه) دیگر دوباره امتحان نشود (تماس‌هایی که واقعاً ضبط نشده‌اند یا
     * فایلشان روی تلفنچی موجود نیست).
     *
     * فراخوانی: از cron_sync.php، مستقل و بعد از syncIncremental().
     *
     * @param int $limit
     * @return array {checked, recovered}
     */
    public function backfillMissingRecordings($limit = 30, $delayMicroseconds = 250000)
    {
        $orphans = Call::findMissingRecordings($limit);
        $checked = 0;
        $recovered = 0;
        $consecutiveFailures = 0;

        foreach ($orphans as $orphan) {
            $checked++;
            $succeeded = false;
            try {
                $ok = $this->enrichCallViaListLookup($orphan['cuid'], $orphan['started_at']);
                if ($ok) $recovered++;
                $succeeded = true;
            } catch (\Throwable $e) {
                Logger::error('backfillMissingRecordings: enrichment failed for cuid=' . $orphan['cuid'] . ': ' . $e->getMessage());
            } finally {
                // ⚠️ شمارنده در هر صورت (موفق یا ناموفق) بالا می‌رود؛ اگر
                // موفق شد دیگر شرط file_id IS NULL در findMissingRecordings
                // آن را حذف می‌کند، پس رسیدن به سقف تلاش فقط برای
                // تماس‌هایی اتفاق می‌افتد که واقعاً پیدا نمی‌شوند.
                Call::markRecordingAttempt($orphan['id']);
            }

            // ⚠️ همان محافظتِ reconcileStaleRingingCalls در برابر burst:
            // فاصله بین درخواست‌ها + توقف زودهنگام روی خطاهای پشت‌سرهم
            $consecutiveFailures = $succeeded ? 0 : ($consecutiveFailures + 1);
            if ($consecutiveFailures >= 3) {
                Logger::error('backfillMissingRecordings: ۳ خطای پشت‌سرهم - توقف زودهنگام این اجرا');
                break;
            }
            if ($delayMicroseconds > 0) usleep($delayMicroseconds);
        }

        if ($checked > 0) {
            Logger::info('backfillMissingRecordings completed', ['checked' => $checked, 'recovered' => $recovered]);
        }

        return ['checked' => $checked, 'recovered' => $recovered];
    }

    /**
     * نرمال‌سازی تاریخ webhook (فرمت Y-m-d H:i:s میلادی طبق مستندات)
     *
     * @param string|null $date
     * @return string|null
     */
    private function normalizeWebhookDate($date)
    {
        if (empty($date)) return null;
        $ts = strtotime($date);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * جایگزین قابل‌اعتماد برای api->getCallInfo() که همیشه با ۵۰۰ شکست
     * می‌خورد (کشف‌شده با لاگ‌های واقعی - نه گاه‌به‌گاه، بلکه ۱۰۰٪ از
     * موارد). این متد یک بازه‌ی زمانی باریک (۱۰ دقیقه قبل از شروع تماس)
     * را از همان endpoint لیست (GET /calls) که در سینک دوره‌ای همیشه
     * موفق بوده می‌گیرد، ردیف متناظر با این cuid را پیدا می‌کند، و با
     * همان `processCall()` (منطق کامل و امن پردازش، شامل file_id) آن را
     * upsert می‌کند.
     *
     * @param string      $cuid
     * @param string|null $startedAt  (Y-m-d H:i:s میلادی) برای محدود کردن بازه‌ی جست‌وجو
     * @return bool  آیا پیدا و enrich شد
     */
    public function enrichCallViaListLookup($cuid, $startedAt)
    {
        try {
            // سقف سراسری: حداکثر یک درخواست /calls در هر ۶۰ ثانیه (توافق با تلفنچی)
            $lockFile = ROOT_PATH . '/logs/.telefonchy_calls_last';
            $last = is_file($lockFile) ? (int)@file_get_contents($lockFile) : 0;
            if ($last > 0 && (time() - $last) < 60) {
                return false; // دفعه‌ی بعد (کرون) دوباره امتحان می‌شود
            }
            @file_put_contents($lockFile, (string)time(), LOCK_EX);

            // پنجره‌ی محدود دو طرفه حول زمان تماس تا تماس حتماً در صفحه‌ی اول باشد
            $baseTs = strtotime($startedAt ?: 'now') ?: time();
            $jFrom = Jalali::fromGregorian(date('Y-m-d H:i', $baseTs - 120), 'Y/m/d H:i');
            $jTo   = Jalali::fromGregorian(date('Y-m-d H:i', $baseTs + 3600), 'Y/m/d H:i');
            if (!$jFrom) return false;

            $response = $this->api->getCalls(['date_from' => $jFrom, 'date_to' => $jTo, 'sort' => 'created_at ASC', 'page' => 1]);
            if (!$response['success']) {
                Logger::error('enrichCallViaListLookup: getCalls failed: ' . ($response['message'] ?? 'نامشخص'));
                return false;
            }

            // همان ساختار تودرتوی واقعی که در runSync() هم استفاده می‌شود
            $outer = $response['data'] ?? null;
            $calls = is_array($outer) ? ($outer['data'] ?? []) : [];
            $calls = array_filter($calls, 'is_array');

            foreach ($calls as $callData) {
                // ⚠️ فیلد شناسه ممکن است در پاسخ لیست با نام‌های مختلفی
                // بیاید - هر سه‌تا را چک می‌کنیم تا مطمئن باشیم.
                $rowCuid = (string)($callData['cuid'] ?? $callData['unique_id'] ?? $callData['id'] ?? '');
                if ($rowCuid !== '' && $rowCuid === (string)$cuid) {
                    $this->processCall($callData);
                    return true;
                }
            }

            // ⚠️ پیدا نشد - برای دیباگ دقیق (بدون نیاز به دسترسی سرور)،
            // cuidهای واقعاً برگشته را هم در فایل لاگ هم در پنل ثبت
            // می‌کنیم تا اگر نام فیلد یا بازه‌ی زمانی اشتباه بود، مستقیم
            // از پنل «📋 لاگ تعاملات با تلفنچی» قابل تشخیص باشد.
            $foundCuids = array_values(array_map(
                function ($c) { return $c['cuid'] ?? $c['unique_id'] ?? $c['id'] ?? '?'; },
                $calls
            ));
            Logger::error('enrichCallViaListLookup: cuid در نتایج لیست پیدا نشد', [
                'target_cuid' => $cuid,
                'jalali_date_from' => $jFrom,
                'found_count' => count($calls),
                'found_cuids' => $foundCuids,
            ]);
            try {
                TelefonchyApiLog::log([
                    'direction'        => 'outbound',
                    'endpoint'         => '/calls (enrichCallViaListLookup - cuid پیدا نشد)',
                    'success'          => false,
                    'error_message'    => 'هدف: ' . $cuid . ' | تعداد نتایج: ' . count($calls),
                    'response_preview' => 'cuidهای پیداشده: ' . implode(', ', $foundCuids),
                ]);
            } catch (\Throwable $ignore) {}
        } catch (\Throwable $e) {
            Logger::error('enrichCallViaListLookup failed (non-blocking): ' . $e->getMessage());
        }
        return false;
    }

    /**
     * پردازش یک تماس تلفنچی (ساختار طبق مستندات رسمی GET /calls) و درج/به‌روزرسانی
     *
     * @param array $callData
     * @return array {id, is_new, contact_matched, already_existed_before_sync}
     */
    private function processCall(array $callData)
    {
        $cuid = $callData['cuid'] ?? $callData['id'] ?? null;
        if (empty($cuid)) {
            return ['id' => null, 'is_new' => false, 'contact_matched' => false, 'already_existed_before_sync' => false];
        }
        $cuid = (string)$cuid;

        $existingBefore = Call::findByCuid($cuid);

        $type = $this->mapCallType($callData['type'] ?? null);
        $status = $this->mapCallStatus($callData['status'] ?? null, $callData);

        // call_source/call_dest رشته‌ی ساده هستند طبق مستندات رسمی
        $source = is_array($callData['call_source'] ?? null) ? ($callData['call_source']['number'] ?? null) : ($callData['call_source'] ?? null);
        $dest   = is_array($callData['call_dest'] ?? null) ? ($callData['call_dest']['number'] ?? null) : ($callData['call_dest'] ?? null);

        // تلفنچی خودش started_at میلادی و jalali_started_at شمسی می‌دهد - نیازی به تبدیل نیست
        $startedAt = $callData['started_at'] ?? $callData['created_at'] ?? null;
        if ($startedAt) {
            // اگر فرمت ISO با T و Z بود (مثل created_at: "2024-12-10T12:00:00.000000Z")، نرمال‌سازی شود
            $ts = strtotime($startedAt);
            if ($ts) $startedAt = date('Y-m-d H:i:s', $ts);
        }
        $jalaliStartedAt = $callData['jalali_started_at'] ?? ($startedAt ? Jalali::fromGregorian($startedAt, 'Y/m/d H:i:s') : null);

        $timeTalk = (int)($callData['time_talk'] ?? 0);
        $timeWait = (int)($callData['time_wait'] ?? 0);

        $fileRecord = $callData['file_record'] ?? null;
        $fileId = $callData['file_id'] ?? null;

        $extenNumber = $callData['exten']['number'] ?? null;
        $extenName   = $callData['exten']['name'] ?? null;
        $trunk       = $callData['trunk'] ?? null;

        $data = [
            'cuid'              => $cuid,
            'call_source'       => $source,
            'call_dest'         => $dest,
            'type'              => $type,
            'status'            => $status,
            'time_wait'         => $timeWait,
            'time_talk'         => $timeTalk,
            'started_at'        => $startedAt,
            'jalali_started_at' => $jalaliStartedAt,
            'trunk'             => $trunk,
            'exten_name'        => $extenName,
            'exten_number'      => $extenNumber,
            'file_record'       => $fileRecord,
            'file_id'           => $fileId,
        ];

        // اگر تلفنچی خودش یادداشتی برای تماس ثبت کرده (note) و ما هنوز یادداشت محلی نداریم
        if (!empty($callData['note']) && empty($existingBefore['notes'])) {
            $data['notes'] = $callData['note'];
        }

        $result = Call::upsert($data);
        $callDbId = $result['id'];
        $isNew = $result['is_new'];

        $contactMatched = false;
        if ($callDbId) {
            $externalNumber = ($type === 'outbound') ? $dest : $source;
            $contactMatched = $this->attemptContactAndLeadLinking($callDbId, $type, $externalNumber, $existingBefore);
        }

        return [
            'id'                          => $callDbId,
            'is_new'                      => $isNew,
            'contact_matched'             => $contactMatched,
            'already_existed_before_sync' => (bool)$existingBefore,
            'started_at'                  => $startedAt ?? null,
        ];
    }

    /**
     * نگاشت type تلفنچی → نوع داخلی
     * طبق مستندات رسمی فعلی فقط incoming|outgoing مستند شده؛ local را هم
     * برای سازگاری با نسخه‌های قدیمی‌تر API نگه می‌داریم.
     *
     * @param string|null $type
     * @return string inbound|outbound|internal
     */
    private function mapCallType($type)
    {
        $type = strtolower((string)$type);
        if ($type === 'incoming') return 'inbound';
        if ($type === 'outgoing') return 'outbound';
        if ($type === 'local') return 'internal';
        return 'inbound';
    }

    /**
     * نگاشت status تلفنچی → وضعیت داخلی
     * مقادیر دیده‌شده: ANSWERED. برای فیلتر ورودی مستندات نمونه‌ی "awnser"
     * (تایپوی خودشان) و "no-answer" را هم ذکر کرده؛ همه‌ی حالت‌های محتمل
     * (فاصله/خط‌تیره/آندرلاین/بزرگ‌وکوچک) نرمال‌سازی می‌شوند.
     *
     * @param string|null $status
     * @param array       $callData
     * @return string answered|no_answer|busy|failed
     */
    private function mapCallStatus($status, array $callData = [])
    {
        $normalized = strtoupper(trim(str_replace(['-', '_'], ' ', (string)$status)));

        if (in_array($normalized, ['ANSWERED', 'AWNSER', 'ANSWER'], true)) return 'answered';
        if ($normalized === 'BUSY') return 'busy';
        if (in_array($normalized, ['NO ANSWER', 'NOANSWER'], true)) return 'no_answer';
        if ($normalized === 'CONGESTION') return 'failed';

        if ((int)($callData['time_talk'] ?? 0) > 0) return 'answered';
        return 'no_answer';
    }
}
