<?php /* pages/settings.php */ ?>
<div class="page-head"><h1>تنظیمات عمومی</h1><p>تنظیمات کلی سیستم و زمان‌بندی خودکار</p></div>

<?php if(!Auth::isAdmin()): ?>
<div class="card card-pad muted">این بخش فقط برای مدیر در دسترس است.</div>
<?php else: ?>

<div class="card card-pad" style="margin-bottom:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:6px">اپراتورها و سطوح دسترسی</div>
  <p class="note" style="margin-bottom:12px">اپراتور بسازید و تعیین کنید به کدام بخش‌ها و کدام گروه‌های مشتری دسترسی داشته باشد.</p>
  <div id="opList"></div>
  <button class="btn btn-ghost btn-sm" onclick="openOpForm()" style="margin-top:10px">+ افزودن اپراتور</button>
</div>

<!-- فرم اپراتور (drawer) -->
<div id="opBackdrop" class="drawer-backdrop" onclick="closeOpForm()"></div>
<aside id="opDrawer" class="drawer">
  <div class="drawer-head"><strong id="opTitle">اپراتور جدید</strong><button class="menu-btn" onclick="closeOpForm()" style="width:32px;height:32px">✕</button></div>
  <div class="drawer-body">
    <input type="hidden" id="opId">
    <div class="field"><label>نام کاربری</label><input id="opUser"></div>
    <div class="field"><label>نقش</label>
      <select id="opRole">
        <option value="operator">پشتیبان — فقط چت‌های بی‌مسئول یا مال خودش را جواب می‌دهد</option>
        <option value="supervisor">سرپرست — همه‌ی مشتریان، بدون محدودیت بر عهده گرفتن، جابه‌جایی چت بین پشتیبان‌ها</option>
      </select>
    </div>
    <div class="field"><label>رمز عبور <span class="muted" id="opPwHint"></span></label><input id="opPass" type="text" placeholder="حداقل ۴ کاراکتر"></div>
    <div class="field">
      <label style="display:flex;align-items:center;justify-content:space-between">
        <span>دسترسی به بخش‌ها</span>
        <span style="display:flex;gap:4px">
          <button type="button" class="btn btn-ghost btn-xs" onclick="toggleOpAll(true)">انتخاب همه</button>
          <button type="button" class="btn btn-ghost btn-xs" onclick="toggleOpAll(false)">پاک‌کردن</button>
        </span>
      </label>
      <div id="opPages" style="margin-top:6px;max-height:340px;overflow-y:auto"></div>
      <div class="note" id="opPagesHint"></div>
    </div>
    <div class="field">
      <label>دسترسی به گروه‌های مشتری</label>
      <div id="opGroups" style="display:flex;flex-direction:column;gap:6px;margin-top:4px"></div>
      <div class="note">اگر هیچ گروهی انتخاب نشود، به همه‌ی مشتریان دسترسی دارد. در غیر این صورت فقط مشتریان گروه‌های انتخابی.</div>
    </div>
    <label style="display:flex;align-items:center;gap:7px;font-size:13px;margin-bottom:10px"><input type="checkbox" id="opCanDelete" style="width:auto"> اجازه‌ی حذف مخاطب</label>
    <label style="display:flex;align-items:center;gap:7px;font-size:13px;margin-bottom:14px"><input type="checkbox" id="opActive" checked style="width:auto"> فعال</label>
    <button class="btn btn-primary" onclick="saveOp()">ذخیره</button>
  </div>
</aside>
<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:420px;max-width:92vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
</style>

<div class="card card-pad" style="margin-bottom:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:14px">اطلاعات شرکت</div>
  <div class="field"><label>نام شرکت/کسب‌وکار</label><input id="stCompany" placeholder="نام شما"></div>
  <button class="btn btn-primary btn-sm" onclick="saveSettings()">ذخیره</button>
</div>

<div class="card card-pad" style="margin-bottom:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:6px">ظاهر و برند</div>
  <p class="note" style="margin-bottom:14px">نام، لوگو و رنگ اصلی پنل. این‌ها در سراسر سیستم (نوار کناری، عنوان، دکمه‌ها) اعمال می‌شوند.</p>

  <div class="field"><label>نام نمایشی برند</label><input id="brName" placeholder="اعلان‌بان" maxlength="60"></div>
  <div class="field"><label>زیرعنوان (در پاورقی)</label><input id="brTagline" placeholder="سیستم ارتباط چندکاناله" maxlength="120"></div>

  <div class="field">
    <label>لوگو (اختیاری)</label>
    <div style="display:flex;gap:10px;align-items:center">
      <div id="brLogoPreview" style="width:46px;height:46px;border-radius:8px;border:1px solid var(--line);display:grid;place-items:center;overflow:hidden;background:var(--bg);flex-shrink:0">
        <span class="muted" style="font-size:10px">بدون لوگو</span>
      </div>
      <input type="file" id="brLogoFile" accept="image/*" onchange="uploadLogo()" style="flex:1">
      <button class="btn btn-ghost btn-sm" onclick="removeLogo()" id="brLogoRemove" style="display:none">حذف</button>
    </div>
  </div>

  <div class="field">
    <label>رنگ اصلی</label>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="color" id="brAccent" value="#1d4ed8" style="width:54px;height:38px;padding:3px;cursor:pointer" onchange="syncAccentText()">
      <input type="text" id="brAccentText" class="mono" value="#1d4ed8" maxlength="7" style="width:120px" onchange="syncAccentColor()">
      <div style="display:flex;gap:6px">
        <button class="swatch" style="background:#1d4ed8" onclick="pickAccent('#1d4ed8')" title="آبی اداری"></button>
        <button class="swatch" style="background:#0a5fb4" onclick="pickAccent('#0a5fb4')" title="آبی بانکی"></button>
        <button class="swatch" style="background:#047857" onclick="pickAccent('#047857')" title="سبز"></button>
        <button class="swatch" style="background:#7c3aed" onclick="pickAccent('#7c3aed')" title="بنفش"></button>
        <button class="swatch" style="background:#b91c1c" onclick="pickAccent('#b91c1c')" title="قرمز"></button>
        <button class="swatch" style="background:#334155" onclick="pickAccent('#334155')" title="خاکستری"></button>
      </div>
    </div>
  </div>

  <button class="btn btn-primary btn-sm" onclick="saveBrand()">ذخیره برند</button>
  <span class="note" style="margin-right:10px">پس از ذخیره، صفحه دوباره بارگذاری می‌شود تا ظاهر اعمال شود.</span>
</div>
<style>.swatch{width:26px;height:26px;border-radius:6px;border:2px solid var(--surface);box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0}</style>

<div class="card card-pad" style="margin-bottom:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:6px">زمان‌بندی خودکار (Cron)</div>
  <p class="note" style="margin-bottom:12px">برای کارکرد زمان‌بندی، کمپین چندمرحله‌ای، دریافت پیامک و پاک‌سازی خودکار، باید این آدرس هر دقیقه روی سرور اجرا شود.</p>
  <div id="cronArea"><div class="empty">در حال بارگذاری…</div></div>
</div>

<div class="card card-pad" style="max-width:560px">
  <div style="font-weight:600;margin-bottom:10px">میان‌برهای تنظیمات</div>
  <div style="display:flex;flex-direction:column;gap:8px">
    <a href="index.php?page=channels" class="btn btn-ghost btn-sm" style="justify-content:flex-start">⚙️ تنظیمات کانال (توکن، وب‌هوک)</a>
    <a href="index.php?page=safir" class="btn btn-ghost btn-sm" style="justify-content:flex-start">📱 تنظیمات صفیر و پیامک</a>
    <a href="index.php?page=botsettings" class="btn btn-ghost btn-sm" style="justify-content:flex-start">🤖 تنظیمات ربات (پیام خوش‌آمد)</a>
  </div>
</div>

<?php endif; ?>
