<?php /* pages/webchat.php — گفتگوی آنلاین سایت (مورد ۱۳) */ ?>
<div class="page-head">
  <h1>گفتگوی آنلاین سایت</h1>
  <p>یک خط کد در سایتت بگذار؛ بازدیدکننده از همان صفحه پیام می‌دهد و گفتگو در صندوق ورودی همین پنل می‌نشیند</p>
</div>

<div class="row" id="wcStats" style="gap:10px;margin-bottom:14px"></div>

<div class="row">
  <!-- ستون تنظیمات -->
  <div class="col card card-pad" style="max-width:430px">
    <div style="font-weight:600;margin-bottom:14px">تنظیمات ویجت</div>

    <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;cursor:pointer">
      <input type="checkbox" id="wcEnabled" style="width:auto">
      <span>گفتگوی آنلاین سایت فعال باشد</span>
    </label>
    <div class="note" style="margin-bottom:16px;font-size:11.5px">
      تا این کلید روشن نشود، ویجت روی سایت هیچ چیزی نشان نمی‌دهد و <span class="mono" dir="ltr">chat.php</span> به هیچ درخواستی جواب نمی‌دهد.
    </div>

    <div class="field"><label>عنوان پنجره</label><input id="wcTitle" placeholder="گفتگو با پشتیبانی"></div>
    <div class="field"><label>زیرعنوان</label><input id="wcSubtitle" placeholder="معمولاً در چند دقیقه پاسخ می‌دهیم"></div>

    <div class="field">
      <label>پیام خوش‌آمد (در ساعت کاری)</label>
      <textarea id="wcWelcome" rows="2" placeholder="سلام! چطور می‌توانم کمکتان کنم؟"></textarea>
    </div>

    <div class="field">
      <label>پیام خارج از ساعت کاری</label>
      <textarea id="wcOffline" rows="2" placeholder="الان خارج از ساعت کاری هستیم؛ پیامتان را بگذارید."></textarea>
      <div class="note" style="margin-top:5px;font-size:11.5px">
        ساعت کاری از همان تنظیمات «پشتیبانی تیمی» خوانده می‌شود تا دو جا دو جواب ندهد.
      </div>
    </div>

    <div class="field">
      <label>شروع گفتگو</label>
      <select id="wcStartMode">
        <option value="open">بدون فرم — بعد از اولین پاسخ، نام و شماره پیشنهاد می‌شود</option>
        <option value="form">فرم نام و موبایل، پیش از باز شدن چت</option>
        <option value="require_phone">شماره موبایل اجباری — تا شماره نده پیام نمی‌رود</option>
      </select>
      <div class="note" style="margin-top:5px;font-size:11.5px">
        «بدون فرم» کمترین ریزش را دارد؛ «اجباری» برای فروش تلفنی مناسب است ولی بخشی از بازدیدکننده‌ها منصرف می‌شوند.
      </div>
    </div>

    <div class="field" style="display:flex;gap:16px;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
        <input type="checkbox" id="wcAskName" style="width:auto"> نام را بپرس
      </label>
      <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer">
        <input type="checkbox" id="wcAskPhone" style="width:auto"> شماره موبایل را بپرس
      </label>
    </div>

    <div class="field">
      <label>جای حباب چت</label>
      <select id="wcPosition">
        <option value="right">پایین راست</option>
        <option value="left">پایین چپ</option>
      </select>
    </div>

    <div class="field">
      <label>فاصله از پایین در گوشی (پیکسل)</label>
      <input id="wcMobileOffset" class="mono" dir="ltr" value="78">
      <div class="note">اگر قالب سایتت تو گوشی یک نوار منوی ثابت پایین صفحه داره و دکمه‌ی چت روش می‌افته، این عدد رو بیشتر کن.</div>
    </div>

    <div class="field">
      <label>رنگ ویجت</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="color" id="wcColor" style="width:54px;padding:2px;height:36px">
        <button class="btn btn-ghost btn-xs" onclick="wcUseBrand()">از رنگ برند پنل استفاده کن</button>
      </div>
      <div class="note" style="margin-top:5px;font-size:11.5px" id="wcColorNote"></div>
    </div>

    <div style="font-weight:600;margin:18px 0 10px;padding-top:14px;border-top:1px solid var(--line-soft)">سفارشی‌سازی کامل ظاهر</div>

    <div class="row" style="gap:10px">
      <div class="col field">
        <label>پس‌زمینه‌ی پنل گفتگو</label>
        <input type="color" id="wcBgColor" style="width:54px;padding:2px;height:36px">
      </div>
      <div class="col field">
        <label>رنگ متن پیام‌های اپراتور</label>
        <input type="color" id="wcTextColor" style="width:54px;padding:2px;height:36px">
      </div>
    </div>

    <div class="field">
      <label>فونت <span class="muted">(خالی = فونت پیش‌فرض ویجت)</span></label>
      <select id="wcFontFamily">
        <option value="">پیش‌فرض (Vazirmatn)</option>
        <option value="IRANSans">ایران‌سنس</option>
        <option value="Shabnam">شبنم</option>
        <option value="Yekan Bakh">یکان بخ</option>
        <option value="Tahoma">Tahoma</option>
        <option value="Arial">Arial</option>
      </select>
      <div class="note" style="margin-top:5px">اگر فونت انتخابی روی سایت مقصد نصب نباشد، مرورگر خودش جایگزین می‌کند؛ فونت‌ها را باید خودت روی سایتت لود کرده باشی.</div>
    </div>

    <div class="row" style="gap:10px">
      <div class="col field">
        <label>شعاع گوشه‌های پنل (پیکسل) <span class="muted">۰ = چهارگوش</span></label>
        <input id="wcBorderRadius" type="number" min="0" max="28" value="16">
      </div>
      <div class="col field">
        <label>اندازه‌ی دکمه‌ی شناور (پیکسل)</label>
        <input id="wcLauncherSize" type="number" min="40" max="88" value="58">
      </div>
    </div>

    <div class="field">
      <label>آیکون دکمه‌ی شناور <span class="muted">(خالی = آیکون پیش‌فرض)</span></label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="file" id="wcLauncherIconFile" accept="image/*" onchange="wcUploadLauncherIcon()">
        <button class="btn btn-ghost btn-xs" id="wcLauncherIconClear" onclick="wcClearLauncherIcon()" style="display:none">حذف عکس</button>
      </div>
      <div class="note" style="margin-top:5px;font-size:11.5px" id="wcLauncherIconStatus"></div>
    </div>

    <div class="field">
      <label>دامنه‌های مجاز (هر خط یک دامنه)</label>
      <textarea id="wcHosts" rows="3" class="mono" dir="ltr" placeholder="example.com&#10;shop.example.com"></textarea>
      <div class="note" style="margin-top:5px;font-size:11.5px">
        خالی = هر سایتی می‌تواند ویجت را سوار کند. اگر پر کنی، فقط همین دامنه‌ها جواب می‌گیرند.
      </div>
    </div>

    <div class="field">
      <label>فاصله‌ی بررسی پیام تازه (ثانیه)</label>
      <input id="wcPoll" type="number" min="2" max="30" style="max-width:110px">
      <div class="note" style="margin-top:5px;font-size:11.5px">عدد کمتر = پاسخ سریع‌تر، بار بیشتر روی سرور. پیشنهاد: ۴</div>
    </div>

    <button class="btn btn-primary" onclick="wcSave()">ذخیره‌ی تنظیمات</button>
    <div id="wcSaveMsg" class="note" style="margin-top:10px"></div>
  </div>

  <!-- ستون نصب + نشست‌ها -->
  <div class="col" style="display:flex;flex-direction:column;gap:14px">
    <div class="card card-pad">
      <div style="font-weight:600;margin-bottom:10px">کد نصب</div>
      <p class="note" style="margin:0 0 10px;font-size:12px">
        این یک خط را پیش از <span class="mono" dir="ltr">&lt;/body&gt;</span> سایتت بگذار. آدرس سرور خودش از همین لینک درمی‌آید،
        پس چیزی برای تنظیم دستی نمی‌ماند.
      </p>
      <pre id="wcEmbed" class="mono" dir="ltr" style="background:var(--bg);padding:12px;border-radius:8px;overflow-x:auto;font-size:11.5px;margin:0">…</pre>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button class="btn btn-ghost btn-xs" onclick="wcCopyEmbed()">کپی کد</button>
        <a class="btn btn-ghost btn-xs" id="wcTestLink" href="#" target="_blank" rel="noopener">آزمایش ویجت</a>
      </div>
    </div>

    <div class="card">
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)">
        <strong style="font-size:13px">نشست‌های بازدیدکننده</strong>
        <input id="wcQ" placeholder="نام، شماره، IP یا صفحه…" style="flex:1;min-width:150px" oninput="wcDebounced()">
        <select id="wcDays" onchange="wcLoadSessions()" style="width:auto">
          <option value="0">همه‌ی زمان‌ها</option>
          <option value="1">۲۴ ساعت گذشته</option>
          <option value="7" selected>۷ روز گذشته</option>
          <option value="30">۳۰ روز گذشته</option>
        </select>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer">
          <input type="checkbox" id="wcOnlyId" style="width:auto" onchange="wcLoadSessions()"> فقط شناسایی‌شده
        </label>
      </div>
      <table><thead><tr>
        <th>بازدیدکننده</th><th>صفحه</th><th>دستگاه</th><th>پیام</th><th>آخرین حضور</th><th></th>
      </tr></thead>
      <tbody id="wcRows"><tr><td colspan="6" class="empty">…</td></tr></tbody></table>
    </div>
  </div>
</div>
