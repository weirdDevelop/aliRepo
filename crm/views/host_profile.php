<?php
/**
 * جاکجاست - پروفایل میزبان
 * Jakojast - Host Profile Page
 *
 * تب‌ها: اطلاعات، تسویه‌ها، تیکت‌ها، عملکرد
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$hostId = (int)($_GET['id'] ?? 0);
if (!$hostId) {
    echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
            <h2>شناسه میزبان مشخص نیست</h2>
            <a href="' . BASE_URL . '/index.php?page=hosts" class="btn btn-primary" style="margin-top:16px">بازگشت</a>
          </div></main>';
    require __DIR__ . '/parts/footer.php';
    exit;
}

try {
    $host = Host::find($hostId);
    if (!$host) {
        echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
                <h2>میزبان یافت نشد</h2>
                <a href="' . BASE_URL . '/index.php?page=hosts" class="btn btn-primary" style="margin-top:16px">بازگشت</a>
              </div></main>';
        require __DIR__ . '/parts/footer.php';
        exit;
    }
    $settlements = Host::getSettlements($hostId);
    $tickets = Host::getTickets($hostId);
    $properties = HostProperty::forHost($hostId);
} catch (Exception $e) {
    Logger::error('Host profile load failed: ' . $e->getMessage());
    $host = null; $settlements = []; $tickets = []; $properties = [];
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-host_profile" class="page active">
    <div class="card">
      <div class="profile-header">
        <div class="avatar" style="width:80px;height:80px;font-size:32px;background:<?= Helpers::avatarColor($host['name']) ?>">
          <?= Helpers::initials($host['name']) ?>
        </div>
        <div class="ph-info">
          <h2><?= Helpers::e($host['name']) ?></h2>
          <div style="display:flex;gap:8px;align-items:center;margin-top:4px;flex-wrap:wrap">
            <span class="badge <?= $host['status'] === 'active' ? 'badge-success' : ($host['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
              <?= $host['status'] === 'active' ? 'فعال' : ($host['status'] === 'pending' ? 'در انتظار' : 'غیرفعال') ?>
            </span>
            <span class="badge badge-secondary"><?= Helpers::e($host['level']) ?></span>
            <span class="badge badge-info">⭐ <?= Helpers::e($host['rating']) ?> از ۵</span>
          </div>
          <div style="display:flex;gap:16px;margin-top:8px;font-size:13px;color:var(--text-secondary);flex-wrap:wrap">
            <span>📞 <span dir="ltr"><?= Helpers::e($host['mobile'] ?: '-') ?></span></span>
            <span>📧 <?= Helpers::e($host['email'] ?: '-') ?></span>
            <span>📍 <?= Helpers::e($host['city'] ?: '-') ?></span>
          </div>
        </div>
        <div class="ph-actions">
          <a href="<?= BASE_URL ?>/index.php?page=hosts" class="btn btn-light btn-sm">← بازگشت</a>
          <?php if ($host['status'] === 'pending'): ?>
            <button class="btn btn-success btn-sm" onclick="changeStatus(<?= (int)$hostId ?>, 'active')">✓ تأیید</button>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" onclick="showToast('ویرایش در صفحه میزبانان','info')">✏️ ویرایش</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)">
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">کد ملی</div>
          <div class="fw-700 mt-1" dir="ltr"><?= Helpers::e($host['national_code'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">عضویت</div>
          <div class="fw-700 mt-1"><?= Helpers::e($host['join_date'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">اقامتگاه‌ها</div>
          <div class="fw-700 text-info mt-1"><?= Helpers::toPersianDigits($host['properties']) ?></div>
        </div>
        <div style="padding:14px;text-align:center">
          <div class="text-muted fs-12">کل درآمد</div>
          <div class="fw-700 text-success mt-1"><?= Helpers::toPersianDigits(Helpers::formatNumber($host['total_revenue'])) ?> ت</div>
        </div>
      </div>

      <div class="tabs-wrapper">
        <button class="tab-btn active" onclick="switchTab('hp','info',this)">👤 اطلاعات</button>
        <button class="tab-btn" onclick="switchTab('hp','properties',this)">🏡 اقامتگاه‌ها <span class="badge badge-secondary"><?= count($properties ?? []) ?></span></button>
        <button class="tab-btn" onclick="switchTab('hp','settlements',this)">💰 تسویه‌ها <span class="badge badge-secondary"><?= count($settlements) ?></span></button>
        <button class="tab-btn" onclick="switchTab('hp','tickets',this)">🎫 تیکت‌ها <span class="badge badge-secondary"><?= count($tickets) ?></span></button>
      </div>

      <!-- تب اطلاعات -->
      <div id="hp-info" class="tab-content active">
        <div style="padding:20px">
          <div class="grid-2">
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">شماره شبا</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($host['sheba'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">کد ملی</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($host['national_code'] ?: '-') ?></div>
            </div>
          </div>
          <?php if (!empty($host['notes'])): ?>
            <div style="margin-top:16px;background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">یادداشت</div>
              <div><?= nl2br(Helpers::e($host['notes'])) ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب اقامتگاه‌ها -->
      <div id="hp-properties" class="tab-content">
        <div style="padding:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:8px;flex-wrap:wrap">
            <h4>🏡 اقامتگاه‌های این میزبان</h4>
            <button class="btn btn-primary btn-sm" onclick="openPropertyModal()">+ اقامتگاه جدید</button>
          </div>
          <div id="host-properties-list">
            <?php if (empty($properties)): ?>
              <div style="text-align:center;padding:30px;color:var(--text-muted)">اقامتگاهی ثبت نشده است</div>
            <?php else: ?>
              <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
                <?php foreach ($properties as $p): ?>
                  <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--bg-secondary)">
                    <?php if (!empty($p['cover_image'])): ?>
                      <img src="<?= BASE_URL ?>/<?= Helpers::e($p['cover_image']) ?>" alt="" style="width:100%;height:140px;object-fit:cover">
                    <?php else: ?>
                      <div style="height:140px;display:flex;align-items:center;justify-content:center;background:#eee;color:var(--text-muted)">بدون تصویر</div>
                    <?php endif; ?>
                    <div style="padding:12px">
                      <div style="font-weight:700;margin-bottom:4px"><?= Helpers::e($p['title']) ?></div>
                      <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px">
                        <?= Helpers::e($p['property_type'] ?: '-') ?> · <?= Helpers::e($p['city'] ?: '-') ?>
                        <?php if (!empty($p['base_price'])): ?> · <?= Helpers::toPersianDigits(Helpers::formatNumber($p['base_price'])) ?> ت<?php endif; ?>
                      </div>
                      <div style="display:flex;gap:4px;flex-wrap:wrap">
                        <button class="btn btn-light btn-xs" onclick='editProperty(<?= json_encode($p, JSON_UNESCAPED_UNICODE) ?>)'>✏️</button>
                        <label class="btn btn-info btn-xs" style="margin:0;cursor:pointer">
                          📷 عکس
                          <input type="file" accept="image/*" style="display:none" onchange="uploadPropertyImage(<?= (int)$p['id'] ?>, this)">
                        </label>
                        <button class="btn btn-danger btn-xs" onclick="deleteProperty(<?= (int)$p['id'] ?>)">🗑</button>
                      </div>
                      <?php if (!empty($p['images'])): ?>
                        <div style="display:flex;gap:4px;margin-top:8px;flex-wrap:wrap">
                          <?php foreach ($p['images'] as $img): ?>
                            <div style="position:relative">
                              <img src="<?= BASE_URL ?>/<?= Helpers::e($img['file_path']) ?>" style="width:48px;height:48px;object-fit:cover;border-radius:6px">
                              <button type="button" title="حذف" onclick="deletePropertyImage(<?= (int)$p['id'] ?>, <?= (int)$img['id'] ?>)" style="position:absolute;top:-4px;left:-4px;border:none;background:#dc3545;color:#fff;border-radius:50%;width:16px;height:16px;font-size:10px;cursor:pointer;line-height:16px;padding:0">×</button>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- تب تسویه‌ها -->
      <div id="hp-settlements" class="tab-content">
        <div style="padding:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h4>💰 تاریخچه تسویه‌حساب</h4>
            <button class="btn btn-primary btn-sm" onclick="openSettlementModal()">+ درخواست تسویه جدید</button>
          </div>

          <?php
          $totalPaid = 0; $totalPending = 0;
          foreach ($settlements as $s) {
              if ($s['status'] === 'paid') $totalPaid += $s['amount'];
              elseif ($s['status'] === 'pending') $totalPending += $s['amount'];
          }
          $remainingBalance = max(0, (int)($host['total_revenue'] ?? 0) - $totalPaid);
          ?>
          <div class="grid-3" style="margin-bottom:16px">
            <div class="stat-box">
              <div class="sb-value text-success"><?= Helpers::toPersianDigits(Helpers::formatNumber($totalPaid)) ?> ت</div>
              <div class="sb-label">تسویه شده</div>
            </div>
            <div class="stat-box">
              <div class="sb-value text-warning"><?= Helpers::toPersianDigits(Helpers::formatNumber($totalPending)) ?> ت</div>
              <div class="sb-label">در انتظار</div>
            </div>
            <div class="stat-box">
              <div class="sb-value text-danger"><?= Helpers::toPersianDigits(Helpers::formatNumber($remainingBalance)) ?> ت</div>
              <div class="sb-label">مانده (از کل درآمد <?= Helpers::toPersianDigits(Helpers::formatNumber($host['total_revenue'] ?? 0)) ?> ت)</div>
            </div>
          </div>

          <?php if (empty($settlements)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">💰 تسویه‌ای ثبت نشده است</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr><th>مبلغ</th><th>تاریخ درخواست</th><th>تاریخ پرداخت</th><th>وضعیت</th><th>توضیحات</th><th></th></tr>
                </thead>
                <tbody>
                  <?php foreach ($settlements as $s): ?>
                    <tr>
                      <td><?= Helpers::toPersianDigits(Helpers::formatNumber($s['amount'])) ?> ت</td>
                      <td><?= Helpers::e($s['request_date'] ?: '-') ?></td>
                      <td><?= Helpers::e($s['paid_date'] ?: '-') ?></td>
                      <td>
                        <span class="badge <?= $s['status'] === 'paid' ? 'badge-success' : ($s['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                          <?= $s['status'] === 'paid' ? 'پرداخت شده' : ($s['status'] === 'pending' ? 'در انتظار' : 'رد شده') ?>
                        </span>
                      </td>
                      <td><?= Helpers::e($s['note'] ?: '-') ?></td>
                      <td>
                        <?php if ($s['status'] === 'pending' && ($currentUser['role'] ?? '') !== 'staff'): ?>
                          <div style="display:flex;gap:4px">
                            <button class="btn btn-success btn-xs" onclick="updateSettlementStatus(<?= (int)$s['id'] ?>, 'paid')">✓ پرداخت شد</button>
                            <button class="btn btn-danger btn-xs" onclick="updateSettlementStatus(<?= (int)$s['id'] ?>, 'rejected')">✗ رد</button>
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب تیکت‌ها -->
      <div id="hp-tickets" class="tab-content">
        <div style="padding:20px">
          <?php if (empty($tickets)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">🎫 تیکتی ثبت نشده است</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>عنوان</th><th>اولویت</th><th>وضعیت</th><th>بخش</th><th>تاریخ</th></tr></thead>
                <tbody>
                  <?php foreach ($tickets as $t): ?>
                    <tr>
                      <td><?= Helpers::e($t['title']) ?></td>
                      <td><span class="badge <?= $t['priority'] === 'high' ? 'badge-danger' : 'badge-secondary' ?>"><?= $t['priority'] ?></span></td>
                      <td><span class="badge badge-secondary"><?= $t['status'] ?></span></td>
                      <td><?= Helpers::e($t['dept'] ?? '-') ?></td>
                      <td><?= Helpers::e($t['date']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- مودال تسویه -->
<div class="modal-overlay" id="modal-settlement">
  <div class="modal-box modal-sm">
    <div class="modal-header">
      <h4>💰 درخواست تسویه جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-settlement')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">مبلغ (تومان) <span style="color:red">*</span></label>
        <input type="number" class="form-control" id="settle-amount" placeholder="10000000">
      </div>
      <div class="form-group">
        <label class="form-label">توضیحات</label>
        <textarea class="form-control" id="settle-note" rows="2" placeholder="توضیح..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-settlement')">انصراف</button>
      <button class="btn btn-primary" onclick="submitSettlement()">✓ ثبت درخواست</button>
    </div>
  </div>
</div>

<script>
const hostId = <?= (int)$hostId ?>;

async function updateSettlementStatus(settlementId, status) {
    const label = status === 'paid' ? 'پرداخت‌شده' : 'رد';
    if (!confirm(`این تسویه به وضعیت «${label}» تغییر کند؟`)) return;
    try {
        const result = await fetchAPI('host-settlements/' + settlementId + '/status', { method: 'PATCH', body: { status } });
        if (result.success) {
            showToast('وضعیت به‌روزرسانی شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(result.message || 'خطا', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

function openSettlementModal() {
    document.getElementById('settle-amount').value = '';
    document.getElementById('settle-note').value = '';
    openModal('modal-settlement');
}

async function submitSettlement() {
    const amount = parseInt(document.getElementById('settle-amount').value);
    const note = document.getElementById('settle-note').value.trim();
    if (!amount || amount <= 0) return showToast('مبلغ نامعتبر است', 'warning');

    try {
        const result = await fetchAPI(`hosts/${hostId}/settlements`, {
            method: 'POST', body: { amount, note }
        });
        if (result.success) {
            showToast('درخواست تسویه ثبت شد', 'success');
            closeModal('modal-settlement');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function changeStatus(id, status) {
    if (!confirm('تغییر وضعیت میزبان؟')) return;
    try {
        const result = await fetchAPI(`hosts/${id}/status`, {
            method: 'PATCH', body: { status }
        });
        if (result.success) {
            showToast('وضعیت تغییر کرد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}

let editingPropertyId = null;
function openPropertyModal(data) {
    editingPropertyId = data && data.id ? data.id : null;
    document.getElementById('prop-modal-title').textContent = editingPropertyId ? 'ویرایش اقامتگاه' : 'اقامتگاه جدید';
    document.getElementById('prop-title').value = (data && data.title) || '';
    document.getElementById('prop-city').value = (data && data.city) || '';
    document.getElementById('prop-type').value = (data && data.property_type) || '';
    document.getElementById('prop-price').value = (data && data.base_price) || '';
    document.getElementById('prop-capacity').value = (data && data.capacity) || '';
    document.getElementById('prop-address').value = (data && data.address) || '';
    document.getElementById('prop-notes').value = (data && data.notes) || '';
    openModal('modal-property');
}
function editProperty(data) { openPropertyModal(data); }

async function saveProperty() {
    const body = {
        title: document.getElementById('prop-title').value.trim(),
        city: document.getElementById('prop-city').value.trim(),
        property_type: document.getElementById('prop-type').value.trim(),
        base_price: parseInt(document.getElementById('prop-price').value) || 0,
        capacity: parseInt(document.getElementById('prop-capacity').value) || null,
        address: document.getElementById('prop-address').value.trim(),
        notes: document.getElementById('prop-notes').value.trim(),
        status: 'active',
    };
    if (!body.title) return showToast('عنوان الزامی است', 'warning');
    try {
        let result;
        if (editingPropertyId) {
            result = await fetchAPI('host-properties/' + editingPropertyId, { method: 'PUT', body });
        } else {
            result = await fetchAPI('hosts/' + hostId + '/properties', { method: 'POST', body });
        }
        if (result.success) {
            showToast('ذخیره شد', 'success');
            closeModal('modal-property');
            setTimeout(() => location.reload(), 700);
        } else showToast(result.message || 'خطا', 'error');
    } catch (e) { showToast('خطا در ارتباط', 'error'); }
}

async function deleteProperty(id) {
    if (!confirm('اقامتگاه حذف شود؟')) return;
    const r = await fetchAPI('host-properties/' + id, { method: 'DELETE' });
    if (r.success) location.reload();
    else showToast(r.message || 'خطا', 'error');
}

async function uploadPropertyImage(propertyId, input) {
    if (!input.files || !input.files[0]) return;
    const fd = new FormData();
    fd.append('image', input.files[0]);
    try {
        const token = (window.JJ && JJ.token) || localStorage.getItem('jj_token') || '';
        const res = await fetch((window.JJ && JJ.baseUrl ? JJ.baseUrl : '') + '/api.php/host-properties/' + propertyId + '/images', {
            method: 'POST',
            headers: Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, token ? { 'Authorization': 'Bearer ' + token } : {}),
            credentials: 'same-origin',
            body: fd,
        });
        const result = await res.json();
        if (result.success) {
            showToast('تصویر افزوده شد', 'success');
            setTimeout(() => location.reload(), 700);
        } else showToast(result.message || 'خطا در آپلود', 'error');
    } catch (e) { showToast('خطا در آپلود', 'error'); }
    input.value = '';
}

async function deletePropertyImage(propertyId, imageId) {
    if (!confirm('تصویر حذف شود؟')) return;
    const r = await fetchAPI('host-properties/' + propertyId + '/images/' + imageId, { method: 'DELETE' });
    if (r.success) location.reload();
    else showToast(r.message || 'خطا', 'error');
}
</script>

<div class="modal-overlay" id="modal-property">
  <div class="modal-box">
    <div class="modal-header">
      <h4 id="prop-modal-title">اقامتگاه جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-property')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">عنوان *</label><input class="form-control" id="prop-title"></div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">شهر</label><input class="form-control" id="prop-city"></div>
        <div class="form-group"><label class="form-label">نوع</label>
          <select class="form-control" id="prop-type">
            <option value="">—</option>
            <?php foreach (HostProperty::$types as $t): ?><option value="<?= Helpers::e($t) ?>"><?= Helpers::e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid-2">
        <div class="form-group"><label class="form-label">قیمت پایه (تومان)</label><input type="number" class="form-control" id="prop-price"></div>
        <div class="form-group"><label class="form-label">ظرفیت</label><input type="number" class="form-control" id="prop-capacity"></div>
      </div>
      <div class="form-group"><label class="form-label">آدرس</label><input class="form-control" id="prop-address"></div>
      <div class="form-group"><label class="form-label">یادداشت</label><textarea class="form-control" id="prop-notes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-property')">انصراف</button>
      <button class="btn btn-primary" onclick="saveProperty()">💾 ذخیره</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
