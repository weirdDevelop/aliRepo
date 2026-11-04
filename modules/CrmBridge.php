<?php
/**
 * modules/CrmBridge.php — سیستم مدیریت یکپارچه‌ی جاکجاست (۲.۰.۰)
 *
 * CRM جاکجاست در پوشه‌ی crm/ و در همین دیتابیس (جدول‌های بدون پیشوند eb_) است.
 * کلاس‌های CRM با کلاس‌های اعلان‌بان هم‌نام‌اند (Auth، Database، ...)، پس اینجا هیچ فایلی از CRM
 * بارگذاری نمی‌شود؛ هر چه از CRM لازم است با SQL مستقیم خوانده می‌شود.
 *
 *  - present()/ready(): نصب پوشه و وجود جدول‌ها
 *  - byPhone(): سوابق یک شماره در CRM (مشتری، سرنخ، میزبان، تماس، تیکت) برای صندوق ورودی و بانک مشتری
 *  - kpis(): کارت‌های CRM در داشبورد اعلان‌بان
 *  - users()/link(): اتصال حساب‌های اعلان‌بان به کاربر و داخلی CRM
 *  - انتقال: srcConnect()/srcTables()/copyTable()/installEmpty()
 */

class CrmBridge
{
    /** کلیدهای دسترسی CRM (در مجوز اپراتورها با پیشوند crm_) */
    const PERMS = [
        'calls' => 'تماس‌ها و پیگیری شماره‌ها', 'leads' => 'سرنخ‌ها و استعلام‌های سایت', 'customers' => 'مشتریان CRM',
        'tickets' => 'تیکت‌ها', 'tasks' => 'تسک‌ها و پروژه‌ها', 'hosts' => 'میزبانان', 'host_leads' => 'جذب میزبان',
        'staff' => 'پرسنل', 'leaves' => 'مرخصی‌ها', 'payroll' => 'حقوق و دستمزد', 'chat' => 'چت داخلی', 'reports' => 'گزارش‌ها و فروش سایت',
    ];

    /** جدول‌های اصلی CRM (برای تشخیص نصب) */
    const CORE_TABLES = ['users', 'customers', 'leads', 'calls', 'settings'];

    /** جدول‌هایی که هنگام انتقال فقط ساختارشان ساخته می‌شود (داده منتقل نمی‌شود) */
    const NO_DATA = ['auth_tokens'];

    public static function dir(): string { return dirname(__DIR__) . '/crm'; }

    /** پوشه‌ی crm/ نصب شده؟ */
    public static function present(): bool { return is_file(self::dir() . '/index.php') && is_file(self::dir() . '/api.php'); }

    /** جدول‌های CRM در دیتابیس هست؟ */
    private static ?bool $ready = null;
    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try { Database::one('SELECT 1 FROM `users` LIMIT 1'); Database::one('SELECT 1 FROM `customers` LIMIT 1'); return self::$ready = true; }
        catch (Throwable $e) { return self::$ready = false; }
    }

    /** کلید ارتباط سرور-به-سرور با CRM (همان که crm/config.php می‌سازد) */
    public static function key(): string
    {
        $cfg = $GLOBALS['config'] ?? null;
        if (!is_array($cfg)) { $f = dirname(__DIR__) . '/config.php'; $cfg = is_file($f) ? (include $f) : []; }
        $ak = is_array($cfg) ? (string)($cfg['app_key'] ?? '') : '';
        return $ak !== '' ? hash_hmac('sha256', 'crm-bridge-v1', $ak) : '';
    }

    public static function last10(?string $p): ?string
    {
        $d = preg_replace('/\D+/', '', strtr((string)$p, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
        return strlen($d) >= 10 ? substr($d, -10) : null;
    }

    private static function tryAll(string $sql, array $p = []): array { try { return Database::all($sql, $p); } catch (Throwable $e) { return []; } }
    private static function tryOne(string $sql, array $p = []): ?array { try { return Database::one($sql, $p); } catch (Throwable $e) { return null; } }

    /**
     * سوابق CRM یک شماره. خروجی: null اگر CRM آماده نیست؛ وگرنه
     * {found, customer, leads[], host, host_leads[], calls{total,answered,last_at,recent[]}, tickets{open,recent[]}}
     */
    public static function byPhone(?string $phone): ?array
    {
        if (!self::ready()) return null;
        $l10 = self::last10($phone);
        if (!$l10) return ['found' => false];
        $cust = self::tryOne("SELECT id, name, level, city, status, reservations, total_spend, last_purchase, score FROM customers WHERE RIGHT(mobile, 10) = ? OR RIGHT(phone, 10) = ? ORDER BY id LIMIT 1", [$l10, $l10]);
        $leads = self::tryAll("SELECT id, name, COALESCE(NULLIF(status, ''), stage) AS status, assignee, source, next_contact, created_at FROM leads WHERE RIGHT(mobile, 10) = ? ORDER BY created_at DESC LIMIT 5", [$l10]);
        if (!$leads) $leads = self::tryAll("SELECT id, name, stage AS status, assignee, source, next_contact, created_at FROM leads WHERE RIGHT(mobile, 10) = ? ORDER BY created_at DESC LIMIT 5", [$l10]);
        $host = self::tryOne("SELECT id, name, city, status, level, properties FROM hosts WHERE RIGHT(mobile, 10) = ? LIMIT 1", [$l10]);
        $hleads = self::tryAll("SELECT id, name, status, city, assignee FROM host_leads WHERE RIGHT(mobile, 10) = ? ORDER BY id DESC LIMIT 3", [$l10]);
        $cs = self::tryOne("SELECT COUNT(*) total, SUM(status = 'answered') answered, MAX(started_at) last_at FROM calls WHERE RIGHT(call_source, 10) = ? OR RIGHT(call_dest, 10) = ?", [$l10, $l10]) ?: ['total' => 0, 'answered' => 0, 'last_at' => null];
        $recent = [];
        if ((int)$cs['total']) {
            $vis = self::visibleExtens(); $vb = [$l10, $l10]; $vw = '';
            if ($vis !== null) { $vw = " AND (exten_number IS NULL OR exten_number = ''" . ($vis ? " OR exten_number IN (" . implode(',', array_fill(0, count($vis), '?')) . ")" : '') . ")"; $vb = array_merge($vb, $vis); }
            $recent = self::tryAll("SELECT id, type, status, time_talk, started_at, exten_name FROM calls WHERE (RIGHT(call_source, 10) = ? OR RIGHT(call_dest, 10) = ?){$vw} ORDER BY started_at DESC LIMIT 5", $vb);
        }
        $tk = [];
        if ($cust) $tk = self::tryAll("SELECT id, title, status, priority, dept, created_at FROM tickets WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5", [(int)$cust['id']]);
        $open = count(array_filter($tk, fn($t) => !in_array($t['status'], ['closed', 'answered'], true)));
        $found = $cust || $leads || $host || $hleads || (int)$cs['total'];
        return [
            'found' => (bool)$found, 'phone' => '0' . $l10,
            'customer' => $cust, 'leads' => $leads, 'host' => $host, 'host_leads' => $hleads,
            'calls' => ['total' => (int)$cs['total'], 'answered' => (int)$cs['answered'], 'last_at' => $cs['last_at'], 'recent' => $recent],
            'tickets' => ['open' => $open, 'recent' => $tk],
        ];
    }

    /** خروجی سازگار با JakojastLookup (صفحه‌ی قدیمی بانک مشتری) */
    public static function legacyLookup(?string $phone): array
    {
        $r = self::byPhone($phone);
        if ($r === null) return ['configured' => false, 'found' => false, 'data' => null, 'error' => null];
        if (empty($r['found'])) return ['configured' => true, 'found' => false, 'data' => null, 'error' => null];
        $c = $r['customer'];
        return ['configured' => true, 'found' => true, 'error' => null, 'data' => [
            'name' => $c['name'] ?? (($r['leads'][0]['name'] ?? null)),
            'is_customer' => (bool)$c,
            'customer' => $c ? ['id' => (int)$c['id'], 'name' => $c['name'], 'level' => $c['level'], 'status' => $c['status'], 'city' => $c['city']] : null,
            'leads' => array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name'], 'stage' => $l['status'], 'assignee' => $l['assignee'], 'source' => $l['source']], $r['leads']),
            'open_tickets_count' => $r['tickets']['open'],
            'call_stats' => ['total_calls' => $r['calls']['total'], 'answer_rate' => $r['calls']['total'] ? round($r['calls']['answered'] / $r['calls']['total'] * 100) : null, 'last_call_at' => $r['calls']['last_at']],
        ]];
    }

    /** کارت‌های CRM برای داشبورد اعلان‌بان (هر کارت جدا؛ نبودن یک جدول بقیه را خراب نمی‌کند) */
    public static function kpis(): ?array
    {
        if (!self::ready()) return null;
        $today = date('Y-m-d');
        $out = [];
        $c = self::tryOne("SELECT COUNT(*) total, SUM(status = 'answered') answered, SUM(status <> 'answered' AND (type IN ('inbound', 'missed') OR type IS NULL)) missed FROM calls WHERE started_at >= ?", [$today . ' 00:00:00']);
        if ($c) $out['calls'] = ['total' => (int)$c['total'], 'answered' => (int)$c['answered'], 'missed' => (int)$c['missed']];
        $term = ['رزرو نهایی شد', 'منصرف شد', 'برنده', 'باخته'];
        $l = self::tryOne("SELECT SUM(created_at >= ?) today, SUM(COALESCE(NULLIF(status, ''), stage) NOT IN (?, ?, ?, ?)) open FROM leads", array_merge([$today . ' 00:00:00'], $term))
          ?: self::tryOne("SELECT SUM(created_at >= ?) today, SUM(stage NOT IN (?, ?, ?, ?)) open FROM leads", array_merge([$today . ' 00:00:00'], $term));
        if ($l) $out['leads'] = ['today' => (int)$l['today'], 'open' => (int)$l['open']];
        $t = self::tryOne("SELECT SUM(status IN ('open', 'in_review')) open, SUM(status IN ('open', 'in_review') AND priority = 'high') high FROM tickets");
        if ($t) $out['tickets'] = ['open' => (int)$t['open'], 'high' => (int)$t['high']];
        $k = self::tryOne("SELECT SUM(status NOT IN ('done', 'cancelled')) open, SUM(status = 'in_progress') doing FROM tasks");
        if ($k) $out['tasks'] = ['open' => (int)$k['open'], 'doing' => (int)$k['doing']];
        $v = self::tryOne("SELECT COUNT(*) n FROM leaves WHERE status = 'pending'");
        if ($v) $out['leaves'] = ['pending' => (int)$v['n']];
        $cu = self::tryOne("SELECT COUNT(*) n, SUM(created_at >= ?) month FROM customers", [date('Y-m-01') . ' 00:00:00']);
        if ($cu) $out['customers'] = ['total' => (int)$cu['n'], 'month' => (int)$cu['month']];
        $h = self::tryOne("SELECT COUNT(*) n FROM host_leads WHERE created_at >= ?", [date('Y-m-d', strtotime('-7 days')) . ' 00:00:00']);
        if ($h) $out['host_leads'] = ['week' => (int)$h['n']];
        return $out;
    }


    /* ================= پرونده‌ی یکپارچه: همه‌ی شماره‌های CRM در «مشتریان» ================= */

    /** جدول اتصال شماره ← سوابق CRM (کلید: ۱۰ رقم آخر؛ شخص با eb_persons.phone = '0'+phone10 پیدا می‌شود) */
    public static function ensureLinks(): void
    {
        static $done = false;
        if ($done) return; $done = true;
        Database::run("CREATE TABLE IF NOT EXISTS eb_crm_links (
            phone10 CHAR(10) NOT NULL PRIMARY KEY,
            customer_id INT UNSIGNED DEFAULT NULL,
            lead_id INT UNSIGNED DEFAULT NULL,
            host_id INT UNSIGNED DEFAULT NULL,
            host_lead_id INT UNSIGNED DEFAULT NULL,
            calls INT UNSIGNED NOT NULL DEFAULT 0,
            last_call_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_cust (customer_id), KEY idx_lead (lead_id), KEY idx_host (host_id), KEY idx_calls (calls)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private static function sget(string $k): string { $r = Database::one("SELECT v FROM eb_settings WHERE k = ?", [$k]); return (string)($r['v'] ?? ''); }
    private static function sset(string $k, string $v): void { Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [$k, $v]); }

    /** نام‌هایی که اسم واقعی نیستند (سرنخ ساخته‌شده از تماس و ...) */
    private static function realName(?string $n): ?string
    {
        $n = trim((string)$n);
        if ($n === '' || preg_match('/^(تماس|سرنخ|مشتری|کاربر|ناشناس)\b|^[\d\s+\-()۰-۹]+$/u', $n)) return null;
        return mb_substr($n, 0, 150);
    }

    /**
     * ثبت یک شماره در «مشتریان» و اتصالش به سوابق CRM.
     * $field: customer_id|lead_id|host_id|host_lead_id|call ؛ $at زمان رویداد (برای مرتب‌سازی «به‌روزترین»)
     */
    private static function upsertNumber(string $raw, ?string $name, string $field, int $id, ?string $at, int $n = 1): bool
    {
        $phone = Persons::normalizePhone($raw);
        if ($phone === null) return false;          // فقط موبایل (تلفن ثابت در بانک مشتری جا ندارد)
        $p10 = substr($phone, -10);
        $name = self::realName($name);
        $person = Database::one("SELECT id, full_name, updated_at FROM eb_persons WHERE phone = ?", [$phone]);
        if (!$person) {
            Database::run("INSERT IGNORE INTO eb_persons (full_name, phone, status, created_at, updated_at) VALUES (?, ?, 'member', ?, ?)",
                [$name, $phone, $at ?: date('Y-m-d H:i:s'), $at ?: date('Y-m-d H:i:s')]);
        } else {
            $sets = []; $b = [];
            if ($name && trim((string)$person['full_name']) === '') { $sets[] = 'full_name = ?'; $b[] = $name; }
            if ($at && (string)$person['updated_at'] < $at) { $sets[] = 'updated_at = ?'; $b[] = $at; }
            if ($sets) { $b[] = (int)$person['id']; Database::run("UPDATE eb_persons SET " . implode(', ', $sets) . " WHERE id = ?", $b); }
        }
        if ($field === 'call') {
            Database::run("INSERT INTO eb_crm_links (phone10, calls, last_call_at) VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE calls = calls + VALUES(calls), last_call_at = IF(last_call_at IS NULL OR VALUES(last_call_at) > last_call_at, VALUES(last_call_at), last_call_at)", [$p10, $n, $at]);
        } else {
            Database::run("INSERT INTO eb_crm_links (phone10, {$field}) VALUES (?, ?) ON DUPLICATE KEY UPDATE {$field} = VALUES({$field})", [$p10, $id]);
        }
        return true;
    }

    /**
     * یک قدم همگام‌سازی (کرون و دکمه‌ی تنظیمات). هر منبع با مکان‌نمای id جلو می‌رود؛ تماس‌های تازه هم همین‌طور.
     * خروجی: {done, processed, added}
     */
    public static function syncStep(int $budgetSec = 15): array
    {
        if (!self::ready()) return ['done' => true, 'processed' => 0, 'skipped' => 'not_ready'];
        self::ensureLinks();
        $t0 = microtime(true); $processed = 0;
        $before = (int)(Database::one("SELECT COUNT(*) n FROM eb_crm_links")['n'] ?? 0);
        $sources = [
            'customers'  => ["SELECT id, name, mobile, phone, COALESCE(updated_at, created_at) at FROM customers WHERE id > ? ORDER BY id LIMIT 500", 'customer_id'],
            'leads'      => ["SELECT id, name, mobile, COALESCE(updated_at, created_at) at FROM leads WHERE id > ? ORDER BY id LIMIT 500", 'lead_id'],
            'hosts'      => ["SELECT id, name, mobile, COALESCE(updated_at, created_at) at FROM hosts WHERE id > ? ORDER BY id LIMIT 500", 'host_id'],
            'host_leads' => ["SELECT id, name, mobile, COALESCE(updated_at, created_at) at FROM host_leads WHERE id > ? ORDER BY id LIMIT 500", 'host_lead_id'],
            'calls'      => ["SELECT c.id, CASE WHEN c.type = 'outbound' THEN c.call_dest ELSE c.call_source END mobile, c.started_at at, n.name
                              FROM calls c LEFT JOIN call_numbers n ON n.phone10 = RIGHT(CASE WHEN c.type = 'outbound' THEN c.call_dest ELSE c.call_source END, 10)
                              WHERE c.id > ? AND c.type <> 'internal' ORDER BY c.id LIMIT 500", 'call'],
        ];
        $allDone = true;
        foreach ($sources as $src => [$sql, $field]) {
            $curKey = 'crm.sync.' . $src;
            while (true) {
                if (microtime(true) - $t0 > $budgetSec) { $allDone = false; break 2; }
                $cur = (int)self::sget($curKey);
                try { $rows = Database::all($sql, [$cur]); }
                catch (Throwable $e) {
                    // جدول کمکی (مثلاً call_numbers) نیست: بدون نام
                    if ($src === 'calls') { try { $rows = Database::all("SELECT id, CASE WHEN type = 'outbound' THEN call_dest ELSE call_source END mobile, started_at at, NULL name FROM calls WHERE id > ? AND type <> 'internal' ORDER BY id LIMIT 500", [$cur]); } catch (Throwable $e2) { $rows = []; } }
                    else $rows = [];
                }
                if (!$rows) break;
                if ($field === 'call') {
                    // تماس‌های این دسته بر اساس شماره جمع می‌شوند (یک ثبت برای هر شماره)
                    $agg = [];
                    foreach ($rows as $r) {
                        $k = substr(preg_replace('/\D+/', '', (string)($r['mobile'] ?? '')), -10);
                        if (strlen($k) < 10) continue;
                        if (!isset($agg[$k])) $agg[$k] = ['mobile' => $r['mobile'], 'name' => $r['name'] ?? null, 'at' => $r['at'], 'n' => 0];
                        $agg[$k]['n']++;
                        if ((string)$r['at'] > (string)$agg[$k]['at']) $agg[$k]['at'] = $r['at'];
                    }
                    foreach ($agg as $a) { try { self::upsertNumber((string)$a['mobile'], $a['name'], 'call', 0, $a['at'], $a['n']); } catch (Throwable $e) {} }
                    $processed += count($rows);
                    self::sset($curKey, (string)(int)end($rows)['id']);
                    if (count($rows) < 500) break;
                    continue;
                }
                foreach ($rows as $r) {
                    try {
                        self::upsertNumber((string)($r['mobile'] ?? ''), $r['name'] ?? null, $field, (int)$r['id'], $r['at'] ?? null);
                        if (!empty($r['phone']) && $field === 'customer_id') self::upsertNumber((string)$r['phone'], $r['name'] ?? null, $field, (int)$r['id'], $r['at'] ?? null);
                    } catch (Throwable $e) { /* یک ردیف خراب جلوی بقیه را نگیرد */ }
                    $processed++;
                }
                self::sset($curKey, (string)(int)end($rows)['id']);
                if (count($rows) < 500) break;
            }
        }
        if ($allDone) self::sset('crm.sync.at', (string)(Database::one('SELECT NOW() n')['n'] ?? date('Y-m-d H:i:s')));
        $after = (int)(Database::one("SELECT COUNT(*) n FROM eb_crm_links")['n'] ?? 0);
        return ['done' => $allDone, 'processed' => $processed, 'added' => max(0, $after - $before), 'linked' => $after];
    }

    /** از اول (بعد از انتقال دوباره) */
    public static function syncReset(): void
    {
        self::ensureLinks();
        foreach (['customers', 'leads', 'hosts', 'host_leads', 'calls'] as $s) self::sset('crm.sync.' . $s, '0');
        Database::run("DELETE FROM eb_crm_links");
    }

    public static function syncStatus(): array
    {
        if (!self::ready()) return ['linked' => 0, 'at' => null, 'pending' => false];
        self::ensureLinks();
        $pending = false;
        foreach (['customers' => 'customers', 'leads' => 'leads', 'calls' => 'calls'] as $s => $t) {
            $max = self::tryOne("SELECT MAX(id) m FROM `{$t}`");
            if ($max && (int)$max['m'] > (int)self::sget('crm.sync.' . $s)) { $pending = true; break; }
        }
        $c = Database::one("SELECT COUNT(*) n, SUM(customer_id IS NOT NULL) cu, SUM(lead_id IS NOT NULL) le, SUM(host_id IS NOT NULL OR host_lead_id IS NOT NULL) ho, SUM(calls > 0) ca FROM eb_crm_links");
        return ['linked' => (int)$c['n'], 'customers' => (int)$c['cu'], 'leads' => (int)$c['le'], 'hosts' => (int)$c['ho'], 'callers' => (int)$c['ca'],
                'at' => self::sget('crm.sync.at') ?: null, 'pending' => $pending];
    }

    /** شرط SQL فیلتر «مشتریان» بر اساس CRM (روی جدول eb_persons با نام مستعار p) */
    public static function personFilterSql(string $f): ?string
    {
        if (!self::ready()) return null;
        self::ensureLinks();
        $w = [
            'any' => '1=1', 'customer' => 'l.customer_id IS NOT NULL', 'lead' => 'l.lead_id IS NOT NULL',
            'host' => '(l.host_id IS NOT NULL OR l.host_lead_id IS NOT NULL)', 'caller' => 'l.calls > 0',
        ][$f] ?? null;
        if ($f === 'call_only') return "p.phone IN (SELECT CONCAT('0', l.phone10) FROM eb_crm_links l WHERE l.calls > 0) AND p.id NOT IN (SELECT person_id FROM eb_identities)";
        if ($f === 'none') return "(p.phone IS NULL OR p.phone NOT IN (SELECT CONCAT('0', l.phone10) FROM eb_crm_links l))";
        return $w ? "p.phone IN (SELECT CONCAT('0', l.phone10) FROM eb_crm_links l WHERE {$w})" : null;
    }

    /** نشان‌های CRM برای ردیف‌های فهرست مشتریان: [phone => {customer, lead, host, calls, last_call_at}] */
    public static function badges(array $phones): array
    {
        $phones = array_values(array_filter(array_unique($phones), fn($p) => is_string($p) && strlen($p) === 11));
        if (!$phones || !self::ready()) return [];
        self::ensureLinks();
        $ph = implode(',', array_fill(0, count($phones), '?'));
        $out = [];
        foreach (Database::all("SELECT * FROM eb_crm_links WHERE phone10 IN ($ph)", array_map(fn($p) => substr($p, -10), $phones)) as $r) {
            $out['0' . $r['phone10']] = ['customer' => (bool)$r['customer_id'], 'lead' => (bool)$r['lead_id'], 'host' => (bool)($r['host_id'] || $r['host_lead_id']), 'calls' => (int)$r['calls'], 'last_call_at' => $r['last_call_at']];
        }
        return $out;
    }

    /** داخلی‌هایی که کاربر جاری اعلان‌بان می‌بیند (null = همه؛ مثل قانون خود CRM برای پشتیبان‌ها) */
    public static function visibleExtens(): ?array
    {
        if (Auth::isManager()) return null;
        $u = self::tryOne("SELECT id, exten_number FROM `users` WHERE eb_user_id = ?", [(int)Auth::id()]);
        $ex = [];
        if ($u && $u['exten_number'] !== null && $u['exten_number'] !== '') $ex[] = (string)$u['exten_number'];
        if ($u) foreach (self::tryAll("SELECT s2.exten_number FROM staff s1 JOIN staff s2 ON s2.supervisor_id = s1.id WHERE s1.user_id = ? AND s2.exten_number IS NOT NULL AND s2.exten_number <> ''", [(int)$u['id']]) as $r) $ex[] = (string)$r['exten_number'];
        return $ex;
    }

    /**
     * تایم‌لاین CRM یک شخص: تماس‌ها (با ضبط و متن)، تغییر وضعیت سرنخ و پیگیری شماره، تیکت، یادداشت، استعلام و رزرو سایت.
     * پیام‌های گفتگو را خود پروفایل اعلان‌بان دارد و سمت مرورگر با این‌ها ادغام می‌شود.
     */
    public static function timeline(?string $phone, bool $calls = true): array
    {
        if (!self::ready()) return ['ready' => false, 'events' => []];
        $l10 = self::last10($phone);
        if (!$l10) return ['ready' => true, 'events' => []];
        $ev = [];
        if ($calls) {
            $vis = self::visibleExtens();
            $hasTr = Database::hasColumn('calls', 'transcript');
            $sql = "SELECT id, type, status, time_talk, time_wait, started_at, exten_name, exten_number, file_record, file_id" . ($hasTr ? ", LEFT(transcript, 1500) transcript" : "") . "
                    FROM calls WHERE (RIGHT(call_source, 10) = ? OR RIGHT(call_dest, 10) = ?)";
            $b = [$l10, $l10];
            if ($vis !== null) {
                $sql .= " AND (exten_number IS NULL OR exten_number = ''" . ($vis ? " OR exten_number IN (" . implode(',', array_fill(0, count($vis), '?')) . ")" : '') . ")";
                $b = array_merge($b, $vis);
            }
            foreach (self::tryAll($sql . " ORDER BY started_at DESC LIMIT 300", $b) as $c) {
                $ev[] = ['k' => 'call', 'at' => $c['started_at'], 'id' => (int)$c['id'], 'type' => $c['type'], 'status' => $c['status'], 'talk' => (int)$c['time_talk'], 'wait' => (int)$c['time_wait'],
                         'by' => $c['exten_name'] ?: $c['exten_number'], 'rec' => !empty($c['file_record']) || !empty($c['file_id']), 'transcript' => $c['transcript'] ?? null];
            }
            foreach (self::tryAll("SELECT from_stage, to_stage, user_name, is_auto, reason, created_at FROM call_number_stage_log WHERE phone10 = ? ORDER BY created_at DESC LIMIT 100", [$l10]) as $r)
                $ev[] = ['k' => 'stage', 'at' => $r['created_at'], 'from' => $r['from_stage'], 'to' => $r['to_stage'], 'by' => $r['user_name'], 'auto' => (bool)$r['is_auto'], 'note' => $r['reason']];
        }
        $leads = self::tryAll("SELECT id, name, COALESCE(NULLIF(status, ''), stage) st, assignee, source, created_at FROM leads WHERE RIGHT(mobile, 10) = ? ORDER BY id DESC LIMIT 20", [$l10])
              ?: self::tryAll("SELECT id, name, stage st, assignee, source, created_at FROM leads WHERE RIGHT(mobile, 10) = ? ORDER BY id DESC LIMIT 20", [$l10]);
        foreach ($leads as $l) $ev[] = ['k' => 'lead', 'at' => $l['created_at'], 'id' => (int)$l['id'], 'title' => $l['name'], 'status' => $l['st'], 'by' => $l['assignee'], 'note' => $l['source']];
        if ($leads) {
            $ids = array_map(fn($l) => (int)$l['id'], $leads);
            foreach (self::tryAll("SELECT lead_id, old_status, new_status, changed_by, note, created_at FROM lead_status_history WHERE lead_id IN (" . implode(',', $ids) . ") ORDER BY created_at DESC LIMIT 100") as $h)
                $ev[] = ['k' => 'lead_st', 'at' => $h['created_at'], 'from' => $h['old_status'], 'to' => $h['new_status'], 'by' => $h['changed_by'], 'note' => $h['note']];
        }
        $cust = self::tryOne("SELECT id FROM customers WHERE RIGHT(mobile, 10) = ? OR RIGHT(phone, 10) = ? ORDER BY id LIMIT 1", [$l10, $l10]);
        if ($cust) {
            foreach (self::tryAll("SELECT id, title, status, priority, dept, assignee, created_at FROM tickets WHERE customer_id = ? ORDER BY created_at DESC LIMIT 30", [(int)$cust['id']]) as $t)
                $ev[] = ['k' => 'ticket', 'at' => $t['created_at'], 'id' => (int)$t['id'], 'title' => $t['title'], 'status' => $t['status'], 'by' => $t['assignee'], 'note' => $t['dept']];
            foreach (self::tryAll("SELECT n.text, n.created_at, u.full_name FROM customer_notes n LEFT JOIN `users` u ON u.id = n.user_id WHERE n.customer_id = ? ORDER BY n.created_at DESC LIMIT 50", [(int)$cust['id']]) as $n)
                $ev[] = ['k' => 'note', 'at' => $n['created_at'], 'title' => $n['text'], 'by' => $n['full_name']];
        }
        foreach (self::tryAll("SELECT id, property_title, status, message, COALESCE(inquired_at, created_at) at FROM site_inquiries WHERE RIGHT(mobile, 10) = ? ORDER BY id DESC LIMIT 30", [$l10]) as $q)
            $ev[] = ['k' => 'inquiry', 'at' => $q['at'], 'title' => $q['property_title'], 'status' => $q['status'], 'note' => $q['message'] ? mb_substr($q['message'], 0, 300) : null];
        foreach (self::tryAll("SELECT order_code, property_title, amount, check_in, check_out, nights, status, COALESCE(sold_at, created_at) at FROM site_sales WHERE RIGHT(customer_mobile, 10) = ? ORDER BY id DESC LIMIT 30", [$l10]) as $o)
            $ev[] = ['k' => 'sale', 'at' => $o['at'], 'title' => $o['property_title'], 'status' => $o['status'], 'amount' => (int)$o['amount'], 'note' => trim(($o['check_in'] ? $o['check_in'] . ' تا ' . $o['check_out'] : '') . ($o['nights'] ? ' · ' . $o['nights'] . ' شب' : '') . ($o['order_code'] ? ' · کد ' . $o['order_code'] : ''), ' ·')];
        usort($ev, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
        return ['ready' => true, 'events' => $ev, 'customer_id' => $cust ? (int)$cust['id'] : null];
    }

    /** ۲.۰.۴: درخواست‌ها و سفارش‌های سایت یک شماره (برای دستیار فروش؛ فقط شماره‌ی خود مشتری) */
    public static function siteRequests(array $phones): array
    {
        if (!self::ready()) return [];
        $out = [];
        foreach (array_unique(array_filter(array_map(fn($p) => substr(preg_replace('/\D+/', '', (string)$p), -10), $phones))) as $l10) {
            if (strlen($l10) < 10) continue;
            foreach (self::tryAll("SELECT order_code, property_title, amount, check_in, check_out, nights, status, COALESCE(sold_at, created_at) at FROM site_sales WHERE RIGHT(customer_mobile, 10) = ? ORDER BY id DESC LIMIT 8", [$l10]) as $o)
                $out[] = ['kind' => 'sale', 'code' => (string)$o['order_code'], 'title' => (string)$o['property_title'], 'status' => (string)$o['status'], 'check_in' => (string)$o['check_in'], 'check_out' => (string)$o['check_out'], 'nights' => (int)$o['nights'], 'amount' => (int)$o['amount'], 'at' => (string)$o['at']];
            foreach (self::tryAll("SELECT property_title, status, message, COALESCE(inquired_at, created_at) at FROM site_inquiries WHERE RIGHT(mobile, 10) = ? ORDER BY id DESC LIMIT 8", [$l10]) as $q)
                $out[] = ['kind' => 'inquiry', 'code' => '', 'title' => (string)$q['property_title'], 'status' => (string)$q['status'], 'at' => (string)$q['at']];
        }
        usort($out, fn($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($out, 0, 10);
    }

    /* ================= کاربران ================= */

    /** ستون اتصال (همان که crm/core/Auth.php هم می‌سازد) */
    public static function ensureLinkColumn(): void
    {
        if (!self::ready()) return;
        try { Database::one('SELECT eb_user_id FROM `users` LIMIT 1'); }
        catch (Throwable $e) {
            try { Database::run("ALTER TABLE `users` ADD COLUMN `eb_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'حساب اعلان‌بان', ADD KEY `idx_eb_user` (`eb_user_id`)"); } catch (Throwable $e2) {}
        }
    }

    /** فهرست حساب‌های اعلان‌بان با کاربر CRM متصل، داخلی تلفنچی و پرسنل */
    public static function users(): array
    {
        if (!self::ready()) return ['eb' => [], 'crm' => []];
        self::ensureLinkColumn();
        $eb = Database::all("SELECT id, username, " . Auth::nameSql('') . " name, role, is_active FROM eb_users WHERE role <> 'viewer' ORDER BY is_active DESC, id");
        $crm = self::tryAll("SELECT u.id, u.username, u.full_name, u.role, u.exten_number, u.is_active, u.eb_user_id, s.id staff_id, s.name staff_name
                             FROM `users` u LEFT JOIN staff s ON s.user_id = u.id ORDER BY u.is_active DESC, u.full_name");
        if (!$crm) $crm = self::tryAll("SELECT id, username, full_name, role, exten_number, is_active, eb_user_id, NULL staff_id, NULL staff_name FROM `users` ORDER BY is_active DESC, full_name");
        $staff = self::tryAll("SELECT id, name, code, exten_number, user_id FROM staff ORDER BY status = 'active' DESC, name");
        return ['eb' => $eb, 'crm' => $crm, 'staff' => $staff];
    }

    /**
     * اتصال یک حساب اعلان‌بان به یک کاربر موجود CRM (یا 0 = ساخت خودکار در اولین ورود)، داخلی و پرسنل.
     */
    public static function link(int $ebId, int $crmUserId, string $exten, int $staffId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'جدول‌های CRM هنوز ساخته نشده‌اند'];
        self::ensureLinkColumn();
        $eb = Database::one("SELECT id, username, display_name FROM eb_users WHERE id = ?", [$ebId]);
        if (!$eb) return ['ok' => false, 'error' => 'حساب پیدا نشد'];
        $exten = preg_replace('/[^0-9]/', '', $exten);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($crmUserId > 0) {
                if (!Database::one("SELECT id FROM `users` WHERE id = ?", [$crmUserId])) throw new RuntimeException('کاربر CRM پیدا نشد');
                Database::run("UPDATE `users` SET eb_user_id = NULL WHERE eb_user_id = ? AND id <> ?", [$ebId, $crmUserId]);
                Database::run("UPDATE `users` SET eb_user_id = ? WHERE id = ?", [$ebId, $crmUserId]);
                $uid = $crmUserId;
            } else {
                $row = Database::one("SELECT id FROM `users` WHERE eb_user_id = ?", [$ebId]);
                if (!$row) {
                    $uname = (string)$eb['username'];
                    if (Database::one("SELECT id FROM `users` WHERE username = ?", [$uname])) $uname = 'eb' . $ebId . '_' . $uname;
                    $name = trim((string)$eb['display_name']) !== '' ? $eb['display_name'] : $eb['username'];
                    Database::run("INSERT INTO `users` (username, password, full_name, role, is_active, eb_user_id) VALUES (?, ?, ?, 'staff', 1, ?)",
                        [mb_substr($uname, 0, 60), password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), mb_substr($name, 0, 120), $ebId]);
                    $uid = Database::lastId();
                } else $uid = (int)$row['id'];
            }
            Database::run("UPDATE `users` SET exten_number = ? WHERE id = ?", [$exten !== '' ? $exten : null, $uid]);
            if ($staffId > 0) {
                try {
                    Database::run("UPDATE staff SET user_id = NULL WHERE user_id = ? AND id <> ?", [$uid, $staffId]);
                    Database::run("UPDATE staff SET user_id = ?" . ($exten !== '' ? ", exten_number = ?" : "") . " WHERE id = ?", $exten !== '' ? [$uid, $exten, $staffId] : [$uid, $staffId]);
                } catch (Throwable $e) { /* ستون user_id در staff هنوز ساخته نشده */ }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => true];
    }

    /* ================= انتقال داده از دیتابیس CRM قبلی ================= */

    public static function srcConnect(array $c): PDO
    {
        $host = trim((string)($c['host'] ?? 'localhost')) ?: 'localhost';
        $port = preg_replace('/[^0-9]/', '', (string)($c['port'] ?? '')) ?: '3306';
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '' || !preg_match('/^[A-Za-z0-9_\-\$]+$/', $name)) throw new RuntimeException('نام دیتابیس معتبر نیست');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8,
        ]);
        return $pdo;
    }

    /** آیا دیتابیس مبدأ همان دیتابیس اعلان‌بان است؟ */
    public static function sameDb(PDO $src): bool
    {
        try {
            $a = $src->query('SELECT DATABASE() d, @@hostname h, @@port p')->fetch();
            $b = Database::one('SELECT DATABASE() d, @@hostname h, @@port p');
            return $a && $b && $a['d'] === $b['d'] && $a['h'] === $b['h'] && (string)$a['p'] === (string)$b['p'];
        } catch (Throwable $e) { return false; }
    }

    private static function q(string $t): string { return '`' . str_replace('`', '', $t) . '`'; }

    /** جدول‌های مبدأ (به‌جز eb_) با تعداد ردیف و وضعیت مقصد */
    public static function srcTables(PDO $src): array
    {
        $out = [];
        foreach ($src->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $t = (string)$r[0];
            if (stripos($t, 'eb_') === 0) continue;
            $n = (int)$src->query('SELECT COUNT(*) FROM ' . self::q($t))->fetchColumn();
            $dst = null;
            try { $dst = (int)(Database::one('SELECT COUNT(*) n FROM ' . self::q($t))['n'] ?? 0); } catch (Throwable $e) { $dst = null; }
            $out[] = ['table' => $t, 'rows' => $n, 'target_rows' => $dst, 'data' => !in_array($t, self::NO_DATA, true)];
        }
        return $out;
    }

    /**
     * کپی یک جدول، تکه‌تکه. offset=0 یعنی شروع: جدول مقصد (اگر replace) حذف و از روی ساختار مبدأ ساخته می‌شود.
     * خروجی: {done, next, copied, total}
     */
    public static function copyTable(PDO $src, string $table, int $offset, bool $replace, int $chunk = 400): array
    {
        if (stripos($table, 'eb_') === 0) throw new RuntimeException('جدول‌های اعلان‌بان منتقل نمی‌شوند');
        $exists = (bool)$src->query("SHOW TABLES LIKE " . $src->quote($table))->fetchColumn();
        if (!$exists) throw new RuntimeException('جدول در مبدأ نیست: ' . $table);
        $dst = Database::pdo();
        $dst->exec('SET FOREIGN_KEY_CHECKS = 0');
        $total = (int)$src->query('SELECT COUNT(*) FROM ' . self::q($table))->fetchColumn();
        if ($offset === 0) {
            $has = true;
            try { Database::one('SELECT 1 FROM ' . self::q($table) . ' LIMIT 1'); } catch (Throwable $e) { $has = false; }
            if ($has && !$replace) {
                $n = (int)(Database::one('SELECT COUNT(*) n FROM ' . self::q($table))['n'] ?? 0);
                if ($n > 0) throw new RuntimeException("جدول «{$table}» در دیتابیس اعلان‌بان داده دارد ({$n} ردیف). برای جایگزینی، گزینه‌ی «جایگزینی جدول‌های موجود» را روشن کنید.");
            }
            $create = $src->query('SHOW CREATE TABLE ' . self::q($table))->fetch(PDO::FETCH_NUM)[1] ?? '';
            if ($create === '') throw new RuntimeException('ساختار جدول خوانده نشد: ' . $table);
            $create = preg_replace('/AUTO_INCREMENT=\d+\s*/i', '', $create);
            $dst->exec('DROP TABLE IF EXISTS ' . self::q($table));
            $dst->exec($create);
        }
        if (in_array($table, self::NO_DATA, true) || $total === 0) {
            $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
            return ['done' => true, 'next' => 0, 'copied' => 0, 'total' => $total];
        }
        // ترتیب پایدار با کلید اصلی (اگر بود)
        $pk = [];
        foreach ($src->query('SHOW KEYS FROM ' . self::q($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll() as $k) $pk[] = self::q($k['Column_name']);
        $order = $pk ? ' ORDER BY ' . implode(', ', $pk) : '';
        $st = $src->query('SELECT * FROM ' . self::q($table) . $order . ' LIMIT ' . (int)$chunk . ' OFFSET ' . (int)$offset);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols = array_keys($rows[0]);
            $colSql = implode(', ', array_map([self::class, 'q'], $cols));
            $ph = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            foreach (array_chunk($rows, 100) as $part) {
                $vals = [];
                foreach ($part as $r) foreach ($cols as $c) $vals[] = $r[$c];
                $stm = $dst->prepare('INSERT IGNORE INTO ' . self::q($table) . " ({$colSql}) VALUES " . implode(', ', array_fill(0, count($part), $ph)));
                $stm->execute($vals);
            }
        }
        $next = $offset + count($rows);
        $dst->exec('SET FOREIGN_KEY_CHECKS = 1');
        return ['done' => count($rows) < $chunk || $next >= $total, 'next' => $next, 'copied' => count($rows), 'total' => $total];
    }

    /** ساخت جدول‌های خالی CRM از crm/db.sql (بدون کاربر پیش‌فرض) */
    public static function installEmpty(): array
    {
        $f = self::dir() . '/db.sql';
        if (!is_file($f)) return ['ok' => false, 'error' => 'فایل crm/db.sql پیدا نشد'];
        $sql = (string)file_get_contents($f) . ";\n" . (string)@file_get_contents(self::dir() . '/db_extra.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $n = 0; $errs = [];
        $pdo = Database::pdo();
        foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql)))) as $stmt) {
            if ($stmt === '' || preg_match('/^INSERT\s+INTO\s+`?users`?/i', $stmt) || preg_match('/^(CREATE\s+DATABASE|USE\s)/i', $stmt)) continue;
            if (preg_match('/^CREATE\s+TABLE/i', $stmt) && !preg_match('/IF\s+NOT\s+EXISTS/i', $stmt)) $stmt = preg_replace('/^CREATE\s+TABLE/i', 'CREATE TABLE IF NOT EXISTS', $stmt);
            try { $pdo->exec($stmt); $n++; } catch (Throwable $e) { $errs[] = mb_substr($e->getMessage(), 0, 200); }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::afterMigrate();
        return ['ok' => !$errs || self::ready(), 'statements' => $n, 'errors' => array_slice($errs, 0, 8)];
    }

    /** بعد از انتقال: کش ساختار CRM و اعلان‌بان پاک شود تا ستون‌های تازه دوباره ساخته شوند */
    public static function afterMigrate(): void
    {
        self::$ready = null;
        foreach (glob(self::dir() . '/logs/.schema_cache_*.json') ?: [] as $f) @unlink($f);
        Database::forgetSchemaCache();
        try { Database::run("DELETE FROM `auth_tokens`"); } catch (Throwable $e) {}
        // جدول‌های جانبی که در نصب‌های قدیمی دستی ساخته شده بودند
        $x = (string)@file_get_contents(self::dir() . '/db_extra.sql');
        foreach (array_filter(array_map('trim', explode(";\n", preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $x))))) as $st) {
            try { Database::pdo()->exec($st); } catch (Throwable $e) {}
        }
        self::ensureLinkColumn();
        try { self::syncReset(); } catch (Throwable $e) {}
    }

    /** وضعیت کلی برای صفحه‌ی تنظیمات CRM */
    public static function status(): array
    {
        $tables = [];
        if (self::ready()) foreach (['users', 'customers', 'leads', 'calls', 'tickets', 'tasks', 'hosts', 'host_leads', 'staff', 'chat_messages'] as $t) {
            $r = self::tryOne('SELECT COUNT(*) n FROM ' . self::q($t));
            $tables[$t] = $r ? (int)$r['n'] : null;
        }
        $cron = null;
        if (self::ready()) { $r = self::tryOne("SELECT value FROM settings WHERE key_name = 'last_sync_at'"); $cron = $r['value'] ?? null; }
        return ['present' => self::present(), 'ready' => self::ready(), 'tables' => $tables, 'last_sync_at' => $cron,
                'dir' => self::dir(), 'php' => PHP_BINARY ?: 'php'];
    }
}
