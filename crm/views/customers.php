<?php
/**
 * جاکجاست - صفحه مشتریان
 * Jakojast - Customers Page
 *
 * نمایش لیست مشتریان با فیلتر، جستجو، صفحه‌بندی،
 * دکمه افزودن، خروجی، و عملیات روی هر ردیف.
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

// دریافت پارامترهای فیلتر از URL
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$levelFilter = $_GET['level'] ?? '';
$sort = $_GET['sort'] ?? 'date';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

// بارگذاری اولیه از سرور
try {
    $result = Customer::all([
        'search' => $search, 'status' => $statusFilter,
        'level' => $levelFilter, 'sort' => $sort,
        'page' => $page, 'limit' => 15,
    ]);
    $customers = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 15));
} catch (Exception $e) {
    $customers = []; $total = 0; $totalPages = 1;
    Logger::error('Customers page load failed: ' . $e->getMessage());
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">

  <div id="page-customers" class="page active">
    <div class="card">
      <div class="card-header">
        <h4>👥 مدیریت مشتریان
            <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?> مشتری</span>
        </h4>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="openModal('modal-add-customer')">+ مشتری جدید</button>
          <button class="btn btn-light btn-sm" onclick="exportCustomers()">⬇ خروجی</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="cust-search" placeholder="جستجو در نام، موبایل، ایمیل..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="cust-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>فعال</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
          <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>مسدود</option>
        </select>
        <select id="cust-level-filter" onchange="applyFilters()">
          <option value="">همه سطوح</option>
          <?php foreach (Customer::$levels as $l): ?>
            <option value="<?= Helpers::e($l) ?>" <?= $levelFilter === $l ? 'selected' : '' ?>><?= Helpers::e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="cust-sort" onchange="applyFilters()">
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>جدیدترین</option>
          <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>نام (الفبا)</option>
          <option value="reservations" <?= $sort === 'reservations' ? 'selected' : '' ?>>بیشترین رزرو</option>
          <option value="spend" <?= $sort === 'spend' ? 'selected' : '' ?>>بیشترین خرید</option>
          <option value="score" <?= $sort === 'score' ? 'selected' : '' ?>>بیشترین امتیاز</option>
        </select>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th><th>آواتار</th><th>نام</th><th>موبایل</th><th>شهر</th>
              <th>سطح</th><th>رزرو</th><th>مجموع خرید</th><th>میانگین</th><th>وفاداری</th><th>روز محبوب</th><th>آخرین خرید</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody id="customers-tbody">
            <?php if (empty($customers)): ?>
              <tr><td colspan="14" style="text-align:center;padding:40px;color:var(--text-muted)">
                📭 هیچ مشتری‌ای یافت نشد. روی «+ مشتری جدید» کلیک کنید.
              </td></tr>
            <?php else: ?>
              <?php $start = ($page - 1) * 15; foreach ($customers as $i => $c): ?>
                <tr>
                  <td><?= $start + $i + 1 ?></td>
                  <td>
                    <div class="avatar" style="background:<?= Helpers::avatarColor($c['name']) ?>;width:32px;height:32px;font-size:13px">
                      <?= Helpers::initials($c['name']) ?>
                    </div>
                  </td>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?page=customer_profile&id=<?= (int)$c['id'] ?>" style="color:var(--primary);font-weight:600;text-decoration:none">
                      <?= Helpers::e($c['name']) ?>
                    </a>
                  </td>
                  <td dir="ltr"><?= Helpers::e($c['mobile'] ?: '-') ?></td>
                  <td><?= Helpers::e($c['city'] ?: '-') ?></td>
                  <td><span class="badge <?= Helpers::e($c['level'] === 'VIP' ? 'badge-gold' : 'badge-secondary') ?>"><?= Helpers::e($c['level']) ?></span></td>
                  <td><?= Helpers::toPersianDigits($c['reservations']) ?></td>
                  <td><?= Helpers::toPersianDigits(Helpers::formatNumber($c['total_spend'])) ?></td>
                  <td title="میانگین خرید"><?= Helpers::toPersianDigits(Helpers::formatNumber($c['avg_purchase'] ?? 0)) ?></td>
                  <td title="وفاداری"><?= Helpers::toPersianDigits(($c['loyalty_percent'] ?? 0)) ?>٪</td>
                  <td><?= Helpers::e($c['favorite_day'] ?: '-') ?></td>
                  <td><?= Helpers::e($c['last_purchase'] ?: '-') ?></td>
                  <td><span class="badge <?= getBadgeClass($c['status']) ?>"><?= getStatusLabelFa($c['status']) ?></span></td>
                  <td>
                    <div style="display:flex;gap:4px;flex-wrap:wrap">
                      <a href="<?= BASE_URL ?>/index.php?page=customer_profile&id=<?= (int)$c['id'] ?>" class="btn btn-info btn-xs">👁</a>
                      <button class="btn btn-light btn-xs" onclick="editCustomer(<?= (int)$c['id'] ?>)">✏️</button>
                      <button class="btn btn-danger btn-xs" onclick="deleteCustomer(<?= (int)$c['id'] ?>, '<?= Helpers::e($c['name']) ?>')">🗑</button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- صفحه‌بندی -->
      <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?page=customers&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $start + 4);
        if ($start > 1) echo '<span class="page-dots">...</span>';
        for ($p = $start; $p <= $end; $p++): ?>
          <a href="?page=customers&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $p ?>"
             class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor;
        if ($end < $totalPages) echo '<span class="page-dots">...</span>';
        ?>

        <?php if ($page < $totalPages): ?>
          <a href="?page=customers&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- ===========================
     مودال افزودن/ویرایش مشتری
     =========================== -->
<div class="modal-overlay" id="modal-add-customer">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="customer-modal-title">👤 افزودن مشتری جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-customer')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="cust-id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">نام <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="cust-name" placeholder="نام و نام خانوادگی">
        </div>
        <div class="form-group">
          <label class="form-label">موبایل <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="cust-mobile" placeholder="09XXXXXXXXX" dir="ltr">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">ایمیل</label>
          <input type="email" class="form-control" id="cust-email" placeholder="example@email.com" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">شهر</label>
          <input type="text" class="form-control" id="cust-city" placeholder="تهران">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">تلفن ثابت</label>
          <input type="text" class="form-control" id="cust-phone" placeholder="02112345678" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">تاریخ تولد (شمسی)</label>
          <input type="text" class="form-control" id="cust-birthday" placeholder="1370/01/01" class="form-control jalali-date-input">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">سطح</label>
          <select class="form-control" id="cust-level">
            <?php foreach (Customer::$levels as $l): ?>
              <option value="<?= Helpers::e($l) ?>"><?= Helpers::e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select class="form-control" id="cust-status">
            <option value="active">فعال</option>
            <option value="inactive">غیرفعال</option>
            <option value="blocked">مسدود</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">امتیاز</label>
          <input type="number" class="form-control" id="cust-score" value="0" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">تعداد رزرو</label>
          <input type="number" class="form-control" id="cust-reservations" value="0" min="0">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">آدرس</label>
        <input type="text" class="form-control" id="cust-address" placeholder="آدرس کامل">
      </div>
      <div class="form-group">
        <label class="form-label">توضیحات</label>
        <textarea class="form-control" id="cust-notes" rows="3" placeholder="یادداشت داخلی..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-customer')">انصراف</button>
      <button class="btn btn-primary" onclick="saveCustomer()">✓ ذخیره</button>
    </div>
  </div>
</div>

<?php
// تابع کمکی برای کلاس badge وضعیت
function getBadgeClass($status) {
    $map = ['active' => 'badge-success', 'inactive' => 'badge-secondary', 'blocked' => 'badge-danger', 'pending' => 'badge-warning'];
    return $map[$status] ?? 'badge-secondary';
}
function getStatusLabelFa($status) {
    $map = ['active' => 'فعال', 'inactive' => 'غیرفعال', 'blocked' => 'مسدود', 'pending' => 'در انتظار'];
    return $map[$status] ?? $status;
}
?>

<script>
// ===========================
// توابع صفحه مشتریان
// ===========================

let searchTimer = null;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 500);
}

function applyFilters() {
    const params = new URLSearchParams();
    params.set('page', 'customers');
    const search = document.getElementById('cust-search').value.trim();
    const status = document.getElementById('cust-status-filter').value;
    const level = document.getElementById('cust-level-filter').value;
    const sort = document.getElementById('cust-sort').value;
    if (search) params.set('search', search);
    if (status) params.set('status', status);
    if (level) params.set('level', level);
    if (sort) params.set('sort', sort);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

// افزودن مشتری جدید
function addCustomer() {
    document.getElementById('customer-modal-title').textContent = '👤 افزودن مشتری جدید';
    document.getElementById('cust-id').value = '';
    document.getElementById('cust-name').value = '';
    document.getElementById('cust-mobile').value = '';
    document.getElementById('cust-email').value = '';
    document.getElementById('cust-city').value = '';
    document.getElementById('cust-phone').value = '';
    document.getElementById('cust-birthday').value = '';
    document.getElementById('cust-level').value = 'عادی';
    document.getElementById('cust-status').value = 'active';
    document.getElementById('cust-score').value = '0';
    document.getElementById('cust-reservations').value = '0';
    document.getElementById('cust-address').value = '';
    document.getElementById('cust-notes').value = '';
    openModal('modal-add-customer');
}

// ویرایش مشتری
async function editCustomer(id) {
    try {
        const result = await fetchAPI('customers/' + id);
        if (!result.success) {
            showToast(result.message, 'error');
            return;
        }
        const c = result.data.customer;
        document.getElementById('customer-modal-title').textContent = '✏️ ویرایش مشتری: ' + c.name;
        document.getElementById('cust-id').value = c.id;
        document.getElementById('cust-name').value = c.name || '';
        document.getElementById('cust-mobile').value = c.mobile || '';
        document.getElementById('cust-email').value = c.email || '';
        document.getElementById('cust-city').value = c.city || '';
        document.getElementById('cust-phone').value = c.phone || '';
        document.getElementById('cust-birthday').value = c.birthday || '';
        document.getElementById('cust-level').value = c.level || 'عادی';
        document.getElementById('cust-status').value = c.status || 'active';
        document.getElementById('cust-score').value = c.score || 0;
        document.getElementById('cust-reservations').value = c.reservations || 0;
        document.getElementById('cust-address').value = c.address || '';
        document.getElementById('cust-notes').value = c.notes || '';
        openModal('modal-add-customer');
    } catch (err) {
        showToast('خطا در دریافت اطلاعات', 'error');
    }
}

// ذخیره مشتری (افزودن یا ویرایش)
async function saveCustomer() {
    const id = document.getElementById('cust-id').value;
    const data = {
        name: document.getElementById('cust-name').value.trim(),
        mobile: document.getElementById('cust-mobile').value.trim(),
        email: document.getElementById('cust-email').value.trim(),
        city: document.getElementById('cust-city').value.trim(),
        phone: document.getElementById('cust-phone').value.trim(),
        birthday: document.getElementById('cust-birthday').value.trim(),
        level: document.getElementById('cust-level').value,
        status: document.getElementById('cust-status').value,
        score: parseInt(document.getElementById('cust-score').value) || 0,
        reservations: parseInt(document.getElementById('cust-reservations').value) || 0,
        address: document.getElementById('cust-address').value.trim(),
        notes: document.getElementById('cust-notes').value.trim(),
    };

    if (!data.name) {
        showToast('نام مشتری الزامی است', 'warning');
        return;
    }

    try {
        let result;
        if (id) {
            result = await fetchAPI('customers/' + id, { method: 'PUT', body: data });
        } else {
            result = await fetchAPI('customers', { method: 'POST', body: data });
        }

        if (result.success) {
            showToast(id ? 'مشتری به‌روزرسانی شد' : 'مشتری جدید ایجاد شد', 'success');
            closeModal('modal-add-customer');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(result.message || 'خطا در ذخیره', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// حذف مشتری
async function deleteCustomer(id, name) {
    if (!confirmDelete('آیا از حذف مشتری «' + name + '» مطمئن هستید؟')) return;
    try {
        const result = await fetchAPI('customers/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('مشتری مسدود شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(result.message, 'error');
        }
    } catch (err) {
        showToast('خطا در حذف', 'error');
    }
}

// خروجی CSV
async function exportCustomers() {
    showToast('در حال آماده‌سازی خروجی...', 'info');
    const search = document.getElementById('cust-search').value;
    const status = document.getElementById('cust-status-filter').value;
    const level = document.getElementById('cust-level-filter').value;

    // دریافت کل داده‌ها بدون صفحه‌بندی
    try {
        const result = await fetchAPI(`customers?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&level=${encodeURIComponent(level)}&limit=1000`);
        if (!result.success || !result.data) {
            showToast('خطا در دریافت داده', 'error');
            return;
        }
        const rows = result.data;
        // ساخت CSV
        const headers = ['ردیف', 'نام', 'موبایل', 'ایمیل', 'شهر', 'سطح', 'رزرو', 'مجموع خرید', 'وضعیت', 'تاریخ عضویت'];
        const csvRows = [headers.join(',')];
        rows.forEach((c, i) => {
            csvRows.push([
                i + 1, '"' + (c.name || '') + '"', c.mobile || '', c.email || '',
                '"' + (c.city || '') + '"', c.level || '', c.reservations || 0,
                c.total_spend || 0, c.status || '', c.join_date || ''
            ].join(','));
        });
        const csv = '\uFEFF' + csvRows.join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `customers-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) {
        showToast('خطا در خروجی', 'error');
    }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
