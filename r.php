<?php
/**
 * r.php — endpoint عمومی ریدایرکت لینک رهگیری.
 * بدون نیاز به لاگین. کلیک را ثبت و به مقصد می‌فرستد.
 * استفاده: r.php?c=CODE  یا  r.php?c=CODE&p=PERSON_ID
 *
 * دو نوع مقصد (مورد ۱):
 *   - وب  → ریدایرکت ۳۰۲ معمولی.
 *   - تماس (tel:) → صفحه‌ی واسط که خودکار شماره‌گیر گوشی را باز می‌کند.
 *     چرا واسط و نه Location؟ چون ریدایرکت ۳۰۲ به tel: در بخشی از مرورگرهای
 *     موبایل و وب‌ویوهای داخل پیام‌رسان‌ها بی‌اثر است (فقط صفحه‌ی سفید می‌دهد).
 *     این صفحه هم تلاش خودکار می‌کند، هم دکمه‌ی دستی و خودِ شماره را نشان می‌دهد.
 */

$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) { http_response_code(500); exit('not configured'); }
$config = require $cfgFile;

// این endpoint عمومی و بدون لاگین است — نباید مسیر فایل یا stack trace به بازدیدکننده نشان دهد.
error_reporting(E_ALL);
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/modules/Links.php';
Database::init($config['db']);

// ورودی می‌تواند آرایه باشد (r.php?c[]=x) — بدون این بررسی، type error می‌داد.
$code = isset($_GET['c']) && is_string($_GET['c']) ? trim($_GET['c']) : '';
$personId = isset($_GET['p']) && is_string($_GET['p']) && ctype_digit($_GET['p']) ? (int)$_GET['p'] : null;

if ($code === '') { http_response_code(404); exit('یافت نشد'); }

// اطلاعات بازدیدکننده (مورد ۱۱).
// trust_proxy را فقط وقتی true کنید که سایت واقعاً پشت CDN/پروکسی است —
// هدر X-Forwarded-For قابل جعل است و در حالت بدون پروکسی نباید اعتماد شود.
$trustProxy = !empty($config['trust_proxy']);

$target = Links::recordClick($code, $personId, [
    'ip'      => Links::clientIp($trustProxy),
    'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'referer' => $_SERVER['HTTP_REFERER'] ?? null,
]);

if ($target === null) {
    http_response_code(404);
    exit('لینک یافت نشد');
}

// دفاع لایه‌دوم: حتی اگر ردیفی از گذشته مقصد عجیبی داشته باشد، ریدایرکت نمی‌کنیم.
if (!Links::isAllowedTarget($target)) {
    http_response_code(400);
    exit('مقصد نامعتبر است');
}

// ---- مقصد وب: ریدایرکت ساده ----
if (Links::kindOf($target) !== 'tel') {
    // مورد ۷ (آنالیتیکس): نشانه‌گذاری خودکار مقصد با کد لینک، تا در صفحه‌ی
    // فرودی eb-pulse.js بفهمد این بازدید از یک لینک رهگیری آمده — بدون این،
    // «چند نفر با لینک رهگیری وارد شدند» قابل تشخیص نبود (چون UTM دستی نیاز
    // به تنظیم جداگانه توسط ادمین دارد، ولی این خودکار و همیشگی است).
    $sep = (strpos($target, '?') !== false) ? '&' : '?';
    $target .= $sep . 'eb_lc=' . urlencode($code);
    header('Location: ' . $target, true, 302);
    exit;
}

// ---- مقصد تماس: صفحه‌ی واسط ----
$phone   = Links::telDisplay($target);
$phoneFa = strtr($phone, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>تماس</title>
<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:#f4f6f9;color:#1b2432;padding:24px;
  font-family:Vazirmatn,'Segoe UI',Tahoma,system-ui,sans-serif}
.card{background:#fff;border:1px solid #e3e8ef;border-radius:14px;box-shadow:0 6px 24px rgba(20,30,50,.07);
  padding:30px 26px;max-width:360px;width:100%;text-align:center}
.icon{width:58px;height:58px;border-radius:50%;background:#e8f0fe;display:flex;align-items:center;
  justify-content:center;margin:0 auto 16px;font-size:26px}
h1{font-size:17px;margin:0 0 6px;font-weight:600}
p{margin:0 0 20px;font-size:13px;color:#5b6779;line-height:1.7}
.num{font-size:21px;font-weight:700;letter-spacing:.5px;margin-bottom:20px;direction:ltr;unicode-bidi:embed}
a.call{display:block;background:#1d63d8;color:#fff;text-decoration:none;padding:13px 18px;
  border-radius:10px;font-weight:600;font-size:15px}
a.call:active{background:#1750b0}
.hint{margin-top:14px;font-size:11.5px;color:#8a94a6}
</style>
</head>
<body>
  <div class="card">
    <div class="icon">📞</div>
    <h1>در حال باز کردن صفحه‌ی تماس…</h1>
    <p>اگر صفحه‌ی تماس خودکار باز نشد، دکمه‌ی زیر را بزنید.</p>
    <div class="num"><?= htmlspecialchars($phoneFa, ENT_QUOTES, 'UTF-8') ?></div>
    <a class="call" id="callBtn" href="<?= htmlspecialchars($target, ENT_QUOTES, 'UTF-8') ?>">تماس بگیرید</a>
    <div class="hint">در رایانه ممکن است نیاز به برنامه‌ی تماس داشته باشید.</div>
  </div>
<script>
// تلاش خودکار. برخی مرورگرها باز کردن tel: را بدون تعامل کاربر بلوکه می‌کنند،
// پس اگر نشد دکمه‌ی بالا همیشه در دسترس است.
(function(){
  // نکته: JSON_UNESCAPED_UNICODE عمداً استفاده نشده — مقصد tel فقط رقم و + است،
  // پس چیزی برای خوانا کردن نیست و در عوض U+2028/U+2029 (که در JS پایان‌خط‌اند) escape می‌شوند.
  var t = <?= json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  setTimeout(function(){ try { window.location.href = t; } catch(e){} }, 120);
})();
</script>
</body>
</html>
