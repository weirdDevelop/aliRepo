/* ۲.۱.۰ — ویرایشگر کروسل (مشترک بین پاسخ خودکار، بات‌ساز و الگوها). خروجی JS خام، داخل <script> صفحه‌ی قدیمی. */
window.CarEd = (function(){
  const MAXC = 10, MAXB = 3, S = {};
  let nodeKeys = null;
  function css(){
    if (document.getElementById('cared-css')) return;
    const st = document.createElement('style'); st.id = 'cared-css';
    st.textContent = `
.cared{border:1px dashed var(--border,#ccc);border-radius:12px;padding:12px;margin-top:6px}
.cared-prev{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 10px;direction:rtl}
.cared-pc{flex:0 0 190px;border:1px solid var(--border,#ddd);border-radius:14px;overflow:hidden;background:var(--card,#fff)}
.cared-pc .im{height:120px;background:#eee center/cover no-repeat;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px}
.cared-pc .tt{padding:8px 10px 2px;font-weight:700;font-size:13px}
.cared-pc .st{padding:0 10px 8px;font-size:11.5px;opacity:.7}
.cared-pc .bt{border-top:1px solid var(--border,#eee);text-align:center;padding:7px 4px;font-size:12px;color:#2563eb}
.cared-card{border:1px solid var(--border,#ddd);border-radius:12px;padding:10px;margin-bottom:8px}
.cared-card .h{display:flex;gap:6px;align-items:center;margin-bottom:8px;font-weight:600;font-size:13px}
.cared-card .h .sp{flex:1}
.cared-row{display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap}
.cared-row>*{flex:1;min-width:110px}
.cared-b{display:flex;gap:6px;margin-bottom:4px}
.cared-b input,.cared-b select{min-width:0}
.cared-b select{flex:0 0 105px}.cared-b input:first-child{flex:0 0 120px}.cared-b input:last-child{flex:1}
.cared .mini{padding:2px 8px;font-size:12px}
.cared .thumb{width:44px;height:44px;border-radius:8px;background:#eee center/cover;flex:0 0 44px}
`;
    document.head.appendChild(st);
  }
  async function keys(){
    if (nodeKeys) return nodeKeys;
    try { const d = await api('botnodes_list'); nodeKeys = [...new Set((d.nodes||[]).map(n=>n.node_key))]; } catch(e){ nodeKeys = []; }
    return nodeKeys;
  }
  function imgUrl(v){ if(!v) return ''; return /^https?:/i.test(v) ? v : v; }
  function blank(){ return {title:'', subtitle:'', image:'', buttons:[]}; }
  function mount(id, cards){
    css();
    S[id] = (Array.isArray(cards) ? JSON.parse(JSON.stringify(cards)) : []).slice(0, MAXC);
    keys().then(()=>render(id));
    render(id);
  }
  function get(id){
    return (S[id]||[]).filter(c=>(c.title||'').trim()!=='').map(c=>({
      title:c.title.trim(), subtitle:(c.subtitle||'').trim(), image:c.image||'',
      buttons:(c.buttons||[]).filter(b=>(b.text||'').trim()&&(b.url||b.goto)).map(b=>b.goto?{text:b.text.trim(),goto:b.goto.trim()}:{text:b.text.trim(),url:b.url.trim()})
    }));
  }
  function render(id){
    const host = document.getElementById(id); if(!host) return;
    const cs = S[id];
    const prev = cs.map(c=>`<div class="cared-pc"><div class="im" style="${c.image?`background-image:url('${esc(imgUrl(c.image))}')`:''}">${c.image?'':'بدون عکس'}</div>
      <div class="tt">${esc(c.title||'عنوان کارت')}</div><div class="st">${esc(c.subtitle||'')}</div>
      ${(c.buttons||[]).filter(b=>b.text).map(b=>`<div class="bt">${esc(b.text)}</div>`).join('')}</div>`).join('');
    const dl = `<datalist id="${id}_keys">${(nodeKeys||[]).map(k=>`<option value="${esc(k)}">`).join('')}</datalist>`;
    host.innerHTML = `<div class="cared">
      ${cs.length?`<div class="cared-prev">${prev}</div>`:'<div class="note" style="margin-bottom:8px">کروسل خالی است. با «افزودن کارت» شروع کنید (حداکثر ۱۰ کارت، هر کارت ۳ دکمه).</div>'}
      ${cs.map((c,i)=>`<div class="cared-card">
        <div class="h"><span>کارت ${i+1}</span><span class="sp"></span>
          <button type="button" class="btn btn-ghost btn-sm mini" onclick="CarEd.mv('${id}',${i},-1)" ${i?'':'disabled'}>→</button>
          <button type="button" class="btn btn-ghost btn-sm mini" onclick="CarEd.mv('${id}',${i},1)" ${i<cs.length-1?'':'disabled'}>←</button>
          <button type="button" class="btn btn-danger-ghost btn-sm mini" onclick="CarEd.rm('${id}',${i})">حذف</button></div>
        <div class="cared-row"><div style="display:flex;gap:8px;align-items:center">
            <div class="thumb" style="${c.image?`background-image:url('${esc(imgUrl(c.image))}')`:''}"></div>
            <label class="btn btn-ghost btn-sm" style="cursor:pointer;white-space:nowrap">آپلود عکس<input type="file" accept="image/*" style="display:none" onchange="CarEd.up('${id}',${i},this)"></label></div>
          <input placeholder="یا آدرس عکس (https://…)" value="${esc(/^https?:/i.test(c.image||'')?c.image:'')}" onchange="CarEd.set('${id}',${i},'image',this.value)"></div>
        <div class="cared-row"><input maxlength="80" placeholder="عنوان (مثلاً قیمت: ۱٬۲۰۰٬۰۰۰ تومان)" value="${esc(c.title||'')}" oninput="CarEd.set('${id}',${i},'title',this.value,1)"></div>
        <div class="cared-row"><input maxlength="80" placeholder="زیرعنوان" value="${esc(c.subtitle||'')}" oninput="CarEd.set('${id}',${i},'subtitle',this.value,1)"></div>
        ${(c.buttons||[]).map((b,j)=>`<div class="cared-b">
          <input maxlength="20" placeholder="متن دکمه" value="${esc(b.text||'')}" oninput="CarEd.bset('${id}',${i},${j},'text',this.value,1)">
          <select onchange="CarEd.btype('${id}',${i},${j},this.value)"><option value="url" ${b.goto===undefined?'selected':''}>لینک</option><option value="goto" ${b.goto!==undefined?'selected':''}>رفتن به منو</option></select>
          <input list="${id}_keys" placeholder="${b.goto!==undefined?'کلید گرهٔ منو (مثلاً main)':'https://…'}" value="${esc(b.goto!==undefined?b.goto:(b.url||''))}" oninput="CarEd.bset('${id}',${i},${j},'${b.goto!==undefined?'goto':'url'}',this.value,1)">
          <button type="button" class="btn btn-danger-ghost btn-sm mini" onclick="CarEd.brm('${id}',${i},${j})">×</button></div>`).join('')}
        ${(c.buttons||[]).length<MAXB?`<button type="button" class="btn btn-ghost btn-sm mini" onclick="CarEd.badd('${id}',${i})">+ دکمه</button>`:''}
      </div>`).join('')}
      ${cs.length<MAXC?`<button type="button" class="btn btn-ghost btn-sm" onclick="CarEd.add('${id}')">+ افزودن کارت</button>`:''}
      ${dl}</div>`;
  }
  function softPrev(id){ // بدون رندر کامل (تا فوکوس input نپرد): فقط پیش‌نمایش
    const host = document.getElementById(id); if(!host) return;
    const box = host.querySelector('.cared-prev'); if(!box) return;
    box.innerHTML = S[id].map(c=>`<div class="cared-pc"><div class="im" style="${c.image?`background-image:url('${esc(imgUrl(c.image))}')`:''}">${c.image?'':'بدون عکس'}</div>
      <div class="tt">${esc(c.title||'عنوان کارت')}</div><div class="st">${esc(c.subtitle||'')}</div>
      ${(c.buttons||[]).filter(b=>b.text).map(b=>`<div class="bt">${esc(b.text)}</div>`).join('')}</div>`).join('');
  }
  return {
    mount, get,
    add(id){ if(S[id].length<MAXC){ S[id].push(blank()); render(id);} },
    rm(id,i){ S[id].splice(i,1); render(id); },
    mv(id,i,d){ const a=S[id], j=i+d; if(j<0||j>=a.length) return; [a[i],a[j]]=[a[j],a[i]]; render(id); },
    set(id,i,k,v,soft){ S[id][i][k]=v; soft?softPrev(id):render(id); },
    badd(id,i){ const b=S[id][i].buttons=S[id][i].buttons||[]; if(b.length<MAXB){ b.push({text:'',url:''}); render(id);} },
    brm(id,i,j){ S[id][i].buttons.splice(j,1); render(id); },
    btype(id,i,j,t){ const b=S[id][i].buttons[j]; if(t==='goto'){ delete b.url; b.goto=''; } else { delete b.goto; b.url=''; } render(id); },
    bset(id,i,j,k,v,soft){ S[id][i].buttons[j][k]=v; soft?softPrev(id):render(id); },
    async up(id,i,inp){
      const f = inp.files[0]; if(!f) return;
      const fd = new FormData(); fd.append('image', f);
      const d = await api('upload_image', fd, true);
      if(d.ok){ S[id][i].image = d.rel; render(id); toast('عکس کارت آپلود شد'); }
      else toast(d.error||'آپلود ناموفق', false);
    }
  };
})();
