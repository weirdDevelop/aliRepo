// pages/queue.js.php
const Q_STATUS = {queued:'در صف', running:'در حال ارسال', done:'تمام‌شده', cancelled:'لغو شده'};
const Q_BADGE = {queued:'tag-mute', running:'tag-accent', done:'tag-ok', cancelled:'tag-warn'};
let Q_PUSHING = false;   // آیا الان در حال هل‌دادن صفیم؟
let Q_TIMER = null;

async function loadQueue(){
  const d = await api('queue_jobs', {limit:50});
  const tb = document.getElementById('qRows');
  if(!d.ok){ tb.innerHTML='<tr><td colspan=6 class=empty>خطا</td></tr>'; return; }

  // خلاصه
  const sum = d.summary || {active_jobs:0, pending:0};
  const sumBox = document.getElementById('qSummary');
  if(sum.active_jobs>0){
    sumBox.style.display='block';
    document.getElementById('qActiveJobs').textContent = faN(sum.active_jobs)+' کار فعال';
    document.getElementById('qPending').textContent = faN(sum.pending)+' در انتظار';
  } else {
    sumBox.style.display='none';
  }

  if(!d.jobs.length){ tb.innerHTML=`<tr><td colspan=6>${emptyState('📭','صف خالی است','کارهای ارسال انبوه اینجا نمایش داده می‌شوند')}</td></tr>`; }
  else {
    tb.innerHTML = d.jobs.map(j=>{
      const total=+j.total, done=+j.processed, sent=+j.sent, failed=+j.failed;
      const pct = total>0 ? Math.round(done/total*100) : (j.status==='done'?100:0);
      const active = (j.status==='queued'||j.status==='running');
      const bar = `<div style="height:6px;background:var(--line-soft);border-radius:8px;overflow:hidden;min-width:90px">
          <div style="height:100%;background:var(--accent);width:${pct}%;transition:width .3s"></div></div>
          <span class="muted" style="font-size:11px">${faN(done)}/${faN(total)} (${faN(pct)}٪)</span>`;
      const cancelBtn = active
        ? `<button class="btn btn-ghost btn-sm" style="color:var(--err)" onclick="cancelJob(${j.id})">لغو</button>`
        : '';
      return `<tr>
        <td>${esc((j.title||'بدون عنوان').slice(0,40))}</td>
        <td><span class="tag ${Q_BADGE[j.status]||'tag-mute'}">${Q_STATUS[j.status]||j.status}</span></td>
        <td>${bar}</td>
        <td><span class="muted" style="font-size:12px">✓${faN(sent)} ✗${faN(failed)}</span></td>
        <td class="muted" style="font-size:11px;white-space:nowrap">${toFa(j.created_at)}</td>
        <td style="text-align:left">${cancelBtn}</td>
      </tr>`;
    }).join('');
  }

  // اگر کار فعالی هست، هم «هل» بده هم به‌زودی دوباره رفرش کن
  if(sum.active_jobs>0 && sum.pending>0){
    pushQueue();          // هل مودبانه
    scheduleRefresh(1500);
  } else if(sum.active_jobs>0){
    scheduleRefresh(1500); // کار فعال ولی pending صفر (در حال نهایی‌شدن)
  } else {
    document.getElementById('qPushState').textContent='';
  }
}

function scheduleRefresh(ms){
  if(Q_TIMER) clearTimeout(Q_TIMER);
  Q_TIMER = setTimeout(loadQueue, ms);
}

// «هل» مودبانه: یک batch، با قفل تا همزمان چند هل نرود
async function pushQueue(){
  if(Q_PUSHING) return;
  Q_PUSHING = true;
  document.getElementById('qPushState').textContent='در حال پردازش صف…';
  try{
    const p = await api('queue_push', {batch:40});
    if(p.ok && p.busy){ document.getElementById('qPushState').textContent='cron مشغول پردازش است…'; }
    else if(p.ok && p.remaining>0){ document.getElementById('qPushState').textContent=`${faN(p.remaining)} باقی‌مانده`; }
    else document.getElementById('qPushState').textContent='';
  }catch(e){}
  Q_PUSHING = false;
}

async function cancelJob(id){
  if(!confirm('این کار ارسال لغو شود؟ پیام‌های فرستاده‌شده برمی‌نمی‌گردند، ولی بقیه ارسال نمی‌شوند.')) return;
  const d = await api('queue_cancel', {job_id:id});
  if(d.ok){ toast('کار لغو شد'); loadQueue(); }
  else toast(d.error||'قابل لغو نیست', false);
}

// تبدیل عدد به فارسی (اگر faNum سراسری بود از آن استفاده کن)
function faN(n){ return (typeof faNum==='function') ? faNum(n) : String(n); }

loadQueue();
