# 🚀 جاکجاست (Jakojast) - سیستم مدیریت یکپارچه

> **نسخه ۳.۰** — سیستم مدیریت یکپارچه CRM + HRM + Helpdesk + Call Center برای کسب‌وکارهای خدماتی

<p align="center">
  <strong>PHP خالص + MySQL + JavaScript خالص</strong><br>
  بدون فریم‌ورک، بدون وابستگی خارجی، آماده نصب روی هر هاست معمولی
</p>

---

## 📋 فهرست

- [معرفی](#معرفی)
- [امکانات](#امکانات)
- [نیازمندی‌ها](#نیازمندی‌ها)
- [نصب سریع](#نصب-سریع)
- [ساختار پوشه‌ها](#ساختار-پوشه‌ها)
- [پیکربندی تلفنچی](#پیکربندی-تلفنچی)
- [تنظیم Cron Job](#تنظیم-cron-job)
- [تنظیم Webhook تلفنچی](#تنظیم-webhook-تلفنچی)
- [امنیت](#امنیت)
- [عیب‌یابی](#عیب-یابی)
- [راهنمای تصویری](GUIDE.md)

---

## معرفی

**جاکجاست** یک سیستم مدیریت یکپارچه است که تمام نیازهای یک کسب‌وکار خدماتی را در یک پلتفرم فراهم می‌کند:

- **CRM**: مدیریت مشتریان، میزبانان، سرنخ‌های فروش، سرنخ‌های میزبان
- **HRM**: مدیریت پرسنل، مرخصی، حقوق و دستمزد، ارزیابی عملکرد
- **Helpdesk**: تیکتینگ، تسک‌ها (کانبان + گانت)، پروژه‌ها، چت داخلی
- **Call Center**: یکپارچگی کامل با تلفنچی (CDR، فایل صوتی، وب‌هوک، کرون جاب)

### ویژگی‌های فنی

- ✅ **PHP 7.4+ / MySQL 5.7+** — بدون فریم‌ورک
- ✅ **Prepared Statements** در تمام کوئری‌ها (امنیت SQL Injection)
- ✅ **bcrypt** برای رمز عبور (با مهاجرت خودکار MD5 قدیمی)
- ✅ **توکن-based auth** با انقضای ۸ ساعت و IP tracking
- ✅ **XSS prevention** با `htmlspecialchars` در همه خروجی‌ها
- ✅ **CSRF token** در فرم‌ها
- ✅ **تاریخ شمسی** (تبدیل میلادی ↔ شمسی بدون ext-calendar)
- ✅ **Long Polling** برای چت real-time (بدون WebSocket)
- ✅ **HTML5 Drag & Drop** برای کانبان تسک‌ها
- ✅ **استریم پروکسی** برای پخش فایل‌های صوتی تلفنچی

---

## امکانات

### ۱. داشبورد اجرایی
- ۶ کارت KPI (مشتریان، میزبانان، پرسنل، درآمد، رضایت، رشد)
- ۲ نمودار میله‌ای (درآمد ۱۲ ماه + مشتریان جدید)
- فعالیت‌های اخیر از ۶ منبع
- هشدارهای هوشمند (تسویه معوق، تیکت قدیمی، مرخصی در انتظار و...)
- نمودار سازمانی درختی

### ۲. ماژول CRM
- **مشتریان**: CRUD کامل + یادداشت + تیکت + تماس + تایم‌لاین
- **میزبانان**: CRUD + تأیید + تسویه‌حساب + تیکت
- **سرنخ‌های فروش**: ۶ مرحله قیف فروش + تبدیل به مشتری + نرخ تبدیل
- **سرنخ‌های میزبان**: با فیلدهای کامل (لوکیشن، منطقه، شهر، نوع اقامتگاه، تاریخ اقدام) + ۳ وضعیت (جذب اولیه/بی‌پاسخ/عدم تمایل) + تبدیل به میزبان

### ۳. ماژول HRM
- **پرسنل**: ساختار سازمانی درختی + جلوگیری از چرخه + ۷ تب پروفایل
- **مرخصی**: جریان تأیید (pending → approved/rejected)
- **حقوق**: ۱۲ مؤلفه + تولید فیش ماهانه + پرداخت گروهی + فیش printable
- **ارزیابی**: امتیاز ۰-۱۰۰ + نقاط قوت/ضعف + top performers

### ۴. ماژول Helpdesk
- **تیکت**: ۴ وضعیت + messages JSON + ترد پاسخ + انتساب
- **تسک**: ۵ وضعیت کانبان + Drag & Drop + زیرتسک + کامنت
- **گانت‌چارت**: نمایش زمان‌بندی تسک‌ها از تاریخ شمسی
- **پروژه‌ها**: members JSON + progress + task_count
- **چت داخلی**: Long Polling real-time + ۶ کانال + کاربران آنلاین

### ۵. یکپارچگی تلفنچی
📖 مرجع کامل و همیشه‌به‌روز API تلفنچی: [`docs/TELEFONCHY_API.md`](docs/TELEFONCHY_API.md)
(این فایل باید با هر تغییری در کد یکپارچه‌سازی، هم‌زمان به‌روزرسانی شود)
- دریافت CDR با فیلتر تاریخ رسمی (date_from/date_to)
- جلوگیری از duplicate با UNIQUE cuid
- تطبیق خودکار contact_id با مشتریان
- پخش فایل صوتی به‌صورت stream proxy (file_id + cuid)
- وب‌هوک برای رویدادهای real-time (ringing/hangup)
- سیستم لاگ‌گیری کامل تعاملات (جدول telefonchy_api_logs + پنل در تنظیمات)
- کرون جاب هر ۵ دقیقه برای همگام‌سازی افزایشی
- خروجی CSV تماس‌ها

### ۶. گزارشات
- گزارش فروش ماهانه
- گزارش مالی میزبانان
- گزارش عملکرد پرسنل
- گزارش پشتیبانی
- گزارش تماس‌ها
- گزارش سرنخ‌ها
- نمودارهای Chart.js
- خروجی CSV + چاپ

---

## نیازمندی‌ها

| نیازمند | حداقل نسخه | توضیح |
|---------|------------|-------|
| PHP | 7.4 | با افزونه‌های `pdo_mysql`, `mbstring`, `curl` |
| MySQL | 5.7 | یا MariaDB 10.3+ |
| فضای هاست | ۵۰ مگابایت | برای فایل‌های پروژه + لاگ |
| مرورگر | Chrome 90+ / Firefox 88+ / Edge 90+ | برای پشتیبانی HTML5 DnD و ES6 |

### افزونه‌های PHP مورد نیاز

```bash
php -m | grep -E "pdo_mysql|mbstring|curl|json|session"
```

---

## نصب سریع

### مرحله ۱: آپلود فایل‌ها

تمام فایل‌های پروژه را در `public_html` (یا ساب‌دایرکتوری) آپلود کنید:

```
public_html/
├── index.php
├── api.php
├── install.php
├── webhook.php
├── cron_sync.php
├── config.php
├── .htaccess
├── core/
├── models/
├── views/
├── api_integration/
├── assets/
└── logs/
```

### مرحله ۲: ایجاد دیتابیس

در cPanel یا phpMyAdmin یک دیتابیس با نام `jakojast` بسازید (یا هر نام دلخواه). کاربر دیتابیس را با تمام دسترسی‌ها به آن متصل کنید.

### مرحله ۳: اجرای نصب‌کننده

در مرورگر به آدرس زیر بروید:

```
https://your-domain.com/install.php
```

فرم نصب را پر کنید:
- **میزبان دیتابیس**: معمولاً `localhost` یا `127.0.0.1`
- **نام دیتابیس**: نام دیتابیسی که ساختید
- **نام کاربری دیتابیس**: یوزر دیتابیس
- **رمز عبور دیتابیس**: پسورد دیتابیس
- **نام کاربری مدیر**: پیش‌فرض `admin`
- **رمز عبور مدیر**: پیش‌فرض `123`

روی **«🚀 شروع نصب»** کلیک کنید.

### مرحله ۴: ورود به سیستم

پس از نصب موفق، فایل `install.php` خودش حذف می‌شود. به آدرس اصلی بروید:

```
https://your-domain.com/
```

با نام کاربری `admin` و رمز `123` وارد شوید. **حتماً پس از اولین ورود، رمز خود را تغییر دهید.**

### مرحله ۵ (اختیاری): درج داده نمونه

برای تست سیستم، فایل `seed.php` را اجرا کنید:

```
https://your-domain.com/seed.php
```

این فایل داده‌های نمونه (۱۰ مشتری، ۸ میزبان، ۱۵ پرسنل، ۱۰ سرنخ و...) درج می‌کند. **پس از تست، این فایل را حذف کنید.**

---

## ساختار پوشه‌ها

```
jakojast/
├── index.php              # ورودی و روتینگ ویوها
├── api.php                # تمام endpointهای REST (۱۱۷+ endpoint)
├── install.php            # نصب خودکار (خودحذف شونده)
├── webhook.php            # وب‌هوک تلفنچی
├── cron_sync.php          # همگام‌سازی دوره‌ای تماس‌ها
├── seed.php               # داده نمونه (برای تست)
├── config.php             # تنظیمات اصلی
├── .htaccess              # mod_rewrite + امنیت
├── db.sql                 # اسکریپت دیتابیس (۲۰ جدول)
├── README.md              # این فایل
├── GUIDE.md               # راهنمای تصویری
├── core/                  # کلاس‌های هسته
│   ├── Database.php       # PDO singleton + prepared statements
│   ├── Auth.php           # احراز هویت + توکن
│   ├── Response.php       # پاسخ JSON استاندارد
│   ├── Helpers.php        # توابع کمکی (XSS، موبایل، CSRF و...)
│   ├── Jalali.php         # تبدیل تاریخ شمسی
│   └── Logger.php         # لاگ خطاها
├── api_integration/       # یکپارچگی خارجی
│   ├── TelefonchyAPI.php  # کلاینت API تلفنچی
│   └── CallSyncManager.php # مدیر همگام‌سازی تماس‌ها
├── models/                # مدل‌های داده (۱۲ مدل)
│   ├── Customer.php
│   ├── Host.php
│   ├── Lead.php
│   ├── HostLead.php
│   ├── Staff.php
│   ├── Leave.php
│   ├── Payroll.php
│   ├── Evaluation.php
│   ├── Ticket.php
│   ├── Task.php
│   ├── Project.php
│   ├── Chat.php
│   ├── Call.php
│   └── Report.php
├── views/                 # صفحات HTML (۱۸ view)
│   ├── login.php
│   ├── dashboard.php
│   ├── customers.php
│   ├── customer_profile.php
│   ├── hosts.php
│   ├── host_profile.php
│   ├── staff.php
│   ├── staff_profile.php
│   ├── leads.php
│   ├── host_leads.php
│   ├── tickets.php
│   ├── chat.php
│   ├── tasks.php
│   ├── reports.php
│   ├── calls.php
│   ├── call_detail.php
│   ├── settings.php
│   ├── leaves.php
│   ├── payroll.php
│   └── parts/             # partials
│       ├── header.php
│       ├── sidebar.php
│       └── footer.php
├── assets/
│   ├── css/
│   │   └── style.css      # استایل اصلی (تم قرمز/تیره RTL)
│   └── js/
│       └── app.js         # توابع مشترک (fetchAPI, showToast و...)
└── logs/
    └── error.log          # لاگ خطاها
```

---

## پیکربندی تلفنچی

### پیش‌نیازها

برای استفاده از یکپارچگی تلفنچی، نیاز دارید:
- یک حساب کاربری در [telefonchy.com](https://telefonchy.com)
- یک سرویس فعال
- توکن وب‌سرویس (webservice-token) از پنل تلفنچی

### مراحل

۱. وارد سیستم جاکجاست شوید (با حساب مدیر)
۲. به **تنظیمات** بروید
۳. در بخش **📞 تنظیمات تلفنچی**:
   - **شناسه سرویس (Service ID)**: شناسه سرویس خود را از پنل تلفنچی وارد کنید
   - **توکن وب‌سرویس**: توکن long را از پنل تلفنچی کپی و پیست کنید
   - **آدرس پایه API**: پیش‌فرض `https://api.telefonchy.com` (معمولاً نیازی به تغییر نیست)
۴. روی **💾 ذخیره** کلیک کنید
۵. روی **🧪 تست اتصال** کلیک کنید — باید پیام «اتصال به تلفنچی برقرار است» ببینید

### دریافت تماس‌ها

پس از پیکربندی:
- به صفحه **📞 تماس‌ها** بروید
- روی **🔄 همگام‌سازی با تلفنچی** کلیک کنید
- تماس‌های ۷ روز اخیر دریافت و در دیتابیس ذخیره می‌شوند
- برای همگام‌سازی خودکار، [Cron Job](#تنظیم-cron-job) را تنظیم کنید

---

## تنظیم Cron Job

برای همگام‌سازی خودکار تماس‌ها هر ۵ دقیقه:

### روش ۱: از طریق cPanel

۱. وارد cPanel شوید
۲. به **Cron Jobs** بروید
۳. در بخش **Add New Cron Job**:
   - **Common Settings**: `Every 5 minutes`
   - **Command**: 
     ```
     php /home/username/public_html/cron_sync.php
     ```
۴. روی **Add New Cron Job** کلیک کنید

### روش ۲: از طریق crontab (SSH)

```bash
crontab -e
```

خط زیر را اضافه کنید:

```
*/5 * * * * php /path/to/jakojast/cron_sync.php
```

### روش ۳: از طریق wget (اگر PHP CLI در دسترس نیست)

ابتدا یک `cron_key` در صفحه تنظیمات تنظیم کنید، سپس:

```
*/5 * * * * wget -q -O /dev/null https://your-domain.com/cron_sync.php?key=YOUR_CRON_KEY
```

### بررسی عملکرد Cron

برای تست دستی:

```bash
# CLI mode
php /path/to/jakojast/cron_sync.php

# یا web mode
wget -q -O - https://your-domain.com/cron_sync.php?key=YOUR_CRON_KEY
```

خروجی باید JSON با `success: true` باشد.

---

## تنظیم Webhook تلفنچی

برای دریافت رویدادهای real-time تلفنچی (شروع تماس، پاسخ، پایان و...):

### مراحل

۱. وارد پنل تلفنچی شوید
۲. به بخش **Webhooks** یا **تنظیمات رویداد** بروید
۳. URL زیر را وارد کنید:
   ```
   https://your-domain.com/webhook.php
   ```
۴. رویدادهای مورد نظر را انتخاب کنید (مثلاً: `call_started`, `call_answered`, `call_ended`)
۵. ذخیره کنید

### امنیت (اختیاری اما توصیه می‌شود)

برای جلوگیری از درخواست‌های غیرمجاز:
- یک secret به URL اضافه کنید: `https://your-domain.com/webhook.php?secret=XXX`
- یا از IP whitelist تلفنچی استفاده کنید

### بررسی Webhook

پس از تنظیم، یک تماس آزمایشی انجام دهید. سپس در دیتابیس جدول `telefonchy_events` را بررسی کنید:

```sql
SELECT * FROM telefonchy_events ORDER BY received_at DESC LIMIT 10;
```

---

## امنیت

### توصیه‌های مهم

۱. **تغییر رمز مدیر**: پس از اولین ورود، حتماً رمز `123` را تغییر دهید (از صفحه تنظیمات)

۲. **حذف فایل‌های تست**: پس از نصب:
   ```bash
   rm install.php    # معمولاً خودش حذف می‌شود
   rm seed.php       # حذف دستی
   rm db.sql         # حذف دستی (اختیاری، برای backup نگه دارید)
   ```

۳. **تغییر APP_SECRET**: در `config.php`، مقدار `APP_SECRET` را به یک رشته تصادفی ۶۴ کاراکتری تغییر دهید (install.php این کار را خودکار انجام می‌دهد)

۴. **HTTPS**: حتماً از HTTPS استفاده کنید (گواهی SSL رایگان از Let's Encrypt)

۵. **پشتیبان‌گیری منظم**: از دیتابیس به‌صورت هفتگی backup بگیرید:
   ```bash
   mysqldump -u user -p jakojast > backup_$(date +%Y%m%d).sql
   ```

۶. **محدود کردن دسترسی**:
   - فایل `config.php` فقط خواندنی باشد
   - پوشه `logs/` فقط برای PHP قابل نوشتن باشد (not web-accessible)
   - `.htaccess` دسترسی به `core/`, `models/`, `logs/` را محدود می‌کند

### ویژگی‌های امنیتی پیاده‌سازی شده

- ✅ Prepared Statements در تمام کوئری‌ها
- ✅ bcrypt برای رمز عبور (با مهاجرت MD5 قدیمی)
- ✅ XSS prevention با `htmlspecialchars`
- ✅ CSRF token در session
- ✅ Token-based auth با انقضا و IP
- ✅ Headerهای امنیتی (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection)
- ✅ `.htaccess` با deny access به فایل‌های حساس
- ✅ strip_tags در پیام‌های چت
- ✅ Auth::requireRole برای عملیات حساس (تولید فیش، پرداخت گروهی و...)

---

## عیب‌یابی

### مشکل: صفحه سفید پس از نصب

**علت**: خطای PHP که نمایش داده نمی‌شود.

**راه‌حل**:
۱. در `config.php`، `DEBUG_MODE` را `true` کنید:
   ```php
   define('DEBUG_MODE', true);
   ```
۲. صفحه را refresh کنید — خطا نمایش داده می‌شود
۳. پس از رفع مشکل، `DEBUG_MODE` را `false` کنید

### مشکل: خطای "امکان اتصال به دیتابیس وجود ندارد"

**علت**: اطلاعات دیتابیس اشتباه است.

**راه‌حل**:
۱. فایل `config.php` را باز کنید
۲. مقادیر `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` را بررسی کنید
۳. مطمئن شوید کاربر دیتابیس دسترسی کامل دارد

### مشکل: تلفنچی کار نمی‌کند

**علت**: تنظیمات تلفنچی ناقص یا توکن اشتباه.

**راه‌حل**:
۱. به صفحه تنظیمات بروید
۲. `service_id` و `token` را بررسی کنید
۳. روی «🧪 تست اتصال» کلیک کنید
۴. اگر خطا بود، توکن را از پنل تلفنچی دوباره کپی کنید

### مشکل: Cron Job کار نمی‌کند

**علت**: مسیر PHP CLI اشتباه یا دسترسی‌ها.

**راه‌حل**:
۱. مسیر PHP CLI را پیدا کنید:
   ```bash
   which php
   ```
۲. در cron از مسیر کامل استفاده کنید:
   ```
   */5 * * * * /usr/local/bin/php /home/username/public_html/cron_sync.php
   ```
۳. فایل `logs/error.log` را بررسی کنید

### مشکل: چت real-time کار نمی‌کند

**علت**: Long Polling توسط سرور مسدود شده.

**راه‌حل**:
۱. در `php.ini`:
   ```ini
   max_execution_time = 60
   output_buffering = Off
   ```
۲. در nginx (اگر استفاده می‌کنید):
   ```nginx
   fastcgi_read_timeout 60;
   proxy_buffering off;
   ```
۳. در Apache:
   ```apache
   <IfModule mod_fcgid.c>
       FcgidIOTimeout 60
   </IfModule>
   ```

### مشکل: فایل صوتی پخش نمی‌شود

**علت**: CORS یا مشکل توکن.

**راه‌حل**:
۱. مطمئن شوید توکن در localStorage ذخیره شده (در DevTools → Application → Local Storage)
۲. فایل `logs/error.log` را بررسی کنید
۳. در DevTools → Network، درخواست `/api/play-recording` را بررسی کنید

### مشکل: کانبان drag & drop کار نمی‌کند

**علت**: مرورگر قدیمی یا JavaScript خطا دارد.

**راه‌حل**:
۱. مرورگر را به آخرین نسخه به‌روز کنید
۲. در DevTools → Console، خطاهای JS را بررسی کنید
۳. مطمئن شوید JavaScript فعال است

### مشاهده لاگ‌ها

خطاها در `logs/error.log` ذخیره می‌شوند:

```bash
tail -f logs/error.log
```

---

## راهنمای تصویری

برای راهنمای تصویری کامل، به [GUIDE.md](GUIDE.md) مراجعه کنید.

---

## مجوز

این پروژه متعلق به تیم جاکجاست است. استفاده تجاری نیاز به مجوز دارد.

---

## پشتیبانی

برای پشتیبانی و گزارش باگ:
- ایمیل: support@jakojast.local
- مستندات: [GUIDE.md](GUIDE.md)

---

**نسخه ۳.۰** — © ۱۴۰۳ جاکجاست
