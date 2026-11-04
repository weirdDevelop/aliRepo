<?php /* pages/autoreply.php */ ?>
<div class="page-head">
  <h1>پاسخ خودکار</h1>
  <p>پاسخ خودکار به پیام‌های دریافتی در هر کانال</p>
</div>

<div class="row">
  <div class="col card card-pad" style="max-width:460px;min-width:340px">
    <div style="font-weight:600;margin-bottom:14px" id="arTitle">قانون جدید</div>
    <input type="hidden" id="arId">
    <div class="field">
      <label>کانال</label>
      <select id="arChannel" onchange="onARChannelChange()">
        <option value="">همه‌ی کانال‌ها</option>
        <option value="instagram">اینستاگرام</option>
        <option value="bale">بله</option>
        <option value="telegram">تلگرام</option>
        <option value="roubika">روبیکا</option>
        <option value="webchat">گفتگوی سایت</option>
      </select>
    </div>
    <div class="field" id="arPageField" style="display:none">
      <label>پیج اینستاگرام</label>
      <select id="arPage"><option value="">همه‌ی پیج‌ها</option></select>
      <div class="note">برای تنظیم کامل هر پیج (AI، گیت شماره، منو…) به «اینستاگرام ← تنظیمات هر پیج» بروید.</div>
    </div>
    <div class="row" style="gap:10px">
      <div class="col field" style="min-width:0" id="arKeywordField">
        <label>کلمه‌ی محرک</label>
        <input id="arKeyword" placeholder="مثلاً: سلام">
      </div>
      <div class="col field" style="min-width:0">
        <label>نوع تطبیق</label>
        <select id="arType" onchange="onARTypeChange()">
          <option value="exact">دقیق</option>
          <option value="contains">شامل</option>
          <option value="starts_with">شروع با</option>
          <option value="regex">Regex</option>
          <option value="on_contact">📱 دریافت شماره (اشتراک شماره)</option>
        </select>
      </div>
    </div>
    <div class="note" id="arContactNote" style="margin-bottom:12px;display:none">
      این قانون وقتی اجرا می‌شود که کاربر شماره‌اش را به اشتراک بگذارد — کلمه‌ی محرک لازم ندارد.
      فقط در کانال‌های رباتی (بله/تلگرام/روبیکا) که دکمه‌ی اشتراک شماره دارند کار می‌کند.
    </div>
    <div class="field">
      <label>متن پاسخ</label>
      <textarea id="arText" placeholder="پاسخ…"></textarea>
    </div>
    <div class="field">
      <label>دکمه‌ها (JSON اختیاری)</label>
      <textarea id="arButtons" class="mono" placeholder='[[{"text":"سایت","url":"https://..."}]]'></textarea>
    </div>
<?php if (SiteCtx::multi()): $__cs = SiteCtx::find(SiteCtx::id()); ?>
    <div class="field">
      <label>نمایش برای</label>
      <select id="arSite">
        <option value="site">فقط سایت «<?= htmlspecialchars($__cs['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>»</option>
        <option value="all">همه‌ی سایت‌ها (مشترک)</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label>🎠 کروسل (اختیاری)</label>
      <div id="arCarousel"></div>
      <div class="note">کارت‌های افقی با عکس، عنوان، زیرعنوان و دکمه — در اینستاگرام و گفتگوی سایت به‌صورت کروسل نمایش داده می‌شود؛ در سایر کانال‌ها هر کارت یک پیام جدا می‌شود. متن بالا قبل از کارت‌ها ارسال می‌شود.</div>
    </div>
    <div class="row" style="gap:10px;align-items:center">
      <div class="col field" style="min-width:0">
        <label>اولویت</label>
        <input id="arPriority" type="number" value="0">
      </div>
      <label style="display:flex;align-items:center;gap:7px;margin-top:14px;font-size:13px">
        <input type="checkbox" id="arContact" style="width:auto"> درخواست اشتراک شماره
      </label>
    </div>
    <div class="note" style="margin-bottom:12px">«اشتراک شماره» فقط در بله و تلگرام کار می‌کند.</div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary btn-sm" onclick="saveAR()">ذخیره</button>
      <button class="btn btn-ghost btn-sm" onclick="resetAR()" id="arCancel" style="display:none">انصراف</button>
    </div>
  </div>

  <div class="col card">
    <table>
      <thead><tr><th>محرک</th><th>کانال</th><th>نوع</th><th></th></tr></thead>
      <tbody id="arRows"><tr><td colspan="4" class="empty">در حال بارگذاری…</td></tr></tbody>
    </table>
  </div>
</div>
