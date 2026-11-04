<?php /* pages/templates.php */ ?>
<div class="page-head">
  <h1>الگوها</h1>
  <p>پیام‌های آماده برای استفاده‌ی مجدد در ارسال‌ها</p>
</div>

<div class="row">
  <div class="col card card-pad" style="max-width:460px;min-width:340px">
    <div style="font-weight:600;margin-bottom:14px" id="tplFormTitle">الگوی جدید</div>
    <input type="hidden" id="tplId">
    <div class="field">
      <label>نام الگو</label>
      <input id="tplName" placeholder="مثلاً: خوش‌آمدگویی">
    </div>
    <div class="field">
      <label>متن پیام</label>
      <textarea id="tplBody" placeholder="متن پیام…"></textarea>
    </div>
    <div class="field">
      <label>دکمه‌ها (JSON اختیاری)</label>
      <textarea id="tplButtons" class="mono" placeholder='[[{"text":"سایت","url":"https://..."}]]'></textarea>
      <div class="note">آرایه‌ای از ردیف‌ها. هر دکمه: text + url یا text + callback</div>
    </div>
    <div class="field">
      <label>🎠 کروسل (اختیاری)</label>
      <div id="tplCarousel"></div>
    </div>
    <div class="field">
      <label>عکس (اختیاری)</label>
      <input type="file" id="tplImage" accept="image/*" onchange="uploadTplImage()">
      <div class="note" id="tplImgStatus"></div>
    </div>
    <div style="display:flex;gap:8px">
      <button class="btn btn-primary btn-sm" onclick="saveTpl()">ذخیره</button>
      <button class="btn btn-ghost btn-sm" onclick="resetTpl()" id="tplCancel" style="display:none">انصراف</button>
    </div>
  </div>

  <div class="col card">
    <table>
      <thead><tr><th>نام</th><th>متن</th><th></th></tr></thead>
      <tbody id="tplRows"><tr><td colspan="3" class="empty">در حال بارگذاری…</td></tr></tbody>
    </table>
  </div>
</div>
