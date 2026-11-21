<?php /* pages/channels.php */ ?>
<div class="page-head">
  <h1>تنظیمات کانال</h1>
  <p>توکن هر پلتفرم را وارد و وب‌هوک را ثبت کنید</p>
</div>

<?php if (!Auth::isAdmin()): ?>
  <div class="card card-pad muted">این بخش فقط برای مدیر در دسترس است.</div>
<?php else: ?>

<div id="channelsWrap">
  <div class="empty">در حال بارگذاری…</div>
</div>

<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:6px">راهنما</div>
  <p class="note">
    توکن ربات را از BotFather هر پلتفرم بگیرید. پس از ذخیره‌ی توکن، دکمه‌ی «ثبت وب‌هوک» را بزنید
    تا پیام‌های ورودی به سیستم برسند. روبیکا از <span class="mono">updateBotEndpoint</span> و
    بله/تلگرام از <span class="mono">setWebhook</span> استفاده می‌کنند (سیستم خودکار مدیریت می‌کند).
  </p>
</div>

<div class="card card-pad" style="margin-top:16px">
  <div style="font-weight:600;margin-bottom:6px">به‌روزرسانی دیتابیس</div>
  <p class="note" style="margin-bottom:10px">اگر تازه نسخه را به‌روز کرده‌اید و قابلیتی کار نمی‌کند (مثلاً تاریخچه‌ی ارسال خالی است)، این دکمه جداول لازم را می‌سازد یا تکمیل می‌کند. اجرای چندباره بی‌خطر است.</p>
  <button class="btn btn-ghost btn-sm" onclick="dbUpgrade()">بررسی و به‌روزرسانی جداول</button>
  <div id="dbUpgradeResult" class="note" style="margin-top:8px"></div>
</div>

<script>
async function dbUpgrade(){
  const r=document.getElementById('dbUpgradeResult');
  r.textContent='در حال بررسی…';
  const d=await api('db_upgrade');
  r.innerHTML = d.ok ? '<span class="tag tag-ok">انجام شد</span> '+esc(d.message||'') : '<span class="tag tag-warn">خطا</span> '+esc(d.error||'');
}
</script>

<div class="card card-pad" style="margin-top:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:6px">ایمیل (SMTP)</div>
  <p class="note" style="margin-bottom:12px">اطلاعات SMTP سرویس ایمیلتان را وارد کنید تا بتوانید ایمیل بفرستید.</p>
  <div class="row" style="gap:10px">
    <div class="col" style="min-width:200px"><label>SMTP Host</label><input id="emHost" class="mono" placeholder="smtp.gmail.com" dir="ltr"></div>
    <div class="col" style="max-width:90px"><label>Port</label><input id="emPort" class="mono" value="587" dir="ltr"></div>
    <div class="col" style="max-width:110px"><label>امنیت</label><select id="emSecure"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="">بدون</option></select></div>
  </div>
  <div class="field"><label>نام کاربری</label><input id="emUser" class="mono" dir="ltr"></div>
  <div class="field"><label>رمز عبور <span class="muted" id="emPassHint"></span></label><input id="emPass" type="password" class="mono" dir="ltr"></div>
  <div class="field"><label>فرستنده (From)</label><input id="emFrom" class="mono" placeholder="noreply@yoursite.com" dir="ltr"></div>
  <button class="btn btn-primary btn-sm" onclick="saveEmail()">ذخیره ایمیل</button>
  <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line-soft)">
    <div class="field"><label>تست: ارسال به</label><input id="emTest" class="mono" placeholder="test@example.com" dir="ltr"></div>
    <button class="btn btn-ghost btn-sm" onclick="testEmail()">ارسال ایمیل تست</button>
    <div id="emResult" class="note" style="margin-top:8px"></div>
  </div>
</div>

<script>
async function loadEmail(){
  const d=await api('email_get');
  if(!d.ok)return;
  emHost.value=d.host||''; emPort.value=d.port||'587'; emUser.value=d.user||'';
  emFrom.value=d.from||''; emSecure.value=d.secure||'tls';
  document.getElementById('emPassHint').textContent=d.has_pass?'(ذخیره شده - خالی = بدون تغییر)':'';
}
async function saveEmail(){
  const data={host:emHost.value.trim(),port:emPort.value.trim(),user:emUser.value.trim(),from:emFrom.value.trim(),secure:emSecure.value};
  if(emPass.value) data.pass=emPass.value;
  const d=await api('email_save',data);
  if(d.ok){toast('ذخیره شد');emPass.value='';loadEmail();}else toast(d.error||'خطا',false);
}
async function testEmail(){
  const to=document.getElementById('emTest').value.trim();
  if(!to){toast('ایمیل مقصد را وارد کنید',false);return;}
  const r=document.getElementById('emResult'); r.textContent='در حال ارسال…';
  const d=await api('email_test',{to});
  r.innerHTML=d.ok?'<span class="tag tag-ok">ارسال شد</span>':'<span class="tag tag-warn">خطا</span> '+esc(d.error||'');
}
document.addEventListener("DOMContentLoaded", loadEmail);   // 2.4.1: api() در layout بعد از این اسکریپت تعریف می‌شود
</script>

<!-- پروکسی ضدفیلترینگ (مورد ۳) -->
<div class="card card-pad" style="margin-top:16px;max-width:560px">
  <div style="font-weight:600;margin-bottom:6px">پروکسی ضدفیلترینگ</div>
  <p class="note" style="margin-bottom:12px">
    برای سرویس‌هایی که در ایران فیلترند (تلگرام، و در آینده واتساپ وقتی اضافه شود؛ همچنین
    OpenRouter/OpenAI در بخش هوش مصنوعی) می‌توانی یک پروکسی HTTP/SOCKS5 تعریف کنی و انتخاب کنی
    کدام سرویس‌ها از آن رد شوند. بله/روبیکا/ابرآروان/GapGPT ایرانی‌اند و معمولاً لازم ندارند.
  </p>

  <label class="switch-row" style="display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer">
    <input type="checkbox" id="pxEnabled">
    <span style="font-weight:600">پروکسی فعال باشد</span>
  </label>

  <div class="row" style="gap:10px">
    <div class="col" style="max-width:140px"><label>نوع</label>
      <select id="pxType">
        <option value="http">HTTP</option>
        <option value="socks5">SOCKS5</option>
        <option value="socks5h">SOCKS5 (DNS از پروکسی)</option>
      </select>
    </div>
    <div class="col" style="min-width:180px"><label>آدرس/هاست</label><input id="pxHost" class="mono" dir="ltr" placeholder="127.0.0.1"></div>
    <div class="col" style="max-width:100px"><label>پورت</label><input id="pxPort" class="mono" dir="ltr" placeholder="1080"></div>
  </div>
  <div class="row" style="gap:10px">
    <div class="col"><label>یوزرنیم (اختیاری)</label><input id="pxUser" class="mono" dir="ltr"></div>
    <div class="col"><label>پسورد (اختیاری) <span class="muted" id="pxPassHint"></span></label><input id="pxPass" type="password" class="mono" dir="ltr"></div>
  </div>

  <div class="field">
    <label>این پروکسی برای کدام سرویس‌ها استفاده شود؟</label>
    <div id="pxTargets" style="display:flex;flex-direction:column;gap:6px;margin-top:6px"></div>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
    <button class="btn btn-primary btn-sm" onclick="savePx()">ذخیره پروکسی</button>
    <button class="btn btn-ghost btn-sm" onclick="testPx('telegram')">تست روی تلگرام</button>
  </div>
  <div id="pxResult" class="note" style="margin-top:8px"></div>
</div>

<script>
const PX_TARGET_LABELS = {
  telegram:'تلگرام',
  whatsapp:'واتساپ (این نسخه هنوز کانال واتساپ ندارد — آماده برای آینده)',
  ai_openrouter:'هوش مصنوعی — OpenRouter',
  ai_openai:'هوش مصنوعی — OpenAI',
  ai_custom:'هوش مصنوعی — پروایدر سفارشی',
};
async function loadPx(){
  const d = await api('proxy_settings');
  if(!d.ok) return;
  document.getElementById('pxEnabled').checked = d.enabled === '1';
  document.getElementById('pxType').value = d.type || 'http';
  document.getElementById('pxHost').value = d.host || '';
  document.getElementById('pxPort').value = d.port || '';
  document.getElementById('pxUser').value = d.username || '';
  document.getElementById('pxPassHint').textContent = d.password_set ? '(ذخیره شده - خالی = بدون تغییر)' : '';
  const targets = (d.targets||'').split(',').map(s=>s.trim()).filter(Boolean);
  document.getElementById('pxTargets').innerHTML = Object.keys(PX_TARGET_LABELS).map(k=>`
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
      <input type="checkbox" class="pxT" value="${k}" ${targets.includes(k)?'checked':''}>
      <span>${esc(PX_TARGET_LABELS[k])}</span>
    </label>`).join('');
}
async function savePx(){
  const targets = Array.from(document.querySelectorAll('.pxT:checked')).map(el=>el.value).join(',');
  const data = {
    enabled: document.getElementById('pxEnabled').checked ? 1 : 0,
    type: document.getElementById('pxType').value,
    host: document.getElementById('pxHost').value.trim(),
    port: document.getElementById('pxPort').value.trim(),
    username: document.getElementById('pxUser').value.trim(),
    targets: targets,
  };
  if(document.getElementById('pxPass').value) data.password = document.getElementById('pxPass').value;
  const d = await api('proxy_save', data);
  if(d.ok){ toast('ذخیره شد'); document.getElementById('pxPass').value=''; loadPx(); }
  else toast(d.error||'خطا', false);
}
async function testPx(target){
  const box = document.getElementById('pxResult');
  box.innerHTML = '<span class="muted">در حال تست…</span>';
  const d = await api('proxy_test', {target});
  box.innerHTML = d.ok
    ? `<span class="tag tag-ok">وصل شد</span> <span class="muted">کد ${faNum(d.code)} · ${faNum(d.ms)} میلی‌ثانیه</span>`
    : `<span class="tag tag-warn">ناموفق</span> ${esc(d.error||'')}`;
}
document.addEventListener("DOMContentLoaded", loadPx);
</script>
<!-- اینستاگرام (دایرکت) — با Instagram Login، بدون نیاز به صفحه‌ی فیسبوک -->
<div class="card card-pad" id="igGraphCard" style="margin-top:16px;max-width:620px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
    <div style="font-weight:600">اینستاگرام (دایرکت)</div>
    <span class="tag" id="igStatusTag">…</span>
  </div>
  <p class="note" style="margin-bottom:14px">
    با «Instagram API with Instagram Login» — نیازی به صفحه‌ی فیسبوک متصل نیست. حساب باید نوع
    Business یا Creator باشد. App ID/Secret را از بخش «Instagram business login» در
    <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">پنل توسعه‌دهندگان متا</a> بگیر
    (نه App ID عمومی Settings → Basic).
  </p>
  <p class="note" style="margin-bottom:14px;border-inline-start:3px solid var(--accent);padding-inline-start:10px">
    این اتصال مستقل از پیج‌های <b>یار API</b> است — برای مدیریت چندپیجی، اتوماسیون کامنت،
    فالوی اجباری و انتشار پست/استوری، برو به <a href="index.php?page=instagram">منوی ارتباطات ← اینستاگرام</a>.
  </p>

  <div class="row" style="gap:10px">
    <div class="field col"><label>Instagram App ID</label><input id="igAppId" class="mono" dir="ltr"></div>
    <div class="field col"><label>Instagram App Secret</label><input id="igAppSecret" type="password" class="mono" dir="ltr" placeholder="خالی = دست‌نخورده بماند"></div>
  </div>
  <button class="btn btn-ghost btn-sm" onclick="saveIgApp()">ذخیره App ID/Secret</button>

  <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line-soft)">
    <div class="field">
      <label>Callback URL <span class="muted">(در «Valid OAuth Redirect URIs» ثبت کن)</span></label>
      <div style="display:flex;gap:6px"><input id="igRedirect" class="mono" dir="ltr" readonly><button class="btn btn-ghost btn-xs" onclick="copyField('igRedirect')">کپی</button></div>
    </div>
    <div class="field">
      <label>Webhook URL <span class="muted">(در تنظیمات Webhook محصول Instagram ثبت کن)</span></label>
      <div style="display:flex;gap:6px"><input id="igWebhook" class="mono" dir="ltr" readonly><button class="btn btn-ghost btn-xs" onclick="copyField('igWebhook')">کپی</button></div>
    </div>
    <div class="field">
      <label>Verify Token <span class="muted">(همان‌جا وارد کن)</span></label>
      <div style="display:flex;gap:6px"><input id="igVerify" class="mono" dir="ltr" readonly><button class="btn btn-ghost btn-xs" onclick="copyField('igVerify')">کپی</button></div>
    </div>
  </div>

  <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--line-soft);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <button class="btn btn-primary btn-sm" id="igConnectBtn" onclick="igConnect()">اتصال با اینستاگرام</button>
    <button class="btn btn-danger-ghost btn-sm" id="igDisconnectBtn" style="display:none" onclick="igDisconnect()">قطع اتصال</button>
    <button class="btn btn-ghost btn-sm" id="igTestBtn" style="display:none" onclick="igTest()">تست/تمدید اشتراک وب‌هوک</button>
    <span id="igConnected" class="muted" style="font-size:12.5px"></span>
  </div>
  <div id="igResult" class="note" style="margin-top:8px"></div>
</div>

<script>
async function copyField(id){
  const el = document.getElementById(id);
  navigator.clipboard?.writeText(el.value).then(()=>toast('کپی شد')).catch(()=>toast('کپی نشد', false));
}

async function loadIg(){
  const d = await api('instagram_get');
  if(!d.ok) return;
  document.getElementById('igAppId').value = d.app_id || '';
  document.getElementById('igAppSecret').placeholder = d.app_secret_set ? '•••••••• (تنظیم شده)' : 'App Secret را وارد کن';
  document.getElementById('igRedirect').value = d.redirect_uri || '';
  document.getElementById('igWebhook').value = d.webhook_url || '';
  document.getElementById('igVerify').value = d.verify_token || '';

  const tag = document.getElementById('igStatusTag');
  const connectBtn = document.getElementById('igConnectBtn');
  const disconnectBtn = document.getElementById('igDisconnectBtn');
  const testBtn = document.getElementById('igTestBtn');
  const connectedNote = document.getElementById('igConnected');

  if(d.connected){
    tag.className = 'tag tag-ok'; tag.textContent = 'متصل';
    connectBtn.style.display = 'none';
    disconnectBtn.style.display = '';
    testBtn.style.display = '';
    connectedNote.textContent = d.username ? ('@' + d.username) : '';
  } else {
    tag.className = 'tag tag-mute'; tag.textContent = 'متصل نیست';
    connectBtn.style.display = d.app_configured ? '' : 'none';
    disconnectBtn.style.display = 'none';
    testBtn.style.display = 'none';
    connectedNote.textContent = d.app_configured ? '' : 'اول App ID/Secret را ذخیره کن';
  }
}

async function saveIgApp(){
  const d = await api('instagram_save_app', {
    app_id: document.getElementById('igAppId').value.trim(),
    app_secret: document.getElementById('igAppSecret').value,
  });
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  document.getElementById('igAppSecret').value = '';
  toast('ذخیره شد');
  loadIg();
}

async function igConnect(){
  const d = await api('instagram_oauth_url');
  if(!d.ok){ toast(d.error||'شروع اتصال ناموفق بود', false); return; }
  window.location.href = d.url;
}

async function igDisconnect(){
  if(!confirm('اتصال اینستاگرام قطع شود؟ پیام‌های دایرکت دیگر دریافت نمی‌شوند تا دوباره وصل کنی.')) return;
  const d = await api('instagram_disconnect');
  if(d.ok){ toast('قطع شد'); loadIg(); } else toast(d.error||'خطا', false);
}

async function igTest(){
  const box = document.getElementById('igResult');
  box.innerHTML = '<span class="muted">در حال بررسی…</span>';
  const d = await api('instagram_test');
  box.innerHTML = d.ok
    ? '<span class="tag tag-ok">اشتراک وب‌هوک فعال است</span>'
    : `<span class="tag tag-warn">ناموفق</span> ${esc(d.error||'')}`;
}

// اگر همین الان از OAuth callback برگشتیم، نتیجه را نشان بده و از URL پاک کن
(function(){
  const p = new URLSearchParams(location.search);
  if(p.has('ig')){
    const ok = p.get('ig') === 'ok';
    toast(ok ? 'اینستاگرام متصل شد' : ('اتصال ناموفق: ' + (p.get('ig_msg')||'')), ok);
    const url = new URL(location.href);
    url.searchParams.delete('ig'); url.searchParams.delete('ig_msg');
    history.replaceState({}, '', url.toString());
  }
})();

document.addEventListener("DOMContentLoaded", loadIg);
</script>

<?php endif; ?>
