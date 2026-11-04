// pages/webchat.js.php — گفتگوی آنلاین سایت
let WC = { accent:'#1d4ed8', base:'', embed:'', brandAccent:'', online:true };
let WC_T = null;

function wcDebounced(){ clearTimeout(WC_T); WC_T=setTimeout(wcLoadSessions, 300); }

async function wcLoad(){
  const d = await api('webchat_settings', {});
  if(!d.ok){ document.getElementById('wcSaveMsg').textContent='خطا در بارگذاری تنظیمات'; return; }

  const s = d.settings || {};
  WC.base   = d.base_url || '';
  WC.embed  = d.embed || '';
  WC.accent = d.accent || '#1d4ed8';
  WC.online = !!d.online;

  document.getElementById('wcEnabled').checked   = s.enabled === '1';
  document.getElementById('wcTitle').value       = s.title || '';
  document.getElementById('wcSubtitle').value    = s.subtitle || '';
  document.getElementById('wcWelcome').value     = s.welcome || '';
  document.getElementById('wcOffline').value     = s.offline_text || '';
  document.getElementById('wcStartMode').value   = s.start_mode || 'open';
  document.getElementById('wcPosition').value    = s.position || 'right';
  document.getElementById('wcMobileOffset').value = s.mobile_bottom_offset || '78';
  document.getElementById('wcAskName').checked   = s.ask_name === '1';
  document.getElementById('wcAskPhone').checked  = s.ask_phone === '1';
  document.getElementById('wcHosts').value       = s.allowed_hosts || '';
  document.getElementById('wcPoll').value        = s.poll_seconds || '4';
  document.getElementById('wcBgColor').value     = /^#[0-9a-fA-F]{6}$/.test(s.bg_color||'') ? s.bg_color : '#ffffff';
  document.getElementById('wcTextColor').value   = /^#[0-9a-fA-F]{6}$/.test(s.text_color||'') ? s.text_color : '#0f172a';
  document.getElementById('wcFontFamily').value  = s.font_family || '';
  document.getElementById('wcBorderRadius').value = s.border_radius || '16';
  document.getElementById('wcLauncherSize').value = s.launcher_size || '58';
  WC.launcherIcon = s.launcher_icon || '';
  document.getElementById('wcLauncherIconStatus').textContent = WC.launcherIcon ? 'یک عکس آپلود شده' : 'آیکون پیش‌فرض';
  document.getElementById('wcLauncherIconClear').style.display = WC.launcherIcon ? '' : 'none';

  // رنگ: خالی بودن یعنی «از برند ارث ببر»؛ در input رنگ همان رنگ مؤثر را نشان می‌دهیم
  const own = /^#[0-9a-fA-F]{6}$/.test(s.color || '');
  document.getElementById('wcColor').value = own ? s.color : WC.accent;
  document.getElementById('wcColorNote').textContent = own
    ? 'رنگ اختصاصی ویجت. برای هماهنگی با پنل، دکمه‌ی بالا را بزن.'
    : 'الان از رنگ برند پنل ارث می‌برد؛ اگر رنگ را عوض کنی، اختصاصی می‌شود.';
  if(!own) WC.brandAccent = WC.accent;

  document.getElementById('wcEmbed').textContent = WC.embed;
  const t = document.getElementById('wcTestLink');
  t.href = (WC.base || '') + '/chat.php?wc=ping';

  wcRenderStats(d.stats || {}, d.online);
  wcLoadSessions();
}

function wcRenderStats(st, online){
  if(online === null || online === undefined) online = WC.online;
  const box = document.getElementById('wcStats');
  const card = (label, val, tone) =>
    `<div class="card card-pad col" style="min-width:130px;padding:14px 16px">
       <div class="muted" style="font-size:11.5px">${label}</div>
       <div style="font-size:20px;font-weight:700;margin-top:4px;color:${tone||'inherit'}">${faNum(val)}</div>
     </div>`;
  box.innerHTML =
    card('گفتگوی امروز', st.today||0) +
    card('۷ روز گذشته', st.week||0) +
    card('شناسایی‌شده (شماره‌دار)', st.identified||0) +
    card('کل پیام‌ها', st.messages||0) +
    card('همین حالا روی سایت', st.online_now||0, 'var(--accent)') +
    `<div class="card card-pad col" style="min-width:130px;padding:14px 16px">
       <div class="muted" style="font-size:11.5px">وضعیت تیم</div>
       <div style="font-size:14px;font-weight:700;margin-top:8px">
         <span class="tag ${online?'tag-ok':'tag-mute'}">${online?'آنلاین':'خارج از ساعت کاری'}</span>
       </div>
     </div>`;
}

function wcUseBrand(){
  if(WC.brandAccent) document.getElementById('wcColor').value = WC.brandAccent;
  document.getElementById('wcColor').dataset.inherit = '1';
  document.getElementById('wcColorNote').textContent =
    'با ذخیره، رنگ اختصاصی پاک می‌شود و از برند پنل ارث می‌برد.';
}

async function wcUploadLauncherIcon(){
  const input = document.getElementById('wcLauncherIconFile');
  const status = document.getElementById('wcLauncherIconStatus');
  if (!input.files || !input.files[0]) return;
  const fd = new FormData();
  fd.append('image', input.files[0]);
  status.textContent = 'در حال آپلود…';
  const d = await api('upload_image', fd, true);
  if (d.ok) {
    WC.launcherIcon = d.rel;
    status.textContent = 'آپلود شد — برای اعمال، «ذخیره» را بزن.';
    document.getElementById('wcLauncherIconClear').style.display = '';
  } else {
    status.textContent = d.error || 'آپلود ناموفق بود';
  }
  input.value = '';
}
function wcClearLauncherIcon(){
  WC.launcherIcon = '';
  document.getElementById('wcLauncherIconStatus').textContent = 'آیکون پیش‌فرض — برای اعمال، «ذخیره» را بزن.';
  document.getElementById('wcLauncherIconClear').style.display = 'none';
}

async function wcSave(){
  const inherit = document.getElementById('wcColor').dataset.inherit === '1';
  const msg = document.getElementById('wcSaveMsg');
  msg.textContent = 'در حال ذخیره…';

  const d = await api('webchat_save', {
    enabled:       document.getElementById('wcEnabled').checked ? 1 : 0,
    title:         document.getElementById('wcTitle').value,
    subtitle:      document.getElementById('wcSubtitle').value,
    welcome:       document.getElementById('wcWelcome').value,
    offline_text:  document.getElementById('wcOffline').value,
    start_mode:    document.getElementById('wcStartMode').value,
    position:      document.getElementById('wcPosition').value,
    mobile_bottom_offset: enNum(document.getElementById('wcMobileOffset').value.trim()),
    ask_name:      document.getElementById('wcAskName').checked ? 1 : 0,
    ask_phone:     document.getElementById('wcAskPhone').checked ? 1 : 0,
    color:         inherit ? '' : document.getElementById('wcColor').value,
    allowed_hosts: document.getElementById('wcHosts').value,
    poll_seconds:  document.getElementById('wcPoll').value,
    bg_color:      document.getElementById('wcBgColor').value,
    text_color:    document.getElementById('wcTextColor').value,
    font_family:   document.getElementById('wcFontFamily').value,
    border_radius: document.getElementById('wcBorderRadius').value,
    launcher_size: document.getElementById('wcLauncherSize').value,
    launcher_icon: WC.launcherIcon || '',
  });

  if(d.ok){
    msg.textContent = 'ذخیره شد.';
    document.getElementById('wcColor').dataset.inherit = '';
    setTimeout(()=>{ msg.textContent=''; }, 2500);
    wcLoad();
  } else {
    msg.textContent = d.error || 'ذخیره نشد';
  }
}

function wcCopyEmbed(){
  const t = document.getElementById('wcEmbed').textContent;
  if(navigator.clipboard) navigator.clipboard.writeText(t);
  const b = document.getElementById('wcSaveMsg');
  b.textContent = 'کد نصب کپی شد.';
  setTimeout(()=>{ b.textContent=''; }, 2000);
}

async function wcLoadSessions(){
  const tb = document.getElementById('wcRows');
  const d = await api('webchat_sessions', {
    q:               (document.getElementById('wcQ')?.value || '').trim(),
    days:            parseInt(document.getElementById('wcDays')?.value || '0', 10),
    only_identified: document.getElementById('wcOnlyId')?.checked ? 1 : 0,
    limit:           150
  });
  if(!d.ok){ tb.innerHTML = `<tr><td colspan=6 class="empty">خطا در بارگذاری</td></tr>`; return; }

  const rows = d.sessions || [];
  if(!rows.length){
    tb.innerHTML = `<tr><td colspan=6>${emptyState('💬','هنوز گفتگویی نیست',
      'کد نصب را در سایت بگذار و کلید فعال‌سازی را روشن کن')}</td></tr>`;
    return;
  }

  tb.innerHTML = rows.map(w=>{
    const who = w.full_name ? esc(w.full_name)
              : (w.phone ? esc(faNum(w.phone)) : '<span class="muted">ناشناس</span>');
    const phone = w.phone ? `<div class="mono muted" style="font-size:10.5px" dir="ltr">${esc(faNum(w.phone))}</div>` : '';
    const page  = w.page_url
      ? `<a href="${esc(w.page_url)}" target="_blank" rel="noopener" class="mono" dir="ltr"
            style="font-size:10.5px;max-width:190px;display:inline-block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
         >${esc(w.page_url)}</a>`
      : '<span class="muted">—</span>';
    const dev = `${esc(w.device||'—')}${w.browser?' · '+esc(w.browser):''}${w.os?' · '+esc(w.os):''}`;
    const prof = w.person_id
      ? `<a href="index.php?page=persons&person=${w.person_id}" class="btn btn-ghost btn-xs">پروفایل</a>`
      : '';
    return `<tr>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          ${w.identified==1?'<span class="tag tag-ok" style="font-size:10.5px">شناسایی‌شده</span>':''}
          <span>${who}</span>
        </div>
        ${phone}
        <div class="mono muted" style="font-size:10px" dir="ltr">${esc(w.ip||'')}</div>
      </td>
      <td>${page}</td>
      <td class="muted" style="font-size:11.5px;white-space:nowrap">${dev}</td>
      <td><span class="tag tag-accent">${faNum(w.msg_count||0)}</span></td>
      <td class="muted" style="font-size:11.5px;white-space:nowrap">${toFa(w.last_seen)}</td>
      <td style="white-space:nowrap">${prof}</td>
    </tr>`;
  }).join('');

  if(d.stats) wcRenderStats(d.stats, null);
}

wcLoad();
