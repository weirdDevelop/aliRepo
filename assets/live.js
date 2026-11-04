/* live.js — لایه‌ی داده‌ی پنل: تماس با api/handler.php و تبدیل خروجی سرور به مدل رابط کاربری */
(function(){
const BOOT = window.EB_BOOT || {};

/* ---------- کانال‌ها ---------- */
const CH = {
  instagram:{name:'اینستاگرام', icon:'instagram-logo', color:'var(--ch-instagram)'},
  telegram:{name:'تلگرام', icon:'telegram-logo', color:'var(--ch-telegram)'},
  bale:{name:'بله', icon:'chat-circle-dots', color:'var(--ch-bale)'},
  roubika:{name:'روبیکا', icon:'chat-teardrop-dots', color:'var(--ch-rubika)'},
  webchat:{name:'گفتگوی سایت', icon:'browser', color:'var(--ch-webchat)'},
  safir:{name:'صفیر بله', icon:'paper-plane-tilt', color:'var(--ch-safir)'},
  sms:{name:'پیامک', icon:'device-mobile-speaker', color:'var(--ch-sms)'},
  email:{name:'ایمیل', icon:'envelope-simple', color:'var(--ch-email)'},
  voice:{name:'تلفن گویا', icon:'phone-call', color:'var(--ch-voice)'},
  other:{name:'سایر', icon:'chat-circle', color:'var(--ch-webchat)'},
};
const chKey = c => ({instagram_apichi:'instagram', bale_safir:'safir', rubika:'roubika'}[c] || (CH[c] ? c : 'other'));

/* ---------- کلاینت API ---------- */
let loggedOut = false;
async function api(name, data = {}, isForm = false) {
  const opts = {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':BOOT.csrf}};
  try { const sid = localStorage.getItem('ebSite'); if (sid) opts.headers['X-EB-Site'] = sid; } catch (e) {}   // ۲.۱.۱: سایتِ انتخاب‌شده
  if (isForm) { data.append('_csrf', BOOT.csrf); opts.body = data; }
  else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(Object.assign({}, data, {_csrf:BOOT.csrf})); }
  let r;
  try { r = await fetch('index.php?api=' + encodeURIComponent(name), opts); }
  catch (e) { return {ok:false, error:'اتصال به سرور برقرار نشد. اینترنت را بررسی کنید.', network:true}; }
  if (r.status === 401 || r.status === 419) {
    if (!loggedOut) { loggedOut = true; location.href = 'index.php?page=login'; }
    return {ok:false, error:'نشست شما تمام شده است'};
  }
  const text = await r.text();
  let j; try { j = JSON.parse(text); } catch (e) { return {ok:false, error:'پاسخ نامعتبر از سرور: ' + text.replace(/<[^>]+>/g, ' ').trim().slice(0, 140)}; }
  
  return j;
}
/** مثل api ولی در صورت خطا استثنا می‌دهد (برای loaderها) */
async function must(name, data) { const r = await api(name, data); if (!r || !r.ok) throw new Error((r && r.error) || 'خطای نامشخص'); return r; }

/* ---------- زمان ---------- */
const parseTs = s => { if (!s) return null; const t = Date.parse(String(s).replace(' ', 'T')); return isNaN(t) ? null : t; };
let skew = 0; // اختلاف ساعت مرورگر با ساعت دیتابیس (برای منطقه‌ی زمانی متفاوت سرور)
const setNow = n => { const t = parseTs(n); if (t != null) skew = Date.now() - t; };
const minsAgo = s => { const t = parseTs(s); return t == null ? 99999 : Math.max(0, Math.round((Date.now() - skew - t) / 60000)); };
/** زمان واقعی (به وقت مرورگر) یک مهر زمانی سرور؛ اختلاف به ربع ساعت گرد می‌شود تا تأخیر شبکه اثری نگذارد */
const absTs = s => { const t = parseTs(s); if (t == null) return null; const q = 15 * 60000; return t + Math.round(skew / q) * q; };
const fmt = (t, opt) => { try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', opt).format(new Date(t)); } catch (e) { return new Date(t).toLocaleString(); } };
const faDate = (s, opt) => { const t = absTs(s); return t == null ? '' : fmt(t, opt || {year:'numeric', month:'2-digit', day:'2-digit'}); };
const sameDay = (a, b) => new Date(a).toDateString() === new Date(b).toDateString();
/** ساعت دقیق: ۱۴:۰۵ */
const clockOf = s => { const t = absTs(s); return t == null ? '' : fmt(t, {hour:'2-digit', minute:'2-digit', hourCycle:'h23'}); };
/** تاریخ و ساعت کامل: سه‌شنبه ۲۲ مهر ۱۴۰۵، ۱۴:۰۵ */
const parts = t => { const o = {}; try { new Intl.DateTimeFormat('fa-IR-u-ca-persian', {weekday:'long', day:'numeric', month:'long', year:'numeric', hour:'2-digit', minute:'2-digit', hourCycle:'h23'}).formatToParts(new Date(t)).forEach(x => o[x.type] = x.value); } catch (e) {} return o; };
const fullDT = s => { const t = absTs(s); if (t == null) return ''; const o = parts(t); return `${o.weekday} ${o.day} ${o.month} ${o.year}، ساعت ${o.hour}:${o.minute}`; };
/** برچسب روز برای جداکننده‌ی گفتگو */
const dayLabel = s => { const t = absTs(s); if (t == null) return ''; const n = Date.now(); if (sameDay(t, n)) return 'امروز · ' + fmt(t, {weekday:'long', day:'numeric', month:'long'}); if (sameDay(t, n - 864e5)) return 'دیروز · ' + fmt(t, {weekday:'long', day:'numeric', month:'long'}); const yr = fmt(t, {year:'numeric'}) !== fmt(n, {year:'numeric'}); return fmt(t, yr ? {weekday:'long', day:'numeric', month:'long', year:'numeric'} : {weekday:'long', day:'numeric', month:'long'}); };
/** زمان کوتاه فهرست: امروز ← ساعت، دیروز، این هفته ← نام روز، بعد ← تاریخ */
const listTime = s => { const t = absTs(s); if (t == null) return ''; const n = Date.now(); if (sameDay(t, n)) return fmt(t, {hour:'2-digit', minute:'2-digit', hourCycle:'h23'}); if (sameDay(t, n - 864e5)) return 'دیروز'; if (n - t < 6 * 864e5) return fmt(t, {weekday:'long'}); const yr = fmt(t, {year:'numeric'}) !== fmt(n, {year:'numeric'}); return fmt(t, yr ? {year:'2-digit', month:'2-digit', day:'2-digit'} : {day:'numeric', month:'short'}); };

/* ---------- رنگ برچسب (برچسب‌های سرور رنگ ندارند) ---------- */
const TAG_COLORS = ['#3D6A9E', '#2F7A5E', '#A35A2A', '#6B4F8A', '#A33B62', '#2E6670', '#8A6A22', '#A8433A'];
const tagColor = id => TAG_COLORS[Math.abs(parseInt(id, 10) || 0) % TAG_COLORS.length];

/* ---------- مدل داده‌ی رابط ---------- */
const D = window.D = {CH, chKey, ops:[], teams:[], pages:[], groups:[], tags:[], contacts:[], convs:[], segments:[], canned:[], notifs:[], counts:{}, me:null, pageAllow:[], channelsOn:{}, sla:{}, unread:0, aiReady:false, brand:{},  version:BOOT.version,
  company:{name:'', domain:location.hostname}};
const SRC = {autoreply:'پاسخ خودکار', botmenu:'منوی بات', form:'فرم', ai:'هوش مصنوعی', comment_dm:'اتوماسیون کامنت', comment_reply:'پاسخ عمومی کامنت', follow_gate:'فالو اجباری', phone_gate:'درخواست شماره', ai_comment:'AI کامنت', campaign:'کمپین', scheduled:'زمان‌بندی', bulk:'ارسال گروهی', flow:'سناریو', csat:'نظرسنجی رضایت', followup:'پیگیری خودکار'};

function mapUser(u) { return {id:'u' + u.id, rid:u.id, name:u.name, username:u.username, title:u.title, role:u.role, roleName:{admin:'مدیر', supervisor:'سرپرست'}[u.role] || 'اپراتور', color:u.color, avatar:u.avatar || '', status:u.status, presence:u.presence, team:u.team_id ? 'tm' + u.team_id : null, last:u.last_seen ? EB.rel(minsAgo(u.last_seen)) : 'هرگز', active:u.active}; }
function mapTeam(t) { return {id:'tm' + t.id, rid:t.id, name:t.name, color:t.color, members:(t.members || []).map(m => 'u' + m), rule:t.rule}; }
function mapTag(t) { const hot = t.name === 'اولویت تماس'; return {id:'t' + t.id, rid:+t.id, name:t.name, color:hot ? '#D92D20' : tagColor(t.id), hot, count:+(t.person_count || 0)}; }   // ۱.۰.۱۴: برچسب «اولویت تماس» همیشه قرمز
function mapGroup(g) { return {id:'g' + g.id, rid:+g.id, name:g.name, color:g.color || '#3D6A9E', count:+(g.member_count || 0), desc:g.description || ''}; }
function mapPage(p) { const user = String(p.user || '').replace(/^@/, ''); return {id:'p' + p.id, rid:p.id, name:(p.name || '').trim() || (user ? '@' + user : 'پیج ' + p.id), user, color:p.color || '#A8335E', active:p.active, health:'ok'}; }

function mapIdent(i) {
  const ch = chKey(i.channel);
  let handle = i.username ? (ch === 'instagram' || ch === 'telegram' ? '@' + String(i.username).replace(/^@/, '') : i.username) : i.channel_uid;
  if (ch === 'bale' || ch === 'telegram' || ch === 'roubika') if (!i.username) handle = 'chat_id ' + i.channel_uid;
  return {id:i.id ? +i.id : null, ch, raw:i.channel, handle:handle || '-', page:i.ig_account_id ? 'p' + i.ig_account_id : null, pageName:i.ig_account_label || (i.ig_page_username ? '@' + i.ig_page_username : ''),
    last:minsAgo(i.last_msg_at || i.last_message_at || i.last_seen), lastAt:i.last_msg_at || i.last_seen || null, firstAt:i.first_msg_at || i.first_seen || null, lastMsg:i.last_message || '', bio:i.bio || '',
    nin:+(i.msg_in || 0), nout:+(i.msg_out || 0), fullName:i.full_name || '', followers:i.followers, isFollower:i.is_follower, url:i.profile_url || null, uid:i.channel_uid};
}
function mapContact(p) {
  // ۱.۰.۱۳: نام کانال (نام یا @نام کاربری) به‌جای «مشتری ۲۹۴۰»
  const id0 = (p.identities || []).find(i => (i.first_name || '').trim() || (i.username || '').trim()) || (p.identities || [])[0] || {};
  const chl = {instagram:'کاربر اینستاگرام', webchat:'بازدیدکننده‌ی سایت', bale:'کاربر بله', telegram:'کاربر تلگرام', roubika:'کاربر روبیکا', sms:'پیامک'}[chKey(id0.channel || '')] || 'مشتری';
  const name = (p.full_name || '').trim() || (id0.first_name || '').trim() || (id0.username ? '@' + String(id0.username).replace(/^@/, '') : '') || (p.phone ? p.phone : chl + ' ' + String(p.id).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]));
  const sent = +((p.stats && p.stats.sent) ?? p.sent_count ?? 0), recv = +((p.stats && p.stats.received) ?? p.recv_count ?? 0);
  const c = {
    id:'c' + p.id, rid:+p.id, name, first:name.split(' ')[0], phone:p.phone || '', member:p.status === 'member', city:'',
    idents:(p.identities || []).map(mapIdent), groups:(p.groups || []).map(g => 'g' + g.id), tags:(p.tags || []).map(t => 't' + t.id),
    sent, recv, rr:p.resp_rate != null ? +p.resp_rate : (sent ? Math.round(recv / sent * 100) : 0),
    orders:(p.orders || []).length, ltv:(p.orders || []).reduce((s, o) => s + (+o.total || 0), 0),
    created:Math.round(minsAgo(p.created_at) / 1440), lastMsg:minsAgo((p.stats && p.stats.last_activity) || p.last_msg_at || p.updated_at),
    note:p.notes || '', blocked:!!(p.blocked || p.blocked_at), filter:p.filter || null, source:p.top_channel ? 'اولین تماس از ' + (CH[chKey(p.top_channel)] || {}).name : '',
    vip:false, birthday:'', raw:p, ordersList:p.orders || [], forms:p.form_responses || [], clicks:p.link_clicks || [], campaigns:p.campaigns || [], aiAssigned:!!p.ai_assigned,
  };
  c.ch = c.idents[0] ? c.idents[0].ch : 'other';
  // VIP: عضو گروهی که اسمش VIP دارد
  c.vip = c.groups.some(g => /vip|ویژه/i.test((D.groups.find(x => x.id === g) || {}).name || ''));
  const old = D.contacts.findIndex(x => x.id === c.id);
  if (old > -1) D.contacts[old] = Object.assign(D.contacts[old], c); else D.contacts.push(c);
  return D.contacts.find(x => x.id === c.id);
}
function mapConv(r) {
  const cid = 'c' + r.person_id;
  if (!D.contacts.find(x => x.id === cid)) D.contacts.push({id:cid, rid:r.person_id, name:r.name || r.phone || ('مشتری ' + r.person_id), first:(r.name || '').split(' ')[0] || 'مشتری', phone:r.phone || '', member:r.member, idents:[], groups:[], tags:[], sent:0, recv:0, rr:0, orders:0, ltv:0, note:'', vip:false, partial:true});
  else { const c = D.contacts.find(x => x.id === cid); if (r.name) c.name = r.name; }
  const prev = D.convs.find(x => x.id === 'v' + r.person_id);
  const v = {
    id:'v' + r.person_id, rid:r.person_id, cid, ch:chKey(r.channel), page:r.ig_account_id ? 'p' + r.ig_account_id : null,
    status:r.status, assignee:r.assignee_id ? 'u' + r.assignee_id : null, team:r.team_id ? 'tm' + r.team_id : null,
    pri:r.priority || 'normal', unread:r.unread, labels:(r.labels || []).map(x => 't' + x), lastM:minsAgo(r.last_at), preview:(r.preview || '').replace(/\s+/g, ' ').slice(0, 160) || (r.last_dir === 'in' ? 'رسانه' : ''),
    lastDir:r.last_dir, lastSrc:r.last_source, lastAt:r.last_at, ai:r.ai, starred:r.starred, sla:r.sla_left, snooze:r.snooze, mention:false,
    assigneeName:r.assignee_name || null, slaTarget:r.sla_target, waitingSince:r.waiting_since, viewers:r.viewers || [], channels:(r.channels || []).map(chKey),
    msgs:prev ? prev.msgs : null,
  };
  if (prev) Object.assign(prev, v); else D.convs.push(v);
  return prev || v;
}

/** پیام‌ها، رویدادها و کامنت‌ها ← فهرست مرتب (جداکننده‌ی روز موقع رندر ساخته می‌شود) */
function buildThread(messages, events, contact, activity) {
  const items = [];
  (messages || []).forEach(m => items.push({t:parseTs(m.created_at) || 0, id:+m.id || 0, m}));
  (events || []).forEach(e => items.push({t:parseTs(e.created_at) || 0, id:0, e}));
  (activity || []).forEach(a => items.push({t:parseTs(a.created_at) || 0, id:0, a}));
  items.sort((a, b) => a.t - b.t || a.id - b.id);
  const out = [];
  for (const it of items) {
    if (it.m) {
      const m = it.m; const isBot = m.direction === 'out' && m.source && m.source !== 'reply' && SRC[m.source];
      const img = m.media_type && /image|photo/.test(m.media_type) && m.media_path ? m.media_path : null;
      out.push({id:+m.id, k:m.direction === 'in' ? 'in' : isBot ? 'bot' : 'out', x:m.body || (img ? '' : m.media_path ? 'فایل پیوست' : ''), m:minsAgo(m.created_at), ts:m.created_at,
        src:isBot ? SRC[m.source] : null, srcIc:m.source === 'ai' ? 'sparkle' : m.source === 'flow' ? 'flow-arrow' : 'robot', comment:m.media_type === 'ig_comment',
        img, fail:m.status === 'failed', err:m.error, deleted:!!m.deleted_at, page_url:m.page_url, ch:chKey(m.channel), ident:+m.identity_id || null,
        page:m.ig_account_id ? 'p' + m.ig_account_id : null, by:m.sent_by_name || null, seen:!!m.seen_at,
        edited:m.edited_at || null, orig:m.orig_body || null, canEdit:m.direction === 'out' && m.status !== 'failed' && !m.deleted_at && (m.channel === 'webchat' || (+m.has_ext && ['telegram', 'bale', 'roubika'].includes(m.channel)))});
    } else if (it.e) {
      const e = it.e; const by = e.user_id ? D.ops.find(o => o.rid === +e.user_id) : null;
      if (e.type === 'note') out.push({k:'note', x:e.body, m:minsAgo(e.created_at), ts:e.created_at, by:by ? by.id : null});
      else out.push({k:'sys', x:eventText(e, by), ic:{status:'circle-dashed', assign:'user-switch', team:'users-three', prio:'flag', csat:'star', followup:'arrow-bend-double-up-right'}[e.type] || 'info', m:minsAgo(e.created_at), ts:e.created_at});
    } else {
      const a = it.a;
      out.push({k:'cmt', x:a.body || '', ts:a.created_at, m:minsAgo(a.created_at), post:a.post_title || a.media_id || '', page:a.ig_account_id ? 'p' + a.ig_account_id : null, pageName:a.account_label || '', replied:!!a.replied_at, repliedType:a.replied_type || ''});
    }
  }
  return out;
}
function eventText(e, by) {
  const who = by ? `<b>${EB.E(by.name)}</b>` : 'سیستم';
  if (e.type === 'status') { const [s, lbl] = String(e.body).split('|'); return `${who} وضعیت را به <b>${({open:'باز', pending:'منتظر مشتری', snoozed:'تعویق' + (lbl ? ' تا ' + EB.E(lbl) : ''), closed:'بسته'})[s] || s}</b> تغییر داد`; }
  if (e.type === 'assign') { const u = D.ops.find(o => o.rid === +e.body); return u ? `${who} گفتگو را به <b>${EB.E(u.name)}</b> سپرد` : `${who} اپراتور را برداشت`; }
  if (e.type === 'team') { const t = D.teams.find(o => o.rid === +e.body); return t ? `${who} گفتگو را به تیم <b>${EB.E(t.name)}</b> فرستاد` : `${who} تیم را برداشت`; }
  if (e.type === 'prio') { const [p, w, name, lab] = String(e.body).split('|'); const t = lab ? D.tags.find(x => x.rid === +lab) : null;
    return `اولویت خودکار <b>${({urgent:'فوری', high:'زیاد', normal:'عادی', low:'کم'})[p] || p}</b> شد (کلمه‌ی «${EB.E(w)}»${name ? ' · قانون ' + EB.E(name) : ''})${t ? ' و برچسب <b>' + EB.E(t.name) + '</b> خورد' : ''}`; }
  if (e.type === 'csat') { const [k, n] = String(e.body).split('|'); return k === 'rated' ? `مشتری به این گفتگو امتیاز <b>${EB.fa(n)} از ۵</b> داد ${'⭐'.repeat(+n || 0)}` : `${who} نظرسنجی رضایت را برای مشتری فرستاد`; }
  if (e.type === 'followup') return `پیام پیگیری خودکار «${EB.E(e.body)}» فرستاده شد`;
  return EB.E(e.body || e.type);
}

/* ---------- بوت ---------- */
async function boot() {
  const r = await must('desk_boot');
  if (r.now) setNow(r.now);
  D.csat = r.csat || null;
  D.sites = r.sites || []; D.siteCur = r.site_current || 0;
  D.me = r.me; D.pageAllow = r.pages || []; D.channelsOn = r.channels || {}; D.sla = r.sla || {}; D.unread = r.unread || 0; D.aiReady = !!r.ai_ready; D.brand = r.brand || {}; D.support = r.support || {};
  D.ops = (r.users || []).map(mapUser);
  D.teams = (r.teams || []).map(mapTeam);
  D.tags = (r.tags || []).map(mapTag);
  D.groups = (r.groups || []).map(mapGroup);
  D.pages = (r.ig_pages || []).map(mapPage);
  D.counts = r.counts || {};
  D.company.name = D.brand.name || 'اعلان‌بان';
  D.meId = 'u' + D.me.id;
  return r;
}
async function refreshMeta() {
  const [g, t] = await Promise.all([api('groups_list'), api('tags_list')]);
  if (g.ok) D.groups = g.groups.map(mapGroup);
  if (t.ok) D.tags = t.tags.map(mapTag);
}

/* ---------- اعلان‌های پنل (polling) ---------- */
let notifAfter = 0, notifTimer = null;
async function pollNotifs(first) {
  const r = await api('notifications_poll', {after:notifAfter});
  if (r && r.ok) {
    if (!first && r.items && r.items.length) {
      r.items.forEach(n => {
        D.notifs.unshift({id:n.id, ic:n.type === 'message' ? 'chat-circle-dots' : n.type === 'mention' ? 'at' : n.type === 'comment' ? 'instagram-logo' : 'bell', t:EB.E(n.title || '') + (n.body ? '<br><span class="muted">' + EB.E(String(n.body).slice(0, 90)) + '</span>' : ''), m:0, unread:true, go:n.person_id ? 'inbox.v' + n.person_id : 'inbox'});
        if (window.EB && EB.toast) EB.toast(EB.E(n.title || 'رویداد تازه'), 'info', n.person_id ? ['باز کردن', () => EB.go('inbox.v' + n.person_id)] : null);
      });
      D.notifs = D.notifs.slice(0, 30);
      EB.UI.emit('notifs');
    }
    notifAfter = r.latest_id || notifAfter;
  }
  clearTimeout(notifTimer); notifTimer = setTimeout(() => pollNotifs(false), document.hidden ? 60000 : 15000);
}

window.EB_LIVE = {api, must, setNow, boot, refreshMeta, pollNotifs, mapConv, mapContact, mapUser, mapTeam, mapTag, mapGroup, mapIdent, buildThread, minsAgo, parseTs, absTs, faDate, dayLabel, clockOf, fullDT, listTime, chKey, tagColor, SRC, rel:m => m};
})();
