<?php
/**
 * جاکجاست - صفحه میزبانان
 * Jakojast - Hosts Page
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$levelFilter = $_GET['level'] ?? '';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Host::all([
        'search' => $search, 'status' => $statusFilter, 'level' => $levelFilter,
        'page' => $page, 'limit' => 12,
    ]);
    $hosts = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 12));
} catch (Exception $e) {
    $hosts = []; $total = 0; $totalPages = 1;
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-hosts" class="page active">
    <div class="card">
      <div class="card-header">
        <h4>🏠 مدیریت میزبانان
          <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?> میزبان</span>
        </h4>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="openHostModal()">+ میزبان جدید</button>
          <button class="btn btn-light btn-sm" onclick="exportHosts()">⬇ خروجی</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="host-search" placeholder="جستجو در نام، موبایل، کد ملی..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="host-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>فعال</option>
          <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار تأیید</option>
          <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>غیرفعال</option>
          <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>مسدود</option>
        </select>
        <select id="host-level-filter" onchange="applyFilters()">
          <option value="">همه سطوح</option>
          <?php foreach (Host::$levels as $l): ?>
            <option value="<?= Helpers::e($l) ?>" <?= $levelFilter === $l ? 'selected' : '' ?>><?= Helpers::e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="hosts-container">
        <?php if (empty($hosts)): ?>
          <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
            🏠 هیچ میزبانی یافت نشد. روی «+ میزبان جدید» کلیک کنید.
          </div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:8px">
            <?php foreach ($hosts as $h): ?>
              <div class="host-card" style="background:var(--bg-secondary);border-radius:12px;padding:16px;border:1px solid var(--border)">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                  <div class="avatar" style="width:48px;height:48px;font-size:18px;background:<?= Helpers::avatarColor($h['name']) ?>">
                    <?= Helpers::initials($h['name']) ?>
                  </div>
                  <div style="flex:1">
                    <a href="<?= BASE_URL ?>/index.php?page=host_profile&id=<?= (int)$h['id'] ?>" style="color:var(--text);font-weight:600;text-decoration:none">
                      <?= Helpers::e($h['name']) ?>
                    </a>
                    <div style="font-size:12px;color:var(--text-muted)" dir="ltr"><?= Helpers::e($h['mobile'] ?: '-') ?></div>
                  </div>
                </div>
                <div style="font-size:12px;line-height:1.8">
                  <div>📍 <strong>شهر:</strong> <?= Helpers::e($h['city'] ?: '-') ?></div>
                  <div>🏠 <strong>اقامتگاه‌ها:</strong> <?= Helpers::toPersianDigits($h['properties']) ?></div>
                  <div>💰 <strong>درآمد کل:</strong> <?= Helpers::toPersianDigits(Helpers::formatNumber($h['total_revenue'])) ?> ت</div>
                  <?php if (!empty($h['balance'])): ?>
                    <div style="color:var(--danger)">🔴 <strong>مانده:</strong> <?= Helpers::toPersianDigits(Helpers::formatNumber($h['balance'])) ?> ت</div>
                  <?php endif; ?>
                  <div>⭐ <strong>امتیاز:</strong> <?= Helpers::e($h['rating']) ?> از ۵</div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
                  <span class="badge <?= $h['status'] === 'active' ? 'badge-success' : ($h['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                    <?= $h['status'] === 'active' ? 'فعال' : ($h['status'] === 'pending' ? 'در انتظار' : 'غیرفعال') ?>
                  </span>
                  <div style="display:flex;gap:4px">
                    <a href="<?= BASE_URL ?>/index.php?page=host_profile&id=<?= (int)$h['id'] ?>" class="btn btn-info btn-xs">👁</a>
                    <button class="btn btn-light btn-xs" onclick="editHost(<?= (int)$h['id'] ?>)">✏️</button>
                    <?php if ($h['status'] === 'pending'): ?>
                      <button class="btn btn-success btn-xs" onclick="approveHost(<?= (int)$h['id'] ?>)">✓</button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?>
          <a href="?page=hosts&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=hosts&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&p=<?= $p ?>"
             class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=hosts&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&level=<?= urlencode($levelFilter) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- مودال میزبان جدید -->
<div class="modal-overlay" id="modal-add-host">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="host-modal-title">🏠 افزودن میزبان جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-host')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="host-id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">نام <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="host-name" placeholder="نام و نام خانوادگی">
        </div>
        <div class="form-group">
          <label class="form-label">موبایل <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="host-mobile" placeholder="09XXXXXXXXX" dir="ltr">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">کد ملی</label>
          <input type="text" class="form-control" id="host-national" placeholder="0000000000" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">شماره شبا</label>
          <input type="text" class="form-control" id="host-sheba" placeholder="IR..." dir="ltr">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">ایمیل</label>
          <input type="email" class="form-control" id="host-email" placeholder="example@email.com" dir="ltr">
        </div>
        <div class="form-group">
          <label class="form-label">شهر</label>
          <input type="text" class="form-control" id="host-city" placeholder="رامسر">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">سطح</label>
          <select class="form-control" id="host-level">
            <?php foreach (Host::$levels as $l): ?>
              <option value="<?= Helpers::e($l) ?>"><?= Helpers::e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select class="form-control" id="host-status">
            <option value="pending">در انتظار تأیید</option>
            <option value="active">فعال</option>
            <option value="inactive">غیرفعال</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">تعداد اقامتگاه‌ها</label>
          <input type="number" class="form-control" id="host-properties" value="0" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">امتیاز (۰ تا ۵)</label>
          <input type="number" class="form-control" id="host-rating" value="0" min="0" max="5" step="0.1">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">یادداشت</label>
        <textarea class="form-control" id="host-notes" rows="2" placeholder="توضیحات..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-host')">انصراف</button>
      <button class="btn btn-primary" onclick="saveHost()">✓ ذخیره</button>
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
    const params = new URLSearchParams({page: 'hosts'});
    const s = document.getElementById('host-search').value.trim();
    const st = document.getElementById('host-status-filter').value;
    const lv = document.getElementById('host-level-filter').value;
    if (s) params.set('search', s);
    if (st) params.set('status', st);
    if (lv) params.set('level', lv);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

function openHostModal() {
    document.getElementById('host-modal-title').textContent = '🏠 افزودن میزبان جدید';
    document.getElementById('host-id').value = '';
    ['host-name','host-mobile','host-national','host-sheba','host-email','host-city','host-notes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('host-level').value = 'برنز';
    document.getElementById('host-status').value = 'pending';
    document.getElementById('host-properties').value = '0';
    document.getElementById('host-rating').value = '0';
    openModal('modal-add-host');
}

async function editHost(id) {
    try {
        const result = await fetchAPI('hosts/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const h = result.data.host;
        document.getElementById('host-modal-title').textContent = '✏️ ویرایش میزبان: ' + h.name;
        document.getElementById('host-id').value = h.id;
        document.getElementById('host-name').value = h.name || '';
        document.getElementById('host-mobile').value = h.mobile || '';
        document.getElementById('host-national').value = h.national_code || '';
        document.getElementById('host-sheba').value = h.sheba || '';
        document.getElementById('host-email').value = h.email || '';
        document.getElementById('host-city').value = h.city || '';
        document.getElementById('host-level').value = h.level || 'برنز';
        document.getElementById('host-status').value = h.status || 'pending';
        document.getElementById('host-properties').value = h.properties || 0;
        document.getElementById('host-rating').value = h.rating || 0;
        document.getElementById('host-notes').value = h.notes || '';
        openModal('modal-add-host');
    } catch (err) { showToast('خطا', 'error'); }
}

async function saveHost() {
    const id = document.getElementById('host-id').value;
    const data = {
        name: document.getElementById('host-name').value.trim(),
        mobile: document.getElementById('host-mobile').value.trim(),
        national_code: document.getElementById('host-national').value.trim(),
        sheba: document.getElementById('host-sheba').value.trim(),
        email: document.getElementById('host-email').value.trim(),
        city: document.getElementById('host-city').value.trim(),
        level: document.getElementById('host-level').value,
        status: document.getElementById('host-status').value,
        properties: parseInt(document.getElementById('host-properties').value) || 0,
        rating: parseFloat(document.getElementById('host-rating').value) || 0,
        notes: document.getElementById('host-notes').value.trim(),
    };
    if (!data.name) return showToast('نام الزامی است', 'warning');

    try {
        const result = id
            ? await fetchAPI('hosts/' + id, { method: 'PUT', body: data })
            : await fetchAPI('hosts', { method: 'POST', body: data });
        if (result.success) {
            showToast(id ? 'میزبان به‌روزرسانی شد' : 'میزبان جدید ایجاد شد', 'success');
            closeModal('modal-add-host');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function approveHost(id) {
    if (!confirmDelete('تأیید میزبان؟')) return;
    try {
        const result = await fetchAPI('hosts/' + id + '/status', {
            method: 'PATCH',
            body: { status: 'active' }
        });
        if (result.success) {
            showToast('میزبان تأیید شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}

async function exportHosts() {
    showToast('در حال آماده‌سازی...', 'info');
    try {
        const result = await fetchAPI('hosts?limit=1000');
        if (!result.success) return;
        const rows = result.data;
        const headers = ['ردیف','نام','موبایل','شهر','اقامتگاه‌ها','درآمد','امتیاز','سطح','وضعیت'];
        const csv = [headers.join(',')];
        rows.forEach((h, i) => {
            csv.push([i+1, '"'+(h.name||'')+'"', h.mobile||'', '"'+(h.city||'')+'"',
                      h.properties||0, h.total_revenue||0, h.rating||0, h.level||'', h.status||''].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `hosts-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
