<?php /* pages/campaigns.php */ ?>
<div class="page-head">
  <h1>کمپین چندمرحله‌ای</h1>
  <p>ارسال هدفمند با پیگیری خودکار؛ مراحل بعدی فقط برای کسانی که جواب نداده‌اند</p>
</div>
<div class="row">
  <div class="col card card-pad" style="max-width:420px">
    <div style="font-weight:600;margin-bottom:14px">کمپین جدید</div>
    <div class="field"><label>نام کمپین</label><input id="cmpName"></div>
    <div class="field"><label>گروه هدف</label><select id="cmpGroup"><option value="">همه مشتریان</option></select></div>
    <div class="field"><label>اولویت کانال</label><input id="cmpChannels" class="mono" value="bale,telegram,roubika"></div>
    <div class="sep"></div>
    <div style="font-weight:600;margin-bottom:8px">مراحل</div>
    <div id="cmpSteps"></div>
    <button class="btn btn-ghost btn-sm" onclick="addStep()" style="margin-top:8px">+ افزودن مرحله</button>
    <div class="sep"></div>
    <button class="btn btn-primary" onclick="createCmp()">ایجاد کمپین</button>
  </div>
  <div class="col card">
    <table><thead><tr><th>نام</th><th>مراحل</th><th>وضعیت</th><th></th></tr></thead>
    <tbody id="cmpRows"><tr><td colspan="4" class="empty">…</td></tr></tbody></table>
  </div>
</div>

<div id="cmpBackdrop" class="drawer-backdrop" onclick="closeCmp()"></div>
<aside id="cmpDrawer" class="drawer">
  <div class="drawer-head"><strong>جزئیات کمپین</strong><button class="menu-btn" onclick="closeCmp()" style="width:32px;height:32px">✕</button></div>
  <div id="cmpBody" class="drawer-body"></div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:440px;max-width:92vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
.step-box{border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:10px;position:relative}
.step-box .num{font-weight:700;color:var(--accent-ink);margin-bottom:6px}
</style>
