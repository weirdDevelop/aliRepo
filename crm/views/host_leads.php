<?php
/**
 * جاکجاست - صفحه جذب میزبان
 * Jakojast - Host Leads Page
 *
 * نمایش سرنخ‌های میزبان با فیلدهای کامل:
 *   نام، موبایل، مرجع (source)، وضعیت (جذب اولیه/بی پاسخ/عدم تمایل)،
 *   توضیحات، لوکیشن، منطقه، تاریخ اقدام، شهر، نوع اقامتگاه، آخرین تماس
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$stageFilter = $_GET['stage'] ?? '';
$cityFilter = $_GET['city'] ?? '';
$propertyTypeFilter = $_GET['property_type'] ?? '';
$sourceFilter = $_GET['source'] ?? '';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = HostLead::all([
        'search' => $search, 'status' => $statusFilter, 'stage' => $stageFilter,
        'city' => $cityFilter, 'property_type' => $propertyTypeFilter,
        'source' => $sourceFilter,
        'page' => $page, 'limit' => 15,
    ]);
    $hostLeads = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 15));
    $stats = HostLead::getStats();
    $cities = HostLead::getCities();
} catch (Exception $e) {
    $hostLeads = []; $total = 0; $totalPages = 1; $stats = []; $cities = [];
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-host_leads" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card red">
        <div class="kpi-icon">🔍</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div>
        <div class="kpi-label">کل سرنخ‌ها</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">✓</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['converted'] ?? 0) ?></div>
        <div class="kpi-label">تبدیل شده به میزبان</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">📈</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['conversion_rate'] ?? 0) ?>٪</div>
        <div class="kpi-label">نرخ تبدیل</div>
      </div>
      <div class="kpi-card orange">
        <div class="kpi-icon">🏠</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['جذب اولیه'] ?? 0) ?></div>
        <div class="kpi-label">در حال جذب اولیه</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>🔍 جذب میزبان (Host Acquisition)</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary btn-sm" onclick="openHostLeadModal()">+ میزبان بالقوه جدید</button>
          <button class="btn btn-success btn-sm" onclick="importMizbanLeads()" id="import-mizban-btn" title="ورود مخاطبین از پوشه mizban-lead (جستجوی مکان)">📥 ایمپورت جستجوی مکان</button>
          <button class="btn btn-light btn-sm" onclick="exportHostLeads()">⬇ خروجی</button>
        </div>
      </div>

      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="hlead-search" placeholder="جستجو در نام، موبایل، لوکیشن..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="hlead-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach (HostLead::$statuses as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="hlead-stage-filter" onchange="applyFilters()">
          <option value="">همه مراحل (قیف قدیمی)</option>
          <?php foreach (HostLead::$stages as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $stageFilter === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="hlead-property-filter" onchange="applyFilters()">
          <option value="">همه انواع اقامتگاه</option>
          <?php foreach (HostLead::$propertyTypes as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $propertyTypeFilter === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="hlead-source-filter" onchange="applyFilters()">
          <option value="">همه مراجع</option>
          <?php foreach (HostLead::$sources as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $sourceFilter === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>ردیف</th>
              <th>تاریخ</th>
              <th>نام میزبان</th>
              <th>شماره تماس</th>
              <th>مرجع</th>
              <th>وضعیت</th>
              <th>توضیحات</th>
              <th>نوع اقامتگاه</th>
              <th>لوکیشن</th>
              <th>منطقه</th>
              <th>شهر</th>
              <th>آخرین تماس</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($hostLeads)): ?>
              <tr><td colspan="13" style="text-align:center;padding:30px;color:var(--text-muted)">
                🔍 سرنخ میزبانی یافت نشد
              </td></tr>
            <?php else: ?>
              <?php $start = ($page - 1) * 15; foreach ($hostLeads as $i => $l): ?>
                <tr>
                  <!-- ردیف -->
                  <td><?= $start + $i + 1 ?></td>
                  <!-- تاریخ (action_date) -->
                  <td><?= Helpers::e($l['action_date'] ?: '-') ?></td>
                  <!-- نام میزبان -->
                  <td><strong><?= Helpers::e($l['name']) ?></strong></td>
                  <!-- شماره تماس -->
                  <td dir="ltr"><?= Helpers::e($l['mobile'] ?: '-') ?></td>
                  <!-- مرجع (source) -->
                  <td><?= Helpers::e($l['source'] ?: '-') ?></td>
                  <!-- وضعیت -->
                  <td>
                    <select onchange="changeStatus(<?= (int)$l['id'] ?>, this.value)" class="form-control" style="width:auto;padding:4px 8px;font-size:12px">
                      <?php foreach (HostLead::$statuses as $s): ?>
                        <option value="<?= Helpers::e($s) ?>" <?= $l['status'] === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <!-- توضیحات (note) -->
                  <td>
                    <?php if (!empty($l['note'])): ?>
                      <span title="<?= Helpers::e($l['note']) ?>"><?= Helpers::e(Helpers::truncate($l['note'], 40)) ?></span>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <!-- نوع اقامتگاه -->
                  <td>
                    <?php if (!empty($l['property_type'])): ?>
                      <span class="badge badge-info"><?= Helpers::e($l['property_type']) ?></span>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <!-- لوکیشن -->
                  <td><?= Helpers::e($l['location'] ?: '-') ?></td>
                  <!-- منطقه -->
                  <td><?= Helpers::e($l['region'] ?: '-') ?></td>
                  <!-- شهر -->
                  <td><?= Helpers::e($l['city'] ?: '-') ?></td>
                  <!-- آخرین تماس -->
                  <td><?= Helpers::e($l['last_contact'] ?: '-') ?></td>
                  <!-- عملیات -->
                  <td>
                    <div style="display:flex;gap:4px">
                      <button class="btn btn-info btn-xs" title="ثبت تماس" onclick="logContact(<?= (int)$l['id'] ?>)">📞</button>
                      <button class="btn btn-light btn-xs" onclick="editHostLead(<?= (int)$l['id'] ?>)" title="ویرایش">✏️</button>
                      <?php if ($l['stage'] !== 'فعالسازی'): ?>
                        <button class="btn btn-success btn-xs" title="تبدیل به میزبان" onclick="convertHostLead(<?= (int)$l['id'] ?>, '<?= Helpers::e($l['name']) ?>')">🏠+</button>
                      <?php endif; ?>
                      <button class="btn btn-danger btn-xs" onclick="deleteHostLead(<?= (int)$l['id'] ?>)">🗑</button>
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
          <a href="?page=host_leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&stage=<?= urlencode($stageFilter) ?>&property_type=<?= urlencode($propertyTypeFilter) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=host_leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&stage=<?= urlencode($stageFilter) ?>&property_type=<?= urlencode($propertyTypeFilter) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=host_leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&stage=<?= urlencode($stageFilter) ?>&property_type=<?= urlencode($propertyTypeFilter) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- گزارش عملکرد بر اساس شهر -->
    <?php if (!empty($stats['by_city'])): ?>
    <div class="card" style="margin-top:20px">
      <div class="card-header"><h4>📊 آمار بر اساس شهر</h4></div>
      <div style="padding:16px">
        <div class="table-wrapper">
          <table class="data-table">
            <thead><tr><th>شهر</th><th>تعداد سرنخ</th></tr></thead>
            <tbody>
              <?php foreach ($stats['by_city'] as $city => $cnt): ?>
                <tr>
                  <td><strong><?= Helpers::e($city) ?></strong></td>
                  <td><?= Helpers::toPersianDigits($cnt) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>

<!-- مودال سرنخ میزبان -->
<div class="modal-overlay" id="modal-add-host-lead">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h4 id="hlead-modal-title">🔍 میزبان بالقوه جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-host-lead')">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="hlead-id">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">نام <span style="color:red">*</span></label>
          <input type="text" class="form-control" id="hlead-name" placeholder="نام میزبان بالقوه">
        </div>
        <div class="form-group">
          <label class="form-label">موبایل</label>
          <input type="text" class="form-control" id="hlead-mobile" placeholder="09XXXXXXXXX" dir="ltr">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">مرجع (منبع جذب)</label>
          <select class="form-control" id="hlead-source">
            <?php foreach (HostLead::$sources as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= Helpers::e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">وضعیت</label>
          <select class="form-control" id="hlead-status">
            <?php foreach (HostLead::$statuses as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= Helpers::e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">مرحله (قیف قدیمی)</label>
          <select class="form-control" id="hlead-stage">
            <?php foreach (HostLead::$stages as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= Helpers::e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">نوع اقامتگاه</label>
          <select class="form-control" id="hlead-property-type">
            <option value="">انتخاب کنید...</option>
            <?php foreach (HostLead::$propertyTypes as $s): ?>
              <option value="<?= Helpers::e($s) ?>"><?= Helpers::e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">شهر</label>
          <input type="text" class="form-control" id="hlead-city" placeholder="رامسر">
        </div>
        <div class="form-group">
          <label class="form-label">منطقه</label>
          <input type="text" class="form-control" id="hlead-region" placeholder="شمال، جنوب، غرب...">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">لوکیشن دقیق</label>
          <input type="text" class="form-control" id="hlead-location" placeholder="مثلاً: رامسر-جنگل">
        </div>
        <div class="form-group">
          <label class="form-label">تاریخ اقدام (شمسی)</label>
          <input type="text" class="form-control" id="hlead-action-date" placeholder="1403/02/15" class="jalali-date-input" value="<?= Jalali::today() ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">آخرین تماس (شمسی)</label>
          <input type="text" class="form-control" id="hlead-last-contact" placeholder="1403/02/15" class="jalali-date-input" value="<?= Jalali::today() ?>">
        </div>
        <div class="form-group">
          <label class="form-label">مسئول جذب</label>
          <input type="text" class="form-control" id="hlead-assignee" placeholder="نام مسئول">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">توضیحات</label>
        <textarea class="form-control" id="hlead-note" rows="2" placeholder="نوع ملک، شرایط خاص..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-host-lead')">انصراف</button>
      <button class="btn btn-primary" onclick="saveHostLead()">✓ ذخیره</button>
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
    const params = new URLSearchParams({page: 'host_leads'});
    const s = document.getElementById('hlead-search').value.trim();
    const st = document.getElementById('hlead-status-filter').value;
    const sg = document.getElementById('hlead-stage-filter').value;
    const pt = document.getElementById('hlead-property-filter').value;
    const src = document.getElementById('hlead-source-filter').value;
    if (s) params.set('search', s);
    if (st) params.set('status', st);
    if (sg) params.set('stage', sg);
    if (pt) params.set('property_type', pt);
    if (src) params.set('source', src);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

function openHostLeadModal() {
    document.getElementById('hlead-modal-title').textContent = '🔍 میزبان بالقوه جدید';
    document.getElementById('hlead-id').value = '';
    ['hlead-name','hlead-mobile','hlead-city','hlead-region','hlead-location','hlead-assignee','hlead-note'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('hlead-source').value = 'اینستاگرام';
    document.getElementById('hlead-status').value = 'جذب اولیه';
    document.getElementById('hlead-stage').value = 'جذب اولیه';
    document.getElementById('hlead-property-type').value = '';
    document.getElementById('hlead-action-date').value = '<?= Jalali::today() ?>';
    document.getElementById('hlead-last-contact').value = '<?= Jalali::today() ?>';
    openModal('modal-add-host-lead');
}

async function editHostLead(id) {
    try {
        const result = await fetchAPI('host_leads/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const l = result.data;
        document.getElementById('hlead-modal-title').textContent = '✏️ ویرایش سرنخ میزبان: ' + l.name;
        document.getElementById('hlead-id').value = l.id;
        document.getElementById('hlead-name').value = l.name || '';
        document.getElementById('hlead-mobile').value = l.mobile || '';
        document.getElementById('hlead-source').value = l.source || 'اینستاگرام';
        document.getElementById('hlead-status').value = l.status || 'جذب اولیه';
        document.getElementById('hlead-stage').value = l.stage || 'جذب اولیه';
        document.getElementById('hlead-property-type').value = l.property_type || '';
        document.getElementById('hlead-city').value = l.city || '';
        document.getElementById('hlead-region').value = l.region || '';
        document.getElementById('hlead-location').value = l.location || '';
        document.getElementById('hlead-action-date').value = l.action_date || '';
        document.getElementById('hlead-last-contact').value = l.last_contact || '';
        document.getElementById('hlead-assignee').value = l.assignee || '';
        document.getElementById('hlead-note').value = l.note || '';
        openModal('modal-add-host-lead');
    } catch (err) { showToast('خطا', 'error'); }
}

async function saveHostLead() {
    const id = document.getElementById('hlead-id').value;
    const data = {
        name: document.getElementById('hlead-name').value.trim(),
        mobile: document.getElementById('hlead-mobile').value.trim(),
        source: document.getElementById('hlead-source').value,
        status: document.getElementById('hlead-status').value,
        stage: document.getElementById('hlead-stage').value,
        property_type: document.getElementById('hlead-property-type').value,
        city: document.getElementById('hlead-city').value.trim(),
        region: document.getElementById('hlead-region').value.trim(),
        location: document.getElementById('hlead-location').value.trim(),
        action_date: document.getElementById('hlead-action-date').value.trim(),
        last_contact: document.getElementById('hlead-last-contact').value.trim(),
        assignee: document.getElementById('hlead-assignee').value.trim(),
        note: document.getElementById('hlead-note').value.trim(),
    };
    if (!data.name) return showToast('نام الزامی است', 'warning');
    try {
        const result = id
            ? await fetchAPI('host_leads/' + id, { method: 'PUT', body: data })
            : await fetchAPI('host_leads', { method: 'POST', body: data });
        if (result.success) {
            showToast(id ? 'سرنخ به‌روزرسانی شد' : 'سرنخ جدید ایجاد شد', 'success');
            closeModal('modal-add-host-lead');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function changeStatus(id, status) {
    try {
        const result = await fetchAPI(`host_leads/${id}/status`, { method: 'PATCH', body: { status } });
        if (!result.success) showToast(result.message, 'error');
        else showToast('وضعیت تغییر کرد', 'info');
    } catch (err) { showToast('خطا', 'error'); }
}

async function logContact(id) {
    try {
        const result = await fetchAPI(`host_leads/${id}/contact`, { method: 'POST' });
        if (result.success) {
            showToast('تماس ثبت شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}

async function convertHostLead(id, name) {
    if (!confirm('تبدیل سرنخ «' + name + '» به میزبان؟')) return;
    try {
        const result = await fetchAPI(`host_leads/${id}/convert`, { method: 'POST' });
        if (result.success) {
            showToast('سرنخ به میزبان تبدیل شد', 'success');
            setTimeout(() => window.location.href = `<?= BASE_URL ?>/index.php?page=host_profile&id=${result.data.host_id}`, 1200);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function deleteHostLead(id) {
    if (!confirmDelete('حذف سرنخ میزبان؟')) return;
    try {
        const result = await fetchAPI('host_leads/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('سرنخ حذف شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}

async function importMizbanLeads() {
    if (!confirm('۷۷۳ مخاطب از پوشه mizban-lead به‌عنوان سرنخ میزبان وارد شود؟\nشماره‌های تکراری رد می‌شوند.')) return;
    const updateExisting = confirm('آیا رکوردهای تکراری (همان شماره) هم به‌روز شوند؟\nOK = بله / Cancel = فقط موارد جدید');
    const btn = document.getElementById('import-mizban-btn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ در حال ایمپورت...'; }
    try {
        const result = await fetchAPI('host_leads/import-mizban', {
            method: 'POST',
            body: { update_existing: !!updateExisting }
        });
        if (result.success) {
            const d = result.data || {};
            showToast(result.message || ('وارد شد: ' + (d.imported || 0)), 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(result.message || 'خطا در ایمپورت', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = '📥 ایمپورت جستجوی مکان'; }
    }
}

async function exportHostLeads() {
    try {
        const result = await fetchAPI('host_leads?limit=1000');
        if (!result.success) return;
        const rows = result.data;
        const headers = ['ردیف','نام','موبایل','مرجع','وضعیت','شهر','منطقه','لوکیشن','نوع اقامتگاه','تاریخ اقدام','آخرین تماس','مسئول','توضیحات'];
        const csv = [headers.join(',')];
        rows.forEach((l, i) => {
            csv.push([i+1, '"'+(l.name||'')+'"', l.mobile||'', l.source||'', l.status||'',
                '"'+(l.city||'')+'"', '"'+(l.region||'')+'"', '"'+(l.location||'')+'"',
                l.property_type||'', l.action_date||'', l.last_contact||'',
                '"'+(l.assignee||'')+'"', '"'+(l.note||'')+'"'].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `host_leads-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
