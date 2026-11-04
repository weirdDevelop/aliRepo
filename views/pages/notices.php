<?php /* pages/notices.php — اعلان‌های سایت: پاپ‌آپ/نوار/اسلاید-این (مورد ۷)، با ادیتور کامل + پیش‌نمایش زنده + سرنخ‌ها */ ?>
<style>
.nz-seg-tabs{display:flex;gap:4px;margin-bottom:14px;flex-wrap:wrap}
.nz-seg-tabs button{border:1px solid var(--line);background:var(--surface);border-radius:8px;padding:6px 12px;font-size:12.5px;font-weight:600;color:var(--ink-soft);cursor:pointer}
.nz-seg-tabs button.on{background:var(--accent);color:#fff;border-color:var(--accent)}
.nz-form-tabs{display:flex;gap:2px;background:var(--surface-2);border:1px solid var(--line);border-radius:10px;padding:3px;margin-bottom:16px}
.nz-form-tabs button{flex:1;border:0;background:none;font-size:12px;font-weight:600;color:var(--ink-soft);padding:7px 6px;border-radius:7px;cursor:pointer}
.nz-form-tabs button.on{background:var(--surface);color:var(--ink);box-shadow:var(--shadow-xs)}
.nz-pane{display:none}
.nz-pane.on{display:block}
.nz-preview-wrap{position:sticky;top:16px}
.nz-preview-frame{position:relative;height:520px;border-radius:14px;overflow:hidden;background:linear-gradient(160deg,#eef1f8,#dfe4f0);border:1px solid var(--line)}
.nz-preview-browser-bar{height:30px;background:#fff;border-bottom:1px solid #e2e5ee;display:flex;align-items:center;gap:5px;padding:0 12px}
.nz-preview-browser-bar span{width:9px;height:9px;border-radius:50%;background:#dcdfe6;display:inline-block}
.nz-preview-body{position:relative;height:calc(100% - 30px);overflow:hidden}
.nz-preview-page-content{padding:26px;color:#98a0b3;font-size:12px;line-height:2}
.nz-leads-table td,.nz-leads-table th{white-space:nowrap}
</style>

<div class="page-head">
  <h1>اعلان سایت</h1>
  <p>پاپ‌آپ، نوار بالا/پایین، یا کارت اسلاید-این — با ادیتور کامل، پیش‌نمایش زنده، و دریافت شماره برای کد تخفیف</p>
</div>

<div class="card card-pad" style="margin-bottom:16px">
  <div style="font-weight:600;margin-bottom:6px">کد نصب</div>
  <p class="note" style="margin-bottom:10px">این یک خط را قبل از <span class="mono">&lt;/body&gt;</span> سایتت بگذار. جدا از کد گفتگوی آنلاین سایت است.</p>
  <pre id="nzEmbed" class="mono" dir="ltr" style="background:var(--bg);padding:12px;border-radius:8px;overflow-x:auto;font-size:11.5px;margin:0">…</pre>
  <button class="btn btn-ghost btn-xs" style="margin-top:8px" onclick="nzCopyEmbed()">کپی کد</button>
  <div class="note" style="margin-top:12px">
    <b>پاپ‌آپ نمایش داده نمی‌شود؟</b> اول با ادبلاک/uBlock خاموش‌شده تست کن. بعد آدرس صفحه‌ی سایتت را با
    <span class="mono" dir="ltr">?eb_preview=1</span> در انتها باز کن — قانون «هر چند وقت یک‌بار»
    نادیده گرفته می‌شود و در کنسول مرورگر (F12) دلیل دقیق را می‌بینی.
  </div>
</div>

<div id="nzNeedUpgrade" class="note" style="display:none;color:var(--err);margin-bottom:12px">
  جدول اعلان‌ها هنوز ساخته نشده — یک‌بار در تب «تنظیمات کانال‌ها» دکمه‌ی «به‌روزرسانی دیتابیس» را بزن.
</div>

<div class="nz-seg-tabs">
  <button id="nzSegEditor" class="on" onclick="nzSegTab('editor')">✏️ ساخت / ویرایش</button>
  <button id="nzSegLeads" onclick="nzSegTab('leads')">📱 سرنخ‌ها (شماره‌ها)</button>
</div>

<!-- ==================== تب ادیتور ==================== -->
<div id="nzPaneEditor">
<div class="row" style="gap:16px;align-items:flex-start">
  <!-- فرم ساخت/ویرایش -->
  <div class="col card card-pad" style="min-width:340px;max-width:440px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-weight:600" id="nzFormTitle">اعلان تازه</div>
      <select id="nzType" onchange="nzUpdatePreview()" style="width:auto;font-size:12px">
        <option value="popup">پاپ‌آپ وسط صفحه</option>
        <option value="banner_top">نوار بالای صفحه</option>
        <option value="banner_bottom">نوار پایین صفحه</option>
        <option value="slide_in">کارت اسلاید-این</option>
      </select>
    </div>

    <div class="nz-form-tabs">
      <button id="nzFTabContent" class="on" onclick="nzFormTab('content')">محتوا</button>
      <button id="nzFTabStyle" onclick="nzFormTab('style')">ظاهر</button>
      <button id="nzFTabPhone" onclick="nzFormTab('phone')">دریافت شماره</button>
      <button id="nzFTabRules" onclick="nzFormTab('rules')">رفتار و زمان‌بندی</button>
    </div>

    <!-- محتوا -->
    <div class="nz-pane on" id="nzPaneContent">
      <div class="field"><label>عنوان داخلی (فقط خودت می‌بینی)</label><input id="nzTitle" placeholder="مثلاً: تخفیف نوروزی" oninput="nzUpdatePreview()"></div>
      <div class="field"><label>تیتر (اختیاری)</label><input id="nzHeadline" placeholder="مثلاً: ۲۰٪ تخفیف تا پایان هفته" oninput="nzUpdatePreview()"></div>
      <div class="field"><label>متن</label><textarea id="nzBody" rows="3" placeholder="متن اعلان…" oninput="nzUpdatePreview()"></textarea></div>
      <div class="field"><label>آدرس تصویر (اختیاری)</label><input id="nzImage" class="mono" dir="ltr" placeholder="https://.../banner.jpg" oninput="nzUpdatePreview()"></div>
      <div class="row" style="gap:10px">
        <div class="field col"><label>متن دکمه</label><input id="nzBtnText" placeholder="مشاهده" oninput="nzUpdatePreview()"></div>
        <div class="field col"><label>لینک دکمه</label><input id="nzBtnUrl" class="mono" dir="ltr" placeholder="https://..." oninput="nzUpdatePreview()"></div>
      </div>
      <p class="note">اگر «دریافت شماره» را در تب بعدی فعال کنی، به‌جای دکمه‌ی بالا، فرم شماره نمایش داده می‌شود.</p>
    </div>

    <!-- ظاهر -->
    <div class="nz-pane" id="nzPaneStyle">
      <div class="row" style="gap:10px">
        <div class="field col"><label>رنگ پس‌زمینه</label><input type="color" id="nzBg" value="#ffffff" style="width:100%;height:36px;padding:2px" oninput="nzUpdatePreview()"></div>
        <div class="field col"><label>رنگ متن</label><input type="color" id="nzFg" value="#1b2030" style="width:100%;height:36px;padding:2px" oninput="nzUpdatePreview()"></div>
        <div class="field col"><label>رنگ تأکیدی (دکمه)</label><input type="color" id="nzAccent" value="#5b6cff" style="width:100%;height:36px;padding:2px" oninput="nzUpdatePreview()"></div>
      </div>
      <div class="field"><label>گردی گوشه‌ها (پیکسل)</label><input type="range" id="nzRadius" min="0" max="32" value="16" oninput="nzUpdatePreview()" style="width:100%"></div>
      <div class="field">
        <label>فونت سفارشی (اختیاری)</label>
        <input id="nzFont" class="mono" dir="ltr" placeholder="خالی = فونت خودِ سایتت به‌طور خودکار" oninput="nzUpdatePreview()">
        <p class="note" style="margin-top:4px">به‌طور پیش‌فرض همان فونت سایتت استفاده می‌شود؛ این فیلد را فقط اگر می‌خواهی فونت دیگری مجبور کنی پر کن.</p>
      </div>
    </div>

    <!-- دریافت شماره -->
    <div class="nz-pane" id="nzPanePhone">
      <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer">
        <input type="checkbox" id="nzCapturePhone" onchange="nzTogglePhone();nzUpdatePreview()"> <span>دریافت شماره موبایل فعال باشد (به‌جای دکمه‌ی معمولی)</span>
      </label>
      <div id="nzPhoneFields" style="display:none">
        <div class="field"><label>متن روی فیلد شماره</label><input id="nzPhoneLabel" placeholder="شماره موبایلت رو بگو تا کد تخفیف بگیری" oninput="nzUpdatePreview()"></div>
        <div class="field"><label>متن دکمه‌ی ارسال</label><input id="nzPhoneBtnText" placeholder="دریافت کد تخفیف" oninput="nzUpdatePreview()"></div>
        <div class="field">
          <label>بعد از ثبت شماره چه چیزی نشان بدهیم؟</label>
          <select id="nzDiscountMode" onchange="nzToggleDiscountMode();nzUpdatePreview()">
            <option value="none">فقط پیام تشکر (بدون کد)</option>
            <option value="static">یک کد ثابت برای همه</option>
            <option value="generated">یک کد یکتا برای هرکس (خودکار)</option>
          </select>
        </div>
        <div class="field" id="nzDiscountStaticWrap" style="display:none">
          <label>کد تخفیف ثابت</label><input id="nzDiscountStatic" class="mono" dir="ltr" placeholder="WELCOME10" oninput="nzUpdatePreview()">
        </div>
        <div class="field" id="nzDiscountPrefixWrap" style="display:none">
          <label>پیشوند کد (اختیاری)</label><input id="nzDiscountPrefix" class="mono" dir="ltr" placeholder="EB" oninput="nzUpdatePreview()">
          <p class="note" style="margin-top:4px">کد نهایی مثلاً این‌شکلی می‌شود: <span class="mono" dir="ltr">EB-9F3A2C</span></p>
        </div>
        <div class="field"><label>تیتر بعد از ثبت (اختیاری)</label><input id="nzSuccessHeadline" placeholder="ممنون! کد تخفیفت اینه:" oninput="nzUpdatePreview()"></div>
        <div class="field"><label>توضیح زیر کد (اختیاری)</label><input id="nzSuccessNote" placeholder="این کد رو موقع پرداخت وارد کن" oninput="nzUpdatePreview()"></div>
      </div>
    </div>

    <!-- رفتار و زمان‌بندی -->
    <div class="nz-pane" id="nzPaneRules">
      <?php if (SiteCtx::multi()): $__cs = SiteCtx::find(SiteCtx::id()); ?>
    <div class="field">
      <label>نمایش برای</label>
      <select id="nzSite">
        <option value="site">فقط سایت «<?= htmlspecialchars($__cs['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>»</option>
        <option value="all">همه‌ی سایت‌ها (مشترک)</option>
      </select>
    </div>
    <?php endif; ?>
      <div class="row" style="gap:10px">
        <div class="field col"><label>تأخیر نمایش (ثانیه)</label><input id="nzDelay" class="mono" dir="ltr" value="3"></div>
        <div class="field col">
          <label>هر چند وقت یک‌بار</label>
          <select id="nzFreq">
            <option value="always">هر بار</option>
            <option value="once_per_session" selected>یک‌بار در هر بازدید (session)</option>
            <option value="once_per_day">یک‌بار در روز</option>
            <option value="once_ever">فقط یک‌بار برای همیشه</option>
          </select>
        </div>
      </div>
      <div class="row" style="gap:10px">
        <div class="field col"><label>فقط اگر آدرس شامل این باشد (اختیاری)</label><input id="nzUrlInc" class="mono" dir="ltr" placeholder="/shop"></div>
        <div class="field col"><label>مستثنی اگر آدرس شامل این باشد (اختیاری)</label><input id="nzUrlExc" class="mono" dir="ltr" placeholder="/checkout"></div>
      </div>
      <div class="row" style="gap:10px">
        <div class="field col"><label>شروع نمایش (اختیاری)</label><input type="datetime-local" id="nzStart"></div>
        <div class="field col"><label>پایان نمایش (اختیاری)</label><input type="datetime-local" id="nzEnd"></div>
      </div>
      <div class="row" style="gap:10px">
        <div class="field col"><label>اولویت (بزرگ‌تر = مهم‌تر)</label><input id="nzPriority" class="mono" dir="ltr" value="0"></div>
        <div class="field col" style="display:flex;align-items:flex-end;padding-bottom:10px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="nzActive" checked><span>فعال</span>
          </label>
        </div>
      </div>
    </div>

    <input type="hidden" id="nzId" value="">
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
      <button class="btn btn-primary" onclick="saveNotice()">ذخیره</button>
      <button class="btn btn-ghost" onclick="resetNotice()" id="nzCancelBtn" style="display:none">انصراف از ویرایش</button>
    </div>
  </div>

  <!-- پیش‌نمایش زنده -->
  <div class="col nz-preview-wrap" style="min-width:300px;max-width:420px">
    <div class="card card-pad">
      <div style="font-weight:600;margin-bottom:10px;font-size:13px">پیش‌نمایش زنده</div>
      <div class="nz-preview-frame">
        <div class="nz-preview-browser-bar"><span></span><span></span><span></span></div>
        <div class="nz-preview-body" id="nzPreviewBody">
          <div class="nz-preview-page-content">
            این یک شبیه‌سازی از سایت توست — پاپ‌آپ/نوار دقیقاً همین‌جا نمایش داده می‌شود.<br><br>
            هر تغییری در فرم سمت چپ بدهی، همین‌جا فوری آپدیت می‌شود.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- فهرست -->
<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:12px">اعلان‌های ساخته‌شده</div>
  <div id="nzList"></div>
</div>
</div>

<!-- ==================== تب سرنخ‌ها ==================== -->
<div id="nzPaneLeads" style="display:none">
  <div class="card card-pad">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
      <div style="font-weight:600">شماره‌های ثبت‌شده از پاپ‌آپ‌ها</div>
      <div style="display:flex;gap:8px;align-items:center">
        <select id="nzLeadsFilter" onchange="loadNoticeLeads()" style="width:auto"><option value="0">همه‌ی اعلان‌ها</option></select>
        <a id="nzLeadsExport" class="btn btn-ghost btn-sm" href="index.php?export=notice_leads">دانلود CSV</a>
      </div>
    </div>
    <div id="nzLeadsTable"></div>
  </div>
</div>
