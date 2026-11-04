<?php
/**
 * جاکجاست - پیگیری مرحله‌ای شماره‌ها
 * عدم پاسخ → در حال پیگیری → ارسال لینک اقامتگاه → تایید شده → رزرو / عدم رزرو
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';
$isMgr = Pipeline::isManager($currentUser);
$stageKey = $_GET['stage'] ?? 'followup';
if (!isset(Pipeline::$stages[$stageKey])) $stageKey = 'followup';
$usersList = $isMgr ? Pipeline::assignableUsers() : [];   // ت۱۰: بدون تکرار
Pipeline::ensureSchema();
$refOpts = Pipeline::refOptions();
?>
<main id="main-content">
  <div class="jj-pagehead">
    <div>
      <h2 class="jj-title">📋 پیگیری شماره‌ها</h2>
      <div class="jj-sub">هر شماره در یک مرحله است. هیچ شماره‌ای بیش از <b id="pl-maxdays"><?= Helpers::toPersianDigits(Pipeline::maxDays()) ?></b> روز در مراحل میانی نمی‌ماند؛ بعد از آن خودکار به «عدم رزرو» می‌رود.</div>
    </div>
    <div class="jj-range">
      <select id="pl-assignee" onchange="plReload()">
        <option value="">همه‌ی شماره‌های قابل دید</option>
        <option value="me">فقط پیگیری‌های من</option>
        <option value="none">بدون مسئول</option>
        <?php foreach ($usersList as $u): ?><option value="<?= (int)$u['id'] ?>">مسئول: <?= Helpers::e($u['full_name']) ?></option><?php endforeach; ?>
      </select>
      <div class="search-box jj-search"><span>🔍</span><input type="text" id="pl-search" placeholder="نام یا شماره..." oninput="clearTimeout(window._plT);window._plT=setTimeout(plLoadList,400)"></div>
      <?php if ($isMgr): ?><button class="jj-chip" onclick="plSettings()" title="تنظیمات هشدار">⚙️</button><?php endif; ?>
    </div>
  </div>

  <div class="pl-tabs" id="pl-tabs">
    <?php foreach (Pipeline::$stages as $k => $label): ?>
      <a href="#" data-stage="<?= $k ?>" class="pl-tab pl-<?= $k ?><?= $k === $stageKey ? ' on' : '' ?>" onclick="event.preventDefault();plSetStage('<?= $k ?>')">
        <span><?= Helpers::e($label) ?></span><b class="pl-cnt">…</b><i class="pl-warn" style="display:none"></i>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="pl-sub" id="pl-sub" style="display:none">
    <button class="jj-chip on" data-last="" onclick="plLast('')">همه</button>
    <button class="jj-chip" data-last="answered" onclick="plLast('answered')">آخرین تماس پاسخ داده شد</button>
    <button class="jj-chip" data-last="missed" onclick="plLast('missed')">آخرین تماس بی‌پاسخ</button>
  </div>

  <div class="jj-card">
    <div class="table-wrapper">
      <table class="data-table jj-table" id="pl-table">
        <thead><tr><th>نام / شماره</th><th>مرجع / نقش</th><th>مسئول</th><th>در این مرحله از</th><th>مهلت</th><th>آخرین تماس</th><th>مرحله</th></tr></thead>
        <tbody id="pl-body"><tr><td colspan="7" class="jj-empty">در حال بارگذاری...</td></tr></tbody>
      </table>
    </div>
  </div>
</main>

<div class="modal-overlay" id="pl-modal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><h3 id="pl-modal-title">شماره</h3><button class="modal-close" onclick="plClose()">✕</button></div>
    <div class="modal-body" id="pl-modal-body"></div>
  </div>
</div>

<script>
const PL_STAGES = <?= json_encode(Pipeline::$stages, JSON_UNESCAPED_UNICODE) ?>;
const PL_IS_MGR = <?= $isMgr ? 'true' : 'false' ?>;
const PL_USERS = <?= json_encode($usersList, JSON_UNESCAPED_UNICODE) ?>;
const PL_BASE = '<?= BASE_URL ?>';
const PL_REFS = <?= json_encode($refOpts, JSON_UNESCAPED_UNICODE) ?>;
const PL_ROLES = <?= json_encode(Pipeline::$roles, JSON_UNESCAPED_UNICODE) ?>;
let plLastF = '';
let plStage = '<?= $stageKey ?>';
const plEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const plFa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
function plDur(sec) {
  sec = Math.abs(sec|0); const d = Math.floor(sec/86400), h = Math.floor(sec%86400/3600), m = Math.floor(sec%3600/60);
  return plFa(d ? `${d} روز ${h} ساعت` : (h ? `${h} ساعت ${m} دقیقه` : `${m} دقیقه`));
}
function plLast(v) {
  plLastF = v;
  document.querySelectorAll('#pl-sub .jj-chip').forEach(b => b.classList.toggle('on', b.dataset.last === v));
  plLoadList();
}
function plSetStage(k) {
  plStage = k;
  document.getElementById('pl-sub').style.display = (k === 'followup') ? '' : 'none';
  if (k !== 'followup') plLastF = '';
  document.querySelectorAll('.pl-tab').forEach(t => t.classList.toggle('on', t.dataset.stage === k));
  history.replaceState(null, '', '?page=pipeline&stage=' + k);
  plLoadList();
}
function plReload() { plLoadCounts(); plLoadList(); }
async function plLoadCounts() {
  const r = await fetchAPI('pipeline/counts?assignee=' + encodeURIComponent(document.getElementById('pl-assignee').value), { silent: true, timeout: 60000 });
  if (!r || !r.success) return;
  for (const [k, v] of Object.entries(r.data)) {
    const t = document.querySelector('.pl-tab[data-stage="' + k + '"]'); if (!t) continue;
    t.querySelector('.pl-cnt').textContent = plFa(v.count);
    const w = t.querySelector('.pl-warn');
    w.style.display = v.warn ? '' : 'none'; w.textContent = v.warn ? '⚠ ' + plFa(v.warn) : ''; w.title = 'نزدیک یا گذشته از مهلت';
  }
}
function plPath(hist) {
  if (!hist || !hist.length) return '';
  return '<div class="pl-path">' + hist.filter(h => h.to_stage !== h.from_stage || h.reason).map(h =>
    `<span class="pl-step pl-${h.to_stage}" title="${plEsc(h.reason || '')}">${plEsc(h.to_label || '-')}<small>${plFa(h.jalali)} · ${plEsc(h.is_auto == 1 ? 'خودکار' : (h.user_name || ''))}</small></span>`
  ).join('<i>←</i>') + '</div>';
}
async function plLoadList() {
  const body = document.getElementById('pl-body');
  body.innerHTML = '<tr><td colspan="7" class="jj-empty">در حال بارگذاری...</td></tr>';
  const q = new URLSearchParams({ stage: plStage, assignee: document.getElementById('pl-assignee').value, search: document.getElementById('pl-search').value.trim(), last: plLastF });
  const r = await fetchAPI('pipeline/list?' + q, { silent: true, timeout: 60000 });
  if (!r || !r.success) { body.innerHTML = '<tr><td colspan="7" class="jj-empty">خطا در دریافت لیست</td></tr>'; return; }
  const rows = r.data || [];
  if (!rows.length) { body.innerHTML = '<tr><td colspan="7" class="jj-empty">شماره‌ای در این مرحله نیست 👌</td></tr>'; return; }
  const closed = plStage === 'reserved' || plStage === 'not_reserved';
  body.innerHTML = rows.map(n => {
    const rem = n.remaining_seconds;
    const dl = n.is_open ? (rem <= 0 ? '<span class="jj-pill bad">گذشته</span>' : (rem < 86400 ? `<span class="jj-pill warn">⚠ ${plDur(rem)} مانده</span>` : `<span class="jj-muted">${plDur(rem)} مانده</span>`)) : '—';
    const lastDir = n.last_direction === 'outbound' ? '📤' : '📥';
    const lastSt = n.last_status === 'answered' ? 'پاسخ داده شد' : (n.last_status ? 'بی‌پاسخ' : '');
    const radios = Object.entries(PL_STAGES).map(([k, l]) =>
      `<label class="pl-rd pl-${k}${k === n.stage ? ' on' : ''}"><input type="radio" name="st-${n.phone10}" value="${k}" ${k === n.stage ? 'checked' : ''} onchange="plMove('${n.phone10}','${k}',this)"><span>${plEsc(l)}</span></label>`).join('');
    const assign = PL_IS_MGR
      ? `<select class="pl-assign" onchange="plAssign('${n.phone10}', this.value)"><option value="0">— بدون مسئول —</option>${PL_USERS.map(u => `<option value="${u.id}" ${u.id == n.assigned_user_id ? 'selected' : ''}>${plEsc(u.full_name)}</option>`).join('')}</select>`
      : (n.assigned_name ? plEsc(n.assigned_name) : '<span class="jj-muted">بدون مسئول</span>');
    const unanswered = n.last_status && n.last_status !== 'answered';
    const called = n.called_back_at
      ? `<button class="pl-called on" title="${plEsc((n.called_back_by || '') + ' — ' + n.called_back_at)} (برای برداشتن کلیک کنید)" onclick="plCalled('${n.phone10}',0)">✓ تماس گرفته شد</button>`
      : ((unanswered || n.stage === 'no_answer') ? `<button class="pl-called" onclick="plCalled('${n.phone10}',1)">تماس گرفته شد؟</button>` : '');
    return `<tr data-p="${n.phone10}">
      <td><input class="pl-name" value="${plEsc(n.name || '')}" placeholder="تعریف نام…" data-v="${plEsc(n.name || '')}" onblur="plSaveName('${n.phone10}', this)" onkeydown="if(event.key==='Enter')this.blur()">
          <a href="#" class="jj-btn" title="سابقه‌ی مرحله‌ها" onclick="event.preventDefault();plOpen('${n.phone10}')">🕘</a>
          <small><a dir="ltr" class="jj-num" href="${PL_BASE}/index.php?page=phone_detail&number=${n.phone}">${n.phone}</a>
          <a class="jj-btn call" href="tel:${n.phone}" title="تماس">📞</a> ${called}</small>
          ${closed ? plPath(n.history) : ''}</td>
      <td>${plRefCell(n)}</td>
      <td>${assign}</td>
      <td class="jj-time">${plFa((n.stage_since_jalali || '').slice(11))}<small>${plFa((n.stage_since_jalali || '').slice(0, 10))} · ${plDur(n.in_stage_seconds)}</small></td>
      <td>${dl}</td>
      <td class="jj-time">${lastDir} ${lastSt}<small>${plFa(n.last_event_jalali || '')}</small></td>
      <td><div class="pl-radios">${radios}</div></td>
    </tr>`;
  }).join('');
}
async function plMove(p10, stage, el) {
  const r = await fetchAPI('pipeline/number/' + p10 + '/stage', { method: 'PATCH', body: { stage } });
  if (r && r.success) {
    showToast(r.message, 'success');
    plLoadCounts();
    const tr = el && el.closest('tr');
    if (tr) { tr.querySelectorAll('.pl-rd').forEach(x => x.classList.toggle('on', x.querySelector('input').checked)); tr.style.transition = 'opacity .5s'; setTimeout(() => { tr.style.opacity = '.35'; }, 300); setTimeout(plLoadList, 1100); }
  } else { plLoadList(); }
}
function plRefCell(n) {
  const opts = (list, cur) => list.map(x => `<option ${x === cur ? 'selected' : ''}>${plEsc(x)}</option>`).join('');
  const refs = PL_REFS.slice(); if (n.ref_source && !refs.includes(n.ref_source)) refs.push(n.ref_source);
  const sel = `<select class="pl-ref" onchange="plSaveRef('${n.phone10}', this.closest('td'))"><option value="">مرجع؟</option>${opts(refs, n.ref_source)}</select>
    <select class="pl-role" onchange="plSaveRef('${n.phone10}', this.closest('td'))"><option value="">نقش؟</option>${Object.entries(PL_ROLES).map(([k, l]) => `<option value="${k}" ${k === n.ref_role ? 'selected' : ''}>${plEsc(l)}</option>`).join('')}</select>`;
  if (n.is_old) {
    const note = n.ref_source ? `<small>اولین بار از «${plEsc(n.ref_source)}» آشنا شد${n.ref_role_label ? ' · ' + plEsc(n.ref_role_label) : ''}</small>` : '<small class="jj-muted">مرجع آشنایی ثبت نشده</small>';
    return `<span class="jj-pill info">قدیمی</span> <span class="jj-muted xs">${plFa(n.call_count || 0)} تماس</span>${note}${n.ref_source ? '' : sel}`;
  }
  return sel;
}
async function plSaveRef(p10, td) {
  const r = await fetchAPI('pipeline/number/' + p10 + '/ref', { method: 'PATCH', body: { ref_source: td.querySelector('.pl-ref').value, ref_role: td.querySelector('.pl-role').value } });
  if (r && r.success) showToast('ذخیره شد', 'success');
}
async function plCalled(p10, v) {
  const r = await fetchAPI('pipeline/number/' + p10 + '/called', { method: 'PATCH', body: { value: v } });
  if (r && r.success) { showToast(r.message, 'success'); plLoadList(); }
}
async function plSaveName(p10, inp) {
  const name = inp.value.trim();
  if (name === (inp.dataset.v || '')) return;
  const r = await fetchAPI('pipeline/number/' + p10 + '/name', { method: 'PATCH', body: { name } });
  if (r && r.success) { inp.dataset.v = name; showToast('نام ذخیره شد', 'success'); }
}
async function plAssign(p10, uid) {
  const r = await fetchAPI('pipeline/number/' + p10 + '/assign', { method: 'PATCH', body: { user_id: +uid } });
  if (r && r.success) { showToast(r.message, 'success'); plLoadCounts(); }
}
async function plOpen(p10) {
  document.getElementById('pl-modal').classList.add('show');
  const b = document.getElementById('pl-modal-body'); b.innerHTML = 'در حال بارگذاری...';
  const r = await fetchAPI('pipeline/number/' + p10, { silent: true });
  if (!r || !r.success) { b.innerHTML = 'خطا'; return; }
  const n = r.data.number || {}, h = r.data.history || [];
  document.getElementById('pl-modal-title').textContent = (n.name || 'بدون نام') + ' — 0' + p10;
  b.innerHTML = `<p>مرحله‌ی فعلی: <b>${plEsc(n.stage_label || '—')}</b> · مسئول: ${plEsc(n.assigned_name || 'ندارد')}</p>
    <table class="data-table jj-table"><thead><tr><th>زمان</th><th>از</th><th>به</th><th>توسط</th><th>توضیح</th></tr></thead><tbody>
    ${h.map(x => `<tr><td class="jj-time">${plFa(x.jalali)}</td><td>${plEsc(x.from_label || '—')}</td><td><b>${plEsc(x.to_label || '—')}</b></td><td>${x.is_auto == 1 ? '🤖 خودکار' : plEsc(x.user_name || '')}</td><td>${plEsc(x.reason || '')}</td></tr>`).join('') || '<tr><td colspan="5" class="jj-empty">بدون سابقه</td></tr>'}
    </tbody></table>
    <p style="margin-top:12px"><a class="btn btn-primary btn-sm" href="${PL_BASE}/index.php?page=phone_detail&number=0${p10}">تاریخچه‌ی تماس‌ها</a></p>`;
}
function plClose() { document.getElementById('pl-modal').classList.remove('show'); }
async function plSettings() {
  const s = await fetchAPI('pipeline/settings', { silent: true });
  const cur = (s && s.data) || {};
  const m = prompt('هشدار تماس بی‌پاسخ پس از چند دقیقه؟', cur.missed_alert_minutes || 30); if (m === null) return;
  const d = prompt('حداکثر روز ماندن شماره در مراحل میانی؟', cur.pipeline_max_days || 3); if (d === null) return;
  const a = prompt('ارجاع خودکار شماره‌ی بدون مسئول به پشتیبان آنلاین بعد از چند دقیقه؟ (۰ = غیرفعال)', cur.pipeline_auto_assign_minutes ?? 20); if (a === null) return;
  const body = { missed_alert_minutes: +m, pipeline_max_days: +d, pipeline_auto_assign_minutes: +a };
  if (+a > 0) {
    const ur = await fetchAPI('pipeline/users', { silent: true });
    const users = (ur && ur.data) || [], pool = cur.pipeline_auto_assign_users || [];
    const picked = await plPickPool(users, pool);
    if (picked === null) return;
    body.pipeline_auto_assign_users = picked;
  }
  const r = await fetchAPI('pipeline/settings', { method: 'PATCH', body });
  if (r && r.success) { showToast('ذخیره شد', 'success'); document.getElementById('pl-maxdays').textContent = plFa(+d); plReload(); }
}
// انتخاب پشتیبان‌هایی که ارجاع خودکار به آن‌ها می‌رسد (هیچ‌کدام = همه‌ی پشتیبان‌های غیرمدیر)
function plPickPool(users, pool) {
  return new Promise(res => {
    const b = document.getElementById('pl-modal-body');
    document.getElementById('pl-modal-title').textContent = 'ارجاع خودکار فقط به این پشتیبان‌ها';
    b.innerHTML = `<p style="color:#666;font-size:13px">فقط پشتیبانی‌هایی را تیک بزنید که باید ارجاع خودکار بگیرند (مثلاً مدیران را تیک نزنید). اگر هیچ‌کس تیک نخورد، همه‌ی پشتیبان‌های غیرمدیر در نظر گرفته می‌شوند.</p>
      <div style="max-height:320px;overflow:auto;margin:8px 0">${users.map(u => `<label style="display:flex;gap:8px;padding:4px 0"><input type="checkbox" class="pl-pool" value="${plEsc(u.full_name)}" ${pool.includes(u.full_name) ? 'checked' : ''}> ${plEsc(u.full_name)}</label>`).join('')}</div>
      <button class="btn btn-primary btn-sm" id="pl-pool-ok">ذخیره</button> <button class="btn btn-sm" id="pl-pool-no">انصراف</button>`;
    document.getElementById('pl-modal').classList.add('show');
    document.getElementById('pl-pool-ok').onclick = () => { const v = [...b.querySelectorAll('.pl-pool:checked')].map(x => x.value); plClose(); res(v); };
    document.getElementById('pl-pool-no').onclick = () => { plClose(); res(null); };
  });
}
document.addEventListener('DOMContentLoaded', plReload);
</script>
<?php require __DIR__ . '/parts/footer.php'; ?>
