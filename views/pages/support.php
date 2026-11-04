<?php /* pages/support.php — پشتیبانی تیمی: پیام‌های آماده (۶-۱) + چت داخلی تیم (۶-۲) */ ?>
<div class="page-head">
  <h1>پشتیبانی تیمی</h1>
  <p>پیام‌های آماده برای پاسخ سریع به مشتری، و چت داخلی بین اعضای تیم — جدا از مکالمه‌ی مشتری‌ها</p>
</div>

<!-- آمار پاسخ‌گویی پشتیبان‌ها (دور ۳ — مورد ۲) -->
<div class="card card-pad" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <div><div style="font-weight:600">پاسخ‌گویی پشتیبان‌ها</div>
      <div class="note">چند دایرکت/چت را هر پشتیبان جواب داده، چند چت را شروع کرده و الان چند چت بر عهده‌اش است.</div></div>
    <div class="sp-seg" id="spStatSeg">
      <button type="button" data-d="1" onclick="spStats(1)">امروز</button>
      <button type="button" data-d="7" onclick="spStats(7)">۷ روز</button>
      <button type="button" data-d="30" onclick="spStats(30)">۳۰ روز</button>
    </div>
  </div>
  <div style="overflow-x:auto"><table class="sp-stat"><thead><tr><th>پشتیبان</th><th>پیام پاسخ</th><th>دایرکت/چت جواب‌داده</th><th>چت شروع‌کرده</th><th>چت باز الان</th></tr></thead><tbody id="spStatRows"><tr><td colspan="5" class="muted">در حال بارگذاری…</td></tr></tbody></table></div>
</div>
<style>
.sp-seg{display:inline-flex;gap:3px;padding:3px;background:var(--surface-2,var(--bg));border:1px solid var(--line);border-radius:10px}
.sp-seg button{border:0;background:transparent;font-family:inherit;font-size:12px;font-weight:700;color:var(--ink-soft);padding:6px 12px;border-radius:7px;cursor:pointer}
.sp-seg button.on{background:var(--brand-soft);color:var(--accent-ink)}
.sp-stat{width:100%;border-collapse:collapse;font-size:12.5px}
.sp-stat th,.sp-stat td{padding:8px;border-bottom:1px solid var(--line-soft);text-align:right;white-space:nowrap}
.sp-stat th{font-size:11px;color:var(--ink-faint);font-weight:600}
.sp-bar{display:inline-block;height:6px;border-radius:3px;background:var(--accent);vertical-align:middle;margin-inline-start:6px;opacity:.7}
@media(max-width:760px){ .sp-tc{flex-direction:column} .sp-tc #tcUsers{width:100%!important;flex-direction:row!important;overflow-x:auto;max-height:none} }
</style>

<div class="row" style="gap:16px;align-items:flex-start">
<?php if (class_exists('CrmBridge') && CrmBridge::present() && CrmBridge::ready()): ?>
  <!-- ۲.۰.۰: چت داخلی تیم = چت داخلی CRM (یکپارچه) -->
  <div class="col card card-pad" style="min-width:320px">
    <div style="font-weight:600;margin-bottom:4px">چت داخلی تیم</div>
    <div class="note" style="margin-bottom:12px">چت داخلی حالا همان چت داخلی CRM است (کانال‌ها و گفتگوی خصوصی بین همکاران).</div>
    <a class="btn btn-primary btn-sm" href="index.php#crm_chat" target="_top">باز کردن چت داخلی</a>
  </div>
<?php else: ?>
  <!-- چت داخلی تیم (۶-۲) -->
  <div class="col card card-pad" style="min-width:320px">
    <div style="font-weight:600;margin-bottom:4px">چت داخلی تیم</div>
    <div class="note" style="margin-bottom:12px">فقط بین اعضای پنل؛ مشتری چیزی از این گفتگو نمی‌بیند.</div>
    <div id="tcNeedUpgrade" class="note" style="display:none;color:var(--err);margin-bottom:12px">
      جدول چت تیمی هنوز ساخته نشده — یک‌بار در تب «تنظیمات کانال‌ها» دکمه‌ی «به‌روزرسانی دیتابیس» را بزن.
    </div>
    <div class="sp-tc" style="display:flex;gap:12px">
      <div id="tcUsers" style="width:170px;flex-shrink:0;display:flex;flex-direction:column;gap:4px;max-height:440px;overflow:auto"></div>
      <div style="flex:1;display:flex;flex-direction:column;min-width:0">
        <div id="tcThread" style="flex:1;min-height:340px;max-height:400px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--surface-2)"></div>
        <div style="display:flex;gap:6px;margin-top:8px">
          <input id="tcInput" placeholder="پیام بنویس…" style="flex:1" onkeydown="if(event.key==='Enter')tcSend()">
          <button class="btn btn-primary btn-sm" onclick="tcSend()">ارسال</button>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>
  <!-- پیام‌های آماده (۶-۱) -->
  <div class="col card card-pad" style="min-width:320px">
    <div style="font-weight:600;margin-bottom:4px">پیام‌های آماده</div>
    <div class="note" style="margin-bottom:12px">
      متن‌های پرتکرار (آدرس دفتر، راهنمای مصاحبه، شماره کارت، سؤالات پرتکرار…) که در صندوق ورودی
      با یک کلیک انتخاب و در کادر پاسخ گذاشته می‌شوند. توکن‌های شخصی‌سازی مثل <span class="mono">{name}</span>
      و <span class="mono">{phone}</span> هم همین‌جا کار می‌کنند.
    </div>
    <div id="cnNeedUpgrade" class="note" style="display:none;color:var(--err);margin-bottom:12px">
      جدول پیام‌های آماده هنوز ساخته نشده — یک‌بار در تب «تنظیمات کانال‌ها» دکمه‌ی «به‌روزرسانی دیتابیس» را بزن.
    </div>

    <div class="row" style="gap:10px">
      <div class="field col"><label>عنوان</label><input id="cnTitle" placeholder="مثلاً: آدرس دفتر"></div>
      <div class="field col"><label>دسته (اختیاری)</label><input id="cnCategory" placeholder="مثلاً: معرفی و آدرس" list="cnCatList">
        <datalist id="cnCatList"></datalist>
      </div>
    </div>
    <div class="field">
      <label>متن پیام</label>
      <textarea id="cnBody" rows="4" placeholder="سلام {name}، آدرس دفتر ما: ..."></textarea>
    </div>
    <input type="hidden" id="cnId" value="">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
      <button class="btn btn-primary btn-sm" onclick="saveCanned()">افزودن</button>
      <button class="btn btn-ghost btn-sm" onclick="resetCanned()" id="cnCancelBtn" style="display:none">انصراف از ویرایش</button>
    </div>

    <div id="cnList" style="max-height:360px;overflow:auto"></div>
  </div>
</div>

<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:6px">در پیش‌رو</div>
  <p class="note">
    تخصیص خودکار/چرخشی مکالمه بین اپراتورها، شیفت و SLA، و گزارش عملکرد (زمان اولین پاسخ، CSAT)
    هنوز پیاده نشده‌اند — این‌ها فیچرهای مستقل و نسبتاً بزرگی هستند. اگر هرکدام اولویتت هستند، جدا بگو.
  </p>
</div>
