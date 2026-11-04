<?php
/**
 * جاکجاست - صفحه سرنخ‌های میهمان (Guest Leads)
 * Jakojast - Guest Leads Page
 *
 * فرم افزودن با فیلدهای کامل:
 *   تاریخ، اپراتور، وضعیت، مرجع، نام مشتری، شماره تماس،
 *   محل زندگی، محلات درخواستی، نوع اقامتگاه، قیمت min/max،
 *   اقامتگاه‌های معرفی شده، امکانات (پایه + ویژه)، توضیحات
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

// بارگذاری لیست‌های داده‌ای
require_once ROOT_PATH . '/config/data_lists.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$referenceFilter = $_GET['reference'] ?? '';
$operatorFilter = $_GET['operator'] ?? '';
$sort = $_GET['sort'] ?? 'date';
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    $result = Lead::all([
        'search' => $search, 'status' => $statusFilter,
        'reference' => $referenceFilter, 'operator' => $operatorFilter,
        'sort' => $sort, 'page' => $page, 'limit' => 15,
    ]);
    $leads = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 15));
    $stats = Lead::getStats();
} catch (Exception $e) {
    $leads = []; $total = 0; $totalPages = 1; $stats = [];
}

$operators = Lead::getOperators();
$guestStatuses = Lead::getGuestStatuses();
$guestStatusesNeedingFollowup = Lead::getGuestStatusesNeedingFollowup();
$guestReferences = Lead::getGuestReferences();
$propertyTypes = Lead::getPropertyTypes();
$neighborhoods = Lead::getNeighborhoods();
$amenitiesBasic = Lead::getAmenitiesBasic();
$amenitiesSpecial = Lead::getAmenitiesSpecial();
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<style>
.checkbox-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 6px;
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    background: var(--bg-secondary);
    border-radius: 8px;
    border: 1px solid var(--border);
}
.checkbox-group label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background 0.2s;
}
.checkbox-group label:hover {
    background: rgba(229, 2, 65, 0.05);
}
.checkbox-group input[type="checkbox"] {
    width: auto;
    margin: 0;
}
.lead-row {
    cursor: pointer;
    transition: background 0.2s;
}
.lead-row:hover {
    background: rgba(229, 2, 65, 0.03) !important;
}
.tag {
    display: inline-block;
    background: var(--bg-secondary);
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    margin: 2px;
    border: 1px solid var(--border);
}
</style>

<main id="main-content">
  <div id="page-leads" class="page active">
    <!-- کارت‌های آمار -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
      <div class="kpi-card red">
        <div class="kpi-icon">🎯</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($total) ?></div>
        <div class="kpi-label">کل سرنخ‌های میهمان</div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">📋</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['رزرو نهایی شد'] ?? 0) ?></div>
        <div class="kpi-label">رزرو نهایی شد</div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">📞</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['جدید'] ?? 0) ?></div>
        <div class="kpi-label">جدید</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">❌</div>
        <div class="kpi-value"><?= Helpers::toPersianDigits($stats['by_status']['منصرف شد'] ?? 0) ?></div>
        <div class="kpi-label">منصرف شد</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>🎯 سرنخ‌های میهمان
          <span class="badge badge-primary" style="font-size:12px"><?= Helpers::toPersianDigits($total) ?></span>
        </h4>
        <div style="display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="openLeadModal()">+ سرنخ جدید</button>
          <button class="btn btn-light btn-sm" onclick="exportLeads()">⬇ خروجی</button>
        </div>
      </div>

      <!-- فیلترها -->
      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="lead-search" placeholder="جستجو در نام، موبایل، توضیحات..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="lead-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach ($guestStatuses as $s): ?>
            <option value="<?= Helpers::e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= Helpers::e($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="lead-reference-filter" onchange="applyFilters()">
          <option value="">همه مراجع</option>
          <?php foreach ($guestReferences as $r): ?>
            <option value="<?= Helpers::e($r) ?>" <?= $referenceFilter === $r ? 'selected' : '' ?>><?= Helpers::e($r) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="lead-operator-filter" onchange="applyFilters()">
          <option value="">همه اپراتورها</option>
          <?php foreach ($operators as $o): ?>
            <option value="<?= Helpers::e($o) ?>" <?= $operatorFilter === $o ? 'selected' : '' ?>><?= Helpers::e($o) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="lead-sort" onchange="applyFilters()">
          <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>جدیدترین ثبت</option>
          <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>آخرین به‌روزرسانی</option>
          <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>نام (الفبا)</option>
          <option value="next_contact" <?= $sort === 'next_contact' ? 'selected' : '' ?>>نزدیک‌ترین تماس بعدی</option>
          <option value="value" <?= $sort === 'value' ? 'selected' : '' ?>>بیشترین ارزش</option>
        </select>
      </div>

      <!-- جدول -->
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>تاریخ</th>
              <th>آخرین به‌روزرسانی</th>
              <th>نام مشتری</th>
              <th>شماره تماس</th>
              <th>اپراتور</th>
              <th>وضعیت</th>
              <th>مرجع</th>
              <th>نوع اقامتگاه</th>
              <th>محل زندگی</th>
              <th>قیمت (تومان)</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($leads)): ?>
              <tr><td colspan="12" style="text-align:center;padding:30px;color:var(--text-muted)">
                🎯 سرنخی یافت نشد. روی «+ سرنخ جدید» کلیک کنید.
              </td></tr>
            <?php else: ?>
              <?php $start = ($page - 1) * 15; foreach ($leads as $i => $l): ?>
                <tr class="lead-row" onclick="editLead(<?= (int)$l['id'] ?>)" <?= $l['status'] === 'رزرو نهایی شد' ? 'style="background:rgba(34,197,94,0.08)"' : '' ?>>
                  <td><?= $start + $i + 1 ?></td>
                  <td><?= Helpers::e($l['date'] ?: $l['created_jalali'] ?: '-') ?></td>
                  <td style="direction:ltr;text-align:right;color:var(--text-muted);font-size:12px"><?= Helpers::e($l['updated_jalali'] ?: '-') ?></td>
                  <td><strong><?= Helpers::e($l['name']) ?></strong></td>
                  <td dir="ltr"><?= Helpers::e($l['mobile'] ?: '-') ?></td>
                  <td>
                    <?php if (!empty($l['operator'] ?: $l['assignee'])): ?>
                      <?= Helpers::e($l['operator'] ?: $l['assignee']) ?>
                    <?php else: ?>
                      <button class="btn btn-primary btn-sm" onclick="event.stopPropagation(); claimLead(<?= (int)$l['id'] ?>)">🖐 برداشتن</button>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($l['status'])): ?>
                      <span class="badge <?= getLeadStatusBadge($l['status']) ?>"><?= Helpers::e($l['status']) ?></span>
                      <?php if ($l['status'] === 'رزرو نهایی شد'): ?>
                        <span title="این سرنخ به فروش/رزرو تبدیل شده" style="color:var(--success,green);font-weight:bold;margin-right:4px">✅</span>
                      <?php endif; ?>
                    <?php elseif (!empty($l['stage'])): ?>
                      <span class="badge badge-secondary"><?= Helpers::e($l['stage']) ?></span>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td><?= Helpers::e($l['reference'] ?: $l['source'] ?: '-') ?></td>
                  <td><?= Helpers::e($l['property_type'] ?: '-') ?></td>
                  <td><?= Helpers::e($l['residence'] ?: '-') ?></td>
                  <td>
                    <?php
                    $priceText = '';
                    if (!empty($l['min_price']) && !empty($l['max_price'])) {
                        $priceText = Helpers::toPersianDigits(Helpers::formatNumber($l['min_price'])) . ' - ' . Helpers::toPersianDigits(Helpers::formatNumber($l['max_price']));
                    } elseif (!empty($l['min_price'])) {
                        $priceText = 'از ' . Helpers::toPersianDigits(Helpers::formatNumber($l['min_price']));
                    } elseif (!empty($l['max_price'])) {
                        $priceText = 'تا ' . Helpers::toPersianDigits(Helpers::formatNumber($l['max_price']));
                    } elseif (!empty($l['value'])) {
                        $priceText = Helpers::toPersianDigits(Helpers::formatNumber($l['value']));
                    } else {
                        $priceText = '-';
                    }
                    echo $priceText;
                    ?>
                  </td>
                  <td onclick="event.stopPropagation()">
                    <div style="display:flex;gap:4px">
                      <button class="btn btn-info btn-xs" title="ویرایش" onclick="editLead(<?= (int)$l['id'] ?>)">✏️</button>
                      <button class="btn btn-danger btn-xs" title="حذف" onclick="deleteLead(<?= (int)$l['id'] ?>)">🗑</button>
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
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?>
          <a href="?page=leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&reference=<?= urlencode($referenceFilter) ?>&operator=<?= urlencode($operatorFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $page - 1 ?>" class="page-btn">« قبلی</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="?page=leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&reference=<?= urlencode($referenceFilter) ?>&operator=<?= urlencode($operatorFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?page=leads&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&reference=<?= urlencode($referenceFilter) ?>&operator=<?= urlencode($operatorFilter) ?>&sort=<?= urlencode($sort) ?>&p=<?= $page + 1 ?>" class="page-btn">بعدی »</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>

<!-- ===========================
     مودال افزودن/ویرایش سرنخ میهمان
     =========================== -->
<div class="modal-overlay" id="modal-add-lead">
  <div class="modal-box modal-lg" style="max-width:800px">
    <div class="modal-header">
      <h4 id="lead-modal-title">🎯 سرنخ میهمان جدید</h4>
      <button class="btn-close" onclick="closeModal('modal-add-lead')">×</button>
    </div>
    <div class="modal-body" style="max-height:70vh;overflow-y:auto">
      <input type="hidden" id="lead-id">

      <!-- بخش ۱: اطلاعات اصلی -->
      <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:8px">
        <div style="font-weight:700;margin-bottom:10px;color:var(--primary)">📋 اطلاعات اصلی</div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">تاریخ *</label>
            <input type="text" class="form-control" id="lead-date" placeholder="1403/02/20" value="<?= Jalali::today() ?>">
          </div>
          <div class="form-group">
            <label class="form-label">اپراتور *</label>
            <select class="form-control" id="lead-operator">
              <option value="">انتخاب اپراتور...</option>
              <?php foreach ($operators as $o): ?>
                <option value="<?= Helpers::e($o) ?>"><?= Helpers::e($o) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">وضعیت</label>
            <select class="form-control" id="lead-status" onchange="onLeadStatusChange()">
              <?php foreach ($guestStatuses as $s): ?>
                <option value="<?= Helpers::e($s) ?>"><?= Helpers::e($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">مرجع</label>
            <select class="form-control" id="lead-reference">
              <option value="">انتخاب مرجع...</option>
              <?php foreach ($guestReferences as $r): ?>
                <option value="<?= Helpers::e($r) ?>"><?= Helpers::e($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">تاریخ پیگیری بعدی</label>
            <input type="text" class="form-control" id="lead-next-contact" placeholder="1403/02/25">
            <div id="lead-followup-hint" style="display:none;font-size:12px;color:var(--warning, #b8860b);margin-top:4px">
              ⚠️ این وضعیت معمولاً نیاز به پیگیری بعدی دارد - بهتره یه تاریخ اینجا ثبت کنی.
            </div>
          </div>
        </div>
        <div id="lead-status-history-box" style="display:none;margin-top:8px;padding:10px;background:var(--bg-secondary);border-radius:8px;font-size:12px;max-height:160px;overflow-y:auto"></div>
      </div>

      <!-- بخش ۲: اطلاعات مشتری -->
      <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:8px">
        <div style="font-weight:700;margin-bottom:10px;color:var(--primary)">👤 اطلاعات مشتری</div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">نام مشتری *</label>
            <input type="text" class="form-control" id="lead-name" placeholder="نام و نام خانوادگی">
          </div>
          <div class="form-group">
            <label class="form-label">شماره تماس *</label>
            <input type="text" class="form-control" id="lead-mobile" placeholder="09XXXXXXXXX" dir="ltr">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">محل زندگی</label>
          <input type="text" class="form-control" id="lead-residence" placeholder="مثلاً: تهران، سعادت آباد">
        </div>
      </div>

      <!-- بخش ۳: درخواست اقامتگاه -->
      <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:8px">
        <div style="font-weight:700;margin-bottom:10px;color:var(--primary)">🏠 درخواست اقامتگاه</div>
        <div class="form-group">
          <label class="form-label">محلات درخواستی</label>
          <input type="text" class="form-control" id="neighborhood-search" placeholder="جستجوی محله..." style="margin-bottom:8px" oninput="filterNeighborhoods()">
          <div class="checkbox-group" id="neighborhoods-group">
            <?php foreach ($neighborhoods as $n): ?>
              <label class="neighborhood-item">
                <input type="checkbox" value="<?= Helpers::e($n) ?>" class="neighborhood-checkbox">
                <?= Helpers::e($n) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:6px;margin-top:8px">
            <input type="text" class="form-control" id="neighborhood-new" placeholder="محله‌ی جدید (اگر در لیست بالا نبود)" style="flex:1">
            <button type="button" class="btn btn-light btn-sm" onclick="addNewNeighborhood()">+ افزودن</button>
          </div>
          <small style="color:var(--text-muted);font-size:11px">می‌توانید چند محله انتخاب کنید یا محل جدید اضافه کنید</small>
        </div>
        <div class="form-group">
          <label class="form-label">نوع اقامتگاه</label>
          <select class="form-control" id="lead-property-type">
            <option value="">انتخاب نوع...</option>
            <?php foreach ($propertyTypes as $pt): ?>
              <option value="<?= Helpers::e($pt) ?>"><?= Helpers::e($pt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">حداقل قیمت (تومان)</label>
            <input type="number" class="form-control" id="lead-min-price" placeholder="500000" min="0">
          </div>
          <div class="form-group">
            <label class="form-label">حداکثر قیمت (تومان)</label>
            <input type="number" class="form-control" id="lead-max-price" placeholder="5000000" min="0">
          </div>
        </div>
      </div>

      <!-- بخش ۴: امکانات -->
      <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:8px">
        <div style="font-weight:700;margin-bottom:10px;color:var(--primary)">🛠️ امکانات اقامتگاه</div>
        <div class="form-group">
          <label class="form-label">امکانات پایه</label>
          <div class="checkbox-group" id="amenities-basic-group">
            <?php foreach ($amenitiesBasic as $a): ?>
              <label>
                <input type="checkbox" value="<?= Helpers::e($a) ?>" class="amenity-basic-checkbox">
                <?= Helpers::e($a) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">امکانات ویژه</label>
          <div class="checkbox-group" id="amenities-special-group">
            <?php foreach ($amenitiesSpecial as $a): ?>
              <label>
                <input type="checkbox" value="<?= Helpers::e($a) ?>" class="amenity-special-checkbox">
                <?= Helpers::e($a) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- بخش ۵: توضیحات -->
      <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:8px">
        <div style="font-weight:700;margin-bottom:10px;color:var(--primary)">📝 توضیحات</div>
        <div class="form-group">
          <label class="form-label">توضیحات</label>
          <textarea class="form-control" id="lead-description" rows="3" placeholder="توضیحات تکمیلی، نیازهای خاص مشتری..."></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-add-lead')">انصراف</button>
      <button class="btn btn-primary" onclick="saveLead()">✓ ذخیره</button>
    </div>
  </div>
</div>

<?php
function getLeadStatusBadge($status) {
    $map = [
        'جدید' => 'badge-secondary',
        'تماس گرفته شد' => 'badge-info',
        'اقامتگاه پیشنهاد شد' => 'badge-info',
        'در انتظار تأیید میزبان' => 'badge-warning',
        'پیش‌فاکتور ارسال شد' => 'badge-warning',
        'در انتظار پرداخت' => 'badge-warning',
        'رزرو نهایی شد' => 'badge-success',
        'منصرف شد' => 'badge-danger',
        'بی‌پاسخ' => 'badge-danger',
    ];
    return $map[$status] ?? 'badge-secondary';
}
?>

<script>
let searchTimer = null;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 500);
}
function applyFilters() {
    const params = new URLSearchParams({page: 'leads'});
    const s = document.getElementById('lead-search').value.trim();
    const st = document.getElementById('lead-status-filter').value;
    const rf = document.getElementById('lead-reference-filter').value;
    const op = document.getElementById('lead-operator-filter').value;
    const sr = document.getElementById('lead-sort').value;
    if (s) params.set('search', s);
    if (st) params.set('status', st);
    if (rf) params.set('reference', rf);
    if (op) params.set('operator', op);
    if (sr) params.set('sort', sr);
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

function openLeadModal() {
    document.getElementById('lead-modal-title').textContent = '🎯 سرنخ میهمان جدید';
    document.getElementById('lead-id').value = '';
    // پاک کردن همه فیلدها
    ['lead-name','lead-mobile','lead-residence','lead-min-price','lead-max-price','lead-description','lead-next-contact'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('lead-date').value = '<?= Jalali::today() ?>';
    document.getElementById('lead-operator').value = '';
    document.getElementById('lead-status').value = 'جدید';
    document.getElementById('lead-reference').value = '';
    document.getElementById('lead-property-type').value = '';
    document.getElementById('neighborhood-search').value = '';
    document.getElementById('neighborhood-new').value = '';
    filterNeighborhoods();
    // unchecked همه checkbox ها
    document.querySelectorAll('.neighborhood-checkbox, .amenity-basic-checkbox, .amenity-special-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('lead-followup-hint').style.display = 'none';
    document.getElementById('lead-status-history-box').style.display = 'none';
    openModal('modal-add-lead');
}

// وضعیت‌هایی که رسیدن بهشون یعنی «نیاز به پیگیری بعدی» - سمت سرور هم
// همین لیست معتبر است (Lead::getGuestStatusesNeedingFollowup)
const LEAD_STATUSES_NEEDING_FOLLOWUP = <?= json_encode($guestStatusesNeedingFollowup, JSON_UNESCAPED_UNICODE) ?>;

// هشدار نرم (نه اجبار سخت): اگه وضعیت انتخاب‌شده نیاز به پیگیری داره و
// تاریخ پیگیری بعدی خالیه، یه هشدار کوچیک نشون بده
function onLeadStatusChange() {
    const status = document.getElementById('lead-status').value;
    const nextContact = document.getElementById('lead-next-contact').value.trim();
    const hint = document.getElementById('lead-followup-hint');
    hint.style.display = (LEAD_STATUSES_NEEDING_FOLLOWUP.includes(status) && !nextContact) ? 'block' : 'none';
}

// جستجو در لیست محلات (فقط نمایش/عدم‌نمایش، بدون تغییر انتخاب‌ها)
function filterNeighborhoods() {
    const q = document.getElementById('neighborhood-search').value.trim().toLowerCase();
    document.querySelectorAll('.neighborhood-item').forEach(item => {
        const text = item.textContent.trim().toLowerCase();
        item.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}

// افزودن یک محل جدید (اگر در لیست نبود) - به‌صورت یک چک‌باکس جدید و انتخاب‌شده
function addNewNeighborhood() {
    const input = document.getElementById('neighborhood-new');
    const name = input.value.trim();
    if (!name) return;

    // اگر از قبل وجود دارد، فقط تیکش بزن
    const existing = Array.from(document.querySelectorAll('.neighborhood-checkbox')).find(cb => cb.value === name);
    if (existing) {
        existing.checked = true;
        showToast('این محل از قبل در لیست بود و انتخاب شد', 'info');
        input.value = '';
        return;
    }

    const group = document.getElementById('neighborhoods-group');
    const label = document.createElement('label');
    label.className = 'neighborhood-item';
    label.innerHTML = `<input type="checkbox" value="${name.replace(/"/g, '&quot;')}" class="neighborhood-checkbox" checked> ${name}`;
    group.appendChild(label);
    input.value = '';
    showToast('محل جدید اضافه شد', 'success');
}

async function claimLead(id) {
    // ⚠️ برداشتن سرنخ به‌صورت اتمیک - سمت سرور تضمین می‌کند فقط یک نفر
    // برنده شود حتی اگر دو اپراتور دقیقاً هم‌زمان کلیک کنند (Lead::claim)
    try {
        const result = await fetchAPI('leads/' + id + '/claim', { method: 'POST' });
        if (!result.success) {
            // ۴۰۹: یعنی یک اپراتور دیگر زودتر برداشته - نه یک خطای واقعی
            showToast(result.message, 'warning');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 600);
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function editLead(id) {
    try {
        const result = await fetchAPI('leads/' + id);
        if (!result.success) return showToast(result.message, 'error');
        const l = result.data;
        document.getElementById('lead-modal-title').textContent = '✏️ ویرایش سرنخ: ' + l.name;
        document.getElementById('lead-id').value = l.id;
        document.getElementById('lead-date').value = l.date || '<?= Jalali::today() ?>';
        document.getElementById('lead-operator').value = l.operator || l.assignee || '';
        document.getElementById('lead-status').value = l.status || 'جدید';
        document.getElementById('lead-reference').value = l.reference || l.source || '';
        document.getElementById('lead-name').value = l.name || '';
        document.getElementById('lead-mobile').value = l.mobile || '';
        document.getElementById('lead-residence').value = l.residence || '';
        document.getElementById('lead-property-type').value = l.property_type || '';
        document.getElementById('lead-min-price').value = l.min_price || '';
        document.getElementById('lead-max-price').value = l.max_price || '';
        document.getElementById('lead-description').value = l.description || l.note || '';
        document.getElementById('lead-next-contact').value = l.next_contact || '';
        onLeadStatusChange();
        loadLeadStatusHistory(id);

        // تنظیم checkbox های محلات
        document.getElementById('neighborhood-search').value = '';
        filterNeighborhoods();
        const neighborhoods = l.requested_neighborhoods_array || [];
        document.querySelectorAll('.neighborhood-checkbox').forEach(cb => {
            cb.checked = neighborhoods.includes(cb.value);
        });
        // تنظیم checkbox های امکانات پایه
        const basicAmenities = l.amenities_basic_array || [];
        document.querySelectorAll('.amenity-basic-checkbox').forEach(cb => {
            cb.checked = basicAmenities.includes(cb.value);
        });
        // تنظیم checkbox های امکانات ویژه
        const specialAmenities = l.amenities_special_array || [];
        document.querySelectorAll('.amenity-special-checkbox').forEach(cb => {
            cb.checked = specialAmenities.includes(cb.value);
        });

        openModal('modal-add-lead');
    } catch (err) { showToast('خطا', 'error'); }
}

// نمایش تاریخچه‌ی تغییر وضعیت این سرنخ (برای تصمیم‌گیری اپراتور بر
// اساس رفتار قبلی سرنخ - نه فقط وضعیت لحظه‌ای)
async function loadLeadStatusHistory(id) {
    const box = document.getElementById('lead-status-history-box');
    box.style.display = 'block';
    box.innerHTML = 'در حال بارگذاری تاریخچه...';
    try {
        const result = await fetchAPI('leads/' + id + '/status-history');
        if (!result.success || !result.data.length) {
            box.innerHTML = '<span style="color:var(--text-muted)">هنوز تغییر وضعیتی ثبت نشده.</span>';
            return;
        }
        box.innerHTML = '<div style="font-weight:700;margin-bottom:6px">🕑 تاریخچه‌ی وضعیت</div>' +
            result.data.map(h => `
              <div style="padding:4px 0;border-bottom:1px solid var(--border)">
                ${h.old_status ? escapeLeadHtml(h.old_status) + ' ← ' : ''}<b>${escapeLeadHtml(h.new_status)}</b>
                <span style="color:var(--text-muted)"> — ${h.created_jalali}${h.changed_by ? ' — ' + escapeLeadHtml(h.changed_by) : ''}</span>
              </div>`).join('');
    } catch (err) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در بارگذاری تاریخچه</span>';
    }
}

function escapeLeadHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

async function saveLead() {
    const id = document.getElementById('lead-id').value;

    // جمع‌آوری محلات انتخاب شده
    const neighborhoods = Array.from(document.querySelectorAll('.neighborhood-checkbox:checked')).map(cb => cb.value);
    // جمع‌آوری امکانات پایه
    const amenitiesBasic = Array.from(document.querySelectorAll('.amenity-basic-checkbox:checked')).map(cb => cb.value);
    // جمع‌آوری امکانات ویژه
    const amenitiesSpecial = Array.from(document.querySelectorAll('.amenity-special-checkbox:checked')).map(cb => cb.value);

    const data = {
        date: document.getElementById('lead-date').value.trim(),
        operator: document.getElementById('lead-operator').value,
        status: document.getElementById('lead-status').value,
        reference: document.getElementById('lead-reference').value,
        name: document.getElementById('lead-name').value.trim(),
        mobile: document.getElementById('lead-mobile').value.trim(),
        residence: document.getElementById('lead-residence').value.trim(),
        property_type: document.getElementById('lead-property-type').value,
        min_price: parseInt(document.getElementById('lead-min-price').value) || null,
        max_price: parseInt(document.getElementById('lead-max-price').value) || null,
        next_contact: document.getElementById('lead-next-contact').value.trim(),
        requested_neighborhoods: neighborhoods,
        amenities_basic: amenitiesBasic,
        amenities_special: amenitiesSpecial,
        description: document.getElementById('lead-description').value.trim(),
    };

    if (!data.name) return showToast('نام مشتری الزامی است', 'warning');
    if (!data.operator) return showToast('اپراتور الزامی است', 'warning');
    if (!data.date) return showToast('تاریخ الزامی است', 'warning');

    try {
        const result = id
            ? await fetchAPI('leads/' + id, { method: 'PUT', body: data })
            : await fetchAPI('leads', { method: 'POST', body: data });
        if (result.success) {
            showToast(id ? 'سرنخ به‌روزرسانی شد' : 'سرنخ جدید ایجاد شد', 'success');
            closeModal('modal-add-lead');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function deleteLead(id) {
    if (!confirmDelete('حذف این سرنخ؟')) return;
    try {
        const result = await fetchAPI('leads/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('سرنخ حذف شد', 'success');
            setTimeout(() => window.location.reload(), 800);
        }
    } catch (err) { showToast('خطا', 'error'); }
}

async function exportLeads() {
    try {
        const result = await fetchAPI('leads?limit=1000');
        if (!result.success) return;
        const rows = result.data;
        const csv = ['ردیف,تاریخ,نام مشتری,شماره تماس,اپراتور,وضعیت,مرجع,نوع اقامتگاه,محل زندگی,حداقل قیمت,حداکثر قیمت,توضیحات'];
        rows.forEach((l, i) => {
            csv.push([i+1, l.date||'', '"'+(l.name||'')+'"', l.mobile||'', '"'+(l.operator||l.assignee||'')+'"',
                '"'+(l.status||'')+'"', '"'+(l.reference||l.source||'')+'"', '"'+(l.property_type||'')+'"',
                '"'+(l.residence||'')+'"', l.min_price||'', l.max_price||'',
                '"'+(l.description||l.note||'')+'"'].join(','));
        });
        const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        downloadBlob(blob, `guest-leads-${Date.now()}.csv`);
        showToast('فایل دانلود شد', 'success');
    } catch (err) { showToast('خطا', 'error'); }
}

// ⚠️ باز کردن خودکار مودال ویرایش یک سرنخ خاص وقتی از جایی دیگر (مثلاً
// کلیک روی اسم توی لیست تماس‌ها) با ?id=X به این صفحه لینک داده شده
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);
    const directId = params.get('id');
    if (directId) editLead(parseInt(directId, 10));
});
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
