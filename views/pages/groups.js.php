// pages/groups.js.php
async function loadGrp(){
  const d=await api('groups_list');
  document.getElementById('grpRows').innerHTML=(d.groups||[]).map(g=>`<tr>
    <td><span class="tag" style="background:${esc(g.color)}22;color:${esc(g.color)}">${esc(g.name)}</span></td>
    <td>${g.member_count} نفر</td>
    <td style="text-align:left"><button class="btn btn-danger-ghost btn-sm" onclick="delGrp(${g.id})">حذف</button></td>
  </tr>`).join('')||'<tr><td class="empty">گروهی نیست</td></tr>';
}
async function loadTag(){
  const d=await api('tags_list');
  document.getElementById('tagRows').innerHTML=(d.tags||[]).map(t=>`<tr>
    <td><span class="tag tag-mute">${esc(t.name)}</span></td>
    <td>${t.person_count} نفر</td>
    <td style="text-align:left"><button class="btn btn-danger-ghost btn-sm" onclick="delTag(${t.id})">حذف</button></td>
  </tr>`).join('')||'<tr><td class="empty">برچسبی نیست</td></tr>';
}
async function createGrp(){
  const name=document.getElementById('grpName').value.trim();
  if(!name){toast('نام لازم است',false);return;}
  const d=await api('group_create',{name,color:document.getElementById('grpColor').value});
  if(d.ok){toast('ایجاد شد');document.getElementById('grpName').value='';loadGrp();}
}
async function delGrp(id){if(!confirm('حذف شود؟'))return;await api('group_delete',{id});loadGrp();}
async function createTag(){
  const name=document.getElementById('tagName').value.trim();
  if(!name){toast('نام لازم است',false);return;}
  const d=await api('tag_create',{name});
  if(d.ok){toast('ایجاد شد');document.getElementById('tagName').value='';loadTag();}
}
async function delTag(id){if(!confirm('حذف شود؟'))return;await api('tag_delete',{id});loadTag();}
loadGrp();loadTag();
