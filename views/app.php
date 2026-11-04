<?php
/** views/app.php — پوسته‌ی پنل جدید (برنامه‌ی تک‌صفحه‌ای؛ داده‌ها از api) */
$brand = Brand::all();
$boot = [
    'csrf'    => Csrf::token(),
    'version' => ELB_VERSION,
    // لینک‌های قدیمی (اعلان‌ها، چت تیمی): index.php?page=inbox&person=12 ← #inbox.v12
    'page'    => $currentPage . (($currentPage === 'inbox' && !empty($_GET['person'])) ? '.v' . (int)$_GET['person'] : ''),
    
    'activated' => !empty($_GET['activated']),
    'viewer'  => Auth::isViewer(),
    // ۲.۰.۰: CRM یکپارچه (پوشه‌ی crm/ و جدول‌هایش)
    'crm'     => class_exists('CrmBridge') ? ['present' => CrmBridge::present(), 'ready' => CrmBridge::present() && CrmBridge::ready()] : null,
    'imp'     => Auth::impersonator() ? ['by' => Auth::impersonator()['name'], 'as' => Auth::name()] : null,
];
$v = rawurlencode(ELB_VERSION);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars($brand['name'] ?: 'اعلان‌بان') ?></title>
<?php if (!empty($brand['logo'])): ?><link rel="icon" href="<?= htmlspecialchars($brand['logo']) ?>"><?php endif; ?>
<link rel="stylesheet" href="assets/fonts.css?v=<?= $v ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<script>
window.EB_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
(function(){try{var p=JSON.parse(localStorage.getItem('eb.prefs2')||'{}');if(p.theme&&p.theme!=='system')document.documentElement.setAttribute('data-theme',p.theme);}catch(e){}})();
</script>
</head>
<body>
<div id="app" dir="rtl"><div style="display:grid;place-items:center;height:100%;color:#7A847F;font-family:Tahoma">در حال بارگذاری پنل...</div></div>
<noscript>برای استفاده از پنل، جاوااسکریپت مرورگر را فعال کنید.</noscript>
<?php foreach (['icons', 'live', 'core', 'jdate', 'p-dashboard', 'p-inbox', 'p-persons', 'p-settings', 'p-analytics', 'p-flows', 'p-widgets', 'p-seo', 'p-team', 'p-agent', 'p-chatplus', 'p-crm', 'p-legacy'] as $js): ?>
<script src="assets/<?= $js ?>.js?v=<?= $v ?>"></script>
<?php endforeach; ?>
<script>EB.start();</script>
</body>
</html>
