<?php
/**
 * core/Autoload.php
 * بارگذاری خودکار ماژول‌ها فقط وقتی لازم می‌شوند، تا هر درخواست ده‌ها فایل
 * را بی‌دلیل کامپایل نکند (روی هاست‌های بدون OPcache محسوس است).
 */
spl_autoload_register(function (string $cls): void {
    static $map = ['IgPageSettings' => 'modules/IgPhoneGate.php'];
    $base = dirname(__DIR__) . '/';
    if (isset($map[$cls])) { require_once $base . $map[$cls]; return; }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $cls)) return;
    foreach (['modules/', 'core/'] as $dir) {
        $f = $base . $dir . $cls . '.php';
        if (is_file($f)) { require_once $f; return; }
    }
});
