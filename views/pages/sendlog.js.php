// pages/sendlog.js.php
const KIND={bulk:'انبوه (چت)',phone:'با شماره',campaign:'کمپین',scheduled:'زمان‌بندی',single:'تکی'};
const LCHN={bale:'بله',telegram:'تلگرام',roubika:'روبیکا',bale_safir:'صفیر',sms:'پیامک',webchat:'گفتگوی سایت'};

async function loadLog(){
  const f = {
    q: document.getElementById('fq')?.value.trim() || '',
    kind: document.getElementById('fkind')?.value || '',
    channel: document.getElementById('fchannel')?.value || '',
    result: document.getElementById('fresult')?.value || '',
    date_from: getJDate('fdfrom'),
    date_to: getJDate('fdto'),
  };
  const d=await api('sends_list', f);
  // خلاصه‌ی نتیجه‌ی فهرست فعلی
  const sumEl=document.getElementById('logSummary');
  if(sumEl && d.summary){
    const s=d.summary;
    sumEl.innerHTML = s.count
      ? `<span class="tag tag-mute">${s.count} عملیات</span> <span class="tag tag-ok">${s.sent} رسید</span> <span class="tag tag-warn">${s.failed} نرسید</span> <span class="tag tag-mute">${s.total} کل گیرنده</span>`
      : '';
  }
  // خلاصه‌ی تحویل پیامک (DLR)
  if(d.dlr){
    const dl=d.dlr; const total=(dl.delivered||0)+(dl.failed||0)+(dl.pending||0);
    if(total>0){
      let box=document.getElementById('dlrSummary');
      if(!box){ box=document.createElement('div'); box.id='dlrSummary'; box.className='card card-pad'; box.style.marginBottom='14px';
        const anchor=document.querySelectorAll('.card')[1]||document.querySelector('.card');
        anchor.parentNode.insertBefore(box, anchor); }
      box.innerHTML=`<div style="font-weight:600;margin-bottom:8px">تحویل پیامک (DLR)</div>
        <div style="display:flex;gap:10px">
          <span class="tag tag-ok">رسید: ${dl.delivered||0}</span>
          <span class="tag tag-warn">نرسید: ${dl.failed||0}</span>
          <span class="tag tag-mute">در انتظار: ${dl.pending||0}</span>
        </div>`;
    }
  }
  const tb=document.getElementById('logRows');
  if(!d.ok||!d.sends.length){ tb.innerHTML='<tr><td colspan=5 class=empty>ارسالی با این فیلترها یافت نشد</td></tr>'; return; }
  tb.innerHTML=d.sends.map(s=>{
    const rate = s.total>0 ? Math.round(s.sent/s.total*100) : 0;
    const badge = s.failed==0 ? 'tag-ok' : (s.sent==0?'tag-warn':'tag-accent');
    return `<tr style="cursor:pointer" onclick="openSd(${s.id})">
      <td class="muted" style="white-space:nowrap">${toFa(s.created_at)}</td>
      <td><span class="tag tag-mute">${KIND[s.kind]||s.kind}</span></td>
      <td>${esc((s.title||'').slice(0,40))}</td>
      <td><span class="tag ${badge}">${s.sent}/${s.total} رسید</span> ${s.failed>0?`<span class="muted">${s.failed} ناموفق</span>`:''}</td>
      <td style="text-align:left"><button class="btn btn-ghost btn-sm">جزئیات</button></td>
    </tr>`;
  }).join('');
}
function resetLogFilters(){
  ['fkind','fchannel','fresult'].forEach(id=>{const e=document.getElementById(id); if(e) e.selectedIndex=0;});
  ['fq'].forEach(id=>{const e=document.getElementById(id); if(e) e.value='';});
  ['fdfrom','fdto'].forEach(id=>{ if(typeof setJDate==='function') setJDate(id,''); });
  loadLog();
}

async function openSd(id){
  document.getElementById('sdBackdrop').classList.add('show');
  document.getElementById('sdDrawer').classList.add('open');
  document.getElementById('sdBody').innerHTML='<div class="empty">در حال بارگذاری…</div>';
  const d=await api('send_detail',{id});
  if(!d.ok){ document.getElementById('sdBody').innerHTML='<div class="empty">خطا</div>'; return; }
  const s=d.send;
  let meta={};try{meta=JSON.parse(s.meta_json||'{}');}catch(e){}
  const targets=(s.targets||[]);
  const okList=targets.filter(t=>t.status==='sent');
  const failList=targets.filter(t=>t.status!=='sent');

  const rowHtml=t=>`<div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--line-soft);font-size:12.5px">
    <span>${esc(t.full_name||t.recipient)} ${t.channel&&t.channel!=='-'?`<span class="tag tag-mute" style="font-size:9px">${LCHN[t.channel]||t.channel}</span>`:''}</span>
    ${t.status==='sent'?'<span class="tag tag-ok">رسید</span>':`<span class="tag tag-warn" title="${esc(t.error||'')}">${esc((t.error||'نرسید').slice(0,30))}</span>`}
  </div>`;

  document.getElementById('sdBody').innerHTML=`
    <div style="margin-bottom:16px">
      <div class="muted" style="font-size:12px">${toFa(s.created_at)} · ${KIND[s.kind]||s.kind}</div>
      <div style="background:var(--bg);border-radius:8px;padding:12px;margin-top:8px;font-size:13px">${esc(s.body||'(بدون متن)')}</div>
    </div>
    <div class="pf-stat" style="display:flex;gap:12px;margin-bottom:16px">
      <div style="flex:1;background:var(--ok-soft);border-radius:8px;padding:10px;text-align:center"><div style="font-size:18px;font-weight:700;color:var(--ok)">${s.sent}</div><div style="font-size:11px">رسید</div></div>
      <div style="flex:1;background:var(--err-soft);border-radius:8px;padding:10px;text-align:center"><div style="font-size:18px;font-weight:700;color:var(--err)">${s.failed}</div><div style="font-size:11px">نرسید</div></div>
      <div style="flex:1;background:var(--bg);border-radius:8px;padding:10px;text-align:center"><div style="font-size:18px;font-weight:700">${s.total}</div><div style="font-size:11px">کل</div></div>
    </div>
    ${failList.length?`<div style="font-weight:600;margin-bottom:6px;color:var(--err)">نرسید (${failList.length})</div>${failList.map(rowHtml).join('')}`:''}
    ${okList.length?`<div style="font-weight:600;margin:16px 0 6px">رسید (${okList.length})</div>${okList.map(rowHtml).join('')}`:''}
  `;
}
function closeSd(){
  document.getElementById('sdBackdrop').classList.remove('show');
  document.getElementById('sdDrawer').classList.remove('open');
}
loadLog();
