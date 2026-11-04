<?php
/**
 * channels/InstagramDriver.php
 * کانال دایرکت اینستاگرام — با «Instagram API with Instagram Login» (بدون نیاز
 * به صفحه‌ی فیسبوک متصل؛ همان روش مدرنی که Meta برای حساب‌های Business/Creator
 * توصیه می‌کند).
 *
 * برخلاف خانواده‌ی بله/تلگرام (BaseBotDriver که یک توکن ساده دارند)، این کانال:
 *   - احراز هویتش OAuth دو مرحله‌ای است (کد → توکن کوتاه‌مدت → توکن ۶۰روزه)،
 *     نه یک توکن ثابت که ادمین از BotFather کپی کند. آن جریان در
 *     `modules/InstagramAuth.php` + `instagram-oauth-callback.php` است.
 *   - آدرس API خودِ `graph.instagram.com` است (نه graph.facebook.com)، با
 *     Authorization: Bearer، بدون نیاز به appsecret_proof.
 *   - رسانه‌ی ارسالی باید یک URL عمومی HTTPS باشد (نه آپلود چندبخشی مثل تلگرام)،
 *     پس برای عکس محلی، آدرس عمومی uploads/ ساخته می‌شود.
 *
 * منبع الگو: پلاگین «اینستینو» (نمونه‌ای که کارفرما فرستاد) — includes/Meta/Client.php
 * و includes/Meta/Webhook.php، با ساده‌سازی برای معماری تک‌حساب این پروژه.
 */

class InstagramDriver implements ChannelInterface
{
    const GRAPH_VERSION = 'v21.0';
    const API_BASE = 'https://graph.instagram.com/';

    private string $accessToken;
    private string $igUserId;

    /** برای ساختن URL عمومیِ عکسِ محلی (uploads/...) هنگام ارسال به Instagram Send API */
    private static ?string $baseUrl = null;
    public static function setBaseUrl(?string $url): void
    {
        self::$baseUrl = $url ? rtrim($url, '/') : null;
    }

    public function __construct(string $accessToken, string $igUserId)
    {
        $this->accessToken = $accessToken;
        $this->igUserId    = $igUserId;
    }

    public function name(): string
    {
        return 'instagram';
    }

    public function capabilities(): array
    {
        return ['text', 'photo', 'buttons', 'inbound'];
    }

    // ==================== ارسال ====================

    public function send(array $recipient, array $message): array
    {
        $chatId = $recipient['channel_uid'] ?? null;
        if (!$chatId) {
            return ['ok' => false, 'error' => 'no_channel_uid'];
        }

        $msg = [];
        if (!empty($message['photo']) && file_exists($message['photo'])) {
            $url = $this->publicUrlFor($message['photo']);
            if (!$url) {
                return ['ok' => false, 'error' => 'برای ارسال عکس به اینستاگرام، آدرس عمومی سایت (base_url در config.php) لازم است'];
            }
            $msg['attachment'] = ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]];
            // اینستاگرام کپشن جدا برای پیوست ندارد؛ اگر متنی هم بود جدا می‌فرستیم (پایین‌تر).
        } elseif (!empty($message['buttons'])) {
            $buttons = [];
            foreach ($message['buttons'] as $row) {
                foreach ((array)$row as $btn) {
                    if (count($buttons) >= 3) break;
                    if (!empty($btn['url'])) {
                        $buttons[] = ['type' => 'web_url', 'url' => $btn['url'], 'title' => mb_substr((string)($btn['text'] ?? ''), 0, 20)];
                    } elseif (isset($btn['callback']) || isset($btn['callback_data'])) {
                        $buttons[] = ['type' => 'postback',
                                      'payload' => mb_substr((string)($btn['callback'] ?? $btn['callback_data'] ?? ''), 0, 1000),
                                      'title'   => mb_substr((string)($btn['text'] ?? ''), 0, 20)];
                    }
                }
            }
            if ($buttons) {
                $msg['attachment'] = ['type' => 'template', 'payload' => [
                    'template_type' => 'button',
                    'text'          => mb_substr((string)($message['text'] ?? ''), 0, 640),
                    'buttons'       => $buttons,
                ]];
            } else {
                $msg['text'] = mb_substr((string)($message['text'] ?? ''), 0, 1000);
            }
        } else {
            $msg['text'] = mb_substr((string)($message['text'] ?? ''), 0, 1000);
        }

        $r = $this->call('POST', "{$this->igUserId}/messages", [
            'recipient' => ['id' => (string)$chatId],
            'message'   => $msg,
        ]);

        $ok = $r['ok'] && empty($r['body']['error']);
        $err = $ok ? null : ($r['body']['error']['message'] ?? ($r['error'] ?? 'send_failed'));

        // اگر عکس داشت و متن هم داشت (اینستاگرام کپشن روی رسانه ندارد)، متن را جدا بفرست.
        if ($ok && !empty($message['photo']) && trim((string)($message['text'] ?? '')) !== '') {
            $this->call('POST', "{$this->igUserId}/messages", [
                'recipient' => ['id' => (string)$chatId],
                'message'   => ['text' => mb_substr((string)$message['text'], 0, 1000)],
            ]);
        }

        return ['ok' => $ok, 'error' => $err, 'raw' => $r['body'] ?? $r['raw'] ?? null];
    }

    /** تبدیل مسیر محلی uploads/... به URL عمومی HTTPS (لازم برای Instagram Send API) */
    private function publicUrlFor(string $localPath): ?string
    {
        if (!self::$baseUrl) return null;
        $marker = 'uploads' . DIRECTORY_SEPARATOR;
        $pos = strrpos($localPath, $marker);
        $rel = $pos !== false ? substr($localPath, $pos) : ('uploads/' . basename($localPath));
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        return self::$baseUrl . '/' . ltrim($rel, '/');
    }

    // ==================== دریافت (Webhook) ====================

    /**
     * ورودی وب‌هوک متا به سبک Messenger Platform: entry[].messaging[].
     * این متد فقط یک آیتم messaging را نرمال می‌کند؛ حلقه‌زدن روی entry/messaging
     * در webhook/receive.php انجام می‌شود (چون یک درخواست می‌تواند چند رویداد داشته باشد).
     */
    public function handleInbound(array $payload): ?array
    {
        // اگر کل بدنه‌ی وب‌هوک داده شود (نه یک آیتم messaging)، اولین رویداد را دربیاور.
        if (isset($payload['entry']) && !isset($payload['sender'])) {
            foreach ((array)$payload['entry'] as $entry) {
                foreach ((array)($entry['messaging'] ?? []) as $m) {
                    $ev = $this->normalizeMessaging($m);
                    if ($ev) return $ev;
                }
            }
            return null;
        }
        return $this->normalizeMessaging($payload);
    }

    /**
     * برخلاف handleInbound (فقط اولین رویداد)، این متد همه‌ی رویدادهای یک وب‌هوک
     * دسته‌ای متا را برمی‌گرداند — متا می‌تواند چند entry/messaging را در یک POST
     * با هم بفرستد و اگر فقط اولی پردازش شود، بقیه‌ی پیام‌ها گم می‌شوند.
     * @return array<int,array>
     */
    public function allEvents(array $payload): array
    {
        $out = [];
        foreach ((array)($payload['entry'] ?? []) as $entry) {
            foreach ((array)($entry['messaging'] ?? []) as $m) {
                $ev = $this->normalizeMessaging($m);
                if ($ev) $out[] = $ev;
            }
        }
        return $out;
    }

    private function normalizeMessaging(array $m): ?array
    {
        $senderId = (string)($m['sender']['id'] ?? '');
        if ($senderId === '' || $senderId === $this->igUserId) return null; // پیام خودمان را نادیده بگیر (echo)

        // کلیک روی دکمه‌ی postback
        if (!empty($m['postback']['payload'])) {
            return [
                'channel_uid'   => $senderId,
                'username'      => null,
                'first_name'    => null,
                'last_name'     => null,
                'text'          => null,
                'callback_data' => (string)$m['postback']['payload'],
                'type'          => 'callback',
                'raw'           => $m,
            ];
        }

        $text = (string)($m['message']['text'] ?? '');
        $attachments = (array)($m['message']['attachments'] ?? []);
        $mediaType = null; $mediaId = null;
        foreach ($attachments as $att) {
            $t = (string)($att['type'] ?? '');
            $u = (string)($att['payload']['url'] ?? '');
            if ($u === '') continue;
            // اینستاگرام URL عمومیِ مستقیم می‌دهد (نه file_id مثل تلگرام) — نیازی به دانلود با توکن نیست.
            $mediaType = ($t === 'image' || $t === 'story_mention' || $t === 'share') ? 'photo' : 'document';
            $mediaId   = $u;
            break;
        }

        if ($text === '' && !$mediaId) return null; // پیام خالی/ناشناس (مثلاً "seen"/"delivery")

        return [
            'channel_uid' => $senderId,
            'username'    => null,   // اینستاگرام در وب‌هوک پیام، username نمی‌دهد؛ جدا با Graph API قابل واکشی است
            'first_name'  => null,
            'last_name'   => null,
            'text'        => $text !== '' ? $text : null,
            'phone'       => null,
            'type'        => $mediaId ? 'photo' : 'text',
            'media_type'  => $mediaType,
            'media_id'    => $mediaId,
            'raw'         => $m,
        ];
    }

    /** این کانال webhook را با API ثبت نمی‌کند (اشتراک فیلد پیام‌ها را OAuth callback انجام می‌دهد) */
    public function setWebhook(string $url): array
    {
        return ['ok' => true, 'error' => null, 'raw' => 'instagram subscribes fields during OAuth connect'];
    }

    // ==================== کمک‌کننده ====================

    private function call(string $method, string $path, array $jsonBody): array
    {
        $url = self::API_BASE . self::GRAPH_VERSION . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => json_encode($jsonBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);

        $body = json_decode((string)$raw, true);
        if (class_exists('ApiLog')) {
            ApiLog::record('instagram', $path, $jsonBody, $body ?? $raw, $code, ($code >= 200 && $code < 300), $cErr ?: null);
        }
        return ['ok' => ($raw !== false && $code >= 200 && $code < 300), 'body' => $body, 'raw' => $raw, 'code' => $code, 'error' => $cErr ?: null];
    }
}
