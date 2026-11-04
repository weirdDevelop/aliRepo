<?php
/**
 * جاکجاست - صفحه پرسنل
 * Jakojast - Staff Page
 *
 * شامل: نمودار سازمانی + لیست پرسنل با فیلتر
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$unitFilter = $_GET['unit'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$sortBy = $_GET['sort'] ?? 'code';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Staff::all([
        'search' => $search, 'unit' => $unitFilter, 'status' => $statusFilter,
        'sort' => $sortBy, 'page' => $page, 'limit' => 15,
    ]);
    $staffList = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 15));
    $orgChart = Staff::getOrgChart();
    $stats = Staff::getStats();
} catch (Exception $e) {
    $staffList = []; $total = 0; $totalPages = 1; $orgChart = []; $stats = [];
    Logger::error('Staff page load failed: ' . $e->getMessage());
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-staff" class="page active">

    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card red">
        <div class="kpi-icon">💼</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div>
        <div class="kpi-label">کل پرسنل</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">✓</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['active'] ?? 0) ?></div>
        <div class="kpi-label">فعال</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">🌴</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['leave'] ?? 0) ?></div>
        <div class="kpi-label">در مرخصی</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">💰</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits(Helpers::formatNumber(($stats['total_salary'] ?? 0) / 1000000)) ?>M</div>
        <div class="kpi-label">مجموع حقوق پایه (تومان)</div>
      </div>
    </div>

    <!-- اپراتورهای آنلاین (وضعیت حضور لحظه‌ای) -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h4>🟢 اپراتورهای آنلاین</h4>
        <span style="font-size:11px;color:var(--text-muted)">هر ۱۵ ثانیه خودکار به‌روزرسانی می‌شود</span>
      </div>
      <div id="online-operators-box" style="padding:14px">
        <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
      </div>
    </div>

    <!-- نمودار سازمانی -->
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <h4>🏢 نمودار سازمانی</h4>
        <button class="btn btn-light btn-sm" onclick="toggleOrgChart()">نمایش/مخفی</button>
      </div>
      <div id="org-chart-full" style="padding:20px;overflow-x:auto;max-height:500px;overflow-y:auto">
        <?php if (empty($orgChart)): ?>
          <div style="text-align:center;padding:30px;color:var(--text-muted)">🏢 پرسنلی ثبت نشده است</div>
        <?php else: ?>
          <?php
          function renderStaffNode($node, $depth = 0) {
              $indent = $depth * 24;
              $color = Helpers::avatarColor($node['name']);
              $initials = Helpers::initials($node['name']);
              $statusColor = $node['status'] === 'active' ? '#28a745' : ($node['status'] === 'leave' ? '#ffc107' : '#dc3545');
              $statusText = $node['status'] === 'active' ? 'فعال' : ($node['status'] === 'leave' ? 'مرخصی' : 'غایب');
              echo '<div style="margin-bottom:8px">';
              echo '<a href="' . BASE_URL . '/index.php?page=staff_profile&id=' . (int)$node['id'] . '" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;background:' . ($depth === 0 ? 'rgba(229,2,65,0.1)' : 'var(--bg-secondary)') . ';text-decoration:none;color:inherit;border-right:3px solid ' . $color . ';margin-right:' . $indent . 'px;transition:transform 0.2s" onmouseover="this.style.transform=\'translateX(-4px)\'" onmouseout="this.style.transform=\'none\'">';
              echo '<span style="width:32px;height:32px;border-radius:50%;background:' . $color . ';color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">' . $initials . '</span>';
              echo '<div style="flex:1">';
              echo '<div style="font-weight:600;font-size:14px">' . Helpers::e($node['name']) . ' <span style="color:var(--text-muted);font-size:11px">(' . Helpers::e($node['code']) . ')</span></div>';
              if (!empty($node['role'])) echo '<div style="font-size:12px;color:var(--text-muted)">' . Helpers::e($node['role']) . (!empty($node['unit']) ? ' - ' . Helpers::e($node['unit']) : '') . '</div>';
              echo '</div>';
              echo '<span style="font-size:11px;color:' . $statusColor . ';font-weight:600">' . $statusText . '</span>';
              if (!empty($node['exten_number'])) echo '<span style="font-size:11px;color:var(--text-muted)">داخلی: ' . Helpers::e($node['exten_number']) . '</span>';
              echo '</a>';
              if (!empty($node['children'])) {
                  echo '<div style="margin-top:4px">';
                  foreach ($node['children'] as $child) {
                      renderStaffNode($child, $depth + 1);
                  }
                  echo '</div>';
              }
              echo '</div>';
          }
          foreach ($orgChart as $root) {
              renderStaffNode($root);
          }
          ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- لیست پرسنل -->
    <div class="card">
      <div class="card-header">
        <h4>💼 لیست پرسنل
          <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?> نفر</span>
        </h4>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="openStaffModal()">+ پرسنل جدید</button>
          <button class="btn btn-light btn-sm" onclick="exportStaff()">⬇ خروجی</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="staff-search" placeholder="جستجو در نام، کد، موبایل..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="staff-unit-filter" onchange="applyFilters()">
          <option value="">همه واحدها</option>
          <?php foreach (Staff::$units as $u): ?>
            <option value="<?= Helpers::e($u) ?>" <?= $unitFilter === $u ? 'selected' : '' ?>><?= Helpers::e($u) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="staff-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>فعال</option>
          <option value="leave" <?= $statusFilter === 'leave' ? 'selected' : '' ?>>در مرخصی</option>
          <option value="absent" <?= $statusFilter === 'absent' ? 'selected' : '' ?>>غایب</option>
        </select>
        <select id="staff-sort" onchange="applyFilters()">
          <option value="code" <?= $sortBy === 'code' ? 'selected' : '' ?>>مرتب‌سازی: کد پرسنلی</option>
          <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>مرتب‌سازی: نام</option>
          <option value="unit" <?= $sortBy === 'unit' ? 'selected' : '' ?>>مرتب‌سازی: واحد</option>
          <option value="salary" <?= $sortBy === 'salary' ? 'selected' : '' ?>>مرتب‌سازی: حقوق (نزولی)</option>
          <option value="date" <?= $sortBy === 'date' ? 'selected' : '' ?>>مرتب‌سازی: تاریخ استخدام</option>
        </select>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>آواتار</th><th>کد</th><th>نام</th><th>سمت</th><th>واحد</th>
              <th>سرپرست</th><th>وضعیت</th><th>حقوق پایه</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($staffList)): ?>
              <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">
                💼 پرسنلی یافت نشد
              </td></tr>
            <?php else: ?>
              <?php foreach ($staffList as $s): ?>
                <tr>
                  <td>
                    <div class="avatar" style="width:32px;height:32px;font-size:13px;background:<?= Helpers::avatarColor($s['name']) ?>">
                      <?= Helpers::initials($s['name']) ?>
                    </div>
                  </td>
                  <td><?= Helpers::e($s['code']) ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$s['id'] ?>" style="color:var(--primary);font-weight:600;text-decoration:none">
                      <?= Helpers::e($s['name']) ?>
                    </a>
                  </td>
                  <td><?= Helpers::e($s['role'] ?: '-') ?></td>
                  <td><?= Helpers::e($s['unit'] ?: '-') ?></td>
                  <td><?= Helpers::e($s['supervisor_name'] ?: '-') ?></td>
                  <td>
                    <span class="badge <?= $s['status'] === 'active' ? 'badge-success' : ($s['status'] === 'leave' ? 'badge-warning' : 'badge-danger') ?>">
                      <?= $s['status'] === 'active' ? 'فعال' : ($s['status'] === 'leave' ? 'مرخصی' : 'غایب') ?>
                    </span>
                  </td>
                  <td><?= Helpers::toPersianDigits(Helpers::formatNumber($s['base_salary'])) ?> ت</td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$s['id'] ?>" class="btn btn-info btn-xs">👁</a>
                      <button class="btn btn-light btn-xs" onclick="editStaff(<?= (int)$s['id'] ?>)">✏️</button>
                      <button class="btn btn-danger btn-xs" onclick="deleteStaff(<?= (int)$s['id'] ?>, '<?= Helpers::e($s['name']) ?>')">🗑</button>
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
          <a href="?page=staff&search=<?= urlencode($search) ?>&unit=<?= urlencode($unitFilter) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=staff&search=<?= urlencode($search) ?>&unit=<?= urlencode($unitFilter) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=staff&search=<?= urlencode($search) ?>&unit=<?= urlencode($unitFilter) ?>&status=<?= urlencode($statusFilter) ?>&sort=<?= urlencode($sortBy) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- مودال پرسنل -->
<div class="modal-overlay" id="modal-add-staff">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="staff-modal-title">💼 پرسنل جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-staff')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="staff-id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">کد پرسنلی <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="staff-code" placeholder="P001">
        </div>
        <div class="form-group">
          <label class="form-label">نام <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="staff-name" placeholder="نام و نام خانوادگی">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">سمت</label>
          <input type="text" class="form-control" id="staff-role" placeholder="کارشناس فروش">
        </div>
        <div class="form-group">
          <label class="form-label">واحد</label>
          <select class="form-control" id="staff-unit">
            <?php foreach (Staff::$units as $u): ?>
              <option value="<?= Helpers::e($u) ?>"><?= Helpers::e($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">سرپرست</label>
          <select class="form-control" id="staff-supervisor">
            <option value="0">بدون سرپرست</option>
            <?php
            // دریافت همه پرسنل به جز خودش (در حالت ویرایش)
            $allStaff = Database::getInstance()->fetchAll('SELECT id, code, name, role FROM staff ORDER BY name');
            foreach ($allStaff as $s2):
            ?>
              <option value="<?= (int)$s2['id'] ?>"><?= Helpers::e($s2['code'] . ' - ' . $s2['name'] . ' (' . $s2['role'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select class="form-control" id="staff-status">
            <option value="active">فعال</option>
            <option value="leave">در مرخصی</option>
            <option value="absent">غایب</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">حقوق پایه (تومان)</label>
          <input type="number" class="form-control" id="staff-salary" placeholder="15000000">
        </div>
        <div class="form-group">
          <label class="form-label">تاریخ استخدام (شمسی)</label>
          <input type="text" class="form-control" id="staff-hire" placeholder="1402/01/15" class="jalali-date-input" value="<?= Jalali::today() ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">موبایل</label>
          <input type="text" class="form-control" id="staff-mobile" placeholder="09XXXXXXXXX" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">ایمیل</label>
          <input type="email" class="form-control" id="staff-email" placeholder="email@jakojast.local" dir="ltr">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">کد ملی</label>
          <input type="text" class="form-control" id="staff-national" placeholder="0000000000" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">شماره داخلی</label>
          <input type="text" class="form-control" id="staff-exten" placeholder="101" dir="ltr">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-staff')">انصراف</button>
      <button class="btn btn-primary" onclick="saveStaff()">✓ ذخیره</button>
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
    const params = new URLSearchParams({page: 'staff'});
    const s = document.getElementById('staff-search').value.trim();
    const u = document.getElementById('staff-unit-filter').value;
    const st = document.getElementById('staff-status-filter').value;
    const sort = document.getElementById('staff-sort').value;
    if (s) params.set('search', s);
    if (u) params.set('unit', u);
    if (st) params.set('status', st);
    if (sort) params.set('sort', sort);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}
function toggleOrgChart() {
    const el = document.getElementById('org-chart-full');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function openStaffModal() {
    document.getElementById('staff-modal-title').textContent = '💼 پرسنل جدید';
    document.getElementById('staff-id').value = '';
    ['staff-code','staff-name','staff-role','staff-salary','staff-mobile','staff-email','staff-national','staff-exten'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('staff-unit').value = 'فروش';
    document.getElementById('staff-supervisor').value = '0';
    document.getElementById('staff-status').value = 'active';
    document.getElementById('staff-hire').value = '<?= Jalali::today() ?>';
    openModal('modal-add-staff');
}
async function editStaff(id) {
    try {
        const result = await fetchAPI('staff/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const s = result.data;
        document.getElementById('staff-modal-title').textContent = '✏️ ویرایش پرسنل: ' + s.name;
        document.getElementById('staff-id').value = s.id;
        document.getElementById('staff-code').value = s.code || '';
        document.getElementById('staff-name').value = s.name || '';
        document.getElementById('staff-role').value = s.role || '';
        document.getElementById('staff-unit').value = s.unit || 'فروش';
        document.getElementById('staff-supervisor').value = s.supervisor_id || '0';
        document.getElementById('staff-status').value = s.status || 'active';
        document.getElementById('staff-salary').value = s.base_salary || '';
        document.getElementById('staff-hire').value = s.hire_date || '';
        document.getElementById('staff-mobile').value = s.mobile || '';
        document.getElementById('staff-email').value = s.email || '';
        document.getElementById('staff-national').value = s.national_code || '';
        document.getElementById('staff-exten').value = s.exten_number || '';
        openModal('modal-add-staff');
    } catch (err) { showToast('خطا', 'error'); }
}
async function saveStaff() {
    const id = document.getElementById('staff-id').value;
    const data = {
        code: document.getElementById('staff-code').value.trim(),
        name: document.getElementById('staff-name').value.trim(),
        role: document.getElementById('staff-role').value.trim(),
        unit: document.getElementById('staff-unit').value,
        supervisor_id: parseInt(document.getElementById('staff-supervisor').value) || null,
        status: document.getElementById('staff-status').value,
        base_salary: parseInt(document.getElementById('staff-salary').value) || 0,
        hire_date: document.getElementById('staff-hire').value.trim(),
        mobile: document.getElementById('staff-mobile').value.trim(),
        email: document.getElementById('staff-email').value.trim(),
        national_code: document.getElementById('staff-national').value.trim(),
        exten_number: document.getElementById('staff-exten').value.trim(),
    };
    if (!data.name || !data.code) return showToast('نام و کد پرسنلی الزامی است', 'warning');
    try {
        const result = id
            ? await fetchAPI('staff/' + id, { method: 'PUT', body: data })
            : await fetchAPI('staff', { method: 'POST', body: data });
        if (result.success) {
            showToast(id ? 'پرسنل به‌روزرسانی شد' : 'پرسنل جدید ایجاد شد', 'success');
            closeModal('modal-add-staff');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function deleteStaff(id, name) {
    if (!confirmDelete('حذف پرسنل «' + name + '»؟ زیرمجموعه‌های او بدون سرپرست می‌شوند.')) return;
    try {
        const result = await fetchAPI('staff/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('پرسنل حذف شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}
async function exportStaff() {
    try {
        const result = await fetchAPI('staff?limit=1000');
        if (!result.success) return;
        const rows = result.data;
        const csv = ['ردیف,کد,نام,سمت,واحد,سرپرست,وضعیت,حقوق پایه,موبایل,داخلی'];
        rows.forEach((s, i) => {
            csv.push([i+1, s.code, '"'+s.name+'"', '"'+(s.role||'')+'"', s.unit||'', '"'+(s.supervisor_name||'')+'"',
                      s.status, s.base_salary||0, s.mobile||'', s.exten_number||''].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `staff-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}

// اپراتورهای آنلاین - وضعیت حضور لحظه‌ای
async function loadOnlineOperators() {
    const box = document.getElementById('online-operators-box');
    if (!box) return;
    try {
        const result = await fetchAPI('users/online-status', { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت وضعیت آنلاین</p>';
            return;
        }
        const items = result.data;
        const onlineCount = items.filter(u => u.is_online).length;
        if (!items.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">هیچ کاربر فعالی یافت نشد.</p>';
            return;
        }
        box.innerHTML = `
          <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">🟢 ${onlineCount} نفر از ${items.length} نفر الان آنلاین هستند</p>
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            ${items.map(u => `
              <div style="display:flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid var(--border);border-radius:20px;background:${u.is_online ? 'rgba(34,197,94,0.08)' : 'transparent'}">
                <span style="width:9px;height:9px;border-radius:50%;background:${u.is_online ? '#22c55e' : '#9ca3af'};display:inline-block"></span>
                <span style="font-size:13px">${u.full_name}</span>
                ${u.exten_number ? `<span style="font-size:11px;color:var(--text-muted)" dir="ltr">(${u.exten_number})</span>` : ''}
                ${!u.is_online && u.last_activity_jalali ? `<span style="font-size:10px;color:var(--text-muted)">- آخرین حضور: ${u.last_activity_jalali}</span>` : ''}
              </div>`).join('')}
          </div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('online-operators-box')) {
        loadOnlineOperators();
        setInterval(loadOnlineOperators, 15000);
    }
});
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
