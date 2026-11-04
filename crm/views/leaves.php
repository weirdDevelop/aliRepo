<?php
/**
 * جاکجاست - صفحه مرخصی‌ها
 * Jakojast - Leaves Page
 *
 * مدیریت درخواست‌های مرخصی با جریان تأیید/رد
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Leave::all([
        'search' => $search, 'status' => $statusFilter, 'type' => $typeFilter,
        'page' => $page, 'limit' => 20,
    ]);
    $leaves = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 20));
    $stats = Leave::getStats();
} catch (Exception $e) {
    $leaves = []; $total = 0; $totalPages = 1; $stats = [];
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-leaves" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card yellow">
        <div class="kpi-icon">⏳</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['pending'] ?? 0) ?></div>
        <div class="kpi-label">در انتظار تأیید</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">✓</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['approved']['count'] ?? 0) ?></div>
        <div class="kpi-label">تأیید شده</div>
      </div>
      <div class="kpi-card red">
        <div class="kpi-icon">✗</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['rejected']['count'] ?? 0) ?></div>
        <div class="kpi-label">رد شده</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">📅</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['total_days'] ?? 0) ?></div>
        <div class="kpi-label">کل روزهای مرخصی تأیید شده</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>🌴 مدیریت مرخصی‌ها
          <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?> درخواست</span>
        </h4>
        <button class="btn btn-primary btn-sm" onclick="openLeaveModal()">+ درخواست جدید</button>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="leave-search" placeholder="جستجو در نام پرسنل..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="leave-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
          <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>تأیید شده</option>
          <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>رد شده</option>
        </select>
        <select id="leave-type-filter" onchange="applyFilters()">
          <option value="">همه انواع</option>
          <?php foreach (Leave::$types as $t): ?>
            <option value="<?= Helpers::e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= Helpers::e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>پرسنل</th><th>کد</th><th>واحد</th><th>نوع</th><th>از</th><th>تا</th><th>روز</th><th>دلیل</th><th>وضعیت</th><th>تأییدکننده</th><th>عملیات</th></tr>
          </thead>
          <tbody>
            <?php if (empty($leaves)): ?>
              <tr><td colspan="11" style="text-align:center;padding:30px;color:var(--text-muted)">
                🌴 مرخصی‌ای ثبت نشده
              </td></tr>
            <?php else: ?>
              <?php foreach ($leaves as $l): ?>
                <tr>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$l['staff_id'] ?>" style="color:var(--primary);font-weight:600;text-decoration:none">
                      <?= Helpers::e($l['staff_name'] ?: '-') ?>
                    </a>
                  </td>
                  <td><?= Helpers::e($l['staff_code'] ?: '-') ?></td>
                  <td><?= Helpers::e($l['staff_unit'] ?: '-') ?></td>
                  <td><span class="badge badge-info"><?= Helpers::e($l['type']) ?></span></td>
                  <td><?= Helpers::e($l['from_date']) ?></td>
                  <td><?= Helpers::e($l['to_date']) ?></td>
                  <td><?= ($l['leave_mode'] ?? 'daily') === 'hourly' ? Helpers::toPersianDigits($l['hours']) . ' ساعت' : Helpers::toPersianDigits($l['days']) . ' روز' ?></td>
                  <td><?= Helpers::e(Helpers::truncate($l['reason'], 30)) ?></td>
                  <td>
                    <span class="badge <?= $l['status'] === 'approved' ? 'badge-success' : ($l['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                      <?= $l['status'] === 'approved' ? 'تأیید شده' : ($l['status'] === 'pending' ? 'در انتظار' : 'رد شده') ?>
                    </span>
                  </td>
                  <td><?= Helpers::e($l['approver_name'] ?: '-') ?></td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <?php if ($l['status'] === 'pending'): ?>
                        <button class="btn btn-success btn-xs" title="تأیید" onclick="approveLeave(<?= (int)$l['id'] ?>)">✓</button>
                        <button class="btn btn-danger btn-xs" title="رد" onclick="rejectLeave(<?= (int)$l['id'] ?>)">✗</button>
                      <?php endif; ?>
                      <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$l['staff_id'] ?>" class="btn btn-info btn-xs" title="پروفایل">👁</a>
                      <button class="btn btn-light btn-xs" title="حذف" onclick="deleteLeave(<?= (int)$l['id'] ?>)">🗑</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?>
          <a href="?page=leaves&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=leaves&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=leaves&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&type=<?= urlencode($typeFilter) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- مودال مرخصی -->
<div class="modal-overlay" id="modal-leave">
  <div class="modal-box">
    <div class="modal-header">
      <h4>🌴 درخواست مرخصی جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-leave')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">پرسنل <span style="color:red">*</span></label>
        <select class="form-control" id="leave-staff">
          <option value="">انتخاب کنید...</option>
          <?php
          $allStaff = Database::getInstance()->fetchAll('SELECT id, code, name, unit FROM staff ORDER BY name');
          foreach ($allStaff as $s):
          ?>
            <option value="<?= (int)$s['id'] ?>"><?= Helpers::e($s['code'] . ' - ' . $s['name'] . ' (' . $s['unit'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">نوع مرخصی</label>
        <select class="form-control" id="leave-type">
          <?php foreach (Leave::$types as $t): ?>
            <option value="<?= Helpers::e($t) ?>"><?= Helpers::e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">از تاریخ (شمسی)</label>
          <input type="text" class="form-control" id="leave-from" placeholder="1403/03/01" class="jalali-date-input">
        </div>
        <div class="form-group">
          <label class="form-label">تا تاریخ (شمسی)</label>
          <input type="text" class="form-control" id="leave-to" placeholder="1403/03/05" class="jalali-date-input">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">دلیل</label>
        <textarea class="form-control" id="leave-reason" rows="2" placeholder="دلیل مرخصی..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-leave')">انصراف</button>
      <button class="btn btn-primary" onclick="submitLeave()">✓ ثبت درخواست</button>
    </div>
  </div>
</div>

<script>
let searchTimer = null;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 500);
}
function applyFilters() {
    const params = new URLSearchParams({page: 'leaves'});
    const s = document.getElementById('leave-search').value.trim();
    const st = document.getElementById('leave-status-filter').value;
    const tp = document.getElementById('leave-type-filter').value;
    if (s) params.set('search', s);
    if (st) params.set('status', st);
    if (tp) params.set('type', tp);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}
function openLeaveModal() {
    ['leave-staff','leave-from','leave-to','leave-reason'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('leave-type').value = 'استحقاقی';
    document.getElementById('leave-staff').value = '';
    openModal('modal-leave');
}
async function submitLeave() {
    const data = {
        staff_id: parseInt(document.getElementById('leave-staff').value),
        type: document.getElementById('leave-type').value,
        from_date: document.getElementById('leave-from').value.trim(),
        to_date: document.getElementById('leave-to').value.trim(),
        reason: document.getElementById('leave-reason').value.trim(),
    };
    if (!data.staff_id || !data.from_date || !data.to_date) return showToast('پرسنل و تاریخ‌ها الزامی است', 'warning');
    try {
        const result = await fetchAPI('leaves', { method: 'POST', body: data });
        if (result.success) {
            showToast('درخواست مرخصی ثبت شد', 'success');
            closeModal('modal-leave');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function approveLeave(id) {
    if (!confirm('تأیید درخواست مرخصی؟')) return;
    try {
        const result = await fetchAPI(`leaves/${id}/approve`, { method: 'PATCH' });
        if (result.success) {
            showToast('مرخصی تأیید شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function rejectLeave(id) {
    if (!confirm('رد درخواست مرخصی؟')) return;
    try {
        const result = await fetchAPI(`leaves/${id}/reject`, { method: 'PATCH' });
        if (result.success) {
            showToast('مرخصی رد شد', 'warning');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function deleteLeave(id) {
    if (!confirmDelete('حذف درخواست مرخصی؟')) return;
    try {
        const result = await fetchAPI('leaves/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('مرخصی حذف شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
