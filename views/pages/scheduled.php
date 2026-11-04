<?php /* pages/scheduled.php */ ?>
<div class="page-head"><h1>زمان‌بندی</h1><p>ارسال خودکار در زمان مشخص (نیازمند Cron)</p></div>
<div class="row">
  <div class="col card card-pad" style="max-width:400px">
    <div style="font-weight:600;margin-bottom:14px">زمان‌بندی جدید</div>
    <div class="field"><label>نام</label><input id="schName" placeholder="یادآوری نوروز"></div>
    <div class="field">
      <label>نوع ارسال</label>
      <select id="schType" onchange="onSchType()">
        <option value="chat">پیام‌رسان (بله/تلگرام/روبیکا)</option>
        <option value="phone">ارسال با شماره (صفیر/پیامک)</option>
        <option value="voice">تماس گویا</option>
        <option value="email">ایمیل</option>
      </select>
    </div>
    <div class="field"><label>الگو (اختیاری)</label><select id="schTpl" onchange="onSchTpl()"><option value="">— متن دستی —</option></select></div>
    <div class="field"><label>متن پیام</label><textarea id="schText"></textarea></div>
    <div class="field" id="schImgWrap">
      <label>عکس (اختیاری)</label>
      <input type="file" id="schImg" accept="image/*" onchange="uploadSchImg()">
      <div class="note" id="schImgStatus"></div>
    </div>
    <!-- هدف: گروه/برچسب برای chat/voice/email -->
    <div id="schAudience">
      <div class="field"><label>گروه هدف</label><select id="schGroup"><option value="">همه</option></select></div>
      <div class="field"><label>برچسب هدف</label><select id="schTag"><option value="">همه</option></select></div>
    </div>
    <!-- شماره‌ها برای phone/voice -->
    <div id="schPhones" style="display:none">
      <div class="field"><label>شماره‌ها (هر خط یک شماره) یا از گروه/برچسب بالا</label>
        <textarea id="schPhonesText" class="mono" placeholder="09123456789&#10;..."></textarea></div>
      <div class="field" id="schPhoneModeWrap"><label>روش ارسال با شماره</label>
        <select id="schPhoneMode">
          <option value="safir_then_sms">اول صفیر، بعد پیامک</option>
          <option value="safir">فقط صفیر</option>
          <option value="sms">فقط پیامک</option>
          <option value="both">هر دو</option>
        </select></div>
    </div>
    <div class="field" id="schChWrap"><label>اولویت کانال</label><input id="schChannels" class="mono" value="bale,telegram,roubika"></div>
    <div class="field"><label>زمان ارسال</label><input id="schTime" type="datetime-local"></div>
    <button class="btn btn-primary btn-sm" onclick="createSch()">زمان‌بندی</button>
    <div id="schResult" class="note" style="margin-top:8px"></div>
  </div>
  <div class="col card">
    <div style="overflow-x:auto">
    <table style="min-width:420px"><thead><tr><th>نام</th><th>نوع</th><th>زمان</th><th>وضعیت</th><th></th></tr></thead>
    <tbody id="schRows"><tr><td colspan="5" class="empty">…</td></tr></tbody></table>
    </div>
  </div>
</div>
