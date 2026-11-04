<?php
/**
 * جاکجاست - جزئیات شماره تلفن (خط‌روند کامل)
 * Jakojast - Phone Number Detail / Timeline Page
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$phoneNumber = trim($_GET['number'] ?? '');
if (empty($phoneNumber)) {
    echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
            <h2>شماره مشخص نیست</h2>
            <a href="' . BASE_URL . '/index.php?page=calls" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
          </div></main>';
    require __DIR__ . '/parts/footer.php';
    exit;
}

try {
    $__p10 = Pipeline::norm($phoneNumber);
    $__pn = $__p10 ? Pipeline::get($__p10) : null;
    // شماره‌ای که به این کاربر ارجاع شده: کل تاریخچه‌ی تماس‌هایش را ببیند
    $__vis = ($__pn && (int)$__pn['assigned_user_id'] === (int)$currentUser['id']) ? null : Auth::getVisibleExtens();
    $profile = Call::getFullNumberProfile($phoneNumber, $__vis);
} catch (Exception $e) {
    Logger::error('Phone detail load failed: ' . $e->getMessage());
    $profile = ['calls' => [], 'name' => null, 'contact_id' => null, 'lead_id' => null, 'stats' => [], 'customer' => null, 'leads' => [], 'tickets' => []];
}

$calls = $profile['calls'];
$name = (!empty($__pn['name']) ? $__pn['name'] : null) ?: $profile['name'];
$contactId = $profile['contact_id'];
$customer = $profile['customer'];
$leads = $profile['leads'];
$tickets = $profile['tickets'];
$stats = $profile['stats'];

$leadStageColors = ['جدید' => 'badge-info', 'تماس اول' => 'badge-warning', 'مذاکره' => 'badge-warning', 'قرارداد' => 'badge-success', 'برنده' => 'badge-success', 'باخته' => 'badge-danger'];
$ticketStatusColors = ['open' => 'badge-danger', 'in_review' => 'badge-warning', 'answered' => 'badge-info', 'closed' => 'badge-success'];
$ticketStatusLabels = ['open' => 'باز', 'in_review' => 'در حال بررسی', 'answered' => 'پاسخ‌داده‌شده', 'closed' => 'بسته'];
?>
<main id="main-content">
  <div class="page-header">
    <h1 id="pd-title">📇 <?= $name ? Helpers::e($name) : 'شماره ناشناس' ?></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="<?= BASE_URL ?>/index.php?page=calls" class="btn btn-light">← بازگشت به لیست تماس‌ها</a>
      <?php if (defined('CRM_BRIDGED') && CRM_BRIDGED && ($__u10 = substr(preg_replace('/\D+/', '', (string)($phoneNumber)), -10)) && strlen($__u10) === 10 && $__u10[0] === '9'): ?>
          <a class="btn btn-success btn-sm" target="_top" href="<?= Helpers::e(Auth::elanbanUrl()) ?>#persons.tel0<?= $__u10 ?>" title="پیام‌های همه‌ی کانال‌ها، تماس‌ها و سوابق CRM در یک تایم‌لاین">🗂 پرونده‌ی یکپارچه</a>
          <?php endif; ?>

      <?php if ($contactId): ?>
        <a href="<?= BASE_URL ?>/index.php?page=customer_profile&id=<?= (int)$contactId ?>" class="btn btn-primary">👤 پروفایل کامل مشتری</a>
      <?php elseif (!empty($calls)): ?>
        <button class="btn btn-primary" id="pd-convert-btn" onclick="convertNumberToLead(<?= (int)$calls[0]['id'] ?>)">🎯 تبدیل به سرنخ</button>
      <?php endif; ?>
    </div>
  </div>

  <div id="pd-enrich-banner"></div>

  <!-- پیگیری مرحله‌ای این شماره (r7) -->
  <?php if ($__p10): ?>
  <div class="jj-card" id="pd-pipeline" style="padding:16px;margin-bottom:16px">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
      <div><b>📋 مرحله‌ی پیگیری:</b> <span id="pdp-stage" class="jj-pill info">…</span> <span id="pdp-rem" class="jj-muted"></span>
        · مسئول: <span id="pdp-assignee">…</span></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn btn-light btn-sm" onclick="pdpRename()">✏️ تعریف نام</button>
        <span id="pdp-assign-box"></span>
      </div>
    </div>
    <div class="pl-moves" id="pdp-moves" style="max-width:none;margin-top:10px"></div>
    <div id="pdp-hist" style="margin-top:10px"></div>
  </div>
  <script>
  const PDP_P10 = '<?= $__p10 ?>';
  const pdpFa = s => String(s ?? '').replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const pdpEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function pdpLoad() {
    const r = await fetchAPI('pipeline/number/' + PDP_P10, { silent: true });
    if (!r || !r.success) return;
    const n = r.data.number || {}, h = r.data.history || [], st = r.data.stages;
    document.getElementById('pdp-stage').textContent = n.stage_label || 'بدون مرحله';
    document.getElementById('pdp-rem').textContent = n.remaining_seconds != null ? (n.remaining_seconds > 0 ? '(' + pdpFa(Math.ceil(n.remaining_seconds / 3600)) + ' ساعت تا بسته‌شدن خودکار)' : '(مهلت گذشته)') : '';
    document.getElementById('pdp-assignee').textContent = n.assigned_name || 'ندارد';
    window._pdpName = n.name || '';
    if (n.name) document.getElementById('pd-title').textContent = '📇 ' + n.name;
    document.getElementById('pdp-moves').innerHTML = r.data.can_edit ? Object.entries(st).filter(([k]) => k !== n.stage).map(([k, l]) => `<button class="pl-mv pl-${k}" onclick="pdpMove('${k}')">→ ${pdpEsc(l)}</button>`).join('') : '<span class="jj-muted">این شماره به همکار دیگری ارجاع شده است.</span>';
    if (r.data.is_manager && !window._pdpUsers) { const u = await fetchAPI('pipeline/users', { silent: true }); window._pdpUsers = (u && u.data) || []; }
    if (r.data.is_manager) document.getElementById('pdp-assign-box').innerHTML = `<select class="pl-assign" onchange="pdpAssign(this.value)"><option value="0">ارجاع به…</option>${window._pdpUsers.map(u => `<option value="${u.id}" ${u.id == n.assigned_user_id ? 'selected' : ''}>${pdpEsc(u.full_name)}</option>`).join('')}</select>`;
    document.getElementById('pdp-hist').innerHTML = h.length ? '<div class="pl-path">' + h.map(x => `<span class="pl-step pl-${x.to_stage}" title="${pdpEsc(x.reason || '')}">${pdpEsc(x.to_label || '-')}${x.reason ? ' · ' + pdpEsc(x.reason) : ''}<small>${pdpFa(x.jalali)} · ${x.is_auto == 1 ? 'خودکار' : pdpEsc(x.user_name || '')}</small></span>`).join('<i>←</i>') + '</div>' : '<span class="jj-muted">هنوز سابقه‌ی مرحله‌ای ندارد.</span>';
  }
  async function pdpMove(stage) {
    const note = prompt('توضیح (اختیاری):', ''); if (note === null) return;
    const r = await fetchAPI('pipeline/number/' + PDP_P10 + '/stage', { method: 'PATCH', body: { stage, note } });
    if (r && r.success) { showToast(r.message, 'success'); pdpLoad(); }
  }
  async function pdpRename() {
    const name = prompt('نام این شماره:', window._pdpName || ''); if (name === null) return;
    const r = await fetchAPI('pipeline/number/' + PDP_P10 + '/name', { method: 'PATCH', body: { name } });
    if (r && r.success) { showToast('نام ذخیره شد', 'success'); pdpLoad(); }
  }
  async function pdpAssign(uid) {
    const r = await fetchAPI('pipeline/number/' + PDP_P10 + '/assign', { method: 'PATCH', body: { user_id: +uid } });
    if (r && r.success) { showToast('ارجاع ثبت شد', 'success'); pdpLoad(); }
  }
  document.addEventListener('DOMContentLoaded', pdpLoad);
  </script>
  <?php endif; ?>

  <!-- آمار خلاصه -->
  <div class="card" style="padding:20px;margin-bottom:20px">
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center">
      <div><div style="font-size:12px;color:var(--text-muted)">شماره</div><div style="font-size:18px;font-weight:700" dir="ltr"><?= Helpers::e($phoneNumber) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">تعداد کل تماس‌ها</div><div style="font-size:18px;font-weight:700"><?= Helpers::toPersianDigits((string)($stats['total_calls'] ?? 0)) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">نرخ پاسخ‌گویی</div><div style="font-size:18px;font-weight:700;color:var(--success)"><?= Helpers::toPersianDigits((string)($stats['answer_rate'] ?? 0)) ?>٪</div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">مجموع مدت مکالمه</div><div style="font-size:18px;font-weight:700"><?= Helpers::toPersianDigits(gmdate('H:i:s', (int)($stats['total_talk_seconds'] ?? 0))) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">میانگین مدت مکالمه</div><div style="font-size:14px"><?= Helpers::toPersianDigits(gmdate('i:s', (int)($stats['avg_talk_seconds'] ?? 0))) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">میانگین زمان انتظار</div><div style="font-size:14px"><?= Helpers::toPersianDigits((string)($stats['avg_wait_seconds'] ?? 0)) ?> ثانیه</div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">اولین / آخرین تماس</div><div style="font-size:13px"><?= Helpers::e($stats['first_call_at'] ?? '-') ?> ← <?= Helpers::e($stats['last_call_at'] ?? '-') ?></div></div>
    </div>
  </div>

  <!-- چت اعلان‌بان (اینستاگرام + وب‌چت سایت) - این صفحه بر اساس شماره کار می‌کند
       پس حتی سرنخ‌هایی که هنوز مشتری رسمی نشده‌اند هم اینجا دیده می‌شوند -->
  <div class="card" style="margin-bottom:20px">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border)"><h3 style="margin:0">💬 چت اینستاگرام / وب‌چت سایت (اعلان‌بان)</h3></div>
    <div style="padding:20px" id="pd-elanban-box">
      <div style="text-align:center;padding:20px;color:var(--text-muted)">در حال بارگذاری...</div>
    </div>
  </div>

  <?php if ($customer): ?>
  <!-- پروفایل کامل مشتری -->
  <div class="card" style="padding:20px;margin-bottom:20px">
    <h3 style="margin-top:0">👤 اطلاعات مشتری</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px">
      <div><div style="font-size:12px;color:var(--text-muted)">نام</div><div><?= Helpers::e($customer['name']) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">سطح مشتری</div><div><span class="badge badge-primary"><?= Helpers::e($customer['level']) ?></span></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">شهر</div><div><?= Helpers::e($customer['city'] ?: '-') ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">آدرس</div><div><?= Helpers::e($customer['address'] ?: '-') ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">ایمیل</div><div dir="ltr"><?= Helpers::e($customer['email'] ?: '-') ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">تعداد رزرو</div><div><?= Helpers::toPersianDigits((string)$customer['reservations']) ?></div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">مجموع خرید</div><div><?= Helpers::toPersianDigits(number_format($customer['total_spend'])) ?> تومان</div></div>
      <div><div style="font-size:12px;color:var(--text-muted)">وضعیت</div><div><?= Helpers::e($customer['status']) ?></div></div>
    </div>
    <?php if (!empty($customer['notes'])): ?>
      <div style="margin-top:14px"><div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">یادداشت</div><div style="background:var(--bg-secondary);padding:10px;border-radius:8px;white-space:pre-wrap"><?= Helpers::e($customer['notes']) ?></div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($leads)): ?>
  <!-- تمام سرنخ‌های مرتبط با این شماره -->
  <div class="card" style="margin-bottom:20px">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border)"><h3 style="margin:0">🎯 سرنخ‌های مرتبط (<?= count($leads) ?>)</h3></div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>نام</th><th>مرحله</th><th>مسئول</th><th>منبع</th><th>ارزش</th><th>تاریخ ثبت</th></tr></thead>
        <tbody>
          <?php foreach ($leads as $l): ?>
            <tr style="cursor:pointer" onclick="window.location.href='<?= BASE_URL ?>/index.php?page=leads&id=<?= (int)$l['id'] ?>'">
              <td><?= Helpers::e($l['name']) ?></td>
              <td><span class="badge <?= $leadStageColors[$l['stage']] ?? 'badge-secondary' ?>"><?= Helpers::e($l['stage']) ?></span></td>
              <td><?= Helpers::e($l['assignee'] ?: '-') ?></td>
              <td><?= Helpers::e($l['source'] ?: '-') ?></td>
              <td><?= $l['value'] ? Helpers::toPersianDigits(number_format($l['value'])) . ' تومان' : '-' ?></td>
              <td><?= Helpers::e($l['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($tickets)): ?>
  <!-- تیکت‌های پشتیبانی مرتبط -->
  <div class="card" style="margin-bottom:20px">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border)"><h3 style="margin:0">🎫 تیکت‌های پشتیبانی (<?= count($tickets) ?>)</h3></div>
    <div class="table-wrapper">
      <table class="data-table">
        <thead><tr><th>عنوان</th><th>وضعیت</th><th>اولویت</th><th>واحد</th><th>مسئول</th><th>تاریخ</th></tr></thead>
        <tbody>
          <?php foreach ($tickets as $t): ?>
            <tr style="cursor:pointer" onclick="window.location.href='<?= BASE_URL ?>/index.php?page=tickets&id=<?= (int)$t['id'] ?>'">
              <td><?= Helpers::e($t['title']) ?></td>
              <td><span class="badge <?= $ticketStatusColors[$t['status']] ?? 'badge-secondary' ?>"><?= $ticketStatusLabels[$t['status']] ?? Helpers::e($t['status']) ?></span></td>
              <td><?= Helpers::e($t['priority']) ?></td>
              <td><?= Helpers::e($t['dept'] ?: '-') ?></td>
              <td><?= Helpers::e($t['assignee'] ?: '-') ?></td>
              <td><?= Helpers::e($t['date'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border)">
      <h3 style="margin:0">📞 تاریخچه‌ی کامل تماس‌ها</h3>
    </div>
    <?php if (empty($calls)): ?>
      <div style="text-align:center;padding:40px;color:var(--text-muted)">هیچ تماسی برای این شماره (در محدوده‌ی دید شما) ثبت نشده.</div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th></th><th>نوع</th><th>وضعیت</th><th>اپراتور</th><th>مدت مکالمه</th><th>تاریخ</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($calls as $call):
                $aiData = null;
                if (!empty($call['ai_analysis'])) {
                    $decoded = json_decode($call['ai_analysis'], true);
                    if (is_array($decoded)) $aiData = $decoded;
                }
            ?>
              <tr>
                <td>
                  <?php if (!empty($call['file_record']) || !empty($call['transcript']) || $aiData): ?>
                    <button class="btn btn-light" style="padding:2px 8px;font-size:11px" onclick="toggleCallDetail(<?= (int)$call['id'] ?>)">جزئیات ▾</button>
                  <?php endif; ?>
                </td>
                <td><span class="badge <?= $call['type'] === 'inbound' ? 'badge-info' : 'badge-primary' ?>"><?= $call['type'] === 'inbound' ? '📥 ورودی' : '📤 خروجی' ?></span></td>
                <td>
                  <span class="badge <?= $call['status'] === 'answered' ? 'badge-success' : ($call['status'] === 'ringing' ? 'badge-warning' : 'badge-danger') ?>">
                    <?= $call['status'] === 'answered' ? 'پاسخ داده شد' : ($call['status'] === 'no_answer' ? 'بی‌پاسخ' : ($call['status'] === 'ringing' ? '⏳ در حال زنگ خوردن' : $call['status'])) ?>
                  </span>
                </td>
                <td><?= Helpers::e($call['staff_name'] ?: ($call['exten_name'] ?: '-')) ?></td>
                <td><?= Helpers::toPersianDigits(gmdate('i:s', (int)$call['time_talk'])) ?></td>
                <td><?= Helpers::e($call['jalali_started_at']) ?></td>
                <td>
                  <a href="<?= BASE_URL ?>/index.php?page=call_detail&id=<?= (int)$call['id'] ?>" style="font-size:11px" onclick="event.stopPropagation()">جزئیات کامل ←</a>
                </td>
              </tr>
              <tr id="call-expand-<?= (int)$call['id'] ?>" style="display:none">
                <td colspan="7" style="background:var(--bg-secondary);padding:16px">
                  <?php if (!empty($call['file_record'])): ?>
                    <div style="margin-bottom:12px">
                      <audio controls style="width:100%;max-width:400px" dir="ltr"
                             src="<?= BASE_URL ?>/api.php/play-recording?id=<?= (int)$call['id'] ?>&token=<?= Helpers::e(Auth::extractToken() ?? '') ?>"></audio>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($call['transcript'])): ?>
                    <div style="margin-bottom:12px">
                      <strong style="font-size:12px">📝 متن مکالمه</strong>
                      <div style="font-size:13px;margin-top:4px;white-space:pre-wrap"><?= Helpers::e($call['transcript']) ?></div>
                    </div>
                  <?php endif; ?>
                  <?php if ($aiData): ?>
                    <div>
                      <strong style="font-size:12px">🤖 تحلیل هوش مصنوعی</strong>
                      <div style="font-size:13px;margin-top:4px;display:flex;flex-direction:column;gap:6px">
                        <?php if (!empty($aiData['summary'])): ?><div><strong>خلاصه:</strong> <?= Helpers::e($aiData['summary']) ?></div><?php endif; ?>
                        <?php if (!empty($aiData['customer_sentiment'])): ?><div><strong>احساس مشتری:</strong> <span class="badge badge-secondary"><?= Helpers::e($aiData['customer_sentiment']) ?></span></div><?php endif; ?>
                        <?php if (!empty($aiData['coaching_tip'])): ?><div><strong>پیشنهاد به اپراتور:</strong> <?= Helpers::e($aiData['coaching_tip']) ?></div><?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                  <?php if (empty($call['file_record']) && empty($call['transcript']) && !$aiData): ?>
                    <div style="color:var(--text-muted);font-size:12px">چیزی برای نمایش نیست.</div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
function toggleCallDetail(callId) {
    const row = document.getElementById('call-expand-' + callId);
    if (row) row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}

let pdEnrichedName = null;
async function loadElanbanChatPd() {
    const box = document.getElementById('pd-elanban-box');
    const phone = <?= json_encode($phoneNumber) ?>;
    try {
        const result = await fetchAPI(`elanban/lookup?phone=${encodeURIComponent(phone)}`, { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted)">خطا در دریافت اطلاعات اعلان‌بان</div>';
            return;
        }
        if (result.data.error) {
            box.innerHTML = `<div style="text-align:center;padding:20px;color:var(--danger)">⚠️ خطا در دریافت از اعلان‌بان: ${pdEsc(result.data.message || 'خطای نامشخص')}</div>`;
            return;
        }
        if (!result.data.configured) {
            box.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted)">اتصال به اعلان‌بان تنظیم نشده است (تنظیمات ← اعلان‌بان)</div>';
            return;
        }
        if (!result.data.found) {
            box.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted)">هیچ چتی برای این شماره در اعلان‌بان ثبت نشده است</div>';
            return;
        }
        const d = result.data.data;

        // ادغام اطلاعات: اگه تو جاکجاست اسمی برای این شماره نیست ولی
        // اعلان‌بان (از چت اینستاگرام/سایت) اسم داره، پیشنهادش بده
        const hasLocalName = <?= json_encode(!empty($name)) ?>;
        if (!hasLocalName && d.full_name) {
            pdEnrichedName = d.full_name;
            document.getElementById('pd-title').innerHTML = `📇 ${pdEsc(d.full_name)} <span style="font-size:12px;font-weight:normal;color:var(--text-muted)">(از اعلان‌بان)</span>`;
            const banner = document.getElementById('pd-enrich-banner');
            if (banner) {
                banner.innerHTML = `<div class="card" style="padding:12px 16px;margin-bottom:16px;background:var(--bg-secondary);font-size:13px">
                    💡 این شماره تو جاکجاست بدون نام بود؛ اعلان‌بان اسم «${pdEsc(d.full_name)}» رو براش داره. موقع «تبدیل به سرنخ» همین اسم پیش‌فرض قرار می‌گیره.
                </div>`;
            }
        }

        let html = `<div style="margin-bottom:14px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:var(--text-muted)">
            <span>👤 ${d.full_name ? pdEsc(d.full_name) : 'بدون نام ثبت‌شده'}</span>
            <span>📊 ${d.message_count} پیام</span>
            <span>📡 کانال پرتکرار: ${d.top_channel || '-'}</span>
        </div>`;
        if (!d.recent_messages || !d.recent_messages.length) {
            html += '<div style="text-align:center;padding:14px;color:var(--text-muted)">پیامی ثبت نشده</div>';
        } else {
            html += '<div style="display:flex;flex-direction:column;gap:8px;max-height:420px;overflow-y:auto">';
            d.recent_messages.slice().reverse().forEach(m => {
                const isOut = m.direction === 'out';
                html += `<div style="align-self:${isOut ? 'flex-start' : 'flex-end'};max-width:75%;background:${isOut ? 'var(--bg-secondary)' : 'var(--primary)'};color:${isOut ? 'inherit' : '#fff'};padding:8px 12px;border-radius:10px;font-size:13px">
                    <div>${pdEsc(m.text || '(بدون متن / رسانه)')}</div>
                    <div style="font-size:10px;opacity:.7;margin-top:4px">${m.channel || ''} · ${m.sent_at || ''}</div>
                </div>`;
            });
            html += '</div>';
        }
        box.innerHTML = html;
    } catch (err) {
        box.innerHTML = '<div style="text-align:center;padding:20px;color:var(--danger)">خطا در ارتباط با سرور</div>';
        console.error(err);
    }
}
function pdEsc(s) {
    const d = document.createElement('div');
    d.innerText = s;
    return d.innerHTML;
}
document.addEventListener('DOMContentLoaded', loadElanbanChatPd);

async function convertNumberToLead(sampleCallId) {
    try {
        const body = pdEnrichedName ? { name: pdEnrichedName } : {};
        const result = await fetchAPI('calls/' + sampleCallId + '/convert-to-lead', { method: 'POST', body });
        if (result.success) {
            showToast('به سرنخ تبدیل شد' + (pdEnrichedName ? ' (با نام از اعلان‌بان)' : ''), 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(result.message || 'خطا', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
