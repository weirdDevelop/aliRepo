// pages/botsettings.js.php
let BTNS=[];
function renderBtns(){
  document.getElementById('bsButtons').innerHTML=BTNS.map((b,i)=>`
    <div style="display:flex;gap:6px;margin-bottom:8px;align-items:center;flex-wrap:wrap">
      <input value="${esc(b.text||'')}" oninput="BTNS[${i}].text=this.value" placeholder="متن دکمه" style="flex:1 1 150px;min-width:0">
      <select onchange="setBtnType(${i},this.value)" style="width:130px">
        <option value="url" ${b.type==='url'?'selected':''}>لینک</option>
        <option value="contact" ${b.type==='contact'?'selected':''}>اشتراک شماره</option>
      </select>
      ${b.type==='url'?`<input value="${esc(b.url||'')}" oninput="BTNS[${i}].url=this.value" placeholder="https://…" class="mono" style="flex:1 1 180px;min-width:0">`:''}
      <button class="btn btn-danger-ghost btn-sm" onclick="BTNS.splice(${i},1);renderBtns()">×</button>
    </div>`).join('');
}
function setBtnType(i,t){BTNS[i].type=t;renderBtns();}
function addBtn(){BTNS.push({text:'',type:'url',url:''});renderBtns();}
async function loadBs(){
  const d=await api('botsettings_get');
  if(d.welcome){
    document.getElementById('bsText').value=d.welcome.text||'';
    BTNS=(d.welcome.buttons||[]).map(row=>{
      const b=Array.isArray(row)?row[0]:row;
      if(b.request_contact)return{text:b.text,type:'contact'};
      return{text:b.text,type:'url',url:b.url||''};
    });
  }
  renderBtns();
}
async function saveBs(){
  // هر دکمه یک ردیف جدا
  const buttons=BTNS.filter(b=>b.text).map(b=>{
    if(b.type==='contact')return[{text:b.text,request_contact:true}];
    return[{text:b.text,url:b.url}];
  });
  const d=await api('botsettings_save',{text:document.getElementById('bsText').value.trim(),buttons});
  if(d.ok)toast('ذخیره شد');else toast(d.error||'خطا',false);
}
loadBs();
