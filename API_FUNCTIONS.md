# فهرست کامل API و توابع اعلان‌بان

> هدف: جلوگیری از دوباره‌کاری. هر چیزی که اینجاست **قبلاً ساخته و تست شده** — دوباره نساز مگر باگ داشته باشد.

---

## ۹۵ API در `api/handler.php` (همه با `out()` پاسخ JSON می‌دهند)

> نقاط ورود مستقل (بیرون از handler): `index.php` (روتر `?page=`/`?api=`) · `webhook/receive.php` (وب‌هوک کانال‌ها؛ `webchat` عمداً رد می‌شود) · `cron.php` · `r.php` (ریدایرکت لینک) · **`chat.php`** (ویجت سایت، بدون لاگین/CSRF) · **`widget.js`** (اسکریپت مهمانِ سایت مشتری)

### راه‌اندازی و کانال
`channels_status` · `channel_save_token` · `channel_set_webhook` · `db_upgrade` (Schema::ensure) · `setup_status` (چک‌لیست onboarding)

### مشتری
`persons_list` (فیلترهای پیشرفته‌ی قابل‌ترکیب: q/status/group_id/tag_id/**channels[]**/has_phone/responded/min_resp_rate/delivery/active_days/inactive_days/date_field+date_from+date_to/sort + محدودیت گروه اپراتور؛ خروجی شامل sent_count/recv_count/resp_rate) · `person_create` (افزودن دستی: channels[]={channel,value}+full_name+phone+notes؛ شناسه‌ی کانال‌محور + هویت طلایی/merge) · `person_profile` (+ هویت‌های غنی + form_responses/link_clicks/campaigns + can_delete) · `person_update` · `person_delete` (canManageCustomers + محدودیت گروه) · `person_group_remove` · `person_tag_remove` · `import_customers` (CSV/xlsx با نام+گروه+برچسب)

### صندوق ورودی (نسخه ۱-الف)
`inbox_conversations` (+ فیلتر blocked) · `inbox_unread_count` · `inbox_thread` (+ blocked + media) · `inbox_reply` (+ media_path عکس) · `inbox_block` · `inbox_unblock` · `person_refresh` (به‌روزرسانی هویت‌ها از API پلتفرم)

### برند و ظاهر (مرحله ۱)
`settings_get` (+brand) · `brand_save` (name/tagline/accent با اعتبارسنجی hex) · `brand_logo_upload` (لوگو، svg مجاز، مسیر نسبی)

### گروه و برچسب
`groups_list` · `group_create` · `group_delete` · `group_add_persons` · `tags_list` · `tag_create` · `tag_delete` · `tag_persons`

### الگو
`templates_list` · `template_save` · `template_delete`

### ارسال
`send_audience_count` · `send_bulk` (همگام، داخلی) · `send_prepare` → `send_chunk` (هر ۲۰) → `send_finish` (تکه‌ای با نوار پیشرفت) · `send_detail` · `sends_list` (فیلتر: q/kind/channel/result(success|partial|failed)/date_from/date_to + خلاصه‌ی count/sent/failed/total + dlr summary)

### ارسال با شماره
`phone_send` (صفیر/پیامک، حالت‌ها) · `phones_from_file` · `phones_from_group`

### تنظیمات کانال‌های شماره‌ای/ایمیل
`safir_get` · `safir_save` · `safir_send` · `sms_get` · `sms_save` · `email_get` · `email_save` · `email_test`

### تلفن گویا
`voice_available` · `voice_call` (SendSpeechText انبوه) · `voice_upload_file` (WAV→base64) · `voice_bulk_file` (SendBulkVoiceSMS)

### پاسخ خودکار
`autoreply_list` · `autoreply_save` · `autoreply_delete`

### بات‌ساز و تنظیمات ربات
`botnodes_list` · `botnode_save` · `botnode_delete` · `botsettings_get` · `botsettings_save` (welcome — توجه: با بات‌ساز ادغام شده)

### فرم/نظرسنجی
`forms_list` · `form_save` · `form_delete` · `form_responses`

### کمپین
`campaigns_list` · `campaign_get` · `campaign_create` · `campaign_start` · `campaign_delete`

### زمان‌بندی
`scheduled_list` · `scheduled_create` (send_type: chat/phone/voice/email) · `scheduled_cancel` · `scheduled_delete`

### لینک رهگیری
`links_list` · `link_create` · `link_update` · `link_detail` · `link_delete`

### آمار و فعالیت
`stats_overview` · `recent_activity`

### گفتگوی آنلاین سایت (webchat)
`webchat_settings` (تنظیمات + آمار + کد نصب + رنگ مؤثر) · `webchat_save` · `webchat_sessions` (فیلتر q/days/only_identified)
هر سه `adminOnly()`. نقطه‌ی عمومی ویجت **API پنل نیست**: `chat.php?wc=init|send|poll|ping` (بدون لاگین، بدون CSRF، با توکن نشست).

### اپراتور و پروفایل
`operators_list` · `operator_create` · `operator_update` · `operator_delete` (perms: pages/groups/can_delete_customers) · `my_profile` · `my_password_change` · `my_username_change`

### تنظیمات عمومی و لاگ
`settings_get` · `settings_save` · `cron_key_generate` · `apilog_list` · `apilog_clear` · `upload_image` (فیلد `image`، نه `file`!)

### توابع کمکی در handler
`out()` (پاسخ JSON و exit) · `adminOnly()` · `parseXlsxNumbers()` (فقط شماره، برای ارسال) · `parseXlsxRows()` (سطری با نام، برای ورود مشتری — sharedStrings→آرایه، سلول t="s"=اندیس)

---

## توابع کلیدی هر کلاس

### core/
- **Database:** init, pdo, run, one, all, lastId
- **Auth:** start, attempt (session_regenerate), logout, check, id, role, isAdmin, requireLogin, requireRole, **permissions, canAccess(page), allowedGroups(): ?array, canManageCustomers, hasGroupRestriction**. صفحات همیشه‌مجاز: dashboard/logout/profile. فقط‌admin: channels/settings/botsettings/apilog.
- **Csrf:** token, field, verify, requireValid (از POST/header/JSON body)
- **Schema:** ensure (کل schema + migration)، addColumn (اجرای چندباره امن)

### channels/
- **ChannelInterface:** name, capabilities, send, handleInbound, setWebhook
- **BaseBotDriver** (بله/تلگرام پایه): __construct, buildUrl, capabilities, call, send, handleInbound (می‌خواند callback_query → type='callback'), setWebhook. **دکمه: callback→callback_data تبدیل می‌شود.**
- **BaleBotDriver / TelegramDriver:** فقط name + capabilities (بقیه از Base)
- **RoubikaDriver:** name, capabilities, buildUrl, send, toRubikaKeypad, handleInbound, fetchChatInfo, uploadFile, setWebhook (با s، دکمه Simple، عکس ۳مرحله‌ای)
- **BaleSafirDriver:** __construct, name, capabilities, call, uploadFile, send (ساختار: message_data.message.{text, file_id?, reply_markup?}), handleInbound, setWebhook. **با phone نه chat_id، api-access-key + bot_id.**
- **SmsDriver:** __construct, availableProviders, name, capabilities, send, **getProvider**, fetchInbound, handleInbound, setWebhook
- **sms/ providers:** SmsProviderInterface (sendSms, fetchInbound, parseWebhook, deliveryStatus). Kavenegar/Smsir/Melipayamak (ملی‌پیامک: msg_id برای DLR، GetDeliveries2)
- **EmailDriver:** __construct, name, capabilities, send, handleInbound, setWebhook, smtpSend (fsockopen، TLS/SSL، AUTH LOGIN)
- **VoiceDriver** (SOAP): __construct (apiKey=user:pass), name, capabilities, client (SoapClient), callSpeech (SendSpeechText), uploadVoiceFile (UploadVoiceFile), sendBulkVoiceFile (SendBulkVoiceSMS), status, interpret (recId>10رقم=موفق)
- **WebchatDriver** (گفتگوی آنلاین سایت): name, capabilities(text/photo/inbound), send (هیچ HTTP بیرونی نمی‌زند — فقط وجود نشست را در eb_webchat_sessions چک می‌کند)، handleInbound (payload ویجت → قالب مشترک)، setWebhook (بی‌اثر: ویجت خودش poll می‌کند). **channel_uid = توکن ۳۲ هگزِ نشست در localStorage مرورگر.**
- **ChannelManager:** available (+webchat)، setting, saveToken, isConfigured (webchat: کلید `webchat.enabled`)، saveSms, saveEmail, getVoice, voiceAvailable, saveSafir, get (webchat بدون توکن؛ اگر خاموش باشد null)، activeDrivers

### modules/
- **Persons:** ingestIdentity (هوشمند)، **createManual** (افزودن دستی کانال‌محور + هویت طلایی؛ خروجی person_id/created/merged)، **normalizeChannelValue** (chat_id/شماره/ایمیل بسته به کانال)، attachPhone, **merge** (جمع‌کننده)، pickRicher, mergeNotes, **normalizePhone**, profile، messages, update, **refreshFromNetwork** (getChat هر کانال رباتی)
- **Templates:** all, find, save, delete, toMessage, **normalizeButtons** (می‌شناسد: url, callback؛ نه callback_data)
- **Sender:** **sendToPerson** (یک target per شخص!)، sendBulk, **resolveAudience** (group_id/tag_id/status/channel/inactive_days/has_responded + محدودیت گروه اپراتور)، sendByPhone (safir_then_sms/safir/sms/both)، logPhoneMsg
- **AutoReply:** all, save, delete, matches (exact/contains/starts/regex), handle, handleContact (تریگر رویدادی on_contact = اشتراک شماره؛ از receive.php پس از Forms/BotMenu صدا زده می‌شود)
- **Groups:** allGroups, createGroup, deleteGroup, addPersons, removePerson, allTags, ensureTag, deleteTag, tagPersons, untagPerson
- **BotMenu:** all, find, findByKey, root, save, delete, **handle** (nav:KEY، دکمه goto/url/contact)
- **Forms:** all, find, save, delete, responses, activeSession, **handle** (حالت‌دار)، recordAnswer
- **Campaigns:** all, find, create, delete, stepMessage, start, runStep, filterNonResponders, processDue, stats
- **Scheduler:** all, create (آرایه‌ای، send_type)، cancel, delete, **processDue** (chat/phone/voice/email)، collectPhones
- **Links:** all(فیلتر), categories, find, findByCode, create, update, delete, recordClick(code, personId, meta[]), detail, normalizeTarget, kindOf, telDisplay, isAllowedTarget, clientIp, parseUa, toAsciiDigits, uniqueCode
- **Brand:** all (کش+پیش‌فرض)، get، save (کلیدهای مجاز)، accentCss (تولید ink/soft + تزریق روشن/تیره، fallback رنگ نامعتبر)
- **Queue:** enqueue (ردیف per گیرنده)، process (idempotent، cron+هل مرورگر)، cancel، jobs، summary، job
- **Inbox:** conversations (گروه بر اساس شخص، GROUP_CONCAT/SUBSTRING_INDEX برای آخرین پیام)، unreadCount، thread، markRead (UPDATE-JOIN)، lastInboundChannel، **block/unblock/isBlocked**، hasMedia/hasBlock
- **SendLog:** start, target, finish, all, **filtered** (kind/channel/result/date/q برای تاریخچه‌ی فیلترشده)، detail, cleanup (۶ ماه)
- **SmsDlr:** track, poll, summary
- **SmsInbound:** pull
- **ApiLog:** enabled, record (ماسک token/pass)، all, clear, cleanup (۱۴ روز)، mask, stringify
- **Stats:** overview
- **Webchat:** settings/save/isEnabled/publicSettings/startMode/accent (رنگ از برند ارث می‌برد)، isOnline (از Support::isOnline)، originAllowed، validToken/startSession/session/touch، clientIp/parseAgent، ipRateExceeded (سقف ۶۰ پیام per IP در ساعت)، **ingest** (ثبت + هویت طلایی + زنجیره‌ی خودکار)، runAutomation (فرم→منو→پاسخ خودکار→AI)، **poll**، takeEvents/pushEvent (رویداد کنترلی، جدا از پیام تا دوباره دیده نشود)، history، sessions، stats، embedCode
- **Webchat:** settings, save, isEnabled, publicSettings (چیزی حساس ندارد)، startMode (open/form/require_phone)، accent (خالی = ارث از Brand)، isOnline (از Support::isOnline)، originAllowed (host-محور)، validToken, startSession, session, touch, clientIp, parseAgent, ipRateExceeded (۶۰ پیام در ساعت هر IP)، **ingest** (ثبت + هویت طلایی + زنجیره‌ی خودکار)، runAutomation (فرم→منو→پاسخ خودکار→AI)، **poll** (فقط eb_messages direction=out)، takeEvents/pushEvent (رویداد کنترلی در eb_webchat_outbox)، history, sessions, stats, embedCode

---

## دیتابیس: ۲۸ جدول (پیشوند eb_)
eb_users(+permissions_json), eb_settings, eb_persons, eb_identities, eb_messages(+error,+send_id,+read_at), eb_sends, eb_send_targets, eb_groups, eb_person_groups, eb_tags, eb_person_tags, eb_templates, eb_campaigns, eb_campaign_steps, eb_campaign_runs, eb_auto_replies, eb_scheduled(+send_type,+phones_text,+phone_mode), eb_bot_settings, eb_bot_nodes, eb_forms(+finish_image), eb_form_sessions, eb_form_responses, eb_links, eb_link_clicks, eb_sms_dlr, eb_api_log, **eb_webchat_sessions** (token یکتا، person_id/identity_id، ip، page_url، device/browser/os، identified، msg_count، last_seen), **eb_webchat_outbox** (فقط رویداد کنترلی، نه پیام)

## صفحات UI (۲۵ صفحه در views/pages/)
dashboard, inbox, persons, send, safir, templates, autoreply, botbuilder, botsettings(ادغام‌شده), forms, groups, campaigns, scheduled, links, sendlog, apilog, stats, channels, settings, profile, queue, support, marketing, ai, **webchat** (گفتگوی آنلاین سایت — admin-only)

## فایل‌های ریشه‌ی مربوط به چت سایت
- `chat.php` — نقطه‌ی عمومی ویجت (init/send/poll/ping). CORS بر اساس دامنه‌های مجاز؛ اگر کانال خاموش باشد به هیچ درخواستی جواب نمی‌دهد.
- `widget.js` — ویجت تک‌فایل بدون وابستگی، با Shadow DOM (استایل سایت مشتری اثر نمی‌گذارد). آدرس سرور را از `src` خودش درمی‌آورد؛ توکن نشست در localStorage.
- **وب‌هوک ندارد:** `webhook/receive.php` عمداً `?channel=webchat` را رد می‌کند تا نتوان با یک POST هویت جعلی ساخت.

## باگ‌های تاریخی رفع‌شده (دوباره نساز)
CSRF با JSON body · روبیکا updateBotEndpoints/Simple · merge پاک‌کردن اطلاعات · ملی‌پیامک دو فیلد · persons_list گروه/تگ · «پاسخ نامعتبر سرور» (SendLog روی جدول ناموجود → try/catch + db_upgrade) · آکولاد SmsDriver::getProvider · regex سلول xlsx · **باگ آمار: sendToPerson per-channel target → حالا یک target per شخص** · صفیر photo/caption → message.{text,file_id} · بات‌ساز callback_data vs callback · upload_image فیلد file→image در forms/scheduled · **نرخ پاسخ persons_list: تقسیم integer (SUM(in)/SUM(out)) صفر می‌داد → اصلاح با *100.0**

## توابع تاریخ (layout.php)
g2j/j2g (دوطرفه، تست‌شده)، toFa (نمایش شمسی)، enNum/faNum، دیت‌پیکر شمسی سبک (data-jdate + getJDate/setJDate).

## خروجی (api/export.php)
?export=customers (CSV مشتریان با فیلتر) · ?export=messages&person_id=N (گفتگوی مشتری). BOM برای اکسل، GET، محدودیت گروه.

---

## فرانت دمو (`elanban-console.html`) — جدا از backend بالا

> ⚠️ همه‌ی APIها و توابع بالا مربوط به **اسکریپت PHP زنده** هستند. بخش زیر یک **پیش‌نمایش فرانت تک‌فایل** است که هنوز backend ندارد؛ هیچ‌کدام از موارد زیر API سمت سرور نیستند و فقط state موقت در مرورگرند. هنگام پیاده‌سازی واقعی، برای هرکدام باید جدول + هندلر `case` در `api/handler.php` ساخته شود.

### کنسول فرانت
- فایل: `elanban-console.html` (تک‌فایل، تم برندمحور `setBrand(hex)`، روشن/تیره، RTL، ۳۵ صفحه، نمودار SVG دستی).
- router هش‌محور (`route()`), `openDrawer/closeDrawer`, `toast()`, `buildNav()`.
- نمودارها: `spark, bars, donut, areaChart, areaChart2, gauge, rankBars, heatmap, postHeatmap`.

### ماژول مارکتینگ (فرانت، «به‌زودی») — skill: `marketing-team`
- state موقت: `GOALS, MKT_CAL(داده‌ی واقعی اکسل), TASKS, TEAM, ROLES, MKT_CHANNELS, CHANNEL_FIELDS, POST_HEATMAP`.
- توابع ایجاد/ویرایش (همه فقط در نشست): `addGoal, addContent(فرم هر-کاناله), renderChannelFields, addTask, moveTask, addMember, addRole/saveRole/deleteRole(چارت درختی قابل‌ویرایش با re-parent), roleTreeNode`.
- صفحه‌ها: `mkt-goals, mkt-calendar(+dayModal), mkt-tasks, mkt-team, mkt-roles, mkt-reports`.

### ماژول پشتیبانی/نیم‌چت (فرانت، «به‌زودی») — skill: `support-team`
- state موقت: `AGENTS, CONV_STATE, PRIOS, SUP_TAGS, MACROS, SROLES, SUP_PERMS, SHIFTS, POST_HEATMAP`.
- توابع (همه در نشست): `assignConv, autoAssign(دستی/چرخشی/کم‌بارترین/تیمی), toAgent/toAI(انتقال دوطرفه AI↔انسان), setPrio, addTag/rmTag, applyMacro, resolveConv(+CSAT), addNote, setAgentStatus, cycleShift, escalate(SLA), addSupRole/saveSupRole/delSupRole(چارت قابل‌ویرایش), slaInfo(تایمر زنده)`.
- صفحه‌ها: `sup-inbox, sup-team, sup-shifts, sup-sla, sup-roles, sup-reports`.

### پایگاه دانش (فرانت کارا) 
- state: `KB_CATS, KB_ARTICLES`. توابع: `kbSave/kbDelete/kbView/kbAddCat/kbSetCat/kbSearchSet`. CRUD کامل، جستجو، فیلتر، وضعیت منتشر/پیش‌نویس. منبع دانش AI پاسخ‌گو.

### چت داخلی تیم (فرانت کارا)
- state: `TC_CHANNELS, TC_MSGS`. توابع: `tcSend, tcSelect, tcAddCh`. کانال + پیام داخلی، جدا از مکالمه‌ی مشتری.

### وضعیت «به‌زودی»
- گروه‌های مارکتینگ/پشتیبانی/هوش مصنوعی در `NAV` با `soon:true` علامت دارند؛ `route()` یک `soon-ribbon` بالای آن صفحه‌ها تزریق می‌کند و `buildNav` برچسب «به‌زودی» می‌گذارد.

### skillهای دانش (برای پیاده‌سازی واقعی بعدی)
- `marketing-team.skill` — مدل عملیاتی کامل تیم مارکتینگ (اهداف، کانال‌ها، نقش‌ها، گردش‌کار، گزارش).
- `support-team.skill` — مدل عملیاتی کامل تیم پشتیبانی/نیم‌چت (صندوق/مسیریابی، انتقال AI، شیفت، SLA، نقش، گزارش).

### جداول backend که هنگام واقعی‌سازی لازم می‌شوند (هنوز ساخته نشده)
`eb_mkt_goals, eb_mkt_content, eb_mkt_tasks, eb_mkt_members, eb_mkt_roles` · `eb_sup_assignments, eb_sup_notes, eb_sup_shifts, eb_sup_sla, eb_sup_roles, eb_sup_macros` · `eb_kb_cats, eb_kb_articles` · `eb_teamchat_channels, eb_teamchat_messages` · `eb_brand_color`(یا داخل eb_settings).
