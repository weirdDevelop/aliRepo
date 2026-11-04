// pages/apilog.js.php
let AL_DATA=[];
async function loadAl(){
  const ch=document.getElementById('alChannel').value;
  const d=await api('apilog_list', ch?{channel:ch}:{});
  AL_DATA=d.logs||[];
  const tb=document.getElementById('alRows');
  if(!AL_DATA.length){ tb.innerHTML=`<tr><td colspan=5>${emptyState('📋','لاگی نیست','وقتی پیامی از طریق API بفرستی، اینجا ثبت می‌شود')}</td></tr>`; return; }
  const chName={bale_safir:'صفیر',voice:'تلفن گویا',sms:'پیامک',email:'ایمیل',roubika:'روبیکا',webchat:'گفتگوی سایت'};
  tb.innerHTML=AL_DATA.map((l,i)=>`<tr>
    <td style="white-space:nowrap;font-size:12px">${toFa(l.created_at)}</td>
    <td><span class="tag tag-mute">${chName[l.channel]||l.channel||'-'}</span></td>
    <td style="font-size:12px">${esc(l.action||'')}</td>
    <td>${l.ok==1?'<span class="tag tag-ok">موفق</span>':(l.ok==0?'<span class="tag tag-warn">ناموفق</span>':'<span class="tag tag-mute">-</span>')}${l.error?`<div class="muted" style="font-size:11px">${esc((l.error||'').slice(0,40))}</div>`:''}</td>
    <td style="text-align:left"><button class="btn btn-ghost btn-sm" onclick="showAl(${i})">جزئیات</button></td>
  </tr>`).join('');
}
function showAl(i){
  const l=AL_DATA[i]; if(!l)return;
  document.getElementById('alBackdrop').classList.add('show');
  document.getElementById('alDrawer').classList.add('open');
  document.getElementById('alBody').innerHTML=`
    <div style="margin-bottom:8px"><b>کانال:</b> ${esc(l.channel||'-')} · <b>عملیات:</b> ${esc(l.action||'-')}</div>
    <div style="margin-bottom:8px"><b>زمان:</b> ${toFa(l.created_at)} · <b>کد HTTP:</b> ${l.http_code||'-'} · <b>نتیجه:</b> ${l.ok==1?'موفق':'ناموفق'}</div>
    ${l.error?`<div style="margin-bottom:8px;color:var(--err)"><b>خطا:</b> ${esc(l.error)}</div>`:''}
    <div style="font-weight:600;margin:10px 0 4px">درخواست ارسالی:</div>
    <div class="log-block">${esc(l.request_data||'-')}</div>
    <div style="font-weight:600;margin:10px 0 4px">پاسخ دریافتی:</div>
    <div class="log-block">${esc(l.response_data||'-')}</div>`;
}
function closeAl(){document.getElementById('alBackdrop').classList.remove('show');document.getElementById('alDrawer').classList.remove('open');}
async function clearAl(){ if(!confirm('همه‌ی لاگ‌ها پاک شوند؟'))return; await api('apilog_clear'); loadAl(); }
loadAl();
