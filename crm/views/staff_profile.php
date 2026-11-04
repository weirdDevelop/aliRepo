<?php
/**
 * جاکجاست - پروفایل پرسنل
 * Jakojast - Staff Profile Page
 *
 * ۱۰ تب: اطلاعات / حقوق / حضور / تسک / ارزیابی / تیکت / تایم‌لاین
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$staffId = (int)($_GET['id'] ?? 0);
if (!$staffId) {
    echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
            <h2>شناسه پرسنل مشخص نیست</h2>
            <a href="' . BASE_URL . '/index.php?page=staff" class="btn btn-primary" style="margin-top:16px">بازگشت</a>
          </div></main>';
    require __DIR__ . '/parts/footer.php';
    exit;
}

try {
    $staff = Staff::find($staffId);
    if (!$staff) {
        echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
                <h2>پرسنل یافت نشد</h2>
                <a href="' . BASE_URL . '/index.php?page=staff" class="btn btn-primary" style="margin-top:16px">بازگشت</a>
              </div></main>';
        require __DIR__ . '/parts/footer.php';
        exit;
    }
    $salaries = Payroll::getByStaff($staffId);
    $attendance = Staff::getAttendance($staffId);
    $tasks = Staff::getTasks($staffId);
    $evaluations = Evaluation::getByStaff($staffId);
    $tickets = Staff::getTickets($staffId);
    $leaves = Leave::getByStaff($staffId);
    $avgScore = Evaluation::getAverageScore($staffId);
    $staffReports = StaffReport::forStaff($staffId, 30);
} catch (Exception $e) {
    Logger::error('Staff profile load failed: ' . $e->getMessage());
    $staff = null; $salaries = []; $attendance = []; $tasks = []; $evaluations = []; $tickets = []; $leaves = []; $avgScore = 0; $staffReports = [];
}

// رکوردهای حضور امروز (ممکن است چند نوبت باشد)
$todayAttendance = null; // آخرین نوبت امروز (برای نمایش خلاصه)
$todaySessions = [];
$hasOpenSession = false;
$today = date('Y-m-d');
foreach ($attendance as $a) {
    if ($a['date'] === $today) {
        $todaySessions[] = $a;
        if ($todayAttendance === null) $todayAttendance = $a;
        if (!empty($a['in_time']) && empty($a['out_time'])) $hasOpenSession = true;
    }
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-staff_profile" class="page active">
    <div class="card">
      <div class="profile-header">
        <div class="avatar" style="width:80px;height:80px;font-size:32px;background:<?= Helpers::avatarColor($staff['name']) ?>">
          <?= Helpers::initials($staff['name']) ?>
        </div>
        <div class="ph-info">
          <h2><?= Helpers::e($staff['name']) ?> <span style="color:var(--text-muted);font-size:14px">(<?= Helpers::e($staff['code']) ?>)</span></h2>
          <p style="color:var(--text-secondary)"><?= Helpers::e($staff['role'] ?: '-') ?> - <?= Helpers::e($staff['unit'] ?: '-') ?></p>
          <div style="display:flex;gap:8px;align-items:center;margin-top:4px;flex-wrap:wrap">
            <span class="badge <?= $staff['status'] === 'active' ? 'badge-success' : ($staff['status'] === 'leave' ? 'badge-warning' : 'badge-danger') ?>">
              <?= $staff['status'] === 'active' ? 'فعال' : ($staff['status'] === 'leave' ? 'در مرخصی' : 'غایب') ?>
            </span>
            <span class="badge badge-info">داخلی: <?= Helpers::e($staff['exten_number'] ?: '-') ?></span>
            <span class="badge badge-secondary">میانگین ارزیابی: <?= Helpers::toPersianDigits($avgScore) ?>/۱۰۰</span>
          </div>
        </div>
        <div class="ph-actions">
          <a href="<?= BASE_URL ?>/index.php?page=staff" class="btn btn-light btn-sm">← بازگشت</a>
          <button class="btn btn-primary btn-sm" onclick="showToast('ویرایش از صفحه پرسنل','info')">✏️ ویرایش</button>
        </div>
      </div>

      <!-- اطلاعات سریع -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)">
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">حقوق پایه</div>
          <div class="fw-700 text-success mt-1"><?= Helpers::toPersianDigits(Helpers::formatNumber($staff['base_salary'])) ?> ت</div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">تاریخ استخدام</div>
          <div class="fw-700 mt-1"><?= Helpers::e($staff['hire_date'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">سرپرست</div>
          <div class="fw-700 mt-1"><?= Helpers::e($staff['supervisor_name'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center">
          <div class="text-muted fs-12">زیرمجموعه‌ها</div>
          <div class="fw-700 text-info mt-1"><?= Helpers::toPersianDigits(count($staff['subordinates'] ?? [])) ?></div>
        </div>
      </div>

      <div class="tabs-wrapper">
        <button class="tab-btn active" onclick="switchTab('sp','info',this)">👤 اطلاعات</button>
        <button class="tab-btn" onclick="switchTab('sp','salary',this)">💰 حقوق</button>
        <button class="tab-btn" onclick="switchTab('sp','attendance',this)">📋 حضور</button>
        <button class="tab-btn" onclick="switchTab('sp','tasks',this)">✅ تسک‌ها</button>
        <button class="tab-btn" onclick="switchTab('sp','evaluation',this)">📊 ارزیابی</button>
        <button class="tab-btn" onclick="switchTab('sp','leaves',this)">🌴 مرخصی‌ها</button>
        <button class="tab-btn" onclick="switchTab('sp','tickets',this)">🎫 تیکت‌ها</button>
        <button class="tab-btn" onclick="switchTab('sp','reports',this); loadStaffCallMetrics()">📊 گزارش‌ها</button>
      </div>

      <!-- تب اطلاعات -->
      <div id="sp-info" class="tab-content active">
        <div style="padding:20px">
          <div class="grid-2">
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">نام کامل</div>
              <div class="fw-700"><?= Helpers::e($staff['name']) ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">کد پرسنلی</div>
              <div class="fw-700"><?= Helpers::e($staff['code']) ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">موبایل</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($staff['mobile'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">ایمیل</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($staff['email'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">کد ملی</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($staff['national_code'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">شماره داخلی</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($staff['exten_number'] ?: '-') ?></div>
            </div>
          </div>

          <!-- زیرمجموعه‌ها -->
          <?php if (!empty($staff['subordinates'])): ?>
          <div style="margin-top:16px">
            <h4 style="margin-bottom:8px">👥 زیرمجموعه‌ها (<?= count($staff['subordinates']) ?>)</h4>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <?php foreach ($staff['subordinates'] as $sub): ?>
                <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$sub['id'] ?>" style="display:flex;align-items:center;gap:6px;padding:6px 12px;background:var(--bg-secondary);border-radius:20px;text-decoration:none;color:var(--text);font-size:13px">
                  <span style="width:24px;height:24px;border-radius:50%;background:<?= Helpers::avatarColor($sub['name']) ?>;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700">
                    <?= Helpers::initials($sub['name']) ?>
                  </span>
                  <?= Helpers::e($sub['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب حقوق -->
      <div id="sp-salary" class="tab-content">
        <div style="padding:20px">
          <h4 style="margin-bottom:16px">💰 تاریخچه حقوق</h4>
          <?php if (empty($salaries)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">💰 فیش حقوقی ثبت نشده است</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr><th>ماه</th><th>پایه</th><th>مزایا</th><th>پاداش</th><th>کسورات</th><th>خالص</th><th>وضعیت</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($salaries as $s):
                    $benefits = $s['housing'] + $s['bonus_food'] + $s['grocery'] + $s['performance'] + $s['overtime'];
                    $deductions = $s['insurance'] + $s['tax'] + $s['loan'];
                  ?>
                    <tr>
                      <td><strong><?= Helpers::e($s['month']) ?></strong></td>
                      <td><?= Helpers::toPersianDigits(Helpers::formatNumber($s['base'])) ?></td>
                      <td class="text-success"><?= Helpers::toPersianDigits(Helpers::formatNumber($benefits)) ?></td>
                      <td class="text-success"><?= Helpers::toPersianDigits(Helpers::formatNumber($s['performance'] + $s['overtime'])) ?></td>
                      <td class="text-danger">-<?= Helpers::toPersianDigits(Helpers::formatNumber($deductions)) ?></td>
                      <td><strong><?= Helpers::toPersianDigits(Helpers::formatNumber($s['net'])) ?></strong> ت</td>
                      <td>
                        <span class="badge <?= $s['paid'] ? 'badge-success' : 'badge-warning' ?>">
                          <?= $s['paid'] ? 'پرداخت شده' : 'در انتظار' ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب حضور -->
      <div id="sp-attendance" class="tab-content">
        <div style="padding:20px">
          <!-- ثبت ورود/خروج -->
          <div style="background:var(--bg-secondary);padding:16px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
            <div>
              <div style="font-weight:600">📅 امروز: <?= Jalali::today() ?></div>
              <?php if (!empty($todaySessions)): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
                  <?php foreach ($todaySessions as $i => $s): ?>
                    نوبت <?= Helpers::toPersianDigits(count($todaySessions) - $i) ?>: ورود <strong dir="ltr"><?= Helpers::e($s['in_time'] ?: '-') ?></strong> — خروج <strong dir="ltr"><?= Helpers::e($s['out_time'] ?: (($s['in_time'] && !$s['out_time']) ? 'در حال کار...' : '-')) ?></strong><?= $i < count($todaySessions) - 1 ? '<br>' : '' ?>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px">هنوز ورود ثبت نشده</div>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-success btn-sm" onclick="logAttendance('in')" id="btn-attend-in" <?= $hasOpenSession ? 'disabled' : '' ?>>📥 ثبت ورود (نوبت جدید)</button>
              <button class="btn btn-danger btn-sm" onclick="logAttendance('out')" id="btn-attend-out" <?= !$hasOpenSession ? 'disabled' : '' ?>>📤 ثبت خروج</button>
            </div>
          </div>

          <h4 style="margin-bottom:16px">📋 تاریخچه حضور و غیاب</h4>
          <?php if (empty($attendance)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">📋 سابقه‌ای ثبت نشده</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>تاریخ</th><th>ورود</th><th>خروج</th><th>ساعت کار</th><th>وضعیت</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($attendance, 0, 30) as $a):
                    $hours = '-';
                    if (!empty($a['in_time']) && !empty($a['out_time'])) {
                        $in = strtotime($a['in_time']);
                        $out = strtotime($a['out_time']);
                        $diff = ($out - $in) / 3600;
                        $hours = number_format($diff, 1) . ' ساعت';
                    }
                  ?>
                    <tr>
                      <td><?= Helpers::e(Jalali::fromGregorian($a['date'], 'Y/m/d')) ?></td>
                      <td dir="ltr"><?= Helpers::e($a['in_time'] ?: '-') ?></td>
                      <td dir="ltr"><?= Helpers::e($a['out_time'] ?: '-') ?></td>
                      <td><?= Helpers::e($hours) ?></td>
                      <td><span class="badge badge-success"><?= Helpers::e($a['status']) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب تسک‌ها -->
      <div id="sp-tasks" class="tab-content">
        <div style="padding:20px">
          <h4 style="margin-bottom:16px">✅ تسک‌های <?= Helpers::e($staff['name']) ?></h4>
          <?php if (empty($tasks)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">✅ تسکی به این پرسنل اختصاص نیافته</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>عنوان</th><th>پروژه</th><th>اولویت</th><th>وضعیت</th><th>ددلاین</th></tr></thead>
                <tbody>
                  <?php foreach ($tasks as $t): ?>
                    <tr>
                      <td><strong><?= Helpers::e($t['title']) ?></strong></td>
                      <td><?= Helpers::e($t['project'] ?: '-') ?></td>
                      <td>
                        <span class="badge <?= $t['priority'] === 'high' ? 'badge-danger' : ($t['priority'] === 'medium' ? 'badge-warning' : 'badge-secondary') ?>">
                          <?= $t['priority'] === 'high' ? 'بالا' : ($t['priority'] === 'medium' ? 'متوسط' : 'کم') ?>
                        </span>
                      </td>
                      <td>
                        <span class="badge <?= $t['status'] === 'done' ? 'badge-success' : ($t['status'] === 'in_progress' ? 'badge-info' : 'badge-secondary') ?>">
                          <?= $t['status'] ?>
                        </span>
                      </td>
                      <td><?= Helpers::e($t['end_date'] ?: '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب ارزیابی -->
      <div id="sp-evaluation" class="tab-content">
        <div style="padding:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h4>📊 ارزیابی‌های عملکرد</h4>
            <button class="btn btn-primary btn-sm" onclick="openEvalModal()">+ ارزیابی جدید</button>
          </div>

          <div class="grid-3" style="margin-bottom:16px">
            <div class="stat-box">
              <div class="sb-value text-primary"><?= Helpers::toPersianDigits($avgScore) ?></div>
              <div class="sb-label">میانگین امتیاز</div>
            </div>
            <div class="stat-box">
              <div class="sb-value text-info"><?= Helpers::toPersianDigits(count($evaluations)) ?></div>
              <div class="sb-label">تعداد ارزیابی</div>
            </div>
            <div class="stat-box">
              <div class="sb-value text-success"><?= Helpers::toPersianDigits($evaluations ? $evaluations[0]['score'] : 0) ?></div>
              <div class="sb-label">آخرین امتیاز</div>
            </div>
          </div>

          <?php if (empty($evaluations)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">📊 ارزیابی ثبت نشده</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>ماه</th><th>امتیاز</th><th>اهداف</th><th>نقاط قوت</th><th>نقاط بهبود</th><th>ارزیاب</th></tr></thead>
                <tbody>
                  <?php foreach ($evaluations as $ev): ?>
                    <tr>
                      <td><strong><?= Helpers::e($ev['month']) ?></strong></td>
                      <td>
                        <div style="display:flex;align-items:center;gap:8px">
                          <div style="width:60px;background:var(--bg-secondary);border-radius:4px;height:8px;overflow:hidden">
                            <div style="width:<?= (int)$ev['score'] ?>%;background:<?= $ev['score'] >= 80 ? '#28a745' : ($ev['score'] >= 60 ? '#ffc107' : '#dc3545') ?>;height:100%"></div>
                          </div>
                          <strong><?= Helpers::toPersianDigits($ev['score']) ?>/۱۰۰</strong>
                        </div>
                      </td>
                      <td><?= Helpers::e($ev['goals'] ?: '-') ?></td>
                      <td class="text-success"><?= Helpers::e($ev['strengths'] ?: '-') ?></td>
                      <td class="text-danger"><?= Helpers::e($ev['weaknesses'] ?: '-') ?></td>
                      <td><?= Helpers::e($ev['evaluator'] ?: '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب مرخصی‌ها -->
      <div id="sp-leaves" class="tab-content">
        <div style="padding:20px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <h4>🌴 مرخصی‌های <?= Helpers::e($staff['name']) ?></h4>
            <button class="btn btn-primary btn-sm" onclick="openLeaveRequestModal()">+ درخواست مرخصی جدید</button>
          </div>
          <?php if (empty($leaves)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">🌴 مرخصی ثبت نشده</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>نوع</th><th>از</th><th>تا</th><th>روز</th><th>دلیل</th><th>وضعیت</th></tr></thead>
                <tbody>
                  <?php foreach ($leaves as $l): ?>
                    <tr>
                      <td><span class="badge badge-info"><?= Helpers::e($l['type']) ?></span></td>
                      <td><?= Helpers::e($l['from_date']) ?></td>
                      <td><?= Helpers::e($l['to_date']) ?></td>
                      <td><?= ($l['leave_mode'] ?? 'daily') === 'hourly' ? Helpers::toPersianDigits($l['hours']) . ' ساعت' : Helpers::toPersianDigits($l['days']) . ' روز' ?></td>
                      <td><?= Helpers::e(Helpers::truncate($l['reason'], 40)) ?></td>
                      <td>
                        <span class="badge <?= $l['status'] === 'approved' ? 'badge-success' : ($l['status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                          <?= $l['status'] === 'approved' ? 'تأیید شده' : ($l['status'] === 'pending' ? 'در انتظار' : 'رد شده') ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب تیکت‌ها -->
      <div id="sp-tickets" class="tab-content">
        <div style="padding:20px">
          <h4 style="margin-bottom:16px">🎫 تیکت‌های <?= Helpers::e($staff['name']) ?></h4>
          <?php if (empty($tickets)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">🎫 تیکتی ثبت نشده</div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>عنوان</th><th>اولویت</th><th>وضعیت</th><th>تاریخ</th></tr></thead>
                <tbody>
                  <?php foreach ($tickets as $t): ?>
                    <tr>
                      <td><?= Helpers::e($t['title']) ?></td>
                      <td><span class="badge <?= $t['priority'] === 'high' ? 'badge-danger' : 'badge-secondary' ?>"><?= $t['priority'] ?></span></td>
                      <td><span class="badge badge-secondary"><?= $t['status'] ?></span></td>
                      <td><?= Helpers::e($t['date']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب گزارش‌ها (تماس + گزارش‌های بله/دستی) -->
      <div id="sp-reports" class="tab-content">
        <div style="padding:20px">
          <h4 style="margin-bottom:12px">📞 آمار تماس (۷ روز اخیر)</h4>
          <div id="staff-call-metrics" style="margin-bottom:20px">
            <p style="color:var(--text-muted);font-size:12px">برای بارگذاری روی تب کلیک کنید...</p>
          </div>
          <h4 style="margin-bottom:12px">📝 گزارش‌های ثبت‌شده (بله / دستی)</h4>
          <?php if (empty($staffReports)): ?>
            <div style="text-align:center;padding:24px;color:var(--text-muted)">گزارشی ثبت نشده —
              <a href="<?= BASE_URL ?>/index.php?page=reports#staff-notes-section">ثبت از صفحه گزارش‌ها</a>
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead><tr><th>تاریخ</th><th>منبع</th><th>عنوان</th><th>متن</th></tr></thead>
                <tbody>
                  <?php foreach ($staffReports as $r): ?>
                    <tr>
                      <td><?= Helpers::e($r['report_date']) ?></td>
                      <td><span class="badge badge-info"><?= Helpers::e($r['source_label'] ?? $r['source']) ?></span></td>
                      <td><?= Helpers::e($r['title'] ?: '-') ?></td>
                      <td style="white-space:pre-wrap;max-width:420px"><?= Helpers::e($r['content']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>
<script>
async function loadStaffCallMetrics() {
    const box = document.getElementById('staff-call-metrics');
    if (!box || box.dataset.loaded === '1') return;
    const exten = <?= json_encode($staff['exten_number'] ?? '') ?>;
    if (!exten) {
        box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">شماره داخلی برای این پرسنل تعریف نشده.</p>';
        box.dataset.loaded = '1';
        return;
    }
    box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>';
    try {
        const result = await fetchAPI('reports/operators?period=daily&exten=' + encodeURIComponent(exten), { method: 'GET' });
        const ops = (result.data && result.data.operators) || [];
        const op = ops[0];
        if (!op) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">تماسی در ۷ روز اخیر ثبت نشده.</p>';
        } else {
            box.innerHTML = `
              <div class="grid-4" style="margin-bottom:12px">
                <div class="stat-box"><div class="sb-value text-primary">${op.total}</div><div class="sb-label">کل تماس</div></div>
                <div class="stat-box"><div class="sb-value text-success">${op.answered}</div><div class="sb-label">پاسخ</div></div>
                <div class="stat-box"><div class="sb-value text-danger">${op.missed}</div><div class="sb-label">بی‌پاسخ</div></div>
                <div class="stat-box"><div class="sb-value text-info">${op.answer_rate}٪</div><div class="sb-label">نرخ پاسخ</div></div>
              </div>
              <div style="font-size:12px;color:var(--text-muted)">مدت مکالمه: <strong>${op.talk_formatted || '-'}</strong>
                · ورودی ${op.inbound} · خروجی ${op.outbound}</div>`;
        }
        box.dataset.loaded = '1';
    } catch (e) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت آمار</p>';
    }
}
</script>

<!-- مودال ارزیابی -->
<div class="modal-overlay" id="modal-eval">
  <div class="modal-box">
    <div class="modal-header">
      <h4>📊 ارزیابی عملکرد <?= Helpers::e($staff['name']) ?></h4>
      <button class="btn-close" onclick="closeModal('modal-eval')">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">ماه ارزیابی <span style="color:red">*</span></label>
        <input type="text" class="form-control" id="eval-month" placeholder="اردیبهشت ۱۴۰۳" value="<?= Jalali::fromGregorian(date('Y-m-d'), 'Y/m') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">امتیاز (۰ تا ۱۰۰) <span style="color:red">*</span></label>
        <input type="number" class="form-control" id="eval-score" placeholder="85" min="0" max="100">
      </div>
      <div class="form-group">
        <label class="form-label">ارزیاب</label>
        <input type="text" class="form-control" id="eval-evaluator" value="<?= Helpers::e($currentUser['full_name']) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">اهداف محقق شده</label>
        <input type="text" class="form-control" id="eval-goals" placeholder="جذب ۵ مشتری جدید">
      </div>
      <div class="form-group">
        <label class="form-label">نقاط قوت</label>
        <input type="text" class="form-control" id="eval-strengths" placeholder="پیگیری قوی">
      </div>
      <div class="form-group">
        <label class="form-label">نقاط بهبود</label>
        <input type="text" class="form-control" id="eval-weaknesses" placeholder="مهارت مذاکره">
      </div>
      <div class="form-group">
        <label class="form-label">نظر ارزیاب</label>
        <textarea class="form-control" id="eval-note" rows="2" placeholder="توضیح..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('modal-eval')">انصراف</button>
      <button class="btn btn-primary" onclick="saveEvaluation()">✓ ذخیره ارزیابی</button>
    </div>
  </div>
</div>

<script>
const staffId = <?= (int)$staffId ?>;

function openEvalModal() {
    ['eval-score','eval-goals','eval-strengths','eval-weaknesses','eval-note'].forEach(id => document.getElementById(id).value = '');
    openModal('modal-eval');
}

async function saveEvaluation() {
    const data = {
        staff_id: staffId,
        month: document.getElementById('eval-month').value.trim(),
        score: parseFloat(document.getElementById('eval-score').value) || 0,
        evaluator: document.getElementById('eval-evaluator').value.trim(),
        goals: document.getElementById('eval-goals').value.trim(),
        strengths: document.getElementById('eval-strengths').value.trim(),
        weaknesses: document.getElementById('eval-weaknesses').value.trim(),
        note: document.getElementById('eval-note').value.trim(),
    };
    if (!data.month || !data.score) return showToast('ماه و امتیاز الزامی است', 'warning');
    try {
        const result = await fetchAPI('evaluations', { method: 'POST', body: data });
        if (result.success) {
            showToast('ارزیابی ثبت شد', 'success');
            closeModal('modal-eval');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

async function logAttendance(type) {
    try {
        const result = await fetchAPI(`staff/${staffId}/attendance`, { method: 'POST', body: { type } });
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 800);
        } else showToast(result.message, 'error');
    } catch (err) { showToast('خطا', 'error'); }
}

// ===========================
// درخواست مرخصی جدید (از پروفایل پرسنل)
// ===========================
function openLeaveRequestModal() {
    const html = `
      <div class="modal-overlay show" id="modal-leave-request">
        <div class="modal-box">
          <div class="modal-header">
            <h4>🌴 درخواست مرخصی جدید</h4>
            <button class="btn-close" onclick="document.getElementById('modal-leave-request').remove()">×</button>
          </div>
          <div class="modal-body" style="padding:20px">
            <div class="form-group">
              <label class="form-label">نوع مرخصی</label>
              <select class="form-control" id="lr-type">
                <option value="استحقاقی">استحقاقی</option>
                <option value="اضطراری">اضطراری</option>
                <option value="بدون حقوق">بدون حقوق</option>
                <option value="بیماری">بیماری</option>
                <option value="ازدواج">ازدواج</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">حالت مرخصی</label>
              <select class="form-control" id="lr-mode" onchange="onLeaveModeChange()">
                <option value="daily">روزانه (یک یا چند روز کامل)</option>
                <option value="hourly">ساعتی (چند ساعت از یک روز)</option>
              </select>
            </div>
            <div id="lr-daily-fields" style="display:flex;gap:8px">
              <div class="form-group" style="flex:1">
                <label class="form-label">از تاریخ</label>
                <input type="text" class="form-control jalali-date-input" id="lr-from" placeholder="1405/06/01">
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">تا تاریخ</label>
                <input type="text" class="form-control jalali-date-input" id="lr-to" placeholder="1405/06/03">
              </div>
            </div>
            <div id="lr-hourly-fields" style="display:none">
              <div class="form-group">
                <label class="form-label">تاریخ</label>
                <input type="text" class="form-control jalali-date-input" id="lr-hourly-date" placeholder="1405/06/01">
              </div>
              <div style="display:flex;gap:8px">
                <div class="form-group" style="flex:1">
                  <label class="form-label">از ساعت</label>
                  <input type="time" class="form-control" id="lr-from-time">
                </div>
                <div class="form-group" style="flex:1">
                  <label class="form-label">تا ساعت</label>
                  <input type="time" class="form-control" id="lr-to-time">
                </div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">دلیل</label>
              <textarea class="form-control" id="lr-reason" rows="3" placeholder="توضیح مختصر..."></textarea>
            </div>
            <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
              <button class="btn btn-light btn-sm" onclick="document.getElementById('modal-leave-request').remove()">انصراف</button>
              <button class="btn btn-primary btn-sm" onclick="submitLeaveRequest()">ثبت درخواست</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
}

function onLeaveModeChange() {
    const mode = document.getElementById('lr-mode').value;
    document.getElementById('lr-daily-fields').style.display = (mode === 'daily') ? 'flex' : 'none';
    document.getElementById('lr-hourly-fields').style.display = (mode === 'hourly') ? 'block' : 'none';
}

async function submitLeaveRequest() {
    const type = document.getElementById('lr-type').value;
    const mode = document.getElementById('lr-mode').value;
    const reason = document.getElementById('lr-reason').value.trim();

    let body = { staff_id: staffId, type, reason, leave_mode: mode };

    if (mode === 'hourly') {
        const date = document.getElementById('lr-hourly-date').value.trim();
        const fromTime = document.getElementById('lr-from-time').value;
        const toTime = document.getElementById('lr-to-time').value;
        if (!date || !fromTime || !toTime) {
            showToast('تاریخ و ساعت شروع/پایان الزامی است', 'error');
            return;
        }
        body.from_date = date;
        body.from_time = fromTime;
        body.to_time = toTime;
    } else {
        const fromDate = document.getElementById('lr-from').value.trim();
        const toDate = document.getElementById('lr-to').value.trim();
        if (!fromDate || !toDate) {
            showToast('تاریخ شروع و پایان الزامی است', 'error');
            return;
        }
        body.from_date = fromDate;
        body.to_date = toDate;
    }

    try {
        const result = await fetchAPI('leaves', { method: 'POST', body });
        if (result.success) {
            showToast('درخواست مرخصی ثبت شد', 'success');
            document.getElementById('modal-leave-request').remove();
            setTimeout(() => window.location.reload(), 800);
        } else {
            showToast(result.message || 'خطا در ثبت درخواست', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
