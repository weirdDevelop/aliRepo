<?php
/**
 * cli/license_recovery.php — صدور «کد بازیابی» لایسنس برای پشتیبانی (فقط فروشنده).
 * وقتی خریدار روی همان دامنه دوباره نصب کرده یا دامنه را عوض کرده و راست‌چین
 * خطای «-4: کد سفارش قبلاً ثبت شده» می‌دهد، با این کد بدون تماس دوباره فعال می‌شود.
 *
 * استفاده:  php cli/license_recovery.php DOMAIN ORDER_ID
 * مثال:     php cli/license_recovery.php panel.example.com 1234567
 *
 * پیش از صدور، در پنل فروشنده‌ی راست‌چین مطمئن شوید سفارش واقعی و متعلق به همین خریدار است.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
[$self, $domain, $order] = $argv + [null, null, null];
if (!$domain || !$order) {
    fwrite(STDERR, "استفاده: php cli/license_recovery.php DOMAIN ORDER_ID\n");
    exit(1);
}
require __DIR__ . '/../modules/License.php';
$d = License::normalize($domain);
echo "دامنه:     {$d}\n";
echo "کد سفارش:  {$order}\n";
echo "کد بازیابی: " . License::recoveryCode($d, $order) . "\n";
