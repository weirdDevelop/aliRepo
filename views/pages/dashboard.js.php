// pages/dashboard.js.php
const DCHN={bale:'بله',telegram:'تلگرام',roubika:'روبیکا',bale_safir:'صفیر',sms:'پیامک',webchat:'گفتگوی سایت'};

async function loadSetup(){
  const d=await api('setup_status');
  if(!d.ok) return;
  // اگر همه کامل، کارت را نشان نده
  if(d.complete){ document.getElementById('setupCard').style.display='none'; return; }
  document.getElementById('setupCard').style.display='block';
  document.getElementById('setupProgress').textContent=`${d.done} از ${d.total} قدم`;
  document.getElementById('setupBar').style.width=Math.round(d.done/d.total*100)+'%';
  document.getElementById('setupSteps').innerHTML=d.steps.map(s=>`
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--line-soft)">
      <span style="width:22px;height:22px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;font-size:12px;${s.done?'background:var(--ok-soft);color:var(--ok)':'background:var(--line-soft);color:var(--ink-faint)'}">${s.done?'✓':'○'}</span>
      <div style="flex:1">
        <div style="font-size:13px;${s.done?'color:var(--ink-faint);text-decoration:line-through':'font-weight:500'}">${esc(s.title)}</div>
        ${!s.done?`<div class="muted" style="font-size:11.5px">${esc(s.desc)}</div>`:''}
      </div>
      ${!s.done?`<a href="index.php?page=${s.page}" class="btn btn-ghost btn-sm">برو</a>`:''}
    </div>`).join('');
}

async function loadActivity(){
  const d=await api('recent_activity');
  const box=document.getElementById('recentActivity');
  if(!d.ok||!d.activity.length){ box.innerHTML='<div class="empty">هنوز فعالیتی نیست</div>'; return; }
  box.innerHTML='<div style="padding:8px 0">'+d.activity.map(a=>{
    const inbound=a.direction==='in';
    const icon=inbound?'↓':'↑';
    const color=inbound?'var(--ok)':(a.status==='failed'?'var(--err)':'var(--accent-ink)');
    const name=a.full_name||'ناشناس';
    const txt=(a.body||'').slice(0,50);
    return `<div style="display:flex;align-items:center;gap:10px;padding:9px 20px;border-bottom:1px solid var(--line-soft);font-size:13px">
      <span style="color:${color};font-weight:700">${icon}</span>
      <span style="flex-shrink:0">${esc(name)}</span>
      <span class="tag tag-mute" style="font-size:10px">${DCHN[a.channel]||a.channel}</span>
      <span class="muted" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(txt)}</span>
      <span class="muted" style="font-size:11px;flex-shrink:0">${toFa(a.created_at)}</span>
    </div>`;
  }).join('')+'</div>';
}

loadSetup();
loadActivity();
