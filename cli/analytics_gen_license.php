<?php
/**
 * cli/analytics_gen_license.php
 * صدور کلید لایسنس ماژول آنالیتیکس برای یک دامنه — فقط از خط فرمان اجرا شود
 * (نه از پنل، نه از وب)، چون این کلید همان چیزی‌ست که جلوی فعال‌شدن ناخواسته
 * را روی نصب‌های کپی‌شده می‌گیرد.
 *
 * استفاده:
 *   php cli/analytics_gen_license.php panel.example.com
 *
 * نکته‌ی مهم: دامنه باید دقیقاً همان چیزی باشد که پنل خودش تشخیص می‌دهد
 * (بدون www., بدون پورت، بدون scheme). اگر مطمئن نیستی، اول از تب «تنظیمات»
 * صفحه‌ی آنالیتیکس در پنل، مقدار «دامنه‌ی فعلی این نصب» را ببین و همان را
 * این‌جا بده.
 *
 * secret امضا از config.php (کلید analytics_license_secret) خوانده می‌شود؛
 * اگر تنظیم نشده باشد، از همان مقدار پیش‌فرض AnalyticsLicense.php استفاده
 * می‌کند — برای امنیت واقعی حتماً آن را در config.php هر نصب جدا تنظیم کن.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از خط فرمان قابل اجراست.');
}

$domain = $argv[1] ?? null;
if (!$domain) {
    fwrite(STDERR, "استفاده: php cli/analytics_gen_license.php DOMAIN\n");
    fwrite(STDERR, "مثال:   php cli/analytics_gen_license.php panel.example.com\n");
    exit(1);
}

require __DIR__ . '/../modules/AnalyticsLicense.php';

$cfgFile = __DIR__ . '/../config.php';
$config = file_exists($cfgFile) ? require $cfgFile : [];

$key = AnalyticsLicense::issue($domain);

echo "دامنه: {$domain}\n";
echo "کلید لایسنس:\n{$key}\n";
echo "\nاین کلید را در پنل → آنالیتیکس → تنظیمات → «کلید لایسنس» بگذار.\n";
