<?php
/**
 * جاکجاست - توابع کمکی
 * Jakojast - Helper Functions
 *
 * توابع عمومی برای: پاک‌سازی ورودی، فرمت‌بندی اعداد،
 * تولید آواتار، اعتبارسنجی، تبدیل اعداد فارسی و ...
 */

class Helpers
{
    /**
     * پاک‌سازی خروجی برای جلوگیری از XSS
     * @param mixed $value
     * @return string
     */
    public static function e($value)
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * پاک‌سازی ورودی کاربر (string)
     * @param mixed $value
     * @return string
     */
    public static function sanitizeString($value)
    {
        if (!is_string($value)) {
            $value = (string)$value;
        }
        // حذف فاصله‌های ابتدا/انتها و کاراکترهای نامرئی
        $value = trim($value);
        // حذف تگ‌های HTML
        $value = strip_tags($value);
        return $value;
    }

    /**
     * پاک‌سازی و اعتبارسنجی شماره موبایل ایرانی
     * @param string $mobile
     * @return string|false  (موبایل نرمال‌سازی شده یا false)
     */
    public static function sanitizeMobile($mobile)
    {
        $mobile = preg_replace('/\D+/', '', $mobile);
        // تبدیل 0098... به 0...
        if (strpos($mobile, '0098') === 0) {
            $mobile = '0' . substr($mobile, 4);
        }
        // تبدیل 98... به 0...
        if (strpos($mobile, '98') === 0 && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }
        // اعتبارسنجی: ۰۹ + ۹ رقم
        if (preg_match('/^09\d{9}$/', $mobile)) {
            return $mobile;
        }
        return false;
    }

    /**
     * نرمال‌سازی شماره تماس برای سرنخ میزبان (موبایل یا تلفن ثابت)
     * موبایل: 09xxxxxxxxx | ثابت: 0... با ۸ تا ۱۱ رقم
     *
     * @param string $phone
     * @return string|false
     */
    public static function sanitizePhone($phone)
    {
        $phone = self::toEnglishDigits((string)$phone);
        $phone = preg_replace('/\D+/', '', $phone);
        if ($phone === '' || $phone === null) return false;

        if (strpos($phone, '0098') === 0) {
            $phone = '0' . substr($phone, 4);
        } elseif (strpos($phone, '98') === 0 && strlen($phone) >= 12) {
            $phone = '0' . substr($phone, 2);
        }

        // شماره جعلی / خیلی کوتاه
        if (preg_match('/^0+$/', $phone) || strlen($phone) < 8 || strlen($phone) > 12) {
            return false;
        }

        if (preg_match('/^09\d{9}$/', $phone)) {
            return $phone;
        }
        // تلفن ثابت ایران معمولاً با ۰ شروع می‌شود
        if (preg_match('/^0\d{7,10}$/', $phone)) {
            return $phone;
        }
        return false;
    }

    /**
     * اعتبارسنجی ایمیل
     * @param string $email
     * @return bool
     */
    public static function validateEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * اعتبارسنجی عدد صحیح مثبت
     * @param mixed $value
     * @return int|false
     */
    public static function sanitizeInt($value)
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        return $value === false ? false : (int)$value;
    }

    /**
     * فرمت‌بندی عدد با جداکننده هزارگان (فارسی)
     * @param mixed $n
     * @return string
     */
    public static function formatNumber($n)
    {
        if ($n === null || $n === '' || $n === '-') return '-';
        if (!is_numeric($n)) return (string)$n;
        return number_format((float)$n, 0, '.', ',');
    }

    /**
     * تبدیل اعداد انگلیسی به فارسی
     * @param string|int $str
     * @return string
     */
    public static function toPersianDigits($str)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $out = (string)$str;
        for ($i = 0; $i < 10; $i++) {
            $out = str_replace((string)$i, $persian[$i], $out);
            $out = str_replace($arabic[$i], $persian[$i], $out);
        }
        return $out;
    }

    /**
     * تبدیل اعداد فارسی/عربی به انگلیسی
     * @param string $str
     * @return string
     */
    public static function toEnglishDigits($str)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $out = (string)$str;
        for ($i = 0; $i < 10; $i++) {
            $out = str_replace($persian[$i], (string)$i, $out);
            $out = str_replace($arabic[$i], (string)$i, $out);
        }
        return $out;
    }

    /**
     * فرمت‌بندی مبلغ به تومان (با هزارگان)
     * @param mixed $amount
     * @return string
     */
    public static function formatToman($amount)
    {
        if ($amount === null || $amount === '') return '-';
        return self::formatNumber($amount) . ' ت';
    }

    /**
     * تولید حروف اول نام برای آواتار
     * @param string $name
     * @return string
     */
    public static function initials($name)
    {
        $name = trim($name);
        if ($name === '') return '؟';
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            return mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1);
        }
        return mb_substr($parts[0], 0, 2);
    }

    /**
     * انتخاب رنگ آواتار بر اساس نام
     * @param string $name
     * @return string
     */
    public static function avatarColor($name)
    {
        $colors = ['#e50241', '#17a2b8', '#28a745', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#007bff', '#ff6b35', '#6f9e00'];
        $hash = 0;
        for ($i = 0; $i < mb_strlen($name); $i++) {
            $hash += ord($name[$i]);
        }
        return $colors[$hash % count($colors)];
    }

    /**
     * دریافت متن JSON از بدنه درخواست (پشتیبانی از PUT/DELETE)
     * @return array
     */
    public static function getJsonBody()
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }
        return $data ?: [];
    }

    /**
     * دریافت کل بدنه خام (raw body)
     * @return string
     */
    public static function getRawBody()
    {
        return file_get_contents('php://input');
    }

    /**
     * دریافت ورودی POST یا JSON ترکیبی
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function input($key, $default = null)
    {
        // بررسی $_POST اول
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        // سپس JSON body
        static $jsonBody = null;
        if ($jsonBody === null) {
            $jsonBody = self::getJsonBody();
        }
        return $jsonBody[$key] ?? $default;
    }

    /**
     * دریافت پارامتر GET
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function query($key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * دریافت پارامتر از مسیر URL (route params)
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function param($key, $default = null)
    {
        return $GLOBALS['_routeParams'][$key] ?? $default;
    }

    /**
     * تولید توکن تصادفی امن
     * @param int $length
     * @return string
     */
    public static function generateToken($length = 64)
    {
        return bin2hex(random_bytes((int)($length / 2)));
    }

    /**
     * هش کردن رمز عبور با bcrypt
     * @param string $password
     * @return string
     */
    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * بررسی رمز عبور (با پشتیبانی از مهاجرت MD5 قدیمی)
     *
     * اگر رمز به‌صورت MD5 قدیمی ذخیره شده باشد، ابتدا با md5 چک می‌کند
     * و در صورت موفقیت، آن را به bcrypt ارتقا می‌دهد.
     *
     * @param string $password     رمز خام وارد شده توسط کاربر
     * @param string $storedHash   هش ذخیره شده در دیتابیس
     * @return array  [matched => bool, newHash => string|null]
     */
    public static function verifyPassword($password, $storedHash)
    {
        // اگر با bcrypt ذخیره شده
        if (password_verify($password, $storedHash)) {
            return ['matched' => true, 'newHash' => null];
        }

        // مهاجرت از MD5 قدیمی
        if (preg_match('/^[a-f0-9]{32}$/', $storedHash)) {
            if (md5($password) === $storedHash) {
                // ارتقا به bcrypt
                $newHash = self::hashPassword($password);
                return ['matched' => true, 'newHash' => $newHash];
            }
        }

        return ['matched' => false, 'newHash' => null];
    }

    /**
     * تولید CSRF token و ذخیره در session
     * @return string
     */
    public static function generateCsrfToken()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken(32);
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * بررسی صحت CSRF token
     * @param string $token
     * @return bool
     */
    public static function verifyCsrfToken($token)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token ?: '');
    }

    /**
     * تولید UUID نسخه ۴
     * @return string
     */
    public static function uuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * بررسی نوع درخواست (هل AJAX است)
     * @return bool
     */
    public static function isAjax()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * دریافت متد HTTP با پشتیبانی از method override
     * @return string
     */
    public static function httpMethod()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // پشتیبانی از فیلد مخفی _method در فرم‌ها
        $override = self::input('_method');
        if ($override) {
            $method = strtoupper($override);
        }
        // پشتیبانی از هدر X-HTTP-Method-Override
        if (!empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }
        return $method;
    }

    /**
     * محاسبه مدت زمان گذشته از یک تاریخ (به صورت فارسی)
     * @param string $datetime
     * @return string
     */
    public static function timeAgo($datetime)
    {
        if (empty($datetime)) return '-';
        $time = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if ($time === 0) return '-';

        $diff = time() - $time;
        if ($diff < 60) return 'چند لحظه پیش';
        if ($diff < 3600) return floor($diff / 60) . ' دقیقه پیش';
        if ($diff < 86400) return floor($diff / 3600) . ' ساعت پیش';
        if ($diff < 604800) return floor($diff / 86400) . ' روز پیش';
        if ($diff < 2592000) return floor($diff / 604800) . ' هفته پیش';
        if ($diff < 31536000) return floor($diff / 2592000) . ' ماه پیش';
        return floor($diff / 31536000) . ' سال پیش';
    }

    /**
     * کوتاه کردن متن
     * @param string $text
     * @param int    $length
     * @return string
     */
    public static function truncate($text, $length = 50)
    {
        $text = (string)$text;
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . '...';
    }
}
