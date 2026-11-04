<?php require __DIR__ . '/../carousel-editor.php'; ?>
// pages/botbuilder.js.php
let BN_BTNS=[];
let BN_RULES=[];
let BOT_NODES=[];

async function importBnScenario(){
  const box = document.getElementById('bnImportResult');
  let nodes;
  try {
    nodes = JSON.parse(document.getElementById('bnImportJson').value);
  } catch(e) {
    box.innerHTML = `<span class="tag tag-warn">JSON نامعتبر</span> ${esc(e.message)}`;
    return;
  }
  if (!Array.isArray(nodes) || !nodes.length) {
    box.innerHTML = '<span class="tag tag-warn">باید یک آرایه‌ی غیرخالی باشد</span>';
    return;
  }
  box.innerHTML = '<span class="muted">در حال وارد کردن…</span>';
  const d = await api('botnodes_import', {nodes});
  if (!d.ok) { box.innerHTML = `<span class="tag tag-warn">خطا</span> ${esc(d.error||'')}`; return; }
  box.innerHTML = `<span class="tag tag-ok">انجام شد</span> ${d.created} گره جدید، ${d.updated} گره آپدیت شد` +
    (d.errors && d.errors.length ? `<div class="tag tag-warn" style="margin-top:6px">${d.errors.map(esc).join('<br>')}</div>` : '');
  toast('سناریو وارد شد');
  loadBn();
}

async function loadBn(){
  const d=await api('botnodes_list');
  BOT_NODES = d.ok ? (d.nodes||[]) : [];
  const tb=document.getElementById('bnRows');
  if(!d.ok||!d.nodes.length){ tb.innerHTML=`<tr><td colspan=4>${emptyState('🤖','هنوز منویی نیست','یک گره با کلید main بساز و آن را گرهِ شروع کن')}</td></tr>`; renderFlow(); return; }
  tb.innerHTML=d.nodes.map(n=>{
    let bc=0; try{bc=(JSON.parse(n.buttons_json||'[]')).length;}catch(e){}
    let rc=0; try{rc=(JSON.parse(n.rules_json||'[]')).length;}catch(e){}
    return `<tr>
      <td><span class="mono">${esc(n.node_key)}</span> ${n.is_root==1?'<span class="tag tag-ok">شروع</span>':''}</td>
      <td>${esc(n.title||'—')}</td>
      <td>${bc} دکمه${rc?` · ${rc} قاعده`:''}</td>
      <td style="text-align:left;white-space:nowrap">
        <button class="btn btn-ghost btn-sm" onclick='editBn(${JSON.stringify(n)})'>ویرایش</button>
        <button class="btn btn-danger-ghost btn-sm" onclick="delBn(${n.id})">حذف</button></td>
    </tr>`;
  }).join('');
  renderFlow();
}

function renderBtns(){
  document.getElementById('bnButtons').innerHTML=BN_BTNS.map((b,i)=>`
    <div style="display:flex;gap:5px;margin-bottom:5px;align-items:center">
      <input value="${esc(b.text||'')}" oninput="BN_BTNS[${i}].text=this.value" placeholder="متن دکمه" style="flex:1">
      <select onchange="BN_BTNS[${i}].kind=this.value;renderBtns()" style="width:100px">
        <option value="goto" ${b.kind==='goto'?'selected':''}>برو به</option>
        <option value="url" ${b.kind==='url'?'selected':''}>لینک</option>
        <option value="contact" ${b.kind==='contact'?'selected':''}>اشتراک شماره</option>
      </select>
      ${b.kind==='url'
        ? `<input value="${esc(b.url||'')}" oninput="BN_BTNS[${i}].url=this.value" placeholder="https://" class="mono" style="flex:1" dir="ltr">`
        : b.kind==='contact'
        ? `<span class="muted" style="flex:1;font-size:11px">شماره کاربر گرفته می‌شود</span>`
        : `<input value="${esc(b.goto||'')}" oninput="BN_BTNS[${i}].goto=this.value" placeholder="کلید گره" class="mono" style="flex:1">`}
      <button class="btn btn-danger-ghost btn-xs" onclick="BN_BTNS.splice(${i},1);renderBtns()">×</button>
    </div>`).join('') || '<span class="muted" style="font-size:12px">دکمه‌ای نیست</span>';
}
function addBtn(){ BN_BTNS.push({text:'',kind:'goto',goto:'',url:''}); renderBtns(); }

// ===== قواعد شرطی =====
function renderRules(){
  document.getElementById('bnRules').innerHTML=BN_RULES.map((r,i)=>`
    <div style="display:flex;gap:5px;margin-bottom:5px;align-items:center">
      <span class="muted" style="font-size:11px">اگر شامل</span>
      <input value="${esc(r.contains||'')}" oninput="BN_RULES[${i}].contains=this.value" placeholder="کلمه" style="flex:1">
      <span class="muted" style="font-size:11px">→ برو به</span>
      <input value="${esc(r.goto||'')}" oninput="BN_RULES[${i}].goto=this.value" placeholder="کلید گره" class="mono" style="flex:1">
      <button class="btn btn-danger-ghost btn-xs" onclick="BN_RULES.splice(${i},1);renderRules()">×</button>
    </div>`).join('') || '<span class="muted" style="font-size:12px">قاعده‌ای نیست</span>';
}
function addRule(){ BN_RULES.push({contains:'',goto:''}); renderRules(); }

function resetBn(){
  document.getElementById('bnId').value='';
  document.getElementById('bnKey').value=''; document.getElementById('bnKey').disabled=false;
  document.getElementById('bnTitle').value=''; document.getElementById('bnText').value='';
  document.getElementById('bnRoot').checked=false;
  document.getElementById('bnAwaitsField').value='';
  document.getElementById('bnFormTitle').textContent='گره جدید';
  BN_BTNS=[]; renderBtns(); BN_RULES=[]; renderRules(); CarEd.mount('bnCarousel', []); if(document.getElementById('bnSite')) document.getElementById('bnSite').value='all';
}
function editBn(n){
  document.getElementById('bnId').value=n.id;
  document.getElementById('bnKey').value=n.node_key; document.getElementById('bnKey').disabled=false;
  document.getElementById('bnTitle').value=n.title||'';
  document.getElementById('bnText').value=n.text||'';
  document.getElementById('bnRoot').checked=(n.is_root==1);
  document.getElementById('bnAwaitsField').value=n.awaits_property_field||'';
  document.getElementById('bnFormTitle').textContent='ویرایش گره';
  try{ BN_BTNS=(JSON.parse(n.buttons_json||'[]')).map(b=>({text:b.text||'',kind:b.contact?'contact':(b.url?'url':'goto'),goto:b.goto||'',url:b.url||''})); }catch(e){ BN_BTNS=[]; }
  try{ BN_RULES=(JSON.parse(n.rules_json||'[]')).map(r=>({contains:r.contains||'',goto:r.goto||''})); }catch(e){ BN_RULES=[]; }
  renderBtns(); renderRules();
  { let cc=[]; try{ cc=JSON.parse(n.cards_json||'[]'); }catch(e){} CarEd.mount('bnCarousel', cc); if(document.getElementById('bnSite')) document.getElementById('bnSite').value = n.site_id ? 'site' : 'all'; }
  window.scrollTo({top:0,behavior:'smooth'});
}
async function saveBn(){
  const key=document.getElementById('bnKey').value.trim();
  if(!key){toast('کلید گره لازم است',false);return;}
  const btns=BN_BTNS.filter(b=>(b.text||'').trim()).map(b=> b.kind==='url'?{text:b.text,url:b.url}: b.kind==='contact'?{text:b.text,contact:true}:{text:b.text,goto:b.goto});
  const rules=BN_RULES.filter(r=>(r.contains||'').trim()&&(r.goto||'').trim()).map(r=>({contains:r.contains.trim(),goto:r.goto.trim()}));
  const d=await api('botnode_save',{
    id:document.getElementById('bnId').value||0,
    node_key:key, title:document.getElementById('bnTitle').value.trim(),
    text:document.getElementById('bnText').value, buttons:btns, rules:rules,
    is_root:document.getElementById('bnRoot').checked?1:0,
    awaits_property_field:document.getElementById('bnAwaitsField').value||'',
    cards:CarEd.get('bnCarousel'),
    site_scope:(document.getElementById('bnSite')||{}).value||''
  });
  if(d.ok){ toast('ذخیره شد'); resetBn(); loadBn(); }
  else toast(d.error||'خطا',false);
}

// ==================== اقامتگاه‌ها (لوکاپ خودکار) ====================
let PROP_CACHE = {};
async function loadProperties(){
  const box = document.getElementById('propRows');
  const d = await api('properties_list');
  if(!d.ok){ box.innerHTML = '<span class="tag tag-warn">خطا</span>'; return; }
  const rows = d.rows || [];
  PROP_CACHE = {};
  rows.forEach(p => PROP_CACHE[p.id] = p);
  box.innerHTML = rows.length ? rows.map(p => `
    <div class="ig-comment-row" style="border:1px solid var(--line-soft);border-radius:10px;padding:10px 12px;margin-bottom:8px">
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:600">
        ${esc(p.name)}
        <span style="display:flex;gap:6px">
          <button class="btn btn-ghost btn-xs" onclick="editPropertyRow(${p.id})">ویرایش</button>
          <button class="btn btn-danger-ghost btn-xs" onclick="deletePropertyRow(${p.id})">حذف</button>
        </span>
      </div>
      <div class="muted" style="font-size:11.5px;margin-top:4px">
        ${p.price ? 'قیمت: '+esc(p.price)+' · ' : ''}${p.checkin_time ? 'ورود: '+esc(p.checkin_time)+' · ' : ''}${p.checkout_time ? 'خروج: '+esc(p.checkout_time)+' · ' : ''}${p.min_advance_days ? 'حداقل رزرو از قبل: '+esc(p.min_advance_days) : ''}
      </div>
    </div>`).join('') : '<span class="muted" style="font-size:13px">هنوز اقامتگاهی ثبت نشده</span>';
}
function resetPropertyForm(){
  document.getElementById('propId').value = '';
  document.getElementById('propName').value = '';
  document.getElementById('propPrice').value = '';
  document.getElementById('propCheckin').value = '';
  document.getElementById('propCheckout').value = '';
  document.getElementById('propMinDays').value = '';
  document.getElementById('propNotes').value = '';
  document.getElementById('propFormTitle').textContent = '+ اقامتگاه جدید';
}
function editPropertyRow(id){
  const p = PROP_CACHE[id];
  if (!p) return;
  document.getElementById('propId').value = p.id;
  document.getElementById('propName').value = p.name || '';
  document.getElementById('propPrice').value = p.price || '';
  document.getElementById('propCheckin').value = p.checkin_time || '';
  document.getElementById('propCheckout').value = p.checkout_time || '';
  document.getElementById('propMinDays').value = p.min_advance_days || '';
  document.getElementById('propNotes').value = p.notes || '';
  document.getElementById('propFormTitle').textContent = 'ویرایش اقامتگاه';
}
async function savePropertyRow(){
  const name = document.getElementById('propName').value.trim();
  if (!name) { toast('نام اقامتگاه لازم است', false); return; }
  const d = await api('property_save', {
    id: document.getElementById('propId').value || null,
    name,
    price: document.getElementById('propPrice').value.trim(),
    checkin_time: document.getElementById('propCheckin').value.trim(),
    checkout_time: document.getElementById('propCheckout').value.trim(),
    min_advance_days: document.getElementById('propMinDays').value.trim(),
    notes: document.getElementById('propNotes').value.trim(),
    is_active: 1,
  });
  if (d.ok) { toast('ذخیره شد'); resetPropertyForm(); loadProperties(); }
  else toast(d.error || 'خطا', false);
}
async function deletePropertyRow(id){
  if (!confirm('این اقامتگاه حذف شود؟')) return;
  await api('property_delete', {id});
  loadProperties();
}
loadProperties();
async function delBn(id){if(!confirm('حذف شود؟'))return;await api('botnode_delete',{id});loadBn();}

// ===== نمای فلو =====
function renderFlow(){
  const box=document.getElementById('botFlow'); if(!box) return;
  if(!BOT_NODES.length){ box.innerHTML='<div class="muted" style="text-align:center;padding:20px">گرهی نیست</div>'; return; }
  box.innerHTML = BOT_NODES.map(n=>{
    let btns=[]; try{btns=JSON.parse(n.buttons_json||'[]');}catch(e){}
    let rules=[]; try{rules=JSON.parse(n.rules_json||'[]');}catch(e){}
    const edges = [
      ...btns.filter(b=>b.goto).map(b=>`<span class="flow-edge" onclick="flowJump('${esc(b.goto)}')">🔘 ${esc(b.text||'')} → <b>${esc(b.goto)}</b></span>`),
      ...btns.filter(b=>b.url).map(b=>`<span class="flow-edge link">🔗 ${esc(b.text||'')}</span>`),
      ...btns.filter(b=>b.contact).map(b=>`<span class="flow-edge link">📱 ${esc(b.text||'')}</span>`),
      ...rules.map(r=>`<span class="flow-edge rule" onclick="flowJump('${esc(r.goto)}')">⤳ «${esc(r.contains)}» → <b>${esc(r.goto)}</b></span>`),
    ].join('') || '<span class="muted" style="font-size:11px">بدون مسیر خروجی</span>';
    return `<div class="flow-node" id="flow_${esc(n.node_key)}">
      <div class="flow-node-head">
        <span class="mono"><b>${esc(n.node_key)}</b></span>
        ${n.is_root==1?'<span class="tag tag-ok" style="font-size:9px">شروع</span>':''}
      </div>
      <div class="flow-node-text">${esc((n.text||'').slice(0,60))||'<span class=muted>بدون متن</span>'}</div>
      <div class="flow-edges">${edges}</div>
    </div>`;
  }).join('');
}
function flowJump(key){
  const el=document.getElementById('flow_'+key);
  if(el){ el.scrollIntoView({behavior:'smooth',block:'center'}); el.classList.add('flow-hl'); setTimeout(()=>el.classList.remove('flow-hl'),1200); }
}

// ===== شبیه‌ساز تست =====
function simAppend(who, html){
  const log=document.getElementById('simLog');
  const cls = who==='user' ? 'sim-user' : (who==='bot'?'sim-bot':'sim-sys');
  log.innerHTML += `<div class="sim-msg ${cls}">${html}</div>`;
  log.scrollTop = log.scrollHeight;
}
async function simSend(){
  const inp=document.getElementById('simInput');
  const text=inp.value.trim(); if(!text) return;
  inp.value='';
  simAppend('user', esc(text));
  const d=await api('bot_simulate', {text});
  if(d.ok && d.matched){
    const m=d.message;
    let html = esc(m.text||'');
    if(m.buttons){
      const flat=[].concat(...m.buttons);
      html += '<div class="sim-btns">'+flat.map(b=>{
        const goto = (b.callback||'').indexOf('nav:')===0 ? b.callback.slice(4) : null;
        return goto
          ? `<span class="sim-btn" onclick="simClick('${esc(goto)}','${esc(b.text||'')}')">${esc(b.text||'')}</span>`
          : `<span class="sim-btn dim">${esc(b.text||'')}</span>`;
      }).join('')+'</div>';
    }
    simAppend('bot', html + `<div class="sim-meta">گره: ${esc(d.node.node_key)}</div>`);
  } else {
    simAppend('sys', 'بات برای این پیام جوابی ندارد');
  }
}
async function simClick(goto, label){
  simAppend('user', '🔘 '+esc(label));
  const d=await api('bot_simulate', {callback:'nav:'+goto});
  if(d.ok && d.matched){
    const m=d.message;
    let html=esc(m.text||'');
    if(m.buttons){
      const flat=[].concat(...m.buttons);
      html+='<div class="sim-btns">'+flat.map(b=>{
        const g=(b.callback||'').indexOf('nav:')===0?b.callback.slice(4):null;
        return g?`<span class="sim-btn" onclick="simClick('${esc(g)}','${esc(b.text||'')}')">${esc(b.text||'')}</span>`:`<span class="sim-btn dim">${esc(b.text||'')}</span>`;
      }).join('')+'</div>';
    }
    simAppend('bot', html+`<div class="sim-meta">گره: ${esc(d.node.node_key)}</div>`);
  } else simAppend('sys','گره مقصد یافت نشد');
}
function simReset(){ document.getElementById('simLog').innerHTML=''; }

renderBtns(); renderRules();
loadBn();
