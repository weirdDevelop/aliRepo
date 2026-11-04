<?php /* pages/persons.php */ ?>
<div class="page-head">
  <h1>بانک مشتری</h1>
  <p>هر مشتری یک «شخص» است که می‌تواند چند هویت در کانال‌های مختلف داشته باشد</p>
</div>

<div class="card card-pad" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
  <div style="font-size:13px;font-weight:600">افزودن یا ورود مشتری</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-primary btn-sm" onclick="openAdd()">➕ افزودن مشتری</button>
    <button class="btn btn-ghost btn-sm" onclick="openImport()">📥 آپلود CSV / Excel</button>
    <button class="btn btn-ghost btn-sm" onclick="exportCustomers()">📤 خروجی CSV</button>
    <?php if (Auth::isManager()): ?>
    <button class="btn btn-ghost btn-sm" onclick="mergeIgDuplicates()" title="یک نفر که با یک @یوزرنیم به چند پیج پیام داده">🔗 ادغام تکراری‌های اینستاگرام</button>
    <?php endif; ?>
  </div>
</div>

<!-- پنل افزودن دستی مشتری -->
<div id="addBackdrop" class="drawer-backdrop" onclick="closeAdd()"></div>
<aside id="addDrawer" class="drawer" style="width:430px">
  <div class="drawer-head"><strong>افزودن مشتری دستی</strong><button class="menu-btn" onclick="closeAdd()" style="width:32px;height:32px">✕</button></div>
  <div class="drawer-body">
    <p class="note" style="margin-bottom:14px">بسته به کانال، شناسه‌ی درست را وارد کنید. اگر شماره موبایل بدهید (یا کانال پیامک/صفیر شماره‌ای انتخاب کنید)، هویت طلایی اعمال و در صورت وجود مشتری هم‌شماره، خودکار ادغام می‌شود.</p>
    <div class="field"><label>نام (اختیاری)</label><input id="addName" placeholder="نام مشتری"></div>
    <div class="field"><label>شماره موبایل (اختیاری — کلید هویت طلایی)</label><input id="addPhone" class="mono" placeholder="مثلاً 09123456789"></div>

    <div class="field">
      <label>هویت‌های کانال</label>
      <div id="addChannels"></div>
      <button class="btn btn-ghost btn-sm" onclick="addChannelRow()" style="margin-top:6px">➕ افزودن هویت کانال</button>
    </div>

    <div class="field"><label>یادداشت (اختیاری)</label><textarea id="addNotes" placeholder="یادداشت داخلی…"></textarea></div>
    <button class="btn btn-primary" onclick="doAddPerson()">ثبت مشتری</button>
    <div id="addResult" class="note" style="margin-top:12px"></div>
  </div>
</aside>

<!-- پنل ورود مشتری -->
<div id="impBackdrop" class="drawer-backdrop" onclick="closeImport()"></div>
<aside id="impDrawer" class="drawer" style="width:400px">
  <div class="drawer-head"><strong>ورود لیست مشتری</strong><button class="menu-btn" onclick="closeImport()" style="width:32px;height:32px">✕</button></div>
  <div class="drawer-body">
    <p class="note" style="margin-bottom:14px">فایل CSV یا Excel آپلود کنید. هر ردیف می‌تواند «نام، شماره» یا فقط «شماره» باشد. مشتری بدون نام به‌صورت ناشناس ذخیره می‌شود.</p>
    <div class="field"><label>افزودن همه به گروه (اختیاری)</label><select id="impGroup"><option value="">— بدون گروه —</option></select></div>
    <div class="field"><label>افزودن همه به برچسب (اختیاری)</label><input id="impTag" placeholder="مثلاً مشتریان نمایشگاه"></div>
    <div class="field"><label>فایل</label><input type="file" id="impFile" accept=".csv,.txt,.xlsx"></div>
    <button class="btn btn-primary" onclick="doImport()">شروع ورود</button>
    <div id="impResult" class="note" style="margin-top:12px"></div>
  </div>
</aside>

<div class="card card-pad" style="margin-bottom:16px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input id="q" placeholder="جستجوی نام یا شماره…" onkeydown="if(event.key==='Enter')loadPersons(1)" style="flex:1;min-width:180px">
    <select id="fStatus" onchange="loadPersons(1)" style="width:auto">
      <option value="">همه</option><option value="member">عضو</option><option value="anonymous">ناشناس</option>
    </select>
    <button class="btn btn-ghost btn-sm" onclick="toggleAdv()">فیلتر پیشرفته <span id="advCount" class="tag tag-accent" style="display:none"></span></button>
    <button class="btn btn-primary btn-sm" onclick="loadPersons(1)">جستجو</button>
  </div>
  <div id="advFilters" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line-soft)">
    <div class="row" style="gap:10px;flex-wrap:wrap">
      <div class="col" style="min-width:150px"><label>گروه</label><select id="fGroup" onchange="loadPersons(1)"><option value="">همه</option></select></div>
      <div class="col" style="min-width:150px"><label>برچسب</label><select id="fTag" onchange="loadPersons(1)"><option value="">همه</option></select></div>
      <div class="col" style="min-width:150px"><label>شماره موبایل</label>
        <select id="fHasPhone" onchange="loadPersons(1)"><option value="">همه</option><option value="yes">دارد</option><option value="no">ندارد</option></select>
      </div>
    </div>

    <div style="margin-top:12px"><label style="font-size:11.5px;color:var(--ink-faint)">کانال‌ها (هر کدام انتخاب شد، شامل می‌شود)</label>
      <div id="fChannels" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px"></div>
    </div>

    <div class="row" style="gap:10px;margin-top:12px;flex-wrap:wrap">
      <div class="col" style="min-width:150px"><label>پاسخ‌دهی</label>
        <select id="fResponded" onchange="loadPersons(1)">
          <option value="">همه</option><option value="yes">پاسخ داده</option><option value="no">هرگز پاسخ نداده</option>
        </select>
      </div>
      <div class="col" style="min-width:150px"><label>نرخ پاسخ حداقل</label>
        <select id="fRespRate" onchange="loadPersons(1)">
          <option value="">—</option><option value="10">۱۰٪+</option><option value="25">۲۵٪+</option><option value="50">۵۰٪+</option><option value="75">۷۵٪+</option>
        </select>
      </div>
      <div class="col" style="min-width:150px"><label>وضعیت تحویل آخرین ارسال</label>
        <select id="fDelivery" onchange="loadPersons(1)">
          <option value="">همه</option><option value="delivered">حداقل یک تحویل موفق</option><option value="failed">فقط ناموفق</option>
        </select>
      </div>
    </div>

    <div class="row" style="gap:10px;margin-top:12px;flex-wrap:wrap">
      <div class="col" style="min-width:150px"><label>فعالیت</label>
        <select id="fActivity" onchange="loadPersons(1)">
          <option value="">همه</option>
          <option value="active:7">فعال در ۷ روز</option>
          <option value="active:30">فعال در ۳۰ روز</option>
          <option value="inactive:30">بدون ارسال ۳۰ روز</option>
          <option value="inactive:90">بدون ارسال ۹۰ روز</option>
        </select>
      </div>
      <div class="col" style="min-width:150px"><label>بازه بر اساس</label>
        <select id="fDateField" onchange="loadPersons(1)"><option value="created">تاریخ افزوده‌شدن</option><option value="last_msg">آخرین پیام</option></select>
      </div>
      <div class="col" style="min-width:130px"><label>از تاریخ</label><input type="text" data-jdate id="fDateFrom" data-onpick="loadPersons(1)" placeholder="انتخاب تاریخ"></div>
      <div class="col" style="min-width:130px"><label>تا تاریخ</label><input type="text" data-jdate id="fDateTo" data-onpick="loadPersons(1)" placeholder="انتخاب تاریخ"></div>
      <div class="col" style="min-width:150px"><label>مرتب‌سازی</label>
        <select id="fSort" onchange="loadPersons(1)">
          <option value="recent">به‌روزترین</option><option value="created">تازه‌افزوده</option>
          <option value="activity">فعال‌ترین</option><option value="name">نام (الفبا)</option>
        </select>
      </div>
    </div>
    <div style="margin-top:12px;text-align:left"><button class="btn btn-ghost btn-sm" onclick="resetAdv()">پاک‌کردن فیلترها</button></div>
  </div>
</div>

<!-- نوار عملیات گروهی (وقتی چیزی انتخاب شده) -->
<div id="bulkBar" class="card card-pad" style="margin-bottom:16px;display:none;background:var(--accent-soft);border-color:var(--accent)">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <strong id="bulkCount" style="color:var(--accent-ink)"></strong>
    <select id="bulkGroup" style="width:auto"><option value="">افزودن به گروه…</option></select>
    <button class="btn btn-ghost btn-sm" onclick="applyGroup()">اعمال گروه</button>
    <input id="bulkTag" placeholder="برچسب…" style="width:140px">
    <button class="btn btn-ghost btn-sm" onclick="applyTag()">اعمال برچسب</button>
    <button class="btn btn-ghost btn-sm" onclick="clearSel()" style="margin-right:auto">لغو انتخاب</button>
  </div>
</div>

<div class="card">
  <table>
    <thead>
      <tr>
        <th style="width:34px"><input type="checkbox" id="selAll" onchange="toggleAll(this)" style="width:auto"></th>
        <th style="width:40px">#</th>
        <th>نام</th><th>شماره</th><th>وضعیت</th><th>هویت‌ها</th><th>فعالیت</th><th>گروه/برچسب</th>
      </tr>
    </thead>
    <tbody id="rows"><tr><td colspan="8" class="empty">در حال بارگذاری…</td></tr></tbody>
  </table>
</div>

<div id="pager" style="margin-top:14px;display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap"></div>

<!-- پنل پروفایل -->
<div id="profileBackdrop" class="drawer-backdrop" onclick="closeProfile()"></div>
<aside id="profileDrawer" class="drawer">
  <div class="drawer-head">
    <strong>پروفایل مشتری</strong>
    <button class="menu-btn" onclick="closeProfile()" style="width:32px;height:32px">✕</button>
  </div>
  <div id="profileBody" class="drawer-body"></div>
</aside>

<style>
.drawer-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:60}
.drawer-backdrop.show{display:block}
.drawer{position:fixed;top:0;left:0;height:100vh;width:440px;max-width:92vw;background:var(--surface);box-shadow:var(--shadow-md);z-index:61;transform:translateX(-100%);transition:transform .22s ease;display:flex;flex-direction:column}
.drawer.open{transform:none}
.drawer-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)}
.drawer-body{padding:20px;overflow-y:auto;flex:1}
.pf-section{margin-bottom:20px}
.pf-tabs{display:flex;gap:6px;align-items:center;margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:0}
.pf-tab{background:none;border:none;padding:8px 12px;cursor:pointer;font-size:13px;font-weight:600;color:var(--ink-soft);border-bottom:2px solid transparent;margin-bottom:-1px}
.pf-tab.active{color:var(--accent-ink);border-bottom-color:var(--accent)}
.pf-tab:hover{color:var(--ink)}
.tl{position:relative;padding-right:8px}
.tl-item{display:flex;gap:10px;padding-bottom:16px;position:relative}
.tl-item::before{content:'';position:absolute;right:15px;top:30px;bottom:0;width:2px;background:var(--line-soft)}
.tl-item:last-child::before{display:none}
.tl-icon{width:32px;height:32px;border-radius:50%;background:var(--bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;z-index:1}
.tl-body{flex:1;min-width:0;padding-top:4px}
.tl-top{font-size:13px;display:flex;align-items:center;gap:6px}
.tl-detail{font-size:12px;color:var(--ink-soft);margin-top:3px;word-break:break-word}
.tl-time{font-size:10.5px;color:var(--ink-faint);margin-top:3px}
.pf-label{font-size:11.5px;color:var(--ink-faint);margin-bottom:6px}
.pf-stat{display:flex;gap:16px;margin:12px 0}
.pf-stat>div{flex:1;background:var(--bg);border-radius:var(--radius-sm);padding:10px;text-align:center}
.pf-stat .n{font-size:18px;font-weight:700}
.chat-log{background:var(--bg);border-radius:var(--radius-sm);padding:12px;max-height:280px;overflow-y:auto}
.bubble{margin-bottom:8px;max-width:85%;padding:8px 11px;border-radius:10px;font-size:12.5px;line-height:1.6}
.bubble.out{background:var(--accent-soft);color:var(--accent-ink);margin-right:auto;border-bottom-left-radius:3px}
.bubble.in{background:#fff;border:1px solid var(--line);margin-left:auto;border-bottom-right-radius:3px}
.bubble .meta{font-size:10px;color:var(--ink-faint);margin-top:3px}
</style>
