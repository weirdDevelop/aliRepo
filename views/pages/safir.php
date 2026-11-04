<?php /* pages/safir.php — ارسال با شماره (صفیر + پیامک) */ ?>
<div class="page-head">
  <h1>ارسال با شماره</h1>
  <p>صفیر بله و پیامک — هر دو با شماره موبایل کار می‌کنند</p>
</div>

<?php if (Auth::isAdmin()): ?>
<div class="row" style="margin-bottom:16px">
  <div class="col card card-pad">
    <div style="font-weight:600;margin-bottom:14px">تنظیمات صفیر بله</div>
    <div class="field"><label>کلید دسترسی سازمان</label><input id="sfKey" class="mono" placeholder="API Access Key"><div class="note" id="sfKeyStatus"></div></div>
    <div class="field"><label>bot_id صفیر</label><input id="sfBotId" class="mono" placeholder="مثلاً 123456789"></div>
    <button class="btn btn-primary btn-sm" onclick="saveSafir()">ذخیره صفیر</button>
  </div>
  <div class="col card card-pad">
    <div style="font-weight:600;margin-bottom:14px">تنظیمات پیامک</div>
    <div class="field"><label>شرکت پیامک</label>
      <select id="smProvider" onchange="onProviderChange()">
        <option value="kavenegar">کاوه‌نگار</option>
        <option value="smsir">sms.ir</option>
        <option value="melipayamak">ملی‌پیامک</option>
      </select>
    </div>
    <!-- فیلد کلید واحد (کاوه‌نگار / sms.ir) -->
    <div class="field" id="smKeyField"><label>کلید API <span class="muted" id="smKeyHint"></span></label><input id="smKey" class="mono" placeholder="کلید"><div class="note" id="smKeyStatus"></div></div>
    <!-- دو فیلد جدا (ملی‌پیامک) -->
    <div class="field" id="smUserField" style="display:none"><label>نام کاربری ملی‌پیامک (شماره موبایل حساب)</label><input id="smUser" class="mono" placeholder="مثلاً 09123456789"></div>
    <div class="field" id="smPassField" style="display:none"><label>رمز عبور یا کلید وب‌سرویس</label><input id="smPass" class="mono" placeholder="رمز یا کلید UUID"></div>
    <div class="field"><label>خط فرستنده (sender)</label><input id="smSender" class="mono" placeholder="مثلاً 30008666306570"><div class="note">شماره‌ی خطی که پیام از آن ارسال می‌شود.</div></div>
    <button class="btn btn-primary btn-sm" onclick="saveSms()">ذخیره پیامک</button>
  </div>
</div>
<?php endif; ?>

<div class="row">
  <div class="col card card-pad">
    <div style="font-weight:600;margin-bottom:14px">پیام</div>
    <div class="field"><label>متن پیام</label><textarea id="phText" placeholder="متن…"></textarea>
      <div class="token-bar" id="phTokenBar">
        <span class="token-bar-label">درج خودکار:</span>
        <button type="button" class="chip-btn" onclick="insertToken('phText','{name}')">نام مشتری</button>
        <button type="button" class="chip-btn" onclick="insertToken('phText','{phone}')">موبایل</button>
        <select id="phLinkPick" class="chip-select" onchange="insertTrackLink('phText','phLinkPick')">
          <option value="">🔗 لینک رهگیری…</option>
        </select>
      </div>
      <div class="note">«{name}» با نام مشتری (در صورت ثبت‌بودن شماره در بانک مشتری) و لینک رهگیری با آدرس اختصاصی همان شخص پر می‌شود.</div>
    </div>
    <div class="field"><label>دکمه‌ها (JSON اختیاری — فقط صفیر)</label><textarea id="phButtons" class="mono" placeholder='[[{"text":"سایت","url":"https://..."}]]'></textarea></div>
    <div class="field"><label>عکس (اختیاری — فقط صفیر)</label><input type="file" id="phImage" accept="image/*" onchange="uploadPhImage()"><div class="note" id="phImgStatus"></div></div>
  </div>

  <div class="col card card-pad" style="max-width:360px">
    <div style="font-weight:600;margin-bottom:14px">شماره‌ها و روش ارسال</div>
    <div class="field">
      <label>روش ارسال</label>
      <select id="phMode">
        <option value="safir_then_sms">اول صفیر، پیامک فقط برای کسانی که بله ندارند (کم‌هزینه)</option>
        <option value="safir">فقط صفیر بله</option>
        <option value="sms">فقط پیامک</option>
        <option value="both">هر دو همزمان</option>
      </select>
      <div class="note">حالت کم‌هزینه: هزینه‌ی پیامک فقط برای کسانی که بله ندارند.</div>
    </div>
    <div class="field">
      <label>شماره‌ها (هر خط یا با کاما)</label>
      <textarea id="phPhones" class="mono" style="min-height:130px" placeholder="09123456789&#10;989123456789"></textarea>
      <div class="note">شماره‌های نامعتبر/تکراری خودکار حذف می‌شوند.</div>
    </div>
    <div class="field">
      <label>یا بارگذاری فایل (CSV یا Excel)</label>
      <input type="file" id="phFile" accept=".csv,.txt,.xlsx" onchange="loadPhonesFile()">
      <div class="note" id="phFileStatus">اکسل را می‌توانید مستقیم بدهید یا از اکسل خروجی CSV بگیرید.</div>
    </div>
    <div class="field">
      <label>یا گرفتن از گروه/برچسب</label>
      <div style="display:flex;gap:6px">
        <select id="phGroup" style="flex:1"><option value="">گروه…</option></select>
        <select id="phTag" style="flex:1"><option value="">برچسب…</option></select>
        <button class="btn btn-ghost btn-sm" onclick="loadPhonesFromGroup()">افزودن</button>
      </div>
      <div class="note" id="phGroupStatus">شماره‌ی اعضای دارای موبایل در گروه/برچسب انتخابی افزوده می‌شود.</div>
    </div>
    <button class="btn btn-primary" id="phSendBtn" onclick="sendPhone()" style="width:100%">ارسال انبوه</button>
    <button class="btn btn-ghost" id="phVoiceBtn" onclick="sendVoice()" style="width:100%;margin-top:8px;display:none">📞 تماس گویا (متن بالا خوانده می‌شود)</button>
    <div id="phVoiceNote" class="note" style="margin-top:6px;display:none">تلفن گویا با ملی‌پیامک فعال است. متن باید اعراب‌گذاری‌شده باشد تا درست خوانده شود.</div>

    <div id="phVoiceFileWrap" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--line-soft)">
      <div style="font-weight:600;font-size:13px;margin-bottom:8px">📁 تماس با فایل صوتی (WAV)</div>
      <div class="field"><label>عنوان</label><input id="phVfTitle" placeholder="پیام صوتی"></div>
      <div class="field"><label>فایل WAV (حداکثر ۵ مگابایت)</label><input type="file" id="phVfFile" accept=".wav" onchange="uploadVoiceFile()"><div class="note" id="phVfStatus"></div></div>
      <button class="btn btn-ghost btn-sm" id="phVfSendBtn" onclick="sendBulkVoice()" style="display:none;width:100%">تماس انبوه با این فایل به شماره‌های بالا</button>
      <div class="note">طبق ملی‌پیامک: تماس انبوه با فایل فقط ساعت ۸ تا ۲۲، برای حداقل ۲ شماره، و چند دقیقه بعد انجام می‌شود (زمان‌دار است، فوری نیست). برای تماس فوری تکی از «تماس گویا» با متن استفاده کنید.</div>
    </div>
    <div id="phResult" class="note" style="margin-top:12px"></div>
  </div>
</div>
