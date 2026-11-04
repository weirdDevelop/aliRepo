<?php /* pages/botbuilder.php */ ?>
<div class="page-head"><h1>بات‌ساز منو</h1><p>درخت منوی چندمرحله‌ای بساز؛ کاربر با دکمه‌ها بین گره‌ها حرکت می‌کند</p></div>

<div class="card card-pad" style="margin-bottom:14px">
  <p class="note">هر «گره» یک پیام با چند دکمه است. هر دکمه یا به گره دیگری می‌رود، یا لینک باز می‌کند، یا شماره‌ی کاربر را می‌گیرد. <b>گرهی که «شروع» باشد، همان پیامی است که با /start به کاربر نشان داده می‌شود</b> (جایگزین تنظیمات ربات قدیمی).</p>
</div>

<div class="card card-pad" style="margin-bottom:14px;max-width:640px">
  <div style="font-weight:600;margin-bottom:6px">وارد کردن یک سناریوی کامل (JSON)</div>
  <p class="note" style="margin-bottom:10px">
    به‌جای ساختن دستی هر گره، کل درخت را یک‌جا با JSON وارد کن — بعدش دقیقاً مثل بقیه‌ی
    گره‌ها از همین صفحه قابل‌ویرایش/حذف است. وارد‌کردن دوباره‌ی یک کلید تکراری، گره را
    آپدیت می‌کند، نه اینکه تکراری بسازد — یعنی می‌شود چند بار با نسخه‌های اصلاح‌شده وارد کرد.
  </p>
  <textarea id="bnImportJson" class="mono" dir="ltr" rows="6" placeholder='[{"node_key":"main","channel":"webchat","text":"...","buttons":[{"text":"میهمان","goto":"guest_main"}],"is_root":true}, ...]'></textarea>
  <button class="btn btn-primary btn-sm" onclick="importBnScenario()" style="margin-top:8px">وارد کردن</button>
  <div id="bnImportResult" class="note" style="margin-top:8px"></div>
</div>

<div class="row">
  <div class="col card card-pad" style="max-width:440px">
    <div style="font-weight:600;margin-bottom:14px" id="bnFormTitle">گره جدید</div>
    <input type="hidden" id="bnId">
    <div class="field"><label>کلید گره <span class="muted">(انگلیسی، یکتا - مثل main)</span></label><input id="bnKey" class="mono" placeholder="main"></div>
    <div class="field"><label>عنوان (یادداشت داخلی)</label><input id="bnTitle" placeholder="منوی اصلی"></div>
    <div class="field"><label>متن پیام</label><textarea id="bnText" placeholder="به پشتیبانی خوش آمدید، یک گزینه را انتخاب کنید:"></textarea></div>
    <div class="field">
      <label>دکمه‌ها</label>
      <div id="bnButtons"></div>
      <button class="btn btn-ghost btn-xs" onclick="addBtn()" style="margin-top:6px">+ دکمه</button>
    </div>
<?php if (SiteCtx::multi()): $__cs = SiteCtx::find(SiteCtx::id()); ?>
    <div class="field">
      <label>نمایش برای</label>
      <select id="bnSite">
        <option value="site">فقط سایت «<?= htmlspecialchars($__cs['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>»</option>
        <option value="all">همه‌ی سایت‌ها (مشترک)</option>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label>🎠 کروسل (اختیاری)</label>
      <div id="bnCarousel"></div>
      <div class="note">کارت‌های افقی با عکس و دکمه — اینستاگرام و گفتگوی سایت کروسل واقعی، بقیه‌ی کانال‌ها هر کارت یک پیام. دکمهٔ «رفتن به منو» کلید گرهٔ دیگر را می‌گیرد.</div>
    </div>
    <div class="field">
      <label>قواعد شرطی <span class="muted">(اگر پیام کاربر شامل کلمه بود، به گرهی برود)</span></label>
      <div id="bnRules"></div>
      <button class="btn btn-ghost btn-xs" onclick="addRule()" style="margin-top:6px">+ قاعده</button>
    </div>
    <label style="display:flex;align-items:center;gap:7px;font-size:13px;margin-bottom:14px"><input type="checkbox" id="bnRoot" style="width:auto"> این گرهِ شروع است (با /start نشان داده شود)</label>
    <div class="field" style="margin-bottom:14px">
      <label>لوکاپ خودکار اقامتگاه <span class="muted">(اگر این گره فرستاده شود، منتظر اسم اقامتگاه می‌ماند)</span></label>
      <select id="bnAwaitsField">
        <option value="">— گره معمولی، بدون لوکاپ —</option>
        <option value="price">قیمت</option>
        <option value="checkin_time">ساعت ورود</option>
        <option value="checkout_time">ساعت خروج</option>
        <option value="min_advance_days">حداقل روز رزرو از قبل</option>
      </select>
    </div>
    <div style="display:flex;gap:6px">
      <button class="btn btn-primary" onclick="saveBn()">ذخیره گره</button>
      <button class="btn btn-ghost" onclick="resetBn()">جدید</button>
    </div>
  </div>
  <div class="col card">
    <table><thead><tr><th>کلید</th><th>عنوان</th><th>دکمه</th><th></th></tr></thead>
    <tbody id="bnRows"><tr><td colspan="4" class="empty">…</td></tr></tbody></table>
  </div>
</div>

<!-- نمای فلو + تست -->
<div class="row" style="margin-top:16px">
  <div class="col card card-pad">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div style="font-weight:600">نمای فلوی بات</div>
      <span class="muted" style="font-size:11.5px">گره‌ها و مسیر دکمه‌ها</span>
    </div>
    <div id="botFlow" style="overflow:auto"></div>
  </div>
  <div class="col card card-pad" style="max-width:360px">
    <div style="font-weight:600;margin-bottom:12px">تست بات (شبیه‌سازی)</div>
    <p class="note" style="margin-bottom:10px">پیام بفرستید و ببینید بات چه جوابی می‌دهد — بدون ارسال واقعی.</p>
    <div id="simLog" style="background:var(--bg);border-radius:10px;padding:10px;height:240px;overflow-y:auto;margin-bottom:10px"></div>
    <div style="display:flex;gap:6px">
      <input id="simInput" placeholder="مثلاً /start یا یک کلمه" onkeydown="if(event.key==='Enter')simSend()" style="flex:1">
      <button class="btn btn-primary btn-sm" onclick="simSend()">بفرست</button>
    </div>
    <button class="btn btn-ghost btn-xs" onclick="simReset()" style="margin-top:8px">پاک‌کردن</button>
  </div>
</div>

<!-- مدیریت اقامتگاه‌ها (برای لوکاپ خودکار) -->
<div class="card card-pad" style="margin-top:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
    <div style="font-weight:600">اقامتگاه‌ها (برای لوکاپ خودکار در گفتگو)</div>
    <button class="btn btn-ghost btn-sm" onclick="loadProperties()">تازه‌سازی</button>
  </div>
  <p class="note" style="margin-bottom:14px">
    اسم دقیقی که اینجا می‌گذاری، همانی است که کاربر باید تو گفتگو تایپ کند تا لوکاپ پیدایش
    کند (تطبیق شامل‌شدن، نه دقیقاً یکسان — یعنی «پونک یک» با «پونک» هم پیدا می‌شود).
  </p>
  <div id="propRows"><span class="muted" style="font-size:13px">در حال بارگذاری…</span></div>
  <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line-soft)">
    <div style="font-weight:600;margin-bottom:8px;font-size:13px" id="propFormTitle">+ اقامتگاه جدید</div>
    <input type="hidden" id="propId">
    <div class="row" style="gap:10px">
      <div class="col field"><label>نام اقامتگاه</label><input id="propName" placeholder="مثلاً: پونک یک"></div>
      <div class="col field"><label>قیمت</label><input id="propPrice" placeholder="مثلاً: شبی ۱٬۲۰۰٬۰۰۰ تومان"></div>
    </div>
    <div class="row" style="gap:10px">
      <div class="col field"><label>ساعت ورود</label><input id="propCheckin" placeholder="مثلاً: از ساعت ۱۴"></div>
      <div class="col field"><label>ساعت خروج</label><input id="propCheckout" placeholder="مثلاً: تا ساعت ۱۲"></div>
    </div>
    <div class="field"><label>حداقل روز رزرو از قبل</label><input id="propMinDays" placeholder="مثلاً: ۲ روز"></div>
    <div class="field"><label>یادداشت داخلی (اختیاری)</label><input id="propNotes"></div>
    <div style="display:flex;gap:6px">
      <button class="btn btn-primary btn-sm" onclick="savePropertyRow()">ذخیره</button>
      <button class="btn btn-ghost btn-sm" onclick="resetPropertyForm()">جدید</button>
    </div>
  </div>
</div>

<style>
/* نمای فلو */
.flow-node{display:inline-block;vertical-align:top;width:200px;margin:0 8px 12px 0;background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:10px;transition:box-shadow .2s,border-color .2s}
.flow-node.flow-hl{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.flow-node-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.flow-node-text{font-size:12px;color:var(--ink-soft);margin-bottom:8px;line-height:1.6;min-height:20px}
.flow-edges{display:flex;flex-direction:column;gap:4px}
.flow-edge{font-size:11px;background:var(--bg);border-radius:6px;padding:4px 7px;cursor:pointer;transition:.12s}
.flow-edge:hover{background:var(--accent-soft);color:var(--accent-ink)}
.flow-edge.link{cursor:default;color:var(--ink-faint)}
.flow-edge.link:hover{background:var(--bg)}
.flow-edge.rule{border:1px dashed var(--line)}
/* شبیه‌ساز */
.sim-msg{margin-bottom:8px;font-size:12.5px;line-height:1.7;max-width:85%;padding:7px 10px;border-radius:10px;word-break:break-word}
.sim-user{background:var(--accent);color:#fff;margin-right:auto;border-bottom-right-radius:3px}
.sim-bot{background:var(--surface);border:1px solid var(--line);margin-left:auto;border-bottom-left-radius:3px}
.sim-sys{background:transparent;color:var(--ink-faint);font-size:11px;text-align:center;max-width:100%;font-style:italic}
.sim-meta{font-size:9.5px;color:var(--ink-faint);margin-top:4px}
.sim-btns{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.sim-btn{font-size:11px;background:var(--accent-soft);color:var(--accent-ink);border-radius:6px;padding:3px 8px;cursor:pointer}
.sim-btn:hover{background:var(--accent);color:#fff}
.sim-btn.dim{background:var(--bg);color:var(--ink-faint);cursor:default}
</style>
