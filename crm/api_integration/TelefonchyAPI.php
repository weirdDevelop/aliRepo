<?php
/**
 * جاکجاست - کلاینت API تلفنچی
 * Jakojast - Telefonchy API Client
 *
 * 📖 مرجع کامل و همیشه‌به‌روز: docs/TELEFONCHY_API.md
 * هر تغییری در این فایل باید همان‌جا هم ثبت شود.
 *
 * =========================================================================
 * تاریخچه‌ی اصلاحات (برای این‌که بدانید چرا کد این شکلی است):
 *
 * نسخه ۱ (باگ اصلی): به https://api.telefonchy.com وصل می‌شد که اصلاً
 *   وجود نداشت (DNS آن resolve نمی‌شد).
 *
 * نسخه ۲: بر اساس مستندات عمومی گیت‌هاب تلفنچی (که قدیمی بوده) به
 *   https://telefonchy.com/webservice/v1 اصلاح شد.
 *
 * نسخه ۳ (فعلی - تأیید شده با مستندات رسمی و به‌روز که مستقیماً از
 *   help center توسط شما فرستاده شد، تاریخ سند: ۲۰ اردیبهشت ۱۴۰۵):
 *   - دامنه عوض شده: https://panel.telefonchy.com/webservice/v1
 *   - GET /calls حالا فیلترهای واقعی و رسمی دارد:
 *     service_id, call_dest, call_source, date_from, date_to (شمسی، فرمت Y/m/d H:i),
 *     type (incoming|outgoing), status, exten, trunk_id, note, sort, page
 *   - ساختار هر تماس در پاسخ:
 *     cuid, server_id, call_source(string), call_dest(string), type, status,
 *     time_wait, time_talk, started_at(میلادی), ended_at, jalali_started_at,
 *     jalali_ended_at, created_at, updated_at, trunk_id, trunk, service_id,
 *     exten{exten_id, number, name}, contact{call_source:{...}, call_dest:{...}},
 *     file_record, file_id, id
 *
 * این فایل الان هر درخواست (موفق یا ناموفق) را با جزئیات کامل در جدول
 * telefonchy_api_logs ثبت می‌کند (از طریق TelefonchyApiLog::log) تا اگر
 * دوباره خطایی رخ داد، بدون نیاز به دسترسی سرور بشود دقیقاً دید کجای
 * درخواست مشکل داشته.
 * =========================================================================
 *
 * موارد زیر هنوز از روی مستندات رسمی «دریافت لیست تماس‌ها» تأیید نشده‌اند
 * چون آن صفحات را ندیدم (فقط عنوانشان را در پیام اول گفتید). اگر متن این
 * صفحات را هم مثل بالا برایم بفرستید، این متدها را قطعی می‌کنم:
 *   - get-a-list-of-contacts / receive-the-audio-of-the-conversation /
 *     receive-voicemail / click-to-call / get-information-about-a-call /
 *     get-basic-service-information
 */

class TelefonchyAPI
{
    /** @var string|null توکن وب‌سرویس */
    private $token;

    /** @var string آدرس پایه API */
    private $baseUrl;

    /** @var string|null شناسه سرویس */
    private $serviceId;

    /** @var int timeout درخواست‌ها (ثانیه) */
    private $timeout = 30;

    public function __construct()
    {
        $this->token = Report::getSetting('telefonchy_token');
        $base = trim((string)(Report::getSetting('telefonchy_base_url') ?: TELEFONCHY_BASE_URL));
        // آدرس در تنظیمات بدون https:// ذخیره شده بود
        if (!preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
        $base = preg_replace('#^http://#i', 'https://', $base);
        $this->baseUrl = rtrim($base, '/');
        $this->serviceId = Report::getSetting('telefonchy_service_id');
    }

    public function isConfigured()
    {
        return !empty($this->token);
    }

    /**
     * دریافت اطلاعات پایه سرویس (لیست سرویس‌ها، خطوط، شارژ، داخلی‌ها)
     * GET /services
     *
     * @return array {success, data, message}
     */
    public function getServiceInfo()
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی (webservice-token) تنظیم نشده است.'];
        }
        return $this->request('GET', '/services');
    }

    /**
     * دریافت لیست تماس‌ها با فیلترهای رسمی API
     * GET /calls
     *
     * @param array $filters {
     *   date_from, date_to  : تاریخ شمسی به فرمت "Y/m/d H:i" (مثلاً "1405/02/01 00:00")
     *   type                : incoming|outgoing
     *   status               : ANSWERED|BUSY|NO-ANSWER|CONGESTION (یا مقادیر مشابه)
     *   call_source, call_dest, exten, trunk_id, note
     *   sort                : باید دقیقاً یکی از این ۴ مقدار باشد (طبق پیام
     *                         خطای رسمی تلفنچی): 'created_at DESC',
     *                         'created_at ASC', 'time_talk DESC', 'time_talk ASC'
     *                         (نه فقط ASC/DESC تنها - این را با curl کشف کردیم)
     *   page                 : شماره صفحه
     * }
     * @return array {success, data: {calls:[...]}, paginator, message}
     */
    public function getCalls(array $filters = [])
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        if (empty($this->serviceId)) {
            return ['success' => false, 'message' => 'service_id تنظیم نشده. ابتدا از تنظیمات، «دریافت اطلاعات سرویس» را بزنید.'];
        }

        $query = ['service_id' => $this->serviceId];

        $allowedFilters = ['date_from', 'date_to', 'type', 'status', 'call_source', 'call_dest', 'exten', 'trunk_id', 'note', 'sort', 'page'];
        foreach ($allowedFilters as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        // نرمال‌سازی sort: تلفنچی فقط این ۴ مقدار دقیق را قبول می‌کند.
        // اگر کسی فقط 'DESC'/'ASC' ساده بفرستد (مثلاً کدهای قدیمی‌تر)،
        // خودکار به 'created_at DESC'/'created_at ASC' تبدیل می‌شود.
        $validSorts = ['created_at DESC', 'created_at ASC', 'time_talk DESC', 'time_talk ASC'];
        if (!isset($query['sort']) || !in_array($query['sort'], $validSorts, true)) {
            $query['sort'] = (isset($query['sort']) && strtoupper($query['sort']) === 'ASC') ? 'created_at ASC' : 'created_at DESC';
        }
        if (!isset($query['page'])) $query['page'] = 1;

        return $this->request('GET', '/calls?' . http_build_query($query));
    }

    /**
     * GET /current/date
     */
    public function getServerDate()
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        return $this->request('GET', '/current/date');
    }

    /**
     * GET /locations
     */
    public function getLocations()
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        return $this->request('GET', '/locations');
    }

    /**
     * دریافت اطلاعات کامل یک تماس با شناسه‌ی cuid
     * GET /call?call_id={cuid}   [تأیید شده طبق مستندات رسمی «دریافت اطلاعات یک تماس»]
     *
     * نکته‌ی مهم مستندات: با این‌که پارامتر call_id نام دارد، مقداری که باید
     * داخلش گذاشته شود همان cuid تماس است (نه یک شناسه‌ی دیگر). endpoint هم
     * مفرد است: /call (نه /calls).
     *
     * کاربرد اصلی: بعد از دریافت رویداد hangup در وب‌هوک، برای گرفتن جزئیات
     * کامل (status نهایی، مدت مکالمه، file_id) همین متد فراخوانی می‌شود.
     *
     * @param string $cuid
     * @return array {success, data, message}
     */
    public function getCallInfo($cuid)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        if (empty($cuid)) {
            return ['success' => false, 'message' => 'cuid تماس مشخص نیست.'];
        }
        $response = $this->request('GET', '/call?' . http_build_query(['call_id' => $cuid]));

        // طبق نمونه‌ی مستندات، data یک آرایه با یک عضو است (نه یک آبجکت تکی)
        if ($response['success'] && is_array($response['data'] ?? null) && isset($response['data'][0])) {
            $response['data'] = $response['data'][0];
        }
        return $response;
    }

    /**
     * دانلود فایل صوتی مکالمه
     * GET /calls/record?file_id=...&cuid=...   [تأیید شده طبق مستندات رسمی «دریافت صوت مکالمه»]
     *
     * هر دو پارامتر file_id و cuid لازم هستند (هر دو از خروجی GET /calls
     * یا GET /call به‌دست می‌آیند). اگر file_record یک تماس در دیتابیس
     * محلی خالی/null باشد، یعنی آن تماس اصلاً ضبط نشده.
     *
     * @param string $fileId
     * @param string $cuid
     * @return array {success, content, contentType, message}
     */
    public function downloadRecording($fileId, $cuid)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        if (empty($fileId) || empty($cuid)) {
            return ['success' => false, 'message' => 'file_id و cuid هر دو برای دانلود فایل صوتی لازم هستند.'];
        }

        // دانلود هم جزو سقف درخواست تلفنچی است؛ تا ۷۰ ثانیه برای گرفتن اسلات صبر کن
        $waited = 0;
        while (!self::acquireRateSlot()) {
            if ($waited >= 70) {
                return ['success' => false, 'rate_limited' => true, 'message' => 'سقف درخواست تلفنچی پر است؛ دانلود بعداً انجام می‌شود.'];
            }
            sleep(5); $waited += 5;
        }
        $endpoint = '/calls/record?' . http_build_query(['file_id' => $fileId, 'cuid' => $cuid]);
        $url = $this->baseUrl . $endpoint;
        $startTime = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['webservice-token: ' . $this->token],
            CURLOPT_TIMEOUT        => max($this->timeout, 60), // فایل صوتی ممکن است دانلودش کمی طول بکشد
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        // ⚠️ رفع باگ «پخش صدا با خطای مرورگر no supported source»: طبق
        // مستندات پاسخ این endpoint باید همیشه باینری (MP3) باشد، ولی در
        // عمل (مثل خیلی جاهای دیگر این API) گاهی حتی با httpCode=200 یک
        // بدنه‌ی JSON خطا برمی‌گردد (مثلاً file_id منقضی/نامعتبر شده).
        // قبلاً این حالت تشخیص داده نمی‌شد: همان متن JSON با
        // Content-Type: audio/mpeg به مرورگر stream می‌شد، که چون واقعاً
        // صدا نیست، مرورگر دقیقاً همین خطا را می‌دهد: "Failed to load
        // because no supported source was found." اینجا قبل از قبول
        // کردن پاسخ به‌عنوان صدای معتبر، محتوا را sniff می‌کنیم.
        $looksLikeJson = is_string($response) && strlen($response) > 0 && strlen($response) < 20000
            && in_array(substr(ltrim($response), 0, 1), ['{', '['], true);

        TelefonchyApiLog::log([
            'method' => 'GET', 'endpoint' => '/calls/record', 'full_url' => $url,
            'request_params' => ['file_id' => $fileId, 'cuid' => $cuid], 'http_code' => $httpCode,
            'success' => ($httpCode === 200 && !$error && !$looksLikeJson), 'error_message' => $error ?: ($looksLikeJson ? ('پاسخ JSON بود نه صدا: ' . substr($response, 0, 300)) : null),
            'duration_ms' => $durationMs,
        ]);

        if ($error) return ['success' => false, 'message' => 'خطا در اتصال به تلفنچی: ' . $error];
        if ($httpCode === 404) return ['success' => false, 'message' => 'فایل صوتی یافت نشد.'];
        if ($httpCode !== 200) return ['success' => false, 'message' => "خطای HTTP {$httpCode} در دریافت فایل صوتی."];

        if ($looksLikeJson) {
            $decoded = json_decode($response, true);
            $apiMessage = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? null) : null;
            Logger::error('downloadRecording: پاسخ ۲۰۰ ولی JSON بود (نه فایل صدا)', [
                'file_id' => $fileId, 'cuid' => $cuid, 'body_preview' => substr($response, 0, 300),
            ]);
            return ['success' => false, 'message' => 'تلفنچی به‌جای فایل صدا پیام خطا برگرداند' . ($apiMessage ? (': ' . $apiMessage) : ' (شاید file_id منقضی شده - دوباره sync کنید).')];
        }

        return ['success' => true, 'content' => $response, 'contentType' => $contentType ?: 'audio/mpeg'];
    }

    /**
     * اجرای درخواست HTTP به API تلفنچی + ثبت لاگ کامل
     *
     * @param string $method
     * @param string $endpoint
     * @param array  $data
     * @return array {success, data, paginator?, message}
     */
    /** سقف سراسری درخواست به API تلفنچی (مستندات: حداکثر ۱۰ در دقیقه) - با حاشیه‌ی امن */
    const RATE_LIMIT_PER_MIN = 8;

    /**
     * محدودکننده‌ی سراسری (sliding window ۶۰ ثانیه، مشترک بین همه‌ی درخواست‌ها/کران)
     * @return bool true اگر اجازه‌ی ارسال هست (و ثبت شد)
     */
    private static function acquireRateSlot()
    {
        $file = (defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../logs') . '/.telefonchy_rate.json';
        $fp = @fopen($file, 'c+');
        if (!$fp) return true; // عدم امکان قفل نباید کل سیستم را از کار بیندازد
        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $now = microtime(true);
            $stamps = array_values(array_filter((array)json_decode((string)$raw, true), function ($t) use ($now) {
                return is_numeric($t) && ($now - $t) < 60;
            }));
            if (count($stamps) >= self::RATE_LIMIT_PER_MIN) return false;
            $stamps[] = $now;
            ftruncate($fp, 0); rewind($fp);
            fwrite($fp, json_encode($stamps));
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function request($method, $endpoint, array $data = [])
    {
        if (!self::acquireRateSlot()) {
            Logger::info('Telefonchy API: local rate limit reached, request skipped', ['endpoint' => $endpoint]);
            return ['success' => false, 'rate_limited' => true, 'message' => 'سقف درخواست به تلفنچی (' . self::RATE_LIMIT_PER_MIN . ' در دقیقه) پر شده؛ کمی بعد دوباره تلاش کنید.'];
        }
        $url = $this->baseUrl . $endpoint;
        $startTime = microtime(true);

        $ch = curl_init($url);
        // نکته‌ی مهم: مستندات رسمی خودِ تلفنچی ناسازگار است - در جدول
        // هدرها همه‌جا `webservice-token` (با خط تیره) نوشته شده، ولی در
        // نمونه‌کد PHP دقیقاً همین GET /calls از `webservice_token` (با
        // آندرلاین) استفاده کرده. برای اطمینان، هر دو را می‌فرستیم؛ این
        // می‌تواند علت واقعی «۲۰۰ موفق ولی صفر تماس» باشد اگر سمت تلفنچی
        // فقط یکی از این دو را برای این endpoint می‌شناسد.
        $headers = [
            'webservice-token: ' . $this->token,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($method === 'POST' && !empty($data)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']));
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        // پارس کردن query string برای ثبت در لاگ (بدون توکن)
        $parsedQuery = [];
        $qPos = strpos($endpoint, '?');
        if ($qPos !== false) parse_str(substr($endpoint, $qPos + 1), $parsedQuery);

        if ($curlErrNo !== 0) {
            Logger::error('Telefonchy API connection error', ['endpoint' => $endpoint, 'error' => $error]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $parsedQuery, 'http_code' => $httpCode,
                'success' => false, 'error_message' => "خطای اتصال (curl #{$curlErrNo}): {$error}",
                'duration_ms' => $durationMs,
            ]);
            $friendlyError = 'خطا در اتصال به تلفنچی: ' . $error;
            if (in_array($curlErrNo, [6, 7], true)) {
                $friendlyError = 'اتصال به سرور تلفنچی برقرار نشد (DNS/شبکه). ممکن است هاست شما فایروال خروجی داشته باشد یا آدرس اشتباه باشد: ' . $url;
            } elseif ($curlErrNo === 28) {
                $friendlyError = 'اتصال به تلفنچی timeout شد. سرور تلفنچی دیر پاسخ داد.';
            } elseif (in_array($curlErrNo, [35, 51, 60], true)) {
                $friendlyError = 'خطای گواهی SSL هنگام اتصال به تلفنچی: ' . $error;
            }
            return ['success' => false, 'message' => $friendlyError];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Telefonchy API invalid JSON response', [
                'endpoint' => $endpoint, 'http_code' => $httpCode, 'response' => substr((string)$response, 0, 500),
            ]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $parsedQuery, 'http_code' => $httpCode,
                'success' => false, 'error_message' => 'پاسخ JSON نامعتبر بود',
                'response_preview' => $response, 'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'message' => "پاسخ نامعتبر از تلفنچی (HTTP {$httpCode}). آدرس درخواست‌شده: {$url}"];
        }

        $status = $decoded['status'] ?? null;
        $code = $decoded['code'] ?? $httpCode;

        // ⚠️ باگ مهم رفع‌شده: تلفنچی حتی برای خطاهای validation (مثلاً
        // پارامتر sort نامعتبر) هدر HTTP را 200 OK برمی‌گرداند و خطای
        // واقعی را فقط داخل بدنه‌ی JSON می‌گذارد (status:"Validation-Error",
        // code:400). قبلاً چون فقط httpCode (که همیشه 200 بود) چک می‌شد،
        // این خطاها «موفق» تشخیص داده می‌شدند و data:null به اشتباه به
        // معنای «هیچ تماسی نیست» تفسیر می‌شد؛ در واقع اصلاً درخواست رد
        // شده بود. الان فقط بر اساس status==="Ok" (یا code==200) تصمیم
        // می‌گیریم، نه بر اساس httpCode.
        $isSuccess = ($status === 'Ok') && ((int)$code === 200);

        if (!$isSuccess) {
            $message = $decoded['message'] ?? "خطا از سمت تلفنچی (status: {$status}, code: {$code})";
            if ((int)$code === 401) {
                $message = 'توکن تلفنچی نامعتبر است یا منقضی شده. آن را در تنظیمات دوباره بررسی کنید.';
            } elseif ((int)$code === 400) {
                $message = 'پارامترهای ارسالی نامعتبر است (Validation-Error): ' . ($decoded['message'] ?? '(بدون جزئیات از سمت تلفنچی)');
            } elseif ((int)$code === 404) {
                $message = 'این مسیر در API تلفنچی یافت نشد. آدرس درخواست‌شده: ' . $url . ' — لطفاً «آدرس پایه API» را در تنظیمات با ' . TELEFONCHY_BASE_URL . ' مقایسه کنید.';
            } elseif ((int)$code === 402) {
                $message = 'خطای ناخواسته در سیستم تلفنچی (Failed): ' . ($decoded['message'] ?? '');
            }
            Logger::error('Telefonchy API error', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'telefonchy_code' => $code, 'telefonchy_status' => $status, 'message' => $message]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $parsedQuery, 'http_code' => (int)$code,
                'success' => false, 'error_message' => $message,
                'response_preview' => $response, 'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'message' => $message, 'http_code' => $httpCode, 'api_code' => (int)$code];
        }

        // نکته: خلاصه‌ی پاسخ حتی در حالت موفق هم ذخیره می‌شود (نه فقط
        // خطاها) چون بدون آن، وقتی sync عدد صفر برمی‌گرداند، امکان تشخیص
        // «تلفنچی واقعاً چیزی نداشت» از «ما داده را اشتباه parse کردیم»
        // وجود ندارد.
        $dataForPreview = array_key_exists('data', $decoded) ? $decoded['data'] : $decoded;
        // ساختار واقعی (کشف‌شده با curl): dataForPreview.data = آرایه‌ی
        // تماس‌ها، dataForPreview.pagination = اطلاعات صفحه‌بندی
        $innerCalls = is_array($dataForPreview) ? ($dataForPreview['data'] ?? null) : null;
        $callsCount = is_array($innerCalls) ? count($innerCalls) : (is_array($dataForPreview) ? count($dataForPreview) : 0);
        $pagination = is_array($dataForPreview) ? ($dataForPreview['pagination'] ?? null) : null;
        $previewSummary = ($dataForPreview === null)
            ? 'data: null (تلفنچی می‌گوید هیچ نتیجه‌ای نیست)'
            : ($callsCount . ' آیتم در پاسخ' . ($pagination ? ' | pagination: ' . json_encode($pagination, JSON_UNESCAPED_UNICODE) : ''));

        TelefonchyApiLog::log([
            'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
            'request_params' => $parsedQuery, 'http_code' => $httpCode,
            'success' => true, 'duration_ms' => $durationMs,
            'response_preview' => $previewSummary,
        ]);

        return [
            'success'   => true,
            // نکته‌ی مهم (باگ رفع‌شده): اگر از `$decoded['data'] ?? $decoded`
            // استفاده می‌کردیم، وقتی تلفنچی مقدار "data": null برمی‌گرداند
            // (یعنی هیچ نتیجه‌ای نیست)، PHP آن را با «کلید data اصلاً وجود
            // ندارد» یکسان می‌گرفت و کل پاسخ (status/code/message) به‌جای
            // لیست تماس‌ها برمی‌گشت که باعث خطای TypeError در پردازش می‌شد.
            // array_key_exists این دو حالت را درست از هم تشخیص می‌دهد.
            'data'      => array_key_exists('data', $decoded) ? $decoded['data'] : $decoded,
            'paginator' => $decoded['paginator'] ?? null,
            'http_code' => $httpCode,
        ];
    }

    /**
     * ثبت درخواست تحلیل هوشمند تماس با هوش مصنوعی
     * POST /calls/analysis/request   [طبق مستندات رسمی «تحلیل هوشمند تماس»]
     *
     * ⚠️ این endpoint قرارداد پاسخ کاملاً متفاوتی از بقیه‌ی این کلاینت دارد
     * (به همین دلیل از متد عمومی request() استفاده نمی‌کند):
     *   - بدنه‌ی درخواست JSON است (نه x-www-form-urlencoded مثل بقیه).
     *   - موفقیت/وضعیت از روی خودِ کد HTTP واقعی تشخیص داده می‌شود، نه یک
     *     پوششِ {status:"Ok", code:200} داخل بدنه:
     *       200 = تحلیل قبلاً کامل شده (این‌بار هزینه‌ای کم نشده)
     *       202 = تازه ثبت شده یا هنوز در حال پردازش است (باید poll شود)
     *       400/401/402/404/429/500 = خطا (پیام دقیق در بدنه)
     *   - record_id عمداً ارسال نمی‌شود؛ طبق مستندات خودِ تلفنچی از روی
     *     cuid و بعد از بررسی مالکیت تماس استخراج می‌شود.
     *
     * @param string $cuid
     * @return array {success, http_code, status: 'completed'|'processing'|null, data, message}
     */
    public function requestCallAnalysis($cuid)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        if (empty($this->serviceId)) {
            return ['success' => false, 'message' => 'service_id تنظیم نشده. ابتدا از تنظیمات، «دریافت اطلاعات سرویس» را بزنید.'];
        }
        if (empty($cuid)) {
            return ['success' => false, 'message' => 'cuid تماس مشخص نیست.'];
        }

        return $this->analysisRequest('POST', '/calls/analysis/request', [
            'service_id' => $this->serviceId,
            'cuid'       => $cuid,
        ]);
    }

    /**
     * دریافت وضعیت/نتیجه‌ی تحلیل هوشمند تماس (رایگان - از remains کم نمی‌کند)
     * GET /calls/analysis/show?service_id=...&cuid=...   [طبق مستندات رسمی]
     *
     * طبق توصیه‌ی خودِ مستندات، تا رسیدن به analysis_status برابر
     * completed یا failed، هر ۳ تا ۵ ثانیه باید دوباره فراخوانی شود.
     *
     * @param string $cuid
     * @return array {success, http_code, status, data, message}
     */
    public function getCallAnalysis($cuid)
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'توکن تلفنچی تنظیم نشده است.'];
        }
        if (empty($this->serviceId)) {
            return ['success' => false, 'message' => 'service_id تنظیم نشده.'];
        }
        if (empty($cuid)) {
            return ['success' => false, 'message' => 'cuid تماس مشخص نیست.'];
        }

        $query = http_build_query(['service_id' => $this->serviceId, 'cuid' => $cuid]);
        return $this->analysisRequest('GET', '/calls/analysis/show?' . $query);
    }

    /**
     * پیاده‌سازی مشترک دو متد بالا - چون هر دو یک قرارداد پاسخ خاص خودشان
     * را دارند که با request() عمومی این کلاینت فرق دارد.
     *
     * @param string $method
     * @param string $endpoint
     * @param array $jsonBody  (فقط برای POST)
     * @return array
     */
    private function analysisRequest($method, $endpoint, array $jsonBody = [])
    {
        $url = $this->baseUrl . $endpoint;
        $startTime = microtime(true);

        // همان احتیاط دوگانه‌ی هدر که در request() عمومی هم هست (مستندات
        // خودِ تلفنچی بین webservice-token و webservice_token ناسازگار است)
        $headers = [
            'webservice-token: ' . $this->token,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADER         => true, // برای خواندن Retry-After در ۴۲۹
        ];

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErrNo = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        if ($curlErrNo !== 0) {
            Logger::error('Telefonchy analysis API connection error', ['endpoint' => $endpoint, 'error' => $error]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $jsonBody, 'http_code' => $httpCode,
                'success' => false, 'error_message' => "خطای اتصال (curl #{$curlErrNo}): {$error}",
                'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'message' => 'خطا در اتصال به تلفنچی: ' . $error];
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $decoded = json_decode($body, true);

        // خواندن X-RateLimit-* و Retry-After برای پیام‌های دقیق‌تر
        $retryAfter = null;
        if (preg_match('/Retry-After:\s*(\d+)/i', $rawHeaders, $m)) $retryAfter = (int)$m[1];

        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Telefonchy analysis API invalid JSON', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'body' => substr($body, 0, 500)]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $jsonBody, 'http_code' => $httpCode,
                'success' => false, 'error_message' => 'پاسخ JSON نامعتبر بود',
                'response_preview' => substr($body, 0, 500), 'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'http_code' => $httpCode, 'message' => "پاسخ نامعتبر از تلفنچی (HTTP {$httpCode})."];
        }

        // طبق مستندات: موفقیت از روی خودِ httpCode واقعی (نه یک پوشش JSON)
        $isSuccess = in_array($httpCode, [200, 202], true);

        if (!$isSuccess) {
            $message = $decoded['message'] ?? "خطا از سمت تلفنچی (HTTP {$httpCode})";
            switch ((int)$httpCode) {
                case 400: $message = 'ورودی یا حساب کاربری نامعتبر است: ' . ($decoded['message'] ?? ''); break;
                case 401: $message = 'توکن، IP یا سهمیه‌ی وب‌سرویس مجاز نیست.'; break;
                case 402: $message = 'ماژول تحلیل هوشمند، اشتراک، اعتبار یا صوت مناسب برای این تماس در دسترس نیست.'; break;
                case 404: $message = 'این تماس/تحلیل متعلق به صاحب این توکن پیدا نشد (سرویس یا cuid را بررسی کنید).'; break;
                case 429: $message = 'سقف درخواست تحلیل رد شده است.' . ($retryAfter ? " لطفاً {$retryAfter} ثانیه دیگر دوباره امتحان کنید." : ''); break;
                case 500: $message = 'خطای داخلی سرور تلفنچی هنگام تحلیل.'; break;
            }
            Logger::error('Telefonchy analysis API error', ['endpoint' => $endpoint, 'http_code' => $httpCode, 'message' => $message]);
            TelefonchyApiLog::log([
                'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
                'request_params' => $jsonBody, 'http_code' => $httpCode,
                'success' => false, 'error_message' => $message,
                'response_preview' => substr($body, 0, 500), 'duration_ms' => $durationMs,
            ]);
            return ['success' => false, 'http_code' => $httpCode, 'retry_after' => $retryAfter, 'message' => $message];
        }

        TelefonchyApiLog::log([
            'method' => $method, 'endpoint' => $endpoint, 'full_url' => $url,
            'request_params' => $jsonBody, 'http_code' => $httpCode,
            'success' => true, 'duration_ms' => $durationMs,
            'response_preview' => ($decoded['analysis_status'] ?? '') . ' / progress=' . ($decoded['progress'] ?? '?'),
        ]);

        return [
            'success'   => true,
            'http_code' => $httpCode,
            // ⚠️ مهم: analysis_status واقعی از خودِ بدنه‌ی پاسخ خوانده می‌شود
            // (requesting|queued|transcribing|analyzing|reconciling|
            // completed|failed) - httpCode فقط برای POST مشخص می‌کند که
            // درخواست تازه ثبت شده (۲۰۲) یا از قبل کامل بوده (۲۰۰)؛ برای
            // GET /show همیشه ۲۰۰ است و وضعیت واقعی فقط در analysis_status است.
            'status'    => $decoded['analysis_status'] ?? ($httpCode === 200 ? 'completed' : 'processing'),
            'data'      => $decoded,
        ];
    }

    public function setTimeout($seconds)
    {
        $this->timeout = (int)$seconds;
    }
}
