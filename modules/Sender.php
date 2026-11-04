<?php
/**
 * modules/Sender.php
 * ارسال تکی و انبوه، چندکاناله، روی لایه‌ی «شخص».
 *
 * منطق کلیدی: به یک «شخص» پیام می‌دهیم، نه به یک کانال. هر شخص ممکن است
 * چند هویت (کانال) داشته باشد. کاربر ترتیب اولویت کانال‌ها را می‌دهد و
 * برای هر شخص، اولین کانالی که هویت فعال دارد انتخاب می‌شود.
 *
 * وابسته به: ChannelManager (درایورها)، Templates (ساخت پیام).
 */

class Sender
{
    /**
     * ارسال یک پیام به یک شخص، با ترتیب اولویت کانال.
     *
     * $personId
     * $message: ['text'=>..., 'photo'=>?, 'buttons'=>?]   (فرمت داخلی)
     * $channelPriority: ['bale','telegram','roubika']  ترتیب تلاش
     *
     * خروجی: ['ok'=>bool, 'channel'=>?string, 'error'=>?string]
     */
    /**
     * $opts (دور ۳):
     *   identity_id — اول از همین هویت بفرست (مثلاً پیجِ اینستاگرامی که مشتری آخرین بار به آن پیام داده
     *                 یا هویتی که اپراتور در پروفایل انتخاب کرده)؛ اگر نشد، طبق اولویت کانال‌ها.
     *   sent_by     — کاربر پنل که این پیام را فرستاد (آمار پاسخ هر پشتیبان)
     */
    /** شناسه‌ی پیام در پلتفرم مقصد (بله/تلگرام/روبیکا: result.message_id) */
    public static function extId(array $result): ?string
    {
        $r = $result['raw'] ?? [];
        foreach ([$result['message_id'] ?? null, $r['result']['message_id'] ?? null, $r['data']['message_id'] ?? null, $r['message_id'] ?? null] as $v) {
            if ($v !== null && $v !== '' && is_scalar($v)) return mb_substr((string)$v, 0, 80);
        }
        return null;
    }

    public static function sendToPerson(int $personId, array $message, array $channelPriority, ?int $sendId = null, array $opts = []): array
    {
        // هویت‌های این شخص، نگاشت channel → identity
        // ترتیب last_seen صعودی: اگر یک کانال چند هویت داشت (مثلاً چند پیج اینستاگرام بعد از ادغام)،
        // تازه‌ترین هویت در نگاشت می‌ماند.
        $identities = Database::all(
            "SELECT * FROM eb_identities WHERE person_id = ? ORDER BY last_seen ASC, id ASC",
            [$personId]
        );
        $sentBy = !empty($opts['sent_by']) && Database::hasColumn('eb_messages', 'sent_by') ? (int)$opts['sent_by'] : null;
        $prefId = (int)($opts['identity_id'] ?? 0);
        if (!$identities) {
            return ['ok' => false, 'channel' => null, 'error' => 'no_identity'];
        }

        // شخصی‌سازی متن/دکمه بر اساس همین شخص: {name} {phone} {link:CODE} ...
        // (در همین نقطه چون همه‌ی مسیرهای ارسال chat از sendToPerson عبور می‌کنند)
        if (class_exists('Personalize')) {
            $message = Personalize::message($message, $personId);
        }

        $byChannel = [];
        $pref = null;
        foreach ($identities as $idn) {
            $byChannel[$idn['channel']] = $idn;
            if ($prefId && (int)$idn['id'] === $prefId) $pref = $idn;
        }
        // صف تلاش: اول هویت انتخابی، بعد اولویت کانال‌ها
        $attempts = [];
        if ($pref) $attempts[] = $pref;
        foreach ($channelPriority as $channel) {
            if (!isset($byChannel[$channel])) continue;
            if ($pref && (int)$byChannel[$channel]['id'] === (int)$pref['id']) continue;
            $attempts[] = $byChannel[$channel];
        }

        // به ترتیب اولویت، اولین کانالی که هم هویت دارد هم درایور فعال
        $lastError = null;
        foreach ($attempts as $identity) {
            $channel = $identity['channel'];

            // اینستاگرام ممکن است چندپیجی باشد (سرویس‌دهنده): اگر این هویت به یک
            // پیج سرویس‌دهنده مشخص وصل است، باید دقیقاً از همان پیج پاسخ برود، نه
            // از کانال «instagram» عمومی (که دایرکت‌ساز/OAuth تک‌پیج را می‌دهد).
            $driver = null;
            if ($channel === 'instagram' && !empty($identity['ig_account_id']) && class_exists('InstagramRoboclick')) {
                $driver = InstagramRoboclick::driverForIdentity($identity);
            }
            if (!$driver) $driver = ChannelManager::get($channel);
            if (!$driver) continue;
            $result = (!empty($message['carousel']) && class_exists('Carousel'))
                ? Carousel::dispatch($driver, (string)$channel, ['channel_uid' => $identity['channel_uid']], $message)
                : $driver->send(
                    ['channel_uid' => $identity['channel_uid']],
                    $message
                );

            // ثبت پیام خروجی در تاریخچه (هر تلاش، برای تاریخچه‌ی کامل گفتگو)
            $logMsg = $message;
            if (!empty($message['carousel']) && class_exists('Carousel') && ($cc = Carousel::normalize($message['carousel']))) {
                $logMsg['text'] = trim(($message['text'] ?? '') . (($message['text'] ?? '') !== '' ? "\n\n" : '') . Carousel::summary($cc));
                $logMsg['cards'] = Carousel::toWebchatCards($cc);
            }
            $outMediaType = !empty($message['photo']) ? 'photo' : (!empty($message['document']) ? 'document' : null);
            $outMediaPath = $message['media_rel'] ?? null; // مسیر نسبی برای نمایش (اگر داده شده)
            // شناسه‌ی پیام در خود پلتفرم (برای ویرایش بعدی) و منبع پیام خودکار
            $extId = self::extId($result);
            $extra = [];
            if ($sentBy) $extra['sent_by'] = $sentBy;
            if ($extId !== null && Database::hasColumn('eb_messages', 'ext_id')) $extra['ext_id'] = $extId;
            if (!empty($opts['source']) && Database::hasColumn('eb_messages', 'source')) $extra['source'] = mb_substr((string)$opts['source'], 0, 20);
            // ۱.۰.۱۴: دکمه‌ها همراه پیام گفتگوی سایت ذخیره می‌شوند تا ویجت به‌صورت دکمه نشانشان دهد
            if ($channel === 'webchat' && class_exists('ChatPlus') && ($pl = ChatPlus::payloadFromMessage($logMsg)) && ChatPlus::hasPayload()) $extra['payload_json'] = json_encode($pl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $msgDbId = null;
            try {
                Database::run(
                    "INSERT INTO eb_messages (identity_id, channel, direction, body, status, error, send_id, media_type, media_path" . ($extra ? ', ' . implode(', ', array_keys($extra)) : '') . ")
                     VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?" . str_repeat(', ?', count($extra)) . ")",
                    array_merge([
                        $identity['id'], $channel, $logMsg['text'] ?? '',
                        !empty($result['ok']) ? 'sent' : 'failed',
                        !empty($result['ok']) ? null : mb_substr((string)($result['error'] ?? 'send_failed'), 0, 500),
                        $sendId, $outMediaType, $outMediaPath,
                    ], array_values($extra))
                );
                $msgDbId = Database::lastId();
            } catch (Throwable $e) {
                try {
                    Database::run(
                        "INSERT INTO eb_messages (identity_id, channel, direction, body, status) VALUES (?, ?, 'out', ?, ?)",
                        [$identity['id'], $channel, $logMsg['text'] ?? '', !empty($result['ok']) ? 'sent' : 'failed']
                    );
                } catch (Throwable $e2) { /* بی‌صدا */ }
            }

            // اگر موفق بود → یک target موفق ثبت کن و تمام
            if (!empty($result['ok'])) {
                if ($sendId) {
                    SendLog::target($sendId, $personId, $channel, (string)$identity['channel_uid'], true, null);
                }
                return ['ok' => true, 'channel' => $channel, 'identity_id' => (int)$identity['id'], 'message_id' => $msgDbId, 'error' => null];
            }
            $lastError = $result['error'] ?? 'send_failed';
        }

        // هیچ کانالی موفق نشد → فقط یک target ناموفق برای این شخص
        if ($sendId) {
            SendLog::target($sendId, $personId, '-', '-', false, $lastError ?? 'no_channel_available');
        }

        return ['ok' => false, 'channel' => null, 'error' => $lastError ?? 'no_channel_available'];
    }

    /**
     * ارسال انبوه به فهرستی از اشخاص.
     *
     * $personIds: آرایه‌ی id اشخاص
     * $message: فرمت داخلی
     * $channelPriority: ترتیب کانال‌ها
     * $delayMs: تأخیر بین ارسال‌ها (پیش‌فرض ۵۰ms برای رعایت rate limit)
     * $onProgress: callback اختیاری(index, total, result)
     *
     * خروجی: ['total'=>, 'sent'=>, 'failed'=>, 'by_channel'=>[...], 'errors'=>[...]]
     */
    public static function sendBulk(array $personIds, array $message, array $channelPriority, int $delayMs = 50, ?callable $onProgress = null, ?int $sendId = null): array
    {
        $total = count($personIds);
        $sent = 0; $failed = 0;
        $byChannel = []; $errors = [];

        // اگر send_id از بیرون نیامد، یک batch بساز (برای ارسال‌های انبوه مستقیم)
        $ownSend = false;
        if ($sendId === null) {
            $title = mb_substr($message['text'] ?? 'ارسال انبوه', 0, 80);
            $sendId = SendLog::start('bulk', $title, $message['text'] ?? null, $channelPriority);
            $ownSend = true;
        }

        $i = 0;
        foreach ($personIds as $pid) {
            $i++;
            $res = self::sendToPerson((int)$pid, $message, $channelPriority, $sendId);
            if ($res['ok']) {
                $sent++;
                $ch = $res['channel'];
                $byChannel[$ch] = ($byChannel[$ch] ?? 0) + 1;
            } else {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = ['person_id' => (int)$pid, 'error' => $res['error']];
                }
            }
            if ($onProgress) $onProgress($i, $total, $res);
            if ($delayMs > 0) usleep($delayMs * 1000);
        }

        // خلاصه‌ی batch را ببند
        SendLog::finish($sendId, $total, $sent, $failed, ['by_channel' => $byChannel]);

        return [
            'total'      => $total,
            'sent'       => $sent,
            'failed'     => $failed,
            'by_channel' => $byChannel,
            'errors'     => $errors,
            'send_id'    => $sendId,
        ];
    }

    /**
     * انتخاب اشخاص هدف بر اساس فیلتر.
     *
     * $filter: [
     *   'group_id' => ?int,
     *   'tag_id'   => ?int,
     *   'status'   => ?'member'|'anonymous',
     *   'channel'  => ?string   // فقط اشخاصی که در این کانال هویت دارند
     * ]
     * خروجی: آرایه‌ی id اشخاص
     */
    public static function resolveAudience(array $filter): array
    {
        $joins = []; $where = []; $params = [];

        // ترتیب: JOINها و پارامترهایشان را با هم و به همان ترتیب می‌چینیم
        if (!empty($filter['channel'])) {
            $joins[]  = "INNER JOIN eb_identities idn ON idn.person_id = p.id AND idn.channel = ?";
            $params[] = $filter['channel'];
        }
        if (!empty($filter['group_id'])) {
            $joins[]  = "INNER JOIN eb_person_groups pg ON pg.person_id = p.id AND pg.group_id = ?";
            $params[] = $filter['group_id'];
        }
        if (!empty($filter['tag_id'])) {
            $joins[]  = "INNER JOIN eb_person_tags pt ON pt.person_id = p.id AND pt.tag_id = ?";
            $params[] = $filter['tag_id'];
        }
        if (!empty($filter['status'])) {
            $where[]  = "p.status = ?";
            $params[] = $filter['status'];
        }
        // مشتریانی که در N روز اخیر پیام (خروجی) نگرفته‌اند — برای کمپین فعال‌سازی مجدد
        if (!empty($filter['inactive_days'])) {
            $days = (int)$filter['inactive_days'];
            $where[] = "p.id NOT IN (
                SELECT i.person_id FROM eb_messages m
                INNER JOIN eb_identities i ON i.id = m.identity_id
                WHERE m.direction='out' AND m.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            )";
        }
        // مشتریانی که حداقل یک پاسخ (ورودی) داده‌اند یا نداده‌اند
        if (isset($filter['has_responded']) && $filter['has_responded'] !== '' && $filter['has_responded'] !== null) {
            $op = !empty($filter['has_responded']) ? 'IN' : 'NOT IN';
            $where[] = "p.id {$op} (
                SELECT i.person_id FROM eb_messages m
                INNER JOIN eb_identities i ON i.id = m.identity_id
                WHERE m.direction='in'
            )";
        }

        $sql = "SELECT DISTINCT p.id FROM eb_persons p";
        if ($joins) $sql .= ' ' . implode(' ', $joins);

        // محدودیت دسترسی اپراتور: فقط گروه‌های مجاز
        if (class_exists('Auth')) {
            $allowed = Auth::allowedGroups();
            if ($allowed !== null) {
                if (!$allowed) $allowed = [-1];
                $ph = implode(',', array_fill(0, count($allowed), '?'));
                $where[] = "p.id IN (SELECT person_id FROM eb_person_groups WHERE group_id IN ($ph))";
                foreach ($allowed as $g) $params[] = $g;
            }
        }

        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

        $rows = Database::all($sql, $params);
        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    /**
     * ارسال انبوه با «شماره» (صفیر و/یا پیامک) — مستقل از بانک مشتری.
     * این بخش مخصوص کانال‌هایی است که با شماره کار می‌کنند، نه chat_id.
     *
     * $phones: آرایه‌ی شماره (هر فرمتی؛ نرمال می‌شوند)
     * $message: ['text'=>..., 'buttons'=>?, 'photo'=>? , 'file_id'=>?]
     * $mode:
     *   'safir_then_sms' → اول صفیر؛ هرکه بله نداشت، پیامک (کم‌هزینه)
     *   'safir'          → فقط صفیر
     *   'sms'            → فقط پیامک
     *   'both'           → هر دو همزمان (کاربر دو پیام می‌گیرد)
     *
     * خروجی: آمار تفکیکی.
     */
    public static function sendByPhone(array $phones, array $message, string $mode = 'safir_then_sms', int $delayMs = 50): array
    {
        $safir = ChannelManager::get('bale_safir');
        $sms   = ChannelManager::get('sms');

        // نرمال‌سازی + حذف تکراری
        $valid = []; $invalid = []; $seen = [];
        foreach ($phones as $p) {
            $n = Persons::normalizePhone($p);
            if ($n === null) { $invalid[] = $p; }
            elseif (!isset($seen[$n])) { $seen[$n] = true; $valid[] = $n; }
        }

        $stat = ['total'=>count($valid), 'safir_sent'=>0, 'sms_sent'=>0, 'failed'=>0, 'invalid'=>count($invalid)];

        // batch لاگ
        $title = mb_substr($message['text'] ?? 'ارسال با شماره', 0, 80);
        $channelsUsed = $mode === 'sms' ? ['sms'] : ($mode === 'safir' ? ['bale_safir'] : ['bale_safir','sms']);
        $sendId = SendLog::start('phone', $title, $message['text'] ?? null, $channelsUsed, $mode);

        // شماره‌های نامعتبر را هم در لاگ بگذار (skipped)
        foreach ($invalid as $bad) {
            SendLog::target($sendId, null, '-', (string)$bad, false, 'شماره نامعتبر');
        }

        // اگر صفیر عکس دارد، یک‌بار آپلود کن
        if ($safir && !empty($message['photo']) && empty($message['file_id'])) {
            $fid = $safir->uploadFile($message['photo']);
            if ($fid) $message['file_id'] = $fid;
        }

        // آیا متن اصلاً توکن شخصی‌سازی دارد؟ (اگر نه، کوئریِ شخص لازم نیست)
        $needPersonalize = class_exists('Personalize') && Personalize::hasTokens($message['text'] ?? '');

        foreach ($valid as $phone) {
            $sentSomewhere = false;
            $lastErr = null;

            // پیام این گیرنده: اگر توکن دارد، با شخصِ متناظرِ این شماره شخصی‌سازی شود
            $pmsg = $message;
            if ($needPersonalize) {
                $pidForMsg = self::personIdByPhone($phone);
                if ($pidForMsg) {
                    $pmsg = Personalize::message($message, $pidForMsg);
                } else {
                    // شخص در بانک نیست → توکن‌ها را با مقدار امن جایگزین کن (نه باقی‌ماندن خام)
                    $pmsg['text'] = Personalize::stripTokens($message['text'] ?? '', $phone);
                }
            }
            $pbody = $pmsg['text'] ?? '';

            $trySafir = in_array($mode, ['safir','both','safir_then_sms'], true) && $safir;
            $trySms   = in_array($mode, ['sms','both'], true) && $sms;

            // مرحله‌ی صفیر
            $safirOk = false;
            if ($trySafir) {
                $r = $safir->send(['phone'=>$phone], $pmsg);
                $safirOk = !empty($r['ok']);
                if ($safirOk) {
                    $stat['safir_sent']++; $sentSomewhere = true;
                    $pid = self::logPhoneMsg('bale_safir', $phone, $pbody, $sendId, true, null);
                    SendLog::target($sendId, $pid, 'bale_safir', $phone, true, null);
                } else {
                    $lastErr = $r['error'] ?? 'safir_failed';
                    // فقط اگر fallback نیست، همین‌جا شکست صفیر را ثبت کن
                    if ($mode !== 'safir_then_sms') {
                        $pid = self::logPhoneMsg('bale_safir', $phone, $pbody, $sendId, false, $lastErr);
                        SendLog::target($sendId, $pid, 'bale_safir', $phone, false, $lastErr);
                    }
                }
            }

            // در حالت fallback: اگر صفیر نرسید (کاربر بله ندارد)، پیامک بفرست
            if ($mode === 'safir_then_sms' && !$safirOk && $sms) {
                $trySms = true;
            }

            if ($trySms) {
                $r = $sms->send(['phone'=>$phone], $pmsg);
                if (!empty($r['ok'])) {
                    $stat['sms_sent']++; $sentSomewhere = true;
                    $pid = self::logPhoneMsg('sms', $phone, $pbody, $sendId, true, null);
                    SendLog::target($sendId, $pid, 'sms', $phone, true, null);
                    // ثبت برای پیگیری تحویل (DLR) اگر provider شناسه برگرداند
                    if (class_exists('SmsDlr') && !empty($r['msg_id'])) {
                        $provName = method_exists($sms, 'getProvider') && $sms->getProvider() ? $sms->getProvider()->name() : 'sms';
                        SmsDlr::track($provName, $r['msg_id'], $phone, $pid);
                    }
                } else {
                    $lastErr = $r['error'] ?? 'sms_failed';
                    $pid = self::logPhoneMsg('sms', $phone, $pbody, $sendId, false, $lastErr);
                    SendLog::target($sendId, $pid, 'sms', $phone, false, $lastErr);
                }
            }

            if (!$sentSomewhere) $stat['failed']++;
            if ($delayMs > 0) usleep($delayMs * 1000);
        }

        SendLog::finish($sendId, $stat['total'], $stat['safir_sent'] + $stat['sms_sent'], $stat['failed'],
            ['safir_sent'=>$stat['safir_sent'], 'sms_sent'=>$stat['sms_sent'], 'invalid'=>$stat['invalid']]);

        $stat['send_id'] = $sendId;
        return $stat;
    }

    /**
     * یافتن person_id از روی شماره (برای شخصی‌سازی در مسیر ارسال با شماره).
     * بدون ساخت شخص جدید؛ اگر هنوز شخصی برای این شماره نباشد null.
     */
    private static function personIdByPhone(string $phone): ?int
    {
        $row = Database::one("SELECT id FROM eb_persons WHERE phone = ? LIMIT 1", [$phone]);
        if ($row) return (int)$row['id'];
        // شاید فقط به‌صورت هویت شماره‌ای ثبت شده باشد (sms/safir) بدون phone طلایی
        $idn = Database::one(
            "SELECT person_id FROM eb_identities WHERE channel_uid = ? AND channel IN ('sms','bale_safir') LIMIT 1",
            [$phone]
        );
        return $idn ? (int)$idn['person_id'] : null;
    }

    /**
     * ثبت پیام ارسالی با شماره در تاریخچه (با وضعیت و علت).
     * خروجی: person_id (برای لاگ target).
     */
    private static function logPhoneMsg(string $channel, string $phone, string $body, ?int $sendId = null, bool $ok = true, ?string $error = null): ?int
    {
        $personId = Persons::ingestIdentity($channel, ['channel_uid'=>$phone, 'phone'=>$phone]);
        $identity = Database::one(
            "SELECT id FROM eb_identities WHERE channel=? AND channel_uid=?",
            [$channel, $phone]
        );
        if ($identity) {
            try {
                Database::run(
                    "INSERT INTO eb_messages (identity_id, channel, direction, body, status, error, send_id)
                     VALUES (?, ?, 'out', ?, ?, ?, ?)",
                    [$identity['id'], $channel, $body, $ok ? 'sent' : 'failed', $error ? mb_substr($error,0,500) : null, $sendId]
                );
            } catch (Throwable $e) {
                // اگر ستون‌های جدید نبودند (نصب قدیمی)، حداقل پیام پایه را ثبت کن
                try {
                    Database::run(
                        "INSERT INTO eb_messages (identity_id, channel, direction, body, status) VALUES (?, ?, 'out', ?, ?)",
                        [$identity['id'], $channel, $body, $ok ? 'sent' : 'failed']
                    );
                } catch (Throwable $e2) { /* بی‌صدا */ }
            }
        }
        return $personId;
    }
}
