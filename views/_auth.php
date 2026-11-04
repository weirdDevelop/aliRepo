<?php
/**
 * views/_auth.php — قاب مشترک صفحه‌های ورود و فعال‌سازی.
 * auth_open($title, $sideTitle, $sideText) ... محتوای فرم ... auth_close()
 */
function auth_open(string $title, string $sideTitle, string $sideText): void
{
    $brand = class_exists('Brand') ? Brand::all() : ['name' => 'اعلان‌بان', 'tagline' => 'سیستم ارتباط چندکاناله'];
    $name = htmlspecialchars($brand['name'] ?? 'اعلان‌بان');
    ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= htmlspecialchars($title) ?> · <?= $name ?></title>
<link rel="stylesheet" href="assets/fonts.css">
<link rel="stylesheet" href="assets/app.css">
<style>
body{overflow:auto}
.auth{min-height:100dvh;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr)}
.auth-side{position:relative;overflow:hidden;background:var(--brand-side);color:#E9EFEC;padding:48px;display:flex;flex-direction:column;justify-content:space-between;isolation:isolate}
.auth-side::before{content:"";position:absolute;inset:0;background:var(--girih);background-size:72px;opacity:.1;z-index:-1}
.auth-side::after{content:"";position:absolute;left:-120px;bottom:-160px;width:460px;height:460px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--gold) 30%,transparent),transparent 65%);z-index:-1}
.auth-brand{display:flex;align-items:center;gap:12px}
.auth-brand b{font-family:var(--display);font-size:30px;color:#FAF7EE}
.auth-side h2{font-family:var(--display);font-size:42px;line-height:1.15;color:#FAF7EE;max-width:14ch}
.auth-side p{opacity:.78;max-width:42ch;margin-top:12px;font-size:14px}
.auth-points{display:flex;flex-direction:column;gap:10px;margin-top:26px;font-size:13.5px}
.auth-points span{display:flex;align-items:center;gap:10px}
.auth-points .ic{color:var(--g-d);width:18px;height:18px}
.auth-main{display:grid;place-items:center;padding:32px 20px;background:var(--bg)}
.auth-card{width:min(400px,100%)}
.auth-card h1{font-family:var(--display);font-size:34px;line-height:1.2}
.auth-card .sub{color:var(--ink-3);font-size:13.5px;margin:6px 0 24px}
.auth-card form{display:flex;flex-direction:column;gap:14px}
.auth-card .btn-p{height:44px;font-size:14.5px}
.auth-card .inp{height:42px}
.auth-foot{margin-top:28px;font-size:12px;color:var(--ink-3);text-align:center}
.domain-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:var(--r);background:var(--surface-3);font-size:12.5px}
@media (max-width:860px){.auth{grid-template-columns:1fr}.auth-side{padding:28px 22px;min-height:auto}.auth-side h2{font-size:30px}.auth-points{display:none}}
</style>
</head>
<body>
<div class="auth">
  <section class="auth-main"><div class="auth-card">
<?php
    $GLOBALS['__auth_side'] = [$name, $sideTitle, $sideText];
}

function auth_close(array $points = []): void
{
    [$name, $sideTitle, $sideText] = $GLOBALS['__auth_side'];
    ?>
    <div class="auth-foot">© <?= date('Y') ?> · اعلان‌بان</div>
  </div></section>
  <aside class="auth-side" aria-hidden="true">
    <div class="auth-brand"><span class="logo"><svg class="ic"><use href="#i-broadcast"></use></svg></span><b><?= $name ?></b></div>
    <div><h2><?= htmlspecialchars($sideTitle) ?></h2><p><?= htmlspecialchars($sideText) ?></p>
      <?php if ($points): ?><div class="auth-points"><?php foreach ($points as $p): ?><span><svg class="ic"><use href="#i-<?= htmlspecialchars($p[0]) ?>"></use></svg><?= htmlspecialchars($p[1]) ?></span><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="small" style="opacity:.6">نسخه‌ی <?= htmlspecialchars(defined('ELB_VERSION') ? ELB_VERSION : '') ?></div>
  </aside>
</div>
<script src="assets/icons.js"></script>
<script>
(function(){try{var p=JSON.parse(localStorage.getItem('eb.prefs2')||'{}');if(p.theme&&p.theme!=='system')document.documentElement.setAttribute('data-theme',p.theme);}catch(e){}})();
</script>
</body>
</html>
<?php
}
