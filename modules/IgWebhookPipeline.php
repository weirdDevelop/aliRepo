<?php
/**
 * modules/IgWebhookPipeline.php
 * پایپ‌لاین مشترک رویدادهای اینستاگرامِ چندپیجی — مستقل از اینکه رویداد از کجا آمده:
 *   - webhook/roboclick.php  (اتصال مستقیم قدیمی به سرویس‌دهنده، هر پیج یک وب‌هوک)
 *   - webhook/apichi.php     (اتصال از طریق یار API — یک وب‌هوک برای همه‌ی پیج‌ها)
 *   - InstagramApichi::poll() (دریافت رویدادهای جاافتاده با polling از یار API در cron)
 *
 * ورودی همیشه payload به شکل اصلی سرویس‌دهنده است:
 *   {type: direct|comment|button_click, contact:{id,username,name}, message:{text,is_story_reply,story_id,story_media}, comment:{id,post_id,text}, button:{payload}}
 * (یار API همین payload خام را در فیلد raw رویداد می‌فرستد.)
 *
 * این کد قبلاً داخل webhook/roboclick.php بود؛ بدون تغییر رفتار به اینجا منتقل شد.
 */

class IgWebhookPipeline
{
    /** پیشوند تا شناسه‌ی داخلی مخاطب بین پیج‌های مختلف/دایرکت‌ساز تداخل نکند */
    public static function uid(int $accountId, string $contactId): string
    {
        return 'rc' . $accountId . '_' . $contactId;
    }

    /**
     * @param array  $account     ردیف eb_ig_accounts
     * @param array  $payload     payload به شکل سرویس‌دهنده
     * @param InstagramRoboclickDriver $driver درایور همان پیج (سرویس‌دهنده یا یار API)
     * @param string $logChannel  instagram_roboclick | instagram_apichi
     * @return string وضعیت (برای لاگ)
     */
    public static function process(array $account, array $payload, $driver, array $config, string $logChannel = 'instagram_roboclick'): string
    {
        try {
            return self::processInner($account, $payload, $driver, $config, $logChannel);
        } finally {
            // «پیج جاری» نباید به پردازش‌های بعدی (کانال‌های دیگر، رویداد بعدی polling) نشت کند
            if (class_exists('IgPageSettings')) IgPageSettings::setCurrent(null);
        }
    }

    private static function processInner(array $account, array $payload, $driver, array $config, string $logChannel): string
    {
        $accountId = (int)$account['id'];
        $type = $payload['type'] ?? '';
        $channel = 'instagram';
        // تنظیمات جداگانه‌ی همین پیج (پاسخ خودکار، AI، گیت شماره، منو، فرم، اعلان)
        if (class_exists('IgPageSettings')) IgPageSettings::setCurrent($accountId);

        try {
            if ($type === 'direct') {
                return self::direct($account, $accountId, $payload, $driver, $config, $logChannel);
            }
            if ($type === 'comment') {
                return self::comment($account, $accountId, $payload, $driver, $logChannel);
            }
            if ($type === 'button_click') {
                return self::buttonClick($accountId, $payload, $driver, $config, $logChannel);
            }
            return 'ignored:' . $type;
        } catch (Throwable $e) {
            ChannelManager::setInstagramOverride(null);
            if (class_exists('IgPageSettings')) IgPageSettings::setCurrent(null);
            if (!empty($config['debug'])) error_log('[IgWebhookPipeline] ' . $e->getMessage());
            if (class_exists('ApiLog')) {
                try { ApiLog::record($logChannel, 'pipeline_error', ['type' => $type, 'account_id' => $accountId], $e->getMessage(), null, false, $e->getMessage()); } catch (Throwable $e2) {}
            }
            return 'error';
        }
    }

    private static function direct(array $account, int $accountId, array $payload, $driver, array $config, string $logChannel): string
    {
        $channel = 'instagram';
        $contact = $payload['contact'] ?? [];
        $message = $payload['message'] ?? [];
        $contactId = (string)($contact['id'] ?? '');
        if ($contactId === '') return 'no_contact';

        $channelUid = self::uid($accountId, $contactId);
        $event = [
            'channel_uid' => $channelUid,
            'username'    => $contact['username'] ?? null,
            'first_name'  => $contact['name'] ?? null,
            'raw'         => $payload,
        ];

        $personId = Persons::ingestIdentity($channel, $event);
        Database::run(
            "UPDATE eb_identities SET ig_account_id = ? WHERE channel = ? AND channel_uid = ? AND ig_account_id IS NULL",
            [$accountId, $channel, $channelUid]
        );

        if ($personId && class_exists('Inbox') && Inbox::isBlocked($personId)) {
            return 'blocked';
        }

        $text = (string)($message['text'] ?? '');
        $isStoryReply = !empty($message['is_story_reply']);

        if ($text !== '') {
            $identity = Database::one("SELECT id FROM eb_identities WHERE channel = ? AND channel_uid = ?", [$channel, $channelUid]);
            if ($identity) {
                $mediaType = null; $mediaPath = null;
                // ریپلای استوری: شناسه‌ی رسانه (اگر «دریافت رسانه پاسخ استوری» در سرویس‌دهنده فعال باشد)
                if ($isStoryReply && !empty($message['story_media']['resource_id'])) {
                    $mediaType = 'story_reply';
                    $mediaPath = (string)$message['story_media']['resource_id'];
                }
                Database::run(
                    "INSERT INTO eb_messages (identity_id, channel, direction, body, status, media_type, media_path)
                     VALUES (?, ?, 'in', ?, 'received', ?, ?)",
                    [$identity['id'], $channel, $isStoryReply ? ('↩️ ریپلای استوری: ' . $text) : $text, $mediaType, $mediaPath]
                );
                if (class_exists('Notifier') && (!class_exists('IgPageSettings') || IgPageSettings::on('notify_enabled'))) {
                    $who = null;
                    if ($personId) {
                        try { $who = Database::one("SELECT full_name FROM eb_persons WHERE id=?", [$personId])['full_name'] ?? null; }
                        catch (Throwable $e) {}
                    }
                    Notifier::push(
                        'message',
                        'پیام تازه از ' . ($who ?: 'مخاطب ناشناس') . ' («' . $account['label'] . '»)',
                        mb_substr($text, 0, 200),
                        $personId ? "index.php?page=inbox&person={$personId}" : "index.php?page=inbox",
                        $personId
                    );
                }
            }
        }

        // پایپ‌لاین کامل — پاسخ‌ها از همین پیج بروند نه از کانال عمومی instagram
        ChannelManager::setInstagramOverride($driver);

        $storyRuleHandled = false;
        if ($isStoryReply && $text !== '') {
            try {
                $storyRuleHandled = IgCommentAutomation::matchAndRunForStoryReply($driver, [
                    'channel_uid' => $channelUid,
                    'text'        => $text,
                    'story_id'    => (string)($message['story_id'] ?? ''),
                    'comment_id'  => '',
                ], $accountId);
            } catch (Throwable $e) {
                if (!empty($config['debug'])) error_log('[IgWebhookPipeline][story-rule] ' . $e->getMessage());
            }
        }

        $gateHandled = false;
        if (!$storyRuleHandled) {
            try { $gateHandled = IgCommentAutomation::handleDmForGate($driver, $channelUid, $text); }
            catch (Throwable $e) { if (!empty($config['debug'])) error_log('[IgWebhookPipeline][gate] ' . $e->getMessage()); }
        }

        $phoneGateHandled = false;
        if (!$storyRuleHandled && !$gateHandled && $personId) {
            try { $phoneGateHandled = IgPhoneGate::handle($driver, $channelUid, $personId, $text); }
            catch (Throwable $e) {
                if (class_exists('ApiLog')) ApiLog::record($logChannel, 'phone_gate_error', ['person_id' => $personId], $e->getMessage(), null, false, $e->getMessage());
            }
        }

        // شناسایی خودکار شماره در هر پیام (نه فقط وقتی گیت شماره روشن است):
        // اگر کاربر هر جای پیامش شماره موبایل داد و پروفایلش هنوز شماره ندارد، روی پروفایل ثبت می‌شود
        // و اگر همین شماره قبلاً در کانال دیگری (سایت، تلگرام، پیامک…) بوده، دو پروفایل یکی می‌شوند.
        if ($personId && $text !== '') {
            try { $personId = self::autoDetectPhone($personId, $text, $account, $logChannel); }
            catch (Throwable $e) { if (!empty($config['debug'])) error_log('[IgWebhookPipeline][phone-detect] ' . $e->getMessage()); }
        }

        $pageOn = fn(string $k) => !class_exists('IgPageSettings') || IgPageSettings::on($k);
        $formHandled = $storyRuleHandled || $gateHandled || $phoneGateHandled
            || ($pageOn('forms_enabled') && Forms::handle($channel, $channelUid, $text, $personId));

        $menuHandled = false;
        if (!$formHandled && $pageOn('botmenu_enabled')) $menuHandled = BotMenu::handle($channel, $channelUid, $text, null);

        $autoReplied = false;
        if (!$formHandled && !$menuHandled && $text !== '' && $text !== '/start') {
            $autoReplied = AutoReply::handle($channel, $channelUid, $text);
        }

        if (!$formHandled && !$menuHandled && !$autoReplied && $text !== '' && $text !== '/start' && class_exists('Ai')) {
            Ai::autoReply($channel, $channelUid, $personId, $text);
        }

        ChannelManager::setInstagramOverride(null);
        return 'direct';
    }

    /** @return int شناسه‌ی شخص بعد از ثبت شماره (اگر ادغام شد، شخص نگه‌داشته‌شده) */
    private static function autoDetectPhone(int $personId, string $text, array $account, string $logChannel): int
    {
        $phone = Persons::extractPhone($text);
        if (!$phone) return $personId;
        $person = Database::one("SELECT id, phone, full_name FROM eb_persons WHERE id = ?", [$personId]);
        if (!$person) return $personId;
        if (!empty($person['phone'])) return $personId; // شماره‌ی قبلی بازنویسی نمی‌شود
        $keeper = Persons::attachPhone($personId, $phone);
        if (class_exists('ApiLog')) {
            try { ApiLog::record($logChannel, 'phone_detected', ['person_id' => $personId, 'keeper_id' => $keeper, 'phone' => $phone], ['merged' => $keeper !== $personId], null, true, null); } catch (Throwable $e) {}
        }
        if (class_exists('Notifier')) {
            try {
                Notifier::push('message',
                    '📞 شماره‌ی ' . ($person['full_name'] ?: 'مخاطب') . ' از دایرکت شناسایی شد' . ($keeper !== $personId ? ' و با پروفایل قبلی‌اش یکی شد' : ''),
                    $phone . ' · پیج «' . ($account['label'] ?? '') . '»',
                    "index.php?page=inbox&person={$keeper}", $keeper);
            } catch (Throwable $e) {}
        }
        return $keeper;
    }

    private static function comment(array $account, int $accountId, array $payload, $driver, string $logChannel): string
    {
        $contact = $payload['contact'] ?? [];
        $comment = $payload['comment'] ?? [];
        $commentId = (string)($comment['id'] ?? '');
        $postId    = (string)($comment['post_id'] ?? '');
        $contactId = (string)($contact['id'] ?? ($comment['sender_ig_user_id'] ?? ''));

        if ($commentId !== '') {
            try {
                Database::run(
                    "INSERT INTO eb_ig_comments (comment_id, media_id, sender_id, sender_username, body, ig_account_id)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE body = VALUES(body), sender_username = VALUES(sender_username)",
                    [$commentId, $postId ?: null, $contactId ?: null, $contact['username'] ?? null, $comment['text'] ?? '', $accountId]
                );
            } catch (Throwable $e) {}
            // اگر این کامنت‌گذار را می‌شناسیم (همین پیج یا هم‌یوزرنیم در پیج دیگر)، کامنت به سابقه‌اش وصل شود
            try {
                $known = null;
                if ($contactId !== '') $known = Database::one("SELECT person_id FROM eb_identities WHERE channel = 'instagram' AND channel_uid = ?", [self::uid($accountId, $contactId)]);
                if (!$known && !empty($contact['username'])) $known = Database::one("SELECT person_id FROM eb_identities WHERE channel = 'instagram' AND username = ? LIMIT 1", [ltrim((string)$contact['username'], '@')]);
                if ($known) Database::run("UPDATE eb_ig_comments SET person_id = ? WHERE comment_id = ?", [(int)$known['person_id'], $commentId]);
            } catch (Throwable $e) {}
        }

        $autoHandled = false;
        if ($commentId !== '' && $postId !== '' && $contactId !== '') {
            $adapter = new InstagramRoboclickCommentAdapter($driver, $postId, self::uid($accountId, $contactId));
            $event = [
                'media_id'    => $postId,
                'comment_id'  => $commentId,
                'channel_uid' => self::uid($accountId, $contactId),
                'text'        => $comment['text'] ?? '',
            ];
            IgCommentAutomation::setCommentContext([
                'uid' => self::uid($accountId, $contactId), 'account_id' => $accountId,
                'username' => $contact['username'] ?? null, 'name' => $contact['name'] ?? null,
                'text' => (string)($comment['text'] ?? ''), 'media_id' => $postId,
            ]);
            try {
                $autoHandled = IgCommentAutomation::matchAndRun($adapter, $event, $accountId);
                Database::run(
                    "UPDATE eb_ig_comments SET replied_at = ?, replied_type = ? WHERE comment_id = ?",
                    [$autoHandled ? date('Y-m-d H:i:s') : null, $autoHandled ? 'auto' : null, $commentId]
                );
            } catch (Throwable $e) {
                if (class_exists('ApiLog')) ApiLog::record($logChannel, 'automation_error', ['comment_id' => $commentId], $e->getMessage(), null, false, $e->getMessage());
            } finally {
                IgCommentAutomation::setCommentContext(null);
            }
        }

        if (class_exists('Notifier')) {
            $who = !empty($contact['username']) ? ('@' . $contact['username']) : 'یک کاربر';
            Notifier::push(
                'message',
                $who . ' زیر یک پست اینستاگرام («' . $account['label'] . '») کامنت گذاشت' . ($autoHandled ? ' (خودکار پاسخ داده شد)' : ''),
                mb_substr((string)($comment['text'] ?? ''), 0, 200),
                'index.php?page=instagram'
            );
        }
        return 'comment';
    }

    private static function buttonClick(int $accountId, array $payload, $driver, array $config, string $logChannel): string
    {
        $channel = 'instagram';
        $contact = $payload['contact'] ?? [];
        $button  = $payload['button'] ?? [];
        $contactId = (string)($contact['id'] ?? '');
        $payloadVal = (string)($button['payload'] ?? ($payload['payload'] ?? ''));
        if ($contactId === '' || $payloadVal === '') return 'no_payload';
        $channelUid = self::uid($accountId, $contactId);

        if (class_exists('ApiLog')) {
            ApiLog::record($logChannel, 'button_click', ['account_id' => $accountId, 'payload' => $payloadVal], $payload, null, true, null);
        }

        ChannelManager::setInstagramOverride($driver);

        // اول: تأیید فالوی یک اتوماسیون کامنت معلق؟ (تأیید واقعی با کلیک، نه حدس متنی)
        $gateHandled = false;
        try { $gateHandled = IgCommentAutomation::handleButtonClickForGate($driver, $channelUid, $payloadVal); }
        catch (Throwable $e) { if (!empty($config['debug'])) error_log('[IgWebhookPipeline][gate-click] ' . $e->getMessage()); }

        // وگرنه: پیمایش منوی بات با «nav:KEY»
        if (!$gateHandled && strpos($payloadVal, 'nav:') === 0) {
            try { BotMenu::handle($channel, $channelUid, null, $payloadVal); }
            catch (Throwable $e) { if (!empty($config['debug'])) error_log('[IgWebhookPipeline][menu-click] ' . $e->getMessage()); }
        }

        ChannelManager::setInstagramOverride(null);
        return 'button_click';
    }
}
