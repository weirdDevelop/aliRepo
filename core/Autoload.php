<?php
/**
 * core/Autoload.php
 * بارگذاری خودکار ماژول‌ها فقط وقتی لازم می‌شوند، تا هر درخواست ده‌ها فایل
 * را بی‌دلیل کامپایل نکند (روی هاست‌های بدون OPcache محسوس است).
 */
// 2.2.0: PSR-4 for the new bounded-context layer: Eb\Work\TaskService → src/Work/TaskService.php
spl_autoload_register(function (string $cls): void {
    if (strncmp($cls, 'Eb\\', 3) !== 0) return;
    $rel = str_replace('\\', '/', substr($cls, 3));
    if (!preg_match('#^[A-Za-z][A-Za-z0-9_]*(/[A-Za-z][A-Za-z0-9_]*)*$#', $rel)) return;
    $f = dirname(__DIR__) . '/src/' . $rel . '.php';
    if (is_file($f)) require_once $f;
});
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
