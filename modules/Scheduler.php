<?php
/**
 * modules/Scheduler.php
 * زمان‌بندی ارسال. پیام‌های سررسیده توسط cron.php پردازش می‌شوند.
 */

class Scheduler
{
    public static function all(): array
    {
        return Database::all("SELECT * FROM eb_scheduled ORDER BY run_at DESC LIMIT 100");
    }

    public static function create(array $d): int
    {
        Database::run(
            "INSERT INTO eb_scheduled
             (name, send_type, template_id, text, buttons_json, media_path, filter_json, phones_text, phone_mode, channel_priority, run_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $d['name'] ?? 'بدون نام',
                $d['send_type'] ?? 'chat',
                !empty($d['template_id']) ? (int)$d['template_id'] : null,
                $d['text'] ?? null,
                $d['buttons_json'] ?? null,
                $d['media_path'] ?? null,
                json_encode($d['filter'] ?? [], JSON_UNESCAPED_UNICODE),
                $d['phones_text'] ?? null,
                $d['phone_mode'] ?? 'safir_then_sms',
                implode(',', $d['channels'] ?? ['bale','telegram','roubika']),
                $d['run_at'] ?? date('Y-m-d H:i:s'),
            ]
        );
        return Database::lastId();
    }

    public static function cancel(int $id): void
    {
        Database::run("UPDATE eb_scheduled SET status='cancelled' WHERE id=? AND status='pending'", [$id]);
    }

    public static function delete(int $id): void
    {
        Database::run("DELETE FROM eb_scheduled WHERE id=?", [$id]);
    }

    /**
     * پردازش پیام‌های سررسیده (توسط cron). در هر اجرا حداکثر $limit مورد.
     * خروجی: تعداد پردازش‌شده.
     *
     * ارسال گفتگو دیگر همگام انجام نمی‌شود. قبلاً همه‌ی گیرنده‌ها داخل همین درخواست
     * cron فرستاده می‌شدند؛ با مخاطب زیاد، محدودیت زمان اجرای PHP (معمولاً ۳۰ تا ۶۰ ثانیه) وسط کار
     * درخواست را می‌کشت و ردیف برای همیشه در وضعیت «در حال ارسال» می‌ماند. حالا گیرنده‌ها وارد صف
     * پایدار ارسال می‌شوند و صف در هر اجرای cron دسته‌دسته جلو می‌رود.
     */
    public static function processDue(int $limit = 5): int
    {
        if (!class_exists('SchemaV4')) require_once __DIR__ . '/../core/SchemaV4.php';
        SchemaV4::ensure();

        // ردیف‌هایی که اجرای قبلی نیمه‌کاره رها کرده (مثلاً تایم‌اوت سرور) بی‌صدا گیر نمانند.
        // دوباره فرستاده نمی‌شوند تا کسی پیام تکراری نگیرد؛ با پیام خطا علامت می‌خورند.
        try {
            Database::run(
                "UPDATE eb_scheduled SET status='failed', error='اجرای قبلی نیمه‌کاره ماند (احتمالاً محدودیت زمان اجرای سرور). از «تاریخچه‌ی ارسال» بررسی کنید.'
                 WHERE status='sending' AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))"
            );
        } catch (Throwable $e) {}

        $due = Database::all(
            "SELECT * FROM eb_scheduled WHERE status='pending' AND run_at <= NOW()
             ORDER BY run_at ASC LIMIT " . max(1, (int)$limit)
        );
        $count = 0;
        foreach ($due as $s) {
            // claim اتمیک: فقط یک اجرای cron برنده می‌شود
            $claim = Database::run(
                "UPDATE eb_scheduled SET status='sending', claimed_at=NOW() WHERE id=? AND status='pending'",
                [$s['id']]
            );
            if (!$claim || $claim->rowCount() !== 1) continue;

            $message = self::buildMessage($s);
            $type = $s['send_type'] ?? 'chat';
            $sent = 0; $failed = 0; $error = null; $status = 'sent'; $jobId = null;

            try {
                if ($type === 'chat') {
                    $filter = json_decode($s['filter_json'] ?? '{}', true) ?: [];
                    $channels = array_values(array_filter(explode(',', $s['channel_priority'] ?? 'bale,telegram,roubika')));
                    $persons = Sender::resolveAudience($filter);
                    if (!$persons) {
                        $status = 'failed'; $error = 'هیچ مخاطبی با این گروه/برچسب پیدا نشد.';
                    } else {
                        $q = Queue::enqueue($persons, $message, $channels, $s['name'] ?? null);
                        $jobId = $q['job_id'] ?: null; $sent = (int)$q['total'];
                        $status = 'queued';
                    }
                } elseif ($type === 'phone') {
                    $phones = self::collectPhones($s);
                    if (!$phones) { $status = 'failed'; $error = 'هیچ شماره‌ی معتبری پیدا نشد.'; }
                    else {
                        $r = Sender::sendByPhone($phones, $message, $s['phone_mode'] ?? 'safir_then_sms');
                        $sent = ($r['safir_sent'] ?? 0) + ($r['sms_sent'] ?? 0); $failed = (int)($r['failed'] ?? 0);
                    }
                } elseif ($type === 'voice') {
                    $phones = self::collectPhones($s);
                    $voice = ChannelManager::getVoice();
                    if (!$voice) { $status = 'failed'; $error = 'سرویس تماس صوتی تنظیم نشده است.'; }
                    elseif (!$phones) { $status = 'failed'; $error = 'هیچ شماره‌ی معتبری پیدا نشد.'; }
                    else foreach ($phones as $p) {
                        $vr = $voice->callSpeech($p, $s['text'] ?? '');
                        if (!empty($vr['ok'])) $sent++; else $failed++;
                    }
                } elseif ($type === 'email') {
                    $email = ChannelManager::get('email');
                    if (!$email) { $status = 'failed'; $error = 'ایمیل (SMTP) تنظیم نشده است.'; }
                    else {
                        $persons = Sender::resolveAudience(json_decode($s['filter_json'] ?? '{}', true) ?: []);
                        foreach ($persons as $pid) {
                            $addr = Database::one("SELECT channel_uid FROM eb_identities WHERE person_id=? AND channel='email' LIMIT 1", [$pid]);
                            if (!$addr) continue;
                            $er = $email->send(['email' => $addr['channel_uid']], ['subject' => $s['name'], 'text' => $s['text'] ?? '']);
                            if (!empty($er['ok'])) $sent++; else $failed++;
                        }
                        if (!$sent && !$failed) { $status = 'failed'; $error = 'هیچ مخاطبی ایمیل ثبت‌شده ندارد.'; }
                    }
                }
                if ($status === 'sent' && !$sent && $failed) { $status = 'failed'; $error = $error ?: 'ارسال به همه‌ی گیرنده‌ها ناموفق بود.'; }
            } catch (Throwable $e) {
                $status = 'failed'; $error = mb_substr($e->getMessage(), 0, 400);
            }

            Database::run(
                "UPDATE eb_scheduled SET status=?, sent_count=?, failed_count=?, queue_job_id=?, error=? WHERE id=?",
                [$status, $sent, $failed, $jobId, $error, $s['id']]
            );
            $count++;
        }
        return $count;
    }

    /** پیام نهایی از الگو یا متن دستی + دکمه‌ها + عکس */
    private static function buildMessage(array $s): array
    {
        if (!empty($s['template_id'])) {
            $tpl = Templates::find((int)$s['template_id']);
            $message = $tpl ? Templates::toMessage($tpl) : ['text' => $s['text'] ?? ''];
        } else {
            $message = ['text' => $s['text'] ?? ''];
            if (!empty($s['buttons_json'])) {
                $dec = json_decode($s['buttons_json'], true);
                if (is_array($dec)) $message['buttons'] = Templates::normalizeButtons($dec);
            }
        }
        if (!empty($s['media_path']) && is_file($s['media_path'])) $message['photo'] = $s['media_path'];
        return $message;
    }

    /** فهرست با ساعت محلی و پیشرفت صف ارسال */
    public static function listForUi(): array
    {
        if (!class_exists('SchemaV4')) require_once __DIR__ . '/../core/SchemaV4.php';
        SchemaV4::ensure();
        if (!class_exists('Tz')) require_once __DIR__ . '/../core/Tz.php';
        $rows = Database::all(
            "SELECT s.*, j.total q_total, j.processed q_processed, j.sent q_sent, j.failed q_failed, j.status q_status
             FROM eb_scheduled s LEFT JOIN eb_queue_jobs j ON j.id = s.queue_job_id
             ORDER BY s.run_at DESC LIMIT 100"
        );
        foreach ($rows as &$r) {
            $r['run_at_local'] = Tz::toLocal($r['run_at']);
            if ($r['status'] === 'queued' && ($r['q_status'] ?? '') === 'done') {
                $r['sent_count'] = (int)$r['q_sent']; $r['failed_count'] = (int)$r['q_failed'];
                $r['status'] = ($r['sent_count'] === 0 && $r['failed_count'] > 0) ? 'failed' : 'sent';
                if ($r['status'] === 'failed' && empty($r['error'])) $r['error'] = 'ارسال به همه‌ی گیرنده‌ها ناموفق بود؛ اتصال کانال‌ها و «تاریخچه‌ی ارسال» را بررسی کنید.';
            }
        }
        unset($r);
        return $rows;
    }

    /** جمع‌آوری شماره‌ها برای زمان‌بندی شماره‌ای: از متن مستقیم یا از فیلتر گروه/برچسب */
    private static function collectPhones(array $s): array
    {
        $phones = [];
        // از متن مستقیم
        if (!empty($s['phones_text'])) {
            foreach (preg_split('/[\r\n,;]+/', $s['phones_text']) as $p) {
                $n = Persons::normalizePhone(trim($p));
                if ($n) $phones[$n] = true;
            }
        }
        // از فیلتر گروه/برچسب
        $filter = json_decode($s['filter_json'] ?? '{}', true) ?: [];
        if (!empty($filter['group_id']) || !empty($filter['tag_id'])) {
            $persons = Sender::resolveAudience($filter);
            if ($persons) {
                $ph = implode(',', array_fill(0, count($persons), '?'));
                $rows = Database::all("SELECT phone FROM eb_persons WHERE id IN ($ph) AND phone IS NOT NULL AND phone<>''", $persons);
                foreach ($rows as $r) {
                    $n = Persons::normalizePhone($r['phone']);
                    if ($n) $phones[$n] = true;
                }
            }
        }
        return array_keys($phones);
    }
}
