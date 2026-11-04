<?php /* pages/botsettings.php */ ?>
<div class="page-head"><h1>تنظیمات ربات</h1><p>پیام خوش‌آمد و منوی دکمه‌ای که با /start به کاربر نشان داده می‌شود</p></div>
<?php if(!Auth::isAdmin()): ?>
<div class="card card-pad muted">این بخش فقط برای مدیر در دسترس است.</div>
<?php else: ?>
<div class="card card-pad" style="max-width:520px">
  <div class="field"><label>متن خوش‌آمد</label><textarea id="bsText" placeholder="سلام! به ربات ما خوش آمدید…"></textarea></div>
  <div class="field">
    <label>دکمه‌ها</label>
    <div id="bsButtons"></div>
    <button class="btn btn-ghost btn-sm" onclick="addBtn()" style="margin-top:8px">+ افزودن دکمه</button>
  </div>
  <div class="note" style="margin-bottom:14px">هر دکمه می‌تواند لینک باز کند، یا «اشتراک شماره» باشد (فقط بله/تلگرام). دکمه‌ی اشتراک شماره برای تبدیل ناشناس به عضو عالی است.</div>
  <button class="btn btn-primary" onclick="saveBs()">ذخیره تنظیمات</button>
</div>
<?php endif; ?>
