<?php /* pages/forms.php */ ?>
<div class="page-head"><h1>فرم و نظرسنجی</h1><p>ربات چند سؤال می‌پرسد و پاسخ‌ها را جمع می‌کند؛ کاربر با کلمه‌ی کلیدی شروع می‌کند</p></div>
<div class="row">
  <div class="col card card-pad" style="max-width:440px">
    <div style="font-weight:600;margin-bottom:14px" id="frmTitle">فرم جدید</div>
    <input type="hidden" id="frmId">
    <div class="field"><label>نام فرم</label><input id="frmName" placeholder="نظرسنجی رضایت"></div>
    <div class="field"><label>کلمه‌ی کلیدی شروع <span class="muted">(کاربر این را بفرستد تا فرم شروع شود)</span></label><input id="frmTrigger" placeholder="نظرسنجی"></div>
    <div class="field">
      <label>سؤالات</label>
      <div id="frmQs"></div>
      <button class="btn btn-ghost btn-xs" onclick="addQ()" style="margin-top:6px">+ سؤال</button>
    </div>
    <div class="field"><label>پیام پایان</label><textarea id="frmFinish" placeholder="با تشکر، پاسخ شما ثبت شد.">با تشکر، پاسخ شما ثبت شد.</textarea></div>
    <div class="field"><label>عکس پیام پایان (اختیاری)</label><input type="file" id="frmImg" accept="image/*" onchange="uploadFrmImg()"><div class="note" id="frmImgStatus"></div></div>
    <div style="display:flex;gap:6px">
      <button class="btn btn-primary" onclick="saveFrm()">ذخیره فرم</button>
      <button class="btn btn-ghost" onclick="resetFrm()">جدید</button>
    </div>
    <div class="note" style="margin-top:8px">کاربر می‌تواند با ارسال «لغو» فرم را نیمه‌کاره رها کند.</div>
  </div>
  <div class="col card">
    <table><thead><tr><th>نام</th><th>کلمه کلیدی</th><th>پاسخ‌ها</th><th></th></tr></thead>
    <tbody id="frmRows"><tr><td colspan="4" class="empty">…</td></tr></tbody></table>
  </div>
</div>

<div id="frmBackdrop" class="drawer-backdrop" onclick="closeFrmResp()"></div>
<aside id="frmDrawer" class="drawer">
  <div class="drawer-head"><strong>پاسخ‌های فرم</strong><button class="menu-btn" onclick="closeFrmResp()" style="width:32px;height:32px">✕</button></div>
  <div id="frmRespBody" class="drawer-body"></div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:460px;max-width:92vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
.resp-card{background:var(--bg);border-radius:8px;padding:12px;margin-bottom:10px}
.resp-card .qa{font-size:12.5px;margin-bottom:5px}
.resp-card .qa b{color:var(--ink-soft);font-weight:600}
</style>
