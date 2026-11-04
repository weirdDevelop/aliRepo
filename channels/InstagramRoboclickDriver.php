<?php
require_once __DIR__ . '/../core/Idempotency.php';   // 2.1.5: stable Idempotency-Key (R-04)
/**
 * channels/InstagramRoboclickDriver.php
 * کانال دایرکت اینستاگرام از طریق اتصال مستقیم (روش قدیمی؛ آدرس در InstagramRoboclick::baseUrl()).
 *
 * برخلاف InstagramDirectsazDriver که کل سیستم را تک‌پیجی فرض می‌کند، این درایور
 * per-account است: هر نمونه با یک Account Key (`ak_...`) مشخص ساخته می‌شود و
 * فقط برای همان پیج کار می‌کند. مسئول انتخاب «کدام پیج» ماژول
 * modules/InstagramRoboclick.php است، نه این کلاس.
 *
 * طبق مستندات سرویس‌دهنده (document-roboclick.md):
 *   - ارسال دایرکت: POST /External/ExternalDirect/SendDirect (متن + دکمه با
 *     /SendDirectWithButtons)
 *   - پاسخ عمومی کامنت: POST /External/ExternalPost/SendComment
 *   - پاسخ خصوصی کامنت: POST /External/ExternalPost/SendPrivateReplyToComment
 *   - همه‌ی این‌ها ۲۰۲ برمی‌گردانند (صف)، نه تحویل فوری؛ اینجا فقط پذیرفته‌شدن
 *     در صف را «موفق» حساب می‌کنیم — دقیقاً مثل الگوی موجود برای دایرکت‌ساز.
 *   - امضای وب‌هوک: HMAC_SHA256(secret, "{timestamp}.{rawBody}") با هدر
 *     X-RoboClick-Signature: sha256=<hex>
 */

class InstagramRoboclickDriver implements ChannelInterface
{
    private string $accountKey;
    private string $baseUrl;

    public function __construct(string $accountKey, string $baseUrl = '')
    {
        $this->accountKey = $accountKey;
        if ($baseUrl === '' && class_exists('InstagramRoboclick')) $baseUrl = InstagramRoboclick::baseUrl();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function name(): string
    {
        return 'instagram';
    }

    public function capabilities(): array
    {
        return ['text', 'buttons', 'comment_reply', 'private_reply', 'follow_status', 'inbound'];
    }

    // ==================== ارسال دایرکت ====================

    public function send(array $recipient, array $message): array
    {
        $contactId = $this->rawContactId($recipient['channel_uid'] ?? null);
        if (!$contactId) {
            return ['ok' => false, 'error' => 'no_channel_uid'];
        }

        $text = (string)($message['text'] ?? '');
        if ($text !== '' && mb_strlen($text) > 1000) {
            $text = mb_substr($text, 0, 1000);
        }

        // اگر دکمه دارد، از مسیر SendDirectWithButtons استفاده کن (حداکثر ۳ دکمه)
        if (!empty($message['buttons']) && is_array($message['buttons'])) {
            $buttons = array_slice($this->normalizeButtons($message['buttons']), 0, 3);
            $r = $this->call('POST', '/External/ExternalDirect/SendDirectWithButtons', [
                'contactId' => $contactId,
                'text'      => $text,
                'buttons'   => $buttons,
            ], true);
            $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
            return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
        }

        if ($text === '') {
            return ['ok' => false, 'error' => 'empty_message'];
        }

        $r = $this->call('POST', '/External/ExternalDirect/SendDirect', [
            'contactId'            => $contactId,
            'chatRoomMessageType'  => 1,
            'text'                 => $text,
        ], true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    /**
     * پاسخ خصوصی به یک کامنت (اولین تماس با کسی که فقط کامنت گذاشته، وقتی
     * پنجره‌ی ۲۴ساعته‌ی دایرکت هنوز باز نشده — دقیقاً همان قانون direct_window_closed).
     * فقط کامنتی که حداکثر ۷ روز پیش ثبت شده قابل استفاده است؛ برای هر کامنت فقط یک بار.
     * دکمه فقط با chatRoomMessageType=1 کار می‌کند (طبق بخش ۶.۲ مستندات).
     */
    public function sendPrivateReplyToComment(string $contactId, string $commentId, string $postId, string $text, array $buttons = []): array
    {
        $body = [
            'contactId'           => $this->rawContactId($contactId),
            'commentId'           => $commentId,
            'postId'              => $postId,
            'chatRoomMessageType' => 1,
            'text'                => mb_substr($text, 0, 1000),
        ];
        if ($buttons) $body['buttons'] = array_slice($this->normalizeButtons($buttons), 0, 3);
        $r = $this->call('POST', '/External/ExternalPost/SendPrivateReplyToComment', $body, true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    /** آماده‌سازی آرایه‌ی دکمه‌ها به فرمت سرویس‌دهنده (type=link|postback) — مشترک بین send و private reply */
    private function normalizeButtons(array $buttons): array
    {
        $out = [];
        foreach ($buttons as $b) {
            if (!empty($b['url'])) {
                $out[] = ['type' => 'link', 'title' => (string)($b['text'] ?? $b['title'] ?? ''), 'url' => $b['url']];
            } else {
                $out[] = ['type' => 'postback', 'title' => (string)($b['text'] ?? $b['title'] ?? ''), 'payload' => (string)($b['payload'] ?? $b['callback'] ?? '')];
            }
        }
        return $out;
    }

    /** پاسخ عمومی زیر یک کامنت، یا کامنت جدید مستقل اگر $commentId خالی باشد. */
    public function sendCommentReply(string $postId, string $text, string $commentId = ''): array
    {
        $body = ['postId' => $postId, 'text' => $text];
        if ($commentId !== '') $body['replayId'] = $commentId;
        $r = $this->call('POST', '/External/ExternalPost/SendComment', $body, false);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    /** چک وضعیت فالو یک مخاطب (فقط چک — اجرای «فالو اجباری» کامل در سرویس‌دهنده را پیاده نکرده‌ایم) */
    public function followStatus(string $contactId, bool $forceRefresh = false): array
    {
        $r = $this->call('POST', '/External/ExternalAutomation/GetFollowerStatus', [
            'contactId'    => $this->rawContactId($contactId),
            'forceRefresh' => $forceRefresh,
        ], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->errorFrom($r)];
        return ['ok' => true, 'is_follower' => !empty($r['body']['isFollower']), 'raw' => $r['body']];
    }

    // ==================== ویترین/کاروسل ====================

    /** ۱ تا ۱۰ کارت؛ هر کارت: ['title','subtitle','photo'(خروجی uploadImage),'buttons'=>[...]] */
    public function sendShowcase(string $contactId, array $cards): array
    {
        $cards = array_slice($cards, 0, 10);
        foreach ($cards as &$c) {
            $c['title'] = mb_substr((string)($c['title'] ?? ''), 0, 80);
            if (isset($c['subtitle'])) $c['subtitle'] = mb_substr((string)$c['subtitle'], 0, 80);
            if (!empty($c['buttons'])) $c['buttons'] = array_slice($this->normalizeButtons($c['buttons']), 0, 3);
        }
        unset($c);
        $r = $this->call('POST', '/External/ExternalDirect/SendShowcase', [
            'contactId' => $this->rawContactId($contactId),
            'cards'     => $cards,
        ], true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    // ==================== ویس ====================

    /** فایل صوتی محلی را آپلود و مقدار `audio` را برمی‌گرداند (برای پاس‌دادن به sendVoice) */
    public function uploadAudio(string $localPath): array
    {
        $r = $this->upload('/External/ExternalMedia/UploadAudio', 'file', $localPath);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'audio' => $r['body']['audio'] ?? null, 'raw' => $r['body']];
    }

    public function sendVoice(string $contactId, string $audioValue): array
    {
        $r = $this->call('POST', '/External/ExternalDirect/SendDirect', [
            'contactId'           => $this->rawContactId($contactId),
            'chatRoomMessageType' => 2,
            'audio'               => $audioValue,
        ], true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    // ==================== پست و استوری ====================

    /** فایل عکس محلی (فقط jpg/jpeg، حداکثر ۸MB طبق مستندات) را آپلود و مقدار `photo` را برمی‌گرداند */
    /** ۲.۱.۳: اینستاگرام فقط JPG می‌پذیرد؛ هر فایل دیگری (PNG/WebP/…) قبل از آپلود به JPG تبدیل می‌شود. [مسیر, مسیر موقت یا null] */
    protected static function toJpg(string $p): array
    {
        $h = @file_get_contents($p, false, null, 0, 3);
        if ($h !== false && strncmp($h, "\xFF\xD8\xFF", 3) === 0) return [$p, null];
        $raw = @file_get_contents($p);
        if ($raw === false || !function_exists('imagecreatefromstring')) return [$p, null];
        $im = @imagecreatefromstring($raw);
        if (!$im) return [$p, null];
        $w = imagesx($im); $hh = imagesy($im);
        $bg = imagecreatetruecolor($w, $hh); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); imagecopy($bg, $im, 0, 0, 0, 0, $w, $hh);
        $t = tempnam(sys_get_temp_dir(), 'ebj'); $out = $t . '.jpg'; @unlink($t);
        imagejpeg($bg, $out, 90); imagedestroy($im); imagedestroy($bg);
        return is_file($out) ? [$out, $out] : [$p, null];
    }

    public function uploadImage(string $localPath): array
    {
        [$localPath, $tmpJ] = self::toJpg($localPath);
        $r = $this->upload('/External/ExternalMedia/UploadImage', 'file', $localPath);
        if ($tmpJ) @unlink($tmpJ);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'photo' => $r['body']['photo'] ?? null, 'raw' => $r['body']];
    }

    public function sendPost(string $text, string $photoValue): array
    {
        $r = $this->call('POST', '/External/ExternalPost/SendPost', ['text' => $text, 'photo' => $photoValue], true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    public function sendStory(string $photoValue): array
    {
        $r = $this->call('POST', '/External/ExternalPost/SendStory', ['photo' => $photoValue], true);
        $ok = $r['ok'] && in_array((int)($r['status'] ?? 0), [200, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->errorFrom($r), 'raw' => $r['body'] ?? null];
    }

    public function listPosts(?string $cursor = null, int $limit = 25): array
    {
        $qs = '?limit=' . (int)$limit . ($cursor ? '&cursor=' . urlencode($cursor) : '');
        $r = $this->call('GET', '/External/ExternalPost/GetPosts' . $qs, [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->errorFrom($r)];
        return ['ok' => true, 'posts' => $r['body']['items'] ?? $r['body']['posts'] ?? [], 'cursor' => $r['body']['cursor'] ?? null];
    }

    /** فهرست استوری‌های فعال پیج (بخش ۷ مستندات: GET /api/v1/stories) */
    public function listStories(int $limit = 25): array
    {
        $r = $this->call('GET', '/api/v1/stories?limit=' . (int)$limit, [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->errorFrom($r)];
        return ['ok' => true, 'stories' => $r['body']['items'] ?? $r['body']['stories'] ?? []];
    }

    // ==================== ریپلای استوری: رسانه‌ی امن ====================

    /** خواندن تصویر/ویدیوی یک ریپلای استوری از endpoint امن سرویس‌دهنده (نه لینک مستقیم متا) */
    public function fetchStoryMedia(string $resourceId): array
    {
        $r = $this->call('GET', '/api/v1/story-media/' . rawurlencode($resourceId), [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->errorFrom($r)];
        return ['ok' => true, 'raw' => $r['body']];
    }

    // ==================== دریافت (وب‌هوک) ====================

    /**
     * نرمال‌سازی رویداد دایرکت سرویس‌دهنده. کامنت/کلیک دکمه چون شکل‌شان کاملاً
     * متفاوت است در webhook/roboclick.php مستقیم پردازش می‌شوند، نه اینجا.
     */
    public function handleInbound(array $payload): ?array
    {
        if (($payload['type'] ?? '') !== 'direct') return null;
        $msg = $payload['message'] ?? [];
        $contact = $payload['contact'] ?? [];
        $uid = (string)($contact['id'] ?? $contact['ig_user_id'] ?? '');
        if ($uid === '') return null;
        return [
            'channel_uid'     => $uid,
            'username'        => $contact['username'] ?? null,
            'first_name'      => $contact['name'] ?? null,
            'text'            => $msg['text'] ?? '',
            'type'            => 'text',
            'is_story_reply'  => !empty($msg['is_story_reply']),
            'raw'             => $payload,
        ];
    }

    public function setWebhook(string $url): array
    {
        // ثبت آدرس وب‌هوک در سرویس‌دهنده فقط از داخل پنل خودشان انجام می‌شود، نه از طریق API.
        return ['ok' => false, 'error' => 'manual_only', 'note' => 'آدرس وب‌هوک باید دستی در پنل سرویس‌دهنده (Account API → Webhook URL) وارد شود'];
    }

    // ==================== امضای وب‌هوک ====================

    public static function verifySignature(string $rawBody, string $timestamp, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $timestamp === '') return false;
        // فرمت هدر: "sha256=<hex>"
        $given = $signatureHeader;
        if (stripos($given, 'sha256=') === 0) $given = substr($given, 7);
        $given = strtolower(trim($given));
        if ($given === '') return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return hash_equals($expected, $given);
    }

    /**
     * eb_identities.channel_uid برای سرویس‌دهنده به شکل "rc{accountId}_{contactId}"
     * ذخیره می‌شود (تا بین پیج‌های مختلف تداخل نکند)؛ برای فراخوانی API باید
     * فقط عدد contactId خام استخراج شود.
     */
    private function rawContactId(?string $channelUid): ?string
    {
        if (!$channelUid) return null;
        if (preg_match('/^rc\d+_(.+)$/', $channelUid, $m)) return $m[1];
        return $channelUid;
    }

    // ==================== HTTP ====================

    private function call(string $method, string $path, array $body = [], bool $idempotent = false): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json', 'X-Api-Key: ' . $this->accountKey];
        // ۲.۰.۶: سرویس برای هر درخواستِ تغییردهنده کلید یکتا می‌خواهد (پاسخ عمومی کامنت و … هم)
        if ($idempotent || strtoupper($method) !== 'GET') {
            // 2.1.5: deterministic inside an Idempotency scope (event/queue row/post); random otherwise
            $headers[] = 'Idempotency-Key: ' . Idempotency::key($path, $body);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $result = ['ok' => false, 'status' => 0, 'error' => $err ?: 'curl_error'];
            $this->log($path, $body, $result);
            return $result;
        }
        $decoded = json_decode($raw, true);
        $result = ['ok' => $status > 0 && $status < 500, 'status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $raw]];
        $this->log($path, $body, $result);
        return $result;
    }

    /**
     * هر تماس با API سرویس‌دهنده (ارسال دایرکت، پاسخ عمومی/خصوصی کامنت، فالو، هرچی) خودکار
     * لاگ می‌شود — تا در تب «لاگ خام → سرویس‌دهنده» بشود دقیقاً دید که واقعاً چه درخواستی رفت
     * و سرویس‌دهنده چه جوابی داد (مثلاً وقتی پاسخ عمومی کامنت ظاهراً هیچ اتفاقی نمی‌افتد، اینجا
     * مشخص می‌شود که یا اصلاً درخواست نرفته، یا رفته و ۲۰۲ گرفته ولی چیز دیگری (مثل تنظیمات
     * ضدخطای پنل سرویس‌دهنده — کول‌داون/سرکوب بعد از N بار) آن را در سمت خودِ سرویس‌دهنده متوقف کرده).
     */
    private function log(string $path, array $body, array $result): void
    {
        if (!class_exists('ApiLog')) return;
        $ok = !empty($result['ok']) && (($result['status'] ?? 0) < 400);
        $err = $ok ? null : $this->errorFrom($result);
        try {
            ApiLog::record('instagram_roboclick', trim($path, '/'), $body, $result['body'] ?? $result, $result['status'] ?? null, $ok, $err);
        } catch (Throwable $e) {}
    }

    /** آپلود چندبخشی (multipart) یک فایل محلی — برای UploadImage/UploadAudio */
    private function upload(string $path, string $fieldName, string $localPath): array
    {
        if (!is_file($localPath)) {
            return ['ok' => false, 'error' => 'file_not_found'];
        }
        $url = $this->baseUrl . $path;
        $headers = ['X-Api-Key: ' . $this->accountKey, 'Idempotency-Key: ' . bin2hex(random_bytes(12))]; // Content-Type چندبخشی را curl خودش با boundary می‌سازد

        // mime واقعی فایل را با finfo تشخیص بده (نه فقط پسوند) تا curl آن را درست به سرور بدهد
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) { $mime = finfo_file($finfo, $localPath) ?: null; finfo_close($finfo); }
        }
        $file = $mime ? new CURLFile($localPath, $mime, basename($localPath)) : new CURLFile($localPath);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => [$fieldName => $file],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $result = ['ok' => false, 'status' => 0, 'error' => 'curl: ' . ($err ?: 'اتصال برقرار نشد')];
            $this->log($path, ['file' => basename($localPath)], $result);
            return $result;
        }
        $decoded = json_decode($raw, true);
        $ok = $status > 0 && $status < 300;
        $errMsg = null;
        if (!$ok) {
            if (is_array($decoded)) {
                $errMsg = (string)($decoded['error'] ?? $decoded['message'] ?? $decoded['msg'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE));
            } else {
                $errMsg = $status > 0 ? "HTTP {$status}: " . mb_substr((string)$raw, 0, 300) : 'پاسخ خالی از سرور سرویس‌دهنده';
            }
        }
        $result = ['ok' => $ok, 'status' => $status, 'body' => is_array($decoded) ? $decoded : ['raw' => $raw], 'error' => $errMsg];
        $this->log($path, ['file' => basename($localPath)], $result);
        return $result;
    }

    private function errorFrom(array $r): string
    {
        if (!empty($r['error'])) return (string)$r['error'];
        $body = $r['body'] ?? [];
        $known = $body['error'] ?? $body['message'] ?? $body['msg'] ?? null;
        if ($known !== null) return (string)$known;
        // اگر پاسخ ساختار شناخته‌شده نداشت (مثلاً یک صفحه‌ی خطای HTML، یا مسیر اشتباه)،
        // به‌جای پیام مبهم، خودِ کد وضعیت + تکه‌ای از پاسخ خام را نشان بده تا قابل‌عیب‌یابی باشد.
        $raw = isset($body['raw']) ? mb_substr((string)$body['raw'], 0, 200) : '';
        return 'HTTP ' . ($r['status'] ?? 0) . ($raw !== '' ? (': ' . $raw) : '');
    }
}
