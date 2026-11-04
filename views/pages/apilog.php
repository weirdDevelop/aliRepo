<?php /* pages/apilog.php */ ?>
<div class="page-head"><h1>لاگ سیستم</h1><p>جزئیات خام تماس با APIها (صفیر، پیامک، تماس گویا، ...) برای اشکال‌یابی</p></div>
<div class="card card-pad" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <select id="alChannel" onchange="loadAl()" style="width:auto">
    <option value="">همه کانال‌ها</option>
    <option value="bale_safir">صفیر بله</option>
    <option value="voice">تلفن گویا</option>
    <option value="sms">پیامک</option>
    <option value="email">ایمیل</option>
    <option value="webchat">گفتگوی سایت</option>
    <option value="instagram_roboclick">اینستاگرام (اتصال مستقیم)</option>
    <option value="instagram_apichi">اینستاگرام (یار API)</option>
  </select>
  <div style="display:flex;gap:6px">
    <button class="btn btn-ghost btn-sm" onclick="loadAl()">تازه‌سازی</button>
    <button class="btn btn-danger-ghost btn-sm" onclick="clearAl()">پاک کردن همه</button>
  </div>
</div>
<div class="card">
  <div style="overflow-x:auto">
    <table style="min-width:600px">
      <thead><tr><th>زمان</th><th>کانال</th><th>عملیات</th><th>نتیجه</th><th></th></tr></thead>
      <tbody id="alRows"><tr><td colspan="5" class="empty">…</td></tr></tbody>
    </table>
  </div>
</div>

<div id="alBackdrop" class="drawer-backdrop" onclick="closeAl()"></div>
<aside id="alDrawer" class="drawer" style="width:560px">
  <div class="drawer-head"><strong>جزئیات لاگ</strong><button class="menu-btn" onclick="closeAl()" style="width:32px;height:32px">✕</button></div>
  <div id="alBody" class="drawer-body"></div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:560px;max-width:94vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
.log-block{background:var(--bg);border-radius:8px;padding:12px;margin-bottom:12px;font-family:monospace;font-size:11.5px;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow:auto}
</style>
