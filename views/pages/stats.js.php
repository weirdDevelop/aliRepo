// pages/stats.js.php
const SCHN={bale:'بله',telegram:'تلگرام',roubika:'روبیکا',bale_safir:'صفیر',sms:'پیامک',email:'ایمیل',voice:'تماس گویا',webchat:'گفتگوی سایت'};
const SCHC={bale:'ch-bale',telegram:'ch-telegram',roubika:'ch-roubika',bale_safir:'ch-safir',sms:'ch-sms',email:'ch-email',voice:'ch-voice'};
async function loadStats(){
  const d=await api('stats_overview');
  if(!d.ok){document.getElementById('statsWrap').innerHTML='<div class="empty">خطا</div>';return;}
  const s=d.stats;
  const stat=(n,l,sub='')=>`<div class="stat"><div class="lab">${l}</div><div class="num">${faNum(n)}</div>${sub?`<div class="note">${sub}</div>`:''}</div>`;
  const maxDaily=Math.max(1,...(s.daily||[]).map(x=>Math.max(+x.sent,+x.received)));
  const bars=(s.daily||[]).map(x=>{
    const hs=Math.round((+x.sent/maxDaily)*120), hr=Math.round((+x.received/maxDaily)*120);
    return `<div style="flex:1;min-width:30px;display:flex;flex-direction:column;align-items:center;gap:6px">
      <div style="display:flex;gap:3px;align-items:flex-end;height:120px">
        <div style="width:12px;background:linear-gradient(180deg,var(--brand-2),var(--brand));height:${hs}px;min-height:3px;border-radius:5px 5px 2px 2px;box-shadow:0 4px 12px -4px var(--brand-glow)" title="ارسالی ${faNum(x.sent)}"></div>
        <div style="width:12px;background:var(--ink-faint);height:${hr}px;min-height:3px;border-radius:5px 5px 2px 2px;opacity:.55" title="دریافتی ${faNum(x.received)}"></div>
      </div><div style="font-size:10px;color:var(--ink-faint)">${esc(String(x.d).slice(5))}</div></div>`;
  }).join('');
  const maxCh=Math.max(1,...(s.by_channel||[]).map(c=>+c.c));
  const chRows=(s.by_channel||[]).map(c=>`<tr>
    <td><span class="chip"><span class="cdot ${SCHC[c.channel]||''}"></span>${SCHN[c.channel]||c.channel}</span></td>
    <td style="width:55%"><div style="display:flex;align-items:center;gap:8px">
      <div style="flex:1;height:7px;background:var(--line-soft);border-radius:6px;overflow:hidden"><div style="height:100%;width:${Math.round(c.c/maxCh*100)}%;background:linear-gradient(90deg,var(--brand),var(--brand-2));border-radius:6px"></div></div>
      <span style="font-weight:700;min-width:34px">${faNum(c.c)}</span></div></td>
  </tr>`).join('')||'<tr><td colspan=2 class=empty>—</td></tr>';
  document.getElementById('statsWrap').innerHTML=`
    <div class="grid g-3">${stat(s.persons,'👥 کل مشتریان',faNum(s.members)+' عضو · '+faNum(s.anonymous)+' ناشناس')}${stat(s.new_30d,'🆕 جدید (۳۰ روز)')}${stat(s.response_rate+'٪','💬 نرخ پاسخ‌دهی')}</div>
    <div class="grid g-3" style="margin-top:16px">${stat(s.sent,'✅ ارسال موفق')}${stat(s.delivery_rate+'٪','📬 نرخ تحویل')}${stat(s.received,'📥 پیام دریافتی')}</div>
    <div class="card card-pad" style="margin-top:16px">
      <div style="font-weight:700;margin-bottom:16px">فعالیت ۷ روز اخیر <span class="muted" style="font-weight:400;font-size:11px">(برند: ارسالی · خاکستری: دریافتی)</span></div>
      <div style="display:flex;gap:8px;align-items:flex-end;overflow-x:auto;padding-bottom:4px">${bars||'<div class="muted">داده‌ای نیست</div>'}</div>
    </div>
    <div class="card" style="margin-top:16px">
      <div class="card-h"><span class="t">هویت‌ها بر اساس کانال</span></div>
      <div class="card-pad" style="padding-top:8px"><table><tbody>${chRows}</tbody></table></div>
    </div>`;
}
loadStats();
