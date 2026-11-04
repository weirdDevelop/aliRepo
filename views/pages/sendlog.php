<?php /* pages/sendlog.php */ ?>
<div class="page-head">
  <h1>تاریخچه‌ی ارسال</h1>
  <p>هر عملیات ارسال با جزئیات: به چه کسانی رفت، چندتا رسید، کدام‌ها نرسید و چرا</p>
</div>

<div class="card card-pad" style="margin-bottom:16px">
  <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div class="col" style="min-width:160px"><label>جستجو در عنوان/متن</label><input id="fq" placeholder="…" onkeydown="if(event.key==='Enter')loadLog()"></div>
    <div class="col" style="min-width:130px"><label>نوع</label>
      <select id="fkind" onchange="loadLog()">
        <option value="">همه</option><option value="bulk">انبوه (چت)</option><option value="phone">با شماره</option>
        <option value="campaign">کمپین</option><option value="scheduled">زمان‌بندی</option><option value="single">تکی</option>
      </select>
    </div>
    <div class="col" style="min-width:130px"><label>کانال</label>
      <select id="fchannel" onchange="loadLog()">
        <option value="">همه</option><option value="bale">بله</option><option value="telegram">تلگرام</option>
        <option value="roubika">روبیکا</option><option value="bale_safir">صفیر</option><option value="sms">پیامک</option><option value="email">ایمیل</option><option value="webchat">گفتگوی سایت</option>
      </select>
    </div>
    <div class="col" style="min-width:130px"><label>نتیجه</label>
      <select id="fresult" onchange="loadLog()">
        <option value="">همه</option><option value="success">کامل موفق</option><option value="partial">نسبی</option><option value="failed">ناموفق</option>
      </select>
    </div>
    <div class="col" style="min-width:120px"><label>از تاریخ</label><input type="text" data-jdate id="fdfrom" data-onpick="loadLog()" placeholder="انتخاب تاریخ"></div>
    <div class="col" style="min-width:120px"><label>تا تاریخ</label><input type="text" data-jdate id="fdto" data-onpick="loadLog()" placeholder="انتخاب تاریخ"></div>
    <div class="col" style="flex:0 0 auto"><button class="btn btn-ghost btn-sm" onclick="resetLogFilters()">پاک‌کردن</button></div>
  </div>
  <div id="logSummary" class="note" style="margin-top:10px"></div>
</div>

<div class="card">
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch">
    <table style="min-width:480px">
      <thead><tr><th>تاریخ</th><th>نوع</th><th>عنوان</th><th>نتیجه</th><th></th></tr></thead>
      <tbody id="logRows"><tr><td colspan="5" class="empty">در حال بارگذاری…</td></tr></tbody>
    </table>
  </div>
</div>

<!-- پنل جزئیات -->
<div id="sdBackdrop" class="drawer-backdrop" onclick="closeSd()"></div>
<aside id="sdDrawer" class="drawer">
  <div class="drawer-head"><strong>جزئیات ارسال</strong><button class="menu-btn" onclick="closeSd()" style="width:32px;height:32px">✕</button></div>
  <div id="sdBody" class="drawer-body"></div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:480px;max-width:94vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
</style>
