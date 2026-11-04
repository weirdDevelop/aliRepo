/* p-agent.js — دستیار فروش هوش مصنوعی + اتصال‌های API (جاکجاست و وب‌سرویس‌های مشابه) */
(function(){
const {$, $$, E, fa, nf, ic, pill, sw, seg, tabs, empty, field, A, ON, reg, go, toast, modal, drawer, confirm, copy, store} = EB;
const L = () => window.EB_LIVE;
const S = {tab:store.get('agTab', 'home'), d:null, sim:[], simBusy:false, simProfile:0, log:null, orders:null, logF:{kind:'', ok:''}, sec:null, surveys:null};
const TABS = [['home', 'وضعیت و تنظیمات', 'robot'], ['profiles', 'برندها و پیج‌ها', 'user-switch'], ['social', 'دایرکت و کامنت', 'instagram-logo'], ['kb', 'پایگاه دانش', 'list-checks'], ['conn', 'اتصال‌های API', 'plugs-connected'],
  ['life', 'پیگیری و نظرسنجی', 'paper-plane-tilt'], ['sec', 'امنیت', 'shield-check'], ['sim', 'آزمایش گفتگو', 'chat-circle-dots'], ['orders', 'سفارش‌ها', 'list-checks'], ['demand', 'تحلیل تقاضا', 'chart-bar'], ['log', 'گزارش کارها', 'flask']];
const CHANS = [['instagram', 'اینستاگرام (همه‌ی پیج‌های بدون پروفایل)'], ['telegram', 'تلگرام'], ['bale', 'بله'], ['roubika', 'روبیکا'], ['webchat', 'گفتگوی سایت'], ['sms', 'پیامک']];
const LINKM = {link:'همه‌ی لینک‌ها', limited:'حداکثر چند لینک در هر پیام', code:'فقط کد (بدون لینک)'};
const KBM = {both:'عمومی + خصوصی', general:'فقط عمومی', own:'فقط خصوصی همین برند', none:'بدون پایگاه دانش'};
const val = id => { const el = document.getElementById(id); return el ? (el.type === 'checkbox' ? el.checked : el.value.trim()) : ''; };
const toEn = s => String(s || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));
const kpi = (l, v, s) => `<div class="kpi"><div class="k-l">${l}</div><div class="k-v">${v}</div>${s ? `<div class="k-s xs muted">${s}</div>` : ''}</div>`;
const pre = o => `<pre class="ltr mono" style="white-space:pre-wrap;word-break:break-word;font-size:12px;max-height:340px;overflow:auto;background:var(--bg-2,rgba(0,0,0,.04));padding:10px;border-radius:8px;margin:0">${E(typeof o === 'string' ? o : JSON.stringify(o, null, 2))}</pre>`;
async function run(btn, fn) { if (btn) { btn.classList.add('loading'); btn.disabled = true; } try { return await fn(); } finally { if (btn && btn.isConnected) { btn.classList.remove('loading'); btn.disabled = false; } } }

reg('agent', {title:'دستیار فروش', icon:'robot', sub:'پاسخ‌گویی و فروش خودکار در همه‌ی کانال‌ها', perm:'ai', admin:true, wide:true,
  async load() { await loadMain(); },
  render(el) { el.innerHTML = `<div class="page" id="agPage">${page()}</div>`; after(); }});

async function loadMain() {
  const r = await L().api('agent_get');
  if (!r.ok) throw new Error(r.error || 'بارگذاری نشد');
  S.d = r;
}
function paint() { const b = $('#agPage'); if (b) { b.innerHTML = page(); after(); } }
async function refresh() { try { await loadMain(); paint(); } catch (e) { toast(e.message, 'err'); } }

function page() {
  const d = S.d, st = d.settings, on = st.enabled === '1';
  return `<div class="ph"><div><h2>دستیار فروش هوش مصنوعی</h2><p>${on ? 'روشن است: همه‌ی گفتگوها در همه‌ی کانال‌ها را جواب می‌دهد، جستجو و استعلام می‌کند و بعد از تأیید مشتری سفارش ثبت می‌کند.' : 'خاموش است. بعد از تنظیم اتصال و آزمایش گفتگو، روشنش کنید.'}</p></div>
    <div class="acts">${on ? pill('روشن', 'ok', 'check-circle') : pill('خاموش', 'warn')}</div></div>
  ${!d.ai_ready ? `<div class="callout warn" style="margin-bottom:14px">${ic('warning')}<span>هیچ پروایدر هوش مصنوعی فعالی نیست. اول در <a href="#ai">هوش مصنوعی ← تنظیمات</a> یک پروایدر (مثلاً GapGPT با مدل gpt-4o-mini یا gpt-4o) فعال کنید. مدل باید از «فراخوانی ابزار» (tools) پشتیبانی کند.</span></div>` : ''}
  <div style="margin-bottom:16px">${tabs(TABS.map(t => [t[0], t[1], t[2]]), S.tab, 'ag-tab')}</div>
  <div id="agBody">${body()}</div>`;
}
function body() {
  switch (S.tab) {
    case 'conn': return conn();
    case 'profiles': return profiles();
    case 'social': return social();
    case 'kb': return kbView();
    case 'life': return life();
    case 'sec': return secView();
    case 'sim': return sim();
    case 'orders': return orders();
    case 'demand': return demandView();
    case 'log': return logView();
    default: return home();
  }
}
function after() {
  if (S.tab === 'orders' && !S.orders) loadOrders();
  if (S.tab === 'social' && !S.posts) loadPosts();
  if (S.tab === 'demand' && !S.dm) loadDemand();
  if (S.tab === 'log' && !S.log) loadLog();
  if (S.tab === 'sec' && !S.sec) loadSec();
  if (S.tab === 'life' && !S.surveys) loadSurveys();
  if (S.tab === 'sim') { const b = $('#simBox'); if (b) b.scrollTop = b.scrollHeight; }
}
A['ag-funnel'] = async el => {
  const st = el.dataset.st, t = el.dataset.t;
  const r = await L().api('agent_funnel_list', {stage:st}); if (!r.ok) return toast(r.error || 'خطا', 'err');
  const rows = r.rows || [];
  const ST = {paid:['پرداخت‌شده','ok'], completed:['تکمیل','ok'], confirmed:['تأییدشده','ok']};
  const head = r.kind === 'order' ? '<th>مهمان</th><th>کد</th><th>اقامتگاه</th><th>وضعیت</th><th>مبلغ</th><th>زمان</th>' : '<th>مشتری</th><th>تعداد</th><th>آخرین</th><th>زمان</th>';
  const tr = x => r.kind === 'order'
    ? `<tr><td>${x.person_id ? `<a href="#inbox.v${+x.person_id}" data-close>${E(x.person || '—')}</a>` : E(x.person || '—')}${x.phone ? `<div class="xs muted ltr">${E(x.phone)}</div>` : ''}</td><td class="ltr xs">${E(x.ref || '')}</td><td class="small">${E(x.title || '—')}</td><td>${pill(E((ST[x.status] || [x.status || '—'])[0]), (ST[x.status] || [0, 'warn'])[1])}</td><td>${x.amount ? EB.toman(x.amount) : '—'}</td><td class="xs">${E(fa(x.at || ''))}</td></tr>`
    : `<tr><td>${x.person_id ? `<a href="#inbox.v${+x.person_id}" data-close>${E(x.person || '—')}</a>` : E(x.person || '—')}${x.phone ? `<div class="xs muted ltr">${E(x.phone)}</div>` : ''}</td><td>${fa(x.n || 1)}</td><td class="small">${E((x.summary || '').slice(0, 120))}</td><td class="xs">${E(fa(x.at || ''))}</td></tr>`;
  modal({title:t + ' — ۷ روز اخیر (' + fa(rows.length) + ')', icon:'funnel', size:'lg', body: rows.length
    ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr>${head}</tr></thead><tbody>${rows.map(tr).join('')}</tbody></table></div>`
    : '<div class="muted small" style="padding:16px">موردی نیست.</div>'});
};
A['ag-tab'] = el => { S.tab = el.dataset.v; store.set('agTab', S.tab); paint(); };

/* ======================= وضعیت و تنظیمات ======================= */
function home() {
  const d = S.d, st = d.settings, s = d.stats || {};
  const nCon = d.connectors.filter(c => +c.is_active).length;
  return `<div class="kpis" style="margin-bottom:16px">
      ${kpi('پیام‌های دستیار امروز', fa(nf(s.ai_msgs_today || 0)))}
      ${kpi('کار با وب‌سرویس امروز', fa(nf(s.tools_today || 0)), 'جستجو، استعلام، رزرو…')}
      ${kpi('سفارش‌های دستیار', fa(nf(s.orders || 0)), fa(nf(s.paid || 0)) + ' پرداخت‌شده')}
      ${kpi('منتظر همکار', fa(nf(s.waiting || 0)), 'گفتگوهایی که دستیار سپرده')}
    </div>
    ${(() => { const f = d.funnel || {}; const st2 = [['chats', 'گفتگو با دستیار'], ['offers', 'پیشنهاد گرفتند'], ['quotes', 'استعلام قیمت'], ['orders', 'سفارش ثبت شد'], ['paid', 'پرداخت شد']];
      return `<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('chart-line-up')}قیف فروش دستیار — ۷ روز اخیر</h3><div class="acts xs muted">${s.rating_avg != null ? '⭐ رضایت ' + fa(s.rating_avg) + ' از ۵ (' + fa(s.rating_n) + ' نظر)' : ''}${s.security_24h ? ` · <a href="#agent" data-act="ag-tab" data-v="sec" style="color:var(--danger,#b42318)">${fa(s.security_24h)} هشدار امنیتی در ۲۴ ساعت</a>` : ''}</div></div>
        <div class="card-b"><div class="row wrap" style="gap:8px;align-items:stretch">${st2.map((x, i) => `<div role="button" tabindex="0" data-act="ag-funnel" data-st="${x[0]}" data-t="${x[1]}" title="نمایش فهرست" style="cursor:pointer;flex:1;min-width:110px;padding:10px 12px;border-radius:10px;background:var(--accent-soft)"><div class="xs muted">${x[1]}</div><div style="font-size:20px;font-weight:700">${fa(nf(f[x[0]] || 0))}</div>${i && f[st2[i - 1][0]] ? `<div class="xs muted">${fa(Math.round(100 * (f[x[0]] || 0) / f[st2[i - 1][0]]))}٪ مرحله‌ی قبل</div>` : ''}</div>`).join('')}</div></div></div>`; })()}
    ${cbCard()}
    <div class="card"><div class="card-b stack s20">
      <label class="row">${sw('agOn', st.enabled === '1')}<span><b>دستیار فروش روشن باشد</b> — همه‌ی گفتگوهای همه‌ی کانال‌ها را خودش جواب بدهد</span></label>
      <label class="row">${sw('agLock', st.lock_operators === '1')}<span>پشتیبان‌ها نتوانند گفتگو را بر عهده بگیرند (فقط مدیر و سرپرست؛ یا گفتگویی که مدیر/سرپرست به پشتیبان بسپارد)</span></label>
      ${(() => { const on = (st.channels || '').split(',').filter(Boolean); const all = [['webchat', 'گفتگوی سایت'], ['instagram', 'اینستاگرام'], ['bale', 'بله'], ['telegram', 'تلگرام'], ['roubika', 'روبیکا'], ['sms', 'پیامک']];
        return field('کانال‌هایی که دستیار جواب می‌دهد', `<div class="row wrap" style="gap:6px 16px">${all.map(c => `<label class="row small"><input type="checkbox" class="cb" name="agCh" value="${c[0]}" ${on.includes(c[0]) ? 'checked' : ''}>${c[1]}</label>`).join('')}</div>`, 'هیچ‌کدام = همه‌ی کانال‌ها. قفل پشتیبان‌ها و پیگیری‌های خودکار هم فقط برای گفتگوهای همین کانال‌ها اعمال می‌شود.'); })()}
      <div class="callout">${ic('info')}<span>مدیر یا سرپرست با «بر عهده گرفتن» یا پاسخ دادن، گفتگو را از دستیار می‌گیرد و با «رها کردن» یا «سپردن به AI» دوباره به دستیار برمی‌گرداند. اگر مشتری انسان بخواهد، دستیار گفتگو را به همکاران می‌سپارد و اعلان می‌دهد. ${nCon ? fa(nCon) + ' اتصال فعال دارید.' : '<b>هنوز هیچ اتصال API فعالی ندارید؛ از تب «اتصال‌های API» جاکجاست را اضافه کنید.</b>'}</span></div>
      <div class="callout">${ic('user-switch')}<span>نام برند، شخصیت، پایگاه دانش و نحوه‌ی ارسال لینک برای هر پیج/کانال در تب <a href="#agent" data-act="ag-tab" data-v="profiles">برندها و پیج‌ها</a> تنظیم می‌شود.</span></div>
      <div class="card flat"><div class="card-h"><h3>${ic('users-three')}گفتگوهایی که مسئول دارند</h3></div><div class="card-b stack s12">
        <label class="row">${sw('agOwned', st.owned === '1')}<span>دستیار گفتگوهای دارای مسئول را هم جواب بدهد، وقتی مسئول
          <input class="inp inp-sm ltr" type="number" min="0" max="1440" id="agIdle" value="${E(st.owned_idle)}" style="max-width:70px;display:inline-block"> دقیقه جواب نداده باشد (۰ = بلافاصله)؛ در این صورت خودِ دستیار مسئول گفتگو می‌شود</span></label>
        <div class="row wrap"><button class="btn" data-act="ag-takeall">${ic('sparkle')}سپردن همه‌ی گفتگوهای همکاران به دستیار…</button><span class="xs muted">فقط گفتگوهای کانال‌هایی که دستیار جواب می‌دهد. مدیر یا سرپرست هر وقت بخواهد گفتگو را پس می‌گیرد.</span></div>
        <p class="xs muted" style="margin:0">پشتیبان‌ها و مدیرها هم از صندوق ورودی می‌توانند هر گفتگو را با «انتقال ← دستیار فروش» یا «سپردن همه‌ی چت‌های من» به دستیار بسپارند.</p>
      </div></div>
      <div class="card flat"><div class="card-h"><h3>${ic('phone-call')}درخواست تماس</h3></div><div class="card-b stack s12">
        <label class="row">${sw('agCb', st.cb_enabled === '1')}<span>دستیار بتواند درخواست تماس ثبت کند («برای سرعت بیشتر، همکارانم با شما تماس می‌گیرند»)</span></label>
        ${field('کِی پیشنهاد بدهد؟', `<select class="inp" id="agCbOffer">${[['smart','هر وقت لازم بود (مشتری مردد، سؤال پیچیده، گروه بزرگ، بعد از استعلام)'],['after','فقط بعد از پیش‌فاکتور یا ثبت استعلام'],['ask','فقط اگر مشتری خودش خواست']].map(o => `<option value="${o[0]}" ${(st.cb_offer || 'smart') === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`, 'در هر گفتگو حداکثر یک بار پیشنهاد می‌دهد.')}
        <label class="row">${sw('agCbBale', st.cb_bale === '1')}<span>درخواست‌های تماس به بله‌ی مدیرانِ «گزارش روزانه» هم فرستاده شود</span></label>
      </div></div>
      ${field('پیام سپردن به همکار', `<input class="inp" id="agHand" value="${E(st.handoff_text)}">`)}
      <details><summary class="small" style="cursor:pointer;color:var(--accent-ink)">${ic('sliders-horizontal')} تنظیمات پیشرفته</summary>
        <div class="fg" style="margin-top:12px">
          ${field('پیام‌های اخیر گفتگو', `<input class="inp ltr" type="number" min="4" max="60" id="agHist" value="${E(st.history)}">`, 'چند پیام آخر به دستیار داده شود')}
          ${field('حداکثر کار با وب‌سرویس در هر پاسخ', `<input class="inp ltr" type="number" min="1" max="12" id="agSteps" value="${E(st.max_steps)}">`)}
          ${field('مکث برای پیام‌های پشت‌سرهم (ثانیه)', `<input class="inp ltr" type="number" min="0" max="15" id="agDeb" value="${E(st.debounce)}">`, 'اگر مشتری چند پیام پشت هم بفرستد، یک پاسخ برای همه')}
          ${field('سقف پیام دستیار به هر مشتری در ساعت', `<input class="inp ltr" type="number" min="0" max="500" id="agRate" value="${E(st.rate_hour)}">`, 'بعد از سقف، گفتگو به همکار سپرده می‌شود. ۰ = بدون سقف')}
          ${field('خلاقیت (temperature)', `<input class="inp ltr" type="number" min="0" max="1" step="0.1" id="agTemp" value="${E(st.temperature)}">`)}
          ${field('حداکثر طول پاسخ (توکن)', `<input class="inp ltr" type="number" min="200" max="4000" id="agTok" value="${E(st.max_tokens)}">`)}
          ${field('مهلت پاسخ مدل (ثانیه)', `<input class="inp ltr" type="number" min="10" max="120" id="agTo" value="${E(st.timeout)}">`)}
          ${field('مدل مخصوص دستیار (اختیاری)', `<input class="inp ltr" id="agModel" value="${E(st.model || '')}" placeholder="خالی = مدل پروایدر؛ مثلاً gpt-4.1-mini">`, 'مدل‌های بدون «فکر کردن» (gpt-4.1-mini، gpt-4o-mini) سریع‌ترند.')}
          ${field('عمق فکر مدل‌های استدلالی (gpt-5، o3…)', `<select class="inp" id="agReason">${[['minimal','کمینه (سریع‌ترین)'],['low','کم (پیشنهادی)'],['medium','متوسط (کندتر)'],['off','پیش‌فرض مدل']].map(o => `<option value="${o[0]}" ${(st.reasoning || 'low') === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`)}
          ${field('امضای انتهای پیام (اختیاری)', `<input class="inp" id="agSig" value="${E(st.signature)}">`)}
        </div>
        <label class="row" style="margin-top:8px">${sw('agFail', st.fail_notify === '1')}<span>اگر دستیار نتوانست جواب بدهد، به تیم اعلان بدهد</span></label>
        <label class="row" style="margin-top:8px">${sw('agSmsC', st.sms_confirmed_only !== '0')}<span>پیامک خودکار لینک فقط برای رزرو فوری؛ گزینه‌های استعلامی تا تأیید میزبان پیامک نشوند</span></label>
        <label class="row" style="margin-top:8px">${sw('agFailH', st.fail_handoff === '1')}<span>اگر بعد از تلاش دوباره هم جواب نداد، «پیام سپردن به همکار» را بفرستد و گفتگو را به همکاران بسپارد (مشتری بی‌جواب نماند)</span></label>
        <label class="row" style="margin-top:8px">${sw('agTyping', st.typing === '1')}<span>«در حال نوشتن…» در گفتگوی سایت، تا وقتی پاسخ آماده می‌شود</span></label>
        <label class="row" style="margin-top:8px">${sw('agChips', st.chips === '1')}<span>دکمه‌های پاسخ سریع زیر پیام دستیار در گفتگوی سایت («همین رو رزرو کن»، «گزینه‌های ارزون‌تر»، «با من تماس بگیرید»)</span></label>
      </details>
      <div class="row"><button class="btn btn-p" data-act="ag-save">${ic('check')}ذخیره</button><span class="xs muted">امروز: ${E(fa(S.d.today || ''))}</span></div>
    </div></div>`;
}
A['ag-save'] = async el => {
  const settings = {enabled:val('agOn'), lock_operators:val('agLock'), channels:$$('input[name=agCh]:checked').map(x => x.value).join(','), handoff_text:val('agHand'), history:toEn(val('agHist')), max_steps:toEn(val('agSteps')),
    debounce:toEn(val('agDeb')), rate_hour:toEn(val('agRate')), temperature:toEn(val('agTemp')), max_tokens:toEn(val('agTok')), timeout:toEn(val('agTo')), signature:val('agSig'), fail_notify:val('agFail'),
    owned:val('agOwned'), owned_idle:toEn(val('agIdle')), model:val('agModel'), reasoning:val('agReason'), fail_handoff:val('agFailH'), sms_confirmed_only:val('agSmsC'), typing:val('agTyping'),
    cb_enabled:val('agCb'), cb_offer:val('agCbOffer'), cb_bale:val('agCbBale'), chips:val('agChips')};
  if (settings.enabled && !S.d.ai_ready) return toast('اول یک پروایدر هوش مصنوعی فعال کنید', 'err');
  const r = await run(el, () => L().api('agent_save', {settings}));
  if (!r.ok) return toast(r.error, 'err');
  toast(settings.enabled ? 'ذخیره شد؛ دستیار فروش روشن است' : 'ذخیره شد'); refresh();
};
/* ================= ۲.۰.۷: «درخواست تماس‌ها» برای تیم پشتیبانی (بدون دسترسی به تنظیمات دستیار) ================= */
const CB = {rows:[]};
function cbRows() {
  const chn = {webchat:'گفتگوی سایت', instagram:'اینستاگرام', bale:'بله', telegram:'تلگرام', roubika:'روبیکا', sms:'پیامک'};
  if (!CB.rows.length) return `<div class="empty" style="padding:30px;text-align:center" class="muted">درخواست تماس بازی نیست 🎉</div>`;
  return `<div class="stack s8">${CB.rows.map(r => `<div class="row wrap" style="gap:10px;padding:10px 12px;border-radius:10px;background:var(--accent-soft)">
    <b>${E(r.person || r.name || 'مشتری')}</b><a class="ltr" href="tel:${E(r.phone)}" dir="ltr">${E(r.phone)}</a>
    ${r.preferred ? `<span class="small">⏰ ${E(r.preferred)}</span>` : ''}<span class="small muted" style="flex:1;min-width:200px">${E(r.note || '')}</span>
    <span class="xs muted">${E(chn[r.channel] || r.channel || '')} · ${E(L().fullDT ? L().fullDT(r.created_at) : r.created_at)}</span>
    ${r.person_id ? `<a class="btn btn-sm" href="index.php#inbox.v${+r.person_id}">${ic('chat-circle')}گفتگو</a>` : ''}
    <button class="btn btn-sm btn-p" data-act="cbp-done" data-id="${+r.id}">${ic('check')}تماس گرفته شد</button></div>`).join('')}</div>`;
}
reg('callbacks', {title:'درخواست تماس‌ها', icon:'phone-call', sub:'مشتری‌هایی که از دستیار فروش خواسته‌اند همکاران با آن‌ها تماس بگیرند',
  async load() { const r = await L().api('agent_callbacks'); if (!r.ok) throw new Error(r.error || 'بارگذاری نشد'); CB.rows = r.rows || []; },
  render(el) { el.innerHTML = `<div class="page"><div class="ph"><div><h2>درخواست تماس‌ها</h2><p>مشتری‌هایی که از دستیار فروش تماس خواسته‌اند؛ بعد از تماس «تماس گرفته شد» را بزنید.</p></div></div><div class="card"><div class="card-b" id="cbpBody">${cbRows()}</div></div></div>`; }});
A['cbp-done'] = async el => {
  const r = await run(el, () => L().api('agent_callback_done', {id:+el.dataset.id}));
  if (!r.ok) return toast(r.error, 'err'); CB.rows = r.rows || []; const b = $('#cbpBody'); if (b) b.innerHTML = cbRows(); toast('ثبت شد');
};

function cbCard() {
  const rows = S.d.callbacks || [];
  if (!rows.length) return '';
  const chn = {webchat:'گفتگوی سایت', instagram:'اینستاگرام', bale:'بله', telegram:'تلگرام', roubika:'روبیکا', sms:'پیامک'};
  return `<div class="card" style="margin-bottom:16px;border-color:var(--accent)"><div class="card-h"><h3>${ic('phone-call')}درخواست‌های تماس (${fa(rows.length)})</h3><div class="acts xs muted">مشتری‌هایی که خواسته‌اند همکاران با آن‌ها تماس بگیرند</div></div>
    <div class="card-b stack s8">${rows.map(r => `<div class="row wrap" style="gap:10px;padding:8px 10px;border-radius:10px;background:var(--accent-soft)">
      <b>${E(r.person || r.name || 'مشتری')}</b><a class="ltr" href="tel:${E(r.phone)}" dir="ltr">${E(r.phone)}</a>
      ${r.preferred ? `<span class="small">⏰ ${E(r.preferred)}</span>` : ''}<span class="small muted" style="flex:1;min-width:200px">${E(r.note || '')}</span>
      <span class="xs muted">${E(chn[r.channel] || r.channel || '')} · ${E(L().fullDT ? L().fullDT(r.created_at) : r.created_at)}</span>
      ${r.person_id ? `<a class="btn btn-sm" href="index.php#inbox.v${+r.person_id}">${ic('chat-circle')}گفتگو</a>` : ''}
      <button class="btn btn-sm btn-p" data-act="cb-done" data-id="${+r.id}">${ic('check')}تماس گرفته شد</button></div>`).join('')}</div></div>`;
}
A['cb-done'] = async el => {
  const r = await run(el, () => L().api('agent_callback_done', {id:+el.dataset.id}));
  if (!r.ok) return toast(r.error, 'err'); S.d.callbacks = r.rows; toast('ثبت شد'); paint();
};
A['ag-takeall'] = async el => {
  const d = await run(el, () => L().api('agent_takeover_all', {dry:1}));
  if (!d.ok) return toast(d.error, 'err');
  if (!d.total) return toast('گفتگوی دارای مسئولی در کانال‌های دستیار نیست');
  confirm({title:'سپردن گفتگوها به دستیار فروش', text:`${fa(nf(d.total))} گفتگو که الان بر عهده‌ی همکاران است (${(d.by || []).map(x => E(x.name) + ': ' + fa(x.n)).join('، ')}) به دستیار سپرده می‌شود و از این به بعد دستیار مسئول و پاسخ‌گوی آن‌هاست. در سابقه‌ی هر گفتگو ثبت می‌شود.`, ok:'سپردن ' + fa(d.total) + ' گفتگو', onOk:async () => {
    const r = await L().api('agent_takeover_all', {});
    if (!r.ok) return toast(r.error, 'err');
    toast(fa(r.moved) + ' گفتگو به دستیار سپرده شد'); refresh();
  }});
};

/* ======================= اتصال‌ها ======================= */
function conn() {
  const cs = S.d.connectors;
  const presetBtns = S.d.presets.filter(p => !cs.some(c => c.slug === p.slug)).map(p => `<button class="btn btn-p" data-act="cn-preset" data-k="${E(p.slug)}">${ic('magic-wand')}افزودن ${E(p.name)}</button>`).join('');
  return `<div class="row wrap" style="gap:8px;margin-bottom:14px">${presetBtns}<button class="btn" data-act="cn-new">${ic('plus')}اتصال دلخواه</button>
      <span class="xs muted">هر وب‌سرویس فروش/رزرو با خروجی JSON را می‌توانید با تعریف «ابزارها» وصل کنید.</span></div>
    ${cs.length ? cs.map(c => `<div class="card" style="margin-bottom:12px"><div class="card-b stack s12">
      <div class="row wrap" style="gap:10px"><span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('plugs-connected')}</span>
        <div class="grow" style="min-width:0"><b>${E(c.name)}</b> <span class="xs muted ltr">${E(c.slug)}</span><div class="xs muted ltr ell" style="text-align:right">${E(c.base_url)}</div></div>
        ${+c.is_active ? pill('فعال', 'ok') : pill('غیرفعال', 'warn')}${c.token_set ? pill('توکن: ' + E(c.token_hint), '', 'key') : pill('توکن ثبت نشده', 'warn', 'key')}</div>
      <div class="small">${fa(c.tools.filter(t => t.enabled).length)} ابزار فعال: ${c.tools.filter(t => t.enabled).map(t => `<span class="tag">${E(t.title || t.name)}${t.confirm ? ' 🔒' : ''}</span>`).join(' ')}</div>
      <div class="field" style="margin:0"><label>آدرس وب‌هوک (برای ثبت در پنل سرویس)</label><div class="row" style="gap:6px"><input class="inp ltr mono inp-sm" readonly value="${E(c.webhook_url)}"><button class="btn btn-sm" data-act="cn-copy" data-v="${E(c.webhook_url)}">${ic('copy')}کپی</button></div>
        <span class="hint">${c.webhook_secret_set ? '✓ راز وب‌هوک ثبت شده؛ رویدادهای امضاشده پذیرفته می‌شوند.' : '⚠️ راز وب‌هوک (HMAC) ثبت نشده؛ تا ثبت نشود رویدادی پذیرفته نمی‌شود.'}</span></div>
      <div class="row wrap" style="gap:8px"><button class="btn btn-sm" data-act="cn-test" data-id="${c.id}">${ic('play')}آزمایش اتصال</button><button class="btn btn-sm" data-act="cn-edit" data-id="${c.id}">${ic('pencil-simple')}ویرایش</button>
        <button class="btn btn-sm" data-act="cn-tools" data-id="${c.id}">${ic('flask')}آزمایش ابزارها</button>${S.d.presets.some(p => p.slug === c.slug) ? `<button class="btn btn-sm btn-g" data-act="cn-preset" data-k="${E(c.slug)}" title="ابزارها و راهنما از الگوی آماده به‌روز می‌شود؛ توکن و راز دست نمی‌خورد">${ic('arrow-counter-clockwise')}به‌روزرسانی از الگو</button>` : ''}
        <span class="ms-auto"></span><button class="btn btn-sm btn-g" data-act="cn-del" data-id="${c.id}">${ic('trash-simple')}حذف</button></div>
      <div id="cnTest${c.id}"></div>
    </div></div>`).join('') : empty('plugs-connected', 'هنوز اتصالی ندارید', 'جاکجاست را با یک کلیک اضافه کنید و توکن را وارد کنید.', presetBtns)}`;
}
A['cn-copy'] = el => copy(el.dataset.v, el);
A['cn-preset'] = el => {
  const k = el.dataset.k; const ex = S.d.connectors.find(c => c.slug === k);
  if (ex) return confirm({title:'به‌روزرسانی از الگو', text:'ابزارها، راهنما و نگاشت کانال‌ها از الگوی آماده جایگزین می‌شود. توکن، راز وب‌هوک و آدرس دست نمی‌خورد.', ok:'به‌روزرسانی', onOk:async () => {
    const r = await L().api('connector_preset', {key:k}); if (!r.ok) return toast(r.error, 'err'); toast('به‌روز شد'); refresh(); }});
  const L2 = modal({title:'افزودن ' + E((S.d.presets.find(p => p.slug === k) || {}).name || k), icon:'plugs-connected', body:`<div class="stack s12">
      ${field('توکن (Bearer)', '<input class="inp ltr mono" id="pvTok" autocomplete="off" placeholder="jkws_...">', 'از پنل وب‌سرویس جاکجاست. فقط روی سرور ذخیره می‌شود و دیگر نمایش داده نمی‌شود.')}
      <p class="xs muted">جاکجاست فقط از آی‌پی‌های لیست سفید قبول می‌کند؛ آی‌پی سرور همین پنل باید در آن لیست باشد.</p></div>`,
    foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>افزودن</button>'});
  L2.el.querySelector('[data-ok]').onclick = async e => {
    const r = await run(e.currentTarget, () => L().api('connector_preset', {key:k, token:val('pvTok')}));
    if (!r.ok) return toast(r.error, 'err'); L2.close(); toast('اتصال اضافه شد؛ «آزمایش اتصال» را بزنید'); refresh();
  };
};
A['cn-del'] = el => { const c = S.d.connectors.find(x => x.id === +el.dataset.id); confirm({title:'حذف ' + E(c.name), text:'اتصال و ابزارهایش حذف می‌شود و دستیار دیگر از آن استفاده نمی‌کند. سفارش‌ها و گزارش‌های قبلی می‌ماند.', ok:'حذف', danger:true, onOk:async () => {
  const r = await L().api('connector_delete', {id:c.id}); if (!r.ok) return toast(r.error, 'err'); toast('حذف شد'); refresh(); }}); };
A['cn-test'] = async el => {
  const box = $('#cnTest' + el.dataset.id);
  const r = await run(el, () => L().api('connector_test', {id:+el.dataset.id})); if (!r.ok) return toast(r.error, 'err');
  const x = r.result;
  box.innerHTML = `<div class="callout ${x.ok ? '' : 'err'}">${ic(x.ok ? 'check-circle' : 'warning-circle')}<span>${x.ok ? 'اتصال برقرار است' : 'اتصال ناموفق: ' + E(x.error || '')} · HTTP ${fa(x.http)} · ${fa(x.ms || 0)} میلی‌ثانیه${x.http === 403 ? ' — اگر IP_NOT_WHITELISTED است، آی‌پی این سرور را به لیست سفید سرویس بدهید.' : ''}</span></div>${x.data ? `<details style="margin-top:8px"><summary class="xs muted" style="cursor:pointer">پاسخ کامل</summary>${pre(x.data)}</details>` : ''}`;
};

function connForm(c) {
  const tools = JSON.stringify(c.tools || [], null, 2);
  const wh = c.webhook || {};
  return `<div class="stack s16" style="padding:18px">
    <div class="fg">${field('نام', `<input class="inp" id="cnName" value="${E(c.name || '')}" placeholder="مثلاً جاکجاست">`)}${field('شناسه‌ی لاتین', `<input class="inp ltr" id="cnSlug" value="${E(c.slug || '')}" placeholder="jakojast">`, 'در نام ابزارها و آدرس وب‌هوک')}</div>
    ${field('آدرس پایه (Base URL)', `<input class="inp ltr" id="cnBase" value="${E(c.base_url || '')}" placeholder="https://example.com/api">`)}
    <div class="fg">${field('روش احراز هویت', `<select class="inp" id="cnAuth">${[['bearer', 'Authorization: Bearer'], ['header', 'هدر دلخواه'], ['query', 'پارامتر آدرس'], ['none', 'بدون احراز']].map(o => `<option value="${o[0]}" ${(c.auth_type || 'bearer') === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`)}
      ${field('نام هدر/پارامتر', `<input class="inp ltr" id="cnAuthN" value="${E(c.auth_name || '')}" placeholder="X-API-Key">`, 'فقط برای «هدر دلخواه» یا «پارامتر آدرس»')}</div>
    ${field('توکن / کلید', `<input class="inp ltr mono" id="cnTok" autocomplete="off" placeholder="${c.token_set ? 'ثبت شده (' + E(c.token_hint) + ') — خالی بماند تا تغییر نکند' : 'توکن را وارد کنید'}">`)}
    <div class="fg">${field('مسیر آزمایش اتصال', `<input class="inp ltr" id="cnTest" value="${E(c.test_path || '')}" placeholder="/ping">`)}<label class="row" style="align-self:end">${sw('cnAct', c.is_active === undefined ? true : !!+c.is_active)}<span>فعال</span></label></div>
    ${field('راهنمای فروش برای دستیار', `<textarea class="inp" rows="7" id="cnIns">${E(c.instructions || '')}</textarea>`, 'کی از کدام ابزار استفاده کند، چه اطلاعاتی برای سفارش لازم است، بعد از ثبت چه بگوید.')}
    <div class="card flat"><div class="card-h"><h3>${ic('webhooks-logo')}وب‌هوک (رویدادهای سرویس → پیام در همان گفتگو)</h3></div><div class="card-b stack s12">
      ${field('راز وب‌هوک (HMAC-SHA256)', `<input class="inp ltr mono" id="cnSec" autocomplete="off" placeholder="${c.webhook_secret_set ? 'ثبت شده — خالی بماند تا تغییر نکند' : 'کلیدی که سرویس برای امضا استفاده می‌کند'}">`)}
      <div class="fg">${field('هدر امضا', `<input class="inp ltr" id="cnSigH" value="${E(wh.sig_header || '')}" placeholder="خودکار">`)}${field('هدر زمان', `<input class="inp ltr" id="cnTsH" value="${E(wh.ts_header || '')}" placeholder="خودکار">`)}</div>
      <div class="fg">${field('کلید شناسه‌ی گفتگو', `<input class="inp ltr" id="cnChatK" value="${E(wh.chat_key || 'external_chat_id')}">`)}${field('کلید متن آماده', `<input class="inp ltr" id="cnTextK" value="${E(wh.text_key || 'social_message_text')}">`)}</div>
      <div class="fg">${field('کلید نوع رویداد', `<input class="inp ltr" id="cnEvK" value="${E(wh.event_key || 'event')}">`)}${field('کلیدهای کد سفارش (با کاما)', `<input class="inp ltr" id="cnRefK" value="${E((wh.ref_keys || ['booking_code', 'enquiry_code', 'order_id']).join(','))}">`)}</div>
    </div></div>
    ${field('نگاشت کانال‌ها (JSON)', `<textarea class="inp ltr mono" rows="3" id="cnMap">${E(JSON.stringify(c.channel_map || {}, null, 0))}</textarea>`, 'کانال اعلان‌بان → مقدار {channel} در ابزارها؛ «*» برای بقیه')}
    ${field('ابزارها (JSON)', `<textarea class="inp ltr mono" rows="16" id="cnTools" spellcheck="false">${E(tools)}</textarea>`, 'هر ابزار: name، title، description، method، path ({param} در مسیر)، params (JSON Schema)، inject ({chat_ref} {person_ref} {channel} {phone} {name})، confirm، side_effect، track، cache، enabled')}
  </div>`;
}
function openConn(c) {
  const isNew = !c.id;
  const dw = drawer({title:isNew ? 'اتصال تازه' : 'ویرایش ' + E(c.name), sub:'وب‌سرویسی که دستیار با آن جستجو، استعلام و ثبت سفارش می‌کند', size:'lg', head:`<span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('plugs-connected')}</span>`,
    body:connForm(c), foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>'});
  dw.el.querySelector('[data-ok]').onclick = async e => {
    let tools, map;
    try { tools = JSON.parse(val('cnTools') || '[]'); if (!Array.isArray(tools)) throw 0; } catch (x) { return toast('ابزارها JSON معتبر (آرایه) نیست', 'err'); }
    try { map = JSON.parse(val('cnMap') || '{}'); } catch (x) { return toast('نگاشت کانال‌ها JSON معتبر نیست', 'err'); }
    const data = {id:c.id || 0, name:val('cnName'), slug:val('cnSlug'), base_url:val('cnBase'), auth_type:val('cnAuth'), auth_name:val('cnAuthN'), token:val('cnTok'), test_path:val('cnTest'),
      is_active:val('cnAct'), instructions:val('cnIns'), webhook_secret:val('cnSec'), headers:c.headers || {}, channel_map:map, tools,
      webhook:Object.assign({}, c.webhook || {}, {sig_header:val('cnSigH'), ts_header:val('cnTsH'), chat_key:val('cnChatK'), text_key:val('cnTextK'), event_key:val('cnEvK'), ref_keys:val('cnRefK').split(',').map(x => x.trim()).filter(Boolean)})};
    const r = await run(e.currentTarget, () => L().api('connector_save', data));
    if (!r.ok) return toast(r.error, 'err'); dw.close(); toast('اتصال ذخیره شد'); refresh();
  };
}
A['cn-new'] = () => openConn({tools:[{name:'search', title:'جستجو', description:'جستجوی محصول/خدمت', method:'GET', path:'/search', params:{type:'object', properties:{q:{type:'string'}}}}], channel_map:{'*':'site'}});
A['cn-edit'] = el => openConn(S.d.connectors.find(c => c.id === +el.dataset.id));
A['cn-tools'] = el => {
  const c = S.d.connectors.find(x => x.id === +el.dataset.id);
  const safe = c.tools.filter(t => !t.side_effect && !t.confirm);
  const sample = t => { const o = {}; Object.entries((t.params || {}).properties || {}).forEach(([k, v]) => { if (((t.params || {}).required || []).includes(k)) o[k] = v.type === 'integer' ? 1 : ''; }); return JSON.stringify(o, null, 2); };
  const md = modal({title:'آزمایش ابزارهای ' + E(c.name), icon:'flask', size:'lg', body:`<div class="stack s12">
      ${field('ابزار', `<select class="inp" id="ttTool">${safe.map(t => `<option value="${E(t.name)}">${E(t.title || t.name)} — ${E(t.method)} ${E(t.path)}</option>`).join('')}</select>`, 'ابزارهایی که پیامک می‌فرستند یا سفارش ثبت می‌کنند از اینجا اجرا نمی‌شوند.')}
      ${field('ورودی (JSON)', `<textarea class="inp ltr mono" rows="6" id="ttArgs">${E(safe[0] ? sample(safe[0]) : '{}')}</textarea>`)}
      <div id="ttOut"></div></div>`, foot:'<button class="btn" data-close>بستن</button><button class="btn btn-p" data-ok>اجرا</button>'});
  md.el.querySelector('#ttTool').onchange = e => { const t = safe.find(x => x.name === e.target.value); md.el.querySelector('#ttArgs').value = sample(t); };
  md.el.querySelector('[data-ok]').onclick = async e => {
    let args; try { args = JSON.parse(md.el.querySelector('#ttArgs').value || '{}'); } catch (x) { return toast('ورودی JSON معتبر نیست', 'err'); }
    const r = await run(e.currentTarget, () => L().api('connector_tool_test', {id:c.id, tool:md.el.querySelector('#ttTool').value, args}));
    if (!r.ok) return toast(r.error, 'err');
    const x = r.result; md.el.querySelector('#ttOut').innerHTML = `<div class="small" style="margin-bottom:6px">${x.ok ? pill('موفق', 'ok') : pill('ناموفق', 'err')} HTTP ${fa(x.http)} · ${fa(x.ms || 0)} ms ${x.cached ? '· از کش' : ''} ${x.error ? '· ' + E(x.error) : ''}</div>${pre(x.data)}`;
  };
};

/* ======================= آزمایش گفتگو ======================= */
function sim() {
  const msgs = S.sim;
  return `<div class="card"><div class="card-h"><h3>${ic('chat-circle-dots')}آزمایش گفتگو</h3><span class="xs muted">جای مشتری بنویسید. جستجو و استعلام واقعی انجام می‌شود؛ ثبت سفارش و پیامک در این حالت اجرا نمی‌شود.</span>
      <div class="acts row" style="gap:6px"><select class="inp inp-sm" style="max-width:200px" data-on-change="sim-prof" aria-label="پروفایل">${S.d.profiles.map(p => `<option value="${p.id}" ${(S.simProfile || (S.d.profiles.find(x => +x.is_default) || {}).id) === p.id ? 'selected' : ''}>${E(p.name)}</option>`).join('')}</select><button class="btn btn-sm btn-g" data-act="sim-reset">${ic('arrow-counter-clockwise')}گفتگوی تازه</button></div></div>
    <div class="card-b"><div id="simBox" class="scroll stack s12" style="max-height:min(58vh,560px);overflow:auto;padding:4px 2px">
      ${msgs.length ? msgs.map(m => `<div style="display:flex;justify-content:${m.role === 'user' ? 'flex-start' : 'flex-end'}"><div style="max-width:min(82%,560px)">
          ${m.steps && m.steps.length ? `<div class="row wrap" style="gap:4px;margin-bottom:4px;justify-content:flex-end">${m.steps.map(s => `<span class="tag xs" title="${E(JSON.stringify(s.args))}">${ic(s.ok ? 'lightning' : 'warning')}${E(toolLabel(s.tool))}${s.summary ? ' · ' + E(String(s.summary).slice(0, 60)) : ''}</span>`).join('')}</div>` : ''}
          <div style="padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;line-height:1.8;${m.role === 'user' ? 'background:var(--surface-2,#eef1ef);' : 'background:var(--accent-soft);color:var(--accent-ink);'}${m.err ? 'background:var(--danger-soft,#fde8e8);color:var(--danger,#b42318)' : ''}">${linkify(m.content)}</div></div></div>`).join('')
        : `<div class="muted small" style="text-align:center;padding:30px 10px">مثلاً بنویسید: «سلام، برای آخر هفته یه ویلای استخردار تو شمال برای ۶ نفر می‌خوام»</div>`}
      ${S.simBusy ? `<div class="muted small" style="text-align:end">${ic('robot')} دستیار در حال کار…</div>` : ''}
    </div>
    <div class="row" style="gap:8px;margin-top:12px"><textarea class="inp" rows="2" id="simIn" placeholder="پیام مشتری…" ${S.simBusy ? 'disabled' : ''}></textarea><button class="btn btn-p" data-act="sim-send" ${S.simBusy ? 'disabled' : ''}>${ic('paper-plane-tilt')}ارسال</button></div>
    </div></div>`;
}
const toolLabel = fn => { for (const c of S.d.connectors) for (const t of c.tools) if (c.slug + '__' + t.name === fn) return t.title || t.name; return {handoff_to_human:'سپردن به همکار', save_customer:'ذخیره‌ی مشخصات', guard:'🛡 محافظ', followup:'پیگیری', pay_reminder:'یادآوری پرداخت', arrival:'پیام روز ورود', survey:'نظرسنجی', skip:'ارسال نشد'}[fn] || fn; };
const linkify = t => E(t).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener" class="ltr" style="word-break:break-all">$1</a>');
A['sim-reset'] = async () => { S.sim = []; await L().api('agent_simulate', {reset:1}); paint(); };
A['sim-send'] = async () => {
  const t = val('simIn'); if (!t || S.simBusy) return;
  S.sim.push({role:'user', content:t}); S.simBusy = true; paint();
  const r = await L().api('agent_simulate', {messages:S.sim.filter(m => !m.err).map(m => ({role:m.role, content:m.content})), channel:'instagram', profile_id:S.simProfile || 0});
  S.simBusy = false;
  if (r.ok) S.sim.push({role:'assistant', content:r.text, steps:r.steps});
  else S.sim.push({role:'assistant', content:'خطا: ' + (r.error || 'نامشخص'), steps:r.steps || [], err:true});
  paint(); const i = $('#simIn'); if (i) i.focus();
};
ON['sim-key'] = () => {};
ON['sim-prof'] = el => { S.simProfile = +el.value; A['sim-reset'](); };
document.addEventListener('keydown', e => { if (e.target && e.target.id === 'simIn' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); A['sim-send'](); } });

/* ======================= سفارش‌ها ======================= */
const ST = {awaiting_payment:['منتظر پرداخت', 'warn'], paid:['پرداخت‌شده', 'ok'], enquiry_pending:['منتظر تأیید میزبان', 'info'], pending:['در انتظار', 'info'], rejected:['رد شده', 'err'], cancelled:['لغو', 'err'], completed:['انجام شده', 'ok']};
async function loadOrders() { const r = await L().api('agent_orders'); S.orders = r.ok ? r.rows : []; if (S.tab === 'orders') { const b = $('#agBody'); if (b) b.innerHTML = body(); } }
/* ---------------- ۱.۰.۱۳: تحلیل تقاضا برای بازاریابی ---------------- */
S.dmDays = S.dmDays || 30; S.dmF = S.dmF || {};
async function loadDemand() {
  const r = await L().api('agent_demand', Object.assign({days:S.dmDays}, S.dmF));
  S.dm = r.ok ? r : {error:r.error || 'خطا'};
  if (S.tab === 'demand') { const b = $('#agBody'); if (b) b.innerHTML = body(); }
}
const DMN = {area:'منطقه / مقصد', hood:'محله', type:'نوع واحد', class:'رده‌ی قیمت', price:'قیمت هر شب', amenity:'امکانات خواسته‌شده', group:'نوع مسافر', guests:'تعداد نفرات', nights:'مدت اقامت', lead:'چقدر زودتر رزرو می‌کنند', weekday:'روز ورود', occasion:'مناسبت', channel:'کانال'};
const DMF = {area:'area', type:'type', class:'class', amenity:'amenity', group:'group', channel:'channel'};
function demandView() {
  const d = S.dm;
  if (!d) return `<div class="muted small" style="padding:20px">در حال بارگذاری…</div>`;
  if (d.error) return `<div class="callout warn">${ic('warning')}<span>${E(d.error)}</span></div>`;
  const k = d.kpi || {}, pct = (a, b) => b ? fa(Math.round(100 * a / b)) + '٪' : '—';
  const filt = Object.entries(S.dmF).filter(([, v]) => v);
  const bars = (key) => { const rows = d.by[key] || []; if (!rows.length) return ''; const max = Math.max(...rows.map(x => x.n));
    return `<div class="card"><div class="card-h"><h3>${E(DMN[key])}</h3></div><div class="card-b stack s6">${rows.map(x => `
      <div ${DMF[key] ? `data-act="dm-f" data-k="${DMF[key]}" data-v="${E(x.label)}" style="cursor:pointer" title="فیلتر روی همین"` : ''}>
        <div class="row" style="justify-content:space-between;gap:8px"><span class="small">${E(x.label)}</span><span class="xs muted">${fa(x.n)}${x.enquiry ? ' · استعلام ' + fa(x.enquiry) : ''}${x.unmet ? ' · <b style="color:var(--danger,#b42318)">بی‌نتیجه ' + fa(x.unmet) + '</b>' : ''}</span></div>
        <div style="height:7px;border-radius:5px;background:var(--line,#e6e3da);overflow:hidden"><i style="display:block;height:100%;width:${Math.max(3, Math.round(100 * x.n / max))}%;background:var(--accent)"></i></div></div>`).join('')}</div></div>`; };
  return `<div class="row wrap" style="gap:10px;margin-bottom:14px;align-items:center">
      ${seg('dmDays', [['7', '۷ روز'], ['30', '۳۰ روز'], ['90', '۹۰ روز']], String(S.dmDays), 'dm-days')}
      ${filt.map(([f, v]) => `<span class="tag">${E(v)}<button data-act="dm-unf" data-k="${f}" aria-label="حذف">${ic('x')}</button></span>`).join('')}
      <span style="flex:1"></span><button class="btn btn-sm" data-act="dm-csv">${ic('download-simple')}خروجی اکسل (CSV)</button></div>
    <div class="kpis" style="margin-bottom:16px">
      ${kpi('درخواست‌ها', fa(nf(k.requests || 0)), fa(nf(k.people || 0)) + ' مشتری')}
      ${kpi('استعلام / رزرو', fa(nf(k.enquiry || 0)), 'نرخ تبدیل ' + pct(k.enquiry, k.requests))}
      ${kpi('پرداخت‌شده', fa(nf(k.paid || 0)), pct(k.paid, k.requests))}
      ${kpi('تقاضای بی‌پاسخ', fa(nf(k.unmet || 0)), 'جستجو بی‌نتیجه ماند')}
      ${kpi('میانه‌ی قیمت هر شب', k.median_night ? fa(nf(k.median_night)) : '—', 'تومان')}
      ${kpi('درخواست تماس', fa(nf(k.callbacks || 0)))}
    </div>
    <div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('funnel')}قیف درخواست‌ها</h3></div><div class="card-b"><div class="row wrap" style="gap:8px">${(d.stages || []).map((s, i) => `<div style="flex:1;min-width:110px;padding:10px 12px;border-radius:10px;background:var(--accent-soft)"><div class="xs muted">${E(s.label)}</div><div style="font-size:20px;font-weight:700">${fa(nf(s.n))}</div>${i ? `<div class="xs muted">${pct(s.n, d.stages[i - 1].n)} مرحله‌ی قبل</div>` : ''}</div>`).join('')}</div></div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">${Object.keys(DMN).map(bars).join('')}</div>
    ${(d.unmet || []).length ? `<div class="card" style="margin-top:16px"><div class="card-h"><h3>${ic('warning')}تقاضای بی‌پاسخ (فرصت جذب میزبان یا تبلیغ)</h3></div><div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>روز</th><th>مقصد</th><th>نوع</th><th>نفرات</th><th>ورود</th><th>شب</th><th>رده</th><th>امکانات</th></tr></thead><tbody>
      ${d.unmet.map(u => `<tr><td class="xs">${E(fa(u.day))}</td><td>${E([u.area || u.city, u.hood].filter(Boolean).join(' · ') || '—')}</td><td>${E(u.type || '—')}</td><td>${u.guests ? fa(u.guests) : '—'}</td><td class="xs">${E(fa(u.check_in || '—'))}</td><td>${u.nights ? fa(u.nights) : '—'}</td><td>${E(u.class || '—')}</td><td class="small">${E((u.amenities || []).join('، ') || '—')}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
    <p class="xs muted" style="margin-top:12px">هر «درخواست» یعنی گفتگوی یک مشتری با دستیار در یک روز. مقصد، نوع، نفرات و تاریخ از جستجوهای دستیار می‌آید و امکانات، نوع مسافر، مناسبت و بودجه از متن پیام‌های مشتری. رده‌ی قیمت: اقتصادی زیر ${fa(nf(+(S.d.settings.dm_eco || 2500000)))} و لوکس بالای ${fa(nf(+(S.d.settings.dm_lux || 6000000)))} تومان برای هر شب. روی هر ردیف بزنید تا همه‌ی نمودارها روی همان فیلتر شوند.</p>`;
}
A['dm-days'] = el => { S.dmDays = +el.dataset.v; S.dm = null; paint(); };
A['dm-f'] = el => { S.dmF[el.dataset.k] = el.dataset.v; S.dm = null; paint(); };
A['dm-unf'] = el => { delete S.dmF[el.dataset.k]; S.dm = null; paint(); };
A['dm-csv'] = async el => {
  const r = await run(el, () => L().api('agent_demand_csv', {days:S.dmDays}));
  if (!r.ok) return toast(r.error, 'err');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([r.csv], {type:'text/csv;charset=utf-8'})); a.download = 'demand-' + S.dmDays + 'd.csv'; a.click();
};

function orders() {
  if (!S.orders) return `<div class="muted small" style="padding:20px">در حال بارگذاری…</div>`;
  if (!S.orders.length) return empty('list-checks', 'هنوز سفارشی ثبت نشده', 'رزروهایی که دستیار ثبت می‌کند و رویدادهای وب‌هوک (تأیید میزبان، پرداخت) اینجا دیده می‌شوند.');
  return `<div class="card"><div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>کد</th><th>عنوان</th><th>مشتری</th><th>وضعیت</th><th>مبلغ</th><th>ورود / خروج</th><th>پیگیری</th><th>آخرین تغییر</th></tr></thead><tbody>
    ${S.orders.map(o => { const s = ST[o.status] || [o.status || '—', '']; return `<tr><td class="ltr mono">${E(o.ref)}${o.ref2 ? `<div class="xs muted">${E(o.ref2)}</div>` : ''}</td><td>${E(o.title || '—')}<div class="xs muted">${E(o.connector || '')}</div></td>
      <td>${o.person_id ? `<a href="#inbox.v${+o.person_id}">${E(o.person || '')}</a>` : '—'}</td><td>${pill(E(s[0]), s[1])}</td><td>${o.amount ? EB.toman(o.amount) : '—'}</td><td class="xs ltr">${o.check_in ? E(fa(o.check_in)) + (o.check_out ? ' → ' + E(fa(o.check_out)) : '') : '—'}</td>
      <td class="xs">${[o.reminded_at ? 'یادآوری پرداخت' : '', o.arrival_at ? 'پیام ورود' : '', o.survey_at ? 'نظرسنجی' : ''].filter(Boolean).join('، ') || '—'}${o.rating ? ` · ⭐ ${fa(o.rating)}` : ''}</td><td class="xs muted">${E(L().fullDT ? L().fullDT(o.updated_at) : o.updated_at)}</td></tr>`; }).join('')}
  </tbody></table></div></div>`;
}

/* ======================= گزارش ======================= */
const KIND = {tool:'ابزار', confirm:'منتظر تأیید', model:'مدل', webhook:'وب‌هوک', test:'آزمایش', sim:'آزمایش گفتگو', lifecycle:'پیگیری خودکار'};
async function loadLog(more) {
  const r = await L().api('agent_log', Object.assign({}, S.logF, more && S.log && S.log.length ? {before:S.log[S.log.length - 1].id} : {}));
  if (!r.ok) return toast(r.error, 'err');
  S.log = more ? (S.log || []).concat(r.rows) : r.rows; S.logMore = r.has_more;
  if (S.tab === 'log') { const b = $('#agBody'); if (b) b.innerHTML = body(); }
}
function logView() {
  const f = `<div class="row wrap" style="gap:8px;margin-bottom:12px"><select class="inp inp-sm" style="max-width:170px" data-on-change="lg-f" data-k="kind"><option value="">همه‌ی انواع</option>${Object.entries(KIND).map(([k, v]) => `<option value="${k}" ${S.logF.kind === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
    <select class="inp inp-sm" style="max-width:140px" data-on-change="lg-f" data-k="ok"><option value="">موفق و ناموفق</option><option value="1" ${S.logF.ok === '1' ? 'selected' : ''}>موفق</option><option value="0" ${S.logF.ok === '0' ? 'selected' : ''}>ناموفق</option></select>
    <button class="btn btn-sm btn-g" data-act="lg-reload">${ic('arrow-counter-clockwise')}تازه‌سازی</button></div>`;
  if (!S.log) return f + `<div class="muted small" style="padding:20px">در حال بارگذاری…</div>`;
  if (!S.log.length) return f + empty('flask', 'گزارشی نیست', 'هر جستجو، استعلام، ثبت سفارش و رویداد وب‌هوک اینجا ثبت می‌شود.');
  return f + `<div class="card"><div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>زمان</th><th>نوع</th><th>کار</th><th>مشتری</th><th>نتیجه</th><th></th></tr></thead><tbody>
    ${S.log.map(x => `<tr><td class="xs muted">${E(L().fullDT ? L().fullDT(x.created_at) : x.created_at)}</td><td>${pill(KIND[x.kind] || E(x.kind))}</td><td class="small">${E(toolLabel(x.tool || '') || '—')}<div class="xs muted">${E(x.connector || '')}</div></td>
      <td class="small">${x.person_id ? `<a href="#inbox.v${+x.person_id}">${E(x.person || '')}</a>` : '—'}</td>
      <td class="small">${+x.ok ? ic('check-circle') : ic('warning-circle')} ${E((x.summary || '').slice(0, 140))}${x.ms ? ` <span class="xs muted">${fa(x.ms)}ms</span>` : ''}</td>
      <td class="w1"><button class="btn btn-sm btn-g" data-act="lg-open" data-id="${x.id}">جزئیات</button></td></tr>`).join('')}
  </tbody></table></div></div>${S.logMore ? `<div class="row" style="justify-content:center;margin-top:12px"><button class="btn btn-sm" data-act="lg-more">موارد قدیمی‌تر</button></div>` : ''}`;
}
ON['lg-f'] = el => { S.logF[el.dataset.k] = el.value; S.log = null; loadLog(); };
A['lg-reload'] = () => { S.log = null; const b = $('#agBody'); if (b) b.innerHTML = body(); loadLog(); };
A['lg-more'] = () => loadLog(true);
A['lg-open'] = async el => {
  const r = await L().api('agent_log_detail', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err');
  const x = r.row; let res = x.result; try { res = JSON.parse(res); } catch (e) {}
  let args = x.args_json; try { args = JSON.parse(args); } catch (e) {}
  modal({title:'جزئیات · ' + E(toolLabel(x.tool || '') || KIND[x.kind] || ''), size:'lg', body:`<div class="stack s12"><div class="small">${E(x.summary || '')}${x.http ? ' · HTTP ' + fa(x.http) : ''}</div>
    ${args ? `<div><div class="lab">ورودی</div>${pre(args)}</div>` : ''}${res ? `<div><div class="lab">پاسخ</div>${pre(res)}</div>` : ''}</div>`});
};

/* ======================= ۱.۰.۱۷: دایرکت و کامنت ======================= */
async function loadPosts(silent) {
  const r = await L().api('agent_posts'); S.posts = r.ok ? r.posts : [];
  if (!silent && S.tab === 'social') { const b = $('#agBody'); if (b) b.innerHTML = body(); }
}
const PMODE = {inherit:'طبق تنظیم کلی', on:'دستیار روشن', off:'دستیار خاموش'};
function social() {
  const st = S.d.settings, on = (st.channels || '').split(',').filter(Boolean), dmOn = !on.length || on.includes('instagram');
  const posts = S.posts;
  return `<div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('chat-circle-text')}دایرکت‌ها</h3><div class="acts">${dmOn ? pill('دستیار جواب می‌دهد', 'ok') : pill('خاموش', 'warn')}</div></div><div class="card-b stack s12">
      <p class="small muted" style="margin:0">دایرکت‌های اینستاگرام دقیقاً مثل گفتگوی سایت جواب داده می‌شوند (جستجو، پیشنهاد، قیمت، رزرو)، و وقتی چند اقامتگاه پیشنهاد می‌دهد، به‌صورت کروسل (کارت‌های عکس‌دار) می‌فرستد؛ لینک‌ها طبق «سیاست لینک» پروفایل همان پیج است. روشن/خاموش بودن هر کانال در تب «وضعیت و تنظیمات ← کانال‌هایی که دستیار جواب می‌دهد» است.</p>
      <p class="small muted" style="margin:0">برند، لحن و پایگاه دانش هر پیج در «برندها و پیج‌ها»؛ کسی که زیر یک پست کامنت گذاشته و بعد دایرکت می‌دهد، دانش همان پست را هم همراه دارد.</p></div></div>
    <div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('cards')}ظاهر کروسل دایرکت</h3></div><div class="card-b stack s12">
      <p class="small muted" style="margin:0">کارت‌هایی که دستیار برای پیشنهاد اقامتگاه در دایرکت می‌فرستد. توکن‌ها: <span class="ltr">{t}</span> نام، <span class="ltr">{pf}</span> قیمت، <span class="ltr">{d}</span> توضیح/تاریخ، <span class="ltr">{id}</span> کد. در آدرس دکمه، <span class="ltr">{url}</span> یعنی لینک همان اقامتگاه (آدرس ثابت https هم مجاز است).</p>
      <div class="fg">${field('قالب عنوان کارت', `<input class="inp" id="igcT" value="${E(st.ig_title_tpl || '{t}')}" maxlength="120" placeholder="قیمت: {pf}">`, 'مثلاً «قیمت: {pf}» مثل نمونه‌ی خرید حضوری/غیرحضوری')}
        ${field('قالب زیرعنوان', `<input class="inp" id="igcS" value="${E(st.ig_sub_tpl || '')}" maxlength="120" placeholder="{t}">`, 'خالی = قیمت · توضیح · کد')}</div>
      <div class="fg">${field('دکمه‌ی ۱ — متن', `<input class="inp" id="igcB1" value="${E(st.ig_btn1_text || '')}" maxlength="20">`)}${field('دکمه‌ی ۱ — آدرس', `<input class="inp ltr" id="igcU1" value="${E(st.ig_btn1_url || '')}" placeholder="{url} یا https://…">`)}</div>
      <div class="fg">${field('دکمه‌ی ۲ — متن', `<input class="inp" id="igcB2" value="${E(st.ig_btn2_text || '')}" maxlength="20" placeholder="مثلاً: خرید حضوری">`)}${field('دکمه‌ی ۲ — آدرس', `<input class="inp ltr" id="igcU2" value="${E(st.ig_btn2_url || '')}" placeholder="https://…">`)}</div>
      <label class="row">${sw('igcL', st.ig_card_links !== '0')}<span>دکمه‌ی «مشاهده» و لمس عکس، لینک همان اقامتگاه را باز کند (حتی اگر لینک در متن پیام ممنوع است)</span></label>
      ${st.ig_card_error ? `<div class="note warn small" style="margin:0">آخرین خطای ارسال کارت در دایرکت: <span class="ltr">${E(st.ig_card_error)}</span></div>` : ''}
      <div class="row"><button class="btn btn-p" data-act="igc-save">${ic('check')}ذخیره</button></div></div></div>
    <div class="card" style="margin-bottom:16px"><div class="card-h"><h3>${ic('chat-teardrop-text')}کامنت‌ها</h3></div><div class="card-b stack s16">
      <label class="row">${sw('cmOn', st.cm_enabled === '1')}<span><b>دستیار به کامنت‌های اینستاگرام هم جواب بدهد</b> (بعد از قوانین «اتوماسیون کامنت»؛ هر پست را پایین جدا روشن/خاموش کنید)</span></label>
      ${field('نحوه‌ی پاسخ', `<select class="inp" id="cmMode">${[['both', 'پاسخ عمومی کوتاه زیر کامنت + جواب کامل در دایرکت (پیشنهادی)'], ['dm', 'فقط دایرکت (پاسخ عمومی ثابت پایین)'], ['public', 'فقط پاسخ عمومی کوتاه']].map(o => `<option value="${o[0]}" ${(st.cm_mode || 'both') === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}</select>`, 'قیمت، لینک، کد و شماره هیچ‌وقت عمومی نوشته نمی‌شود؛ اگر پاسخ عمومی شامل این‌ها باشد، با پاسخ ثابت جایگزین و جواب به دایرکت می‌رود.')}
      <div class="fg">${field('پاسخ عمومی ثابت', `<input class="inp" id="cmPub" value="${E(st.cm_public_text || '')}" maxlength="300">`, 'وقتی جواب کامل در دایرکت است')}
        ${field('پاسخ به کامنت تشکر/ایموجی', `<input class="inp" id="cmThx" value="${E(st.cm_thanks || '')}" maxlength="300" placeholder="خالی = بی‌پاسخ؛ مثلاً: ممنون از همراهیتون 🌸">`)}</div>
      <label class="row">${sw('cmTriv', st.cm_trivial === '1')}<span>کامنت‌های فقط ایموجی، تگ دوستان یا تشکر کوتاه به دستیار داده نشود (هزینه‌ی کمتر)</span></label>
      <div class="row"><button class="btn btn-p" data-act="cm-save">${ic('check')}ذخیره</button></div></div></div>
    <div class="card"><div class="card-h"><h3>${ic('image')}پست‌ها و رگ هر پست</h3><div class="acts"><button class="btn btn-sm" data-act="ps-new">${ic('plus')}پست دیگر</button></div></div>
      <div class="card-b"><p class="small muted" style="margin:0 0 10px">برای هر پست: دستیار روشن/خاموش (یا طبق تنظیم کلی)، «دانش پست» (توضیح همان پست: کدام اقامتگاه، شرایط، تخفیف…) و سندهای پایگاه دانش مخصوص همان پست. پست‌هایی که اخیراً کامنت گرفته‌اند خودکار اینجا می‌آیند.</p>
      ${posts === null || posts === undefined ? EB.sk(4) : posts.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>پست</th><th>کامنت‌ها</th><th>دستیار</th><th>رگ پست</th><th></th></tr></thead><tbody>
        ${posts.map(p => `<tr><td><b>${E(p.title || 'بدون عنوان')}</b><div class="xs muted ltr" style="text-align:right">${E(p.media_id)}</div>${p.sample ? `<div class="xs soft">«${E(String(p.sample).slice(0, 60))}»</div>` : ''}</td>
          <td class="small">${fa(p.comments || 0)}${p.last ? `<div class="xs muted">${E(L().faDate ? L().faDate(p.last) : p.last)}</div>` : ''}</td>
          <td>${pill(PMODE[p.mode] || PMODE.inherit, p.mode === 'on' ? 'ok' : p.mode === 'off' ? 'warn' : '')}</td>
          <td class="small">${p.knowledge ? '📝 دانش پست' : '<span class="muted">بدون دانش</span>'}${p.docs ? ` · ${fa(p.docs)} سند` : ''}</td>
          <td class="w1"><button class="btn btn-sm" data-act="ps-edit" data-id="${E(p.media_id)}">${ic('pencil-simple')}تنظیم</button></td></tr>`).join('')}
      </tbody></table></div>` : empty('image', 'هنوز پستی نیست', 'وقتی پست‌ها کامنت بگیرند اینجا می‌آیند؛ یا با «پست دیگر» شناسه‌ی پست را وارد کنید.')}</div></div>`;
}
A['igc-save'] = async el => {
  const settings = {ig_title_tpl:val('igcT'), ig_sub_tpl:val('igcS'), ig_btn1_text:val('igcB1'), ig_btn1_url:val('igcU1'), ig_btn2_text:val('igcB2'), ig_btn2_url:val('igcU2'), ig_card_links:val('igcL')};
  const r = await run(el, () => L().api('agent_save', {settings})); if (!r.ok) return toast(r.error, 'err');
  S.d.settings = r.settings; toast('ذخیره شد');
};
A['cm-save'] = async el => {
  const settings = {cm_enabled:val('cmOn'), cm_mode:val('cmMode'), cm_public_text:val('cmPub'), cm_thanks:val('cmThx'), cm_trivial:val('cmTriv')};
  const r = await run(el, () => L().api('agent_save', {settings})); if (!r.ok) return toast(r.error, 'err');
  S.d.settings = r.settings; toast('ذخیره شد');
};
function openPost(p) {
  const docs = (S.d.kb_docs || []).filter(d => p.media_id && d.media_id === p.media_id);
  const dw = drawer({title:p.media_id ? 'تنظیم پست' : 'پست تازه', sub:'دستیار، دانش و رگ مخصوص همین پست', size:'lg', body:`<div class="stack s16" style="padding:18px">
    <div class="fg">${field('شناسه‌ی پست (media id)', `<input class="inp ltr" id="psId" value="${E(p.media_id || '')}" ${p.media_id ? 'disabled' : ''}>`, 'از صفحه‌ی اینستاگرام ← پست‌ها، یا از کامنت‌های همان پست')}${field('عنوان (برای خودتان)', `<input class="inp" id="psTitle" value="${E(p.title || '')}" placeholder="مثلاً ویلا استخردار رامسر">`)}</div>
    ${field('دستیار برای این پست', seg('psMode', Object.entries(PMODE), p.mode || 'inherit', 'ps-mode'))}
    ${field('دانش این پست', `<textarea class="inp" rows="8" id="psKn" placeholder="این پست درباره‌ی ویلای کد 10052485 در رامسر است. تخفیف ۱۵٪ برای رزرو تا آخر آبان. اگر قیمت پرسیدند، قیمت شب‌های آخر هفته را بگو…">${E(p.knowledge || '')}</textarea>`, 'دستیار در کامنت‌های این پست و دایرکتِ کسانی که زیرش کامنت گذاشته‌اند، اول بر اساس همین جواب می‌دهد. اقامتگاه و قیمت را همچنان از وب‌سرویس می‌گیرد.')}
    <div><div class="lab" style="margin-bottom:6px">سندهای پایگاه دانش مخصوص این پست</div>${docs.length ? docs.map(d => `<span class="tag">${E(d.title)} · ${fa(d.chunks)} بخش</span>`).join(' ') : '<span class="small muted">ندارد — در تب «پایگاه دانش» با «فقط برای پست اینستاگرام» اضافه کنید.</span>'}</div>
  </div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>'});
  A['ps-mode'] = b => { $$('.seg[data-name=psMode] button').forEach(x => x.classList.toggle('on', x === b)); };
  dw.el.querySelector('[data-ok]').onclick = async e => {
    const m = $('.seg[data-name=psMode] .on');
    const data = {media_id:p.media_id || val('psId'), title:val('psTitle'), knowledge:val('psKn'), mode:m ? m.dataset.v : 'inherit'};
    if (!data.media_id) return toast('شناسه‌ی پست لازم است', 'err');
    const r = await run(e.target, () => L().api('agent_post_save', data)); if (!r.ok) return toast(r.error, 'err');
    dw.close(); toast('ذخیره شد'); S.posts = null; loadPosts();
  };
}
A['ps-edit'] = el => openPost((S.posts || []).find(p => p.media_id === el.dataset.id) || {media_id:el.dataset.id});
A['ps-new'] = () => openPost({});

/* ======================= برندها و پیج‌ها ======================= */
const tgtName = t => { if (t === 'cm:all') return 'همه‌ی کامنت‌ها'; if (t.startsWith('cm:')) { const pg = (S.d.ig_pages || []).find(x => 'cm:' + x.id === t); return 'کامنت‌های ' + (pg ? (pg.name || '@' + pg.username) : t.slice(3)); } if (t.startsWith('ig:')) { const pg = (S.d.ig_pages || []).find(x => 'ig:' + x.id === t); return 'پیج ' + (pg ? (pg.name || '@' + pg.username) : t.slice(3)); } const c = CHANS.find(x => 'ch:' + x[0] === t); return c ? c[1] : t; };
function profiles() {
  const ps = S.d.profiles;
  return `<div class="row wrap" style="gap:8px;margin-bottom:14px"><button class="btn btn-p" data-act="pf-new">${ic('plus')}برند / پروفایل تازه</button>
      <span class="xs muted">هر پیج اینستاگرام یا کانال می‌تواند برند، لحن، پایگاه دانش و سیاست لینک خودش را داشته باشد. پیج یا کانالی که پروفایل ندارد از «پیش‌فرض» استفاده می‌کند.</span></div>
    ${ps.map(p => `<div class="card" style="margin-bottom:12px"><div class="card-b stack s12">
      <div class="row wrap" style="gap:10px"><span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('user-switch')}</span>
        <div class="grow" style="min-width:0"><b>${E(p.name)}</b>${p.brand ? ` <span class="small muted">· برند «${E(p.brand)}»</span>` : ''}</div>
        ${+p.is_default ? pill('پیش‌فرض', 'acc') : ''}${+p.is_active ? '' : pill('غیرفعال', 'warn')}</div>
      <div class="small">${p.targets.length ? 'وصل به: ' + p.targets.map(t => `<span class="tag">${E(tgtName(t))}</span>`).join(' ') : (+p.is_default ? '<span class="muted">همه‌ی پیج‌ها و کانال‌هایی که پروفایل ندارند</span>' : '<span class="muted">به هیچ پیج یا کانالی وصل نیست</span>')}</div>
      <div class="row wrap xs muted" style="gap:14px"><span>${ic('link')} ${E(LINKM[p.link_mode] || p.link_mode)}${p.link_mode === 'limited' ? ' (' + fa(p.link_max) + ')' : ''}${+p.allow_checkout ? '، لینک پرداخت مجاز' : ''}</span><span>${ic('list-checks')} دانش: ${E(KBM[p.kb_mode] || p.kb_mode)}</span>
        <span>${ic('plugs-connected')} ${p.connectors.length ? p.connectors.map(id => E((S.d.connectors.find(c => c.id === id) || {}).name || '#' + id)).join('، ') : 'همه‌ی اتصال‌ها'}</span>${p.avoid_names ? `<span>${ic('eye')} پنهان: ${E(p.avoid_names)}</span>` : ''}</div>
      <div class="row" style="gap:8px"><button class="btn btn-sm" data-act="pf-edit" data-id="${p.id}">${ic('pencil-simple')}ویرایش</button><button class="btn btn-sm btn-g" data-act="pf-sim" data-id="${p.id}">${ic('chat-circle-dots')}آزمایش گفتگو</button>
        <span class="ms-auto"></span>${+p.is_default ? '' : `<button class="btn btn-sm btn-g" data-act="pf-del" data-id="${p.id}">${ic('trash-simple')}حذف</button>`}</div>
    </div></div>`).join('')}`;
}
function openProfile(p) {
  const isNew = !p.id;
  const used = {}; S.d.profiles.forEach(x => { if (x.id !== p.id) x.targets.forEach(t => { used[t] = x.name; }); });
  const tg = (key, label) => `<label class="row small" style="padding:3px 0;${used[key] ? 'opacity:.55' : ''}"><input type="checkbox" class="cb" name="pfT" value="${E(key)}" ${p.targets && p.targets.includes(key) ? 'checked' : ''} ${used[key] ? 'disabled' : ''}>${E(label)}${used[key] ? ` <span class="xs muted">(${E(used[key])})</span>` : ''}</label>`;
  const dw = drawer({title:isNew ? 'برند / پروفایل تازه' : 'ویرایش ' + E(p.name), sub:'برند، لحن، پایگاه دانش و سیاست لینک برای پیج‌ها و کانال‌های انتخابی', size:'lg', head:`<span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic('user-switch')}</span>`,
    body:`<div class="stack s16" style="padding:18px">
      <div class="fg">${field('نام پروفایل', `<input class="inp" id="pfName" value="${E(p.name || '')}" placeholder="مثلاً پیج پاندا اقامت">`)}${field('نام برند', `<input class="inp" id="pfBrand" value="${E(p.brand || '')}" placeholder="مثلاً جاکجاست یا پاندا اقامت">`, 'نام برند در معرفی و پاسخ‌ها')}</div>
      <div class="fg">${field('نام دستیار', `<input class="inp" id="pfAgent" value="${E(p.agent_name || '')}" placeholder="مثلاً: سارا، آوا یا جاکو">`, 'خالی = «دستیار فروش {برند}». فقط در اولین پیام گفتگو خودش را معرفی می‌کند.')}
        ${field('تصویر دستیار', `<div class="row"><span id="pfAvPrev">${p.avatar ? `<img src="${E(p.avatar)}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover">` : '<span class="xs muted">بدون تصویر (حرف اول نام)</span>'}</span><label class="btn btn-sm" style="cursor:pointer">${ic('upload-simple')}آپلود<input type="file" class="sr" accept="image/*" data-on-change="pf-avatar"></label><input type="hidden" id="pfAvatar" value="${E(p.avatar || '')}"></div>`, 'در گفتگوی سایت بالای پنجره و کنار پیام‌های دستیار نشان داده می‌شود.')}
        ${field('معرفی به مشتری', `<select class="inp" id="pfPersona"><option value="ai" ${(p.persona || 'ai') === 'ai' ? 'selected' : ''}>دستیار هوشمند (می‌گوید هوش مصنوعی است)</option><option value="human" ${p.persona === 'human' ? 'selected' : ''}>پشتیبان با نام (به ربات بودن اشاره نمی‌کند)</option></select>`, 'در حالت دوم، اگر مشتری مستقیم بپرسد «ربات هستی؟»، دستیار انکار نمی‌کند و پیشنهاد همکار انسانی می‌دهد.')}</div>
      ${field('شخصیت، لحن و هدف', `<textarea class="inp" rows="5" id="pfPrompt">${E(p.prompt || '')}</textarea>`, 'از {brand} برای نام برند استفاده کنید. قوانین ایمنی، تاریخ امروز و راهنمای اتصال‌ها خودکار اضافه می‌شود.')}
      ${field('نام‌هایی که نباید گفته شود', `<input class="inp" id="pfAvoid" value="${E(p.avoid_names || '')}" placeholder="مثلاً: جاکجاست، جاکجا">`, 'برای پیج برندهای دیگر. سرور هم این نام‌ها را در پاسخ با نام برند جایگزین می‌کند (لینک‌ها دست نمی‌خورند).')}
      <div class="card flat"><div class="card-h"><h3>${ic('link')}ارسال لینک (محدودیت اینستاگرام)</h3></div><div class="card-b stack s12">
        ${seg('pfLink', Object.entries(LINKM), p.link_mode || 'limited', 'pf-link')}
        <div class="row" id="pfMaxRow" style="${(p.link_mode || 'limited') === 'limited' ? '' : 'display:none'}"><span class="small">حداکثر لینک در هر پیام:</span><input class="inp inp-sm ltr" type="number" min="0" max="10" id="pfMax" value="${E(p.link_max ?? 1)}" style="max-width:80px"></div>
        <label class="row">${sw('pfPay', p.allow_checkout === undefined ? true : !!+p.allow_checkout)}<span>لینک پرداخت همیشه مجاز باشد</span></label>
        <label class="row">${sw('pfSms', p.sms_links === undefined ? true : !!+p.sms_links)}<span>وقتی لینک بسته است، لینک کدهای معرفی‌شده خودکار برای موبایل مشتری پیامک شود (اگر شماره‌اش را داریم) و در پیام بگوید «لینک‌ها پیامک شد»</span></label>
        <p class="xs muted" style="margin:0">در حالت «فقط کد»، دستیار گزینه‌ها را با کد و قیمت معرفی می‌کند و اگر مشتری لینک خواست، شماره‌اش را می‌گیرد و لینک‌ها را پیامک می‌کند. لینک‌های اضافه را سرور هم حذف می‌کند.</p>
      </div></div>
      <div class="fg">${field('پایگاه دانش', `<select class="inp" id="pfKb">${Object.entries(KBM).map(([k, v]) => `<option value="${k}" ${(p.kb_mode || 'both') === k ? 'selected' : ''}>${v}</option>`).join('')}</select>`, 'سندهای «عمومی» برای همه و سندهای «خصوصی» فقط برای همین پروفایل')}
        ${field('اتصال‌های مجاز', `<div class="stack s6">${S.d.connectors.map(c => `<label class="row small"><input type="checkbox" class="cb" name="pfC" value="${c.id}" ${p.connectors && p.connectors.includes(c.id) ? 'checked' : ''}>${E(c.name)}</label>`).join('') || '<span class="small muted">اتصالی نیست</span>'}</div>`, 'هیچ‌کدام = همه‌ی اتصال‌های فعال')}</div>
      ${field('وصل به پیج‌ها و کانال‌ها', `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:2px 16px">${(S.d.ig_pages || []).map(pg => tg('ig:' + pg.id, 'پیج ' + (pg.name || '@' + pg.username))).join('')}${CHANS.map(c => tg('ch:' + c[0], c[1])).join('')}${(S.d.ig_pages || []).map(pg => tg('cm:' + pg.id, 'کامنت‌های پیج ' + (pg.name || '@' + pg.username))).join('')}${tg('cm:all', 'همه‌ی کامنت‌های اینستاگرام')}</div>`, 'هر پیج یا کانال فقط به یک پروفایل وصل می‌شود. کامنت‌ها اگر پروفایل جدا نداشته باشند، از پروفایل همان پیج استفاده می‌کنند.')}
      <label class="row">${sw('pfDef', !!+p.is_default)}<span>پروفایل پیش‌فرض (برای پیج‌ها و کانال‌های بدون پروفایل)</span></label>
      <label class="row">${sw('pfAct', p.is_active === undefined ? true : !!+p.is_active)}<span>فعال</span></label>
    </div>`, foot:'<button class="btn" data-close>انصراف</button><button class="btn btn-p" data-ok>ذخیره</button>'});
  dw.el.querySelector('[data-ok]').onclick = async e => {
    const lm = $('.seg[data-name=pfLink] .on');
    const data = {id:p.id || 0, name:val('pfName'), brand:val('pfBrand'), prompt:val('pfPrompt'), avoid_names:val('pfAvoid'), agent_name:val('pfAgent'), persona:val('pfPersona'), avatar:val('pfAvatar'), sms_links:val('pfSms'), link_mode:lm ? lm.dataset.v : 'limited', link_max:+toEn(val('pfMax')) || 0,
      allow_checkout:val('pfPay'), kb_mode:val('pfKb'), connectors:$$('input[name=pfC]:checked').map(x => +x.value), targets:$$('input[name=pfT]:checked').map(x => x.value), is_default:val('pfDef'), is_active:val('pfAct')};
    if (!data.name) return toast('نام پروفایل را بنویسید', 'err');
    const r = await run(e.currentTarget, () => L().api('agent_profile_save', data));
    if (!r.ok) return toast(r.error, 'err'); dw.close(); toast('پروفایل ذخیره شد'); refresh();
  };
}
ON['pf-avatar'] = async el => {
  const f = el.files && el.files[0]; if (!f) return;
  const fd = new FormData(); fd.append('image', f);
  const r = await L().api('wcx_upload', fd, true);
  if (!r.ok) return toast(r.error, 'err');
  $('#pfAvatar').value = r.rel; $('#pfAvPrev').innerHTML = `<img src="${E(r.rel)}" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover">`;
};
A['pf-link'] = el => { A['seg'](el); const r = $('#pfMaxRow'); if (r) r.style.display = el.dataset.v === 'limited' ? '' : 'none'; };
A['pf-new'] = () => openProfile({prompt:(S.d.profiles.find(p => +p.is_default) || {}).prompt || '', targets:[], connectors:[], link_mode:'limited', link_max:1});
A['pf-edit'] = el => openProfile(S.d.profiles.find(p => p.id === +el.dataset.id));
A['pf-sim'] = el => { S.simProfile = +el.dataset.id; S.sim = []; S.tab = 'sim'; store.set('agTab', 'sim'); paint(); };
A['pf-del'] = el => { const p = S.d.profiles.find(x => x.id === +el.dataset.id); confirm({title:'حذف ' + E(p.name), text:'پیج‌ها و کانال‌های این پروفایل به پروفایل پیش‌فرض برمی‌گردند و سندهای خصوصی‌اش غیرفعال می‌شوند.', ok:'حذف', danger:true, onOk:async () => {
  const r = await L().api('agent_profile_delete', {id:p.id}); if (!r.ok) return toast(r.error, 'err'); toast('حذف شد'); refresh(); }}); };

/* ======================= پایگاه دانش ======================= */
const KBCH = {'':'همه‌ی کانال‌ها', webchat:'گفتگوی سایت', instagram:'دایرکت اینستاگرام', ig_comment:'کامنت اینستاگرام', telegram:'تلگرام', bale:'بله', roubika:'روبیکا', sms:'پیامک'};
const chOpts = sel => Object.entries(KBCH).map(([k, v]) => `<option value="${k}" ${(sel || '') === k ? 'selected' : ''}>${v}</option>`).join('');
const postList = () => `<datalist id="kbPosts">${(S.posts || []).map(p => `<option value="${E(p.media_id)}">${E(p.title || p.sample || '')}</option>`).join('')}</datalist>`;
function kbView() {
  if (!S.posts) loadPosts(true);
  const docs = S.d.kb_docs || [];
  const profOpts = sel => `<option value="0" ${!sel ? 'selected' : ''}>عمومی (همه‌ی برندها)</option>${S.d.profiles.map(p => `<option value="${p.id}" ${+sel === p.id ? 'selected' : ''}>خصوصی: ${E(p.name)}</option>`).join('')}`;
  return `<div class="card" style="margin-bottom:14px"><div class="card-h"><h3>${ic('plus')}افزودن سند</h3></div><div class="card-b stack s12">
      <div class="fg">${field('فایل Markdown یا متنی (چندتایی)', '<input type="file" class="inp" id="kbFiles" accept=".md,.markdown,.txt" multiple>', 'بر اساس سرفصل‌ها (#، ##، ###) به بخش تقسیم می‌شود؛ هر بخش جداگانه بازیابی می‌شود.')}
        ${field('دامنه', `<select class="inp" id="kbScope">${profOpts(0)}</select>`, 'عمومی برای همه‌ی پروفایل‌ها؛ خصوصی فقط برای همان برند')}
        ${field('فقط برای کانال', `<select class="inp" id="kbCh">${chOpts('')}</select>`, 'مثلاً سندی که فقط برای کامنت‌ها یا فقط گفتگوی سایت است')}
        ${field('فقط برای پست اینستاگرام', `<input class="inp ltr" id="kbMd" placeholder="شناسه‌ی پست (media id)" list="kbPosts">${postList()}`, 'رگ همان پست: برای کامنت‌های آن پست و دایرکتِ کسانی که زیرش کامنت گذاشته‌اند؛ همیشه همراه دستیار است')}</div>
      <details><summary class="small" style="cursor:pointer;color:var(--accent-ink)">یا متن را اینجا بچسبانید</summary><div class="stack s12" style="margin-top:10px">${field('عنوان', '<input class="inp" id="kbTitle">')}${field('متن', '<textarea class="inp" rows="6" id="kbText" placeholder="## سرفصل&#10;متن…"></textarea>')}</div></details>
      <div class="row"><button class="btn btn-p" data-act="kb-up">${ic('check')}افزودن به پایگاه دانش</button></div>
    </div></div>
    ${docs.length ? `<div class="card" style="margin-bottom:14px"><div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>سند</th><th>دامنه</th><th>بخش‌ها</th><th>فعال</th><th></th></tr></thead><tbody>
      ${docs.map(d => `<tr><td><b>${E(d.title)}</b>${d.filename ? `<div class="xs muted ltr" style="text-align:right">${E(d.filename)}</div>` : ''}</td>
        <td><select class="inp inp-sm" data-on-change="kb-scope" data-id="${d.id}">${profOpts(d.profile_id)}</select>
          <div class="row" style="gap:4px;margin-top:4px"><select class="inp inp-sm" data-on-change="kb-ch" data-id="${d.id}" style="max-width:150px">${chOpts(d.channel)}</select>${d.media_id ? `<span class="tag" title="فقط برای این پست">${ic('image')}${E(d.media_id)}</span>` : ''}</div></td>
        <td class="small">${fa(d.chunks)} بخش${+d.pinned ? ` · ${fa(d.pinned)} 📌` : ''}<div class="xs muted">${fa(nf(d.chars))} نویسه</div></td>
        <td>${sw('kbA' + d.id, !!+d.is_active, '', 'kb-act')}</td>
        <td class="w1"><div class="row" style="gap:4px"><button class="btn btn-sm" data-act="kb-view" data-id="${d.id}">بخش‌ها</button><button class="btn btn-sm btn-g" data-act="kb-del" data-id="${d.id}" aria-label="حذف">${ic('trash-simple')}</button></div></td></tr>`).join('')}
    </tbody></table></div></div>` : empty('list-checks', 'هنوز سندی ندارید', 'فایل md پایگاه دانش (مثل راهنمای فروشنده) را آپلود کنید تا دستیار با آن جواب بدهد.')}
    <div class="card"><div class="card-h"><h3>${ic('flask')}آزمایش بازیابی</h3><span class="xs muted">ببینید برای یک سؤال، کدام بخش‌ها به دستیار داده می‌شود</span></div><div class="card-b stack s12">
      <div class="row" style="gap:8px"><input class="inp" id="kbQ" placeholder="مثلاً: اجاره ساعتی دارید؟"><select class="inp" id="kbQP" style="max-width:200px">${S.d.profiles.map(p => `<option value="${p.id}">${E(p.name)}</option>`).join('')}</select><select class="inp" id="kbQC" style="max-width:160px">${chOpts('')}</select><input class="inp ltr" id="kbQM" placeholder="پست (اختیاری)" list="kbPosts" style="max-width:150px"><button class="btn" data-act="kb-test">آزمایش</button></div>
      <div id="kbOut"></div></div></div>`;
}
A['kb-up'] = async el => {
  const fd = new FormData(); const f = $('#kbFiles');
  [...(f && f.files || [])].forEach(x => fd.append('files[]', x));
  fd.append('profile_id', val('kbScope')); fd.append('channel', val('kbCh')); fd.append('media_id', val('kbMd')); if (val('kbText')) { fd.append('text', val('kbText')); fd.append('title', val('kbTitle')); }
  if (!(f && f.files.length) && !val('kbText')) return toast('فایل یا متنی انتخاب نشده', 'err');
  const r = await run(el, () => L().api('agent_kb_upload', fd, true));
  if (r.errors && r.errors.length) toast(r.errors.join(' · '), 'err');
  if (!r.ok) return r.error && !(r.errors || []).length ? toast(r.error, 'err') : null;
  toast('اضافه شد: ' + r.done.join('، ')); S.d.kb_docs = r.docs; paint();
};
ON['kb-scope'] = async el => { const r = await L().api('agent_kb_update', {id:+el.dataset.id, profile_id:+el.value}); if (!r.ok) return toast(r.error, 'err'); S.d.kb_docs = r.docs; toast('دامنه‌ی سند تغییر کرد'); };
ON['kb-ch'] = async el => { const r = await L().api('agent_kb_update', {id:+el.dataset.id, channel:el.value}); if (!r.ok) return toast(r.error, 'err'); S.d.kb_docs = r.docs; toast('کانال سند تغییر کرد'); };
ON['kb-act'] = async el => { const r = await L().api('agent_kb_update', {id:+el.id.slice(3), is_active:el.checked}); if (!r.ok) return toast(r.error, 'err'); S.d.kb_docs = r.docs; };
A['kb-del'] = el => confirm({title:'حذف سند', text:'سند و همه‌ی بخش‌هایش از پایگاه دانش حذف می‌شود.', ok:'حذف', danger:true, onOk:async () => { const r = await L().api('agent_kb_delete', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err'); S.d.kb_docs = r.docs; paint(); }});
A['kb-view'] = async el => {
  const d = (S.d.kb_docs || []).find(x => +x.id === +el.dataset.id);
  const r = await L().api('agent_kb_chunks', {id:+el.dataset.id}); if (!r.ok) return toast(r.error, 'err');
  drawer({title:E(d.title), sub:fa(r.chunks.length) + ' بخش · بخش‌های سنجاق‌شده (📌) همیشه همراه دستیارند؛ بقیه بر اساس سؤال مشتری بازیابی می‌شوند', size:'lg',
    body:`<div class="stack s12" style="padding:18px">${r.chunks.map(c => `<div class="card flat"><div class="card-b stack s6"><div class="row" style="gap:8px"><b class="small grow">${E(c.heading || d.title)}</b><span class="xs muted">${fa(c.len)} نویسه</span><label class="row xs">${sw('kbP' + c.id, !!+c.pinned, '', 'kb-pin')}<span>📌 همیشه</span></label></div>
      <div class="small soft" style="white-space:pre-wrap;max-height:160px;overflow:auto;line-height:1.8">${E(c.content)}</div></div></div>`).join('')}</div>`});
};
ON['kb-pin'] = async el => { const r = await L().api('agent_kb_pin', {id:+el.id.slice(3), on:el.checked}); if (!r.ok) toast(r.error, 'err'); };
A['kb-test'] = async el => {
  const r = await run(el, () => L().api('agent_kb_search', {q:val('kbQ'), profile_id:+val('kbQP'), channel:val('kbQC'), media_id:val('kbQM')})); if (!r.ok) return toast(r.error, 'err');
  const row = (h, pin) => `<div class="li"><div class="grow"><div class="small"><b>${pin ? '📌 ' : ''}${E(h.heading)}</b>${h.score != null ? ` <span class="xs muted">امتیاز ${fa(h.score)}</span>` : ''}</div><div class="xs muted">${E(h.preview)}…</div></div></div>`;
  $('#kbOut').innerHTML = `${r.hits.length ? r.hits.map(h => row(h)).join('') : '<p class="small muted">بخش مرتبطی پیدا نشد.</p>'}${r.pinned.length ? `<div class="xs muted" style="margin-top:8px">همیشه همراه: ${r.pinned.map(h => E(h.heading)).join('، ')}</div>` : ''}`;
};

/* ======================= پیگیری و نظرسنجی ======================= */
async function loadSurveys() { const r = await L().api('agent_surveys'); S.surveys = r.ok ? r.rows : []; if (S.tab === 'life') { const b = $('#agBody'); if (b) b.innerHTML = body(); } }
function life() {
  const st = S.d.settings;
  const card = (key, title, desc, inner) => `<div class="card" style="margin-bottom:12px"><div class="card-h"><h3>${title}</h3><label class="row small ms-auto">${sw('lf_' + key, st[key] === '1')}<span>فعال</span></label></div><div class="card-b stack s12"><p class="small muted" style="margin:0">${desc}</p>${inner}</div></div>`;
  const num = (id, v, min, max, step = 1) => `<input class="inp inp-sm ltr" type="number" id="${id}" min="${min}" max="${max}" step="${step}" value="${E(v)}" style="max-width:90px">`;
  const vars = '<span class="xs muted">متغیرها: {name} {title} {ref} {checkout_url} {check_in} {check_out}</span>';
  const sv = S.surveys || [];
  return `${card('fu_enabled', ic('chat-circle-dots') + 'پیگیری بعد از پیشنهاد', 'اگر دستیار گزینه پیشنهاد داد و مشتری جواب نداد و سفارشی هم ثبت نشد. در اینستاگرام فقط داخل پنجره‌ی ۲۴ ساعته‌ی متا فرستاده می‌شود.',
      `<div class="row wrap" style="gap:12px"><span class="small">بعد از</span>${num('lfFuH', st.fu_hours, 0.25, 23, 0.25)}<span class="small">ساعت · حداکثر</span>${num('lfFuMax', st.fu_max, 1, 3)}<span class="small">بار</span>
        <select class="inp inp-sm" id="lfFuMode" style="max-width:220px"><option value="ai" ${st.fu_mode === 'ai' ? 'selected' : ''}>متن را دستیار بنویسد (طبیعی‌تر)</option><option value="template" ${st.fu_mode !== 'ai' ? 'selected' : ''}>متن ثابت</option></select></div>
      ${field('متن ثابت (یا پشتیبان وقتی دستیار نتوانست)', `<textarea class="inp" rows="2" id="lfFuT">${E(st.fu_text)}</textarea>`)}`)}
    ${card('pay_enabled', ic('hand-grabbing') + 'یادآوری پرداخت', 'سفارش «منتظر پرداخت» که هنوز پرداخت نشده؛ قبل از ارسال، وضعیت تازه از وب‌سرویس گرفته می‌شود.',
      `<div class="row" style="gap:12px"><span class="small">بعد از</span>${num('lfPayM', st.pay_minutes, 3, 120)}<span class="small">دقیقه</span></div>${field('متن', `<textarea class="inp" rows="3" id="lfPayT">${E(st.pay_text)}</textarea>`, '', '', '')}${vars}`)}
    ${card('arr_enabled', ic('key') + 'پیام روز ورود', 'صبح روز ورود برای رزروهای پرداخت‌شده (اگر پنجره‌ی اینستاگرام بسته باشد و شماره داشته باشیم، با پیامک).',
      `<div class="row" style="gap:12px"><span class="small">از ساعت</span>${num('lfArrH', st.arr_hour, 0, 23)}</div>${field('متن', `<textarea class="inp" rows="3" id="lfArrT">${E(st.arr_text)}</textarea>`)}${vars}`)}
    ${card('sv_enabled', ic('check-circle') + 'نظرسنجی بعد از اقامت', 'روز خروج. پاسخ ۱ تا ۵ (یا «عالی»، «بد»…) در همان گفتگو ثبت می‌شود؛ امتیاز پایین به تیم اعلان می‌دهد و گفتگو را به همکار می‌سپارد.',
      `<div class="row wrap" style="gap:12px"><span class="small">از ساعت</span>${num('lfSvH', st.sv_hour, 0, 23)}<span class="small">· امتیاز پایین: کمتر یا مساوی</span>${num('lfSvLow', st.sv_low, 1, 4)}</div>
      ${field('سؤال', `<textarea class="inp" rows="2" id="lfSvT">${E(st.sv_text)}</textarea>`)}
      <div class="fg">${field('تشکر (امتیاز خوب)', `<textarea class="inp" rows="2" id="lfSvTh">${E(st.sv_thanks)}</textarea>`)}${field('پاسخ به امتیاز پایین', `<textarea class="inp" rows="2" id="lfSvTl">${E(st.sv_thanks_low)}</textarea>`)}</div>
      ${field('تشکر بعد از توضیح مشتری', `<input class="inp" id="lfSvTc" value="${E(st.sv_comment_thanks)}">`)}`)}
    ${card('rej_enabled', ic('arrow-counter-clockwise') + 'پیگیری بعد از رد شدن استعلام', 'اگر میزبان استعلام را رد کرد و مشتری بعد از آن جوابی نداد، دستیار با جستجوی تازه برای همان تاریخ و تعداد نفرات، ۲ تا ۳ گزینه‌ی مشابه پیشنهاد می‌دهد.',
      `<div class="row wrap" style="gap:12px"><span class="small">بعد از</span>${num('lfRejH', st.rej_hours, 0.25, 23, 0.25)}<span class="small">ساعت بی‌جوابی</span></div>
      <label class="row">${sw('lf_rej_instant', st.rej_instant === '1')}<span class="small">همان لحظه‌ی رد شدن هم دستیار گزینه‌ی جایگزین بفرستد (پیگیری بالا اگر باز هم جواب نداد می‌رود)</span></label>
      ${field('متن پشتیبان (وقتی دستیار نتوانست یا پنجره‌ی اینستاگرام بسته بود)', `<textarea class="inp" rows="2" id="lfRejT">${E(st.rej_text)}</textarea>`)}${vars}`)}
    ${card('rep_enabled', ic('chart-line-up') + 'گزارش روزانه به مدیران در بله', 'هر شب: گفتگوها، پیشنهادها، استعلام‌ها، سفارش‌ها و مبلغ پرداخت‌شده، امتیازهای پایین، هشدارهای امنیتی و گفتگوهای منتظر همکار. مدیر هر وقت به ربات «گزارش» بفرستد، گزارش همان لحظه را می‌گیرد.',
      `<div class="row wrap" style="gap:12px"><span class="small">ساعت ارسال</span>${num('lfRepH', st.rep_hour, 0, 23)}<span class="xs muted">(به وقت تهران)</span></div>
      ${field('شناسه‌ی چت بله‌ی مدیران', `<div class="row" style="gap:6px"><input class="inp ltr" id="lfRepC" value="${E(st.rep_chats)}" placeholder="مثلاً 123456789, 987654321"><button class="btn btn-sm" type="button" data-act="lf-rep-pick">${ic('users-three')}انتخاب</button></div>`, 'مدیر اول باید یک پیام به ربات بله‌ی پنل بدهد؛ بعد با «انتخاب» از فهرست گفتگوهای بله برش دارید. این چت‌ها از پاسخ دستیار مستثنا می‌شوند.')}
      <div class="row" style="gap:8px"><button class="btn btn-sm" type="button" data-act="lf-rep-prev">${ic('eye')}پیش‌نمایش گزارش امروز</button><button class="btn btn-sm" type="button" data-act="lf-rep-send">${ic('paper-plane-tilt')}ارسال همین حالا</button></div>`)}
    <div class="row" style="gap:8px;margin-bottom:16px"><button class="btn btn-p" data-act="lf-save">${ic('check')}ذخیره</button><button class="btn" data-act="lf-run" title="همان کاری که cron هر چند دقیقه انجام می‌دهد">${ic('play')}اجرای همین حالا</button></div>
    <div class="card"><div class="card-h"><h3>⭐ نظرسنجی‌ها</h3>${sv.length ? `<span class="acts small">میانگین ${fa((sv.filter(x => x.rating).reduce((a, x) => a + +x.rating, 0) / Math.max(1, sv.filter(x => x.rating).length)).toFixed(1))} از ۵ · ${fa(sv.length)} نظر</span>` : ''}</div>
      ${sv.length ? `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>مهمان</th><th>اقامتگاه</th><th>امتیاز</th><th>توضیح</th><th>زمان</th></tr></thead><tbody>${sv.map(x => `<tr><td><a href="#inbox.v${+x.person_id}">${E(x.person || '')}</a></td><td class="small">${E(x.title || '—')}<div class="xs muted ltr">${E(x.ref || '')}</div></td>
        <td>${x.rating ? pill('⭐ ' + fa(x.rating), +x.rating <= +st.sv_low ? 'err' : +x.rating >= 4 ? 'ok' : '') : '—'}</td><td class="small">${E(x.comment || '—')}</td><td class="xs muted">${E(L().fullDT ? L().fullDT(x.created_at) : x.created_at)}</td></tr>`).join('')}</tbody></table></div>`
        : `<div class="card-b small muted">${S.surveys ? 'هنوز نظری ثبت نشده.' : 'در حال بارگذاری…'}</div>`}</div>`;
}
A['lf-save'] = async el => {
  const settings = {fu_enabled:val('lf_fu_enabled'), fu_hours:toEn(val('lfFuH')), fu_max:toEn(val('lfFuMax')), fu_mode:val('lfFuMode'), fu_text:val('lfFuT'),
    pay_enabled:val('lf_pay_enabled'), pay_minutes:toEn(val('lfPayM')), pay_text:val('lfPayT'), arr_enabled:val('lf_arr_enabled'), arr_hour:toEn(val('lfArrH')), arr_text:val('lfArrT'),
    sv_enabled:val('lf_sv_enabled'), sv_hour:toEn(val('lfSvH')), sv_low:toEn(val('lfSvLow')), sv_text:val('lfSvT'), sv_thanks:val('lfSvTh'), sv_thanks_low:val('lfSvTl'), sv_comment_thanks:val('lfSvTc'),
    rej_enabled:val('lf_rej_enabled'), rej_instant:val('lf_rej_instant'), rej_hours:toEn(val('lfRejH')), rej_text:val('lfRejT'), rep_enabled:val('lf_rep_enabled'), rep_hour:toEn(val('lfRepH')), rep_chats:toEn(val('lfRepC'))};
  const r = await run(el, () => L().api('agent_save', {settings})); if (!r.ok) return toast(r.error, 'err');
  S.d.settings = r.settings; toast('تنظیمات پیگیری و نظرسنجی ذخیره شد');
};
A['lf-run'] = async el => { const r = await run(el, () => L().api('agent_lifecycle_run')); if (!r.ok) return toast(r.error, 'err'); const x = r.result || {};
  toast(x.skip ? 'دستیار خاموش است' : `پیگیری ${fa(x.followups || 0)} · بعد از رد ${fa(x.rejected || 0)} · یادآوری پرداخت ${fa(x.pay || 0)} · پیام ورود ${fa(x.arrival || 0)} · نظرسنجی ${fa(x.survey || 0)} · گزارش ${fa(x.report || 0)}`); S.surveys = null; loadSurveys(); };
A['lf-rep-prev'] = async el => { const r = await run(el, () => L().api('agent_report_preview')); if (!r.ok) return toast(r.error, 'err');
  modal({title:'پیش‌نمایش گزارش امروز', icon:'chart-line-up', body:`<div style="white-space:pre-wrap;line-height:1.9;font-size:13.5px;background:var(--accent-soft);padding:14px;border-radius:12px">${E(r.text)}</div>`}); };
A['lf-rep-send'] = async el => { await A['lf-save'](null); const r = await run(el, () => L().api('agent_report_send')); if (!r.ok) return toast(r.error, 'err'); toast(`گزارش برای ${fa(r.sent)} از ${fa(r.total)} مدیر فرستاده شد`); };
A['lf-rep-pick'] = async el => { const r = await L().api('agent_bale_contacts'); if (!r.ok) return toast(r.error, 'err');
  if (!r.rows.length) return toast('هنوز هیچ گفتگوی بله‌ای نیست؛ مدیر اول یک پیام به ربات بله بدهد', 'warn');
  EB.menu(el, [{h:'گفتگوهای اخیر بله'}].concat(r.rows.map(x => ({t:`${x.name}${x.username ? ' @' + x.username : ''} · ${x.uid}`, icon:'user-circle', fn:() => { const i = $('#lfRepC'); const cur = i.value.split(/[,،\s]+/).filter(Boolean); if (!cur.includes(x.uid)) cur.push(x.uid); i.value = cur.join(', '); }}))), {w:300}); };

/* ======================= امنیت ======================= */
async function loadSec() { const r = await L().api('agent_security'); S.sec = r.ok ? r.rows : []; if (S.tab === 'sec') { const b = $('#agBody'); if (b) b.innerHTML = body(); } }
const SECA = {warned:['هشدار', 'warn'], blocked:['مسدود', 'err'], paused:['دستیار متوقف شد', 'err'], replaced:['پاسخ جایگزین شد', 'err'], refused:['رد شد', 'warn']};
const SECK = {inbound:'پیام مشتری', output:'پاسخ دستیار', tool:'درخواست داده', sim:'آزمایش'};
function secView() {
  const st = S.d.settings, ev = S.sec;
  return `<div class="card" style="margin-bottom:14px"><div class="card-b stack s16">
      <label class="row">${sw('scOn', st.guard_enabled === '1')}<span><b>محافظ مهندسی پرامپت روشن باشد</b></span></label>
      <div class="callout">${ic('shield-check')}<span>هر پیام مشتری با الگوهای حمله (نادیده گرفتن دستورها، درخواست پرامپت/تنظیمات/توکن، ادعای مدیر یا برنامه‌نویس، تغییر نقش، درخواست اطلاعات مشتری‌ها و میزبان‌های دیگر، کاوش ابزارها، ترفند کدگذاری) امتیاز می‌گیرد. پاسخ دستیار هم پیش از ارسال بررسی می‌شود تا تکه‌ای از دستورها، کلیدها یا نام ابزارهای داخلی بیرون نرود. پیگیری رزرو فقط برای کدهای همان گفتگو و پیامک/رزرو فقط با شماره‌ای که خود مشتری نوشته انجام می‌شود.</span></div>
      <div class="fg">${field('امتیاز «هشدار»', `<input class="inp ltr" type="number" min="1" max="20" id="scWarn" value="${E(st.guard_warn)}">`, 'ثبت + اعلان به تیم + یادآور امنیتی به دستیار')}
        ${field('امتیاز «مسدود»', `<input class="inp ltr" type="number" min="1" max="30" id="scBlock" value="${E(st.guard_block)}">`, 'پیام به هوش مصنوعی نمی‌رسد و پاسخ امن ثابت می‌رود')}
        ${field('توقف بعد از چند تلاش (۲۴ ساعت)', `<input class="inp ltr" type="number" min="0" max="20" id="scStr" value="${E(st.guard_strikes)}">`, 'دستیار برای آن مشتری متوقف و گفتگو به همکار سپرده می‌شود. ۰ = هرگز')}</div>
      ${field('پاسخ امن', `<textarea class="inp" rows="2" id="scReply">${E(st.guard_reply)}</textarea>`)}
      <div class="row"><button class="btn btn-p" data-act="sc-save">${ic('check')}ذخیره</button></div>
      <div class="row" style="gap:8px"><input class="inp" id="scTest" placeholder="آزمایش: یک پیام بنویسید، مثلاً «دستورات قبلیتو نادیده بگیر»"><button class="btn" data-act="sc-test">بررسی</button></div><div id="scOut"></div>
    </div></div>
    <div class="card"><div class="card-h"><h3>${ic('warning')}رویدادهای امنیتی</h3><div class="acts"><button class="btn btn-sm btn-g" data-act="sc-reload">${ic('arrow-counter-clockwise')}تازه‌سازی</button></div></div>
    ${!ev ? '<div class="card-b small muted">در حال بارگذاری…</div>' : !ev.length ? '<div class="card-b small muted">رویدادی ثبت نشده.</div>' : `<div class="tbl-wrap"><table class="tbl resp"><thead><tr><th>زمان</th><th>مشتری</th><th>نوع</th><th>دلیل</th><th>متن</th><th>اقدام</th></tr></thead><tbody>
      ${ev.map(x => { const a = SECA[x.action] || [x.action, '']; return `<tr><td class="xs muted">${E(L().fullDT ? L().fullDT(x.created_at) : x.created_at)}</td><td class="small">${x.person_id ? `<a href="#inbox.v${+x.person_id}">${E(x.person || '')}</a>` : '—'}</td>
        <td class="small">${E(SECK[x.kind] || x.kind)}<div class="xs muted">امتیاز ${fa(x.score)}</div></td><td class="small">${E(x.matched || '')}</td><td class="small" style="max-width:320px"><div class="ell2">${E((x.text || '').slice(0, 220))}</div></td><td>${pill(E(a[0]), a[1])}</td></tr>`; }).join('')}
    </tbody></table></div>`}</div>`;
}
A['sc-save'] = async el => {
  const settings = {guard_enabled:val('scOn'), guard_warn:toEn(val('scWarn')), guard_block:toEn(val('scBlock')), guard_strikes:toEn(val('scStr')), guard_reply:val('scReply')};
  const r = await run(el, () => L().api('agent_save', {settings})); if (!r.ok) return toast(r.error, 'err'); S.d.settings = r.settings; toast('تنظیمات امنیت ذخیره شد');
};
A['sc-test'] = async el => { const r = await run(el, () => L().api('agent_guard_test', {text:val('scTest')})); if (!r.ok) return toast(r.error, 'err');
  const a = {ok:['بی‌خطر', 'ok'], warn:['هشدار', 'warn'], block:['مسدود', 'err']}[r.action];
  $('#scOut').innerHTML = `<div class="small">${pill(a[0], a[1])} امتیاز ${fa(r.score)}${r.labels.length ? ' · ' + E(r.labels.join('، ')) : ''}</div>`; };
A['sc-reload'] = () => { S.sec = null; const b = $('#agBody'); if (b) b.innerHTML = body(); loadSec(); };

})();
