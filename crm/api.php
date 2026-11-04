<?php
/**
 * جاکجاست - API مرکزی (REST)
 * Jakojast - Central API
 *
 * تمام endpointهای REST در این فایل قرار دارند.
 * مسیرها از طریق پارامتر ?path=... یا PATH_INFO تشخیص داده می‌شوند.
 *
 * نمونه فراخوانی:
 *   POST /api.php/auth/login        → لاگین
 *   GET  /api.php/auth/me           → اطلاعات کاربر جاری
 *   GET  /api.php/customers         → لیست مشتریان
 *   POST /api.php/customers         → افزودن مشتری
 *   ...
 *
 * این فایل در مراحل بعد گسترش می‌یابد تا تمام endpointها را پوشش دهد.
 */

require_once __DIR__ . '/config.php';
// آزاد کردن قفل session برای درخواست‌های GET (پایش ۵/۳۰ ثانیه‌ای پشت صفحات کند صف نکشد)
// سیستم یکپارچه: نشست با اعلان‌بان مشترک است؛ API چیزی در نشست نمی‌نویسد، پس قفل همیشه آزاد شود
if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' || (defined('CRM_BRIDGED') && CRM_BRIDGED)) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// تنظیم هدر JSON برای همه پاسخ‌ها (به جز موارد خاص)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    if (!(defined('CRM_BRIDGED') && CRM_BRIDGED)) {   // سیستم یکپارچه: فقط هم‌مبدأ (ورود با کوکی نشست)
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
    }
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

// پاسخ به درخواست‌های preflight CORS
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// استخراج مسیر (path) از URL
// روش ۱: از PATH_INFO (مثلاً /api.php/auth/login)
// روش ۲: از query string ?path=auth/login
// روش ۳: از REQUEST_URI بعد از /api.php
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // حذف query string
    $uri = strtok($uri, '?');
    // حذف پیشوند api.php
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    if (strpos($uri, $scriptName) === 0) {
        $pathInfo = substr($uri, strlen($scriptName));
    }
    // اگر از mod_rewrite با /api/ استفاده شده
    if ($pathInfo === '' && preg_match('#/api/(.+)$#', $uri, $m)) {
        $pathInfo = '/' . $m[1];
    }
}
if ($pathInfo === '' && isset($_GET['path'])) {
    $pathInfo = '/' . ltrim($_GET['path'], '/');
}

// نرمال‌سازی: حذف / ابتدا و انتها
$path = trim($pathInfo, '/');
$segments = $path === '' ? [] : explode('/', $path);

// متد HTTP
$method = Helpers::httpMethod();

// لاگ درخواست (فقط در DEBUG_MODE)
if (DEBUG_MODE) {
    Logger::debug('API request', [
        'method' => $method,
        'path'   => $path,
        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

// ============================================================
//   مسیریابی
// ============================================================

try {
    // اگر مسیر خالی است
    if (empty($segments)) {
        Response::success(['version' => '3.0.0', 'name' => 'Jakojast API'], 'API is running');
    }

    // ===== لوکاپ چت اعلان‌بان (تب «چت اینستاگرام/وب‌چت» در پروفایل مشتری/سرنخ) =====
    // GET /api.php/elanban/lookup?phone=09xxxxxxxxx
    // عمداً یک route ساده و مستقل: فقط یک متد GET، بدون CRUD، چون این
    // صرفاً یک پروکسی نازک روی ElanbanAPI::lookupByPhone است.
    if ($segments[0] === 'elanban' && ($segments[1] ?? '') === 'lookup') {
        Auth::requireLogin();
        $phone = trim($_GET['phone'] ?? '');
        if ($phone === '') {
            Response::validationError('شماره تلفن ارسال نشده است');
        }
        $api = new ElanbanAPI();
        $result = $api->lookupByPhone($phone);
        if (!$result['success']) {
            // خطای واقعی (توکن/اتصال/...) را باید مثل خطا نشان داد، نه مثل
            // «چتی نیست» - این دقیقاً همان اشتباهی بود که اول اشتباه تشخیص داده شد
            Response::success(['found' => false, 'configured' => $api->isConfigured(), 'error' => true, 'message' => $result['message']], 'اعلان‌بان در دسترس نیست');
        }
        Response::success(['found' => $result['found'], 'configured' => true, 'error' => false, 'data' => $result['data']], 'ok');
    }

    // ===== CSRF: ورود با کوکی نشست است؛ درخواست‌های تغییر فقط از صفحه‌های خود پنل (هدر سفارشی) =====
    if (defined('CRM_BRIDGED') && CRM_BRIDGED && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest'
        && !(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $method === 'GET' && in_array($segments[0], ['play-recording', 'export-calls'], true))) {
        Response::forbidden('درخواست نامعتبر (CSRF)');
    }

    // ===== دسترسی ماژول‌ها در API (قبلاً فقط در صفحه‌ها بود؛ حالا همه‌ی همکاران اعلان‌بان حساب CRM دارند) =====
    if (defined('CRM_BRIDGED') && CRM_BRIDGED && !in_array($segments[0], ['auth', 'dashboard', 'elanban'], true)) {
        $__u = Auth::currentUser();
        if ($__u && $__u['role'] !== 'admin' && is_array($__u['permissions'])) {
            $__map = [
                'customers' => 'customers', 'hosts' => 'hosts', 'host-properties' => 'hosts', 'host-settlements' => 'hosts',
                'host_leads' => 'host_leads', 'leads' => 'leads', 'site-inquiries' => 'leads', 'tickets' => 'tickets',
                'tasks' => 'tasks', 'projects' => 'tasks', 'payroll' => 'payroll', 'chat' => 'chat', 'channels' => 'chat',
                'calls' => 'calls', 'pipeline' => 'calls', 'sync' => 'calls', 'export-calls' => 'calls', 'play-recording' => 'calls',
                'reports' => 'reports', 'site-sales' => 'reports', 'users' => 'settings', 'settings' => 'settings', 'sms' => 'settings',
                'site-sync' => 'settings', 'staff' => 'staff', 'evaluations' => 'staff', 'leaves' => 'leaves',
            ];
            $__need = $__map[$segments[0]] ?? null;
            $__ok = $__need === null || Auth::hasPermission($__need);
            if ($segments[0] === 'search') $__ok = Auth::hasPermission('customers') || Auth::hasPermission('leads') || Auth::hasPermission('hosts') || Auth::hasPermission('tickets');
            if ($segments[0] === 'references' && $method === 'GET') $__ok = true;   // فهرست مراجع در فرم سرنخ
            if (!$__ok) {
                // پرونده‌ی پرسنلی خود کاربر (ورود/خروج، درخواست مرخصی، گزارش داخلی خودش)
                $__sid = null;
                try { $__sid = Database::getInstance()->fetchColumn('SELECT id FROM staff WHERE user_id = ? LIMIT 1', [$__u['id']]); } catch (Exception $e) {}
                if ($segments[0] === 'staff' && $__sid && (int)($segments[1] ?? 0) === (int)$__sid) $__ok = true;
                if ($segments[0] === 'leaves' && $method === 'POST' && $__sid && ($segments[1] ?? '') === '') {
                    $__b = json_decode((string)file_get_contents('php://input'), true);
                    if (!is_array($__b)) $__b = $_POST;
                    if ((int)($__b['staff_id'] ?? 0) === (int)$__sid) $__ok = true;   // فقط مرخصی برای خودش
                }
                if ($segments[0] === 'leaves' && $method === 'GET' && $__sid && (int)($_GET['staff_id'] ?? 0) === (int)$__sid) $__ok = true;
                if ($segments[0] === 'reports' && ($segments[1] ?? '') === 'operators' && !empty($__u['exten_number']) && (string)($_GET['exten'] ?? '') === (string)$__u['exten_number']) $__ok = true;
            }
            if (!$__ok) Response::forbidden('دسترسی به این بخش برای شما فعال نشده است.');
        }
    }

    // ===== حساب نمایشی اعلان‌بان: فقط مشاهده =====
    if (defined('CRM_BRIDGED') && CRM_BRIDGED && $method !== 'GET' && $segments[0] !== 'auth' && Auth::isReadOnly()) {
        Response::forbidden('در حساب نمایشی، ثبت و تغییر غیرفعال است.');
    }

    // ===== ماژول احراز هویت =====
    if ($segments[0] === 'auth') {
        handleAuthRoutes($segments, $method);
    }

    // ===== ماژول داشبورد =====
    elseif ($segments[0] === 'dashboard') {
        Auth::requireLogin();
        handleDashboardRoutes($segments, $method);
    }

    // ===== ماژول مشتریان =====
    elseif ($segments[0] === 'customers') {
        Auth::requireLogin();
        handleCustomerRoutes($segments, $method);
    }

    // ===== ماژول میزبانان =====
    elseif ($segments[0] === 'hosts') {
        Auth::requireLogin();
        handleHostRoutes($segments, $method);
    }

    // ===== ماژول پرسنل =====
    elseif ($segments[0] === 'staff') {
        Auth::requireLogin();
        handleStaffRoutes($segments, $method);
    }

    // ===== ماژول سرنخ‌ها =====
    elseif ($segments[0] === 'leads') {
        Auth::requireLogin();
        handleLeadRoutes($segments, $method);
    }

    // ===== ماژول مراجع سرنخ (منبع ورودی: سایت، اینستاگرام، معرف، ...) =====
    elseif ($segments[0] === 'references') {
        Auth::requireLogin();
        handleReferenceRoutes($segments, $method);
    }

    // ===== ماژول سرنخ‌های میزبان =====
    elseif ($segments[0] === 'host_leads') {
        Auth::requireLogin();
        handleHostLeadRoutes($segments, $method);
    }

    // ===== ماژول تیکت‌ها =====
    elseif ($segments[0] === 'tickets') {
        Auth::requireLogin();
        handleTicketRoutes($segments, $method);
    }

    // ===== ماژول تسک‌ها =====
    elseif ($segments[0] === 'tasks') {
        Auth::requireLogin();
        handleTaskRoutes($segments, $method);
    }

    // ===== ماژول چت =====
    elseif ($segments[0] === 'chat' || $segments[0] === 'channels') {
        Auth::requireLogin();
        handleChatRoutes($segments, $method);
    }

    // ===== ماژول پروژه‌ها =====
    elseif ($segments[0] === 'projects') {
        Auth::requireLogin();
        handleProjectRoutes($segments, $method);
    }

    // ===== ماژول تماس‌ها =====
    elseif ($segments[0] === 'calls' || $segments[0] === 'sync' || $segments[0] === 'play-recording' || $segments[0] === 'export-calls') {
        Auth::requireLogin();
        handleCallRoutes($segments, $method);
    }

    // ===== پیگیری مرحله‌ای شماره‌ها =====
    elseif ($segments[0] === 'pipeline') {
        Auth::requireLogin();
        handlePipelineRoutes($segments, $method);
    }

    // ===== ماژول مرخصی =====
    elseif ($segments[0] === 'leaves') {
        Auth::requireLogin();
        handleLeaveRoutes($segments, $method);
    }

    // ===== ماژول حقوق =====
    elseif ($segments[0] === 'payroll') {
        Auth::requireLogin();
        handlePayrollRoutes($segments, $method);
    }

    // ===== ماژول ارزیابی عملکرد =====
    elseif ($segments[0] === 'evaluations') {
        Auth::requireLogin();
        handleEvaluationRoutes($segments, $method);
    }

    // ===== ماژول گزارشات =====
    elseif ($segments[0] === 'reports') {
        Auth::requireLogin();
        handleReportRoutes($segments, $method);
    }

    // ===== استعلام‌های سایت =====
    elseif ($segments[0] === 'site-inquiries') {
        Auth::requireLogin();
        handleSiteInquiryRoutes($segments, $method);
    }

    // ===== فروش سایت =====
    elseif ($segments[0] === 'site-sales') {
        Auth::requireLogin();
        handleSiteSaleRoutes($segments, $method);
    }

    // ===== همگام‌سازی دیتابیس سایت =====
    elseif ($segments[0] === 'site-sync') {
        Auth::requireLogin();
        handleSiteSyncRoutes($segments, $method);
    }

    // ===== پنل پیامک =====
    elseif ($segments[0] === 'sms') {
        Auth::requireLogin();
        handleSmsRoutes($segments, $method);
    }

    // ===== اقامتگاه‌های میزبان =====
    elseif ($segments[0] === 'host-properties') {
        Auth::requireLogin();
        handleHostPropertyRoutes($segments, $method);
    }

    // ===== جستجوی سراسری =====
    elseif ($segments[0] === 'search') {
        Auth::requireLogin();
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            Response::success(['customers' => [], 'leads' => [], 'hosts' => [], 'tickets' => [], 'staff' => []], 'عبارت جستجو خیلی کوتاه است');
        }
        $like = '%' . $q . '%';
        $db = Database::getInstance();

        $results = [
            'customers' => $db->fetchAll("SELECT id, name, mobile FROM customers WHERE name LIKE ? OR mobile LIKE ? LIMIT 5", [$like, $like]),
            'leads'     => $db->fetchAll("SELECT id, name, mobile, stage FROM leads WHERE name LIKE ? OR mobile LIKE ? LIMIT 5", [$like, $like]),
            'hosts'     => $db->fetchAll("SELECT id, name, mobile FROM hosts WHERE name LIKE ? OR mobile LIKE ? LIMIT 5", [$like, $like]),
            'tickets'   => $db->fetchAll("SELECT id, title AS subject, title, status FROM tickets WHERE title LIKE ? LIMIT 5", [$like]),
            'staff'     => $db->fetchAll("SELECT id, name, code FROM staff WHERE name LIKE ? OR code LIKE ? LIMIT 5", [$like, $like]),
        ];
        Response::success($results, 'نتایج جستجو');
    }

    // PATCH /api/host-settlements/{id}/status  → تغییر وضعیت یک تسویه (پرداخت/رد)
    elseif ($segments[0] === 'host-settlements' && isset($segments[1]) && ctype_digit((string)$segments[1]) && ($segments[2] ?? '') === 'status' && $method === 'PATCH') {
        Auth::requireLogin();
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        Host::updateSettlementStatus((int)$segments[1], $data['status'] ?? '');
        Response::success(null, 'وضعیت تسویه به‌روزرسانی شد');
    }

    // ===== ماژول تنظیمات =====
    elseif ($segments[0] === 'settings') {
        Auth::requireLogin();
        handleSettingsRoutes($segments, $method);
    }

    // ===== ماژول کاربران و دسترسی‌ها =====
    // ===== وضعیت آنلاین اپراتورها - برای همه‌ی کاربران لاگین‌شده (نه فقط ادمین) =====
    elseif ($segments[0] === 'users' && ($segments[1] ?? '') === 'online-status') {
        Auth::requireLogin();
        Response::success(User::getOnlineStatus(), 'وضعیت آنلاین کاربران');
    }

    elseif ($segments[0] === 'users') {
        Auth::requireAdmin();
        handleUserRoutes($segments, $method);
    }

    // مسیر نامشخص
    else {
        Response::notFound('مسیر API یافت نشد: ' . $path);
    }

} catch (\Throwable $e) {
    // Throwable هم Exception و هم Error/TypeError را می‌گیرد - قبلاً فقط
    // Exception گرفته می‌شد و خطاهای PHP (مثل TypeError) بدون هیچ توضیحی
    // در پنل، فقط در فایل لاگ سرور (که کاربر دسترسی نداشت) ثبت می‌شدند.
    Logger::error('API exception: ' . $e->getMessage(), [
        'path'  => $path,
        'class' => get_class($e),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);

    // ثبت در همان جدول لاگی که در پنل تنظیمات نمایش داده می‌شود، تا کاربر
    // بدون نیاز به دسترسی سرور بتواند دقیقاً ببیند مشکل کجای کد بوده
    if (class_exists('TelefonchyApiLog') && (stripos($path, 'call') !== false || stripos($path, 'sync') !== false || stripos($path, 'telefonchy') !== false)) {
        try {
            TelefonchyApiLog::log([
                'direction'     => 'outbound',
                'method'        => $method ?? ($_SERVER['REQUEST_METHOD'] ?? null),
                'endpoint'      => '/api/' . $path . ' (خطای داخلی PHP)',
                'success'       => false,
                'error_message' => get_class($e) . ': ' . $e->getMessage() . ' — فایل: ' . basename($e->getFile()) . ':' . $e->getLine(),
            ]);
        } catch (\Throwable $ignore) {
            // اگر حتی ثبت لاگ هم شکست خورد، نادیده بگیر - نباید پاسخ اصلی را خراب کند
        }
    }

    Response::serverError(
        DEBUG_MODE ? $e->getMessage() : 'خطای داخلی سرور. لطفاً بعداً تلاش کنید.'
    );
}

// ============================================================
//   هندلرهای ماژول احراز هویت (پیاده‌سازی کامل - مرحله ۲)
// ============================================================
function handleAuthRoutes(array $segments, $method)
{
    $action = $segments[1] ?? '';

    // سیستم یکپارچه: ورود فقط از پنل اعلان‌بان
    if (defined('CRM_BRIDGED') && CRM_BRIDGED && in_array($action, ['login', 'change-password'], true)) {
        Response::forbidden('ورود و تغییر رمز از پنل اعلان‌بان انجام می‌شود.');
    }

    // POST /api/auth/login
    if ($action === 'login' && $method === 'POST') {
        $username = Helpers::input('username');
        $password = Helpers::input('password');

        if (empty($username) || empty($password)) {
            Response::validationError('نام کاربری و رمز عبور الزامی است', [
                'username' => empty($username) ? 'نام کاربری الزامی است' : null,
                'password' => empty($password) ? 'رمز عبور الزامی است' : null,
            ]);
        }

        $result = Auth::login($username, $password);
        if (!$result) {
            Response::unauthorized('نام کاربری یا رمز عبور نادرست است');
        }

        // تنظیم توکن در کوکی هم (برای صفحات HTML)
        if (!headers_sent()) {
            setcookie('jakojast_token', $result['token'], [
                'expires'  => time() + TOKEN_EXPIRY,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
        }

        Response::success([
            'user'  => $result['user'],
            'token' => $result['token'],
        ], 'ورود با موفقیت انجام شد');
    }

    // GET /api/auth/me
    if ($action === 'me' && $method === 'GET') {
        $user = Auth::requireLogin();
        Response::success($user, 'اطلاعات کاربر جاری');
    }

    // POST /api/auth/logout
    if ($action === 'logout' && $method === 'POST') {
        Auth::logout();
        if (!headers_sent()) {
            setcookie('jakojast_token', '', time() - 3600, '/');
        }
        Response::success(null, 'خروج با موفقیت انجام شد');
    }

    // POST /api/auth/change-password
    if ($action === 'change-password' && $method === 'POST') {
        $user = Auth::requireLogin();
        $currentPassword = Helpers::input('current_password');
        $newPassword = Helpers::input('new_password');

        if (empty($currentPassword) || empty($newPassword)) {
            Response::validationError('رمز فعلی و رمز جدید الزامی است');
        }
        if (strlen($newPassword) < MIN_PASSWORD_LENGTH) {
            Response::validationError('رمز جدید باید حداقل ' . MIN_PASSWORD_LENGTH . ' کاراکتر باشد');
        }

        // تأیید رمز فعلی
        $db = Database::getInstance();
        $row = $db->fetchOne('SELECT password FROM users WHERE id = ?', [$user['id']]);
        $verify = Helpers::verifyPassword($currentPassword, $row['password']);
        if (!$verify['matched']) {
            Response::unauthorized('رمز فعلی نادرست است');
        }

        Auth::changePassword($user['id'], $newPassword);
        Response::success(null, 'رمز عبور با موفقیت تغییر کرد');
    }

    Response::notFound('مسیر احراز هویت یافت نشد');
}

// ============================================================
//   stub ها برای ماژول‌های بعدی (در مراحل ۳ تا ۸ تکمیل می‌شوند)
// ============================================================
function handleDashboardRoutes(array $segments, $method)
{
    $action = $segments[1] ?? '';

    // GET /api/dashboard/kpi
    if ($action === 'kpi' && $method === 'GET') {
        $kpi = Report::getKPIs();
        Response::success($kpi, 'KPIهای داشبورد');
    }

    // GET /api/dashboard/charts
    if ($action === 'charts' && $method === 'GET') {
        $revenue = Report::getRevenueChart();
        $reservations = Report::getReservationsChart();
        Response::success([
            'revenue'      => $revenue,
            'reservations' => $reservations,
        ], 'داده‌های نمودار');
    }

    // GET /api/dashboard/activities
    if ($action === 'activities' && $method === 'GET') {
        $limit = (int)($_GET['limit'] ?? 20);
        $limit = max(1, min(100, $limit));
        $activities = Report::getActivities($limit);
        Response::success($activities, 'فعالیت‌های اخیر');
    }

    // GET /api/dashboard/alerts
    if ($action === 'alerts' && $method === 'GET') {
        $alerts = Report::getAlerts();
        Response::success($alerts, 'هشدارهای سیستم');
    }

    // GET /api/dashboard/org-chart
    if ($action === 'org-chart' && $method === 'GET') {
        $org = Report::getOrgChart();
        Response::success($org, 'نمودار سازمانی');
    }

    // GET /api/dashboard/summary  → همه داده‌های داشبورد در یک درخواست
    if (($action === 'summary' || $action === '') && $method === 'GET') {
        $data = [
            'summary'      => Report::getDashboardSummary(),
            'revenue'      => Report::getRevenueChart(),
            'reservations' => Report::getReservationsChart(),
            'activities'   => Report::getActivities(15),
            'alerts'       => Report::getAlerts(),
            'orgChart'     => Report::getOrgChart(),
        ];
        Response::success($data, 'داده‌های داشبورد');
    }

    Response::notFound('مسیر داشبورد یافت نشد');
}

function handleCustomerRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/customers  → لیست با فیلتر و صفحه‌بندی
    if ($action === '' && $method === 'GET') {
        $params = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'level'  => $_GET['level'] ?? '',
            'city'   => $_GET['city'] ?? '',
            'sort'   => $_GET['sort'] ?? 'date',
            'page'   => $_GET['page'] ?? 1,
            'limit'  => $_GET['limit'] ?? 20,
        ];
        $result = Customer::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست مشتریان');
    }

    // POST /api/customers  → ایجاد
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Customer::create($data);
        $customer = Customer::find($id);
        Response::success($customer, 'مشتری جدید ایجاد شد', [], 201);
    }

    // GET /api/customers/stats  → آمار
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Customer::getStats(), 'آمار مشتریان');
    }

    // GET /api/customers/{id}  → جزئیات
    if ($id && $action === (string)$id && $method === 'GET') {
        $customer = Customer::find($id);
        if (!$customer) Response::notFound('مشتری یافت نشد');
        $notes = Customer::getNotes($id);
        $tickets = Customer::getTickets($id);
        $calls = Customer::getCalls($id);
        Response::success([
            'customer' => $customer,
            'notes'    => $notes,
            'tickets'  => $tickets,
            'calls'    => $calls,
        ], 'جزئیات مشتری');
    }

    // PUT /api/customers/{id}  → ویرایش
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Customer::update($id, $data);
        $customer = Customer::find($id);
        Response::success($customer, 'مشتری به‌روزرسانی شد');
    }

    // DELETE /api/customers/{id}  → حذف نرم
    if ($id && $method === 'DELETE') {
        $hard = isset($_GET['hard']) && $_GET['hard'] === '1';
        if ($hard) {
            Customer::delete($id);
            Response::success(null, 'مشتری برای همیشه حذف شد');
        } else {
            Customer::softDelete($id);
            Response::success(null, 'مشتری مسدود شد (حذف نرم)');
        }
    }

    // GET /api/customers/{id}/notes  → یادداشت‌ها
    if ($id && $action === (string)$id && isset($segments[3]) && $segments[3] === 'notes' && $method === 'GET') {
        $notes = Customer::getNotes($id);
        Response::success($notes, 'یادداشت‌های مشتری');
    }

    // POST /api/customers/{id}/notes  → افزودن یادداشت
    if ($id && isset($segments[3]) && $segments[3] === 'notes' && $method === 'POST') {
        $user = Auth::currentUser();
        $text = Helpers::input('text');
        $noteId = Customer::addNote($id, $user['id'], $text);
        Response::success(['id' => $noteId], 'یادداشت افزوده شد', [], 201);
    }

    Response::notFound('مسیر مشتریان یافت نشد');
}

function handleHostRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/hosts
    if ($action === '' && $method === 'GET') {
        $params = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'level'  => $_GET['level'] ?? '',
            'city'   => $_GET['city'] ?? '',
            'sort'   => $_GET['sort'] ?? 'date',
            'page'   => $_GET['page'] ?? 1,
            'limit'  => $_GET['limit'] ?? 20,
        ];
        $result = Host::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست میزبانان');
    }

    // POST /api/hosts
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Host::create($data);
        $host = Host::find($id);
        Response::success($host, 'میزبان جدید ایجاد شد', [], 201);
    }

    // GET /api/hosts/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Host::getStats(), 'آمار میزبانان');
    }

    // GET /api/hosts/{id}/properties
    if ($id && $subAction === 'properties' && $method === 'GET') {
        Response::success(HostProperty::forHost($id), 'اقامتگاه‌های میزبان');
    }

    // POST /api/hosts/{id}/properties
    if ($id && $subAction === 'properties' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $data['host_id'] = $id;
        $pid = HostProperty::create($data);
        Response::success(HostProperty::find($pid), 'اقامتگاه افزوده شد', [], 201);
    }

    // GET /api/hosts/{id}
    if ($id && $subAction === '' && $method === 'GET') {
        $host = Host::find($id);
        if (!$host) Response::notFound('میزبان یافت نشد');
        $settlements = Host::getSettlements($id);
        $tickets = Host::getTickets($id);
        $properties = HostProperty::forHost($id);
        Response::success([
            'host'         => $host,
            'settlements'  => $settlements,
            'tickets'      => $tickets,
            'properties'   => $properties,
        ], 'جزئیات میزبان');
    }

    // PUT /api/hosts/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Host::update($id, $data);
        Response::success(Host::find($id), 'میزبان به‌روزرسانی شد');
    }

    // PATCH /api/hosts/{id}/status  → تغییر وضعیت
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $status = $data['status'] ?? '';
        Host::changeStatus($id, $status);
        Response::success(Host::find($id), 'وضعیت میزبان تغییر کرد');
    }

    // POST /api/hosts/{id}/settlements  → افزودن درخواست تسویه
    if ($id && $subAction === 'settlements' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $amount = (int)($data['amount'] ?? 0);
        $note = $data['note'] ?? '';
        $sid = Host::addSettlement($id, $amount, $note);
        Response::success(['id' => $sid], 'درخواست تسویه ثبت شد', [], 201);
    }

    // DELETE /api/hosts/{id}
    if ($id && $method === 'DELETE') {
        Host::delete($id);
        Response::success(null, 'میزبان حذف شد');
    }

    Response::notFound('مسیر میزبانان یافت نشد');
}

function handleStaffRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/staff  → لیست پرسنل
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'unit'     => $_GET['unit'] ?? '',
            'status'   => $_GET['status'] ?? '',
            'supervisor_id' => $_GET['supervisor_id'] ?? '',
            'sort'     => $_GET['sort'] ?? 'name',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = Staff::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست پرسنل');
    }

    // POST /api/staff  → ایجاد پرسنل
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Staff::create($data);
        Response::success(Staff::find($id), 'پرسنل جدید ایجاد شد', [], 201);
    }

    // GET /api/staff/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Staff::getStats(), 'آمار پرسنل');
    }

    // GET /api/staff/org-chart  → نمودار سازمانی
    if ($action === 'org-chart' && $method === 'GET') {
        Response::success(Staff::getOrgChart(), 'نمودار سازمانی');
    }

    // GET /api/staff/{id}
    if ($id && $method === 'GET') {
        $staff = Staff::find($id);
        if (!$staff) Response::notFound('پرسنل یافت نشد');
        Response::success($staff, 'جزئیات پرسنل');
    }

    // PUT /api/staff/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Staff::update($id, $data);
        Response::success(Staff::find($id), 'پرسنل به‌روزرسانی شد');
    }

    // PATCH /api/staff/{id}/status
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $status = $data['status'] ?? '';
        Staff::changeStatus($id, $status);
        Response::success(Staff::find($id), 'وضعیت پرسنل تغییر کرد');
    }

    // POST /api/staff/{id}/attendance  → ثبت ورود/خروج
    if ($id && $subAction === 'attendance' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $type = $data['type'] ?? 'in';
        Staff::logAttendance($id, $type);
        Response::success(null, $type === 'in' ? 'ورود ثبت شد' : 'خروج ثبت شد');
    }

    // GET /api/staff/{id}/attendance  → حضور و غیاب
    if ($id && $subAction === 'attendance' && $method === 'GET') {
        $month = (int)($_GET['month'] ?? 0);
        $year = (int)($_GET['year'] ?? 0);
        $attendance = Staff::getAttendance($id, $month, $year);
        Response::success($attendance, 'سوابق حضور و غیاب');
    }

    // GET /api/staff/{id}/attendance-status  → آیا الان نوبت کاری باز دارد؟
    if ($id && $subAction === 'attendance-status' && $method === 'GET') {
        $openSession = Staff::getOpenAttendanceSession($id);
        Response::success(['is_checked_in' => (bool)$openSession, 'session' => $openSession], 'وضعیت حضور');
    }

    // GET /api/staff/{id}/tickets
    if ($id && $subAction === 'tickets' && $method === 'GET') {
        Response::success(Staff::getTickets($id), 'تیکت‌های پرسنل');
    }

    // GET /api/staff/{id}/tasks
    if ($id && $subAction === 'tasks' && $method === 'GET') {
        Response::success(Staff::getTasks($id), 'تسک‌های پرسنل');
    }

    // DELETE /api/staff/{id}
    if ($id && $method === 'DELETE') {
        Staff::delete($id);
        Response::success(null, 'پرسنل حذف شد');
    }

    Response::notFound('مسیر پرسنل یافت نشد');
}

function handleLeaveRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/leaves
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'status'   => $_GET['status'] ?? '',
            'type'     => $_GET['type'] ?? '',
            'staff_id' => $_GET['staff_id'] ?? '',
            'unit'     => $_GET['unit'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = Leave::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست مرخصی‌ها');
    }

    // POST /api/leaves
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Leave::create($data);
        Response::success(Leave::find($id), 'درخواست مرخصی ثبت شد', [], 201);
    }

    // GET /api/leaves/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Leave::getStats(), 'آمار مرخصی‌ها');
    }

    // GET /api/leaves/{id}
    if ($id && $method === 'GET') {
        $leave = Leave::find($id);
        if (!$leave) Response::notFound('درخواست مرخصی یافت نشد');
        Response::success($leave, 'جزئیات مرخصی');
    }

    // PUT /api/leaves/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        // فقط قبل از تأیید قابل ویرایش است
        $existing = Leave::find($id);
        if (!$existing) Response::notFound('مرخصی یافت نشد');
        if ($existing['status'] !== 'pending') {
            Response::validationError('پس از تأیید/رد، ویرایش ممکن نیست');
        }
        // فقط فیلدهای محدود قابل ویرایش
        $allowed = ['type', 'from_date', 'to_date', 'days', 'reason'];
        $update = [];
        foreach ($allowed as $f) if (isset($data[$f])) $update[$f] = $data[$f];
        Database::getInstance()->update('leaves', $update, 'id = ?', [$id]);
        Response::success(Leave::find($id), 'مرخصی به‌روزرسانی شد');
    }

    // PATCH /api/leaves/{id}/approve  → تأیید
    if ($id && $subAction === 'approve' && $method === 'PATCH') {
        $user = Auth::requireLogin();
        $leave = Leave::changeStatus($id, 'approved', $user['id']);
        Response::success($leave, 'مرخصی تأیید شد');
    }

    // PATCH /api/leaves/{id}/reject  → رد
    if ($id && $subAction === 'reject' && $method === 'PATCH') {
        $user = Auth::requireLogin();
        $leave = Leave::changeStatus($id, 'rejected', $user['id']);
        Response::success($leave, 'مرخصی رد شد');
    }

    // DELETE /api/leaves/{id}
    if ($id && $method === 'DELETE') {
        Leave::delete($id);
        Response::success(null, 'مرخصی حذف شد');
    }

    Response::notFound('مسیر مرخصی یافت نشد');
}

function handlePayrollRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/payroll
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'month'    => $_GET['month'] ?? '',
            'paid'     => $_GET['paid'] ?? '',
            'staff_id' => $_GET['staff_id'] ?? '',
            'unit'     => $_GET['unit'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = Payroll::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست فیش‌های حقوقی');
    }

    // POST /api/payroll  → ایجاد فیش
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Payroll::create($data);
        Response::success(Payroll::find($id), 'فیش حقوقی ایجاد شد', [], 201);
    }

    // POST /api/payroll/generate  → تولید فیش برای ماه
    if ($action === 'generate' && $method === 'POST') {
        Auth::requireRole(['admin', 'manager']);
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $month = $data['month'] ?? '';
        if (empty($month)) Response::validationError('ماه الزامی است');
        $count = Payroll::generateForMonth($month);
        Response::success(['count' => $count], "{$count} فیش حقوقی تولید شد");
    }

    // POST /api/payroll/pay-all  → پرداخت گروهی یک ماه
    if ($action === 'pay-all' && $method === 'POST') {
        Auth::requireRole(['admin', 'manager']);
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $month = $data['month'] ?? '';
        if (empty($month)) Response::validationError('ماه الزامی است');
        $count = Payroll::payAllMonth($month);
        Response::success(['count' => $count], "{$count} فیش پرداخت شد");
    }

    // GET /api/payroll/stats
    if ($action === 'stats' && $method === 'GET') {
        $month = $_GET['month'] ?? null;
        Response::success(Payroll::getStats($month), 'آمار حقوق');
    }

    // GET /api/payroll/months  → لیست ماه‌های موجود
    if ($action === 'months' && $method === 'GET') {
        Response::success(Payroll::getMonths(), 'ماه‌های موجود');
    }

    // GET /api/payroll/{id}
    if ($id && $method === 'GET') {
        $pay = Payroll::find($id);
        if (!$pay) Response::notFound('فیش حقوقی یافت نشد');
        Response::success($pay, 'جزئیات فیش حقوقی');
    }

    // PUT /api/payroll/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Payroll::update($id, $data);
        Response::success(Payroll::find($id), 'فیش به‌روزرسانی شد');
    }

    // PATCH /api/payroll/{id}/pay  → علامت‌گذاری به‌عنوان پرداخت شده
    if ($id && $subAction === 'pay' && $method === 'PATCH') {
        Payroll::markPaid($id);
        Response::success(Payroll::find($id), 'فیش پرداخت شد');
    }

    // DELETE /api/payroll/{id}
    if ($id && $method === 'DELETE') {
        Payroll::delete($id);
        Response::success(null, 'فیش حذف شد');
    }

    Response::notFound('مسیر حقوق یافت نشد');
}

function handleEvaluationRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/evaluations
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'staff_id' => $_GET['staff_id'] ?? '',
            'month'    => $_GET['month'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = Evaluation::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست ارزیابی‌ها');
    }

    // POST /api/evaluations
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Evaluation::create($data);
        Response::success(Evaluation::find($id), 'ارزیابی ثبت شد', [], 201);
    }

    // GET /api/evaluations/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Evaluation::getStats(), 'آمار ارزیابی‌ها');
    }

    // GET /api/evaluations/staff/{id}  → ارزیابی‌های یک پرسنل
    if ($action === 'staff' && isset($segments[2]) && $method === 'GET') {
        $staffId = (int)$segments[2];
        Response::success(Evaluation::getByStaff($staffId), 'ارزیابی‌های پرسنل');
    }

    // GET /api/evaluations/{id}
    if ($id && $method === 'GET') {
        $eval = Evaluation::find($id);
        if (!$eval) Response::notFound('ارزیابی یافت نشد');
        Response::success($eval, 'جزئیات ارزیابی');
    }

    // PUT /api/evaluations/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Evaluation::update($id, $data);
        Response::success(Evaluation::find($id), 'ارزیابی به‌روزرسانی شد');
    }

    // DELETE /api/evaluations/{id}
    if ($id && $method === 'DELETE') {
        Evaluation::delete($id);
        Response::success(null, 'ارزیابی حذف شد');
    }

    Response::notFound('مسیر ارزیابی یافت نشد');
}

/**
 * مدیریت مراجع سرنخ (منبع ورودی مشتری) - جایگزین لیست ثابت قدیمی
 */
function handleReferenceRoutes(array $segments, $method)
{
    $id = isset($segments[1]) && ctype_digit((string)$segments[1]) ? (int)$segments[1] : null;

    // GET /api/references  → لیست همه‌ی مراجع
    if (!$id && $method === 'GET') {
        Response::success(Reference::all(), 'لیست مراجع');
    }

    // POST /api/references  → ساخت مرجع جدید
    if (!$id && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $newId = Reference::create($data);
        Response::success(Reference::find($newId), 'مرجع جدید ثبت شد');
    }

    // PUT /api/references/{id}  → ویرایش مرجع
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Reference::update($id, $data);
        Response::success(Reference::find($id), 'مرجع به‌روزرسانی شد');
    }

    // DELETE /api/references/{id}  → غیرفعال‌سازی مرجع (نه حذف کامل)
    if ($id && $method === 'DELETE') {
        Reference::delete($id);
        Response::success(null, 'مرجع غیرفعال شد');
    }

    Response::notFound('مسیر مراجع یافت نشد');
}

function handleLeadRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/leads
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'stage'    => $_GET['stage'] ?? '',
            'source'   => $_GET['source'] ?? '',
            'assignee' => $_GET['assignee'] ?? '',
            'sort'     => $_GET['sort'] ?? 'date',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
            'visible_assignees' => Auth::getVisibleAssigneeNames(),
        ];
        $result = Lead::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست سرنخ‌ها');
    }

    // POST /api/leads
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Lead::create($data);
        Response::success(Lead::find($id), 'سرنخ جدید ایجاد شد', [], 201);
    }

    // GET /api/leads/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Lead::getStats(), 'آمار سرنخ‌ها');
    }

    // GET /api/leads/assignees  → لیست مسئولین
    if ($action === 'assignees' && $method === 'GET') {
        Response::success(Lead::getAssignees(), 'لیست مسئولین فروش');
    }

    // GET /api/leads/{id}
    if ($id && $method === 'GET') {
        $lead = Lead::find($id);
        if (!$lead) Response::notFound('سرنخ یافت نشد');
        // ⚠️ محافظت در برابر دسترسی مستقیم با حدس‌زدن id به سرنخ کسی که
        // در محدوده‌ی دید کاربر نیست
        $visibleNames = Auth::getVisibleAssigneeNames();
        if ($visibleNames !== null) {
            $leadOwner = $lead['operator'] ?: $lead['assignee'];
            // ⚠️ سرنخ‌های هنوز-برداشته‌نشده (بدون اپراتور) باید برای همه‌ی
            // کارشناس‌های محدودشده هم قابل‌دیدن/claim باشند - وگرنه اصلاً
            // نمی‌توانند دکمه‌ی «🖐 برداشتن» (v4) را ببینند و استفاده کنند.
            if (!empty($leadOwner) && !in_array($leadOwner, $visibleNames, true)) {
                Response::forbidden('شما دسترسی به این سرنخ را ندارید.');
            }
        }
        Response::success($lead, 'جزئیات سرنخ');
    }

    // PUT /api/leads/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Lead::update($id, $data);
        Response::success(Lead::find($id), 'سرنخ به‌روزرسانی شد');
    }

    // PATCH /api/leads/{id}/stage  → تغییر مرحله
    if ($id && $subAction === 'stage' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $stage = $data['stage'] ?? '';
        Lead::changeStage($id, $stage);
        Response::success(Lead::find($id), 'مرحله سرنخ تغییر کرد');
    }

    // POST /api/leads/{id}/claim  → برداشتن اتمیک سرنخ (بدون race condition)
    if ($id && $subAction === 'claim' && $method === 'POST') {
        $user = Auth::requireLogin();
        $result = Lead::claim($id, $user['full_name']);
        if (!$result['claimed']) {
            // ۴۰۹ Conflict: سرنخ قبلاً توسط شخص دیگری برداشته شده
            Response::json(['success' => false, 'message' => $result['message'], 'data' => $result['lead']], 409);
        }
        Response::success($result['lead'], $result['message']);
    }

    // GET /api/leads/{id}/status-history  → تاریخچه‌ی کامل تغییر وضعیت این سرنخ
    if ($id && $subAction === 'status-history' && $method === 'GET') {
        $lead = Lead::find($id);
        if (!$lead) Response::notFound('سرنخ یافت نشد');
        Response::success(Lead::getStatusHistory($id), 'تاریخچه‌ی وضعیت سرنخ');
    }

    // POST /api/leads/{id}/convert  → تبدیل به مشتری
    if ($id && $subAction === 'convert' && $method === 'POST') {
        $customerId = Lead::convertToCustomer($id);
        Response::success([
            'customer_id' => $customerId,
            'customer'    => Customer::find($customerId),
        ], 'سرنخ با موفقیت به مشتری تبدیل شد', [], 201);
    }

    // DELETE /api/leads/{id}
    if ($id && $method === 'DELETE') {
        Lead::delete($id);
        Response::success(null, 'سرنخ حذف شد');
    }

    Response::notFound('مسیر سرنخ‌ها یافت نشد');
}

function handleHostLeadRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/host_leads
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'        => $_GET['search'] ?? '',
            'stage'         => $_GET['stage'] ?? '',
            'status'        => $_GET['status'] ?? '',
            'source'        => $_GET['source'] ?? '',
            'city'          => $_GET['city'] ?? '',
            'property_type' => $_GET['property_type'] ?? '',
            'assignee'      => $_GET['assignee'] ?? '',
            'sort'          => $_GET['sort'] ?? 'date',
            'page'          => $_GET['page'] ?? 1,
            'limit'         => $_GET['limit'] ?? 20,
        ];
        $result = HostLead::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست سرنخ‌های میزبان');
    }

    // POST /api/host_leads
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = HostLead::create($data);
        Response::success(HostLead::find($id), 'سرنخ میزبان جدید ایجاد شد', [], 201);
    }

    // GET /api/host_leads/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(HostLead::getStats(), 'آمار سرنخ‌های میزبان');
    }

    // GET /api/host_leads/cities  → لیست شهرها
    if ($action === 'cities' && $method === 'GET') {
        Response::success(HostLead::getCities(), 'لیست شهرها');
    }

    // GET /api/host_leads/assignees
    if ($action === 'assignees' && $method === 'GET') {
        Response::success(HostLead::getAssignees(), 'لیست مسئولین جذب');
    }

    // POST /api/host_leads/import-mizban  → ایمپورت بانک لید از پوشه mizban-lead
    if ($action === 'import-mizban' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $updateExisting = !empty($data['update_existing']);
        $result = HostLead::importFromMizbanLeadBank($updateExisting);
        $msg = sprintf(
            'ایمپورت انجام شد: %d جدید، %d به‌روز، %d تکراری ردشد، %d نامعتبر (از %d)',
            $result['imported'],
            $result['updated'],
            $result['skipped'],
            $result['invalid'],
            $result['total']
        );
        Response::success($result, $msg);
    }

    // GET /api/host_leads/{id}
    if ($id && $method === 'GET') {
        $lead = HostLead::find($id);
        if (!$lead) Response::notFound('سرنخ میزبان یافت نشد');
        Response::success($lead, 'جزئیات سرنخ میزبان');
    }

    // PUT /api/host_leads/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        HostLead::update($id, $data);
        Response::success(HostLead::find($id), 'سرنخ میزبان به‌روزرسانی شد');
    }

    // PATCH /api/host_leads/{id}/status  → تغییر وضعیت (جذب اولیه/بی پاسخ/عدم تمایل)
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $status = $data['status'] ?? '';
        HostLead::changeStatus($id, $status);
        Response::success(HostLead::find($id), 'وضعیت سرنخ میزبان تغییر کرد');
    }

    // PATCH /api/host_leads/{id}/stage  → تغییر مرحله قیف قدیمی
    if ($id && $subAction === 'stage' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $stage = $data['stage'] ?? '';
        HostLead::changeStage($id, $stage);
        Response::success(HostLead::find($id), 'مرحله سرنخ میزبان تغییر کرد');
    }

    // POST /api/host_leads/{id}/contact  → ثبت تماس
    if ($id && $subAction === 'contact' && $method === 'POST') {
        HostLead::logContact($id);
        Response::success(HostLead::find($id), 'تماس ثبت شد');
    }

    // POST /api/host_leads/{id}/convert  → تبدیل به میزبان
    if ($id && $subAction === 'convert' && $method === 'POST') {
        $hostId = HostLead::convertToHost($id);
        Response::success([
            'host_id' => $hostId,
            'host'    => Host::find($hostId),
        ], 'سرنخ با موفقیت به میزبان تبدیل شد', [], 201);
    }

    // DELETE /api/host_leads/{id}
    if ($id && $method === 'DELETE') {
        HostLead::delete($id);
        Response::success(null, 'سرنخ میزبان حذف شد');
    }

    Response::notFound('مسیر سرنخ‌های میزبان یافت نشد');
}

function handleTicketRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/tickets
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'status'   => $_GET['status'] ?? '',
            'priority' => $_GET['priority'] ?? '',
            'type'     => $_GET['type'] ?? '',
            'dept'     => $_GET['dept'] ?? '',
            'assignee' => $_GET['assignee'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = Ticket::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست تیکت‌ها');
    }

    // POST /api/tickets
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $user = Auth::currentUser();
        $data['from_name'] = $user['full_name'] ?? 'کاربر';
        $id = Ticket::create($data);
        Response::success(Ticket::find($id), 'تیکت ایجاد شد', [], 201);
    }

    // GET /api/tickets/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Ticket::getStats(), 'آمار تیکت‌ها');
    }

    // GET /api/tickets/{id}
    if ($id && $method === 'GET') {
        $ticket = Ticket::find($id);
        if (!$ticket) Response::notFound('تیکت یافت نشد');
        Response::success($ticket, 'جزئیات تیکت');
    }

    // PUT /api/tickets/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Ticket::update($id, $data);
        Response::success(Ticket::find($id), 'تیکت به‌روزرسانی شد');
    }

    // POST /api/tickets/{id}/reply  → افزودن پاسخ
    if ($id && $subAction === 'reply' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $text = $data['text'] ?? '';
        $user = Auth::currentUser();
        $ticket = Ticket::addReply($id, $text, $user['full_name'] ?? 'کارشناس پشتیبانی', true);
        Response::success($ticket, 'پاسخ ثبت شد');
    }

    // PATCH /api/tickets/{id}/status  → تغییر وضعیت
    // ⚠️ محدودیت جدید: فقط فرد مسئول (assignee) یا مدیر سیستم می‌تواند
    // تیکت را ببندد (status='closed') - سایر کارشناسان نمی‌توانند تیکتی
    // که مسئولیتش با آن‌ها نیست را ببندند.
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $status = $data['status'] ?? '';

        if ($status === 'closed') {
            $currentUser = Auth::currentUser();
            $ticket = Ticket::find($id);
            $isAssignee = $ticket && !empty($ticket['assignee']) && $ticket['assignee'] === ($currentUser['full_name'] ?? null);
            if ($currentUser['role'] !== 'admin' && !$isAssignee) {
                Response::forbidden('فقط فرد مسئول این تیکت یا مدیر سیستم می‌تواند آن را ببندد.');
            }
        }

        Ticket::changeStatus($id, $status);
        Response::success(Ticket::find($id), 'وضعیت تیکت تغییر کرد');
    }

    // PATCH /api/tickets/{id}/assign  → انتساب
    if ($id && $subAction === 'assign' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $assignee = $data['assignee'] ?? '';
        Ticket::assign($id, $assignee);
        Response::success(Ticket::find($id), 'تیکت منتقل شد');
    }

    // DELETE /api/tickets/{id}
    if ($id && $method === 'DELETE') {
        Ticket::delete($id);
        Response::success(null, 'تیکت حذف شد');
    }

    Response::notFound('مسیر تیکت‌ها یافت نشد');
}

function handleTaskRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/tasks
    if ($action === '' && $method === 'GET') {
        $params = [
            'search'   => $_GET['search'] ?? '',
            'status'   => $_GET['status'] ?? '',
            'priority' => $_GET['priority'] ?? '',
            'project_id' => $_GET['project_id'] ?? '',
            'assignee' => $_GET['assignee'] ?? '',
            'sort'     => $_GET['sort'] ?? 'date',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 100,  // بالاتر برای کانبان
        ];
        $result = Task::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست تسک‌ها');
    }

    // POST /api/tasks
    // ⚠️ محدودیت جدید: فقط مدیر سیستم (admin) یا مدیر بخش (manager)
    // می‌تواند تسک ایجاد کند - کارمندان عادی فقط می‌توانند تسک‌های
    // محول‌شده به خودشان را ببینند/انجام دهند، نه تسک جدید بسازند.
    if ($action === '' && $method === 'POST') {
        $currentUser = Auth::currentUser();
        if (!in_array($currentUser['role'], ['admin', 'manager'], true)) {
            Response::forbidden('فقط مدیران می‌توانند تسک جدید ایجاد کنند.');
        }
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Task::create($data);
        Response::success(Task::find($id), 'تسک ایجاد شد', [], 201);
    }

    // GET /api/tasks/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Task::getStats(), 'آمار تسک‌ها');
    }

    // GET /api/tasks/kanban  → همه تسک‌ها گروه‌بندی شده بر اساس status
    if ($action === 'kanban' && $method === 'GET') {
        $assignee = $_GET['assignee'] ?? '';
        $projectId = $_GET['project_id'] ?? '';
        $params = ['limit' => 500];
        if ($assignee) $params['assignee'] = $assignee;
        if ($projectId) $params['project_id'] = $projectId;
        $result = Task::all($params);
        $kanban = [];
        foreach (Task::$statuses as $s) $kanban[$s] = [];
        foreach ($result['items'] as $t) {
            if (isset($kanban[$t['status']])) $kanban[$t['status']][] = $t;
        }
        Response::success($kanban, 'داده‌های کانبان');
    }

    // GET /api/tasks/assignees
    if ($action === 'assignees' && $method === 'GET') {
        Response::success(Task::getAssignees(), 'مسئولین تسک‌ها');
    }

    // GET /api/tasks/{id}
    if ($id && $method === 'GET') {
        $task = Task::find($id);
        if (!$task) Response::notFound('تسک یافت نشد');
        Response::success($task, 'جزئیات تسک');
    }

    // PUT /api/tasks/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Task::update($id, $data);
        Response::success(Task::find($id), 'تسک به‌روزرسانی شد');
    }

    // PATCH /api/tasks/{id}/status  → تغییر وضعیت (برای drag & drop)
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $status = $data['status'] ?? '';
        Task::changeStatus($id, $status);
        Response::success(Task::find($id), 'وضعیت تسک تغییر کرد');
    }

    // POST /api/tasks/{id}/comments  → افزودن کامنت
    if ($id && $subAction === 'comments' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $user = Auth::currentUser();
        Task::addComment($id, $data['text'] ?? '', $user['full_name'] ?? 'کاربر');
        Response::success(Task::find($id), 'کامنت ثبت شد');
    }

    // PATCH /api/tasks/{id}/subtasks/{index}  → تغییر وضعیت زیرتسک
    if ($id && $subAction === 'subtasks' && isset($segments[3]) && $method === 'PATCH') {
        $subIndex = (int)$segments[3];
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $checked = (bool)($data['done'] ?? false);
        Task::toggleSubtask($id, $subIndex, $checked);
        Response::success(Task::find($id), 'زیرتسک به‌روزرسانی شد');
    }

    // DELETE /api/tasks/{id}
    if ($id && $method === 'DELETE') {
        Task::delete($id);
        Response::success(null, 'تسک حذف شد');
    }

    Response::notFound('مسیر تسک‌ها یافت نشد');
}

function handleChatRoutes(array $segments, $method)
{
    // ⚠️ این هندلر دو نوع مسیر متفاوت دارد: هم /api/chat/{action} (مثل
    // chat/messages، chat/send) و هم /api/channels به‌تنهایی (بدون
    // پیشوند chat/). فرمول عمومی $seg1 = $segments[1] برای حالت دوم
    // اشتباه است چون segments[1] اصلاً وجود ندارد - در آن حالت خودِ
    // segments[0] ("channels") همان اکشن است.
    if ($segments[0] === 'channels') {
        $action = 'channels';
        $id = null;
        $subAction = $segments[1] ?? '';
    } else {
        $seg1 = $segments[1] ?? '';
        $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
        $action = ($id !== null) ? (string)$id : $seg1;
        $subAction = $segments[2] ?? '';
    }

    // GET /api/channels  → لیست کانال‌ها با تعداد پیام
    if ($action === 'channels' && $method === 'GET') {
        Response::success(Chat::getChannelsWithCounts(), 'لیست کانال‌ها');
    }

    // GET /api/chat/messages?channel=X&last_id=Y&poll=1
    if ($action === 'messages' && $method === 'GET') {
        $channel = $_GET['channel'] ?? 'عمومی';
        $lastId = (int)($_GET['last_id'] ?? 0);
        $longPoll = isset($_GET['poll']) && $_GET['poll'] === '1';

        // برای Long Polling timeout را افزایش بده
        if ($longPoll) {
            @set_time_limit(CHAT_POLL_TIMEOUT + 10);
            @ini_set('max_execution_time', CHAT_POLL_TIMEOUT + 10);
            if (!headers_sent()) {
                header('X-Accel-Buffering: no');  // nginx
                header('Cache-Control: no-cache');
            }
        }

        $messages = Chat::getMessages($channel, $lastId, $longPoll);
        $currentUserId = Auth::currentUser()['id'] ?? 0;
        $currentUserName = Auth::currentUser()['full_name'] ?? '';

        // علامت‌گذاری is_me برای پیام‌های کاربر جاری
        foreach ($messages as &$m) {
            $m['is_me'] = ($m['user_id'] && $m['user_id'] == $currentUserId) ? 1 : 0;
        }

        Response::success([
            'messages'     => $messages,
            'last_id'      => !empty($messages) ? end($messages)['id'] : $lastId,
            'server_time'  => date('Y-m-d H:i:s'),
        ], $longPoll ? 'Long poll completed' : 'پیام‌ها دریافت شد');
    }

    // POST /api/chat/send
    if ($action === 'send' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $user = Auth::currentUser();
        $channel = $data['channel'] ?? 'عمومی';
        $text = $data['text'] ?? '';
        $message = Chat::sendMessage(
            $channel,
            $user['id'] ?? null,
            $user['full_name'] ?? 'کاربر',
            $text,
            true  // همیشه is_me=true برای فرستنده
        );
        Response::success($message, 'پیام ارسال شد', [], 201);
    }

    // GET /api/chat/online-users
    if ($action === 'online-users' && $method === 'GET') {
        Response::success(Chat::getOnlineUsers(), 'کاربران آنلاین');
    }

    // GET /api/chat/stats
    if ($action === 'stats' && $method === 'GET') {
        Response::success(Chat::getStats(), 'آمار چت');
    }

    // DELETE /api/chat/{id}  → حذف پیام (فقط مدیر)
    if ($id && $method === 'DELETE') {
        Auth::requireAdmin();
        Chat::deleteMessage($id);
        Response::success(null, 'پیام حذف شد');
    }

    Response::notFound('مسیر چت یافت نشد');
}

function handleProjectRoutes(array $segments, $method)
{
    // ⚠️ رفع باگ بنیادی (کشف‌شده هنگام بررسی خطای ویرایش پرسنل/تبدیل تماس
    // به سرنخ): نسخه‌ی قبلی segments[1] را همیشه «اکشن» و segments[2] را
    // «id» فرض می‌کرد. اما در تمام URLهای واقعی این پروژه (مثلاً
    // /staff/{id} یا /staff/{id}/status)، این‌id همیشه در segments[1]
    // است و segments[2] (اگر باشد) یک زیرمسیر/subAction است، نه برعکس.
    // این یعنی تقریباً تمام route های ویرایش/حذف/جزئیاتِ تک‌رکوردی در
    // کل api.php هیچ‌وقت درست کار نمی‌کردند (چون $id همیشه null می‌شد).
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/projects
    if ($action === '' && $method === 'GET') {
        $params = [
            'search' => $_GET['search'] ?? '',
            'page'   => $_GET['page'] ?? 1,
            'limit'  => $_GET['limit'] ?? 50,
        ];
        $result = Project::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست پروژه‌ها');
    }

    // POST /api/projects
    // مشابه تسک‌ها: فقط مدیران اجازه‌ی ساخت پروژه‌ی جدید دارند
    if ($action === '' && $method === 'POST') {
        $currentUser = Auth::currentUser();
        if (!in_array($currentUser['role'], ['admin', 'manager'], true)) {
            Response::forbidden('فقط مدیران می‌توانند پروژه جدید ایجاد کنند.');
        }
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = Project::create($data);
        Response::success(Project::find($id), 'پروژه ایجاد شد', [], 201);
    }

    // GET /api/projects/{id}
    if ($id && $method === 'GET') {
        $project = Project::find($id);
        if (!$project) Response::notFound('پروژه یافت نشد');
        Response::success($project, 'جزئیات پروژه');
    }

    // PUT /api/projects/{id}
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Project::update($id, $data);
        Response::success(Project::find($id), 'پروژه به‌روزرسانی شد');
    }

    // GET /api/projects/{id}/tasks
    if ($id && $subAction === 'tasks' && $method === 'GET') {
        Response::success(Project::getTasks($id), 'تسک‌های پروژه');
    }

    // DELETE /api/projects/{id}
    if ($id && $method === 'DELETE') {
        Project::delete($id);
        Response::success(null, 'پروژه حذف شد');
    }

    Response::notFound('مسیر پروژه‌ها یافت نشد');
}

function handleCallRoutes(array $segments, $method)
{
    $action = $segments[0] ?? '';
    // ⚠️ همان رفع باگ بنیادی: id باید از segments[1] خوانده شود (نه
    // segments[2])، چون URL واقعی به‌شکل calls/{id}/{subAction} است.
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $subAction = ($id !== null) ? ($segments[2] ?? '') : $seg1;

    // ===== GET /api/calls  → لیست تماس‌ها =====
    // ⚠️ باگ مهم پیدا و رفع‌شده: این شرط قبلاً فقط action==='calls' چک
    // می‌کرد، بدون اینکه subAction خالی باشد. چون Response::paginated
    // در انتها exit می‌زند، این یعنی هر درخواست GET به هر زیرمسیر
    // (calls/stats, calls/extensions, calls/categories, calls/live)
    // همیشه همین‌جا (به‌عنوان لیست عادی) پاسخ می‌گرفت و هیچ‌وقت به
    // هندلر واقعی‌اش نمی‌رسید - مثلاً «پایش زنده‌ی تماس‌ها» هیچ‌وقت واقعاً
    // Call::getLiveCalls() را صدا نمی‌زد، فقط یک لیست عادی صفحه‌بندی‌شده
    // می‌گرفت (که چون امروز فیلتر جدید محدودسازی دید هم رویش اعمال شد،
    // خطای «Column ambiguous» را آشکار کرد - ولی خودِ باگِ مسیریابی خیلی
    // قدیمی‌تر و مستقل از آن است).
    if ($action === 'calls' && $id === null && $subAction === '' && $method === 'GET') {
        $params = [
            'search'        => $_GET['search'] ?? '',
            'type'          => $_GET['type'] ?? '',
            'status'        => $_GET['status'] ?? '',
            'category'      => $_GET['category'] ?? '',
            'label'         => $_GET['label'] ?? '',
            'from_date'     => $_GET['from_date'] ?? '',
            'to_date'       => $_GET['to_date'] ?? '',
            'exten_number'  => $_GET['exten_number'] ?? '',
            'contact_id'    => $_GET['contact_id'] ?? '',
            'has_recording' => $_GET['has_recording'] ?? '',
            'sort'          => $_GET['sort'] ?? 'date_desc',
            'page'          => $_GET['page'] ?? 1,
            'limit'         => $_GET['limit'] ?? 20,
            'visible_extens' => Auth::getVisibleExtens(),
        ];
        $result = Call::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'لیست تماس‌ها');
    }

    // ===== GET /api/calls/unique-numbers  → شماره‌های یکتای امروز (خط‌روند) =====
    if ($action === 'calls' && $subAction === 'unique-numbers' && $method === 'GET') {
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $numbers = Call::getTodayUniqueNumbers($limit, Auth::getVisibleExtens(), $_GET['direction'] ?? '');
        Response::success($numbers, count($numbers) . ' شماره‌ی یکتا امروز');
    }

    // ===== GET /api/calls/by-number?number=...  → کل تاریخچه‌ی یک شماره + پروفایل کامل =====
    if ($action === 'calls' && $subAction === 'by-number' && $method === 'GET') {
        $number = $_GET['number'] ?? '';
        if (empty($number)) Response::validationError('شماره مشخص نشده');
        $history = Call::getFullNumberProfile($number, Auth::getVisibleExtens());
        Response::success($history, 'تاریخچه‌ی شماره ' . $number);
    }

    // ===== GET /api/calls/stats =====
    if ($action === 'calls' && $subAction === 'stats' && $method === 'GET') {
        $fromDate = $_GET['from_date'] ?? null;
        $toDate   = $_GET['to_date'] ?? null;
        Response::success(Call::getStats($fromDate, $toDate), 'آمار تماس‌ها');
    }

    // ===== GET /api/calls/extensions  → لیست داخلی‌ها =====
    if ($action === 'calls' && $subAction === 'extensions' && $method === 'GET') {
        Response::success(Call::getExtensions(), 'لیست شماره‌های داخلی');
    }

    // ===== GET /api/calls/{id}  → جزئیات تماس =====
    if ($action === 'calls' && $id && $method === 'GET') {
        $call = Call::find($id);
        if (!$call) Response::notFound('تماس یافت نشد');
        // ⚠️ محافظت در برابر دسترسی مستقیم با حدس‌زدن id به تماس کسی که
        // در محدوده‌ی دید کاربر نیست (نگاه کنید به Auth::canViewExten)
        if (!Auth::canViewExten($call['exten_number'] ?? null)) {
            Response::forbidden('شما دسترسی به این تماس را ندارید.');
        }
        Response::success($call, 'جزئیات تماس');
    }

    // ===== PUT /api/calls/{id}/summary → خلاصه‌ی یک‌جمله‌ای تماس (ت۱۱) =====
    if ($action === 'calls' && $id && $subAction === 'summary' && in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
        $call = Call::find($id);
        if (!$call) Response::notFound('تماس یافت نشد');
        if (!Auth::canViewExten($call['exten_number'] ?? null)) Response::forbidden('شما دسترسی به این تماس را ندارید.');
        $d = Helpers::getJsonBody();
        Call::setSummary($id, (string)($d['summary'] ?? ''));
        Response::success(['summary' => Call::summaryOf($id)], 'خلاصه ذخیره شد');
    }

    // ===== PUT /api/calls/{id}/notes  → به‌روزرسانی یادداشت =====
    if ($action === 'calls' && $id && $subAction === 'notes' && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        Call::updateNotes($id, $data['notes'] ?? '');
        Response::success(Call::find($id), 'یادداشت به‌روزرسانی شد');
    }

    // ===== PATCH /api/calls/{id}/category  → تنظیم دستی دسته‌بندی تماس =====
    if ($action === 'calls' && $id && $subAction === 'category' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        $category = Call::setCategory($id, $data['category'] ?? '');
        Response::success(['id' => $id, 'category' => $category], 'دسته‌بندی تماس به‌روزرسانی شد');
    }

    // ===== PATCH /api/calls/{id}/label  → لیبل عملیاتی (استعلام/فروش/...) =====
    if ($action === 'calls' && $id && $subAction === 'label' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        $label = Call::setLabel($id, $data['label'] ?? '');
        Response::success(['id' => $id, 'label' => $label], 'لیبل تماس به‌روزرسانی شد');
    }

    // ===== GET /api/calls/categories  → لیست دسته‌بندی‌های ممکن + برچسب فارسی =====
    if ($action === 'calls' && $subAction === 'categories' && $method === 'GET') {
        Response::success(Call::$callCategoryLabels, 'دسته‌بندی‌های تماس');
    }

    // ===== GET /api/calls/labels  → لیست لیبل‌های عملیاتی =====
    if ($action === 'calls' && $subAction === 'labels' && $method === 'GET') {
        Response::success(Call::$callLabelLabels, 'لیبل‌های تماس');
    }

    // ===== GET /api/calls/missed-alerts  → هشدار تماس‌های بی‌پاسخ + تایمر =====
    if ($action === 'calls' && $subAction === 'missed-alerts' && $method === 'GET') {
        $hours = (int)($_GET['hours'] ?? 12);
        $alerts = Call::getMissedCallAlerts($hours, Auth::getVisibleExtens());
        Response::success([
            'items' => $alerts,
            'deadline_seconds' => Pipeline::missedAlertSeconds(),
            'overdue_count' => count(array_filter($alerts, function ($a) { return !empty($a['is_overdue']); })),
        ], count($alerts) . ' تماس بی‌پاسخ در صف پیگیری');
    }

    // ===== POST /api/calls/{id}/convert-to-lead  → تبدیل تماس به سرنخ =====
    if ($action === 'calls' && $id && $subAction === 'convert-to-lead' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $result = Call::convertToLead($id, $data['name'] ?? null);
        $message = $result['already_existed'] ? 'این تماس قبلاً به سرنخ تبدیل شده بود' : 'سرنخ جدید ساخته شد';
        Response::success($result, $message);
    }

    // ===== GET /api/calls/live  → پایش زنده تماس‌ها =====
    if ($action === 'calls' && $subAction === 'live' && $method === 'GET') {
        Response::success(Call::getLiveCalls(), 'تماس‌های زنده');
    }

    // ===== PATCH /api/calls/{id}/live-status  → به‌روزرسانی وضعیت زنده توسط تیم =====
    if ($action === 'calls' && $id && $subAction === 'live-status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        Call::updateLiveStatus($id, $data['live_status'] ?? '', $data['note'] ?? null);
        Response::success(null, 'وضعیت به‌روزرسانی شد');
    }
    if ($action === 'calls' && $subAction === 'auto-convert-to-leads' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $result = Call::autoGenerateLeadsFromRecentCalls($data['since_date'] ?? null);
        Response::success($result, "{$result['created_leads']} سرنخ جدید از {$result['unique_numbers_seen']} شماره‌ی یکتا ساخته شد ({$result['linked_calls']} تماس لینک شد)");
    }

    // ===== POST /api/calls/{id}/analysis/request  → ثبت درخواست تحلیل هوشمند =====
    if ($action === 'calls' && $id && $subAction === 'analysis' && ($segments[3] ?? '') === 'request' && $method === 'POST') {
        $call = Call::find($id);
        if (!$call) Response::notFound('تماس یافت نشد');
        if (empty($call['cuid'])) Response::badRequest('این تماس cuid ندارد (احتمالاً هنوز از تلفنچی همگام‌سازی نشده)');

        $api = new TelefonchyAPI();
        $result = $api->requestCallAnalysis($call['cuid']);

        if (!$result['success']) {
            Response::badRequest($result['message']);
        }

        Call::saveAiAnalysis($id, $result['data'], $result['status'], true);
        Response::success(Call::getAiAnalysis($id), $result['status'] === 'completed'
            ? 'تحلیل این تماس قبلاً کامل شده بود'
            : 'درخواست تحلیل ثبت شد - در حال پردازش');
    }

    // ===== GET /api/calls/{id}/analysis  → دریافت وضعیت/نتیجه‌ی تحلیل (poll) =====
    if ($action === 'calls' && $id && $subAction === 'analysis' && $method === 'GET') {
        $cached = Call::getAiAnalysis($id);
        $terminal = ['completed', 'failed'];

        // اگر هنوز کامل/ناموفق نشده (یا اصلاً هنوز درخواستی ثبت نشده)،
        // یک‌بار زنده از تلفنچی هم چک کن و در دیتابیس به‌روزرسانی کن -
        // این GET طبق مستندات رایگان است (از remains چیزی کم نمی‌کند)
        if (!$cached || !in_array($cached['ai_analysis_status'], $terminal, true)) {
            $call = Call::find($id);
            if ($call && !empty($call['cuid'])) {
                $api = new TelefonchyAPI();
                $result = $api->getCallAnalysis($call['cuid']);
                if ($result['success']) {
                    Call::saveAiAnalysis($id, $result['data'], $result['status']);
                    $cached = Call::getAiAnalysis($id);
                }
                // اگر خطا بود (مثلاً هنوز اصلاً درخواستی ثبت نشده - 404)،
                // چیزی جدید ذخیره نمی‌کنیم؛ همان $cached قبلی (شاید null) برمی‌گردد
            }
        }

        if (!$cached) {
            Response::success(['ai_analysis_status' => null], 'هنوز تحلیلی برای این تماس درخواست نشده');
        }
        Response::success($cached, 'وضعیت تحلیل تماس');
    }

    // ===== POST /api/calls/{id}/reload-recording  → بارگذاری مجدد دستیِ صدا =====
    // برای وقتی که پخش صدا خطا می‌دهد ("ممکن است فایل در تلفنچی وجود
    // نداشته باشد") و نمی‌خواهید صبر کنید تا کرون خودکار دوباره تلاش کند.
    // این هم وضعیت را کامل تازه می‌کند (از GET /call) و هم سقف تلاش
    // خودکار را ریست می‌کند تا اگر همین الان هم موفق نشد، سیستم خودکار
    // در کرون‌های بعدی دوباره چند بار امتحان کند.
    if ($action === 'calls' && $id && $subAction === 'reload-recording' && $method === 'POST') {
        $call = Call::find($id);
        if (!$call) Response::notFound('تماس یافت نشد');
        if (empty($call['cuid'])) Response::badRequest('این تماس cuid ندارد');

        $syncManager = new CallSyncManager();
        try {
            $syncManager->reconcileSingleCallByCuid($call['cuid']);
        } catch (\Throwable $e) {
            Logger::error('reload-recording failed for call ' . $id . ': ' . $e->getMessage());
        }
        Call::resetRecordingAttempts($id);

        $fresh = Call::find($id);
        $hasRecording = !empty($fresh['file_record']) && !empty($fresh['file_id']);

        Response::success($fresh, $hasRecording
            ? 'فایل صوتی با موفقیت به‌روزرسانی شد.'
            : 'هنوز از تلفنچی در دسترس نیست (ممکن است هنوز پردازش نشده باشد). سیستم به‌صورت خودکار در چند دقیقه‌ی آینده دوباره تلاش می‌کند.');
    }

    // ===== POST /api/calls/{id}/reconcile  → بررسی مجدد دستی وضعیت واقعی تماس از تلفنچی =====
    // برای وقتی که یک تماس به هر دلیلی روی «در حال زنگ خوردن» گیر کرده
    // (وب‌هوک hangup نرسیده) و نمی‌خواهید تا اجرای بعدی کرون صبر کنید.
    if ($action === 'calls' && $id && $subAction === 'reconcile' && $method === 'POST') {
        $call = Call::find($id);
        if (!$call) Response::notFound('تماس یافت نشد');
        if (empty($call['cuid'])) Response::badRequest('این تماس cuid ندارد');

        $syncManager = new CallSyncManager();
        try {
            $fixed = $syncManager->reconcileSingleCallByCuid($call['cuid']);
        } catch (\Throwable $e) {
            Response::badRequest('خطا در دریافت وضعیت از تلفنچی: ' . $e->getMessage());
        }

        Response::success(Call::find($id), $fixed
            ? 'وضعیت این تماس اصلاح شد'
            : 'وضعیت این تماس از تلفنچی دریافت شد (ممکن است از قبل درست بوده یا هنوز واقعاً در جریان باشد)');
    }

    // ===== POST /api/calls/{id}/transcribe  → استخراج متن مکالمه (Whisper) =====
    if ($action === 'calls' && $id && $subAction === 'transcribe' && $method === 'POST') {
        // مدل medium برای یک تماس چند دقیقه‌ای ممکن است ۳۰-۹۰ ثانیه طول
        // بکشد؛ محدودیت زمانی پیش‌فرض PHP را برای همین یک درخواست بالا می‌بریم
        set_time_limit(300);
        $result = Call::transcribe($id);
        if ($result['success']) {
            Response::success(['transcript' => $result['text']], 'متن مکالمه استخراج شد');
        } else {
            Response::json(['success' => false, 'message' => $result['message']], 422);
        }
    }

    // ===== DELETE /api/calls/{id} =====
    if ($action === 'calls' && $id && $method === 'DELETE') {
        Call::delete($id);
        Response::success(null, 'تماس حذف شد');
    }

    // ===== POST /api/sync  → همگام‌سازی دستی تماس‌ها =====
    if ($action === 'sync' && $method === 'POST' && $subAction !== 'operators') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;

        $syncManager = new CallSyncManager();
        $mode = $data['mode'] ?? '';

        // حالت "all": همگام‌سازی کل تاریخچه.
        // ⚠️ کشف مهم (با curl دستی تأیید شد): وقتی هیچ date_from/date_to
        // فرستاده نشود، تلفنچی خودش پیش‌فرض را روی «فقط امروز» می‌گذارد
        // (فیلد date_default_from/to در پاسخ همیشه امروز بود)! پس برای
        // واقعاً گرفتن کل تاریخچه، باید صریحاً از یک تاریخ خیلی قدیمی
        // شروع کنیم، نه این‌که فیلتر را خالی بگذاریم.
        if ($mode === 'all') {
            $result = $syncManager->sync('2015-01-01', date('Y-m-d'));
        }
        // حالت‌های آماده (امروز/۷ روز/۳۰ روز اخیر) - محاسبه‌ی بازه روی سرور
        elseif (isset($data['days_back'])) {
            $daysBack = (int)$data['days_back'];
            $fromGregorian = date('Y-m-d', strtotime("-{$daysBack} days"));
            $toGregorian = date('Y-m-d');
            $result = $syncManager->sync($fromGregorian, $toGregorian);
        }
        // اگر from و to ارسال شد، همگام‌سازی در بازه مشخص
        elseif (!empty($data['from_date']) && !empty($data['to_date'])) {
            // تبدیل شمسی به میلادی
            $fromGregorian = Jalali::toGregorianString($data['from_date']);
            $toGregorian = Jalali::toGregorianString($data['to_date']);
            if (!$fromGregorian || !$toGregorian) {
                Response::validationError('تاریخ شمسی نامعتبر است');
            }
            $result = $syncManager->sync(
                date('Y-m-d', strtotime($fromGregorian)),
                date('Y-m-d', strtotime($toGregorian))
            );
        } else {
            // همگام‌سازی افزایشی از آخرین همگام‌سازی
            $result = $syncManager->syncIncremental();
        }

        if ($result['success']) {
            Response::success($result, $result['message']);
        } else {
            Response::badRequest($result['message']);
        }
    }

    // ===== GET /api/play-recording?id={callId}  → پخش فایل صوتی (stream proxy) =====
    if ($action === 'play-recording' && $method === 'GET') {
        $callId = (int)($_GET['id'] ?? 0);
        if (!$callId) Response::badRequest('شناسه تماس الزامی است');

        $call = Call::find($callId);
        if (!$call) Response::notFound('تماس یافت نشد');

        if (empty($call['file_record']) || empty($call['file_id']) || empty($call['cuid'])) {
            Response::notFound('فایل صوتی برای این تماس وجود ندارد (تماس ضبط نشده یا هنوز file_id همگام‌سازی نشده)');
        }

        // دریافت فایل صوتی از تلفنچی (طبق مستندات رسمی: نیاز به file_id و cuid)
        $api = new TelefonchyAPI();
        $result = $api->downloadRecording($call['file_id'], $call['cuid']);

        if (!$result['success']) {
            Response::notFound($result['message'] ?? 'خطا در دریافت فایل صوتی');
        }

        // stream کردن فایل صوتی به مرورگر
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . ($result['contentType'] ?? 'audio/mpeg'));
            header('Content-Length: ' . strlen($result['content']));
            header('Content-Disposition: inline; filename="recording_' . $callId . '.mp3"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Accept-Ranges: bytes');
            // CORS برای پخش cross-origin
            header('Access-Control-Allow-Origin: *');
        }
        echo $result['content'];
        exit;
    }

    // ===== GET /api/export-calls  → خروجی CSV تماس‌ها =====
    if ($action === 'export-calls' && $method === 'GET') {
        $params = [
            'search'        => $_GET['search'] ?? '',
            'type'          => $_GET['type'] ?? '',
            'status'        => $_GET['status'] ?? '',
            'category'      => $_GET['category'] ?? '',
            'label'         => $_GET['label'] ?? '',
            'from_date'     => $_GET['from_date'] ?? '',
            'to_date'       => $_GET['to_date'] ?? '',
            'exten_number'  => $_GET['exten_number'] ?? '',
            'contact_id'    => $_GET['contact_id'] ?? '',
            'has_recording' => $_GET['has_recording'] ?? '',
            'sort'          => 'date_desc',
            'page'          => 1,
            'limit'         => 10000,  // حداکثر
            'visible_extens' => Auth::getVisibleExtens(),
        ];
        $result = Call::all($params);
        $calls = $result['items'];

        $headers = ['ردیف', 'تاریخ شمسی', 'نوع', 'وضعیت', 'دسته‌بندی', 'لیبل', 'مبدا', 'مقصد', 'داخلی', 'نام داخلی', 'مدت مکالمه', 'مدت انتظار', 'فایل صوتی', 'مخاطب'];
        $rows = [];
        $i = 1;
        foreach ($calls as $c) {
            $rows[] = [
                $i++,
                $c['jalali_started_at'] ?? '-',
                $c['type'] ?? '-',
                $c['status'] ?? '-',
                Call::$callCategoryLabels[$c['call_category'] ?? ''] ?? ($c['call_category'] ?? '-'),
                Call::$callLabelLabels[$c['call_label'] ?? ''] ?? ($c['call_label'] ?? '-'),
                $c['call_source'] ?? '-',
                $c['call_dest'] ?? '-',
                $c['exten_number'] ?? '-',
                $c['exten_name'] ?? '-',
                $c['talk_formatted'] ?? '-',
                $c['wait_formatted'] ?? '-',
                !empty($c['file_record']) ? 'دارد' : 'ندارد',
                $c['contact_name'] ?? ($c['lead_name'] ?? '-'),
            ];
        }

        $filename = 'calls-export-' . date('Y-m-d-His') . '.csv';
        Response::csv($filename, $headers, $rows);
    }

    // ===== GET /api/sync/status  → وضعیت همگام‌سازی =====
    if ($action === 'sync' && $subAction === 'status' && $method === 'GET') {
        $lastSync = Report::getSetting('last_sync_at');
        Response::success([
            'last_sync_at'       => $lastSync,
            'last_sync_jalali'   => $lastSync ? Jalali::fromGregorian($lastSync, 'Y/m/d H:i') : null,
            'is_configured'      => (new TelefonchyAPI())->isConfigured(),
            'service_id'         => Report::getSetting('telefonchy_service_id'),
        ], 'وضعیت همگام‌سازی');
    }

    // ===== POST /api/sync/operators → همگام‌سازی داخلی‌ها/اپراتورها با تلفنچی =====
    if ($action === 'sync' && $subAction === 'operators' && $method === 'POST') {
        Auth::requireAdmin();
        $r = (new CallSyncManager())->syncOperators();
        if (!empty($r['success'])) Response::success($r, 'اپراتورها همگام شدند');
        Response::badRequest($r['message'] ?? 'خطا در همگام‌سازی اپراتورها');
    }

    // ===== GET /api/sync/test  → تست اتصال به تلفنچی =====
    if ($action === 'sync' && $subAction === 'test' && $method === 'GET') {
        $api = new TelefonchyAPI();
        if (!$api->isConfigured()) {
            Response::badRequest('تنظیمات تلفنچی کامل نیست');
        }
        $result = $api->getServiceInfo();
        if ($result['success']) {
            Response::success($result['data'], 'اتصال به تلفنچی برقرار است');
        } else {
            Response::badRequest($result['message'] ?? 'خطا در اتصال به تلفنچی');
        }
    }

    Response::notFound('مسیر تماس‌ها یافت نشد');
}

function handlePipelineRoutes(array $segments, $method)
{
    $user = Auth::currentUser();
    $action = $segments[1] ?? '';
    $data = [];
    if ($method !== 'GET') { $data = Helpers::getJsonBody(); if (empty($data)) $data = $_POST; }
    session_write_close();

    // GET /api/pipeline/alerts → زنگوله‌ی هدر (برای همه‌ی کاربران)
    if ($action === 'alerts' && $method === 'GET') {
        if (!Auth::hasPermission('calls')) Response::success(['missed_overdue' => [], 'missed_overdue_count' => 0, 'stage_warn' => []]);
        Response::success(Pipeline::alerts($user), 'هشدارها');
    }
    if (!Auth::hasPermission('calls')) Response::forbidden('دسترسی به تماس‌ها ندارید');

    // GET /api/pipeline/counts
    if ($action === 'counts' && $method === 'GET') {
        try { Pipeline::processNewCalls(1500); } catch (\Throwable $e) { Logger::error('pipeline process: ' . $e->getMessage()); }
        Response::success(Pipeline::counts($user, $_GET['assignee'] ?? ''), 'شمارش مراحل');
    }
    // GET /api/pipeline/list?stage=..
    if ($action === 'list' && $method === 'GET') {
        $stage = $_GET['stage'] ?? 'followup';
        if (!isset(Pipeline::$stages[$stage])) Response::validationError('مرحله نامعتبر');
        $withHist = in_array($stage, ['reserved', 'not_reserved'], true) || !empty($_GET['history']);
        Response::success(Pipeline::listByStage($user, $stage, ['assignee' => $_GET['assignee'] ?? '', 'search' => $_GET['search'] ?? '', 'last' => $_GET['last'] ?? '', 'with_history' => $withHist]), 'لیست');
    }
    // GET /api/pipeline/users → لیست کاربران برای ارجاع
    if ($action === 'users' && $method === 'GET') {
        Response::success(Pipeline::assignableUsers(), 'کاربران');
    }
    // GET /api/pipeline/refs → گزینه‌های «مرجع» و «نقش»
    if ($action === 'refs' && $method === 'GET') {
        Response::success(['refs' => Pipeline::refOptions(), 'roles' => Pipeline::$roles], 'مراجع');
    }
    // GET/PATCH /api/pipeline/settings
    if ($action === 'settings') {
        if ($method === 'GET') Response::success(['missed_alert_minutes' => (int)(Pipeline::missedAlertSeconds() / 60), 'pipeline_max_days' => Pipeline::maxDays(), 'pipeline_auto_assign_minutes' => Pipeline::autoAssignMinutes(), 'pipeline_auto_assign_users' => Pipeline::autoAssignPool()]);
        if (!Pipeline::isManager($user)) Response::forbidden('فقط مدیر');
        $db = Database::getInstance();
        foreach (['missed_alert_minutes' => [1, 1440], 'pipeline_max_days' => [1, 30], 'pipeline_auto_assign_minutes' => [0, 1440]] as $k => $rg) {
            if (isset($data[$k])) {
                $v = max($rg[0], min($rg[1], (int)$data[$k]));
                $db->execute('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, (string)$v]);
            }
        }
        if (isset($data['pipeline_auto_assign_users']) && is_array($data['pipeline_auto_assign_users'])) {
            $names = array_values(array_unique(array_filter(array_map(function ($n) { return preg_replace('/\s+/u', ' ', trim((string)$n)); }, $data['pipeline_auto_assign_users']))));
            $db->execute('INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['pipeline_auto_assign_users', json_encode($names, JSON_UNESCAPED_UNICODE)]);
        }
        Response::success(null, 'تنظیمات ذخیره شد');
    }
    // POST /api/pipeline/smart-names → نام‌گذاری هوشمند از سایت (فقط مدیر)
    if ($action === 'smart-names' && $method === 'POST') {
        if (!Pipeline::isManager($user)) Response::forbidden('فقط مدیر');
        set_time_limit(300);
        $r = Pipeline::smartFillNames();
        Response::success($r, ($r['site'] + $r['customer'] + $r['lead']) . ' شماره نام‌گذاری شد');
    }

    // /api/pipeline/number/{phone}/...
    if ($action === 'number') {
        $p10 = Pipeline::norm($segments[2] ?? '');
        if (!$p10) Response::validationError('شماره نامعتبر');
        $sub = $segments[3] ?? '';
        $row = Pipeline::get($p10);
        if ($sub === '' && $method === 'GET') {
            if ($row) {
                $row['stage_label'] = $row['stage'] ? Pipeline::$stages[$row['stage']] : null;
                $row['assigned_name'] = $row['assigned_user_id'] ? Database::getInstance()->fetchColumn('SELECT full_name FROM users WHERE id = ?', [$row['assigned_user_id']]) : null;
                $row['remaining_seconds'] = in_array($row['stage'], Pipeline::$openStages, true) && $row['cycle_started_at'] ? Pipeline::maxDays() * 86400 - (time() - strtotime($row['cycle_started_at'])) : null;
            }
            Response::success(['number' => $row, 'history' => Pipeline::historyFor([$p10])[$p10] ?? [], 'stages' => Pipeline::$stages,
                'can_edit' => Pipeline::canAccess($user, $row), 'is_manager' => Pipeline::isManager($user)], 'شماره');
        }
        if (!Pipeline::canAccess($user, $row)) Response::forbidden('این شماره به همکار دیگری ارجاع شده است');
        if ($sub === 'stage' && in_array($method, ['PATCH', 'POST'], true)) {
            $to = $data['stage'] ?? '';
            if (!isset(Pipeline::$stages[$to])) Response::validationError('مرحله نامعتبر');
            $note = trim((string)($data['note'] ?? ''));
            $changed = Pipeline::setStage($p10, $to, ['user' => $user, 'reason' => $note !== '' ? $note : null, 'force_log' => $note !== '']);
            // کسی که مرحله را جلو می‌برد، اگر مسئولی نیست، مسئول می‌شود
            if (empty($row['assigned_user_id']) && !Pipeline::isManager($user)) {
                Database::getInstance()->execute('UPDATE call_numbers SET assigned_user_id = ?, assigned_at = NOW() WHERE phone10 = ?', [$user['id'], $p10]);
            }
            Response::success(Pipeline::get($p10), $changed ? 'مرحله به «' . Pipeline::$stages[$to] . '» تغییر کرد' : 'بدون تغییر');
        }
        if ($sub === 'name' && in_array($method, ['PATCH', 'POST', 'PUT'], true)) {
            Pipeline::setName($p10, trim((string)($data['name'] ?? '')), 'manual');
            Response::success(Pipeline::get($p10), 'نام ذخیره شد');
        }
        if ($sub === 'ref' && in_array($method, ['PATCH', 'POST', 'PUT'], true)) {
            Pipeline::setRef($p10, (string)($data['ref_source'] ?? ''), (string)($data['ref_role'] ?? ''));
            Response::success(Pipeline::get($p10), 'مرجع و نقش ذخیره شد');
        }
        if ($sub === 'called' && in_array($method, ['PATCH', 'POST', 'PUT'], true)) {
            Pipeline::setCalledBack($p10, !empty($data['value']), $user);
            Response::success(Pipeline::get($p10), !empty($data['value']) ? 'ثبت شد: تماس گرفته شد' : 'برچسب برداشته شد');
        }
        if ($sub === 'assign' && in_array($method, ['PATCH', 'POST'], true)) {
            if (!Pipeline::isManager($user)) Response::forbidden('فقط مدیر می‌تواند ارجاع بدهد');
            Pipeline::assign($p10, (int)($data['user_id'] ?? 0), $user);
            Response::success(Pipeline::get($p10), 'ارجاع ثبت شد');
        }
    }
    Response::notFound('مسیر پیگیری یافت نشد');
}

function handleReportRoutes(array $segments, $method)
{
    $action = $segments[1] ?? '';

    // GET /api/reports  → همه گزارش‌ها در یک درخواست
    if ($action === '' && $method === 'GET') {
        $data = [
            'customerStats'   => Customer::getStats(),
            'hostStats'       => Host::getStats(),
            'leadStats'       => Lead::getStats(),
            'hostLeadStats'   => HostLead::getStats(),
            'ticketStats'     => Ticket::getStats(),
            'taskStats'       => Task::getStats(),
            'callStats'       => Call::getStats(),
            'leaveStats'      => Leave::getStats(),
            'salesReport'     => Report::getSalesReport(),
            'revenueChart'    => Report::getRevenueChart(),
            'reservationsChart' => Report::getReservationsChart(),
        ];
        Response::success($data, 'گزارش‌های سیستم');
    }

    // GET /api/reports/sales  → گزارش فروش
    if ($action === 'sales' && $method === 'GET') {
        $fromDate = $_GET['from'] ?? null;
        $toDate = $_GET['to'] ?? null;
        Response::success(Report::getSalesReport($fromDate, $toDate), 'گزارش فروش');
    }

    // GET /api/reports/export-customers  → خروجی CSV مشتریان
    if ($action === 'export-customers' && $method === 'GET') {
        $result = Customer::all(['limit' => 10000]);
        $customers = $result['items'];
        $headers = ['ردیف', 'نام', 'موبایل', 'ایمیل', 'شهر', 'سطح', 'رزرو', 'مجموع خرید', 'وضعیت', 'تاریخ عضویت'];
        $rows = [];
        $i = 1;
        foreach ($customers as $c) {
            $rows[] = [
                $i++, $c['name'], $c['mobile'] ?? '', $c['email'] ?? '',
                $c['city'] ?? '', $c['level'] ?? '', $c['reservations'] ?? 0,
                $c['total_spend'] ?? 0, $c['status'] ?? '', $c['join_date'] ?? ''
            ];
        }
        Response::csv('customers-report-' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // GET /api/reports/export-staff  → خروجی CSV عملکرد پرسنل
    if ($action === 'export-staff' && $method === 'GET') {
        $allStaff = Database::getInstance()->fetchAll('SELECT id, code, name, role, unit FROM staff ORDER BY name');
        $headers = ['کد', 'نام', 'سمت', 'واحد', 'تعداد تسک', 'تسک انجام شده', 'نرخ تکمیل'];
        $rows = [];
        foreach ($allStaff as $s) {
            $tasks = Staff::getTasks($s['id']);
            $taskCount = count($tasks);
            $doneCount = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
            $rate = $taskCount > 0 ? round(($doneCount / $taskCount) * 100, 1) : 0;
            $rows[] = [$s['code'], $s['name'], $s['role'] ?? '', $s['unit'] ?? '', $taskCount, $doneCount, $rate . '%'];
        }
        Response::csv('staff-performance-' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // GET /api/reports/operators  → گزارش ساعتی/روزانه/هفتگی اپراتورها
    if ($action === 'operators' && $method === 'GET') {
        $period = $_GET['period'] ?? 'daily';
        $from = $_GET['from_date'] ?? $_GET['from'] ?? null;
        $to = $_GET['to_date'] ?? $_GET['to'] ?? null;
        $exten = $_GET['exten'] ?? null;
        Response::success(
            Call::getOperatorMetrics($period, $from, $to, $exten),
            'گزارش عملکرد اپراتورها'
        );
    }

    // ===== گزارش‌های فردی پرسنل (بله / دستی / ایمپورت) =====
    // GET /api/reports/staff-notes
    if ($action === 'staff-notes' && $method === 'GET') {
        $params = [
            'staff_id' => $_GET['staff_id'] ?? '',
            'source'   => $_GET['source'] ?? '',
            'search'   => $_GET['search'] ?? '',
            'page'     => $_GET['page'] ?? 1,
            'limit'    => $_GET['limit'] ?? 20,
        ];
        $result = StaffReport::all($params);
        Response::paginated($result['items'], $result['total'], $params['page'], $params['limit'], 'گزارش‌های فردی');
    }
    // POST /api/reports/staff-notes
    if ($action === 'staff-notes' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = StaffReport::create($data);
        Response::success(['id' => $id], 'گزارش ثبت شد', [], 201);
    }
    // POST /api/reports/staff-notes/import
    if ($action === 'staff-notes' && ($segments[2] ?? '') === 'import' && $method === 'POST') {
        Auth::requireAdmin();
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $items = $data['items'] ?? $data;
        if (!is_array($items)) Response::validationError('آرایه items الزامی است');
        $result = StaffReport::importBatch($items);
        Response::success($result, sprintf('%d گزارش وارد شد، %d رد شد', $result['created'], $result['skipped']));
    }
    // DELETE /api/reports/staff-notes/{id}
    if ($action === 'staff-notes' && isset($segments[2]) && ctype_digit((string)$segments[2]) && $method === 'DELETE') {
        StaffReport::delete((int)$segments[2]);
        Response::success(null, 'گزارش حذف شد');
    }

    Response::notFound('مسیر گزارشات یافت نشد');
}

function handleSettingsRoutes(array $segments, $method)
{
    // GET /api/settings  → لیست همه تنظیمات
    if ($method === 'GET' && empty($segments[1])) {
        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT key_name, value FROM settings ORDER BY key_name');
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['key_name']] = $r['value'];
        }
        Response::success($settings, 'تنظیمات سیستم');
    }

    // PUT /api/settings  → به‌روزرسانی دسته‌ای
    if ($method === 'PUT' && empty($segments[1])) {
        Auth::requireAdmin();
        $body = Helpers::getJsonBody();
        if (empty($body)) {
            Response::validationError('داده‌ای ارسال نشده است');
        }
        $db = Database::getInstance();
        $updated = 0;
        $rejected = [];
        foreach ($body as $key => $value) {
            // ⚠️ کلیدهای مجاز برای به‌روزرسانی - هر کلید تنظیمات جدیدی که
            // به settings.php اضافه می‌شود باید اینجا هم اضافه شود، وگرنه
            // ذخیره‌اش بی‌صدا شکست می‌خورد (نه خطا می‌دهد، نه واقعاً ذخیره
            // می‌شود) - دقیقاً همین اتفاق برای کلید GapGPT افتاد تا وقتی
            // که کشف شد. الان حداقل در پاسخ (rejected) گزارش می‌شود تا
            // دفعه‌ی بعد سریع‌تر پیدا شود.
            $allowed = ['system_name', 'primary_color', 'telefonchy_service_id',
                       'telefonchy_token', 'telefonchy_base_url', 'commission_rate',
                       'min_settlement_amount', 'settlement_day',
                       'transcription_provider', 'vosk_worker_url', 'ffmpeg_path',
                       'groq_api_key', 'gapgpt_api_key', 'gapgpt_transcription_model', 'openai_api_key',
                       'elanban_base_url', 'elanban_token', 'elanban_incoming_token',
                       'site_db_host', 'site_db_port', 'site_db_name', 'site_db_user', 'site_db_pass',
                       'site_table_customers', 'site_table_hosts', 'site_table_properties',
                       'site_table_inquiries', 'site_table_sales', 'site_last_sync_at',
                       'sms_provider', 'sms_api_key', 'sms_password', 'sms_sender', 'sms_generic_url',
                       'sms_missed_enabled', 'sms_missed_template', 'sms_missed_delay_minutes'];
            if (in_array($key, $allowed, true)) {
                $db->execute(
                    'INSERT INTO settings (key_name, value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value)',
                    [$key, (string)$value]
                );
                $updated++;
            } else {
                $rejected[] = $key;
                Logger::error('Settings save: کلید غیرمجاز نادیده گرفته شد (باید به لیست $allowed اضافه شود)', ['key' => $key]);
            }
        }
        Response::success(
            ['updated' => $updated, 'rejected' => $rejected],
            $rejected ? ('تنظیمات ذخیره شد، ولی این کلیدها ناشناخته بودند و ذخیره نشدند: ' . implode('، ', $rejected)) : 'تنظیمات به‌روزرسانی شد'
        );
    }

    // GET /api/settings/vosk-health  → چک آنی سلامت سرویس Vosk worker
    if ($method === 'GET' && ($segments[1] ?? '') === 'vosk-health') {
        Response::success([
            'healthy'      => TranscriptionService::checkVoskWorkerHealth(),
            'exec_enabled' => TranscriptionService::isExecEnabled(),
        ], 'وضعیت سرویس Vosk');
    }

    // GET /api/settings/telefonchy-logs  → لیست لاگ‌های تعامل با تلفنچی
    if ($method === 'GET' && ($segments[1] ?? '') === 'telefonchy-logs') {
        $params = [
            'only_errors' => $_GET['only_errors'] ?? '',
            'direction'   => $_GET['direction'] ?? '',
            'page'        => $_GET['page'] ?? 1,
            'limit'       => $_GET['limit'] ?? 50,
        ];
        $result = TelefonchyApiLog::all($params);
        $stats = TelefonchyApiLog::getStats();
        Response::success(['items' => $result['items'], 'total' => $result['total'], 'stats' => $stats], 'لاگ‌های تلفنچی');
    }

    // DELETE /api/settings/telefonchy-logs  → پاک کردن همه‌ی لاگ‌ها
    if ($method === 'DELETE' && ($segments[1] ?? '') === 'telefonchy-logs') {
        Auth::requireAdmin();
        TelefonchyApiLog::clear();
        Response::success(null, 'لاگ‌ها پاک شدند');
    }

    // GET /api/settings/telefonchy-events  → مشاهده‌ی خام رویدادهای webhook
    // (پاسخ به «تلفنچی دقیقاً چی می‌فرسته؟» بدون نیاز به SSH/phpMyAdmin)
    if ($method === 'GET' && ($segments[1] ?? '') === 'telefonchy-events') {
        $params = [
            'page'  => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
            'cuid'  => $_GET['cuid'] ?? '',
        ];
        $result = TelefonchyEvent::all($params);
        Response::success(['items' => $result['items'], 'total' => $result['total']], 'رویدادهای خام webhook');
    }

    // GET /api/settings/telefonchy-events/{id}  → جزئیات کامل یک رویداد (raw_body خام)
    if ($method === 'GET' && ($segments[1] ?? '') === 'telefonchy-events' && !empty($segments[2])) {
        $event = TelefonchyEvent::find($segments[2]);
        if (!$event) Response::notFound('رویداد یافت نشد');
        Response::success($event, 'جزئیات رویداد خام');
    }

    // DELETE /api/settings/telefonchy-events  → پاک کردن همه‌ی رویدادهای خام
    if ($method === 'DELETE' && ($segments[1] ?? '') === 'telefonchy-events') {
        Auth::requireAdmin();
        TelefonchyEvent::clear();
        Response::success(null, 'رویدادهای خام پاک شدند');
    }


    // POST /api/settings/migrate  → اجرای دستی migrationهای خودکار دیتابیس
    // (برای وقتی که فقط فایل‌های PHP را جایگزین کرده‌اید و می‌خواهید بدون
    // صبر کردن برای اولین بازدید از هر صفحه، ستون‌ها/جدول‌های جدید ساخته شوند)
    //
    // ⚠️ رفع باگ شفافیت: قبلاً این endpoint فقط یک لیست ثابت از برچسب‌های
    // «انجام شد» برمی‌گرداند (حتی وقتی هیچ ستون جدیدی واقعاً ساخته نشده
    // بود یا یک migration خطا داده بود - خطا فقط در logs/error.log ثبت
    // می‌شد که کارفرما بدون دسترسی FTP/SSH نمی‌توانست ببیندش). الان هر
    // مرحله دقیقاً می‌گوید: چه ستون‌های *جدیدی* واقعاً ساخته شد (added)،
    // چیزی که از قبل وجود داشت اصلاً ذکر نمی‌شود، و اگر مرحله‌ای خطا داد
    // پیام خطای واقعی (نه فقط «ناموفق») در پاسخ برمی‌گردد.
    if ($method === 'POST' && ($segments[1] ?? '') === 'migrate') {
        Auth::requireAdmin();
        $added = [];
        $errors = [];

        $steps = [
            'calls (file_id/lead_id/transcript/category/backfill/live_status/ai_analysis)' => function () { return Call::migrate(); },
            'customers (yas fields + site_user_id)' => function () { return Customer::migrate(); },
            'hosts.site_host_id' => function () { return Host::migrate(); },
            'host_properties (+images)' => function () { return HostProperty::migrate(); },
            'site_inquiries' => function () { return SiteInquiry::migrate(); },
            'site_sales' => function () { return SiteSale::migrate(); },
            'staff_reports' => function () { return StaffReport::migrate(); },
            'telefonchy_api_logs' => function () {
                TelefonchyApiLog::getStats(); // این متد داخلی ensureTable را صدا می‌زند
                return ['added' => [], 'errors' => []];
            },
            'leads' => function () {
                if (class_exists('Lead') && method_exists('Lead', 'runAutoMigrate')) Lead::runAutoMigrate();
                return ['added' => [], 'errors' => []];
            },
            'users.permissions' => function () { User::migrate(); return ['added' => [], 'errors' => []]; },
            'staff.user_id' => function () { Staff::migrate(); return ['added' => [], 'errors' => []]; },
            'leaves (ساعتی)' => function () { Leave::migrate(); return ['added' => [], 'errors' => []]; },
        ];

        foreach ($steps as $label => $fn) {
            try {
                $result = $fn();
                if (!empty($result['added'])) {
                    $added = array_merge($added, $result['added']);
                }
                if (!empty($result['errors'])) {
                    foreach ($result['errors'] as $err) $errors[] = "{$label}: {$err}";
                }
            } catch (\Throwable $e) {
                $errors[] = "{$label}: " . $e->getMessage();
                Logger::error("Migrate step failed [{$label}]: " . $e->getMessage());
            }
        }

        $message = empty($errors)
            ? (empty($added) ? 'همه‌چیز از قبل به‌روز بود - هیچ ستون جدیدی لازم نبود.' : count($added) . ' مورد جدید ساخته شد.')
            : 'با ' . count($errors) . ' خطا مواجه شد - جزئیات را ببینید.';

        Response::success(['added' => $added, 'errors' => $errors], $message);
    }

    // POST /api/settings/reconcile-stale-calls  → اصلاح دستیِ گروهی تماس‌های
    // گیرکرده روی «در حال زنگ خوردن» (بدون صبر برای اجرای بعدی کرون)
    if ($method === 'POST' && ($segments[1] ?? '') === 'reconcile-stale-calls') {
        Auth::requireAdmin();
        $syncManager = new CallSyncManager();
        try {
            // ⚠️ قبلاً ۱۰۰ بود - همراه با نبودِ فاصله بین درخواست‌ها باعث
            // burst و مسدودسازی موقت توسط تلفنچی شد. الان هم عدد کمتر و
            // هم فاصله‌ی اجباری بین درخواست‌ها (داخل خودِ متد) وجود دارد.
            $result = $syncManager->reconcileStaleRingingCalls(20);
        } catch (\Throwable $e) {
            Response::badRequest('خطا: ' . $e->getMessage());
        }
        $msg = "{$result['checked']} تماس گیرکرده بررسی شد، {$result['fixed']} مورد اصلاح شد.";
        if (!empty($result['stopped_early'])) {
            $msg .= ' ⚠️ به‌خاطر چند خطای پشت‌سرهم (احتمال rate-limit تلفنچی) زودتر متوقف شد - چند دقیقه دیگر دوباره امتحان کنید یا صبر کنید کرون خودکار ادامه بدهد.';
        }
        Response::success($result, $msg);
    }

    // GET /api/settings/error-log?lines=200  → آخرین خطوط logs/error.log
    // بدون نیاز به دسترسی FTP/SSH - چون Logger::error/info فقط در این فایل
    // ثبت می‌شوند و تا الان هیچ راهی برای دیدنشان از داخل پنل نبود.
    if ($method === 'GET' && ($segments[1] ?? '') === 'error-log') {
        Auth::requireAdmin();
        $lines = max(20, min(1000, (int)($_GET['lines'] ?? 200)));
        $logFile = LOG_PATH . '/error.log';

        if (!file_exists($logFile)) {
            Response::success(['lines' => [], 'file_size' => 0], 'فایل لاگ هنوز ساخته نشده (یعنی هنوز هیچ خطایی ثبت نشده)');
        }

        // خواندن فقط N خط آخر بدون لود کردن کل فایل در حافظه (فایل لاگ
        // می‌تواند خیلی بزرگ شود)
        $fileSize = filesize($logFile);
        $chunkSize = 65536;
        $handle = fopen($logFile, 'r');
        $buffer = '';
        $pos = $fileSize;
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }
        fclose($handle);

        $allLines = explode("\n", trim($buffer));
        $lastLines = array_slice($allLines, -$lines);

        Response::success([
            'lines' => array_reverse($lastLines), // جدیدترین اول
            'file_size' => $fileSize,
            'file_size_human' => round($fileSize / 1024 / 1024, 2) . ' MB',
        ], count($lastLines) . ' خط آخر لاگ');
    }

    // GET /api/settings/transcription-cost-estimate
    if ($method === 'GET' && ($segments[1] ?? '') === 'transcription-cost-estimate') {
        Response::success(TranscriptionService::estimateMonthlyCost(), 'تخمین هزینه');
    }

    Response::notFound('مسیر تنظیمات یافت نشد');
}

/**
 * مدیریت کاربران و دسترسی‌ها - فقط ادمین (نگاه کنید به dispatcher بالا:
 * Auth::requireAdmin() قبل از فراخوانی این تابع اجرا شده)
 */
function handleUserRoutes(array $segments, $method)
{
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $action = ($id !== null) ? (string)$id : $seg1;
    $subAction = $segments[2] ?? '';

    // GET /api/users  → لیست کاربران
    if ($action === '' && $method === 'GET') {
        Response::success(['items' => User::all(), 'permission_keys' => User::$permissionKeys], 'لیست کاربران');
    }

    // POST /api/users  → ایجاد کاربر جدید
    if ($action === '' && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $id = User::create($data);
        Response::success(User::find($id), 'کاربر جدید ایجاد شد', [], 201);
    }

    // GET /api/users/permission-keys  → لیست کلیدهای دسترسی قابل تعریف
    if ($action === 'permission-keys' && $method === 'GET') {
        Response::success(User::$permissionKeys, 'کلیدهای دسترسی');
    }

    // POST /api/users/generate-for-all-staff  → تبدیل خودکار همه‌ی پرسنل به کاربر
    if ($action === 'generate-for-all-staff' && $method === 'POST') {
        $results = User::bulkCreateForAllStaff();
        Response::success(['created' => $results], count($results) . ' حساب کاربری جدید ساخته شد');
    }

    // POST /api/users/generate-for-staff/{staffId}
    if ($action === 'generate-for-staff' && $subAction && $method === 'POST') {
        $result = User::createForStaff((int)$subAction);
        $message = $result['already_existed'] ? 'این پرسنل قبلاً حساب کاربری داشت' : 'حساب کاربری ساخته شد';
        Response::success($result, $message);
    }

    // GET /api/users/{id}
    if ($id && $method === 'GET') {
        $user = User::find($id);
        if (!$user) Response::notFound('کاربر یافت نشد');
        Response::success($user, 'جزئیات کاربر');
    }

    // PUT /api/users/{id}  → ویرایش (شامل تغییر دسترسی‌ها)
    if ($id && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        User::update($id, $data);
        Response::success(User::find($id), 'کاربر به‌روزرسانی شد');
    }

    // DELETE /api/users/{id}
    if ($id && $method === 'DELETE') {
        User::delete($id);
        Response::success(null, 'کاربر حذف شد');
    }

    Response::notFound('مسیر کاربران یافت نشد');
}

function handleSiteInquiryRoutes(array $segments, $method)
{
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $subAction = ($id !== null) ? ($segments[2] ?? '') : $seg1;

    if ($id === null && $subAction === '' && $method === 'GET') {
        $result = SiteInquiry::all([
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
        ]);
        Response::paginated($result['items'], $result['total'], (int)($_GET['page'] ?? 1), (int)($_GET['limit'] ?? 20), 'لیست استعلام‌های سایت');
    }
    if ($id === null && $subAction === 'stats' && $method === 'GET') {
        Response::success(SiteInquiry::getStats(), 'آمار استعلام‌ها');
    }
    if ($id && $subAction === 'convert-to-lead' && $method === 'POST') {
        $result = SiteInquiry::convertToLead($id);
        Response::success($result, !empty($result['already_existed']) ? 'قبلاً به سرنخ تبدیل شده' : 'سرنخ از استعلام ساخته شد');
    }
    if ($id && $subAction === 'status' && $method === 'PATCH') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        SiteInquiry::updateStatus($id, $data['status'] ?? '', $data['assignee'] ?? null);
        Response::success(SiteInquiry::find($id), 'وضعیت به‌روزرسانی شد');
    }
    if ($id && $method === 'GET') {
        $row = SiteInquiry::find($id);
        if (!$row) Response::notFound('استعلام یافت نشد');
        Response::success($row, 'جزئیات استعلام');
    }
    Response::notFound('مسیر استعلام سایت یافت نشد');
}

function handleSiteSaleRoutes(array $segments, $method)
{
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $subAction = ($id !== null) ? ($segments[2] ?? '') : $seg1;

    if ($id === null && $subAction === '' && $method === 'GET') {
        $result = SiteSale::all([
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'from_date' => $_GET['from_date'] ?? '',
            'to_date' => $_GET['to_date'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 20,
        ]);
        Response::success($result['items'], 'لیست فروش سایت', [
            'total' => (int)$result['total'],
            'page' => (int)($_GET['page'] ?? 1),
            'limit' => (int)($_GET['limit'] ?? 20),
            'sum_amount' => (int)($result['sum_amount'] ?? 0),
        ]);
    }
    if ($id === null && $subAction === 'stats' && $method === 'GET') {
        Response::success(SiteSale::getStats(), 'آمار فروش سایت');
    }
    Response::notFound('مسیر فروش سایت یافت نشد');
}

function handleSmsRoutes(array $segments, $method)
{
    $action = $segments[1] ?? '';
    $sms = new SmsService();

    // GET /api/sms/status
    if ($action === 'status' && $method === 'GET') {
        Response::success([
            'configured' => $sms->isConfigured(),
            'provider' => Report::getSetting('sms_provider', ''),
            'missed_enabled' => Report::getSetting('sms_missed_enabled', '0') === '1',
            'sender' => Report::getSetting('sms_sender', ''),
        ], 'وضعیت پنل پیامک');
    }

    // POST /api/sms/test  → ارسال پیامک تست
    if ($action === 'test' && $method === 'POST') {
        Auth::requireAdmin();
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $to = $data['to'] ?? '';
        $message = $data['message'] ?? ('تست پیامک جاکجاست CRM — ' . date('Y-m-d H:i'));
        $result = $sms->send($to, $message);
        if (empty($result['success'])) {
            Response::badRequest($result['message'] ?? 'ارسال ناموفق');
        }
        Response::success($result, $result['message']);
    }

    // POST /api/sms/send
    if (($action === '' || $action === 'send') && $method === 'POST') {
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $result = $sms->send($data['to'] ?? '', $data['message'] ?? '');
        if (empty($result['success'])) {
            Response::badRequest($result['message'] ?? 'ارسال ناموفق');
        }
        Response::success($result, $result['message']);
    }

    // POST /api/sms/process-missed  → اجرای دستی صف پیامک بی‌پاسخ
    if ($action === 'process-missed' && $method === 'POST') {
        Auth::requireAdmin();
        $result = $sms->processMissedCallSms();
        Response::success($result, sprintf(
            'پیامک بی‌پاسخ: %d ارسال، %d رد، %d ناموفق',
            $result['sent'], $result['skipped'], $result['failed']
        ));
    }

    Response::notFound('مسیر پیامک یافت نشد');
}

function handleSiteSyncRoutes(array $segments, $method)
{
    $action = $segments[1] ?? '';
    $sync = new SiteSyncManager();

    if ($action === 'test' && $method === 'GET') {
        Response::success($sync->testConnection(), 'نتیجه تست اتصال سایت');
    }
    if (($action === '' || $action === 'run') && $method === 'POST') {
        Auth::requireAdmin();
        $data = Helpers::getJsonBody();
        if (empty($data)) $data = $_POST;
        $modules = $data['modules'] ?? ['customers', 'hosts', 'properties', 'inquiries', 'sales'];
        if (!is_array($modules)) $modules = ['customers', 'hosts', 'properties', 'inquiries', 'sales'];
        $result = $sync->sync($modules);
        if (empty($result['success'])) {
            Response::badRequest($result['message'] ?? 'همگام‌سازی ناموفق');
        }
        Response::success($result['stats'], $result['message']);
    }
    if ($action === 'status' && $method === 'GET') {
        Response::success([
            'configured' => $sync->isConfigured(),
            'last_sync_at' => Report::getSetting('site_last_sync_at'),
            'tables' => [
                'customers' => Report::getSetting('site_table_customers', 'users'),
                'hosts' => Report::getSetting('site_table_hosts', 'hosts'),
                'properties' => Report::getSetting('site_table_properties', 'properties'),
                'inquiries' => Report::getSetting('site_table_inquiries', 'inquiries'),
                'sales' => Report::getSetting('site_table_sales', 'orders'),
            ],
        ], 'وضعیت همگام‌سازی سایت');
    }
    Response::notFound('مسیر همگام‌سازی سایت یافت نشد');
}

function handleHostPropertyRoutes(array $segments, $method)
{
    $seg1 = $segments[1] ?? '';
    $id = ($seg1 !== '' && ctype_digit((string)$seg1)) ? (int)$seg1 : null;
    $subAction = ($id !== null) ? ($segments[2] ?? '') : $seg1;

    if (!defined('BASE_URL')) {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        define('BASE_URL', rtrim($scriptDir, '/'));
    }

    if ($id && $subAction === '' && $method === 'PUT') {
        $data = Helpers::getJsonBody();
        if (empty($data)) parse_str(file_get_contents('php://input'), $data);
        HostProperty::update($id, $data);
        Response::success(HostProperty::find($id), 'اقامتگاه به‌روزرسانی شد');
    }
    if ($id && $subAction === '' && $method === 'DELETE') {
        HostProperty::delete($id);
        Response::success(null, 'اقامتگاه حذف شد');
    }
    if ($id && $subAction === 'images' && $method === 'POST') {
        if (empty($_FILES['image'])) {
            Response::validationError('فایل تصویر ارسال نشده');
        }
        $file = $_FILES['image'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::validationError('خطا در آپلود فایل');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) {
            Response::validationError('فقط تصویر JPG/PNG/WEBP/GIF مجاز است');
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            Response::validationError('حداکثر حجم تصویر ۵ مگابایت است');
        }
        $dir = ROOT_PATH . '/uploads/properties/' . (int)$id;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Response::serverError('ذخیره فایل ناموفق بود');
        }
        $relative = 'uploads/properties/' . (int)$id . '/' . $name;
        $imgId = HostProperty::addImage($id, $relative);
        Response::success([
            'id' => $imgId,
            'file_path' => $relative,
            'url' => BASE_URL . '/' . $relative,
        ], 'تصویر افزوده شد', [], 201);
    }
    if ($id && $subAction === 'images' && isset($segments[3]) && ctype_digit((string)$segments[3]) && $method === 'DELETE') {
        HostProperty::deleteImage((int)$segments[3]);
        Response::success(null, 'تصویر حذف شد');
    }
    if ($id && $method === 'GET') {
        $row = HostProperty::find($id);
        if (!$row) Response::notFound('اقامتگاه یافت نشد');
        Response::success($row, 'جزئیات اقامتگاه');
    }
    Response::notFound('مسیر اقامتگاه یافت نشد');
}
