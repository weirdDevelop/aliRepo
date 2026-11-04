<?php
/**
 * نمونه‌ی تنظیمات — این فایل را به config.php کپی کن و مقادیر را پر کن.
 * config.php نباید در مخزن/تحویل عمومی قرار بگیرد.
 */

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'elanban',
        'user'    => 'elanban',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // کلید رمزنگاری تنظیمات حساس کانال‌ها (۳۲ بایت تصادفی، base64)
    // برای تولید: base64_encode(random_bytes(32))
    'app_key' => 'CHANGE_ME_BASE64_32BYTES',

    // مسیر پایه‌ی نصب (برای ساخت آدرس webhook و ...)
    'base_url' => 'https://example.com/elanban',

    // اگر سایت پشت CDN یا پروکسی (مثل کلادفلر) است true کنید تا IP واقعی بازدیدکننده
    // در آمار لینک رهگیری ثبت شود. در حالت بدون پروکسی false بماند — هدر X-Forwarded-For
    // قابل جعل است و اعتماد بی‌مورد به آن آمار را آلوده می‌کند.
    'trust_proxy' => false,

    'paths' => [
        'uploads' => __DIR__ . '/uploads/',
        'logs'    => __DIR__ . '/logs/',
    ],

    // حالت توسعه: نمایش خطاها
    'debug' => false,

];
