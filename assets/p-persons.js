(function(){
const {$, $$, E, fa, nf, pct, compact, rel, ic, av, chi, pill, tagHtml, sw, seg, tabs, empty, field, A, ON, UI, reg, go, op, contact, page, toast, menu, modal, drawer, confirm, busy} = EB;
const L = () => window.EB_LIVE;
const F = {q:'', st:'', group:'', tag:'', chs:[], phone:'', act:'', inactive:'', responded:'', crm:'', sort:'recent', sel:new Set(), pg:1};
const CRM_ON = () => !!((window.EB_BOOT || {}).crm && EB_BOOT.crm.ready);
const CRM_F = [['', 'همه (CRM)'], ['any', 'هر سابقه‌ای در CRM'], ['customer', 'مشتری CRM'], ['lead', 'سرنخ فروش'], ['host', 'میزبان'], ['caller', 'تماس گرفته'], ['call_only', 'فقط تماس (بدون پیام)'], ['none', 'بدون سابقه در CRM']];
const R = {rows:[], total:0, pages:1, loading:false, err:null};
let req = 0;

async function fetchPersons() {
  const my = ++req; R.loading = true; paintTable();
  const r = await L().api('persons_list', {q:F.q, status:F.st, group_id:F.group ? +F.group.slice(1) : 0, tag_id:F.tag ? +F.tag.slice(1) : 0, channels:F.chs.map(c => c === 'safir' ? 'bale_safir' : c), has_phone:F.phone, active_days:F.act, inactive_days:F.inactive, responded:F.responded, crm:F.crm, sort:F.sort, page:F.pg});
  if (my !== req) return;
  R.loading = false;
  if (!r.ok) { R.err = r.error; paintTable(); return; }
  R.err = null; R.rows = r.persons.map(p => Object.assign(L().mapContact(p), {crm:p.crm || null})); R.total = r.total; R.pages = r.pages || 1;
  paintTable();
}

/* نشان‌های CRM در فهرست */
function crmTags(k) {
  if (!k) return '';
  const t = [];
  if (k.customer) t.push(`<span class="mini-tag" style="background:var(--gold-soft,#f6ecd6);color:var(--gold,#8a6413)">مشتری CRM</span>`);
  if (k.lead) t.push(`<span class="mini-tag">سرنخ</span>`);
  if (k.host) t.push(`<span class="mini-tag">میزبان</span>`);
  if (k.calls) t.push(`<span class="mini-tag" title="تماس با این شماره">${ic('phone')} ${fa(k.calls)}</span>`);
  return t.length ? `<div class="row wrap" style="gap:3px;margin-top:3px">${t.join('')}</div>` : '';
}

reg('persons', {title:'مشتریان', icon:'users-three', sub:'بانک مشتری یکپارچه در همه‌ی کانال‌ها',
async load() { await L().refreshMeta(); },
render(el, args) {
  el.innerHTML = `
  <div class="ph"><div><h2>مشتریان</h2><p>هر مشتری یک «شخص» است و هر کانال یک «هویت» او. با شماره موبایل، هویت‌های کانال‌های مختلف یکی می‌شوند.</p></div>
    <div class="acts">${D.me.is_admin ? `<button class="btn" data-act="pp-import">${ic('upload-simple')}ورود از اکسل</button>` : ''}<button class="btn" data-act="pp-export">${ic('download-simple')}خروجی</button><button class="btn btn-p" data-act="person-new">${ic('user-plus')}مخاطب جدید</button></div></div>
  <div class="card" id="ppCard"></div>`;
  paintHead(); fetchPersons();
  // ۲.۰.۱: #persons.p12 یا #persons.tel0912… (از صفحه‌های CRM) ← باز کردن پرونده‌ی یکپارچه
  const a = (args || [])[0] || '';
  if (/^p\d+$/.test(a) || /^tel\d{10,13}$/.test(a)) {
    try { history.replaceState(null, '', '#persons'); } catch (e) {}
    (async () => {
      let id = a[0] === 'p' ? +a.slice(1) : 0;
      if (!id) { const r = await L().api('crm_find_person', {phone:a.slice(3)}); if (!r.ok) return toast(r.error, 'err'); id = r.id; }
      A['person-open']({dataset:{id:String(id), tab:'tl'}});
    })();
  }
}});
function chips() {
  const c = [];
  if (F.group) c.push(['group', 'گروه: ' + E((D.groups.find(g => g.id === F.group) || {}).name || '')]); if (F.tag) c.push(['tag', 'برچسب: ' + E((D.tags.find(g => g.id === F.tag) || {}).name || '')]);
  F.chs.forEach(ch => c.push(['ch:' + ch, 'کانال: ' + D.CH[ch].name])); if (F.phone) c.push(['phone', F.phone === 'yes' ? 'دارای شماره' : 'بدون شماره']);
  if (F.act) c.push(['act', 'فعال در ' + fa(F.act) + ' روز']); if (F.inactive) c.push(['inactive', 'بدون ارسال در ' + fa(F.inactive) + ' روز']); if (F.responded) c.push(['responded', F.responded === 'yes' ? 'پاسخ داده' : 'هرگز پاسخ نداده']); if (F.crm) c.push(['crm', 'CRM: ' + (CRM_F.find(x => x[0] === F.crm) || ['', ''])[1]]);
  return c.length ? `<div class="row wrap" style="padding:10px 16px;border-bottom:1px solid var(--line-2)">${c.map(x => `<span class="tag">${x[1]}<button data-act="pp-unf" data-k="${x[0]}" aria-label="حذف فیلتر">${ic('x')}</button></span>`).join('')}<button class="btn btn-g btn-sm" data-act="pp-reset">پاک کردن همه</button></div>` : '';
}
function paintHead() {
  const card = $('#ppCard'); if (!card) return;
  card.innerHTML = `<div class="card-h" style="gap:8px">
      ${seg('ppSt', [['', 'همه'], ['member', 'عضو'], ['anonymous', 'ناشناس']], F.st)}
      <div class="acts" style="flex-wrap:wrap">
        <div class="inp-wrap" style="width:220px">${ic('magnifying-glass')}<input class="inp inp-sm" id="ppQ" placeholder="نام یا شماره" value="${E(F.q)}" data-on-input="pp-q"></div>
        <select class="inp inp-sm" style="width:auto" data-on-change="pp-group"><option value="">همه‌ی گروه‌ها</option>${D.groups.map(g => `<option value="${g.id}" ${F.group === g.id ? 'selected' : ''}>${E(g.name)}</option>`).join('')}</select>
        ${CRM_ON() ? `<select class="inp inp-sm" style="width:auto" data-on-change="pp-crm" aria-label="فیلتر CRM">${CRM_F.map(x => `<option value="${x[0]}" ${F.crm === x[0] ? 'selected' : ''}>${x[1]}</option>`).join('')}</select>` : ''}
        <button class="btn btn-sm" data-act="pp-chs" data-pop>${ic('broadcast')}کانال${F.chs.length ? ' (' + fa(F.chs.length) + ')' : ''}</button>
        <button class="btn btn-sm" data-act="pp-adv">${ic('funnel')}فیلتر پیشرفته</button>
        <select class="inp inp-sm" style="width:auto" data-on-change="pp-sort">${[['recent', 'به‌روزترین'], ['created', 'تازه‌افزوده'], ['activity', 'آخرین پیام'], ['name', 'الفبا']].map(s => `<option value="${s[0]}" ${F.sort === s[0] ? 'selected' : ''}>مرتب: ${s[1]}</option>`).join('')}</select>
      </div></div>
    ${chips()}<div id="ppBody"></div>`;
}
function paintTable() {
  const b = $('#ppBody'); if (!b) return;
  const rows = R.rows;
  if (R.loading && !rows.length) { b.innerHTML = `<div class="card-b">${EB.sk(8)}</div>`; return; }
  if (R.err) { b.innerHTML = empty('warning-circle', 'فهرست بارگذاری نشد', E(R.err), '<button class="btn btn-sm" data-act="pp-reload">تلاش دوباره</button>'); return; }
  if (!rows.length) { b.innerHTML = empty('users-three', F.q || F.group || F.tag || F.chs.length || F.crm ? 'مشتری‌ای با این فیلترها پیدا نشد' : 'هنوز مشتری‌ای ثبت نشده', F.q || F.group ? 'فیلترها را کم کنید.' : 'وقتی کسی به ربات‌ها یا دایرکت پیام بدهد، اینجا اضافه می‌شود. می‌توانید دستی هم اضافه کنید.', '<button class="btn btn-sm" data-act="person-new">' + ic('user-plus') + 'مخاطب جدید</button>'); return; }
  b.innerHTML = `${F.sel.size ? `<div class="bulkbar"><b>${fa(F.sel.size)} نفر انتخاب شده</b><button class="btn btn-sm" data-act="pp-bulk-group" data-pop>${ic('users-three')}افزودن به گروه</button><button class="btn btn-sm" data-act="pp-bulk-tag" data-pop>${ic('tag')}برچسب</button>${D.me.can_delete ? `<button class="btn btn-sm btn-d" data-act="pp-bulk-del">${ic('trash')}حذف</button>` : ''}<button class="btn btn-g btn-sm ms-auto" data-act="pp-sel-clear">لغو انتخاب</button></div>` : ''}
    <div class="tbl-wrap" style="${R.loading ? 'opacity:.6' : ''}"><table class="tbl resp"><thead><tr><th class="w1"><input type="checkbox" class="cb" data-act="pp-all" ${rows.every(r => F.sel.has(r.id)) ? 'checked' : ''} aria-label="انتخاب همه"></th><th>مشتری</th><th>کانال‌ها</th><th>گروه و برچسب</th><th>فعالیت</th><th>آخرین پیام</th><th></th></tr></thead><tbody>
      ${rows.map(c => `<tr class="clk ${F.sel.has(c.id) ? 'sel' : ''}" data-act="person-open" data-id="${c.id}">
        <td class="w1"><input type="checkbox" class="cb" data-act="pp-check" data-id="${c.id}" ${F.sel.has(c.id) ? 'checked' : ''} aria-label="انتخاب"></td>
        <td><div class="row">${av(c.name)}<div style="min-width:0"><div class="row" style="gap:5px"><b>${E(c.name)}</b>${c.vip ? `<span style="color:var(--gold)" title="VIP">${ic('crown-simple')}</span>` : ''}${c.blocked ? pill('بلاک', 'err') : ''}</div><div class="xs muted">${c.phone ? fa(c.phone) : 'بدون شماره'} · ${c.member ? 'عضو' : 'ناشناس'}</div>${crmTags(c.crm)}</div></div></td>
        <td class="m-hide"><div class="chans">${[...new Set(c.idents.map(i => i.ch))].map(ch => chi(ch)).join('')}</div></td>
        <td class="m-hide"><div class="row wrap" style="gap:4px;max-width:260px">${c.groups.slice(0, 2).map(g => tagHtml(g)).join('')}${c.tags.slice(0, 1).map(t => tagHtml(t)).join('')}${c.groups.length + c.tags.length > 3 ? `<span class="mini-tag">+${fa(c.groups.length + c.tags.length - 3)}</span>` : ''}</div></td>
        <td class="m-hide"><div class="small tnum"><span title="ارسالی">${ic('arrow-up')}${fa(c.sent)}</span> <span title="دریافتی">${ic('arrow-down')}${fa(c.recv)}</span></div>${c.sent ? `<div class="bar ${c.rr > 50 ? 'ok' : c.rr > 25 ? '' : 'warn'}" style="width:70px;margin-top:4px" title="نرخ پاسخ ${fa(c.rr)}٪"><i style="width:${Math.min(100, c.rr)}%"></i></div>` : ''}</td>
        <td class="small muted">${(c.sent || c.recv) && c.lastMsg < 99999 ? rel(c.lastMsg) : c.crm && c.crm.last_call_at ? `<span title="آخرین تماس">${ic('phone')} ${L().faDate(c.crm.last_call_at, {day:'numeric', month:'short'})}</span>` : '-'}</td>
        <td class="w1"><button class="btn btn-g btn-ic btn-sm" data-act="pp-row" data-id="${c.id}" data-pop aria-label="بیشتر">${ic('dots-three-vertical')}</button></td></tr>`).join('')}
    </tbody></table></div>
    <div class="pager"><span>${fa(R.total)} مشتری · صفحه ${fa(F.pg)} از ${fa(R.pages)}</span><div class="ms-auto"><button class="btn btn-sm" data-act="pp-pg" data-v="${F.pg - 1}" ${F.pg <= 1 ? 'disabled' : ''}>${ic('caret-right')}قبلی</button><button class="btn btn-sm" data-act="pp-pg" data-v="${F.pg + 1}" ${F.pg >= R.pages ? 'disabled' : ''}>بعدی${ic('caret-left')}</button></div></div>`;
}
const refetch = () => { F.pg = 1; F.sel.clear(); paintHead(); fetchPersons(); };
UI.on('seg:ppSt', v => { F.st = v; refetch(); });
ON['pp-q'] = EB.debounce(el => { F.q = el.value.trim(); F.pg = 1; fetchPersons(); }, 350);
ON['pp-group'] = el => { F.group = el.value; refetch(); };
ON['pp-crm'] = el => { F.crm = el.value; refetch(); };
ON['pp-sort'] = el => { F.sort = el.value; fetchPersons(); };
A['pp-reload'] = () => fetchPersons();
A['pp-pg'] = el => { F.pg = Math.max(1, +el.dataset.v); fetchPersons(); $('.page-scroll') && ($('.page-scroll').scrollTop = 0); };
A['pp-reset'] = () => { Object.assign(F, {q:'', st:'', group:'', tag:'', chs:[], phone:'', act:'', inactive:'', responded:'', crm:''}); refetch(); };
A['pp-unf'] = el => { const k = el.dataset.k; if (k.startsWith('ch:')) F.chs = F.chs.filter(c => c !== k.slice(3)); else F[k] = ''; refetch(); };
A['pp-chs'] = el => menu(el, [{h:'کانال‌ها (چندانتخابی)'}, ...['instagram', 'telegram', 'bale', 'roubika', 'webchat', 'sms', 'safir', 'email'].map(k => ({t:D.CH[k].name, icon:D.CH[k].icon, on:F.chs.includes(k), fn:() => { F.chs.includes(k) ? F.chs = F.chs.filter(x => x !== k) : F.chs.push(k); refetch(); }}))]);
A['pp-check'] = (el, e) => { e.stopPropagation(); el.checked ? F.sel.add(el.dataset.id) : F.sel.delete(el.dataset.id); paintTable(); };
A['pp-all'] = (el, e) => { e.stopPropagation(); if (el.checked) R.rows.forEach(r => F.sel.add(r.id)); else R.rows.forEach(r => F.sel.delete(r.id)); paintTable(); };
A['pp-sel-clear'] = () => { F.sel.clear(); paintTable(); };
const selIds = () => [...F.sel].map(id => +id.slice(1));
A['pp-bulk-group'] = el => menu(el, D.groups.length ? D.groups.map(g => ({t:E(g.name), dot:g.color, fn:async () => { const r = await L().api('group_add_persons', {group_id:g.rid, person_ids:selIds()}); if (!r.ok) return toast(r.error, 'err'); toast(fa(F.sel.size) + ' نفر به گروه «' + E(g.name) + '» اضافه شدند'); F.sel.clear(); fetchPersons(); }})) : [{t:'ابتدا یک گروه بسازید', icon:'plus', fn:() => go('groups')}]);
A['pp-bulk-tag'] = el => menu(el, D.tags.length ? D.tags.map(g => ({t:E(g.name), dot:g.color, fn:async () => { const r = await L().api('tag_persons', {tag_id:g.rid, person_ids:selIds()}); if (!r.ok) return toast(r.error, 'err'); toast('برچسب «' + E(g.name) + '» اضافه شد'); F.sel.clear(); fetchPersons(); }})) : [{t:'ابتدا یک برچسب بسازید', icon:'plus', fn:() => go('groups')}]);
A['pp-bulk-del'] = () => confirm({title:'حذف ' + fa(F.sel.size) + ' مخاطب', text:'مخاطبان و همه‌ی هویت‌هایشان حذف می‌شوند. این کار قابل بازگشت نیست.', ok:'حذف', danger:true, onOk:async () => { let n = 0; for (const id of selIds()) { const r = await L().api('person_delete', {id}); if (r.ok) n++; } toast(fa(n) + ' مخاطب حذف شد'); F.sel.clear(); fetchPersons(); }});
A['pp-row'] = (el, e) => { e.stopPropagation(); const c = contact(el.dataset.id); menu(el, [{t:'باز کردن پروفایل', icon:'user-focus', fn:() => A['person-open']({dataset:{id:c.id}})}, ...(D.pageAllow.includes('inbox') ? [{t:'رفتن به گفتگو', icon:'chat-circle-text', fn:() => go('inbox.v' + c.rid)}] : []), ...(c.phone ? [{t:'کپی شماره', icon:'copy', fn:() => EB.copy(c.phone)}] : []), ...(D.me.can_delete ? ['-', {t:'حذف مخاطب', icon:'trash', danger:true, fn:() => confirm({title:'حذف ' + E(c.name), text:'این کار قابل بازگشت نیست.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('person_delete', {id:c.rid}); if (!r.ok) return toast(r.error, 'err'); toast('حذف شد'); fetchPersons(); }})}] : [])]); };
A['pp-adv'] = () => {
  const L2 = drawer({title:'فیلتر پیشرفته', sub:'شرط‌ها با هم ترکیب می‌شوند', body:`<div class="stack s16" style="padding:18px">
    ${field('برچسب', `<select class="inp" id="fTag"><option value="">همه</option>${D.tags.map(t => `<option value="${t.id}" ${F.tag === t.id ? 'selected' : ''}>${E(t.name)}</option>`).join('')}</select>`)}
    <div class="field"><label>شماره موبایل</label>${seg('fPhone', [['', 'مهم نیست'], ['yes', 'دارد'], ['no', 'ندارد']], F.phone)}</div>
    <div class="field"><label>فعال (پیام در N روز اخیر)</label>${seg('fAct', [['', 'همه'], ['7', '۷ روز'], ['30', '۳۰ روز']], F.act)}</div>
    <div class="field"><label>بدون پیام ارسالی از ما</label>${seg('fIn', [['', 'همه'], ['30', '۳۰ روز'], ['90', '۹۰ روز']], F.inactive)}</div>
    <div class="field"><label>پاسخ‌دهی</label>${seg('fResp', [['', 'همه'], ['yes', 'پاسخ داده'], ['no', 'هرگز']], F.responded)}</div></div>`, foot:'<button class="btn" data-act="pp-reset" data-close>پاک کردن</button><button class="btn btn-p" data-ok>اعمال فیلتر</button>'});
  const T = {phone:F.phone, act:F.act, inactive:F.inactive, responded:F.responded};
  UI.set('seg:fPhone', v => T.phone = v); UI.set('seg:fAct', v => T.act = v); UI.set('seg:fIn', v => T.inactive = v); UI.set('seg:fResp', v => T.responded = v);
  L2.el.querySelector('[data-ok]').onclick = () => { F.tag = $('#fTag').value; Object.assign(F, T); L2.close(); refetch(); };
};
A['pp-export'] = () => { const q = new URLSearchParams({export:'customers', q:F.q, status:F.st, has_phone:F.phone, group_id:F.group ? F.group.slice(1) : '', tag_id:F.tag ? F.tag.slice(1) : ''}); location.href = 'index.php?' + q.toString(); toast('فایل CSV در حال دانلود است', 'info'); };
A['pp-import'] = () => {
  const M = modal({title:'ورود مشتریان از اکسل یا CSV', icon:'upload-simple', size:'lg', body:`<div class="stack s16">
    <label class="well" style="display:grid;place-items:center;min-height:130px;border-style:dashed;cursor:pointer;text-align:center"><div class="stack s6" style="align-items:center">${ic('file-xls')}<b id="impName">فایل را انتخاب کنید</b><span class="xs muted">CSV یا XLSX. هر ردیف «نام، شماره» یا فقط «شماره». سطر عنوان خودکار رد می‌شود؛ تکراری‌ها ادغام می‌شوند.</span></div><input type="file" class="sr" id="impFile" accept=".csv,.xlsx,.txt" data-on-change="imp-file"></label>
    <div class="fg">${field('افزودن به گروه', `<select class="inp" id="impGroup"><option value="">بدون گروه</option>${D.groups.map(g => `<option value="${g.rid}">${E(g.name)}</option>`).join('')}</select>`)}${field('برچسب', '<input class="inp" id="impTag" placeholder="اختیاری، مثلاً «نمایشگاه پاییز»">')}</div></div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>شروع ورود</button>'});
  M.el.querySelector('[data-ok]').onclick = async e => {
    const f = $('#impFile').files[0]; if (!f) return toast('فایلی انتخاب نشده', 'warn');
    const fd = new FormData(); fd.append('file', f); fd.append('group_id', $('#impGroup').value); fd.append('tag_name', $('#impTag').value.trim());
    e.target.classList.add('loading'); const r = await L().api('import_customers', fd, true); e.target.classList.remove('loading');
    if (!r.ok) return toast(r.error, 'err');
    M.close(); toast(`${fa(r.created || 0)} مشتری جدید، ${fa(r.added || 0)} مورد ادغام/به‌روز${r.invalid ? '، ' + fa(Array.isArray(r.invalid) ? r.invalid.length : r.invalid) + ' ردیف نامعتبر' : ''}`); refetch();
  };
};
ON['imp-file'] = el => { $('#impName').textContent = el.files[0] ? el.files[0].name : 'فایل را انتخاب کنید'; };
A['person-new'] = () => {
  const chOpts = [['instagram', 'یوزرنیم اینستاگرام'], ['telegram', 'chat_id تلگرام'], ['bale', 'chat_id بله'], ['roubika', 'شناسه‌ی روبیکا'], ['email', 'ایمیل']];
  const row = () => `<div class="row"><select class="inp" style="max-width:150px">${chOpts.map(c => `<option value="${c[0]}">${D.CH[c[0]].name}</option>`).join('')}</select><input class="inp ltr" placeholder="مقدار"></div>`;
  const M = modal({title:'مخاطب جدید', icon:'user-plus', body:`<div class="fg">${field('نام و نام خانوادگی', '<input class="inp" id="npName" placeholder="مثلاً مینا فروتن">')}${field('موبایل', '<input class="inp ltr" id="npPhone" placeholder="09121234567">', 'اگر این شماره در بانک باشد، هویت‌ها ادغام می‌شوند', '', 'شماره‌ی موبایل معتبر نیست')}${field('یادداشت', '<input class="inp" id="npNote" placeholder="اختیاری">', '', 'full')}</div>
    <div class="lab" style="margin:16px 0 8px">هویت در کانال‌ها (اختیاری)</div><div class="stack s6" id="npIds">${row()}</div><button class="btn btn-sm" style="margin-top:8px" data-act="np-id">${ic('plus')}هویت دیگر</button>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>افزودن مخاطب</button>'});
  A['np-id'] = () => $('#npIds').insertAdjacentHTML('beforeend', row());
  M.el.querySelector('[data-ok]').onclick = async e => {
    const ph = $('#npPhone'); const pv = ph.value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[\s-]/g, '');
    const bad = pv && !/^(\+98|0098|98|0)?9\d{9}$/.test(pv); ph.closest('.field').classList.toggle('has-err', bad); if (bad) return;
    const channels = $$('#npIds .row').map(r => ({channel:$('select', r).value, value:$('input', r).value.trim()})).filter(c => c.value);
    if (!$('#npName').value.trim() && !pv && !channels.length) return toast('حداقل نام، شماره یا یک هویت لازم است', 'warn');
    e.target.classList.add('loading');
    const r = await L().api('person_create', {full_name:$('#npName').value.trim(), phone:pv, notes:$('#npNote').value.trim(), channels});
    e.target.classList.remove('loading');
    if (!r.ok) return toast(r.error, 'err');
    M.close(); toast(r.merged ? 'با مشتری موجود ادغام شد' : 'مخاطب اضافه شد');
    if (EB.current().slug === 'persons') fetchPersons();
  };
};

/* ۲.۰.۱: پرونده‌ی یکپارچه — پیام‌های همه‌ی کانال‌ها + تماس‌ها و سوابق CRM به ترتیب زمان */
const STG = {no_answer:'عدم پاسخ', followup:'در حال پیگیری', link_sent:'ارسال لینک اقامتگاه', confirmed:'تایید شده', reserved:'رزرو', not_reserved:'عدم رزرو'};
const CALLT = {inbound:['تماس ورودی', 'phone-incoming'], outbound:['تماس خروجی', 'phone-call'], missed:['تماس بی‌پاسخ', 'phone'], internal:['تماس داخلی', 'phone']};
const durF = s => { s = +s || 0; return s < 60 ? fa(s) + ' ثانیه' : fa(Math.floor(s / 60)) + ':' + fa(String(s % 60).padStart(2, '0')); };
const TLG = {'':'همه', msg:'پیام', call:'تماس', sale:'سرنخ و فروش', ticket:'تیکت و یادداشت'};
const tlGroup = k => k === 'msg' ? 'msg' : (k === 'call' || k === 'stage') ? 'call' : (k === 'lead' || k === 'lead_st' || k === 'inquiry' || k === 'sale') ? 'sale' : 'ticket';
function tlHtml(msgs, tl, f) {
  const ev = (tl.events || []).slice();
  msgs.forEach(m => ev.push({k:'msg', at:m.created_at, dir:m.direction, ch:L().chKey(m.channel || m.ch), body:m.body, status:m.status}));
  ev.sort((a, b) => String(b.at || '').localeCompare(String(a.at || '')));
  const cnt = {}; ev.forEach(e => { const g = tlGroup(e.k); cnt[g] = (cnt[g] || 0) + 1; });
  const list = f ? ev.filter(e => tlGroup(e.k) === f) : ev;
  const head = `<div class="row wrap" style="gap:6px;margin-bottom:12px">${Object.entries(TLG).map(([k, t]) => (k && !cnt[k]) ? '' : `<button class="btn btn-sm ${f === k ? 'btn-p' : ''}" data-act="tl-f" data-v="${k}">${t}${k ? ' ' + fa(cnt[k]) : ' ' + fa(ev.length)}</button>`).join('')}${tl.ready === false ? '<span class="xs muted">CRM هنوز راه‌اندازی نشده؛ فقط پیام‌ها</span>' : ''}${tl.error ? `<span class="xs" style="color:var(--err)">${E(tl.error)}</span>` : ''}</div>`;
  if (!list.length) return head + empty('clock-counter-clockwise', 'سابقه‌ای نیست', 'هنوز پیام، تماس یا سابقه‌ای در CRM برای این مشتری ثبت نشده.');
  let day = '';
  const rows = list.slice(0, 400).map(e => {
    const d = String(e.at || '').slice(0, 10); let sep = '';
    if (d !== day) { day = d; sep = `<div class="lab" style="margin:14px 0 6px">${d ? L().faDate(d + ' 12:00:00', {weekday:'long', day:'numeric', month:'long', year:'numeric'}) : 'بدون تاریخ'}</div>`; }
    const time = e.at && e.at.length > 10 ? `<span class="xs muted ms-auto tnum">${L().faDate(e.at, {hour:'2-digit', minute:'2-digit'})}</span>` : '';
    const by = e.by ? ` · ${E(e.by)}` : '';
    let icon = 'note', t = '', sub = '', extra = '';
    if (e.k === 'msg') { icon = null; t = `${e.dir === 'in' ? '<b>مشتری:</b> ' : '<span class="muted">ما:</span> '}${E(String(e.body || '').slice(0, 400))}`; sub = (D.CH[e.ch] ? D.CH[e.ch].name : '') + (e.status === 'failed' ? ' · <span style="color:var(--err)">ناموفق</span>' : ''); }
    else if (e.k === 'call') { const ct = CALLT[e.type] || ['تماس', 'phone']; const ok = e.status === 'answered'; icon = ct[1]; t = `<b>${ct[0]}</b> · ${ok ? durF(e.talk) : '<span style="color:var(--err)">بی‌پاسخ</span>'}${by}`; sub = e.wait ? 'انتظار ' + durF(e.wait) : '';
      extra = (e.rec ? `<div class="tl-rec"><button class="btn btn-sm" data-act="tl-play" data-id="${e.id}">${ic('play')}پخش مکالمه</button></div>` : '') + (e.transcript ? `<details style="margin-top:4px"><summary class="xs" style="cursor:pointer">متن مکالمه</summary><div class="small soft" style="white-space:pre-wrap;margin-top:4px">${E(e.transcript)}</div></details>` : ''); }
    else if (e.k === 'stage') { icon = 'list-bullets'; t = `پیگیری شماره: ${E(STG[e.from] || e.from || '—')} ← <b>${E(STG[e.to] || e.to || '')}</b>`; sub = (e.auto ? 'خودکار' : '') + by + (e.note ? ' · ' + E(e.note) : ''); }
    else if (e.k === 'lead') { icon = 'funnel'; t = `سرنخ ثبت شد: <b>${E(e.title || '')}</b>`; sub = E(e.status || '') + by + (e.note ? ' · منبع: ' + E(e.note) : ''); }
    else if (e.k === 'lead_st') { icon = 'funnel'; t = `وضعیت سرنخ: ${E(e.from || '—')} ← <b>${E(e.to || '')}</b>`; sub = (e.by ? E(e.by) : '') + (e.note ? ' · ' + E(e.note) : ''); }
    else if (e.k === 'ticket') { icon = 'flag'; t = `تیکت: <b>${E(e.title || '')}</b>`; sub = E(e.status || '') + by + (e.note ? ' · ' + E(e.note) : ''); }
    else if (e.k === 'note') { icon = 'note'; t = `یادداشت CRM: ${E(String(e.title || '').slice(0, 400))}`; sub = e.by ? E(e.by) : ''; }
    else if (e.k === 'inquiry') { icon = 'envelope-simple'; t = `استعلام سایت: <b>${E(e.title || '')}</b>`; sub = E(e.status || '') + (e.note ? ' · ' + E(e.note) : ''); }
    else if (e.k === 'sale') { icon = 'trend-up'; t = `رزرو سایت: <b>${E(e.title || '')}</b> · ${nf(e.amount || 0)} تومان`; sub = E(e.status || '') + (e.note ? ' · ' + E(e.note) : ''); }
    return sep + `<div class="li" style="align-items:flex-start">${icon ? `<span class="chi" style="background:var(--surface-2,#f3f3f0);color:var(--ink-2)">${ic(icon)}</span>` : chi(e.ch)}<div class="grow" style="min-width:0"><div class="row" style="gap:6px;align-items:flex-start"><div class="small grow" style="min-width:0">${t}</div>${time}</div>${sub ? `<div class="xs muted">${sub}</div>` : ''}${extra}</div></div>`;
  }).join('');
  return head + `<div class="card flat" style="padding:0 12px 8px">${rows}</div>${list.length > 400 ? '<p class="xs muted">۴۰۰ مورد آخر نشان داده شد.</p>' : ''}`;
}
A['tl-play'] = el => { const box = el.parentElement; box.innerHTML = `<audio controls autoplay preload="none" style="width:100%;max-width:420px;margin-top:4px" src="crm/api.php/play-recording?id=${+el.dataset.id}"></audio>`; };

/* پروفایل کامل */
A['person-open'] = async el => {
  const id = +String(el.dataset.id).replace(/^\D+/, '');
  const Dw = drawer({title:'', size:'xl', body:`<div style="padding:18px">${EB.sk(10)}</div>`});
  const r = await L().api('person_profile', {id});
  if (!r.ok) { $('.m-b', Dw.el).innerHTML = empty('warning-circle', 'پروفایل باز نشد', E(r.error)); return; }
  const c = L().mapContact(r.person); let tab = el.dataset.tab || 'over'; let TL = null, tlF = '';
  Dw.el.querySelector('.m-h').innerHTML = `${av(c.name, {size:'lg'})}<div class="grow" style="min-width:0"><div class="row wrap" style="gap:6px"><h3 style="font-size:20px">${E(c.name)}</h3>${c.member ? pill('عضو', 'ok', 'seal-check') : pill('ناشناس', '', 'question')}${c.vip ? pill('VIP', 'gold', 'crown-simple') : ''}${c.blocked ? pill('بلاک', 'err') : ''}</div><div class="small muted">${c.phone ? fa(c.phone) + ' · ' : ''}عضو از ${L().faDate(r.person.created_at)}</div></div>
    <div class="row wrap">${D.pageAllow.includes('inbox') && c.recv ? `<button class="btn btn-sm" data-act="pd-chat" data-id="${c.rid}">${ic('chat-circle-text')}گفتگو</button>` : ''}<a class="btn btn-sm hide-m" href="index.php?export=messages&person_id=${c.rid}">${ic('download-simple')}CSV</a><button class="btn btn-g btn-ic btn-sm" data-close aria-label="بستن">${ic('x')}</button></div>`;
  const paintD = () => {
    const T = [['over', 'نمای کلی', 'user'], ['tl', 'همه‌ی سوابق', 'clock-counter-clockwise'], ['msgs', 'پیام‌ها', 'chats-circle', (r.person.messages || []).length], ['beh', 'فرم، کلیک و کمپین', 'cursor-click']];
    let b = '';
    if (tab === 'tl') {
      if (!TL) { b = `<div style="padding:4px 0">${EB.sk(8)}</div>`; L().api('crm_timeline', {person_id:c.rid}).then(x => { TL = x.ok ? x : {events:[], error:x.error}; if (tab === 'tl') paintD(); }); }
      else b = tlHtml(r.person.messages || [], TL, tlF);
    } else
    if (tab === 'over') b = `<div class="grid g-main" style="gap:20px">
      <div class="stack s20"><div class="grid g4" style="gap:8px">${[['پیام ارسالی', fa(c.sent)], ['پیام دریافتی', fa(c.recv)], ['نرخ پاسخ', c.sent ? fa(c.rr) + '٪' : '-'], ['کانال اصلی', r.person.top_channel ? D.CH[L().chKey(r.person.top_channel)].name : '-']].map(s => `<div class="well"><div class="xs muted">${s[0]}</div><b class="tnum" style="font-size:16px">${s[1]}</b></div>`).join('')}</div>
        <div class="fg">${field('نام', `<input class="inp" id="pdName" value="${E(r.person.full_name || '')}">`)}${field('موبایل', `<input class="inp ltr" id="pdPhone" inputmode="tel" value="${E(c.phone)}" placeholder="09121234567">`, 'با هر قالبی (+98، 0098، 98) وارد شود، به‌صورت 09… ذخیره می‌شود؛ اگر مشتری دیگری همین شماره را دارد، یکی می‌شوند', '', 'شماره‌ی موبایل معتبر نیست')}</div>
        ${field('یادداشت داخلی', `<textarea class="inp" id="pdNote" rows="3" placeholder="فقط تیم می‌بیند">${E(c.note)}</textarea>`)}
        <div class="row">${D.me.can_delete ? `<button class="btn btn-d" data-act="pd-del" data-id="${c.rid}">${ic('trash')}حذف مخاطب</button>` : ''}<button class="btn btn-p ms-auto" data-act="pd-save" data-id="${c.rid}">ذخیره‌ی تغییرات</button></div></div>
      <div class="stack s20">${EB.crmBox && (window.EB_BOOT || {}).crm && EB_BOOT.crm.ready ? `<div><div class="lab" style="margin-bottom:8px">سوابق CRM</div>${EB.crmBox(c.phone, c.rid)}</div>` : ''}<div><div class="lab" style="margin-bottom:8px">هویت‌ها</div><div class="stack s6">${c.idents.length ? c.idents.map(i => `<div class="ident">${chi(i.ch)}<div class="grow" style="min-width:0"><div class="b ell ltr" style="text-align:right">${E(i.handle)}</div><div class="xs muted">${D.CH[i.ch].name} · آخرین فعالیت ${rel(i.last)}</div>${i.bio ? `<div class="xs soft">${E(i.bio)}</div>` : ''}</div></div>`).join('') : '<span class="muted small">هویتی ندارد</span>'}</div></div>
        <div><div class="lab" style="margin-bottom:8px">گروه‌ها</div><div class="row wrap">${c.groups.map(g => tagHtml(g)).join('') || '<span class="muted small">در گروهی نیست</span>'}</div></div>
        <div><div class="lab" style="margin-bottom:8px">برچسب‌ها</div><div class="row wrap">${c.tags.map(g => tagHtml(g)).join('') || '<span class="muted small">بدون برچسب</span>'}</div></div></div></div>`;
    else if (tab === 'msgs') { const ms = (r.person.messages || []).slice(-60).reverse(); b = ms.length ? `<div class="card flat">${ms.map(m => `<div class="li">${chi(L().chKey(m.channel))}<div class="grow" style="min-width:0"><div class="small ${m.direction === 'in' ? 'b' : 'soft'}">${m.direction === 'in' ? '' : 'ما: '}${E(String(m.body || '').slice(0, 200))}</div><div class="xs muted">${L().faDate(m.created_at, {day:'numeric', month:'long', hour:'2-digit', minute:'2-digit'})}${m.status === 'failed' ? ' · <span style="color:var(--err)">ناموفق</span>' : ''}</div></div></div>`).join('')}</div>` : empty('chats-circle', 'پیامی ندارد', 'هنوز پیامی با این مشتری رد و بدل نشده.'); }
    else b = `<div class="grid g3">${[['list-checks', 'پاسخ فرم‌ها', (r.person.form_responses || []).map(f => [f.form_name || 'فرم', L().faDate(f.created_at)])], ['cursor-click', 'کلیک لینک‌ها', (r.person.link_clicks || []).map(k => [k.title || k.target_url, L().faDate(k.clicked_at)])], ['megaphone', 'کمپین‌ها', (r.person.campaigns || []).map(k => [k.campaign_name + (k.step_order ? ' · مرحله ' + fa(k.step_order) : ''), +k.responded ? 'پاسخ داد' : 'پاسخ نداده'])]].map(x => `<div class="card flat"><div class="card-h">${ic(x[0])}<h3>${x[1]}</h3></div>${x[2].length ? x[2].map(rw => `<div class="li"><div class="grow"><div class="t small">${E(rw[0])}</div><div class="s">${E(rw[1])}</div></div></div>`).join('') : '<div class="card-b muted small">موردی ثبت نشده</div>'}</div>`).join('')}</div>`;
    $('.m-b', Dw.el).innerHTML = `<div style="padding:0 18px">${tabs(T, tab, 'pd-tab')}</div><div style="padding:18px">${b}</div>`;
  };
  A['pd-tab'] = b => { tab = b.dataset.v; paintD(); };
  A['tl-f'] = b => { tlF = b.dataset.v; paintD(); };
  paintD();
};
A['pd-save'] = async el => {
  const ph = $('#pdPhone'), pv = ph ? ph.value.replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[\s\-.()]/g, '') : null;
  const bad = ph && pv && !/^(\+98|0098|98|0)?9\d{9}$/.test(pv); if (ph) ph.closest('.field').classList.toggle('has-err', !!bad); if (bad) return;
  const data = {id:+el.dataset.id, full_name:$('#pdName').value.trim(), notes:$('#pdNote').value}; if (ph) data.phone = pv;
  const r = await L().api('person_update', data); if (!r.ok) return toast(r.error, 'err');
  toast(r.merged ? 'شماره ثبت شد و با مشتری دارای همین شماره یکی شد' : 'پروفایل ذخیره شد');
  if (r.merged) document.querySelector('.drawer [data-close]')?.click();
  if (EB.current().slug === 'persons') fetchPersons(); };
A['pd-del'] = el => confirm({title:'حذف مخاطب', text:'مخاطب و همه‌ی هویت‌هایش حذف می‌شود. این کار قابل بازگشت نیست.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('person_delete', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err'); document.querySelector('.drawer [data-close]')?.click(); toast('حذف شد'); if (EB.current().slug === 'persons') fetchPersons(); }});
A['pd-chat'] = el => { document.querySelector('.drawer [data-close]')?.click(); go('inbox.v' + el.dataset.id); };

/* گروه و برچسب */
reg('groups', {title:'گروه و برچسب', icon:'tag',
async load() { await L().refreshMeta(); },
render(el) {
  el.innerHTML = `<div class="ph"><div><h2>گروه و برچسب</h2><p>گروه برای ارسال هدفمند و محدود کردن دسترسی اپراتورها؛ برچسب برای دسته‌بندی آزاد و چندگانه‌ی مشتری و گفتگو.</p></div><div class="acts"><button class="btn" data-act="tag-new">${ic('tag')}برچسب جدید</button><button class="btn btn-p" data-act="grp-new">${ic('plus')}گروه جدید</button></div></div>
  <div class="grid g-main"><div class="card"><div class="card-h"><h3>گروه‌ها</h3><span class="sub">${fa(D.groups.length)} گروه</span></div>
    ${D.groups.length ? D.groups.map(g => `<div class="li"><span class="chi md" style="background:${g.color}">${ic('users-three')}</span><div class="grow"><div class="t">${E(g.name)}</div><div class="s">${E(g.desc || 'بدون توضیح')}</div></div><div style="text-align:left"><b class="tnum">${nf(g.count)}</b><div class="xs muted">عضو</div></div><button class="btn btn-g btn-ic btn-sm" data-act="grp-menu" data-id="${g.id}" data-pop aria-label="بیشتر">${ic('dots-three-vertical')}</button></div>`).join('') : empty('users-three', 'هنوز گروهی نساخته‌اید', 'مثلاً «مشتریان VIP» یا «عمده‌فروشان» برای ارسال هدفمند.', `<button class="btn btn-sm" data-act="grp-new">${ic('plus')}گروه جدید</button>`)}</div>
    <div class="card"><div class="card-h"><h3>برچسب‌ها</h3><span class="sub">برای مشتری و گفتگو</span></div>
    ${D.tags.length ? D.tags.map(t => `<div class="li"><i class="dot" style="background:${t.color};width:10px;height:10px"></i><span class="grow">${E(t.name)}</span><span class="muted small tnum">${fa(t.count)} مشتری</span><button class="btn btn-g btn-ic btn-sm" data-act="tag-del" data-id="${t.id}" aria-label="حذف برچسب">${ic('trash')}</button></div>`).join('') : empty('tag', 'برچسبی نیست', 'برچسب‌ها مثل «سؤال سایز» یا «پیگیری سفارش» به دسته‌بندی گفتگوها کمک می‌کنند.')}</div></div>`;
}});
const COLORS = ['#3D6A9E', '#2F7A5E', '#A35A2A', '#6B4F8A', '#A33B62', '#2E6670', '#8A6A22', '#5E6A66', '#A8433A'];
const colorPick = cur => `<div class="swatches" id="clrs">${COLORS.map(c => `<button type="button" class="swatch ${c === cur ? 'on' : ''}" style="background:${c}" data-act="clr" data-v="${c}" aria-label="رنگ ${c}">${c === cur ? ic('check') : ''}</button>`).join('')}</div>`;
A['clr'] = el => { $$('#clrs .swatch').forEach(s => { s.classList.toggle('on', s === el); s.innerHTML = s === el ? ic('check') : ''; }); };
A['grp-new'] = () => { const M = modal({title:'گروه جدید', icon:'users-three', body:`<div class="stack s16">${field('نام گروه', '<input class="inp" id="gName" placeholder="مثلاً: مشتریان شیراز">', '', '', 'نام گروه لازم است')}${field('توضیح', '<input class="inp" id="gDesc" placeholder="اختیاری">')}<div class="field"><label>رنگ</label>${colorPick(COLORS[0])}</div></div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ساخت گروه</button>'});
  M.el.querySelector('[data-ok]').onclick = async () => { const n = $('#gName').value.trim(); if (!n) { $('#gName').closest('.field').classList.add('has-err'); return; } const r = await L().api('group_create', {name:n, color:$('#clrs .on')?.dataset.v || COLORS[0], description:$('#gDesc').value.trim()}); if (!r.ok) return toast(r.error, 'err'); M.close(); toast('گروه «' + E(n) + '» ساخته شد'); EB.route(true); }; };
A['tag-new'] = () => { const M = modal({title:'برچسب جدید', size:'sm', icon:'tag', body:field('نام برچسب', '<input class="inp" id="tName" placeholder="مثلاً: سؤال سایز">'), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ساخت</button>'});
  M.el.querySelector('[data-ok]').onclick = async () => { const n = $('#tName').value.trim(); if (!n) return; const r = await L().api('tag_create', {name:n}); if (!r.ok) return toast(r.error, 'err'); M.close(); toast('برچسب ساخته شد'); EB.route(true); }; };
A['tag-del'] = el => { const t = D.tags.find(x => x.id === el.dataset.id); confirm({title:'حذف برچسب ' + E(t.name), text:'برچسب از همه‌ی مشتریان برداشته می‌شود.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('tag_delete', {id:t.rid}); if (!r.ok) return toast(r.error, 'err'); toast('برچسب حذف شد'); EB.route(true); }}); };
A['grp-menu'] = el => { const g = D.groups.find(x => x.id === el.dataset.id); menu(el, [{t:'دیدن اعضا', icon:'users', fn:() => { F.group = g.id; go('persons'); }}, ...(D.pageAllow.includes('send') ? [{t:'ارسال پیام به این گروه', icon:'paper-plane-tilt', fn:() => go('send')}] : []), '-', {t:'حذف گروه', icon:'trash', danger:true, fn:() => confirm({title:'حذف گروه ' + E(g.name), text:'مشتریان حذف نمی‌شوند؛ فقط از این گروه خارج می‌شوند.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('group_delete', {id:g.rid}); if (!r.ok) return toast(r.error, 'err'); toast('گروه حذف شد'); EB.route(true); }})}]); };
})();
