<?php
/**
 * modules/ApiConnectors.php — اتصال وب‌سرویس‌های بیرونی به دستیار فروش
 * ==========================================================================
 * هر «اتصال» یک وب‌سرویس فروش/رزرو است (جاکجاست یا هر API مشابه) که چند «ابزار» دارد.
 * هر ابزار یک endpoint است که دستیار هوش مصنوعی می‌تواند صدا بزند: جستجو، جزئیات، استعلام
 * قیمت، ثبت سفارش، پیگیری وضعیت و… . تعریف ابزارها JSON است و از پنل قابل ویرایش است؛ پس
 * برای وصل کردن یک API تازه به کدنویسی نیازی نیست.
 *
 * تعریف ابزار:
 *   name        نام لاتین (a-z 0-9 _)
 *   title       عنوان فارسی برای پنل
 *   description توضیح برای هوش مصنوعی: کی و چرا صدا زده شود
 *   method      GET یا POST
 *   path        مسیر نسبت به base_url؛ {param} با مقدار همان پارامتر پر می‌شود (مثل /spaces/{id})
 *   params      JSON Schema ورودی‌ها {type:object, properties:{…}, required:[…]}
 *   inject      پارامترهایی که سیستم خودش پر می‌کند و AI نمی‌بیند: {chat_ref} {person_ref} {channel} {phone} {name}
 *   confirm     true = عمل جدی (ثبت سفارش)؛ فقط بعد از تأیید صریح مشتری در پیام بعدی اجرا می‌شود
 *   side_effect true = در «آزمایش در پنل» اجرا نمی‌شود (پیامک، ثبت سفارش)
 *   track       true = پاسخ در «سفارش‌های دستیار» ثبت/به‌روز می‌شود
 *   cache       ثانیه‌ی کش پاسخ (برای داده‌های ثابت مثل فهرست شهرها)
 *   enabled     خاموش/روشن
 */
class ApiConnectors
{
    const SCHEMA = '6';
    /** محله‌های هر منطقه‌ی تهران، به ترتیب تعداد اقامتگاه (برای جستجوی محله‌های همسایه) */
    const TEHRAN_AREAS = [
        'شمال تهران' => ['پاسداران', 'ونک', 'میرداماد', 'جردن', 'دروس', 'زعفرانیه', 'تجریش', 'الهیه', 'نیاوران', 'ولنجک', 'قیطریه', 'اقدسیه'],
        'غرب تهران'  => ['صادقیه', 'سعادت آباد', 'جنت آباد', 'آیت الله کاشانی', 'ستارخان', 'پونک', 'چیتگر', 'گیشا', 'شهرک غرب', 'مرزداران', 'اکباتان'],
        'شرق تهران'  => ['نارمک', 'تهرانپارس', 'پیروزی'],
        'مرکز تهران' => ['انقلاب', 'ولیعصر', 'فاطمی', 'یوسف آباد', 'منیریه', 'کشاورز'],
        'جنوب تهران' => ['یافت آباد', 'نازی آباد'],
    ];
    private static $ready = null;

    /* ======================= اسکیما ======================= */

    public static function ensure(): void
    {
        if (self::$ready) return;
        try {
            $v = Database::one("SELECT v FROM eb_settings WHERE k = 'agent.schema'");
            if (($v['v'] ?? '') === self::SCHEMA) { self::$ready = true; return; }
        } catch (Throwable $e) { return; }
        $pdo = Database::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_api_connectors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL,
            name VARCHAR(120) NOT NULL,
            base_url VARCHAR(300) NOT NULL,
            auth_type VARCHAR(10) NOT NULL DEFAULT 'bearer',
            auth_name VARCHAR(80) DEFAULT NULL,
            token TEXT,
            headers_json TEXT,
            instructions TEXT,
            tools_json MEDIUMTEXT,
            channel_map_json TEXT,
            test_path VARCHAR(200) DEFAULT NULL,
            webhook_secret VARCHAR(255) DEFAULT NULL,
            webhook_json TEXT,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT DEFAULT NULL,
            connector_id INT DEFAULT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'tool',
            tool VARCHAR(80) DEFAULT NULL,
            args_json TEXT,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            http INT DEFAULT NULL,
            summary VARCHAR(500) DEFAULT NULL,
            result MEDIUMTEXT,
            ms INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_person (person_id), KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_state (
            person_id INT PRIMARY KEY,
            mode VARCHAR(10) NOT NULL DEFAULT 'ai',
            pending_json TEXT,
            memory_json MEDIUMTEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            connector_id INT NOT NULL,
            person_id INT DEFAULT NULL,
            identity_id INT DEFAULT NULL,
            ref VARCHAR(80) NOT NULL,
            ref2 VARCHAR(80) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            status VARCHAR(40) DEFAULT NULL,
            amount BIGINT DEFAULT NULL,
            checkout_url VARCHAR(500) DEFAULT NULL,
            data_json MEDIUMTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ref (connector_id, ref), KEY idx_person (person_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // نسخه‌ی ۲: پروفایل برندها، پایگاه دانش، امنیت، پیگیری و نظرسنجی
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            brand VARCHAR(120) NOT NULL DEFAULT '',
            prompt TEXT,
            kb_mode VARCHAR(10) NOT NULL DEFAULT 'both',
            connectors_json TEXT,
            link_mode VARCHAR(10) NOT NULL DEFAULT 'limited',
            link_max INT NOT NULL DEFAULT 1,
            allow_checkout TINYINT(1) NOT NULL DEFAULT 1,
            avoid_names VARCHAR(255) DEFAULT NULL,
            targets_json TEXT,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_kb_docs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profile_id INT DEFAULT NULL,
            title VARCHAR(200) NOT NULL,
            filename VARCHAR(200) DEFAULT NULL,
            chars INT NOT NULL DEFAULT 0,
            chunks INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_profile (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_kb_chunks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_id INT NOT NULL,
            heading VARCHAR(400) DEFAULT NULL,
            content MEDIUMTEXT,
            terms MEDIUMTEXT,
            len INT NOT NULL DEFAULT 0,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_doc (doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_security (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT DEFAULT NULL,
            kind VARCHAR(12) NOT NULL,
            score INT NOT NULL DEFAULT 0,
            matched VARCHAR(500) DEFAULT NULL,
            text TEXT,
            action VARCHAR(30) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_person (person_id), KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_surveys (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT DEFAULT NULL,
            person_id INT DEFAULT NULL,
            rating TINYINT DEFAULT NULL,
            comment TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS eb_agent_callbacks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            person_id INT DEFAULT NULL,
            phone VARCHAR(20) NOT NULL,
            name VARCHAR(120) DEFAULT NULL,
            preferred VARCHAR(120) DEFAULT NULL,
            note VARCHAR(500) DEFAULT NULL,
            channel VARCHAR(30) DEFAULT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            result VARCHAR(255) DEFAULT NULL,
            done_by INT DEFAULT NULL,
            done_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_status (status, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $add = function (string $t, string $c, string $def) use ($pdo) {
            try { if (!Database::hasColumn($t, $c)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def"); } catch (Throwable $e) {}
        };
        Database::forgetSchemaCache();
        foreach (['check_in' => 'VARCHAR(10) DEFAULT NULL', 'check_out' => 'VARCHAR(10) DEFAULT NULL', 'guests' => 'INT DEFAULT NULL', 'profile_id' => 'INT DEFAULT NULL',
                  'reminded_at' => 'DATETIME DEFAULT NULL', 'arrival_at' => 'DATETIME DEFAULT NULL', 'survey_at' => 'DATETIME DEFAULT NULL', 'rating' => 'TINYINT DEFAULT NULL',
                  'rejected_at' => 'DATETIME DEFAULT NULL', 'rej_fu_at' => 'DATETIME DEFAULT NULL'] as $c => $d) $add('eb_agent_orders', $c, $d);
        foreach (['last_offer_at' => 'DATETIME DEFAULT NULL', 'followups' => 'INT NOT NULL DEFAULT 0', 'last_followup_at' => 'DATETIME DEFAULT NULL',
                  'strikes' => 'INT NOT NULL DEFAULT 0', 'strike_at' => 'DATETIME DEFAULT NULL', 'survey_order' => 'INT DEFAULT NULL', 'survey_wait' => 'VARCHAR(10) DEFAULT NULL',
                  'survey_wait_at' => 'DATETIME DEFAULT NULL', 'profile_id' => 'INT DEFAULT NULL', 'busy_at' => 'DATETIME DEFAULT NULL'] as $c => $d) $add('eb_agent_state', $c, $d);
        foreach (['agent_name' => "VARCHAR(60) NOT NULL DEFAULT ''", 'persona' => "VARCHAR(10) NOT NULL DEFAULT 'ai'", 'avatar' => "VARCHAR(255) NOT NULL DEFAULT ''", 'sms_links' => 'TINYINT(1) NOT NULL DEFAULT 1'] as $c => $d) $add('eb_agent_profiles', $c, $d);
        $add('eb_agent_orders', 'polled_at', 'DATETIME DEFAULT NULL');
        $add('eb_agent_state', 'extra_json', 'TEXT');
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('agent.schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::SCHEMA]);
        Database::forgetSchemaCache();
        self::$ready = true;
        // پروفایل پیش‌فرض (یک‌بار)
        try {
            if (!Database::one("SELECT id FROM eb_agent_profiles LIMIT 1")) {
                $pr = Database::one("SELECT v FROM eb_settings WHERE k = 'agent.prompt'");
                Database::run("INSERT INTO eb_agent_profiles (name, brand, prompt, kb_mode, link_mode, link_max, allow_checkout, is_default, targets_json, connectors_json) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    ['پیش‌فرض', 'جاکجاست', $pr['v'] ?? AgentProfiles::DEFAULT_PROMPT, 'both', 'limited', 1, 1, 1, '[]', '[]']);
            }
        } catch (Throwable $e) {}
    }

    /* ======================= خواندن/ذخیره ======================= */

    public static function all(bool $onlyActive = false): array
    {
        self::ensure();
        $rows = Database::all("SELECT * FROM eb_api_connectors" . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY id");
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function get(int $id): ?array
    {
        self::ensure();
        $r = Database::one("SELECT * FROM eb_api_connectors WHERE id = ?", [$id]);
        return $r ? self::hydrate($r) : null;
    }

    public static function bySlug(string $slug): ?array
    {
        self::ensure();
        $r = Database::one("SELECT * FROM eb_api_connectors WHERE slug = ?", [$slug]);
        return $r ? self::hydrate($r) : null;
    }

    private static function hydrate(array $r): array
    {
        foreach (['headers_json' => 'headers', 'tools_json' => 'tools', 'channel_map_json' => 'channel_map', 'webhook_json' => 'webhook'] as $col => $k) {
            $d = json_decode((string)($r[$col] ?? ''), true);
            $r[$k] = is_array($d) ? $d : [];
            unset($r[$col]);
        }
        $r['id'] = (int)$r['id'];
        $r['is_active'] = (int)$r['is_active'];
        return $r;
    }

    /** نسخه‌ی امن برای پنل (بدون توکن و راز وب‌هوک) */
    public static function publicRow(array $c): array
    {
        $t = (string)($c['token'] ?? '');
        $c['token_set'] = $t !== '';
        $c['token_hint'] = $t === '' ? '' : (mb_substr($t, 0, 7) . '…' . mb_substr($t, -4));
        $c['webhook_secret_set'] = (string)($c['webhook_secret'] ?? '') !== '';
        unset($c['token'], $c['webhook_secret']);
        return $c;
    }

    public static function save(array $in, ?int $id = null): int
    {
        self::ensure();
        $slug = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)($in['slug'] ?? '')));
        if ($slug === '') throw new InvalidArgumentException('شناسه‌ی لاتین اتصال لازم است (مثلاً jakojast)');
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('نام اتصال لازم است');
        $base = rtrim(trim((string)($in['base_url'] ?? '')), '/');
        if (!preg_match('#^https?://#i', $base)) throw new InvalidArgumentException('آدرس پایه باید با https:// شروع شود');
        $tools = $in['tools'] ?? [];
        if (is_string($tools)) {
            $tools = json_decode($tools, true);
            if (!is_array($tools)) throw new InvalidArgumentException('تعریف ابزارها JSON معتبر نیست');
        }
        $tools = self::normalizeTools($tools);
        $dup = Database::one("SELECT id FROM eb_api_connectors WHERE slug = ? AND id <> ?", [$slug, (int)$id]);
        if ($dup) throw new InvalidArgumentException('اتصال دیگری با همین شناسه وجود دارد');
        $j = fn($v) => json_encode(is_string($v) ? (json_decode($v, true) ?: []) : ($v ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $vals = [
            'slug' => $slug, 'name' => mb_substr($name, 0, 120), 'base_url' => mb_substr($base, 0, 300),
            'auth_type' => in_array($in['auth_type'] ?? 'bearer', ['bearer', 'header', 'query', 'none'], true) ? $in['auth_type'] : 'bearer',
            'auth_name' => mb_substr(trim((string)($in['auth_name'] ?? '')), 0, 80) ?: null,
            'headers_json' => $j($in['headers'] ?? []),
            'instructions' => (string)($in['instructions'] ?? ''),
            'tools_json' => json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'channel_map_json' => $j($in['channel_map'] ?? []),
            'test_path' => mb_substr(trim((string)($in['test_path'] ?? '')), 0, 200) ?: null,
            'webhook_json' => $j($in['webhook'] ?? []),
            'is_active' => !empty($in['is_active']) ? 1 : 0,
        ];
        // توکن و راز وب‌هوک: خالی = دست‌نزن (در پنل هرگز برنمی‌گردند)
        if (array_key_exists('token', $in) && trim((string)$in['token']) !== '') $vals['token'] = trim((string)$in['token']);
        if (array_key_exists('webhook_secret', $in) && trim((string)$in['webhook_secret']) !== '') $vals['webhook_secret'] = trim((string)$in['webhook_secret']);
        if (!empty($in['clear_token'])) $vals['token'] = null;
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
            Database::run("UPDATE eb_api_connectors SET $set WHERE id = ?", array_merge(array_values($vals), [$id]));
            return $id;
        }
        $cols = implode(', ', array_keys($vals));
        Database::run("INSERT INTO eb_api_connectors ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")", array_values($vals));
        return Database::lastId();
    }

    public static function delete(int $id): void
    {
        self::ensure();
        Database::run("DELETE FROM eb_api_connectors WHERE id = ?", [$id]);
    }

    private static function normalizeTools(array $tools): array
    {
        $out = []; $seen = [];
        foreach ($tools as $t) {
            if (!is_array($t)) continue;
            $name = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)($t['name'] ?? '')));
            if ($name === '' || isset($seen[$name])) continue;
            $seen[$name] = true;
            $method = strtoupper((string)($t['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET';
            $params = is_array($t['params'] ?? null) ? $t['params'] : ['type' => 'object', 'properties' => new stdClass()];
            if (($params['type'] ?? '') !== 'object') $params = ['type' => 'object', 'properties' => $params];
            if (empty($params['properties'])) $params['properties'] = new stdClass();
            $out[] = [
                'name' => mb_substr($name, 0, 40), 'title' => (string)($t['title'] ?? $name),
                'description' => mb_substr((string)($t['description'] ?? ''), 0, 1500),
                'method' => $method, 'path' => '/' . ltrim((string)($t['path'] ?? ''), '/'),
                'params' => $params, 'inject' => is_array($t['inject'] ?? null) ? $t['inject'] : new stdClass(),
                'confirm' => !empty($t['confirm']), 'side_effect' => !empty($t['side_effect']),
                'track' => !empty($t['track']), 'cache' => max(0, (int)($t['cache'] ?? 0)),
                'own_ref' => preg_replace('/[^a-z0-9_]/i', '', (string)($t['own_ref'] ?? '')), 'phone_check' => preg_replace('/[^a-z0-9_]/i', '', (string)($t['phone_check'] ?? '')),
                'enabled' => !array_key_exists('enabled', $t) || !empty($t['enabled']),
                // ۱.۰.۷: اگر نتیجه‌ای نیامد، این فیلترها به ترتیب برداشته و دوباره جستجو می‌شود
                // ۱.۰.۱۰: گروه مقدارهای هم‌ارز برای جستجوی دوباره (محله‌های یک منطقه) پیش از برداشتن فیلتر
                'relax_groups' => self::cleanGroups($t['relax_groups'] ?? []),
                // ابزار پیامک لینک (برای پیامک خودکار لینک کدها در کانال‌های بدون لینک): نام پارامترهای کدها و موبایل
                'link_sms' => is_array($t['link_sms'] ?? null) ? ['ids' => preg_replace('/[^a-z0-9_]/i', '', (string)($t['link_sms']['ids'] ?? 'space_ids')), 'phone' => preg_replace('/[^a-z0-9_]/i', '', (string)($t['link_sms']['phone'] ?? 'phone'))] : null,
                // اگر مشتری نام یک گروه را گفت (مثلاً «شمال تهران»)، همان جستجو شود: این پارامترها پاک/تنظیم می‌شوند
                'group_force' => is_array($t['group_force'] ?? null) ? ['clear' => array_values(array_map('strval', (array)($t['group_force']['clear'] ?? []))), 'set' => (array)($t['group_force']['set'] ?? [])] : null,
                'relax' => array_values(array_filter(array_map(fn($x) => preg_replace('/[^a-z0-9_]/i', '', (string)$x), is_array($t['relax'] ?? null) ? $t['relax'] : preg_split('/[\s,]+/', (string)($t['relax'] ?? ''))))),
            ];
        }
        return $out;
    }

    /* ======================= ابزارها برای هوش مصنوعی ======================= */

    /** نام تابع برای مدل: {slug}__{tool} */
    public static function fnName(array $c, array $t): string
    {
        return substr($c['slug'] . '__' . $t['name'], 0, 64);
    }

    /** همه‌ی ابزارهای فعال به قالب tools در chat/completions + نگاشت نام → [connector, tool] */
    public static function toolSpecs(?array $onlyIds = null): array
    {
        $specs = []; $map = [];
        foreach (self::all(true) as $c) {
            if ($onlyIds && !in_array($c['id'], $onlyIds, true)) continue;
            foreach ($c['tools'] as $t) {
                if (empty($t['enabled'])) continue;
                $fn = self::fnName($c, $t);
                $params = $t['params'];
                // پارامترهای تزریقی از دید مدل پنهان‌اند
                $inj = (array)($t['inject'] ?? []);
                if ($inj && isset($params['properties']) && is_array($params['properties'])) {
                    foreach (array_keys($inj) as $k) unset($params['properties'][$k]);
                    if (!empty($params['required'])) $params['required'] = array_values(array_diff($params['required'], array_keys($inj)));
                    if (empty($params['required'])) unset($params['required']);
                    if (!$params['properties']) $params['properties'] = new stdClass();
                }
                $params = self::schema($params);   // «properties» خالی باید {} باشد، نه []؛ وگرنه OpenAI کل درخواست را رد می‌کند
                $desc = '[' . $c['name'] . '] ' . $t['description'];
                if (!empty($t['confirm'])) $desc .= ' (عمل جدی: فقط بعد از نشان دادن خلاصه و گرفتن تأیید صریح مشتری)';
                $specs[] = ['type' => 'function', 'function' => ['name' => $fn, 'description' => mb_substr($desc, 0, 1000), 'parameters' => $params]];
                $map[$fn] = [$c, $t];
            }
        }
        return [$specs, $map];
    }

    /** یکسان‌سازی JSON Schema برای سرویس‌های سازگار با OpenAI (بازگشتی) */
    public static function schema($p)
    {
        if (!is_array($p)) return $p;
        $isList = $p !== [] && array_keys($p) === range(0, count($p) - 1);
        if ($isList) return array_map([self::class, 'schema'], $p);
        foreach ($p as $k => $v) {
            if ($k === 'properties') {
                $props = is_array($v) ? $v : [];
                foreach ($props as $pk => $pv) $props[$pk] = self::schema($pv);
                $p[$k] = $props ? $props : new stdClass();
            } elseif ($k === 'required') {
                $req = array_values(array_filter((array)$v, 'is_string'));
                if ($req) $p[$k] = $req; else unset($p[$k]);
            } elseif (is_array($v)) {
                $p[$k] = self::schema($v);
            }
        }
        if (($p['type'] ?? '') === 'object' && !isset($p['properties'])) $p['properties'] = new stdClass();
        if (($p['type'] ?? '') === 'array' && !isset($p['items'])) $p['items'] = ['type' => 'string'];
        return $p;
    }

    /** دستورالعمل‌های اتصال‌های فعال برای دستور سیستم */
    public static function instructionsBlock(?array $onlyIds = null, string $brand = ''): string
    {
        $parts = [];
        foreach (self::all(true) as $c) {
            if ($onlyIds && !in_array($c['id'], $onlyIds, true)) continue;
            $i = trim(str_replace('{brand}', $brand !== '' ? $brand : $c['name'], (string)$c['instructions']));
            if ($i !== '') $parts[] = "[راهنمای فروش با وب‌سرویس «" . ($brand !== '' ? $brand : $c['name']) . "»]\n" . $i;
        }
        return implode("\n\n", $parts);
    }

    /* ======================= فراخوانی ======================= */

    /**
     * اجرای یک ابزار.
     * $ctx: person_id, identity_id, channel, phone, name
     * @return array{ok:bool, http:int, data:mixed, error:?string, ms:int}
     */
    public static function call(array $c, array $t, array $args, array $ctx = []): array
    {
        $t0 = microtime(true);
        // پارامترهای تزریقی
        foreach ((array)($t['inject'] ?? []) as $k => $tpl) {
            $args[$k] = self::fill((string)$tpl, $c, $ctx);
        }
        $args = array_filter($args, fn($v) => $v !== null && $v !== '' && $v !== []);
        // جای‌گذاری مسیر
        $path = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use (&$args) {
            $v = $args[$m[1]] ?? '';
            unset($args[$m[1]]);
            return rawurlencode(is_array($v) ? implode(',', $v) : (string)$v);
        }, (string)$t['path']);

        $cacheKey = null;
        if (!empty($t['cache']) && class_exists('Cache')) {
            $cacheKey = 'apic_' . $c['id'] . '_' . md5($t['name'] . json_encode($args));
            $hit = Cache::get($cacheKey);
            if (is_array($hit)) return $hit + ['cached' => true];
        }

        $r = self::request($c, $t['method'], $path, $args);
        $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        if ($cacheKey && $r['ok']) Cache::set($cacheKey, $r, (int)$t['cache']);
        return $r;
    }

    /** درخواست خام به وب‌سرویس */
    public static function request(array $c, string $method, string $path, array $args = []): array
    {
        if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'cURL روی سرور فعال نیست'];
        $url = rtrim((string)$c['base_url'], '/') . '/' . ltrim($path, '/');
        $headers = ['Accept: application/json'];
        $token = (string)($c['token'] ?? '');
        $query = [];
        switch ($c['auth_type']) {
            case 'bearer': if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token; break;
            case 'header': if ($token !== '') $headers[] = ($c['auth_name'] ?: 'X-API-Key') . ': ' . $token; break;
            case 'query':  if ($token !== '') $query[$c['auth_name'] ?: 'api_key'] = $token; break;
        }
        foreach ((array)$c['headers'] as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z0-9\-]+$/', $k)) $headers[] = $k . ': ' . str_replace(["\r", "\n"], '', (string)$v);
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Elanban-Agent/1.0',
        ];
        if ($method === 'GET') {
            $q = http_build_query(array_merge($args, $query));
            if ($q !== '') $url .= (strpos($url, '?') === false ? '?' : '&') . $q;
        } else {
            if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($args ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        if (class_exists('NetProxy')) {
            try { $opts += NetProxy::curlOptionsFor('api_' . $c['slug']); } catch (Throwable $e) {}
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'اتصال برقرار نشد: ' . $err];
        $j = json_decode((string)$raw, true);
        $data = is_array($j) ? $j : ['raw' => mb_substr((string)$raw, 0, 1500)];
        $ok = $http >= 200 && $http < 300 && (!is_array($j) || !array_key_exists('ok', $j) || !empty($j['ok']));
        $error = null;
        if (!$ok) {
            $error = is_array($j) ? (string)(self::find($j, 'message') ?? self::find($j, 'error_code') ?? self::find($j, 'error') ?? ('HTTP ' . $http)) : ('HTTP ' . $http);
            if (is_array($j) && ($code = self::find($j, 'error_code'))) $error = $code . ': ' . $error;
        }
        return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $error];
    }

    /** آزمایش اتصال از پنل (test_path، وگرنه اولین ابزار GET بدون پارامتر اجباری) */
    public static function test(array $c): array
    {
        $path = (string)($c['test_path'] ?? '');
        if ($path === '') {
            foreach ($c['tools'] as $t) if ($t['method'] === 'GET' && empty($t['params']['required']) && strpos($t['path'], '{') === false) { $path = $t['path']; break; }
        }
        if ($path === '') return ['ok' => false, 'error' => 'مسیر آزمایش تعریف نشده'];
        $t0 = microtime(true);
        $r = self::request($c, 'GET', $path);
        $r['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $r['path'] = $path;
        return $r;
    }

    /* ======================= کمکی‌ها ======================= */

    /** پر کردن قالب‌های تزریقی */
    public static function fill(string $tpl, array $c, array $ctx): string
    {
        $ch = (string)($ctx['channel'] ?? '');
        $map = (array)($c['channel_map'] ?? []);
        $mapped = $map[$ch] ?? null;
        if ($mapped === null) foreach ($map as $k => $v) if ($k !== '*' && $k !== '' && strpos($ch, (string)$k) === 0) { $mapped = $v; break; }
        if ($mapped === null) $mapped = $map['*'] ?? $ch;
        return strtr($tpl, [
            '{chat_ref}' => !empty($ctx['identity_id']) ? 'eb-' . (int)$ctx['identity_id'] : '',
            '{person_ref}' => !empty($ctx['person_id']) ? 'eb-p' . (int)$ctx['person_id'] : '',
            '{channel}' => (string)$mapped,
            '{phone}' => (string)($ctx['phone'] ?? ''),
            '{name}' => (string)($ctx['name'] ?? ''),
        ]);
    }

    /** پیدا کردن اولین مقدار یک کلید در هر عمقی از آرایه */
    public static function find($data, string $key)
    {
        if (!is_array($data)) return null;
        if (array_key_exists($key, $data) && !is_array($data[$key])) return $data[$key];
        foreach ($data as $v) {
            if (is_array($v)) { $r = self::find($v, $key); if ($r !== null) return $r; }
        }
        return null;
    }

    public static function log(?int $personId, ?int $connectorId, string $kind, ?string $tool, $args, bool $ok, ?int $http, ?string $summary, $result, ?int $ms): void
    {
        try {
            self::ensure();
            Database::run(
                "INSERT INTO eb_agent_log (person_id, connector_id, kind, tool, args_json, ok, http, summary, result, ms) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$personId, $connectorId, $kind, $tool, $args === null ? null : json_encode($args, JSON_UNESCAPED_UNICODE),
                 $ok ? 1 : 0, $http, $summary !== null ? mb_substr($summary, 0, 500) : null,
                 $result === null ? null : mb_substr(is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE), 0, 60000), $ms]
            );
            // نگهداری ۶۰ روز
            if (mt_rand(1, 200) === 1) Database::run("DELETE FROM eb_agent_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
        } catch (Throwable $e) {}
    }

    /** ثبت/به‌روزرسانی سفارش از پاسخ ابزار یا وب‌هوک */
    public static function trackOrder(array $c, $data, ?int $personId, ?int $identityId, array $args = [], ?int $profileId = null): ?array
    {
        if (!is_array($data)) return null;
        $ci = self::find($args, 'check_in') ?? self::find($data, 'check_in'); $co = self::find($args, 'check_out') ?? self::find($data, 'check_out');
        $gu = self::find($args, 'guests') ?? self::find($data, 'guests');
        $jd = fn($v) => is_string($v) && preg_match('/^1[34]\d\d-\d\d-\d\d$/', $v) ? $v : null;   // فقط تاریخ شمسی
        $wh = (array)($c['webhook'] ?? []);
        $refKeys = $wh['ref_keys'] ?? ['booking_code', 'enquiry_code', 'order_id'];
        $ref = null; $ref2 = null;
        foreach ($refKeys as $k) {
            $v = self::find($data, (string)$k);
            if ($v !== null && $v !== '') { if ($ref === null) $ref = (string)$v; elseif ($ref2 === null) $ref2 = (string)$v; }
        }
        if ($ref === null) return null;
        $status = self::find($data, $wh['status_key'] ?? 'status');
        $amount = self::find($data, 'total_payable_price') ?? self::find($data, 'total_paid') ?? self::find($data, 'amount');
        $title = self::find($data, 'space_title') ?? self::find($data, 'title');
        $checkout = self::find($data, 'checkout_url');
        try {
            self::ensure();
            $ex = Database::one("SELECT * FROM eb_agent_orders WHERE connector_id = ? AND (ref = ? OR ref2 = ?" . ($ref2 ? " OR ref = ? OR ref2 = ?" : "") . ") LIMIT 1",
                $ref2 ? [$c['id'], $ref, $ref, $ref2, $ref2] : [$c['id'], $ref, $ref]);
            if ($ex) {
                Database::run("UPDATE eb_agent_orders SET status = COALESCE(?, status), amount = COALESCE(?, amount), title = COALESCE(?, title), checkout_url = COALESCE(?, checkout_url),
                               ref2 = COALESCE(ref2, ?), person_id = COALESCE(person_id, ?), identity_id = COALESCE(identity_id, ?), data_json = ? WHERE id = ?",
                    [$status !== null ? (string)$status : null, is_numeric($amount) ? (int)$amount : null, $title, $checkout, $ref2, $personId, $identityId,
                     json_encode($data, JSON_UNESCAPED_UNICODE), $ex['id']]);
                Database::run("UPDATE eb_agent_orders SET check_in = COALESCE(check_in, ?), check_out = COALESCE(check_out, ?), guests = COALESCE(guests, ?), profile_id = COALESCE(profile_id, ?) WHERE id = ?",
                    [$jd($ci), $jd($co), is_numeric($gu) ? (int)$gu : null, $profileId, $ex['id']]);
                return Database::one("SELECT * FROM eb_agent_orders WHERE id = ?", [$ex['id']]);
            }
            Database::run("INSERT INTO eb_agent_orders (connector_id, person_id, identity_id, ref, ref2, title, status, amount, checkout_url, data_json) VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$c['id'], $personId, $identityId, $ref, $ref2, $title, $status !== null ? (string)$status : null, is_numeric($amount) ? (int)$amount : null, $checkout,
                 json_encode($data, JSON_UNESCAPED_UNICODE)]);
            $nid = Database::lastId();
            Database::run("UPDATE eb_agent_orders SET check_in = ?, check_out = ?, guests = ?, profile_id = ? WHERE id = ?", [$jd($ci), $jd($co), is_numeric($gu) ? (int)$gu : null, $profileId, $nid]);
            return Database::one("SELECT * FROM eb_agent_orders WHERE id = ?", [$nid]);
        } catch (Throwable $e) { return null; }
    }

    /* ======================= الگوی آماده: جاکجاست ======================= */

    public static function presets(): array
    {
        return ['jakojast' => self::jakojastPreset()];
    }

    /** {param: {نام گروه: [مقدار، …]}} */
    public static function cleanGroups($g): array
    {
        if (is_string($g)) $g = json_decode($g, true);
        if (!is_array($g)) return [];
        $out = [];
        foreach ($g as $param => $groups) {
            $param = preg_replace('/[^a-z0-9_]/i', '', (string)$param);
            if ($param === '' || !is_array($groups)) continue;
            foreach ($groups as $name => $vals) {
                if (!is_array($vals)) continue;
                $vals = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 60), $vals), 'strlen'));
                if ($vals) $out[$param][mb_substr(trim((string)$name), 0, 60)] = array_slice($vals, 0, 20);
            }
        }
        return $out;
    }

    public static function jakojastPreset(): array
    {
        $dates = ['check_in' => ['type' => 'string', 'description' => 'تاریخ ورود شمسی YYYY-MM-DD، مثل 1405-08-07'],
                  'check_out' => ['type' => 'string', 'description' => 'تاریخ خروج شمسی YYYY-MM-DD']];
        return [
            'slug' => 'jakojast', 'name' => 'جاکجاست', 'base_url' => 'https://www.jakojast.com/api/ws/ai-assistant',
            'auth_type' => 'bearer', 'auth_name' => '', 'headers' => [], 'test_path' => '/ping', 'is_active' => 1,
            'channel_map' => ['instagram' => 'instagram', 'roubika' => 'rubika', 'rubika' => 'rubika', 'bale' => 'bale', 'whatsapp' => 'whatsapp', 'webchat' => 'site', '*' => 'site'],
            'webhook' => ['event_key' => 'event', 'chat_key' => 'external_chat_id', 'text_key' => 'social_message_text', 'ref_keys' => ['booking_code', 'enquiry_code', 'order_id'], 'status_key' => 'status',
                          'sig_header' => '', 'ts_header' => ''],
            'instructions' => implode("\n", [
                'تو اقامتگاه‌های {brand} را می‌فروشی (سوئیت، آپارتمان مبله، ویلا و کلبه). موجودی، قیمت و لینک‌ها از این وب‌سرویس می‌آید.',
                '- برای پیشنهاد اقامتگاه همیشه search را صدا بزن؛ هرگز اقامتگاه، قیمت یا لینک از خودت نساز.',
                '- اگر مقصد، تاریخ یا تعداد نفرات معلوم نیست، کوتاه بپرس؛ ولی اگر مشتری فقط منطقه گفت، بدون تاریخ هم جستجو کن تا گزینه ببیند.',
                '- «شمال»، «جنوب»، «اطراف تهران» و… (مناطق کلان کشور) را در region بفرست. اگر نام شهر/امکانات را مطمئن نیستی metadata را ببین.',
                '- مهم: «شمال/غرب/شرق/جنوب/مرکز تهران» یعنی محله‌های داخل شهر تهران (city «تهران»). «شمال» یا «جنوب» تنها، یعنی شمال/جنوب کشور (region). وقتی مشتری «تهران» را گفته، هرگز نپرس منظورش شمال کشور است یا نه.',
                '- q فقط با یک نام محله یا یک کلمه جواب می‌دهد (مثلاً «پونک» یا «صادقیه»)، نه با عبارت. نوع واحد را در space_type بفرست نه در q. محله‌های پرگزینه‌ی هر منطقه‌ی تهران: شمال = پاسداران، ونک، میرداماد، جردن؛ غرب = صادقیه، سعادت‌آباد، جنت‌آباد، ستارخان؛ شرق = نارمک، تهرانپارس؛ مرکز = انقلاب، ولیعصر، فاطمی؛ جنوب = یافت‌آباد، نازی‌آباد. برای یک منطقه، ۱ تا ۲ محله‌ی اصلی را جداگانه جستجو کن.',
                '- اگر محله‌ای خالی بود، سیستم خودش محله‌های دیگر همان منطقه را می‌گردد. محله‌ی واقعی هر گزینه را از نتیجه بگو؛ گزینه‌ی منطقه‌ی دیگر را «در شمال تهران» معرفی نکن.',
                '- هاستل و اقامتگاه اشتراکی (خوابگاهی، مخصوص آقایان یا خانم‌ها) فقط برای یک نفر یا گروهی که همه آقا یا همه خانم‌اند مناسب است. برای ۲ نفر یا بیشتر اگر نمی‌دانی هم‌جنس‌اند، پیشنهادش نکن (یا کوتاه بپرس). برای زوج و خانواده هرگز.',
                '- اگر نتیجه‌ها با منطقه‌ی خواسته‌شده نمی‌خواند (مثلاً شهر دیگری آمد)، آن‌ها را پیشنهاد نکن؛ با فیلتر درست دوباره جستجو کن.',
                '- وقتی جستجو نتیجه‌ی مناسب داد، همان موقع چند گزینه (حداکثر ۳) را نشان بده و بعد سؤال تکمیلی بپرس؛ مشتری را منتظر سؤال اضافه نگه ندار.',
                '- social_card_text فقط منبع اطلاعات است، کپی‌اش نکن: هر گزینه یک خط کوتاه (کد، اسم کوتاه، محله، قیمت کل). اگر لینک مجاز است، از لینک همان خروجی استفاده کن (booking_url یا referral_url).',
                '- برای سؤال جزئی درباره‌ی یک اقامتگاه (قوانین، امکانات، روزهای خالی) space_details را با کد اقامتگاه صدا بزن.',
                '- وقتی مشتری اقامتگاهی را انتخاب کرد، بدون اجازه گرفتن quote بگیر و در یک پیام کوتاه مبلغ کل و نوع رزرو (فوری = پرداخت آنلاین همان لحظه / استعلامی = اول تأیید میزبان) را بگو.',
                '- برای رزرو این‌ها لازم است: کد اقامتگاه، تاریخ ورود و خروج، تعداد نفرات، نام و نام خانوادگی، موبایل ۱۱ رقمی. اگر نام و شماره را داریم، نپرس؛ در یک پیام ۲-۳ خطی خلاصه کن و بپرس «با نام … و شماره‌ی … ثبت کنم؟». به محض تأیید مشتری booking_request را صدا بزن و خلاصه را دوباره نشان نده.',
                '- بعد از ثبت رزرو فوری: لینک پرداخت (checkout_url) را بفرست و بگو با کد پیامکی وارد می‌شود و پرداخت می‌کند.',
                '- بعد از ثبت استعلامی: کوتاه بگو درخواست برای میزبان رفت و جواب میزبان خودکار در همین گفتگو اعلام می‌شود (و پیامک هم می‌آید). تا میزبان جواب نداده، گزینه‌ی جایگزین پیشنهاد نکن مگر مشتری خودش بخواهد؛ اگر رد شد، خودت جایگزین می‌دهی.',
                '- شماره و آدرس میزبان فقط بعد از تأیید و پرداخت در رسید رزرو می‌آید. شماره‌ی مشتری را به میزبان «اعلام» نمی‌کنی (میزبان از طریق جاکجاست خبردار می‌شود). اگر مشتری عجله دارد، درخواست تماس با همکاران را پیشنهاد بده.',
                '- اگر مشتری خواست لینک‌ها پیامک شود، send_links را با شماره‌ی خودش صدا بزن.',
                '- برای پیگیری رزرو booking_status را با کد رزرو/استعلام صدا بزن. اطلاعات تماس و آدرس میزبان فقط بعد از پرداخت داده می‌شود.',
                '- اگر خطای DATES_UNAVAILABLE یا CAPACITY_EXCEEDED آمد، با جستجوی تازه گزینه‌ی جایگزین پیشنهاد بده.',
            ]),
            'tools' => [
                ['name' => 'metadata', 'title' => 'فهرست مناطق، شهرها و امکانات', 'method' => 'GET', 'path' => '/metadata', 'cache' => 3600,
                 'description' => 'فهرست مناطق کلان (شمال، جنوب، اطراف تهران…)، استان‌ها و شهرهای فعال، انواع اقامتگاه، امکانات و آمار قیمت. وقتی نام منطقه، شهر یا امکانات را مطمئن نیستی صدا بزن.',
                 'params' => ['type' => 'object', 'properties' => new stdClass()]],
                ['name' => 'search', 'title' => 'جستجوی اقامتگاه', 'method' => 'POST', 'path' => '/spaces/search', 'relax' => ['q', 'space_type'], 'relax_groups' => ['q' => self::TEHRAN_AREAS], 'group_force' => ['clear' => ['region', 'province'], 'set' => ['city' => 'تهران']],
                 'description' => 'جستجوی اقامتگاه‌ها (پیش‌فرض ۵ نتیجه در هر صفحه) با متن آماده‌ی کارت و لینک رزرو. با تاریخ، موجودی و قیمت کل هم برمی‌گردد.',
                 'params' => ['type' => 'object', 'properties' => [
                     'q' => ['type' => 'string', 'description' => 'عبارت آزاد یا کد اقامتگاه'],
                     'region' => ['type' => 'string', 'description' => 'منطقه‌ی کلان: shomal، jonoub، tehran_suburbs، markazi، gharb، shargh یا نام فارسی مثل «شمال»'],
                     'city' => ['type' => 'string', 'description' => 'یک یا چند شهر با کاما، مثل «رامسر,چالوس»'],
                     'province' => ['type' => 'string', 'description' => 'استان'],
                     'space_type' => ['type' => 'string', 'description' => 'نوع: ویلا، کلبه سوئیسی، آپارتمان، سوئیت…'],
                     'amenities' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'امکانات لازم، مثل ["استخر","بیلیارد"]'],
                     'guests' => ['type' => 'integer', 'description' => 'تعداد کل نفرات'],
                     'min_beds' => ['type' => 'integer', 'description' => 'حداقل اتاق خواب'],
                     'min_price' => ['type' => 'integer', 'description' => 'حداقل قیمت شبانه (تومان)'],
                     'max_price' => ['type' => 'integer', 'description' => 'حداکثر قیمت شبانه (تومان)'],
                     'instant' => ['type' => 'boolean', 'description' => 'فقط رزرو فوری'],
                     'page' => ['type' => 'integer', 'description' => 'شماره‌ی صفحه برای نتایج بیشتر'],
                 ] + $dates]],
                ['name' => 'space_details', 'title' => 'جزئیات اقامتگاه', 'method' => 'GET', 'path' => '/spaces/{id}',
                 'description' => 'جزئیات کامل یک اقامتگاه: امکانات، قوانین و کنسلی، نظرات و تقویم ۳۰ روز آینده. با تاریخ، موجودی و قیمت آن بازه را هم می‌دهد.',
                 'params' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'description' => 'کد اقامتگاه، مثل 1042'], 'guests' => ['type' => 'integer']] + $dates, 'required' => ['id']]],
                ['name' => 'quote', 'title' => 'استعلام قیمت دقیق', 'method' => 'POST', 'path' => '/bookings/quote',
                 'description' => 'مبلغ دقیق قابل پرداخت، ریز قیمت و نوع رزرو (فوری یا استعلامی) برای یک اقامتگاه در بازه‌ی مشخص. پیش از هر رزرو لازم است.',
                 'params' => ['type' => 'object', 'properties' => ['space_id' => ['type' => 'string', 'description' => 'کد اقامتگاه'], 'guests' => ['type' => 'integer']] + $dates, 'required' => ['space_id', 'check_in', 'check_out', 'guests']]],
                ['name' => 'booking_request', 'title' => 'ثبت رزرو', 'method' => 'POST', 'path' => '/bookings/request', 'confirm' => true, 'side_effect' => true, 'track' => true, 'phone_check' => 'phone',
                 'description' => 'ثبت نهایی رزرو برای مهمان. فوری: فاکتور و لینک پرداخت ساخته و پیامک می‌شود. استعلامی: برای تأیید میزبان پیامک می‌رود.',
                 'inject' => ['channel' => '{channel}', 'external_chat_id' => '{chat_ref}', 'external_reference' => '{person_ref}'],
                 'params' => ['type' => 'object', 'properties' => [
                     'space_id' => ['type' => 'string'], 'guests' => ['type' => 'integer'],
                     'phone' => ['type' => 'string', 'description' => 'موبایل ۱۱ رقمی مهمان، مثل 09123456789'],
                     'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string'],
                     'note' => ['type' => 'string', 'description' => 'توضیح اختیاری مهمان'],
                     'channel' => ['type' => 'string'], 'external_chat_id' => ['type' => 'string'], 'external_reference' => ['type' => 'string'],
                 ] + $dates, 'required' => ['space_id', 'check_in', 'check_out', 'guests', 'phone', 'first_name', 'last_name']]],
                ['name' => 'send_links', 'title' => 'پیامک لینک اقامتگاه‌ها', 'method' => 'POST', 'path' => '/spaces/send-links', 'side_effect' => true, 'phone_check' => 'phone', 'link_sms' => ['ids' => 'space_ids', 'phone' => 'phone'],
                 'description' => 'لینک ۱ تا ۱۰ اقامتگاه را برای موبایل مهمان پیامک می‌کند (فروش به نام دستیار ثبت می‌شود). فقط وقتی مشتری خودش پیامک خواست یا شماره داد.',
                 'params' => ['type' => 'object', 'properties' => [
                     'space_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'کدهای اقامتگاه'],
                     'phone' => ['type' => 'string'], 'guest_name' => ['type' => 'string'], 'guests' => ['type' => 'integer'],
                 ] + $dates, 'required' => ['space_ids', 'phone']]],
                ['name' => 'booking_status', 'title' => 'پیگیری رزرو', 'method' => 'GET', 'path' => '/bookings/status/{code}', 'track' => true, 'own_ref' => 'code',
                 'description' => 'آخرین وضعیت رزرو یا استعلام با کد رزرو یا کد استعلام. بعد از پرداخت، واچر و اطلاعات میزبان هم برمی‌گردد.',
                 'params' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'description' => 'کد رزرو یا کد استعلام']], 'required' => ['code']]],
            ],
        ];
    }
}
