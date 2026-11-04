// pages/support.js.php
let CN_ITEMS = [];
let TC_USERS = [];
let TC_OTHER = 0;   // 0 = کانال «همه»
let TC_ME = 0;
let TC_TIMER = null;

// ===== پیام‌های آماده (۶-۱) =====
async function loadCanned(){
  const d = await api('canned_list');
  if(!d.ok) return;
  const up = document.getElementById('cnNeedUpgrade');
  if(up) up.style.display = (d.ready === 1) ? 'none' : '';
  CN_ITEMS = d.items || [];
  document.getElementById('cnCatList').innerHTML = (d.categories||[]).map(c=>`<option value="${esc(c)}">`).join('');
  renderCanned();
}

function renderCanned(){
  const box = document.getElementById('cnList');
  if(!CN_ITEMS.length){ box.innerHTML = '<div class="muted" style="padding:8px 0">هنوز پیام آماده‌ای نساخته‌ای</div>'; return; }
  // گروه‌بندی بر اساس دسته
  const groups = {};
  CN_ITEMS.forEach(it=>{
    const cat = it.category || 'بدون دسته';
    (groups[cat] = groups[cat] || []).push(it);
  });
  box.innerHTML = Object.keys(groups).map(cat=>`
    <div style="margin-bottom:10px">
      <div class="muted" style="font-size:11.5px;font-weight:600;margin-bottom:4px">${esc(cat)}</div>
      ${groups[cat].map(it=>`
        <div class="card" style="padding:10px;margin-bottom:6px;border:1px solid var(--line)">
          <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
            <div style="font-weight:600;font-size:13px">${esc(it.title)}</div>
            <div style="display:flex;gap:4px;flex-shrink:0">
              <button class="btn btn-ghost btn-sm" onclick="editCanned(${it.id})" title="ویرایش">✎</button>
              <button class="btn btn-danger-ghost btn-sm" onclick="deleteCanned(${it.id})" title="حذف">✕</button>
            </div>
          </div>
          <div class="note" style="margin-top:4px;white-space:pre-wrap">${esc(it.body)}</div>
        </div>
      `).join('')}
    </div>
  `).join('');
}

async function saveCanned(){
  const id = document.getElementById('cnId').value;
  const d = await api('canned_save', {
    id: id || undefined,
    title: document.getElementById('cnTitle').value.trim(),
    category: document.getElementById('cnCategory').value.trim(),
    body: document.getElementById('cnBody').value,
  });
  if(!d.ok){ toast(d.error||'ذخیره نشد', false); return; }
  toast(id ? 'ویرایش شد' : 'اضافه شد');
  resetCanned();
  loadCanned();
}

function editCanned(id){
  const it = CN_ITEMS.find(x=>x.id==id);
  if(!it) return;
  document.getElementById('cnId').value = it.id;
  document.getElementById('cnTitle').value = it.title;
  document.getElementById('cnCategory').value = it.category || '';
  document.getElementById('cnBody').value = it.body;
  document.getElementById('cnCancelBtn').style.display = '';
  document.querySelector('#cnTitle').closest('.card').scrollIntoView({behavior:'smooth', block:'start'});
}

function resetCanned(){
  document.getElementById('cnId').value = '';
  document.getElementById('cnTitle').value = '';
  document.getElementById('cnCategory').value = '';
  document.getElementById('cnBody').value = '';
  document.getElementById('cnCancelBtn').style.display = 'none';
}

async function deleteCanned(id){
  if(!confirm('این پیام آماده حذف شود؟')) return;
  const d = await api('canned_delete', {id});
  if(d.ok){ toast('حذف شد'); loadCanned(); } else toast(d.error||'خطا', false);
}

// ===== چت داخلی تیم (۶-۲) =====
async function loadTcUsers(){
  const d = await api('teamchat_users');
  if(!d.ok) return;
  const up = document.getElementById('tcNeedUpgrade');
  if(up) up.style.display = (d.ready === 1) ? 'none' : '';
  TC_USERS = d.users || [];
  TC_ME = d.me || 0;
  renderTcUsers();
}

function renderTcUsers(){
  const box = document.getElementById('tcUsers');
  const items = [{id:0, username:'📢 همه‌ی تیم', unread:0}, ...TC_USERS];
  box.innerHTML = items.map(u=>{
    const on = (TC_OTHER === u.id) ? ' style="background:var(--accent-soft);color:var(--accent-ink)"' : '';
    const badge = u.unread ? `<span class="nav-badge">${faNum(u.unread)}</span>` : '';
    return `<button type="button" class="btn btn-ghost btn-sm" onclick="tcOpen(${u.id})"${on}
      style="justify-content:space-between;display:flex;align-items:center">
      <span>${esc(u.name || u.username)}</span>${badge}
    </button>`;
  }).join('');
}

async function tcOpen(otherId){
  TC_OTHER = otherId;
  renderTcUsers();
  await tcRefresh();
}

async function tcRefresh(){
  const d = await api('teamchat_thread', {other_id: TC_OTHER});
  if(!d.ok) return;
  TC_ME = d.me || TC_ME;
  const box = document.getElementById('tcThread');
  const msgs = d.messages || [];
  if(!msgs.length){ box.innerHTML = '<div class="muted" style="padding:8px">هنوز پیامی نیست</div>'; return; }
  const wasAtBottom = (box.scrollTop + box.clientHeight) >= (box.scrollHeight - 20);
  box.innerHTML = msgs.map(m=>{
    const mine = (Number(m.sender_id) === Number(TC_ME));
    return `<div style="margin-bottom:8px;display:flex;flex-direction:column;align-items:${mine?'flex-end':'flex-start'}">
      ${TC_OTHER===0 && !mine ? `<div class="muted" style="font-size:11px;margin-bottom:2px">${esc(m.sender_name)}</div>` : ''}
      <div style="max-width:80%;padding:8px 10px;border-radius:10px;background:${mine?'var(--accent-soft)':'var(--surface)'};border:1px solid var(--line);font-size:13px;white-space:pre-wrap">${esc(m.body)}</div>
      <div class="muted" style="font-size:10px;margin-top:2px">${toFa(m.created_at)}</div>
    </div>`;
  }).join('');
  if(wasAtBottom) box.scrollTop = box.scrollHeight;
  // آپدیت نشان خوانده‌نشده‌ی کناری
  loadTcUsers();
}

async function tcSend(){
  const input = document.getElementById('tcInput');
  const body = input.value.trim();
  if(!body) return;
  input.value = '';
  const d = await api('teamchat_send', {other_id: TC_OTHER, body});
  if(!d.ok){ toast(d.error||'ارسال نشد', false); input.value = body; return; }
  await tcRefresh();
}

async function spStats(days){
  document.querySelectorAll('#spStatSeg button').forEach(b=>b.classList.toggle('on', +b.dataset.d === days));
  const d = await api('inbox_agent_stats', {days});
  const tb = document.getElementById('spStatRows');
  if(!tb) return;
  if(!d.ok){ tb.innerHTML = '<tr><td colspan="5" class="muted">خطا</td></tr>'; return; }
  const max = Math.max(1, ...(d.rows||[]).map(r=>r.chats));
  tb.innerHTML = (d.rows||[]).map(r=>`<tr><td><b>${esc(r.username)}</b></td><td>${faNum(r.replies)}</td>
    <td>${faNum(r.chats)}<span class="sp-bar" style="width:${Math.round(r.chats/max*80)}px"></span></td><td>${faNum(r.started)}</td><td>${faNum(r.open)}</td></tr>`).join('')
    || '<tr><td colspan="5" class="muted">داده‌ای نیست</td></tr>';
}

function initSupportPage(){
  spStats(1);
  loadCanned();
  if(!document.getElementById('tcUsers')) return;   // ۲.۰.۰: چت داخلی در CRM
  loadTcUsers();
  tcOpen(0); // پیش‌فرض: کانال «همه»
  if(TC_TIMER) clearInterval(TC_TIMER);
  TC_TIMER = setInterval(tcRefresh, 5000);
}
initSupportPage();
