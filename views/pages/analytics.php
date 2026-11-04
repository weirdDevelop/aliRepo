<?php /* pages/analytics.php — آنالیتیکس حرفه‌ای (فاز ۱+۲: وب کامل + رفتار/هیت‌مپ) */ ?>
<style>
.bd-row{display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0}
.bd-name{flex:0 0 40%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bd-bar{flex:1;height:7px;background:var(--bg);border-radius:4px;overflow:hidden}
.bd-fill{height:100%;background:var(--accent);border-radius:4px}
.bd-num{flex:0 0 30px;text-align:left;color:var(--ink-faint);font-size:11px}
.an-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
.an-card{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.an-card .num{font-size:22px;font-weight:700}
.an-card .lbl{font-size:12px;color:var(--ink-faint);margin-top:4px}
.an-funnel-step{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.an-funnel-bar{flex:1;height:28px;background:var(--bg);border-radius:6px;overflow:hidden;position:relative}
.an-funnel-fill{height:100%;background:var(--accent);border-radius:6px;display:flex;align-items:center;padding-inline-start:10px;color:#fff;font-size:11.5px;font-weight:600}
.an-person-link{display:inline-flex;align-items:center;gap:7px;color:var(--accent);text-decoration:none;font-weight:600;font-size:12.5px}
.an-person-link:hover{text-decoration:underline}
.an-person-avatar{width:22px;height:22px;border-radius:50%;background:var(--accent-soft);color:var(--accent-ink);
  display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.an-url-link{color:var(--ink-soft);text-decoration:none;font-size:11px;font-family:inherit;direction:ltr;
  display:inline-block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  vertical-align:middle}
.an-url-link:hover{color:var(--accent);text-decoration:underline}
.bd-name .an-url-link{max-width:100%}
.an-url-cell{max-width:260px}
table.tbl{table-layout:fixed;width:100%}
table.tbl td,table.tbl th{overflow:hidden}
.an-ses-row{padding:9px 10px;border-radius:8px;cursor:pointer;border:1px solid transparent;margin-bottom:4px;font-size:12px}
.an-ses-row:hover{background:var(--surface-2)}
.an-ses-row.on{background:var(--accent-soft);border-color:var(--accent)}
.an-ses-meta{color:var(--ink-faint);font-size:11px;margin-top:2px}
.an-journey-step{display:flex;gap:10px;padding:9px 0;border-bottom:1px dashed var(--line)}
.an-journey-step:last-child{border-bottom:0}
.an-journey-dot{width:9px;height:9px;border-radius:50%;background:var(--accent);margin-top:5px;flex-shrink:0}
.an-journey-badges{display:flex;gap:5px;margin-top:4px;flex-wrap:wrap}
.an-journey-badges span{font-size:10px;padding:1px 6px;border-radius:5px;background:var(--surface-2);color:var(--ink-soft)}
</style>

<div class="page-head">
  <h1>آنالیتیکس</h1>
  <p>آنالیتیکس وب کامل + رفتار بازدیدکننده (هیت‌مپ)، به‌علاوه گزارش‌های تعامل با مشتری از قبل</p>
</div>

<div id="anLockedNotice" class="card card-pad" style="display:none;margin-bottom:16px;border:1px solid var(--warn);background:var(--warn-soft)">
  <div style="font-weight:700;margin-bottom:6px">ماژول آنالیتیکس وب هنوز فعال نیست</div>
  <div class="note">بخش «تعامل و لینک‌ها» (که از قبل داشتی) همیشه در دسترس است. برای آنالیتیکس وب/هیت‌مپ کامل، اول از تب «تنظیمات» فعالش کن.</div>
</div>

<div class="seg" style="margin-bottom:16px;flex-wrap:wrap">
  <button id="tabWeb" class="on" onclick="anTab('web')">🌐 وب</button>
  <button id="tabRealtime" onclick="anTab('realtime')">🟢 لحظه‌ای</button>
  <button id="tabPages" onclick="anTab('pages')">📄 صفحات و منابع</button>
  <button id="tabAudience" onclick="anTab('audience')">👥 مخاطبان</button>
  <button id="tabBehavior" onclick="anTab('behavior')">🖱️ رفتار (هیت‌مپ)</button>
  <button id="tabSessions" onclick="anTab('sessions')">🧵 سفر بازدیدکننده‌ها</button>
  <button id="tabFunnels" onclick="anTab('funnels')">🧭 قیف تبدیل</button>
  <button id="tabLegacy" onclick="anTab('legacy')">💬 تعامل و لینک‌ها</button>
  <?php if (Auth::isAdmin()): ?>
  <button id="tabSettings" onclick="anTab('settings')">⚙️ تنظیمات</button>
  <?php endif; ?>
</div>

<!-- ===== وب (نمای کلی) ===== -->
<div id="paneWeb">
  <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
    <select id="anWebDays" onchange="loadAnWeb()" style="width:auto">
      <option value="7">۷ روز اخیر</option>
      <option value="30" selected>۳۰ روز اخیر</option>
      <option value="90">۹۰ روز اخیر</option>
    </select>
  </div>
  <div id="anWebCards" class="an-cards"></div>
  <div class="card card-pad" style="margin-bottom:16px">
    <div style="font-weight:600;margin-bottom:8px">امروز</div>
    <div id="anTodayCards" class="an-cards" style="margin-bottom:0"></div>
    <div id="anTodayLinks" style="margin-top:10px"></div>
  </div>
  <div class="card card-pad" style="margin-bottom:16px">
    <div style="font-weight:600;margin-bottom:8px">روند بازدید صفحه</div>
    <div id="anWebPvChart"><div class="muted">در حال بارگذاری…</div></div>
  </div>
  <div class="card card-pad">
    <div style="font-weight:600;margin-bottom:8px">روند نشست (بازدیدکننده‌ی تازه در برابر برگشتی)</div>
    <div id="anWebSesChart"><div class="muted">در حال بارگذاری…</div></div>
  </div>
</div>

<!-- ===== لحظه‌ای ===== -->
<div id="paneRealtime" style="display:none">
  <div class="an-cards" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
    <div class="an-card"><div class="num" id="anRtCount">۰</div><div class="lbl">همین الان روی سایت</div></div>
  </div>
  <div class="row" style="gap:16px">
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">صفحات پربازدید همین الان</div>
      <div id="anRtPages"></div>
    </div>
    <div class="col card card-pad" style="flex:2">
      <div style="font-weight:600;margin-bottom:8px">بازدیدکننده‌های آنلاین</div>
      <div id="anRtTable"></div>
    </div>
  </div>
</div>

<!-- ===== صفحات و منابع ===== -->
<div id="panePages" style="display:none">
  <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
    <select id="anPagesDays" onchange="loadAnPages()" style="width:auto">
      <option value="7">۷ روز اخیر</option>
      <option value="30" selected>۳۰ روز اخیر</option>
      <option value="90">۹۰ روز اخیر</option>
    </select>
  </div>
  <div class="row" style="gap:16px">
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">صفحات برتر</div>
      <div id="anTopPages"></div>
    </div>
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">منابع ترافیک</div>
      <div id="anSources"></div>
    </div>
  </div>
  <div class="row" style="gap:16px;margin-top:16px">
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">صفحات ورودی</div>
      <div id="anEntryPages"></div>
    </div>
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">صفحات خروجی</div>
      <div id="anExitPages"></div>
    </div>
  </div>
  <div class="card card-pad" style="margin-top:16px">
    <div style="font-weight:600;margin-bottom:8px">کمپین‌ها (UTM)</div>
    <div id="anCampaigns"></div>
  </div>
</div>

<!-- ===== مخاطبان ===== -->
<div id="paneAudience" style="display:none">
  <div style="display:flex;justify-content:flex-end;margin-bottom:10px">
    <select id="anAudDays" onchange="loadAnAudience()" style="width:auto">
      <option value="7">۷ روز اخیر</option>
      <option value="30" selected>۳۰ روز اخیر</option>
      <option value="90">۹۰ روز اخیر</option>
    </select>
  </div>
  <div class="row" style="gap:16px">
    <div class="col card card-pad" style="flex:1"><div style="font-weight:600;margin-bottom:8px">دستگاه</div><div id="anDevices"></div></div>
    <div class="col card card-pad" style="flex:1"><div style="font-weight:600;margin-bottom:8px">مرورگر</div><div id="anBrowsers"></div></div>
    <div class="col card card-pad" style="flex:1"><div style="font-weight:600;margin-bottom:8px">سیستم‌عامل</div><div id="anOs"></div></div>
  </div>
  <div class="row" style="gap:16px;margin-top:16px">
    <div class="col card card-pad" style="flex:1">
      <div style="font-weight:600;margin-bottom:8px">جغرافیا</div>
      <div id="anCountries"></div>
    </div>
    <div class="col card card-pad" style="flex:2">
      <div style="font-weight:600;margin-bottom:8px">بازدیدکننده‌های شناسایی‌شده</div>
      <p class="note" style="margin-bottom:10px">وقتی یک بازدیدکننده از طریق گفتگوی سایت شماره‌اش را می‌دهد، اینجا به پروفایلش در صندوق ورودی وصل می‌شود.</p>
      <div id="anIdentified"></div>
    </div>
  </div>
</div>

<!-- ===== رفتار (هیت‌مپ) ===== -->
<div id="paneBehavior" style="display:none">
  <div class="card card-pad" style="margin-bottom:16px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px">
      <div class="field" style="min-width:220px;margin:0"><label>صفحه</label><select id="anHmPage"></select></div>
      <div class="field" style="min-width:140px;margin:0"><label>دستگاه</label>
        <select id="anHmBucket" onchange="loadAnHeatmap()"><option value="desktop">رایانه</option><option value="mobile">موبایل</option></select>
      </div>
      <div class="field" style="min-width:140px;margin:0"><label>نوع</label>
        <select id="anHmKind" onchange="drawAnHeatmap()"><option value="click">کلیک</option><option value="move">حرکت ماوس</option></select>
      </div>
      <div class="field" style="min-width:120px;margin:0"><label>بازه</label>
        <select id="anHmDays" onchange="loadAnHeatmap()"><option value="7">۷ روز</option><option value="30" selected>۳۰ روز</option><option value="90">۹۰ روز</option></select>
      </div>
    </div>
    <p class="note" style="margin-bottom:10px">این نما اندازه‌ی واقعی صفحه را نشان نمی‌دهد — صفحه‌ی واقعی سایتت را کنارش باز کن تا مختصات را با هم تطبیق بدهی. رنگ سبز=کم، قرمز=زیاد.</p>
    <div id="anHmWrap" style="position:relative;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--bg)">
      <canvas id="anHmCanvas" width="1280" height="800" style="width:100%;height:auto;display:block"></canvas>
    </div>
  </div>
  <div class="card card-pad">
    <div style="font-weight:600;margin-bottom:8px">نقاط مشکل‌دار (رِیج‌کلیک / کلیک روی چیز بی‌واکنش)</div>
    <p class="note" style="margin-bottom:10px">رِیج‌کلیک یعنی کاربر چند بار پشت‌سرهم روی یک نقطه کلیک کرده — معمولاً نشانه‌ی سردرگمی یا انتظار واکنشی که اتفاق نیفتاده.</p>
    <div id="anProblemSpots"></div>
  </div>
</div>

<!-- ===== سفر بازدیدکننده‌ها (مورد ۳) ===== -->
<div id="paneSessions" style="display:none">
  <div class="row" style="gap:16px;align-items:flex-start">
    <div class="col card card-pad" style="flex:1;min-width:320px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="font-weight:600">نشست‌های اخیر</div>
        <select id="anSesDays" onchange="loadAnSessions()" style="width:auto">
          <option value="1">امروز</option>
          <option value="7" selected>۷ روز اخیر</option>
          <option value="30">۳۰ روز اخیر</option>
        </select>
      </div>
      <div id="anSessionsList"></div>
    </div>
    <div class="col card card-pad" style="flex:1;min-width:320px">
      <div id="anSessionDetail"><div class="muted">یک نشست را از فهرست کنار انتخاب کن تا مسیر بازدیدش را ببینی.</div></div>
    </div>
  </div>
</div>

<!-- ===== قیف تبدیل ===== -->
<div id="paneFunnels" style="display:none">
  <div class="row" style="gap:16px">
    <div class="col card card-pad" style="max-width:340px">
      <div style="font-weight:700;margin-bottom:12px">قیف جدید</div>
      <div class="field"><label>نام</label><input id="anFName" placeholder="مثلاً قیف خرید"></div>
      <div id="anFSteps"></div>
      <button class="btn btn-ghost btn-sm" onclick="anAddStep()" style="margin:8px 0">+ افزودن مرحله</button>
      <div class="note" style="margin-bottom:10px">برای هر مرحله بخشی از URL را بنویس که باید در آدرس صفحه باشد (مثلاً <span class="mono" dir="ltr">/pricing</span> نه فقط <span class="mono" dir="ltr">/</span>).</div>
      <button class="btn btn-primary btn-sm" onclick="saveAnFunnel()">ذخیره‌ی قیف</button>
    </div>
    <div class="col" style="flex:1">
      <div id="anFunnelsList"></div>
    </div>
  </div>
</div>

<!-- ===== تعامل و لینک‌ها (قدیمی) ===== -->
<div id="paneLegacy" style="display:none">
  <div class="card card-pad" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px">
      <div style="font-weight:600">روند کلیک لینک‌های رهگیری (کل سایت)</div>
      <select id="anLinkDays" onchange="loadAnLinks()" style="width:auto">
        <option value="7">۷ روز اخیر</option>
        <option value="30" selected>۳۰ روز اخیر</option>
        <option value="90">۹۰ روز اخیر</option>
      </select>
    </div>
    <div id="anLinkChart" style="margin-bottom:14px"><div class="muted">در حال بارگذاری…</div></div>
    <div style="font-weight:600;margin-bottom:8px;font-size:13px">پرکلیک‌ترین لینک‌ها در این بازه</div>
    <div id="anTopLinks"></div>
  </div>

  <div class="card card-pad">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px">
      <div style="font-weight:600">تعامل با مشتری‌ها</div>
      <select id="anEngDays" onchange="loadAnEngagement()" style="width:auto">
        <option value="7">۷ روز اخیر</option>
        <option value="30" selected>۳۰ روز اخیر</option>
        <option value="90">۹۰ روز اخیر</option>
      </select>
    </div>
    <p class="note" style="margin-bottom:14px">
      مرتب‌شده بر اساس تعداد پیامی که <b>ما</b> فرستادیم. ردیف‌های قرمز یعنی آخرین حرف را ما زده‌ایم
      و مشتری هنوز جواب نداده — اگر برای یک نفر این عدد پشت‌سرهم بالا می‌رود، بهتر است ارسال را کم‌تر کنیم.
    </p>
    <div id="anEngTable"></div>
  </div>
</div>

<?php if (Auth::isAdmin()): ?>
<!-- ===== تنظیمات ===== -->
<div id="paneSettings" style="display:none">
  <div class="card card-pad" style="margin-bottom:16px;max-width:640px">
    <div style="font-weight:700;margin-bottom:6px">فعال‌سازی ماژول</div>
    <p class="note" style="margin-bottom:14px">
      «فعال باشد» را بزن و ذخیره کن، بعد کد ردیاب پایین را در سایتت بگذار.
    </p>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <input type="checkbox" id="anStEnabled" style="width:auto"> فعال باشد
    </label>
    <div class="field" style="margin-bottom:12px">
      <label>آدرس پایه‌ی سایتِ ردیابی‌شده <span class="muted">(مثلاً https://example.com — بدون / آخر)</span></label>
      <input id="anStSiteUrl" class="mono" dir="ltr" placeholder="https://example.com">
      <p class="note" style="margin-top:4px">
        بدون این، لینکِ «صفحات پربازدید» به‌جای سایت خودت به همین پنل ادمین اشاره می‌کند —
        چون پنل و سایتت معمولاً روی دو دامنه‌ی جدا هستند.
      </p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="saveAnSettings()">ذخیره</button>
  </div>

  <div class="card card-pad" style="margin-bottom:16px;max-width:640px">
    <div style="font-weight:700;margin-bottom:6px">⚠️ کرون سرور — بدون این، «امروز» و «روند» خالی می‌ماند</div>
    <p class="note" style="margin-bottom:10px">
      تب‌های «لحظه‌ای»، «صفحات و منابع»، «مخاطبان» مستقیم از دیتای خام می‌خوانند و
      همیشه کار می‌کنند. اما «نمای کلی/امروز» و «رفتار (هیت‌مپ)» به رول‌آپِ کرون
      نیاز دارند — اگر این‌ها خالی‌اند، به‌احتمال زیاد یک خط cron روی سرور تعریف نشده.
    </p>
    <p class="note" style="margin-bottom:10px">این خط را در crontab سرور (نه پنل هاست، خودِ سرور) اضافه کن تا هر دقیقه اجرا شود:</p>
    <pre class="mono" dir="ltr" style="background:var(--bg);padding:10px;border-radius:8px;font-size:11px;margin:0 0 10px">* * * * * php <?= htmlspecialchars(__DIR__) ?>/../cron.php >/dev/null 2>&1</pre>
    <button class="btn btn-ghost btn-sm" onclick="runAnRollupNow()">اجرای دستی رول‌آپ همین الان (برای تست فوری)</button>
    <div id="anRollupResult" class="note" style="margin-top:8px"></div>
  </div>

  <div class="card card-pad" style="margin-bottom:16px;max-width:640px">
    <div style="font-weight:700;margin-bottom:6px">نمونه‌برداری و نگه‌داری</div>
    <div class="row" style="gap:14px">
      <div class="col field"><label>درصد نشست‌ها برای هیت‌مپ کلیک</label><input id="anStSampHeat" type="number" min="0" max="100"></div>
      <div class="col field"><label>درصد نشست‌ها برای حرکت ماوس</label><input id="anStSampMove" type="number" min="0" max="100"></div>
    </div>
    <div class="row" style="gap:14px">
      <div class="col field"><label>نگه‌داری داده‌ی خام کلیک/حرکت (روز)</label><input id="anStRetention" type="number" min="7" max="365"></div>
      <div class="col field"><label>نگه‌داری جزئیات نشست/بازدید (روز)</label><input id="anStRetentionSessions" type="number" min="30" max="730"></div>
    </div>
    <p class="note" style="margin-bottom:10px">بعد از این مدت، جزئیات ریز پاک می‌شوند ولی عددهای روزانه (نمودارها) برای همیشه می‌مانند — چیزی از داشبورد گم نمی‌شود.</p>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
      <input type="checkbox" id="anStCookieless" style="width:auto"> حالت بدون کوکی (شناسه‌ی روزانه‌ی چرخشی)
    </label>
    <button class="btn btn-primary btn-sm" onclick="saveAnSettings()">ذخیره</button>
  </div>

  <div class="card card-pad" style="margin-bottom:16px;max-width:640px">
    <div style="font-weight:700;margin-bottom:6px">مدیریت داده / پشتیبان‌گیری</div>
    <p class="note" style="margin-bottom:10px">قبل از این‌که پاک‌سازی خودکار جزئیات قدیمی را حذف کند، می‌توانی همیشه یک نسخه‌ی کامل CSV بگیری.</p>
    <a class="btn btn-ghost btn-sm" href="index.php?export=analytics_sessions&days=365">دانلود پشتیبان کامل نشست‌ها (CSV)</a>
  </div>

  <div class="card card-pad" style="margin-bottom:16px">
    <div style="font-weight:700;margin-bottom:6px">کد نصب</div>
    <p class="note" style="margin-bottom:10px">این یک خط را قبل از <span class="mono">&lt;/body&gt;</span> سایتت بگذار.</p>
    <pre id="anEmbed" class="mono" dir="ltr" style="background:var(--bg);padding:12px;border-radius:8px;overflow-x:auto;font-size:11.5px;margin:0">…</pre>
    <button class="btn btn-ghost btn-xs" style="margin-top:8px" onclick="anCopyEmbed()">کپی کد</button>
  </div>

  <div class="card card-pad">
    <div style="font-weight:700;margin-bottom:6px">وضعیت جغرافیا (GeoIP)</div>
    <div id="anGeoStatus" class="note"></div>
    <p class="note" style="margin-top:10px">
      کشور بازدیدکننده از همان لحظه‌ی نصب، بدون هیچ تنظیمی کار می‌کند (دیتابیس
      رایگان همراهِ خودِ پروژه). اگر شهر هم می‌خواهی، راهنمای نصب اختیاری
      <span class="mono" dir="ltr">GeoLite2-City.mmdb</span> در <span class="mono" dir="ltr">data/README.md</span> هست.
    </p>
    <button class="btn btn-ghost btn-sm" style="margin-top:10px" onclick="runAnGeoTest()">تست: IP/کشور من همین الان چی تشخیص داده می‌شه؟</button>
    <div id="anGeoTestResult" class="note" style="margin-top:8px"></div>
  </div>
</div>
<?php endif; ?>
