# اعلان‌بان — سند مرجع کامل پروژه

> این سند «منبع حقیقت» پروژه است. با خواندن آن، کل سیستم بدون نیاز به تاریخچه‌ی گفتگو قابل‌فهم است.
> هر تغییر مهم باید اینجا به‌روز شود.

---

## ۱. محصول در یک نگاه

پلتفرم ارتباط چندکاناله با مشتری، **PHP خام بدون فریم‌ورک**، نصب مستقل روی سرور هر شرکت. هدف: مدیریت مشتری در چند پلتفرم (بله، تلگرام، روبیکا، صفیر بله، پیامک) از یک پنل واحد، با شناختن **یک مشتری واحد در همه‌ی کانال‌ها** (هویت طلایی بر اساس شماره موبایل).

**تصمیم‌های قفل‌شده:** بدون composer/فریم‌ورک · هر نصب = یک شرکت · احراز هویت اجباری (admin/operator) · merge خودکار بر اساس شماره · پیامک مستقیم با curl.

---

## ۲. معماری چهارلایه

```
UI (views/) → ماژول‌های کاری (modules/) → کانال‌ها (channels/) → هسته (core/)
```
قانون: لایه‌ی بالا از پایین استفاده می‌کند. ماژول کاری به «شخص» پیام می‌دهد، نه به کانال؛ لایه‌ی کانال تصمیم می‌گیرد چطور بفرستد.

---

## ۳. هویت طلایی (مهم‌ترین مفهوم)

- **person** (شخص واقعی) ⇄ چند **identity** (هر کانال یک هویت).
- شماره موبایل کلید تجمیع است. هرجا شماره به دست آید، هویت زیر شخص دارای آن شماره می‌رود.
- **member** = شماره دارد · **anonymous** = هنوز ندارد.
- merge اطلاعات را **جمع می‌کند، پاک نمی‌کند**: نام کامل‌تر، یادداشت‌های هر دو (به‌هم چسبیده)، شماره، و اگر هرکدام member بود → member. (در `Persons::merge`)
- `ingestIdentity`: اگر شماره‌ی ورودی از قبل برای شخصی هست، هویت جدید **مستقیم** زیر همان شخص می‌رود (بدون ساخت شخص خالی و merge). این باگ پاک‌شدن اطلاعات را حل کرد.

---

## ۴. دیتابیس (پیشوند eb_)

| جدول | نقش |
|------|-----|
| eb_users | اپراتورها (admin/operator) |
| eb_settings | key/value (توکن کانال‌ها، secret وب‌هوک، cron.key) |
| eb_persons | شخص (full_name, phone UNIQUE NULL, status, notes) |
| eb_identities | هویت (person_id, channel, channel_uid, username, first_name, raw_json) UNIQUE(channel,channel_uid) |
| eb_messages | پیام‌ها (identity_id, channel, direction in/out, body, status, **error**, **send_id**) |
| eb_groups / eb_person_groups | گروه و عضویت N:M |
| eb_tags / eb_person_tags | تگ N:M |
| eb_templates | الگوها (name, body, buttons_json, media_path) |
| eb_campaigns | کمپین (name, channels, filter_json, status, stats_json) |
| eb_campaign_steps | مراحل کمپین (campaign_id, step_order, template_id/text, delay_hours, only_nonresponders) |
| eb_campaign_runs | اجرای هر شخص در هر مرحله (responded برای پیگیری) |
| eb_auto_replies | پاسخ خودکار (channel, trigger_keyword, trigger_type, response_json, priority) |
| eb_scheduled | زمان‌بندی (filter_json, channel_priority, run_at, status) |
| eb_bot_settings | پیام خوش‌آمد /start (key='welcome') |
| eb_sends | لاگ هر عملیات ارسال (kind, title, body, total/sent/failed, meta_json) |
| eb_send_targets | لاگ تک‌تک گیرنده‌ها (send_id, person_id, channel, recipient, status, error) |

**نکته:** schema کامل در `core/Schema.php::ensure()` است (هم install و هم endpoint `db_upgrade` از آن استفاده می‌کنند). همه IF NOT EXISTS و ALTERها امن‌اند → اجرای چندباره بی‌خطر.

---

## ۵. کانال‌ها (channels/)

رابط مشترک `ChannelInterface`: `name() / capabilities() / send(recipient,message) / handleInbound(payload) / setWebhook(url)`.

| درایور | شناسه | نکته‌ی کلیدی |
|--------|-------|--------------|
| BaleBotDriver | bale | API tapi.bale.ai، خانواده‌ی تلگرام |
| TelegramDriver | telegram | api.telegram.org، دوقلوی بله (BaseBotDriver) |
| RoubikaDriver | roubika | botapi.rubika.ir/v3/{token}/؛ webhook با **updateBotEndpoints** (با s) type=ReceiveUpdate؛ نام کاربر با getChat جدا؛ عکس سه‌مرحله‌ای requestSendFile→آپلود→sendFile؛ فقط دکمه Simple |
| BaleSafirDriver | bale_safir | safir.bale.ai/api/v3؛ با **phone** نه chat_id؛ هدر api-access-key + bot_id؛ phone_number رشته‌ی 98...؛ عکس با upload_file→file_id؛ webhook ندارد |
| SmsDriver | sms | provider انتخابی را به کار می‌گیرد؛ با phone؛ دریافت با pull |

**`recipient` در send:** کانال‌های chat_id از `channel_uid` می‌خوانند، کانال‌های شماره‌ای (sms/safir) از `phone`. `AutoReply` هر دو را می‌فرستد.

**SMS providers (channels/sms/):** رابط `SmsProviderInterface` (`name/sendSms/fetchInbound/parseWebhook`). سه پیاده‌سازی: Kavenegar، Smsir، Melipayamak. ملی‌پیامک apiKey به فرمت `username:password` (UI دو فیلد جدا دارد و با : ترکیب می‌کند). ارسال ساده با SendSMS endpoint (نه BaseServiceNumber که برای الگوست — فعلاً نداریم).

`ChannelManager`: درایورها را از eb_settings می‌سازد و کش می‌کند. صفیر و sms موارد خاص با چند پارامتر (`saveSafir`, `saveSms`).

---

## ۶. ماژول‌های کاری (modules/)

- **Persons:** ingestIdentity، attachPhone، merge (جمع‌کننده)، normalizePhone (→98...)، profile، messages، update.
- **Sender:** هسته‌ی ارسال.
  - `sendToPerson(pid,msg,priority,sendId?)`: برای هر شخص اولین کانال موجود از روی اولویت.
  - `sendBulk(pids,msg,priority,delay,onProgress?,sendId?)`: انبوه chat_id؛ اگر sendId ندهی خودش batch می‌سازد.
  - `sendByPhone(phones,msg,mode,delay)`: انبوه با شماره. mode: `safir_then_sms` (پیش‌فرض کم‌هزینه: صفیر، پیامک فقط برای کسانی که بله ندارند) / `safir` / `sms` / `both`.
  - `resolveAudience(filter)`: لیست person_id از فیلتر (group_id, tag_id, status, channel).
- **Templates:** save/find/toMessage/normalizeButtons.
- **AutoReply:** handle(channel,uid,text) — ۴ نوع تطبیق (exact/contains/starts_with/regex)، request_contact.
- **Groups:** گروه و تگ (allGroups/createGroup/addPersons، allTags/ensureTag/tagPersons).
- **Campaigns:** چندمرحله‌ای. create(name,filter,channels,steps)، start (مرحله ۱ فوری)، processDue (cron مراحل بعدی)، filterNonResponders («جواب» = پیام in بعد از زمان ارسال مرحله‌ی قبل)، stats.
- **Scheduler:** زمان‌بندی تک‌مرحله‌ای؛ processDue توسط cron.
- **SmsInbound:** pull پاسخ پیامک خط اختصاصی + اجرای AutoReply روی sms.
- **SendLog:** لاگ سه‌لایه (start/target/finish/all/detail) + cleanup ۶ ماهه (RETENTION_DAYS=180). **همه‌ی متدها try/catch دارند تا لاگ هرگز ارسال را نترکاند.**
- **Stats:** overview (مشتری، نرخ تحویل، نرخ پاسخ، رشد ۳۰ روز، نمودار ۷ روز، تفکیک کانال).

---

## ۷. مسیریابی و API

- `index.php`: front controller. `?page=X` صفحه، `?api=X` → `api/handler.php`. همه با CSRF.
- `api/handler.php`: هندلر مرکزی JSON. ورودی از `$_POST` یا بدنه‌ی JSON. توابع `out()` و `adminOnly()`. تابع `parseXlsxNumbers()` برای استخراج شماره از اکسل (با ZipArchive، بدون کتابخانه).
- `webhook/receive.php`: `?channel=X&secret=Y`. پیام خوش‌آمد /start، سپس AutoReply.
- `cron.php`: `?key=CRON_KEY`. اجرا: Scheduler::processDue + SmsInbound::pull + Campaigns::processDue + SendLog::cleanup.

**CSRF:** `Csrf::requireValid` توکن را از `$_POST`، هدر، یا بدنه‌ی JSON می‌خواند (هر سه). خطا را JSON می‌دهد.

---

## ۸. صفحات UI (views/pages/) — هرکدام .php + .js.php

dashboard · persons (لیست با انتخاب چندتایی، گروه/تگ گروهی، شماره‌گذاری، صفحه‌بندی هوشمند، پروفایل drawer) · send (انبوه chat_id) · safir (ارسال با شماره: صفیر+پیامک، تنظیمات هر دو، آپلود CSV/اکسل) · templates · autoreply · groups · campaigns (چندمرحله‌ای) · scheduled · sendlog (تاریخچه‌ی ارسال + جزئیات گیرنده‌ها) · stats · botsettings · channels (توکن + وب‌هوک + دکمه‌ی db_upgrade).

`layout.php`: اسکلت، sidebar (۱۲ آیتم nav)، CSS کامل (روشن، Vazirmatn، RTL، ریسپانسیو با menu-btn)، توابع `api()/toast()/esc()`. هر صفحه `pages/{name}.js.php` خودکار لود می‌شود.

---

## ۹. راه‌اندازی

1. `config.php` از روی sample (db, app_key, base_url).
2. `install.php` در مرورگر → ساخت جداول + مدیر. (بعد حذفش کن)
3. اگر بعداً نسخه به‌روز شد و جدول/ستونی کم بود: تب «تنظیمات کانال» → دکمه‌ی «به‌روزرسانی دیتابیس» (یا دوباره install).
4. تنظیمات کانال → توکن + ثبت وب‌هوک.
5. **Cron هر دقیقه:** `wget -q -O /dev/null "https://DOMAIN/elanban/cron.php?key=CRON_KEY"` (CRON_KEY در eb_settings کلید cron.key).

---

## ۱۰. محدودیت‌های دانسته / کارهای آینده

- تحویل واقعی پیامک (DLR) ثبت نمی‌شود؛ فقط «به مخابرات تحویل شد».
- الگو/پترن پیامک (BaseServiceNumber با bodyId) هنوز نیست — فقط ارسال ساده.
- ارسال انبوه همگام است (برای فهرست خیلی بزرگ بهتر است صف شود).
- روبیکا: امکانات هنگام پیاده‌سازی واقعی باید تأیید شود (مستندات ناقص رسمی).
- PHP در محیط توسعه اجرا نمی‌شود؛ منطق با شبیه‌سازی تست می‌شود، تست زنده روی سرور لازم است.

---

## ۱۱. الگوهای تکراری مهم (برای توسعه‌ی بعدی)

- **افزودن کانال جدید:** یک درایور با ChannelInterface در channels/ + ثبت در ChannelManager::$map (یا مورد خاص اگر چند پارامتری).
- **افزودن صفحه:** `views/pages/X.php` + `X.js.php` + افزودن X به `$validPages` در index.php + آیتم nav در layout.php.
- **افزودن API:** یک `case 'X':` در api/handler.php که با `out([...])` تمام شود؛ سمت JS با `api('X', {...})`.
- **لاگ ارسال:** هر مسیر ارسال جدید باید `SendLog::start/target/finish` را صدا بزند تا در تاریخچه بیاید.


---

## ۱۲. به‌روزرسانی‌های اخیر (تجربه‌ی کاربری)

- **حالت تاریک:** toggle در sidebar؛ با `data-theme="dark"` روی html و localStorage. همه‌ی رنگ‌ها CSS variable‌اند.
- **onboarding:** داشبورد چک‌لیست راه‌اندازی نشان می‌دهد (API `setup_status`) تا کامل شدن.
- **داشبورد زنده:** بخش «آخرین فعالیت» (API `recent_activity`).
- **ارسال تکه‌ای با نوار پیشرفت:** به‌جای send_bulk همگام، حالا `send_prepare` → چند بار `send_chunk` (هر ۲۰ نفر) → `send_finish`. پیام در `$_SESSION['send_jobs'][sendId]` نگه داشته می‌شود. مزیت: بازخورد زنده + بدون timeout برای فهرست بزرگ. (send_bulk قدیمی هنوز برای استفاده‌ی داخلی/کمپین هست.)
- **صفحه‌ی تنظیمات عمومی (`settings`):** مدیریت cron key (تولید + نمایش دستور)، نام شرکت، میان‌بر به بقیه‌ی تنظیمات. API: `settings_get/settings_save/cron_key_generate`.
- **فیلتر پیشرفته‌ی مشتریان:** در `persons_list` و `resolveAudience`: group_id, tag_id, channel, status, **inactive_days** (بدون پیام در N روز), **has_responded**. برای کمپین فعال‌سازی مجدد عالی.
- **helper `emptyState(ico,title,desc,action)`** در layout برای حالت‌های خالی زیبا.


---

## ۱۳. سطوح کاربری اپراتور (مرحله ۱ - آیتم ۱)

دو نقش ثابت (admin/operator) با محدودیت قابل‌تنظیم برای هر اپراتور توسط admin.
- ستون `eb_users.permissions_json`: `{pages:[...], groups:[...]}`.
- **Auth::canAccess(page):** admin همیشه؛ dashboard/logout همیشه؛ channels/settings/botsettings فقط admin؛ اگر pages خالی = دسترسی کامل، وگرنه فقط لیست.
- **Auth::allowedGroups():** null = همه؛ آرایه = فقط آن group_idها. در `persons_list` و `Sender::resolveAudience` اعمال می‌شود (اپراتور فقط مشتریان گروه‌های مجازش را می‌بیند و به آنها می‌فرستد).
- روتر (index.php) و sidebar (layout) با canAccess فیلتر می‌شوند.
- مدیریت در صفحه‌ی «تنظیمات عمومی»: API `operators_list/operator_create/operator_update/operator_delete` (همه adminOnly).
- **نکته‌ی نصب:** ستون جدید permissions_json → دکمه‌ی «به‌روزرسانی دیتابیس» یا install.

### نقشه‌ی راه باقی‌مانده
- مرحله ۱: ✅ سطوح کاربری · ✅ لینک رهگیری · ⬜ وب‌پوش
- مرحله ۲: OTP · بات‌ساز منوی چندمرحله‌ای · DLR پیامک · فرم/نظرسنجی
- مرحله آخر: بات هوشمند AI (مدیر + مشتری). اصل: **AI پیشنهاد می‌دهد، انسان دکمه‌ی نهایی ارسال را می‌زند.**


---

## ۱۴. لینک رهگیری (مرحله ۱ - آیتم ۲)

- جداول: `eb_links` (code یکتا، target_url، clicks) و `eb_link_clicks` (link_id, person_id, ip, ua, clicked_at).
- ماژول `Links`: create/all/detail/recordClick/delete. کد یکتا ۷ کاراکتری.
- **endpoint عمومی `r.php?c=CODE&p=PERSON_ID`** (بدون لاگین): کلیک را ثبت و 302 به مقصد. اگر p بدهی، کلیک به آن شخص نسبت داده می‌شود.
- صفحه‌ی `links`: ساخت، لیست با شمارش کلیک، جزئیات (کلیک‌های اخیر + مشتری یکتا).
- API: `links_list/link_create/link_detail/link_delete`.
- نکته: target فقط http(s) پذیرفته می‌شود (جلوگیری از open redirect خطرناک).
- **نصب:** جداول جدید → دکمه‌ی «به‌روزرسانی دیتابیس».


---

## ۱۵. بات‌ساز منوی چندمرحله‌ای (مرحله ۲ - آیتم ۱)

- جدول `eb_bot_nodes`: node_key (یکتا per channel)، text، buttons_json، is_root. UNIQUE(node_key, channel).
- ماژول `BotMenu`: all/find/findByKey/root/save/delete/handle.
- هر دکمه: `{text, goto:KEY}` (ناوبری به گره) یا `{text, url}` (لینک). goto → callback_data='nav:KEY'.
- **webhook:** اول BotMenu::handle (اولویت بالا) → اگر جواب داد، welcome و autoreply رد می‌شوند. /start یا «منو»/«شروع» → گرهِ ریشه؛ callback nav:KEY → آن گره.
- **BaseBotDriver::handleInbound** حالا callback_query را هم می‌خواند (type='callback', callback_data). قبلاً فقط message را می‌خواند.
- صفحه‌ی `botbuilder`: ساخت گره با دکمه‌های پویا (برو به / لینک)، علامت گرهِ شروع.
- API: botnodes_list/botnode_save/botnode_delete.
- **نصب:** جدول جدید → دکمه‌ی به‌روزرسانی دیتابیس.

### وضعیت مرحله ۲
✅ بات‌ساز · ✅ DLR پیامک · ✅ فرم/نظرسنجی · ✅ ایمیل · ✅ تلفن گویا — **مرحله ۲ کامل شد** (اسکلت، منتظر متد دقیق SendVoice ملی‌پیامک)
نقشه‌ی بعد: مرحله‌ی ماقبل‌آخر (OTP + وب‌پوش) · مرحله‌ی آخر (بات AI مدیر+مشتری)


---

## ۱۶. فرم/نظرسنجی + ایمیل (مرحله ۲ - آیتم‌های ۳ و ۴)

### فرم/نظرسنجی (موتور حالت‌دار)
- جداول: `eb_forms` (questions_json، trigger_keyword، finish_text)، `eb_form_sessions` (وضعیت فعال هر کاربر: step، answers_json)، `eb_form_responses` (پاسخ نهایی).
- ماژول `Forms::handle(channel,uid,text,personId)`: اگر جلسه‌ی active باشد، پیام = جواب سؤال فعلی؛ وگرنه اگر متن=کلمه‌ی کلیدی، فرم شروع می‌شود. «لغو» جلسه را می‌بندد.
- **اولویت webhook:** فرم (بالاترین) → منو → welcome → autoreply. چون اگر کاربر وسط فرم است، پیامش جواب است نه دستور.
- صفحه‌ی `forms`: ساخت فرم با سؤالات پویا، مشاهده‌ی پاسخ‌ها در drawer.
- API: forms_list/form_save/form_delete/form_responses.

### ایمیل (SMTP)
- `EmailDriver` (channels/): SMTP خام با fsockopen، پشتیبانی TLS/SSL، AUTH LOGIN، body با base64+UTF-8.
- در ChannelManager به‌عنوان مورد خاص 'email' (مثل sms/bale_safir). تنظیمات: channel.email.{host,port,user,pass,from,secure}.
- API: email_get/email_save/email_test. UI در تب channels (فقط admin) با دکمه‌ی تست.
- **نیازمند تست زنده** (TLS/openssl روی سرور). ایمیل ورودی نداریم.

### تلفن گویا (باقی‌مانده)
ملی‌پیامک SendVoice/GetVoiceStatus — منتظر متد دقیق. اسکلت در نوبت بعد.

### نصب
جداول جدید (forms×۳) → دکمه‌ی به‌روزرسانی دیتابیس.


---

## ۱۷. پروفایل اپراتور + سابقه‌ی رفتاری مشتری

### پروفایل اپراتور (صفحه‌ی profile)
- هر کاربر (مدیر/اپراتور) از طریق کلیک روی نامش در sidebar به پروفایل می‌رسد.
- نمایش: نام کاربری، نقش، تعداد ارسال‌های انجام‌شده، سطح دسترسی، تاریخ عضویت.
- تغییر نام کاربری و رمز عبور (با تأیید رمز فعلی).
- API: my_profile/my_password_change/my_username_change. صفحه‌ی profile در $always (همه دسترسی دارند).

### سابقه‌ی رفتاری مشتری (در پروفایل مشتری)
- person_profile حالا علاوه بر پیام‌ها برمی‌گرداند: form_responses (پاسخ به فرم‌ها)، link_clicks (کلیک روی لینک‌های رهگیری)، campaigns (کمپین‌هایی که گرفته + آیا پاسخ داد).
- در drawer مشتری بخش‌های جدید: پاسخ فرم‌ها، کلیک لینک‌ها، کمپین‌ها.

### تلفن گویا — مستندات کامل دریافت شد (نوبت بعد)
ملی‌پیامک **SOAP** است (نه REST مثل SMS): `http://api.payamak-panel.com/post/Voice.asmx?wsdl`.
متدها: SendSpeechText(username,password,to,text) → تماس صوتی متن‌به‌گفتار · UploadVoiceFile(username,password,title,Base64StringFile) · SendBulkVoiceSMS · GetSendSMSWithSpeechTextStatus(recId). خروجی موفق = recId (>10 رقم). پیاده‌سازی با SoapClient بومی PHP (بدون پکیج).


---

## ۱۸. تلفن گویا (مرحله ۲ - آیتم ۵) — مرحله ۲ کامل شد

- `VoiceDriver` (channels/): ملی‌پیامک از طریق **SOAP** (نه REST). WSDL: `http://api.payamak-panel.com/post/Voice.asmx?wsdl` با SoapClient بومی PHP.
- متدها: callSpeech(to,text)=SendSpeechText · uploadVoiceFile · status=GetSendSMSWithSpeechTextStatus. خروجی موفق=recId (>10 رقم)؛ کدهای خطا map شده.
- apiKey از همان تنظیمات ملی‌پیامک (username:password). فقط وقتی provider پیامک=melipayamak فعال است.
- ChannelManager::getVoice()/voiceAvailable().
- API: voice_available/voice_call (تماس انبوه با لاگ در SendLog با kind='voice').
- UI: دکمه‌ی «📞 تماس گویا» در صفحه‌ی ارسال با شماره (فقط اگر فعال باشد). همان شماره‌ها و متن؛ متن باید اعراب‌گذاری‌شده باشد.
- **نیازمند تست زنده** (SOAP + تماس واقعی). تابع status برای پیگیری وضعیت آماده ولی هنوز به UI وصل نشده.

### وضعیت کلی
✅ مرحله ۱ (سطوح کاربری، لینک رهگیری) — وب‌پوش به مرحله‌ی ماقبل‌آخر منتقل شد
✅ مرحله ۲ کامل (بات‌ساز، DLR، فرم، ایمیل، تلفن گویا) + پروفایل اپراتور + سابقه‌ی رفتاری مشتری
⬜ مرحله‌ی ماقبل‌آخر: OTP + وب‌پوش
⬜ مرحله‌ی آخر: بات هوشمند AI (مدیر + مشتری)


---

## ۱۹. رفع باگ‌ها و بهبودها (دور بازخورد)

- **باگ آمار ارسال (مهم):** sendToPerson قبلاً برای هر تلاشِ کانال یک SendLog::target ثبت می‌کرد، پس شخصی با چند کانال چند target می‌ساخت و total < مجموع می‌شد. حالا **هر شخص دقیقاً یک target** (موفق روی کانال جواب‌داده، یا یک ناموفق نهایی).
- **حذف گروه/برچسب از پروفایل:** دکمه‌ی × روی هر چیپ. API: person_group_remove/person_tag_remove (Groups::removePerson/untagPerson).
- **اطلاعات کامل هر هویت در پروفایل:** profile حالا برای هر identity نام+نام‌خانوادگی، بیو (از raw_json)، و آخرین پیام آن شبکه (متن، جهت، تاریخ) را برمی‌گرداند.
- **ورود لیست مشتری از فایل:** API import_customers (CSV «نام,شماره» یا فقط شماره؛ xlsx فقط شماره). با انتخاب گروه/برچسب برای همه. بدون نام = ناشناس، با شماره = member. در صفحه‌ی بانک مشتری drawer ورود.

### باقی‌مانده‌ی این دور (نوبت بعد)
- مورد ۳: تماس گویا ناموفق می‌خورد (نیاز به متن خطای دقیق از تست زنده) + افزودن تماس با فایل صوتی (UploadVoiceFile/SendBulkVoiceSMS به UI).
- مورد ۴: بات‌ساز روی بله ست نمی‌شود (نیاز به جزئیات: /start چه می‌کند؟) + باید برای روبیکا هم فعال شود.


---

## ۲۰. ورود اکسل با نام + حذف مخاطب/اپراتور

- **خواننده‌ی xlsx سطری (parseXlsxRows):** xlsx را سطر-به-سطر با ستون‌ها می‌خواند (sharedStrings را به آرایه‌ی اندیس‌دار تبدیل، هر <c> با نوع t="s"/عدد). برای ورود مشتری با نام. (parseXlsxNumbers قدیمی برای ارسال باقی می‌ماند.)
- **فرمت فایل ورود مشتری:** ستون اول نام، ستون دوم شماره (یا فقط شماره). سطر هدر خودکار رد می‌شود. import_customers هر دو CSV و xlsx را با نام می‌خواند.
- **حذف مخاطب:** API person_delete با کنترل دسترسی (Auth::canManageCustomers + محدودیت گروه اپراتور). دکمه در پروفایل فقط اگر can_delete.
- **مجوز حذف مخاطب در سطوح دسترسی:** permissions.can_delete_customers (چک‌باکس در فرم اپراتور). Auth::canManageCustomers() — admin همیشه.
- **حذف اپراتور:** از قبل operator_delete در تنظیمات.


---

## ۲۱. دور بازخورد بزرگ: تاریخ شمسی، ریسپانسیو، بازنویسی زمان‌بندی، عکس فرم، آپلود صدا

- **تاریخ شمسی:** توابع g2j()+toFa() در layout. همه‌ی نمایش‌های تاریخ (۹ مورد در persons/sendlog/links/forms/dashboard/profile/scheduled) از .slice به toFa() تبدیل شد. الگوریتم تست‌شده (2024-03-20→1403/01/01).
- **ریسپانسیو sendlog:** جدول در wrapper با overflow-x:auto و min-width.
- **بازنویسی کامل زمان‌بندی (Scheduler):**
  - ستون‌های جدید: send_type (chat/phone/voice/email)، phones_text، phone_mode.
  - Scheduler::create حالا آرایه می‌گیرد. processDue بر اساس send_type: chat→sendBulk، phone→sendByPhone، voice→callSpeech حلقه، email→ارسال به ایمیلِ هر مخاطب.
  - collectPhones(): شماره از متن مستقیم یا از گروه/برچسب.
  - صفحه‌ی scheduled بازنویسی شد: نوع ارسال، الگو، برچسب+گروه، عکس (upload_image)، شماره‌ها، روش ارسال.
- **عکس در پیام پایان فرم:** ستون eb_forms.finish_image. Forms::save و پیام پایان عکس را می‌فرستد. UI آپلود در صفحه‌ی forms.
- **آپلود صدا (تلفن گویا):** VoiceDriver::sendBulkVoiceFile (SendBulkVoiceSMS). API voice_upload_file (WAV→base64، چک ۵MB) + voice_bulk_file. UI در صفحه‌ی ارسال با شماره: آپلود WAV + تماس انبوه با فایل.
- **نصب:** ستون‌های جدید scheduled×۳ و forms×۱ → دکمه‌ی به‌روزرسانی دیتابیس.

### هنوز باز (نیازمند جزئیات تست زنده)
- تماس گویای تکی ناموفق (متن خطای دقیق لازم) · بات‌ساز روی بله (جزئیات /start) + فعال‌سازی روبیکا.


---

## ۲۲. سیستم لاگ API + هدر/فوتر

### سیستم لاگ دقیق (مورد ۵)
- جدول `eb_api_log`: channel, action, request_data, response_data, http_code, ok, error.
- ماژول `ApiLog::record/all/clear/cleanup/enabled`. فیلدهای حساس (token/password/api_key) خودکار ماسک می‌شوند. سقف ۸۰۰۰ کاراکتر هر فیلد.
- ثبت در: BaleSafirDriver (send_message)، VoiceDriver (SendSpeechText). قابل گسترش به بقیه.
- صفحه‌ی `apilog` (فقط admin): لیست با فیلتر کانال، drawer جزئیات با payload و پاسخ خام (برای اشکال‌یابی صفیر/تماس). API: apilog_list/apilog_clear.
- cron: ApiLog::cleanup (۱۴ روز).
- **این کلید رفع باگ صفیر است:** کاربر یک پیام تستی صفیر می‌فرستد، payload و پاسخ خام در این صفحه دیده می‌شود → ساختار دقیق مشخص و رفع می‌شود.

### هدر و فوتر (مورد ۳)
- هدر چسبان بالای main: عنوان صفحه‌ی فعلی + دکمه‌ی تم + کاربر (لینک پروفایل) + خروج. (avatar-sm، topbar-*).
- فوتر پایین: نام محصول + سال.
- main حالا flex column با main-inner (پدینگ) و appfoot. کاربر/تم از sidebar footer به هدر منتقل شد.
- toFa در apilog هم استفاده شد.

### نصب
جدول جدید eb_api_log → دکمه‌ی به‌روزرسانی دیتابیس.

## ۲۳. وضعیت کامل پروژه و موارد باز

### ساختار فعلی (همه در /home/claude/elanban/)
- **core/:** Database, Auth (+permissions/canAccess/allowedGroups/canManageCustomers), Csrf, Schema, Helpers, Router, Config
- **channels/:** ChannelInterface, BaseBotDriver (+callback_query), BaleBotDriver, TelegramDriver, RoubikaDriver, BaleSafirDriver (+ApiLog), SmsDriver (+getProvider), EmailDriver (SMTP), VoiceDriver (SOAP +ApiLog), ChannelManager (+getVoice/saveEmail), sms/ (Kavenegar/Smsir/Melipayamak +DLR)
- **modules/:** Persons (+سابقه رفتاری), Identities, Groups, Tags, Templates, Sender (resolveAudience+group/tag/inactive، sendToPerson یک‌target، sendByPhone، collectPhones), AutoReply, BotMenu, Forms (حالت‌دار +finish_image), Campaigns, Scheduler (۴ نوع: chat/phone/voice/email), SmsInbound, SmsDlr, Links, SendLog, ApiLog, Stats
- **api/handler.php:** همه‌ی APIها (persons/send/safir/templates/autoreply/botnodes/forms/groups/campaigns/scheduled/links/voice/email/operators/settings/apilog/my_profile/import_customers/...)
- **views/:** layout (هدر/فوتر/sidebar/تم/toFa) + pages/{dashboard,persons,send,safir,templates,autoreply,botbuilder,forms,groups,campaigns,scheduled,links,apilog,sendlog,stats,botsettings,channels,settings,profile}.php + .js.php
- **webhook/receive.php** (اولویت: Forms→BotMenu→welcome→AutoReply), cron.php, r.php (لینک رهگیری), install.php

### موارد باز (نیازمند تست زنده/جزئیات کاربر)
1. **صفیر خالی:** کاربر باید پیام تستی بفرستد و لاگ خام (صفحه‌ی apilog) را بدهد تا ساختار message_data دقیق مشخص شود. (فعلاً ساختار `message_data.message.text` است.)
2. **بات‌ساز /start هیچ نمی‌کند:** نیاز به بررسی — آیا webhook بله callback/start را می‌رساند؟ آیا گرهِ is_root ساخته شده؟ + فعال‌سازی روبیکا.
3. **تماس گویا ناموفق:** با لاگ apilog حالا raw_result و interpreted دیده می‌شود → کد دقیق خطای ملی‌پیامک مشخص می‌شود.

### تلفن گویا تعاملی (IVR مثل «۱ را بزنید») — مورد ۶
وب‌سرویس صوتی ملی‌پیامک فقط یک‌طرفه است (TTS/فایل). برای IVR تعاملی (گرفتن ورودی از کاربر، منوی صوتی) نیاز به سرویس VOIP جداگانه است (مثلاً سرویس‌های مرکز تماس). ملی‌پیامک این را ندارد. این یک تصمیم/جستجوی جدا می‌خواهد.

### نقشه‌ی راه باقی‌مانده
- مرحله‌ی ماقبل آخر: OTP + وب‌پوش (نیازمند تست زنده، کور)
- مرحله‌ی آخر: بات هوشمند AI (مدیر + مشتری). اصل: AI پیشنهاد می‌دهد، انسان دکمه‌ی نهایی را می‌زند.


---

## ۲۴. رفع باگ‌های ریشه‌ای (دور بازخورد + مستندات صفیر)

طبق مستندات رسمی docs.bale.ai/safir، ساختار درست message_data مشخص شد و چند باگ ردیابی شد:

- **صفیر عکس خالی (مورد ۱):** ساختار اشتباه `message.{photo,caption}` بود. **درست:** `message.{text, file_id}` — عکس با file_id داخل message و text همان کپشن است. اصلاح شد. (این همان تغییری بود که در آپدیتی قبلی شکسته شد.)
- **لاگ خالی (مورد ۲):** ApiLog فقط در صفیر/voice بود. حالا به BaseBotDriver (بله/تلگرام sendMessage/sendPhoto) و **webhook ورودی** (webhook_in با raw payload) هم اضافه شد. نکته: جدول eb_api_log نیاز به db_upgrade دارد، وگرنه لاگ بی‌صدا رد می‌شود.
- **بات‌ساز کار نمی‌کرد (مورد ۳):** BotMenu::handle دکمه‌ها را با کلید `callback_data` می‌ساخت ولی normalizeButtons فقط `callback` را می‌شناسد → دکمه‌ها دور ریخته می‌شدند. اصلاح: BotMenu از `callback` استفاده می‌کند، و BaseBotDriver موقع ارسال `callback`→`callback_data` تبدیل می‌کند (inline_keyboard درست برای بله/تلگرام).
- **عکس فرم/زمان‌بندی (مورد ۴):** forms.js و scheduled.js فایل را با نام `file` می‌فرستادند ولی upload_image فیلد `image` می‌خواهد. اصلاح شد.
- **مورد ۵ (تنظیمات بات):** botsettings از قبل متن خوش‌آمد+دکمه دارد؛ به رفع بات‌ساز و webhook وابسته بود.

### ساختار صفیر (مرجع رسمی)
POST https://safir.bale.ai/api/v3/send_message · هدر: api-access-key
body: {request_id, bot_id, phone_number, message_data:{message:{text, file_id?, reply_markup?}}}

### نصب
جدول eb_api_log → db_upgrade. بعد از آن لاگ‌ها کار می‌کنند.


---

## ۲۵. ادغام تنظیمات ربات در بات‌ساز + اصلاحات UI

- **ادغام (مورد ۲):** «تنظیمات ربات» و «بات‌ساز» یک کار می‌کردند → ادغام شدند. بلوک welcome از webhook حذف شد؛ گرهِ شروعِ بات‌ساز (is_root) تنها منبع /start است. اگر گرهِ شروع نبود، welcome قدیمی (تنظیمات ربات) به‌عنوان fallback می‌ماند. botsettings از nav حذف شد.
- **دکمه‌ی اشتراک شماره در بات‌ساز:** نوع دکمه‌ی سوم 'contact' اضافه شد (کنار goto/url). BotMenu::handle با contact → request_contact=true. این قابلیت کلیدی تنظیمات ربات (تبدیل ناشناس به عضو) را به بات‌ساز آورد.
- **تماس با فایل صوتی (مورد ۱):** لاگ به SendBulkVoiceSMS اضافه شد. نکته‌ی مهم: این متد **زمان‌دار** است (نه فوری)، فقط ساعت ۸-۲۲ و حداقل ۲ شماره. UI شفاف شد. برای تماس فوری تکی از SendSpeechText (متن) استفاده شود.
- **استایل side-foot (مورد ۴):** ساده شد به who-mini (فقط نام/نقش). کاربر/تم/خروج در هدر بالا هستند.
- **آیکن خروج (مورد ۵):** ⏻ → SVG قرمز قشنگ (topbar-logout با err-soft hover).

### مورد ۳ (باز - قابلیت جدید): ارسال چند عکس با کپشن به کانال بله
نیازمند sendMediaGroup + آپلود چندتایی + آدرس کانال. قابلیت جدید (نه باگ). نوبت بعد به‌صورت مستقل.


---

## ۲۶. تلاش رفع آپلود فایل صوتی (خطای SOAP)

خطای زنده: `Value cannot be null. Parameter name: s` — خطای .NET سرور ملی‌پیامک، یعنی Base64StringFile به سرور null می‌رسد (Convert.FromBase64String(s) با s=null).
سه رفع شایع اعمال شد (بدون تست زنده، نیازمند بررسی):
1. base64 تمیز بدون \r\n و فاصله (str_replace).
2. cast صریح همه‌ی پارامترها به string.
3. ارسال به‌صورت (object) به‌جای array برای نگاشت بهتر نام پارامتر در SoapClient.
+ لاگ کامل به UploadVoiceFile (base64_len, sample) → اگر بازهم خطا، payload در apilog دیده می‌شود.

نکته: تماس با فایل قابلیت حاشیه‌ای و پرمحدودیت است (زمان‌دار، ۸-۲۲، حداقل ۲ شماره). تماس با متن (SendSpeechText) که کار می‌کند، فوری و بی‌دردسرتر است.

---

## ۲۷. افزودن دستی مشتری + فیلتر پیشرفته‌ی قوی‌تر

### مورد ۱: افزودن دستی مشتری (صفحه‌ی بانک مشتری)
- **UI:** دکمه‌ی «➕ افزودن مشتری» در کارت بالای صفحه‌ی persons → drawer جدید (addDrawer). فیلدها: نام (اختیاری)، شماره موبایل (اختیاری، کلید هویت طلایی)، چند ردیف «هویت کانال» (انتخاب کانال + شناسه)، یادداشت.
- **شناسه‌ی کانال‌محور:** بسته به کانال، شناسه‌ی درست گرفته می‌شود:
  - بله/تلگرام/روبیکا/صفیر(ربات) → chat_id عددی (علامت منفی برای گروه/کانال حفظ می‌شود).
  - پیامک/صفیر(با شماره)/تلفن گویا → شماره موبایل (normalizePhone).
  - ایمیل → آدرس ایمیل معتبر (FILTER_VALIDATE_EMAIL، lowercase).
- **API:** `person_create` (channels: آرایه‌ای از {channel,value}, full_name, phone, notes). خروجی: person_id, created, merged.
- **منطق:** `Persons::createManual()`:
  1. هر هویت با `normalizeChannelValue($ch,$val)` اعتبارسنجی/نرمال می‌شود؛ نامعتبر → خطای فارسی.
  2. اگر کانال شماره‌ای بود و شماره‌ی صریح نداده بودیم، همان را شماره‌ی طلایی می‌گیرد.
  3. اگر هویتی از قبل موجود بود → روی همان شخص؛ وگرنه اگر شماره با شخص موجودی خورد → روی او؛ وگرنه شخص جدید.
  4. هویت‌ها ثبت/منتقل می‌شوند (تکراری نادیده). در پایان `attachPhone` صدا زده می‌شود که ممکن است merge هویت طلایی را تریگر کند.
  - داده‌ی موجود هرگز پاک نمی‌شود؛ فیلدهای خالی پر و یادداشت‌ها ادغام می‌شوند.
- **توابع جدید Persons:** `createManual`, `normalizeChannelValue`, `isPhoneChannel`(private).
- محدودیت اپراتور: اگر اپراتور محدودیت گروه دارد و مشتری تازه ساخته شد، خودکار به اولین گروه مجازش افزوده می‌شود تا برایش دیده شود.

### مورد ۲: فیلتر پیشرفته‌ی قوی‌تر
**لیست مشتریان (persons_list):** فیلترهای جدید قابل‌ترکیب:
- `channels[]` (چندتایی، شخص در هر یک هویت داشته باشد) به‌جای channel تکی.
- `has_phone` (yes/no)، `responded` (yes=حداقل یک پیام دریافتی / no=هیچ پاسخی)، `min_resp_rate` (نرخ پاسخ ≥ ٪).
- `delivery` (delivered=حداقل یک تحویل موفق در eb_send_targets / failed=فقط ناموفق).
- `active_days` (پیام هر جهت در N روز) + `inactive_days` (بدون ارسال در N روز).
- بازه‌ی تاریخ: `date_field` (created|last_msg) + `date_from`/`date_to` (میلادی YYYY-MM-DD).
- `sort` (recent|created|name|activity). ستون جدید «فعالیت» در جدول: ↑ارسالی ↓دریافتی + نرخ پاسخ (resp_rate). نشانگر تعداد فیلتر فعال کنار دکمه.
- **نکته‌ی فنی مهم (باگ رفع‌شده):** نرخ پاسخ با `SUM(in)*100.0/SUM(out)` محاسبه می‌شود (نه `SUM(in)/SUM(out)*100`) — تقسیم صحیح integer هم در MySQL هم SQLite صفر می‌داد. حتماً `*100.0` بماند.

**تاریخچه‌ی ارسال (sends_list):** کارت فیلتر بالای صفحه: q (عنوان/متن)، kind، channel (تطبیق ستون comma-separated channels با LIKE)، result (success=failed:0/partial=هردو>0/failed=sent:0)، بازه‌ی تاریخ. خلاصه‌ی نتیجه‌ی فهرست فعلی (تعداد عملیات، رسید/نرسید/کل) بالای جدول. تابع جدید `SendLog::filtered($f,$limit)`.
- هیچ تغییر دیتابیسی لازم نیست (همه‌ی داده از جداول موجود: eb_persons، eb_messages، eb_send_targets، eb_sends).

---

## ۲۸. نسخه ۱-الف: صندوق ورودی + پاسخ (مرحله ۱، آیتم‌های ۱+۲)

اولین واحد از بازطراحی تجاری. هدف: مشتری‌ها به کانال‌ها پیام می‌دهند، تا حالا جایی برای دیدن/جواب‌دادن نبود.

### دیتابیس
- ستون جدید `eb_messages.read_at DATETIME NULL` — وضعیت «خوانده‌نشده» برای پیام دریافتی. **مستقل از اپراتور** (تصمیم محصول): هرکس ببیند، برای همه خوانده می‌شود. نیازمند دکمه‌ی «به‌روزرسانی دیتابیس» (db_upgrade).

### ماژول `modules/Inbox.php`
- `conversations($filters,$page,$per)`: گفتگوها **گروه‌بندی‌شده بر اساس شخص** (نه کانال). هر شخص که حداقل یک پیام `direction='in'` دارد، با آخرین پیام (متن+کانال از `GROUP_CONCAT...SEPARATOR 0x1e + SUBSTRING_INDEX`)، شمار خوانده‌نشده (`SUM(read_at IS NULL)`)، و تعداد. فیلتر: channel/unread/q. محدودیت گروه اپراتور اعمال می‌شود.
- `unreadCount()`: مجموع پیام‌های دریافتیِ خوانده‌نشده (برای نشانگر منو). با محدودیت گروه.
- `thread($personId)`: کل گفتگوی شخص (هر دو جهت، زمانی).
- `markRead($personId)`: همه‌ی دریافتی‌های خوانده‌نشده‌ی شخص را خوانده می‌کند (`UPDATE...JOIN`).
- `lastInboundChannel($personId)`: کانالی که آخرین پیام دریافتی از آن آمده (برای پاسخ از همان کانال).

### API
- `inbox_conversations` (فهرست + unread_total) · `inbox_unread_count` · `inbox_thread` (نخ + markRead خودکار + reply_channel + محدودیت گروه) · `inbox_reply` (پاسخ **فوری و تکی**، نه صف).
- **پاسخ:** اولویت کانال = کانالِ آخرین پیام دریافتی، سپس bale/telegram/roubika. روی `Sender::sendToPerson` موجود. لاگ در SendLog با `kind='reply'`, `mode='inbox'`. **عمداً مینیمال و فوری** تا وقتی مرحله ۲ (صف) آمد، نیاز به بازنویسی نداشته باشد.
- پاسخ فقط برای کانال‌های رباتی (بله/تلگرام/روبیکا) فعال است؛ صفیر/پیامک/ایمیل در این نسخه پاسخ مستقیم ندارند (UI شفاف می‌گوید).

### UI
- صفحه‌ی جدید `inbox` (دونمایی: فهرست گفتگو راست، نخ+پاسخ چپ). آیتم منو بعد از داشبورد با **نشانگر قرمز خوانده‌نشده** (nav-badge، در layout با `Inbox::unreadCount()`). ریسپانسیو (موبایل: عمودی).
- باز کردن گفتگو → markRead خودکار → badge صفر. پاسخ با Ctrl+Enter یا دکمه.
- `inbox` در `$validPages` و `$nav`. دسترسی: admin همیشه، اپراتور مثل بقیه‌ی صفحات (مگر pages محدود شده باشد).

### تست‌شده
منطق با SQLite و **کوئری‌های MySQL-specific (GROUP_CONCAT/SUBSTRING_INDEX/UPDATE-JOIN/HAVING) با MySQL واقعی** تأیید شد: شخصِ فقط-خروجی در صندوق نمی‌آید، شمار خوانده‌نشده درست، markRead درست، ترتیب پارامتر فیلترهای ترکیبی (channel+q+group) درست.

### باقی‌مانده‌ی مرحله ۱ (نسخه‌های بعد)
- نسخه ۱-ب: design system + ناوبری دوسطحی.
- نسخه ۱-ج: تنظیمات برند (اسم/رنگ/لوگو).

---

## ۲۹. مرحله ۱ کامل: ناوبری دوسطحی + برند + صیقل design system

(نسخه ۱-الف صندوق ورودی در بخش ۲۸ مستند شد. این بخش بقیه‌ی مرحله ۱ است.)

### ناوبری دوسطحی (layout.php)
- `$nav` تخت حذف شد. حالا `$icons` + `$labels` + `$navGroups` (۶ گروه: ارتباطات/مخاطبان/اتوماسیون/محتوا/تحلیل/تنظیمات). داشبورد بالای همه بدون گروه.
- هر گروه آکاردئونی (`.nav-group` + `toggleNavGroup()`). گروهی که شامل صفحه‌ی جاری است خودکار `open` می‌شود.
- محدودیت دسترسی اپراتور حفظ شد: `array_filter($slugs, fn=>Auth::canAccess)` — گروه خالی نمایش داده نمی‌شود.
- نشانگر خوانده‌نشده‌ی صندوق ورودی داخل گروه «ارتباطات» باقی ماند.
- متغیر JS `titles` از `$labels` ساخته می‌شود (نه `$nav` حذف‌شده).

### برند / سفیدسازی (modules/Brand.php)
- ذخیره در `eb_settings` با کلیدهای `brand.name/tagline/logo/accent`. بدون جدول جدید (k=PRIMARY KEY، پس `ON DUPLICATE KEY UPDATE` کار می‌کند).
- `Brand::all()` (با کش درون‌درخواست + پیش‌فرض‌ها)، `get()`, `save()` (فقط کلیدهای مجاز)، `accentCss()`.
- `accentCss()`: از رنگ اصلی، نسخه‌ی `ink` (۱۸٪ تیره‌تر برای hover) و `soft` (rgba شفاف) می‌سازد و متغیرهای CSS را در حالت روشن و تیره override می‌کند. رنگ نامعتبر → fallback به پیش‌فرض.
- **layout.php**: `$brand` بالای فایل بارگذاری می‌شود؛ عنوان صفحه، نوار کناری (لوگو یا نقطه + نام)، نوار موبایل، و پاورقی از برند می‌خوانند. `<style id="brandAccent">` رنگ را تزریق می‌کند.
- **API**: `settings_get` حالا `brand` را برمی‌گرداند · `brand_save` (name/tagline/accent با اعتبارسنجی hex) · `brand_logo_upload` (آپلود لوگو، svg هم مجاز، مسیر نسبی `uploads/...` ذخیره می‌شود).
- **UI** (settings.php): کارت «ظاهر و برند» با نام، زیرعنوان، آپلود/حذف لوگو با پیش‌نمایش، انتخاب رنگ (color picker + متن hex + ۶ سواچ آماده). پس از ذخیره `location.reload()` تا ظاهر اعمال شود.

### صیقل design system
- شعاع‌ها کمی تیزتر: `--radius:10px`/`--radius-sm:7px` (حس رسمی/اداری). سایه‌ها ملایم‌تر. رنگ پایه از قبل آبی/خاکستری بود.

### تست‌شده
- layout با PHP رندر و بررسی شد: عنوان/لوگو/تزریق رنگ، گروه‌های دوسطحی، باز شدن خودکار گروه فعال، badge صندوق، بدون خطای `$nav` باقی‌مانده.
- منطق رنگ Brand برای ورودی‌های معتبر/نامعتبر (#fff، #000، bad، #ggg) تأیید شد.
- **چرخه‌ی کامل ذخیره/خواندن برند با MySQL واقعی**: persist در eb_settings، خواندن مجدد، متن فارسی سالم، accentCss درست، fallback رنگ نامعتبر.

### وضعیت مرحله ۱: ✅ کامل (صندوق ورودی + پاسخ + ناوبری دوسطحی + برند + صیقل)

---

## ۳۰. بهبود صندوق ورودی و پروفایل (۵ مورد)

### دیتابیس (نیازمند db_upgrade)
- `eb_messages.media_type` (photo|document|null) و `media_path` (مسیر نسبی ارسالی، یا file_id دریافتی).
- `eb_persons.blocked_at` (DATETIME، NULL=فعال).

### مورد ۱ — ارسال عکس در چت
دکمه‌ی پیوست در کادر پاسخ → `upload_image` (حالا `rel` نسبی هم برمی‌گرداند) → `inbox_reply` با `media_path`. ارسال از طریق `Sender::sendToPerson` با `$message['photo']=مسیر مطلق` (روی `sendPhoto` موجود درایور). فقط کانال رباتی.

### مورد ۲ — نمایش رسانه در سابقه
`webhook/receive.php` حالا پیام‌های فقط-عکس (بدون متن) را هم ثبت می‌کند و `media_type`/`media_id` را نگه می‌دارد (قبلاً فقط `!empty(text)` ثبت می‌شد). `BaseBotDriver::handleInbound` حالا `file_id` و نوع رسانه را استخراج می‌کند. `Sender::sendToPerson` رسانه‌ی خروجی را در تاریخچه ثبت می‌کند. در UI: عکس ارسالی واقعاً نمایش، عکس دریافتی به‌صورت «🖼 تصویر دریافتی».

### مورد ۳ — فیکس دکمه‌ی پروفایل
قبلاً `index.php?page=persons` (لیست همه). حالا `?page=persons&person=ID` و persons.js در bootstrap اگر `?person=` ببیند، `openProfile` همان را باز می‌کند.

### مورد ۴ — به‌روزرسانی پروفایل از شبکه
`BaseBotDriver::getChat($chatId)` (متد جدید، روی `call('getChat')`). `Persons::refreshFromNetwork($personId)`: برای هر هویت رباتی، getChat → به‌روزرسانی username/first_name؛ اگر شخص بی‌نام بود، نام را پر می‌کند. API: `person_refresh`. دکمه‌ی «↻ به‌روزرسانی» در سربرگ چت.

### مورد ۵ — بلاک چت
`Inbox::block/unblock/isBlocked`. در `conversations`: بلاک‌شده‌ها پیش‌فرض مخفی (فیلتر `blocked=true` برای دیدنشان). در `webhook`: پیام شخص بلاک‌شده ثبت می‌شود ولی فرم/منو/پاسخ‌خودکار اجرا نمی‌شود (Inbox در webhook require شد). API: `inbox_block`/`inbox_unblock`. دکمه در سربرگ چت + فیلتر «بلاک‌شده‌ها» + غیرفعال‌شدن پاسخ برای چت بلاک.

### مقاومت migration
هر سه ستون با الگوی `hasReadAt`/`hasMedia`/`hasBlock` (SHOW COLUMNS کش‌شده) محافظت شدند. **تست‌شده با MySQL واقعی، هم با migration هم بدون آن — بدون کرش.**

### helper جدید
`inbox_assert_access($personId)` در handler — بررسی محدودیت گروه اپراتور (جایگزین تکرار کد در inbox_thread/reply/block/refresh).

---

## ۳۱. تاریخ شمسی کامل (مرحله ۲)

### توابع جدید در layout.php (سراسری)
- `j2g(jy,jm,jd)` → [gy,gm,gd]: معکوس g2j. **تست‌شده: ۵ تاریخ مرجع + رفت‌وبرگشت ۲۲۰۰ روز متوالی بدون خطا.**
- `enNum/faNum`: تبدیل ارقام فارسی↔انگلیسی.
- `getJDate(id)` مقدار میلادی (YYYY-MM-DD) یک input شمسی، `setJDate(id,greg)` تنظیم.
- دیت‌پیکر شمسی سبک بدون کتابخانه: `jdpInit/jdpOpen/jdpRender/...`. `jdpIsLeap` با واقعیت هماهنگ (تست‌شده ۱۳۹۰-۱۴۲۰).

### نحوه‌ی استفاده
`<input type="text" data-jdate id="x" data-onpick="loadX()">`. مقدار نمایشی شمسی، مقدار واقعی در `dataset.value` (میلادی). `jdpInit()` خودکار در لود صفحه اجرا می‌شود. backend دست‌نخورده — همان YYYY-MM-DD می‌گیرد.

### تغییرات
- ۴ ورودی `type="date"` میلادی (persons ۲ + sendlog ۲) → دیت‌پیکر شمسی.
- خواندن فیلترها: `getJDate()` به‌جای `.value`. resetها با `setJDate(id,'')`.
- ۳ نمایش تاریخ خام (`esc(created_at)`) که از قلم افتاده بود → `toFa`: سابقه‌ی پیام پروفایل، ۲ جای تاریخچه‌ی ارسال. حالا **همه‌ی** نمایش‌های تاریخ شمسی‌اند.

---

## ۳۲. صف پایدار ارسال (مرحله ۲) — رویکرد الف + هیبرید cron/مرورگر

### چرا
ارسال انبوه قبلاً همگام در مرورگر بود (`$_SESSION` + send_chunk). اگر مرورگر بسته می‌شد، نیمی از مخاطبان پیام نمی‌گرفتند. حالا **صف پایدار**: هر گیرنده یک ردیف دیتابیس، پس اگر پردازش قطع شود از همان‌جا ادامه می‌یابد و **هیچ‌کس دوبار پیام نمی‌گیرد** (idempotent).

### دیتابیس (نیازمند db_upgrade)
- `eb_queue_jobs`: یک ردیف per کار (send_id, message_json, total/processed/sent/failed, status: queued|running|done|cancelled).
- `eb_queue`: یک ردیف per گیرنده (job_id, person_id, status: pending|sent|failed, channel, error).

### ماژول `modules/Queue.php`
- `enqueue($personIds,$message,$priority,$title)`: job + ردیف‌های pending (batch insert 500تایی) + SendLog با mode='queue'.
- `process($batch)`: یک batch از pending کل صف را می‌فرستد (روی `Sender::sendToPerson`). **idempotent**: فقط pending→sent/failed. توسط cron و «هل» مرورگر صدا زده می‌شود.
- `cancel($jobId)`, `jobs()`, `job()`, `summary()`, و کمک‌کننده‌های refreshJobCounts/finalizeJob.

### هیبرید cron + هل مرورگر (تصمیم حرفه‌ای)
- **cron** (`cron.php` → `Queue::process(80)`): قطعیت. کار را حتی با مرورگرِ بسته تمام می‌کند.
- **هل مرورگر** (`queue_push`): حسِ زنده‌بودن. صفحه‌ی ارسال و صفحه‌ی صف، تا وقتی pending هست، هر چند صد میلی‌ثانیه یک batch می‌زنند و وقتی خالی شد می‌ایستند (مودبانه، با قفل Q_PUSHING).
- چون idempotent است، cron و مرورگر می‌توانند همزمان کار کنند بدون double-send. **اگر cron نباشد، فقط با هل مرورگر هم کار می‌کند (کندتر).**

### API
`send_enqueue` (جایگزین send_prepare؛ enqueue + یک batch اول) · `queue_push` · `queue_jobs` · `queue_summary` · `queue_cancel`. مسیر قدیمی `send_prepare/send_chunk/send_finish` به‌صورت alias/no-op حفظ شد (سازگاری عقب‌رو).

### UI
- صفحه‌ی جدید `queue` (صف ارسال): نوار پیشرفت هر کار، وضعیت، لغو، و هل خودکار + رفرش زنده.
- `send.js` بازنویسی شد: به‌جای ارسال همگام تکه‌ای، enqueue + حلقه‌ی هل با نوار پیشرفت زنده از `queue_jobs`. پیام: «می‌توانید این صفحه را ببندید».
- `queue` در validPages + nav (گروه ارتباطات، بعد از ارسال).

### تست‌شده (MySQL واقعی)
مقاومت/ادامه از همان‌جا، عدم double-send (۱۰/۱۰ یکتا)، چند-job همزمان، لغو (ردیف‌های pending دست‌نخورده)، جریان هل مرورگر تکه‌تکه، و **سناریوی قطع مرورگر → ادامه با cron**.

---

## ۳۳. ترکیب صفحات: صندوق ورودی سه‌ستونی (مرحله ۲)

### تصمیم
از سه گزینه‌ی «ترکیب»، حرفه‌ای‌ترین و پرکاربردترین انتخاب شد: **صندوق ورودی سه‌ستونی** (الگوی استاندارد Intercom/Crisp/Zendesk): فهرست گفتگو | متن گفتگو | **پروفایل و زمینه‌ی مشتری**. وقتی اپراتور جواب می‌دهد، همان لحظه می‌بیند مشتری کیست، چه گروه/تگی دارد، چند پیام، چه فرم‌هایی — بدون پریدن به صفحه‌ی دیگر. این نیمی از «پروفایل تجاری» مرحله ۳ را در بهترین جا (کنار گفتگو) انجام می‌دهد.

### API
`inbox_profile` (person_id): `Persons::profile` کامل + blocked + form_responses (اگر `Forms::responsesForPerson` موجود باشد — با method_exists محافظت‌شده).

### UI (inbox)
- ستون سوم `inbox-profile` (۲۸۸px): آواتار، نام/شماره/وضعیت، ۳ آمار (ارسالی/دریافتی/کانال)، هویت‌ها با یوزرنیم و آخرین فعالیت، گروه‌ها، تگ‌ها، فرم‌های اخیر، و دکمه‌ی «پروفایل کامل و ویرایش».
- `loadProfilePane(personId)` همراه `openConvo` بارگذاری می‌شود.
- ریسپانسیو: زیر ۱۱۰۰px ستون پروفایل مخفی (صفحه‌های متوسط)، زیر ۷۶۰px عمودی. لیست از ۳۴۰→۳۲۰px.

### تست‌شده (MySQL واقعی)
داده‌ی پروفایل کامل: آمار ارسالی/دریافتی، هویت‌ها، گروه/تگ، کانال برتر — همه درست.

---

## ۳۴. خروجی داده + پروفایل تجاری (مرحله ۳)

### خروجی داده (CSV)
- مسیر جدید `?export=NAME` در index.php (جدا از `?api=` چون هدر فایل می‌خواهد نه JSON). فایل `api/export.php`.
- `export=customers`: CSV همه‌ی مشتریان با فیلترهای فعلی (q/status/has_phone/group/tag) + محدودیت گروه اپراتور. ستون‌ها: شناسه، نام، موبایل، وضعیت، کانال‌ها، گروه‌ها، برچسب‌ها، ارسالی، دریافتی، یادداشت، تاریخ. BOM برای اکسل فارسی.
- `export=messages&person_id=N`: کل گفتگوی یک مشتری.
- دکمه‌ی «📤 خروجی CSV» در بانک مشتری (با فیلترهای جاری) + لینک «📤 CSV» در پروفایل (گفتگوی همان مشتری).
- **باگ رفع‌شده:** `groups` و `tags` کلمات رزروشده‌ی MySQL 8 هستند؛ alias باید با backtick باشد. (فقط با تست MySQL واقعی پیدا شد، نه lint.)
- دانلود از طریق GET (CSRF فقط POST را چک می‌کند؛ Auth::requireLogin قبلاً اجرا شده).

### پروفایل تجاری — تایم‌لاین یکپارچه
پروفایل از قبل غنی بود (هویت، آمار، فرم، کلیک، کمپین در بخش‌های جدا). تنها چیز کم: **یکپارچگی زمانی**. تب جدید «تایم‌لاین» همه‌ی رویدادها (پیام/فرم/کلیک/کمپین) را در یک نخ زمانی واحد (جدیدترین بالا) ادغام می‌کند. **سمت کلاینت** از داده‌ی موجودِ person_profile ساخته می‌شود (بدون API جدید). تب‌بندی نمای کلی/تایم‌لاین در drawer پروفایل.

---

## ۳۵. نمای فلو + تست بات + شاخه‌بندی شرطی (مرحله ۳ — تکمیل)

### دیتابیس (نیازمند db_upgrade)
- `eb_bot_nodes.rules_json`: آرایه‌ای از `{contains, goto}` برای شاخه‌بندی شرطی ساده. در base CREATE + addColumn (هر دو).

### شاخه‌بندی شرطی (ساده، طبق خواست کاربر — پیچیده نشد)
قاعده: «اگر پیام کاربر شامل کلمه‌ی X بود → برو به گره Y». **Stateless** (نیازی به ردیابی node فعلی کاربر ندارد؛ مثل کلیدواژه‌ی سراسری). در `BotMenu::handle` وقتی متن آزاد می‌آید، `matchRule` همه‌ی nodeها را چک می‌کند، اولین تطابق برنده. lowercase + contains.

### ماژول BotMenu (افزوده)
- `nodeToMessage($node)`: تبدیل node به پیام (refactor از handle، مشترک با شبیه‌ساز).
- `matchRule($channel,$text)`: تطابق قاعده‌ی کلمه‌ای، با guard `hasRulesColumn()`.
- `simulate($channel,$input)`: شبیه‌سازی پاسخ بات (text یا callback) بدون ارسال واقعی → `{node, message}`.
- `save` حالا rules_json را با guard ذخیره می‌کند (مقاوم به نبود ستون).

### API
`botnode_save` حالا `rules` می‌گیرد (فیلتر قواعد خالی) · `bot_simulate` (text/callback → matched/node/message).

### UI (botbuilder)
- **ویرایش قواعد** در فرم گره: «اگر شامل [کلمه] → برو به [گره]».
- **نمای فلو**: هر گره یک کارت، یال‌ها = دکمه‌های goto (کلیک‌پذیر، پرش به مقصد با هایلایت)، لینک/تماس، و قواعد (خط‌چین). 
- **شبیه‌ساز تست**: چت‌باکس که /start یا کلمه می‌فرستی، پاسخ بات + دکمه‌های کلیک‌پذیر را نشان می‌دهد، گره مقصد را برچسب می‌زند. بدون ارسال واقعی.

### تست‌شده (MySQL واقعی)
matchRule (خرید/قیمت→sales، بی‌ربط→null)، simulate (/start، کلیک، شرط، عدم‌تطابق)، ذخیره/خواندن قاعده با فیلتر، و **مقاومت بدون migration** (نبود rules_json → بدون کرش).

---

## ۳۶. بات: نمای فلو + تست داخل پنل + شرط کلمه‌ای (مرحله ۳)

### دیتابیس (نیازمند db_upgrade)
- `eb_bot_nodes.rules_json`: آرایه‌ی {contains, goto} برای شاخه‌بندی شرطی ساده. با `hasRulesColumn()` محافظت (قبل از migration کرش نمی‌کند).

### شرط کلمه‌ای ساده (مدل فعلی حفظ شد)
- `BotMenu::matchRule($channel,$text)`: اگر متن کاربر شامل کلمه‌ی یک قاعده بود → node مقصد. اولین تطابق برنده. **بدون state** (مثل تریگر کلمه‌ای سراسری per-node). داخل `handle()` صدا زده می‌شود، پس webhook خودکار پشتیبانی می‌کند.
- `nodeToMessage($node)`: استخراج‌شده از handle، مشترک بین ارسال واقعی و شبیه‌ساز.

### تست داخل پنل (شبیه‌ساز)
- `BotMenu::simulate($channel,$input)`: پاسخ بات به /start، کلیک دکمه (nav:)، یا متن (شرط) — بدون ارسال واقعی. API: `bot_simulate`.
- UI: کادر چت شبیه‌ساز در botbuilder؛ پیام می‌فرستی، جواب بات + دکمه‌های قابل‌کلیک را می‌بینی.

### نمای فلو
- هر node یک کارت؛ دکمه‌های goto و قواعد شرطی به‌صورت یال (با کلیک → پرش به node مقصد). روی داده‌ی موجود، بدون API جدید.

### تست‌شده (MySQL واقعی)
شرط (خرید/قیمت→فروش، بی‌ربط→null)، شبیه‌ساز (/start، کلیک، شرط، عدم‌تطابق)، و مقاومت بدون ستون rules_json.

---

## ۳۷. کنسول فرانت تجاری (`elanban-console.html`) — تک‌فایل، دمو

نسخه‌ی فرانت کامل و یکدست؛ تک‌فایل HTML مستقل با تم برندمحور، روشن/تیره، RTL، Vazirmatn، ریسپانسیو، و ۳۵ صفحه. هیچ backendی ندارد؛ همه‌ی داده نمونه و state در همان نشست (drawer، toast، router هش‌محور).

### تم برندمحور
- تنها متغیر `--brand` کل پنل را رنگ می‌کند (نوار کناری تیره‌ی سازمانی، نمودارها، دکمه‌ها). انتخاب رنگ زنده در «تنظیمات عمومی» با `setBrand(hex)` که پالت (روشن/تیره/سافت/گلو) را از HSL می‌سازد و در `<style id="brandVars">` تزریق می‌کند.

### نمودارها (SVG دستی، بدون کتابخانه)
`spark, bars, donut, areaChart, areaChart2, gauge, rankBars, heatmap, postHeatmap`.

### گروه‌های ناوبری و صفحه‌ها (۳۵)
- داشبورد (کارت هویت طلایی، نمودارها)
- ارتباطات: صندوق ورودی، ارسال، ارسال با شماره، صف، تاریخچه
- **پشتیبانی تیمی (به‌زودی)**: صندوق تیمی، اپراتورها، شیفت و ساعت کاری، SLA و تشدید، نقش‌ها و چارت، گزارش
- مخاطبان: بانک مشتری، گروه و برچسب
- اتوماسیون: بات‌ساز، فرم، پاسخ خودکار، کمپین، زمان‌بندی
- **مارکتینگ و تیم (به‌زودی)**: اهداف، تقویم محتوا، وظایف کانبان، تیم، نقش‌ها/چارت، گزارش
- **هوش مصنوعی (به‌زودی)**: دستیار پاسخ‌گو، مارکتینگ هوشمند
- محتوا: الگوها، لینک رهگیری، **پایگاه دانش**
- **فضای کار تیم: چت داخلی تیم**
- تحلیل: آمار، لاگ سیستم
- تنظیمات: کانال‌ها، تنظیمات عمومی

### ماژول مارکتینگ (skill: `marketing-team`)
پنج لایه: اهداف (ماه/هفته/روز/کمپین با KPI و پیشرفت) → تقویم محتوا (داده‌ی واقعی اکسل جاکجاست خرداد ۱۴۰۵، ۳۰ روز، رنگ‌بندی تراکم، روز ویژه) → فرم محتوای **هر-کاناله** (فیلدها بر اساس نوع عوض می‌شوند) + سناریو → کانبان وظایف → تیم → **چارت درختی قابل‌ویرایش** (افزودن/ویرایش/حذف/تغییر والد) → گزارش‌ها. همه‌ی دکمه‌های ایجاد، state واقعی دارند.

### ماژول پشتیبانی/نیم‌چت (skill: `support-team`)
هفت لایه: صندوق مشترک با تخصیص (دستی/چرخشی/کم‌بارترین/تیمی) + **انتقال دوطرفه به/از هوش مصنوعی** + تایمر زنده‌ی SLA + اولویت + برچسب + احساسات + ماکرو + یادداشت داخلی/منشن؛ اپراتورها (حضور چهارحالته، ظرفیت، مهارت)؛ **شیفت و ساعت کاری** (جدول قابل‌کلیک + تشخیص شکاف)؛ **SLA و تشدید** (سیاست، مکالمات پرخطر، بازتخصیص خودکار)؛ **چارت نقش درختی قابل‌ویرایش** با دسترسی چک‌باکسی؛ گزارش (انحراف AI، انطباق SLA، FRT، هیت‌مپ).

### پایگاه دانش (`kb`)
دسته‌ها + مقاله‌ها (CRUD کامل: افزودن/ویرایش/حذف، جستجو، فیلتر دسته، وضعیت منتشر/پیش‌نویس، بازدید و درصد مفیدبودن). به‌عنوان منبع دانش هوش مصنوعی پاسخ‌گو هم استفاده می‌شود.

### چت داخلی تیم (`teamchat`)
کانال‌ها (عمومی/پشتیبانی/مارکتینگ/فنی/آزاد) + پیام‌رسانی داخلی + ساخت کانال + لیست آنلاین. جدا از مکالمه‌ی مشتری.

### وضعیت «به‌زودی»
گروه‌های مارکتینگ، پشتیبانی و هوش مصنوعی با برچسب «به‌زودی» در ناوبری و یک ribbon بالای صفحه مشخص شده‌اند: پیش‌نمایش طراحی، فعال‌سازی واقعی در نسخه‌های بعدی.

### اعتبارسنجی فرانت
چک سینتکس JS با `node --check`، اجرای همه‌ی ۳۵ رندرکننده‌ی صفحه با DOM stub، تست توابع ایجاد/ویرایش/حذف (state واقعی)، و توازن تگ‌های div. (Chromium برای اسکرین‌شات در محیط در دسترس نبود.)

### skillهای ساخته‌شده
- `marketing-team.skill` — مدل عملیاتی کامل تیم مارکتینگ
- `support-team.skill` — مدل عملیاتی کامل تیم پشتیبانی/نیم‌چت

## ۳۸. ثبت رویداد «اشتراک شماره» در تاریخچه + دریافت محافظتی صفیر

دو باگ شماره/تاریخچه‌ی backend رفع/تأیید شد:

### باگ ۱ — پیام «اشتراک شماره» (contact) در تاریخچه نمی‌آمد
- **محل:** `webhook/receive.php`، بلوک ثبت inbound.
- **ریشه:** شرط ثبت `($hasText || $hasMedia) && !$isCallback` بود. رویداد contact نه `text` دارد نه `media_type`، پس هرگز در `eb_messages` ثبت نمی‌شد (شماره درست merge می‌شد ولی خودِ رویداد گم می‌شد).
- **رفع:** قبل از بلوک عمومی، اگر `type==='contact'` و `phone` موجود بود، یک پیام `in` با متن خوانا ثبت می‌شود: `📱 شماره به اشتراک گذاشته شد: +98…` (شماره با `Persons::normalizePhone` نرمال و با `+` نمایش داده می‌شود).
- **ضد ثبت دوباره:** اگر همراهِ contact متنی هم آمده باشد، به همان پیام ضمیمه و `$hasText=false` می‌شود تا بلوک عمومی دوباره ثبت نکند. callback اصلاً ثبت نمی‌شود.
- **چندکاناله:** برای همه‌ی کانال‌های رباتی (بله/تلگرام/روبیکا) کار می‌کند چون `BaseBotDriver::handleInbound` فیلد `phone` و `type='contact'` را برمی‌گرداند.
- **فرانت:** صفحه‌ی صندوق سه‌ستونی و thread متن پیام (`m.t`/`body`) را خام رندر می‌کنند؛ این پیام بدون نیاز به type خاص درست نمایش داده می‌شود. کانال `bale_safir` در `CH` با برچسب «صفیر بله» شناخته می‌شود.

### باگ ۲ — دریافت پاسخ صفیر بله
- **محل:** `BaleSafirDriver::handleInbound()` که قبلاً `return null` بود.
- **تحقیق:** مستندات رسمی `docs.bale.ai/safir` با ابزار خودکار قابل دریافت نبود (ROBOTS_DISALLOWED). نتایج جستجو فقط سرویس‌های **ارسال** را تأیید کردند (`send_message`, `upload_file`, OTP, `request_id` ضد تکرار). صفحه‌ی محصول از «تعامل دوطرفه» و «رهگیری پیام» نام می‌برد، ولی **قرارداد فنیِ رویداد دریافت (مسیر/نام فیلد شماره/فرمت) با منبع رسمی تأیید نشد.**
- **تصمیم:** پیاده‌سازی **محافظتی** به‌جای حدسِ قطعی. متد شماره را از کلیدهای محتمل (`phone_number`/`phone`/`from.phone_number`/`message.phone_number`/`data.phone_number`) استخراج و با `normalizePhone` نرمال می‌کند؛ متن و `file_id` را هم از الگوهای محتمل می‌خواند. **اگر شماره‌ی معتبری نبود `null` برمی‌گرداند** تا هیچ merge اشتباه یا پیام جعلی ثبت نشود.
- **هویت:** چون صفیر phone-based است و chat_id ندارد، `channel_uid` را **خودِ شماره‌ی نرمال‌شده** می‌گذاریم → هر شماره دقیقاً یک هویت صفیر، و merge طلایی روی شماره درست عمل می‌کند. اگر صفیر پاسخ شماره بدهد، **بهترین منبع هویت طلایی** است (شماره‌ی مستقیم، بدون دکمه‌ی اشتراک شماره).
- **محدودیت ثبت‌شده (نیازمند تست زنده):** نگاشت دقیق فیلدهای رویداد صفیر تأییدنشده است. روش قطعی‌سازی: کاربر یک پاسخ تستی به پیام صفیر می‌فرستد، payload خام در صفحه‌ی `apilog` (کلید `webhook_in`) دیده می‌شود، سپس استخراج‌گرِ `handleInbound` با ساختار واقعی جایگزین می‌شود. `setWebhook` صفیر با API نیست؛ آدرس دریافت در پنل سازمانی صفیر تنظیم می‌شود.

### اعتبارسنجی
توازن براکت (token-aware، با هندل رشته/کامنت/regex) روی `receive.php`, `BaleSafirDriver.php`, `BaseBotDriver.php`, `Persons.php`, `Inbox.php` = OK. شبیه‌سازی پایتونیِ `normalizePhone` و استخراج‌گر صفیر روی ۶ پی‌لود (معتبر تخت/تودرتو، بدون شماره، شماره‌ی نامعتبر) = خروجی درست؛ موارد بدون شماره → `null`.

## ۳۹. پاسخ خودکار رویدادی: «اشتراک شماره» (on_contact)

نیاز: ادمین بتواند برای رویدادِ «کاربر شماره به اشتراک گذاشت» یک پاسخ خودکار تنظیم کند. چون رویداد contact متن ندارد، نمی‌شد آن را به‌عنوان کلمه/کلید پاسخ متنی تعریف کرد. راه‌حل: یک **نوع تریگر رویدادی** مستقل.

### تغییرات backend
- **`eb_auto_replies.trigger_type`**: از `ENUM` به `VARCHAR(20)` گسترش یافت تا مقدار `on_contact` (و انواع آینده) پذیرفته شود.
  - نصب تازه: `install.php` مستقیم `VARCHAR(20)`.
  - نصب قدیمی: `core/Schema.php::widenTriggerType()` با migration **idempotent** (فقط اگر هنوز ENUM باشد `MODIFY COLUMN` می‌زند). از طریق `Schema::ensure()` در دکمه‌ی «به‌روزرسانی دیتابیس» (`db_upgrade`) اجرا می‌شود.
- **`AutoReply::save()`**: برای نوع `on_contact` چون کلمه‌ی کلیدی معنی ندارد، `trigger_keyword` را به مقدار ثابت بی‌اثر `'__contact__'` ست می‌کند تا قید NOT NULL نقض نشود و ادمین مجبور به وارد کردن کلمه نباشد. سایر انواع دست‌نخورده.
- **`AutoReply::handleContact($channel, $channelUid)`** (متد جدید): قواعدِ فعالِ نوع `on_contact` برای این کانال یا همه‌کاناله را به ترتیب `priority DESC, id DESC` می‌گیرد و اولین موردِ دارای محتوا (متن یا دکمه) را می‌فرستد. قاعده‌ی بدون محتوا رد می‌شود. پشتیبانی از دکمه (`normalizeButtons`) و در صورت ست‌بودن و پشتیبانی کانال، `request_contact`.
- **`matches()`**: نوع `on_contact` در مسیر متنی هرگز تطبیق نمی‌دهد (در `default` رد می‌شود) تا با پیام‌های متنی قاطی نشود؛ پردازشش فقط از مسیر رویدادی است.
- **`webhook/receive.php`**: در dispatch پاسخ خودکار، اگر `$isContact` بود `handleContact` صدا زده می‌شود، وگرنه مسیر متنی `handle`. این پس از Forms و BotMenu است، پس **تابع اولویت webhook** است: اگر فرم/منوی فعالی رویداد را گرفته باشد، پاسخ on_contact اجرا نمی‌شود (جلوگیری از پاسخ دوگانه).

### چندقاعده‌ای
طبق تصمیم کارفرما (گزینه‌ی ۲): چند قاعده‌ی `on_contact` با اولویت و فعال/غیرفعال مجاز است؛ اولین قاعده‌ی فعالِ دارای محتوا به ترتیب اولویت اجرا می‌شود (مثل بقیه‌ی قواعد AutoReply).

### فرانت (`elanban-console.html`)
صفحه‌ی `autoreply`: ستون «کلیدواژه / رویداد»، یک ردیف نمونه‌ی «📱 اشتراک شماره» با نشان نوع «رویداد» (badge)، و زیرنویس به‌روز. (فرم واقعی افزودن/ویرایش هنگام وصل‌کردن این صفحه به backend ساخته می‌شود؛ آنجا انتخاب نوع «دریافت شماره» فیلد کلمه‌ی کلیدی را مخفی می‌کند.)

### محدودیت سفیر (یادآوری، تأییدشده با مستند رسمی)
مستند رسمی سفیر (`safir.bale.ai/api/v3`) فقط سرویس **ارسال** را دارد (`send_message`, `upload_file`, OTP, `request_id` برای idempotency؛ شماره باید `98…` و بدون کاراکتر اضافه باشد). **هیچ endpoint/webhook دریافت پاسخ مستند نشده است.** پس on_contact برای سفیر عملاً کاربرد ندارد (سفیر contact دریافت نمی‌کند)؛ این تریگر برای کانال‌های رباتی (بله/تلگرام/روبیکا) است که دکمه‌ی اشتراک شماره دارند. پیاده‌سازی محافظتی `handleInbound` سفیر همچنان «نیازمند تست زنده» باقی می‌ماند.

### اعتبارسنجی
توازن براکت (token-aware) روی `AutoReply.php`, `receive.php`, `Schema.php`, `install.php` = OK. `node --check` روی اسکریپت اصلی فرانت = OK. شبیه‌سازی پایتونیِ `save()` (پیش‌فرض `__contact__`) و `handleContact()` (اولویت/کانال/غیرفعال/خالی) = خروجی درست.

## ۴۰. تکمیل UI ادمینِ پاسخ خودکار رویدادی (views/pages/autoreply)

نکته‌ی مهم: در بخش ۳۹ منطق on_contact ساخته شد ولی **فرم واقعی ادمین در backend** (نه console.html نمایشی) هنوز راهی برای تعریف آن نداشت. این بخش آن نقص را رفع کرد.

### autoreply.php (فرم)
- گزینه‌ی «📱 دریافت شماره (اشتراک شماره)» با مقدار `on_contact` به منوی «نوع تطبیق» اضافه شد.
- `<select id="arType">` حالا `onchange="onARTypeChange()"` دارد.
- فیلد کلمه‌ی محرک داخل wrapper با `id="arKeywordField"` قرار گرفت تا قابل مخفی‌کردن باشد.
- یادداشت توضیحی `id="arContactNote"` اضافه شد (پیش‌فرض مخفی): توضیح می‌دهد قانون رویدادی است، کلمه نمی‌خواهد و فقط در کانال‌های رباتی کار می‌کند.

### autoreply.js.php
- `ARTYPE` نگاشت `on_contact:'📱 اشتراک شماره'` گرفت؛ در جدول، ستون محرک برای این نوع «— رویدادی —» نشان می‌دهد.
- `onARTypeChange()`: با انتخاب on_contact فیلد محرک مخفی و یادداشت نمایش داده می‌شود؛ برعکس برای انواع متنی.
- اعتبارسنجی `saveAR()`: برای on_contact فقط «متن پاسخ» لازم است (نه کلمه‌ی محرک)؛ برای بقیه هر دو لازم‌اند.
- `editAR()` و `resetAR()` پس از ست‌کردن نوع، `onARTypeChange()` را صدا می‌زنند تا UI با حالت قانون هماهنگ شود.

### مسیر کامل ادمین (تأییدشده)
فرم (انتخاب on_contact، نوشتن متن، اختیاری دکمه/اولویت/کانال) ← `autoreply_save` در handler (نوع را از ورودی پاس می‌دهد) ← `AutoReply::save()` (محرک = `__contact__`) ← DB (ستون VARCHAR پس از db_upgrade). در زمان اجرا: کاربر شماره می‌دهد ← `receive.php` (`$isContact`) ← پس از Forms/BotMenu ← `AutoReply::handleContact()` ← ارسال.

### اعتبارسنجی
`node --check` روی autoreply.js.php = OK. توازن براکت autoreply.php/js = OK. چک یکپارچگیِ ۶ حلقه‌ی زنجیره (فرم←handler←save←handleContact←migration←receive) = همه موجود.

---

## ۴۱. دور ۱۳ موردی — بخش اول: ارسال دوباره، کندی سایت، دسترسی‌ها، شمار خوانده‌نشده، پاسخ بات

پنج مورد از فهرست ۱۳ موردی کاربر. سه‌تای اول باگ ریشه‌ای بودند (نه ظاهری) و ریشه‌شان یک الگوی مشترک داشت: **خواندن و نوشتن در دو دستور جدا، بدون قفل**.

### مورد ۲ — شمار «خوانده‌نشده» اشتباه بود
- **ریشه:** `Inbox::unreadCount()` مستقیم روی `eb_messages` جمع می‌زد و اصلاً `eb_persons` را نمی‌دید، در حالی که `conversations()` چت‌های بلاک‌شده را (درست) مخفی می‌کرد. نتیجه: badge عددی نشان می‌داد که در فهرست وجود نداشت.
- **رفع:** جوین به `eb_persons` + شرط `p.blocked_at IS NULL` (با گاردِ `hasBlock()` برای نصب‌های بدون migration). `modules/Inbox.php:134`.
- کش درون‌درخواست اضافه شد چون layout و API هر دو در یک request صدایش می‌زنند.

### مورد ۶ — بعضی پیام‌ها دوبار ارسال می‌شد (مهم‌ترین مورد این دور)
**دو ریشه‌ی کاملاً مستقل داشت. هر دو بسته شد.**

**ریشه‌ی الف — مسابقه‌ی (race) پردازنده‌های صف.** الگوی معیوب در **سه** جا تکرار شده بود:
`Queue::process`، `Scheduler::processDue`، `Campaigns::processDue` — همه `SELECT ... WHERE status='pending'` می‌کردند و بعد در دستوری **جدا** ارسال و update می‌کردند. cron هر دقیقه اجرا می‌شود و `send.js` هم در حلقه `queue_push` می‌زند؛ اگر یک اجرا بیش از یک دقیقه طول بکشد، دو worker همان ردیف‌ها را برمی‌دارند و هر دو می‌فرستند.
- **رفع، دولایه:**
  1. قفل انحصاری worker با `GET_LOCK` (`Queue.php:163-178`، `Scheduler`، و قفل سراسری cron در `cron.php:51`).
  2. **claim اتمیک**: اول `UPDATE ... SET status='sending', claim_token=?, claimed_at=NOW() WHERE status='pending' LIMIT n`، بعد `SELECT ... WHERE claim_token=?`. یعنی مالکیت ردیف را update تعیین می‌کند نه select. (`Queue.php:204-210`)
- **چرا دولایه:** لایه‌ی دوم حتی اگر `GET_LOCK` در دسترس نباشد یا اتصال‌ها جدا باشند کار می‌کند.
- **نکته‌ی نسخه (تله‌ی واقعی):** در MySQL ≤۵.۶ گرفتن قفل تازه، قفل قبلی را **بی‌صدا آزاد می‌کند**. پس قفل‌ها تودرتو نشدند — cron و صف قفل هم‌زمان نمی‌گیرند.
- **وضعیت `sending`:** شمارنده‌ها اصلاح شدند تا `sending` را «پردازش‌شده» حساب نکنند؛ `summary()` هم آن را باز می‌شمارد. ردیف‌های claim‌شده در خطا به `pending` برمی‌گردند (`Queue.php:223,239`).

**ریشه‌ی ب — retry وب‌هوک.** بله/تلگرام اگر ۲۰۰ را دیر بگیرند همان update را دوباره می‌فرستند و `receive.php` هیچ dedup روی شناسه‌ی رویداد نداشت → پاسخ خودکار/فرم دوباره اجرا می‌شد. این با ریشه‌ی الف فرق دارد: الف پیام‌های **انبوه** را تکرار می‌کرد، ب **پاسخ خودکار** را.
- **رفع:** جدول `eb_webhook_events` با کلید یکتا روی `(channel, event_key)`؛ INSERT تکراری = رویداد تکراری = خروج زودهنگام (`receive.php:81-87`).
- **طراحی fail-open (عمدی):** اگر جدول نبود یا خطایی رخ داد، پیام **پردازش می‌شود**. تکرار نادرِ یک پیام بهتر از دورریختن پیام مشتری است.
- پاک‌سازی: cron رکوردهای قدیمی‌تر از ۲ روز را حذف می‌کند (`cron.php:73`).

### مورد ۸ — سایت چندباری بالا نمی‌آمد
- **ریشه:** قفل فایل session. PHP تا پایان یک request قفل session را نگه می‌دارد؛ چون `queue_push` طولانی است، همه‌ی درخواست‌های بعدیِ همان کاربر (حتی باز کردن یک صفحه) پشت آن صف می‌کشیدند و سایت «بالا نمی‌آمد».
- **رفع:** `session_write_close()` در مسیر API بعد از احراز هویت (`api/handler.php:31`). امن است چون بررسی شد `Csrf` فقط از session **می‌خواند** و چیزی نمی‌نویسد.
- **مکمل:** حلقه‌های هلِ مرورگر حالا وقتی worker مشغول است backoff می‌کنند به‌جای کوبیدن پشت‌سرهم (فلگ `busy` از API).

### مورد ۹ — بیشتر دسترسی‌ها در فرم کاربر جدید نبود
- **ریشه:** فهرست صفحات در فرم دسترسی، **دستی** نوشته شده بود و از ناوبری عقب مانده بود. هفت صفحه غایب بود: صندوق ورودی، لینک رهگیری، فرم‌ها، بات‌ساز، صف، مارکتینگ، پشتیبانی. برای همین ناچار به دادن «دسترسی کل» شده بودید.
- **رفع:** فهرست کامل و گروه‌بندی‌شده هم‌ساختار با نوار کناری.
- **باگ ضمنی که سر راه پیدا شد:** کد از `faN(...)` استفاده می‌کرد ولی تابع سراسری `faNum` است و `faN` فقط داخل `queue.js.php` تعریف شده بود → در صفحه‌ی تنظیمات `ReferenceError` می‌داد. اصلاح شد.

### مورد ۱۰ — پیامی که بات فرستاده در گفتگو دیده نمی‌شد
- **ریشه:** `AutoReply`، `BotMenu` و `Forms` مستقیم از درایور کانال می‌فرستادند و چیزی در `eb_messages` ثبت نمی‌کردند. پس وقتی مشتری «۱» را می‌فرستاد، معلوم نبود «۱» پاسخِ کدام پیام است.
- **رفع:** ماژول تازه‌ی `modules/BotReply.php` — تنها مسیر ارسال بات. می‌فرستد **و** پیام را با `source` ثبت می‌کند (`autoreply|botmenu|form|ai`). پنج نقطه‌ی فراخوانی وصل شد (`AutoReply.php:138,190`، `BotMenu.php:150`، `Forms.php:101,124,157,164`).
- ستون `eb_messages.source` + برچسب «🤖 پاسخ خودکار / منوی بات / فرم» در UI گفتگو.
- ثابت `SRC_AI` از همین حالا تعریف شد تا مورد ۱۲ (پشتیبانی هوش مصنوعی) بدون تغییر اسکیما وصل شود.

### اصلاح جانبی: `install.php`
یک کپیِ **کهنه** از اسکیما داشت (۱۶ جدول از ~۳۶) که با `core/Schema.php` هم‌گام نبود؛ یعنی نصب تازه ناقص بالا می‌آمد. حذف و به `Schema::ensure()` وصل شد — حالا یک منبع حقیقت.

### دیتابیس (نیازمند db_upgrade)
`eb_messages.source` · `eb_queue.claim_token` + `claimed_at` + وضعیت `sending` · جدول `eb_webhook_events` · دو ایندکس (`idx_claim`, رویداد یکتا).

### اعتبارسنجی
- توازن براکت token-aware (با هندل رشته/کامنت/heredoc) روی همه‌ی فایل‌های تغییریافته = OK. صحتِ خودِ ابزار با تزریق خطای عمدی تأیید شد.
- **شبیه‌سازی مسابقه با SQLite:** سناریوی کلاسیک (worker الف ردیف‌ها را برمی‌دارد، ب وسط کار کامل اجرا می‌شود، بعد الف می‌فرستد) با الگوریتم قدیم **۱۰ ارسال به ۵ نفر** داد — دقیقاً همان علامتی که کاربر گزارش کرده بود؛ با الگوریتم جدید **صفر تکرار**.
- کوئری قدیم/جدیدِ `unreadCount` کنار هم اجرا و اختلاف عدد تأیید شد.
- `node --check` روی JSهای تغییریافته + بررسی اینکه هر `onclick` به تابع تعریف‌شده می‌رسد.
- **محدودیت:** در این نشست PHP در محیط اجرا نبود، پس `php -l` زده نشده. تغییرات صف/زمان‌بندی عمداً محافظه‌کارانه و برگشت‌پذیرند، ولی **قبل از اتکا روی سرور زنده باید یک ارسال آزمایشی گرفته شود.**

---

## ۴۲. دور ۱۳ موردی — بخش دوم: لینک رهگیری (موارد ۱، ۳، ۴، ۵، ۱۱)

پنج مورد از فهرست کاربر که همه در یک ماژول بودند، در یک دسته زده شدند.

### مورد ۱ — لینک تماس (شماره داخل لینک رهگیری)
- مقصد حالا دو نوع دارد: `http(s)` و `tel:`. تشخیص با `Links::kindOf()`.
- `Links::normalizeTarget()` جای رگکس تک‌خطی قبلی را گرفت و **allowlist طرح** است، نه صرفاً «باید با http شروع شود». می‌پذیرد: آدرس کامل · شماره‌ی خام (`09121234567`، `+98…`، `021…`) · `tel:…` · دامنه‌ی بدون طرح (خودش `https://` می‌گذارد). رد می‌کند: هر طرح دیگر.
- **چرا allowlist و نه حذف اعتبارسنجی:** محدودیت http(s) عمدی بود (بخش ۱۸۶ همین سند: جلوگیری از open redirect). حالا `tel` هم اضافه شد ولی `javascript:`/`data:`/`file:` همچنان بسته‌اند.
- نرمال‌سازی شماره‌ی ایران داخل خودِ ماژول است (نه `Persons::normalizePhone`)، چون `r.php` فقط `Database` و `Links` را بارگذاری می‌کند و نباید به ماژول دیگری وابسته شود. موبایل → `tel:+98…`، ثابت با کد شهر → `tel:021…` بدون `+` (تا شماره‌گیر محلی درست عمل کند).
- **صفحه‌ی واسط به‌جای ۳۰۲:** ریدایرکت ۳۰۲ به `tel:` در بخشی از مرورگرهای موبایل و وب‌ویوهای داخل پیام‌رسان‌ها بی‌اثر است (صفحه‌ی سفید). پس `r.php` برای `tel:` یک صفحه‌ی کوچک می‌دهد که خودکار شماره‌گیر را باز می‌کند و دکمه‌ی دستی + خودِ شماره را هم نشان می‌دهد. مقصد وب همان ۳۰۲ قبلی است.

### مورد ۳ — تاریخ لینک‌ها
ستون `created_at` از قبل بود ولی UI هیچ‌جا نشانش نمی‌داد. ستون «تاریخ ساخت» به جدول اضافه شد (با `toFa`) و در drawer جزئیات هم می‌آید.

### مورد ۴ — ویرایش عنوان و لینک
- `Links::update($id, $patch)` + اکشن `link_update`. فیلدی که در ورودی نباشد دست‌نخورده می‌ماند.
- **کد کوتاه هرگز عوض نمی‌شود** — لینک‌هایی که قبلاً برای مشتری رفته‌اند باید کار کنند و به مقصد تازه بروند. این در UI هم صریح نوشته شده.
- drawer دو حالت دارد (نمایش / ویرایش)، به‌جای drawer دوم.

### مورد ۵ — دسته‌بندی
- ستون `eb_links.category` (VARCHAR 60، آزاد نه ENUM) + ایندکس.
- ورودی با `datalist`: دسته‌های موجود + پیشنهادهای `Links::SUGGESTED` (استوری/ریلز/پست/سایت/پیامک/بات/کمپین). کاربر می‌تواند دسته‌ی تازه هم بنویسد.
- فیلتر بالای جدول: همه / بدون دسته / هر دسته با تعداد. فیلتر نوع (وب/تماس) و جستجو هم اضافه شد.
- انتخاب‌گر لینک در `layout.php` هم دسته را به‌صورت `دسته › عنوان` نشان می‌دهد.

### مورد ۱۱ — اطلاعات بیشتر بازدیدکننده
- ستون‌های تازه‌ی `eb_link_clicks`: `referer`, `device`, `browser`, `os`. (IP و user_agent از قبل ثبت می‌شدند ولی **هیچ‌جا نمایش داده نمی‌شدند** و referer اصلاً گرفته نمی‌شد.)
- `Links::parseUa()` بدون کتابخانه (پروژه composer ندارد): دستگاه (موبایل/تبلت/رایانه/ربات)، مرورگر، سیستم‌عامل. **ترتیب تشخیص مهم است:** ربات‌ها اول (وگرنه به‌عنوان مرورگر شناسایی می‌شوند) و اج/اپرا قبل از کروم (چون خودشان را کروم هم معرفی می‌کنند). پیش‌نمایش‌کننده‌ی تلگرام/بله/واتساپ جدا برچسب می‌خورد — این مهم است چون خودِ پیام‌رسان لینک را پیش‌واکشی می‌کند و آن کلیک واقعی نیست.
- `Links::clientIp($trustProxy)`: پشت CDN، `REMOTE_ADDR` آدرس پروکسی است. کلید تازه‌ی config: `trust_proxy` (پیش‌فرض false). **عمداً پیش‌فرض false** چون `X-Forwarded-For` قابل جعل است و اعتماد بی‌مورد آمار را آلوده می‌کند.
- drawer جزئیات: تفکیک دستگاه/مرورگر/سیستم‌عامل/مرجع با نوار سهم، آمار «بازدیدکننده یکتا» بر اساس IP، و در هر کلیک دستگاه/مرورگر/OS/IP/مرجع.

### باگ ریشه‌ای که سر راه پیدا شد: آدرس پنل ≠ آدرس داخل پیام
- `app_base_url()` داخل `api/handler.php` بود، پس فقط مسیر API از آن استفاده می‌کرد. پنل لینک کوتاهِ **درست** نشان می‌داد، ولی `index.php`/`cron.php`/`webhook/receive.php` مقدار خام `$config['base_url']` را به `Personalize::setBaseUrl` می‌دادند.
- نتیجه: نصب‌کننده‌ای که `base_url` را ویرایش نکرده بود (مقدار نمونه `https://example.com/elanban`)، در پنل لینک سالم می‌دید ولی **لینک داخل هر پیام ارسالی به مشتری مرده بود** — خرابی کاملاً بی‌صدا که دقیقاً همین قابلیت را بی‌اثر می‌کند.
- **رفع:** تابع به `core/Url.php` منتقل و در هر چهار بوت‌استرپ استفاده شد.
- تشخیص مسیر نصب از `DOCUMENT_ROOT` + `dirname(__DIR__)` است، نه `SCRIPT_NAME` — چون نقطه‌ی ورود می‌تواند `index.php` یا `webhook/receive.php` یا `r.php` باشد و با `SCRIPT_NAME` مسیر غلطی مثل `/elanban/webhook` درمی‌آمد. پشتیبان `SCRIPT_NAME` با حذف `/(api|webhook)$` مانده.
- **cron در CLI میزبان HTTP ندارد**، پس نمی‌تواند تشخیص دهد. لایه‌ی وب آدرس تشخیص‌داده‌شده را یک‌بار در هر نشست در `eb_settings['app.base_url_detected']` ذخیره می‌کند و cron از آن می‌خواند. اگر `base_url` در config درست تنظیم باشد، این کل مسیر دور زده می‌شود.

### دیتابیس (نیازمند db_upgrade)
`eb_links.category` + ایندکس · `eb_link_clicks.referer/device/browser/os`
تک‌تک این ستون‌ها با گارد (`hasCategory()` / `hasVisitorCols()`) محافظت شدند تا قبل از db_upgrade کرش نکنند؛ UI هم اگر تفکیک خالی بود پیام «یک بار به‌روزرسانی دیتابیس را بزن» نشان می‌دهد.

### اعتبارسنجی
- **بازبینی مستقل با subagent** روی قرارداد API (نام هر فیلدی که JS می‌خواند در PHP وجود دارد)، وجود سمبل‌ها، متغیرهای CSS، و تعداد ستون/colspan — و رفع ۷ ایراد پیداشده.
- **ردیابی دستی حمله به allowlist:** `  javascript:` و `java<TAB>script:` (کاراکتر کنترلی قبل از تشخیص طرح حذف می‌شود، پس به `javascript:` تبدیل و رد می‌شود)، `JaVaScRiPt:`، `//evil.com`، `\/\/evil.com`، `https:/\evil.com`، `http:evil`، `https://` تنها، `tel:` بی‌رقم، `evil.com@attacker.com` — همه رد شدند. مسیر ورودی هر یک در همان ردیابی مشخص شد.
- CR/LF: هم در ورودی حذف می‌شود، هم `isAllowedTarget()` قبل از `header('Location')` ردش می‌کند (جلوگیری از header injection برای ردیف‌های قدیمی).
- خروجیِ صفحه‌ی واسط در سه بستر جدا escape شد: متن HTML و `href` با `htmlspecialchars(ENT_QUOTES)`، و رشته‌ی JS با `json_encode` + `JSON_HEX_TAG|AMP|APOS|QUOT` (و **بدون** `JSON_UNESCAPED_UNICODE` تا U+2028/2029 هم escape شوند).
- **محدودیت (مثل بخش ۴۱):** محیط لینوکس در این نشست بالا نیامد (خطای VHDX)، پس `php -l` و `node --check` اجرا نشده. اعتبارسنجی از راه بازخوانی و بازبینی مستقل بوده. **قبل از استفاده‌ی جدی: یک لینک وب و یک لینک تماس بساز، هر دو را باز کن، و db_upgrade را بزن.**

---

## ۴۳. دور ۱۳ موردی — مورد ۱۲ (پشتیبانی هوش مصنوعی): لایه‌ی دانش چندمنبعی

**وضعیت پیش از این دور (تصحیح مستند):** برخلاف تصوری که از بخش‌های ۴۱/۴۲ برمی‌آمد، **هسته‌ی مورد ۱۲ از قبل ساخته شده بود** و در این سند مستند نشده بود:
- `modules/Ai.php` — کلاینت سازگار با OpenAI (`chat/completions`)، `draftFor` (پیش‌نویس برای اپراتور)، `autoReply` (فقط وقتی تیم آفلاین است یا ارجاع دستی)، `mode/modeFor`، سقف نرخ، `test`.
- `modules/Support.php` — آنلاین/آفلاین، ساعت کاری با `DateTimeZone`، `presence`، و ارجاع دستی گفتگو به AI (`eb_persons.ai_handoff_at`).
- `webhook/receive.php` — AI به‌عنوان **آخرین حلقه** وصل است (بعد از فرم/منو/پاسخ‌خودکار؛ فقط اگر آفلاین/ارجاع‌شده).
- `views/pages/ai.php` + `ai.js.php` — صفحه‌ی تنظیمات کامل (اتصال، شخصیت، امضا، ساعت کاری).
- `api/handler.php` — `ai_settings/ai_save/ai_key_clear/ai_test/ai_draft/support_save/support_status/inbox_ai_assign/inbox_ai_unassign`؛ و `inbox_thread` مقادیر `ai_ready/ai_mode/ai_assigned` را برمی‌گرداند.
- `inbox.js.php` — دکمه‌ی «✦ پیش‌نویس AI» + دکمه‌ی ارجاع/بازگشت + نشان «🤖 با AI».
- اصل قفل‌شده رعایت شده: **AI پیشنهاد می‌دهد، دکمه‌ی نهایی ارسال دست انسان است**؛ تنها استثنای خودکار = آفلاین/ارجاع دستی.

**آنچه در این دور اضافه شد (خواسته‌ی کارفرما: «همه‌ی منابع دانش»):** تا اینجا `Ai::contextFor()` فقط **نخ گفتگو** را به مدل می‌داد. حالا یک لایه‌ی دانش چندمنبعی افزوده شد که در `Ai::systemWithContext()` متمرکز است و هم به `draftFor` و هم به `autoReply` وصل شده:

1. **مشخصات مشتری** (`Ai::customerFacts`): نام، وضعیت (عضو/ناشناس)، شماره، گروه‌ها، برچسب‌ها، یادداشت داخلی.
2. **پاسخ فرم‌های همان مشتری** (از `eb_form_responses`) — دانشِ شخصیِ آنچه خودِ مشتری گفته.
3. **پایگاه دانش هوش مصنوعی** (ماژول تازه‌ی `modules/AiKb.php` + جدول `eb_ai_knowledge`): مدخل‌های عنوان/محتوا/کلیدواژه که ادمین می‌نویسد. بازیابی **بدون کتابخانه** با امتیاز هم‌پوشانی کلیدواژه بین «آخرین پیام مشتری» و هر مدخل (`Ai::lastInboundText` پرس‌وجو را می‌دهد). مدخل‌های **سنجاق‌شده (pinned)** همیشه گنجانده می‌شوند (مثل سیاست‌های کلی)، بقیه فقط با تطابق. سقف: ۳ مدخل، هرکدام تا ۱۲۰۰ کاراکتر (کنترل هزینه‌ی توکن).

### جزئیات پایگاه دانش (`AiKb`)
- `search($query,$limit)`: pinnedها (تا نصف سقف) + بهترین تطابق‌ها؛ pinned و scored مجزا (بدون تکرار). `tokenize` با `/[^\p{L}\p{N}]+/u` و حذف stopword فارسی/انگلیسی؛ عنوان/کلیدواژه وزن ۳، متن وزن ۱.
- `contextBlock($query)`: بلوک متنی آماده برای تزریق به system prompt (خالی اگر چیزی مرتبط نبود).
- `importText()`: یک فایل متنی (txt/md/csv) را با خط خالی به بلوک‌ها می‌برد؛ هر بلوک ≥۲۰ کاراکتر یک مدخل (سقف ۲۰۰). **PDF/Word پشتیبانی نمی‌شود** (بدون کتابخانه؛ اول به متن تبدیل شود).
- گاردِ `hasTable()` تا قبل از db_upgrade کرش نکند. همه‌ی زیرکوئری‌های `customerFacts` هم try/catch جدا دارند.

### API (فقط admin)
`aikb_list` (items + ready) · `aikb_save` (title/content/keywords/pinned/is_active) · `aikb_delete` · `aikb_import` (آپلود فایل متنی، `$_FILES['file']`).

### UI (`views/pages/ai.php` + `ai.js.php`)
کارت تازه‌ی «پایگاه دانش هوش مصنوعی» زیر ساعت کاری: فرم افزودن/ویرایش (عنوان، کلیدواژه، محتوا، سنجاق، فعال)، درون‌ریزی فایل متنی، و فهرست مدخل‌ها با ویرایش/حذف و نشان 📌/غیرفعال. `loadKb()` کنار `loadAi()` اجرا می‌شود.

### دیتابیس (نیازمند db_upgrade)
جدول تازه‌ی `eb_ai_knowledge` (در CREATE هردوی install و `Schema::ensure`). ستون `eb_persons.ai_handoff_at` (مربوط به هسته‌ی مورد ۱۲) هم در همین migration هست.

### اتصال‌ها
`modules/AiKb.php` در `index.php` و `webhook/receive.php` **قبل از** `Ai.php` require شد.

### اعتبارسنجی
- **بازبینی مستقل با subagent** روی هر ۶ فایل تغییریافته: توازن براکت/پرانتز، صحت heredoc (تنها یک `SQL;`، سبک flexible-heredoc سالم)، ترتیب catch، قرارداد API↔JS (همه‌ی idهای DOM و onclickها و نام فیلدها منطبق)، و صحت `self::MAX_HITS` به‌عنوان مقدار پیش‌فرض پارامتر و `break 2` داخل try. نتیجه: **پاک، بدون باگ**.
- **محدودیت (مثل بخش‌های ۴۱/۴۲):** محیط لینوکس این نشست بالا نیامد (junction روی مسیر Claude-3p)، پس `php -l`/`node --check` اجرا نشد. **قبل از اتکا: db_upgrade را بزن، یک کلید واقعیِ سرویس بگذار، چند مدخل دانش اضافه کن، و در یک گفتگو دکمه‌ی «پیش‌نویس AI» را امتحان کن** تا تزریق دانش و اتصال زنده تأیید شود.

### باقی‌ماندهٔ منطقی (اختیاری، دسته‌های بعد)
- درون‌ریزی PDF/Word (نیازمند استخراج متن؛ فعلاً فقط متن ساده).
- بازیابی معنایی واقعی (embedding/RAG) به‌جای هم‌پوشانی کلیدواژه — اگر سرویس embedding در دسترس بود.
