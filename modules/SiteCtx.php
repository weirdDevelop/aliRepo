<?php
/**
 * modules/SiteCtx.php  (۲.۱.۱) — مدیریت چندسایته
 *
 * یک پنل اعلان‌بان چند سایت را مدیریت می‌کند. هر سایت یک ردیف در eb_sites دارد با «کلید سایت» (site_key)
 * که در کد نصب ویجت/اعلان/آنالیتیکس همان سایت می‌آید:
 *   <script src=".../widget.js" data-site="KEY" async></script>
 *
 * ▪ «سایت اصلی» (is_default=1) همان تنظیمات قدیمی را دارد؛ هیچ کلیدی تغییر نمی‌کند، پس نصب فعلی دست‌نخورده کار می‌کند.
 * ▪ تنظیمات هر سایت دیگر در eb_settings با پیشوند «s{id}.» ذخیره می‌شود (مثلاً s2.webchat.title).
 *   خواندن تنظیمات سایت غیراصلی فقط از همین کلیدها و پیش‌فرض‌های کد است (نه از سایت اصلی) تا اطلاعات سایت‌ها قاطی نشود؛
 *   برای شروع سریع، «کپی تنظیمات از سایت دیگر» هست.
 * ▪ سایت جاری (SiteCtx::id()) در هر درخواست یک‌بار تعیین می‌شود:
 *     – درخواست عمومی (chat.php / notices.php / eb-feed.php / eb-pulse.php): از ?site=KEY (یا کلید آنالیتیکس)
 *     – درخواست پنل: از هدر X-EB-Site (انتخاب‌گر سایت بالای پنل)
 * ▪ دادهٔ دارای site_id: eb_notices / eb_bot_nodes / eb_auto_replies (NULL = همهٔ سایت‌ها)،
 *   eb_webchat_sessions / eb_sessions (NULL = سایت اصلی).
 */
class SiteCtx
{
    private static $cur = null;
    private static $ready = false;
    private static $list = null;

    /* ------------------------------------------------------------ اسکیما */

    public static function ensure(): void
    {
        if (self::$ready) return;
        self::$ready = true;
        try {
            if (!Database::hasTable('eb_sites')) {
                Database::run("CREATE TABLE IF NOT EXISTS eb_sites (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    site_key VARCHAR(32) NOT NULL,
                    domain VARCHAR(255) DEFAULT NULL,
                    color VARCHAR(7) DEFAULT NULL,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_site_key (site_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                if (method_exists('Database', 'forgetSchemaCache')) Database::forgetSchemaCache();
            }
            if (!Database::one("SELECT id FROM eb_sites WHERE is_default = 1 LIMIT 1")) {
                $host = '';
                try { $host = trim((string)(Database::one("SELECT v FROM eb_settings WHERE k = 'analytics.site_url'")['v'] ?? '')); } catch (Throwable $e) {}
                Database::run("INSERT INTO eb_sites (name, site_key, domain, is_default) VALUES (?, ?, ?, 1)", ['سایت اصلی', self::newKey(), $host ?: null]);
            }
            try {   // رول‌آپ روزانهٔ هر سایت: کلید (day, site_id) — ۰ = سایت اصلی
                if (Database::hasTable('eb_stats_daily') && !Database::hasColumn('eb_stats_daily', 'site_id')) {
                    Database::run("ALTER TABLE eb_stats_daily ADD COLUMN site_id INT NOT NULL DEFAULT 0");
                    Database::run("ALTER TABLE eb_stats_daily DROP PRIMARY KEY, ADD PRIMARY KEY (day, site_id)");
                    if (method_exists('Database', 'forgetSchemaCache')) Database::forgetSchemaCache();
                }
            } catch (Throwable $e) { error_log('[SiteCtx stats_daily] ' . $e->getMessage()); }
            foreach ([['eb_notices', 'site_id'], ['eb_bot_nodes', 'site_id'], ['eb_auto_replies', 'site_id'], ['eb_webchat_sessions', 'site_id'], ['eb_sessions', 'site_id']] as [$t, $c]) {
                try {
                    if (Database::hasTable($t) && !Database::hasColumn($t, $c)) {
                        Database::run("ALTER TABLE `$t` ADD COLUMN `$c` INT NULL DEFAULT NULL");
                        if (method_exists('Database', 'forgetSchemaCache')) Database::forgetSchemaCache();
                    }
                } catch (Throwable $e) { /* جدول هنوز ساخته نشده؛ بعداً */ }
            }
            // گرهٔ منوی مخصوص یک سایت می‌تواند هم‌کلید با گرهٔ مشترک باشد: یکتایی (کلید، کانال، سایت)
            try {
                if (Database::hasTable('eb_bot_nodes') && Database::hasColumn('eb_bot_nodes', 'site_id')) {
                    $idx = Database::all("SHOW INDEX FROM eb_bot_nodes WHERE Key_name = 'uniq_key_channel'");
                    $has = false; foreach ($idx as $ix) if (($ix['Column_name'] ?? '') === 'site_id') $has = true;
                    if ($idx && !$has) {
                        Database::run("ALTER TABLE eb_bot_nodes DROP INDEX uniq_key_channel, ADD UNIQUE KEY uniq_key_channel (node_key, channel, site_id)");
                    }
                }
            } catch (Throwable $e) { error_log('[SiteCtx bot_nodes index] ' . $e->getMessage()); }
        } catch (Throwable $e) { error_log('[SiteCtx ensure] ' . $e->getMessage()); }
    }

    private static function newKey(): string { return bin2hex(random_bytes(8)); }

    /* ------------------------------------------------------------ فهرست */

    public static function all(bool $force = false): array
    {
        self::ensure();
        if (self::$list !== null && !$force) return self::$list;
        try { self::$list = Database::all("SELECT * FROM eb_sites ORDER BY is_default DESC, id ASC"); }
        catch (Throwable $e) { self::$list = []; }
        return self::$list;
    }

    public static function find(int $id): ?array
    {
        foreach (self::all() as $s) if ((int)$s['id'] === $id) return $s;
        return null;
    }

    public static function byKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '') return null;
        foreach (self::all() as $s) if (hash_equals((string)$s['site_key'], $key)) return $s;
        return null;
    }

    public static function defaultId(): int
    {
        foreach (self::all() as $s) if (!empty($s['is_default'])) return (int)$s['id'];
        return 1;
    }

    /** چند سایت داریم؟ (برای نمایش انتخاب‌گر) */
    public static function multi(): bool { return count(self::all()) > 1; }

    /* ------------------------------------------------------------ سایت جاری */

    public static function set(?int $id): void
    {
        self::$cur = ($id && self::find($id)) ? $id : null;
    }

    public static function id(): int
    {
        return self::$cur ?: self::defaultId();
    }

    public static function isDefault(): bool { return self::id() === self::defaultId(); }

    /** پیشوند کلید تنظیمات برای سایت جاری: سایت اصلی «» ، بقیه «s{id}.» */
    public static function pfx(): string
    {
        return self::isDefault() ? '' : 's' . self::id() . '.';
    }

    /** مقدار site_id برای ذخیره روی ردیف‌های «متعلق به یک سایت»: سایت اصلی ⇒ NULL */
    public static function rowId(): ?int
    {
        return self::isDefault() ? null : self::id();
    }

    /** از درخواست عمومی: ?site=KEY (یا در بدنهٔ JSON) */
    public static function fromPublic(?string $key): bool
    {
        $key = trim((string)$key);
        if ($key === '') { self::$cur = null; return true; }          // بدون کلید = سایت اصلی (نصب‌های قدیمی)
        $s = self::byKey($key);
        if (!$s || empty($s['is_active'])) { self::$cur = null; return false; }   // کلید ناشناخته/غیرفعال: خدمت نده
        self::$cur = (int)$s['id'];
        return true;
    }

    /** از درخواست پنل: هدر X-EB-Site */
    public static function fromAdmin(): void
    {
        $h = $_SERVER['HTTP_X_EB_SITE'] ?? '';
        self::$cur = ($h !== '' && ctype_digit($h) && self::find((int)$h)) ? (int)$h : null;
    }

    /** سایتِ یک ردیف (NULL = اصلی) → شناسهٔ واقعی */
    public static function realId($rowSiteId): int
    {
        return $rowSiteId ? (int)$rowSiteId : self::defaultId();
    }

    /** شرط «متعلق به سایت جاری یا همه‌ی سایت‌ها» برای جدول‌های دارای site_id (NULL = همه). با یک سایت: خالی. */
    public static function scopeSql(string $table, string $col = 'site_id'): string
    {
        if (!self::multi()) return '';
        try { if (!Database::hasColumn($table, 'site_id')) return ''; } catch (Throwable $e) { return ''; }
        return " AND ({$col} IS NULL OR {$col} = " . (int)self::id() . ")";
    }

    /** مقدار site_id برای ذخیره‌ی ردیف: site_scope='site' ⇒ سایت جاری، وگرنه NULL (همه) */
    public static function scopeValue($scope): ?int
    {
        return ((string)$scope === 'site' && self::multi()) ? self::id() : null;
    }

    /* ------------------------------------------------------------ آنالیتیکس */

    /** شرط SQL نشست‌های سایت جاری (برای جدول eb_sessions با نام مستعار $a) */
    public static function sessCond(string $a = ''): string
    {
        $c = $a !== '' ? $a . '.site_id' : 'site_id';
        $id = self::id();
        return self::isDefault() ? "({$c} IS NULL OR {$c} = {$id})" : "{$c} = {$id}";
    }

    /** نام جدول نشست‌ها برای FROM: وقتی فقط یک سایت هست همان eb_sessions (بدون هیچ تغییر)، وگرنه زیرپرسوجوی فیلترشده */
    public static function sess(): string
    {
        if (!self::multi() || !Database::hasColumn('eb_sessions', 'site_id')) return 'eb_sessions';
        return '(SELECT * FROM eb_sessions WHERE ' . self::sessCond() . ')';
    }

    /** مثل sess() ولی برای جایی که بعدش نام مستعار نمی‌آید */
    public static function sessAs(): string
    {
        $t = self::sess();
        return $t === 'eb_sessions' ? $t : $t . ' eb_sessions';
    }

    /** مقدار site_id در eb_stats_daily (۰ = سایت اصلی) */
    public static function dailyId(): int { return self::isDefault() ? 0 : self::id(); }

    /* ------------------------------------------------------------ مدیریت */

    public static function save(array $d, ?int $id = null): int
    {
        self::ensure();
        $name = mb_substr(trim((string)($d['name'] ?? '')), 0, 120);
        if ($name === '') throw new InvalidArgumentException('نام سایت لازم است');
        $domain = strtolower(trim((string)($d['domain'] ?? '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(preg_replace('#[/?].*$#', '', $domain), '/');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($d['color'] ?? '')) ? (string)$d['color'] : null;
        $active = array_key_exists('is_active', $d) ? (!empty($d['is_active']) ? 1 : 0) : 1;
        if ($id) {
            $cur = self::find($id);
            if (!$cur) throw new InvalidArgumentException('سایت پیدا نشد');
            if (!empty($cur['is_default'])) $active = 1;
            Database::run("UPDATE eb_sites SET name=?, domain=?, color=?, is_active=? WHERE id=?", [$name, $domain ?: null, $color, $active, $id]);
        } else {
            Database::run("INSERT INTO eb_sites (name, site_key, domain, color, is_active) VALUES (?,?,?,?,?)", [$name, self::newKey(), $domain ?: null, $color, $active]);
            $id = (int)Database::lastId();
        }
        self::all(true);
        // دامنهٔ سایت = دامنهٔ مجاز چت همان سایت (اگر هنوز خالی است)
        if ($domain !== '' && !empty($d['sync_hosts'])) {
            $prev = self::$cur; self::$cur = $id;
            try { if (trim((string)Webchat::settings()['allowed_hosts']) === '') Webchat::save(['allowed_hosts' => $domain]); } catch (Throwable $e) {}
            self::$cur = $prev;
        }
        return $id;
    }

    public static function rotateKey(int $id): string
    {
        $k = self::newKey();
        Database::run("UPDATE eb_sites SET site_key = ? WHERE id = ?", [$k, $id]);
        self::all(true);
        return $k;
    }

    /** حذف سایت (غیر اصلی): تنظیمات s{id}.* پاک می‌شود، داده‌های دارای site_id به «همه/اصلی» برمی‌گردند. */
    public static function delete(int $id): void
    {
        $s = self::find($id);
        if (!$s) return;
        if (!empty($s['is_default'])) throw new InvalidArgumentException('سایت اصلی حذف نمی‌شود');
        Database::run("DELETE FROM eb_settings WHERE k LIKE ?", ['s' . $id . '.%']);
        foreach (['eb_notices', 'eb_bot_nodes', 'eb_auto_replies'] as $t) {
            try { Database::run("UPDATE `$t` SET site_id = NULL WHERE site_id = ?", [$id]); } catch (Throwable $e) {}
        }
        foreach (['eb_webchat_sessions', 'eb_sessions'] as $t) {
            try { Database::run("UPDATE `$t` SET site_id = NULL WHERE site_id = ?", [$id]); } catch (Throwable $e) {}
        }
        Database::run("DELETE FROM eb_sites WHERE id = ?", [$id]);
        self::all(true);
    }

    /** کپی تنظیمات (چت/طراحی/دکمه‌های شناور/آنالیتیکس) از یک سایت به سایت دیگر. کلید و فعال‌بودن آنالیتیکس کپی نمی‌شود. */
    public static function copySettings(int $from, int $to): int
    {
        if ($from === $to || !self::find($from) || !self::find($to)) throw new InvalidArgumentException('سایت نامعتبر');
        $fp = $from === self::defaultId() ? '' : 's' . $from . '.';
        $tp = $to === self::defaultId() ? '' : 's' . $to . '.';
        $n = 0;
        foreach (['webchat.', 'chatplus.', 'analytics.'] as $ns) {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE ?", [$fp . $ns . '%']);
            foreach ($rows as $r) {
                $k = substr((string)$r['k'], strlen($fp));
                if ($fp === '' && preg_match('/^s\d+\./', (string)$r['k'])) continue;
                if (in_array($k, ['webchat.enabled', 'analytics.enabled', 'analytics.site_key', 'analytics.site_url', 'chatplus.schema', 'chatplus.phone_mig', 'chatplus.units_sync'], true)) continue;
                Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$tp . $k, $r['v']]);
                $n++;
            }
        }
        foreach (['fab.config', 'wcx.design'] as $k) {
            $r = Database::one("SELECT v FROM eb_settings WHERE k = ?", [$fp . $k]);
            if ($r) { Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$tp . $k, $r['v']]); $n++; }
        }
        return $n;
    }

    /** کدهای نصب یک سایت */
    public static function embed(array $s, string $base): array
    {
        $base = rtrim($base, '/');
        $k = htmlspecialchars((string)$s['site_key'], ENT_QUOTES, 'UTF-8');
        return [
            'widget'  => '<script src="' . $base . '/widget.js" data-site="' . $k . '" async></script>',
            'notices' => '<script src="' . $base . '/eb-embed.js" data-site="' . $k . '" async></script>',
            'pulse'   => '<script src="' . $base . '/eb-pulse.js" data-site="' . $k . '" async></script>',
        ];
    }

    /** آمار کوتاه هر سایت برای کارت‌های مدیریت */
    public static function stats(int $id): array
    {
        $isDef = $id === self::defaultId();
        $w = $isDef ? "(site_id IS NULL OR site_id = ?)" : "site_id = ?";
        $out = ['chats' => 0, 'notices' => 0, 'menus' => 0];
        try { $out['chats'] = (int)(Database::one("SELECT COUNT(*) c FROM eb_webchat_sessions WHERE $w", [$id])['c'] ?? 0); } catch (Throwable $e) {}
        try { $out['notices'] = (int)(Database::one("SELECT COUNT(*) c FROM eb_notices WHERE site_id = ?", [$id])['c'] ?? 0); } catch (Throwable $e) {}
        try { $out['menus'] = (int)(Database::one("SELECT COUNT(*) c FROM eb_bot_nodes WHERE site_id = ?", [$id])['c'] ?? 0); } catch (Throwable $e) {}
        return $out;
    }
}
