<?php
/**
 * modules/ConvIndex.php
 * فهرست خلاصه‌ی گفتگوها (یک ردیف برای هر مشتری) تا صندوق ورودی و شمارنده‌ها
 * مجبور نباشند در هر درخواست روی همه‌ی پیام‌ها GROUP BY بزنند.
 *
 * به‌روز ماندن:
 *  - پیام تازه: با «نشانگر» آخرین شناسه‌ی پیام (eb_settings convidx.wm). هر درخواست صندوق
 *    ورودی فقط پیام‌های بعد از نشانگر را می‌خواند؛ به هر مسیر ثبت پیام دست زده نمی‌شود.
 *  - خواندن، حذف/بازگردانی پیام و ادغام مشتری: touch() همان مشتری‌ها را دوباره حساب می‌کند.
 *  - کران: هر بار بخشی از فهرست را از نو می‌سازد (تور چرخشی)، تا هر تغییر دیگری هم جبران شود.
 */
class ConvIndex
{
    const BATCH = 400;
    private static $synced = false;

    public static function ready(): bool
    {
        return Database::hasTable('eb_conv_index');
    }

    public static function ensureTable(): void
    {
        Database::pdo()->exec("CREATE TABLE IF NOT EXISTS eb_conv_index (
            person_id INT PRIMARY KEY,
            last_in_id INT DEFAULT NULL,
            last_out_id INT DEFAULT NULL,
            last_in_at DATETIME DEFAULT NULL,
            last_out_at DATETIME DEFAULT NULL,
            last_at DATETIME DEFAULT NULL,
            waiting_since DATETIME DEFAULT NULL,
            unread INT NOT NULL DEFAULT 0,
            in_count INT NOT NULL DEFAULT 0,
            out_count INT NOT NULL DEFAULT 0,
            last_channel VARCHAR(30) DEFAULT NULL,
            last_ig_account_id INT DEFAULT NULL,
            chans VARCHAR(255) DEFAULT NULL,
            pages VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_last (last_at), KEY idx_last_in (last_in_at), KEY idx_wait (waiting_since)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** خواندن پیام‌های تازه از آخرین نشانگر (در هر درخواست حداکثر یک بار) */
    public static function sync(): void
    {
        if (self::$synced || !self::ready()) return;
        self::$synced = true;
        $wm = (int)(Database::one("SELECT v FROM eb_settings WHERE k = 'convidx.wm'")['v'] ?? -1);
        if ($wm < 0) {
            // نصب اول: پیام‌های بعد از این لحظه با نشانگر، قبلی‌ها با ساخت تدریجی (از جدیدترین مشتری)
            $max = (int)(Database::one("SELECT MAX(id) m FROM eb_messages")['m'] ?? 0);
            $top = (int)(Database::one("SELECT MAX(id) m FROM eb_persons")['m'] ?? 0);
            self::setKey('convidx.build', (string)($top + 1));
            self::setWm($max);
            $wm = $max;
        }
        if (self::building()) self::buildStep(3.0);
        $max = (int)(Database::one("SELECT MAX(id) m FROM eb_messages")['m'] ?? 0);
        if ($max <= $wm) return;
        // حداکثر ۵۰۰۰ پیام در هر نوبت؛ بقیه در درخواست بعدی
        $to = min($max, $wm + 5000);
        $ids = array_map(fn($r) => (int)$r['person_id'], Database::all(
            "SELECT DISTINCT i.person_id FROM eb_messages m INNER JOIN eb_identities i ON i.id = m.identity_id
             WHERE m.id > ? AND m.id <= ?", [$wm, $to]));
        self::refresh($ids);
        self::setWm($to);
        if ($ids && class_exists('Desk')) {
            Desk::bumpCounts();
            try { Desk::applyKeywordRules($wm, $to); } catch (Throwable $e) { error_log('[prio rules] ' . $e->getMessage()); }
        }
    }

    private static function setKey(string $k, string $v): void
    {
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$k, $v]);
    }

    private static function setWm(int $v): void { self::setKey('convidx.wm', (string)$v); }

    /** آیا ساخت اولیه هنوز تمام نشده؟ */
    public static function building(): bool
    {
        $c = Database::one("SELECT v FROM eb_settings WHERE k = 'convidx.build'")['v'] ?? 'done';
        return $c !== 'done';
    }

    /** درصد پیشرفت ساخت اولیه (برای نمایش در صندوق ورودی) */
    public static function progress(): ?int
    {
        $c = Database::one("SELECT v FROM eb_settings WHERE k = 'convidx.build'")['v'] ?? 'done';
        if ($c === 'done') return null;
        $top = (int)(Database::one("SELECT MAX(id) m FROM eb_persons")['m'] ?? 0);
        return $top ? max(1, min(99, (int)round(100 * ($top - (int)$c) / $top))) : 99;
    }

    /** یک قدم از ساخت اولیه، با سقف زمانی (ثانیه) */
    public static function buildStep(float $budget): void
    {
        $t0 = microtime(true);
        $cur = Database::one("SELECT v FROM eb_settings WHERE k = 'convidx.build'")['v'] ?? 'done';
        if ($cur === 'done') return;
        $cur = (int)$cur;
        while (microtime(true) - $t0 < $budget) {
            $ids = array_map(fn($r) => (int)$r['id'], Database::all("SELECT id FROM eb_persons WHERE id < ? ORDER BY id DESC LIMIT " . self::BATCH, [$cur]));
            if (!$ids) { self::setKey('convidx.build', 'done'); break; }
            self::refresh($ids);
            $cur = min($ids);
            self::setKey('convidx.build', (string)$cur);
        }
        if (class_exists('Desk')) Desk::bumpCounts();
    }

    /** محاسبه‌ی دوباره‌ی چند مشتری مشخص (بعد از خواندن، حذف پیام، ادغام و…) */
    public static function touch(...$ids): void
    {
        if (!self::ready()) return;
        $flat = [];
        array_walk_recursive($ids, function ($v) use (&$flat) { if ((int)$v > 0) $flat[] = (int)$v; });
        if ($flat) { try { self::refresh(array_values(array_unique($flat))); } catch (Throwable $e) { error_log('[convidx] ' . $e->getMessage()); } }
        if (class_exists('Desk')) Desk::bumpCounts();
    }

    /** ساخت کامل (نصب اول) — تکه‌تکه تا روی دیتابیس بزرگ حافظه/زمان تمام نشود */
    public static function rebuildAll(): int
    {
        @set_time_limit(600);
        $max = (int)(Database::one("SELECT MAX(id) m FROM eb_messages")['m'] ?? 0);
        $n = 0; $after = 0;
        while (true) {
            $ids = array_map(fn($r) => (int)$r['id'], Database::all("SELECT id FROM eb_persons WHERE id > ? ORDER BY id LIMIT " . self::BATCH, [$after]));
            if (!$ids) break;
            self::refresh($ids);
            $after = end($ids); $n += count($ids);
        }
        Database::run("DELETE x FROM eb_conv_index x LEFT JOIN eb_persons p ON p.id = x.person_id WHERE p.id IS NULL");
        self::setWm($max);
        self::setKey('convidx.build', 'done');
        return $n;
    }

    /** تور چرخشی کران: هر بار $n مشتری را دوباره حساب می‌کند */
    public static function cronSweep(int $n = 1500): int
    {
        if (!self::ready()) return 0;
        self::sync();
        if (self::building()) { self::buildStep(40.0); return 0; }
        $cur = (int)(Database::one("SELECT v FROM eb_settings WHERE k = 'convidx.sweep'")['v'] ?? 0);
        $ids = array_map(fn($r) => (int)$r['person_id'], Database::all("SELECT person_id FROM eb_conv_index WHERE person_id > ? ORDER BY person_id LIMIT " . (int)$n, [$cur]));
        if (!$ids) { Database::run("DELETE x FROM eb_conv_index x LEFT JOIN eb_persons p ON p.id = x.person_id WHERE p.id IS NULL"); $next = 0; }
        else { foreach (array_chunk($ids, self::BATCH) as $ch) self::refresh($ch); $next = end($ids); }
        self::setKey('convidx.sweep', (string)$next);
        return count($ids);
    }

    /** محاسبه‌ی ردیف خلاصه برای فهرستی از مشتری‌ها */
    public static function refresh(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return;
        foreach (array_chunk($ids, self::BATCH) as $chunk) self::refreshChunk($chunk);
    }

    private static function refreshChunk(array $ids): void
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $del = Inbox::hasDeleted() ? " AND deleted_at IS NULL" : "";
        $unread = Inbox::hasReadAt() ? "SUM(direction = 'in' AND read_at IS NULL)" : "0";
        $ig = Inbox::hasIgAccounts();
        // ۱) هویت‌های این مشتری‌ها (کوئری تک‌جدولی با ایندکس person_id)
        $idents = [];
        foreach (Database::all("SELECT id, person_id" . ($ig ? ", ig_account_id" : ", NULL AS ig_account_id") . " FROM eb_identities WHERE person_id IN ($ph)", $ids) as $r) $idents[(int)$r['id']] = $r;
        // ۲) جمع‌بندی پیام‌ها برای هر هویت (ایندکس identity_id) و تجمیع در PHP
        $by = [];
        if ($idents) {
            $iph = implode(',', array_fill(0, count($idents), '?'));
            $rows = Database::all(
                "SELECT identity_id,
                        MAX(CASE WHEN direction = 'in' THEN id END) AS last_in_id,
                        MAX(CASE WHEN direction = 'out' THEN id END) AS last_out_id,
                        MAX(CASE WHEN direction = 'in' THEN created_at END) AS last_in_at,
                        MAX(CASE WHEN direction = 'out' THEN created_at END) AS last_out_at,
                        MAX(CASE WHEN direction = 'out' AND (status IS NULL OR status <> 'failed') THEN created_at END) AS last_ok_out_at,
                        $unread AS unread, SUM(direction = 'in') AS in_count, SUM(direction = 'out') AS out_count,
                        GROUP_CONCAT(DISTINCT CASE WHEN direction = 'in' THEN channel END) AS chans
                 FROM eb_messages WHERE identity_id IN ($iph) $del GROUP BY identity_id", array_keys($idents));
            foreach ($rows as $r) {
                $idn = $idents[(int)$r['identity_id']] ?? null; if (!$idn) continue;
                $pid = (int)$idn['person_id'];
                $b = $by[$pid] ?? ['last_in_id' => null, 'last_out_id' => null, 'last_in_at' => null, 'last_out_at' => null, 'last_ok_out_at' => null, 'unread' => 0, 'in_count' => 0, 'out_count' => 0, 'chans' => [], 'pages' => [], 'idents' => []];
                foreach (['last_in_id', 'last_out_id'] as $k) if ($r[$k] !== null && (int)$r[$k] > (int)$b[$k]) $b[$k] = (int)$r[$k];
                foreach (['last_in_at', 'last_out_at', 'last_ok_out_at'] as $k) if ($r[$k] !== null && (string)$r[$k] > (string)($b[$k] ?? '')) $b[$k] = $r[$k];
                $b['unread'] += (int)$r['unread']; $b['in_count'] += (int)$r['in_count']; $b['out_count'] += (int)$r['out_count'];
                foreach (array_filter(explode(',', (string)$r['chans'])) as $c) $b['chans'][$c === 'instagram_apichi' ? 'instagram' : $c] = 1;
                if ((int)$r['in_count'] > 0 && !empty($idn['ig_account_id'])) $b['pages'][(int)$idn['ig_account_id']] = 1;
                $b['idents'][] = (int)$r['identity_id'];
                $by[$pid] = $b;
            }
        }

        // ۳) کانال/پیج آخرین پیام دریافتی (با کلید اصلی)
        $lastIn = array_values(array_filter(array_map(fn($b) => (int)$b['last_in_id'], $by)));
        $meta = [];
        if ($lastIn) {
            foreach (Database::all("SELECT id, identity_id, channel FROM eb_messages WHERE id IN (" . implode(',', array_fill(0, count($lastIn), '?')) . ")", $lastIn) as $m) {
                $m['ig_account_id'] = $idents[(int)$m['identity_id']]['ig_account_id'] ?? null;
                $meta[(int)$m['id']] = $m;
            }
        }

        // ۴) از کی منتظر پاسخ است: اولین پیام دریافتی بعد از آخرین پیام ارسالیِ موفق
        // (پیامِ ارسال‌نشده «پاسخ» حساب نمی‌شود تا حق بر عهده گرفتن چت از پشتیبان دیگر نگیرد)
        $wait = [];
        $case = []; $cp = []; $wIdents = [];
        foreach ($by as $pid => $b) {
            if (!$b['last_in_at'] || ($b['last_ok_out_at'] && $b['last_ok_out_at'] >= $b['last_in_at'])) continue;
            foreach ($b['idents'] as $iid) { $wIdents[] = $iid; if ($b['last_ok_out_at']) { $case[] = "WHEN ? THEN ?"; $cp[] = $iid; $cp[] = $b['last_ok_out_at']; } }
        }
        if ($wIdents) {
            $cmp = $case ? "CASE identity_id " . implode(' ', $case) . " ELSE '1000-01-01' END" : "'1000-01-01'";
            foreach (Database::all("SELECT identity_id, MIN(created_at) w FROM eb_messages
                     WHERE identity_id IN (" . implode(',', array_fill(0, count($wIdents), '?')) . ") AND direction = 'in' $del AND created_at > $cmp
                     GROUP BY identity_id", array_merge($wIdents, $cp)) as $w) {
                $pid = (int)$idents[(int)$w['identity_id']]['person_id'];
                if (!isset($wait[$pid]) || $w['w'] < $wait[$pid]) $wait[$pid] = $w['w'];
            }
        }

        $pdo = Database::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $gone = array_diff($ids, array_keys($by));
            if ($gone) Database::run("DELETE FROM eb_conv_index WHERE person_id IN (" . implode(',', array_fill(0, count($gone), '?')) . ")", array_values($gone));
            $ins = $pdo->prepare("INSERT INTO eb_conv_index
                (person_id, last_in_id, last_out_id, last_in_at, last_out_at, last_at, waiting_since, unread, in_count, out_count, last_channel, last_ig_account_id, chans, pages)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE last_in_id = VALUES(last_in_id), last_out_id = VALUES(last_out_id), last_in_at = VALUES(last_in_at),
                  last_out_at = VALUES(last_out_at), last_at = VALUES(last_at), waiting_since = VALUES(waiting_since), unread = VALUES(unread),
                  in_count = VALUES(in_count), out_count = VALUES(out_count), last_channel = VALUES(last_channel),
                  last_ig_account_id = VALUES(last_ig_account_id), chans = VALUES(chans), pages = VALUES(pages)");
            foreach ($by as $pid => $b) {
                $mi = $meta[(int)$b['last_in_id']] ?? [];
                $lastAt = max((string)$b['last_in_at'], (string)$b['last_out_at']) ?: null;
                $lc = $mi['channel'] ?? null; if ($lc === 'instagram_apichi') $lc = 'instagram';
                $ins->execute([$pid, $b['last_in_id'], $b['last_out_id'], $b['last_in_at'], $b['last_out_at'], $lastAt, $wait[$pid] ?? null,
                    $b['unread'], $b['in_count'], $b['out_count'], $lc, !empty($mi['ig_account_id']) ? (int)$mi['ig_account_id'] : null,
                    $b['chans'] ? ',' . implode(',', array_keys($b['chans'])) . ',' : null, $b['pages'] ? ',' . implode(',', array_keys($b['pages'])) . ',' : null]);
            }
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
