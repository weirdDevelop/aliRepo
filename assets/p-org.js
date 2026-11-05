/* p-org.js — 2.4.0: چارت سازمانی (فقط‌خواندنی برای همه) + ابزار مشترک نمایش درخت واحدها */
(function(){
const {$, E, fa, ic, pill, empty, A, reg, toast} = EB;
const L = () => window.EB_LIVE;
const TYPE = {division:'معاونت', department:'دپارتمان', team:'تیم', unit:'واحد'};
const ROLE = {manager:'مدیر', deputy:'معاون', member:'عضو'};
const jd = iso => iso ? (window.JD ? JD.toJ(iso) : iso) : '';
const S = {date:null, data:null, chain:[]};

function build(units) {
  const by = {}, roots = [];
  units.forEach(u => { u.children = []; by[u.id] = u; });
  units.forEach(u => { if (u.parent_id && by[u.parent_id]) by[u.parent_id].children.push(u); else roots.push(u); });
  return roots;
}
function node(u, depth, opts) {
  const mgr = u.acting_manager && u.acting_manager.user ? `${pill('سرپرست موقت: ' + E(u.acting_manager.user.name), 'warn', 'user-switch')}` : (u.manager ? pill('مدیر: ' + E(u.manager.name), 'info', 'crown-simple') : '<span class="xs muted">بدون مدیر</span>');
  const others = u.members.filter(m => m.role !== 'manager');
  return `<div class="org-node" data-unit="${u.id}" style="margin-inline-start:${depth * 22}px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;margin-top:8px;background:var(--card)${u.status === 'archived' ? ';opacity:.55' : ''}">
    <div class="row wrap" style="gap:8px">${ic(u.type === 'team' ? 'users-three' : 'tree-structure')}<b>${E(u.name)}</b>${pill(TYPE[u.type] || u.type)}${u.status === 'archived' ? pill('بایگانی', 'warn') : ''}${mgr}
      <span class="xs muted">${fa(u.members.length)} نفر</span>${opts.manage ? `<button class="btn btn-sm ms-auto" data-act="org-open" data-id="${u.id}">${ic('pencil-simple')}مدیریت</button>` : ''}</div>
    ${opts.members && others.length ? `<div class="xs" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px">${others.map(m => `<span>${E(m.user.name)}${m.role === 'deputy' ? ' <span class="muted">(معاون)</span>' : ''}</span>`).join('<span class="muted">·</span>')}</div>` : ''}
  </div>${u.children.map(c => node(c, depth + 1, opts)).join('')}`;
}
EB.orgTree = (units, opts = {}) => {
  const roots = build(units.map(u => Object.assign({}, u)));
  return roots.length ? `<div class="org-tree">${roots.map(r => node(r, 0, opts)).join('')}</div>`
    : empty('tree-structure', 'هنوز واحدی تعریف نشده', opts.emptyText || 'مدیر می‌تواند در «تنظیمات ← واحدهای سازمانی» واحدها را بسازد یا ساختار فعلی را وارد کند.');
};
Object.assign(EB, {orgTypes:TYPE, orgRoles:ROLE, jd});

function page() {
  const d = S.data, chain = S.chain;
  return `<div class="ph"><div><h2>چارت سازمانی</h2><p>واحدها، مدیران و اعضا در تاریخ ${E(jd(d.date))}</p></div>
    <div class="acts"><input class="inp inp-sm" id="orgDate" value="${E(jd(d.date))}" data-act="org-date" readonly style="width:130px" title="نمایش در تاریخ دیگر"></div></div>
  ${chain.length ? `<div class="card" style="margin-top:12px"><div class="card-b small">${ic('arrow-bend-up-left')} گزارش‌دهی شما: ${chain.map(c => `<b>${E(c.user.name || ('#' + c.user.id))}</b>${c.acting ? ' <span class="muted">(موقت)</span>' : ''}`).join(' ← ')}</div></div>` : ''}
  <div style="margin-top:12px">${EB.orgTree(d.units, {members:true})}</div>`;
}
async function load() {
  const [t, c] = await Promise.all([L().v2('GET', '/v2/org/units' + (S.date ? '?date=' + S.date : '')), L().v2('GET', '/v2/org/me/reporting-chain' + (S.date ? '?date=' + S.date : ''))]);
  if (!t.ok) throw new Error(t.error);
  S.data = t.data; S.chain = c.ok ? c.data : [];
}
reg('org', {title:'چارت سازمانی', icon:'tree-structure', perm:'dashboard',
  async load() { await load(); },
  render(el) { el.innerHTML = page(); }});
A['org-date'] = el => JD.picker(el, async iso => {
  S.date = iso;
  try { await load(); const box = $('.page'); if (box) box.innerHTML = page(); } catch (e) { toast(e.message, 'err'); }
});
})();
