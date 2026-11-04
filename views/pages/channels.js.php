// pages/channels.js.php
const CH_NAMES = {bale:'بله', telegram:'تلگرام', roubika:'روبیکا'};

async function loadChannels(){
  const d = await api('channels_status');
  const wrap = document.getElementById('channelsWrap');
  if(!d.ok){ wrap.innerHTML='<div class="empty">خطا: '+esc(d.error||'نامشخص')+'</div>'; return; }
  let h = '';
  for(const ch of Object.keys(d.channels)){
    // فقط کانال‌های «تک‌توکنه»ی خانواده‌ی ربات اینجا کارت توکن می‌گیرند.
    // صفیر/پیامک/ایمیل فرم اختصاصی خودشان را پایین‌تر در همین صفحه دارند و
    // گفتگوی آنلاین سایت اصلاً توکن ندارد (صفحه‌ی جدا: گفتگوی آنلاین سایت).
    if(!CH_NAMES[ch]) continue;
    const conf = d.channels[ch].configured;
    h += `
      <div class="card card-pad" style="margin-bottom:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div style="font-weight:600;font-size:15px">${CH_NAMES[ch]||ch}</div>
          <span class="tag ${conf?'tag-ok':'tag-mute'}">${conf?'تنظیم‌شده':'تنظیم‌نشده'}</span>
        </div>
        <div class="field">
          <label>توکن ربات</label>
          <input id="tok_${ch}" type="text" class="mono" placeholder="${conf?'••• (برای تغییر، توکن جدید وارد کنید)':'توکن را وارد کنید'}">
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="saveToken('${ch}')">ذخیره توکن</button>
          <button class="btn btn-ghost btn-sm" onclick="setHook('${ch}')" ${conf?'':'disabled style=opacity:.5'}>ثبت وب‌هوک</button>
        </div>
        <div id="hook_${ch}" class="note" style="margin-top:8px"></div>
      </div>`;
  }
  wrap.innerHTML = h;
}

async function saveToken(ch){
  const token = document.getElementById('tok_'+ch).value.trim();
  if(!token){ toast('توکن خالی است', false); return; }
  const d = await api('channel_save_token', {channel:ch, token});
  if(d.ok){ toast('توکن ذخیره شد'); loadChannels(); }
  else toast(d.error||'خطا', false);
}

async function setHook(ch){
  const box = document.getElementById('hook_'+ch);
  box.textContent = 'در حال ثبت…';
  const d = await api('channel_set_webhook', {channel:ch});
  if(d.ok){
    toast('وب‌هوک ثبت شد');
    box.innerHTML = 'آدرس وب‌هوک: <span class="mono">'+esc(d.webhook_url)+'</span>';
  } else { toast(d.error||'خطا', false); box.textContent=''; }
}

loadChannels();
