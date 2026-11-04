(function(){
const {$, E, fa, nf, pct, compact, rel, ic, av, chi, pill, chartBox, spark, hbars, A, UI, reg, go, op, todayFa, toast, store, delta, empty} = EB;
const L = () => window.EB_LIVE;
let DS = {};

reg('dashboard', {title:'داشبورد', icon:'squares-four',
async load() {
  const crmOn = !!((window.EB_BOOT || {}).crm && EB_BOOT.crm.ready);
  const [st, act, setup, cnt, crm] = await Promise.all([L().api('stats_overview'), L().api('recent_activity'), L().api('setup_status'), L().api('desk_counts'), crmOn ? L().api('crm_kpis').catch(() => ({})) : Promise.resolve({})]);
  if (!st.ok) throw new Error(st.error);
  if (cnt.ok) D.counts = cnt.counts;
  DS = {stats:st.stats, activity:act.ok ? act.activity : [], setup:setup.ok ? setup : null, crm:crm && crm.ok ? crm.kpis : null};
},
render(el){
  const me = op(D.meId) || {name:'کاربر'}; const h = new Date().getHours();
  const hi = h < 12 ? 'صبح بخیر' : h < 17 ? 'روز بخیر' : 'عصر بخیر';
  const s = DS.stats || {}; const c = D.counts || {};
  const online = D.ops.filter(o => o.status === 'online').length;
  // روند ۷ روز: پر کردن روزهای بدون پیام
  const days = [], sent = [], recv = [];
  for (let i = 6; i >= 0; i--) {
    const d = new Date(); d.setDate(d.getDate() - i);
    const key = d.toISOString().slice(0, 10);
    const row = (s.daily || []).find(x => String(x.d).slice(0, 10) === key) || {};
    days.push(L().faDate(key + ' 12:00:00', {day:'numeric', month:'short'})); sent.push(+row.sent || 0); recv.push(+row.received || 0);
  }
  const totalId = (s.by_channel || []).reduce((a, r) => a + (+r.c || 0), 0);
  const byCh = (s.by_channel || []).slice(0, 7).map(r => { const k = L().chKey(r.channel); return {l:`<span class="row" style="gap:6px">${chi(k)}${D.CH[k].name}</span>`, v:+r.c, c:D.CH[k].color}; });
  const attention = [];
  if (c.sla) attention.push({ic:'timer', c:'err', t:`${fa(c.sla)} گفتگو بیش از ۲۰ دقیقه منتظر پاسخ است`, s:'مهلت پاسخ (SLA) در حال عبور است', btn:['نمایش', 'inbox.sla']});
  if (c.unassigned) attention.push({ic:'user-circle-dashed', c:'warn', t:`${fa(c.unassigned)} گفتگوی باز بدون اپراتور`, s:'از صندوق ورودی به همکاران بسپارید', btn:['سپردن', 'inbox.unassigned']});
  if (c.mentions) attention.push({ic:'at', c:'info', t:`${fa(c.mentions)} گفتگو که در آن منشن شده‌اید`, s:'یادداشت‌های داخلی ۱۴ روز اخیر', btn:['دیدن', 'inbox.mentions']});
  if (s.failed) attention.push({ic:'warning-octagon', c:'err', t:`${fa(s.failed)} پیام ناموفق در کل تاریخچه`, s:`نرخ تحویل ${pct(s.delivery_rate, 0)}`, btn:['جزئیات', 'sendlog']});
  const setup = DS.setup; const showSetup = setup && !setup.complete && !store.get('setupHidden', false);
  el.innerHTML = `
  <section class="band"><div><h2>${hi}، ${E(me.name.split(' ')[0])}</h2><p>${todayFa()} · ${fa(online)} همکار آنلاین${D.support && D.support.online === false ? ' · خارج از ساعت کاری' : ''}</p>
      <div class="row wrap" style="margin-top:14px"><button class="btn btn-sm" data-act="go" data-to="inbox">${ic('tray')}صندوق ورودی</button>${D.pageAllow.includes('stats') ? `<button class="btn btn-sm" data-act="go" data-to="stats">${ic('chart-bar')}آمار کامل</button>` : ''}</div></div>
    <div class="bstats">${[[fa(c.all || 0), 'گفتگوی باز'], [fa(c.unread_convs || 0), 'منتظر پاسخ'], [fa(c.mine || 0), 'سپرده به شما']].map(b => `<div class="bstat"><span class="bn tnum">${b[0]}</span><span class="bl">${b[1]}</span></div>`).join('')}</div></section>
  ${showSetup ? `<div class="card" style="margin-bottom:16px" id="setupCard"><div class="card-h"><span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('rocket-launch')}</span><div><h3>راه‌اندازی اعلان‌بان</h3><div class="sub">${fa(setup.done)} از ${fa(setup.total)} مرحله انجام شده</div></div><div class="acts"><div class="bar" style="width:140px"><i style="width:${setup.done / setup.total * 100}%"></i></div><button class="btn btn-g btn-sm" data-act="setup-hide">بستن</button></div></div>
    <div class="card-b" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:0 24px">${setup.steps.map(st => `<a class="check-i ${st.done ? 'done' : ''}" href="#${st.page}" style="color:inherit;text-decoration:none"><span class="ck">${ic('check')}</span><span class="t grow">${E(st.title)}<br><span class="xs muted" style="text-decoration:none">${E(st.desc)}</span></span>${st.done ? '' : ic('arrow-left', 'muted')}</a>`).join('')}</div></div>` : ''}
  <div class="kpis" style="margin-bottom:16px">
    <div class="kpi"><span class="k-l">${ic('users-three')}مشتریان</span><span class="k-v">${nf(s.persons || 0)}</span><span class="k-d">${fa(s.new_30d || 0)} نفر در ۳۰ روز اخیر</span></div>
    <div class="kpi"><span class="k-l">${ic('seal-check')}عضو (دارای شماره)</span><span class="k-v">${nf(s.members || 0)}</span><span class="k-d">نرخ عضویت ${pct(s.persons ? s.members / s.persons * 100 : 0, 0)}</span></div>
    <div class="kpi"><span class="k-l">${ic('chat-circle-dots')}پیام دریافتی</span><span class="k-v">${nf(s.received || 0)}</span><span class="k-d">${fa(recv.reduce((a, b) => a + b, 0))} پیام در ۷ روز</span>${spark(recv.some(Boolean) ? recv : [0, 0, 0, 0, 0, 0, 1])}</div>
    <div class="kpi"><span class="k-l">${ic('paper-plane-tilt')}ارسال موفق</span><span class="k-v">${nf(s.sent || 0)}</span><span class="k-d">نرخ تحویل ${pct(s.delivery_rate || 0, 0)} · پاسخ‌دهی ${pct(s.response_rate || 0, 0)}</span>${spark(sent.some(Boolean) ? sent : [0, 0, 0, 0, 0, 0, 1], 'var(--ok)')}</div>
  </div>
  ${EB.crmKpiRow ? EB.crmKpiRow(DS.crm) : ''}
  <div class="grid g-main" style="margin-bottom:16px">
    <div class="card"><div class="card-h"><h3>حجم پیام‌ها</h3><span class="sub">۷ روز اخیر، همه‌ی کانال‌ها</span><div class="acts legend"><span><i style="background:var(--accent)"></i>ارسالی</span><span><i style="background:var(--ink-4)"></i>دریافتی</span></div></div>
      <div class="card-b">${sent.concat(recv).some(Boolean) ? chartBox({labels:days, series:[{name:'ارسالی', data:sent}, {name:'دریافتی', data:recv}], aria:'نمودار پیام‌های ۷ روز اخیر'}, 240) : empty('chart-line', 'هنوز پیامی ثبت نشده', 'وقتی اولین پیام‌ها ارسال یا دریافت شوند، روند اینجا نمایش داده می‌شود.')}</div></div>
    <div class="card"><div class="card-h"><h3>نیاز به توجه</h3>${attention.length ? `<span class="pill err">${fa(attention.length)}</span>` : ''}</div>
      <div>${attention.length ? attention.map(a => `<div class="li"><span class="chi md" style="background:var(--${a.c}-soft);color:var(--${a.c})">${ic(a.ic)}</span><div class="grow"><div class="t">${a.t}</div><div class="s ell">${a.s}</div></div><button class="btn btn-sm" data-act="go" data-to="${a.btn[1]}">${a.btn[0]}</button></div>`).join('') : empty('check-circle', 'همه‌چیز مرتب است', 'گفتگوی عقب‌افتاده یا بی‌اپراتوری وجود ندارد.')}</div></div>
  </div>
  <div class="grid g-main">
    <div class="card"><div class="card-h"><h3>آخرین فعالیت‌ها</h3><span class="pill ok">${ic('circle')}زنده</span></div><div class="card-b" style="padding-block:4px">
      ${(DS.activity || []).length ? DS.activity.slice(0, 12).map(a => { const k = L().chKey(a.channel); return `<div class="feed-i" ${a.person_id ? `data-act="go" data-to="inbox.v${a.person_id}" style="cursor:pointer"` : ''}>${chi(k)}<span class="grow ell"><b>${E(a.full_name || 'مشتری')}</b> ${a.direction === 'in' ? 'نوشت' : 'دریافت کرد'}: ${E(String(a.body || '').replace(/\s+/g, ' ').slice(0, 80))}</span><span class="t">${rel(L().minsAgo(a.created_at))}</span></div>`; }).join('') : empty('pulse', 'فعالیتی ثبت نشده', 'پیام‌های ورودی و خروجی اینجا نمایش داده می‌شوند.')}
    </div></div>
    <div class="stack s16">
      <div class="card"><div class="card-h"><h3>هویت‌ها در کانال‌ها</h3><span class="sub">${nf(totalId)} هویت</span></div><div class="card-b">${byCh.length ? hbars(byCh) : '<span class="muted small">هنوز هویتی ثبت نشده</span>'}</div></div>
      <div class="card"><div class="card-h"><h3>تیم</h3>${D.me.is_admin ? `<div class="acts"><button class="btn btn-g btn-sm" data-act="go" data-to="settings.operators">مدیریت</button></div>` : ''}</div>
        ${D.ops.filter(o => o.active !== false).slice(0, 8).map(o => `<div class="li">${av(o.name, {color:o.color, size:'sm', st:o.status})}<div class="grow"><div class="t">${E(o.name)}</div><div class="s">${E(o.title || '')}</div></div><span class="xs muted">${o.status === 'online' ? 'آنلاین' : o.status === 'busy' ? 'مشغول' : o.status === 'away' ? 'غایب' : E(o.last)}</span></div>`).join('')}</div>
    </div>
  </div>`;
}});
A['setup-hide'] = () => { store.set('setupHidden', true); $('#setupCard')?.remove(); };
})();
