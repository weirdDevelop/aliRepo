<?php
/**
 * جاکجاست - داشبورد اجرایی (کامل)
 * Jakojast - Executive Dashboard
 *
 * شامل:
 *   - ۶ کارت KPI (مشتریان، میزبانان، پرسنل، درآمد، رضایت، رشد)
 *   - ۲ نمودار میله‌ای CSS (درآمد ۱۲ ماه + رزروها)
 *   - لیست فعالیت‌های اخیر
 *   - لیست هشدارهای سیستم
 *   - نمودار سازمانی مینی
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

// دریافت داده‌های اولیه از سرور (به جای درخواست AJAX جداگانه)
// این کار از فلش محتوا و تجربه بهتر کاربر می‌دهد
try {
    $summary = Report::getDashboardSummary();
    $revenueChart = Report::getRevenueChart();
    $reservationsChart = Report::getReservationsChart();
    $activities = Report::getActivities(15);
    $alerts = Report::getAlerts();
    $orgChart = Report::getOrgChart();
} catch (Exception $e) {
    $summary = [];
    $revenueChart = [];
    $reservationsChart = [];
    $activities = [];
    $alerts = [];
    $orgChart = [];
    Logger::error('Dashboard data load failed: ' . $e->getMessage());
}

$kpi = $summary['kpi'] ?? [];
$tableCounts = $summary['tableCounts'] ?? [];
$systemName = $summary['systemName'] ?? 'جاکجاست';
$currentDate = $summary['currentDate'] ?? Jalali::today();
$currentTime = $summary['currentTime'] ?? date('H:i');
$dayName = $summary['dayName'] ?? '';
?>

<!-- Meta tag برای BASE_URL در JS -->
<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">

  <div id="page-dashboard" class="page active">

    <!-- ===========================
         خوش‌آمدگویی و تاریخ
         =========================== -->
    <div class="jj-pagehead">
      <div>
        <h2 class="jj-title">👋 سلام <?= Helpers::e($currentUser['full_name'] ?? 'کاربر') ?></h2>
        <div class="jj-sub"><?= Helpers::e($dayName) ?>، <?= Helpers::e($currentDate) ?> · ساعت <?= Helpers::e($currentTime) ?></div>
      </div>
      <button class="btn btn-light btn-sm" onclick="refreshDashboard()" id="refresh-btn">🔄 بروزرسانی</button>
    </div>

    <?php
      try { $jjToday = Jalali::fromGregorian(date('Y-m-d'), 'Y/m/d'); $jjCallStats = Call::getStats($jjToday, $jjToday); }
      catch (Throwable $e) { $jjCallStats = []; }
    ?>
    <div class="jj-sub" style="margin:0 0 8px;font-weight:700;color:var(--jj-ink)">امروز در یک نگاه</div>
    <div class="jj-tiles">
      <a class="jj-tile" href="<?= BASE_URL ?>/index.php?page=calls&range=today">
        <span class="jj-tile-label">📞 تماس‌های امروز</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($jjCallStats['total'] ?? ($kpi['todayCalls'] ?? 0)) ?></span>
        <span class="jj-tile-hint">پاسخ‌داده <?= Helpers::toPersianDigits($jjCallStats['answered'] ?? 0) ?> · نرخ <?= Helpers::toPersianDigits($jjCallStats['answer_rate'] ?? 0) ?>٪</span>
      </a>
      <a class="jj-tile bad" href="<?= BASE_URL ?>/index.php?page=calls&range=today&type=missed">
        <span class="jj-tile-label">⏰ بی‌پاسخِ باز امروز</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($jjCallStats['missed_open'] ?? 0) ?></span>
        <span class="jj-tile-hint">برای پیگیری کلیک کنید</span>
      </a>
      <a class="jj-tile" href="<?= BASE_URL ?>/index.php?page=leads">
        <span class="jj-tile-label">🎯 سرنخ‌های فعال</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($kpi['activeLeads'] ?? 0) ?></span>
        <span class="jj-tile-hint">مشاهده سرنخ‌ها</span>
      </a>
      <a class="jj-tile" href="<?= BASE_URL ?>/index.php?page=tickets">
        <span class="jj-tile-label">🎫 تیکت‌های باز</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($kpi['openTickets'] ?? 0) ?></span>
        <span class="jj-tile-hint">مشاهده تیکت‌ها</span>
      </a>
      <a class="jj-tile" href="<?= BASE_URL ?>/index.php?page=tasks">
        <span class="jj-tile-label">✅ تسک‌ها</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($tableCounts['tasks'] ?? 0) ?></span>
        <span class="jj-tile-hint">مشاهده تسک‌ها</span>
      </a>
    </div>
    <div class="jj-sub" style="margin:4px 0 8px;font-weight:700;color:var(--jj-ink)">کسب‌وکار</div>

    <!-- ===========================
         کارت‌های KPI
         =========================== -->
    <div class="kpi-grid jj-kpi6">
      <div class="kpi-card red">
        <div class="kpi-icon">👥</div>
        <div class="kpi-value" id="kpi-customers"><?= Helpers::toPersianDigits($kpi['totalCustomers'] ?? 0) ?></div>
        <div class="kpi-label">مشتریان فعال</div>
        <div class="kpi-change up" id="kpi-customers-change">
            <?php if (($kpi['lastMonthCustomers'] ?? 0) > 0): ?>
                ↑ <?= Helpers::toPersianDigits($kpi['lastMonthCustomers']) ?> مشتری جدید در ماه اخیر
            <?php else: ?>
                - بدون تغییر
            <?php endif; ?>
        </div>
      </div>
      <div class="kpi-card green">
        <div class="kpi-icon">🏠</div>
        <div class="kpi-value" id="kpi-hosts"><?= Helpers::toPersianDigits($kpi['totalHosts'] ?? 0) ?></div>
        <div class="kpi-label">میزبانان فعال</div>
        <div class="kpi-change up">
            <?= Helpers::toPersianDigits($tableCounts['hosts'] ?? 0) ?> میزبان کل
        </div>
      </div>
      <div class="kpi-card blue">
        <div class="kpi-icon">💼</div>
        <div class="kpi-value" id="kpi-staff"><?= Helpers::toPersianDigits($kpi['totalStaff'] ?? 0) ?></div>
        <div class="kpi-label">کل پرسنل</div>
        <div class="kpi-change up">
            <?= Helpers::toPersianDigits($tableCounts['staff'] ?? 0) ?> پرسنل کل
        </div>
      </div>
      <div class="kpi-card orange">
        <div class="kpi-icon">💵</div>
        <div class="kpi-value" id="kpi-revenue"><?= Helpers::toPersianDigits(Helpers::formatNumber($kpi['monthlyRevenue'] ?? 0)) ?></div>
        <div class="kpi-label">مجموع خریدها (تومان)</div>
        <div class="kpi-change up">
            میانگین: <?= Helpers::toPersianDigits(Helpers::formatNumber(
                ($kpi['totalCustomers'] ?? 0) > 0
                    ? intval(($kpi['monthlyRevenue'] ?? 0) / $kpi['totalCustomers'])
                    : 0
            )) ?> ت / مشتری
        </div>
      </div>
      <div class="kpi-card purple">
        <div class="kpi-icon">⭐</div>
        <div class="kpi-value" id="kpi-rating"><?= Helpers::toPersianDigits($kpi['avgRating'] ?? 0) ?></div>
        <div class="kpi-label">میانگین رضایت میزبانان</div>
        <div class="kpi-change up">از ۵ امتیاز</div>
      </div>
      <div class="kpi-card yellow">
        <div class="kpi-icon">📈</div>
        <div class="kpi-value" id="kpi-growth"><?= Helpers::toPersianDigits($kpi['growthRate'] ?? 0) ?>٪</div>
        <div class="kpi-label">نرخ رشد ماهانه</div>
        <div class="kpi-change <?= ($kpi['growthRate'] ?? 0) >= 0 ? 'up' : 'down' ?>">
            <?= ($kpi['growthRate'] ?? 0) >= 0 ? '↑' : '↓' ?> نسبت به ماه قبل
        </div>
      </div>
    </div>

    <!-- ===========================
         نمودارها
         =========================== -->
    <div class="grid-2" style="margin-bottom:24px">
      <div class="card">
        <div class="card-header">
          <h4>📊 درآمد ۱۲ ماه اخیر</h4>
          <span class="badge badge-primary">تومان</span>
        </div>
        <div class="bar-chart-wrapper">
          <div class="bar-chart" id="revenue-chart">
              <?php if (empty($revenueChart)): ?>
                  <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
                      📭 هنوز داده‌ای ثبت نشده است
                  </div>
              <?php else: ?>
                <?php
                $maxRev = max(array_column($revenueChart, 'value')) ?: 1;
                foreach ($revenueChart as $bar):
                    $height = $bar['value'] > 0 ? max(8, round(($bar['value'] / $maxRev) * 160)) : 4;
                ?>
                  <div class="bar-group">
                    <div class="bar" style="height:<?= $height ?>px" title="<?= Helpers::e($bar['month']) ?>: <?= Helpers::formatNumber($bar['value']) ?> تومان">
                      <div class="bar-tooltip"><?= Helpers::e($bar['month']) ?>: <?= Helpers::toPersianDigits(Helpers::formatNumber($bar['value'] / 1000000)) ?>M</div>
                    </div>
                    <div class="bar-label"><?= Helpers::e(mb_substr($bar['month'], 0, 3)) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <h4>📅 مشتریان جدید ماهانه</h4>
          <span class="badge badge-info">تعداد</span>
        </div>
        <div class="bar-chart-wrapper">
          <div class="bar-chart" id="reservation-chart">
              <?php if (empty($reservationsChart)): ?>
                  <div style="text-align:center;padding:60px 20px;color:var(--text-muted)">
                      📭 هنوز داده‌ای ثبت نشده است
                  </div>
              <?php else: ?>
                <?php
                $maxRes = max(array_column($reservationsChart, 'value')) ?: 1;
                foreach ($reservationsChart as $bar):
                    $height = $bar['value'] > 0 ? max(8, round(($bar['value'] / $maxRes) * 160)) : 4;
                ?>
                  <div class="bar-group">
                    <div class="bar" style="height:<?= $height ?>px;background:linear-gradient(to top,#17a2b8,#74d0e4)" title="<?= Helpers::e($bar['month']) ?>: <?= $bar['value'] ?>">
                      <div class="bar-tooltip"><?= Helpers::e($bar['month']) ?>: <?= Helpers::toPersianDigits($bar['value']) ?></div>
                    </div>
                    <div class="bar-label"><?= Helpers::e(mb_substr($bar['month'], 0, 3)) ?></div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ===========================
         فعالیت‌ها و هشدارها
         =========================== -->
    <div class="grid-2">
      <div class="card">
        <div class="card-header">
          <h4>🕐 آخرین فعالیت‌ها</h4>
          <button class="btn btn-light btn-sm" onclick="refreshActivities()">🔄 بروزرسانی</button>
        </div>
        <div id="activity-list" class="activity-list" style="max-height:400px;overflow-y:auto">
            <?php if (empty($activities)): ?>
                <div style="text-align:center;padding:40px 20px;color:var(--text-muted)">
                    📭 هنوز فعالیتی ثبت نشده است
                </div>
            <?php else: ?>
                <?php foreach ($activities as $a): ?>
                <div class="activity-item">
                    <div class="ai-icon" style="background:<?= $a['color'] ?>22;color:<?= $a['color'] ?>"><?= $a['icon'] ?></div>
                    <div class="ai-content">
                        <div class="ai-text"><?= Helpers::e($a['text']) ?></div>
                        <div class="ai-time" title="<?= Helpers::e($a['timeJalali'] ?? '') ?>"><?= Helpers::e($a['timeAgo'] ?? '-') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
      </div>
      <div>
        <!-- هشدارها -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <h4>⚠️ هشدارها و اعلان‌ها</h4>
            <span class="badge badge-danger"><?= count($alerts) ?></span>
          </div>
          <div class="card-body" id="alerts-list">
              <?php if (empty($alerts)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted)">
                    ✅ همه چیز مرتب است! هشداری وجود ندارد.
                </div>
              <?php else: ?>
                <?php foreach ($alerts as $alert): ?>
                <div class="alert-item <?= Helpers::e($alert['type']) ?>">
                    <div class="ai-icon"><?= $alert['icon'] ?></div>
                    <div class="ai-text"><?= Helpers::e($alert['text']) ?></div>
                    <div class="ai-action" onclick="navigateTo('<?= Helpers::e($alert['page']) ?>')"><?= Helpers::e($alert['action']) ?> ←</div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
          </div>
        </div>

        <!-- نمودار سازمانی مینی -->
        <div class="card">
          <div class="card-header">
            <h4>🏢 نمودار سازمانی</h4>
            <button class="btn btn-light btn-sm" onclick="navigateTo('staff')">مشاهده کامل →</button>
          </div>
          <div id="org-chart-container" style="overflow-x:auto;padding:16px;font-size:12px;max-height:300px;overflow-y:auto">
              <?php if (empty($orgChart)): ?>
                <div style="text-align:center;padding:30px;color:var(--text-muted)">
                    📭 هنوز پرسنلی ثبت نشده است
                </div>
              <?php else: ?>
                <?php
                function renderOrgNode($node, $depth = 0) {
                    $indent = $depth * 20;
                    $color = Helpers::avatarColor($node['name']);
                    $initials = Helpers::initials($node['name']);
                    echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;padding:6px;border-radius:6px;background:' . ($depth === 0 ? 'rgba(229,2,65,0.08)' : 'var(--bg-secondary)') . ';margin-right:' . $indent . 'px">';
                    echo '<span style="width:24px;height:24px;border-radius:50%;background:' . $color . ';color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:11px">' . $initials . '</span>';
                    echo '<div>';
                    echo '<div style="font-weight:600">' . Helpers::e($node['name']) . '</div>';
                    if (!empty($node['role'])) echo '<div style="font-size:11px;color:var(--text-muted)">' . Helpers::e($node['role']) . (!empty($node['unit']) ? ' - ' . Helpers::e($node['unit']) : '') . '</div>';
                    echo '</div>';
                    echo '</div>';
                    foreach ($node['children'] as $child) {
                        renderOrgNode($child, $depth + 1);
                    }
                }
                foreach ($orgChart as $root) {
                    renderOrgNode($root);
                }
                ?>
              <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

  </div>

</main>

<script>
// ===========================
// توابع داشبورد
// ===========================

/**
 * بروزرسانی کامل داشبورد از API
 */
async function refreshDashboard() {
    const btn = document.getElementById('refresh-btn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '🔄 در حال بروزرسانی...';
    }

    try {
        const result = await fetchAPI('dashboard/summary');
        if (result.success && result.data) {
            updateKPIs(result.data.summary?.kpi || {}, result.data.summary?.tableCounts || {});
            updateRevenueChart(result.data.revenue || []);
            updateReservationsChart(result.data.reservations || []);
            updateActivities(result.data.activities || []);
            updateAlerts(result.data.alerts || []);
            showToast('داشبورد بروزرسانی شد', 'success');
        }
    } catch (err) {
        console.error('Dashboard refresh failed:', err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '🔄 بروزرسانی';
        }
    }
}

/**
 * بروزرسانی کارت‌های KPI
 */
function updateKPIs(kpi, tableCounts) {
    const set = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = toPersianDigits(val);
    };
    set('kpi-customers', kpi.totalCustomers || 0);
    set('kpi-hosts', kpi.totalHosts || 0);
    set('kpi-staff', kpi.totalStaff || 0);
    set('kpi-revenue', formatNumber(kpi.monthlyRevenue || 0));
    set('kpi-rating', kpi.avgRating || 0);
    set('kpi-growth', (kpi.growthRate || 0) + '٪');

    // بروزرسانی متن تغییرات
    const changeEl = document.getElementById('kpi-customers-change');
    if (changeEl) {
        changeEl.textContent = (kpi.lastMonthCustomers || 0) > 0
            ? '↑ ' + toPersianDigits(kpi.lastMonthCustomers) + ' مشتری جدید در ماه اخیر'
            : '- بدون تغییر';
    }
}

/**
 * بروزرسانی نمودار درآمد
 */
function updateRevenueChart(data) {
    const container = document.getElementById('revenue-chart');
    if (!container) return;
    if (!data || data.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">📭 هنوز داده‌ای ثبت نشده است</div>';
        return;
    }
    const max = Math.max(...data.map(d => d.value)) || 1;
    container.innerHTML = data.map(d => {
        const height = d.value > 0 ? Math.max(8, Math.round((d.value / max) * 160)) : 4;
        return `
            <div class="bar-group">
                <div class="bar" style="height:${height}px" title="${d.month}: ${formatNumber(d.value)} تومان">
                    <div class="bar-tooltip">${d.month}: ${toPersianDigits(formatNumber(d.value / 1000000))}M</div>
                </div>
                <div class="bar-label">${d.month.slice(0, 3)}</div>
            </div>
        `;
    }).join('');
}

/**
 * بروزرسانی نمودار رزروها
 */
function updateReservationsChart(data) {
    const container = document.getElementById('reservation-chart');
    if (!container) return;
    if (!data || data.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:60px 20px;color:var(--text-muted)">📭 هنوز داده‌ای ثبت نشده است</div>';
        return;
    }
    const max = Math.max(...data.map(d => d.value)) || 1;
    container.innerHTML = data.map(d => {
        const height = d.value > 0 ? Math.max(8, Math.round((d.value / max) * 160)) : 4;
        return `
            <div class="bar-group">
                <div class="bar" style="height:${height}px;background:linear-gradient(to top,#17a2b8,#74d0e4)" title="${d.month}: ${d.value}">
                    <div class="bar-tooltip">${d.month}: ${toPersianDigits(d.value)}</div>
                </div>
                <div class="bar-label">${d.month.slice(0, 3)}</div>
            </div>
        `;
    }).join('');
}

/**
 * بروزرسانی لیست فعالیت‌ها
 */
function updateActivities(activities) {
    const container = document.getElementById('activity-list');
    if (!container) return;
    if (!activities || activities.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:40px 20px;color:var(--text-muted)">📭 هنوز فعالیتی ثبت نشده است</div>';
        return;
    }
    container.innerHTML = activities.map(a => `
        <div class="activity-item">
            <div class="ai-icon" style="background:${a.color}22;color:${a.color}">${a.icon}</div>
            <div class="ai-content">
                <div class="ai-text">${escapeHtml(a.text)}</div>
                <div class="ai-time" title="${a.timeJalali || ''}">${a.timeAgo || '-'}</div>
            </div>
        </div>
    `).join('');
}

/**
 * بروزرسانی لیست هشدارها
 */
function updateAlerts(alerts) {
    const container = document.getElementById('alerts-list');
    if (!container) return;
    if (!alerts || alerts.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted)">✅ همه چیز مرتب است! هشداری وجود ندارد.</div>';
        return;
    }
    container.innerHTML = alerts.map(a => `
        <div class="alert-item ${a.type}">
            <div class="ai-icon">${a.icon}</div>
            <div class="ai-text">${escapeHtml(a.text)}</div>
            <div class="ai-action" onclick="navigateTo('${a.page}')">${a.action} ←</div>
        </div>
    `).join('');
}

/**
 * بروزرسانی فقط فعالیت‌ها
 */
async function refreshActivities() {
    try {
        const result = await fetchAPI('dashboard/activities?limit=15');
        if (result.success) {
            updateActivities(result.data || []);
            showToast('فعالیت‌ها بروزرسانی شد', 'info');
        }
    } catch (err) {
        console.error(err);
    }
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// بروزرسانی خودکار هر ۵ دقیقه
setInterval(() => {
    refreshActivities();
}, 5 * 60 * 1000);
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
