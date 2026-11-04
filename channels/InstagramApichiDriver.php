<?php
/**
 * channels/InstagramApichiDriver.php
 * کانال دایرکت اینستاگرام از طریق API خودمان «یار API» (apichi) به‌جای اتصال مستقیم.
 *
 * چرا از InstagramRoboclickDriver ارث می‌برد؟
 *   همه‌ی ماژول‌های موجود (InstagramRoboclickCommentAdapter، IgCommentAutomation، IgPhoneGate،
 *   Sender، ChannelManager override) با همان امضای متدهای درایور سرویس‌دهنده کار می‌کنند و بعضی
 *   type-hint آن را دارند. این کلاس دقیقاً همان متدهای عمومی را بازنویسی می‌کند ولی به‌جای
 *   اتصال مستقیم با Account Key، به  {yarapi}/api/v1  با کلید یار API و igAccountId
 *   می‌رود. پس کل پایپ‌لاین بدون هیچ تغییری روی یار API هم کار می‌کند.
 *
 * یک کلید یار API برای همه‌ی پیج‌ها کافی است؛ هر نمونه‌ی این درایور مخصوص یک پیج (igAccountId) است.
 * channel_uid مخاطب‌ها مثل قبل به شکل "rc{eb_ig_accounts.id}_{contactId}" است.
 */

require_once __DIR__ . '/InstagramRoboclickDriver.php';

class InstagramApichiDriver extends InstagramRoboclickDriver
{
    private string $apiKey;
    private string $apiBase;
    private int $igAccountId;

    public function __construct(string $apiKey, string $apiBase, int $igAccountId)
    {
        parent::__construct('', 'about:blank'); // فقط برای سازگاری؛ استفاده نمی‌شود (همه‌ی درخواست‌ها از call() خود یار API می‌روند)
        $this->apiKey = trim($apiKey);
        $this->apiBase = rtrim($apiBase, '/');
        $this->igAccountId = $igAccountId;
    }

    public function igAccountId(): int
    {
        return $this->igAccountId;
    }

    public function capabilities(): array
    {
        return ['text', 'buttons', 'comment_reply', 'private_reply', 'follow_status', 'inbound', 'showcase', 'voice', 'publish'];
    }

    // ==================== ارسال دایرکت ====================

    public function send(array $recipient, array $message): array
    {
        $contactId = $this->contactId($recipient['channel_uid'] ?? null);
        if (!$contactId) return ['ok' => false, 'error' => 'no_channel_uid'];

        $text = (string)($message['text'] ?? '');
        if ($text !== '' && mb_strlen($text) > 1000) $text = mb_substr($text, 0, 1000);

        $body = ['igAccountId' => $this->igAccountId, 'contactId' => $contactId];
        if (!empty($message['buttons']) && is_array($message['buttons'])) {
            $body['text'] = $text;
            $body['buttons'] = array_slice($this->buttons($message['buttons']), 0, 3);
            return $this->result($this->call('POST', 'instagram/send-direct', $body, true));
        }
        if ($text === '') return ['ok' => false, 'error' => 'empty_message'];
        $body['text'] = $text;
        return $this->result($this->call('POST', 'instagram/send-direct', $body, true));
    }

    public function sendPrivateReplyToComment(string $contactId, string $commentId, string $postId, string $text, array $buttons = []): array
    {
        $body = [
            'igAccountId' => $this->igAccountId,
            'contactId'   => $this->contactId($contactId),
            'commentId'   => $commentId,
            'postId'      => $postId,
            'text'        => mb_substr($text, 0, 1000),
        ];
        if ($buttons) $body['buttons'] = array_slice($this->buttons($buttons), 0, 3);
        return $this->result($this->call('POST', 'instagram/reply-comment', $body, true));
    }

    public function sendCommentReply(string $postId, string $text, string $commentId = ''): array
    {
        return $this->result($this->call('POST', 'instagram/send-comment', [
            'igAccountId' => $this->igAccountId,
            'postId'      => $postId,
            'commentId'   => $commentId,
            'text'        => $text,
        ], true));
    }

    public function followStatus(string $contactId, bool $forceRefresh = false): array
    {
        $r = $this->call('POST', 'instagram/follow-status', [
            'igAccountId'  => $this->igAccountId,
            'contactId'    => $this->contactId($contactId),
            'forceRefresh' => $forceRefresh,
        ], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->err($r)];
        $raw = $r['body']['data'] ?? [];
        $isFollower = ($r['body']['isFollowing'] ?? null) === true || !empty($raw['isFollower']);
        return ['ok' => true, 'is_follower' => $isFollower, 'raw' => $raw];
    }

    // ==================== ویترین / ویس ====================

    public function sendShowcase(string $contactId, array $cards): array
    {
        $cards = array_slice($cards, 0, 10);
        foreach ($cards as &$c) {
            if (!empty($c['buttons'])) $c['buttons'] = array_slice($this->buttons($c['buttons']), 0, 3);
        }
        unset($c);
        return $this->result($this->call('POST', 'instagram/send-showcase', [
            'igAccountId' => $this->igAccountId,
            'contactId'   => $this->contactId($contactId),
            'cards'       => $cards,
        ], true));
    }

    public function uploadAudio(string $localPath): array
    {
        $r = $this->upload('instagram/upload-audio', $localPath);
        if (!$r['ok']) return $r;
        $d = $r['body']['data'] ?? [];
        return ['ok' => true, 'audio' => $d['audio'] ?? null, 'raw' => $d];
    }

    public function sendVoice(string $contactId, string $audioValue): array
    {
        return $this->result($this->call('POST', 'instagram/send-voice', [
            'igAccountId' => $this->igAccountId,
            'contactId'   => $this->contactId($contactId),
            'audio'       => $audioValue,
        ], true));
    }

    // ==================== پست و استوری ====================

    public function uploadImage(string $localPath): array
    {
        [$localPath, $tmpJ] = self::toJpg($localPath);
        $r = $this->upload('instagram/upload-image', $localPath);
        if ($tmpJ) @unlink($tmpJ);
        if (!$r['ok']) return $r;
        $d = $r['body']['data'] ?? [];
        return ['ok' => true, 'photo' => $d['photo'] ?? null, 'raw' => $d];
    }

    public function sendPost(string $text, string $photoValue): array
    {
        return $this->result($this->call('POST', 'instagram/publish-post', ['igAccountId' => $this->igAccountId, 'photo' => $photoValue, 'caption' => $text], true));
    }

    public function sendStory(string $photoValue): array
    {
        return $this->result($this->call('POST', 'instagram/publish-story', ['igAccountId' => $this->igAccountId, 'photo' => $photoValue], true));
    }

    public function listPosts(?string $cursor = null, int $limit = 25): array
    {
        $q = ['igAccountId' => $this->igAccountId, 'limit' => $limit];
        if ($cursor) $q['cursor'] = $cursor;
        $r = $this->call('GET', 'instagram/posts?' . http_build_query($q), [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->err($r)];
        $d = $r['body']['data'] ?? [];
        return ['ok' => true, 'posts' => $d['items'] ?? $d['posts'] ?? $d['data'] ?? (InstagramApichiHttp::isList($d) ? $d : []), 'cursor' => $d['cursor'] ?? $d['nextCursor'] ?? $d['next_cursor'] ?? ($r['body']['cursor'] ?? $r['body']['nextCursor'] ?? null)];
    }

    public function listStories(int $limit = 25): array
    {
        $r = $this->call('GET', 'instagram/stories?' . http_build_query(['igAccountId' => $this->igAccountId, 'limit' => $limit]), [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->err($r)];
        $d = $r['body']['data'] ?? [];
        return ['ok' => true, 'stories' => $d['items'] ?? $d['stories'] ?? $d['data'] ?? (InstagramApichiHttp::isList($d) ? $d : [])];
    }

    public function fetchStoryMedia(string $resourceId): array
    {
        $r = $this->call('GET', 'instagram/story-media?' . http_build_query(['igAccountId' => $this->igAccountId, 'resourceId' => $resourceId]), [], false);
        if (!$r['ok']) return ['ok' => false, 'error' => $this->err($r)];
        return ['ok' => true, 'raw' => $r['body']['data'] ?? null];
    }

    public function setWebhook(string $url): array
    {
        // در حالت یار API یک وب‌هوک برای همه‌ی پیج‌ها ثبت می‌شود: InstagramApichi::registerWebhook()
        return ['ok' => false, 'error' => 'use_InstagramApichi_registerWebhook'];
    }

    // ==================== کمکی‌ها ====================

    private function contactId(?string $channelUid): ?string
    {
        if ($channelUid === null || $channelUid === '') return null;
        if (preg_match('/^rc\d+_(.+)$/', $channelUid, $m)) return $m[1];
        return $channelUid;
    }

    /** همان قالب دکمه‌های سرویس‌دهنده؛ یار API هم دقیقاً type=link|postback می‌خواهد */
    private function buttons(array $buttons): array
    {
        $out = [];
        foreach ($buttons as $b) {
            $title = mb_substr((string)($b['text'] ?? $b['title'] ?? ''), 0, 20);
            if (!empty($b['url'])) {
                $out[] = ['type' => 'link', 'title' => $title, 'url' => $b['url']];
            } else {
                $out[] = ['type' => 'postback', 'title' => $title, 'payload' => (string)($b['payload'] ?? $b['callback'] ?? '')];
            }
        }
        return $out;
    }

    private function result(array $r): array
    {
        $ok = $r['ok'] && in_array((int)$r['status'], [200, 201, 202], true);
        return ['ok' => $ok, 'error' => $ok ? null : $this->err($r), 'raw' => $r['body']['data'] ?? ($r['body'] ?? null), 'tracking_id' => $r['body']['trackingId'] ?? null];
    }

    private function err(array $r): string
    {
        if (!empty($r['error'])) return (string)$r['error'];
        $b = $r['body'] ?? [];
        if (!empty($b['error'])) return (string)$b['error'] . (!empty($b['code']) ? ' [' . $b['code'] . ']' : '');
        $raw = isset($b['raw']) ? mb_substr((string)$b['raw'], 0, 200) : '';
        return 'HTTP ' . ($r['status'] ?? 0) . ($raw !== '' ? ': ' . $raw : '');
    }

    private function call(string $method, string $path, array $body = [], bool $idempotent = false): array
    {
        // 2.1.5: deterministic Idempotency-Key inside an Idempotency scope (R-04); random outside, as before
        $idem = strtoupper($method) !== 'GET' ? Idempotency::key($path, $body + ['igAccountId' => $this->igAccountId]) : null;
        $r = InstagramApichiHttp::request($this->apiBase, $this->apiKey, $method, $path, $method === 'GET' ? null : $body, $idem);
        $this->logCall($path, $body, $r);
        return $r;
    }

    private function upload(string $path, string $localPath): array
    {
        if (!is_file($localPath)) return ['ok' => false, 'error' => 'file_not_found'];
        $mime = null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) { $mime = finfo_file($f, $localPath) ?: null; finfo_close($f); }
        }
        $file = $mime ? new CURLFile($localPath, $mime, basename($localPath)) : new CURLFile($localPath);
        $r = InstagramApichiHttp::request($this->apiBase, $this->apiKey, 'POST', $path, null, null, [
            'igAccountId' => (string)$this->igAccountId,
            'file' => $file,
        ], 60);
        $this->logCall($path, ['file' => basename($localPath)], $r);
        if (!$r['ok']) $r['error'] = $this->err($r);
        return $r;
    }

    private function logCall(string $path, array $body, array $r): void
    {
        // لاگ کامل در InstagramApichiHttp::request (جدول eb_apichi_log) ثبت می‌شود — این‌جا تکرار نمی‌شود
    }
}

/**
 * HTTP خام به یار API (مشترک بین درایور و ماژول InstagramApichi)
 * خروجی: ['ok'=>bool (2xx), 'status'=>int, 'body'=>array, 'error'=>?string]
 */
class InstagramApichiHttp
{
    public static function isList($a): bool
    {
        return is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1));
    }

    public static function request(string $base, string $key, string $method, string $path, ?array $json = null, ?string $idempotencyKey = null, ?array $multipart = null, int $timeout = 25): array
    {
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        $headers = ['X-Api-Key: ' . $key, 'Accept: application/json', 'User-Agent: elanban-apichi/1.0'];
        // ۲.۰.۵: یار API برای هر درخواستِ تغییردهنده کلید یکتا می‌خواهد («Idempotency-Key header is required»)
        if (!$idempotencyKey && strtoupper($method) !== 'GET') $idempotencyKey = 'eb-' . bin2hex(random_bytes(10));
        if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($multipart !== null) {
            $opts[CURLOPT_POSTFIELDS] = $multipart;
        } elseif ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        if ($raw === false) {
            $res = ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'curl: ' . ($err ?: 'اتصال به یار API برقرار نشد')];
        } else {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : ['raw' => mb_substr($raw, 0, 2000)];
            $ok = $status >= 200 && $status < 300 && (($body['ok'] ?? true) !== false);
            $res = ['ok' => $ok, 'status' => $status, 'body' => $body, 'error' => $ok ? null : (isset($body['error']) && is_string($body['error']) ? $body['error'] . (!empty($body['code']) ? ' [' . $body['code'] . ']' : '') : null)];
            if (!$ok && $res['error'] === null) {
                $res['error'] = 'HTTP ' . $status . (is_array($decoded) ? '' : ' — پاسخ JSON نبود (آدرس API درست است؟): ' . mb_substr(trim(strip_tags($raw)), 0, 120));
            }
        }

        // polling خالی (هر دقیقه) لاگ را پر نکند
        $emptyPoll = $res['ok'] && strpos($path, 'instagram/events') === 0 && empty($res['body']['data']) && strpos($path, 'latest') === false;
        if (class_exists('ApichiLog') && !$emptyPoll) {
            $igId = $json['igAccountId'] ?? ($multipart['igAccountId'] ?? null);
            if ($igId === null && preg_match('~igAccountId=(\d+)~', $path, $m)) $igId = (int)$m[1];
            ApichiLog::add('out', [
                'method' => strtoupper($method), 'endpoint' => $url, 'http_code' => $res['status'], 'ok' => $res['ok'], 'duration_ms' => $ms,
                'ig_account_id' => $igId,
                'summary' => self::label($path) . ($res['ok'] ? ' ✓' : ' ✗ ' . ($res['error'] ?? '')),
                'request' => $multipart !== null ? array_map(fn($v) => $v instanceof CURLFile ? '[file ' . basename($v->getFilename()) . ']' : $v, $multipart) : $json,
                'response' => $raw === false ? null : $raw,
                'ref' => $idempotencyKey,
            ]);
        }
        return $res;
    }

    /** برچسب فارسی endpointهای یار API برای لاگ */
    public static function label(string $path): string
    {
        $p = strtok($path, '?');
        $map = [
            'ping' => 'تست اتصال (ping)', 'instagram/accounts' => 'لیست پیج‌ها', 'instagram/events' => 'دریافت رویدادها (polling)',
            'instagram/send-direct-buttons' => 'ارسال دایرکت با دکمه', 'instagram/send-direct' => 'ارسال دایرکت', 'instagram/send-image' => 'ارسال عکس',
            'instagram/private-reply' => 'پاسخ خصوصی کامنت', 'instagram/reply-comment' => 'پاسخ عمومی کامنت', 'instagram/follower-status' => 'وضعیت فالو',
            'instagram/upload-image' => 'آپلود عکس', 'instagram/posts' => 'لیست پست‌ها', 'instagram/stories' => 'لیست استوری‌ها',
            'webhooks' => 'وب‌هوک‌ها',
        ];
        foreach ($map as $k => $v) if (strpos($p, $k) === 0) return $v . ($p !== $k ? ' (' . $p . ')' : '');
        return $p;
    }
}

/**
 * لاگ کامل ارتباط اعلان‌بان ⇄ یار API (جدول eb_apichi_log — خودکار ساخته می‌شود)
 *   kind:  out      اعلان‌بان → یار API (ارسال، همگام‌سازی، ثبت وب‌هوک، polling …)
 *          in       وب‌هوک ورودی از یار API (حتی ردشده‌ها با دلیل)
 *          event    نتیجه‌ی پردازش هر رویداد در پایپ‌لاین (اینباکس، اتوماسیون کامنت، پاسخ خودکار …)
 *          poll     هر دور polling که رویداد آورد یا خطا داد
 *          action   کارهای دستی پنل (ذخیره‌ی اتصال، همگام‌سازی، ثبت/تست وب‌هوک)
 * این کلاس داخل همین فایل است چون همه‌ی ورودی‌ها (index، webhook، cron) این فایل را require می‌کنند.
 */
class ApichiLog
{
    const MAX = 6000;
    private static bool $ready = false;

    public static function kinds(): array
    {
        return [
            'out'    => 'اعلان‌بان → یار API',
            'in'     => 'وب‌هوک یار API → اعلان‌بان',
            'event'  => 'پردازش رویداد',
            'poll'   => 'دریافت دوره‌ای (polling)',
            'action' => 'عملیات پنل',
        ];
    }

    public static function ensure(): bool
    {
        if (self::$ready) return true;
        try {
            Database::run("CREATE TABLE IF NOT EXISTS eb_apichi_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(10) NOT NULL,
                method VARCHAR(10) DEFAULT NULL,
                endpoint VARCHAR(255) DEFAULT NULL,
                http_code INT DEFAULT NULL,
                ok TINYINT(1) NOT NULL DEFAULT 1,
                duration_ms INT DEFAULT NULL,
                ig_account_id INT DEFAULT NULL,
                summary VARCHAR(500) DEFAULT NULL,
                request_data MEDIUMTEXT,
                response_data MEDIUMTEXT,
                ref VARCHAR(80) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_kind (kind, id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::$ready = true;
        } catch (Throwable $e) {
            error_log('[ApichiLog] ' . $e->getMessage());
        }
        return self::$ready;
    }

    public static function enabled(): bool
    {
        static $on = null;
        if ($on === null) {
            try { $r = Database::one("SELECT v FROM eb_settings WHERE k = 'apichi.log_enabled'"); $on = !$r || $r['v'] !== '0'; }
            catch (Throwable $e) { $on = true; }
        }
        return $on;
    }

    private static function mask($d)
    {
        if (is_array($d)) {
            $o = [];
            foreach ($d as $k => $v) {
                $o[$k] = (is_string($k) && preg_match('/(api[_-]?key|secret|password|token)/i', $k) && is_scalar($v) && strlen((string)$v) > 6)
                    ? substr((string)$v, 0, 6) . '…' : self::mask($v);
            }
            return $o;
        }
        return is_string($d) ? preg_replace('/\b((?:ak|rk|whsec)_[A-Za-z0-9_]{4})[A-Za-z0-9_]{6,}/', '$1…', $d) : $d;
    }

    private static function body($b): ?string
    {
        if ($b === null || $b === '' || $b === []) return null;
        if (is_string($b)) { $j = json_decode($b, true); if (is_array($j)) $b = $j; }
        $s = is_string($b) ? self::mask($b) : json_encode(self::mask($b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($s)) return null;
        return mb_strlen($s) > self::MAX ? mb_substr($s, 0, self::MAX) . ' …[کوتاه شد]' : $s;
    }

    /** $f: method, endpoint, http_code, ok, duration_ms, ig_account_id, summary, request, response, ref */
    public static function add(string $kind, array $f): void
    {
        if (!self::enabled() || !self::ensure()) return;
        try {
            Database::run(
                "INSERT INTO eb_apichi_log (kind, method, endpoint, http_code, ok, duration_ms, ig_account_id, summary, request_data, response_data, ref)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $kind, $f['method'] ?? null, isset($f['endpoint']) ? mb_substr((string)$f['endpoint'], 0, 255) : null,
                    isset($f['http_code']) ? (int)$f['http_code'] : null, !empty($f['ok']) ? 1 : 0,
                    isset($f['duration_ms']) ? (int)$f['duration_ms'] : null, isset($f['ig_account_id']) ? (int)$f['ig_account_id'] : null,
                    isset($f['summary']) ? mb_substr((string)$f['summary'], 0, 500) : null,
                    self::body($f['request'] ?? null), self::body($f['response'] ?? null),
                    isset($f['ref']) ? mb_substr((string)$f['ref'], 0, 80) : null,
                ]
            );
        } catch (Throwable $e) { error_log('[ApichiLog] ' . $e->getMessage()); }
    }

    public static function list(array $flt, int $limit = 60): array
    {
        if (!self::ensure()) return [];
        $w = ['1=1']; $p = [];
        if (!empty($flt['kind']) && isset(self::kinds()[$flt['kind']])) { $w[] = 'kind = ?'; $p[] = $flt['kind']; }
        if (isset($flt['ok']) && $flt['ok'] !== '' && $flt['ok'] !== null) { $w[] = 'ok = ?'; $p[] = (int)$flt['ok']; }
        if (!empty($flt['before'])) { $w[] = 'id < ?'; $p[] = (int)$flt['before']; }
        if (!empty($flt['q'])) { $w[] = '(summary LIKE ? OR endpoint LIKE ? OR ref LIKE ?)'; $q = '%' . $flt['q'] . '%'; array_push($p, $q, $q, $q); }
        $limit = max(1, min(200, $limit));
        return Database::all("SELECT id, kind, method, endpoint, http_code, ok, duration_ms, ig_account_id, summary, ref, created_at
                              FROM eb_apichi_log WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT {$limit}", $p);
    }

    public static function get(int $id): ?array
    {
        if (!self::ensure()) return null;
        return Database::one("SELECT * FROM eb_apichi_log WHERE id = ?", [$id]);
    }

    public static function clear(?string $kind = null): void
    {
        if (!self::ensure()) return;
        $kind && isset(self::kinds()[$kind]) ? Database::run("DELETE FROM eb_apichi_log WHERE kind = ?", [$kind]) : Database::run("DELETE FROM eb_apichi_log");
    }

    public static function cleanup(int $days = 14): void
    {
        if (!self::ensure()) return;
        try { Database::run("DELETE FROM eb_apichi_log WHERE created_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)]); } catch (Throwable $e) {}
    }

    public static function stats(): array
    {
        $out = [];
        foreach (self::kinds() as $k => $l) $out[$k] = ['total' => 0, 'failed' => 0, 'last_at' => null];
        if (!self::ensure()) return $out;
        foreach (Database::all("SELECT kind, COUNT(*) c, SUM(ok = 0) f, MAX(created_at) l FROM eb_apichi_log WHERE created_at > ? GROUP BY kind", [date('Y-m-d H:i:s', time() - 86400)]) as $r) {
            $out[$r['kind']] = ['total' => (int)$r['c'], 'failed' => (int)$r['f'], 'last_at' => $r['l']];
        }
        return $out;
    }

    public static function last(string $kind, ?int $ok = null): ?array
    {
        if (!self::ensure()) return null;
        $sql = "SELECT id, summary, http_code, created_at FROM eb_apichi_log WHERE kind = ?" . ($ok === null ? '' : ' AND ok = ' . (int)$ok) . " ORDER BY id DESC LIMIT 1";
        return Database::one($sql, [$kind]);
    }
}
