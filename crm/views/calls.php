<?php
/**
 * جاکجاست - صفحه تماس‌ها
 * Jakojast - Calls Page
 *
 * نمایش تماس‌های تلفنچی با DataTables، فیلتر تاریخ و نوع، دکمه همگام‌سازی.
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$search = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$labelFilter = $_GET['label'] ?? '';
$extenFilter = preg_replace('/[^0-9a-z,]/', '', $_GET['exten'] ?? '');
// ت۱۱: پیش‌فرض «شماره‌های یکتا» (از هر شماره آخرین تماس)؛ view=all همه‌ی تماس‌ها
$view = (($_GET['view'] ?? '') === 'all') ? 'all' : 'unique';
// ارقام فارسی/عربی در URL (اگر دستی یا از جای دیگر آمده) به انگلیسی نرمال شوند
$fromDate = Helpers::toEnglishDigits(trim($_GET['from_date'] ?? ''));
$toDate = Helpers::toEnglishDigits(trim($_GET['to_date'] ?? ''));
// ⚠️ رفع باگ سیستمیک: قبلاً از $_GET['page'] برای شماره صفحه استفاده می‌شد
// که دقیقاً همان پارامتری است که روتر اصلی (index.php) برای تشخیص
// «کدام صفحه» (customers/leads/...) استفاده می‌کند. چون در URL هر دو
// یک کلید (page) بودند، PHP فقط آخری را نگه می‌داشت و با کلیک روی
// «صفحه ۲» کل روتر گم می‌شد. الان از $_GET['p'] استفاده می‌شود.
$page = max(1, (int)($_GET['p'] ?? 1));
$pageError = '';

// بازه‌ی زمانی (پیش‌فرض: امروز). today|yesterday|week|month|year|all|custom
$rangeLabels = ['today' => 'امروز', 'yesterday' => 'دیروز', 'week' => 'این هفته', 'month' => 'این ماه', 'year' => 'امسال', 'all' => 'همه زمان‌ها', 'custom' => 'بازه‌ی دلخواه'];
$range = $_GET['range'] ?? '';
if (!isset($rangeLabels[$range])) $range = ($fromDate !== '' || $toDate !== '') ? 'custom' : 'today';
if ($range !== 'custom') {
    $jToday = Jalali::fromGregorian(date('Y-m-d'), 'Y/m/d');
    [$jy, $jm] = array_map('intval', explode('/', $jToday));
    switch ($range) {
        case 'today':     $fromDate = $toDate = $jToday; break;
        case 'yesterday': $fromDate = $toDate = Jalali::fromGregorian(date('Y-m-d', strtotime('-1 day')), 'Y/m/d'); break;
        case 'week':      // هفته‌ی ایرانی از شنبه
            $fromDate = Jalali::fromGregorian(date('Y-m-d', strtotime('-' . ((int)date('w') + 1) % 7 . ' days')), 'Y/m/d'); $toDate = $jToday; break;
        case 'month':     $fromDate = sprintf('%04d/%02d/01', $jy, $jm); $toDate = $jToday; break;
        case 'year':      $fromDate = sprintf('%04d/01/01', $jy); $toDate = $jToday; break;
        case 'all':       $fromDate = $toDate = ''; break;
    }
}
$rangeText = $rangeLabels[$range] . ($fromDate || $toDate ? ' (' . Helpers::toPersianDigits(trim(($fromDate ?: '…') . ($toDate && $toDate !== $fromDate ? ' تا ' . $toDate : ''))) . ')' : '');
$rangeQs = '&range=' . urlencode($range) . ($range === 'custom' ? '&from_date=' . urlencode($fromDate) . '&to_date=' . urlencode($toDate) : '');

try {
    // ⚠️ رفع گپ: این صفحه رندر سمت سرور مستقیم Call::all() را صدا می‌زند
    // (نه از طریق API JSON) و تا الان فیلتر محدودسازی دید (v11) را
    // نداشت - یعنی کاربر محدودشده وقتی مستقیم این صفحه را باز می‌کرد
    // (نه صفحه‌بندی/فیلتر AJAX) همه‌چیز را می‌دید.
    $result = Call::all([
        'search' => $search, 'type' => $typeFilter, 'status' => $statusFilter,
        'category' => $categoryFilter, 'label' => $labelFilter,
        'from_date' => $fromDate, 'to_date' => $toDate,
        'exten_number' => $extenFilter,
        'page' => $page, 'limit' => 20, 'unique' => $view === 'unique',
        'visible_extens' => Auth::getVisibleExtens(),
    ]);
    $calls = $result['items'];
    $total = $result['total'];
    $totalPages = max(1, ceil($total / 20));
    $stats = Call::getStats($fromDate, $toDate, Auth::getVisibleExtens());
    $extensions = Call::getExtensions();
    $refOpts = Pipeline::refOptions();
    // اپراتورها برای فیلتر: نام پرسنل بر اساس داخلی
    // ت۱۰: هر اپراتور فقط یک بار (هم‌نام‌ها یکی می‌شوند و فیلتر همه‌ی داخلی‌هایشان را شامل می‌شود)
    $opByName = []; $known = [];
    foreach (Database::getInstance()->fetchAll("SELECT exten_number, name FROM staff WHERE exten_number IS NOT NULL AND exten_number <> '' ORDER BY name") as $o) {
        $nm = trim(preg_replace('/\s+/u', ' ', (string)$o['name'])); if ($nm === '') $nm = 'داخلی ' . $o['exten_number'];
        $opByName[$nm][] = $o['exten_number']; $known[$o['exten_number']] = true;
    }
    foreach ($extensions as $e) if (!isset($known[$e['exten_number']])) {
        $nm = ($e['exten_name'] ?: 'داخلی') . ' (' . $e['exten_number'] . ')';
        $opByName[$nm][] = $e['exten_number']; $known[$e['exten_number']] = true;
    }
    $operatorOpts = [];
    foreach ($opByName as $nm => $exs) $operatorOpts[implode(',', array_unique($exs))] = $nm;
    $syncStatus = [
        'last_sync_at' => Report::getSetting('last_sync_at'),
        'is_configured' => (new TelefonchyAPI())->isConfigured(),
    ];
} catch (Exception $e) {
    $calls = []; $total = 0; $totalPages = 1; $stats = []; $extensions = []; $syncStatus = [];
    $pageError = $e->getMessage();
    Logger::error('Calls page load failed: ' . $e->getMessage());
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">
  <div id="page-calls" class="page active">
    <!-- ===== هدر ساده: عنوان + بازه‌ی زمانی ===== -->
    <div class="jj-pagehead">
      <div>
        <h2 class="jj-title">📞 تماس‌ها</h2>
        <div class="jj-sub">آمار و لیست برای: <strong><?= Helpers::e($rangeText) ?></strong>
          <?php if (!empty($syncStatus['last_sync_at'])): ?> · آخرین همگام‌سازی کامل: <?= Jalali::fromGregorian($syncStatus['last_sync_at'], 'Y/m/d H:i') ?><?php endif; ?>
          <?php if (empty($syncStatus['is_configured'])): ?> · <a href="<?= BASE_URL ?>/index.php?page=settings" class="text-danger">⚠ تلفنچی پیکربندی نشده</a><?php endif; ?>
        </div>
      </div>
      <div class="jj-range">
        <?php foreach (['today','yesterday','week','month','year','all'] as $rk): ?>
          <a href="?page=calls&range=<?= $rk ?>" class="jj-chip<?= $range === $rk ? ' on' : '' ?>"><?= $rangeLabels[$rk] ?></a>
        <?php endforeach; ?>
        <span class="jj-custom<?= $range === 'custom' ? ' on' : '' ?>">
          <input type="text" id="call-from-date" placeholder="از تاریخ" value="<?= Helpers::e($fromDate) ?>" onchange="window._jjRangeChanged=1;applyFilters()" class="jalali-date-input">
          <span>تا</span>
          <input type="text" id="call-to-date" placeholder="تا تاریخ" value="<?= Helpers::e($toDate) ?>" onchange="window._jjRangeChanged=1;applyFilters()" class="jalali-date-input">
        </span>
      </div>
    </div>

    <!-- ===== ۵ کاشی کلیدی ===== -->
    <?php $avgTalk = ($stats['answered'] ?? 0) > 0 ? (int)round(($stats['total_talk_time'] ?? 0) / $stats['answered']) : 0; ?>
    <div class="jj-tiles">
      <a href="?page=calls<?= $rangeQs ?>" class="jj-tile<?= ($typeFilter === '' && $statusFilter === '') ? ' on' : '' ?>">
        <span class="jj-tile-label">کل تماس‌ها</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($stats['total'] ?? 0) ?></span>
        <span class="jj-tile-hint">ورودی <?= Helpers::toPersianDigits($stats['inbound'] ?? 0) ?> · خروجی <?= Helpers::toPersianDigits($stats['outbound'] ?? 0) ?></span>
      </a>
      <a href="?page=calls<?= $rangeQs ?>&status=answered" class="jj-tile ok<?= $statusFilter === 'answered' ? ' on' : '' ?>">
        <span class="jj-tile-label">پاسخ‌داده‌شده</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($stats['answered'] ?? 0) ?></span>
        <span class="jj-tile-hint">نرخ پاسخ <?= Helpers::toPersianDigits($stats['answer_rate'] ?? 0) ?>٪</span>
      </a>
      <a href="?page=calls<?= $rangeQs ?>&type=missed" class="jj-tile bad<?= $typeFilter === 'missed' ? ' on' : '' ?>">
        <span class="jj-tile-label">بی‌پاسخِ باز</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($stats['missed_open'] ?? ($stats['missed'] ?? 0)) ?></span>
        <span class="jj-tile-hint">از <?= Helpers::toPersianDigits($stats['missed'] ?? 0) ?> بی‌پاسخ (بقیه پیگیری شده)</span>
      </a>
      <div class="jj-tile">
        <span class="jj-tile-label">میانگین مکالمه</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits(Call::formatDuration($avgTalk)) ?></span>
        <span class="jj-tile-hint">کل <?= Helpers::toPersianDigits(Call::formatDuration($stats['total_talk_time'] ?? 0)) ?></span>
      </div>
      <a href="#unique-numbers-section" class="jj-tile" onclick="event.preventDefault(); document.getElementById('jj-more').open = true; openUniqueNumbersSection();">
        <span class="jj-tile-label">شماره‌ی یکتا</span>
        <span class="jj-tile-val"><?= Helpers::toPersianDigits($stats['unique_callers'] ?? 0) ?></span>
        <span class="jj-tile-hint">ثبت‌نشده <?= Helpers::toPersianDigits($stats['unresolved_leads'] ?? 0) ?></span>
      </a>
    </div>

    <?php if ($pageError): ?>
    <div class="jj-alert">⚠ خطا در بارگذاری تماس‌ها: <?= Helpers::e($pageError) ?></div>
    <?php endif; ?>

    <!-- ===== پیگیری بی‌پاسخ‌ها + تماس زنده ===== -->
    <div class="jj-two">
      <div class="jj-card" id="missed-alerts-section">
        <div class="jj-card-head">
          <span>⏰ بی‌پاسخ‌ها برای پیگیری <span id="missed-alerts-count" class="badge badge-danger" style="display:none"></span></span>
          <small>۱۲ ساعت اخیر · مهلت ۲۰ دقیقه</small>
        </div>
        <div id="missed-alerts-box" class="jj-scroll"><p class="jj-muted">در حال بارگذاری...</p></div>
      </div>
      <div class="jj-card" id="live-calls-section">
        <div class="jj-card-head">
          <span>🔴 پایش زنده تماس‌ها <span id="live-calls-count" class="badge badge-danger"></span></span>
          <small>به‌روزرسانی خودکار</small>
        </div>
        <div id="live-calls-box" class="jj-scroll"><p class="jj-muted">در حال بارگذاری...</p></div>
      </div>
    </div>

    <!-- ===== جدول اصلی ===== -->
    <div class="jj-card">
      <div class="jj-card-head jj-filters">
        <div class="search-box jj-search">
          <span>🔍</span>
          <input type="text" id="call-search" placeholder="جستجوی شماره یا نام..." value="<?= Helpers::e($search) ?>" oninput="debounceSearch()">
        </div>
        <select id="call-type-filter" onchange="applyFilters()">
          <option value="">همه جهت‌ها</option>
          <option value="inbound" <?= $typeFilter === 'inbound' ? 'selected' : '' ?>>📥 ورودی</option>
          <option value="outbound" <?= $typeFilter === 'outbound' ? 'selected' : '' ?>>📤 خروجی</option>
          <option value="missed" <?= $typeFilter === 'missed' ? 'selected' : '' ?>>❌ بی‌پاسخ</option>
          <option value="internal" <?= $typeFilter === 'internal' ? 'selected' : '' ?>>🔄 داخلی</option>
        </select>
        <select id="call-status-filter" onchange="applyFilters()">
          <option value="">همه وضعیت‌ها</option>
          <option value="answered" <?= $statusFilter === 'answered' ? 'selected' : '' ?>>پاسخ داده شد</option>
          <option value="no_answer" <?= $statusFilter === 'no_answer' ? 'selected' : '' ?>>بی‌پاسخ</option>
          <option value="busy" <?= $statusFilter === 'busy' ? 'selected' : '' ?>>مشغول</option>
          <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>ناموفق</option>
          <option value="registered" <?= $statusFilter === 'registered' ? 'selected' : '' ?>>ثبت‌شده (مشتری/سرنخ)</option>
          <option value="unresolved" <?= $statusFilter === 'unresolved' ? 'selected' : '' ?>>ثبت‌نشده</option>
        </select>
        <select id="call-exten-filter" onchange="applyFilters()" title="فیلتر اپراتور">
          <option value="">همه اپراتورها</option>
          <?php foreach (($operatorOpts ?? []) as $ex => $nm): ?>
            <option value="<?= Helpers::e($ex) ?>" <?= $extenFilter === (string)$ex ? 'selected' : '' ?>>👤 <?= Helpers::e($nm) ?></option>
          <?php endforeach; ?>
          <option value="none" <?= $extenFilter === 'none' ? 'selected' : '' ?>>— بدون اپراتور (صف) —</option>
        </select>
        <span class="jj-viewtoggle">
          <a href="<?= '?page=calls&view=unique' . $rangeQs ?>" class="jj-chip<?= $view === 'unique' ? ' on' : '' ?>" title="از هر شماره فقط آخرین تماس؛ با کلیک بقیه‌ی تماس‌ها باز می‌شود">شماره‌های یکتا</a>
          <a href="<?= '?page=calls&view=all' . $rangeQs ?>" class="jj-chip<?= $view === 'all' ? ' on' : '' ?>">همه‌ی تماس‌ها</a>
        </span>
        <span class="jj-count"><?= Helpers::toPersianDigits($total) ?> <?= $view === 'unique' ? 'شماره' : 'تماس' ?></span>
        <label class="jj-toggle"><input type="checkbox" id="jj-adv-cols" onchange="document.body.classList.toggle('jj-show-adv', this.checked)"> ستون‌های بیشتر</label>
      </div>

      <div class="table-wrapper">
        <table class="data-table jj-table">
          <thead>
            <tr>
              <th>زمان</th><th>شماره / مخاطب</th><th>مرجع / نقش</th><th>جهت</th><th>وضعیت</th><th>اپراتور</th><th>مدت</th><th>خلاصه</th>
              <th class="jj-adv">دسته‌بندی</th><th class="jj-adv">لیبل</th><th class="jj-adv">مبدا → مقصد</th><th class="jj-adv">انتظار</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($calls)): ?>
              <tr><td colspan="13" class="jj-empty">📞 در این بازه تماسی پیدا نشد.</td></tr>
            <?php else: ?>
              <?php
                $typeIcon = ['inbound' => '📥', 'outbound' => '📤', 'missed' => '❌', 'internal' => '🔄'];
                $typeText = ['inbound' => 'ورودی', 'outbound' => 'خروجی', 'missed' => 'بی‌پاسخ', 'internal' => 'داخلی'];
              ?>
              <?php foreach ($calls as $c): ?>
                <?php
                  $sourceNum = $c['call_source'] ?: '';
                  $destNum = $c['call_dest'] ?: '';
                  $profileNum = ($c['type'] === 'outbound') ? $destNum : $sourceNum;
                  $statusCls = ['answered' => 'ok', 'busy' => 'warn', 'ringing' => 'info'][$c['status']] ?? 'bad';
                  $statusText = ['answered' => 'پاسخ داده شد', 'no_answer' => 'بی‌پاسخ', 'busy' => 'مشغول', 'failed' => 'ناموفق', 'ringing' => '🔔 در حال زنگ'][$c['status']] ?? $c['status'];
                  $jt = (string)($c['jalali_started_at'] ?? '-');
                ?>
                <tr>
                  <td class="jj-time" title="<?= Helpers::e($jt) ?>"><?= Helpers::e(strlen($jt) > 11 ? substr($jt, 11, 5) : $jt) ?><small><?= Helpers::e(substr($jt, 0, 10)) ?></small></td>
                  <td>
                    <?php if ($profileNum): ?>
                      <a dir="ltr" class="jj-num" href="<?= BASE_URL ?>/index.php?page=phone_detail&number=<?= urlencode($profileNum) ?>" title="تاریخچه این شماره"><?= Helpers::e($profileNum) ?></a>
                    <?php else: ?>-<?php endif; ?>
                    <?php if ($view === 'unique' && ($c['calls_in_range'] ?? 1) > 1 && !empty($c['p10'])): ?>
                      <button type="button" class="jj-more-btn" onclick="toggleNumberCalls(this, '<?= Helpers::e($c['p10']) ?>')"><?= Helpers::toPersianDigits((int)$c['calls_in_range']) ?> تماس ▾</button>
                    <?php endif; ?>
                    <small>
                    <?php if (!empty($c['number_name'])): ?>
                      <span>👤 <?= Helpers::e($c['number_name']) ?></span><?php if (!empty($c['number_stage'])): ?> <span class="jj-pill info"><?= Helpers::e(Pipeline::$stages[$c['number_stage']] ?? '') ?></span><?php endif; ?>
                    <?php elseif (!empty($c['contact_name'])): ?>
                      <a href="<?= BASE_URL ?>/index.php?page=customer_profile&id=<?= (int)$c['contact_id'] ?>">👤 <?= Helpers::e($c['contact_name']) ?></a>
                    <?php elseif (!empty($c['lead_name'])): ?>
                      <a href="<?= BASE_URL ?>/index.php?page=leads&id=<?= (int)$c['lead_id'] ?>" class="jj-lead">🎯 <?= Helpers::e($c['lead_name']) ?></a>
                    <?php else: ?>
                      <span class="jj-muted">ناشناس</span>
                    <?php endif; ?>
                    </small>
                  </td>
                  <td class="jj-ref" data-p="<?= Helpers::e($c['p10'] ?? '') ?>">
                    <?php if (!empty($c['p10'])): ?>
                      <?php if (!empty($c['is_old'])): ?><span class="jj-pill old">قدیمی</span><?php endif; ?>
                      <?php $refs = $refOpts; if (!empty($c['ref_source']) && !in_array($c['ref_source'], $refs, true)) $refs[] = $c['ref_source']; ?>
                      <?php if (!empty($c['is_old']) && !empty($c['ref_source'])): ?>
                        <small>اولین بار از «<?= Helpers::e($c['ref_source']) ?>» آشنا شد<?= !empty($c['ref_role']) ? ' · ' . Helpers::e(Pipeline::$roles[$c['ref_role']] ?? '') : '' ?></small>
                      <?php else: ?>
                        <select class="pl-ref" onchange="saveRef(this)"><option value="">مرجع؟</option><?php foreach ($refs as $rf): ?><option <?= ($c['ref_source'] ?? '') === $rf ? 'selected' : '' ?>><?= Helpers::e($rf) ?></option><?php endforeach; ?></select>
                        <select class="pl-role" onchange="saveRef(this)"><option value="">نقش؟</option><?php foreach (Pipeline::$roles as $rk => $rl): ?><option value="<?= $rk ?>" <?= ($c['ref_role'] ?? '') === $rk ? 'selected' : '' ?>><?= $rl ?></option><?php endforeach; ?></select>
                      <?php endif; ?>
                    <?php else: ?>-<?php endif; ?>
                  </td>
                  <td><span class="jj-dir"><?= $typeIcon[$c['type']] ?? '📞' ?> <?= $typeText[$c['type']] ?? Helpers::e($c['type']) ?></span></td>
                  <td><span class="jj-pill <?= $statusCls ?><?= $c['status'] === 'ringing' ? ' pulse-live' : '' ?>"><?= $statusText ?></span></td>
                  <td>
                    <?php if (!empty($c['staff_name'])): ?>
                      <a href="<?= BASE_URL ?>/index.php?page=staff_profile&id=<?= (int)$c['staff_id'] ?>"><?= Helpers::e($c['staff_name']) ?></a>
                    <?php elseif (!empty($c['exten_name'])): ?>
                      <span class="jj-muted"><?= Helpers::e($c['exten_name']) ?></span>
                    <?php else: ?><span class="jj-muted" dir="ltr"><?= Helpers::e($c['exten_number'] ?: '-') ?></span><?php endif; ?>
                  </td>
                  <td class="jj-dur"><?= Helpers::e($c['talk_formatted'] ?? '-') ?></td>
                  <td><input class="jj-sumline" maxlength="255" placeholder="خلاصه‌ی یک جمله‌ای…" value="<?= Helpers::e($c['summary_line'] ?? '') ?>" data-v="<?= Helpers::e($c['summary_line'] ?? '') ?>" data-id="<?= (int)$c['id'] ?>" onblur="saveSummary(this)" onkeydown="if(event.key==='Enter')this.blur()"></td>
                  <td class="jj-adv">
                    <select class="call-category-select" data-call-id="<?= (int)$c['id'] ?>" data-original="<?= Helpers::e($c['call_category'] ?? 'unclassified') ?>" onchange="updateCallCategory(this)" onclick="event.stopPropagation()">
                      <?php foreach (Call::$callCategoryLabels as $catKey => $catLabel): ?>
                        <option value="<?= Helpers::e($catKey) ?>" <?= ($c['call_category'] ?? 'unclassified') === $catKey ? 'selected' : '' ?>><?= Helpers::e($catLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="jj-adv">
                    <select class="call-label-select" data-call-id="<?= (int)$c['id'] ?>" data-original="<?= Helpers::e($c['call_label'] ?? 'none') ?>" onchange="updateCallLabel(this)" onclick="event.stopPropagation()">
                      <?php foreach (Call::$callLabelLabels as $lbKey => $lbLabel): ?>
                        <option value="<?= Helpers::e($lbKey) ?>" <?= ($c['call_label'] ?? 'none') === $lbKey ? 'selected' : '' ?>><?= Helpers::e($lbLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="jj-adv" dir="ltr"><?= Helpers::e($sourceNum ?: '-') ?> → <?= Helpers::e($destNum ?: '-') ?><?= $c['exten_number'] ? ' (' . Helpers::e($c['exten_number']) . ')' : '' ?></td>
                  <td class="jj-adv"><?= Helpers::e($c['wait_formatted'] ?? '-') ?></td>
                  <td class="jj-actions">
                    <?php if (!empty($c['file_record'])): ?>
                      <button class="jj-btn" title="پخش صوت" onclick="playRecording(<?= (int)$c['id'] ?>)">▶</button>
                    <?php endif; ?>
                    <a class="jj-btn" href="<?= BASE_URL ?>/index.php?page=call_detail&id=<?= (int)$c['id'] ?>" title="جزئیات و متن مکالمه">📝</a>
                    <?php if ($profileNum && $c['status'] !== 'answered'): ?>
                      <a class="jj-btn call" href="tel:<?= Helpers::e(preg_replace('/[^0-9+]/', '', $profileNum)) ?>" title="تماس مجدد">📞</a>
                    <?php endif; ?>
                    <?php if (!empty($c['lead_id'])): ?>
                      <a class="jj-btn jj-adv-inline" href="<?= BASE_URL ?>/index.php?page=leads&id=<?= (int)$c['lead_id'] ?>" title="مشاهده سرنخ">🎯</a>
                    <?php elseif ($c['call_source'] || $c['call_dest']): ?>
                      <button class="jj-btn jj-adv-inline" title="تبدیل به سرنخ" onclick="convertToLead(<?= (int)$c['id'] ?>)">➕</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1):
        $pq = '?page=calls&search=' . urlencode($search) . '&type=' . urlencode($typeFilter) . '&status=' . urlencode($statusFilter) . '&category=' . urlencode($categoryFilter) . '&label=' . urlencode($labelFilter) . '&exten=' . urlencode($extenFilter) . '&view=' . $view . '&from_date=' . urlencode($fromDate) . '&to_date=' . urlencode($toDate) . '&range=' . urlencode($range) . '&p=';
      ?>
      <div class="pagination jj-pages">
        <?php if ($page > 1): ?><a href="<?= $pq . ($page - 1) ?>" class="page-btn">« قبلی</a><?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <a href="<?= $pq . $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= Helpers::toPersianDigits($p) ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?= $pq . ($page + 1) ?>" class="page-btn">بعدی »</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ===== بیشتر (ابزارهای پیشرفته) ===== -->
    <details class="jj-more" id="jj-more">
      <summary>⚙️ بیشتر: همگام‌سازی، خروجی، فیلترهای پیشرفته، شماره‌های یکتا، آمار داخلی‌ها</summary>
      <div class="jj-more-body">
        <div class="jj-row">
          <button class="btn btn-primary btn-sm" onclick="openSyncModal()" id="sync-btn">🔄 همگام‌سازی با تلفنچی</button>
          <button class="btn btn-light btn-sm" onclick="autoConvertToLeads()" id="auto-lead-btn" title="شماره‌های تکراری بدون مشتری/سرنخ را به سرنخ تبدیل می‌کند">🎯 تبدیل خودکار به سرنخ</button>
          <button class="btn btn-light btn-sm" onclick="exportCalls()">⬇ خروجی اکسل</button>
        </div>
        <div class="jj-row">
          <select id="call-category-filter" onchange="applyFilters()">
            <option value="">همه دسته‌بندی‌ها</option>
            <?php foreach (Call::$callCategoryLabels as $catKey => $catLabel): ?>
              <option value="<?= Helpers::e($catKey) ?>" <?= $categoryFilter === $catKey ? 'selected' : '' ?>><?= Helpers::e($catLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="call-label-filter" onchange="applyFilters()">
            <option value="">همه لیبل‌ها</option>
            <?php foreach (Call::$callLabelLabels as $lbKey => $lbLabel): ?>
              <option value="<?= Helpers::e($lbKey) ?>" <?= $labelFilter === $lbKey ? 'selected' : '' ?>><?= Helpers::e($lbLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="settings-section" id="unique-numbers-section">
          <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleUniqueNumbers()">
            <span>📇 شماره‌های یکتای امروز <span id="unique-numbers-count" class="badge badge-primary"></span></span>
            <span id="unique-numbers-toggle-icon" style="font-size:12px;color:var(--text-muted)">▾ نمایش</span>
          </div>
          <div class="ss-body" id="unique-numbers-body" style="display:none">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;align-items:center">
              <button type="button" class="btn btn-light btn-sm" onclick="toggleSelectAllUnique(true)">☑ انتخاب همه</button>
              <button type="button" class="btn btn-light btn-sm" onclick="toggleSelectAllUnique(false)">☐ لغو انتخاب</button>
              <button type="button" class="btn btn-primary btn-sm" onclick="copySelectedUniqueNumbers()">📋 کپی انتخاب‌شده‌ها</button>
              <span id="unique-selected-count" style="font-size:12px;color:var(--text-muted)"></span>
            </div>
            <div id="unique-numbers-box"><p class="jj-muted">برای بارگذاری کلیک کنید...</p></div>
          </div>
        </div>

        <?php if (!empty($stats['by_extension'])): ?>
        <div class="table-wrapper">
          <table class="data-table">
            <thead><tr><th>داخلی</th><th>نام</th><th>تعداد تماس</th><th>کل مکالمه</th></tr></thead>
            <tbody>
              <?php foreach ($stats['by_extension'] as $ext): ?>
                <tr>
                  <td dir="ltr"><strong><?= Helpers::e($ext['exten_number']) ?></strong></td>
                  <td><?= Helpers::e($ext['exten_name'] ?: '-') ?></td>
                  <td><?= Helpers::toPersianDigits($ext['cnt']) ?></td>
                  <td><?= Helpers::toPersianDigits(Call::formatDuration($ext['total_talk'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </details>
  </div>
</main>

<!-- مودال پخش صوت -->
<!-- مودال همگام‌سازی (مستقل از فیلترهای لیست تماس‌ها) -->
<div class="modal-overlay" id="modal-sync">
  <div class="modal-box">
    <div class="modal-header">
      <h4>🔄 همگام‌سازی با تلفنچی</h4>
      <button class="btn-close" onclick="closeModal('modal-sync')">×</button>
    </div>
    <div class="modal-body" style="padding:20px">
      <div class="form-group">
        <label class="form-label">بازه‌ی زمانی</label>
        <select id="sync-mode-select" class="form-control" onchange="onSyncModeChange()">
          <option value="incremental">فقط تماس‌های جدید (از آخرین همگام‌سازی تا الان)</option>
          <option value="today">امروز</option>
          <option value="7days">۷ روز اخیر</option>
          <option value="30days">۳۰ روز اخیر</option>
          <option value="custom">بازه‌ی دلخواه</option>
          <option value="all">🌐 همه‌ی تاریخچه (از 1394 تا امروز - ممکن است طول بکشد)</option>
        </select>
      </div>
      <div id="sync-custom-range" style="display:none;gap:8px;margin-top:8px">
        <div class="form-group" style="flex:1">
          <label class="form-label">از تاریخ (شمسی)</label>
          <input type="text" id="sync-from-date" class="form-control jalali-date-input" placeholder="1405/06/01">
        </div>
        <div class="form-group" style="flex:1">
          <label class="form-label">تا تاریخ (شمسی)</label>
          <input type="text" id="sync-to-date" class="form-control jalali-date-input" placeholder="1405/06/03">
        </div>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:10px;line-height:1.7" id="sync-mode-hint">
        این حالت فقط تماس‌هایی را می‌گیرد که از آخرین همگام‌سازی موفق تا الان اتفاق افتاده‌اند.
      </p>
      <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
        <button class="btn btn-light btn-sm" onclick="closeModal('modal-sync')">انصراف</button>
        <button class="btn btn-primary btn-sm" id="sync-start-btn" onclick="startSync()">شروع همگام‌سازی</button>
      </div>
      <div id="sync-progress" style="display:none;margin-top:14px;text-align:center;color:var(--text-muted)">
        ⏳ در حال دریافت اطلاعات از تلفنچی... (ممکن است چند ثانیه طول بکشد)
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-play-recording">
  <div class="modal-box">
    <div class="modal-header">
      <h4>🎵 پخش فایل صوتی تماس</h4>
      <button class="btn-close" onclick="closeModal('modal-play-recording'); stopAudio()">×</button>
    </div>
    <div class="modal-body" style="text-align:center;padding:30px">
      <div id="recording-info" style="margin-bottom:16px;font-size:13px;color:var(--text-muted)"></div>
      <audio id="audio-player" controls style="width:100%;max-width:400px" dir="ltr"></audio>
      <div id="recording-loading" style="display:none;padding:20px">
        <div style="font-size:32px;margin-bottom:8px">⏳</div>
        <div>در حال دریافت فایل صوتی از تلفنچی...</div>
      </div>
      <div id="recording-error" style="display:none;padding:20px;color:var(--danger)"></div>
    </div>
  </div>
</div>

<script>
let searchTimer = null;
function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 500);
}
function toEnDigitsLocal(str) {
    return String(str || '').replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
        .replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}
function applyFilters() {
    const params = new URLSearchParams({page: 'calls'});
    const s = document.getElementById('call-search').value.trim();
    const t = document.getElementById('call-type-filter').value;
    const st = document.getElementById('call-status-filter').value;
    const cat = document.getElementById('call-category-filter').value;
    const lb = document.getElementById('call-label-filter').value;
    const fd = toEnDigitsLocal(document.getElementById('call-from-date').value.trim());
    const td = toEnDigitsLocal(document.getElementById('call-to-date').value.trim());
    if (s) params.set('search', s);
    if (t) params.set('type', t);
    if (st) params.set('status', st);
    if (cat) params.set('category', cat);
    if (lb) params.set('label', lb);
    const ex = document.getElementById('call-exten-filter').value;
    if (ex) params.set('exten', ex);
    params.set('view', '<?= $view ?>');
    if (fd) params.set('from_date', fd);
    if (td) params.set('to_date', td);
    params.set('range', (fd || td) ? (window._jjRangeChanged || '<?= $range ?>' === 'custom' ? 'custom' : '<?= $range ?>') : 'all');
    window.location.href = '<?= BASE_URL ?>/index.php?' + params.toString();
}

// ===== ۲.۰.۸: مرجع/نقش، خلاصه‌ی تماس، بازکردن تماس‌های یک شماره =====
async function saveRef(el) {
    const td = el.closest('td');
    const r = await fetchAPI('pipeline/number/' + td.dataset.p + '/ref', { method: 'PATCH', body: { ref_source: td.querySelector('.pl-ref').value, ref_role: td.querySelector('.pl-role').value } });
    if (r && r.success) showToast('ذخیره شد', 'success');
}
async function saveSummary(inp) {
    const v = inp.value.trim();
    if (v === (inp.dataset.v || '')) return;
    const r = await fetchAPI('calls/' + inp.dataset.id + '/summary', { method: 'PUT', body: { summary: v } });
    if (r && r.success) { inp.dataset.v = v; showToast('خلاصه ذخیره شد', 'success'); }
}
async function toggleNumberCalls(btn, p10) {
    const tr = btn.closest('tr');
    const next = tr.nextElementSibling;
    if (next && next.classList.contains('jj-sub-calls')) { next.remove(); return; }
    const cols = tr.children.length;
    const sub = document.createElement('tr'); sub.className = 'jj-sub-calls';
    sub.innerHTML = '<td colspan="' + cols + '" class="jj-muted">در حال بارگذاری…</td>';
    tr.after(sub);
    const r = await fetchAPI('calls/by-number?number=0' + p10, { silent: true });
    const calls = (r && r.success && r.data && r.data.calls) || [];
    const esc = x => String(x ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const dir = { inbound: '📥 ورودی', outbound: '📤 خروجی', internal: '🔄 داخلی' };
    const st = { answered: 'پاسخ داده شد', no_answer: 'بی‌پاسخ', busy: 'مشغول', failed: 'ناموفق', ringing: 'در حال زنگ' };
    sub.innerHTML = '<td colspan="' + cols + '"><table class="data-table jj-table" style="margin:0"><tbody>' + (calls.map(c => `<tr>
        <td class="jj-time">${esc(c.jalali_started_at || c.started_at || '')}</td>
        <td>${dir[c.type] || esc(c.type)}</td><td>${st[c.status] || esc(c.status)}</td>
        <td>${esc(c.staff_name || c.exten_name || c.exten_number || '-')}</td><td class="jj-dur">${esc(c.talk_formatted || '-')}</td>
        <td style="width:40%"><input class="jj-sumline" maxlength="255" placeholder="خلاصه‌ی یک جمله‌ای…" value="${esc(c.summary_line || '')}" data-v="${esc(c.summary_line || '')}" data-id="${c.id}" onblur="saveSummary(this)" onkeydown="if(event.key==='Enter')this.blur()"></td>
        <td><a class="jj-btn" href="<?= BASE_URL ?>/index.php?page=call_detail&id=${c.id}" title="جزئیات و متن مکالمه">📝</a></td>
      </tr>`).join('') || '<tr><td class="jj-muted">تماسی یافت نشد</td></tr>') + '</tbody></table></td>';
}

// تغییر دستی دسته‌بندی یک تماس (مهمان/میزبان/جذب نیرو/اسپم/داخلی) از
// همون دراپ‌داون داخل لیست - بدون نیاز به باز کردن جزئیات تماس
async function updateCallCategory(selectEl) {
    const callId = selectEl.dataset.callId;
    const category = selectEl.value;
    const original = selectEl.dataset.original || category;
    selectEl.disabled = true;
    try {
        const result = await fetchAPI('calls/' + callId + '/category', {
            method: 'PATCH', body: { category }
        });
        if (result.success) {
            selectEl.dataset.original = category;
            showToast('دسته‌بندی به‌روزرسانی شد', 'success');
        } else {
            selectEl.value = original;
            showToast(result.message || 'خطا در به‌روزرسانی', 'error');
        }
    } catch (err) {
        selectEl.value = original;
        showToast('خطا در ارتباط با سرور', 'error');
    }
    selectEl.disabled = false;
}

async function updateCallLabel(selectEl) {
    const callId = selectEl.dataset.callId;
    const label = selectEl.value;
    const original = selectEl.dataset.original || label;
    selectEl.disabled = true;
    try {
        const result = await fetchAPI('calls/' + callId + '/label', {
            method: 'PATCH', body: { label }
        });
        if (result.success) {
            selectEl.dataset.original = label;
            showToast('لیبل به‌روزرسانی شد', 'success');
        } else {
            selectEl.value = original;
            showToast(result.message || 'خطا در به‌روزرسانی', 'error');
        }
    } catch (err) {
        selectEl.value = original;
        showToast('خطا در ارتباط با سرور', 'error');
    }
    selectEl.disabled = false;
}

function formatCountdown(totalSec) {
    const s = Math.max(0, Math.floor(totalSec));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
}

async function loadMissedAlerts() {
    const box = document.getElementById('missed-alerts-box');
    const countBadge = document.getElementById('missed-alerts-count');
    if (!box) return;
    try {
        const result = await fetchAPI('calls/missed-alerts?hours=12', { method: 'GET', silent: true });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت هشدارها</p>';
            return;
        }
        const payload = result.data || {};
        const items = payload.items || [];
        const overdue = payload.overdue_count || 0;
        if (overdue > 0) {
            countBadge.style.display = 'inline-block';
            countBadge.textContent = overdue + ' منقضی';
        } else if (items.length) {
            countBadge.style.display = 'inline-block';
            countBadge.className = 'badge badge-warning';
            countBadge.textContent = items.length + ' در صف';
        } else {
            countBadge.style.display = 'none';
        }

        if (!items.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">تماس بی‌پاسخِ باز برای پیگیری وجود ندارد.</p>';
            return;
        }

        box.innerHTML = items.map(c => {
            const overdueCls = c.is_overdue ? 'background:rgba(220,53,69,0.08)' : '';
            const timerLabel = c.is_overdue
                ? `<span style="color:var(--danger);font-weight:700">⚠ منقضی (+${formatCountdown(c.elapsed_seconds - c.deadline_seconds)})</span>`
                : `<span style="color:var(--warning,#b8860b);font-weight:700" data-remain="${c.remaining_seconds}" class="missed-timer">⏳ ${formatCountdown(c.remaining_seconds)}</span>`;
            const who = escapeHtml(c.display_name || '-');
            const phone = escapeHtml(c.call_source || c.call_dest || '');
            return `
              <div style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--border);${overdueCls}">
                <div style="flex:1;min-width:0">
                  <div><strong>${who}</strong> <span dir="ltr" style="font-size:12px;color:var(--text-muted)">${phone}</span>${c.attempts > 1 ? ` <span class="jj-pill warn">${c.attempts} بار</span>` : ''}</div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                    ${escapeHtml(c.jalali_started_at || '-')} · اپراتور: ${escapeHtml(c.staff_name || c.exten_number || '-')}
                  </div>
                </div>
                <div style="text-align:center;min-width:110px">${timerLabel}</div>
                ${phone ? `<a class="btn btn-success btn-xs" href="tel:${phone.replace(/[^0-9+]/g,'')}" title="تماس مجدد">📞 تماس</a>` : ''}
                <button class="btn btn-light btn-xs" onclick="resolveMissedAlert(${c.id})" title="علامت حل‌شده">✓ رسیدگی شد</button>
                ${JJ_IS_MGR && phone ? `<select class="pl-assign" title="ارجاع به" onchange="assignMissed('${phone}', this.value)"><option value="">↪ ارجاع…</option>${JJ_USERS.map(u => `<option value="${u.id}" ${u.id == c.assigned_user_id ? 'selected' : ''}>${escapeHtml(u.full_name)}</option>`).join('')}</select>` : ''}
                <a class="btn btn-info btn-xs" href="<?= BASE_URL ?>/index.php?page=call_detail&id=${c.id}">جزئیات</a>
                <a class="btn btn-primary btn-xs" href="<?= BASE_URL ?>/index.php?page=phone_detail&number=${encodeURIComponent(c.call_source || '')}">پروفایل</a>
              </div>`;
        }).join('');
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
    }
}

const JJ_IS_MGR = <?= Pipeline::isManager($currentUser) ? 'true' : 'false' ?>;
const JJ_USERS = <?= json_encode(Pipeline::isManager($currentUser) ? Database::getInstance()->fetchAll("SELECT id, full_name FROM users WHERE is_active = 1 AND username <> 'admin' ORDER BY full_name") : [], JSON_UNESCAPED_UNICODE) ?>;
async function assignMissed(phone, uid) {
    if (!uid) return;
    const r = await fetchAPI('pipeline/number/' + encodeURIComponent(phone) + '/assign', { method: 'PATCH', body: { user_id: +uid } });
    if (r && r.success) showToast('ارجاع شد و در لیست پیگیری همکار قرار گرفت', 'success');
}
async function resolveMissedAlert(callId) {
    try {
        const result = await fetchAPI('calls/' + callId + '/live-status', {
            method: 'PATCH', body: { live_status: 'resolved', note: 'پیگیری بی‌پاسخ انجام شد' }
        });
        if (result.success) {
            showToast('تماس از صف هشدار خارج شد', 'success');
            loadMissedAlerts();
        } else {
            showToast(result.message || 'خطا', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

setInterval(function tickMissedTimers() {
    document.querySelectorAll('.missed-timer[data-remain]').forEach(el => {
        let remain = parseInt(el.dataset.remain, 10);
        if (isNaN(remain)) return;
        remain = Math.max(0, remain - 1);
        el.dataset.remain = String(remain);
        if (remain <= 0) {
            el.style.color = 'var(--danger)';
            el.textContent = '⚠ منقضی';
            el.classList.remove('missed-timer');
        } else {
            el.textContent = '⏳ ' + formatCountdown(remain);
        }
    });
}, 1000);

let _missedOverdueNotified = 0;
async function loadMissedAlertsWithNotify() {
    await loadMissedAlerts();
    const badge = document.getElementById('missed-alerts-count');
    const overdueText = badge && badge.style.display !== 'none' ? badge.textContent : '';
    const overdueMatch = overdueText && overdueText.match(/(\d+)\s*منقضی/);
    const overdue = overdueMatch ? parseInt(overdueMatch[1], 10) : 0;
    if (overdue > _missedOverdueNotified) {
        document.title = '⏰ ' + overdue + ' بی‌پاسخ منقضی | تماس‌ها';
        if (typeof Notification !== 'undefined' && Notification.permission === 'granted') {
            try { new Notification('جاکجاست CRM', { body: overdue + ' تماس بی‌پاسخ از مهلت پیگیری گذشته است', tag: 'missed-overdue' }); } catch (e) {}
        } else if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            Notification.requestPermission().catch(() => {});
        }
    }
    if (overdue === 0 && document.title.indexOf('بی‌پاسخ منقضی') !== -1) {
        document.title = 'تماس‌ها | جاکجاست';
    }
    _missedOverdueNotified = overdue;
}

if (document.getElementById('missed-alerts-box')) {
    window.addEventListener('DOMContentLoaded', () => loadMissedAlertsWithNotify());
    setInterval(loadMissedAlertsWithNotify, 30000);
}

// ===========================
// پایش زنده‌ی تماس‌ها (Live Calls Board)
// ===========================
const LIVE_STATUS_LABELS = {
    new: '🆕 جدید',
    being_handled: '👀 در حال رسیدگی',
    needs_callback: '📞 نیاز به تماس مجدد',
    resolved: '✅ حل شد',
};

async function loadLiveCalls() {
    const box = document.getElementById('live-calls-box');
    const countBadge = document.getElementById('live-calls-count');
    try {
        const result = await fetchAPI('calls/live', { method: 'GET', silent: true });
        if (!result || !result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت پایش زنده: ' + ((result && result.message) || 'ارتباط با سرور') + '</p>';
            return;
        }

        const calls = result.data || [];
        const ringingCount = calls.filter(c => c.status === 'ringing').length;
        countBadge.textContent = ringingCount > 0 ? `${ringingCount} تماس در حال زنگ خوردن` : '';
        countBadge.style.display = ringingCount > 0 ? 'inline-block' : 'none';

        if (!calls.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در ۳۰ دقیقه‌ی اخیر تماسی ثبت نشده.</p>';
            return;
        }

        box.innerHTML = calls.map(c => {
            const isRinging = c.status === 'ringing';
            const who = c.customer_name || c.lead_name || c.call_source || c.call_dest || '-';
            const operator = c.staff_name || c.exten_name || '-';
            const liveStatus = c.live_status || 'new';
            const statusOptions = Object.entries(LIVE_STATUS_LABELS)
                .map(([k, v]) => `<option value="${k}" ${k === liveStatus ? 'selected' : ''}>${v}</option>`)
                .join('');

            return `
              <div style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid var(--border)${isRinging ? ';background:rgba(220,53,69,0.05)' : ''}">
                <div style="flex:1">
                  <div>
                    ${isRinging ? '<span class="pulse-live" style="color:var(--danger);font-weight:700">🔔 در حال زنگ خوردن</span> — ' : ''}
                    <strong>${who}</strong>
                    <span style="color:var(--text-muted);font-size:12px" dir="ltr">(${c.call_source || c.call_dest || ''})</span>
                  </div>
                  <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                    اپراتور: ${operator} ${c.live_status_by ? '| آخرین بروزرسانی: ' + c.live_status_by : ''}
                  </div>
                </div>
                <select class="form-control" style="width:auto;font-size:12px" onchange="setLiveStatus(${c.id}, this.value)">
                  ${statusOptions}
                </select>
                <a href="<?= BASE_URL ?>/index.php?page=call_detail&id=${c.id}" class="btn btn-light btn-xs">جزئیات</a>
              </div>`;
        }).join('');
    } catch (err) {
        console.error('Live calls load error:', err);
    }
}

async function setLiveStatus(callId, status) {
    try {
        await fetchAPI('calls/' + callId + '/live-status', { method: 'PATCH', body: { live_status: status } });
        showToast('وضعیت به‌روزرسانی شد', 'success');
    } catch (err) {
        showToast('خطا در به‌روزرسانی وضعیت', 'error');
    }
}

if (document.getElementById('live-calls-box')) {
    window.addEventListener('DOMContentLoaded', () => loadLiveCalls());
    setInterval(loadLiveCalls, 15000);
}

// ===================== شماره‌های یکتای امروز (خط‌روند) =====================
let uniqueNumbersLoaded = false;

function toggleUniqueNumbers() {
    const body = document.getElementById('unique-numbers-body');
    const icon = document.getElementById('unique-numbers-toggle-icon');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    icon.textContent = isOpen ? '▾ نمایش' : '▴ پنهان کردن';
    if (!isOpen && !uniqueNumbersLoaded) {
        loadUniqueNumbers();
    }
}

function openUniqueNumbersSection() {
    const body = document.getElementById('unique-numbers-body');
    if (body.style.display === 'none') toggleUniqueNumbers();
    document.getElementById('unique-numbers-section').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateUniqueSelectedCount() {
    const n = document.querySelectorAll('.unique-number-cb:checked').length;
    const el = document.getElementById('unique-selected-count');
    if (el) el.textContent = n ? (n + ' شماره انتخاب شده') : '';
}

function toggleSelectAllUnique(checked) {
    document.querySelectorAll('.unique-number-cb').forEach(cb => { cb.checked = !!checked; });
    updateUniqueSelectedCount();
}

async function copySelectedUniqueNumbers() {
    const selected = Array.from(document.querySelectorAll('.unique-number-cb:checked')).map(cb => cb.value).filter(Boolean);
    if (!selected.length) {
        showToast('هیچ شماره‌ای انتخاب نشده', 'error');
        return;
    }
    const text = selected.join('\n');
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        showToast(selected.length + ' شماره کپی شد', 'success');
    } catch (err) {
        showToast('کپی ناموفق بود', 'error');
    }
}

async function loadUniqueNumbers() {
    const box = document.getElementById('unique-numbers-box');
    const countBadge = document.getElementById('unique-numbers-count');
    box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>';
    try {
        const result = await fetchAPI('calls/unique-numbers?limit=200&direction=' + (window._uDir || ''), { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت لیست</p>';
            return;
        }
        uniqueNumbersLoaded = true;
        const rows = result.data || [];
        countBadge.textContent = rows.length ? rows.length + ' شماره' : '';
        countBadge.style.display = rows.length ? 'inline-block' : 'none';

        if (!rows.length) {
            box.innerHTML = `<div class="jj-dir-tabs">${[['', 'همه'], ['inbound', '📥 ورودی'], ['outbound', '📤 خروجی']].map(([k, l]) => `<a href="#" class="jj-chip${(window._uDir || '') === k ? ' on' : ''}" onclick="event.preventDefault();window._uDir='${k}';loadUniqueNumbers()">${l}</a>`).join('')}</div><p style="color:var(--text-muted);font-size:12px">موردی نیست.</p>`;
            return;
        }

        const dirTabs = `<div class="jj-dir-tabs">${[['', 'همه'], ['inbound', '📥 ورودی'], ['outbound', '📤 خروجی']].map(([k, l]) => `<a href="#" class="jj-chip${(window._uDir || '') === k ? ' on' : ''}" onclick="event.preventDefault();window._uDir='${k}';loadUniqueNumbers()">${l}</a>`).join('')}</div>`;
        box.innerHTML = dirTabs + `
          <div class="table-wrapper">
            <table class="data-table">
              <thead><tr>
                <th style="width:36px"><input type="checkbox" title="انتخاب همه" onchange="toggleSelectAllUnique(this.checked)"></th>
                <th>نام</th><th>شماره</th><th>مرحله</th><th>📥 ورودی (پاسخ)</th><th>📤 خروجی (پاسخ)</th>
                <th>اپراتور آخرین تماس</th><th>وضعیت آخرین تماس</th><th>زمان آخرین تماس</th><th></th>
              </tr></thead>
              <tbody>
                ${rows.map(r => {
                    const lc = r.last_call || {};
                    const statusLabel = lc.status === 'answered' ? 'پاسخ داده شد' : (lc.status === 'no_answer' ? 'بی‌پاسخ' : (lc.status === 'ringing' ? '⏳ در حال زنگ خوردن' : (lc.status || '-')));
                    const statusBadge = lc.status === 'answered' ? 'badge-success' : (lc.status === 'ringing' ? 'badge-warning' : 'badge-danger');
                    const num = r.number || '';
                    return `
                      <tr>
                        <td><input type="checkbox" class="unique-number-cb" value="${escapeHtml(num)}" onchange="updateUniqueSelectedCount()"></td>
                        <td>${r.name ? escapeHtml(r.name) : '<span style="color:var(--text-muted)">ناشناس</span>'}</td>
                        <td dir="ltr" style="user-select:all;cursor:text" title="برای کپی انتخاب کنید"><code style="user-select:all;background:transparent;padding:0">${escapeHtml(num || '-')}</code></td>
                        <td>${r.stage_label ? `<span class="jj-pill info">${escapeHtml(r.stage_label)}</span>` : '-'}</td>
                        <td>${r.inbound_today} (${r.inbound_answered})</td>
                        <td>${r.outbound_today} (${r.outbound_answered})</td>
                        <td>${escapeHtml(lc.staff_name || '-')}</td>
                        <td><span class="badge ${statusBadge}">${statusLabel}</span></td>
                        <td>${escapeHtml(lc.jalali_started_at || '-')}</td>
                        <td><a class="btn btn-info btn-xs" href="<?= BASE_URL ?>/index.php?page=phone_detail&number=${encodeURIComponent(num)}" title="تاریخچه کامل شماره">پروفایل</a></td>
                      </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>`;
        updateUniqueSelectedCount();
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function openSyncModal() {
    openModal('modal-sync');
}

function onSyncModeChange() {
    const mode = document.getElementById('sync-mode-select').value;
    const range = document.getElementById('sync-custom-range');
    range.style.display = (mode === 'custom') ? 'flex' : 'none';

    const hints = {
        incremental: 'این حالت فقط تماس‌هایی را می‌گیرد که از آخرین همگام‌سازی موفق تا الان اتفاق افتاده‌اند.',
        today: 'همه‌ی تماس‌های امروز (از ساعت ۰۰:۰۰) دریافت می‌شود.',
        '7days': 'تماس‌های ۷ روز اخیر دریافت می‌شود.',
        '30days': 'تماس‌های ۳۰ روز اخیر دریافت می‌شود؛ اگر تعداد زیاد باشد کمی طول می‌کشد.',
        custom: 'بازه‌ی دلخواه خودتان را با تاریخ شمسی وارد کنید.',
        all: '⚠️ از سال 1394 تا امروز را می‌گیرد. اگر حجم تماس‌های شما خیلی بالاست (مثلاً چند صد تماس در روز)، ممکن است این گزینه چند دقیقه طول بکشد یا timeout بدهد؛ در آن صورت به‌جای «همه‌ی تاریخچه»، از «بازه‌ی دلخواه» با یک ماه در هر بار استفاده کنید.',
    };
    document.getElementById('sync-mode-hint').textContent = hints[mode] || '';
}

// تبدیل تاریخ میلادی به رشته‌ی شمسی Y/m/d با استفاده از همان تابع سرور
// (چون تبدیل شمسی سمت کلاینت نداریم، این کمکی‌ها را از خودِ سرور می‌گیریم
// - ساده‌تر: چون سرور از from_date/to_date شمسی استفاده می‌کند، همین‌جا
// بر اساس امروز/۷ روز/۳۰ روز، محاسبه را به سرور می‌سپاریم با ارسال mode)
async function startSync() {
    const mode = document.getElementById('sync-mode-select').value;
    const btn = document.getElementById('sync-start-btn');
    const progress = document.getElementById('sync-progress');

    let body = {};

    if (mode === 'incremental') {
        body = {};
    } else if (mode === 'all') {
        body = { mode: 'all' };
    } else if (mode === 'custom') {
        const fromDate = document.getElementById('sync-from-date').value.trim();
        const toDate = document.getElementById('sync-to-date').value.trim();
        if (!fromDate || !toDate) {
            showToast('لطفاً هر دو تاریخ را وارد کنید (فرمت: 1405/06/01)', 'error');
            return;
        }
        body = { from_date: fromDate, to_date: toDate };
    } else {
        // today, 7days, 30days → محاسبه‌ی بازه روی سرور راحت‌تر است، پس
        // به سرور می‌گوییم چند روز عقب برود
        const daysMap = { today: 0, '7days': 7, '30days': 30 };
        body = { days_back: daysMap[mode] };
    }

    btn.disabled = true;
    btn.textContent = 'در حال همگام‌سازی...';
    progress.style.display = 'block';

    try {
        const result = await fetchAPI('sync', { method: 'POST', body });
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.message || 'خطا در همگام‌سازی', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'شروع همگام‌سازی';
        progress.style.display = 'none';
    }
}

async function autoConvertToLeads() {
    const btn = document.getElementById('auto-lead-btn');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال بررسی...';
    try {
        const result = await fetchAPI('calls/auto-convert-to-leads', { method: 'POST', body: {} });
        if (result.success) {
            showToast(result.message, 'success');
            if (result.data.created_leads > 0) {
                setTimeout(() => window.location.href = '<?= BASE_URL ?>/index.php?page=leads', 1800);
            }
        } else {
            showToast(result.message || 'خطا', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🎯 تبدیل خودکار به سرنخ';
    }
}

async function convertToLead(callId) {
    const name = prompt('نام مشتری برای سرنخ جدید (خالی بگذارید تا خودکار ساخته شود):', '');
    if (name === null) return; // کاربر لغو کرد

    try {
        const result = await fetchAPI('calls/' + callId + '/convert-to-lead', {
            method: 'POST',
            body: name ? { name } : {}
        });
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showToast(result.message || 'خطا در تبدیل به سرنخ', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

let jjPlyrInstance = null;

async function playRecording(callId) {
    openModal('modal-play-recording');
    const loadingEl = document.getElementById('recording-loading');
    const errorEl = document.getElementById('recording-error');
    const audio = document.getElementById('audio-player');

    loadingEl.style.display = 'block';
    errorEl.style.display = 'none';
    audio.style.display = 'none';
    document.getElementById('recording-info').textContent = '';

    try {
        // دریافت فایل صوتی از API
        const url = `${JJ.baseUrl}/api.php/play-recording?id=${callId}&token=${encodeURIComponent(JJ.token)}`;

        // ⚠️ رفع باگ: قبلاً بلافاصله بعد از ست‌کردن src، audio.play() صدا
        // زده می‌شد بدون صبر برای بارگذاری واقعی و بدون گوش‌دادن به رویداد
        // error - یعنی اگر دریافت فایل شکست می‌خورد (هنوز file_id sync
        // نشده، یا تلفنچی خطا برگردانده)، خود پرامیس play() خطای خام
        // مرورگر را throw می‌کرد: "Failed to load because no supported
        // source was found." الان صبر می‌کنیم تا رویداد loadeddata واقعاً
        // اتفاق بیفتد (یا error، که پیام فارسی دوستانه نشان می‌دهد).
        audio.src = url;

        audio.addEventListener('loadeddata', () => {
            loadingEl.style.display = 'none';
            audio.style.display = 'block';

            // راه‌اندازی پلیر زیبای Plyr (به‌جای پلیر پیش‌فرض و کم‌طرح مرورگر)
            if (window.Plyr) {
                if (jjPlyrInstance) jjPlyrInstance.destroy();
                jjPlyrInstance = new Plyr(audio, { controls: ['play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'speed', 'download'], download: url });
            }

            audio.play().catch(err => {
                errorEl.textContent = 'خطا در پخش فایل صوتی: ' + err.message;
                errorEl.style.display = 'block';
                audio.style.display = 'none';
            });
        }, { once: true });

        audio.addEventListener('error', () => {
            loadingEl.style.display = 'none';
            errorEl.textContent = 'خطا در دریافت فایل صوتی. ممکن است فایل هنوز از تلفنچی همگام‌سازی نشده باشد.';
            errorEl.style.display = 'block';
        }, { once: true });

        audio.addEventListener('ended', () => {
            document.getElementById('recording-info').textContent = 'پخش کامل شد';
        });

        audio.load();
    } catch (err) {
        loadingEl.style.display = 'none';
        errorEl.textContent = 'خطا در دریافت فایل صوتی';
        errorEl.style.display = 'block';
    }
}

function stopAudio() {
    const audio = document.getElementById('audio-player');
    if (audio) {
        audio.pause();
        audio.src = '';
    }
    if (jjPlyrInstance) {
        jjPlyrInstance.destroy();
        jjPlyrInstance = null;
    }
}

function exportCalls() {
    const params = new URLSearchParams();
    const s = document.getElementById('call-search').value.trim();
    const t = document.getElementById('call-type-filter').value;
    const st = document.getElementById('call-status-filter').value;
    const cat = document.getElementById('call-category-filter').value;
    const lb = document.getElementById('call-label-filter').value;
    const fd = document.getElementById('call-from-date').value.trim();
    const td = document.getElementById('call-to-date').value.trim();
    if (s) params.set('search', s);
    if (t) params.set('type', t);
    if (st) params.set('status', st);
    if (cat) params.set('category', cat);
    if (lb) params.set('label', lb);
    if (fd) params.set('from_date', fd);
    if (td) params.set('to_date', td);
    // اضافه کردن token برای auth
    params.set('token', JJ.token);
    // هدایت به URL خروجی
    window.location.href = `${JJ.baseUrl}/api.php/export-calls?${params.toString()}`;
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
