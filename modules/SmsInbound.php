<?php
/**
 * modules/SmsInbound.php
 * دریافت پاسخ‌های پیامک (فقط خط اختصاصی) با روش pull و ثبت در سیستم.
 * توسط cron.php به‌صورت دوره‌ای صدا زده می‌شود.
 *
 * بعد از ثبت هر پیام دریافتی، پاسخ خودکار کانال 'sms' هم بررسی می‌شود.
 */

class SmsInbound
{
    /**
     * یک‌بار pull: پیام‌های جدید پیامک را می‌گیرد، در بانک مشتری و تاریخچه ثبت می‌کند،
     * و پاسخ خودکار را اجرا می‌کند.
     * خروجی: تعداد پیام دریافتی پردازش‌شده.
     */
    public static function pull(): int
    {
        $sms = ChannelManager::get('sms');
        if (!$sms) return 0;

        $inbound = $sms->fetchInbound(); // آرایه‌ای از ['phone','text','received_at','raw']
        if (!$inbound) return 0;

        $count = 0;
        foreach ($inbound as $msg) {
            $phone = Persons::normalizePhone($msg['phone'] ?? '');
            $text  = $msg['text'] ?? '';
            if (!$phone || $text === '') continue;

            // ثبت هویت/شخص با این شماره (ممکن است merge شود اگر از قبل عضو باشد)
            Persons::ingestIdentity('sms', ['channel_uid'=>$phone, 'phone'=>$phone]);
            $identity = Database::one(
                "SELECT id FROM eb_identities WHERE channel='sms' AND channel_uid=?",
                [$phone]
            );
            if (!$identity) continue;

            // جلوگیری از ثبت تکراری: اگر همین متن از همین شماره اخیراً ثبت شده، رد کن
            $dup = Database::one(
                "SELECT id FROM eb_messages WHERE identity_id=? AND direction='in' AND body=? 
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 1",
                [$identity['id'], $text]
            );
            if ($dup) continue;

            Database::run(
                "INSERT INTO eb_messages (identity_id, channel, direction, body, status)
                 VALUES (?, 'sms', 'in', ?, 'received')",
                [$identity['id'], $text]
            );

            // پاسخ خودکار روی کانال پیامک
            AutoReply::handle('sms', $phone, $text);
            $count++;
        }
        return $count;
    }
}
