<?php
/**
 * استعلام‌های سایت
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    SiteInquiry::migrate();
    $result = SiteInquiry::all([
        'search' => $search,
        'status' => $statusFilter,
        'page' => $page,
        'limit' => 20,
    ]);
    $items = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, (int)ceil($total / 20));
    $stats = SiteInquiry::getStats();
} catch (Exception $e) {
    $items = []; $total = 0; $totalPages = 1; $stats = [];
    Logger::error('site_inquiries page: ' . $e->getMessage());
}

$statusLabels = [
    'new' => 'جدید', 'in_progress' => 'در حال پیگیری',
    'converted' => 'تبدیل به سرنخ', 'closed' => 'بسته', 'spam' => 'اسپم',
];
?>
<meta name="jj-base-url" content="<?= BASE_URL ?>">
<main id="main-content">
  <div id="page-site_inquiries" class="page active">
    <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
      <div class="kpi-card blue"><div class="kpi-icon">📨</div><div class="kpi-value"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div><div class="kpi-label">کل استعلام‌ها</div></div>
      <div class="kpi-card orange"><div class="kpi-icon">🆕</div><div class="kpi-value"><?= Helpers::toPersianDigits($stats['new'] ?? 0) ?></div><div class="kpi-label">جدید</div></div>
      <div class="kpi-card green"><div class="kpi-icon">🎯</div><div class="kpi-value"><?= Helpers::toPersianDigits($stats['converted'] ?? 0) ?></div><div class="kpi-label">تبدیل‌شده به سرنخ</div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>📨 استعلام‌های سایت <span class="badge badge-primary"><?= Helpers::toPersianDigits($total) ?></span></h4>
      </div>
      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="inq-search" value="<?= Helpers::e($search) ?>" placeholder="جستجو نام/شماره/پیام..." onkeydown="if(event.key==='Enter')applyInqFilters()">
        </div>
        <select id="inq-status" onchange="applyInqFilters()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach ($statusLabels as $k => $v): ?>
            <option value="<?= $k ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-light btn-sm" onclick="applyInqFilters()">اعمال</button>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>تاریخ</th><th>نام</th><th>موبایل</th><th>اقامتگاه</th><th>پیام</th><th>پشتیبان</th><th>وضعیت</th><th>عملیات</th></tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-muted)">استعلامی ثبت نشده. از تنظیمات → همگام‌سازی سایت می‌توانید ایمپورت کنید.</td></tr>
            <?php else: foreach ($items as $r): ?>
              <tr>
                <td><?= Helpers::e($r['inquired_jalali'] ?? '-') ?></td>
                <td><?= Helpers::e($r['name'] ?: '-') ?></td>
                <td dir="ltr"><?php if (!empty($r['mobile'])): ?><a href="<?= BASE_URL ?>/index.php?page=phone_detail&number=<?= urlencode($r['mobile']) ?>"><?= Helpers::e($r['mobile']) ?></a><?php else: ?>-<?php endif; ?></td>
                <td><?= Helpers::e($r['property_title'] ?: '-') ?></td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= Helpers::e($r['message'] ?? '') ?>"><?= Helpers::e(mb_substr($r['message'] ?? '-', 0, 60)) ?></td>
                <td><?= Helpers::e($r['assignee'] ?: '-') ?></td>
                <td><span class="badge badge-secondary"><?= Helpers::e($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
                <td style="display:flex;gap:4px;flex-wrap:wrap">
                  <?php if (!empty($r['lead_id'])): ?>
                    <a class="btn btn-success btn-xs" href="<?= BASE_URL ?>/index.php?page=leads&id=<?= (int)$r['lead_id'] ?>">سرنخ</a>
                  <?php else: ?>
                    <button class="btn btn-primary btn-xs" onclick="convertInquiry(<?= (int)$r['id'] ?>)">→ سرنخ</button>
                  <?php endif; ?>
                  <button class="btn btn-light btn-xs" onclick="setInquiryStatus(<?= (int)$r['id'] ?>, 'in_progress')">پیگیری</button>
                  <button class="btn btn-light btn-xs" onclick="setInquiryStatus(<?= (int)$r['id'] ?>, 'closed')">بستن</button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?><a class="page-btn" href="?page=site_inquiries&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&p=<?= $page - 1 ?>">«</a><?php endif; ?>
        <span class="page-info"><?= Helpers::toPersianDigits($page) ?> / <?= Helpers::toPersianDigits($totalPages) ?></span>
        <?php if ($page < $totalPages): ?><a class="page-btn" href="?page=site_inquiries&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&p=<?= $page + 1 ?>">»</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<script>
function applyInqFilters() {
  const p = new URLSearchParams({page: 'site_inquiries'});
  const s = document.getElementById('inq-search').value.trim();
  const st = document.getElementById('inq-status').value;
  if (s) p.set('search', s);
  if (st) p.set('status', st);
  location.href = '<?= BASE_URL ?>/index.php?' + p.toString();
}
async function convertInquiry(id) {
  const r = await fetchAPI('site-inquiries/' + id + '/convert-to-lead', {method:'POST', body:{}});
  if (r.success) { showToast(r.message, 'success'); setTimeout(() => location.reload(), 800); }
  else showToast(r.message || 'خطا', 'error');
}
async function setInquiryStatus(id, status) {
  const r = await fetchAPI('site-inquiries/' + id + '/status', {method:'PATCH', body:{status}});
  if (r.success) location.reload();
  else showToast(r.message || 'خطا', 'error');
}
</script>
<?php require __DIR__ . '/parts/footer.php'; ?>
