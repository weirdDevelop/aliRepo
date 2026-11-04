<?php /* pages/send.php */ ?>
<div class="page-head">
  <h1>ارسال پیام</h1>
  <p>ارسال انبوه به مشتریان. سیستم برای هر شخص، کانال را بر اساس اولویت انتخاب می‌کند</p>
</div>

<div class="row">
  <div class="col card card-pad">
    <div style="font-weight:600;margin-bottom:14px">پیام</div>
    <div class="field">
      <label>الگو (اختیاری)</label>
      <select id="sndTpl" onchange="onTplPick()"><option value="">— بدون الگو، متن دستی —</option></select>
    </div>
    <div class="field">
      <label>متن پیام</label>
      <textarea id="sndText" placeholder="متن…"></textarea>
      <div class="token-bar" id="sndTokenBar">
        <span class="token-bar-label">درج خودکار:</span>
        <button type="button" class="chip-btn" onclick="insertToken('sndText','{name}')">نام مشتری</button>
        <button type="button" class="chip-btn" onclick="insertToken('sndText','{phone}')">موبایل</button>
        <select id="sndLinkPick" class="chip-select" onchange="insertTrackLink('sndText','sndLinkPick')">
          <option value="">🔗 لینک رهگیری…</option>
        </select>
      </div>
      <div class="note">«{name}» با نام هر مشتری و لینک رهگیری با آدرس اختصاصی همان شخص پر می‌شود.</div>
    </div>
    <div class="field">
      <label>دکمه‌ها (JSON اختیاری)</label>
      <textarea id="sndButtons" class="mono" placeholder='[[{"text":"سایت","url":"https://..."}]]'></textarea>
    </div>
    <div class="field">
      <label>عکس (اختیاری)</label>
      <input type="file" id="sndImage" accept="image/*" onchange="uploadSndImage()">
      <div class="note" id="sndImgStatus"></div>
    </div>
  </div>

  <div class="col card card-pad" style="max-width:340px">
    <div style="font-weight:600;margin-bottom:14px">مخاطب و کانال</div>
    <div class="field">
      <label>وضعیت مشتری</label>
      <select id="sndStatus" onchange="refreshCount()">
        <option value="">همه</option>
        <option value="member">فقط اعضا</option>
        <option value="anonymous">فقط ناشناس</option>
      </select>
    </div>
    <div class="field">
      <label>گروه</label>
      <select id="sndGroup" onchange="refreshCount()"><option value="">همه گروه‌ها</option></select>
    </div>
    <div class="field">
      <label>برچسب</label>
      <select id="sndTag" onchange="refreshCount()"><option value="">همه برچسب‌ها</option></select>
    </div>
    <div class="field">
      <label>اولویت کانال</label>
      <input id="sndPriority" value="bale,telegram,roubika" class="mono">
      <div class="note">به ترتیب تلاش. برای هر شخص اولین کانال موجود انتخاب می‌شود.</div>
    </div>
    <div class="sep"></div>
    <div style="font-size:13px;margin-bottom:12px">
      مخاطبان هدف: <strong id="audCount">—</strong> نفر
    </div>
    <button class="btn btn-primary" onclick="doSend()" id="sendBtn" style="width:100%">ارسال انبوه</button>
    <div id="sendResult" class="note" style="margin-top:12px"></div>
  </div>
</div>
