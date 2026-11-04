<?php
/**
 * جاکجاست - تنظیمات سیستم
 * Jakojast - Settings Page
 *
 * شامل:
 *   ۱) پروفایل کاربر و تغییر رمز عبور
 *   ۲) تنظیمات تلفنچی (service_id, token)
 *   ۳) تنظیمات برند (نام، رنگ)
 *   ۴) تنظیمات مالی (کمیسیون، تسویه)
 *   ۵) مدیریت نقش‌ها و دسترسی‌ها (در مرحله ۸ تکمیل می‌شود)
 *   ۶) بکاپ و بازیابی (در مرحله ۸ تکمیل می‌شود)
 */
require __DIR__ . '/parts/header.php';
require __DIR__ . '/parts/sidebar.php';

// دریافت تنظیمات فعلی از دیتابیس
$db = Database::getInstance();
$settingsRows = $db->fetchAll('SELECT key_name, value FROM settings');
$settings = [];
foreach ($settingsRows as $r) {
    $settings[$r['key_name']] = $r['value'];
}

// برای پنل اتصال داخلی‌های تلفنچی به پرسنل
try {
    $telefonchyExtensions = Call::getExtensions();
    $allStaffForExtens = Staff::all(['limit' => 500])['items'] ?? [];
} catch (Exception $e) {
    $telefonchyExtensions = [];
    $allStaffForExtens = [];
}

// داده‌های کاربر جاری
$user = $currentUser;
?>

<?php $__br = defined('CRM_BRIDGED') && CRM_BRIDGED; ?>
<main id="main-content">

  <div id="page-settings" class="page active">
    <div class="grid-2">

      <!-- ===========================
           ستون راست - پروفایل و امنیت
           =========================== -->
      <div>
<?php if ($__br): ?>
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">🔗 سیستم مدیریت یکپارچه</div>
          <div class="ss-body" style="font-size:12.5px;line-height:1.9;color:var(--text-muted)">
            حساب کاربری، رمز عبور، نقش و دسترسی‌ها از <b>اعلان‌بان ← تنظیمات ← اپراتورها و دسترسی</b> مدیریت می‌شود.
            پنل پیامک همان سرویس پیامک اعلان‌بان است (تنظیمات ← اتصال کانال‌ها). اتصال هر همکار به داخلی تلفنچی و انتقال اطلاعات CRM قدیمی در
            <b>اعلان‌بان ← تنظیمات ← CRM</b> است.
          </div>
        </div>
<?php endif; ?>
<?php if (!$__br): ?>
        <!-- پروفایل کاربر -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">👤 اطلاعات پروفایل</div>
          <div class="ss-body">
            <div class="form-group">
              <label class="form-label">نام کامل</label>
              <input type="text" class="form-control" id="settings-name" value="<?= Helpers::e($user['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">نام کاربری</label>
              <input type="text" class="form-control" value="<?= Helpers::e($user['username'] ?? '') ?>" disabled style="opacity:0.6">
              <small style="color:var(--text-muted);font-size:11px">نام کاربری قابل تغییر نیست</small>
            </div>
            <div class="form-group">
              <label class="form-label">ایمیل</label>
              <input type="email" class="form-control" id="settings-email" value="<?= Helpers::e($user['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">نقش</label>
              <input type="text" class="form-control" value="<?= Helpers::e($user['role'] ?? 'staff') ?>" disabled style="opacity:0.6">
            </div>
            <button class="btn btn-primary" onclick="saveProfile()">💾 ذخیره تغییرات</button>
          </div>
        </div>

        <!-- تغییر رمز عبور -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">🔑 تغییر رمز عبور</div>
          <div class="ss-body">
            <div class="form-group">
              <label class="form-label">رمز فعلی</label>
              <input type="password" class="form-control" id="current-password" placeholder="••••••••">
            </div>
            <div class="form-group">
              <label class="form-label">رمز جدید</label>
              <input type="password" class="form-control" id="new-password" placeholder="حداقل ۴ کاراکتر">
            </div>
            <div class="form-group">
              <label class="form-label">تکرار رمز جدید</label>
              <input type="password" class="form-control" id="confirm-password" placeholder="تکرار رمز جدید">
            </div>
            <button class="btn btn-primary" onclick="changePassword()">🔐 تغییر رمز</button>
          </div>
        </div>

        <!-- تنظیمات برند -->
        <div class="settings-section">
          <div class="ss-header">🎨 تنظیمات برند</div>
          <div class="ss-body">
            <div class="form-group">
              <label class="form-label">نام سیستم</label>
              <input type="text" class="form-control" id="setting-system_name" value="<?= Helpers::e($settings['system_name'] ?? 'جاکجاست') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">رنگ اصلی</label>
              <div style="display:flex;gap:8px;align-items:center">
                <input type="color" id="setting-primary_color" value="<?= Helpers::e($settings['primary_color'] ?? '#e50241') ?>" style="width:60px;height:36px;border-radius:8px;border:1px solid var(--border);cursor:pointer" onchange="document.documentElement.style.setProperty('--primary',this.value)">
                <input type="text" id="setting-primary_color_text" value="<?= Helpers::e($settings['primary_color'] ?? '#e50241') ?>" style="flex:1" class="form-control" oninput="document.getElementById('setting-primary_color').value=this.value;document.documentElement.style.setProperty('--primary',this.value)">
              </div>
            </div>
            <button class="btn btn-primary" onclick="saveSettings(['system_name','primary_color'])">💾 ذخیره تنظیمات برند</button>
          </div>
        </div>
<?php endif; ?>
      </div>

      <!-- ===========================
           ستون چپ - تلفنچی و مالی
           =========================== -->
      <div>
        <!-- تنظیمات تلفنچی -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">📞 تنظیمات تلفنچی (Telefonchy)</div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
              📖 این مقادیر از <a href="https://telefonchy.com" target="_blank" style="color:var(--primary)">پنل تلفنچی</a> قابل دریافت هستند.
              پس از ثبت، سیستم قادر به همگام‌سازی تماس‌ها و دریافت فایل‌های صوتی خواهد بود.
            </p>
            <div class="form-group">
              <label class="form-label">شناسه سرویس (Service ID)</label>
              <input type="text" class="form-control" id="setting-telefonchy_service_id" value="<?= Helpers::e($settings['telefonchy_service_id'] ?? '') ?>" placeholder="مثلاً: 12345">
            </div>
            <div class="form-group">
              <label class="form-label">توکن وب‌سرویس (Webservice Token)</label>
              <input type="password" class="form-control" id="setting-telefonchy_token" value="<?= Helpers::e($settings['telefonchy_token'] ?? '') ?>" placeholder="توکنlong از پنل تلفنچی">
              <small style="color:var(--text-muted);font-size:11px">این توکن در هدر <code>webservice-token</code> ارسال می‌شود</small>
            </div>
            <div class="form-group">
              <label class="form-label">آدرس پایه API</label>
              <input type="text" class="form-control" id="setting-telefonchy_base_url" value="<?= Helpers::e($settings['telefonchy_base_url'] ?? 'https://panel.telefonchy.com/webservice/v1') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">توکن وب‌هوک (اختیاری)</label>
              <input type="password" class="form-control" id="setting-telefonchy_webhook_token" value="<?= Helpers::e($settings['telefonchy_webhook_token'] ?? '') ?>" placeholder="فقط اگر تلفنچی در وب‌هوک توکنی غیر از توکن وب‌سرویس می‌فرستد">
              <small style="color:var(--text-muted);font-size:11px">مقدار <code>webservice_token</code> داخل رویدادها با این توکن یا توکن وب‌سرویس بالا مقایسه می‌شود.</small>
            </div>
            <div class="form-group">
              <label class="form-label">اعتبارسنجی وب‌هوک تماس</label>
              <?php $__wa = ($settings['telefonchy_webhook_auth'] ?? '') === 'monitor' ? 'monitor' : 'enforce'; ?>
              <select class="form-control" id="setting-telefonchy_webhook_auth">
                <option value="enforce" <?= $__wa === 'enforce' ? 'selected' : '' ?>>سخت‌گیرانه — رویداد بدون توکن یا با توکن اشتباه رد شود (پیشنهادی)</option>
                <option value="monitor" <?= $__wa === 'monitor' ? 'selected' : '' ?>>فقط پایش — پردازش شود و در لاگ ثبت شود (ناامن، موقت)</option>
              </select>
              <small style="color:var(--text-muted);font-size:11px">رویداد ردشده از دست نمی‌رود؛ همگام‌سازی دوره‌ای (cron) تماس را از API تلفنچی می‌گیرد. علت رد در error.log با «Telefonchy webhook: auth» ثبت می‌شود.</small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-primary" onclick="saveSettings(['telefonchy_service_id','telefonchy_token','telefonchy_base_url','telefonchy_webhook_token','telefonchy_webhook_auth'])">💾 ذخیره</button>
              <button class="btn btn-light" onclick="testTelefonchy()">🧪 تست اتصال</button>
              <button class="btn btn-light" onclick="runDbMigrate()" id="migrate-btn" title="بعد از جایگزین کردن فایل‌های PHP جدید، این را بزنید تا ستون‌ها/جدول‌های جدید دیتابیس ساخته شوند">🛠 به‌روزرسانی جداول دیتابیس</button>
              <button class="btn btn-light" onclick="runReconcileStaleCalls()" id="reconcile-btn" title="تماس‌هایی که به‌خاطر نرسیدن وب‌هوک hangup روی «در حال زنگ خوردن» گیر کرده‌اند را همین الان (بدون صبر برای کرون) اصلاح می‌کند">🔄 اصلاح تماس‌های گیرکرده</button>
            </div>
          </div>
        </div>

<?php if (!$__br): ?>
        <!-- تنظیمات اعلان‌بان -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">💬 تنظیمات اعلان‌بان (Elanban)</div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
              📖 برای نمایش تاریخچه‌ی چت اینستاگرام/وب‌چت سایت کنار تماس‌ها در پروفایل مشتری و سرنخ.
              توکن باید دقیقاً همان مقداری باشد که سمت اعلان‌بان در <code>config.php</code> زیر کلید
              <code>jakojast_integration_token</code> تنظیم شده.
            </p>
            <div class="form-group">
              <label class="form-label">آدرس پایه‌ی نصب اعلان‌بان</label>
              <input type="text" class="form-control" id="setting-elanban_base_url" value="<?= Helpers::e($settings['elanban_base_url'] ?? '') ?>" placeholder="مثلاً: https://example.com/elanban">
              <small style="color:var(--text-muted);font-size:11px">بدون / در انتها؛ همان <code>base_url</code>ی که در config.php اعلان‌بان تنظیم شده</small>
            </div>
            <div class="form-group">
              <label class="form-label">توکن داخلی (Internal Token) - برای درخواست‌های ما به اعلان‌بان</label>
              <input type="password" class="form-control" id="setting-elanban_token" value="<?= Helpers::e($settings['elanban_token'] ?? '') ?>" placeholder="مقدار jakojast_integration_token در config.php اعلان‌بان">
              <small style="color:var(--text-muted);font-size:11px">این توکن در هدر <code>X-Internal-Token</code> ارسال می‌شود؛ توکن عمومی/کاربری نیست</small>
            </div>
            <div class="form-group">
              <label class="form-label">توکن ورودی (Incoming Token) - برای درخواست‌های اعلان‌بان به ما</label>
              <input type="password" class="form-control" id="setting-elanban_incoming_token" value="<?= Helpers::e($settings['elanban_incoming_token'] ?? '') ?>" placeholder="یک رشته‌ی تصادفی جدا - باید در تنظیمات اعلان‌بان هم وارد شود">
              <small style="color:var(--text-muted);font-size:11px">جهت برعکس: وقتی اعلان‌بان از <code>api_external/elanban_lookup.php</code> ما می‌پرسد. عمداً توکنی جدا از بالا - نشتِ یکی باعث دسترسی دوطرفه نمی‌شود</small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn btn-primary" onclick="saveSettings(['elanban_base_url','elanban_token','elanban_incoming_token'])">💾 ذخیره</button>
              <button class="btn btn-light" onclick="testElanban()">🧪 تست اتصال</button>
            </div>
            <div id="elanban-test-result" style="margin-top:8px;font-size:13px"></div>
            <div id="migrate-result" style="display:none;margin-top:10px;font-size:12px;border-radius:8px;padding:10px"></div>
          </div>
        </div>

<?php endif; ?>
<?php if ($__br): ?><div id="migrate-result" style="display:none;margin:-6px 0 16px;font-size:12px;border-radius:8px;padding:10px"></div><?php endif; ?>
        <!-- اتصال دیتابیس سایت جاکجاست -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">🌐 اتصال دیتابیس سایت (مشتریان / میزبانان / استعلام / فروش)</div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
              برای همگام‌سازی خودکار داده از دیتابیس سایت اصلی (که معمولاً روی همین سرور است).
              نام جداول را مطابق ساختار واقعی سایت تنظیم کنید؛ سپس «تست اتصال» و «همگام‌سازی» را بزنید.
              بعد از اولین همگام‌سازی، از منوی CRM بخش‌های «استعلام‌های سایت» و «فروش سایت» را ببینید.
            </p>
            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">هاست دیتابیس سایت</label>
                <input type="text" class="form-control" id="setting-site_db_host" value="<?= Helpers::e($settings['site_db_host'] ?? 'localhost') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">پورت</label>
                <input type="text" class="form-control" id="setting-site_db_port" value="<?= Helpers::e($settings['site_db_port'] ?? '3306') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">نام دیتابیس</label>
                <input type="text" class="form-control" id="setting-site_db_name" value="<?= Helpers::e($settings['site_db_name'] ?? '') ?>" placeholder="مثلاً jakojast_site">
              </div>
              <div class="form-group">
                <label class="form-label">کاربر</label>
                <input type="text" class="form-control" id="setting-site_db_user" value="<?= Helpers::e($settings['site_db_user'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">رمز عبور</label>
                <input type="password" class="form-control" id="setting-site_db_pass" value="<?= Helpers::e($settings['site_db_pass'] ?? '') ?>">
              </div>
            </div>
            <div class="grid-2" style="margin-top:8px">
              <div class="form-group"><label class="form-label">جدول مشتریان/کاربران</label><input class="form-control" id="setting-site_table_customers" value="<?= Helpers::e($settings['site_table_customers'] ?? 'users') ?>"></div>
              <div class="form-group"><label class="form-label">جدول میزبانان</label><input class="form-control" id="setting-site_table_hosts" value="<?= Helpers::e($settings['site_table_hosts'] ?? 'hosts') ?>"></div>
              <div class="form-group"><label class="form-label">جدول اقامتگاه‌ها</label><input class="form-control" id="setting-site_table_properties" value="<?= Helpers::e($settings['site_table_properties'] ?? 'properties') ?>"></div>
              <div class="form-group"><label class="form-label">جدول استعلام‌ها</label><input class="form-control" id="setting-site_table_inquiries" value="<?= Helpers::e($settings['site_table_inquiries'] ?? 'inquiries') ?>"></div>
              <div class="form-group"><label class="form-label">جدول فروش/سفارش</label><input class="form-control" id="setting-site_table_sales" value="<?= Helpers::e($settings['site_table_sales'] ?? 'orders') ?>"></div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
              <button class="btn btn-primary" onclick="saveSiteDbSettings()">💾 ذخیره</button>
              <button class="btn btn-light" onclick="testSiteDb()">🧪 تست اتصال</button>
              <button class="btn btn-success" onclick="runSiteSync()" id="site-sync-btn">🔄 همگام‌سازی الان</button>
            </div>
            <div id="site-sync-result" style="margin-top:10px;font-size:12px"></div>
            <?php if (!empty($settings['site_last_sync_at'])): ?>
              <div style="margin-top:6px;font-size:11px;color:var(--text-muted)">آخرین همگام‌سازی: <?= Helpers::e(Jalali::fromGregorian($settings['site_last_sync_at'], 'Y/m/d H:i')) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- پنل پیامک -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">📱 پیامک خودکار تماس بی‌پاسخ</div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
              برای ارسال پیامک به تماس‌گیرنده‌های بی‌پاسخ. سرویس‌های پشتیبانی‌شده: کاوه‌نگار، ملی‌پیامک، یا وب‌سرویس سفارشی.
              کرون هر ۵ دقیقه صف را پردازش می‌کند.
            </p>
<?php if ($__br): ?><div style="font-size:12px;background:rgba(0,128,96,.08);border-radius:8px;padding:8px 10px;margin-bottom:10px">پیامک‌ها با سرویس پیامک اعلان‌بان فرستاده می‌شوند و در صندوق ورودی همان مشتری ثبت می‌شوند.</div><?php endif; ?>
            <div <?= $__br ? 'hidden' : '' ?>>
            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">سرویس‌دهنده</label>
                <select class="form-control" id="setting-sms_provider">
                  <option value="" <?= ($settings['sms_provider'] ?? '') === '' ? 'selected' : '' ?>>— انتخاب کنید —</option>
                  <option value="kavenegar" <?= ($settings['sms_provider'] ?? '') === 'kavenegar' ? 'selected' : '' ?>>کاوه‌نگار (Kavenegar)</option>
                  <option value="melipayamak" <?= ($settings['sms_provider'] ?? '') === 'melipayamak' ? 'selected' : '' ?>>ملی‌پیامک</option>
                  <option value="generic" <?= ($settings['sms_provider'] ?? '') === 'generic' ? 'selected' : '' ?>>وب‌سرویس سفارشی (JSON POST)</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">کلید API / نام کاربری</label>
                <input type="password" class="form-control" id="setting-sms_api_key" value="<?= Helpers::e($settings['sms_api_key'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">رمز عبور (ملی‌پیامک)</label>
                <input type="password" class="form-control" id="setting-sms_password" value="<?= Helpers::e($settings['sms_password'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">خط فرستنده</label>
                <input type="text" class="form-control" id="setting-sms_sender" value="<?= Helpers::e($settings['sms_sender'] ?? '') ?>" placeholder="مثلاً 10008663">
              </div>
              <div class="form-group" style="grid-column:1/-1">
                <label class="form-label">آدرس وب‌سرویس (فقط generic)</label>
                <input type="text" class="form-control" id="setting-sms_generic_url" value="<?= Helpers::e($settings['sms_generic_url'] ?? '') ?>" placeholder="https://...">
              </div>
            </div>
            </div>
            <hr style="margin:14px 0;border:none;border-top:1px solid var(--border)">
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" id="setting-sms_missed_enabled" value="1" <?= ($settings['sms_missed_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                ارسال خودکار پیامک برای تماس‌های بی‌پاسخ ورودی
              </label>
            </div>
            <div class="grid-2">
              <div class="form-group">
                <label class="form-label">تأخیر ارسال (دقیقه بعد از تماس)</label>
                <input type="number" min="0" max="120" class="form-control" id="setting-sms_missed_delay_minutes" value="<?= Helpers::e($settings['sms_missed_delay_minutes'] ?? '2') ?>">
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">متن پیامک بی‌پاسخ</label>
              <textarea class="form-control" id="setting-sms_missed_template" rows="3" placeholder="از {name} و {phone} می‌توانید استفاده کنید"><?= Helpers::e($settings['sms_missed_template'] ?? 'سلام، با جاکجاست تماس گرفته بودید اما پاسخ داده نشد. به‌زودی با شما تماس می‌گیریم. jakojast.com') ?></textarea>
              <small style="color:var(--text-muted);font-size:11px">متغیرها: {name} نام مخاطب، {phone} شماره</small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
              <button class="btn btn-primary" onclick="saveSmsSettings()">💾 ذخیره پیامک</button>
              <input type="text" id="sms-test-to" class="form-control" style="width:150px" placeholder="09xxxxxxxxx" dir="ltr">
              <button class="btn btn-light" onclick="testSmsSend()">🧪 ارسال تست</button>
              <button class="btn btn-success" onclick="processMissedSmsNow()">▶️ اجرای صف بی‌پاسخ الان</button>
            </div>
            <div id="sms-settings-result" style="margin-top:10px;font-size:12px"></div>
          </div>
        </div>

        <!-- تنظیمات تبدیل صدا به متن -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">🎙 تبدیل صدا به متن مکالمات</div>
          <div class="ss-body">
            <div class="form-group">
              <label class="form-label">روش تبدیل</label>
              <select class="form-control" id="setting-transcription_provider" onchange="checkVoskHealth()">
                <option value="local" <?= ($settings['transcription_provider'] ?? 'local') === 'local' ? 'selected' : '' ?>>محلی و رایگان - Whisper (کیفیت متوسط، نیاز به کامپایل C++)</option>
                <option value="vosk" <?= ($settings['transcription_provider'] ?? '') === 'vosk' ? 'selected' : '' ?>>محلی و رایگان - Vosk (سبک‌تر، بدون کامپایل، تبدیل فوری بعد از هر تماس)</option>
                <option value="groq" <?= ($settings['transcription_provider'] ?? '') === 'groq' ? 'selected' : '' ?>>Groq (ابری، کیفیت بالا Whisper large-v3، ~$۰.۰۴ در ساعت)</option>
                <option value="gapgpt" <?= ($settings['transcription_provider'] ?? '') === 'gapgpt' ? 'selected' : '' ?>>GapGPT (ابری، Whisper، ریال ایرانی - بدون نیاز به کارت خارجی)</option>
                <option value="openai" <?= ($settings['transcription_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (ابری، کیفیت بالا، ~$۰.۳۶ در ساعت - گران‌تر)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">آدرس سرویس Vosk worker (فقط اگر روش «Vosk» انتخاب شده)</label>
              <input type="text" class="form-control" id="setting-vosk_worker_url" value="<?= Helpers::e($settings['vosk_worker_url'] ?? 'http://127.0.0.1:8899') ?>" placeholder="http://127.0.0.1:8899">
              <p style="font-size:11px;color:var(--text-muted);margin-top:4px">راهنمای راه‌اندازی: docs/VOSK_TRANSCRIPTION_SETUP.md</p>
            </div>
            <div class="form-group">
              <label class="form-label">کلید API گروک (Groq) - از console.groq.com</label>
              <input type="password" class="form-control" id="setting-groq_api_key" value="<?= Helpers::e($settings['groq_api_key'] ?? '') ?>" placeholder="gsk_...">
            </div>
            <div class="form-group">
              <label class="form-label">کلید API گپ‌جی‌پی‌تی (GapGPT) - از api.gapgpt.app</label>
              <input type="password" class="form-control" id="setting-gapgpt_api_key" value="<?= Helpers::e($settings['gapgpt_api_key'] ?? '') ?>" placeholder="sk-...">
            </div>
            <div class="form-group">
              <label class="form-label">مدل تبدیل صدا به متن GapGPT</label>
              <select class="form-control" id="setting-gapgpt_transcription_model">
                <option value="gapgpt/whisper-1" <?= ($settings['gapgpt_transcription_model'] ?? 'gapgpt/whisper-1') === 'gapgpt/whisper-1' ? 'selected' : '' ?>>gapgpt/whisper-1 ($۲۴ در میلیون توکن - ارزان‌تر)</option>
                <option value="whisper-1" <?= ($settings['gapgpt_transcription_model'] ?? '') === 'whisper-1' ? 'selected' : '' ?>>whisper-1 ($۳۰ در میلیون توکن)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">کلید API اوپن‌ای‌آی (اختیاری)</label>
              <input type="password" class="form-control" id="setting-openai_api_key" value="<?= Helpers::e($settings['openai_api_key'] ?? '') ?>" placeholder="sk-...">
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.7">
              ⚠️ در حالت‌های ابری (Groq/GapGPT/OpenAI)، فایل صوتی مکالمه به سرور خارجی ارسال می‌شود. حالت‌های محلی (Whisper/Vosk) هیچ داده‌ای را از سرور شما خارج نمی‌کنند.
            </p>
            <div id="transcription-cost-estimate" style="font-size:12px;color:var(--text-muted);margin-bottom:10px"></div>
            <div id="vosk-health-status" style="font-size:12px;margin-bottom:10px"></div>
            <button class="btn btn-primary btn-sm" onclick="saveSettings(['transcription_provider','vosk_worker_url','groq_api_key','gapgpt_api_key','gapgpt_transcription_model','openai_api_key'])">💾 ذخیره</button>
          </div>
        </div>

        <!-- لاگ حرفه‌ای تعاملات با تلفنچی -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center">
            <span>📋 لاگ تعاملات با تلفنچی</span>
            <div style="display:flex;gap:6px">
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px" onclick="loadTelefonchyLogs(true)">فقط خطاها</button>
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px" onclick="loadTelefonchyLogs(false)">همه</button>
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px;color:var(--danger)" onclick="clearTelefonchyLogs()">پاک کردن</button>
            </div>
          </div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.7">
              هر درخواستی که به تلفنچی می‌رود (و هر webhook که از تلفنچی می‌رسد) این‌جا با جزئیات کامل (آدرس، کد HTTP، زمان پاسخ، پیام خطا) ثبت می‌شود. اگر همگام‌سازی یا تست اتصال با خطا مواجه شد، این لیست را چک کنید.
            </p>
            <div id="telefonchy-logs-box">
              <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
            </div>
          </div>
        </div>

        <!-- مشاهده‌ی خام payload های webhook (برای دیدن دقیقاً چیزی که تلفنچی می‌فرستد) -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center">
            <span>🔬 دریافتی خام webhook (Raw Payload)</span>
            <div style="display:flex;gap:6px">
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px" onclick="loadRawWebhookEvents()">🔄 تازه‌سازی</button>
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px;color:var(--danger)" onclick="clearRawWebhookEvents()">پاک کردن</button>
            </div>
          </div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.7">
              دقیقاً همان JSON خامی که تلفنچی به <code>webhook.php</code> می‌فرستد (بدون هیچ تفسیر یا پردازشی)، برای وقتی که لازم است ۱۰۰٪ مطمئن شوید تلفنچی واقعاً چه ساختاری ارسال می‌کند. روی هر ردیف کلیک کنید تا JSON کامل باز شود.
            </p>
            <div id="raw-webhook-events-box">
              <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
            </div>
          </div>
        </div>

        <!-- لاگ خطاهای سرور (logs/error.log) -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center">
            <span>🪵 لاگ خطاهای سرور (logs/error.log)</span>
            <div style="display:flex;gap:6px;align-items:center">
              <span id="error-log-size" style="font-size:11px;color:var(--text-muted)"></span>
              <button class="btn btn-light" style="padding:4px 10px;font-size:12px" onclick="loadErrorLog()">🔄 تازه‌سازی</button>
            </div>
          </div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.7">
              همه‌ی خطاهایی که کد در پس‌زمینه ثبت می‌کند (مثلاً backfill صدا، migration، خطاهای webhook) اینجا هستند - بدون نیاز به FTP/SSH. جدیدترین بالا.
            </p>
            <div id="error-log-box" style="max-height:400px;overflow-y:auto;background:var(--bg-secondary);border-radius:8px;padding:10px;font-size:11px;font-family:monospace;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all">
              در حال بارگذاری...
            </div>
          </div>
        </div>

        <!-- اتصال داخلی‌های تلفنچی به پرسنل -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">🔗 اتصال داخلی‌ها به پرسنل (اپراتورها)</div>
          <div class="ss-body">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.7">
              با اتصال هر شماره داخلی به یک نفر از پرسنل، در لیست تماس‌ها و جزئیات هر تماس، به‌جای شماره خام، نام همان اپراتور نشان داده می‌شود.
            </p>
            <?php if (empty($telefonchyExtensions)): ?>
              <p style="color:var(--text-muted);font-size:12px">هنوز هیچ داخلی‌ای از تماس‌های همگام‌شده دیده نشده. اول یک‌بار همگام‌سازی را در صفحه «تماس‌ها» انجام دهید.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table class="data-table">
                  <thead><tr><th>داخلی</th><th>نام ثبت‌شده در تلفنچی</th><th>پرسنل مرتبط</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($telefonchyExtensions as $ext): ?>
                      <?php
                        $matchedStaff = null;
                        foreach ($allStaffForExtens as $s) {
                            if ((string)($s['exten_number'] ?? '') === (string)$ext['exten_number']) { $matchedStaff = $s; break; }
                        }
                      ?>
                      <tr>
                        <td dir="ltr"><strong><?= Helpers::e($ext['exten_number']) ?></strong></td>
                        <td><?= Helpers::e($ext['exten_name'] ?: '-') ?></td>
                        <td>
                          <select class="form-control exten-map-select" data-exten="<?= Helpers::e($ext['exten_number']) ?>" style="min-width:160px">
                            <option value="">— متصل نیست —</option>
                            <?php foreach ($allStaffForExtens as $s): ?>
                              <option value="<?= (int)$s['id'] ?>" <?= ($matchedStaff && (int)$matchedStaff['id'] === (int)$s['id']) ? 'selected' : '' ?>><?= Helpers::e($s['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td><button class="btn btn-primary btn-xs" onclick="saveExtenMapping('<?= Helpers::e($ext['exten_number']) ?>', this)">ذخیره</button></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- تنظیمات مالی -->
        <div class="settings-section" style="margin-bottom:16px">
          <div class="ss-header">💰 تنظیمات مالی</div>
          <div class="ss-body">
            <div class="form-group">
              <label class="form-label">درصد کمیسیون جاکجاست</label>
              <input type="number" class="form-control" id="setting-commission_rate" value="<?= Helpers::e($settings['commission_rate'] ?? '20') ?>" min="0" max="100">
            </div>
            <div class="form-group">
              <label class="form-label">حداقل مبلغ تسویه (تومان)</label>
              <input type="number" class="form-control" id="setting-min_settlement_amount" value="<?= Helpers::e($settings['min_settlement_amount'] ?? '1000000') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">روز تسویه هر ماه</label>
              <input type="number" class="form-control" id="setting-settlement_day" value="<?= Helpers::e($settings['settlement_day'] ?? '15') ?>" min="1" max="31">
            </div>
            <button class="btn btn-primary" onclick="saveSettings(['commission_rate','min_settlement_amount','settlement_day'])">💾 ذخیره تنظیمات مالی</button>
          </div>
        </div>

        <!-- اطلاعات سیستم -->
        <div class="settings-section">
          <div class="ss-header">ℹ️ اطلاعات سیستم</div>
          <div class="ss-body">
            <div style="font-size:13px;line-height:2">
              <div>📦 <strong>نسخه:</strong> <?= Helpers::e($settings['app_version'] ?? '3.0.0') ?></div>
              <div>🕒 <strong>تاریخ نصب:</strong> <?= !empty($settings['installed_at']) ? Jalali::fromGregorian($settings['installed_at'], 'Y/m/d H:i') : '-' ?></div>
              <div>👤 <strong>کاربر جاری:</strong> <?= Helpers::e($user['full_name'] ?? '') ?></div>
              <div>🎭 <strong>نقش:</strong> <?= Helpers::e($user['role'] ?? 'staff') ?></div>
              <div>🕒 <strong>آخرین ورود:</strong> <?= !empty($user['last_login']) ? Jalali::fromGregorian($user['last_login'], 'Y/m/d H:i') : 'این اولین ورود است' ?></div>
            </div>
            <hr style="border:none;border-top:1px solid var(--border);margin:16px 0">
            <button class="btn btn-danger" onclick="if(confirm('آیا از خروج اطمینان دارید؟')) logout()">🚪 خروج از سیستم</button>
          </div>
        </div>
      </div>

    </div>

<?php if (!$__br): ?>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <!-- مدیریت کاربران و دسترسی‌ها (فقط ادمین) -->
    <div class="settings-section" style="margin-top:16px">
      <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <span>👥 کاربران و دسترسی‌ها</span>
        <div style="display:flex;gap:8px">
          <button class="btn btn-light btn-sm" onclick="generateUsersForAllStaff()" title="برای هر پرسنلی که هنوز حساب کاربری ندارد، یک حساب با نام کاربری بر اساس کد پرسنلی می‌سازد">🔁 ساخت خودکار کاربر برای همه‌ی پرسنل</button>
          <button class="btn btn-primary btn-sm" onclick="openUserModal()">+ کاربر جدید</button>
        </div>
      </div>
      <div class="ss-body">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
          نقش <strong>admin</strong> همیشه دسترسی کامل دارد. برای نقش‌های <strong>manager</strong> و <strong>staff</strong>، فقط ماژول‌هایی که این‌جا فعال کنید برایشان قابل مشاهده خواهد بود.
        </p>
        <div id="users-list-box">
          <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

<?php endif; ?>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <!-- مدیریت مراجع سرنخ (منبع ورودی: سایت، اینستاگرام، معرف، ...) -->
    <div class="settings-section" style="margin-top:16px">
      <div class="ss-header" style="display:flex;justify-content:space-between;align-items:center">
        <span>🔖 مراجع سرنخ</span>
        <button class="btn btn-primary btn-sm" onclick="openReferenceModal()">+ مرجع جدید</button>
      </div>
      <div class="ss-body">
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.7">
          این لیست، همون گزینه‌های دراپ‌داون «مرجع» توی فرم سرنخ‌های میهمانه. حذف واقعی نمی‌کنه (فقط غیرفعال می‌شه) تا سرنخ‌های قدیمی بی‌معنی نشن.
        </p>
        <div id="references-list-box">
          <p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>


</main>

<script>
// ذخیره پروفایل کاربر
async function saveProfile() {
    const name = document.getElementById('settings-name').value.trim();
    const email = document.getElementById('settings-email').value.trim();

    if (!name) {
        showToast('نام کامل الزامی است', 'warning');
        return;
    }

    try {
        // در مرحله ۸ این endpoint کامل می‌شود؛ فعلا فقط نمایش پیام
        showToast('اطلاعات پروفایل ذخیره شد (در مرحله ۸ تکمیل می‌شود)', 'success');
    } catch (err) {
        showToast('خطا در ذخیره', 'error');
    }
}

// تغییر رمز عبور
async function changePassword() {
    const current = document.getElementById('current-password').value;
    const newPass = document.getElementById('new-password').value;
    const confirm = document.getElementById('confirm-password').value;

    if (!current || !newPass || !confirm) {
        showToast('تمام فیلدها الزامی است', 'warning');
        return;
    }
    if (newPass !== confirm) {
        showToast('رمز جدید و تکرار آن یکسان نیستند', 'error');
        return;
    }
    if (newPass.length < 4) {
        showToast('رمز جدید باید حداقل ۴ کاراکتر باشد', 'warning');
        return;
    }

    try {
        const result = await fetchAPI('auth/change-password', {
            method: 'POST',
            body: { current_password: current, new_password: newPass }
        });
        if (result.success) {
            showToast('رمز عبور با موفقیت تغییر کرد', 'success');
            document.getElementById('current-password').value = '';
            document.getElementById('new-password').value = '';
            document.getElementById('confirm-password').value = '';
        } else {
            showToast(result.message || 'خطا در تغییر رمز', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// ذخیره تنظیمات سیستم
async function saveSettings(keys) {
    const data = {};
    keys.forEach(k => {
        const el = document.getElementById('setting-' + k);
        if (el) data[k] = el.value;
    });

    try {
        const result = await fetchAPI('settings', {
            method: 'PUT',
            body: data
        });
        if (result.success) {
            // ⚠️ قبلاً همیشه پیام ثابت «تنظیمات ذخیره شد» نشون داده می‌شد،
            // حتی وقتی بک‌اند پیام دقیق‌تری (مثلاً هشدار درباره‌ی کلیدهای
            // ناشناخته که ذخیره نشدند) برگردونده بود.
            const hadRejected = result.data && result.data.rejected && result.data.rejected.length;
            showToast(result.message || 'تنظیمات ذخیره شد', hadRejected ? 'warning' : 'success');
        } else {
            showToast(result.message || 'خطا در ذخیره', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// اجرای دستی migration دیتابیس
// ⚠️ نتیجه الان واضح نمایش داده می‌شود: کدام ستون‌ها *واقعاً* تازه ساخته
// شدند (نه یک پیام ثابت «انجام شد»)، و اگر خطایی بود، متن دقیق خطا -
// بدون نیاز به دسترسی به logs/error.log روی سرور.
async function runDbMigrate() {
    const btn = document.getElementById('migrate-btn');
    const box = document.getElementById('migrate-result');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال به‌روزرسانی...';
    box.style.display = 'none';

    try {
        const result = await fetchAPI('settings/migrate', { method: 'POST' });
        const added = (result.data && result.data.added) || [];
        const errors = (result.data && result.data.errors) || [];

        box.style.display = 'block';
        box.style.background = errors.length ? 'rgba(239,68,68,0.08)' : 'rgba(34,197,94,0.08)';
        box.style.border = '1px solid ' + (errors.length ? 'rgba(239,68,68,0.3)' : 'rgba(34,197,94,0.3)');

        let html = '<div style="font-weight:600;margin-bottom:6px">' + (result.message || '') + '</div>';
        if (added.length) {
            html += '<div style="margin-bottom:4px">✅ ستون‌های تازه ساخته‌شده:</div><ul style="margin:0;padding-right:18px">';
            added.forEach(a => html += '<li dir="ltr" style="text-align:right">' + a + '</li>');
            html += '</ul>';
        } else if (!errors.length) {
            html += '<div style="color:var(--text-muted)">همه‌ی جداول از قبل به‌روز بودند - چیزی برای ساختن نبود.</div>';
        }
        if (errors.length) {
            html += '<div style="margin-top:8px;color:var(--danger)">❌ خطاها:</div><ul style="margin:0;padding-right:18px;color:var(--danger)">';
            errors.forEach(e => html += '<li>' + e + '</li>');
            html += '</ul>';
        }
        box.innerHTML = html;

        showToast(result.success ? '✅ ' + result.message : (result.message || 'خطا در به‌روزرسانی'), errors.length ? 'warning' : 'success');
    } catch (err) {
        box.style.display = 'block';
        box.style.background = 'rgba(239,68,68,0.08)';
        box.innerHTML = '❌ خطا در ارتباط با سرور';
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🛠 به‌روزرسانی جداول دیتابیس';
    }
}

// تست اتصال به تلفنچی
async function testTelefonchy() {
    showToast('در حال ذخیره و تست اتصال...', 'info');
    await saveSettings(['telefonchy_service_id', 'telefonchy_token', 'telefonchy_base_url']);

    try {
        const result = await fetchAPI('sync/test', { method: 'GET' });
        if (result.success) {
            showToast('✅ اتصال به تلفنچی برقرار است', 'success');
        } else {
            showToast('❌ ' + (result.message || 'خطا در اتصال به تلفنچی'), 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور جاکجاست (نه تلفنچی) - Console را چک کنید', 'error');
        console.error(err);
    }
    loadTelefonchyLogs();
}

async function saveSiteDbSettings() {
    await saveSettings([
        'site_db_host','site_db_port','site_db_name','site_db_user','site_db_pass',
        'site_table_customers','site_table_hosts','site_table_properties',
        'site_table_inquiries','site_table_sales'
    ]);
}
async function testSiteDb() {
    const box = document.getElementById('site-sync-result');
    await saveSiteDbSettings();
    box.innerHTML = 'در حال تست...';
    try {
        const result = await fetchAPI('site-sync/test', { method: 'GET' });
        const d = result.data || {};
        if (d.ok) {
            box.innerHTML = '<span style="color:var(--success)">✅ ' + (d.message || 'اتصال موفق') + '</span>' +
                (d.tables ? '<div style="margin-top:6px;color:var(--text-muted)">جداول: ' + d.tables.slice(0,30).join(', ') + (d.tables.length>30?'…':'') + '</div>' : '');
            showToast('اتصال سایت برقرار است', 'success');
        } else {
            box.innerHTML = '<span style="color:var(--danger)">❌ ' + (d.message || result.message || 'ناموفق') + '</span>';
            showToast('اتصال ناموفق', 'error');
        }
    } catch (e) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در ارتباط</span>';
    }
}
async function runSiteSync() {
    const box = document.getElementById('site-sync-result');
    const btn = document.getElementById('site-sync-btn');
    await saveSiteDbSettings();
    btn.disabled = true;
    btn.textContent = 'در حال همگام‌سازی...';
    box.innerHTML = '⏳ همگام‌سازی ممکن است چند لحظه طول بکشد...';
    try {
        const result = await fetchAPI('site-sync/run', { method: 'POST', body: {} });
        if (result.success) {
            const stats = result.data || {};
            const lines = Object.keys(stats).map(k => {
                const s = stats[k] || {};
                if (s.error) return k + ': خطا — ' + s.error;
                return k + ': ساخته‌شده ' + (s.created||0) + ' / به‌روز ' + (s.updated||0) + ' / اسکن ' + (s.scanned||0);
            });
            box.innerHTML = '<span style="color:var(--success)">✅ ' + (result.message||'انجام شد') + '</span><pre style="margin-top:8px;white-space:pre-wrap;font-size:11px">' + lines.join('\n') + '</pre>';
            showToast('همگام‌سازی سایت انجام شد', 'success');
        } else {
            box.innerHTML = '<span style="color:var(--danger)">❌ ' + (result.message||'خطا') + '</span>';
            showToast(result.message || 'خطا', 'error');
        }
    } catch (e) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در ارتباط با سرور</span>';
    } finally {
        btn.disabled = false;
        btn.textContent = '🔄 همگام‌سازی الان';
    }
}

const SMS_SETTING_KEYS = [
    'sms_provider','sms_api_key','sms_password','sms_sender','sms_generic_url',
    'sms_missed_enabled','sms_missed_template','sms_missed_delay_minutes'
];
async function saveSmsSettings() {
    const body = {};
    SMS_SETTING_KEYS.forEach(k => {
        const el = document.getElementById('setting-' + k);
        if (!el) return;
        if (el.type === 'checkbox') body[k] = el.checked ? '1' : '0';
        else body[k] = el.value;
    });
    const result = await fetchAPI('settings', { method: 'PUT', body });
    if (result.success) showToast('تنظیمات پیامک ذخیره شد', 'success');
    else showToast(result.message || 'خطا در ذخیره', 'error');
    return result;
}
async function testSmsSend() {
    const box = document.getElementById('sms-settings-result');
    const to = (document.getElementById('sms-test-to') || {}).value || '';
    if (!to.trim()) { showToast('شماره تست را وارد کنید', 'error'); return; }
    await saveSmsSettings();
    box.innerHTML = '⏳ در حال ارسال...';
    try {
        const result = await fetchAPI('sms/test', { method: 'POST', body: { to: to.trim() } });
        if (result.success) {
            box.innerHTML = '<span style="color:var(--success)">✅ ' + (result.message || 'ارسال شد') + '</span>';
            showToast('پیامک تست ارسال شد', 'success');
        } else {
            box.innerHTML = '<span style="color:var(--danger)">❌ ' + (result.message || 'ناموفق') + '</span>';
            showToast(result.message || 'خطا', 'error');
        }
    } catch (e) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در ارتباط</span>';
    }
}
async function processMissedSmsNow() {
    const box = document.getElementById('sms-settings-result');
    await saveSmsSettings();
    box.innerHTML = '⏳ پردازش صف بی‌پاسخ...';
    try {
        const result = await fetchAPI('sms/process-missed', { method: 'POST', body: {} });
        if (result.success) {
            const d = result.data || {};
            box.innerHTML = '<span style="color:var(--success)">✅ ' + (result.message || '') +
                '</span><div style="margin-top:4px;color:var(--text-muted)">ارسال:' + (d.sent||0) +
                ' / رد:' + (d.skipped||0) + ' / ناموفق:' + (d.failed||0) + '</div>';
            showToast(result.message || 'انجام شد', 'success');
        } else {
            box.innerHTML = '<span style="color:var(--danger)">❌ ' + (result.message || 'خطا') + '</span>';
        }
    } catch (e) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در ارتباط</span>';
    }
}

async function testElanban() {
    const box = document.getElementById('elanban-test-result');
    showToast('در حال ذخیره و تست اتصال...', 'info');
    await saveSettings(['elanban_base_url', 'elanban_token']);

    try {
        // شماره تستی واقعی نیست - فقط برای این‌که اتصال/توکن را بسنجیم،
        // نه این‌که واقعاً یک شخص پیدا کنیم
        const result = await fetchAPI('elanban/lookup?phone=0000000000', { method: 'GET' });
        if (result.success && result.data && result.data.configured) {
            box.innerHTML = '<span style="color:var(--success)">✅ اتصال به اعلان‌بان برقرار است (توکن و آدرس درست تنظیم شده‌اند)</span>';
            showToast('✅ اتصال به اعلان‌بان برقرار است', 'success');
        } else {
            const msg = (result.data && result.data.message) || result.message || 'خطا در اتصال به اعلان‌بان';
            box.innerHTML = '<span style="color:var(--danger)">❌ ' + msg + '</span>';
            showToast('❌ ' + msg, 'error');
        }
    } catch (err) {
        box.innerHTML = '<span style="color:var(--danger)">خطا در ارتباط با سرور جاکجاست - Console را چک کنید</span>';
        showToast('خطا در ارتباط با سرور جاکجاست', 'error');
        console.error(err);
    }
}

// بارگذاری لاگ‌های تعامل با تلفنچی
async function loadTelefonchyLogs(onlyErrors = false) {
    const box = document.getElementById('telefonchy-logs-box');
    if (!box) return;
    box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>';

    try {
        const qs = onlyErrors ? '?only_errors=1' : '';
        const result = await fetchAPI('settings/telefonchy-logs' + qs, { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت لاگ‌ها</p>';
            return;
        }
        const { items, stats } = result.data;

        let statsHtml = `
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;font-size:12px;color:var(--text-muted)">
            <span>۲۴ ساعت اخیر: <b style="color:var(--text)">${stats.last_24h_total}</b> درخواست</span>
            <span>ناموفق: <b style="color:${stats.last_24h_failed > 0 ? 'var(--danger)' : 'var(--text)'}">${stats.last_24h_failed}</b></span>
            <span>میانگین زمان پاسخ: <b style="color:var(--text)">${stats.last_24h_avg_ms ?? '-'} ms</b></span>
          </div>`;

        if (!items.length) {
            box.innerHTML = statsHtml + '<p style="color:var(--text-muted);font-size:12px">هنوز لاگی ثبت نشده.</p>';
            return;
        }

        let rows = items.map(log => {
            const badgeColor = log.success == 1 ? 'var(--success, #28a745)' : 'var(--danger)';
            const badgeText = log.success == 1 ? 'موفق' : 'ناموفق';
            const time = new Date(log.created_at).toLocaleString('fa-IR');
            return `
              <div style="border-bottom:1px solid var(--border);padding:8px 0;font-size:12px">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                  <span><b>${log.method || '-'}</b> ${log.endpoint || '-'}</span>
                  <span style="color:${badgeColor};font-weight:600">${badgeText}${log.http_code ? ' (' + log.http_code + ')' : ''}</span>
                </div>
                <div style="color:var(--text-muted);margin-top:2px">
                  ${time} — ${log.duration_ms ?? '-'} ms
                  ${log.direction === 'webhook' ? ' — 📥 webhook دریافتی' : ''}
                </div>
                ${log.error_message ? `<div style="color:var(--danger);margin-top:4px">${log.error_message}</div>` : ''}
                ${log.response_preview ? `<div style="color:var(--text-muted);margin-top:4px;direction:ltr;text-align:right">${log.response_preview}</div>` : ''}
              </div>`;
        }).join('');

        box.innerHTML = statsHtml + `<div style="max-height:360px;overflow-y:auto">${rows}</div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
        console.error(err);
    }
}

async function clearTelefonchyLogs() {
    if (!confirm('همه‌ی لاگ‌های تلفنچی پاک شود؟')) return;
    try {
        await fetchAPI('settings/telefonchy-logs', { method: 'DELETE' });
        showToast('لاگ‌ها پاک شدند', 'success');
        loadTelefonchyLogs();
    } catch (err) {
        showToast('خطا در پاک کردن لاگ‌ها', 'error');
    }
}

// بارگذاری دریافتی خام webhook (raw_body دقیق تلفنچی)
async function loadRawWebhookEvents() {
    const box = document.getElementById('raw-webhook-events-box');
    if (!box) return;
    box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">در حال بارگذاری...</p>';

    try {
        const result = await fetchAPI('settings/telefonchy-events?limit=20', { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت رویدادها</p>';
            return;
        }
        const { items } = result.data;

        if (!items.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">هنوز هیچ webhook ای دریافت نشده.</p>';
            return;
        }

        let rows = items.map(ev => {
            const time = new Date(ev.received_at).toLocaleString('fa-IR');
            let pretty = ev.payload;
            try { pretty = JSON.stringify(JSON.parse(ev.payload), null, 2); } catch (e) { /* همان رشته‌ی خام */ }
            return `
              <div style="border-bottom:1px solid var(--border);padding:8px 0;font-size:12px">
                <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;cursor:pointer" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                  <span><b>event_type:</b> ${escapeHtml(ev.event_type || '-')} ${ev.call_cuid ? '— cuid: ' + escapeHtml(ev.call_cuid) : ''}</span>
                  <span style="color:var(--text-muted)">${time} ▾</span>
                </div>
                <pre style="display:none;margin-top:6px;background:var(--bg-2, #f7f7f7);padding:10px;border-radius:6px;direction:ltr;text-align:left;overflow-x:auto;font-size:11px;white-space:pre-wrap;word-break:break-all">${escapeHtml(pretty)}</pre>
              </div>`;
        }).join('');

        box.innerHTML = `<div style="max-height:420px;overflow-y:auto">${rows}</div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
        console.error(err);
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

async function clearRawWebhookEvents() {
    if (!confirm('همه‌ی رویدادهای خام webhook پاک شود؟')) return;
    try {
        await fetchAPI('settings/telefonchy-events', { method: 'DELETE' });
        showToast('رویدادهای خام پاک شدند', 'success');
        loadRawWebhookEvents();
    } catch (err) {
        showToast('خطا در پاک کردن رویدادها', 'error');
    }
}

async function runReconcileStaleCalls() {
    const btn = document.getElementById('reconcile-btn');
    btn.disabled = true;
    btn.innerHTML = '⏳ در حال بررسی...';
    try {
        const result = await fetchAPI('settings/reconcile-stale-calls', { method: 'POST' });
        showToast(result.success ? '✅ ' + result.message : (result.message || 'خطا'), result.success ? 'success' : 'error');
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '🔄 اصلاح تماس‌های گیرکرده';
    }
}

async function loadErrorLog() {
    const box = document.getElementById('error-log-box');
    const sizeEl = document.getElementById('error-log-size');
    if (!box) return;
    box.textContent = 'در حال بارگذاری...';

    try {
        const result = await fetchAPI('settings/error-log?lines=200', { method: 'GET' });
        if (!result.success) {
            box.textContent = 'خطا در دریافت لاگ';
            return;
        }
        const { lines, file_size_human } = result.data;
        if (sizeEl) sizeEl.textContent = file_size_human ? `(حجم فایل: ${file_size_human})` : '';

        if (!lines || !lines.length) {
            box.textContent = 'هنوز هیچ خطایی ثبت نشده. ✅';
            return;
        }
        // رنگ‌آمیزی سطرهای ERROR/CRITICAL برای پیدا کردن سریع‌تر
        box.innerHTML = lines.map(l => {
            const isError = /\b(ERROR|CRITICAL)\b/.test(l);
            const color = isError ? 'var(--danger)' : 'inherit';
            return `<div style="color:${color};padding:2px 0;border-bottom:1px solid rgba(0,0,0,0.05)">${escapeHtml(l)}</div>`;
        }).join('');
    } catch (err) {
        box.textContent = 'خطا در ارتباط با سرور';
    }
}

// اتصال داخلی به پرسنل
async function saveExtenMapping(extenNumber, btnEl) {
    const row = btnEl.closest('tr');
    const select = row.querySelector('.exten-map-select');
    const staffId = select.value;

    btnEl.disabled = true;
    const originalText = btnEl.innerHTML;
    btnEl.innerHTML = '...';

    try {
        // اگر گزینه‌ای انتخاب نشده، یعنی می‌خواهیم اتصال قبلی هر پرسنلی که
        // با این داخلی مچ بود را پاک کنیم
        if (!staffId) {
            showToast('برای قطع اتصال، ابتدا پرسنل قبلی را در پروفایلش ویرایش کنید', 'info');
            btnEl.disabled = false;
            btnEl.innerHTML = originalText;
            return;
        }
        const result = await fetchAPI('staff/' + staffId, {
            method: 'PUT',
            body: { exten_number: extenNumber }
        });
        if (result.success) {
            showToast('اتصال ذخیره شد', 'success');
        } else {
            showToast(result.message || 'خطا در ذخیره', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    } finally {
        btnEl.disabled = false;
        btnEl.innerHTML = originalText;
    }
}

// بارگذاری خودکار لاگ‌ها هنگام باز شدن صفحه تنظیمات
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('telefonchy-logs-box')) {
        loadTelefonchyLogs();
    }
    if (document.getElementById('raw-webhook-events-box')) {
        loadRawWebhookEvents();
    }
    if (document.getElementById('error-log-box')) {
        loadErrorLog();
    }
    if (document.getElementById('users-list-box')) {
        loadUsersList();
    }
    if (document.getElementById('references-list-box')) {
        loadReferencesList();
    }
    if (document.getElementById('transcription-cost-estimate')) {
        loadTranscriptionCostEstimate();
    }
    if (document.getElementById('vosk-health-status')) {
        checkVoskHealth();
    }
});

async function loadTranscriptionCostEstimate() {
    const box = document.getElementById('transcription-cost-estimate');
    try {
        const result = await fetchAPI('settings/transcription-cost-estimate', { method: 'GET' });
        if (result.success) {
            const d = result.data;
            const providerLabel = { local: 'محلی/Whisper (رایگان)', vosk: 'محلی/Vosk (رایگان)', groq: 'Groq', openai: 'OpenAI' }[d.provider] || d.provider;
            box.textContent = `📊 در ۳۰ روز اخیر: ${d.monthly_minutes} دقیقه صدای ضبط‌شده. با روش فعلی (${providerLabel})، تخمین هزینه‌ی ماهانه: $${d.estimated_cost_usd}`;
        }
    } catch (err) { /* بی‌صدا - فقط یک تخمین کمکی است */ }
}

// چک آنی سلامت سرویس Vosk worker (فقط وقتی روش فعلی vosk باشد معنا دارد)
async function checkVoskHealth() {
    const box = document.getElementById('vosk-health-status');
    const provider = document.getElementById('setting-transcription_provider')?.value;
    if (provider !== 'vosk') { box.innerHTML = ''; return; }
    box.textContent = 'در حال بررسی وضعیت Vosk...';
    try {
        const result = await fetchAPI('settings/vosk-health', { method: 'GET' });
        if (!result.success) { box.innerHTML = '<span style="color:var(--danger)">❌ خطا در بررسی اتصال</span>'; return; }
        const { healthy, exec_enabled } = result.data;
        if (!exec_enabled) {
            box.innerHTML = '<span style="color:var(--danger)">❌ تابع exec() روی سرور غیرفعال است (disable_functions در php.ini) - برای ffmpeg لازم است.</span>';
        } else if (healthy) {
            box.innerHTML = '<span style="color:var(--success,green)">✅ سرویس Vosk worker در دسترس است.</span>';
        } else {
            box.innerHTML = '<span style="color:var(--danger)">❌ سرویس Vosk worker در دسترس نیست - راهنما: docs/VOSK_TRANSCRIPTION_SETUP.md</span>';
        }
    } catch (err) {
        box.innerHTML = '<span style="color:var(--danger)">❌ خطا در بررسی اتصال</span>';
    }
}

// ===========================
// مدیریت کاربران و دسترسی‌ها
// ===========================
let jjPermissionKeys = {};
let jjEditingUserId = null;

async function loadUsersList() {
    const box = document.getElementById('users-list-box');
    try {
        const result = await fetchAPI('users', { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت لیست کاربران</p>';
            return;
        }
        jjPermissionKeys = result.data.permission_keys || {};
        const items = result.data.items || [];

        if (!items.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">هیچ کاربری ثبت نشده.</p>';
            return;
        }

        const roleLabel = { admin: 'مدیر سیستم', manager: 'مدیر بخش', staff: 'کارمند' };

        box.innerHTML = `
          <div class="table-wrapper">
            <table class="data-table">
              <thead><tr><th>نام کاربری</th><th>نام کامل</th><th>داخلی</th><th>نقش</th><th>دسترسی‌ها</th><th>وضعیت</th><th></th></tr></thead>
              <tbody>
                ${items.map(u => `
                  <tr>
                    <td dir="ltr">${u.username}</td>
                    <td>${u.full_name}</td>
                    <td dir="ltr">${u.exten_number || '-'}</td>
                    <td><span class="badge ${u.role === 'admin' ? 'badge-primary' : 'badge-secondary'}">${roleLabel[u.role] || u.role}</span></td>
                    <td style="font-size:11px;color:var(--text-muted)">
                      ${u.role === 'admin' ? 'کامل' : (u.permissions.length ? u.permissions.map(k => jjPermissionKeys[k] || k).join('، ') : 'هیچ‌کدام')}
                    </td>
                    <td>${u.is_active == 1 ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>'}</td>
                    <td style="display:flex;gap:4px">
                      <button class="btn btn-light btn-xs" onclick='openUserModal(${JSON.stringify(u)})'>✏️</button>
                      <button class="btn btn-light btn-xs" style="color:var(--danger)" onclick="deleteUser(${u.id}, '${u.username}')">🗑</button>
                    </td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
    }
}

function openUserModal(user = null) {
    jjEditingUserId = user ? user.id : null;

    const permCheckboxes = Object.entries(jjPermissionKeys).map(([key, label]) => {
        const checked = user && user.permissions && user.permissions.includes(key) ? 'checked' : '';
        return `<label style="display:flex;align-items:center;gap:6px;font-size:13px;padding:4px 0">
                  <input type="checkbox" class="user-perm-checkbox" value="${key}" ${checked}> ${label}
                </label>`;
    }).join('');

    const modalHtml = `
      <div class="modal-overlay" id="modal-user">
        <div class="modal-box">
          <div class="modal-header">
            <h4>${user ? '✏️ ویرایش کاربر' : '+ کاربر جدید'}</h4>
            <button class="btn-close" onclick="closeModal('modal-user')">×</button>
          </div>
          <div class="modal-body" style="padding:20px">
            <div class="form-group">
              <label class="form-label">نام کاربری</label>
              <input type="text" class="form-control" id="user-username" value="${user ? user.username : ''}" dir="ltr">
            </div>
            <div class="form-group">
              <label class="form-label">نام کامل</label>
              <input type="text" class="form-control" id="user-fullname" value="${user ? user.full_name : ''}">
            </div>
            <div class="form-group">
              <label class="form-label">رمز عبور ${user ? '(خالی = بدون تغییر)' : ''}</label>
              <input type="password" class="form-control" id="user-password" placeholder="حداقل ۴ کاراکتر">
            </div>
            <div class="form-group">
              <label class="form-label">نقش</label>
              <select class="form-control" id="user-role" onchange="onUserRoleChange()">
                <option value="staff" ${user && user.role === 'staff' ? 'selected' : ''}>کارمند</option>
                <option value="manager" ${user && user.role === 'manager' ? 'selected' : ''}>مدیر بخش</option>
                <option value="admin" ${user && user.role === 'admin' ? 'selected' : ''}>مدیر سیستم</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">شماره تماس داخلی</label>
              <input type="text" class="form-control" id="user-exten" value="${user && user.exten_number ? user.exten_number : ''}" dir="ltr" placeholder="مثلاً 326153">
            </div>
            <div class="form-group">
              <label class="form-label">ایمیل</label>
              <input type="email" class="form-control" id="user-email" value="${user && user.email ? user.email : ''}" dir="ltr">
            </div>
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" id="user-active" ${(!user || user.is_active == 1) ? 'checked' : ''}> فعال
              </label>
            </div>
            <div class="form-group" id="user-perms-group" style="${user && user.role === 'admin' ? 'display:none' : ''}">
              <label class="form-label">دسترسی به ماژول‌ها</label>
              <div style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px">
                ${permCheckboxes}
              </div>
            </div>
            <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
              <button class="btn btn-light btn-sm" onclick="closeModal('modal-user')">انصراف</button>
              <button class="btn btn-primary btn-sm" onclick="saveUser()">ذخیره</button>
            </div>
          </div>
        </div>
      </div>`;

    const existing = document.getElementById('modal-user');
    if (existing) existing.remove();
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    openModal('modal-user');
}

function onUserRoleChange() {
    const role = document.getElementById('user-role').value;
    document.getElementById('user-perms-group').style.display = (role === 'admin') ? 'none' : 'block';
}

async function saveUser() {
    const username = document.getElementById('user-username').value.trim();
    const fullName = document.getElementById('user-fullname').value.trim();
    const password = document.getElementById('user-password').value;
    const role = document.getElementById('user-role').value;
    const extenNumber = document.getElementById('user-exten').value.trim();
    const email = document.getElementById('user-email').value.trim();
    const isActive = document.getElementById('user-active').checked;
    const permissions = Array.from(document.querySelectorAll('.user-perm-checkbox:checked')).map(el => el.value);

    if (!username || !fullName) {
        showToast('نام کاربری و نام کامل الزامی است', 'error');
        return;
    }
    if (!jjEditingUserId && !password) {
        showToast('برای کاربر جدید، رمز عبور الزامی است', 'error');
        return;
    }

    const body = { username, full_name: fullName, role, permissions, exten_number: extenNumber, email, is_active: isActive ? 1 : 0 };
    if (password) body.password = password;

    try {
        const result = jjEditingUserId
            ? await fetchAPI('users/' + jjEditingUserId, { method: 'PUT', body })
            : await fetchAPI('users', { method: 'POST', body });

        if (result.success) {
            showToast('کاربر ذخیره شد', 'success');
            closeModal('modal-user');
            loadUsersList();
        } else {
            showToast(result.message || 'خطا در ذخیره', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function deleteUser(id, username) {
    if (!confirm(`کاربر «${username}» حذف شود؟`)) return;
    try {
        const result = await fetchAPI('users/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('کاربر حذف شد', 'success');
            loadUsersList();
        } else {
            showToast(result.message || 'خطا در حذف', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

// مدیریت مراجع سرنخ
async function loadReferencesList() {
    const box = document.getElementById('references-list-box');
    try {
        const result = await fetchAPI('references', { method: 'GET' });
        if (!result.success) {
            box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در دریافت لیست مراجع</p>';
            return;
        }
        const items = result.data;
        if (!items.length) {
            box.innerHTML = '<p style="color:var(--text-muted);font-size:12px">هنوز مرجعی ثبت نشده.</p>';
            return;
        }
        box.innerHTML = `
          <div class="table-wrapper">
            <table class="data-table">
              <thead><tr><th>نام</th><th>کد</th><th>نوع</th><th>وضعیت</th><th></th></tr></thead>
              <tbody>
                ${items.map(r => `
                  <tr>
                    <td>${r.name}</td>
                    <td dir="ltr">${r.code || '-'}</td>
                    <td>${r.type || '-'}</td>
                    <td>${r.is_active == 1 ? '<span class="badge badge-success">فعال</span>' : '<span class="badge badge-danger">غیرفعال</span>'}</td>
                    <td style="display:flex;gap:4px">
                      <button class="btn btn-light btn-xs" onclick='openReferenceModal(${JSON.stringify(r)})'>✏️</button>
                      <button class="btn btn-light btn-xs" style="color:var(--danger)" onclick="deleteReference(${r.id}, '${r.name}')">🗑</button>
                    </td>
                  </tr>`).join('')}
              </tbody>
            </table>
          </div>`;
    } catch (err) {
        box.innerHTML = '<p style="color:var(--danger);font-size:12px">خطا در ارتباط با سرور</p>';
    }
}

let jjEditingReferenceId = null;

function openReferenceModal(ref) {
    jjEditingReferenceId = ref ? ref.id : null;
    const modalHtml = `
      <div class="modal-overlay" id="modal-reference">
        <div class="modal-box">
          <div class="modal-header">
            <h4>${ref ? '✏️ ویرایش مرجع' : '+ مرجع جدید'}</h4>
            <button class="btn-close" onclick="closeModal('modal-reference')">×</button>
          </div>
          <div class="modal-body" style="padding:20px">
            <div class="form-group">
              <label class="form-label">نام مرجع</label>
              <input type="text" class="form-control" id="ref-name" value="${ref ? ref.name : ''}">
            </div>
            <div class="form-group">
              <label class="form-label">کد مرجع (اختیاری)</label>
              <input type="text" class="form-control" id="ref-code" value="${ref && ref.code ? ref.code : ''}" dir="ltr">
            </div>
            <div class="form-group">
              <label class="form-label">نوع مرجع</label>
              <select class="form-control" id="ref-type">
                <option value="">انتخاب کنید...</option>
                <option value="مشتری" ${ref && ref.type === 'مشتری' ? 'selected' : ''}>مشتری</option>
                <option value="همکار" ${ref && ref.type === 'همکار' ? 'selected' : ''}>همکار</option>
                <option value="تامین‌کننده" ${ref && ref.type === 'تامین‌کننده' ? 'selected' : ''}>تامین‌کننده</option>
                <option value="سایر" ${ref && ref.type === 'سایر' ? 'selected' : ''}>سایر</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">توضیحات</label>
              <textarea class="form-control" id="ref-description" rows="2">${ref && ref.description ? ref.description : ''}</textarea>
            </div>
            <div class="form-group">
              <label class="form-label" style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" id="ref-active" ${(!ref || ref.is_active == 1) ? 'checked' : ''}> فعال
              </label>
            </div>
            <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px">
              <button class="btn btn-light btn-sm" onclick="closeModal('modal-reference')">انصراف</button>
              <button class="btn btn-primary btn-sm" onclick="saveReference()">ذخیره</button>
            </div>
          </div>
        </div>
      </div>`;
    const existing = document.getElementById('modal-reference');
    if (existing) existing.remove();
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    openModal('modal-reference');
}

async function saveReference() {
    const name = document.getElementById('ref-name').value.trim();
    const code = document.getElementById('ref-code').value.trim();
    const type = document.getElementById('ref-type').value;
    const description = document.getElementById('ref-description').value.trim();
    const isActive = document.getElementById('ref-active').checked;

    if (!name) { showToast('نام مرجع الزامی است', 'error'); return; }

    const body = { name, code, type, description, is_active: isActive ? 1 : 0 };
    try {
        const result = jjEditingReferenceId
            ? await fetchAPI('references/' + jjEditingReferenceId, { method: 'PUT', body })
            : await fetchAPI('references', { method: 'POST', body });
        if (result.success) {
            showToast('مرجع ذخیره شد', 'success');
            closeModal('modal-reference');
            loadReferencesList();
        } else {
            showToast(result.message || 'خطا در ذخیره', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function deleteReference(id, name) {
    if (!confirm(`مرجع «${name}» غیرفعال شود؟`)) return;
    try {
        const result = await fetchAPI('references/' + id, { method: 'DELETE' });
        if (result.success) {
            showToast('مرجع غیرفعال شد', 'success');
            loadReferencesList();
        } else {
            showToast(result.message || 'خطا در حذف', 'error');
        }
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}

async function generateUsersForAllStaff() {
    if (!confirm('برای همه‌ی پرسنلی که هنوز حساب کاربری ندارند، حساب جدید ساخته می‌شود. ادامه می‌دهید؟')) return;
    try {
        const result = await fetchAPI('users/generate-for-all-staff', { method: 'POST' });
        if (!result.success) {
            showToast(result.message || 'خطا', 'error');
            return;
        }
        const created = result.data.created || [];
        loadUsersList();

        if (!created.length) {
            showToast('همه‌ی پرسنل از قبل حساب کاربری داشتند', 'info');
            return;
        }

        // نمایش لیست رمزهای عبور تولیدشده - این تنها فرصت دیدن رمزهاست
        const rows = created.map(c => `
            <tr><td>${c.staff_name}</td><td dir="ltr"><b>${c.username}</b></td><td dir="ltr"><b style="color:var(--danger)">${c.password}</b></td></tr>
        `).join('');

        const html = `
          <div class="modal-overlay show" id="modal-generated-users">
            <div class="modal-box" style="max-width:500px">
              <div class="modal-header">
                <h4>✅ ${created.length} حساب کاربری ساخته شد</h4>
                <button class="btn-close" onclick="document.getElementById('modal-generated-users').remove()">×</button>
              </div>
              <div class="modal-body" style="padding:20px">
                <p style="font-size:12px;color:var(--danger);margin-bottom:10px">⚠️ این رمزهای عبور فقط همین یک‌بار قابل مشاهده‌اند. حتماً همین الان یادداشت/کپی و به هرکدام از پرسنل اطلاع بدید.</p>
                <div class="table-wrapper" style="max-height:300px;overflow-y:auto">
                  <table class="data-table">
                    <thead><tr><th>پرسنل</th><th>نام کاربری</th><th>رمز عبور</th></tr></thead>
                    <tbody>${rows}</tbody>
                  </table>
                </div>
                <button class="btn btn-primary btn-sm" style="margin-top:14px" onclick="document.getElementById('modal-generated-users').remove()">متوجه شدم</button>
              </div>
            </div>
          </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    } catch (err) {
        showToast('خطا در ارتباط با سرور', 'error');
    }
}
</script>

<?php require __DIR__ . '/parts/footer.php'; ?>
