<?php
/**
 * لیست فروش سایت
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$fromDate = Helpers::toEnglishDigits(trim($_GET['from_date'] ?? ''));
$toDate = Helpers::toEnglishDigits(trim($_GET['to_date'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    SiteSale::migrate();
    $result = SiteSale::all([
        'search' => $search,
        'status' => $statusFilter,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'page' => $page,
        'limit' => 20,
    ]);
    $items = $result['items'];
    $total = $result['total'];
    $sumAmount = $result['sum_amount'] ?? 0;
    $totalPages = max(1, (int)ceil($total / 20));
    $stats = SiteSale::getStats();
} catch (Exception $e) {
    $items = []; $total = 0; $sumAmount = 0; $totalPages = 1; $stats = [];
    Logger::error('site_sales page: ' . $e->getMessage());
}
?>
<meta name="jj-base-url" content="<?= BASE_URL ?>">
<main id="main-content">
  <div id="page-site_sales" class="page active">
    <div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
      <div class="kpi-card blue"><div class="kpi-icon">🧾</div><div class="kpi-value"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></div><div class="kpi-label">کل فروش‌ها</div></div>
      <div class="kpi-card green"><div class="kpi-icon">💰</div><div class="kpi-value"><?= Helpers::toPersianDigits(Helpers::formatNumber($stats['amount'] ?? 0)) ?></div><div class="kpi-label">مبلغ کل (تومان)</div></div>
      <div class="kpi-card orange"><div class="kpi-icon">📅</div><div class="kpi-value"><?= Helpers::toPersianDigits($stats['today'] ?? 0) ?></div><div class="kpi-label">فروش امروز</div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <h4>🧾 لیست فروش سایت
          <span class="badge badge-primary"><?= Helpers::toPersianDigits($total) ?></span>
          <span class="badge badge-success" style="margin-right:6px"><?= Helpers::toPersianDigits(Helpers::formatNumber($sumAmount)) ?> ت (فیلتر)</span>
        </h4>
      </div>
      <div class="filter-bar">
        <div class="search-box">
          <span class="si">🔍</span>
          <input type="text" id="sale-search" value="<?= Helpers::e($search) ?>" placeholder="کد سفارش / مشتری / اقامتگاه..." onkeydown="if(event.key==='Enter')applySaleFilters()">
        </div>
        <select id="sale-status" onchange="applySaleFilters()">
          <option value="">همه وضعیت‌ها</option>
          <?php foreach (SiteSale::$statuses as $st): ?>
            <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" id="sale-from" class="jalali-date-input" placeholder="از تاریخ" value="<?= Helpers::e($fromDate) ?>" style="width:120px" onchange="applySaleFilters()">
        <input type="text" id="sale-to" class="jalali-date-input" placeholder="تا تاریخ" value="<?= Helpers::e($toDate) ?>" style="width:120px" onchange="applySaleFilters()">
        <button class="btn btn-light btn-sm" onclick="applySaleFilters()">اعمال</button>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>تاریخ</th><th>کد</th><th>مشتری</th><th>موبایل</th><th>اقامتگاه</th><th>میزبان</th><th>ورود/خروج</th><th>مبلغ</th><th>وضعیت</th></tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text-muted)">فروشی ثبت نشده. از تنظیمات سایت همگام‌سازی کنید.</td></tr>
            <?php else: foreach ($items as $r): ?>
              <tr>
                <td><?= Helpers::e($r['sold_jalali'] ?? '-') ?></td>
                <td dir="ltr"><?= Helpers::e($r['order_code'] ?: ($r['site_id'] ?: '-')) ?></td>
                <td><?= Helpers::e($r['customer_name'] ?: '-') ?></td>
                <td dir="ltr"><?php if (!empty($r['customer_mobile'])): ?><a href="<?= BASE_URL ?>/index.php?page=phone_detail&number=<?= urlencode($r['customer_mobile']) ?>"><?= Helpers::e($r['customer_mobile']) ?></a><?php else: ?>-<?php endif; ?></td>
                <td><?= Helpers::e($r['property_title'] ?: '-') ?></td>
                <td><?= Helpers::e($r['host_name'] ?: '-') ?></td>
                <td><?= Helpers::e(($r['check_in'] ?: '-') . ' → ' . ($r['check_out'] ?: '-')) ?></td>
                <td><?= Helpers::toPersianDigits(Helpers::formatNumber($r['amount'] ?? 0)) ?></td>
                <td><span class="badge badge-secondary"><?= Helpers::e($r['status']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="pagination" style="padding:16px">
        <?php if ($page > 1): ?><a class="page-btn" href="?page=site_sales&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&p=<?= $page-1 ?>">«</a><?php endif; ?>
        <span class="page-info"><?= Helpers::toPersianDigits($page) ?> / <?= Helpers::toPersianDigits($totalPages) ?></span>
        <?php if ($page < $totalPages): ?><a class="page-btn" href="?page=site_sales&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&p=<?= $page+1 ?>">»</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<script>
function applySaleFilters() {
  const p = new URLSearchParams({page:'site_sales'});
  const s = document.getElementById('sale-search').value.trim();
  const st = document.getElementById('sale-status').value;
  const fd = document.getElementById('sale-from').value.trim();
  const td = document.getElementById('sale-to').value.trim();
  if (s) p.set('search', s);
  if (st) p.set('status', st);
  if (fd) p.set('from_date', fd);
  if (td) p.set('to_date', td);
  location.href = '<?= BASE_URL ?>/index.php?' + p.toString();
}
</script>
<?php require __DIR__ . '/parts/footer.php'; ?>
