<?php /* pages/ai.php — هوش مصنوعی: اتصال، ساعت کاری، و مرز انسان/AI (مورد ۱۲) */ ?>
<div class="page-head">
  <h1>✦ هوش مصنوعی</h1>
  <p>دستیار پاسخ‌گوی پشتیبانی. در ساعت کاری فقط پیش‌نویس می‌دهد؛ بیرون ساعت کاری یا با ارجاع دستی، خودش جواب می‌دهد</p>
</div>

<!-- وضعیت فعلی: یک نگاه بگو الان چه خبر است -->
<div class="card card-pad" style="margin-bottom:16px">
  <div id="aiStatus" class="muted">…</div>
</div>

<label class="switch-row card card-pad" style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer">
  <input type="checkbox" id="aiEnabled">
  <span style="font-weight:600">هوش مصنوعی فعال باشد</span>
  <span class="note" style="margin-inline-start:8px">اگر خاموش باشد، هیچ پروایدری—حتی اگر تنظیم شده باشد—استفاده نمی‌شود.</span>
</label>

<!-- پروایدرها: چند سرویس هم‌زمان با اولویت و fallback خودکار -->
<div class="card card-pad" style="margin-bottom:16px">
  <div style="font-weight:600;margin-bottom:4px">پروایدرها و مدل‌ها</div>
  <div class="note" style="margin-bottom:14px">
    هر تعداد پروایدر که می‌خواهی فعال کن. سیستم به ترتیب «اولویت» امتحان می‌کند؛ اگر یکی خطا داد
    (قطعی، ۴۰۱/۴۲۹/۵xx)، خودکار سراغ پروایدر بعدی می‌رود. عدد اولویت کوچک‌تر = زودتر امتحان می‌شود.
  </div>
  <div id="providerList"></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
    <button class="btn btn-primary" onclick="saveAi()">ذخیره‌ی همه‌ی پروایدرها</button>
    <button class="btn btn-ghost" onclick="testAi()">تست زنجیره‌ی fallback</button>
  </div>
  <div id="aiTestResult" class="note" style="margin-top:10px"></div>
</div>

<!-- ۱.۰.۱۴: مصرف و هزینه‌ی API -->
<div class="card card-pad" style="margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px">
    <div style="font-weight:600">مصرف و هزینه‌ی هوش مصنوعی</div>
    <div style="display:flex;gap:6px" id="aiUsageDays">
      <button type="button" class="chip-btn" data-d="1" onclick="loadUsage(1)">امروز</button>
      <button type="button" class="chip-btn" data-d="7" onclick="loadUsage(7)">۷ روز</button>
      <button type="button" class="chip-btn" data-d="30" onclick="loadUsage(30)">۳۰ روز</button>
    </div>
  </div>
  <div class="note" style="margin-bottom:12px">هر درخواست (دستیار فروش، پاسخ خودکار، پیش‌نویس، تست) با تعداد توکن، زمان پاسخ و خطا ثبت می‌شود. هزینه از روی قیمتی که برای هر مدل وارد می‌کنید حساب می‌شود (تومان به ازای ۱ میلیون توکن؛ قیمت‌ها را از تعرفه‌ی پنل AvalAI یا پروایدر خود بردارید).</div>
  <div id="aiUsage"><div class="muted">در حال بارگذاری…</div></div>
</div>

<div class="row">
  <!-- رفتار دستیار -->
  <div class="col card card-pad">
    <div style="font-weight:600;margin-bottom:14px">رفتار دستیار</div>

    <div class="field">
      <label>دستور سیستمی (شخصیت و مرزها)</label>
      <textarea id="aiSystem" rows="6" style="font-size:12.5px"></textarea>
      <div class="note">اینجا بگو چه چیزهایی را نباید بگوید (قیمت، تخفیف، تعهد زمانی).</div>
    </div>

    <div class="field">
      <label>امضای پایان پاسخ خودکار (اختیاری)</label>
      <input id="aiSignature" placeholder="مثلاً: پاسخ خودکار — همکاران ما در ساعت کاری پیگیری می‌کنند">
      <div class="note">به مشتری شفاف می‌گوید با ربات حرف می‌زند. پیشنهاد می‌شود پرش کنی.</div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px;cursor:pointer">
      <input type="checkbox" id="aiAutoOffline">
      <span>بیرون ساعت کاری خودش جواب بدهد</span>
    </label>
    <div class="note" style="margin:-8px 0 16px">
      اگر خاموش باشد، AI هیچ‌وقت خودکار چیزی نمی‌فرستد و فقط دکمه‌ی «پیش‌نویس» در گفتگو کار می‌کند.
    </div>

    <div class="row" style="gap:10px">
      <div class="field col"><label>خلاقیت (۰ تا ۱)</label><input id="aiTemp" class="mono" dir="ltr" placeholder="0.3"></div>
      <div class="field col"><label>سقف طول پاسخ</label><input id="aiMaxTok" class="mono" dir="ltr" placeholder="400"></div>
    </div>
    <div class="row" style="gap:10px">
      <div class="field col"><label>چند پیام آخر به‌عنوان زمینه</label><input id="aiHistory" class="mono" dir="ltr" placeholder="8"></div>
      <div class="field col"><label>مهلت پاسخ (ثانیه)</label><input id="aiTimeout" class="mono" dir="ltr" placeholder="20"></div>
    </div>
    <div class="field" style="max-width:260px">
      <label>سقف پاسخ خودکار به هر مشتری در ساعت</label>
      <input id="aiRate" class="mono" dir="ltr" placeholder="10">
      <div class="note">جلوی خرج بی‌حساب روی پیام‌های پشت‌سرهم را می‌گیرد. ۰ = بی‌حد.</div>
    </div>

    <label style="display:flex;align-items:center;gap:8px;margin-top:6px;cursor:pointer">
      <input type="checkbox" id="aiInsecure">
      <span style="font-size:12.5px">نادیده‌گرفتن خطای گواهی SSL</span>
    </label>
    <div class="note">فقط اگر هاست گواهی‌های ریشه‌ی قدیمی دارد و اتصال با خطای SSL رد می‌شود. کلید API را در معرض شنود می‌گذارد، پس آخرین راه باشد.</div>
  </div>
</div>

<!-- ساعت کاری تیم -->
<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:4px">ساعت کاری تیم پشتیبانی</div>
  <div class="note" style="margin-bottom:14px">
    این تعیین می‌کند «آفلاین» چه زمانی است. بیرون این بازه، AI (اگر فعال باشد) جای تیم جواب می‌دهد.
  </div>

  <div class="field" style="max-width:320px">
    <label>وضعیت</label>
    <select id="supPresence">
      <option value="auto">خودکار بر اساس ساعت کاری</option>
      <option value="online">همیشه آنلاین (AI خودکار جواب ندهد)</option>
      <option value="offline">همیشه آفلاین (AI جواب بدهد)</option>
    </select>
  </div>

  <div class="field">
    <label>روزهای کاری</label>
    <div id="supDays" style="display:flex;gap:6px;flex-wrap:wrap"></div>
  </div>

  <div class="row" style="gap:10px;max-width:520px">
    <div class="field col"><label>از ساعت</label><input id="supFrom" class="mono" dir="ltr" placeholder="09:00"></div>
    <div class="field col"><label>تا ساعت</label><input id="supTo" class="mono" dir="ltr" placeholder="18:00"></div>
    <div class="field col"><label>منطقه‌ی زمانی</label><select id="supTz"></select></div>
  </div>
  <div class="note" style="margin-bottom:14px">اگر «تا» کوچک‌تر از «از» باشد، بازه از نیمه‌شب می‌گذرد (مثلاً ۲۲:۰۰ تا ۰۶:۰۰).</div>

  <button class="btn btn-primary" onclick="saveSupport()">ذخیره‌ی ساعت کاری</button>
</div>

<!-- پایگاه دانش هوش مصنوعی: منبعی که پاسخ‌ها از آن ساخته می‌شوند -->
<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:4px">پایگاه دانش هوش مصنوعی</div>
  <div class="note" style="margin-bottom:14px">
    هر چیزی که دستیار باید بداند اینجا بنویس: معرفی محصولات، سیاست‌ها، سؤالات پرتکرار.
    هنگام پاسخ‌دادن، مرتبط‌ترین مدخل‌ها به پیام مشتری خودکار به AI داده می‌شوند.
    مدخلِ «همیشه» (📌) در هر پاسخ گنجانده می‌شود (مثل قوانین کلی).
    <br>علاوه بر این، AI خودکار از <b>نام/گروه/برچسب مشتری</b> و <b>پاسخ فرم‌های او</b> هم استفاده می‌کند.
  </div>

  <div id="kbNeedUpgrade" class="note" style="display:none;color:var(--err);margin-bottom:12px">
    جدول دانش هنوز ساخته نشده — یک‌بار در تب «تنظیمات کانال‌ها» دکمه‌ی «به‌روزرسانی دیتابیس» را بزن.
  </div>

  <!-- فرم افزودن/ویرایش -->
  <div class="row" style="gap:10px">
    <div class="field col"><label>عنوان</label><input id="kbTitle" placeholder="مثلاً: ساعت‌های ارسال سفارش"></div>
    <div class="field col"><label>کلیدواژه‌ها (اختیاری، با ویرگول)</label><input id="kbKeywords" placeholder="ارسال، پست، سفارش"></div>
  </div>
  <div class="field">
    <label>محتوا</label>
    <textarea id="kbContent" rows="4" placeholder="پاسخ یا اطلاعاتی که AI باید بداند…"></textarea>
  </div>
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
      <input type="checkbox" id="kbPinned"><span>📌 همیشه به AI داده شود</span>
    </label>
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
      <input type="checkbox" id="kbActive" checked><span>فعال</span>
    </label>
    <input type="hidden" id="kbId" value="">
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="saveKb()">افزودن مدخل</button>
    <button class="btn btn-ghost" onclick="resetKb()" id="kbCancelBtn" style="display:none">انصراف از ویرایش</button>
  </div>

  <!-- درون‌ریزی فایل متنی -->
  <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
    <label style="font-size:12.5px;font-weight:600">درون‌ریزی از فایل متنی</label>
    <div class="note" style="margin:4px 0 8px">
      فایل txt/md/csv را بده؛ هر پاراگراف (جداشده با خط خالی) یک مدخل می‌شود.
      PDF و Word پشتیبانی نمی‌شوند — اول به متن تبدیلشان کن.
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="file" id="kbFile" accept=".txt,.md,.csv">
      <button class="btn btn-ghost btn-sm" onclick="importKb()">درون‌ریزی</button>
    </div>
  </div>

  <!-- فهرست مدخل‌ها -->
  <div id="kbList" style="margin-top:16px"></div>
</div>

