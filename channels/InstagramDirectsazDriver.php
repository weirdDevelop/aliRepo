<?php
/**
 * channels/InstagramDirectsazDriver.php
 * کانال دایرکت اینستاگرام از طریق واسط «دایرکت‌ساز» (developers.directsaz.com).
 *
 * برخلاف InstagramDriver.php (که مستقیم با graph.instagram.com و OAuth دومرحله‌ای
 * کار می‌کند)، اینجا فقط یک کلید API ثابت (`ds_live_...`) لازم است — دایرکت‌ساز
 * خودش Advanced Access/App Review متا را از قبل دارد. جزئیات پروتکل از
 * مستندات دایرکت‌ساز (نسخه‌ای که کارفرما فرستاد) گرفته شده:
 *   - ارسال: POST /send با action=dm|comment_reply|private_reply|like
 *   - وب‌هوک: GET برای تأیید (401 بدون توکن / 200 با توکن)، POST با
 *     X-Directsaz-Signature: t=…,v1=… برای رویدادها
 *   - محدودیت‌ها: متن ساده ۱۰۰۰ بایت، متن+دکمه ۶۴۰ کاراکتر و حداکثر ۳ دکمه،
 *     کاروسل ۱ تا ۱۰ کارت، پاسخ عمومی کامنت ۲۲۰۰ کاراکتر.
 *
 * ⚠️ صداقت در محدودیت باقی‌مانده: مستندات رسمی فقط شکل رویداد dm را نشان می‌دادند؛
 * شکل رویداد کامنت را از یک نمونه‌ی واقعی (که کارفرما از تب لاگ گرفت) استخراج کردیم:
 *   {type:"comment", account_id, sender:{id,username}, comment:{id,text,media_id}, actions:{...}}
 * چون این از مستندات رسمی نیست، اگر دایرکت‌ساز فیلد جدیدی اضافه/حذف کرد، اینجا باید
 * به‌روزرسانی شود. متدهای sendCommentReply/sendPrivateReply/sendLike/followStatus/listPosts
 * طبق مستندات رسمی (بخش‌های ۷ تا ۱۰) پیاده‌سازی شده‌اند.
 */

class InstagramDirectsazDriver implements ChannelInterface
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://developers.directsaz.com/api/v1')
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function name(): string
    {
        return 'instagram';
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'video', 'buttons', 'carousel', 'comment_reply', 'private_reply', 'like', 'follow_status', 'inbound'];
    }

    // ==================== ارسال (DM عادی — همان مسیری که Sender/Inbox صدا می‌زنند) ====================

    public function send(array $recipient, array $message): array
    {
        $chatId = $recipient['channel_uid'] ?? null;
        if (!$chatId) {
            return ['ok' => false, 'error' => 'no_channel_uid'];
        }

        $content = $this->buildContentBody($message);
        if (isset($content['error_before_send'])) {
            return ['ok' => false, 'error' => $content['error_before_send']];
        }
        $body = array_merge(['action' => 'dm', 'recipient_id' => (string)$chatId], $content);

        $r = $this->call('POST', '/send', $body);
        $ok = $r['ok'] && !empty($r['body']['success']);
        $err = $ok ? null : ($r['body']['msg'] ?? ($r['error'] ?? 'send_failed'));

        // اگر رسانه با متن همراه بود، اینستاگرام کپشن روی رسانه ندارد؛ متن را جدا بفرست.
        if ($ok && (!empty($message['photo']) || !empty($message['video']) || !empty($message['media_url'])) && trim((string)($message['text'] ?? '')) !== '') {
            $this->call('POST', '/send', [
                'action'       => 'dm',
                'recipient_id' => (string)$chatId,
                'text'         => $this->truncateBytes((string)$message['text'], 1000),
            ]);
        }

        return ['ok' => $ok, 'error' => $err, 'raw' => $r['body'] ?? $r['raw'] ?? null];
    }

    /**
     * پاسخ خصوصی به یک کامنت (هر کامنت فقط یک‌بار قابل پاسخ خصوصی است).
     * طبق بخش‌های ۷ و ۸ مستندات، private_reply فقط به متن محدود نیست — رسانه و
     * کاروسل هم با همین اکشن و comment_id به‌جای recipient_id ارسال می‌شوند؛
     * پس $textOrMessage هم رشته‌ی ساده (سازگار با نسخه‌ی قبلی این متد) و هم
     * آرایه‌ی کامل پیام (['text'=>..,'photo'=>..,'carousel'=>..]) را قبول می‌کند.
     */
    public function sendPrivateReply(string $commentId, $textOrMessage): array
    {
        $message = is_array($textOrMessage) ? $textOrMessage : ['text' => (string)$textOrMessage];
        $content = $this->buildContentBody($message);
        if (isset($content['error_before_send'])) {
            return ['ok' => false, 'error' => $content['error_before_send']];
        }
        $body = array_merge(['action' => 'private_reply', 'comment_id' => $commentId], $content);
        $r = $this->call('POST', '/send', $body);
        return $this->simpleResult($r);
    }

    /** پاسخ عمومی زیر یک کامنت — طبق مستندات فقط متن دارد (بخش ۹)، رسانه/کاروسل مستند نشده */
    public function sendCommentReply(string $commentId, string $text): array
    {
        $r = $this->call('POST', '/send', [
            'action'     => 'comment_reply',
            'comment_id' => $commentId,
            'text'       => mb_substr($text, 0, 2200),
        ]);
        return $this->simpleResult($r);
    }

    /**
     * بخش مشترکِ ساختِ بدنه‌ی پیام (type/text/media_url/buttons/cards) — بین
     * send() (به recipient_id) و sendPrivateReply() (به comment_id) مشترک است،
     * چون هر دو یک اکشن با «مقصد» متفاوت هستند، نه دو پروتکل جدا.
     */
    private function buildContentBody(array $message): array
    {
        if (!empty($message['carousel']) && is_array($message['carousel'])) {
            return [
                'type'  => 'carousel',
                'cards' => array_slice(array_map(function ($card) {
                    $out = [
                        'title'     => mb_substr((string)($card['title'] ?? ''), 0, 80),
                        'image_url' => (string)($card['image_url'] ?? ''),
                    ];
                    if (!empty($card['subtitle'])) $out['subtitle'] = (string)$card['subtitle'];
                    elseif (!empty($card['description'])) $out['subtitle'] = (string)$card['description'];
                    if (!empty($card['buttons'])) $out['buttons'] = array_slice($this->normalizeButtons($card['buttons']), 0, 3);
                    return $out;
                }, $message['carousel']), 0, 10),
            ];
        }

        if (!empty($message['media_url']) || !empty($message['photo']) || !empty($message['video'])) {
            $isVideo = !empty($message['video']) || (($message['media_type'] ?? '') === 'video');
            if (!empty($message['media_url'])) {
                // آدرس عمومی از پیش آماده (مثلاً از ابزار «ارسال پیشرفته») — مستقیم استفاده می‌شود
                $url = (string)$message['media_url'];
            } else {
                $localPath = $isVideo ? $message['video'] : $message['photo'];
                $url = $this->publicUrlFor($localPath);
                if (!$url) {
                    return ['error_before_send' => 'برای ارسال رسانه به اینستاگرام، آدرس عمومی سایت (base_url در config.php) لازم است'];
                }
            }
            return ['type' => $isVideo ? 'video' : 'image', 'media_url' => $url];
        }

        if (!empty($message['buttons'])) {
            $buttons = array_slice($this->normalizeButtons($message['buttons']), 0, 3);
            if ($buttons) {
                return ['text' => mb_substr((string)($message['text'] ?? ''), 0, 640), 'buttons' => $buttons];
            }
        }

        return ['text' => $this->truncateBytes((string)($message['text'] ?? ''), 1000)];
    }

    /** لایک یک پیام دایرکت (شناسه‌ها را از actions همان رویداد وب‌هوک بردار) */
    public function sendLike(string $recipientId, string $messageId): array
    {
        $r = $this->call('POST', '/send', [
            'action'       => 'like',
            'recipient_id' => $recipientId,
            'message_id'   => $messageId,
        ]);
        return $this->simpleResult($r);
    }

    /** آیا این کاربر (IGSCOPED) پیج را فالو کرده؟ */
    public function followStatus(string $igScopedUserId): array
    {
        $r = $this->call('GET', '/users/' . rawurlencode($igScopedUserId) . '/follow-status', []);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['body']['msg'] ?? $r['error'] ?? 'follow_status_failed'];
        return ['ok' => true, 'follows' => !empty($r['body']['follows'])];
    }

    /** فهرست پست‌های پیج (برای گرفتن comment_id واقعی جهت تست دستی) */
    public function listPosts(int $limit = 30, ?string $after = null): array
    {
        $q = ['limit' => max(1, min(100, $limit))];
        if ($after) $q['after'] = $after;
        $r = $this->call('GET', '/posts?' . http_build_query($q), []);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['body']['msg'] ?? $r['error'] ?? 'list_posts_failed'];
        return ['ok' => true, 'data' => $r['body']];
    }

    private function simpleResult(array $r): array
    {
        $ok = $r['ok'] && !empty($r['body']['success']);
        return ['ok' => $ok, 'error' => $ok ? null : ($r['body']['msg'] ?? ($r['error'] ?? 'failed')), 'raw' => $r['body'] ?? $r['raw'] ?? null];
    }

    private function normalizeButtons($rawButtons): array
    {
        // دایرکت‌ساز اصلاً مفهوم «ردیف» ندارد — یک آرایه‌ی تخت حداکثر ۳تایی می‌خواهد
        // (بخش ۵ مستندات). ولی هم ممکن است رشته‌ی JSON کاربر مستقیماً تخت باشد
        // (`[{text,url}]`، دقیقاً مثل نمونه‌ی خودِ بخش ۸ برای دکمه‌ی کارت کاروسل)،
        // هم ممکن است از داخل اپلیکیشن با فرمت داخلیِ ردیف‌دار (`Templates::normalizeButtons`،
        // یعنی `[[{text,url}]]`) بیاید (وقتی پاسخ‌خودکار/کمپین/منوی بات به این کانال می‌فرستد).
        // هر دو را تشخیص می‌دهیم و تخت می‌کنیم.
        $isFlat = isset($rawButtons[0]) && is_array($rawButtons[0]) && isset($rawButtons[0]['text']);
        $rows = $isFlat ? [$rawButtons] : (array)$rawButtons;

        $buttons = [];
        foreach ($rows as $row) {
            foreach ((array)$row as $btn) {
                if (count($buttons) >= 3) break 2;
                if (!is_array($btn)) continue;
                $title = mb_substr((string)($btn['text'] ?? $btn['title'] ?? ''), 0, 20);
                if ($title === '') continue;
                if (!empty($btn['url'])) {
                    $buttons[] = ['title' => $title, 'url' => (string)$btn['url']];
                } elseif (isset($btn['callback']) || isset($btn['payload']) || isset($btn['callback_data'])) {
                    $buttons[] = ['title' => $title, 'payload' => mb_substr((string)($btn['payload'] ?? $btn['callback'] ?? $btn['callback_data'] ?? ''), 0, 1000)];
                }
            }
        }
        return $buttons;
    }

    /** بریدن متن طبق سقف بایتی (نه کاراکتری) — مطابق «۱۰۰۰ بایت UTF-8» مستندات */
    private function truncateBytes(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) return $text;
        // برش امن روی مرز کاراکتر UTF-8 چندبایتی
        $out = $text;
        while (strlen($out) > $maxBytes) {
            $out = mb_substr($out, 0, -1);
        }
        return $out;
    }

    /** تبدیل مسیر محلی uploads/... به URL عمومی HTTPS (طبق تصمیم: میزبانی رسانه از همان سرور اعلان‌بان) */
    private static ?string $publicSiteBaseUrl = null;
    public static function setBaseUrl(?string $url): void
    {
        self::$publicSiteBaseUrl = $url ? rtrim($url, '/') : null;
    }

    private function publicUrlFor(string $localPath): ?string
    {
        if (!self::$publicSiteBaseUrl) return null;
        $marker = 'uploads' . DIRECTORY_SEPARATOR;
        $pos = strrpos($localPath, $marker);
        $rel = $pos !== false ? substr($localPath, $pos) : ('uploads/' . basename($localPath));
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        return self::$publicSiteBaseUrl . '/' . ltrim($rel, '/');
    }

    // ==================== دریافت (Webhook) ====================

    /**
     * فقط رویدادهای type=dm را نرمال می‌کند (تنها شکلی که در مستندات دریافتی
     * مستند شده — بخش ۴). اگر بعداً شکل واقعی رویداد کامنت را دیدیم/تست کردیم،
     * اینجا اضافه می‌شود.
     */
    public function handleInbound(array $payload): ?array
    {
        $events = $this->allEvents($payload);
        return $events[0] ?? null;
    }

    /** @return array<int,array> هر عنصر یا event_kind='dm' یا event_kind='comment' دارد */
    public function allEvents(array $payload): array
    {
        $out = [];
        foreach ((array)($payload['events'] ?? []) as $ev) {
            $n = $this->normalizeEvent((array)$ev);
            if ($n) $out[] = $n;
        }
        return $out;
    }

    private function normalizeEvent(array $ev): ?array
    {
        $type = (string)($ev['type'] ?? '');

        if ($type === 'dm') {
            $senderId = (string)($ev['sender']['id'] ?? '');
            if ($senderId === '') return null; // echo پیام‌های خودمان اصلاً تحویل داده نمی‌شود (طبق مستندات)

            $text = (string)($ev['message']['text'] ?? '');
            if ($text === '') return null; // پیام رسانه‌ای بدون متن؛ فرمت attachment ورودی مستند نشده

            return [
                'event_kind'  => 'dm',
                'channel_uid' => $senderId,
                'username'    => null, // یوزرنیم در وب‌هوکِ dm نمی‌آید (برخلاف comment — پایین‌تر)
                'first_name'  => null,
                'last_name'   => null,
                'text'        => $text,
                'phone'       => null,
                'type'        => 'text',
                // شناسه‌های آماده برای پاسخ (لایک/پاسخ) — طبق «قانون طلایی» مستندات:
                // مستقیم از همین رویداد بردار، نه از پاسخ‌های قبلی.
                'directsaz_message_id' => (string)($ev['message']['id'] ?? ''),
                'directsaz_account_id' => (string)($ev['account_id'] ?? ''),
                'raw' => $ev,
            ];
        }

        if ($type === 'comment') {
            // شکل واقعی (تأیید شده از یک نمونه‌ی زنده، نه مستندات — مستندات این شکل را نداشتند):
            // {type:"comment", account_id, sender:{id,username}, comment:{id,text,media_id}, actions:{...}}
            $senderId = (string)($ev['sender']['id'] ?? '');
            $commentId = (string)($ev['comment']['id'] ?? '');
            if ($senderId === '' || $commentId === '') return null;

            $username = $ev['sender']['username'] ?? null;
            return [
                'event_kind'  => 'comment',
                'channel_uid' => $senderId,
                // برخلاف dm، کامنت یوزرنیم واقعی می‌دهد — همین را (به‌جای first_name واقعی که
                // نداریم) به‌عنوان نام نمایشی استفاده می‌کنیم تا «ناشناس» نماند.
                'username'    => $username,
                'first_name'  => $username ? ('@' . $username) : null,
                'last_name'   => null,
                'phone'       => null,
                'comment_id'  => $commentId,
                'media_id'    => (string)($ev['comment']['media_id'] ?? ''),
                'text'        => (string)($ev['comment']['text'] ?? ''),
                'raw' => $ev,
            ];
        }

        // نوع دیگری از رویداد — فعلاً پردازش خودکار نمی‌شود، فقط لاگ خام می‌ماند
        // (لاگ‌کردن در webhook/directsaz.php از طریق ApiLog انجام می‌شود، نه اینجا).
        return null;
    }

    /** ثبت وب‌هوک از طریق API این کانال انجام نمی‌شود — در پنل دایرکت‌ساز به‌ازای هر اپ/پیج دستی ثبت می‌شود */
    public function setWebhook(string $url): array
    {
        return ['ok' => true, 'error' => null, 'raw' => 'آدرس وب‌هوک را در پنل دایرکت‌ساز (بخش اپلیکیشن این پیج) ثبت کن'];
    }

    // ==================== کمک‌کننده ====================

    private function call(string $method, string $path, array $jsonBody): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ];
        if ($method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        $body = json_decode((string)$raw, true);
        if (class_exists('ApiLog')) {
            ApiLog::record('instagram_directsaz', $path, $jsonBody, $body ?? $raw, $code, ($code >= 200 && $code < 300), $cErr ?: null);
        }
        return ['ok' => ($raw !== false && $code >= 200 && $code < 300), 'body' => is_array($body) ? $body : [], 'raw' => $raw, 'code' => $code, 'error' => $cErr ?: null];
    }

    // ==================== تأیید امضای وب‌هوک ====================

    /** v1 = HMAC-SHA256(webhook_token, "{t}.{raw_body}") — طبق بخش ۴ مستندات */
    public static function verifySignature(string $rawBody, string $signatureHeader, string $webhookToken): bool
    {
        if ($webhookToken === '' || $signatureHeader === '') return false;
        $t = null; $v1 = null;
        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (strpos($part, 't=') === 0) $t = substr($part, 2);
            elseif (strpos($part, 'v1=') === 0) $v1 = substr($part, 3);
        }
        if ($t === null || $v1 === null) return false;
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $webhookToken);
        return hash_equals($expected, $v1);
    }
}
