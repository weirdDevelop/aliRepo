<?php /* pages/marketing.php — مارکتینگ و تیم */ ?>
<div class="page-head">
  <h1>مارکتینگ و تیم</h1>
  <p>هدف‌گذاری، چارت سازمانی و تیم. لایه‌های تقویم محتوا، وظایف و گزارش در نسخه‌های بعدی افزوده می‌شوند.</p>
</div>

<div id="mktNeedsDb" class="card card-pad" style="display:none;border:1px solid var(--warn);background:var(--warn-soft);margin-bottom:16px">
  <div style="font-weight:700;margin-bottom:6px">دیتابیس نیاز به به‌روزرسانی دارد</div>
  <div class="note" style="margin-bottom:10px">جدول‌های ماژول مارکتینگ هنوز ساخته نشده‌اند. یک‌بار روی دکمه بزنید.</div>
  <button class="btn btn-primary btn-sm" onclick="mktUpgradeDb()">به‌روزرسانی دیتابیس</button>
</div>

<div id="mktApp" style="display:none">
  <div id="mktSeedBar" class="demo-banner" style="display:none;justify-content:space-between">
    <span>📥 می‌توانید داده‌ی واقعی تقویم محتوا (تیر/خرداد ۱۴۰۵) را یک‌بار وارد کنید تا سیستم خالی نباشد.</span>
    <button class="btn btn-primary btn-sm" onclick="importSeed()">وارد کردن داده‌ی نمونه</button>
  </div>
  <div class="seg" style="margin-bottom:16px">
    <button id="tabGoals" class="on" onclick="mktTab('goals')">🎯 اهداف</button>
    <button id="tabMine" onclick="mktTab('mine')">✅ تسک‌های من</button>
    <button id="tabCal" onclick="mktTab('cal')">📅 تقویم محتوا</button>
    <button id="tabTasks" onclick="mktTab('tasks')">🗒️ وظایف</button>
    <button id="tabReports" onclick="mktTab('reports')">📈 گزارش‌ها</button>
    <button id="tabFinance" onclick="mktTab('finance')">💰 مالی و بودجه</button>
    <button id="tabTeam" onclick="mktTab('team')">👥 تیم و چارت</button>
  </div>

  <!-- ===== اهداف ===== -->
  <div id="paneGoals">
    <div class="row">
      <div class="col card card-pad" style="max-width:330px">
        <div style="font-weight:700;margin-bottom:14px">هدف جدید</div>
        <div class="field"><label>دوره</label>
          <select id="gPeriod">
            <option value="month">ماهانه</option>
            <option value="week">هفتگی</option>
            <option value="day">روزانه</option>
            <option value="campaign">کمپینی</option>
          </select>
        </div>
        <div class="field"><label>عنوان</label><input id="gTitle" placeholder="مثلاً رشد فالوور اینستاگرام"></div>
        <div class="field"><label>شاخص (KPI)</label><input id="gMetric" placeholder="مثلاً فالوور، نرخ تبدیل"></div>
        <div class="row" style="gap:10px">
          <div class="col field" style="min-width:0"><label>هدف</label><input id="gTarget" type="number" value="0"></div>
          <div class="col field" style="min-width:0"><label>فعلی</label><input id="gCurrent" type="number" value="0"></div>
        </div>
        <div class="field"><label>مسئول</label><select id="gOwner"><option value="">—</option></select></div>
        <div class="field"><label>مهلت</label><input id="gDue" data-jdate placeholder="انتخاب تاریخ"></div>
        <button class="btn btn-primary btn-sm" onclick="saveGoal()">افزودن هدف</button>
      </div>
      <div class="col" id="goalsList"><div class="empty">در حال بارگذاری…</div></div>
    </div>
  </div>

  <!-- ===== تسک‌های من ===== -->
  <div id="paneMine" style="display:none">
    <div id="mineWrap"><div class="empty">در حال بارگذاری…</div></div>
  </div>

  <!-- ===== تقویم محتوا ===== -->
  <div id="paneCal" style="display:none">
    <div class="card" style="margin-bottom:14px">
      <div class="card-h">
        <div style="display:flex;align-items:center;gap:8px">
          <button class="btn btn-ghost btn-sm" onclick="calNav(1)">‹</button>
          <span class="t" id="calLabel">—</span>
          <button class="btn btn-ghost btn-sm" onclick="calNav(-1)">›</button>
          <button class="btn btn-ghost btn-sm" onclick="calNav(0)">این ماه</button>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <select id="calPage" class="chip-select" onchange="loadCalendar()" title="فقط محتوای یک پیج">
            <option value="">همه‌ی پیج‌ها</option>
          </select>
          <button class="btn btn-ghost btn-sm" onclick="openPagesModal()" title="تعریف و ویرایش پیج‌ها/اکانت‌ها">🏷 پیج‌ها</button>
          <button class="btn btn-primary btn-sm" onclick="newContent(null, calPageFilter())">+ محتوای جدید</button>
        </div>
      </div>
      <div class="card-pad">
        <div id="calLegend" class="mcal-legend"></div>
        <div class="muted" style="font-size:11.5px;margin-bottom:8px">روی هر روز بزنید تا <b>همه‌ی موارد آن روز</b> را ببینید؛ برای افزودن، دکمه‌ی <b>＋</b> گوشه‌ی هر روز را بزنید.</div>
        <div id="calGrid"><div class="empty">در حال بارگذاری…</div></div>
      </div>
    </div>
  </div>

  <!-- ===== وظایف (کانبان) ===== -->
  <div id="paneTasks" style="display:none">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <div class="muted" style="font-size:12.5px">کارت‌ها را با دکمه‌های ◀ ▶ بین ستون‌ها جابه‌جا کنید</div>
      <button class="btn btn-primary btn-sm" onclick="newContent()">+ وظیفه‌ی جدید</button>
    </div>
    <div id="kanban" class="mkanban"><div class="empty">در حال بارگذاری…</div></div>
  </div>

  <!-- ===== مالی و بودجه ===== -->
  <div id="paneFinance" style="display:none">
    <div class="card" style="margin-bottom:14px">
      <div class="card-h">
        <div style="display:flex;align-items:center;gap:8px">
          <button class="btn btn-ghost btn-sm" onclick="finNav(1)">‹</button>
          <span class="t" id="finLabel">—</span>
          <button class="btn btn-ghost btn-sm" onclick="finNav(-1)">›</button>
          <button class="btn btn-ghost btn-sm" onclick="finNav(0)">این ماه</button>
        </div>
        <button class="btn btn-primary btn-sm" onclick="newBudget()">+ تعیین بودجه</button>
      </div>
      <div class="card-pad"><div id="finWrap"><div class="empty">در حال بارگذاری…</div></div></div>
    </div>
  </div>

  <!-- ===== گزارش‌ها ===== -->
  <div id="paneReports" style="display:none">
    <div id="reportsWrap"><div class="empty">در حال بارگذاری…</div></div>
  </div>

  <!-- ===== تیم و چارت ===== -->
  <div id="paneTeam" style="display:none">
    <div class="row">
      <div class="col card card-pad" style="max-width:330px">
        <div style="font-weight:700;margin-bottom:14px">نقش جدید</div>
        <div class="field"><label>عنوان نقش</label><input id="rTitle" placeholder="مثلاً مدیر دیجیتال"></div>
        <div class="field"><label>سطح</label>
          <select id="rLevel">
            <option value="strategic">استراتژیک</option>
            <option value="specialist">تخصصی</option>
            <option value="executive">اجرایی</option>
          </select>
        </div>
        <div class="field"><label>والد (بالادست)</label><select id="rParent"><option value="">— بدون والد (ریشه)</option></select></div>
        <div class="field"><label>شرح وظایف</label><textarea id="rDuties" style="min-height:60px"></textarea></div>
        <button class="btn btn-primary btn-sm" onclick="saveRole()">افزودن نقش</button>
        <div class="sep"></div>
        <div style="font-weight:700;margin-bottom:14px">عضو جدید</div>
        <div class="field"><label>نام</label><input id="mName"></div>
        <div class="field"><label>نقش</label><select id="mRole"><option value="">—</option></select></div>
        <div class="row" style="gap:10px">
          <div class="col field" style="min-width:0"><label>دپارتمان</label>
            <select id="mDept">
              <option value="marketing">مارکتینگ</option>
              <option value="sales">فروش</option>
              <option value="content">محتوا</option>
              <option value="design">طراحی</option>
              <option value="other">سایر</option>
            </select>
          </div>
          <div class="col field" style="min-width:0"><label>کاربر اعلان‌بان</label>
            <select id="mUser"><option value="">— بدون دسترسی</option></select>
          </div>
        </div>
        <div class="row" style="gap:10px">
          <div class="col field" style="min-width:0"><label>ایمیل</label><input id="mEmail" class="mono"></div>
          <div class="col field" style="min-width:0"><label>موبایل</label><input id="mPhone" class="mono"></div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="saveMember()">افزودن عضو</button>
      </div>
      <div class="col">
        <div class="card">
          <div class="card-h"><span class="t">چارت سازمانی</span><span class="sub">قابل ویرایش، تغییر والد و حذف</span></div>
          <div class="card-pad" id="orgTree"><div class="empty">در حال بارگذاری…</div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- مودال تعیین بودجه -->
<div id="bModal" class="mkt-modal" style="display:none">
  <div class="card" style="max-width:420px;width:100%">
    <div class="card-h"><span class="t">تعیین بودجه‌ی دوره</span><button class="btn btn-ghost btn-sm" onclick="closeBudget()">بستن</button></div>
    <div class="card-pad">
      <input type="hidden" id="bId">
      <div class="field"><label>دوره (شمسی)</label><input id="bPeriod" placeholder="مثلاً 1405-04" class="mono"></div>
      <div class="field"><label>کانال</label>
        <select id="bChannel">
          <option value="">کل (همه‌ی کانال‌ها)</option>
          <option value="instagram">اینستاگرام</option><option value="telegram">تلگرام</option>
          <option value="google_ads">گوگل ادز</option><option value="divar">دیوار</option>
          <option value="sheypoor">شیپور</option><option value="billboard">بیلبورد</option>
          <option value="influencer">اینفلوئنسر</option><option value="reportage">رپورتاژ</option>
          <option value="sms">پیامک</option><option value="email">ایمیل</option>
        </select>
      </div>
      <div class="field"><label>مبلغ بودجه (تومان)</label><input id="bAmount" type="number" class="mono" placeholder="0"></div>
      <div class="field"><label>یادداشت</label><input id="bNote"></div>
      <button class="btn btn-primary btn-sm" onclick="saveBudget2()">ذخیره</button>
    </div>
  </div>
</div>

<!-- مودال ویرایش محتوا/وظیفه -->
<style>
.mcal-head{display:flex;align-items:center;justify-content:space-between;gap:4px}
.mcal-add{width:22px;height:22px;border-radius:7px;border:1px dashed var(--line);background:transparent;color:var(--ink-soft);font-size:15px;line-height:18px;cursor:pointer;padding:0;opacity:.55;transition:.12s;font-family:inherit}
.mcal-cell:hover .mcal-add{opacity:1}
.mcal-add:hover{background:var(--brand);border-color:var(--brand);color:#fff}
.mcal-chip{border-inline-start:3px solid var(--pc,transparent)}
.mcal-chip .pg{font-size:9px;font-weight:700;color:var(--pc,var(--ink-faint));flex-shrink:0;max-width:52px;overflow:hidden;text-overflow:ellipsis}
.mcal-more{font-size:10.5px!important;color:var(--brand)!important;font-weight:700;cursor:pointer;padding:2px 4px!important;border-radius:6px}
.mcal-more:hover{background:var(--brand-soft)}
.mcal-count{font-size:9.5px;color:var(--ink-faint);margin-inline-start:auto}
.mcal-legend{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.mcal-legend .lg{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;border:1px solid var(--line);border-radius:20px;padding:3px 9px;cursor:pointer;background:var(--surface)}
.mcal-legend .lg.on{border-color:var(--brand);background:var(--brand-soft)}
.mcal-legend .dot{width:9px;height:9px;border-radius:50%}
.day-group{border:1px solid var(--line);border-radius:12px;margin-bottom:10px;overflow:hidden}
.day-group-h{display:flex;align-items:center;gap:8px;padding:8px 12px;font-weight:700;font-size:13px;background:var(--surface-2);border-inline-start:4px solid var(--pc,var(--line))}
.day-item{display:flex;align-items:center;gap:8px;padding:9px 12px;border-top:1px solid var(--line-soft);font-size:12.5px;cursor:pointer}
.day-item:hover{background:var(--surface-2)}
.day-item .tm{font-family:ui-monospace,monospace;font-size:11.5px;color:var(--ink-soft);min-width:38px}
.day-item .ttl{flex:1;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pg-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed var(--line-soft)}
.pg-row input[type=color]{width:34px;height:30px;padding:0;border:1px solid var(--line);border-radius:7px;background:none;flex:none}

/* گزارش عملکرد محتوا */
.perf-box{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:14px;background:var(--surface-2)}
.perf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px}
.perf-k{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:8px 10px;display:flex;flex-direction:column;gap:2px}
.perf-k span{font-size:11px;color:var(--ink-soft)}
.perf-k b{font-size:16px;font-variant-numeric:tabular-nums}
.perf-k.hl{border-color:var(--brand);background:var(--brand-soft)}
.perf-k small{font-size:10px;line-height:1.5;overflow-wrap:anywhere}
.perf-form{border:1px dashed var(--line);border-radius:10px;padding:10px;margin-top:10px;background:var(--surface)}
.perf-inputs{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin-top:8px}
.perf-inputs label{font-size:11px}
.perf-tbl{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.perf-tbl th{font-size:11px;color:var(--ink-soft);font-weight:600;text-align:right;padding:6px;border-bottom:1px solid var(--line);white-space:nowrap}
.perf-tbl td{padding:6px;border-bottom:1px solid var(--line-soft);font-variant-numeric:tabular-nums}
.perf-posts{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;margin-top:8px;max-height:320px;overflow:auto}
.perf-post{display:flex;flex-direction:column;gap:3px;border:1px solid var(--line);border-radius:9px;padding:4px;background:var(--surface);cursor:pointer;font-family:inherit;text-align:right;color:var(--ink)}
.perf-post:hover{border-color:var(--brand)}
.perf-post img,.perf-post .ph{width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;background:var(--surface-2);display:grid;place-items:center}
.perf-post span{font-size:10.5px;line-height:1.5}
.perf-post small{font-size:10px;color:var(--ink-soft)}
</style>

<!-- مشاهده‌ی یک روز: همه‌ی موارد، گروه‌بندی‌شده بر اساس پیج -->
<div id="dayModal" class="mkt-modal" style="display:none" onclick="if(event.target===this) closeDay()">
  <div class="card" style="max-width:620px;width:100%;max-height:90vh;overflow:auto">
    <div class="card-h">
      <span class="t" id="dayTitle">—</span>
      <div style="display:flex;gap:6px">
        <button class="btn btn-primary btn-sm" onclick="dayAdd()">+ افزودن به این روز</button>
        <button class="btn btn-ghost btn-sm" onclick="closeDay()">بستن</button>
      </div>
    </div>
    <div class="card-pad" id="dayBody"></div>
  </div>
</div>

<!-- مدیریت پیج‌ها / اکانت‌های شرکت -->
<div id="pagesModal" class="mkt-modal" style="display:none" onclick="if(event.target===this) closePagesModal()">
  <div class="card" style="max-width:600px;width:100%;max-height:90vh;overflow:auto">
    <div class="card-h"><span class="t">🏷 پیج‌ها و اکانت‌های شرکت</span><button class="btn btn-ghost btn-sm" onclick="closePagesModal()">بستن</button></div>
    <div class="card-pad">
      <div class="note" style="margin-top:0;margin-bottom:10px">هر محتوا در تقویم به یک پیج نسبت داده می‌شود تا مثلاً ببینید روز ۱۲ مهر برای هر کدام از ۴ پیج شرکت چه چیزی برنامه‌ریزی شده. پیج‌های اینستاگرامِ متصل خودکار اضافه می‌شوند؛ پیج‌های دیگر (تلگرام، لینکدین، سایت…) را این‌جا بسازید.</div>
      <div id="pagesList"></div>
      <div class="sep"></div>
      <div style="font-weight:700;margin-bottom:8px">پیج جدید</div>
      <div class="row" style="gap:8px;align-items:flex-end">
        <div class="col field" style="min-width:0;margin:0"><label>نام</label><input id="npName" placeholder="مثلاً پیج فروشگاه"></div>
        <div class="col field" style="min-width:0;margin:0"><label>پلتفرم</label>
          <select id="npPlatform"><option value="instagram">اینستاگرام</option><option value="telegram">تلگرام</option><option value="bale">بله</option><option value="roubika">روبیکا</option><option value="whatsapp">واتساپ</option><option value="linkedin">لینکدین</option><option value="twitter">توییتر/ایکس</option><option value="youtube">یوتیوب</option><option value="aparat">آپارات</option><option value="article">سایت</option><option value="">سایر</option></select>
        </div>
        <div class="field" style="margin:0"><label>رنگ</label><input id="npColor" type="color" value="#ec4899" style="width:44px;height:36px;padding:0"></div>
        <button class="btn btn-primary btn-sm" onclick="addPage()">افزودن</button>
      </div>
    </div>
  </div>
</div>

<div id="cModal" class="mkt-modal" style="display:none">
  <div class="card" style="max-width:560px;width:100%;max-height:90vh;overflow:auto">
    <div class="card-h"><span class="t" id="cModalTitle">محتوای جدید</span><button class="btn btn-ghost btn-sm" onclick="closeContent()">بستن</button></div>
    <div class="card-pad">
      <input type="hidden" id="cId">
      <div class="field"><label>عنوان</label><input id="cTitle" placeholder="مثلاً ریلز معرفی محصول"></div>
      <div class="field"><label>پیج / اکانت <span class="muted" style="font-size:11px">(مثلاً کدام پیج اینستاگرام شرکت)</span></label>
        <select id="cPage" onchange="cPageChanged(this)"><option value="">— بدون پیج —</option></select>
      </div>
      <div class="row" style="gap:10px">
        <div class="col field" style="min-width:0"><label>کانال</label>
          <select id="cChannel" onchange="cTypeOptions()">
            <option value="">—</option>
            <optgroup label="شبکه اجتماعی">
              <option value="instagram">اینستاگرام</option><option value="telegram">تلگرام</option>
              <option value="bale">بله</option><option value="roubika">روبیکا</option>
              <option value="whatsapp">واتساپ</option><option value="linkedin">لینکدین</option>
              <option value="twitter">توییتر/ایکس</option><option value="youtube">یوتیوب</option><option value="aparat">آپارات</option>
            </optgroup>
            <optgroup label="سایت">
              <option value="article">مقاله</option><option value="landing">لندینگ</option><option value="seo">سئو</option>
            </optgroup>
            <optgroup label="تبلیغات">
              <option value="google_ads">گوگل ادز</option><option value="divar">دیوار</option><option value="sheypoor">شیپور</option><option value="reportage">رپورتاژ</option>
            </optgroup>
            <optgroup label="مستقیم">
              <option value="sms">پیامک</option><option value="email">ایمیل</option>
            </optgroup>
          </select>
        </div>
        <div class="col field" style="min-width:0"><label>نوع محتوا</label><select id="cType" onchange="renderDynFields()"><option value="">—</option></select></div>
      </div>
      <div class="row" style="gap:10px">
        <div class="col field" style="min-width:0"><label>وضعیت</label>
          <select id="cStatus">
            <option value="idea">ایده / شروع نشده</option>
            <option value="producing">در حال تولید</option>
            <option value="review">بازبینی/تأیید</option>
            <option value="ready">آماده انتشار</option>
            <option value="published">منتشر شده</option>
          </select>
        </div>
        <div class="col field" style="min-width:0"><label>مسئول</label><select id="cAssignee"><option value="">—</option></select></div>
      </div>
      <div class="row" style="gap:10px">
        <div class="col field" style="min-width:0"><label>تاریخ انتشار</label><input id="cDate" data-jdate placeholder="انتخاب تاریخ"></div>
        <div class="col field" style="min-width:0"><label>ساعت</label><input id="cTime" placeholder="۱۴:۳۰" class="mono" maxlength="5"></div>
      </div>
      <div class="row" style="gap:10px">
        <div class="col field" style="min-width:0"><label>بودجه (تومان)</label><input id="cBudget" type="number" placeholder="0" class="mono"></div>
        <div class="col field" style="min-width:0"><label>هزینه‌ی واقعی (تومان)</label><input id="cSpent" type="number" placeholder="0" class="mono"></div>
      </div>
      <div id="cDynFields"></div>
      <div class="field"><label>یادداشت / سناریوی کلی</label><textarea id="cScenario" style="min-height:90px" placeholder="هر توضیح اضافه‌ای که در فیلدهای بالا جا نشد…"></textarea></div>

      <div id="cExtra" style="display:none">
        <div id="cSendBridge" style="display:none;margin-bottom:14px"></div>
        <div id="cPerf" class="perf-box"></div>
        <div class="sep"></div>
        <!-- رودمپ مراحل -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="font-weight:700;font-size:13px">رودمپ تولید <span id="cProgLbl" class="muted" style="font-weight:400"></span></div>
        </div>
        <div id="cProgBar" style="height:7px;background:var(--line-soft);border-radius:6px;overflow:hidden;margin-bottom:12px"><div style="height:100%;width:0;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:6px"></div></div>
        <div id="cSteps"></div>
        <div style="display:flex;gap:6px;margin-top:8px">
          <input id="cNewStep" placeholder="مرحله‌ی جدید…" style="flex:1">
          <button class="btn btn-ghost btn-sm" onclick="addStep()">+ مرحله</button>
        </div>

        <div class="sep"></div>
        <!-- عوامل تولید -->
        <div style="font-weight:700;font-size:13px;margin-bottom:10px">عوامل تولید</div>
        <div id="cCrew"></div>
        <div class="row" style="gap:6px;margin-top:8px">
          <div class="col" style="min-width:0"><select id="cCrewRole">
            <option value="">نقش تولید…</option>
            <option>سناریونویس</option><option>فیلم‌بردار</option><option>ادیتور / تدوین</option>
            <option>طراح گرافیک</option><option>کپشن‌نویس</option><option>صداگذار</option>
            <option>عکاس</option><option>مدل</option><option>کارگردان</option><option>ناظر کیفی</option>
          </select></div>
          <div class="col" style="min-width:0"><select id="cCrewMember"><option value="">شخص…</option></select></div>
          <button class="btn btn-ghost btn-sm" onclick="addCrew()">+ افزودن</button>
        </div>
        <div class="note" style="margin-top:8px">رودمپ و عوامل پس از ذخیره‌ی محتوا قابل ویرایش‌اند.</div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="saveContent2()">ذخیره</button>
    </div>
  </div>
</div>
