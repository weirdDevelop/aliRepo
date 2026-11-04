<?php
/**
 * modules/InstagramRoboclick.php
 * مدیریت چند پیج اینستاگرام (جدول eb_ig_accounts) — هم پیج‌های اتصال مستقیم سرویس‌دهنده
 * (provider='roboclick') و هم پیج‌های متصل از طریق یار API (provider='apichi'، ماژول
 * modules/InstagramApichi.php). get/all/update/delete و driverForAccount هر دو را پوشش می‌دهند.
 *
 * دو راه برای افزودن پیج: (۱) دستی با Account Key (کپی از پنل سرویس‌دهنده)،
 * (۲) OAuth+PKCE کامل (CreateInstagramAuthLinkV2 → CompleteInstagramConnection)
 * — کاربر مستقیم در اینستاگرام تأیید می‌کند و پیج خودکار با Account Key تازه
 * اضافه می‌شود؛ برای این روش یک Integration Key سطح‌بالا (rk_...) لازم است.
 *
 * هر پیج یک webhook_secret مخصوص خودش دارد (نه یک secret مشترک) چون آدرس
 * وب‌هوکی که در پنل سرویس‌دهنده برای هر پیج ثبت می‌شود شامل شناسه‌ی همین ردیف است:
 *   https://.../webhook/roboclick.php?account={id}
 * و امضا با همان secret همین پیج تأیید می‌شود — امن‌تر از اعتماد به account.id
 * داخل خودِ payload برای انتخاب secret.
 */

class InstagramRoboclick
{
    /** فهرست همه‌ی پیج‌ها (فعال و غیرفعال) */
    public static function all(): array
    {
        try {
            return Database::all("SELECT * FROM eb_ig_accounts WHERE provider IN ('roboclick','apichi') ORDER BY id DESC");
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function get(int $id): ?array
    {
        return Database::one("SELECT * FROM eb_ig_accounts WHERE id=? AND provider IN ('roboclick','apichi')", [$id]);
    }

    /**
     * افزودن یک پیج جدید. خروجی: آرایه‌ی ردیف تازه (شامل id).
     * ⚠️ webhook_secret اینجا عمداً خالی می‌ماند: آن سکرت را خودِ سرویس‌دهنده
     * تولید می‌کند (دکمه‌ی «Rotate Secret» در پنلشان، فقط یک‌بار نمایش داده
     * می‌شود) — نه ما. باید بعد از ثبت آدرس وب‌هوک در پنل سرویس‌دهنده، همان مقدار
     * را با InstagramRoboclick::update() اینجا هم وارد کرد، وگرنه تأیید امضای
     * وب‌هوک همیشه شکست می‌خورد (۴۰۳).
     */
    public static function add(string $label, string $accountKey, string $username = '', string $color = '#6366f1'): array
    {
        $label = trim($label) !== '' ? trim($label) : ('پیج ' . substr($accountKey, -4));
        Database::run(
            "INSERT INTO eb_ig_accounts (provider, label, color, username, account_key, webhook_secret, is_active)
             VALUES ('roboclick', ?, ?, ?, ?, NULL, 1)",
            [$label, $color ?: '#6366f1', trim($username) ?: null, trim($accountKey)]
        );
        $id = (int)Database::lastId();
        return self::get($id) ?? [];
    }

    public static function update(int $id, array $fields): bool
    {
        $allowed = ['label', 'color', 'username', 'account_key', 'webhook_secret', 'is_active'];
        $sets = []; $params = [];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) continue;
            $sets[] = "$k = ?";
            $params[] = $fields[$k];
        }
        if (!$sets) return false;
        $params[] = $id;
        Database::run("UPDATE eb_ig_accounts SET " . implode(', ', $sets) . " WHERE id = ? AND provider IN ('roboclick','apichi')", $params);
        return true;
    }

    public static function delete(int $id): void
    {
        // پیام‌ها/هویت‌های قبلی حذف نمی‌شوند، فقط دیگر به این پیج اشاره نمی‌کنند
        Database::run("UPDATE eb_identities SET ig_account_id = NULL WHERE ig_account_id = ?", [$id]);
        Database::run("DELETE FROM eb_ig_accounts WHERE id = ? AND provider IN ('roboclick','apichi')", [$id]);
    }

    /** ساخت درایور آماده برای یک پیج (برای ارسال) */
    public static function driverForAccount(array $account): InstagramRoboclickDriver
    {
        // پیج‌هایی که از طریق یار API وصل شده‌اند (provider='apichi') با درایور یار API کار می‌کنند؛
        // InstagramApichiDriver زیرکلاس همین درایور است، پس بقیه‌ی سیستم فرقی حس نمی‌کند.
        if (($account['provider'] ?? 'roboclick') === 'apichi' && class_exists('InstagramApichi')) {
            return InstagramApichi::driverForAccount($account);
        }
        return new InstagramRoboclickDriver($account['account_key'], InstagramRoboclick::baseUrl());
    }

    public static function driverForAccountId(int $accountId): ?InstagramRoboclickDriver
    {
        $acc = self::get($accountId);
        if (!$acc || !$acc['is_active']) return null;
        return self::driverForAccount($acc);
    }

    /** درایوری که باید برای پاسخ به یک هویت مشخص (شخص/مخاطب) استفاده شود، یا null. */
    public static function driverForIdentity(array $identity): ?InstagramRoboclickDriver
    {
        if (empty($identity['ig_account_id'])) return null;
        return self::driverForAccountId((int)$identity['ig_account_id']);
    }

    /** آدرس API اتصال مستقیم (روش قدیمی). قابل تغییر با تنظیم channel.instagram.direct_base_url */
    public static function baseUrl(): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k = 'channel.instagram.direct_base_url'");
            if ($row && trim((string)$row['v']) !== '') return rtrim(trim((string)$row['v']), '/');
        } catch (Throwable $e) {}
        return base64_decode('aHR0cHM6Ly9hcGkucm9ib2NsaWNrLmNv');
    }

    /** آدرس وب‌هوک اختصاصی یک پیج (برای نمایش در پنل، کاربر همین را در پنل سرویس‌دهنده می‌گذارد) */
    public static function webhookUrl(array $config, int $accountId): string
    {
        return rtrim(app_base_url($config), '/') . '/webhook/roboclick.php?account=' . $accountId;
    }

    // ==================== Integration Key (سطح کل اتصال، نه هر پیج) ====================

    public static function integrationKey(): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k='roboclick.integration_key'");
            return $row['v'] ?? '';
        } catch (Throwable $e) { return ''; }
    }

    public static function setIntegrationKey(string $key): void
    {
        Database::run(
            "INSERT INTO eb_settings (k,v) VALUES ('roboclick.integration_key',?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
            [trim($key)]
        );
    }

    /** آدرس callback ثابتی که باید در پنل سرویس‌دهنده به‌عنوان «Allowed Return URL» ثبت شود */
    public static function oauthReturnUrl(array $config): string
    {
        return rtrim(app_base_url($config), '/') . '/instagram-roboclick-oauth-callback.php';
    }

    // ==================== اتصال پیج با OAuth+PKCE ====================

    /**
     * قدم ۱: یک لینک اتصال بساز (CreateInstagramAuthLinkV2). codeVerifier و state را
     * سمت سرور نگه می‌داریم (نه مرورگر) تا در قدم تکمیل استفاده شوند — دقیقاً طبق
     * نکته‌ی امنیتی مستندات: «externalUserId باید از session احرازشده‌ی سرور بیاید».
     */
    public static function startOAuth(array $config, bool $useFreeTrial = false): array
    {
        $rk = self::integrationKey();
        if ($rk === '') return ['ok' => false, 'error' => 'اول Integration Key را ذخیره کن'];

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $verifier = substr($verifier, 0, 128);
        $state = bin2hex(random_bytes(16));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $externalUserId = 'admin' . (class_exists('Auth') ? (Auth::id() ?? 0) : 0);

        $body = [
            'returnUrl'            => self::oauthReturnUrl($config),
            'externalUserId'       => $externalUserId,
            'clientState'          => $state,
            'codeChallenge'        => $challenge,
            'codeChallengeMethod'  => 'S256',
            'useFreeTrial'         => $useFreeTrial,
        ];
        $r = self::integrationCall($rk, 'POST', '/External/ExternalAccount/CreateInstagramAuthLinkV2', $body);
        if (empty($r['ok']) || empty($r['body']['authorizeUrl'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'ساخت لینک اتصال ناموفق بود'];
        }

        Database::run(
            "INSERT INTO eb_ig_oauth_pending (state, code_verifier, external_user_id, admin_id) VALUES (?,?,?,?)",
            [$state, $verifier, $externalUserId, class_exists('Auth') ? Auth::id() : null]
        );
        // حالت‌های خیلی قدیمی که هیچ‌وقت تکمیل نشدند را پاک کن (بیشتر از ۱ ساعت)
        try { Database::run("DELETE FROM eb_ig_oauth_pending WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"); } catch (Throwable $e) {}

        return ['ok' => true, 'authorize_url' => $r['body']['authorizeUrl']];
    }

    /**
     * قدم ۲: بعد از برگشتن مرورگر به returnUrl ما با connectionCode و state، اینجا
     * (سمت سرور) CompleteInstagramConnection صدا زده می‌شود و پیج در eb_ig_accounts
     * ساخته/به‌روزرسانی می‌شود.
     */
    public static function completeOAuth(string $connectionCode, string $state): array
    {
        $rk = self::integrationKey();
        if ($rk === '') return ['ok' => false, 'error' => 'Integration Key تنظیم نشده'];
        if ($connectionCode === '' || $state === '') return ['ok' => false, 'error' => 'پارامترهای بازگشتی ناقص است'];

        $pending = Database::one("SELECT * FROM eb_ig_oauth_pending WHERE state = ?", [$state]);
        if (!$pending) return ['ok' => false, 'error' => 'این لینک منقضی شده یا قبلاً استفاده شده — دوباره از «ساخت لینک» شروع کن'];

        $r = self::integrationCall($rk, 'POST', '/External/ExternalAccount/CompleteInstagramConnection', [
            'connectionCode'  => $connectionCode,
            'codeVerifier'    => $pending['code_verifier'],
            'externalUserId'  => $pending['external_user_id'],
            'issueAccountKey' => true,
        ]);
        Database::run("DELETE FROM eb_ig_oauth_pending WHERE state = ?", [$state]);

        if (empty($r['ok']) || empty($r['body']['accountId'])) {
            return ['ok' => false, 'error' => $r['error'] ?? 'تکمیل اتصال ناموفق بود'];
        }
        $body = $r['body'];
        $roboAccId = (string)$body['accountId'];
        $accountKey = $body['accountKey'] ?? null;
        $username = $body['username'] ?? $body['igUsername'] ?? null;

        // اگر این accountId قبلاً وصل شده بود (اتصال مجدد)، همون ردیف را به‌روزرسانی کن
        $existing = Database::one("SELECT * FROM eb_ig_accounts WHERE roboclick_account_id = ? AND provider='roboclick'", [$roboAccId]);
        if ($existing) {
            $fields = ['username' => $username ?: $existing['username'], 'is_active' => 1];
            if ($accountKey) $fields['account_key'] = $accountKey; // ممکن است کلید تازه صادر نشده باشد
            self::update((int)$existing['id'], $fields);
            return ['ok' => true, 'id' => (int)$existing['id'], 'reconnected' => true, 'account_key_issued' => (bool)$accountKey];
        }

        if (!$accountKey) {
            return ['ok' => false, 'error' => 'اتصال کامل شد ولی Account Key صادر نشد — با پشتیبانی سرویس‌دهنده هماهنگ کن'];
        }

        Database::run(
            "INSERT INTO eb_ig_accounts (provider, label, color, username, account_key, roboclick_account_id, roboclick_external_user_id, is_active)
             VALUES ('roboclick', ?, '#6366f1', ?, ?, ?, ?, 1)",
            [$username ?: ('پیج ' . $roboAccId), $username, $accountKey, $roboAccId, $pending['external_user_id']]
        );
        return ['ok' => true, 'id' => (int)Database::lastId(), 'reconnected' => false, 'account_key_issued' => true];
    }

    /** تماس ساده با API سرویس‌دهنده با Integration Key (سطح اتصال، نه یک پیج مشخص) */
    private static function integrationCall(string $rk, string $method, string $path, array $body): array
    {
        $ch = curl_init(self::baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $rk],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $decoded = $raw !== false ? json_decode($raw, true) : null;
        $result = [
            'ok'     => $raw !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : ['raw' => $raw],
        ];
        if (!$result['ok']) {
            $b = $result['body'];
            $result['error'] = $raw === false
                ? ('curl: ' . ($err ?: 'اتصال برقرار نشد'))
                : (string)($b['error'] ?? $b['message'] ?? $b['msg'] ?? ('HTTP ' . $status));
        }
        if (class_exists('ApiLog')) {
            try { ApiLog::record('instagram_roboclick', trim($path, '/'), $body, $result['body'], $status, $result['ok'], $result['error'] ?? null); }
            catch (Throwable $e) {}
        }
        return $result;
    }

    /**
     * یکسان‌سازی پست/استوری برگشتی از API (کلیدهای مختلف و تودرتو) به {id, thumb, caption, permalink, created_at, raw}.
     * شناسه همان مقداری است که در رویداد کامنت به‌عنوان postId می‌آید.
     */
    public static function normalizeMedia(array $item, string $kind = 'post'): array
    {
        $flat = $item;
        foreach (['post', 'media', 'story', 'item', 'node', 'data'] as $k) {
            if (isset($item[$k]) && is_array($item[$k])) $flat = $item[$k] + $flat;
        }
        $lc = [];
        foreach ($flat as $k => $v) { if (!is_array($v) && !is_object($v)) $lc[strtolower(str_replace(['_', '-'], '', (string)$k))] = $v; }
        $pick = function (array $keys) use ($lc) {
            foreach ($keys as $k) { $k = strtolower(str_replace(['_', '-'], '', $k)); if (isset($lc[$k]) && (string)$lc[$k] !== '') return (string)$lc[$k]; }
            return '';
        };
        $idKeys = $kind === 'story'
            ? ['storyId', 'story_id', 'id', 'mediaId', 'media_id', 'instagramStoryId', 'igStoryId', 'pk', 'resourceId']
            : ['postId', 'post_id', 'mediaId', 'media_id', 'instagramPostId', 'igPostId', 'instagramMediaId', 'id', 'pk', 'externalId'];
        $thumb = $pick(['thumbnailUrl', 'thumbnail_url', 'thumbnail', 'thumb', 'previewUrl', 'coverUrl', 'displayUrl', 'imageUrl', 'image_url', 'image', 'picture', 'photoUrl', 'mediaUrl', 'media_url', 'url']);
        if ($thumb === '' && !empty($flat['images']) && is_array($flat['images'])) {
            $first = reset($flat['images']);
            $thumb = is_array($first) ? (string)($first['url'] ?? $first['src'] ?? '') : (string)$first;
        }
        if ($thumb !== '' && !preg_match('~^(https?:)?//|^data:image/~i', $thumb)) $thumb = '';
        return [
            'id'         => $pick($idKeys),
            'thumb'      => $thumb,
            'caption'    => $pick(['caption', 'text', 'description', 'title']),
            'permalink'  => $pick(['permalink', 'link', 'postUrl', 'shortUrl']),
            'shortcode'  => $pick(['shortcode', 'code']),
            'created_at' => $pick(['createdAt', 'created_at', 'timestamp', 'takenAt', 'publishedAt', 'date']),
            'raw'        => $item,
        ];
    }
}
