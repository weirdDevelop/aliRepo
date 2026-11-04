<?php
/**
 * جاکجاست - صفحه حقوق و دستمزد
 * Jakojast - Payroll Page
 *
 * نمایش فیش‌های حقوقی + تولید فیش برای ماه + پرداخت گروهی + فیش تک‌تک
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$monthFilter = $_GET['month'] ?? '';
$paidFilter = $_GET['paid'] ?? '';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Payroll::all([
        'search' => $search, 'month' => $monthFilter,
        'paid' => $paidFilter,
        'page' => $page, 'limit' => 20,
    ]);
    $salaries = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 20));
    $months = Payroll::getMonths();
    $stats = Payroll::getStats($monthFilter ?: null);
} catch (Exception $e) {
    $salaries = []; $total = 0; $totalPages = 1; $months = []; $stats = [];
}

// محاسبه مجموع خالص صفحه فعلی
$pageTotal = 0;
foreach ($salaries as $s) $pageTotal += $s['net'];
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-payroll" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card red">
        <div class="kpi-icon">💰</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits(Helpers::formatNumber(($stats['total_net'] ?? 0) / 1000000)) ?>M</div>
        <div class="kpi-label">مجموع حقوق (تومان)</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">✓</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['paid_count'] ?? 0) ?></div>
        <div class="kpi-label">پرداخت شده</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">⏳</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['unpaid_count'] ?? 0) ?></div>
        <div class="kpi-label">در انتظار پرداخت</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">📊</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['total_records'] ?? 0) ?></div>
        <div class="kpi-label">کل فیش‌ها</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>💰 حقوق و دستمزد
          <?php if ($monthFilter): ?>
            <span class="badge badge-primary" style="font-size:12px"><?= Helpers::e($monthFilter) ?></span>
          <?php endif; ?>
        </h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary btn-sm" onclick="openGenerateModal()">⚙️ تولید فیش ماه</button>
          <?php if ($monthFilter): ?>
            <button class="btn btn-success btn-sm" onclick="payAllMonth('<?= Helpers::e($monthFilter) ?>')">💳 پرداخت همه</button>
          <?php endif; ?>
          <button class="btn btn-light btn-sm" onclick="exportPayroll()">⬇ خروجی</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="payroll-search" placeholder="جستجو در نام پرسنل، کد..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="payroll-month-filter" onchange="applyFilters()">
          <option value="">همه ماه‌ها</option>
          <?php foreach ($months as $m): ?>
            <option value="<?= Helpers::e($m) ?>" <?= $monthFilter === $m ? 'selected' : '' ?>><?= Helpers::e($m) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="payroll-paid-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="1" <?= $paidFilter === '1' ? 'selected' : '' ?>>پرداخت شده</option>
          <option value="0" <?= $paidFilter === '0' ? 'selected' : '' ?>>در انتظار</option>
        </select>
      </div>

      <?php if ($pageTotal > 0): ?>
      <div style="padding:12px 16px;background:var(--bg-secondary);border-bottom:1px solid var(--border);font-size:13px">
        <strong>مجموع این صفحه:</strong>
        <span class="text-success fw-700"><?= Helpers::toPersianDigits(Helpers::formatNumber($pageTotal)) ?> تومان</span>
      </div>
      <?php endif; ?>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>آواتار</th><th>کد</th><th>نام</th><th>واحد</th>
              <th>پایه</th><th>مزایا</th><th>کسورات</th><th>خالص</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($salaries)): ?>
              <tr><td colspan="10" style="text-align:center;padding:30px;color:var(--text-muted)">
                💰 فیش حقوقی یافت نشد. روی «تولید فیش ماه» کلیک کنید.
              </td></tr>
            <?php else: ?>
              <?php foreach ($salaries as $s):
                $benefits = $s['housing'] + $s['bonus_food'] + $s['grocery'] + $s['performance'] + $s['overtime'];
                $deductions = $s['insurance'] + $s['tax'] + $s['loan'];
              ?>
                <tr>
                  <td>
                    <div class="avatar" style="width:32px;height:32px;font-size:13px;background:<?= Helpers::avatarColor($s['staff_name']) ?>">
                      <?= Helpers::initials($s['staff_name']) ?>
                    </div>
                  </td>
                  <td><?= Helpers::e($s['staff_code'] ?: '-') ?></td>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$s['staff_id'] ?>" style="color:var(--primary);font-weight:600;text-decoration:none">
                      <?= Helpers::e($s['staff_name']) ?>
                    </a>
                  </td>
                  <td><?= Helpers::e($s['staff_unit'] ?: '-') ?></td>
                  <td><?= Helpers::toPersianDigits(Helpers::formatNumber($s['base'])) ?></td>
                  <td class="text-success">+<?= Helpers::toPersianDigits(Helpers::formatNumber($benefits)) ?></td>
                  <td class="text-danger">-<?= Helpers::toPersianDigits(Helpers::formatNumber($deductions)) ?></td>
                  <td><strong class="text-primary"><?= Helpers::toPersianDigits(Helpers::formatNumber($s['net'])) ?> ت</strong></td>
                  <td>
                    <span class="badge <?= $s['paid'] ? 'badge-success' : 'badge-warning' ?>">
                      <?= $s['paid'] ? '✓ پرداخت شد' : '⏳ در انتظار' ?>
                    </span>
                  </td>
                  <td>
                    <div style="display:flex;gap:4px">
                      <button class="btn btn-info btn-xs" title="فیش" onclick="showPayslip(<?= (int)$s['id'] ?>)">🖨</button>
                      <?php if (!$s['paid']): ?>
                        <button class="btn btn-success btn-xs" title="پرداخت" onclick="paySalary(<?= (int)$s['id'] ?>)">💳</button>
                      <?php endif; ?>
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
          <a href="?page=payroll&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>&paid=<?= urlencode($paidFilter) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=payroll&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>&paid=<?= urlencode($paidFilter) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=payroll&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>&paid=<?= urlencode($paidFilter) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- مودال تولید فیش -->
<div class="modal-overlay" id="modal-generate">
  <div class="modal-box modal-sm">
    <div class="modal-header">
      <h4>⚙️ تولید فیش حقوقی ماه</h4>
      <button class="btn-close" onclick="closeModal('modal-generate')">×</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="margin-bottom:16px;font-size:12px">
        ℹ️ این عملیات برای تمام پرسنل فعال، یک فیش حقوقی با محاسبات پیش‌فرض ایجاد می‌کند.
        <br>اگر برای این ماه قبلاً فیش ایجاد شده، خطا می‌دهد.
      </div>
      <div class="form-group">
        <label class="form-label">ماه <span style="color:red">*</span></label>
        <input type="text" class="form-control" id="generate-month" placeholder="مثلاً: اردیبهشت ۱۴۰۳" value="<?= Jalali::fromGregorian(date('Y-m-d'), 'Y/m') ?>">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-generate')">انصراف</button>
      <button class="btn btn-primary" onclick="generatePayroll()">⚙️ تولید</button>
    </div>
  </div>
</div>

<!-- مودال فیش حقوقی -->
<div class="modal-overlay" id="modal-payslip">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4>🖨 فیش حقوقی</h4>
      <button class="btn-close" onclick="closeModal('modal-payslip')">×</button>
    </div>
    <div class="modal-body" id="payslip-content"></div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-payslip')">بستن</button>
      <button class="btn btn-primary" onclick="window.print()">🖨 چاپ</button>
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
    const params = new URLSearchParams({page: 'payroll'});
    const s = document.getElementById('payroll-search').value.trim();
    const m = document.getElementById('payroll-month-filter').value;
    const p = document.getElementById('payroll-paid-filter').value;
    if (s) params.set('search', s);
    if (m) params.set('month', m);
    if (p) params.set('paid', p);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}
function openGenerateModal() {
    openModal('modal-generate');
}
async function generatePayroll() {
    const month = document.getElementById('generate-month').value.trim();
    if (!month) return showToast('ماه الزامی است', 'warning');
    try {
        const result = await fetchAPI('payroll/generate', { method: 'POST', body: { month } });
        if (result.success) {
            showToast(result.message, 'success');
            closeModal('modal-generate');
            setTimeout(() => window.location.href = `<?= BASE_URL ?>/index.php?page=payroll&month=${encodeURIComponent(month)}`, 1200);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function payAllMonth(month) {
    if (!confirm('پرداخت تمام فیش‌های ماه ' + month + '؟')) return;
    try {
        const result = await fetchAPI('payroll/pay-all', { method: 'POST', body: { month } });
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}
async function paySalary(id) {
    if (!confirm('پرداخت این فیش؟')) return;
    try {
        const result = await fetchAPI(`payroll/${id}/pay`, { method: 'PATCH' });
        if (result.success) {
            showToast('فیش پرداخت شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}
async function showPayslip(id) {
    try {
        const result = await fetchAPI('payroll/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const p = result.data;
        const benefits = p.housing + p.bonus_food + p.grocery + p.performance + p.overtime;
        const deductions = p.insurance + p.tax + p.loan;
        document.getElementById('payslip-content').innerHTML = `
            <div style="padding:20px">
                <div style="text-align:center;margin-bottom:20px">
                    <h2 style="color:var(--primary)">فیش حقوقی</h2>
                    <div style="color:var(--text-muted);font-size:13px">ماه: ${p.month}</div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;font-size:13px">
                    <div><strong>نام:</strong> ${p.staff_name}</div>
                    <div><strong>کد پرسنلی:</strong> ${p.staff_code}</div>
                    <div><strong>واحد:</strong> ${p.staff_unit || '-'}</div>
                    <div><strong>سمت:</strong> ${p.staff_role || '-'}</div>
                </div>
                <table class="data-table" style="margin-bottom:16px">
                    <thead><tr><th>شرح</th><th>مبلغ (تومان)</th></tr></thead>
                    <tbody>
                        <tr><td>حقوق پایه</td><td>${toPersianDigits(formatNumber(p.base))}</td></tr>
                        <tr><td>حق مسکن</td><td>${toPersianDigits(formatNumber(p.housing))}</td></tr>
                        <tr><td>بن کارانه (غذا)</td><td>${toPersianDigits(formatNumber(p.bonus_food))}</td></tr>
                        <tr><td>بن خواربار</td><td>${toPersianDigits(formatNumber(p.grocery))}</td></tr>
                        <tr><td>پاداش عملکرد</td><td>${toPersianDigits(formatNumber(p.performance))}</td></tr>
                        <tr><td>اضافه کاری</td><td>${toPersianDigits(formatNumber(p.overtime))}</td></tr>
                        <tr style="background:#e6f7ed"><td><strong>مجموع مزایا</strong></td><td><strong class="text-success">${toPersianDigits(formatNumber(p.base + benefits))}</strong></td></tr>
                        <tr><td>بیمه</td><td class="text-danger">-${toPersianDigits(formatNumber(p.insurance))}</td></tr>
                        <tr><td>مالیات</td><td class="text-danger">-${toPersianDigits(formatNumber(p.tax))}</td></tr>
                        <tr><td>وام / مساعده</td><td class="text-danger">-${toPersianDigits(formatNumber(p.loan))}</td></tr>
                        <tr style="background:#ffeaea"><td><strong>مجموع کسورات</strong></td><td><strong class="text-danger">-${toPersianDigits(formatNumber(deductions))}</strong></td></tr>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--primary);color:#fff">
                            <td><strong>خالص پرداختی</strong></td>
                            <td><strong style="font-size:18px">${toPersianDigits(formatNumber(p.net))} تومان</strong></td>
                        </tr>
                    </tfoot>
                </table>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted)">
                    <div>وضعیت: ${p.paid ? '✓ پرداخت شده' : '⏳ در انتظار پرداخت'}</div>
                    <div>تاریخ پرداخت: ${p.paid_date || '-'}</div>
                </div>
            </div>
        `;
        openModal('modal-payslip');
    } catch (err) { showToast('خطا', 'error'); }
}
async function exportPayroll() {
    try {
        const m = document.getElementById('payroll-month-filter').value;
        const url = m ? `payroll?month=${encodeURIComponent(m)}&limit=1000` : 'payroll?limit=1000';
        const result = await fetchAPI(url);
        if (!result.success) return;
        const rows = result.data;
        const csv = ['ردیف,کد,نام,واحد,ماه,پایه,مزایا,کسورات,خالص,وضعیت'];
        rows.forEach((s, i) => {
            const benefits = s.housing + s.bonus_food + s.grocery + s.performance + s.overtime;
            const deductions = s.insurance + s.tax + s.loan;
            csv.push([i+1, s.staff_code, '"'+s.staff_name+'"', s.staff_unit||'', s.month,
                      s.base, benefits, deductions, s.net, s.paid ? 'پرداخت شده' : 'در انتظار'].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `payroll-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
