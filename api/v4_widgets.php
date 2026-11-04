<?php
/**
 * api/v4_widgets.php — دکمه‌های شناور (fab_) و طراحی گفتگوی آنلاین (wcx_)
 * از api/v4.php require می‌شود.
 */
require_once __DIR__ . '/../modules/SiteWidgets.php';
adminOnly();

$base = rtrim(app_base_url($config), '/');
$__site = SiteCtx::find(SiteCtx::id());   // ۲.۱.۱: کد نصبِ سایتِ انتخاب‌شده (با کلید سایت)
$embed = $__site ? SiteCtx::embed($__site, $base)['widget'] : '<script src="' . htmlspecialchars($base . '/widget.js', ENT_QUOTES, 'UTF-8') . '" async></script>';

switch ($api) {
    case 'fab_get':
        out(['ok' => true, 'config' => SiteWidgets::fab(), 'types' => SiteWidgets::TYPES, 'icons' => SiteWidgets::ICONS,
             'embed' => $embed, 'base_url' => $base, 'webchat_enabled' => Webchat::isEnabled(), 'online' => Webchat::isOnline()]);
    case 'fab_save':
        $cfg = SiteWidgets::saveFab((array)($in['config'] ?? []));
        out(['ok' => true, 'config' => $cfg]);
    case 'fab_stats':
        out(['ok' => true] + SiteWidgets::clickStats((int)($in['days'] ?? 30)));

    case 'wcx_get':
        $s = Webchat::settings();
        out(['ok' => true, 'design' => SiteWidgets::design(), 'base' => [
            'enabled' => $s['enabled'] === '1', 'title' => $s['title'], 'subtitle' => $s['subtitle'], 'welcome' => $s['welcome'],
            'offline_text' => $s['offline_text'], 'start_mode' => $s['start_mode'], 'ask_name' => $s['ask_name'] === '1',
            'ask_phone' => $s['ask_phone'] === '1', 'color' => $s['color'], 'allowed_hosts' => $s['allowed_hosts'],
        ], 'accent' => Webchat::accent(), 'brand' => Brand::all(), 'icons' => SiteWidgets::ICONS, 'embed' => $embed, 'base_url' => $base,
           'online' => Webchat::isOnline(), 'stats' => Webchat::stats()]);
    case 'wcx_save':
        $b = (array)($in['base'] ?? []);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($b['color'] ?? '')) ? (string)$b['color'] : '';
        $design = SiteWidgets::saveDesign((array)($in['design'] ?? []));
        // تنظیمات پایه در کلیدهای قبلی می‌ماند تا صفحه‌ی گفتگوی سایت و نسخه‌های قبلی ویجت هم هماهنگ باشند
        Webchat::save([
            'enabled' => !empty($b['enabled']) ? '1' : '0',
            'title' => mb_substr(trim((string)($b['title'] ?? '')), 0, 80),
            'subtitle' => mb_substr(trim((string)($b['subtitle'] ?? '')), 0, 120),
            'welcome' => mb_substr((string)($b['welcome'] ?? ''), 0, 600),
            'offline_text' => mb_substr((string)($b['offline_text'] ?? ''), 0, 600),
            'start_mode' => in_array(($b['start_mode'] ?? ''), ['open', 'form', 'require_phone'], true) ? (string)$b['start_mode'] : 'open',
            'ask_name' => !empty($b['ask_name']) ? '1' : '0',
            'ask_phone' => !empty($b['ask_phone']) ? '1' : '0',
            'color' => $color,
            'allowed_hosts' => mb_substr((string)($b['allowed_hosts'] ?? ''), 0, 2000),
            // سازگاری با ویجت قدیمی
            'position' => $design['position']['side'],
            'mobile_bottom_offset' => (string)$design['position']['m_bottom'],
            'border_radius' => (string)$design['panel']['radius'],
            'launcher_size' => (string)$design['launcher']['size'],
            'font_family' => $design['font']['family'],
        ]);
        out(['ok' => true, 'design' => $design, 'accent' => Webchat::accent()]);
    case 'wcx_upload':
        if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) out(['ok' => false, 'error' => 'فایلی آپلود نشد']);
        if (($_FILES['image']['size'] ?? 0) > 1024 * 1024) out(['ok' => false, 'error' => 'حجم تصویر باید کمتر از ۱ مگابایت باشد']);
        $info = @getimagesize($_FILES['image']['tmp_name']);
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
        if (!$ext) out(['ok' => false, 'error' => 'فقط PNG، JPG، WebP یا GIF']);
        $dir = rtrim($config['paths']['uploads'] ?? (__DIR__ . '/../uploads/'), '/') . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $name = 'wgt_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dir . $name)) out(['ok' => false, 'error' => 'ذخیره‌ی فایل ناموفق بود']);
        out(['ok' => true, 'rel' => 'uploads/' . $name]);
}
