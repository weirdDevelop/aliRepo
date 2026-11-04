<?php
/**
 * جاکجاست - پروفایل مشتری
 * Jakojast - Customer Profile Page
 *
 * نمایش اطلاعات کامل مشتری با ۷ تب:
 *   ۱) اطلاعات  ۲) یادداشت‌ها  ۳) تیکت‌ها  ۴) تماس‌ها  ۵) تایم‌لاین  ۶) فاکتورها  ۷) فایل‌ها
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

$customerId = (int)($_GET['id'] ?? 0);
if (!$customerId) {
    echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
            <h2>شناسه مشتری مشخص نیست</h2>
            <a href="' . BASE_URL . '/index.php?page=customers" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
          </div></main>';
    require __DIR__ . '/parts/footer.php';
    exit;
}

try {
    $customer = Customer::find($customerId);
    if (!$customer) {
        echo '<main id="main-content"><div class="card" style="padding:40px;text-align:center">
                <h2>مشتری یافت نشد</h2>
                <a href="' . BASE_URL . '/index.php?page=customers" class="btn btn-primary" style="margin-top:16px">بازگشت به لیست</a>
              </div></main>';
        require __DIR__ . '/parts/footer.php';
        exit;
    }
    $notes = Customer::getNotes($customerId);
    $tickets = Customer::getTickets($customerId);
    $calls = Customer::getCalls($customerId);
} catch (Exception $e) {
    Logger::error('Customer profile load failed: ' . $e->getMessage());
    $customer = null; $notes = []; $tickets = []; $calls = [];
}
?>

<meta name="jj-base-url" content="<?= BASE_URL ?>">

<main id="main-content">

  <div id="page-customer_profile" class="page active">
    <div class="card">
      <!-- هدر پروفایل -->
      <div class="profile-header">
        <div class="avatar" style="width:80px;height:80px;font-size:32px;background:<?= Helpers::avatarColor($customer['name']) ?>">
          <?= Helpers::initials($customer['name']) ?>
        </div>
        <div class="ph-info">
          <h2><?= Helpers::e($customer['name']) ?></h2>
          <div style="display:flex;gap:8px;align-items:center;margin-top:4px;flex-wrap:wrap">
            <span class="badge <?= $customer['level'] === 'VIP' ? 'badge-gold' : 'badge-secondary' ?>"><?= Helpers::e($customer['level']) ?></span>
            <span class="badge <?= $customer['status'] === 'active' ? 'badge-success' : 'badge-danger' ?>">
              <?= $customer['status'] === 'active' ? 'فعال' : ($customer['status'] === 'blocked' ? 'مسدود' : 'غیرفعال') ?>
            </span>
            <span class="badge badge-info">امتیاز: <?= Helpers::toPersianDigits($customer['score']) ?></span>
          </div>
          <div style="display:flex;gap:16px;margin-top:8px;font-size:13px;color:var(--text-secondary);flex-wrap:wrap">
            <span>📞 <span dir="ltr"><?= Helpers::e($customer['mobile'] ?: '-') ?></span></span>
            <span>📧 <?= Helpers::e($customer['email'] ?: '-') ?></span>
            <span>📍 <?= Helpers::e($customer['city'] ?: '-') ?></span>
          </div>
        </div>
        <div class="ph-actions">
          <a href="<?= BASE_URL ?>/index.php?page=customers" class="btn btn-light btn-sm">← بازگشت</a>
          <?php if (defined('CRM_BRIDGED') && CRM_BRIDGED && ($__u10 = substr(preg_replace('/\D+/', '', (string)($customer['mobile'] ?: ($customer['phone'] ?? ''))), -10)) && strlen($__u10) === 10 && $__u10[0] === '9'): ?>
          <a class="btn btn-success btn-sm" target="_top" href="<?= Helpers::e(Auth::elanbanUrl()) ?>#persons.tel0<?= $__u10 ?>" title="پیام‌های همه‌ی کانال‌ها، تماس‌ها و سوابق CRM در یک تایم‌لاین">🗂 پرونده‌ی یکپارچه</a>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm" onclick="editCustomer(<?= (int)$customerId ?>)">✏️ ویرایش</button>
          <button class="btn btn-danger btn-sm" onclick="deleteCustomer(<?= (int)$customerId ?>, '<?= Helpers::e($customer['name']) ?>')">🚫 مسدود</button>
        </div>
      </div>

      <!-- اطلاعات سریع + فیلدهای یاس -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)">
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">تاریخ عضویت</div>
          <div class="fw-700 mt-1"><?= Helpers::e($customer['join_date'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">تعداد رزرو / خرید</div>
          <div class="fw-700 text-info mt-1"><?= Helpers::toPersianDigits($customer['reservations']) ?></div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">مجموع خرید</div>
          <div class="fw-700 text-success mt-1"><?= Helpers::toPersianDigits(Helpers::formatNumber($customer['total_spend'])) ?> ت</div>
        </div>
        <div style="padding:14px;text-align:center">
          <div class="text-muted fs-12">آخرین تماس</div>
          <div class="fw-700 mt-1"><?= Helpers::e($customer['last_call_jalali'] ?? '-') ?></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)">
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">میانگین مبلغ خرید</div>
          <div class="fw-700 mt-1"><?= Helpers::toPersianDigits(Helpers::formatNumber($customer['avg_purchase'] ?? 0)) ?> ت</div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">درصد وفاداری</div>
          <div class="fw-700 text-success mt-1"><?= Helpers::toPersianDigits($customer['loyalty_percent'] ?? 0) ?>٪</div>
        </div>
        <div style="padding:14px;text-align:center;border-left:1px solid var(--border)">
          <div class="text-muted fs-12">روز محبوب</div>
          <div class="fw-700 mt-1"><?= Helpers::e($customer['favorite_day'] ?: '-') ?></div>
        </div>
        <div style="padding:14px;text-align:center">
          <div class="text-muted fs-12">آخرین خرید</div>
          <div class="fw-700 mt-1"><?= Helpers::e($customer['last_purchase'] ?: '-') ?></div>
        </div>
      </div>

      <!-- تب‌ها -->
      <div class="tabs-wrapper">
        <button class="tab-btn active" onclick="switchTab('cp','info',this)">👤 اطلاعات</button>
        <button class="tab-btn" onclick="switchTab('cp','notes',this)">📝 یادداشت‌ها <span class="badge badge-secondary"><?= count($notes) ?></span></button>
        <button class="tab-btn" onclick="switchTab('cp','tickets',this)">🎫 تیکت‌ها <span class="badge badge-secondary"><?= count($tickets) ?></span></button>
        <button class="tab-btn" onclick="switchTab('cp','calls',this)">📞 تماس‌ها <span class="badge badge-secondary"><?= count($calls) ?></span></button>
        <button class="tab-btn" onclick="switchTab('cp','chat',this); loadElanbanChat()">💬 چت اینستاگرام/سایت</button>
        <button class="tab-btn" onclick="switchTab('cp','timeline',this)">⏱ تایم‌لاین</button>
      </div>

      <!-- تب اطلاعات -->
      <div id="cp-info" class="tab-content active">
        <div style="padding:20px">
          <div class="grid-2">
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">نام کامل</div>
              <div class="fw-700"><?= Helpers::e($customer['name']) ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">موبایل</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($customer['mobile'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">تلفن ثابت</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($customer['phone'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">ایمیل</div>
              <div class="fw-700" dir="ltr"><?= Helpers::e($customer['email'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">شهر</div>
              <div class="fw-700"><?= Helpers::e($customer['city'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">تاریخ تولد</div>
              <div class="fw-700"><?= Helpers::e($customer['birthday'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">آخرین خرید</div>
              <div class="fw-700"><?= Helpers::e($customer['last_purchase'] ?: '-') ?></div>
            </div>
            <div style="background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">آدرس</div>
              <div class="fw-700"><?= Helpers::e($customer['address'] ?: '-') ?></div>
            </div>
          </div>
          <?php if (!empty($customer['notes'])): ?>
            <div style="margin-top:16px;background:var(--bg-secondary);padding:14px;border-radius:8px">
              <div class="text-muted fs-12 mb-1">یادداشت عمومی</div>
              <div><?= nl2br(Helpers::e($customer['notes'])) ?></div>
            </div>
          <?php endif; ?>
          <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" onclick="showToast('امکان ثبت تیکت در مرحله ۶ فعال می‌شود','info')">🎫 ثبت تیکت</button>
            <button class="btn btn-info" onclick="addNote()">📝 یادداشت جدید</button>
          </div>
        </div>
      </div>

      <!-- تب یادداشت‌ها -->
      <div id="cp-notes" class="tab-content">
        <div style="padding:20px">
          <div style="display:flex;gap:8px;margin-bottom:16px">
            <input type="text" class="form-control" id="note-text-input" placeholder="یادداشت جدید..." onkeypress="if(event.key==='Enter')addNote()">
            <button class="btn btn-primary" onclick="addNote()">➕ افزودن</button>
          </div>
          <div id="notes-list">
            <?php if (empty($notes)): ?>
              <div style="text-align:center;padding:30px;color:var(--text-muted)">
                📝 هنوز یادداشتی ثبت نشده است
              </div>
            <?php else: ?>
              <?php foreach ($notes as $n): ?>
                <div class="alert-item info" style="margin-bottom:8px;align-items:flex-start">
                  <div class="ai-icon">📝</div>
                  <div style="flex:1">
                    <div class="ai-text"><?= nl2br(Helpers::e($n['text'])) ?></div>
                    <div class="ai-time" style="margin-top:4px">
                      <?= Helpers::e($n['user_name'] ?? 'سیستم') ?> - <?= Helpers::e(Jalali::fromGregorian($n['created_at'], 'Y/m/d H:i')) ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- تب تیکت‌ها -->
      <div id="cp-tickets" class="tab-content">
        <div style="padding:20px">
          <?php if (empty($tickets)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
              🎫 این مشتری تیکتی ندارد
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr><th>عنوان</th><th>اولویت</th><th>وضعیت</th><th>بخش</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($tickets as $t): ?>
                    <tr>
                      <td><?= Helpers::e($t['title']) ?></td>
                      <td><span class="badge <?= $t['priority'] === 'high' ? 'badge-danger' : ($t['priority'] === 'medium' ? 'badge-warning' : 'badge-secondary') ?>"><?= $t['priority'] === 'high' ? 'بالا' : ($t['priority'] === 'medium' ? 'متوسط' : 'کم') ?></span></td>
                      <td><span class="badge badge-secondary"><?= $t['status'] ?></span></td>
                      <td><?= Helpers::e($t['dept'] ?? '-') ?></td>
                      <td><?= Helpers::e($t['date']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب تماس‌ها -->
      <div id="cp-calls" class="tab-content">
        <div style="padding:20px">
          <?php if (empty($calls)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
              📞 تماسی برای این مشتری ثبت نشده است
            </div>
          <?php else: ?>
            <div class="table-wrapper">
              <table class="data-table">
                <thead>
                  <tr><th>نوع</th><th>مبدا</th><th>مقصد</th><th>اپراتور</th><th>وضعیت</th><th>مدت مکالمه</th><th>تاریخ</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($calls as $call): ?>
                    <tr>
                      <td>
                        <span class="badge <?= $call['type'] === 'inbound' ? 'badge-info' : 'badge-primary' ?>">
                          <?= $call['type'] === 'inbound' ? '📥 ورودی' : '📤 خروجی' ?>
                        </span>
                      </td>
                      <td dir="ltr"><?= Helpers::e($call['call_source']) ?></td>
                      <td dir="ltr"><?= Helpers::e($call['call_dest']) ?></td>
                      <td><?= Helpers::e($call['staff_name'] ?: ($call['exten_name'] ?: '-')) ?></td>
                      <td><?= Helpers::e($call['status']) ?></td>
                      <td><?= Helpers::toPersianDigits(gmdate('i:s', $call['time_talk'])) ?></td>
                      <td><?= Helpers::e($call['jalali_started_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- تب چت اعلان‌بان (اینستاگرام + وب‌چت سایت) -->
      <div id="cp-chat" class="tab-content">
        <div style="padding:20px" id="cp-elanban-box">
          <div style="text-align:center;padding:30px;color:var(--text-muted)">در حال بارگذاری...</div>
        </div>
      </div>

      <!-- تب تایم‌لاین -->
      <div id="cp-timeline" class="tab-content">
        <div style="padding:20px">
          <?php
          // ساخت تایم‌لاین از داده‌های موجود
          $timeline = [];
          if (!empty($customer['join_date'])) $timeline[] = ['👤 عضویت در سیستم', $customer['join_date'], 'عضو شد'];
          foreach ($notes as $n) $timeline[] = ['📝 یادداشت ثبت شد', Jalali::fromGregorian($n['created_at'], 'Y/m/d H:i'), $n['text']];
          foreach ($tickets as $t) $timeline[] = ['🎫 تیکت: ' . $t['title'], $t['date'], 'وضعیت: ' . $t['status']];
          foreach ($calls as $c) $timeline[] = ['📞 تماس ' . ($c['type'] === 'inbound' ? 'ورودی' : 'خروجی'), $c['jalali_started_at'], 'مدت: ' . gmdate('i:s', $c['time_talk'])];

          // مرتب‌سازی بر اساس تاریخ نزولی
          usort($timeline, function ($a, $b) {
              return strcmp($b[1], $a[1]);
          });

          if (empty($timeline)):
          ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted)">
              ⏱ رویدادی برای نمایش وجود ندارد
            </div>
          <?php else: ?>
            <div class="timeline">
              <?php foreach ($timeline as $item): ?>
                <div class="activity-item" style="border-right:2px solid var(--primary);padding-right:16px;margin-bottom:12px">
                  <div class="ai-content">
                    <div class="ai-text fw-600"><?= Helpers::e($item[0]) ?></div>
                    <div class="ai-time"><?= Helpers::e($item[1]) ?></div>
                    <?php if (!empty($item[2])): ?>
                      <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= Helpers::e(Helpers::truncate($item[2], 100)) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
const customerId = <?= (int)$customerId ?>;

// افزودن یادداشت
async function addNote() {
    const input = document.getElementById('note-text-input');
    const text = input.value.trim();
    if (!text) {
        showToast('متن یادداشت را وارد کنید', 'warning');
        return;
    }
    try {
        const result = await fetchAPI(`customers/${customerId}/notes`, {
            method: 'POST',
            body: { text }
        });
        if (result.success) {
            showToast('یادداشت افزوده شد', 'success');
            input.value = '';
            setTimeout(() => window.location.reload(), 600);
        } else {
            showToast(result.message, 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// چت اعلان‌بان - فقط یک‌بار (اولین باز شدن تب) fetch می‌شود
let elanbanChatLoaded = false;
async function loadElanbanChat() {
    if (elanbanChatLoaded) return;
    elanbanChatLoaded = true;
    const box = document.getElementById('cp-elanban-box');
    const mobile = <?= json_encode($customer['mobile'] ?? '') ?>;
    if (!mobile) {
        box.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted)">شماره موبایلی برای این مشتری ثبت نشده</div>';
        return;
    }
    try {
        const result = await fetchAPI(`elanban/lookup?phone=${encodeURIComponent(mobile)}`, { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted)">خطا در دریافت اطلاعات اعلان‌بان</div>';
            return;
        }
        if (result.data.error) {
            box.innerHTML = `<div style="text-align:center;padding:30px;color:var(--danger)">⚠️ خطا در دریافت از اعلان‌بان: ${Helpers_e(result.data.message || 'خطای نامشخص')}</div>`;
            return;
        }
        if (!result.data.configured) {
            box.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted)">اتصال به اعلان‌بان تنظیم نشده است (تنظیمات ← اعلان‌بان)</div>';
            return;
        }
        if (!result.data.found) {
            box.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted)">💬 هیچ چتی برای این شماره در اعلان‌بان ثبت نشده است</div>';
            return;
        }
        const d = result.data.data;
        let html = `<div style="margin-bottom:14px;display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:var(--text-muted)">
            <span>👤 ${d.full_name ? Helpers_e(d.full_name) : 'بدون نام ثبت‌شده'}</span>
            <span>📊 ${d.message_count} پیام</span>
            <span>📡 کانال پرتکرار: ${d.top_channel || '-'}</span>
        </div>`;
        if (!d.recent_messages || !d.recent_messages.length) {
            html += '<div style="text-align:center;padding:20px;color:var(--text-muted)">پیامی ثبت نشده</div>';
        } else {
            html += '<div style="display:flex;flex-direction:column;gap:8px;max-height:420px;overflow-y:auto">';
            d.recent_messages.slice().reverse().forEach(m => {
                const isOut = m.direction === 'out';
                html += `<div style="align-self:${isOut ? 'flex-start' : 'flex-end'};max-width:75%;background:${isOut ? 'var(--bg-secondary)' : 'var(--primary)'};color:${isOut ? 'inherit' : '#fff'};padding:8px 12px;border-radius:10px;font-size:13px">
                    <div>${Helpers_e(m.text || '(بدون متن / رسانه)')}</div>
                    <div style="font-size:10px;opacity:.7;margin-top:4px">${m.channel || ''} · ${m.sent_at || ''}</div>
                </div>`;
            });
            html += '</div>';
        }
        box.innerHTML = html;
    } catch (err) {
        box.innerHTML = '<div style="text-align:center;padding:30px;color:var(--danger)">خطا در ارتباط با سرور</div>';
        console.error(err);
    }
}
function Helpers_e(s) {
    const d = document.createElement('div');
    d.innerText = s;
    return d.innerHTML;
}

// ویرایش مشتری - هدایت به صفحه مشتریان و باز کردن مودال
function editCustomer(id) {
    window.location.href = `<?= BASE_URL ?>/index.php?page=customers`;
    // TODO: در آینده با query string باز کردن مودال خودکار
}

// حذف/مسدود کردن
async function deleteCustomer(id, name) {
    if (!confirmDelete('آیا از مسدود کردن مشتری «' + name + '» مطمئن هستید؟')) return;
    try {
        const result = await fetchAPI('customers/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('مشتری مسدود شد', 'warning');
            setTimeout(() => window.location.href = `<?= BASE_URL ?>/index.php?page=customers`, 1000);
        }
    } catch (err) {
        showToast('خطا', 'error');
    }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
