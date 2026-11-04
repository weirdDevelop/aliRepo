# Module Inventory

> Generated from the files themselves: path, line count, and the first line of each file's own docblock (original Persian kept verbatim).
> Classification follows master prompt §2.2. Because nothing could be executed against a live DB/provider in this session, **no module is classified "Existing and verified"**; the strongest status available is *Existing, unverified* (code present, lints clean).

## A. Capability classification (summary)

| Capability (master prompt) | Status | Evidence |
|---|---|---|
| Organisation / departments / teams | Incomplete | `eb_teams`, `eb_roles` + `modules/OrgRoles.php` (tree of roles with page grants); CRM `staff.unit` (free text) + `staff.supervisor_id`; `eb_mkt_roles` tree for marketing. Three unrelated hierarchies, no division/department entity, no effective dating. |
| Task / project management | Incomplete + Duplicated | CRM `tasks`/`projects` (`crm/models/Task.php`, `Project.php`): status enum, priority, assignee **free text**, subtasks/comments stored as JSON, no history, no dependencies, no workflow. Separately: marketing content kanban `eb_mkt_content` (+steps/crew) in `modules/Marketing.php`. |
| Workflow / approval engine | Missing (generic) | Only conversation `Flows` (`eb_flows`, `eb_flow_runs`) for chat automation; no approval entity. |
| CRM customer master | Duplicated | `eb_persons`+`eb_identities` (Elanban) vs `customers`, `leads`, `host_leads`, `hosts` (CRM), joined by `eb_crm_links`/phone. |
| Lead lifecycle / pipeline | Existing, unverified (Jakojast-specific) | `leads` (stage free text in Persian), `lead_status_history`, `crm/models/Pipeline.php`, `HostLead.php`. |
| Identity resolution & merge | Existing, unverified; no unmerge, no review queue | `Persons::merge`, `ingestIdentity`; auto-merge on phone. |
| Iranian phone normalisation | Defective (inconsistent) | 3 implementations disagree — see `evidence/phone-normalizer-comparison.md`. |
| Unified inbox / assignment / SLA | Existing, unverified | `Desk.php`, `Inbox.php`, `ChatAssign.php`, `eb_assign_log`, `eb_conv_state`, `Csat.php`, `Canned.php`. SLA timers listed as front-end only in `ROADMAP.md` §5. |
| Segmentation | Incomplete | Groups/tags (`eb_groups`, `eb_tags`), `Sender::resolveAudience`; no nested AND/OR/NOT builder. |
| Scoring | Incomplete | `customers.score` column; no explainable model. |
| Notification / messaging (Elanban) | Existing, unverified | `Sender`, `Queue`, `SendLog`, `Templates`, `Campaigns`, `Scheduler`, `Personalize`, `SmsDlr`, `Notifier` (in-app), `Notices` (website pop-ups). |
| Automation / rules | Duplicated | `AutoReply`, `BotMenu`, `Flows`, `Followups`, `IgCommentAutomation`, `Campaigns` — six rule engines, plus YarAPI server-side automations. |
| Instagram | Existing, unverified (4 providers) | see `integration-inventory.md`. |
| Carousel / showcase | Existing, unverified | `modules/Carousel.php`, `views/carousel-editor.php` (messaging showcase cards); feed publishing single-photo only (YarAPI limitation). |
| Audit | Incomplete | `eb_audit` (user admin actions, impersonation), `lead_status_history`, `eb_assign_log`; no generic audit. |
| Multi-tenancy / entitlements / white-label | Missing (white-label partial) | No tenant column anywhere; `Brand.php` (brand name/colours), `License.php`. |
| Analytics | Existing, unverified | `Analytics*.php` (website analytics, licensed), `Stats.php`, `crm/models/Report.php`, `SearchConsole.php`. |
| Knowledge base / AI | Existing, unverified | `AiKb`, `AgentKb`, `SalesAgent`, `Ai`. |
| Automated tests | Missing | none in repo. |

## B. File-level inventory

| File | Lines | Own description |
|---|---:|---|
| `core/Auth.php` | 276 | احراز هویت ساده مبتنی بر session با سه نقش: admin (مدیر)، supervisor (سرپرست)، operator (پشتیبان). |
| `core/Autoload.php` | 16 | بارگذاری خودکار ماژول‌ها فقط وقتی لازم می‌شوند، تا هر درخواست ده‌ها فایل |
| `core/Cache.php` | 78 | کش ساده‌ی فایلی (بدون نیاز به APCu/Redis) برای نتایج کوتاه‌عمر: |
| `core/Csrf.php` | 53 | محافظت CSRF برای فرم‌ها و درخواست‌های POST. |
| `core/Database.php` | 128 | اتصال PDO و کمک‌متدهای کوئری امن (prepared statements). |
| `core/Schema.php` | 1187 | تعریف کامل جداول و مهاجرت ستون‌ها در یک جا. |
| `core/SchemaV4.php` | 237 | core/SchemaV4.php |
| `core/Tz.php` | 70 | هماهنگی ساعت کاربر با ساعت دیتابیس. |
| `core/Url.php` | 111 | آدرس پایه‌ی واقعی برنامه. |
| `core/Viewer.php` | 153 | core/Viewer.php — نقش «نمایشی» (فقط مشاهده) |
| `channels/BaleBotDriver.php` | 21 | کانال ربات بله — فقط API_BASE را تعیین می‌کند. |
| `channels/BaleSafirDriver.php` | 212 | کانال صفیر بله — ارسال پیام انبوه با شماره تماس. |
| `channels/BaseBotDriver.php` | 271 | پایه‌ی مشترک برای کانال‌هایی که معماری ربات تلگرام را کپی کرده‌اند: |
| `channels/ChannelInterface.php` | 42 | قرارداد مشترک همه‌ی کانال‌ها. هر درایور این را پیاده می‌کند. |
| `channels/ChannelManager.php` | 253 | ثبت و انتخاب درایورها. توکن هر کانال از eb_settings خوانده می‌شود. |
| `channels/EmailDriver.php` | 122 | کانال ایمیل از طریق SMTP (بدون کتابخانه، با سوکت خام). |
| `channels/InstagramApichiDriver.php` | 495 | کانال دایرکت اینستاگرام از طریق API خودمان «یار API» (apichi) به‌جای اتصال مستقیم. |
| `channels/InstagramDirectsazDriver.php` | 380 | کانال دایرکت اینستاگرام از طریق واسط «دایرکت‌ساز» (developers.directsaz.com). |
| `channels/InstagramDriver.php` | 248 | کانال دایرکت اینستاگرام — با «Instagram API with Instagram Login» (بدون نیاز |
| `channels/InstagramRoboclickCommentAdapter.php` | 59 | modules/IgCommentAutomation.php و modules/IgPhoneGate.php برای دایرکت‌ساز نوشته |
| `channels/InstagramRoboclickDriver.php` | 417 | کانال دایرکت اینستاگرام از طریق اتصال مستقیم (روش قدیمی؛ آدرس در InstagramRoboclick::baseUrl()). |
| `channels/RoubikaDriver.php` | 234 | کانال ربات روبیکا — از خانواده‌ی تلگرام است ولی چند تفاوت ساختاری دارد: |
| `channels/SmsDriver.php` | 97 | کانال پیامک. خودش یک provider (شرکت پیامک) انتخاب‌شده را به کار می‌گیرد. |
| `channels/TelegramDriver.php` | 30 | کانال ربات تلگرام — دوقلوی بله. فقط API_BASE فرق می‌کند. |
| `channels/VoiceDriver.php` | 200 | تلفن گویا (تماس صوتی) — ملی‌پیامک، از طریق SOAP. |
| `channels/WebchatDriver.php` | 104 | کانال «گفتگوی آنلاین سایت» (مورد ۱۳ دور بازخورد). |
| `modules/AgentCallbacks.php` | 73 | modules/AgentCallbacks.php — درخواست تماس (۱.۰.۹) |
| `modules/AgentDemand.php` | 278 | modules/AgentDemand.php — تحلیل تقاضا برای بازاریابی (۱.۰.۱۳) |
| `modules/AgentGuard.php` | 146 | modules/AgentGuard.php — محافظ دستیار در برابر مهندسی پرامپت و بیرون کشیدن داده |
| `modules/AgentKb.php` | 287 | modules/AgentKb.php — پایگاه دانش دستیار فروش (RAG) |
| `modules/AgentLifecycle.php` | 445 | modules/AgentLifecycle.php — پیگیری فروش و نظرسنجی بعد از رزرو (با cron) |
| `modules/AgentProfiles.php` | 285 | modules/AgentProfiles.php — پروفایل برند دستیار فروش |
| `modules/AgentReport.php` | 118 | گزارش راندمان اپراتورها در یک بازه‌ی زمانی. |
| `modules/Ai.php` | 1001 | لایه‌ی هوش مصنوعی — کلاینت چندپروایدریِ سازگار با OpenAI. |
| `modules/AiKb.php` | 244 | پایگاه دانشِ هوش مصنوعی — منبعی که پاسخ‌های دستیار از آن تغذیه می‌شود. |
| `modules/Analytics.php` | 94 | هسته‌ی صفحه‌ی «آنالیتیکس» — گزارش‌هایی که از داده‌ی موجود (eb_messages, |
| `modules/AnalyticsIngest.php` | 196 | پردازش رویدادهای خام رسیده از eb-pulse.js (فراخوانی از eb-pulse.php). |
| `modules/AnalyticsLicense.php` | 93 | قفل فعال‌سازی ماژول آنالیتیکس — «هر مشتری نخواست، بهش نمی‌دیم». |
| `modules/AnalyticsQuery.php` | 261 | گزارش آنالیتیکس با فیلتر: بازه‌ی دلخواه (از/تا)، مقایسه با دوره‌ی قبل، و فیلتر دستگاه، مرورگر، |
| `modules/AnalyticsReports.php` | 382 | کوئری‌های سمت خواندن برای صفحه‌ی ادمین آنالیتیکس — نمای کلی، لحظه‌ای، |
| `modules/AnalyticsRollup.php` | 240 | کارهای سنگین آنالیتیکس — همه در cron، هرگز در مسیر درخواستِ بازدیدکننده: |
| `modules/AnalyticsSettings.php` | 136 | تنظیمات ماژول آنالیتیکس در eb_settings با پیشوند `analytics.` — دقیقاً |
| `modules/ApiConnectors.php` | 700 | modules/ApiConnectors.php — اتصال وب‌سرویس‌های بیرونی به دستیار فروش |
| `modules/ApiLog.php` | 100 | لاگ دقیق تماس با APIهای خارجی (صفیر، پیامک، تماس گویا، ایمیل، روبیکا، ...). |
| `modules/AutoReply.php` | 296 | موتور پاسخ خودکار چندکاناله. روی پیام دریافتی از هر کانالی کار می‌کند. |
| `modules/BotMenu.php` | 302 | بات‌ساز: درخت منوی چندمرحله‌ای با دکمه. |
| `modules/BotReply.php` | 155 | ارسال پاسخِ خودکارِ بات + **ثبت آن در تاریخچه‌ی گفتگو**. |
| `modules/Brand.php` | 123 | تنظیمات برند (سفیدسازی نام تجاری): نام، لوگو، رنگ اصلی. |
| `modules/Campaigns.php` | 225 | کمپین چندمرحله‌ای با پیگیری خودکار. |
| `modules/Canned.php` | 104 | پیام‌های آماده‌ی تیم پشتیبانی (مورد ۶-۱) — متن‌های پرتکرار (آدرس دفتر، شماره |
| `modules/Carousel.php` | 219 | modules/Carousel.php  (۲.۱.۰) |
| `modules/ChatAssign.php` | 347 | تخصیص گفتگو به پشتیبان (دور ۳ صندوق ورودی — موارد ۲، ۲-۱، ۲-۲). |
| `modules/ChatPlus.php` | 1348 | modules/ChatPlus.php — نسخه‌ی ۱.۰.۱۴ جاکجاست: چت و پشتیبانی |
| `modules/ContentMetrics.php` | 218 | گزارش عملکرد محتوای تقویم: بازدید، لایک، کامنت، اشتراک، ذخیره، کلیک، سرنخ و فروش. |
| `modules/ConvIndex.php` | 257 | فهرست خلاصه‌ی گفتگوها (یک ردیف برای هر مشتری) تا صندوق ورودی و شمارنده‌ها |
| `modules/CrmBridge.php` | 599 | modules/CrmBridge.php — سیستم مدیریت یکپارچه‌ی جاکجاست (۲.۰.۰) |
| `modules/Csat.php` | 145 | نظرسنجی رضایت مشتری بعد از پایان گفتگو. |
| `modules/Desk.php` | 710 | لایه‌ی میز پشتیبانی روی صندوق ورودی موجود: |
| `modules/Flows.php` | 907 | سناریوساز: موتور اجرای گراف گره‌ها (شبیه n8n) برای همه‌ی کانال‌ها و گفتگوی سایت. |
| `modules/Followups.php` | 124 | پیام پیگیری خودکار: «اگر X ساعت از آخرین پیام مشتری گذشت و شرط‌ها برقرار بود، این پیام را بفرست». |
| `modules/Forms.php` | 193 | فرم/نظرسنجی چندمرحله‌ای با حافظه‌ی وضعیت گفتگو. |
| `modules/GeoIp.php` | 352 | خواننده‌ی خالصِ PHP فایل دیتابیس MaxMind (.mmdb) — بدون composer/vendor، |
| `modules/GeoIpCountry.php` | 94 | جست‌وجوی کشور از روی IP — بدون نیاز به حساب MaxMind یا دانلود دستی. |
| `modules/Groups.php` | 94 | مدیریت گروه‌ها و تگ‌ها روی لایه‌ی شخص (نه کانال). |
| `modules/IgCommentAutomation.php` | 647 | اتوماسیون «کامنت کن X تا برات بفرستم» با گزینه‌ی فالو اجباری — سبک ManyChat/دایرکتم. |
| `modules/IgPhoneGate.php` | 191 | گرفتن اجباری شماره تلفن قبل از هر مکالمه‌ی دیگر در دایرکت اینستاگرام — دقیقاً همان |
| `modules/IgPosts.php` | 221 | صف انتشار پست و استوری اینستاگرام. |
| `modules/IgSales.php` | 112 | نسخه‌ی اول «مدیریت فروش» (مورد ۳ نقشه‌راه): کاتالوگ ساده + ثبت سفارش برای پیگیری |
| `modules/IgWebhookPipeline.php` | 308 | پایپ‌لاین مشترک رویدادهای اینستاگرامِ چندپیجی — مستقل از اینکه رویداد از کجا آمده: |
| `modules/Inbox.php` | 459 | صندوق ورودی: پیام‌های دریافتی از مشتریان (direction='in')، گروه‌بندی‌شده بر اساس شخص. |
| `modules/InstagramApichi.php` | 491 | اتصال اعلان‌بان به پیج‌های اینستاگرام از طریق API خودمان «یار API» (به‌جای اتصال مستقیم سرویس‌دهنده). |
| `modules/InstagramAuth.php` | 244 | اتصال حساب اینستاگرام با «Instagram API with Instagram Login» — همان روشی |
| `modules/InstagramDirectsaz.php` | 121 | تنظیمات و کمک‌متدهای اتصال اینستاگرام از طریق «دایرکت‌ساز» (developers.directsaz.com) |
| `modules/InstagramRoboclick.php` | 306 | مدیریت چند پیج اینستاگرام (جدول eb_ig_accounts) — هم پیج‌های اتصال مستقیم سرویس‌دهنده |
| `modules/JakojastLookup.php` | 94 | کلاینت طرف اعلان‌بان برای پرسیدن وضعیت سرنخ/مشتری/تماس از جاکجاست |
| `modules/License.php` | 338 | لایسنس اعلان‌بان از طریق OAuth راست‌چین (rtl-theme.com). |
| `modules/Links.php` | 491 | لینک رهگیری: ساخت لینک کوتاه، ثبت کلیک، آمار. |
| `modules/Marketing.php` | 947 | ماژول مارکتینگ — لایه‌ی اول: اهداف، نقش‌ها (چارت سازمانی)، اعضای تیم. |
| `modules/NetProxy.php` | 396 | پروکسی ضدفیلترینگ برای سرویس‌های خارجی (تلگرام، هوش مصنوعی، درخواست‌های HTTP سناریوها). |
| `modules/Notices.php` | 277 | ساخت انواع اعلان روی سایت (مورد ۷): پاپ‌آپ، نوار بالا/پایین، اسلاید-این. |
| `modules/Notifier.php` | 96 | فید اعلان داخل پنل (مورد ۲ و ۵) — پیام تازه‌ی مشتری، و تکمیل فرم/استعلام روی سایت. |
| `modules/OrgRoles.php` | 178 | modules/OrgRoles.php — ۲.۰.۹ — چارت سازمانی و نقش‌های قابل تعریف |
| `modules/Personalize.php` | 163 | شخصی‌سازی متن پیام بر اساس «شخص» گیرنده (per-person). |
| `modules/Persons.php` | 774 | بانک مشتری + منطق هویت طلایی و تجمیع خودکار (merge). |
| `modules/PropertyLookup.php` | 129 | لوکاپ خودکار اقامتگاه — قسمتی از سناریوی فلوچارت میهمان/میزبان که قبلاً به‌عنوان |
| `modules/Queue.php` | 365 | صف پایدار ارسال انبوه. هر گیرنده یک ردیف در eb_queue دارد، پس اگر پردازش وسط کار |
| `modules/SalesAgent.php` | 1814 | modules/SalesAgent.php — دستیار هوش مصنوعی پاسخ‌گویی و فروش |
| `modules/Scheduler.php` | 209 | زمان‌بندی ارسال. پیام‌های سررسیده توسط cron.php پردازش می‌شوند. |
| `modules/SearchConsole.php` | 770 | اتصال به Google Search Console و ابزارهای سئو: |
| `modules/SendLog.php` | 117 | لاگ‌گیری ارسال در سه لایه: |
| `modules/Sender.php` | 447 | ارسال تکی و انبوه، چندکاناله، روی لایه‌ی «شخص». |
| `modules/SiteCtx.php` | 309 | modules/SiteCtx.php  (۲.۱.۱) — مدیریت چندسایته |
| `modules/SiteWidgets.php` | 314 | پیکربندی ابزارهای روی سایت مشتری: «دکمه‌های شناور تماس» و طراحی کامل «گفتگوی آنلاین». |
| `modules/SmsDlr.php` | 72 | گزارش تحویل پیامک (DLR): ثبت شناسه‌ی پیام موقع ارسال، |
| `modules/SmsInbound.php` | 59 | دریافت پاسخ‌های پیامک (فقط خط اختصاصی) با روش pull و ثبت در سیستم. |
| `modules/Stats.php` | 58 | آمار دقیق برای مارکتینگ. |
| `modules/Support.php` | 215 | وضعیت تیم پشتیبانی: آنلاین/آفلاین، ساعت کاری، و ارجاع گفتگو به هوش مصنوعی. |
| `modules/TeamChat.php` | 119 | چت داخلی بین اعضای تیم (مورد ۶-۲) — کاملاً جدا از مکالمه‌ی مشتری‌ها. |
| `modules/Templates.php` | 119 | الگوهای پیام قابل استفاده مجدد. مستقل از کانال — یک الگو روی هر کانالی |
| `modules/Webchat.php` | 765 | منطق «گفتگوی آنلاین سایت» (لایوچت) — مورد ۱۳ دور بازخورد. |
| `api/agent.php` | 377 | api/agent.php — اکشن‌های دستیار فروش و اتصال‌های API (پیشوندهای agent_ و connector_) |
| `api/chatplus.php` | 82 | api/chatplus.php — ۱.۰.۱۴: صفحه‌ی «چت و پشتیبانی» (پیشوند cp_) |
| `api/crm.php` | 124 | api/crm.php — ۲.۰.۰: سیستم مدیریت یکپارچه (پیشوند crm_) |
| `api/desk.php` | 318 | api/desk.php — اکشن‌های پنل جدید (میز پشتیبانی، ترجیحات، تیم‌ها). |
| `api/export.php` | 171 | خروجی/پشتیبان‌گیری داده به‌صورت CSV. از index.php با ?export=NAME صدا زده می‌شود. |
| `api/handler.php` | 2926 | هندلر مرکزی API. از index.php با ?api=NAME صدا زده می‌شود. |
| `api/internal_lookup.php` | 128 | نقطه‌ی ورود اختصاصی برای سیستم‌های داخلی شرکت (فعلاً فقط جاکجاست) که |
| `api/v4.php` | 96 | api/v4.php |
| `api/v42_mkt.php` | 32 | api/v42_mkt.php — آمار عملکرد محتوای تقویم (پیشوند mktm_) |
| `api/v4_flows.php` | 55 | api/v4_flows.php — سناریوساز (flow_). از api/v4.php require می‌شود. |
| `api/v4_gsc.php` | 77 | api/v4_gsc.php — سرچ کنسول و ابزارهای سئو (gsc_). از api/v4.php require می‌شود. |
| `api/v4_widgets.php` | 66 | api/v4_widgets.php — دکمه‌های شناور (fab_) و طراحی گفتگوی آنلاین (wcx_) |
| `webhook/_ig_bootstrap.php` | 33 | بارگذاری مشترک وابستگی‌های پایپ‌لاین اینستاگرام چندپیجی (webhook/roboclick.php، webhook/apichi.php |
| `webhook/apichi.php` | 88 | دریافت رویدادهای اینستاگرام از یار API — یک آدرس برای همه‌ی پیج‌ها. |
| `webhook/connector.php` | 85 | webhook/connector.php?c=<شناسه‌ی اتصال> |
| `webhook/directsaz.php` | 266 | نقطه‌ی دریافت مستقل رویدادهای اینستاگرام از طریق دایرکت‌ساز. |
| `webhook/directsaz2.php` | 251 | webhook/directsaz2.php |
| `webhook/receive.php` | 309 | نقطه‌ی دریافت یکپارچه‌ی رویداد همه‌ی کانال‌ها. |
| `webhook/roboclick.php` | 75 | نقطه‌ی دریافت رویدادهای اینستاگرام از سرویس‌دهنده — چندپیجی، با پایپ‌لاین کامل. |
| `crm/models/Call.php` | 1879 | جاکجاست - مدل تماس‌ها |
| `crm/models/Chat.php` | 188 | جاکجاست - مدل چت |
| `crm/models/Customer.php` | 505 | جاکجاست - مدل مشتری |
| `crm/models/ElanbanApiLog.php` | 111 | جاکجاست - لاگ تعاملات با API اعلان‌بان |
| `crm/models/Evaluation.php` | 194 | جاکجاست - مدل ارزیابی عملکرد پرسنل |
| `crm/models/Host.php` | 365 | جاکجاست - مدل میزبان |
| `crm/models/HostLead.php` | 481 | جاکجاست - مدل سرنخ میزبان |
| `crm/models/HostProperty.php` | 230 | اقامتگاه‌های متعلق به یک میزبان (CRUD + عکس) |
| `crm/models/Lead.php` | 845 | جاکجاست - مدل سرنخ میهمان (Guest Leads) |
| `crm/models/Leave.php` | 278 | جاکجاست - مدل مرخصی |
| `crm/models/Payroll.php` | 335 | جاکجاست - مدل حقوق و دستمزد |
| `crm/models/Pipeline.php` | 583 | جاکجاست - پیگیری مرحله‌ای شماره‌ها (Pipeline) |
| `crm/models/Project.php` | 133 | جاکجاست - مدل پروژه |
| `crm/models/Reference.php` | 146 | جاکجاست - مدیریت مراجع سرنخ‌ها (منبع ورودی مشتری: سایت، اینستاگرام، معرف، ...) |
| `crm/models/Report.php` | 614 | جاکجاست - مدل گزارش‌ها و داشبورد |
| `crm/models/SiteInquiry.php` | 181 | استعلام‌های سایت جاکجاست → قابل پیگیری در CRM / تبدیل به سرنخ |
| `crm/models/SiteSale.php` | 144 | لیست فروش / رزروهای سایت در CRM |
| `crm/models/Staff.php` | 528 | جاکجاست - مدل پرسنل |
| `crm/models/StaffReport.php` | 203 | گزارش‌های فردی پرسنل / اپراتور |
| `crm/models/Task.php` | 301 | جاکجاست - مدل تسک |
| `crm/models/TelefonchyApiLog.php` | 192 | جاکجاست - لاگ حرفه‌ای تعاملات با API تلفنچی |
| `crm/models/TelefonchyEvent.php` | 78 | جاکجاست - مشاهده‌ی خام رویدادهای webhook تلفنچی |
| `crm/models/Ticket.php` | 269 | جاکجاست - مدل تیکت |
| `crm/models/User.php` | 332 | جاکجاست - مدل کاربران سیستم (حساب‌های ورود) |
| `crm/api_integration/CallSyncManager.php` | 1319 | جاکجاست - مدیر همگام‌سازی تماس‌ها |
| `crm/api_integration/ElanbanAPI.php` | 189 | جاکجاست - کلاینت API اعلان‌بان |
| `crm/api_integration/SiteSyncManager.php` | 350 | همگام‌سازی داده از دیتابیس سایت جاکجاست به CRM |
| `crm/api_integration/SmsService.php` | 296 | سرویس ارسال پیامک — کاوه‌نگار / ملی‌پیامک / وب‌سرویس ساده |
| `crm/api_integration/TelefonchyAPI.php` | 647 | جاکجاست - کلاینت API تلفنچی |
| `crm/api_integration/TranscriptionService.php` | 399 | جاکجاست - سرویس تبدیل صدای مکالمات به متن |
| `crm/core/Auth.php` | 659 | جاکجاست - کلاس احراز هویت |
| `crm/core/Database.php` | 301 | جاکجاست - کلاس دیتابیس (PDO Singleton) |
| `crm/core/Helpers.php` | 423 | جاکجاست - توابع کمکی |
| `crm/core/Jalali.php` | 242 | جاکجاست - کلاس تبدیل تاریخ جلالی (شمسی) |
| `crm/core/Logger.php` | 174 | جاکجاست - کلاس لاگ‌گیری |
| `crm/core/Response.php` | 219 | جاکجاست - کلاس پاسخ‌دهی استاندارد |
