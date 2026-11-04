<?php
/**
 * جاکجاست - صفحه گزارش‌ها
 * Jakojast - Reports Page
 *
 * شامل گزارش‌های:
 *   ۱) فروش ماهانه
 *   ۲) مالی میزبانان
 *   ۳) عملکرد پرسنل
 *   ۴) پشتیبانی (تیکت‌ها)
 *   ۵) تماس‌ها
 *   ۶) سرنخ‌ها
 *
 * با Chart.js (CDN) برای نمودارهای تعاملی + خروجی CSV + چاپ
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

try {
    // دریافت داده‌ها از مدل‌ها
    $customerStats = Customer::getStats();
    $hostStats = Host::getStats();
    $leadStats = Lead::getStats();
    $hostLeadStats = HostLead::getStats();
    $ticketStats = Ticket::getStats();
    $taskStats = Task::getStats();
    $callStats = Call::getStats();
    $leaveStats = Leave::getStats();
    $salesReport = Report::getSalesReport();

    // داده نمودار درآمد ۱۲ ماه
    $revenueChart = Report::getRevenueChart();
    $reservationsChart = Report::getReservationsChart();
} catch (Exception $e) {
    Logger::error('Reports page load failed: ' . $e->getMessage());
    $customerStats = []; $hostStats = []; $leadStats = []; $hostLeadStats = [];
    $ticketStats = []; $taskStats = []; $callStats = []; $leaveStats = [];
    $salesReport = []; $revenueChart = []; $reservationsChart = [];
}

// محاسبه خلاصه‌ها
$totalRevenue = $customerStats['total_spend'] ?? 0;
$totalHostRevenue = $hostStats['total_revenue'] ?? 0;
$avgRating = $hostStats['avg_rating'] ?? 0;
$conversionRate = $leadStats['conversion_rate'] ?? 0;
$callAnswerRate = ($callStats['total'] ?? 0) > 0
    ? round((($callStats['answered'] ?? 0) / $callStats['total']) * 100, 1)
    : 0;
$taskCompletionRate = $taskStats['completion_rate'] ?? 0;
$ticketCloseRate = ($ticketStats['total'] ?? 0) > 0
    ? round((($ticketStats['by_status']['closed'] ?? 0) / $ticketStats['total']) * 100, 1)
    : 0;
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">
<!-- Chart.js از CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
@media print {
    #sidebar, #main-header, #mobile-topbar, #mobile-nav, .no-print { display: none !important; }
    #main-content { margin: 0 !important; padding: 0 !important; }
    .card { break-inside: avoid; }
}
.chart-container {
    position: relative;
    height: 300px;
    width: 100%;
}
.report-section {
    margin-bottom: 24px;
}
.report-section h3 {
    color: var(--primary);
    border-bottom: 2px solid var(--primary);
    padding-bottom: 8px;
    margin-bottom: 16px;
    font-size: 16px;
}
</style>

<main id="main-content">
  <div id="page-reports" class="page active">

    <!-- کارت‌های خلاصه -->
    <div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px">
      <div class="stat-box">
        <div class="sb-value text-primary"><?= Helpers::toPersianDigits(Helpers::formatNumber(($customerStats['total_spend'] ?? 0) / 1000000)) ?>M</div>
        <div class="sb-label">کل فروش (تومان)</div>
      </div>
      <div class="stat-box">
        <div class="sb-value text-success"><?= Helpers::toPersianDigits(Helpers::formatNumber(($hostStats['total_revenue'] ?? 0) / 1000000)) ?>M</div>
        <div class="sb-label">درآمد میزبانان (تومان)</div>
      </div>
      <div class="stat-box">
        <div class="sb-value text-info"><?= Helpers::toPersianDigits($avgRating) ?> ⭐</div>
        <div class="sb-label">میانگین رضایت</div>
      </div>
      <div class="stat-box">
        <div class="sb-value text-warning"><?= Helpers::toPersianDigits($conversionRate) ?>٪</div>
        <div class="sb-label">نرخ تبدیل سرنخ</div>
      </div>
    </div>

    <!-- نوار ابزار -->
    <div class="card no-print" style="margin-bottom:20px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div style="font-weight:600">📊 گزارش‌های پیشرفته</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-light btn-sm" onclick="exportAllStats()">⬇ خروجی CSV</button>
        <button class="btn btn-light btn-sm" onclick="window.print()">🖨 چاپ</button>
      </div>
    </div>

    <!-- نمودار درآمد و رزرو -->
    <div class="grid-2 report-section">
      <div class="card">
        <div class="card-header">
          <h4>📈 درآمد ۱۲ ماه اخیر</h4>
          <span class="badge badge-primary">تومان</span>
        </div>
        <div style="padding:16px">
          <div class="chart-container">
            <canvas id="revenue-chart-canvas"></canvas>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <h4>📅 مشتریان جدید ماهانه</h4>
          <span class="badge badge-info">تعداد</span>
        </div>
        <div style="padding:16px">
          <div class="chart-container">
            <canvas id="reservations-chart-canvas"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- گزارش فروش ماهانه -->
    <div class="card report-section">
      <div class="card-header">
        <h4>📈 گزارش فروش ماهانه</h4>
        <button class="btn btn-light btn-sm no-print" onclick="exportSalesReport()">⬇ CSV</button>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>ماه</th><th>مشتریان جدید</th><th>درآمد</th><th>میانگین هر مشتری</th></tr>
          </thead>
          <tbody>
            <?php if (empty($salesReport)): ?>
              <tr><td colspan="4" style="text-align:center;padding:30px;color:var(--text-muted)">داده‌ای موجود نیست</td></tr>
            <?php else: ?>
              <?php foreach ($salesReport as $r): ?>
                <tr>
                  <td><strong><?= Helpers::e($r['month']) ?></strong></td>
                  <td><?= Helpers::toPersianDigits($r['newCustomers']) ?></td>
                  <td><?= Helpers::toPersianDigits(Helpers::formatNumber($r['revenue'])) ?> ت</td>
                  <td><?= Helpers::toPersianDigits(Helpers::formatNumber($r['newCustomers'] > 0 ? intval($r['revenue'] / $r['newCustomers']) : 0)) ?> ت</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- گزارش مالی میزبانان -->
    <div class="card report-section">
      <div class="card-header">
        <h4>💰 گزارش مالی میزبانان</h4>
        <button class="btn btn-light btn-sm no-print" onclick="exportHostFinance()">⬇ CSV</button>
      </div>
      <div class="grid-4" style="padding:16px;margin-bottom:16px">
        <div class="stat-box">
          <div class="sb-value text-success"><?= Helpers::toPersianDigits(Helpers::formatNumber(($hostStats['total_revenue'] ?? 0) / 1000000)) ?>M</div>
          <div class="sb-label">کل درآمد میزبانان</div>
        </div>
        <div class="stat-box">
          <div class="sb-value text-info"><?= Helpers::toPersianDigits($hostStats['total_properties'] ?? 0) ?></div>
          <div class="sb-label">کل اقامتگاه‌ها</div>
        </div>
        <div class="stat-box">
          <div class="sb-value text-warning"><?= Helpers::toPersianDigits($hostStats['by_status']['active'] ?? 0) ?></div>
          <div class="sb-label">میزبانان فعال</div>
        </div>
        <div class="stat-box">
          <div class="sb-value text-danger"><?= Helpers::toPersianDigits($hostStats['by_status']['pending'] ?? 0) ?></div>
          <div class="sb-label">در انتظار تأیید</div>
        </div>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>سطح</th><th>تعداد</th><th>درصد</th></tr></thead>
          <tbody>
            <?php
            $totalHosts = array_sum($hostStats['by_level'] ?? []);
            foreach (Host::$levels as $lvl):
              $cnt = $hostStats['by_level'][$lvl] ?? 0;
              $pct = $totalHosts > 0 ? round(($cnt / $totalHosts) * 100, 1) : 0;
            ?>
              <tr>
                <td><strong><?= Helpers::e($lvl) ?></strong></td>
                <td><?= Helpers::toPersianDigits($cnt) ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:100px;background:var(--bg-secondary);border-radius:4px;height:8px;overflow:hidden">
                      <div style="width:<?= $pct ?>%;background:var(--primary);height:100%"></div>
                    </div>
                    <span><?= Helpers::toPersianDigits($pct) ?>٪</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- گزارش عملکرد پرسنل -->
    <div class="card report-section">
      <div class="card-header">
        <h4>👥 گزارش عملکرد پرسنل</h4>
        <button class="btn btn-light btn-sm no-print" onclick="exportStaffPerformance()">⬇ CSV</button>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr><th>نام</th><th>سمت</th><th>واحد</th><th>تعداد تسک</th><th>تسک‌های انجام شده</th><th>نرخ تکمیل</th></tr>
          </thead>
          <tbody>
            <?php
            $allStaff = Database::getInstance()->fetchAll('SELECT id, code, name, role, unit FROM staff ORDER BY name');
            foreach ($allStaff as $s):
                $tasks = Staff::getTasks($s['id']);
                $taskCount = count($tasks);
                $doneCount = count(array_filter($tasks, fn($t) => $t['status'] === 'done'));
                $rate = $taskCount > 0 ? round(($doneCount / $taskCount) * 100, 1) : 0;
            ?>
              <tr>
                <td><strong><?= Helpers::e($s['name']) ?></strong></td>
                <td><?= Helpers::e($s['role'] ?: '-') ?></td>
                <td><?= Helpers::e($s['unit'] ?: '-') ?></td>
                <td><?= Helpers::toPersianDigits($taskCount) ?></td>
                <td><?= Helpers::toPersianDigits($doneCount) ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:80px;background:var(--bg-secondary);border-radius:4px;height:8px;overflow:hidden">
                      <div style="width:<?= $rate ?>%;background:<?= $rate >= 70 ? '#28a745' : ($rate >= 40 ? '#ffc107' : '#dc3545') ?>;height:100%"></div>
                    </div>
                    <span><?= Helpers::toPersianDigits($rate) ?>٪</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- گزارش پشتیبانی -->
    <div class="card report-section">
      <div class="card-header">
        <h4>🎫 گزارش پشتیبانی</h4>
      </div>
      <div style="padding:20px">
        <div class="grid-4">
          <div class="stat-box">
            <div class="sb-value text-danger"><?= Helpers::toPersianDigits($ticketStats['by_status']['open'] ?? 0) ?></div>
            <div class="sb-label">تیکت باز</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-warning"><?= Helpers::toPersianDigits($ticketStats['by_status']['in_review'] ?? 0) ?></div>
            <div class="sb-label">در بررسی</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-info"><?= Helpers::toPersianDigits($ticketStats['by_status']['answered'] ?? 0) ?></div>
            <div class="sb-label">پاسخ داده شده</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-success"><?= Helpers::toPersianDigits($ticketStats['by_status']['closed'] ?? 0) ?></div>
            <div class="sb-label">بسته شده</div>
          </div>
        </div>
        <div style="margin-top:16px">
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">نرخ بستن تیکت: <strong class="text-success"><?= Helpers::toPersianDigits($ticketCloseRate) ?>٪</strong></div>
          <div style="height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden">
            <div style="width:<?= $ticketCloseRate ?>%;background:#28a745;height:100%;transition:width 0.5s"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- گزارش اپراتورها (ساعتی / روزانه / هفتگی) -->
    <div class="card report-section" id="operator-metrics-section">
      <div class="card-header" style="flex-wrap:wrap;gap:8px">
        <h4>🎧 عملکرد اپراتورها (ساعتی / روزانه / هفتگی)</h4>
        <div class="no-print" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <select id="op-period" class="form-control" style="width:auto;padding:4px 8px" onchange="loadOperatorMetrics()">
            <option value="hourly">ساعتی (امروز)</option>
            <option value="daily" selected>روزانه (۷ روز)</option>
            <option value="weekly">هفتگی</option>
          </select>
          <input type="text" id="op-from" class="form-control jalali-date-input" placeholder="از تاریخ" style="width:120px">
          <input type="text" id="op-to" class="form-control jalali-date-input" placeholder="تا تاریخ" style="width:120px">
          <button class="btn btn-primary btn-sm" onclick="loadOperatorMetrics()">نمایش</button>
          <button class="btn btn-light btn-sm" onclick="exportOperatorMetrics()">⬇ CSV</button>
        </div>
      </div>
      <div style="padding:12px 16px">
        <div id="operator-metrics-box">
          <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
        </div>
      </div>
    </div>

    <!-- گزارش‌های فردی (بله / دستی) -->
    <div class="card report-section" id="staff-notes-section">
      <div class="card-header" style="flex-wrap:wrap;gap:8px">
        <h4>📝 گزارش‌های فردی پرسنل (بله / دستی / ایمپورت)</h4>
        <div class="no-print" style="display:flex;gap:6px">
          <button class="btn btn-primary btn-sm" onclick="openStaffNoteModal()">+ ثبت گزارش</button>
          <button class="btn btn-light btn-sm" onclick="loadStaffNotes()">↻ تازه‌سازی</button>
        </div>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>تاریخ</th><th>پرسنل</th><th>منبع</th><th>عنوان</th><th>متن</th><th>ثبت‌کننده</th><th></th></tr></thead>
          <tbody id="staff-notes-tbody">
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:20px">در حال بارگذاری...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- گزارش تماس‌ها -->
    <div class="card report-section">
      <div class="card-header">
        <h4>📞 گزارش تماس‌ها</h4>
        <button class="btn btn-light btn-sm no-print" onclick="window.location.href='<?= BASE_URL ?>/index.php?page=calls'">مشاهده کامل →</button>
      </div>
      <div style="padding:20px">
        <div class="grid-4">
          <div class="stat-box">
            <div class="sb-value text-primary"><?= Helpers::toPersianDigits($callStats['total'] ?? 0) ?></div>
            <div class="sb-label">کل تماس‌ها</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-success"><?= Helpers::toPersianDigits($callStats['answered'] ?? 0) ?></div>
            <div class="sb-label">پاسخ داده شد</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-warning"><?= Helpers::toPersianDigits($callAnswerRate) ?>٪</div>
            <div class="sb-label">نرخ پاسخگویی</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-info"><?= Helpers::toPersianDigits(Call::formatDuration($callStats['total_talk_time'] ?? 0)) ?></div>
            <div class="sb-label">کل مدت مکالمه</div>
          </div>
        </div>
        <div style="margin-top:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="background:var(--bg-secondary);padding:12px;border-radius:8px">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">📥 تماس‌های ورودی</div>
            <div style="font-size:18px;font-weight:700" class="text-info"><?= Helpers::toPersianDigits($callStats['inbound'] ?? 0) ?></div>
          </div>
          <div style="background:var(--bg-secondary);padding:12px;border-radius:8px">
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">📤 تماس‌های خروجی</div>
            <div style="font-size:18px;font-weight:700" class="text-primary"><?= Helpers::toPersianDigits($callStats['outbound'] ?? 0) ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- گزارش سرنخ‌ها -->
    <div class="card report-section">
      <div class="card-header">
        <h4>🎯 گزارش سرنخ‌های فروش</h4>
      </div>
      <div class="table-wrapper">
        <table class="data-table">
          <thead><tr><th>مرحله</th><th>تعداد</th><th>ارزش کل</th><th>میانگین</th><th>درصد</th></tr></thead>
          <tbody>
            <?php
            $totalLeads = array_sum(array_map(fn($s) => $s['count'] ?? 0, $leadStats['by_stage'] ?? []));
            foreach (Lead::$stages as $stage):
              $data = $leadStats['by_stage'][$stage] ?? ['count' => 0, 'value' => 0];
              $pct = $totalLeads > 0 ? round(($data['count'] / $totalLeads) * 100, 1) : 0;
            ?>
              <tr>
                <td><strong><?= Helpers::e($stage) ?></strong></td>
                <td><?= Helpers::toPersianDigits($data['count']) ?></td>
                <td><?= Helpers::toPersianDigits(Helpers::formatNumber($data['value'])) ?> ت</td>
                <td><?= Helpers::toPersianDigits(Helpers::formatNumber($data['count'] > 0 ? intval($data['value'] / $data['count']) : 0)) ?> ت</td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div style="width:80px;background:var(--bg-secondary);border-radius:4px;height:8px;overflow:hidden">
                      <div style="width:<?= $pct ?>%;background:<?= $stage === 'برنده' ? '#28a745' : ($stage === 'باخته' ? '#dc3545' : 'var(--primary)') ?>;height:100%"></div>
                    </div>
                    <span><?= Helpers::toPersianDigits($pct) ?>٪</span>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- گزارش تسک‌ها -->
    <div class="card report-section">
      <div class="card-header">
        <h4>✅ گزارش تسک‌ها</h4>
      </div>
      <div style="padding:20px">
        <div class="grid-4">
          <div class="stat-box">
            <div class="sb-value text-info"><?= Helpers::toPersianDigits($taskStats['by_status']['new'] ?? 0) ?></div>
            <div class="sb-label">جدید</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-primary"><?= Helpers::toPersianDigits($taskStats['by_status']['in_progress'] ?? 0) ?></div>
            <div class="sb-label">در حال انجام</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-warning"><?= Helpers::toPersianDigits($taskStats['by_status']['review'] ?? 0) ?></div>
            <div class="sb-label">در بررسی</div>
          </div>
          <div class="stat-box">
            <div class="sb-value text-success"><?= Helpers::toPersianDigits($taskStats['by_status']['done'] ?? 0) ?></div>
            <div class="sb-label">انجام شده</div>
          </div>
        </div>
        <div style="margin-top:16px">
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">نرخ تکمیل تسک: <strong class="text-success"><?= Helpers::toPersianDigits($taskCompletionRate) ?>٪</strong></div>
          <div style="height:8px;background:var(--bg-secondary);border-radius:4px;overflow:hidden">
            <div style="width:<?= $taskCompletionRate ?>%;background:#28a745;height:100%;transition:width 0.5s"></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
// ===========================
// نمودارهای Chart.js
// ===========================

// داده‌ها از PHP
const revenueData = <?= json_encode($revenueChart ?: []) ?>;
const reservationsData = <?= json_encode($reservationsChart ?: []) ?>;

// نمودار درآمد
function renderRevenueChart() {
    const ctx = document.getElementById('revenue-chart-canvas');
    if (!ctx || revenueData.length === 0) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: revenueData.map(d => d.month),
            datasets: [{
                label: 'درآمد (میلیون تومان)',
                data: revenueData.map(d => d.value / 1000000),
                backgroundColor: 'rgba(229, 2, 65, 0.7)',
                borderColor: '#e50241',
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, labels: { font: { family: 'Vazirmatn' } } },
                tooltip: {
                    bodyFont: { family: 'Vazirmatn' },
                    titleFont: { family: 'Vazirmatn' },
                    callbacks: {
                        label: function(ctx) {
                            return ctx.parsed.y.toFixed(1) + ' میلیون تومان';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { font: { family: 'Vazirmatn' } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { family: 'Vazirmatn' } },
                    grid: { display: false }
                }
            }
        }
    });
}

// نمودار رزروها
function renderReservationsChart() {
    const ctx = document.getElementById('reservations-chart-canvas');
    if (!ctx || reservationsData.length === 0) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: reservationsData.map(d => d.month),
            datasets: [{
                label: 'مشتریان جدید',
                data: reservationsData.map(d => d.value),
                backgroundColor: 'rgba(23, 162, 184, 0.2)',
                borderColor: '#17a2b8',
                borderWidth: 2,
                tension: 0.3,
                fill: true,
                pointBackgroundColor: '#17a2b8',
                pointRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, labels: { font: { family: 'Vazirmatn' } } },
                tooltip: {
                    bodyFont: { family: 'Vazirmatn' },
                    titleFont: { family: 'Vazirmatn' },
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { font: { family: 'Vazirmatn' } },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                },
                x: {
                    ticks: { font: { family: 'Vazirmatn' } },
                    grid: { display: false }
                }
            }
        }
    });
}

// ===========================
// خروجی CSV
// ===========================

function exportCSV(filename, headers, rows) {
    const csv = [headers.join(',')];
    rows.forEach(row => {
        csv.push(row.map(cell => {
            const s = String(cell ?? '');
            return s.includes(',') || s.includes('"') ? `"${s.replace(/"/g, '""')}"` : s;
        }).join(','));
    });
    const blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    downloadBlob(blob, filename);
    showToast('فایل دانلود شد', 'success');
}

function exportSalesReport() {
    const data = <?= json_encode($salesReport) ?>;
    exportCSV('sales-report.csv', ['ماه', 'مشتریان جدید', 'درآمد'], 
        data.map(r => [r.month, r.newCustomers, r.revenue]));
}

function exportHostFinance() {
    const stats = <?= json_encode($hostStats) ?>;
    const rows = [];
    Object.entries(stats.by_level || {}).forEach(([level, count]) => rows.push([level, count]));
    exportCSV('host-finance.csv', ['سطح', 'تعداد'], rows);
}

function exportStaffPerformance() {
    // دریافت از جدول
    const rows = [];
    document.querySelectorAll('table.data-table tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (tds.length >= 6) rows.push([tds[0].textContent, tds[1].textContent, tds[2].textContent, tds[3].textContent, tds[4].textContent, tds[5].textContent]);
    });
    if (rows.length > 0) {
        exportCSV('staff-performance.csv', ['نام', 'سمت', 'واحد', 'تعداد تسک', 'تسک انجام شده', 'نرخ تکمیل'], rows);
    }
}

function exportAllStats() {
    const stats = {
        customers: <?= json_encode($customerStats) ?>,
        hosts: <?= json_encode($hostStats) ?>,
        leads: <?= json_encode($leadStats) ?>,
        tickets: <?= json_encode($ticketStats) ?>,
        calls: <?= json_encode($callStats) ?>,
        tasks: <?= json_encode($taskStats) ?>,
    };
    const rows = [];
    Object.entries(stats).forEach(([category, data]) => {
        Object.entries(data).forEach(([key, value]) => {
            if (typeof value === 'object') {
                Object.entries(value).forEach(([k, v]) => rows.push([category, key + '.' + k, JSON.stringify(v)]));
            } else {
                rows.push([category, key, value]);
            }
        });
    });
    exportCSV('all-stats.csv', ['دسته', 'کلید', 'مقدار'], rows);
}

// ===========================
// گزارش اپراتورها
// ===========================
let _lastOperatorData = null;
function fmtDur(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    const m = Math.floor(sec / 60), s = sec % 60;
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
async function loadOperatorMetrics() {
    const box = document.getElementById('operator-metrics-box');
    if (!box) return;
    box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>';
    const period = document.getElementById('op-period').value;
    const from = (document.getElementById('op-from').value || '').trim();
    const to = (document.getElementById('op-to').value || '').trim();
    const qs = new URLSearchParams({ period });
    if (from) qs.set('from_date', from);
    if (to) qs.set('to_date', to);
    try {
        const result = await fetchAPI('reports/operators?' + qs.toString(), { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger)">خطا در دریافت گزارش</p>';
            return;
        }
        _lastOperatorData = result.data;
        const ops = (result.data && result.data.operators) || [];
        if (!ops.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در این بازه تماسی برای اپراتورها ثبت نشده.</p>';
            return;
        }
        box.innerHTML = `
          <div class="table-wrapper">
            <table class="data-table">
              <thead>
                <tr>
                  <th>اپراتور</th><th>داخلی</th><th>کل</th><th>پاسخ</th><th>بی‌پاسخ</th>
                  <th>ورودی</th><th>خروجی</th><th>نرخ پاسخ</th><th>مدت مکالمه</th><th>جزئیات بازه</th>
                </tr>
              </thead>
              <tbody>
                ${ops.map(op => {
                    const detail = (op.buckets || []).map(b =>
                        `<span class="badge badge-secondary" style="margin:2px;font-size:10px" title="پاسخ ${b.answered} / بی‌پاسخ ${b.missed}">${escapeHtml(b.bucket_label||b.bucket)}: ${b.total}</span>`
                    ).join(' ');
                    const profile = op.staff_id
                        ? `<a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=${op.staff_id}" style="color:var(--primary);text-decoration:none">${escapeHtml(op.operator_name)}</a>`
                        : escapeHtml(op.operator_name);
                    return `<tr>
                      <td><strong>${profile}</strong></td>
                      <td dir="ltr">${escapeHtml(op.exten_number)}</td>
                      <td>${op.total}</td>
                      <td>${op.answered}</td>
                      <td>${op.missed}</td>
                      <td>${op.inbound}</td>
                      <td>${op.outbound}</td>
                      <td>${op.answer_rate}٪</td>
                      <td>${escapeHtml(op.talk_formatted || fmtDur(op.talk_seconds))}</td>
                      <td style="max-width:280px">${detail || '-'}</td>
                    </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger)">خطا در ارتباط با سرور</p>';
    }
}
function exportOperatorMetrics() {
    if (!_lastOperatorData || !(_lastOperatorData.operators || []).length) {
        showToast('ابتدا گزارش را بارگذاری کنید', 'error');
        return;
    }
    const rows = [];
    _lastOperatorData.operators.forEach(op => {
        (op.buckets || [{ bucket_label: 'جمع', total: op.total, answered: op.answered, missed: op.missed, inbound: op.inbound, outbound: op.outbound, answer_rate: op.answer_rate, talk_seconds: op.talk_seconds }]).forEach(b => {
            rows.push([
                op.operator_name, op.exten_number, b.bucket_label || b.bucket,
                b.total, b.answered, b.missed, b.inbound, b.outbound, b.answer_rate, b.talk_seconds
            ]);
        });
    });
    exportCSV('operator-metrics.csv',
        ['اپراتور','داخلی','بازه','کل','پاسخ','بی‌پاسخ','ورودی','خروجی','نرخ پاسخ','ثانیه مکالمه'],
        rows);
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
}

// ===========================
// گزارش‌های فردی
// ===========================
const STAFF_OPTIONS = <?= json_encode(array_map(function ($s) {
    return ['id' => (int)$s['id'], 'name' => $s['name'], 'exten' => $s['exten_number'] ?? ''];
}, Database::getInstance()->fetchAll('SELECT id, name, exten_number FROM staff ORDER BY name'))) ?>;

async function loadStaffNotes() {
    const tbody = document.getElementById('staff-notes-tbody');
    if (!tbody) return;
    try {
        const result = await fetchAPI('reports/staff-notes?limit=50', { method: 'GET' });
        if (!result.success) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--danger)">خطا</td></tr>';
            return;
        }
        const rows = result.data || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:20px">گزارشی ثبت نشده. از «+ ثبت گزارش» یا ایمپورت JSON استفاده کنید.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => `
          <tr>
            <td>${escapeHtml(r.report_date || '-')}</td>
            <td><a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=${r.staff_id}" style="color:var(--primary);text-decoration:none">${escapeHtml(r.staff_name || ('#'+r.staff_id))}</a></td>
            <td><span class="badge badge-info">${escapeHtml(r.source_label || r.source)}</span></td>
            <td>${escapeHtml(r.title || '-')}</td>
            <td title="${escapeHtml(r.content || '')}">${escapeHtml((r.content || '').slice(0, 80))}${(r.content||'').length > 80 ? '…' : ''}</td>
            <td>${escapeHtml(r.author_name || '-')}</td>
            <td><button class="btn btn-danger btn-xs" onclick="deleteStaffNote(${r.id})">🗑</button></td>
          </tr>`).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--danger)">خطا در ارتباط</td></tr>';
    }
}
function openStaffNoteModal() {
    openModal('modal-staff-note');
}
async function saveStaffNote() {
    const staffId = document.getElementById('sn-staff').value;
    const content = document.getElementById('sn-content').value.trim();
    if (!staffId || !content) { showToast('پرسنل و متن الزامی است', 'error'); return; }
    const body = {
        staff_id: parseInt(staffId, 10),
        report_date: document.getElementById('sn-date').value.trim() || undefined,
        source: document.getElementById('sn-source').value,
        title: document.getElementById('sn-title').value.trim() || undefined,
        content
    };
    const result = await fetchAPI('reports/staff-notes', { method: 'POST', body });
    if (result.success) {
        showToast('گزارش ثبت شد', 'success');
        closeModal('modal-staff-note');
        loadStaffNotes();
    } else showToast(result.message || 'خطا', 'error');
}
async function deleteStaffNote(id) {
    if (!confirm('حذف گزارش؟')) return;
    const result = await fetchAPI('reports/staff-notes/' + id, { method: 'DELETE' });
    if (result.success) { showToast('حذف شد', 'success'); loadStaffNotes(); }
    else showToast(result.message || 'خطا', 'error');
}
async function importStaffNotesJson() {
    const raw = document.getElementById('sn-import-json').value.trim();
    if (!raw) { showToast('JSON را وارد کنید', 'error'); return; }
    let items;
    try { items = JSON.parse(raw); } catch (e) { showToast('JSON نامعتبر', 'error'); return; }
    if (!Array.isArray(items)) items = [items];
    const result = await fetchAPI('reports/staff-notes/import', { method: 'POST', body: { items } });
    if (result.success) {
        showToast(result.message || 'ایمپورت شد', 'success');
        document.getElementById('sn-import-json').value = '';
        loadStaffNotes();
    } else showToast(result.message || 'خطا', 'error');
}

// ===========================
// راه‌اندازی اولیه
// ===========================
document.addEventListener('DOMContentLoaded', function() {
    renderRevenueChart();
    renderReservationsChart();
    loadOperatorMetrics();
    loadStaffNotes();
});
</script>

<div class="modal-overlay" id="modal-staff-note">
  <div class="modal-box" style="max-width:560px">
    <div class="modal-header">
      <h4>📝 ثبت گزارش فردی</h4>
      <button class="btn-close" onclick="closeModal('modal-staff-note')">×</button>
    </div>
    <div class="modal-body" style="padding:16px">
      <div class="form-group">
        <label class="form-label">پرسنل</label>
        <select id="sn-staff" class="form-control">
          <option value="">— انتخاب —</option>
          <?php foreach (Database::getInstance()->fetchAll('SELECT id, name, exten_number FROM staff ORDER BY name') as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= Helpers::e($s['name']) ?><?= $s['exten_number'] ? ' (' . Helpers::e($s['exten_number']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid-2">
        <div class="form-group">
          <label class="form-label">تاریخ (شمسی)</label>
          <input type="text" id="sn-date" class="form-control jalali-date-input" value="<?= Helpers::e(Jalali::today()) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">منبع</label>
          <select id="sn-source" class="form-control">
            <option value="bale">بله</option>
            <option value="manual" selected>دستی</option>
            <option value="import">ایمپورت</option>
            <option value="other">سایر</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">عنوان</label>
        <input type="text" id="sn-title" class="form-control" placeholder="اختیاری">
      </div>
      <div class="form-group">
        <label class="form-label">متن گزارش</label>
        <textarea id="sn-content" class="form-control" rows="5" placeholder="متن گزارش روزانه / بله..."></textarea>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px">
        <button class="btn btn-light btn-sm" onclick="closeModal('modal-staff-note')">انصراف</button>
        <button class="btn btn-primary btn-sm" onclick="saveStaffNote()">ذخیره</button>
      </div>
      <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
      <div class="form-group">
        <label class="form-label">ایمپورت JSON (آرایه گزارش‌ها)</label>
        <textarea id="sn-import-json" class="form-control" rows="4" dir="ltr" placeholder='[{"staff_name":"علی","report_date":"1404/07/20","source":"bale","content":"..."}]'></textarea>
        <small style="color:var(--text-muted);font-size:11px">فیلدها: staff_id یا staff_name یا exten، report_date، content، source، title</small>
      </div>
      <button class="btn btn-success btn-sm" onclick="importStaffNotesJson()">📥 ایمپورت JSON</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/parts/footer.php'; ?>
