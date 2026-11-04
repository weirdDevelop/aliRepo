<?php
/**
 * جاکجاست - هدر صفحه
 * Jakojast - Page Header (Partial)
 *
 * متغیرهای مورد نیاز:
 *   $currentUser  → اطلاعات کاربر جاری (از index.php)
 *   $pageTitle    → عنوان صفحه
 *   $pageName     → نام صفحه فعلی
 */
if (!defined('BASE_URL')) {
    // تعیین BASE_URL نسبی برای استفاده در لینک‌های HTML
    // اگر پروژه در ریشه دامنه باشد، BASE_URL = ''
    // اگر در ساب‌دایرکتوری باشد، BASE_URL = '/jakojast'
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim($scriptDir, '/');
    define('BASE_URL', $scriptDir);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Helpers::generateCsrfToken() ?>">
    <meta name="jj-base-url" content="<?= BASE_URL ?>">
    <title><?= Helpers::e($pageTitle ?? 'جاکجاست') ?> | جاکجاست</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/ui-simple.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/ui-simple.css') ?>">
    <!-- Plyr: پلیر متن‌باز صدا/ویدیو برای پخش فایل‌های ضبط تماس (github.com/sampotts/plyr) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if (defined('CRM_BRIDGED') && CRM_BRIDGED): $__cu = Auth::currentUser(); ?>
    <meta name="jj-bridged" content="1">
    <meta name="jj-eb-url" content="<?= Helpers::e(Auth::elanbanUrl()) ?>">
    <script>
    /* داخل پنل یکپارچه: کاربر از نشست اعلان‌بان؛ توکن قدیمی CRM لازم نیست */
    (function(){
      try {
        localStorage.removeItem('jakojast_token');
        localStorage.setItem('jakojast_user', <?= json_encode(json_encode($__cu ? ['id' => $__cu['id'], 'username' => $__cu['username'], 'full_name' => $__cu['full_name'], 'role' => $__cu['role'], 'exten_number' => $__cu['exten_number'], 'permissions' => $__cu['permissions']] : null, JSON_UNESCAPED_UNICODE), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
      } catch (e) {}
      var inFrame = false; try { inFrame = window.self !== window.top; } catch (e) { inFrame = true; }
      if (inFrame || /[?&]embed=1/.test(location.search)) document.documentElement.classList.add('jj-embed');
      try { var t = window.top.document.documentElement.getAttribute('data-theme'); if (t === 'dark') document.documentElement.classList.add('jj-dark'); } catch (e) {}
    })();
    </script>
    <style>
      html.jj-embed #sidebar, html.jj-embed #main-header, html.jj-embed #mobile-topbar, html.jj-embed #mobile-nav, html.jj-embed #sidebar-overlay { display: none !important; }
      html.jj-embed #main-content { margin: 0 !important; padding: 18px 20px 28px !important; min-height: 100vh; }
      html.jj-embed body { padding: 0 !important; }
      @media (max-width: 768px) { html.jj-embed #main-content { padding: 12px !important; } }
    </style>
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23e50241'/><text x='16' y='22' font-size='18' font-family='Tahoma' fill='white' text-anchor='middle' font-weight='bold'>ج</text></svg>">
</head>
<body>

<!-- ===========================
     لایه تست اعلان
     =========================== -->
<div id="toast-container"></div>

<!-- ===========================
     پوشش سایدبار موبایل
     =========================== -->
<div id="sidebar-overlay" onclick="closeSidebar()"></div>

<!-- ===========================
     هدر موبایل
     =========================== -->
<div id="mobile-topbar">
    <button class="header-btn" onclick="toggleSidebar()">☰</button>
    <div style="flex:1;font-size:15px;font-weight:700">جاکجاست</div>
    <button class="header-btn" style="position:relative" id="notif-btn-mob" onclick="toggleNotifications()">
        🔔<span class="badge-dot"></span>
    </button>
</div>

<!-- ===========================
     هدر اصلی
     =========================== -->
<header id="main-header">
    <button class="header-btn mobile-hidden" onclick="toggleSidebar()" style="display:none">☰</button>
    <div id="header-title" class="header-title"><?= Helpers::e($pageTitle ?? 'داشبورد') ?></div>

    <div class="header-search" style="position:relative">
        <span class="search-icon">🔍</span>
        <input type="text" id="global-search" placeholder="جستجوی جهانی... (مشتری، سرنخ، میزبان، تیکت)" autocomplete="off">
        <div id="global-search-results" style="display:none;position:absolute;top:100%;right:0;left:0;background:var(--card-bg,#fff);border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-height:400px;overflow-y:auto;z-index:1000;margin-top:6px"></div>
    </div>

    <!-- اعلان‌ها -->
    <div style="position:relative">
        <button class="header-btn" id="notif-btn" onclick="toggleNotifications()">
            🔔<span class="badge-dot"></span>
        </button>
        <div id="notif-dropdown" class="notif-dropdown">
            <div class="notif-header">
                <span>اعلان‌ها</span>
                <span style="font-size:12px;color:var(--primary);cursor:pointer" onclick="markAllNotificationsRead()">همه را خواندم</span>
            </div>
            <div id="notif-list">
                <div class="notif-item">
                    <div class="ni-text" style="text-align:center;color:var(--text-muted);padding:20px">
                        اعلانی موجود نیست
                    </div>
                </div>
            </div>
            <div style="text-align:center;padding:10px;border-top:1px solid var(--border)">
                <a href="#" style="font-size:13px;color:var(--primary)" onclick="event.preventDefault()">مشاهده همه ←</a>
            </div>
        </div>
    </div>

    <!-- منوی کاربر -->
    <div style="position:relative">
        <button class="header-btn" onclick="toggleUserMenu()" style="display:flex;align-items:center;gap:6px">
            <span style="width:28px;height:28px;border-radius:50%;background:<?= Helpers::avatarColor($currentUser['full_name'] ?? 'admin') ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px">
                <?= Helpers::initials($currentUser['full_name'] ?? 'A') ?>
            </span>
            <span class="mobile-hidden" style="font-size:13px;font-weight:600"><?= Helpers::e($currentUser['full_name'] ?? 'مدیر') ?></span>
        </button>
        <div id="user-menu" class="notif-dropdown" style="min-width:200px">
            <div style="padding:12px;border-bottom:1px solid var(--border)">
                <div style="font-weight:600;font-size:14px"><?= Helpers::e($currentUser['full_name'] ?? 'مدیر سیستم') ?></div>
                <div style="font-size:12px;color:var(--text-muted)"><?= Helpers::e($currentUser['email'] ?? '') ?></div>
                <div style="margin-top:6px">
                    <span class="badge badge-primary" style="font-size:11px"><?= Helpers::e($currentUser['role'] ?? 'admin') ?></span>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/index.php?page=settings" style="display:block;padding:10px 14px;color:var(--text);text-decoration:none;font-size:13px;border-bottom:1px solid var(--border)">
                ⚙️ تنظیمات
            </a>
            <a href="<?= BASE_URL ?>/index.php?page=settings" style="display:block;padding:10px 14px;color:var(--text);text-decoration:none;font-size:13px;border-bottom:1px solid var(--border)">
                🔑 تغییر رمز عبور
            </a>
            <a href="<?= BASE_URL ?>/index.php?page=logout" style="display:block;padding:10px 14px;color:var(--danger);text-decoration:none;font-size:13px">
                🚪 خروج
            </a>
        </div>
    </div>
</header>
