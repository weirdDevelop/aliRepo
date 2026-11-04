/* هسته‌ی پنل: ابزارها، ترجیحات ظاهر، پوسته، مسیریاب، کامپوننت‌ها، نمودارها */
(function(){
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const E = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const fa = v => String(v ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
const nf = (n, d = 0) => Number(n || 0).toLocaleString('fa-IR', {maximumFractionDigits:d});
const pct = (n, d = 1) => nf(n, d) + '٪';
const compact = n => n >= 1e9 ? nf(n / 1e9, 2) + ' میلیارد' : n >= 1e6 ? nf(n / 1e6, 1) + ' میلیون' : n >= 1e4 ? nf(n / 1e3, 1) + ' هزار' : nf(n);
const toman = n => nf(n) + ' <span class="muted xs">تومان</span>';
const rel = m => m < 1 ? 'همین حالا' : m < 60 ? fa(m) + ' دقیقه پیش' : m < 1440 ? fa(Math.round(m / 60)) + ' ساعت پیش' : m < 2880 ? 'دیروز' : fa(Math.round(m / 1440)) + ' روز پیش';
const clock = m => { const d = new Date(Date.now() - m * 60000); const today = new Date().toDateString() === d.toDateString(); if (!today) return m < 2880 ? 'دیروز' : fa(Math.round(m / 1440)) + ' روز'; return fa(String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0')); };
const todayFa = () => { try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {weekday:'long', day:'numeric', month:'long', year:'numeric'}).format(new Date()); } catch(e) { return ''; } };
const uid = () => Math.random().toString(36).slice(2, 9);
const debounce = (f, t = 200) => { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => f(...a), t); }; };
const store = {
  get(k, d) { try { const v = localStorage.getItem('eb.' + k); return v == null ? d : JSON.parse(v); } catch(e) { return d; } },
  set(k, v) { try { localStorage.setItem('eb.' + k, JSON.stringify(v)); } catch(e) {} }
};

/* ---------- آیکون، آواتار، نشان ---------- */
const ic = (n, cls = '') => `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${n}"></use></svg>`;
const AV_COLORS = ['#1D5C4D','#8A5A1C','#3D5A80','#7A3E48','#4F6B3A','#2E6670','#6B4F7A','#9A4E2A','#47535E','#7D6A2B'];
const hash = s => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) | 0; return Math.abs(h); };
const initials = n => { const p = String(n || '؟').trim().split(/\s+/); return (p[0][0] || '') + (p[1] ? '‌' + p[1][0] : ''); };
function av(name, o = {}) {
  const color = o.color || AV_COLORS[hash(name) % AV_COLORS.length];
  // ۱.۰.۱۳: تصویر پروفایل همکاران (اگر آپلود شده)
  const img = o.img !== undefined ? o.img : (!o.ch && window.D && D.ops ? ((D.ops.find(x => x.name === name && x.avatar) || {}).avatar || '') : '');
  if (img) { const st0 = o.st ? `<span class="st" style="background:${o.st === 'online' ? 'var(--ok)' : o.st === 'away' ? 'var(--warn)' : 'var(--ink-4)'}"></span>` : ''; return `<span class="av ${o.size || ''}" style="background:${color};overflow:visible" title="${E(name)}"><img src="${E(img)}" alt="" style="width:100%;height:100%;border-radius:inherit;object-fit:cover">${st0}</span>`; }
  const ch = o.ch ? `<span class="chb" style="background:${D.CH[o.ch].color}">${ic(D.CH[o.ch].icon)}</span>` : '';
  const st = o.st ? `<span class="st" style="background:${o.st === 'online' ? 'var(--ok)' : o.st === 'away' ? 'var(--warn)' : 'var(--ink-4)'}"></span>` : '';
  return `<span class="av ${o.size || ''}" style="background:${color}" title="${E(name)}">${E(initials(name))}${ch}${st}</span>`;
}
const chi = (ch, size = '') => `<span class="chi ${size}" style="background:${D.CH[ch].color}" title="${D.CH[ch].name}">${ic(D.CH[ch].icon)}</span>`;
const chTxt = ch => `<span class="chtxt" style="color:${D.CH[ch].color}">${ic(D.CH[ch].icon)}${D.CH[ch].name}</span>`;
const pill = (t, cls = '', icon) => `<span class="pill ${cls}">${icon ? ic(icon) : ''}${t}</span>`;
const tagHtml = (id, removable) => { const t = D.tags.find(x => x.id === id) || D.groups.find(x => x.id === id); if (!t) return ''; return `<span class="tag"><i class="dot" style="background:${t.color}"></i>${E(t.name)}${removable ? `<button data-act="${removable}" data-id="${id}" aria-label="حذف">${ic('x')}</button>` : ''}</span>`; };
const miniTag = id => { const t = D.tags.find(x => x.id === id); return t ? `<span class="mini-tag${t.hot ? ' hot' : ''}"${t.hot ? ' style="background:#FEE4E2;color:#B42318;font-weight:700"' : ''}>${t.hot ? ic('phone-call') : `<i class="dot" style="background:${t.color}"></i>`}${E(t.name)}</span>` : ''; };
const sw = (id, on, label = '', act = '') => `<label class="sw"><input type="checkbox" id="${id}" ${on ? 'checked' : ''} ${act ? `data-on-change="${act}"` : ''}><i></i>${label ? `<span>${label}</span>` : ''}</label>`;
const seg = (name, opts, cur, act = 'seg') => `<div class="seg" role="tablist" data-name="${name}">${opts.map(o => `<button type="button" class="${o[0] === cur ? 'on' : ''}" data-act="${act}" data-name="${name}" data-v="${o[0]}">${o[2] ? ic(o[2]) : ''}${o[1]}</button>`).join('')}</div>`;
const tabs = (items, cur, act = 'tab', extra = '') => `<div class="tabs" role="tablist" ${extra}>${items.map(t => `<button type="button" class="tab ${t[0] === cur ? 'on' : ''}" data-act="${act}" data-v="${t[0]}">${t[2] ? ic(t[2]) : ''}${t[1]}${t[3] != null ? `<span class="cnt">${fa(t[3])}</span>` : ''}</button>`).join('')}</div>`;
const empty = (icon, title, text, btn = '') => `<div class="empty"><div class="e-ic">${ic(icon)}</div><h4>${title}</h4><p>${text}</p>${btn}</div>`;
const field = (label, input, hint = '', cls = '', err = '') => `<div class="field ${cls}"><label>${label}</label>${input}${hint ? `<span class="hint">${hint}</span>` : ''}${err ? `<span class="ferr">${err}</span>` : ''}</div>`;
const delta = (v, inverse) => { const up = v >= 0; const good = inverse ? !up : up; return `<span class="delta ${good ? 'up' : 'down'}">${ic(up ? 'arrow-up' : 'arrow-down')}${pct(Math.abs(v))}</span>`; };
const contact = id => D.contacts.find(c => c.id === id);
const op = id => D.ops.find(o => o.id === id);
const page = id => D.pages.find(p => p.id === id);
const media = (tone, kind, cls = '', ovl = '') => `<div class="media-ph ${cls}" style="background:${tone[0]};color:${tone[1]}">${ic(tone[2], 'big')}${kind && kind !== 'post' ? `<span class="kind">${ic(kind === 'reel' ? 'film-strip' : kind === 'carousel' ? 'copy-simple' : 'circle')}</span>` : ''}${ovl}</div>`;
const sk = (rows = 3) => Array.from({length: rows}, (_, i) => `<div class="sk sk-line" style="width:${[92, 70, 84, 58, 76][i % 5]}%"></div>`).join('');

/* ---------- ترجیحات ظاهر ---------- */
const ACCENTS = {
  pine:{name:'کاج و زعفران', l:['#1D5C4D','#134236','#E3EEE9','#FFFFFF'], d:['#5CC2A2','#9BDCC6','#16302A','#06140F'], side:'#0F2A25', g:['#A97A22','#E2B45C']},
  lapis:{name:'لاجورد و طلا', l:['#22407A','#172D58','#E6EBF5','#FFFFFF'], d:['#89A8E8','#BACBF2','#18233D','#0A1122'], side:'#101B33', g:['#A97A22','#E2B45C']},
  turquoise:{name:'فیروزه و نقره', l:['#0E6A70','#094E53','#DFF0F0','#FFFFFF'], d:['#55C4C8','#98DFE1','#0F2B2D','#041618'], side:'#0C2628', g:['#7D8894','#C3CBD4']},
  emerald:{name:'زمرد و کهربا', l:['#156B47','#0D5034','#E1F1E8','#FFFFFF'], d:['#5ACB91','#9BE3BB','#132A1E','#06190F'], side:'#0D271C', g:['#B26B12','#E9A44A']},
  plum:{name:'آلو و مس', l:['#5A2D5C','#432145','#F1E7F1','#FFFFFF'], d:['#C99ACB','#E1C5E2','#2A1A2B','#150A16'], side:'#24132A', g:['#A8653A','#DB9A6C']},
  graphite:{name:'گرافیت و کهربا', l:['#2D3540','#1D232B','#E9EBEE','#FFFFFF'], d:['#C8D0DA','#E4E8ED','#252B34','#0E1116'], side:'#15191F', g:['#B9741A','#E7A54B']},
};
const FONTS = {
  vazir:{name:'وزیرمتن', fam:"'Vazirmatn'", q:'Vazirmatn:wght@400;500;600;700;800'},
  plex:{name:'IBM Plex عربی', fam:"'IBM Plex Sans Arabic'", q:'IBM+Plex+Sans+Arabic:wght@400;500;600;700'},
  noto:{name:'نوتو سنس', fam:"'Noto Sans Arabic'", q:'Noto+Sans+Arabic:wght@400;500;600;700'},
  readex:{name:'ریدکس', fam:"'Readex Pro'", q:'Readex+Pro:wght@300;400;500;600;700'},
};
const DFONTS = {
  markazi:{name:'مرکزی (نسخ)', fam:"'Markazi Text'", q:'Markazi+Text:wght@500;600;700', big:1.28},
  kufi:{name:'نوتو کوفی', fam:"'Noto Kufi Arabic'", q:'Noto+Kufi+Arabic:wght@500;600;700', big:1},
  lalezar:{name:'لاله‌زار', fam:"'Lalezar'", q:'Lalezar', big:1.08},
  same:{name:'همان فونت متن', fam:'', q:'', big:1},
};
const RADII = {sharp:[4, 6, 'تیز'], soft:[8, 12, 'نرم'], round:[12, 18, 'گرد']};
const DENS = {compact:[32, 28, 14, 8, 'فشرده'], normal:[36, 30, 16, 11, 'متعادل'], comfy:[40, 34, 20, 14, 'راحت']};
const DEF_PREFS = {theme:'system', accent:'pine', custom:'', font:'vazir', dfont:'markazi', radius:'soft', density:'normal', fs:14, side:'brand', surface:'raised', collapsed:false, motion:true};
const P = Object.assign({}, DEF_PREFS, store.get('prefs2', {}));
const hexMix = (a, b, t) => { const p = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16)); const x = p(a), y = p(b); return '#' + x.map((v, i) => Math.round(v + (y[i] - v) * t).toString(16).padStart(2, '0')).join(''); };
const lum = h => { const [r, g, b] = [1, 3, 5].map(i => { let c = parseInt(h.slice(i, i + 2), 16) / 255; return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); }); return .2126 * r + .7152 * g + .0722 * b; };
function accentFromHex(hex) {
  const on = lum(hex) > .36 ? '#10141C' : '#FFFFFF';
  return {l:[hex, hexMix(hex, '#000000', .22), hexMix(hex, '#FFFFFF', .9), on], d:[hexMix(hex, '#FFFFFF', .35), hexMix(hex, '#FFFFFF', .6), hexMix(hex, '#141922', .78), '#0B0F16'], side:hexMix(hex, '#0A0F0E', .72), g:['#A97A22', '#E2B45C']};
}
const loadedFonts = new Set();
function loadFont(f) {
  if (!f || !f.q || loadedFonts.has(f.q)) return; loadedFonts.add(f.q);
  if (window.EB_BOOT && /^(Vazirmatn|Markazi)/.test(f.q)) return; // از assets/fonts.css می‌آیند
  if (document.querySelector('link[href*="' + f.q.split(':')[0] + '"]')) return;
  const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://fonts.googleapis.com/css2?family=' + f.q + '&display=swap'; document.head.appendChild(l);
}
function applyPrefs() {
  const r = document.documentElement, s = r.style;
  if (P.theme === 'system') r.removeAttribute('data-theme'); else r.setAttribute('data-theme', P.theme);
  const a = P.accent === 'custom' && /^#[0-9a-f]{6}$/i.test(P.custom) ? accentFromHex(P.custom) : (ACCENTS[P.accent] || ACCENTS.pine);
  [['a-l', a.l[0]], ['a-l-ink', a.l[1]], ['a-l-soft', a.l[2]], ['a-l-on', a.l[3]], ['a-d', a.d[0]], ['a-d-ink', a.d[1]], ['a-d-soft', a.d[2]], ['a-d-on', a.d[3]], ['brand-side', a.side], ['g-l', a.g[0]], ['g-d', a.g[1]]].forEach(([k, v]) => s.setProperty('--' + k, v));
  const bf = FONTS[P.font] || FONTS.vazir, df = DFONTS[P.dfont] || DFONTS.markazi; loadFont(bf); loadFont(df);
  s.setProperty('--font', bf.fam + ",'Vazirmatn',Tahoma,system-ui,sans-serif");
  s.setProperty('--display', (df.fam || bf.fam) + ",'Vazirmatn',serif"); s.setProperty('--dz', df.big);
  const rd = RADII[P.radius] || RADII.soft; s.setProperty('--r', rd[0] + 'px'); s.setProperty('--r-lg', rd[1] + 'px');
  const dn = DENS[P.density] || DENS.normal; s.setProperty('--h', dn[0] + 'px'); s.setProperty('--h-sm', dn[1] + 'px'); s.setProperty('--pad', dn[2] + 'px'); s.setProperty('--row', dn[3] + 'px');
  s.setProperty('--fs', P.fs + 'px');
  r.setAttribute('data-side', P.side); r.setAttribute('data-surface', P.surface);
  r.setAttribute('data-collapsed', P.collapsed ? '1' : '0');
  r.classList.toggle('no-motion', !P.motion);
}
let prefSync;
function setPref(k, v, silent) { P[k] = v; store.set('prefs2', P); applyPrefs(); if (!silent) UI.emit('prefs'); if (window.EB_LIVE) { clearTimeout(prefSync); prefSync = setTimeout(() => EB_LIVE.api('desk_prefs', {prefs:P}), 600); } }
function mergeServerPrefs(sp) { if (!sp || typeof sp !== 'object') return; Object.keys(DEF_PREFS).forEach(k => { if (sp[k] !== undefined) P[k] = sp[k]; }); store.set('prefs2', P); applyPrefs(); }

/* ---------- لایه‌ها: مودال، کشو، منو، اعلان ---------- */
const layers = [];
function openLayer(el, cls) {
  const bd = document.createElement('div'); bd.className = 'backdrop';
  document.body.append(bd, el);
  requestAnimationFrame(() => { bd.classList.add('in'); el.classList.add('in'); });
  const L = { el, close(){ el.classList.remove('in'); bd.classList.remove('in'); setTimeout(() => { el.remove(); bd.remove(); }, 230); const i = layers.indexOf(L); if (i > -1) layers.splice(i, 1); L.onclose && L.onclose(); } };
  bd.addEventListener('click', () => L.close());
  layers.push(L);
  setTimeout(() => { const f = el.querySelector('[autofocus],input:not([type=checkbox]):not([type=radio]):not([type=file]),textarea'); if (f && window.innerWidth > 767) f.focus(); }, 60);
  return L;
}
function modal({title, body = '', foot = '', size = '', icon}) {
  const el = document.createElement('div'); el.className = 'modal ' + size; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
  el.innerHTML = `<div class="m-h">${icon ? `<span class="chi md" style="background:var(--accent-soft);color:var(--accent-ink)">${ic(icon)}</span>` : ''}<h3>${title}</h3><button class="btn btn-g btn-ic btn-sm x" data-close aria-label="بستن">${ic('x')}</button></div><div class="m-b">${body}</div>${foot ? `<div class="m-f">${foot}</div>` : ''}`;
  const L = openLayer(el); el.addEventListener('click', e => { if (e.target.closest('[data-close]')) L.close(); }); return L;
}
function drawer({title, sub = '', body = '', foot = '', size = '', head = ''}) {
  const el = document.createElement('aside'); el.className = 'drawer ' + size; el.setAttribute('role', 'dialog');
  el.innerHTML = `<div class="m-h">${head}<div class="grow" style="min-width:0"><h3>${title}</h3>${sub ? `<div class="muted small">${sub}</div>` : ''}</div><button class="btn btn-g btn-ic btn-sm" data-close aria-label="بستن">${ic('x')}</button></div><div class="m-b scroll" style="padding:0">${body}</div>${foot ? `<div class="m-f">${foot}</div>` : ''}`;
  const L = openLayer(el); el.addEventListener('click', e => { if (e.target.closest('[data-close]')) L.close(); }); return L;
}
function confirm({title, text, ok = 'تأیید', danger, onOk}) {
  const L = modal({title, size:'sm', body:`<p class="soft">${text}</p>`, foot:`<button class="btn" data-close>انصراف</button><button class="btn ${danger ? 'btn-dp' : 'btn-p'}" data-ok>${ok}</button>`});
  L.el.querySelector('[data-ok]').onclick = () => { L.close(); onOk && onOk(); };
  return L;
}
let popEl = null;
function closePop() { if (popEl) { popEl.remove(); popEl = null; } }
function menu(anchor, items, opt = {}) {
  closePop();
  const el = document.createElement('div'); el.className = 'pop'; el.setAttribute('role', 'menu');
  el.innerHTML = items.map((it, i) => it === '-' ? '<hr>' : it.h ? `<div class="ph2">${it.h}</div>` : it.html ? it.html : `<button class="pi ${it.danger ? 'danger' : ''}" data-i="${i}" role="menuitem">${it.av || (it.icon ? ic(it.icon) : '')}${it.dot ? `<i class="dot" style="background:${it.dot}"></i>` : ''}<span class="grow ell">${it.t}</span>${it.kbd ? `<kbd>${it.kbd}</kbd>` : ''}${it.on ? ic('check', 'chk') : ''}</button>`).join('');
  document.body.appendChild(el); popEl = el;
  const r = anchor.getBoundingClientRect(); const w = Math.max(el.offsetWidth, opt.w || 0); if (opt.w) el.style.width = opt.w + 'px';
  let left = opt.alignLeft ? r.left : r.right - w; left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
  let top = r.bottom + 6; if (top + el.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - el.offsetHeight - 6);
  el.style.left = left + 'px'; el.style.top = top + 'px';
  requestAnimationFrame(() => el.classList.add('in'));
  el.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (!b) return; const it = items[+b.dataset.i]; closePop(); it.fn && it.fn(); });
  return el;
}
function toast(msg, type = 'ok', action) {
  let box = $('.toasts'); if (!box) { box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
  const t = document.createElement('div'); t.className = 'toast ' + type;
  t.innerHTML = `${ic(type === 'ok' ? 'check-circle' : type === 'err' ? 'warning-circle' : type === 'warn' ? 'warning' : 'info')}<span>${msg}</span>${action ? `<button>${action[0]}</button>` : ''}`;
  if (action) t.querySelector('button').onclick = () => { action[1](); t.remove(); };
  box.appendChild(t);
  let h = setTimeout(() => t.remove(), 3800);
  t.onmouseenter = () => clearTimeout(h); t.onmouseleave = () => { h = setTimeout(() => t.remove(), 1800); };
}
function copy(text, el) {
  const done = () => toast('کپی شد');
  try { navigator.clipboard.writeText(text).then(done, () => fallbackCopy(text, done)); } catch(e) { fallbackCopy(text, done); }
}
function fallbackCopy(text, done) { const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch(e) { toast('متن را انتخاب و کپی کنید', 'warn'); } ta.remove(); }
function busy(btn, ms = 900, then) { btn.classList.add('loading'); setTimeout(() => { btn.classList.remove('loading'); then && then(); }, ms); }

/* ---------- نمودارها (SVG، اندازه‌گیری‌شده با عرض واقعی) ---------- */
const CHARTS = {};
let chartSeq = 0;
function chartBox(def, h = 200) { const id = 'ch' + (++chartSeq); CHARTS[id] = Object.assign({h}, def); return `<div class="chart-box" data-chart="${id}" style="height:${h}px"></div>`; }
function niceMax(v) { if (v <= 0) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); const n = v / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p; }
function drawChart(box) {
  const def = CHARTS[box.dataset.chart]; if (!def) return;
  const W = Math.max(box.clientWidth, 120), H = def.h, padL = 44, padR = 12, padT = 12, padB = 26;
  const n = def.labels.length; const all = def.series.flatMap(s => s.data); const max = niceMax(Math.max(...all) * 1.05); const iw = W - padL - padR, ih = H - padT - padB;
  const x = i => def.type === 'bar' ? padL + iw * (i + .5) / n : padL + (n === 1 ? iw / 2 : iw * i / (n - 1));
  const y = v => padT + ih - ih * v / max;
  const fmt = def.fmt || (v => compact(v));
  let g = '';
  for (let k = 0; k <= 4; k++) { const v = max * k / 4; const yy = y(v); g += `<line class="grid-l" x1="${padL}" x2="${W - padR}" y1="${yy}" y2="${yy}"/><text x="${padL - 8}" y="${yy + 4}" text-anchor="end">${fmt(v)}</text>`; }
  const step = Math.ceil(n / Math.max(2, Math.floor(iw / 64)));
  def.labels.forEach((l, i) => { if (i % step === 0 || i === n - 1) g += `<text x="${x(i)}" y="${H - 6}" text-anchor="middle">${E(l)}</text>`; });
  let marks = '';
  if (def.type === 'bar') {
    const bw = Math.min(28, iw / n * .56); const ns = def.series.length; const sw2 = bw / ns;
    def.series.forEach((s, si) => s.data.forEach((v, i) => { const bx = x(i) - bw / 2 + si * sw2; const by = y(v); const hh = Math.max(1, padT + ih - by); marks += `<path class="${si === 0 ? 'br' : 'bra'}" d="M${bx},${padT + ih} v${-(hh - 3)} q0,-3 3,-3 h${sw2 - 8} q3,0 3,3 v${hh - 3} z" ${s.color ? `style="fill:${s.color}"` : ''}/>`; }));
  } else {
    def.series.forEach((s, si) => {
      const pts = s.data.map((v, i) => [x(i), y(v)]);
      const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
      if (si === 0 && def.area !== false) marks += `<path class="ar" d="${d}L${pts[pts.length - 1][0]},${padT + ih}L${pts[0][0]},${padT + ih}Z"/>`;
      marks += `<path class="${si === 0 ? 'ln' : 'ln2'}" d="${d}" ${s.color ? `style="stroke:${s.color}"` : ''}/>`;
      if (si === 0) { const lp = pts[pts.length - 1]; marks += `<circle class="pt" cx="${lp[0]}" cy="${lp[1]}" r="4"/>`; }
    });
  }
  box.innerHTML = `<svg class="chart" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img" aria-label="${E(def.aria || 'نمودار')}">${g}${marks}<line class="hov" x1="0" x2="0" y1="${padT}" y2="${padT + ih}" style="display:none"/></svg>`;
  const svg = box.firstChild, hov = svg.querySelector('.hov');
  svg.onmousemove = e => {
    const r = svg.getBoundingClientRect(); const px = e.clientX - r.left;
    let i = def.type === 'bar' ? Math.floor((px - padL) / iw * n) : Math.round((px - padL) / iw * (n - 1)); i = Math.max(0, Math.min(n - 1, i));
    hov.setAttribute('x1', x(i)); hov.setAttribute('x2', x(i)); hov.style.display = '';
    let tip = $('.chart-tip'); if (!tip) { tip = document.createElement('div'); tip.className = 'chart-tip'; document.body.appendChild(tip); }
    tip.innerHTML = `<b>${E(def.labels[i])}</b>` + def.series.map(s => `<div>${E(s.name)}: ${(def.tipFmt || nf)(s.data[i])}</div>`).join('');
    tip.style.left = Math.min(window.innerWidth - tip.offsetWidth - 8, e.clientX + 12) + 'px'; tip.style.top = (e.clientY - tip.offsetHeight - 10) + 'px'; tip.style.display = 'block';
  };
  svg.onmouseleave = () => { hov.style.display = 'none'; const t = $('.chart-tip'); if (t) t.style.display = 'none'; };
}
const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(debounce(ents => ents.forEach(en => drawChart(en.target)), 80)) : null;
function drawCharts(root = document) { $$('.chart-box', root).forEach(b => { drawChart(b); ro && ro.observe(b); }); }
function spark(data, color = 'var(--accent)', h = 30) {
  const w = 120, max = Math.max(...data), min = Math.min(...data), r = max - min || 1;
  const pts = data.map((v, i) => [(i / (data.length - 1)) * w, h - 3 - ((v - min) / r) * (h - 6)]);
  const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('');
  return `<svg class="spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true"><path d="${d}L${w},${h}L0,${h}Z" style="fill:${color};opacity:.1"/><path d="${d}" style="fill:none;stroke:${color};stroke-width:1.6;vector-effect:non-scaling-stroke"/></svg>`;
}
const hbars = (rows, max) => { max = max || Math.max(...rows.map(r => r.v)); return rows.map(r => `<div class="hbar"><span class="ell">${r.l}</span><div class="bar"><i style="width:${(r.v / max * 100).toFixed(1)}%;${r.c ? 'background:' + r.c : ''}"></i></div><span class="v">${r.f ? r.f(r.v) : nf(r.v)}</span></div>`).join(''); };

/* ---------- رویدادها ---------- */
const A = {};        // اکشن‌های کلیکی: data-act
const ON = {};       // تغییر/ورودی: data-on-change / data-on-input
ON['site-switch'] = el => { try { localStorage.setItem('ebSite', el.value); } catch (e) {} location.reload(); };   // ۲.۱.۱: انتخاب‌گر سایت
const bus = {};
const UI = {
  on(ev, f) { (bus[ev] = bus[ev] || []).push(f); },
  set(ev, f) { bus[ev] = [f]; },
  emit(ev, x) { (bus[ev] || []).forEach(f => f(x)); },
};
document.addEventListener('click', e => {
  if (popEl && !e.target.closest('.pop') && !e.target.closest('[data-pop]')) closePop();
  const t = e.target.closest('[data-act]'); if (!t) return;
  const f = A[t.dataset.act]; if (f) { if (t.tagName === 'A') e.preventDefault(); f(t, e); }
});
document.addEventListener('change', e => { const n = e.target.dataset && e.target.dataset.onChange; if (n && ON[n]) ON[n](e.target, e); });
document.addEventListener('input', e => { const n = e.target.dataset && e.target.dataset.onInput; if (n && ON[n]) ON[n](e.target, e); });
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { if ($('.cmdk')) { closeCmdk(); return; } if (popEl) { closePop(); return; } if (layers.length) { layers[layers.length - 1].close(); return; } const so = $('.side.open,.ib-nav.open,.ig-side.open,.set-nav.open,.inbox.prof-open'); if (so) { so.classList.remove('open', 'prof-open'); return; } }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openCmdk(); return; }
  const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
  if (typing || e.ctrlKey || e.metaKey || e.altKey) return;
  if (e.key === '?') { A['shortcuts'](); return; }
  if (e.key === '/') { e.preventDefault(); openCmdk(); return; }
  UI.emit('key', e);
});
A['seg'] = el => { $$('button', el.parentElement).forEach(b => b.classList.toggle('on', b === el)); UI.emit('seg:' + el.dataset.name, el.dataset.v); };
A['copy'] = el => copy(el.dataset.text || el.closest('.copyrow')?.querySelector('.code')?.textContent || '', el);
A['go'] = el => go(el.dataset.to);
A['toast'] = el => toast(el.dataset.msg || 'انجام شد', el.dataset.type || 'ok');
A['sec-toggle'] = el => el.parentElement.classList.toggle('closed');

/* ---------- مسیریاب ---------- */
const PAGES = {};
const NAV = [
  {items:['dashboard']},
  {h:'گفتگو', items:['inbox','instagram','webchat','chatplus']},
  {h:'فروش و تماس', items:['crm_calls','callbacks','crm_pipeline','crm_leads','crm_inquiries','crm_sales']},
  {h:'مشتریان', items:['persons','crm_customers','groups']},
  {h:'میزبانان', items:['crm_hosts','crm_host_leads']},
  {h:'پشتیبانی و کار', items:['crm_tickets','crm_tasks','crm_chat']},
  {h:'ارسال و کمپین', items:['send','safir','campaigns','scheduled','queue','sendlog']},
  {h:'اتوماسیون', items:['agent','flows','botbuilder','autoreply','forms','ai']},
  {h:'سایت و سئو', items:['widgets','notices','analytics','seo']},
  {h:'محتوا', items:['templates','links']},
  {h:'تیم و منابع انسانی', items:['crm_staff','crm_leaves','crm_payroll','crm_me']},
  {h:'گزارش', items:['crm_dash','crm_reports','support','marketing','stats','apilog']},
];
function reg(slug, def) { PAGES[slug] = def; }
let current = {slug:null};
function go(h) { if (location.hash.slice(1) === h) route(true); else location.hash = h; }
function parse() { const h = decodeURIComponent(location.hash.slice(1)) || (window.EB_BOOT && EB_BOOT.page) || 'dashboard'; const [slug, ...args] = h.split('.'); return {slug: PAGES[slug] ? slug : 'dashboard', args}; }
function route(force) {
  const {slug, args} = parse(); const def = PAGES[slug]; const view = $('#vpage');
  // صفحه‌های قدیمی در قاب‌های نگه‌داشته‌شده نمایش داده می‌شوند (بازگشت به آن‌ها فوری است)
  const lg = $('#vlegacy'); if (lg) { lg.hidden = !def.legacy; view.hidden = !!def.legacy; }
  closePop(); $$('.side.open').forEach(x => x.classList.remove('open'));
  if (current.slug === slug && def.update && !force) { current.args = args; def.update(args); return; }
  if (current.slug && PAGES[current.slug] && PAGES[current.slug].leave) PAGES[current.slug].leave();
  current = {slug, args};
  $$('.nav-i').forEach(a => a.classList.toggle('on', a.dataset.to === slug));
  $$('.mob-nav button').forEach(b => b.classList.toggle('on', b.dataset.to === slug));
  $('#crumb').innerHTML = `<h1>${def.title}</h1>${def.sub ? `<span class="muted ell">${def.sub}</span>` : ''}`;
  document.title = def.title + ' · ' + ((window.D && D.company && D.company.name) || 'اعلان‌بان');
  view.innerHTML = def.full ? `<div style="padding:24px">${skelPage()}</div>` : `<div class="page-scroll scroll"><div class="page">${skelPage()}</div></div>`;
  const token = current;
  Promise.resolve(def.load ? def.load(args) : null).then(() => {
    if (current !== token) return;
    if (def.full) { view.innerHTML = ''; def.render(view, args); }
    else { view.innerHTML = `<div class="page-scroll scroll"><div class="page ${def.wide ? 'wide' : ''}"></div></div>`; def.render($('.page', view), args); }
    drawCharts(view);
  }).catch(err => {
    if (current !== token) return;
    view.innerHTML = `<div class="page-scroll scroll"><div class="page">${empty('warning-circle', 'این صفحه بارگذاری نشد', E(err && err.message || 'خطای نامشخص'), '<button class="btn" data-act="route-retry">' + ic('arrow-clockwise') + 'تلاش دوباره</button>')}</div></div>`;
  });
  firstRoute = false;
}
A['route-retry'] = () => route(true);
A['imp-stop'] = async el => { el.classList.add('loading'); const r = await window.EB_LIVE.api('desk_impersonate_stop'); if (!r.ok) { el.classList.remove('loading'); return toast(r.error, 'err'); } location.href = 'index.php#settings.operators'; setTimeout(() => location.reload(), 50); };
let firstRoute = true;
function skelPage() { return `<div class="sk" style="height:26px;width:220px;margin-bottom:22px"></div><div class="kpis" style="margin-bottom:16px">${'<div class="kpi">' + sk(2) + '</div>'.repeat(1)}${'<div class="kpi">' + sk(2) + '</div>'}${'<div class="kpi">' + sk(2) + '</div>'}${'<div class="kpi">' + sk(2) + '</div>'}</div><div class="card"><div class="card-b">${sk(6)}</div></div>`; }
window.addEventListener('hashchange', () => { if ($('#view') && $('#crumb')) route(); });   // پیش از ساخته شدن پوسته (بوت ناهمگام) مسیریابی نکن
window.addEventListener('resize', debounce(() => UI.emit('resize'), 120));

/* ---------- پوسته ---------- */
function unreadTotal() { return (D.counts && D.counts.unread_convs != null) ? D.counts.unread_convs : D.convs.filter(c => c.status === 'open').reduce((s, c) => s + (c.unread ? 1 : 0), 0); }
function navItem(slug) {
  const p = PAGES[slug]; if (!p || (D.pageAllow && D.pageAllow.length && !D.pageAllow.includes(p.perm || slug)) || (p.admin && !(D.me && D.me.is_admin))) return '';
  const cnt = slug === 'inbox' ? `<span class="cnt hot" id="navInboxCnt">${fa(unreadTotal())}</span>` : p.cnt ? `<span class="cnt">${p.cnt()}</span>` : '';
  return `<a class="nav-i" href="#${slug}" data-to="${slug}" title="${p.title}">${ic(p.icon)}<span class="lbl">${p.title}</span>${cnt}</a>`;
}
/* کارت کوچک پایین منوی کناری (فقط مدیر) */
function sideBadge() {
  if (!(D.me && D.me.is_admin)) return '';
  
  
  return `<button class="usage" data-act="go" data-to="settings.system"><div class="row"><i class="ok-dot"></i><b>${E(D.company.name || 'اعلان‌بان')}</b><span class="ms-auto tnum">نسخه‌ی ${fa(D.version || '')}</span></div><div class="row" style="margin-top:4px;opacity:.75"><span class="ltr ell">${E(location.hostname)}</span></div></button>`;
  
}
function shell() {
  const closed = store.get('navClosed', []);
  const me = op(D.meId) || {name:'کاربر', color:'#1D5C4D', status:'online'};
  document.documentElement.lang = 'fa'; document.documentElement.dir = 'rtl';
  $('#app').innerHTML = `
  <div class="app" dir="rtl">
    <aside class="side" id="side" aria-label="منوی اصلی">
      <div class="side-top">${D.brand && D.brand.logo ? `<img class="logo" src="${E(D.brand.logo)}" alt="" style="object-fit:contain;background:#fff">` : `<span class="logo" aria-hidden="true">${ic('broadcast')}</span>`}<button class="ws" data-act="ws-menu" data-pop><b>${E(D.company.name)}</b><span>${E((D.brand && D.brand.tagline) || 'اعلان‌بان')}</span></button></div>
      <nav class="nav scroll">${NAV.map((g, gi) => g.h ? (!g.items.map(navItem).join('') ? '' : `<div class="nav-sec ${closed.includes(gi) ? 'closed' : ''}" data-gi="${gi}"><button class="nav-h" data-act="nav-sec">${g.h}${ic('caret-down')}</button><div class="nav-items">${g.items.map(navItem).join('')}</div></div>`) : g.items.map(navItem).join('')).join('')}</nav>
      <div class="side-foot">
        ${sideBadge()}
        ${navItem('settings')}
        <button class="nav-i" data-act="collapse" title="جمع کردن منو">${ic(P.collapsed ? 'sidebar' : 'sidebar-simple')}<span class="lbl">${P.collapsed ? 'باز کردن منو' : 'جمع کردن منو'}</span></button>
      </div>
    </aside>
    <div class="main">
      <header class="top">
        <button class="btn btn-g btn-ic menu-btn" data-act="open-side" aria-label="منو">${ic('list')}</button>
        <div class="crumb" id="crumb"></div>
        ${(D.sites || []).length > 1 ? `<select class="inp site-sel" data-on-change="site-switch" title="سایتی که تنظیمات چت‌بات، دکمه‌های شناور، اعلان و آنالیتیکس‌اش را می‌بینید" aria-label="انتخاب سایت" style="width:auto;max-width:180px;height:34px;padding:0 10px;font-size:12.5px;flex:0 0 auto;border-inline-start:3px solid ${(D.sites.find(x => x.id === D.siteCur) || {}).color || 'var(--accent)'}">${D.sites.filter(x => x.is_active || x.id === D.siteCur).map(x => `<option value="${x.id}" ${x.id === D.siteCur ? 'selected' : ''}>🌐 ${E(x.name)}</option>`).join('')}</select>` : ''}
        <button class="search" data-act="cmdk">${ic('magnifying-glass')}<span>جستجو در مشتری، گفتگو، صفحه...</span><kbd>Ctrl K</kbd></button>
        <div class="top-acts">
          <button class="btn btn-p btn-sm hide-m" data-act="quick-new" data-pop>${ic('plus')}<span>جدید</span></button>
          <button class="presence" data-act="presence" data-pop><i class="dot" id="presDot" style="background:${{busy:'var(--warn)', away:'var(--ink-4)'}[me.presence] || 'var(--ok)'}"></i><span class="lbl2" id="presLbl">${{busy:'مشغول', away:'غایب'}[me.presence] || 'آنلاین'}</span></button>
          <button class="btn btn-g btn-ic" data-act="theme-toggle" aria-label="تغییر تم" title="تغییر تم">${ic('moon')}</button>
          <button class="btn btn-g btn-ic bell" data-act="notifs" data-pop aria-label="اعلان‌ها">${ic('bell')}${D.notifs.some(n => n.unread) ? '<span class="badge-dot"></span>' : ''}</button>
          <button data-act="user-menu" data-pop aria-label="حساب کاربری">${av(me.name, {color:me.color, size:'sm', st:'online'})}</button>
        </div>
      </header>
      ${(window.EB_BOOT || {}).imp ? `<div class="imp-bar" role="status">${ic('user-switch')}<span>شما به‌جای <b>${E(EB_BOOT.imp.as)}</b> وارد شده‌اید؛ پیام‌ها و کارها به نام او ثبت می‌شود.</span><button class="btn btn-sm" data-act="imp-stop">${ic('arrow-u-up-left')}بازگشت به حساب ${E(EB_BOOT.imp.by)}</button></div>` : ''}${(window.EB_BOOT || {}).viewer ? `<div class="imp-bar" role="status" style="background:var(--accent-ink,#1D5C4D)">${ic('eye')}<span><b>نسخه‌ی نمایشی</b> — همه‌ی بخش‌ها را روی یک سیستم واقعی ببینید. ارسال پیام، ذخیره و تغییر تنظیمات در این حساب غیرفعال است.</span></div>` : ''}<main class="view" id="view"><div class="vpage" id="vpage"></div><div class="vlegacy" id="vlegacy" hidden></div></main>
    </div>
    <nav class="mob-nav" aria-label="ناوبری پایین">
      <button data-act="go" data-to="dashboard">${ic('squares-four')}داشبورد</button>
      <button data-act="go" data-to="inbox">${ic('tray')}صندوق<span class="cnt">${fa(unreadTotal())}</span></button>
      <button data-act="go" data-to="persons">${ic('users-three')}مشتریان</button>
      <button data-act="go" data-to="send">${ic('paper-plane-tilt')}ارسال</button>
      <button data-act="open-side">${ic('dots-three-outline')}بیشتر</button>
    </nav>
  </div>`;
}
A['nav-sec'] = el => { const s = el.parentElement; s.classList.toggle('closed'); store.set('navClosed', $$('.nav-sec.closed').map(x => +x.dataset.gi)); };
A['collapse'] = () => { setPref('collapsed', !P.collapsed); shell(); route(true); };
A['open-side'] = () => { const s = $('#side'); s.classList.add('open'); const bd = document.createElement('div'); bd.className = 'backdrop in'; bd.style.zIndex = 90; document.body.appendChild(bd); const close = () => { s.classList.remove('open'); bd.remove(); }; bd.onclick = close; s.addEventListener('click', e => { if (e.target.closest('a.nav-i')) close(); }, {once:false}); };
A['theme-toggle'] = () => { const dark = document.documentElement.getAttribute('data-theme') === 'dark' || (!document.documentElement.getAttribute('data-theme') && matchMedia('(prefers-color-scheme: dark)').matches); setPref('theme', dark ? 'light' : 'dark'); toast(dark ? 'تم روشن فعال شد' : 'تم تاریک فعال شد'); };
A['presence'] = el => menu(el, [
  {h:'وضعیت شما'},
  {t:'آنلاین', dot:'var(--ok)', fn:() => setPres('آنلاین', 'var(--ok)', 'online')},
  {t:'مشغول', dot:'var(--warn)', fn:() => setPres('مشغول', 'var(--warn)', 'busy')},
  {t:'غایب', dot:'var(--ink-4)', fn:() => setPres('غایب', 'var(--ink-4)', 'away')},
]);
function setPres(l, c, v) { $('#presDot').style.background = c; $('#presLbl').textContent = l; if (window.EB_LIVE) EB_LIVE.api('desk_presence', {presence:v}); const me = op(D.meId); if (me) me.presence = v; toast('وضعیت شما: ' + l); }
A['ws-menu'] = el => menu(el, [{h:D.company.name}, {t:'ظاهر و تم', icon:'palette', fn:() => go('settings.appearance')}, ...(D.me && D.me.is_admin ? [{t:'برند پنل', icon:'paint-brush', fn:() => go('settings.brand')}, ] : []), '-', {t:'خروج از حساب', icon:'sign-out', danger:true, fn:() => { location.href = 'index.php?page=logout'; }}]);
A['quick-new'] = el => menu(el, [
  {t:'ارسال پیام گروهی', icon:'paper-plane-tilt', fn:() => go('send')},
  {t:'ارسال با شماره', icon:'device-mobile-speaker', fn:() => go('safir')},
  {t:'مخاطب جدید', icon:'user-plus', fn:() => { go('persons'); setTimeout(() => A['person-new'] && A['person-new'](), 500); }},
  {t:'قانون کامنت اینستاگرام', icon:'lightning', fn:() => go('instagram')},
  {t:'کمپین چندمرحله‌ای', icon:'megaphone', fn:() => go('campaigns')},
  {t:'لینک رهگیری', icon:'link-simple', fn:() => go('links')},
].filter(i => true));
A['user-menu'] = el => { const me = op(D.meId) || {name:'کاربر'}; menu(el, [
  {html:`<div class="row" style="padding:8px 9px 10px">${av(me.name, {color:me.color})}<div class="grow"><b>${E(me.name)}</b><div class="muted xs">${E(me.title || '')} · <span class="ltr">${E(me.username || '')}</span></div></div></div>`}, '-',
  {t:'پروفایل من', icon:'user-circle', fn:() => go('profile')}, {t:'تنظیمات ظاهر', icon:'palette', fn:() => go('settings.appearance')}, {t:'میان‌برهای صفحه‌کلید', icon:'keyboard', kbd:'?', fn:() => A['shortcuts']()},
  '-', {t:'خروج از حساب', icon:'sign-out', danger:true, fn:() => { location.href = 'index.php?page=logout'; }}], {w:250}); };
A['notifs'] = el => {
  const p = menu(el, [{html:`<div class="row" style="padding:8px 9px"><b>اعلان‌ها</b><button class="btn btn-g btn-sm ms-auto" data-act="notif-read">خواندن همه</button></div>`}, ...(D.notifs.length ? [] : [{html:'<div class="muted small" style="padding:14px 10px;text-align:center">اعلان تازه‌ای نیست. پیام‌ها و رویدادهای مهم اینجا نمایش داده می‌شوند.</div>'}]), ...D.notifs.map(n => ({html:`<button class="pi" data-act="go" data-to="${n.go}" style="align-items:flex-start;${n.unread ? 'background:var(--accent-soft)' : ''}">${ic(n.ic)}<span class="grow" style="white-space:normal;line-height:1.6">${n.t}<br><span class="muted xs">${rel(n.m)}</span></span></button>`}))], {w:340});
};
A['notif-read'] = () => { D.notifs.forEach(n => n.unread = false); $('.bell .badge-dot')?.remove(); closePop(); toast('همه‌ی اعلان‌ها خوانده شد'); };
A['shortcuts'] = () => modal({title:'میان‌برهای صفحه‌کلید', icon:'keyboard', body:`<div class="stack s6">${[['Ctrl K یا /','جستجوی سراسری و پرش به صفحه'],['?','نمایش همین راهنما'],['J / K','گفتگوی بعدی و قبلی در صندوق ورودی'],['R','رفتن به کادر پاسخ'],['N','نوشتن یادداشت داخلی'],['E','بستن گفتگو'],['A','سپردن به اپراتور'],['Ctrl Enter','ارسال پیام'],['Esc','بستن پنجره یا منو']].map(r => `<div class="row" style="padding:6px 0;border-bottom:1px solid var(--line-2)"><span class="grow">${r[1]}</span><kbd>${r[0]}</kbd></div>`).join('')}</div>`});
A['cmdk'] = () => openCmdk();

/* ---------- پالت فرمان ---------- */
function closeCmdk() { $('.cmdk')?.remove(); $('.cmdk-bd')?.remove(); }
function openCmdk() {
  closeCmdk();
  const bd = document.createElement('div'); bd.className = 'backdrop in cmdk-bd'; bd.style.zIndex = 140; bd.onclick = closeCmdk;
  const el = document.createElement('div'); el.className = 'cmdk'; el.setAttribute('role', 'dialog');
  el.innerHTML = `<div class="q">${ic('magnifying-glass')}<input id="cmdkQ" placeholder="نام مشتری، شماره، صفحه یا دستور..." autocomplete="off"><kbd>Esc</kbd></div><div class="res scroll" id="cmdkRes"></div>`;
  document.body.append(bd, el);
  const inp = $('#cmdkQ', el); let sel = 0, items = [];
  const render = () => {
    const q = inp.value.trim().toLowerCase();
    const pagesL = Object.entries(PAGES).filter(([k, p]) => p.title && !p.hidden).map(([k, p]) => ({g:'صفحه‌ها', t:p.title, icon:p.icon, fn:() => go(k), s:p.title + k}));
    const cmds = [
      {g:'دستورها', t:'تغییر تم روشن و تاریک', icon:'moon', fn:() => A['theme-toggle'](), s:'تم تاریک روشن dark'},
      {g:'دستورها', t:'تغییر رنگ و فونت پنل', icon:'palette', fn:() => go('settings.appearance'), s:'رنگ فونت ظاهر'},
      {g:'دستورها', t:'ارسال پیام گروهی', icon:'paper-plane-tilt', fn:() => go('send'), s:'ارسال انبوه'},
      {g:'دستورها', t:'قانون کامنت جدید', icon:'lightning', fn:() => go('instagram.automation'), s:'قانون کامنت اتوماسیون'},
      {g:'دستورها', t:'افزودن اپراتور', icon:'user-plus', fn:() => go('settings.operators'), s:'اپراتور کاربر'},
    ];
    const people = (window.__cmdkPeople || D.contacts).map(c => ({g:'مشتریان', t:c.name, sub:c.phone ? fa(c.phone) : (c.idents[0] || {}).handle, avn:c.name, fn:() => A['person-open'] ? A['person-open']({dataset:{id:c.id}}) : go('persons'), s:c.name + c.phone + c.idents.map(i => i.handle).join(' ')}));
    const all = [...cmds, ...pagesL, ...people];
    items = (q ? all.filter(x => x.s.toLowerCase().includes(q) || fa(x.s).includes(q)) : [...pagesL.slice(0, 7), ...cmds.slice(0, 3), ...people.slice(0, 4)]).slice(0, 30);
    sel = Math.min(sel, items.length - 1);
    let lastG = '';
    $('#cmdkRes', el).innerHTML = items.length ? items.map((it, i) => { const g = it.g !== lastG ? `<div class="grp">${it.g}</div>` : ''; lastG = it.g; return g + `<button class="it ${i === sel ? 'kb' : ''}" data-i="${i}">${it.avn ? av(it.avn, {size:'xs'}) : ic(it.icon)}<span>${E(it.t)}</span>${it.sub ? `<small>${E(it.sub)}</small>` : ''}</button>`; }).join('') : empty('magnifying-glass', 'نتیجه‌ای پیدا نشد', 'با نام، شماره موبایل یا یوزرنیم اینستاگرام جستجو کنید.');
  };
  let qt; inp.oninput = () => { sel = 0; render(); clearTimeout(qt); const q = inp.value.trim(); if (q.length > 1 && window.EB_LIVE) qt = setTimeout(async () => { const r = await EB_LIVE.api('persons_list', {q}); if (r.ok) { window.__cmdkPeople = r.persons.map(EB_LIVE.mapContact); render(); } }, 250); };
  inp.onkeydown = e => {
    if (e.key === 'ArrowDown') { sel = Math.min(items.length - 1, sel + 1); render(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { sel = Math.max(0, sel - 1); render(); e.preventDefault(); }
    else if (e.key === 'Enter' && items[sel]) { const f = items[sel].fn; closeCmdk(); f(); }
  };
  el.addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) { const f = items[+b.dataset.i].fn; closeCmdk(); f(); } });
  render(); inp.focus();
}

/* ---------- خروجی ---------- */
/* ---------- راهنمای شناور (data-tip): با هاور، فوکوس یا لمس ---------- */
(function tipInit() {
  let tip = null, cur = null;
  const hide = () => { if (tip) tip.remove(); tip = null; cur = null; };
  const show = el => {
    if (cur === el) return; hide(); cur = el;
    tip = document.createElement('div'); tip.className = 'eb-tip'; tip.setAttribute('role', 'tooltip');
    tip.innerHTML = el.getAttribute('data-tip'); document.body.appendChild(tip);
    const r = el.getBoundingClientRect(), t = tip.getBoundingClientRect();
    let top = r.bottom + 8, left = r.left + r.width / 2 - t.width / 2;
    if (top + t.height > innerHeight - 8) top = r.top - t.height - 8;
    left = Math.max(8, Math.min(innerWidth - t.width - 8, left));
    tip.style.top = top + 'px'; tip.style.left = left + 'px';
  };
  document.addEventListener('mouseover', e => { const el = e.target.closest('[data-tip]'); if (el) show(el); else if (cur) hide(); });
  document.addEventListener('focusin', e => { const el = e.target.closest('[data-tip]'); if (el) show(el); });
  document.addEventListener('focusout', hide);
  document.addEventListener('touchstart', e => { const el = e.target.closest('[data-tip]'); if (el) { show(el); setTimeout(hide, 6000); } else hide(); }, {passive:true});
  document.addEventListener('scroll', hide, true);
})();
window.EB = { $, $$, E, fa, nf, pct, compact, toman, rel, clock, todayFa, uid, debounce, store, ic, av, chi, chTxt, pill, tagHtml, miniTag, sw, seg, tabs, empty, field, delta, contact, op, page, media, sk,
  ACCENTS, FONTS, DFONTS, RADII, DENS, P, DEF_PREFS, setPref, applyPrefs, accentFromHex, mergeServerPrefs, openCmdk,
  modal, drawer, confirm, menu, closePop, toast, copy, busy, chartBox, drawCharts, spark, hbars, A, ON, UI, PAGES, reg, go, route, current:() => current, shell, unreadTotal };
window.EB.boot = () => { applyPrefs(); shell(); route(); if (matchMedia) matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => UI.emit('prefs')); };
/** راه‌اندازی نسخه‌ی واقعی: بوت از سرور، ترجیحات کاربر، پوسته، مسیر و اعلان‌ها */
window.EB.start = async () => {
  applyPrefs();
  try { await EB_LIVE.boot(); }
  catch (e) { $('#app').innerHTML = `<div style="display:grid;place-items:center;height:100%;padding:20px">${empty('warning-circle', 'پنل بارگذاری نشد', E(e.message) + '<br>اگر نسخه را تازه به‌روز کرده‌اید، از تنظیمات «به‌روزرسانی دیتابیس» را بزنید.', '<a class="btn" href="index.php">تلاش دوباره</a> <a class="btn btn-g" href="index.php?page=logout">خروج</a>')}</div>`; return; }
  const sp = (D.me && D.me.prefs) || null; if (sp && Object.keys(sp).length) mergeServerPrefs(sp);
  shell(); route();
  if (matchMedia) matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => UI.emit('prefs'));
  EB_LIVE.pollNotifs(true);
  UI.on('notifs', () => { const b = $('.bell'); if (b && !$('.badge-dot', b)) b.insertAdjacentHTML('beforeend', '<span class="badge-dot"></span>'); });
  window.addEventListener('message', e => { if (e.origin === location.origin && e.data && e.data.eb === 'go' && e.data.page) go(e.data.page); });
  
  setInterval(async () => { if (document.hidden) return; const r = await EB_LIVE.api('desk_counts'); if (r.ok) { D.counts = r.counts; const n = unreadTotal(); const b = $('#navInboxCnt'); if (b) b.textContent = fa(n); const m = $('.mob-nav .cnt'); if (m) m.textContent = fa(n); UI.emit('counts'); } }, 30000);
};
})();
