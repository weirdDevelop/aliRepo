<?php
/**
 * api/handler.php
 * هندلر مرکزی API. از index.php با ?api=NAME صدا زده می‌شود.
 * خروجی همه JSON است. ورودی JSON یا FormData.
 * CSRF قبلاً در index.php بررسی شده.
 */

// ورودی JSON یا POST معمولی
$in = $_POST;
$rawJson = file_get_contents('php://input');
if ($rawJson && empty($in)) {
    $decoded = json_decode($rawJson, true);
    if (is_array($decoded)) $in = $decoded;
}

$api = $_GET['api'];

// ۲.۱.۱: سایتِ انتخاب‌شده در بالای پنل (هدر X-EB-Site) — تنظیمات چت/دکمه‌ها/اعلان/آنالیتیکس برای همان سایت خوانده و ذخیره می‌شود
SiteCtx::fromAdmin();

// ---- مورد ۸: آزادکردن قفل فایل session (ریشه‌ی کندی و تایم‌اوت پنل) ----
// PHP هندلر پیش‌فرض session یک قفل انحصاری روی فایل session می‌گیرد و تا پایان
// درخواست نگه می‌دارد. صفحه‌ی «ارسال» یک حلقه‌ی `while(true)` دارد که پشت‌سرهم
// `queue_push` می‌زند و هر کدام تا ۴۰ ارسال طول می‌کشد. تا وقتی آن حلقه می‌چرخد،
// **هر درخواست دیگری از همان کاربر** (باز کردن هر صفحه‌ی پنل) پشت همان قفل صف
// می‌کشد — یعنی از دید کاربر «سایت بالا نمی‌آید».
//
// همه‌ی APIها فقط از session می‌خوانند (نقش، مجوزها، شناسه). `$_SESSION` بعد از
// close هم برای **خواندن** در دسترس است، پس Auth بدون تغییر کار می‌کند. فقط
// اکشن‌هایی که واقعاً در session می‌نویسند از این معاف‌اند.
$sessionWriters = ['my_username_change', 'instagram_oauth_url', 'desk_user_profile', 'desk_impersonate', 'desk_impersonate_stop'];
if (!in_array($api, $sessionWriters, true) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function out($data): void {
    if (class_exists('Viewer')) $data = Viewer::mask($data);   // حساب نمایشی: پوشاندن کلیدها و اطلاعات تماس
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    echo $json !== false ? $json : '{"ok":false,"error":"خطای ساخت پاسخ JSON"}';
    exit;
}
// هر خطای پیش‌بینی‌نشده به‌جای پاسخ خالی (= «پاسخ نامعتبر سرور») به JSON خوانا تبدیل می‌شود
// 2.2.0: جزئیات خطا فقط برای مدیر واقعی و در لاگ؛ بقیه پیام عمومی + شناسه‌ی درخواست می‌بینند
// (قبلاً متن exception — شامل SQL، مسیر فایل و داده — برای هر کاربری برمی‌گشت).
set_exception_handler(function (Throwable $e) {
    error_log('[elanban api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $rid = class_exists('Log') ? Log::requestId() : '';
    if (class_exists('Log')) Log::error('rpc.unhandled', ['api' => (string)($_GET['api'] ?? ''), 'exception' => get_class($e), 'error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); if ($rid) header('X-Request-Id: ' . $rid); }
    $msg = (class_exists('Auth') && Auth::isRealAdmin()) ? 'خطای داخلی: ' . $e->getMessage() : 'خطای داخلی؛ شناسه‌ی درخواست را برای پشتیبانی بفرستید: ' . $rid;
    echo json_encode(['ok' => false, 'error' => $msg, 'code' => 'internal_error', 'request_id' => $rid], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    $msg = $err['message'];
    $rid = class_exists('Log') ? Log::requestId() : '';
    if (class_exists('Log')) Log::error('rpc.fatal', ['api' => (string)($_GET['api'] ?? ''), 'error' => mb_substr($msg, 0, 500), 'at' => basename($err['file']) . ':' . $err['line']]);
    if (stripos($msg, 'Maximum execution time') !== false) $public = 'زمان اجرا تمام شد (سرور یار API دیر جواب داد)';
    elseif (class_exists('Auth') && Auth::isRealAdmin()) $public = 'خطای سرور: ' . mb_substr($msg, 0, 300) . ' (' . basename($err['file']) . ':' . $err['line'] . ')';
    else $public = 'خطای سرور؛ شناسه‌ی درخواست را برای پشتیبانی بفرستید: ' . $rid;
    echo json_encode(['ok' => false, 'error' => $public, 'code' => 'internal_error', 'request_id' => $rid], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});

// نکته: `app_base_url()` به `core/Url.php` منتقل شد تا index/cron/webhook هم از همان
// منطق استفاده کنند. قبلاً فقط اینجا بود و همین باعث می‌شد لینک کوتاهِ داخل پیامِ ارسالی
// با لینکی که پنل نشان می‌دهد یکی نباشد.
function adminOnly(): void { if (!Auth::isAdmin()) out(['ok'=>false,'error'=>'دسترسی فقط برای مدیر']); }
/** بررسی دسترسی اپراتور به یک مشتری بر اساس محدودیت گروه؛ اگر مجاز نباشد، خطا و خروج. */
function inbox_assert_access(int $personId): void {
    // پشتیبان عادی (اگر مدیر اجازه نداده باشد) به چتی که بر عهده‌ی همکار دیگری است دسترسی ندارد
    if (class_exists('Desk') && !Desk::canSee($personId)) out(['ok'=>false,'error'=>'این گفتگو بر عهده‌ی همکار دیگری است و فقط مسئولش، سرپرست و مدیر آن را می‌بینند']);
    $allowed = Auth::allowedGroups();
    if ($allowed === null) return; // بدون محدودیت (admin یا اپراتور بدون محدودیت گروه)
    $ok = $allowed ? Database::one(
        "SELECT 1 FROM eb_person_groups WHERE person_id=? AND group_id IN (".implode(',',array_fill(0,count($allowed),'?')).")",
        array_merge([$personId], $allowed)
    ) : null;
    if (!$ok) out(['ok'=>false,'error'=>'این گفتگو در دسترس شما نیست']);
}

/**
 * هویت‌های یک مشتری برای صندوق ورودی/پروفایل، با پیج اینستاگرام و آمار هر هویت (دور ۳ — مورد ۶).
 * $rich: + بیو، نام کامل، تعداد پیام ورودی/خروجی، اولین/آخرین پیام، لینک پروفایل در همان شبکه.
 */
function inbox_identities(int $pid, bool $rich = false): array {
    $ig = Inbox::hasIgAccounts();
    $rows = Database::all(
        "SELECT i.id, i.channel, i.channel_uid, i.username, i.first_name, i.first_seen, i.last_seen" . ($rich ? ", i.raw_json" : "") .
        ($ig ? ", i.ig_account_id, a.label AS ig_account_label, a.color AS ig_account_color, a.username AS ig_page_username" : ", NULL AS ig_account_id, NULL AS ig_account_label, NULL AS ig_account_color, NULL AS ig_page_username") . "
         FROM eb_identities i " . ($ig ? "LEFT JOIN eb_ig_accounts a ON a.id = i.ig_account_id" : "") . "
         WHERE i.person_id = ? ORDER BY i.last_seen DESC", [$pid]);
    if (!$rows) return [];
    $stats = [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $del = Inbox::hasDeleted() ? "AND deleted_at IS NULL" : "";
    foreach (Database::all("SELECT identity_id, SUM(direction='in') rin, SUM(direction='out') rout, MIN(created_at) first_at, MAX(created_at) last_at FROM eb_messages WHERE identity_id IN ($ph) $del GROUP BY identity_id", $ids) as $st) $stats[(int)$st['identity_id']] = $st;
    foreach ($rows as &$r) {
        $st = $stats[(int)$r['id']] ?? [];
        $r['msg_in'] = (int)($st['rin'] ?? 0); $r['msg_out'] = (int)($st['rout'] ?? 0);
        $r['first_msg_at'] = $st['first_at'] ?? null; $r['last_msg_at'] = $st['last_at'] ?? null;
        $u = trim((string)($r['username'] ?? ''), '@ ');
        $r['profile_url'] = $u === '' ? null : ([
            'instagram' => 'https://instagram.com/' . rawurlencode($u),
            'telegram' => 'https://t.me/' . rawurlencode($u),
            'bale' => 'https://ble.ir/' . rawurlencode($u),
        ][$r['channel']] ?? null);
        if ($rich) {
            $raw = json_decode($r['raw_json'] ?? '{}', true) ?: [];
            $c = $raw['contact'] ?? [];
            $r['full_name'] = $c['name'] ?? trim(($r['first_name'] ?? '') . ' ' . ($raw['last_name'] ?? ''));
            $r['bio'] = $raw['bio'] ?? ($c['biography'] ?? ($raw['description'] ?? null));
            $r['followers'] = $c['follower_count'] ?? ($c['followers_count'] ?? null);
            $r['is_follower'] = $c['is_user_follow_business'] ?? ($c['is_follower'] ?? null);
            $r['avatar'] = $c['profile_pic'] ?? ($c['profile_picture_url'] ?? null);
            unset($r['raw_json']);
        }
    }
    unset($r);
    return $rows;
}

/**
 * استخراج رشته‌های شبیه شماره از یک فایل xlsx بدون کتابخانه.
 * xlsx یک zip است: sharedStrings.xml متن‌ها، و sheetN.xml مقادیر.
 * خروجی: آرایه‌ی رشته‌ها، یا null اگر باز نشد.
 */
/**
 * خواندن سطرهای xlsx به‌صورت آرایه‌ای از ستون‌ها (برای ورود مشتری با نام).
 * هر سطر: [مقدار ستون A, مقدار ستون B, ...]. خروجی null اگر باز نشد.
 */
function parseXlsxRows(string $path): ?array
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    // ۱) sharedStrings را به آرایه‌ی اندیس‌دار بخوان
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        // هر <si> یک رشته است؛ ممکن است چند <t> داشته باشد (rich text)
        if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sim)) {
            foreach ($sim[1] as $si) {
                $txt = '';
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                    foreach ($tm[1] as $t) $txt .= html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $shared[] = $txt;
            }
        }
    }

    // ۲) اولین شیت را بخوان
    $sheet = false;
    for ($i = 1; $i <= 10; $i++) {
        $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
        if ($sheet !== false) break;
    }
    $zip->close();
    if ($sheet === false) return [];

    $rows = [];
    // هر <row> ... </row>
    if (preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            // هر سلول کامل را جدا بگیر، بعد نوع و مقدارش را استخراج کن
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $attrs = $c[1];
                    $inner = $c[2];
                    $type = '';
                    if (preg_match('/\bt="([^"]*)"/', $attrs, $tt)) $type = $tt[1];

                    $val = '';
                    if ($type === 'inlineStr') {
                        if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $im)) {
                            $val = html_entity_decode($im[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                        $raw = trim($vm[1]);
                        if ($type === 's') {
                            $val = $shared[(int)$raw] ?? '';
                        } else {
                            $val = $raw;
                        }
                    }
                    $cells[] = $val;
                }
            }
            if ($cells) $rows[] = $cells;
        }
    }
    return $rows;
}

function parseXlsxNumbers(string $path): ?array
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;

    $out = [];

    // ۱) رشته‌های مشترک (متن سلول‌ها معمولاً اینجاست)
    $shared = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared !== false) {
        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $shared, $m)) {
            foreach ($m[1] as $val) {
                $out[] = html_entity_decode(strip_tags($val), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    // ۲) مقادیر عددی مستقیم در شیت‌ها (سلول‌هایی که عدد خام‌اند)
    for ($i = 1; $i <= 10; $i++) {
        $sheet = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
        if ($sheet === false) continue;
        // مقادیر <v>...</v> که در سلول‌های غیر-string عدد خام‌اند
        if (preg_match_all('/<v>(.*?)<\/v>/s', $sheet, $m)) {
            foreach ($m[1] as $val) {
                $val = trim($val);
                // فقط چیزهایی که شبیه عدد بلندند (شماره موبایل)، نه اندیس sharedString کوتاه
                if (preg_match('/^\d{6,}$/', $val)) $out[] = $val;
            }
        }
    }

    $zip->close();
    return $out;
}

if (strpos($api, 'desk_') === 0) { require __DIR__ . '/desk.php'; exit; }
// ۲.۰.۰: سیستم مدیریت یکپارچه (CRM)
if (strpos($api, 'crm_') === 0) { require __DIR__ . '/crm.php'; exit; }
// دستیار فروش و اتصال‌های API (نسخه‌ی جاکجاست)
if (preg_match('/^(agent|connector)_/', $api)) { require __DIR__ . '/agent.php'; exit; }
// ماژول‌های تکمیلی (صف انتشار، سناریوساز، دکمه‌ی شناور، ویجت گفتگو، سرچ کنسول، پروکسی)
if (preg_match('/^(igpost|flow|fab|wcx|gsc|v4|mktm)_/', $api)) { require __DIR__ . '/v4.php'; exit; }

switch ($api) {

    // ============ تنظیمات کانال ============
    case 'channels_status':
        $list = [];
        foreach (ChannelManager::available() as $ch) {
            $list[$ch] = ['configured' => ChannelManager::isConfigured($ch)];
        }
        out(['ok'=>true, 'channels'=>$list, 'base_url'=>app_base_url($config)]);

    case 'channel_save_token':
        adminOnly();
        $ch = $in['channel'] ?? '';
        $token = trim($in['token'] ?? '');
        if (!in_array($ch, ChannelManager::available(), true)) out(['ok'=>false,'error'=>'کانال نامعتبر']);
        if ($token === '') out(['ok'=>false,'error'=>'توکن خالی است']);
        ChannelManager::saveToken($ch, $token);
        out(['ok'=>true]);

    case 'channel_set_webhook':
        adminOnly();
        $ch = $in['channel'] ?? '';
        $driver = ChannelManager::get($ch);
        if (!$driver) out(['ok'=>false,'error'=>'ابتدا توکن را ذخیره کنید']);
        // secret وب‌هوک
        $secretRow = Database::one("SELECT v FROM eb_settings WHERE k='webhook.secret'");
        $secret = $secretRow['v'] ?? null;
        if (!$secret) {
            $secret = bin2hex(random_bytes(8));
            Database::run("INSERT INTO eb_settings (k,v) VALUES ('webhook.secret',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$secret]);
        }
        $base = app_base_url($config);
        $url = "{$base}/webhook/receive.php?channel={$ch}&secret={$secret}";
        $res = $driver->setWebhook($url);
        out(['ok'=>true, 'webhook_url'=>$url, 'platform_response'=>$res]);

    // ============ صفیر بله ============
    case 'safir_get':
        $key = Database::one("SELECT v FROM eb_settings WHERE k='channel.bale_safir.access_key'")['v'] ?? '';
        $bid = Database::one("SELECT v FROM eb_settings WHERE k='channel.bale_safir.bot_id'")['v'] ?? '';
        $masked = '';
        if ($key !== '') {
            $masked = strlen($key) > 8 ? substr($key,0,4).str_repeat('•',6).substr($key,-4) : '••••';
        }
        out(['ok'=>true, 'has_key'=>($key!==''), 'access_key_masked'=>$masked, 'bot_id'=>$bid]);

    case 'safir_save':
        adminOnly();
        $key = trim($in['access_key'] ?? '');
        $bid = trim($in['bot_id'] ?? '');
        if ($key === '' || $bid === '') out(['ok'=>false,'error'=>'کلید دسترسی و bot_id لازم است']);
        ChannelManager::saveSafir($key, $bid);
        out(['ok'=>true]);

    case 'safir_send':
        @set_time_limit(0);
        $driver = ChannelManager::get('bale_safir');
        if (!$driver) out(['ok'=>false,'error'=>'صفیر تنظیم نشده است']);

        $text = trim($in['text'] ?? '');
        $buttonsJson = trim($in['buttons_json'] ?? '');
        $phonesRaw = trim($in['phones'] ?? '');
        $hasImage = !empty($in['image_path']) && is_file($in['image_path']);
        if ($text === '' && !$hasImage) out(['ok'=>false,'error'=>'متن یا عکس لازم است']);
        if ($phonesRaw === '') out(['ok'=>false,'error'=>'شماره‌ای وارد نشده']);

        // نرمال‌سازی + حذف تکراری
        $valid = []; $invalid = []; $seen = [];
        foreach (preg_split('/[\r\n,;]+/', $phonesRaw) as $r) {
            $r = trim($r);
            if ($r === '') continue;
            $n = Persons::normalizePhone($r);
            if ($n === null) { $invalid[] = $r; }
            elseif (!isset($seen[$n])) { $seen[$n] = true; $valid[] = $n; }
        }
        if (!$valid) out(['ok'=>false,'error'=>'شماره معتبری یافت نشد','invalid'=>array_slice($invalid,0,20)]);

        $message = ['text' => $text];
        if ($buttonsJson !== '') {
            $dec = json_decode($buttonsJson, true);
            if (is_array($dec)) $message['buttons'] = Templates::normalizeButtons($dec);
        }
        // عکس: مسیر فایل را به درایور می‌دهیم؛ درایور صفیر خودش upload_file می‌کند
        if (!empty($in['image_path']) && is_file($in['image_path'])) {
            $message['photo'] = $in['image_path'];
        }

        // عکس: یک‌بار آپلود کن (نه به‌ازای هر شماره) و file_id را استفاده کن
        if (!empty($in['image_path']) && is_file($in['image_path'])) {
            $fid = $driver->uploadFile($in['image_path']);
            if ($fid) $message['file_id'] = $fid;
        }

        $sent = 0; $failed = 0; $errors = [];
        foreach ($valid as $phone) {
            $res = $driver->send(['phone'=>$phone], $message);
            if (!empty($res['ok'])) {
                $sent++;
                // ثبت در بانک مشتری: این شماره را به‌عنوان هویت صفیر ثبت کن
                Persons::ingestIdentity('bale_safir', ['channel_uid'=>$phone, 'phone'=>$phone]);
            } else {
                $failed++;
                if (count($errors) < 10) $errors[] = ['phone'=>$phone, 'error'=>$res['error'] ?? '?'];
            }
            usleep(50000);
        }
        out(['ok'=>true, 'total'=>count($valid), 'sent'=>$sent, 'failed'=>$failed,
             'invalid_count'=>count($invalid), 'invalid'=>array_slice($invalid,0,20), 'errors'=>$errors]);

    // ============ بانک مشتری ============
    case 'persons_list':
        $q = trim($in['q'] ?? '');
        $status = $in['status'] ?? '';
        $groupId = !empty($in['group_id']) ? (int)$in['group_id'] : 0;
        $tagId = !empty($in['tag_id']) ? (int)$in['tag_id'] : 0;
        // کانال: می‌تواند تکی (رشته) یا چندتایی (آرایه) باشد
        $channels = $in['channels'] ?? ($in['channel'] ?? '');
        if (!is_array($channels)) $channels = ($channels !== '' ? [$channels] : []);
        $channels = array_values(array_filter(array_map('trim', $channels)));
        $inactiveDays = !empty($in['inactive_days']) ? (int)$in['inactive_days'] : 0;
        $activeDays   = !empty($in['active_days']) ? (int)$in['active_days'] : 0;     // پیام در N روز اخیر داشته
        $hasPhone     = $in['has_phone'] ?? '';   // 'yes' | 'no' | ''
        $dateField    = in_array($in['date_field'] ?? '', ['created','last_msg'], true) ? $in['date_field'] : 'created';
        $dateFrom     = trim($in['date_from'] ?? '');  // YYYY-MM-DD (میلادی — تبدیل سمت کلاینت)
        $dateTo       = trim($in['date_to'] ?? '');
        $delivery     = $in['delivery'] ?? '';     // وضعیت آخرین ارسال: 'delivered'|'failed'|'' (از eb_send_targets)
        $responded    = $in['responded'] ?? '';    // 'yes' (حداقل یک پیام دریافتی) | 'no' (هیچ پاسخی) | ''
        $minRespRate  = (isset($in['min_resp_rate']) && $in['min_resp_rate'] !== '') ? (float)$in['min_resp_rate'] : null; // نرخ پاسخ ≥ (٪)
        $sort         = in_array($in['sort'] ?? '', ['recent','created','name','activity'], true) ? $in['sort'] : 'recent';
        $page = max(1, (int)($in['page'] ?? 1));
        $per = 25; $off = ($page-1)*$per;
        $joins = []; $conds = []; $params = [];

        if ($q !== '') { $conds[] = "(p.full_name LIKE ? OR p.phone LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($status === 'member' || $status === 'anonymous') { $conds[] = "p.status = ?"; $params[] = $status; }
        if ($hasPhone === 'yes') $conds[] = "p.phone IS NOT NULL AND p.phone <> ''";
        if ($hasPhone === 'no')  $conds[] = "(p.phone IS NULL OR p.phone = '')";
        // ۲.۰.۱: فیلتر CRM (مشتری، سرنخ، میزبان، تماس‌گرفته، فقط تماس)
        if (!empty($in['crm']) && class_exists('CrmBridge') && CrmBridge::present()) {
            $cw = CrmBridge::personFilterSql((string)$in['crm']);
            if ($cw) $conds[] = $cw;
        }

        // کانال‌ها: شخص باید حداقل در یکی از کانال‌های انتخاب‌شده هویت داشته باشد
        if ($channels) {
            $ph = implode(',', array_fill(0, count($channels), '?'));
            $conds[] = "p.id IN (SELECT person_id FROM eb_identities WHERE channel IN ($ph))";
            foreach ($channels as $c) $params[] = $c;
        }
        if ($groupId) { $joins[] = "INNER JOIN eb_person_groups pg ON pg.person_id=p.id AND pg.group_id=?"; $params[] = $groupId; }
        if ($tagId)   { $joins[] = "INNER JOIN eb_person_tags pt ON pt.person_id=p.id AND pt.tag_id=?"; $params[] = $tagId; }

        // بازه‌ی تاریخ
        if ($dateField === 'created') {
            if ($dateFrom !== '') { $conds[] = "p.created_at >= ?"; $params[] = $dateFrom.' 00:00:00'; }
            if ($dateTo   !== '') { $conds[] = "p.created_at <= ?"; $params[] = $dateTo.' 23:59:59'; }
        } else { // last_msg: بر اساس آخرین پیام (هر جهت)
            if ($dateFrom !== '') {
                $conds[] = "p.id IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.created_at >= ?)";
                $params[] = $dateFrom.' 00:00:00';
            }
            if ($dateTo !== '') {
                $conds[] = "p.id IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.created_at <= ?)";
                $params[] = $dateTo.' 23:59:59';
            }
        }

        // بدون پیام ارسالی در N روز (غیرفعال)
        if ($inactiveDays) {
            $conds[] = "p.id NOT IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.direction='out' AND m.created_at >= DATE_SUB(NOW(), INTERVAL {$inactiveDays} DAY))";
        }
        // فعال: پیام (هر جهت) در N روز اخیر داشته
        if ($activeDays) {
            $conds[] = "p.id IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL {$activeDays} DAY))";
        }

        // پاسخ‌دهی: آیا تا حالا پیام دریافتی (in) داشته؟
        if ($responded === 'yes') {
            $conds[] = "p.id IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.direction='in')";
        } elseif ($responded === 'no') {
            $conds[] = "p.id NOT IN (SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE m.direction='in')";
        }

        // نرخ پاسخ ≥ آستانه (دریافتی / ارسالی، فقط افرادی که ارسالی دارند)
        if ($minRespRate !== null) {
            $conds[] = "p.id IN (
                SELECT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id
                GROUP BY i.person_id
                HAVING SUM(m.direction='out') > 0
                   AND (SUM(m.direction='in') * 100.0 / SUM(m.direction='out')) >= ?
            )";
            $params[] = $minRespRate;
        }

        // وضعیت تحویل آخرین ارسال (از eb_send_targets)
        if ($delivery === 'delivered') {
            $conds[] = "p.id IN (SELECT person_id FROM eb_send_targets WHERE status='sent' AND person_id IS NOT NULL)";
        } elseif ($delivery === 'failed') {
            $conds[] = "p.id IN (SELECT person_id FROM eb_send_targets WHERE status='failed' AND person_id IS NOT NULL)
                        AND p.id NOT IN (SELECT person_id FROM eb_send_targets WHERE status='sent' AND person_id IS NOT NULL)";
        }

        // محدودیت دسترسی اپراتور: فقط مشتریان گروه‌های مجاز
        $allowedGroups = Auth::allowedGroups();
        if ($allowedGroups !== null) {
            if (!$allowedGroups) { $allowedGroups = [-1]; } // هیچ گروهی → هیچ مشتری
            $ph = implode(',', array_fill(0, count($allowedGroups), '?'));
            $conds[] = "p.id IN (SELECT person_id FROM eb_person_groups WHERE group_id IN ($ph))";
            foreach ($allowedGroups as $g) $params[] = $g;
        }
        $joinSql = $joins ? (' ' . implode(' ', $joins)) : '';
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

        // ترتیب
        $orderBy = [
            'recent'   => 'p.updated_at DESC',
            'created'  => 'p.created_at DESC',
            'name'     => 'p.full_name ASC',
            'activity' => '(SELECT MAX(m.created_at) FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id WHERE i.person_id=p.id) DESC',
        ][$sort];

        $total = (int)(Database::one("SELECT COUNT(DISTINCT p.id) c FROM eb_persons p $joinSql $where", $params)['c'] ?? 0);
        // کارایی (دور ۳، مورد ۵): به‌جای ۳ زیرکوئری همبسته + ۳ کوئری جدا برای *هر* ردیف
        // (حدود ۱۵۰ کوئری در هر صفحه)، ۵ کوئری دسته‌ای برای کل صفحه.
        $rows = Database::all("SELECT DISTINCT p.* FROM eb_persons p $joinSql $where ORDER BY $orderBy LIMIT $per OFFSET $off", $params);
        if ($rows) {
            $ids = array_map(fn($r) => (int)$r['id'], $rows);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $cnt = []; $idn = []; $grp = []; $tg = [];
            foreach (Database::all("SELECT i.person_id, SUM(m.direction='out') s, SUM(m.direction='in') r, MAX(m.created_at) l
                                    FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id
                                    WHERE i.person_id IN ($ph) GROUP BY i.person_id", $ids) as $c) $cnt[(int)$c['person_id']] = $c;
            foreach (Database::all("SELECT person_id, channel, channel_uid, username FROM eb_identities WHERE person_id IN ($ph)", $ids) as $c) $idn[(int)$c['person_id']][] = ['channel'=>$c['channel'],'channel_uid'=>$c['channel_uid'],'username'=>$c['username']];
            foreach (Database::all("SELECT pg.person_id, g.id, g.name, g.color FROM eb_groups g INNER JOIN eb_person_groups pg ON pg.group_id=g.id WHERE pg.person_id IN ($ph)", $ids) as $c) $grp[(int)$c['person_id']][] = ['id'=>$c['id'],'name'=>$c['name'],'color'=>$c['color']];
            foreach (Database::all("SELECT pt.person_id, t.id, t.name FROM eb_tags t INNER JOIN eb_person_tags pt ON pt.tag_id=t.id WHERE pt.person_id IN ($ph)", $ids) as $c) $tg[(int)$c['person_id']][] = ['id'=>$c['id'],'name'=>$c['name']];
            $crmB = (class_exists('CrmBridge') && CrmBridge::present()) ? CrmBridge::badges(array_column($rows, 'phone')) : [];
            foreach ($rows as &$r) {
                $id = (int)$r['id'];
                $r['crm'] = $crmB[(string)$r['phone']] ?? null;
                $r['identities'] = $idn[$id] ?? [];
                $r['identity_count'] = count($r['identities']);
                $r['groups'] = $grp[$id] ?? [];
                $r['tags'] = $tg[$id] ?? [];
                $sent = (int)($cnt[$id]['s'] ?? 0); $recv = (int)($cnt[$id]['r'] ?? 0);
                $r['sent_count'] = $sent; $r['recv_count'] = $recv; $r['last_msg_at'] = $cnt[$id]['l'] ?? null;
                $r['resp_rate'] = $sent > 0 ? round($recv / $sent * 100) : null;
            }
            unset($r);
        }
        out(['ok'=>true,'persons'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$per)]);

    case 'person_profile':
        $pid = (int)($in['id'] ?? 0);
        $prof = Persons::profile($pid);
        if (!$prof) out(['ok'=>false,'error'=>'مشتری یافت نشد']);
        $prof['messages'] = Persons::messages($pid, 300);
        // سابقه‌ی رفتاری: پاسخ فرم‌ها، کلیک لینک‌ها، کمپین‌ها
        $prof['form_responses'] = Database::all(
            "SELECT r.id, r.answers_json, r.created_at, f.name AS form_name
             FROM eb_form_responses r LEFT JOIN eb_forms f ON f.id=r.form_id
             WHERE r.person_id = ? ORDER BY r.created_at DESC LIMIT 20",
            [$pid]
        );
        $prof['link_clicks'] = Database::all(
            "SELECT c.clicked_at, l.title, l.target_url
             FROM eb_link_clicks c INNER JOIN eb_links l ON l.id=c.link_id
             WHERE c.person_id = ? ORDER BY c.clicked_at DESC LIMIT 20",
            [$pid]
        );
        $prof['campaigns'] = Database::all(
            "SELECT cr.run_at, cr.responded, c.name AS campaign_name, cs.step_order
             FROM eb_campaign_runs cr
             INNER JOIN eb_campaigns c ON c.id=cr.campaign_id
             LEFT JOIN eb_campaign_steps cs ON cs.id=cr.step_id
             WHERE cr.person_id = ? ORDER BY cr.run_at DESC LIMIT 20",
            [$pid]
        );
        // وضعیت در جاکجاست (سرنخ/مشتری/تماس) - جهت برعکسِ لوکاپ اعلان‌بان از جاکجاست.
        // اگر جاکجاست تنظیم نشده یا در دسترس نباشد، فقط configured=false برمی‌گردد؛
        // نباید کل باز شدن پروفایل را کند یا خراب کند.
        $prof['jakojast'] = JakojastLookup::lookupByPhone($prof['phone'] ?? null);
        $prof['ig_comments'] = Persons::igComments($pid, 50);
        $prof['identities'] = inbox_identities($pid, true);
        $prof['assign'] = ChatAssign::state($pid, (int)Auth::id());
        out(['ok'=>true,'person'=>$prof,'can_delete'=>Auth::canManageCustomers()]);

    case 'person_update':
        // ۱.۰.۱۴: شماره هم از همین‌جا ثبت/ویرایش می‌شود (استاندارد 09… و ادغام تکراری)
        $puId = (int)($in['id'] ?? 0);
        $puMerged = false;
        if ($puId && array_key_exists('phone', $in)) {
            $ph = Persons::setPhone($puId, (string)$in['phone']);
            if (empty($ph['ok'])) out(['ok'=>false,'error'=>$ph['error'] ?? 'شماره ثبت نشد']);
            $puId = (int)$ph['person_id']; $puMerged = !empty($ph['merged']);
            // ادغام شد: نام و یادداشت هر دو پروفایل قبلاً ترکیب شده؛ بازنویسی نشود
            if ($puMerged) out(['ok'=>true,'person_id'=>$puId,'merged'=>true]);
        }
        Persons::update(
            $puId,
            trim($in['full_name'] ?? '') ?: null,
            trim($in['notes'] ?? '') ?: null
        );
        out(['ok'=>true,'person_id'=>$puId,'merged'=>$puMerged]);

    case 'person_create':
        // افزودن دستی مشتری (با هویت کانال‌محور + هویت طلایی)
        $chs = $in['channels'] ?? [];
        if (!is_array($chs)) $chs = [];
        // پاک‌سازی: فقط آیتم‌های دارای channel و value
        $clean = [];
        foreach ($chs as $c) {
            if (!is_array($c)) continue;
            $clean[] = ['channel'=>trim((string)($c['channel'] ?? '')), 'value'=>trim((string)($c['value'] ?? ''))];
        }
        try {
            $res = Persons::createManual(
                trim($in['full_name'] ?? '') ?: null,
                trim($in['phone'] ?? '') ?: null,
                trim($in['notes'] ?? '') ?: null,
                $clean
            );
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false,'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            out(['ok'=>false,'error'=>'خطا در ثبت مشتری: '.$e->getMessage()]);
        }
        // اگر اپراتور محدودیت گروه دارد، مشتری تازه را به یکی از گروه‌های مجازش بیفزای تا برایش دیده شود
        $allowedG = Auth::allowedGroups();
        if ($allowedG !== null && $allowedG && $res['created']) {
            Database::run("INSERT IGNORE INTO eb_person_groups (group_id, person_id) VALUES (?, ?)", [$allowedG[0], $res['person_id']]);
        }
        out(['ok'=>true,'person_id'=>$res['person_id'],'created'=>$res['created'],'merged'=>$res['merged']]);

    // ============ صندوق ورودی ============
    case 'inbox_conversations':
        $res = Inbox::conversations([
            'channel' => $in['channel'] ?? '',
            'unread'  => !empty($in['unread']),
            'blocked' => !empty($in['blocked']),
            'q'       => $in['q'] ?? '',
            'ig_account_id' => (int)($in['ig_account_id'] ?? 0),
            'assigned' => in_array($in['assigned'] ?? '', ['me','none','others','ai'], true) ? $in['assigned'] : '',
        ], max(1, (int)($in['page'] ?? 1)));
        $extraOut = !empty($in['with_counts']) ? ['counts' => Inbox::channelCounts()] : [];
        out(['ok'=>true] + $res + ['unread_total'=>Inbox::unreadCount()] + $extraOut);

    case 'inbox_unread_count':
        out(['ok'=>true,'unread'=>Inbox::unreadCount()]);

    // ============ فید اعلان داخل پنل (مورد ۲ و ۵) ============
    case 'notifications_poll':
        $after = (int)($in['after'] ?? 0);
        // اولین بار (بدون after معتبر) فقط نقطه‌ی شروع را بده، اعلان‌های قدیمی سیل نشوند
        $items = $after > 0 ? Notifier::since($after) : [];
        // پشتیبانی که چت همکاران را نمی‌بیند، اعلان آن چت‌ها را هم نمی‌گیرد
        if ($items && class_exists('Desk') && !Desk::seesOthers()) {
            $pids = array_values(array_unique(array_filter(array_map(fn($n) => (int)($n['person_id'] ?? 0), $items))));
            $other = [];
            if ($pids) foreach (Database::all("SELECT id FROM eb_persons WHERE id IN (" . implode(',', $pids) . ") AND assigned_user_id IS NOT NULL AND assigned_user_id <> ?", [(int)Auth::id()]) as $r) $other[(int)$r['id']] = true;
            $items = array_values(array_filter($items, fn($n) => empty($other[(int)($n['person_id'] ?? 0)])));
        }
        out(['ok'=>true, 'items'=>$items, 'latest_id'=>Notifier::latestId()]);

    case 'inbox_thread':
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        inbox_assert_access($pid); // محدودیت گروه اپراتور
        $person = Database::one("SELECT id, full_name, phone, status FROM eb_persons WHERE id=?", [$pid]);
        if (!$person) out(['ok'=>false,'error'=>'مشتری یافت نشد']);
        $beforeId = (int)($in['before_id'] ?? 0);
        $identF = (int)($in['identity_id'] ?? 0);
        $th = Inbox::thread($pid, (int)($in['limit'] ?? 60), !empty($in['include_deleted']), $beforeId, $identF);
        $marked = $beforeId ? 0 : Inbox::markRead($pid);
        $me = (int)Auth::id();
        if (!$beforeId) ChatAssign::ping($pid, $me);
        // کامنت‌های اینستاگرامِ این مشتری در همان بازه‌ی زمانیِ پیام‌های لودشده (خط زمانی یکپارچه — مورد ۱)
        $activity = [];
        $comments = Persons::igComments($pid, 100);
        if ($comments) {
            $from = (!empty($th['has_more']) && $th['messages']) ? $th['messages'][0]['created_at'] : null;
            $to = $beforeId && $th['messages'] ? end($th['messages'])['created_at'] : null;
            $logged = [];
            foreach ($th['messages'] as $m) if (($m['media_type'] ?? '') === 'ig_comment') $logged[trim(preg_replace('/^💬 کامنت زیر پست: /u', '', (string)$m['body']))] = true;
            foreach ($comments as $c) {
                if ($from && $c['created_at'] < $from) continue;
                if ($to && $c['created_at'] > $to) continue;
                if ($identF) continue; // در نمای فیلترشده‌ی یک هویت، فقط پیام‌های همان هویت
                if (isset($logged[trim((string)$c['body'])])) continue; // قبلاً به‌عنوان پیام در گفتگو ثبت شده
                $activity[] = ['kind'=>'ig_comment'] + $c;
            }
        }
        $resp = ['ok'=>true, 'messages'=>$th['messages'], 'has_more'=>$th['has_more'], 'activity'=>$activity];
        if (!$beforeId) {
            $person['identities'] = inbox_identities($pid);
            $person['blocked'] = Inbox::isBlocked($pid);
            $person['ai_assigned'] = Support::isAiAssigned($pid);
            $last = Inbox::lastInboundIdentity($pid);
            $resp += ['person'=>$person, 'marked_read'=>$marked,
                'reply_channel'=>$last['channel'] ?? null, 'reply_identity_id'=>isset($last['id']) ? (int)$last['id'] : null,
                'assign'=>ChatAssign::state($pid, $me),
                'ai_ready'=>(class_exists('SalesAgent') ? SalesAgent::aiOrAgent() : Ai::isReady()) ? 1 : 0, 'ai_mode'=>Ai::modeFor($pid)];
        }
        out($resp);

    // ============ تخصیص چت به پشتیبان (دور ۳ — موارد ۲، ۲-۱، ۲-۲) ============
    case 'inbox_ping':
        // هر ~۲۰ ثانیه از گفتگوی باز: حضور + وضعیت تازه‌ی تخصیص (اگر همکاری چت را برداشت، فوراً دیده شود)
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false]);
        inbox_assert_access($pid);
        ChatAssign::ping($pid, (int)Auth::id(), !empty($in['typing']));
        $lastId = (int)($in['last_id'] ?? 0);
        $newer = 0;
        if ($lastId) {
            $newer = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages m JOIN eb_identities i ON i.id=m.identity_id WHERE i.person_id=? AND m.id>?", [$pid, $lastId])['c'] ?? 0);
        }
        out(['ok'=>true, 'assign'=>ChatAssign::state($pid, (int)Auth::id()), 'new_messages'=>$newer]);
    case 'inbox_leave':
        ChatAssign::leave((int)($in['person_id'] ?? 0), (int)Auth::id());
        out(['ok'=>true]);
    case 'inbox_claim':
        $pid = (int)($in['person_id'] ?? 0);
        inbox_assert_access($pid);
        $r = ChatAssign::claim($pid, (int)Auth::id(), !empty($in['force']));
        out($r + ['assign'=>ChatAssign::state($pid, (int)Auth::id())]);
    case 'inbox_release':
        $pid = (int)($in['person_id'] ?? 0);
        inbox_assert_access($pid);
        $r = ChatAssign::release($pid, (int)Auth::id());
        out($r + ['assign'=>ChatAssign::state($pid, (int)Auth::id())]);
    case 'inbox_transfer':
        $pid = (int)($in['person_id'] ?? 0);
        inbox_assert_access($pid);
        $to = ($in['to'] ?? '') === 'ai' ? 'ai' : (int)($in['to'] ?? 0);
        $r = ChatAssign::transfer($pid, $to, (int)Auth::id(), trim((string)($in['note'] ?? '')) ?: null);
        out($r + ['assign'=>ChatAssign::state($pid, (int)Auth::id())]);
    case 'inbox_transfer_all':
        $from = (int)($in['from_user_id'] ?? 0) ?: (int)Auth::id();
        $to = ($in['to'] ?? '') === 'ai' ? 'ai' : (int)($in['to'] ?? 0);
        out(ChatAssign::transferAll($from, $to, (int)Auth::id(), trim((string)($in['note'] ?? '')) ?: null));
    case 'inbox_agents':
        out(['ok'=>true, 'agents'=>ChatAssign::agents((int)Auth::id()), 'me'=>(int)Auth::id(),
             'unrestricted'=>ChatAssign::unrestricted(), 'ready'=>ChatAssign::ready(),
             'mine_open'=>ChatAssign::ready() ? (int)(Database::one("SELECT COUNT(*) c FROM eb_persons WHERE assigned_user_id=?", [(int)Auth::id()])['c'] ?? 0) : 0,
             'support'=>class_exists('Support') ? Support::status() : null]);
    case 'inbox_agent_stats':
        out(['ok'=>true, 'days'=>max(1,(int)($in['days'] ?? 1)), 'rows'=>ChatAssign::agentStats((int)($in['days'] ?? 1))]);
    case 'inbox_assign_history':
        $pid = (int)($in['person_id'] ?? 0);
        inbox_assert_access($pid);
        out(['ok'=>true, 'rows'=>ChatAssign::history($pid, 100)]);

    // مورد ۳: حذف پیام (نرم — لاگ در همان ردیف می‌ماند) + بازگرداندن
    case 'message_delete':
        $mid = (int)($in['id'] ?? 0);
        if (!$mid) out(['ok'=>false,'error'=>'شناسه‌ی پیام لازم است']);
        $pid = Inbox::personIdForMessage($mid);
        if (!$pid) out(['ok'=>false,'error'=>'پیام یافت نشد']);
        inbox_assert_access($pid); // فقط کسی که به این گفتگو دسترسی دارد
        if (!Inbox::deleteMessage($mid, (int)Auth::id())) {
            out(['ok'=>false,'error'=>'قابلیت حذف در دسترس نیست — دیتابیس را به‌روزرسانی کنید']);
        }
        out(['ok'=>true]);
    case 'message_edit':
        $mid = (int)($in['id'] ?? 0);
        $pid = $mid ? Inbox::personIdForMessage($mid) : null;
        if (!$pid) out(['ok'=>false,'error'=>'پیام یافت نشد']);
        inbox_assert_access($pid);
        $g = ChatAssign::guardReply($pid, (int)Auth::id());
        if (empty($g['ok'])) out(['ok'=>false,'error'=>$g['error']]);
        out(Inbox::editMessage($mid, (string)($in['text'] ?? ''), (int)Auth::id()));

    case 'message_restore':
        $mid = (int)($in['id'] ?? 0);
        if (!$mid) out(['ok'=>false,'error'=>'شناسه‌ی پیام لازم است']);
        $pid = Inbox::personIdForMessage($mid);
        if (!$pid) out(['ok'=>false,'error'=>'پیام یافت نشد']);
        inbox_assert_access($pid);
        Inbox::restoreMessage($mid);
        out(['ok'=>true]);

    case 'inbox_block':
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        inbox_assert_access($pid); // محدودیت گروه اپراتور
        if (!Inbox::block($pid)) out(['ok'=>false,'error'=>'قابلیت بلاک در دسترس نیست — دیتابیس را به‌روزرسانی کنید']);
        out(['ok'=>true,'blocked'=>true]);

    case 'inbox_unblock':
    case 'inbox_unfilter':
        // ۱.۰.۱۴: رفع فیلتر دستی توسط مدیر یا هر پشتیبانی که به این گفتگو دسترسی دارد:
        // بلاک، توقف دستیار به‌خاطر پیام مشکوک، اخطارها، سقف پیام/جستجو و سقف IP چت سایت
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        inbox_assert_access($pid);
        if (class_exists('ChatPlus')) {
            $r = ChatPlus::unfilter($pid, (int)Auth::id());
            if (empty($r['ok'])) out($r);
            out(['ok'=>true,'blocked'=>false,'done'=>$r['done']]);
        }
        Inbox::unblock($pid);
        out(['ok'=>true,'blocked'=>false]);

    // ۱.۰.۱۴: صفحه‌ی «چت و پشتیبانی» (تماس سریع، تلفن گویا، فیلترها، نام واحدها، شماره‌ها)
    case 'cp_get':
    case 'cp_save':
    case 'cp_filters':
    case 'cp_ivr_test':
    case 'cp_units_sync':
    case 'cp_units_find':
    case 'cp_phones_run':
    case 'cp_unfilter_all':
        require __DIR__ . '/chatplus.php';
        exit;

    case 'inbox_reply':
        $pid = (int)($in['person_id'] ?? 0);
        $text = trim($in['text'] ?? '');
        $mediaRel = trim($in['media_path'] ?? '');   // مسیر نسبی فایلی که قبلاً آپلود شده (upload_image)
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        if ($text === '' && $mediaRel === '') out(['ok'=>false,'error'=>'پیام خالی است']);
        inbox_assert_access($pid);
        if (Inbox::isBlocked($pid)) out(['ok'=>false,'error'=>'این چت بلاک است؛ اول رفع بلاک کنید']);
        $me = (int)Auth::id();
        // چتِ همکار: پشتیبان عادی باید اول «بر عهده بگیرد» (مدیر/سرپرست معاف)
        $g = ChatAssign::guardReply($pid, $me);
        if (empty($g['ok'])) out(['ok'=>false,'error'=>$g['error'],'taken_by'=>$g['taken_by'] ?? null,'assign'=>ChatAssign::state($pid, $me)]);
        // هویت مقصد: انتخاب اپراتور در پروفایل (مورد ۶)، وگرنه همان هویت/پیجی که آخرین پیام از آن آمده
        $identityId = (int)($in['identity_id'] ?? 0);
        if ($identityId && !Database::one("SELECT 1 FROM eb_identities WHERE id=? AND person_id=?", [$identityId, $pid])) $identityId = 0;
        $last = Inbox::lastInboundIdentity($pid);
        if (!$identityId && $last) $identityId = (int)$last['id'];
        $replyCh = $last['channel'] ?? null;
        $priority = [];
        if ($replyCh) $priority[] = $replyCh;
        foreach (['bale','telegram','roubika'] as $c) { if (!in_array($c, $priority, true)) $priority[] = $c; }
        // ساخت پیام: متن و/یا عکس
        $msg = [];
        if ($text !== '') $msg['text'] = $text;
        if ($mediaRel !== '') {
            $absPath = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/' . basename($mediaRel);
            if (is_file($absPath)) {
                $msg['photo'] = $absPath;
                $msg['media_rel'] = $mediaRel;
            }
        }
        $logTitle = $text !== '' ? mb_substr($text, 0, 80) : '🖼 تصویر';
        $sendId = SendLog::start('reply', $logTitle, $text, $priority, 'inbox');
        $r = Sender::sendToPerson($pid, $msg, $priority, $sendId, ['identity_id'=>$identityId, 'sent_by'=>$me]);
        SendLog::finish($sendId, 1, !empty($r['ok']) ? 1 : 0, !empty($r['ok']) ? 0 : 1);
        if (empty($r['ok'])) out(['ok'=>false,'error'=>'ارسال نشد: '.($r['error'] ?? 'نامشخص')]);
        ChatAssign::afterReply($pid, $me);
        ChatAssign::ping($pid, $me, false);
        out(['ok'=>true,'channel'=>$r['channel'],'identity_id'=>$r['identity_id'] ?? null,'assign'=>ChatAssign::state($pid, $me)]);

    case 'person_refresh':
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        inbox_assert_access($pid);
        $res = Persons::refreshFromNetwork($pid);
        out(['ok'=>true,'updated'=>$res['updated'],'errors'=>$res['errors']]);

    case 'inbox_profile':
        // پروفایل کامل برای ستون سوم صندوق ورودی (بدون پریدن به صفحه‌ی مشتری)
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'شناسه‌ی مشتری لازم است']);
        inbox_assert_access($pid);
        $prof = Persons::profile($pid);
        if (!$prof) out(['ok'=>false,'error'=>'مشتری یافت نشد']);
        $prof['blocked'] = Inbox::isBlocked($pid);
        // فرم‌ها/کلیک‌ها/کمپین‌های اخیر (اگر ماژول‌ها موجودند) — زمینه‌ی تجاری
        if (class_exists('Forms') && method_exists('Forms','responsesForPerson')) {
            $prof['form_responses'] = Forms::responsesForPerson($pid, 5);
        }
        $prof['identities'] = inbox_identities($pid, true);
        $prof['ig_comments'] = Persons::igComments($pid, 30);
        $prof['assign_history'] = ChatAssign::history($pid, 10);
        out(['ok'=>true,'profile'=>$prof]);

    // ============ ادغام پروفایل‌ها (دور ۳ — مورد ۴) ============
    case 'persons_merge_ig':
        // ادغام یک‌باره‌ی سوابق: هم‌یوزرنیم‌های اینستاگرام روی پیج‌های مختلف
        if (!Auth::isManager()) out(['ok'=>false,'error'=>'فقط مدیر یا سرپرست']);
        $r = Persons::backfillInstagramMerge();
        Cache::flush('inbox_counts_');
        out(['ok'=>true] + $r);
    case 'person_merge':
        // ادغام دستی دو مخاطب (مثلاً دایرکتی که یوزرنیم نداشت) — شخص دارای شماره نگه داشته می‌شود
        if (!Auth::canManageCustomers() && !Auth::isManager()) out(['ok'=>false,'error'=>'اجازه‌ی ادغام مخاطب ندارید']);
        $a1 = (int)($in['id'] ?? 0); $a2 = (int)($in['other_id'] ?? 0);
        if (!$a1 || !$a2 || $a1 === $a2) out(['ok'=>false,'error'=>'دو مخاطب متفاوت انتخاب کنید']);
        inbox_assert_access($a1); inbox_assert_access($a2);
        $pp = Database::all("SELECT id, phone FROM eb_persons WHERE id IN (?, ?)", [$a1, $a2]);
        if (count($pp) < 2) out(['ok'=>false,'error'=>'مخاطب پیدا نشد']);
        if (!empty($pp[0]['phone']) && !empty($pp[1]['phone']) && $pp[0]['phone'] !== $pp[1]['phone']) out(['ok'=>false,'error'=>'این دو مخاطب شماره‌ی متفاوت دارند؛ ادغام انجام نشد']);
        $keep = Persons::mergeGroup([$a1, $a2], 'manual:' . Auth::id());
        Cache::flush('inbox_counts_');
        out(['ok'=>true, 'person_id'=>$keep]);
    case 'person_search_min':
        // جستجوی سبک برای انتخاب مخاطب دوم در ادغام
        $q = trim((string)($in['q'] ?? ''));
        if (mb_strlen($q) < 2) out(['ok'=>true,'rows'=>[]]);
        $dq = ltrim(preg_replace('/\D+/', '', Persons::asciiDigits($q)), '0');
        $params = ["%$q%", '%' . ltrim($q, '@') . '%'];
        $phoneCond = '';
        if ($dq !== '') { $phoneCond = ' OR p.phone LIKE ?'; $params[] = "%$dq%"; }
        $rows = Database::all(
            "SELECT p.id, p.full_name, p.phone,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(i2.channel, ':', COALESCE(i2.username, '')) SEPARATOR ', ') FROM eb_identities i2 WHERE i2.person_id = p.id) AS idents
             FROM eb_persons p
             WHERE p.full_name LIKE ? OR p.id IN (SELECT person_id FROM eb_identities WHERE username LIKE ?) $phoneCond
             ORDER BY p.id DESC LIMIT 12", $params);
        out(['ok'=>true,'rows'=>$rows]);

    case 'person_group_remove':
        Groups::removePerson((int)($in['group_id'] ?? 0), (int)($in['person_id'] ?? 0));
        out(['ok'=>true]);
    case 'person_tag_remove':
        Groups::untagPerson((int)($in['tag_id'] ?? 0), (int)($in['person_id'] ?? 0));
        out(['ok'=>true]);
    case 'person_delete':
        // حذف مخاطب نیازمند دسترسی است (در سطوح دسترسی کنترل می‌شود)
        if (!Auth::canManageCustomers()) out(['ok'=>false,'error'=>'شما اجازه‌ی حذف مخاطب ندارید']);
        $pid = (int)($in['id'] ?? 0);
        // اعمال محدودیت گروه اپراتور: فقط مشتری‌ای که در دسترسش است
        $allowed = Auth::allowedGroups();
        if ($allowed !== null) {
            $inGroup = $allowed ? Database::one(
                "SELECT 1 FROM eb_person_groups WHERE person_id=? AND group_id IN (".implode(',',array_fill(0,count($allowed),'?')).")",
                array_merge([$pid], $allowed)
            ) : null;
            if (!$inGroup) out(['ok'=>false,'error'=>'این مخاطب در دسترس شما نیست']);
        }
        Database::run("DELETE FROM eb_identities WHERE person_id=?", [$pid]);
        Database::run("DELETE FROM eb_person_groups WHERE person_id=?", [$pid]);
        Database::run("DELETE FROM eb_person_tags WHERE person_id=?", [$pid]);
        Database::run("DELETE FROM eb_persons WHERE id=?", [$pid]);
        out(['ok'=>true]);

    // ============ الگوها ============
    case 'templates_list':
        out(['ok'=>true,'templates'=>Templates::all()]);

    case 'template_save':
        try {
            $id = Templates::save(
                trim($in['name'] ?? ''),
                trim($in['body'] ?? ''),
                trim($in['buttons_json'] ?? '') ?: null,
                (!empty($in['media_path']) && is_file($in['media_path'])) ? $in['media_path'] : null,
                !empty($in['id']) ? (int)$in['id'] : null,
                array_key_exists('cards', $in) ? ($in['cards'] ?: null) : false   // ۲.۱.۰: کروسل (false = دست‌نخورده بماند)
            );
            out(['ok'=>true,'id'=>$id]);
        } catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }

    case 'template_delete':
        Templates::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ پاسخ خودکار ============
    case 'autoreply_list':
        out(['ok'=>true,'rules'=>AutoReply::all(!empty($in['account_id']) ? (int)$in['account_id'] : null)]);

    // ============ تنظیمات جداگانه‌ی هر پیج اینستاگرام ============
    case 'ig_page_list':
        adminOnly();
        $pages = [];
        try { $pages = Database::all("SELECT id, provider, label, username, color, is_active FROM eb_ig_accounts ORDER BY is_active DESC, id"); } catch (Throwable $e) {}
        out(['ok'=>true,'pages'=>$pages]);
    case 'ig_page_settings_get':
        adminOnly();
        $aid = (int)($in['account_id'] ?? 0);
        $acc = Database::one("SELECT id, provider, label, username, color, is_active FROM eb_ig_accounts WHERE id = ?", [$aid]);
        if (!$acc) out(['ok'=>false,'error'=>'پیج پیدا نشد']);
        $globalRules = [];
        try {
            $globalRules = Database::all("SELECT id, trigger_keyword, trigger_type, channel, is_active FROM eb_auto_replies WHERE (channel IS NULL OR channel = 'instagram')"
                . (AutoReply::hasPageColumn() ? " AND ig_account_id IS NULL" : "") . " ORDER BY priority DESC, id DESC");
        } catch (Throwable $e) {}
        $commentRules = 0;
        try { $commentRules = (int)(Database::one("SELECT COUNT(*) c FROM eb_ig_comment_rules WHERE ig_account_id = ?", [$aid])['c'] ?? 0); } catch (Throwable $e) {}
        $aiGlobal = class_exists('Ai') ? Ai::mode() : 'off';
        out(['ok'=>true, 'page'=>$acc, 'settings'=>IgPageSettings::get($aid), 'defaults'=>IgPageSettings::DEFAULTS,
             'rules'=>AutoReply::all($aid), 'global_rules'=>$globalRules, 'comment_rules'=>$commentRules,
             'global'=>['ai_mode'=>$aiGlobal, 'ai_ready'=>class_exists('Ai') && Ai::isReady(), 'phone_gate'=>IgPhoneGate::isEnabled(),
                        'phone_gate_text'=>IgPhoneGate::promptText(), 'phone_gate_thanks'=>IgPhoneGate::thankYouText()]]);
    case 'ig_page_settings_save':
        adminOnly();
        $aid = (int)($in['account_id'] ?? 0);
        if (!$aid || !Database::one("SELECT id FROM eb_ig_accounts WHERE id = ?", [$aid])) out(['ok'=>false,'error'=>'پیج پیدا نشد']);
        try { out(['ok'=>true, 'settings'=>IgPageSettings::save($aid, is_array($in['settings'] ?? null) ? $in['settings'] : [])]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }

    case 'autoreply_save':
        $buttons = null;
        if (!empty($in['buttons_json'])) {
            $dec = json_decode($in['buttons_json'], true);
            if (is_array($dec)) $buttons = $dec;
        }
        $id = AutoReply::save([
            'channel'         => $in['channel'] ?? null,
            'trigger_keyword' => trim($in['trigger_keyword'] ?? ''),
            'trigger_type'    => $in['trigger_type'] ?? 'exact',
            'text'            => trim($in['text'] ?? ''),
            'buttons'         => $buttons,
            'request_contact' => !empty($in['request_contact']),
            'priority'        => (int)($in['priority'] ?? 0),
            'is_active'       => isset($in['is_active']) ? !empty($in['is_active']) : true,
            'ig_account_id'   => !empty($in['ig_account_id']) ? (int)$in['ig_account_id'] : null,
            'carousel'        => $in['cards'] ?? null,
            'site_scope'      => $in['site_scope'] ?? '',
        ], !empty($in['id']) ? (int)$in['id'] : null);
        out(['ok'=>true,'id'=>$id]);

    case 'autoreply_delete':
        AutoReply::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ ارسال ============
    case 'send_audience_count':
        $persons = Sender::resolveAudience([
            'status'   => $in['status'] ?? null,
            'channel'  => $in['channel'] ?? null,
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
        ]);
        out(['ok'=>true,'count'=>count($persons)]);

    case 'send_bulk':
        $tplId = (int)($in['template_id'] ?? 0);
        if ($tplId) {
            $tpl = Templates::find($tplId);
            if (!$tpl) out(['ok'=>false,'error'=>'الگو یافت نشد']);
            $message = Templates::toMessage($tpl);
        } else {
            $message = ['text' => trim($in['text'] ?? '')];
            if (!empty($in['buttons_json'])) {
                $dec = json_decode($in['buttons_json'], true);
                if (is_array($dec)) $message['buttons'] = Templates::normalizeButtons($dec);
            }
        }
        if (!empty($in['image_path']) && is_file($in['image_path'])) {
            $message['photo'] = $in['image_path'];
        }
        if (empty($message['text']) && empty($message['photo'])) {
            out(['ok'=>false,'error'=>'متن یا عکس لازم است']);
        }
        $priority = $in['channel_priority'] ?? ['bale','telegram','roubika'];
        if (is_string($priority)) $priority = explode(',', $priority);
        $persons = Sender::resolveAudience([
            'status'   => $in['status'] ?? null,
            'channel'  => $in['filter_channel'] ?? null,
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
        ]);
        if (!$persons) out(['ok'=>false,'error'=>'هیچ مخاطبی یافت نشد']);
        $result = Sender::sendBulk($persons, $message, $priority);
        out(['ok'=>true] + $result);

    // ---- ارسال تکه‌ای با بازخورد زنده ----
    case 'send_prepare':
    case 'send_enqueue':
        // ارسال انبوه → صف پایدار (رویکرد الف). به‌جای ارسال همگام در مرورگر،
        // کار در صف می‌رود و cron (یا «هل» مرورگر) پردازشش می‌کند.
        $tplId = (int)($in['template_id'] ?? 0);
        if ($tplId) {
            $tpl = Templates::find($tplId);
            if (!$tpl) out(['ok'=>false,'error'=>'الگو یافت نشد']);
            $message = Templates::toMessage($tpl);
        } else {
            $message = ['text' => trim($in['text'] ?? '')];
            if (!empty($in['buttons_json'])) {
                $dec = json_decode($in['buttons_json'], true);
                if (is_array($dec)) $message['buttons'] = Templates::normalizeButtons($dec);
            }
        }
        if (!empty($in['image_path']) && is_file($in['image_path'])) {
            $message['photo'] = $in['image_path'];
        }
        if (empty($message['text']) && empty($message['photo'])) {
            out(['ok'=>false,'error'=>'متن یا عکس لازم است']);
        }
        $priority = $in['channel_priority'] ?? ['bale','telegram','roubika'];
        if (is_string($priority)) $priority = explode(',', $priority);
        $persons = Sender::resolveAudience([
            'status'   => $in['status'] ?? null,
            'channel'  => $in['filter_channel'] ?? null,
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
        ]);
        if (!$persons) out(['ok'=>false,'error'=>'هیچ مخاطبی یافت نشد']);

        $q = Queue::enqueue($persons, $message, $priority, mb_substr($message['text'] ?? 'ارسال انبوه', 0, 80));
        // یک batch اول را همین‌جا هل بده تا اگر cron نبود، ارسال بلافاصله شروع شود
        $first = Queue::process(40);
        out([
            'ok'=>true, 'job_id'=>$q['job_id'], 'send_id'=>$q['send_id'], 'total'=>$q['total'],
            'queued'=>true, 'first_batch'=>$first,
        ]);

    case 'queue_push':
        // «هل» مرورگر: یک batch از صف را پردازش کن (پشتیبان وقتی cron نیست یا برای سرعت بیشتر)
        @set_time_limit(0);
        $res = Queue::process((int)($in['batch'] ?? 50));
        out(['ok'=>true] + $res);

    case 'queue_jobs':
        out(['ok'=>true, 'jobs'=>Queue::jobs((int)($in['limit'] ?? 50)), 'summary'=>Queue::summary()]);

    case 'queue_summary':
        out(['ok'=>true, 'summary'=>Queue::summary()]);

    case 'queue_cancel':
        $jid = (int)($in['job_id'] ?? 0);
        if (!$jid) out(['ok'=>false,'error'=>'شناسه‌ی کار لازم است']);
        $ok = Queue::cancel($jid);
        out(['ok'=>$ok, 'cancelled'=>$ok] + ($ok?[]:['error'=>'این کار قابل لغو نیست']));

    case 'send_chunk':
    case 'send_finish':
        // سازگاری عقب‌رو: مسیر قدیمی تکه‌ای دیگر استفاده نمی‌شود (همه از صف می‌روند).
        out(['ok'=>true, 'deprecated'=>true]);


    // ============ آپلود فایل (عکس) ============
    case 'upload_image':
        if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
            out(['ok'=>false,'error'=>'فقط عکس مجاز است']);
        }
        $dir = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = 'img_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $path = $dir . $fname;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $path)) {
            out(['ok'=>false,'error'=>'ذخیره‌ی فایل ناموفق بود']);
        }
        out(['ok'=>true, 'path'=>$path, 'rel'=>'uploads/'.$fname, 'name'=>$fname]);

    // ============ گروه‌ها ============
    case 'groups_list':
        out(['ok'=>true,'groups'=>Groups::allGroups()]);
    case 'group_create':
        $id = Groups::createGroup(trim($in['name'] ?? ''), $in['color'] ?? '#2563eb', trim($in['description'] ?? '') ?: null);
        out(['ok'=>true,'id'=>$id]);
    case 'group_delete':
        Groups::deleteGroup((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'group_add_persons':
        $ids = is_array($in['person_ids'] ?? null) ? $in['person_ids'] : explode(',', (string)($in['person_ids'] ?? ''));
        Groups::addPersons((int)($in['group_id'] ?? 0), $ids);
        out(['ok'=>true]);

    // ============ تگ‌ها ============
    case 'tags_list':
        out(['ok'=>true,'tags'=>Groups::allTags()]);
    case 'tag_create':
        $id = Groups::ensureTag(trim($in['name'] ?? ''));
        out(['ok'=>true,'id'=>$id]);
    case 'tag_delete':
        Groups::deleteTag((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'tag_persons':
        $ids = is_array($in['person_ids'] ?? null) ? $in['person_ids'] : explode(',', (string)($in['person_ids'] ?? ''));
        Groups::tagPersons((int)($in['tag_id'] ?? 0), $ids);
        out(['ok'=>true]);

    // ============ کمپین ============
    case 'campaigns_list':
        out(['ok'=>true,'campaigns'=>Campaigns::all()]);
    case 'campaign_get':
        $c = Campaigns::find((int)($in['id'] ?? 0));
        if (!$c) out(['ok'=>false,'error'=>'یافت نشد']);
        $c['step_stats'] = Campaigns::stats((int)$in['id']);
        out(['ok'=>true,'campaign'=>$c]);
    case 'campaign_create':
        $filter = [
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
            'status'   => $in['status'] ?? null,
        ];
        $channels = !empty($in['channels']) ? (is_array($in['channels']) ? $in['channels'] : explode(',', $in['channels'])) : ['bale','telegram','roubika'];
        $steps = $in['steps'] ?? [];
        if (!is_array($steps) || !$steps) out(['ok'=>false,'error'=>'حداقل یک مرحله لازم است']);
        $id = Campaigns::create(trim($in['name'] ?? ''), $filter, $channels, $steps);
        out(['ok'=>true,'id'=>$id]);
    case 'campaign_start':
        @set_time_limit(0);
        out(Campaigns::start((int)($in['id'] ?? 0)));
    case 'campaign_delete':
        Campaigns::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ زمان‌بندی ============
    case 'scheduled_list':
        out(['ok'=>true,'scheduled'=>Scheduler::listForUi(), 'now_local'=>Tz::nowLocal()]);
    case 'scheduled_create':
        if (empty($in['run_at'])) out(['ok'=>false,'error'=>'زمان ارسال لازم است']);
        // زمان واردشده به ساعت محلی است؛ به ساعت دیتابیس تبدیل می‌شود تا با NOW() مقایسه‌پذیر باشد
        $runAtDb = Tz::toDb((string)$in['run_at']);
        if (!$runAtDb) out(['ok'=>false,'error'=>'قالب زمان نامعتبر است']);
        $filter = [
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
            'status'   => $in['status'] ?? null,
        ];
        $channels = !empty($in['channel_priority']) ? explode(',', $in['channel_priority']) : ['bale','telegram','roubika'];
        $id = Scheduler::create([
            'name'        => trim($in['name'] ?? '') ?: 'بدون نام',
            'send_type'   => $in['send_type'] ?? 'chat',
            'template_id' => !empty($in['template_id']) ? (int)$in['template_id'] : null,
            'text'        => trim($in['text'] ?? '') ?: null,
            'buttons_json'=> trim($in['buttons_json'] ?? '') ?: null,
            'media_path'  => (!empty($in['image_path']) && is_file($in['image_path'])) ? $in['image_path'] : null,
            'filter'      => $filter,
            'phones_text' => trim($in['phones_text'] ?? '') ?: null,
            'phone_mode'  => $in['phone_mode'] ?? 'safir_then_sms',
            'channels'    => $channels,
            'run_at'      => $runAtDb,
        ]);
        out(['ok'=>true,'id'=>$id]);
    case 'scheduled_cancel':
        Scheduler::cancel((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'scheduled_delete':
        Scheduler::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ آمار ============
    case 'stats_overview':
        // شمارش روی کل پیام‌ها سنگین است؛ یک دقیقه کش (برای همه‌ی کاربران یکسان است)
        out(['ok'=>true,'stats'=>Cache::remember('stats_overview', 60, fn() => Stats::overview())]);

    // ============ تنظیمات ربات ============
    case 'botsettings_get':
        $row = Database::one("SELECT v FROM eb_bot_settings WHERE k='welcome'");
        out(['ok'=>true,'welcome'=>$row['v'] ? json_decode($row['v'], true) : null]);
    case 'botsettings_save':
        adminOnly();
        $data = json_encode([
            'text'    => trim($in['text'] ?? ''),
            'buttons' => $in['buttons'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        Database::run("INSERT INTO eb_bot_settings (k,v) VALUES ('welcome',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$data]);
        out(['ok'=>true]);

    // ============ تنظیمات پیامک ============
    case 'sms_get':
        $provider = Database::one("SELECT v FROM eb_settings WHERE k='channel.sms.provider'")['v'] ?? '';
        $sender   = Database::one("SELECT v FROM eb_settings WHERE k='channel.sms.sender'")['v'] ?? '';
        $key      = Database::one("SELECT v FROM eb_settings WHERE k='channel.sms.api_key'")['v'] ?? '';
        // برای ملی‌پیامک، نام کاربری (قبل از ':') قابل نمایش است؛ رمز هرگز برنمی‌گردد.
        $meliUser = '';
        if ($provider === 'melipayamak' && strpos($key, ':') !== false) {
            $meliUser = explode(':', $key, 2)[0];
        }
        out(['ok'=>true, 'provider'=>$provider, 'sender'=>$sender, 'has_key'=>($key!==''), 'meli_user'=>$meliUser, 'providers'=>SmsDriver::availableProviders()]);
    case 'sms_save':
        adminOnly();
        $provider = $in['provider'] ?? '';
        $key = trim($in['api_key'] ?? '');
        $sender = trim($in['sender'] ?? '');
        if (!in_array($provider, SmsDriver::availableProviders(), true)) out(['ok'=>false,'error'=>'provider نامعتبر']);
        if ($key === '') out(['ok'=>false,'error'=>'کلید لازم است']);
        ChannelManager::saveSms($provider, $key, $sender);
        out(['ok'=>true]);

    // ============ گرفتن شماره‌ی اعضای گروه/برچسب (برای ارسال با شماره) ============
    case 'phones_from_group':
        $filter = [
            'group_id' => !empty($in['group_id']) ? (int)$in['group_id'] : null,
            'tag_id'   => !empty($in['tag_id']) ? (int)$in['tag_id'] : null,
            'status'   => 'member', // فقط اعضایی که شماره دارند
        ];
        $persons = Sender::resolveAudience($filter);
        $phones = [];
        if ($persons) {
            $ph = implode(',', array_fill(0, count($persons), '?'));
            $rows = Database::all("SELECT phone FROM eb_persons WHERE id IN ($ph) AND phone IS NOT NULL AND phone <> ''", $persons);
            foreach ($rows as $r) $phones[] = $r['phone'];
        }
        out(['ok'=>true, 'phones'=>$phones, 'count'=>count($phones)]);

    // ============ ارسال یکپارچه با شماره (صفیر + پیامک) ============
    case 'phone_send':
        @set_time_limit(0);
        $text = trim($in['text'] ?? '');
        $phonesRaw = trim($in['phones'] ?? '');
        $mode = $in['mode'] ?? 'safir_then_sms'; // safir_then_sms | safir | sms | both
        $hasImage = !empty($in['image_path']) && is_file($in['image_path']);
        if ($text === '' && !$hasImage) out(['ok'=>false,'error'=>'متن یا عکس لازم است']);
        if ($phonesRaw === '') out(['ok'=>false,'error'=>'شماره‌ای وارد نشده']);

        $phones = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $phonesRaw)));
        $message = ['text' => $text];
        if (!empty($in['buttons_json'])) {
            $dec = json_decode($in['buttons_json'], true);
            if (is_array($dec)) $message['buttons'] = Templates::normalizeButtons($dec);
        }
        if ($hasImage) $message['photo'] = $in['image_path'];

        $stat = Sender::sendByPhone($phones, $message, $mode);
        out(['ok'=>true] + $stat);

    // ============ استخراج شماره از فایل (CSV / Excel) ============
    case 'phones_from_file':
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $numbers = [];

        if ($ext === 'csv' || $ext === 'txt') {
            $content = file_get_contents($tmp);
            // حذف BOM احتمالی
            $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
            foreach (preg_split('/[\r\n,;\t]+/', $content) as $tok) {
                $tok = trim($tok);
                if ($tok !== '') $numbers[] = $tok;
            }
        } elseif ($ext === 'xlsx') {
            // xlsx = zip حاوی XML. شماره‌ها را از sharedStrings و sheet استخراج می‌کنیم.
            $numbers = parseXlsxNumbers($tmp);
            if ($numbers === null) out(['ok'=>false,'error'=>'خواندن فایل اکسل ناموفق بود']);
        } else {
            out(['ok'=>false,'error'=>'فقط CSV یا XLSX پشتیبانی می‌شود. (اکسل را می‌توانید Save As CSV کنید)']);
        }

        // نرمال‌سازی و جداسازی معتبر/نامعتبر فقط برای پیش‌نمایش (ارسال بعداً جدا انجام می‌شود)
        $valid = []; $invalid = 0; $seen = [];
        foreach ($numbers as $n) {
            $norm = Persons::normalizePhone($n);
            if ($norm === null) { $invalid++; }
            elseif (!isset($seen[$norm])) { $seen[$norm] = true; $valid[] = $norm; }
        }
        out(['ok'=>true, 'phones'=>$valid, 'valid_count'=>count($valid), 'invalid_count'=>$invalid]);

    // ============ ورود لیست مشتری از فایل (با گروه/برچسب) ============
    case 'import_customers':
        adminOnly();
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $groupId = !empty($in['group_id']) ? (int)$in['group_id'] : 0;
        $tagName = trim($in['tag_name'] ?? '');

        // استخراج ردیف‌ها: هر ردیف می‌تواند «نام,شماره» یا فقط «شماره» باشد
        $rows = [];
        if ($ext === 'csv' || $ext === 'txt') {
            $content = preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($tmp));
            foreach (preg_split('/[\r\n]+/', $content) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $cols = preg_split('/[,;\t]+/', $line);
                $rows[] = $cols;
            }
        } elseif ($ext === 'xlsx') {
            // سطرها را با ستون‌ها بخوان (نام + شماره)
            $xrows = parseXlsxRows($tmp);
            if ($xrows === null) out(['ok'=>false,'error'=>'خواندن اکسل ناموفق']);
            foreach ($xrows as $cols) {
                // سطر هدر را رد کن (اگر هیچ ستونی عدد نداشت و کلمه‌هایی مثل نام/شماره دارد)
                $rows[] = $cols;
            }
        } else {
            out(['ok'=>false,'error'=>'فقط CSV یا XLSX']);
        }

        // آماده‌سازی تگ (اگر داده شد)
        $tagId = 0;
        if ($tagName !== '') { $tagId = Groups::ensureTag($tagName); }

        $created = 0; $added = 0; $invalid = 0;
        foreach ($rows as $cols) {
            // تشخیص نام و شماره: اگر دو ستون، اولی نام دومی شماره؛ اگر یکی، فقط شماره
            $name = null; $phoneRaw = null;
            if (count($cols) >= 2) {
                // کدام ستون شماره است؟ آنکه عددی‌تر است
                $a = trim($cols[0]); $b = trim($cols[1]);
                if (preg_match('/\d{6,}/', $b)) { $name = $a; $phoneRaw = $b; }
                elseif (preg_match('/\d{6,}/', $a)) { $name = $b; $phoneRaw = $a; }
                else { $phoneRaw = $a; }
            } else {
                $phoneRaw = trim($cols[0]);
            }
            $phone = Persons::normalizePhone($phoneRaw);
            if ($phone === null) { $invalid++; continue; }

            // آیا شخصی با این شماره هست؟
            $existing = Database::one("SELECT id FROM eb_persons WHERE phone = ?", [$phone]);
            if ($existing) {
                $pid = (int)$existing['id'];
                if ($name && $name !== '') {
                    Database::run("UPDATE eb_persons SET full_name = COALESCE(NULLIF(full_name,''), ?) WHERE id = ?", [$name, $pid]);
                }
            } else {
                Database::run(
                    "INSERT INTO eb_persons (full_name, phone, status) VALUES (?, ?, 'member')",
                    [$name !== '' ? $name : null, $phone]
                );
                $pid = Database::lastId();
                $created++;
            }
            if ($groupId) { Database::run("INSERT IGNORE INTO eb_person_groups (group_id, person_id) VALUES (?, ?)", [$groupId, $pid]); }
            if ($tagId)   { Database::run("INSERT IGNORE INTO eb_person_tags (tag_id, person_id) VALUES (?, ?)", [$tagId, $pid]); }
            $added++;
        }
        out(['ok'=>true, 'created'=>$created, 'added'=>$added, 'invalid'=>$invalid]);

    // ============ تلفن گویا (تماس صوتی) ============
    case 'voice_available':
        out(['ok'=>true, 'available'=>ChannelManager::voiceAvailable()]);
    case 'voice_upload_file':
        @set_time_limit(0);
        $voice = ChannelManager::getVoice();
        if (!$voice) out(['ok'=>false,'error'=>'تلفن گویا فعال نیست']);
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'wav') out(['ok'=>false,'error'=>'فقط فایل WAV پشتیبانی می‌شود (طبق ملی‌پیامک)']);
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) out(['ok'=>false,'error'=>'حجم فایل نباید بیش از ۵ مگابایت باشد']);
        $bin = file_get_contents($_FILES['file']['tmp_name']);
        // base64 تمیز بدون خط جدید یا فاصله (علت شایع خطای Value cannot be null در ملی‌پیامک)
        $b64 = str_replace(["\r", "\n", " "], '', base64_encode($bin));
        if ($b64 === '') out(['ok'=>false,'error'=>'فایل خالی است یا خوانده نشد']);
        $title = trim($in['title'] ?? '') ?: ('voice_' . date('Ymd_His'));
        $r = $voice->uploadVoiceFile($title, $b64);
        out($r);
    case 'voice_bulk_file':
        @set_time_limit(0);
        $voice = ChannelManager::getVoice();
        if (!$voice) out(['ok'=>false,'error'=>'تلفن گویا فعال نیست']);
        $fileId = (int)($in['voice_file_id'] ?? 0);
        if ($fileId <= 0) out(['ok'=>false,'error'=>'شناسه‌ی فایل صوتی نامعتبر']);
        $phonesRaw = trim($in['phones'] ?? '');
        $phones = [];
        foreach (preg_split('/[\r\n,;]+/', $phonesRaw) as $p) {
            $n = Persons::normalizePhone(trim($p));
            if ($n) $phones[$n] = true;
        }
        $phones = array_keys($phones);
        if (!$phones) out(['ok'=>false,'error'=>'شماره‌ی معتبری یافت نشد']);
        $title = trim($in['title'] ?? '') ?: ('bulk_' . date('Ymd_His'));
        $dateToSend = $in['date_to_send'] ?? date('Y-m-d\TH:i:s', time() + 120); // پیش‌فرض: ۲ دقیقه بعد
        $repeat = max(1, min(4, (int)($in['repeat'] ?? 1)));
        $r = $voice->sendBulkVoiceFile($title, $fileId, implode(',', $phones), $dateToSend, $repeat);
        out($r);
    case 'voice_call':
        @set_time_limit(0);
        $voice = ChannelManager::getVoice();
        if (!$voice) out(['ok'=>false,'error'=>'تلفن گویا فعال نیست (نیاز به provider ملی‌پیامک)']);
        $text = trim($in['text'] ?? '');
        $phonesRaw = trim($in['phones'] ?? '');
        if ($text === '') out(['ok'=>false,'error'=>'متن گفتار لازم است']);
        if ($phonesRaw === '') out(['ok'=>false,'error'=>'شماره‌ای وارد نشده']);

        $phones = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $phonesRaw)));
        $valid = []; $seen = [];
        foreach ($phones as $p) {
            $n = Persons::normalizePhone($p);
            if ($n !== null && !isset($seen[$n])) { $seen[$n] = true; $valid[] = $n; }
        }
        if (!$valid) out(['ok'=>false,'error'=>'شماره‌ی معتبری یافت نشد']);

        $sendId = SendLog::start('voice', mb_substr($text, 0, 80), $text, ['voice']);
        $ok = 0; $fail = 0;
        foreach ($valid as $phone) {
            $r = $voice->callSpeech($phone, $text);
            $success = !empty($r['ok']);
            if ($success) $ok++; else $fail++;
            $pid = Persons::ingestIdentity('voice', ['channel_uid'=>$phone, 'phone'=>$phone]);
            SendLog::target($sendId, $pid, 'voice', $phone, $success, $r['error'] ?? null);
            usleep(50000);
        }
        SendLog::finish($sendId, count($valid), $ok, $fail, []);
        out(['ok'=>true, 'total'=>count($valid), 'sent'=>$ok, 'failed'=>$fail, 'send_id'=>$sendId]);

    // ============ پروفایل اپراتور (خودِ کاربر فعلی) ============
    case 'my_profile':
        $uid = Auth::id();
        $u = Database::one("SELECT id, username, display_name, role, permissions_json, created_at FROM eb_users WHERE id=?", [$uid]);
        if (!$u) out(['ok'=>false,'error'=>'یافت نشد']);
        // آمار فعالیت این اپراتور (ارسال‌هایی که زده)
        $sends = (int)(Database::one("SELECT COUNT(*) c FROM eb_sends WHERE created_by=?", [$uid])['c'] ?? 0);
        $perms = json_decode($u['permissions_json'] ?? '{}', true) ?: [];
        out([
            'ok'=>true,
            'username'=>$u['username'],
            'display_name'=>$u['display_name'] ?? null,
            'role'=>$u['role'],
            'created_at'=>$u['created_at'],
            'send_count'=>$sends,
            'pages'=>$perms['pages'] ?? [],
            'groups'=>$perms['groups'] ?? [],
            'unrestricted'=>($u['role']==='admin'),
        ]);
    case 'my_password_change':
        $uid = Auth::id();
        $cur = (string)($in['current'] ?? '');
        $new = (string)($in['new'] ?? '');
        if (strlen($new) < 4) out(['ok'=>false,'error'=>'رمز جدید حداقل ۴ کاراکتر']);
        $u = Database::one("SELECT password_hash FROM eb_users WHERE id=?", [$uid]);
        if (!$u || !password_verify($cur, $u['password_hash'])) out(['ok'=>false,'error'=>'رمز فعلی اشتباه است']);
        Database::run("UPDATE eb_users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_BCRYPT), $uid]);
        out(['ok'=>true]);
    case 'my_username_change':
        $uid = Auth::id();
        $newU = trim($in['username'] ?? '');
        if ($newU === '') out(['ok'=>false,'error'=>'نام کاربری لازم است']);
        if (Database::one("SELECT id FROM eb_users WHERE username=? AND id<>?", [$newU, $uid])) out(['ok'=>false,'error'=>'این نام کاربری گرفته شده']);
        Database::run("UPDATE eb_users SET username=? WHERE id=?", [$newU, $uid]);
        if (!(Database::one("SELECT display_name FROM eb_users WHERE id=?", [$uid])['display_name'] ?? '')) $_SESSION['eb_name'] = $newU;
        Cache::forget('users_names');
        out(['ok'=>true]);

    // ============ تنظیمات ایمیل (SMTP) ============
    // ============ اینستاگرام (Instagram Login — بدون نیاز به صفحه‌ی فیسبوک) ============
    case 'instagram_get':
        adminOnly();
        $a = InstagramAuth::all();
        $base = app_base_url($config);
        out([
            'ok'            => true,
            'app_id'        => $a['app_id'],
            'app_id_set'    => trim($a['app_id']) !== '',
            'app_secret_set'=> trim($a['app_secret']) !== '',
            'verify_token'  => $a['verify_token'],
            'username'      => $a['username'],
            'connected'     => InstagramAuth::isConnected(),
            'app_configured'=> InstagramAuth::isAppConfigured(),
            'token_expires_at' => $a['token_expires_at'],
            'webhook_url'   => rtrim($base, '/') . '/webhook/receive.php?channel=instagram',
            'redirect_uri'  => InstagramAuth::redirectUri($base),
        ]);
    case 'instagram_save_app':
        adminOnly();
        InstagramAuth::saveApp((string)($in['app_id'] ?? ''), (string)($in['app_secret'] ?? ''));
        out(['ok'=>true]);
    case 'instagram_oauth_url':
        adminOnly();
        $base = app_base_url($config);
        $state = bin2hex(random_bytes(16));
        $_SESSION['ig_oauth_state'] = $state;
        $url = InstagramAuth::authorizationUrl($base, $state);
        if (!$url) out(['ok'=>false,'error'=>'ابتدا App ID را ذخیره کن']);
        out(['ok'=>true, 'url'=>$url]);
    case 'instagram_disconnect':
        adminOnly();
        InstagramAuth::disconnect();
        out(['ok'=>true]);
    case 'instagram_test':
        adminOnly();
        if (!InstagramAuth::isConnected()) out(['ok'=>false,'error'=>'حساب اینستاگرام متصل نیست']);
        $r = InstagramAuth::subscribeWebhook();
        out(['ok'=>!empty($r['ok']), 'error'=>$r['ok'] ? null : ($r['body']['error']['message'] ?? 'اشتراک وب‌هوک ناموفق بود')]);

    // ============ یار API — اتصال همه‌ی پیج‌ها با یک کلید یار API (روش پیشنهادی) ============
    case 'apichi_status':
        adminOnly();
        out(['ok' => true] + InstagramApichi::status($config));
    // ============ لاگ کامل ارتباط با یار API ============
    case 'apichi_log_list':
        adminOnly();
        $rows = class_exists('ApichiLog') ? ApichiLog::list(['kind' => $in['kind'] ?? '', 'ok' => $in['ok'] ?? '', 'q' => trim((string)($in['q'] ?? '')), 'before' => (int)($in['before'] ?? 0)], 60) : [];
        out(['ok' => true, 'rows' => $rows, 'has_more' => count($rows) === 60, 'stats' => class_exists('ApichiLog') ? ApichiLog::stats() : [], 'kinds' => class_exists('ApichiLog') ? ApichiLog::kinds() : []]);
    case 'apichi_log_get':
        adminOnly();
        $row = class_exists('ApichiLog') ? ApichiLog::get((int)($in['id'] ?? 0)) : null;
        out($row ? ['ok' => true, 'row' => $row] : ['ok' => false, 'error' => 'پیدا نشد']);
    case 'apichi_log_clear':
        adminOnly();
        if (class_exists('ApichiLog')) ApichiLog::clear(($in['kind'] ?? '') ?: null);
        out(['ok' => true]);
    case 'apichi_log_settings':
        adminOnly();
        InstagramApichi::setSetting('log_enabled', !empty($in['enabled']) ? '1' : '0');
        InstagramApichi::setSetting('log_days', (string)max(1, min(90, (int)($in['days'] ?? 14))));
        out(['ok' => true]);
    case 'apichi_health':
        adminOnly();
        try { out(['ok' => true, 'checks' => InstagramApichi::health($config), 'log_enabled' => InstagramApichi::setting('log_enabled', '1') !== '0', 'log_days' => (int)InstagramApichi::setting('log_days', '14')]); }
        catch (Throwable $e) { out(['ok' => false, 'error' => $e->getMessage()]); }

    case 'apichi_save':
        adminOnly();
        try {
            $r = InstagramApichi::saveConnection((string)($in['base_url'] ?? ''), (string)($in['api_key'] ?? ''), (string)($in['mode'] ?? 'webhook'));
        } catch (Throwable $e) { $r = ['ok' => false, 'error' => 'خطای داخلی: ' . $e->getMessage()]; }
        if (class_exists('ApichiLog')) ApichiLog::add('action', ['method' => 'save', 'ok' => !empty($r['ok']), 'summary' => 'ذخیره‌ی اتصال یار API: ' . (!empty($r['ok']) ? 'موفق' . (!empty($r['sync']['ok']) ? ' — ' . ($r['sync']['total'] ?? 0) . ' پیج' : '') : 'ناموفق — ' . ($r['error'] ?? '')), 'response' => $r]);
        out($r);
    case 'apichi_sync':
        adminOnly();
        try {
            $r = InstagramApichi::syncAccounts();
            if (class_exists('ApichiLog')) ApichiLog::add('action', ['method' => 'sync', 'ok' => !empty($r['ok']), 'summary' => 'همگام‌سازی پیج‌ها: ' . (!empty($r['ok']) ? "کل {$r['total']}، جدید {$r['added']}، به‌روز {$r['updated']}، غیرفعال‌شده {$r['disabled']}" : 'ناموفق — ' . ($r['error'] ?? '')), 'response' => $r]);
            out($r);
        }
        catch (Throwable $e) { out(['ok' => false, 'error' => 'خطای داخلی: ' . $e->getMessage()]); }
    case 'apichi_webhook_register':
        adminOnly();
        $r = InstagramApichi::registerWebhook($config);
        if (class_exists('ApichiLog')) ApichiLog::add('action', ['method' => 'webhook', 'ok' => !empty($r['ok']), 'summary' => 'ثبت وب‌هوک در یار API: ' . (!empty($r['ok']) ? 'موفق' : 'ناموفق — ' . ($r['error'] ?? '')), 'response' => $r]);
        out($r);
    case 'apichi_webhook_test':
        adminOnly();
        $r = InstagramApichi::testWebhook();
        if (class_exists('ApichiLog')) ApichiLog::add('action', ['method' => 'test', 'ok' => !empty($r['ok']), 'summary' => 'تست وب‌هوک: ' . (!empty($r['ok']) ? 'یار API رسید و اعلان‌بان جواب داد ✓' : 'ناموفق — ' . ($r['error'] ?? '')), 'response' => $r]);
        out($r);
    case 'apichi_mode':
        adminOnly();
        InstagramApichi::setSetting('mode', ($in['mode'] ?? '') === 'polling' ? 'polling' : 'webhook');
        out(['ok' => true]);
    case 'apichi_poll':
        adminOnly();
        $n = InstagramApichi::pollWithPipeline($config);
        out(['ok' => true, 'processed' => $n, 'error' => InstagramApichi::setting('last_poll_error') ?: null]);
    case 'apichi_disconnect':
        adminOnly();
        foreach (['api_key', 'webhook_id', 'webhook_secret', 'poll_cursor', 'scopes'] as $k) InstagramApichi::setSetting($k, '');
        Database::run("UPDATE eb_ig_accounts SET is_active = 0 WHERE provider = 'apichi'");
        out(['ok' => true]);

    // ============ سرویس‌دهنده (اتصال مستقیم قدیمی) — چند پیج، هر پیج با Account Key خودش ============
    case 'roboclick_integration_get':
        adminOnly();
        $rk = InstagramRoboclick::integrationKey();
        out([
            'ok' => true,
            'key_set' => $rk !== '',
            'key_masked' => $rk !== '' ? (substr($rk,0,5).str_repeat('•',6).substr($rk,-4)) : '',
            'return_url' => InstagramRoboclick::oauthReturnUrl($config),
        ]);
    case 'roboclick_integration_save':
        adminOnly();
        $key = trim($in['key'] ?? '');
        if ($key === '') out(['ok'=>false,'error'=>'Integration Key لازم است']);
        if (strpos($key, 'rk_') !== 0) out(['ok'=>false,'error'=>'کلید باید با rk_ شروع شود (Integration Key، نه Account Key)']);
        InstagramRoboclick::setIntegrationKey($key);
        out(['ok'=>true]);
    case 'roboclick_oauth_start':
        adminOnly();
        $r = InstagramRoboclick::startOAuth($config, !empty($in['use_free_trial']));
        out($r['ok'] ? ['ok'=>true,'authorize_url'=>$r['authorize_url']] : ['ok'=>false,'error'=>$r['error']]);
    case 'roboclick_accounts_list':
        // ۲.۰.۷: غیرمدیر هم فهرست پیج‌ها را می‌بیند (بدون کلید، وب‌هوک و تنظیمات اتصال)
        if (!Auth::isAdmin()) {
            $slim = [];
            foreach (InstagramRoboclick::all() as $r) $slim[] = ['id' => (int)$r['id'], 'label' => $r['label'] ?? '', 'username' => $r['username'] ?? '', 'color' => $r['color'] ?? '', 'is_active' => (int)($r['is_active'] ?? 1), 'provider' => $r['provider'] ?? 'roboclick', 'webhook_secret_set' => 1, 'readonly' => 1];
            out(['ok' => true, 'accounts' => $slim, 'limited' => true]);
        }
        $rows = InstagramRoboclick::all();
        $base = app_base_url($config);
        $apichiSecretSet = InstagramApichi::setting('webhook_secret') !== '';
        foreach ($rows as &$r) {
            $k = (string)$r['account_key'];
            $isApichi = ($r['provider'] ?? '') === 'apichi';
            $r['account_key_masked'] = $isApichi ? ('یار API · igAccountId=' . $r['apichi_account_id']) : (strlen($k) > 8 ? substr($k,0,5).str_repeat('•',6).substr($k,-4) : '••••');
            unset($r['account_key']);
            $r['webhook_secret_set'] = $isApichi ? ($apichiSecretSet || InstagramApichi::mode() === 'polling') : !empty($r['webhook_secret']);
            unset($r['webhook_secret']); // خودِ سکرت هیچ‌وقت به فرانت برنمی‌گردد
            $r['webhook_url'] = $isApichi ? InstagramApichi::webhookUrl($config) : rtrim($base,'/') . '/webhook/roboclick.php?account=' . $r['id'];
        }
        unset($r);
        out(['ok'=>true, 'accounts'=>$rows]);

    case 'roboclick_account_add':
        adminOnly();
        $label = trim($in['label'] ?? '');
        $key = trim($in['account_key'] ?? '');
        $username = trim($in['username'] ?? '');
        $color = trim($in['color'] ?? '') ?: '#6366f1';
        if ($key === '') out(['ok'=>false,'error'=>'Account Key لازم است']);
        if (strpos($key, 'ak_') !== 0) out(['ok'=>false,'error'=>'کلید باید با ak_ شروع شود (Account Key، نه Integration Key)']);
        $acc = InstagramRoboclick::add($label, $key, $username, $color);
        if (!$acc) out(['ok'=>false,'error'=>'ثبت پیج ناموفق بود']);
        out(['ok'=>true, 'id'=>$acc['id'], 'webhook_url'=>InstagramRoboclick::webhookUrl($config, (int)$acc['id'])]);

    case 'roboclick_account_update':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        if (!$id) out(['ok'=>false,'error'=>'شناسه‌ی پیج لازم است']);
        $fields = [];
        foreach (['label','username','color'] as $k) { if (isset($in[$k])) $fields[$k] = trim((string)$in[$k]); }
        $accRow = InstagramRoboclick::get($id);
        if ($accRow && ($accRow['provider'] ?? '') === 'apichi' && (isset($in['account_key']) || isset($in['webhook_secret']))) {
            out(['ok'=>false,'error'=>'این پیج از طریق یار API وصل است؛ کلید و وب‌هوک آن از کارت «اتصال به یار API» مدیریت می‌شود']);
        }
        if (isset($in['account_key']) && trim($in['account_key']) !== '') {
            $newKey = trim($in['account_key']);
            if (strpos($newKey, 'ak_') !== 0) out(['ok'=>false,'error'=>'کلید باید با ak_ شروع شود']);
            $fields['account_key'] = $newKey;
        }
        if (isset($in['webhook_secret']) && trim($in['webhook_secret']) !== '') {
            $fields['webhook_secret'] = trim($in['webhook_secret']);
        }
        if (isset($in['is_active'])) $fields['is_active'] = !empty($in['is_active']) ? 1 : 0;
        InstagramRoboclick::update($id, $fields);
        out(['ok'=>true]);

    case 'roboclick_account_delete':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        if (!$id) out(['ok'=>false,'error'=>'شناسه‌ی پیج لازم است']);
        InstagramRoboclick::delete($id);
        out(['ok'=>true]);

    case 'roboclick_account_test':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        // ساده‌ترین تست: چک وضعیت فالو با یک contactId ساختگی هم کلید را تست می‌کند
        // (اگر کلید نامعتبر باشد ۴۰۱/۴۰۳ می‌گیریم، نه یک خطای دیگر)
        $r = $driver->followStatus('0');
        $badKey = !$r['ok'] && in_array((string)($r['error'] ?? ''), ['wrong_key_type'], true);
        out(['ok'=>true, 'reachable'=>true, 'note'=>$badKey ? 'کلید از نوع Account Key نیست' : 'اتصال برقرار است']);

    case 'roboclick_publish_post':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $imgRel = trim($in['image_path'] ?? '');
        if ($imgRel === '') out(['ok'=>false,'error'=>'عکس لازم است']);
        $absPath = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/' . basename($imgRel);
        if (!is_file($absPath)) out(['ok'=>false,'error'=>'فایل عکس یافت نشد']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        $up = $driver->uploadImage($absPath);
        if (empty($up['ok']) || empty($up['photo'])) out(['ok'=>false,'error'=>'آپلود عکس ناموفق بود: '.($up['error']??'نامشخص').' — برای جزئیات کامل، «اینستاگرام ← اتصال و عیب‌یابی ← لاگ خام API» را ببین']);
        $r = $driver->sendPost(trim((string)($in['caption'] ?? '')), $up['photo']);
        out(['ok'=>!empty($r['ok']), 'error'=>$r['error'] ?? null]);

    case 'roboclick_publish_story':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $imgRel = trim($in['image_path'] ?? '');
        if ($imgRel === '') out(['ok'=>false,'error'=>'عکس لازم است']);
        $absPath = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/' . basename($imgRel);
        if (!is_file($absPath)) out(['ok'=>false,'error'=>'فایل عکس یافت نشد']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        $up = $driver->uploadImage($absPath);
        if (empty($up['ok']) || empty($up['photo'])) out(['ok'=>false,'error'=>'آپلود عکس ناموفق بود: '.($up['error']??'نامشخص').' — برای جزئیات کامل، «اینستاگرام ← اتصال و عیب‌یابی ← لاگ خام API» را ببین']);
        $r = $driver->sendStory($up['photo']);
        out(['ok'=>!empty($r['ok']), 'error'=>$r['error'] ?? null]);

    case 'roboclick_send_showcase':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $contactId = trim($in['contact_id'] ?? '');
        if ($contactId === '') out(['ok'=>false,'error'=>'شناسه‌ی مخاطب (contactId) لازم است']);
        $cardsJson = trim($in['cards_json'] ?? '');
        $cards = json_decode($cardsJson, true);
        if (!is_array($cards) || !$cards) out(['ok'=>false,'error'=>'JSON کارت‌ها نامعتبر است']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        // هر کارت باید عکسش را قبلاً با upload_image (روی همین سرور) آپلود کرده باشد؛ اینجا
        // مسیر نسبی را می‌گیریم و به خروجی UploadImage سرویس‌دهنده تبدیل می‌کنیم.
        foreach ($cards as &$c) {
            if (!empty($c['photo_rel'])) {
                $absPath = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/' . basename($c['photo_rel']);
                if (is_file($absPath)) {
                    $up = $driver->uploadImage($absPath);
                    $c['photo'] = $up['photo'] ?? null;
                }
                unset($c['photo_rel']);
            }
        }
        unset($c);
        $r = $driver->sendShowcase($contactId, $cards);
        out(['ok'=>!empty($r['ok']), 'error'=>$r['error'] ?? null]);

    case 'roboclick_posts_list':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        $r = $driver->listPosts(($in['cursor'] ?? '') !== '' ? (string)$in['cursor'] : null, 25);
        if (!$r['ok']) out(['ok'=>false,'error'=>$r['error']]);
        $posts = array_map(fn($p) => is_array($p) ? InstagramRoboclick::normalizeMedia($p, 'post') : ['id' => (string)$p, 'thumb' => '', 'caption' => '', 'raw' => $p], array_values((array)($r['posts'] ?? [])));
        out(['ok'=>true,'posts'=>$posts,'cursor'=>$r['cursor'] ?? null]);

    case 'roboclick_publish_history':
        adminOnly();
        $rows = Database::all(
            "SELECT * FROM eb_api_log WHERE channel IN ('instagram_roboclick','instagram_apichi')
             AND action IN ('External/ExternalPost/SendPost','External/ExternalPost/SendStory','instagram/publish-post','instagram/publish-story')
             ORDER BY id DESC LIMIT 15"
        );
        foreach ($rows as &$r) {
            $req = json_decode($r['request_data'] ?? '', true);
            $r['kind'] = stripos($r['action'], 'story') !== false ? 'story' : 'post';
            $r['caption'] = is_array($req) ? mb_substr((string)($req['text'] ?? $req['caption'] ?? ''), 0, 80) : '';
            unset($r['request_data'], $r['response_data']);
        }
        unset($r);
        out(['ok'=>true, 'rows'=>$rows]);

    case 'roboclick_stories_list':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        $r = $driver->listStories(25);
        if (!$r['ok']) out(['ok'=>false,'error'=>$r['error']]);
        $stories = array_map(fn($p) => is_array($p) ? InstagramRoboclick::normalizeMedia($p, 'story') : ['id' => (string)$p, 'thumb' => '', 'caption' => '', 'raw' => $p], array_values((array)($r['stories'] ?? [])));
        out(['ok'=>true,'stories'=>$stories]);

    case 'roboclick_stats_today':
        adminOnly();
        $accounts = InstagramRoboclick::all();
        $rows = [];
        foreach ($accounts as $acc) {
            $msgIn = 0; $msgOut = 0; $comments = 0;
            try {
                $msgIn = (int)(Database::one(
                    "SELECT COUNT(*) c FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id
                     WHERE i.ig_account_id=? AND m.direction='in' AND DATE(m.created_at)=CURDATE()",
                    [$acc['id']]
                )['c'] ?? 0);
                $msgOut = (int)(Database::one(
                    "SELECT COUNT(*) c FROM eb_messages m INNER JOIN eb_identities i ON i.id=m.identity_id
                     WHERE i.ig_account_id=? AND m.direction='out' AND DATE(m.created_at)=CURDATE()",
                    [$acc['id']]
                )['c'] ?? 0);
                $comments = (int)(Database::one(
                    "SELECT COUNT(*) c FROM eb_ig_comments WHERE ig_account_id=? AND DATE(created_at)=CURDATE()",
                    [$acc['id']]
                )['c'] ?? 0);
            } catch (Throwable $e) {}
            $rows[] = [
                'id' => $acc['id'], 'label' => $acc['label'], 'color' => $acc['color'],
                'messages_in' => $msgIn, 'messages_out' => $msgOut, 'comments' => $comments,
            ];
        }
        out(['ok'=>true, 'rows'=>$rows]);

    case 'roboclick_comments_list':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $rows = $id
            ? Database::all("SELECT * FROM eb_ig_comments WHERE ig_account_id = ? ORDER BY id DESC LIMIT 100", [$id])
            : Database::all("SELECT * FROM eb_ig_comments WHERE ig_account_id IS NOT NULL ORDER BY id DESC LIMIT 100");
        out(['ok'=>true, 'rows'=>$rows]);

    case 'roboclick_story_media_lookup':
        adminOnly();
        $id = (int)($in['id'] ?? 0);
        $acc = $id ? InstagramRoboclick::get($id) : null;
        if (!$acc) out(['ok'=>false,'error'=>'پیج یافت نشد']);
        $resourceId = trim($in['resource_id'] ?? '');
        if ($resourceId === '') out(['ok'=>false,'error'=>'شناسه‌ی رسانه لازم است']);
        $driver = InstagramRoboclick::driverForAccount($acc);
        $r = $driver->fetchStoryMedia($resourceId);
        out($r['ok'] ? ['ok'=>true,'raw'=>$r['raw']] : ['ok'=>false,'error'=>$r['error']]);

    // ============ صفحه‌ی مدیریت اینستاگرام — از این پس فقط سرویس‌دهنده ============
    case 'instagram_mgmt_status':
        out([
            'ok'          => true,
            'connected'   => class_exists('InstagramAuth') && InstagramAuth::isConnected(),
            'provider'    => InstagramApichi::isConfigured() ? 'apichi' : 'roboclick',
        ]);
    case 'instagram_phone_gate_get':
        out([
            'ok' => true,
            'enabled' => IgPhoneGate::isEnabled(),
            'prompt_text' => IgPhoneGate::promptText(),
            'thanks_text' => IgPhoneGate::thankYouText(),
        ]);
    case 'instagram_phone_gate_save':
        IgPhoneGate::setEnabled(!empty($in['enabled']));
        if (isset($in['prompt_text'])) IgPhoneGate::setPromptText((string)$in['prompt_text']);
        if (isset($in['thanks_text'])) IgPhoneGate::setThankYouText((string)$in['thanks_text']);
        out(['ok' => true]);

    // ============ دانش هر پست (پاسخ هوشمند AI به کامنت‌های بدون قانون ثابت) + کلمات ممنوعه ============
    case 'instagram_knowledge_list':
        out(['ok'=>true, 'rows'=>IgCommentAutomation::listPostKnowledge()]);
    case 'instagram_knowledge_save':
        $mediaId = trim((string)($in['media_id'] ?? ''));
        if ($mediaId === '') out(['ok'=>false, 'error'=>'شناسه‌ی پست لازم است']);
        IgCommentAutomation::savePostKnowledge($mediaId, (string)($in['title'] ?? ''), (string)($in['knowledge_text'] ?? ''));
        out(['ok'=>true]);
    case 'instagram_knowledge_delete':
        IgCommentAutomation::deletePostKnowledge((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'instagram_forbidden_get':
        out([
            'ok' => true,
            'topics' => implode(',', IgCommentAutomation::forbiddenTopics()),
            'redirect_text' => IgCommentAutomation::forbiddenRedirectText(),
        ]);
    case 'instagram_forbidden_save':
        if (isset($in['topics'])) IgCommentAutomation::setForbiddenTopics((string)$in['topics']);
        if (isset($in['redirect_text'])) IgCommentAutomation::setForbiddenRedirectText((string)$in['redirect_text']);
        out(['ok'=>true]);

    case 'instagram_mgmt_users_list':
        // eb_identities مشترک است، پس این لیست شامل هردو دسته می‌شود.
        $rows = Database::all(
            "SELECT i.channel_uid, i.username, i.first_name, i.last_seen, i.first_seen,
                    p.id AS person_id, p.full_name, p.phone, p.blocked_at,
                    (SELECT COUNT(*) FROM eb_messages m WHERE m.identity_id = i.id) AS dm_count,
                    (SELECT COUNT(*) FROM eb_ig_comments c WHERE c.sender_id = i.channel_uid) AS comment_count,
                    (SELECT COUNT(*) FROM eb_ig_orders o WHERE o.person_id = p.id) AS order_count
             FROM eb_identities i
             LEFT JOIN eb_persons p ON p.id = i.person_id
             WHERE i.channel = 'instagram'
             ORDER BY i.last_seen DESC
             LIMIT 200"
        );
        out(['ok'=>true, 'rows'=>$rows]);

    // ============ اتوماسیون کامنت («کامنت کن X تا برات بفرستم» + فالو اجباری) ============
    case 'instagram_automation_list':
        $rows = IgCommentAutomation::all();
        if (class_exists('InstagramRoboclick')) {
            $accMap = [];
            foreach (InstagramRoboclick::all() as $a) { $accMap[$a['id']] = $a['label']; }
            foreach ($rows as &$r) { $r['ig_account_label'] = !empty($r['ig_account_id']) ? ($accMap[$r['ig_account_id']] ?? null) : null; }
            unset($r);
        }
        out(['ok'=>true, 'rows'=>$rows]);
    case 'instagram_automation_save':
        $id = IgCommentAutomation::save($in);
        out(['ok'=>true, 'id'=>$id]);
    case 'instagram_automation_delete':
        IgCommentAutomation::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'instagram_automation_toggle':
        IgCommentAutomation::toggle((int)($in['id'] ?? 0), !empty($in['active']));
        out(['ok'=>true]);

    // ============ تحلیل رسانه با AI (مورد ۴ — ابزار دستی تا فرمت پیوست ورودی مستند شود) ============
    case 'instagram_analyze_media':
        $mediaUrl = trim((string)($in['media_url'] ?? ''));
        $kind     = (string)($in['kind'] ?? 'image');
        if ($mediaUrl === '') out(['ok'=>false, 'error'=>'آدرس رسانه لازم است']);
        if (!class_exists('Ai')) out(['ok'=>false, 'error'=>'ماژول AI بارگذاری نشده']);
        $r = $kind === 'audio'
            ? Ai::transcribeAudio($mediaUrl)
            : Ai::analyzeImage($mediaUrl, (string)($in['prompt'] ?? ''));
        out($r);

    // ============ فروش (نسخه‌ی اول داخلی: کاتالوگ + سفارش) ============
    case 'instagram_products_list':
        out(['ok'=>true, 'rows'=>IgSales::products(false)]);
    case 'instagram_products_save':
        $id = IgSales::saveProduct($in);
        out(['ok'=>true, 'id'=>$id]);
    case 'instagram_products_delete':
        IgSales::deleteProduct((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'instagram_orders_list':
        out(['ok'=>true, 'rows'=>IgSales::orders($in['status'] ?? null)]);
    case 'instagram_orders_items':
        out(['ok'=>true, 'rows'=>IgSales::orderItems((int)($in['order_id'] ?? 0))]);
    case 'instagram_orders_create':
        $items = json_decode((string)($in['items_json'] ?? '[]'), true);
        if (!is_array($items) || !$items) out(['ok'=>false, 'error'=>'حداقل یک قلم کالا لازم است']);
        $orderId = IgSales::createOrder(($in['person_id'] ?? '') !== '' ? (int)$in['person_id'] : null, $items, (string)($in['notes'] ?? ''));
        out(['ok'=>true, 'id'=>$orderId]);
    case 'instagram_orders_status':
        IgSales::setStatus((int)($in['id'] ?? 0), (string)($in['status'] ?? ''));
        out(['ok'=>true]);
    case 'instagram_orders_delete':
        IgSales::deleteOrder((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ لاگ خام سرویس‌دهنده — همه‌ی JSONهای رفت‌وبرگشتی (ارسال، پست، فالو، وب‌هوک ورودی) ============
    case 'instagram_mgmt_raw_log':
        adminOnly();
        $rows = class_exists('ApiLog') ? array_merge(ApiLog::all(100, 'instagram_roboclick'), ApiLog::all(100, 'instagram_apichi')) : [];
        usort($rows, fn($a, $b) => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        out(['ok'=>true, 'rows'=>array_slice($rows, 0, 150)]);
    case 'instagram_mgmt_raw_log_clear':
        adminOnly();
        if (class_exists('ApiLog')) { ApiLog::clearChannel('instagram_roboclick'); ApiLog::clearChannel('instagram_apichi'); }
        out(['ok'=>true]);

    case 'email_get':
        adminOnly();
        out([
            'ok'=>true,
            'host'=>Database::one("SELECT v FROM eb_settings WHERE k='channel.email.host'")['v'] ?? '',
            'port'=>Database::one("SELECT v FROM eb_settings WHERE k='channel.email.port'")['v'] ?? '587',
            'user'=>Database::one("SELECT v FROM eb_settings WHERE k='channel.email.user'")['v'] ?? '',
            'from'=>Database::one("SELECT v FROM eb_settings WHERE k='channel.email.from'")['v'] ?? '',
            'secure'=>Database::one("SELECT v FROM eb_settings WHERE k='channel.email.secure'")['v'] ?? 'tls',
            'has_pass'=>!empty(Database::one("SELECT v FROM eb_settings WHERE k='channel.email.pass'")['v']),
        ]);
    case 'email_save':
        adminOnly();
        $cfg = [
            'host'=>trim($in['host'] ?? ''),
            'port'=>(int)($in['port'] ?? 587),
            'user'=>trim($in['user'] ?? ''),
            'from'=>trim($in['from'] ?? ''),
            'secure'=>$in['secure'] ?? 'tls',
        ];
        if (!empty($in['pass'])) $cfg['pass'] = $in['pass']; // فقط اگر رمز جدید داده شد
        ChannelManager::saveEmail($cfg);
        out(['ok'=>true]);
    case 'email_test':
        adminOnly();
        $to = trim($in['to'] ?? '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) out(['ok'=>false,'error'=>'ایمیل مقصد نامعتبر']);
        $driver = ChannelManager::get('email');
        if (!$driver) out(['ok'=>false,'error'=>'ایمیل تنظیم نشده']);
        $r = $driver->send(['email'=>$to], ['subject'=>'تست اعلان‌بان', 'text'=>'این یک ایمیل تستی از اعلان‌بان است.']);
        out($r);

    // ============ پروکسی ضدفیلترینگ (مورد ۳) ============
    case 'proxy_settings':
        adminOnly();
        out(array_merge(['ok'=>true], NetProxy::publicSettings()));
    case 'proxy_save':
        adminOnly();
        NetProxy::save([
            'enabled'  => !empty($in['enabled']) ? '1' : '0',
            'type'     => array_key_exists(($in['type'] ?? ''), NetProxy::TYPES) ? (string)$in['type'] : 'http',
            'host'     => trim((string)($in['host'] ?? '')),
            'port'     => trim((string)($in['port'] ?? '')),
            'username' => trim((string)($in['username'] ?? '')),
            'password' => (string)($in['password'] ?? ''),   // خالی = دست‌نزن
            'targets'  => trim((string)($in['targets'] ?? '')),
        ]);
        out(['ok'=>true]);
    case 'proxy_test':
        adminOnly();
        $target = (string)($in['target'] ?? 'telegram');
        $r = NetProxy::test($target);
        out(array_merge(['ok'=>!empty($r['ok'])], $r));

    // ============ پیام‌های آماده‌ی تیم پشتیبانی (مورد ۶-۱) ============
    case 'canned_list':
        out(['ok'=>true, 'items'=>Canned::all(), 'categories'=>Canned::categories(), 'ready'=>Canned::hasTable() ? 1 : 0]);
    case 'canned_save':
        try {
            $id = Canned::save([
                'title'      => $in['title'] ?? '',
                'body'       => $in['body'] ?? '',
                'category'   => $in['category'] ?? '',
                'sort_order' => $in['sort_order'] ?? 0,
            ], !empty($in['id']) ? (int)$in['id'] : null, Auth::id());
            out(['ok'=>true, 'id'=>$id]);
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }
    case 'canned_delete':
        Canned::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'canned_get':
        // متن یک پیام آماده، شخصی‌سازی‌شده برای مشتریِ گفتگوی باز (اگر person_id بیاید)
        $cid = (int)($in['id'] ?? 0);
        if (!$cid) out(['ok'=>false,'error'=>'شناسه‌ی پیام آماده مشخص نیست']);
        $pid = !empty($in['person_id']) ? (int)$in['person_id'] : null;
        if ($pid) inbox_assert_access($pid);
        $text = Canned::textFor($cid, $pid);
        if ($text === null) out(['ok'=>false,'error'=>'یافت نشد']);
        out(['ok'=>true, 'text'=>$text]);

    // ============ چت داخلی تیم (مورد ۶-۲) ============
    case 'teamchat_users':
        $me = (int)Auth::id();
        $users = TeamChat::users($me);
        $unread = TeamChat::unreadCounts($me);
        foreach ($users as &$u) { $u['unread'] = $unread[(int)$u['id']] ?? 0; }
        unset($u);
        out(['ok'=>true, 'users'=>$users, 'total_unread'=>TeamChat::totalUnread($me), 'me'=>$me, 'ready'=>TeamChat::hasTable() ? 1 : 0]);
    case 'teamchat_thread':
        $me = (int)Auth::id();
        $other = (int)($in['other_id'] ?? 0);
        $msgs = TeamChat::thread($me, $other);
        TeamChat::markRead($me, $other);
        out(['ok'=>true, 'messages'=>$msgs, 'me'=>$me]);
    case 'teamchat_send':
        $me = (int)Auth::id();
        $other = (int)($in['other_id'] ?? 0);
        try {
            $id = TeamChat::send($me, $other, (string)($in['body'] ?? ''));
            out(['ok'=>true, 'id'=>$id]);
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }
    case 'teamchat_unread':
        out(['ok'=>true, 'total_unread'=>TeamChat::totalUnread((int)Auth::id())]);

    // ============ ۲.۱.۱: مدیریت چندسایته ============
    case 'sites_list':
        adminOnly();
        $base = app_base_url($config);
        $rows = [];
        foreach (SiteCtx::all(true) as $st) {
            $rows[] = $st + ['embed' => SiteCtx::embed($st, $base), 'stats' => SiteCtx::stats((int)$st['id']),
                             'chat_on' => (function () use ($st) { $p = SiteCtx::id(); SiteCtx::set((int)$st['id']); $v = Webchat::isEnabled(); SiteCtx::set($p); return $v; })(),
                             'pulse_on' => (function () use ($st) { $p = SiteCtx::id(); SiteCtx::set((int)$st['id']); $v = AnalyticsSettings::isActive(); SiteCtx::set($p); return $v; })()];
        }
        out(['ok'=>true, 'sites'=>$rows, 'current'=>SiteCtx::id(), 'base'=>$base]);
    case 'site_save':
        adminOnly();
        try {
            $sid = SiteCtx::save($in, !empty($in['id']) ? (int)$in['id'] : null);
            out(['ok'=>true, 'id'=>$sid]);
        } catch (Throwable $e) { out(['ok'=>false, 'error'=>$e->getMessage()]); }
    case 'site_delete':
        adminOnly();
        try { SiteCtx::delete((int)($in['id'] ?? 0)); out(['ok'=>true]); }
        catch (Throwable $e) { out(['ok'=>false, 'error'=>$e->getMessage()]); }
    case 'site_rotate_key':
        adminOnly();
        if (!SiteCtx::find((int)($in['id'] ?? 0))) out(['ok'=>false, 'error'=>'سایت پیدا نشد']);
        out(['ok'=>true, 'key'=>SiteCtx::rotateKey((int)$in['id'])]);
    case 'site_copy':
        adminOnly();
        try { out(['ok'=>true, 'copied'=>SiteCtx::copySettings((int)($in['from'] ?? 0), (int)($in['to'] ?? 0))]); }
        catch (Throwable $e) { out(['ok'=>false, 'error'=>$e->getMessage()]); }

    // ============ اعلان‌های سایت — پاپ‌آپ/نوار/اسلاید-این (مورد ۷) ============
    case 'notices_list':
        adminOnly();
        $base = app_base_url($config);
        $curSite = SiteCtx::find(SiteCtx::id());
        out(['ok'=>true, 'items'=>Notices::all(), 'ready'=>Notices::hasTable() ? 1 : 0,
             'embed'=>$curSite ? SiteCtx::embed($curSite, $base)['notices'] : '<script src="' . htmlspecialchars(rtrim($base,'/') . '/eb-embed.js', ENT_QUOTES, 'UTF-8') . '" async></script>',
             'multi'=>SiteCtx::multi(), 'site_name'=>$curSite['name'] ?? '']);
    case 'notices_save':
        adminOnly();
        try {
            $id = Notices::save([
                'title'         => $in['title'] ?? '',
                'type'          => $in['type'] ?? 'popup',
                'headline'      => $in['headline'] ?? '',
                'body'          => $in['body'] ?? '',
                'image_url'     => $in['image_url'] ?? '',
                'button_text'   => $in['button_text'] ?? '',
                'button_url'    => $in['button_url'] ?? '',
                'bg_color'      => $in['bg_color'] ?? '',
                'text_color'    => $in['text_color'] ?? '',
                'accent_color'  => $in['accent_color'] ?? '',
                'corner_radius' => $in['corner_radius'] ?? 16,
                'font_family'   => $in['font_family'] ?? '',
                'delay_seconds' => $in['delay_seconds'] ?? 3,
                'frequency'     => $in['frequency'] ?? 'once_per_session',
                'url_contains'  => $in['url_contains'] ?? '',
                'url_exclude'   => $in['url_exclude'] ?? '',
                'starts_at'     => $in['starts_at'] ?? '',
                'ends_at'       => $in['ends_at'] ?? '',
                'priority'      => $in['priority'] ?? 0,
                'is_active'     => $in['is_active'] ?? 1,
                'capture_phone' => $in['capture_phone'] ?? 0,
                'phone_label'   => $in['phone_label'] ?? '',
                'phone_button_text' => $in['phone_button_text'] ?? '',
                'discount_mode' => $in['discount_mode'] ?? 'none',
                'discount_static_code' => $in['discount_static_code'] ?? '',
                'discount_prefix' => $in['discount_prefix'] ?? '',
                'success_headline' => $in['success_headline'] ?? '',
                'success_note'  => $in['success_note'] ?? '',
                'site_scope'    => $in['site_scope'] ?? '',
            ], !empty($in['id']) ? (int)$in['id'] : null);
            out(['ok'=>true, 'id'=>$id]);
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }
    case 'notices_delete':
        adminOnly();
        Notices::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'notices_leads':
        adminOnly();
        out(['ok'=>true, 'leads'=>Notices::leads((int)($in['notice_id'] ?? 0), 300)]);

    // ============ آنالیتیکس (مورد ۲ و ۳ — تعامل/لینک، همیشه در دسترس) ============
    case 'analytics_engagement':
        $days = max(1, min(365, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + Analytics::engagement($days, (int)($in['limit'] ?? 100)));
    case 'analytics_links':
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + Analytics::linkClicksOverview($days));

    // ============ آنالیتیکس وب/رفتار (فاز ۱+۲ — پشت قفل فعال‌سازی) ============
    case 'analytics_overview':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true, 'today'=>AnalyticsReports::todaySummary()] + AnalyticsReports::overview($days));
    case 'analytics_realtime':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        out(['ok'=>true] + AnalyticsReports::realtime());
    case 'analytics_pages_sources':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + AnalyticsReports::pagesAndSources($days));
    case 'analytics_audience':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + AnalyticsReports::audience($days));
    case 'analytics_heatmap_pages':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true, 'pages'=>AnalyticsReports::heatmapPages($days)]);
    case 'analytics_heatmap':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $url = trim((string)($in['page_url'] ?? ''));
        if ($url === '') out(['ok'=>false, 'error'=>'صفحه مشخص نشده']);
        $bucket = ($in['bucket'] ?? 'desktop') === 'mobile' ? 'mobile' : 'desktop';
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + AnalyticsReports::heatmapFor($url, $bucket, $days));
    case 'analytics_problem_spots':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        out(['ok'=>true, 'rows'=>AnalyticsReports::problemSpots(20)]);

    case 'analytics_sessions_list':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 7)));
        out(['ok'=>true, 'sessions'=>AnalyticsReports::recentSessions($days, 150)]);
    case 'analytics_session_detail':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $detail = AnalyticsReports::sessionDetail((int)($in['id'] ?? 0));
        if (!$detail) out(['ok'=>false, 'error'=>'نشست پیدا نشد']);
        out(['ok'=>true] + $detail);

    case 'analytics_funnels':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        out(['ok'=>true, 'funnels'=>AnalyticsReports::funnels()]);
    case 'analytics_funnel_save':
        adminOnly();
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $name = trim((string)($in['name'] ?? ''));
        $steps = json_decode((string)($in['steps'] ?? '[]'), true);
        if ($name === '' || !is_array($steps)) out(['ok'=>false, 'error'=>'ورودی نامعتبر']);
        try {
            $id = AnalyticsReports::saveFunnel(!empty($in['id']) ? (int)$in['id'] : null, $name, $steps);
            out(['ok'=>true, 'id'=>$id]);
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }
    case 'analytics_funnel_delete':
        adminOnly();
        AnalyticsReports::deleteFunnel((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'analytics_funnel_stats':
        if (!AnalyticsSettings::isActive()) out(['ok'=>false, 'error'=>'ماژول فعال نیست']);
        $days = max(1, min(180, (int)($in['days'] ?? 30)));
        out(['ok'=>true] + AnalyticsReports::funnelStats((int)($in['id'] ?? 0), $days));

    case 'analytics_settings_get':
        adminOnly();
        $base = app_base_url($config);
        out([
            'ok' => true,
            'settings' => AnalyticsSettings::publicSettings(),
            'embed' => ($__s = SiteCtx::find(SiteCtx::id())) ? SiteCtx::embed($__s, $base)['pulse'] : '<script src="' . htmlspecialchars(rtrim($base, '/') . '/eb-pulse.js', ENT_QUOTES, 'UTF-8') . '" async></script>',
        ]);
    case 'analytics_settings_save':
        adminOnly();
        AnalyticsSettings::save([
            'enabled' => ($in['enabled'] ?? '0') === '1' ? '1' : '0',
            'site_url' => rtrim(trim((string)($in['site_url'] ?? '')), '/'),
            'sampling_heatmap' => $in['sampling_heatmap'] ?? '20',
            'sampling_move' => $in['sampling_move'] ?? '5',
            'retention_days_raw' => $in['retention_days_raw'] ?? '90',
            'retention_days_sessions' => $in['retention_days_sessions'] ?? '180',
            'cookieless' => ($in['cookieless'] ?? '0') === '1' ? '1' : '0',
        ]);
        out(['ok'=>true]);
    case 'analytics_run_rollup_now':
        adminOnly();
        out(['ok'=>true, 'result'=>AnalyticsRollup::run(true)]);
    case 'analytics_geoip_test':
        adminOnly();
        $ip = class_exists('Links') ? Links::clientIp(!empty($config['trust_proxy'])) : ($_SERVER['REMOTE_ADDR'] ?? null);
        $rawRemote = $_SERVER['REMOTE_ADDR'] ?? null;
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $geo = GeoIp::lookup($ip);
        out([
            'ok' => true,
            'trust_proxy' => !empty($config['trust_proxy']),
            'detected_ip' => $ip,
            'remote_addr' => $rawRemote,
            'x_forwarded_for' => $xff,
            'geo' => $geo,
            'warning' => (!$geo && $ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
                ? 'این IP یک آدرس خصوصی/رزرو‌شده است (نه یک IP عمومی واقعی) — به‌احتمال زیاد سایتت پشت پروکسی/Nginx/Cloudflare است و باید trust_proxy را در config.php روی true بگذاری.'
                : null,
        ]);

    // ============ فرم/نظرسنجی ============
    case 'forms_list':
        out(['ok'=>true, 'forms'=>Forms::all()]);
    case 'form_save':
        adminOnly();
        $fid = !empty($in['id']) ? (int)$in['id'] : null;
        if (trim($in['name'] ?? '') === '') out(['ok'=>false,'error'=>'نام فرم لازم است']);
        $qs = $in['questions'] ?? [];
        if (!is_array($qs) || !$qs) out(['ok'=>false,'error'=>'حداقل یک سؤال لازم است']);
        // نرمال‌سازی سؤالات
        $clean = [];
        foreach ($qs as $q) {
            $qt = trim(is_array($q) ? ($q['q'] ?? '') : (string)$q);
            if ($qt !== '') $clean[] = ['q'=>$qt];
        }
        if (!$clean) out(['ok'=>false,'error'=>'حداقل یک سؤال معتبر لازم است']);
        $id = Forms::save([
            'name'=>$in['name'],
            'trigger_keyword'=>$in['trigger_keyword'] ?? '',
            'questions_json'=>json_encode($clean, JSON_UNESCAPED_UNICODE),
            'finish_text'=>$in['finish_text'] ?? 'با تشکر، پاسخ شما ثبت شد.',
            'finish_image'=>(!empty($in['finish_image']) && is_file($in['finish_image'])) ? $in['finish_image'] : ($in['finish_image_keep'] ?? null),
            'is_active'=>isset($in['is_active']) ? (!empty($in['is_active'])?1:0) : 1,
        ], $fid);
        out(['ok'=>true, 'id'=>$id]);
    case 'form_delete':
        adminOnly();
        Forms::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'form_responses':
        $fid = (int)($in['id'] ?? 0);
        out(['ok'=>true, 'form'=>Forms::find($fid), 'responses'=>Forms::responses($fid)]);

    // ============ بات‌ساز (منوی چندمرحله‌ای) ============
    case 'botnodes_list':
        out(['ok'=>true, 'nodes'=>BotMenu::all()]);
    case 'botnode_save':
        adminOnly();
        $nid = !empty($in['id']) ? (int)$in['id'] : null;
        $key = trim($in['node_key'] ?? '');
        if ($key === '') out(['ok'=>false,'error'=>'کلید گره لازم است (مثلاً main)']);
        if (!preg_match('/^[a-z0-9_]+$/i', $key)) out(['ok'=>false,'error'=>'کلید فقط حروف/عدد/زیرخط (مثلاً support_2)']);
        $btns = $in['buttons'] ?? [];
        $rules = $in['rules'] ?? [];
        $id = BotMenu::save([
            'node_key'=>$key,
            'channel'=>$in['channel'] ?? null,
            'title'=>$in['title'] ?? '',
            'text'=>$in['text'] ?? '',
            'buttons_json'=>is_array($btns) ? json_encode($btns, JSON_UNESCAPED_UNICODE) : ($btns ?: null),
            'rules_json'=>is_array($rules) ? json_encode(array_values(array_filter($rules, fn($r)=>!empty($r['contains'])&&!empty($r['goto']))), JSON_UNESCAPED_UNICODE) : ($rules ?: null),
            'is_root'=>!empty($in['is_root']) ? 1 : 0,
            'awaits_property_field'=>!empty($in['awaits_property_field']) ? (string)$in['awaits_property_field'] : null,
            'cards_json'=>$in['cards'] ?? null,
            'site_scope'=>$in['site_scope'] ?? '',
        ], $nid);
        out(['ok'=>true, 'id'=>$id]);
    case 'botnode_delete':
        adminOnly();
        BotMenu::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'botnodes_import':
        adminOnly();
        $nodes = $in['nodes'] ?? null;
        if (is_string($nodes)) $nodes = json_decode($nodes, true);
        if (!is_array($nodes) || !$nodes) out(['ok'=>false, 'error'=>'JSON نامعتبر یا خالی است']);
        $r = BotMenu::importScenario($nodes);
        out(array_merge(['ok'=>true], $r));

    // ============ لوکاپ خودکار اقامتگاه (مورد ۱ فلوچارت) ============
    case 'properties_list':
        out(['ok'=>true, 'rows'=>PropertyLookup::all()]);
    case 'property_save':
        $id = PropertyLookup::save($in);
        out(['ok'=>true, 'id'=>$id]);
    case 'property_delete':
        PropertyLookup::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    case 'bot_simulate':
        // شبیه‌سازی پاسخ بات (تست داخل پنل، بدون ارسال واقعی)
        $sim = BotMenu::simulate($in['channel'] ?? null, [
            'text'     => $in['text'] ?? null,
            'callback' => $in['callback'] ?? null,
        ]);
        if (!$sim) out(['ok'=>true, 'matched'=>false]);
        out(['ok'=>true, 'matched'=>true, 'node'=>$sim['node'], 'message'=>$sim['message']]);

    // ============ لینک رهگیری ============
    case 'links_list':
        $base = app_base_url($config);
        $links = Links::all([
            'category' => $in['category'] ?? '',
            'q'        => $in['q'] ?? '',
            'kind'     => $in['kind'] ?? '',
        ]);
        foreach ($links as &$l) {
            $l['short_url'] = "{$base}/r.php?c={$l['code']}";
            if (($l['kind'] ?? '') === 'tel') $l['tel'] = Links::telDisplay($l['target_url']);
        }
        unset($l);
        out([
            'ok'         => true,
            'links'      => $links,
            'categories' => Links::categories(),
            'suggested'  => Links::SUGGESTED,
        ]);
    case 'link_create':
        $r = Links::create($in['target_url'] ?? '', $in['title'] ?? null, $in['category'] ?? null);
        if (!$r['ok']) out($r);
        $base = app_base_url($config);
        out(['ok'=>true, 'id'=>$r['id'], 'code'=>$r['code'], 'kind'=>$r['kind'],
             'short_url'=>"{$base}/r.php?c={$r['code']}"]);
    case 'link_update':
        // مورد ۴: ویرایش عنوان/مقصد/دسته. کد کوتاه عوض نمی‌شود تا لینک‌های پخش‌شده نشکنند.
        $patch = [];
        foreach (['title','target_url','category'] as $f) {
            if (array_key_exists($f, $in)) $patch[$f] = $in[$f];
        }
        $r = Links::update((int)($in['id'] ?? 0), $patch);
        out($r);
    case 'link_detail':
        $d = Links::detail((int)($in['id'] ?? 0));
        if (!$d) out(['ok'=>false,'error'=>'یافت نشد']);
        $base = app_base_url($config);
        $d['short_url'] = "{$base}/r.php?c={$d['code']}";
        if (($d['kind'] ?? '') === 'tel') $d['tel'] = Links::telDisplay($d['target_url']);
        // مورد ۶: روند روزانه‌ی کلیک (لینک‌های دائمی مثل دکمه‌ی تماس، عدد جمعی کمکی نیست)
        $d['daily'] = Links::dailyClicks((int)$d['id'], (int)($in['days'] ?? 30));
        out(['ok'=>true, 'link'=>$d]);
    case 'link_delete':
        Links::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);

    // ============ هوش مصنوعی + وضعیت پشتیبانی (مورد ۱۲) ============
    case 'ai_settings':
        adminOnly();
        out([
            'ok'       => true,
            'ai'       => Ai::publicSettings(),
            'mode'     => Ai::mode(),
            'support'  => Support::status(),
            'timezones'=> ['Asia/Tehran','UTC','Asia/Dubai','Europe/Istanbul'],
        ]);
    case 'ai_save':
        adminOnly();
        Ai::save([
            'enabled'      => !empty($in['enabled']) ? '1' : '0',
            'system'       => (string)($in['system'] ?? ''),
            'temperature'  => (string)($in['temperature'] ?? '0.3'),
            'max_tokens'   => (string)((int)($in['max_tokens'] ?? 400)),
            'timeout'      => (string)((int)($in['timeout'] ?? 20)),
            'history'      => (string)((int)($in['history'] ?? 8)),
            'auto_offline' => !empty($in['auto_offline']) ? '1' : '0',
            'rate_hour'    => (string)((int)($in['rate_hour'] ?? 10)),
            'insecure'     => !empty($in['insecure']) ? '1' : '0',
            'signature'    => (string)($in['signature'] ?? ''),
        ]);
        // پروایدرها: هر کدام { enabled, api_key, model, base_url, priority }
        $rawProv = $in['providers'] ?? [];
        if (is_string($rawProv)) $rawProv = json_decode($rawProv, true) ?: [];
        if (is_array($rawProv)) {
            $clean = [];
            foreach ($rawProv as $pk => $pv) {
                if (!is_array($pv)) continue;
                $clean[(string)$pk] = [
                    'enabled'  => !empty($pv['enabled']) ? 1 : 0,
                    'api_key'  => (string)($pv['api_key'] ?? ''),
                    'model'    => trim((string)($pv['model'] ?? '')),
                    'base_url' => trim((string)($pv['base_url'] ?? '')),
                    'priority' => (int)($pv['priority'] ?? 9),
                ];
            }
            if ($clean) Ai::saveProviders($clean);
        }
        out(['ok'=>true, 'ready'=>Ai::isReady() ? 1 : 0, 'mode'=>Ai::mode(), 'providers'=>Ai::publicProviders()]);
    case 'ai_key_clear':
        adminOnly();
        Ai::clearKey(!empty($in['provider']) ? (string)$in['provider'] : null);
        out(['ok'=>true]);
    case 'ai_test':
        adminOnly();
        $r = Ai::test();
        out(['ok'=>!empty($r['ok']), 'text'=>$r['text'] ?? null, 'error'=>$r['error'] ?? null,
             'code'=>$r['code'] ?? null, 'provider'=>$r['provider'] ?? null]);
    case 'ai_test_provider':
        adminOnly();
        $pk = (string)($in['provider'] ?? '');
        if ($pk === '') out(['ok'=>false,'error'=>'پروایدر مشخص نیست']);
        $r = Ai::testProvider($pk);
        out(['ok'=>!empty($r['ok']), 'text'=>$r['text'] ?? null, 'error'=>$r['error'] ?? null, 'code'=>$r['code'] ?? null, 'status'=>Ai::providerStatus($pk)]);

    // ۱.۰.۱۴: فهرست مدل‌های پروایدر (AvalAI و…)، گزارش مصرف و هزینه، قیمت مدل‌ها
    case 'ai_models':
        adminOnly();
        out(Ai::listModels((string)($in['provider'] ?? ''), (string)($in['api_key'] ?? ''), (string)($in['base_url'] ?? '')));
    case 'ai_usage':
        adminOnly();
        out(['ok'=>true] + Ai::usageReport((int)($in['days'] ?? 30)));
    case 'ai_prices_save':
        adminOnly();
        $pr = $in['prices'] ?? [];
        if (is_string($pr)) $pr = json_decode($pr, true) ?: [];
        out(['ok'=>true, 'prices'=>Ai::savePrices(is_array($pr) ? $pr : [])]);

    // ============ گفتگوی آنلاین سایت (مورد ۱۳) ============
    case 'webchat_settings':
        adminOnly();
        $base = app_base_url($config);
        out([
            'ok'       => true,
            'settings' => Webchat::settings(),
            'stats'    => Webchat::stats(),
            'accent'   => Webchat::accent(),
            'online'   => Webchat::isOnline(),
            'embed'    => ($__s = SiteCtx::find(SiteCtx::id())) ? SiteCtx::embed($__s, $base)['widget'] : Webchat::embedCode($base),
            'base_url' => $base,
        ]);

    case 'webchat_save':
        adminOnly();
        Webchat::save([
            'enabled'       => !empty($in['enabled']) ? '1' : '0',
            'title'         => trim((string)($in['title'] ?? '')),
            'subtitle'      => trim((string)($in['subtitle'] ?? '')),
            'welcome'       => (string)($in['welcome'] ?? ''),
            'offline_text'  => (string)($in['offline_text'] ?? ''),
            'start_mode'    => in_array(($in['start_mode'] ?? ''), ['open','form','require_phone'], true)
                                 ? (string)$in['start_mode'] : 'open',
            'color'         => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? ''))
                                 ? (string)$in['color'] : '',
            'position'      => (($in['position'] ?? '') === 'left') ? 'left' : 'right',
            'ask_name'      => !empty($in['ask_name'])  ? '1' : '0',
            'ask_phone'     => !empty($in['ask_phone']) ? '1' : '0',
            'allowed_hosts' => (string)($in['allowed_hosts'] ?? ''),
            'poll_seconds'  => (string)max(2, min(30, (int)($in['poll_seconds'] ?? 4))),
            'mobile_bottom_offset' => (string)max(0, min(200, (int)($in['mobile_bottom_offset'] ?? 78))),
            'font_family'   => trim((string)($in['font_family'] ?? '')),
            'bg_color'      => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['bg_color'] ?? ''))
                                 ? (string)$in['bg_color'] : '',
            'text_color'    => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['text_color'] ?? ''))
                                 ? (string)$in['text_color'] : '',
            'border_radius' => (string)max(0, min(28, (int)($in['border_radius'] ?? 16))),
            'launcher_icon' => trim((string)($in['launcher_icon'] ?? '')),
            'launcher_size' => (string)max(40, min(88, (int)($in['launcher_size'] ?? 58))),
        ]);
        out(['ok'=>true, 'accent'=>Webchat::accent()]);

    case 'webchat_sessions':
        adminOnly();
        out([
            'ok'       => true,
            'sessions' => Webchat::sessions([
                'q'               => (string)($in['q'] ?? ''),
                'days'            => (int)($in['days'] ?? 0),
                'only_identified' => !empty($in['only_identified']),
            ], (int)($in['limit'] ?? 100)),
            'stats'    => Webchat::stats(),
        ]);
    case 'support_save':
        adminOnly();
        Support::save([
            'presence' => (string)($in['presence'] ?? 'auto'),
            'tz'       => (string)($in['tz'] ?? 'Asia/Tehran'),
            'days'     => is_array($in['days'] ?? null) ? implode(',', $in['days']) : (string)($in['days'] ?? ''),
            'from'     => (string)($in['from'] ?? '09:00'),
            'to'       => (string)($in['to'] ?? '18:00'),
        ]);
        out(['ok'=>true, 'support'=>Support::status(), 'mode'=>Ai::mode()]);
    case 'support_status':
        out(['ok'=>true, 'support'=>Support::status(), 'mode'=>Ai::mode(), 'ai_ready'=>Ai::isReady() ? 1 : 0]);

    // پیش‌نویس پاسخ برای اپراتور — هیچ چیزی ارسال نمی‌شود (اصل: دکمه‌ی نهایی با انسان)
    case 'ai_draft':
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'مشتری مشخص نیست']);
        inbox_assert_access($pid);
        if (!Ai::isReady()) out(['ok'=>false,'error'=>'اتصال هوش مصنوعی تنظیم یا فعال نشده است']);
        $r = Ai::draftFor($pid, $in['hint'] ?? null);
        out(['ok'=>!empty($r['ok']), 'text'=>$r['text'] ?? null, 'error'=>$r['error'] ?? null]);

    // ارجاع دستی گفتگو به AI و بازگرداندنش به انسان
    case 'inbox_ai_assign':
    case 'inbox_ai_unassign':
        $pid = (int)($in['person_id'] ?? 0);
        if (!$pid) out(['ok'=>false,'error'=>'مشتری مشخص نیست']);
        inbox_assert_access($pid);
        if (!Support::hasHandoff()) {
            out(['ok'=>false,'error'=>'ابتدا «به‌روزرسانی دیتابیس» را در تنظیمات کانال‌ها بزنید']);
        }
        if ($api === 'inbox_ai_assign') {
            if (!(class_exists('SalesAgent') ? SalesAgent::aiOrAgent() : Ai::isReady())) out(['ok'=>false,'error'=>'اتصال هوش مصنوعی تنظیم یا فعال نشده است']);
            if (ChatAssign::ready()) {
                $r = ChatAssign::transfer($pid, 'ai', (int)Auth::id(), null);
                if (empty($r['ok'])) out($r);
            } else Support::assignAi($pid);
            out(['ok'=>true, 'assigned'=>1, 'assign'=>ChatAssign::state($pid, (int)Auth::id())]);
        }
        Support::unassignAi($pid);
        // پس گرفتن از AI = بر عهده گرفتن توسط همین کاربر
        if (ChatAssign::ready()) ChatAssign::claim($pid, (int)Auth::id(), Auth::isManager());
        out(['ok'=>true, 'assigned'=>0, 'assign'=>ChatAssign::state($pid, (int)Auth::id())]);

    // ---- پایگاه دانش هوش مصنوعی (منبع پاسخ‌گویی دستیار) ----
    case 'aikb_list':
        adminOnly();
        out(['ok'=>true, 'items'=>AiKb::all(), 'ready'=>AiKb::hasTable() ? 1 : 0]);
    case 'aikb_save':
        adminOnly();
        try {
            $id = AiKb::save([
                'title'     => $in['title'] ?? '',
                'content'   => $in['content'] ?? '',
                'keywords'  => $in['keywords'] ?? '',
                'pinned'    => !empty($in['pinned']),
                'is_active' => isset($in['is_active']) ? !empty($in['is_active']) : true,
            ], !empty($in['id']) ? (int)$in['id'] : null);
            out(['ok'=>true, 'id'=>$id]);
        } catch (InvalidArgumentException $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }
    case 'aikb_delete':
        adminOnly();
        AiKb::delete((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'aikb_import':
        adminOnly();
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt','md','csv'], true)) {
            out(['ok'=>false,'error'=>'فقط فایل متنی (txt/md/csv) پشتیبانی می‌شود. PDF/Word را اول به متن تبدیل کنید.']);
        }
        if (($_FILES['file']['size'] ?? 0) > 2*1024*1024) out(['ok'=>false,'error'=>'حجم فایل نباید از ۲ مگابایت بیشتر باشد']);
        $content = file_get_contents($_FILES['file']['tmp_name']);
        if ($content === false || trim($content) === '') out(['ok'=>false,'error'=>'فایل خالی است یا خوانده نشد']);
        try {
            $srcTitle = pathinfo($_FILES['file']['name'], PATHINFO_FILENAME);
            $n = AiKb::importText($content, mb_substr((string)$srcTitle, 0, 60));
            out(['ok'=>true, 'imported'=>$n]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }

    // ============ مدیریت اپراتورها (فقط admin) ============
    case 'operators_list':
        adminOnly();
        OrgRoles::ensure();
        $ops = Database::all("SELECT id, username, display_name, " . Auth::nameSql('') . " AS name, role, permissions_json, is_active, created_at" . (Database::hasColumn('eb_users', 'role_id') ? ", role_id" : "") . " FROM eb_users ORDER BY id");
        out(['ok'=>true, 'operators'=>$ops]);
    // ============ چارت سازمانی و نقش‌ها (۲.۰.۹) ============
    case 'roles_list':
        adminOnly();
        $rs = OrgRoles::all();
        $mem = Database::hasColumn('eb_users', 'role_id') ? Database::all("SELECT id, " . Auth::nameSql('') . " AS name, role_id FROM eb_users WHERE role_id IS NOT NULL AND is_active=1") : [];
        foreach ($rs as &$r) { $r['members'] = array_values(array_map(function ($m) { return ['id'=>(int)$m['id'], 'name'=>$m['name']]; }, array_filter($mem, function ($m) use ($r) { return (int)$m['role_id'] === $r['id']; }))); $r['effective_count'] = count(OrgRoles::effectivePages($r['id'])); }
        unset($r);
        out(['ok'=>true, 'roles'=>$rs]);
    case 'role_save':
        adminOnly();
        out(OrgRoles::save($in));
    case 'role_delete':
        adminOnly();
        out(OrgRoles::delete((int)($in['id'] ?? 0)));
    case 'role_seed':
        adminOnly();
        out(OrgRoles::seed());
    case 'operator_create':
        adminOnly();
        $u = trim($in['username'] ?? '');
        $pw = (string)($in['password'] ?? '');
        if ($u === '' || strlen($pw) < 4) out(['ok'=>false,'error'=>'نام کاربری و رمز (حداقل ۴ کاراکتر) لازم است']);
        if (Database::one("SELECT id FROM eb_users WHERE username=?", [$u])) out(['ok'=>false,'error'=>'این نام کاربری موجود است']);
        $perms = ['pages'=>$in['pages'] ?? [], 'groups'=>array_map('intval', $in['groups'] ?? []), 'can_delete_customers'=>!empty($in['can_delete_customers'])];
        $newRole = (($in['role'] ?? '') === 'supervisor' && Database::hasColumn('eb_persons', 'assigned_user_id')) ? 'supervisor' : 'operator';
        if (($in['role'] ?? '') === 'viewer') {
            Viewer::ensureRole();
            $newRole = 'viewer';
            $perms = ['pages'=>[], 'groups'=>[], 'can_delete_customers'=>false, 'mask_pii'=>!array_key_exists('mask_pii', $in) || !empty($in['mask_pii'])];
        }
        Database::run(
            "INSERT INTO eb_users (username, password_hash, role, permissions_json, is_active) VALUES (?, ?, ?, ?, 1)",
            [$u, password_hash($pw, PASSWORD_BCRYPT), $newRole, json_encode($perms, JSON_UNESCAPED_UNICODE)]
        );
        $newId = Database::lastId();
        if (array_key_exists('role_id', $in) && $newRole !== 'viewer') OrgRoles::setUserRole($newId, !empty($in['role_id']) ? (int)$in['role_id'] : null);
        Cache::forget('users_names');
        out(['ok'=>true, 'id'=>$newId]);
    case 'operator_update':
        adminOnly();
        $oid = (int)($in['id'] ?? 0);
        $op = Database::one("SELECT * FROM eb_users WHERE id=?", [$oid]);
        if (!$op) out(['ok'=>false,'error'=>'یافت نشد']);
        if ($op['role'] === 'admin') out(['ok'=>false,'error'=>'مدیر قابل محدودسازی نیست']);
        $perms = ['pages'=>$in['pages'] ?? [], 'groups'=>array_map('intval', $in['groups'] ?? []), 'can_delete_customers'=>!empty($in['can_delete_customers'])];
        if (($in['role'] ?? '') === 'viewer') {
            Viewer::ensureRole();
            $perms = ['pages'=>[], 'groups'=>[], 'can_delete_customers'=>false, 'mask_pii'=>!array_key_exists('mask_pii', $in) || !empty($in['mask_pii'])];
            Database::run("UPDATE eb_users SET role='viewer' WHERE id=?", [$oid]);
        }
        Database::run("UPDATE eb_users SET permissions_json=?, is_active=? WHERE id=?",
            [json_encode($perms, JSON_UNESCAPED_UNICODE), !empty($in['is_active']) ? 1 : 0, $oid]);
        if (isset($in['role']) && in_array($in['role'], ['operator','supervisor'], true) && Database::hasColumn('eb_persons', 'assigned_user_id')) {
            Database::run("UPDATE eb_users SET role=? WHERE id=?", [$in['role'], $oid]);
        }
        if (array_key_exists('role_id', $in)) OrgRoles::setUserRole($oid, ($in['role'] ?? '') === 'viewer' || empty($in['role_id']) ? null : (int)$in['role_id']);
        Cache::forget('users_names');
        if (!empty($in['password']) && strlen($in['password']) >= 4) {
            Database::run("UPDATE eb_users SET password_hash=? WHERE id=?", [password_hash($in['password'], PASSWORD_BCRYPT), $oid]);
        }
        out(['ok'=>true]);
    case 'operator_delete':
        adminOnly();
        $oid = (int)($in['id'] ?? 0);
        $op = Database::one("SELECT role FROM eb_users WHERE id=?", [$oid]);
        if ($op && $op['role'] === 'admin') out(['ok'=>false,'error'=>'مدیر قابل حذف نیست']);
        Database::run("DELETE FROM eb_users WHERE id=? AND role IN ('operator','supervisor','viewer')", [$oid]);
        if (Database::hasColumn('eb_persons', 'assigned_user_id')) Database::run("UPDATE eb_persons SET assigned_user_id=NULL, assigned_at=NULL WHERE assigned_user_id=?", [$oid]);
        Cache::forget('users_names');
        out(['ok'=>true]);

    // ============ لاگ API ============
    case 'apilog_list':
        adminOnly();
        out(['ok'=>true, 'logs'=>ApiLog::all(150, $in['channel'] ?? null)]);
    case 'apilog_clear':
        adminOnly();
        ApiLog::clear();
        out(['ok'=>true]);

    // ============ تنظیمات عمومی ============
    case 'settings_get':
        $cronKey = Database::one("SELECT v FROM eb_settings WHERE k='cron.key'")['v'] ?? '';
        $company = Database::one("SELECT v FROM eb_settings WHERE k='company.name'")['v'] ?? '';
        $base = app_base_url($config);
        out([
            'ok'=>true,
            'cron_key'=>$cronKey,
            'company'=>$company,
            'brand'=>Brand::all(),
            'cron_url'=>$cronKey ? "{$base}/cron.php?key={$cronKey}" : '',
            'channels_configured'=>array_values(array_filter(ChannelManager::available(), fn($c)=>ChannelManager::isConfigured($c))),
        ]);
    case 'settings_save':
        adminOnly();
        if (isset($in['company'])) {
            Database::run("INSERT INTO eb_settings (k,v) VALUES ('company.name',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [trim($in['company'])]);
        }
        out(['ok'=>true]);

    case 'brand_save':
        adminOnly();
        $vals = [];
        if (isset($in['name']))    $vals['name'] = mb_substr(trim($in['name']), 0, 60);
        if (isset($in['tagline'])) $vals['tagline'] = mb_substr(trim($in['tagline']), 0, 120);
        if (isset($in['accent'])) {
            $acc = trim($in['accent']);
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $acc)) out(['ok'=>false,'error'=>'رنگ نامعتبر است (مثلاً #1d4ed8)']);
            $vals['accent'] = $acc;
        }
        if (array_key_exists('logo', $in)) $vals['logo'] = trim((string)$in['logo']); // مسیر یا خالی برای حذف
        Brand::save($vals);
        out(['ok'=>true,'brand'=>Brand::all()]);

    case 'brand_logo_upload':
        adminOnly();
        if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            out(['ok'=>false,'error'=>'فایلی آپلود نشد']);
        }
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) {
            out(['ok'=>false,'error'=>'فقط عکس (jpg/png/gif/webp/svg) مجاز است']);
        }
        $dir = rtrim($config['paths']['uploads'] ?? (__DIR__.'/../uploads/'), '/') . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = 'logo_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $path = $dir . $fname;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $path)) {
            out(['ok'=>false,'error'=>'ذخیره‌ی فایل ناموفق بود']);
        }
        // مسیر نسبی برای استفاده در <img src>
        $rel = 'uploads/' . $fname;
        Brand::save(['logo' => $rel]);
        out(['ok'=>true,'logo'=>$rel]);
    case 'cron_key_generate':
        adminOnly();
        $key = bin2hex(random_bytes(8));
        Database::run("INSERT INTO eb_settings (k,v) VALUES ('cron.key',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$key]);
        $base = app_base_url($config);
        out(['ok'=>true, 'cron_key'=>$key, 'cron_url'=>"{$base}/cron.php?key={$key}"]);

    // ============ فعالیت اخیر داشبورد ============
    case 'recent_activity':
        // مورد ۴: مشتری‌های بلاک‌شده در داشبورد نمایش داده نشوند — این فید برای
        // فعالیت واقعیِ در حال رسیدگی است، نه پیام‌های اسپم/مزاحمی که بلاک شده‌اند.
        $blockCond = (class_exists('Inbox') && Inbox::hasBlock()) ? "AND (p.blocked_at IS NULL OR p.id IS NULL)" : "";
        $rows = Database::all(
            "SELECT m.direction, m.channel, m.body, m.status, m.created_at, p.full_name, p.id AS person_id
             FROM (SELECT id, identity_id, direction, channel, body, status, created_at FROM eb_messages ORDER BY id DESC LIMIT 80) m
             LEFT JOIN eb_identities i ON i.id = m.identity_id
             LEFT JOIN eb_persons p ON p.id = i.person_id
             WHERE 1=1 {$blockCond}
             ORDER BY m.id DESC LIMIT 15"
        );
        out(['ok'=>true, 'activity'=>$rows]);

    // ============ وضعیت راه‌اندازی (onboarding) ============
    case 'setup_status':
        // چک‌لیست قدم‌های راه‌اندازی برای کاربر تازه
        $anyChannel = false;
        foreach (ChannelManager::available() as $ch) {
            if (ChannelManager::isConfigured($ch)) { $anyChannel = true; break; }
        }
        $hasPersons   = (int)(Database::one("SELECT COUNT(*) c FROM eb_persons")['c'] ?? 0) > 0;
        $hasTemplate  = (int)(Database::one("SELECT COUNT(*) c FROM eb_templates")['c'] ?? 0) > 0;
        $hasSent      = (int)(Database::one("SELECT COUNT(*) c FROM eb_messages WHERE direction='out'")['c'] ?? 0) > 0;
        $hasWelcome   = (bool)(Database::one("SELECT v FROM eb_bot_settings WHERE k='welcome'")['v'] ?? null);

        $steps = [
            ['key'=>'channel',  'title'=>'اتصال یک کانال', 'desc'=>'توکن بله/تلگرام/روبیکا یا پیامک را وارد کنید', 'done'=>$anyChannel, 'page'=>'channels'],
            ['key'=>'welcome',  'title'=>'پیام خوش‌آمد ربات', 'desc'=>'پیامی که با /start به کاربر نشان داده می‌شود', 'done'=>$hasWelcome, 'page'=>'botsettings'],
            ['key'=>'persons',  'title'=>'اولین مشتری', 'desc'=>'وقتی کسی به ربات پیام دهد، اینجا می‌آید', 'done'=>$hasPersons, 'page'=>'persons'],
            ['key'=>'template', 'title'=>'ساخت یک الگو', 'desc'=>'پیام آماده برای ارسال‌های بعدی', 'done'=>$hasTemplate, 'page'=>'templates'],
            ['key'=>'sent',     'title'=>'اولین ارسال', 'desc'=>'یک پیام به مشتریان بفرستید', 'done'=>$hasSent, 'page'=>'send'],
        ];
        // 2.1.6 (R-06): cron بدون کلید از بیرون سرور دیگر اجرا نمی‌شود — اگر رد شد یا اجرا نمی‌شود، اینجا دیده شود
        $cronLast = Database::one("SELECT v FROM eb_settings WHERE k='cron.last_run'")['v'] ?? null;
        $cronDenied = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k='cron.last_denied'")['v'] ?? ''), true);
        $cronOk = $cronLast && (time() - strtotime($cronLast)) < 15 * 60;
        $cronDesc = 'cron.php باید هر دقیقه اجرا شود (صف ارسال، زمان‌بندی، کمپین‌ها)';
        if (!$cronOk && is_array($cronDenied) && (!$cronLast || strtotime($cronDenied['at'] ?? '') > strtotime($cronLast))) {
            $cronDesc = 'اجرای cron در ' . ($cronDenied['at'] ?? '?') . ' رد شد؛ در تنظیمات ← سیستم کلید cron بسازید و آدرس cron هاست را با ?key=… به‌روز کنید';
        }
        $steps[] = ['key'=>'cron', 'title'=>'اجرای خودکار (cron)', 'desc'=>$cronDesc, 'done'=>(bool)$cronOk, 'page'=>'settings'];
        $doneCount = count(array_filter($steps, fn($s)=>$s['done']));
        out(['ok'=>true, 'steps'=>$steps, 'done'=>$doneCount, 'total'=>count($steps), 'complete'=>($doneCount===count($steps))]);

    // ============ مارکتینگ: اهداف، نقش‌ها، اعضا ============
    case 'mkt_ready':
        out(['ok'=>true,'ready'=>Marketing::ready()]);
    case 'mkt_goals_list':
        out(['ok'=>true,'goals'=>Marketing::goals(trim($in['period'] ?? '') ?: null)]);
    case 'mkt_goal_save':
        try { out(['ok'=>true,'id'=>Marketing::saveGoal(is_array($in['goal'] ?? null) ? $in['goal'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_goal_delete':
        Marketing::deleteGoal((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_roles_list':
        out(['ok'=>true,'roles'=>Marketing::roles()]);
    case 'mkt_role_save':
        try { out(['ok'=>true,'id'=>Marketing::saveRole(is_array($in['role'] ?? null) ? $in['role'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_role_delete':
        Marketing::deleteRole((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_members_list':
        out(['ok'=>true,'members'=>Marketing::members()]);
    case 'mkt_member_save':
        try { out(['ok'=>true,'id'=>Marketing::saveMember(is_array($in['member'] ?? null) ? $in['member'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_member_delete':
        Marketing::deleteMember((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_users_linkable':
        out(['ok'=>true,'users'=>Marketing::linkableUsers()]);
    case 'mkt_my_member':
        $mm = Marketing::memberByUser((int)(Auth::id() ?? 0));
        out(['ok'=>true,'member'=>$mm]);
    case 'mkt_my_tasks':
        $mid = (int)($in['member_id'] ?? 0);
        if (!$mid) {
            $mm = Marketing::memberByUser((int)(Auth::id() ?? 0));
            $mid = $mm ? (int)$mm['id'] : 0;
        }
        if (!$mid) out(['ok'=>true,'linked'=>false,'steps'=>[],'content'=>[],'stats'=>['open'=>0,'done'=>0,'overdue'=>0]]);
        out(['ok'=>true,'linked'=>true]+Marketing::myTasks($mid, trim($in['today'] ?? '') ?: null));
    case 'mkt_content_list':
        out(['ok'=>true,'content'=>Marketing::content(
            trim($in['from'] ?? '') ?: null,
            trim($in['to'] ?? '') ?: null,
            (isset($in['page_id']) && $in['page_id'] !== '' && $in['page_id'] !== null) ? (int)$in['page_id'] : null
        )]);
    case 'mkt_pages':
        try { out(['ok'=>true,'pages'=>Marketing::pages(!empty($in['all']))]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_page_save':
        try { out(['ok'=>true,'id'=>Marketing::savePage(is_array($in['page'] ?? null) ? $in['page'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_page_delete':
        try { Marketing::deletePage((int)($in['id'] ?? 0)); out(['ok'=>true]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_content_save':
        try { out(['ok'=>true,'id'=>Marketing::saveContent(is_array($in['content'] ?? null) ? $in['content'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_content_move':
        Marketing::moveContent((int)($in['id'] ?? 0), trim($in['status'] ?? ''));
        out(['ok'=>true]);
    case 'mkt_content_delete':
        Marketing::deleteContent((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_content_detail':
        out(['ok'=>true]+Marketing::contentDetail((int)($in['id'] ?? 0)));
    case 'mkt_crew_add':
        try { out(['ok'=>true,'id'=>Marketing::addCrew(
            (int)($in['content_id'] ?? 0),
            !empty($in['member_id']) ? (int)$in['member_id'] : null,
            trim($in['prod_role'] ?? '')
        )]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_crew_delete':
        Marketing::deleteCrew((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_step_save':
        try { out(['ok'=>true,'id'=>Marketing::saveStep(is_array($in['step'] ?? null) ? $in['step'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_step_toggle':
        Marketing::toggleStep((int)($in['id'] ?? 0), !empty($in['done']));
        out(['ok'=>true]);
    case 'mkt_step_delete':
        Marketing::deleteStep((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_steps_seed':
        Marketing::seedSteps((int)($in['content_id'] ?? 0), trim($in['content_type'] ?? ''));
        out(['ok'=>true]);
    case 'mkt_sendable':
        out(Marketing::sendablePayload((int)($in['id'] ?? 0)));
    case 'mkt_seed_status':
        out(['ok'=>true,'has_seed'=>Marketing::hasSeed()]);
    case 'mkt_reports':
        out(['ok'=>true,'r'=>Marketing::reports()]);
    case 'mkt_budgets_list':
        out(['ok'=>true,'budgets'=>Marketing::budgets(trim($in['period'] ?? '') ?: null)]);
    case 'mkt_budget_save':
        try { out(['ok'=>true,'id'=>Marketing::saveBudget(is_array($in['budget'] ?? null) ? $in['budget'] : $in)]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
    case 'mkt_budget_delete':
        Marketing::deleteBudget((int)($in['id'] ?? 0));
        out(['ok'=>true]);
    case 'mkt_finance':
        out(['ok'=>true,'f'=>Marketing::financeReport(
            trim($in['period'] ?? ''),
            trim($in['greg_from'] ?? '') ?: null,
            trim($in['greg_to'] ?? '') ?: null
        )]);
    case 'mkt_seed_import':
        adminOnly();
        try { out(['ok'=>true,'inserted'=>Marketing::seedSampleData()]); }
        catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }

    // ============ تعمیر/به‌روزرسانی دیتابیس ============
    case 'db_upgrade':
        adminOnly();
        try {
            require_once __DIR__ . '/../core/Schema.php';
            Schema::ensure();
            Database::forgetSchemaCache();
            $mg = ['groups'=>0,'merged'=>0];
            try { $mg = Persons::backfillInstagramMerge(); } catch (Throwable $e) {}
            // 2.2.0: migrationهای نسخه‌دار (فقط غیرمخرب)
            $mig = Migrations::run(false, false, 'admin:' . (int)Auth::id());
            if (!$mig['ok']) out(['ok'=>false, 'error'=>'migration ' . ($mig['stopped_at'] ?? '') . ' ناموفق: ' . ($mig['reason'] ?? ''), 'migrations'=>$mig]);
            out(['ok'=>true, 'migrations'=>$mig, 'message'=>'جداول بررسی و در صورت نیاز ساخته/تکمیل شدند'
                . ($mig['applied'] ? ' · migration: ' . implode('، ', $mig['applied']) : '')
                . ($mg['merged'] ? ' · ' . $mg['merged'] . ' پروفایل تکراری اینستاگرام (هم‌یوزرنیم در پیج‌های مختلف) ادغام شد' : '')]);
        } catch (Throwable $e) {
            out(['ok'=>false, 'error'=>$e->getMessage()]);
        }

    // ============ تاریخچه‌ی ارسال‌ها (لاگ) ============
    case 'sends_list':
        $hasFilter = !empty($in['kind']) || !empty($in['channel']) || !empty($in['result']) || !empty($in['date_from']) || !empty($in['date_to']) || !empty($in['q']);
        $sends = $hasFilter
            ? SendLog::filtered([
                'kind'=>trim($in['kind'] ?? ''),
                'channel'=>trim($in['channel'] ?? ''),
                'result'=>trim($in['result'] ?? ''),
                'date_from'=>trim($in['date_from'] ?? ''),
                'date_to'=>trim($in['date_to'] ?? ''),
                'q'=>trim($in['q'] ?? ''),
              ], 300)
            : SendLog::all(100);
        // خلاصه‌ی نتیجه‌ی همین فهرست
        $sumT=0;$sumS=0;$sumF=0;
        foreach ($sends as $s){ $sumT+=(int)$s['total']; $sumS+=(int)$s['sent']; $sumF+=(int)$s['failed']; }
        out(['ok'=>true,'sends'=>$sends,'dlr'=>class_exists('SmsDlr') ? SmsDlr::summary() : null,
             'summary'=>['count'=>count($sends),'total'=>$sumT,'sent'=>$sumS,'failed'=>$sumF]]);
    case 'send_detail':
        $d = SendLog::detail((int)($in['id'] ?? 0));
        if (!$d) out(['ok'=>false,'error'=>'یافت نشد']);
        out(['ok'=>true,'send'=>$d]);

    default:
        out(['ok'=>false,'error'=>'API ناشناخته: '.$api]);
}
