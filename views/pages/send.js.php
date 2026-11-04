// pages/send.js.php
let SND_TPLS = [];

async function initSend(){
  const d = await api('templates_list');
  SND_TPLS = d.templates||[];
  const sel = document.getElementById('sndTpl');
  sel.innerHTML = '<option value="">— بدون الگو، متن دستی —</option>' +
    SND_TPLS.map(t=>`<option value="${t.id}">${esc(t.name)}</option>`).join('');
  // پر کردن گروه و برچسب
  const g = await api('groups_list');
  if(document.getElementById('sndGroup'))
    document.getElementById('sndGroup').innerHTML='<option value="">همه گروه‌ها</option>'+(g.groups||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  const t = await api('tags_list');
  if(document.getElementById('sndTag'))
    document.getElementById('sndTag').innerHTML='<option value="">همه برچسب‌ها</option>'+(t.tags||[]).map(x=>`<option value="${x.id}">${esc(x.name)}</option>`).join('');
  refreshCount();
  loadTrackLinks('sndLinkPick');
}

function onTplPick(){
  const id = document.getElementById('sndTpl').value;
  const t = SND_TPLS.find(x=>x.id==id);
  if(t){
    document.getElementById('sndText').value = t.body||'';
    document.getElementById('sndButtons').value = t.buttons_json||'';
  }
}

function sendFilters(){
  return {
    status: document.getElementById('sndStatus').value,
    group_id: document.getElementById('sndGroup')?.value || '',
    tag_id: document.getElementById('sndTag')?.value || '',
  };
}

async function refreshCount(){
  const d = await api('send_audience_count', sendFilters());
  document.getElementById('audCount').textContent = d.ok ? d.count : '—';
}

let SND_IMG_PATH = null;

async function uploadSndImage(){
  const f = document.getElementById('sndImage').files[0];
  if(!f) return;
  const st = document.getElementById('sndImgStatus');
  st.textContent = 'در حال آپلود…';
  const fd = new FormData();
  fd.append('image', f);
  const d = await api('upload_image', fd, true);
  if(d.ok){ SND_IMG_PATH = d.path; st.textContent = '✅ عکس آماده شد'; }
  else { SND_IMG_PATH = null; st.textContent = '⚠️ '+(d.error||'خطا'); }
}

async function doSend(){
  const text = document.getElementById('sndText').value.trim();
  const tpl = document.getElementById('sndTpl').value;
  if(!text && !tpl && !SND_IMG_PATH){ toast('متن، الگو یا عکس لازم است', false); return; }
  if(!confirm('ارسال انبوه شروع شود؟ پیام‌ها در صف قرار می‌گیرند و حتی اگر این صفحه را ببندید، تکمیل می‌شوند.')) return;

  const btn = document.getElementById('sendBtn');
  const res = document.getElementById('sendResult');
  btn.disabled = true; btn.textContent = 'در حال افزودن به صف…';
  res.innerHTML = 'در حال آماده‌سازی فهرست…';

  // ۱) افزودن به صف پایدار (یک‌بار). ارسال دیگر در مرورگر همگام نیست.
  const q = await api('send_enqueue', {
    template_id: tpl || 0, text,
    buttons_json: document.getElementById('sndButtons').value.trim(),
    image_path: SND_IMG_PATH || '',
    status: document.getElementById('sndStatus').value,
    group_id: document.getElementById('sndGroup')?.value || '',
    tag_id: document.getElementById('sndTag')?.value || '',
    channel_priority: document.getElementById('sndPriority').value.trim(),
  });
  if(!q.ok){ btn.disabled=false; btn.textContent='ارسال انبوه'; res.innerHTML=`<span class="tag tag-warn">خطا</span> ${esc(q.error||'')}`; toast(q.error||'خطا',false); return; }

  const total = q.total;
  let sent = (q.first_batch&&q.first_batch.sent)||0;
  let failed = (q.first_batch&&q.first_batch.failed)||0;
  let done = sent + failed;

  res.innerHTML = `
    <div style="margin-bottom:8px;font-size:13px">در صف: ارسال به <strong>${total}</strong> مخاطب…
      <span class="muted" style="font-size:11px">(می‌توانید این صفحه را ببندید؛ ارسال در پس‌زمینه ادامه می‌یابد)</span></div>
    <div style="height:8px;background:var(--line-soft);border-radius:10px;overflow:hidden">
      <div id="sndBar" style="height:100%;background:var(--accent);width:0;transition:width .3s"></div>
    </div>
    <div id="sndProg" class="muted" style="font-size:12px;margin-top:6px">۰ از ${total}</div>`;

  btn.textContent='در حال ارسال…';

  function updateBar(){
    const pct = total>0 ? Math.round(done/total*100) : 100;
    const bar=document.getElementById('sndBar'); if(bar) bar.style.width=pct+'%';
    const pr=document.getElementById('sndProg'); if(pr) pr.textContent=`${done} از ${total} · موفق ${sent} · ناموفق ${failed}`;
  }
  updateBar();

  // ۲) «هل» مودبانه‌ی مرورگر: هر بار یک batch تا صفِ این کار خالی شود.
  //    اگر کاربر صفحه را ببندد، cron بقیه را تمام می‌کند (مقاومت).
  let idleRounds = 0;
  while(true){
    const p = await api('queue_push', {batch:40});
    if(!p.ok) break;
    // مورد ۶/۸: اگر worker دیگری (cron) مشغول همین صف است، هل نزن — عقب بکش.
    // بدون این، مرورگر و cron همزمان می‌تاختند و سرور را هم کند می‌کردند.
    if(p.busy){
      await new Promise(r=>setTimeout(r, 1500));
      if(++idleRounds > 80) break;   // سقف ایمنی تا حلقه ابدی نشود
      continue;
    }
    idleRounds = 0;
    // پیشرفت را از وضعیت همین job بخوان (دقیق‌تر از جمع‌زدن batchها)
    const st = await api('queue_jobs', {limit:50});
    const job = st.ok ? (st.jobs||[]).find(j=>j.id==q.job_id) : null;
    if(job){ sent=+job.sent; failed=+job.failed; done=+job.processed; updateBar(); }
    if(!job || job.status==='done' || job.status==='cancelled'){ done=total; updateBar(); break; }
    if(p.remaining===0){ break; }
    await new Promise(r=>setTimeout(r, 400)); // مودبانه: کمی مکث بین batchها
  }

  btn.disabled=false; btn.textContent='ارسال انبوه';
  res.innerHTML = `<span class="tag tag-ok">پایان</span> کل: ${total} · موفق: ${sent} · ناموفق: ${failed} · <a href="index.php?page=queue" style="color:var(--accent-ink)">صف</a> · <a href="index.php?page=sendlog" style="color:var(--accent-ink)">تاریخچه</a>`;
  toast('ارسال در صف تکمیل شد');
}

initSend();

// پل از ماژول مارکتینگ: اگر ?from_content=ID بود، متن/دکمه/کانال را پر کن
(async function prefillFromContent(){
  try{
    const p=new URLSearchParams(window.location.search);
    const cid=p.get('from_content');
    if(!cid) return;
    const d=await api('mkt_sendable',{id:cid});
    if(!d || !d.ok) return;
    const ta=document.getElementById('sndText'); if(ta && d.text){ ta.value=d.text; }
    const btn=document.getElementById('sndButtons'); if(btn && d.buttons){ btn.value=d.buttons; }
    const pr=document.getElementById('sndPriority'); if(pr && d.priority){ pr.value=d.priority; }
    if(typeof refreshCount==='function') refreshCount();
    const res=document.getElementById('sendResult');
    if(res){ res.innerHTML=`<span class="tag tag-accent">از مارکتینگ</span> محتوای «${esc(d.title||'')}» بارگذاری شد${d.sendable?'':' — کانال این محتوا با ارسال مستقیم هم‌خوان نیست'}. متن را بازبینی کنید؛ دکمه‌ی ارسال با شماست.`; }
    if(typeof toast==='function') toast('متن از مارکتینگ بارگذاری شد');
  }catch(e){}
})();
