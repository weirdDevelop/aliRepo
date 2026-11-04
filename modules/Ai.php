<?php
/**
 * modules/Ai.php
 * لایه‌ی هوش مصنوعی — کلاینت چندپروایدریِ سازگار با OpenAI.
 *
 * چرا چندپروایدری؟ چون هیچ سرویسی همیشه در دسترس نیست (قطعی، فیلترینگ، اتمام
 * اعتبار). پس چند سرویس هم‌زمان تعریف می‌شود، هرکدام با اولویت خودش؛ اگر
 * اولویت اول خطا داد (شبکه/۴۰۱/۴۲۹/۵xx)، خودکار سراغ بعدی می‌رود.
 * همه‌ی این پروایدرها قرارداد `POST {base_url}/chat/completions` سازگار با
 * OpenAI را می‌فهمند، فقط آدرس/کلید/مدل فرق دارد.
 *
 * پروایدرهای از پیش تعریف‌شده: GapGPT و آروان‌کلاود AI (ایرانی)، OpenRouter،
 * OpenAI، و یک اسلات «سفارشی» برای هر سرویس دیگر (Ollama محلی، واسط‌های دیگر).
 *
 * تنظیمات مشترک (پرامپت، دما و...) در `eb_settings` با پیشوند `ai.` ذخیره می‌شود.
 * تنظیمات هر پروایدر با پیشوند `ai.prov.<key>.` ذخیره می‌شود (همان جدول، بدون
 * نیاز به migration/جدول تازه).
 *
 * اصل محصولی (قفل‌شده): AI پیشنهاد می‌دهد، انسان دکمه‌ی نهایی را می‌زند.
 * تنها استثنا — که کارفرما صریح خواسته — بیرونِ ساعت کاری یا ارجاع دستی است،
 * تا مشتری بی‌جواب نماند. `mode()` همین تصمیم را متمرکز می‌کند.
 */

class Ai
{
    /** تنظیمات مشترک (مستقل از پروایدر) */
    const DEFAULTS = [
        'enabled'      => '0',
        'system'       => "تو دستیار پشتیبانی یک کسب‌وکار ایرانی هستی. کوتاه، مودب و روشن به فارسی جواب بده.\n"
                        . "فقط بر اساس «دانش سازمان» و «اطلاعات مشتری» که در پیام سیستم به تو داده می‌شود جواب بده؛ چیزی از خودت نساز.\n"
                        . "اگر جواب را در این منابع پیدا نکردی یا با قطعیت نمی‌دانی، حدس نزن؛ بگو که همکاران پشتیبانی پیگیری می‌کنند.\n"
                        . "هرگز قیمت، تخفیف، یا تعهد زمانی از خودت نساز.",
        'temperature'  => '0.3',
        'max_tokens'   => '400',
        'timeout'      => '20',
        'history'      => '8',       // چند پیام آخر گفتگو به‌عنوان زمینه فرستاده شود
        'auto_offline' => '1',       // بیرون ساعت کاری خودش جواب بدهد
        'rate_hour'    => '10',      // سقف پاسخ خودکار به هر مشتری در هر ساعت
        'insecure'     => '0',       // نادیده‌گرفتن خطای گواهی SSL (فقط اگر CA هاست خراب است)
        'signature'    => '',        // متن کوتاهی که به انتهای پاسخ خودکار اضافه شود
        // --- فیلدهای قدیمی (نسخه‌ی تک‌پروایدری) — فقط برای مهاجرت خودکار به «سفارشی» نگه داشته شده ---
        'base_url'     => '',
        'api_key'      => '',
        'model'        => '',
    ];

    /** پروایدرهای از پیش تعریف‌شده. base_url خالی یعنی خودِ ادمین باید آدرس را بدهد. */
    const PROVIDERS = [
        // ۱.۰.۱۴: AvalAI — واسط ایرانی سازگار با OpenAI با مدل‌های متعدد (GPT، Claude، Gemini، DeepSeek…)
        'avalai' => [
            'label'         => 'AvalAI (ایرانی)',
            'base_url'      => 'https://api.avalai.ir/v1',
            'editable_url'  => true,
            'default_model' => 'gpt-4o-mini',
            'hint'          => 'avalai.ir — کلید را از پنل AvalAI بسازید. آدرس پیش‌فرض https://api.avalai.ir/v1 است و در صورت نیاز قابل تغییر است. با «دریافت مدل‌ها» فهرست مدل‌های قابل‌استفاده‌ی حسابتان را ببینید.',
        ],
        'gapgpt' => [
            'label'         => 'GapGPT (ایرانی)',
            'base_url'      => 'https://api.gapgpt.app/v1',
            'editable_url'  => false,
            'default_model' => 'gpt-4o-mini',
            'hint'          => 'واسط ایرانیِ سازگار با OpenAI؛ نیاز به فیلترشکن ندارد.',
        ],
        'arvancloud' => [
            'label'         => 'ابرآروان AI (ایرانی)',
            'base_url'      => '',
            'editable_url'  => true,
            'default_model' => 'gpt-4o-mini',
            'hint'          => 'آدرس endpoint را از پنل ابرآروان کپی کن (مثلاً .../v1).',
        ],
        'openrouter' => [
            'label'         => 'OpenRouter',
            'base_url'      => 'https://openrouter.ai/api/v1',
            'editable_url'  => false,
            'default_model' => 'openai/gpt-4o-mini',
            'hint'          => 'دسترسی به ۲۰۰+ مدل؛ گزینه‌ی خوب برای fallback نهایی. معمولاً فیلتر است.',
        ],
        'openai' => [
            'label'         => 'OpenAI',
            'base_url'      => 'https://api.openai.com/v1',
            'editable_url'  => false,
            'default_model' => 'gpt-4o-mini',
            'hint'          => 'سرویس رسمی OpenAI؛ در ایران معمولاً به فیلترشکن/پروکسی نیاز دارد.',
        ],
        'custom' => [
            'label'         => 'سفارشی (هر سرویس دیگر / Ollama محلی)',
            'base_url'      => '',
            'editable_url'  => true,
            'default_model' => '',
            'hint'          => 'هر سرویسی با قرارداد chat/completions سازگار با OpenAI.',
        ],
    ];

    /** کلیدهایی که در UI هرگز بازگردانده نمی‌شوند (فقط «تنظیم‌شده/نشده») */
    const SECRET_KEYS = ['api_key'];

    /** @var array|null کش در همان درخواست */
    private static $cache = null;
    /** @var array|null کش پروایدرها در همان درخواست */
    private static $provCache = null;

    // ==================== تنظیمات مشترک ====================

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $out = self::DEFAULTS;
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'ai.%' AND k NOT LIKE 'ai.prov.%'");
            foreach ($rows as $r) {
                $key = substr($r['k'], 3);   // حذف 'ai.'
                if (array_key_exists($key, $out) && $r['v'] !== null && $r['v'] !== '') {
                    $out[$key] = $r['v'];
                }
            }
        } catch (Throwable $e) { /* جدول نبود → پیش‌فرض‌ها */ }
        self::$cache = $out;
        return $out;
    }

    public static function get(string $key): string
    {
        $all = self::all();
        return (string)($all[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    /**
     * ذخیره‌ی تنظیمات مشترک (غیر از پروایدرها). فقط کلیدهای مجاز.
     * کلید خالی یا نامعتبر تغییری نمی‌دهد.
     */
    public static function save(array $values): void
    {
        $allowed = array_keys(self::DEFAULTS);
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            if (in_array($k, ['base_url', 'api_key', 'model'], true)) continue; // فیلدهای قدیمی از این مسیر ذخیره نمی‌شوند
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ['ai.' . $k, (string)$v]
            );
        }
        self::$cache = null;
    }

    // ==================== پروایدرها ====================

    /**
     * فهرست کامل پروایدرها با تنظیمات فعلی (کلید API خام — فقط برای مصرف داخلی/چت).
     * یک‌بار در هر درخواست، فیلدهای قدیمیِ تک‌پروایدری را (اگر بودند و «سفارشی»
     * هنوز پیکربندی نشده) به‌عنوان مهاجرتِ خودکار داخل 'custom' می‌ریزد.
     */
    public static function providers(): array
    {
        if (self::$provCache !== null) return self::$provCache;

        $rows = [];
        try {
            $rows = Database::all("SELECT k, v FROM eb_settings WHERE k LIKE 'ai.prov.%'");
        } catch (Throwable $e) { /* جدول نبود */ }

        $byProv = [];
        foreach ($rows as $r) {
            // ai.prov.<key>.<field>
            $rest = substr($r['k'], strlen('ai.prov.'));
            $pos = strpos($rest, '.');
            if ($pos === false) continue;
            $pkey = substr($rest, 0, $pos);
            $field = substr($rest, $pos + 1);
            $byProv[$pkey][$field] = $r['v'];
        }

        $out = [];
        foreach (self::PROVIDERS as $key => $meta) {
            $stored = $byProv[$key] ?? [];
            // ۱.۰.۱۴: کلید API رمزنگاری‌شده ذخیره می‌شود؛ کلیدهای قدیمیِ بی‌رمز همین‌جا یک بار رمز می‌شوند
            $rawKey = (string)($stored['api_key'] ?? '');
            if ($rawKey !== '' && strpos($rawKey, 'enc1:') !== 0 && ($encK = self::vaultEnc($rawKey)) !== $rawKey) {
                try { Database::run("UPDATE eb_settings SET v = ? WHERE k = ?", [$encK, "ai.prov.{$key}.api_key"]); } catch (Throwable $e) {}
            }
            $stored['api_key'] = self::vaultDec($rawKey);
            if ($meta['editable_url'] && trim((string)($stored['base_url'] ?? '')) === '' && $meta['base_url'] !== '') $stored['base_url'] = $meta['base_url'];
            $out[$key] = [
                'key'          => $key,
                'label'        => $meta['label'],
                'hint'         => $meta['hint'],
                'editable_url' => $meta['editable_url'],
                'enabled'      => ($stored['enabled'] ?? '0') === '1',
                'api_key'      => (string)($stored['api_key'] ?? ''),
                'model'        => trim((string)($stored['model'] ?? '')) !== ''
                                    ? trim((string)$stored['model']) : $meta['default_model'],
                'base_url'     => $meta['editable_url']
                                    ? trim((string)($stored['base_url'] ?? ''))
                                    : $meta['base_url'],
                'priority'     => isset($stored['priority']) ? (int)$stored['priority'] : self::defaultPriority($key),
            ];
        }

        // مهاجرت یک‌باره‌ی نسخه‌ی قدیمی: اگر ai.base_url/ai.api_key قدیمی ست شده
        // ولی هیچ پروایدری هنوز فعال نیست، آن را به‌عنوان 'custom' اعمال کن (فقط
        // در حافظه، بدون نوشتن در دیتابیس — تا خود ادمین یک‌بار ذخیره کند).
        $anyEnabled = false;
        foreach ($out as $p) { if ($p['enabled']) { $anyEnabled = true; break; } }
        if (!$anyEnabled) {
            $legacy = self::all();
            if (trim($legacy['api_key']) !== '' && trim($legacy['base_url']) !== '') {
                $out['custom']['enabled']  = ($legacy['enabled'] === '1');
                $out['custom']['api_key']  = $legacy['api_key'];
                $out['custom']['model']    = $legacy['model'] !== '' ? $legacy['model'] : $out['custom']['model'];
                $out['custom']['base_url'] = $legacy['base_url'];
            }
        }

        self::$provCache = $out;
        return $out;
    }

    private static function defaultPriority(string $key): int
    {
        $order = ['avalai' => 1, 'gapgpt' => 2, 'arvancloud' => 3, 'openrouter' => 4, 'openai' => 5, 'custom' => 6];
        return $order[$key] ?? 9;
    }

    /** فهرست پروایدرهای واقعاً «قابل‌استفاده» (فعال + کلید + مدل + آدرس)، به ترتیب اولویت. */
    public static function activeProviders(): array
    {
        $out = [];
        foreach (self::providers() as $p) {
            if (!$p['enabled']) continue;
            if (trim($p['api_key']) === '') continue;
            if (trim($p['model']) === '') continue;
            if (trim($p['base_url']) === '') continue;
            $out[] = $p;
        }
        usort($out, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $out;
    }

    /** نسخه‌ی امن پروایدرها برای UI (کلید API افشا نمی‌شود). */
    public static function publicProviders(): array
    {
        $out = [];
        foreach (self::providers() as $key => $p) {
            $out[$key] = [
                'key'          => $p['key'],
                'label'        => $p['label'],
                'hint'         => $p['hint'],
                'editable_url' => $p['editable_url'],
                'enabled'      => $p['enabled'] ? 1 : 0,
                'api_key_set'  => trim($p['api_key']) !== '' ? 1 : 0,
                'model'        => $p['model'],
                'base_url'     => $p['base_url'],
                'priority'     => $p['priority'],
                'ready'        => (trim($p['api_key']) !== '' && trim($p['model']) !== '' && trim($p['base_url']) !== ''),
                'default_url'  => self::PROVIDERS[$key]['base_url'] ?? '',
                'key_hint'     => trim($p['api_key']) !== '' ? '••••' . substr(trim($p['api_key']), -4) : '',
                'encrypted'    => self::vaultKey() !== '',
                'status'       => self::providerStatus($key),
            ];
        }
        return $out;
    }

    /**
     * ذخیره‌ی تنظیمات پروایدرها.
     * @param array $list  ['gapgpt' => ['enabled'=>1,'api_key'=>'...','model'=>'...','priority'=>1], ...]
     */
    public static function saveProviders(array $list): void
    {
        foreach ($list as $key => $vals) {
            if (!array_key_exists($key, self::PROVIDERS)) continue;
            $meta = self::PROVIDERS[$key];

            $enabled = !empty($vals['enabled']) ? '1' : '0';
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["ai.prov.{$key}.enabled", $enabled]
            );

            $model = trim((string)($vals['model'] ?? ''));
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["ai.prov.{$key}.model", $model]
            );

            $priority = (int)($vals['priority'] ?? self::defaultPriority($key));
            Database::run(
                "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                ["ai.prov.{$key}.priority", (string)$priority]
            );

            if ($meta['editable_url']) {
                $url = trim((string)($vals['base_url'] ?? ''));
                Database::run(
                    "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                    ["ai.prov.{$key}.base_url", $url]
                );
            }

            // کلید API: خالی = دست‌نزن (مثل نسخه‌ی قبل)
            $apiKey = (string)($vals['api_key'] ?? '');
            if (trim($apiKey) !== '') {
                Database::run(
                    "INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v=VALUES(v)",
                    ["ai.prov.{$key}.api_key", self::vaultEnc(trim($apiKey))]   // ۱.۰.۱۴: رمزنگاری با app_key
                );
            }
        }
        self::$provCache = null;
    }

    /** پاک‌کردن صریح کلید API یک پروایدر (یا همه اگر خالی) */
    public static function clearKey(?string $providerKey = null): void
    {
        if ($providerKey && array_key_exists($providerKey, self::PROVIDERS)) {
            Database::run("DELETE FROM eb_settings WHERE k = ?", ["ai.prov.{$providerKey}.api_key"]);
        } else {
            Database::run("DELETE FROM eb_settings WHERE k LIKE 'ai.prov.%.api_key'");
            Database::run("DELETE FROM eb_settings WHERE k = 'ai.api_key'"); // کلید قدیمی هم پاک شود
        }
        self::$provCache = null;
        self::$cache = null;
    }

    /** تنظیمات امن برای UI (بدون افشای کلید) */
    public static function publicSettings(): array
    {
        $a = self::all();
        foreach (self::SECRET_KEYS as $k) { unset($a[$k]); }
        unset($a['base_url'], $a['model']); // فیلدهای قدیمی دیگر در UI نشان داده نمی‌شوند
        $a['ready']     = self::isReady() ? 1 : 0;
        $a['providers'] = self::publicProviders();
        return $a;
    }

    /** آیا حداقل یک پروایدر آماده‌ی استفاده است؟ */
    public static function isReady(): bool
    {
        if (self::get('enabled') !== '1') return false;
        return count(self::activeProviders()) > 0;
    }

    // ==================== تصمیم: پیشنهاد یا پاسخ خودکار ====================

    /**
     * در وضعیت فعلی، AI چه اجازه‌ای دارد؟
     *   'off'     — خاموش یا تنظیم‌نشده.
     *   'suggest' — فقط پیش‌نویس برای اپراتور (ساعت کاری). هیچ چیز خودکار فرستاده نمی‌شود.
     *   'auto'    — اجازه‌ی پاسخ خودکار دارد (تیم آفلاین است).
     */
    public static function mode(): string
    {
        if (!self::isReady()) return 'off';
        if (self::get('auto_offline') !== '1') return 'suggest';
        if (!class_exists('Support')) return 'suggest';
        return Support::isOnline() ? 'suggest' : 'auto';
    }

    /**
     * همان `mode()` ولی برای یک گفتگوی مشخص.
     * ارجاع دستی بر ساعت کاری **مقدم** است: اگر اپراتور گفتگو را به AI سپرده،
     * حتی در ساعت کاری هم AI جواب می‌دهد (این همان «ارجاع دستی» درخواستی است).
     */
    public static function modeFor(?int $personId): string
    {
        if (!self::isReady()) return 'off';
        if ($personId && class_exists('Support') && Support::isAiAssigned($personId)) return 'auto';
        return self::mode();
    }

    // ==================== تولید پاسخ ====================

    /**
     * پیش‌نویس پاسخ برای گفتگوی یک مشتری.
     * @return array{ok:bool, text?:string, error?:string, provider?:string}
     */
    public static function draftFor(int $personId, ?string $extra = null): array
    {
        $messages = [['role' => 'system', 'content' => self::systemWithContext($personId, $extra)]];

        foreach (self::contextFor($personId) as $m) $messages[] = $m;

        if ($extra !== null && trim($extra) !== '') {
            $messages[] = ['role' => 'user', 'content' => trim($extra)];
        }
        if (count($messages) === 1) {
            return ['ok' => false, 'error' => 'گفتگویی برای پاسخ‌دادن پیدا نشد'];
        }
        self::$usageCtx = ['source' => 'draft', 'person_id' => $personId];
        return self::chat($messages);
    }

    /**
     * چند پیام آخر گفتگو را به فرمت نقش‌دار تبدیل می‌کند.
     * پیام دریافتی = user، پیام ارسالی ما = assistant.
     */
    public static function contextFor(int $personId): array
    {
        $limit = max(2, min(40, (int)self::get('history')));
        try {
            $rows = Database::all(
                "SELECT m.direction, m.body FROM eb_messages m
                 JOIN eb_identities i ON i.id = m.identity_id
                 WHERE i.person_id = ? AND m.body IS NOT NULL AND m.body <> ''
                 ORDER BY m.id DESC LIMIT {$limit}",
                [$personId]
            );
        } catch (Throwable $e) { return []; }

        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = [
                'role'    => ($r['direction'] === 'in') ? 'user' : 'assistant',
                'content' => mb_substr((string)$r['body'], 0, 1500),
            ];
        }
        return $out;
    }

    /**
     * دستور سیستمیِ غنی‌شده: دستور پایه + مشخصات این مشتری + دانش مرتبط سازمان
     * (پایگاه دانش دستی/RAG سبک تیم پشتیبانی). این همان نقطه‌ای است که «همه‌ی
     * منابع» به AI تزریق می‌شود. هر بخش مستقل try/catch دارد تا نبودِ یکی، پاسخ
     * را نشکند.
     */
    public static function systemWithContext(?int $personId, ?string $query = null): string
    {
        $system = self::get('system');
        $blocks = [];

        if ($personId) {
            $facts = self::customerFacts((int)$personId);
            if ($facts !== '') $blocks[] = $facts;
        }

        // پرس‌وجوی بازیابی دانش: متن داده‌شده، وگرنه آخرین پیام مشتری
        $q = trim((string)$query);
        if ($q === '' && $personId) $q = self::lastInboundText((int)$personId);

        if (class_exists('AiKb')) {
            try {
                $kn = AiKb::contextBlock($q);
                if ($kn !== '') $blocks[] = $kn;
            } catch (Throwable $e) { /* دانش نباید پاسخ را بشکند */ }
        }

        if (!$blocks) return $system;
        return $system . "\n\n" . implode("\n\n", $blocks)
            . "\n\nمرز مهم: فقط از بخش‌های بالا («اطلاعات این مشتری» و «دانش سازمان») و گفتگوی جاری استفاده کن. "
            . "چیزی که در این منابع نیست را حدس نزن یا نساز.";
    }

    /**
     * حقایق این مشتری برای زمینه: نام، وضعیت، شماره، گروه/برچسب، یادداشت، و پاسخ فرم‌ها.
     * خالی اگر چیزی نبود. هر کوئری جداگانه محافظت‌شده است.
     */
    public static function customerFacts(int $personId): string
    {
        if ($personId <= 0) return '';
        try {
            $p = Database::one("SELECT full_name, phone, status, notes FROM eb_persons WHERE id = ?", [$personId]);
        } catch (Throwable $e) { return ''; }
        if (!$p) return '';

        $lines = [];
        if (!empty($p['full_name'])) $lines[] = 'نام: ' . $p['full_name'];
        $lines[] = 'وضعیت: ' . ((($p['status'] ?? '') === 'member') ? 'مشتری عضو' : 'ناشناس');
        if (!empty($p['phone'])) $lines[] = 'شماره: ' . $p['phone'];

        try {
            $g = array_column(Database::all(
                "SELECT g.name FROM eb_groups g JOIN eb_person_groups pg ON pg.group_id = g.id WHERE pg.person_id = ?",
                [$personId]
            ), 'name');
            if ($g) $lines[] = 'گروه‌ها: ' . implode('، ', $g);
        } catch (Throwable $e) { /* بی‌اثر */ }

        try {
            $t = array_column(Database::all(
                "SELECT t.name FROM eb_tags t JOIN eb_person_tags pt ON pt.tag_id = t.id WHERE pt.person_id = ?",
                [$personId]
            ), 'name');
            if ($t) $lines[] = 'برچسب‌ها: ' . implode('، ', $t);
        } catch (Throwable $e) { /* بی‌اثر */ }

        if (!empty($p['notes'])) $lines[] = 'یادداشت داخلی: ' . mb_substr((string)$p['notes'], 0, 300);

        // پاسخ فرم‌ها (چیزهایی که خود مشتری گفته) — منبع دانشِ شخصی
        try {
            $forms = Database::all(
                "SELECT answers_json FROM eb_form_responses WHERE person_id = ? ORDER BY created_at DESC LIMIT 3",
                [$personId]
            );
            $fLines = [];
            foreach ($forms as $fr) {
                $ans = json_decode($fr['answers_json'] ?? '[]', true);
                if (!is_array($ans)) continue;
                foreach ($ans as $qa) {
                    $qq = trim((string)($qa['q'] ?? ''));
                    $aa = trim((string)($qa['a'] ?? ''));
                    if ($aa === '') continue;
                    $fLines[] = '- ' . ($qq !== '' ? $qq . ' ' : '') . '→ ' . mb_substr($aa, 0, 200);
                    if (count($fLines) >= 12) break 2;
                }
            }
            if ($fLines) {
                $lines[] = 'پاسخ‌های فرم‌های این مشتری:';
                foreach ($fLines as $fl) $lines[] = $fl;
            }
        } catch (Throwable $e) { /* بی‌اثر */ }

        if (!$lines) return '';
        return "اطلاعات این مشتری (فقط اگر مرتبط بود استفاده کن، عیناً تکرار نکن):\n" . implode("\n", $lines);
    }

    /** آخرین پیام دریافتیِ مشتری (برای بازیابی دانش). خالی اگر نبود. */
    public static function lastInboundText(int $personId): string
    {
        try {
            $r = Database::one(
                "SELECT m.body FROM eb_messages m
                 JOIN eb_identities i ON i.id = m.identity_id
                 WHERE i.person_id = ? AND m.direction = 'in' AND m.body IS NOT NULL AND m.body <> ''
                 ORDER BY m.id DESC LIMIT 1",
                [$personId]
            );
        } catch (Throwable $e) { return ''; }
        return trim((string)($r['body'] ?? ''));
    }

    /**
     * پاسخ خودکار به یک پیام تازه (مسیر webhook). فقط وقتی `mode() === 'auto'`.
     * @return bool آیا پاسخی فرستاده شد
     */
    public static function autoReply(string $channel, string $channelUid, ?int $personId, string $text): bool
    {
        // دستیار فروش (اگر روشن است) همه‌ی گفتگوها را با ابزارهای اتصال‌ها جواب می‌دهد
        if (class_exists('SalesAgent') && SalesAgent::enabled()) {
            try { return SalesAgent::handle($channel, $channelUid, $personId, $text); }
            catch (Throwable $e) { error_log('[SalesAgent] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); return false; }
        }
        // تنظیم مخصوص پیج اینستاگرام جاری: خاموش / پاسخ خودکار روشن / طبق تنظیم کلی
        $pageMode = class_exists('IgPageSettings') ? IgPageSettings::cur('ai_mode') : null;
        if ($pageMode === 'off') return false;
        if ($pageMode === 'auto') { if (!self::isReady()) return false; }
        elseif (self::modeFor($personId) !== 'auto') return false;
        if (trim($text) === '') return false;
        if ($personId && self::rateExceeded($personId)) return false;

        $system = self::systemWithContext($personId, $text);
        $pagePrompt = class_exists('IgPageSettings') ? trim((string)IgPageSettings::cur('ai_prompt')) : '';
        if ($pagePrompt !== '') $system .= "\n\n[دستورالعمل مخصوص این پیج]\n" . $pagePrompt;
        $messages = [['role' => 'system', 'content' => $system]];
        if ($personId) {
            foreach (self::contextFor($personId) as $m) $messages[] = $m;
        }
        // اگر آخرین پیام زمینه خودش همین متن بود، دوباره اضافه نکن
        $last = end($messages);
        if (!is_array($last) || ($last['role'] ?? '') !== 'user' || trim((string)$last['content']) !== trim($text)) {
            $messages[] = ['role' => 'user', 'content' => trim($text)];
        }

        self::$usageCtx = ['source' => 'autoreply', 'person_id' => $personId];
        $r = self::chat($messages);
        if (!$r['ok'] || trim((string)($r['text'] ?? '')) === '') return false;

        $body = trim($r['text']);
        $pageSig = class_exists('IgPageSettings') ? trim((string)IgPageSettings::cur('ai_signature')) : '';
        $sig  = $pageSig !== '' ? $pageSig : trim(self::get('signature'));
        if ($sig !== '') $body .= "\n\n" . $sig;

        return BotReply::send($channel, $channelUid, ['text' => $body], BotReply::SRC_AI);
    }

    /**
     * سقف نرخ: اگر در یک ساعت گذشته بیش از حد مجاز پاسخ AI برای این مشتری رفته،
     * دیگر نمی‌فرستیم. جلوی خرج بی‌حساب و حلقه‌ی پاسخ در برابر پیام‌های پشت‌سرهم را می‌گیرد.
     * صفر یا منفی = بی‌حد.
     */
    public static function rateExceeded(int $personId): bool
    {
        $limit = (int)self::get('rate_hour');
        if ($limit <= 0) return false;
        try {
            // به ستون `source` نیاز دارد؛ اگر migration نخورده باشد سقف اعمال نمی‌شود
            if (!Database::hasColumn('eb_messages', 'source')) return false;
            $r = Database::one(
                "SELECT COUNT(*) c FROM eb_messages m
                 JOIN eb_identities i ON i.id = m.identity_id
                 WHERE i.person_id = ? AND m.direction = 'out' AND m.source = 'ai'
                   AND m.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                [$personId]
            );
            return ((int)($r['c'] ?? 0)) >= $limit;
        } catch (Throwable $e) {
            return false;   // شک داشتیم، جلوی پاسخ را نگیریم
        }
    }

    /**
     * فراخوانی سرویس با fallback خودکار بین پروایدرهای فعال (به ترتیب اولویت).
     * اگر پروایدری خطای شبکه/۴۰۱/۴۰۳/۴۲۹/۵xx بدهد، سراغ بعدی می‌رود.
     * @return array{ok:bool, text?:string, error?:string, code?:int, provider?:string}
     */
    public static function chat(array $messages): array
    {
        $providers = self::activeProviders();
        if (!$providers) {
            return ['ok' => false, 'error' => 'هیچ پروایدر هوش مصنوعی فعال و کامل تنظیم‌نشده است'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'افزونه‌ی cURL روی این هاست فعال نیست'];
        }

        $common = self::all();
        $lastError = null;
        $lastCode  = null;

        foreach ($providers as $p) {
            $r = self::callOne($p, $messages, $common);
            if ($r['ok']) {
                $r['provider'] = $p['key'];
                return $r;
            }
            $lastError = $r['error'] ?? 'خطای نامشخص';
            $lastCode  = $r['code'] ?? null;
        }

        return [
            'ok'    => false,
            'error' => count($providers) > 1
                ? "همه‌ی پروایدرها ناموفق بودند. آخرین خطا: {$lastError}"
                : $lastError,
            'code'  => $lastCode,
        ];
    }

    /** یک تلاش خام روی یک پروایدر مشخص. */
    private static function callOne(array $p, array $messages, array $common): array
    {
        $url = rtrim($p['base_url'], '/') . '/chat/completions';
        $payload = [
            'model'       => $p['model'],
            'messages'    => $messages,
            'temperature' => (float)$common['temperature'],
            'max_tokens'  => (int)$common['max_tokens'],
        ];

        // پروکسی ضدفیلترینگ: فقط اگر ادمین در تنظیمات کانال‌ها این پروایدر را
        // به‌عنوان هدف پروکسی انتخاب کرده باشد (معمولاً OpenRouter/OpenAI/سفارشی؛
        // GapGPT و ابرآروان ایرانی‌اند و معمولاً لازم ندارند).
        $proxyOpts = [];
        if (class_exists('NetProxy')) {
            try { $proxyOpts = NetProxy::curlOptionsFor('ai_' . $p['key']); }
            catch (Throwable $e) { $proxyOpts = []; }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => max(5, min(120, (int)$common['timeout'])),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $p['api_key'],
            ],
            // برخلاف درایورهای کانال، اینجا پیش‌فرض **بررسی گواهی روشن** است:
            // این درخواست کلید API را حمل می‌کند و خاموش‌کردن بررسی، آن را در معرض
            // شنود قرار می‌دهد. اگر CA هاست خراب بود، کاربر آگاهانه insecure را روشن کند.
            CURLOPT_SSL_VERIFYPEER => ($common['insecure'] === '1' ? false : true),
            CURLOPT_SSL_VERIFYHOST => ($common['insecure'] === '1' ? 0 : 2),
        ] + $proxyOpts);
        $t0   = microtime(true);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cErr = curl_error($ch);
        curl_close($ch);
        $jU = is_string($raw) ? json_decode($raw, true) : null;
        $okU = is_array($jU) && !isset($jU['error']) && $code >= 200 && $code < 300 && isset($jU['choices'][0]);
        $errU = $okU ? null : (is_array($jU) && isset($jU['error']) ? (is_array($jU['error']) ? (string)($jU['error']['message'] ?? 'خطا') : (string)$jU['error']) : ($cErr !== '' ? $cErr : 'HTTP ' . $code));
        self::recordUsage($p['key'], (string)$p['model'], is_array($jU) ? ($jU['usage'] ?? null) : null, $okU, (int)round((microtime(true) - $t0) * 1000), $code, $errU);

        // لاگ بدون کلید (پی‌لود پیام‌ها ممکن است طولانی باشد → فقط خلاصه)
        self::log([
            'provider' => $p['key'],
            'url'      => $url,
            'model'    => $p['model'],
            'msgs'     => count($messages),
        ], $raw, $code, $cErr);

        if ($raw === false || $raw === null) {
            return ['ok' => false, 'code' => $code,
                    'error' => "خطای اتصال در {$p['label']}" . ($cErr !== '' ? ": {$cErr}" : '')];
        }

        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            return ['ok' => false, 'code' => $code, 'error' => "پاسخ {$p['label']} قابل خواندن نبود (کد {$code})"];
        }
        if (isset($j['error'])) {
            $msg = is_array($j['error']) ? ($j['error']['message'] ?? 'خطای سرویس') : (string)$j['error'];
            return ['ok' => false, 'code' => $code, 'error' => "{$p['label']}: " . mb_substr((string)$msg, 0, 300)];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'code' => $code, 'error' => "{$p['label']}: خطای HTTP {$code}"];
        }
        $text = $j['choices'][0]['message']['content'] ?? null;
        if ($text === null || trim((string)$text) === '') {
            return ['ok' => false, 'code' => $code, 'error' => "{$p['label']}: سرویس پاسخ خالی برگرداند (کد {$code})"];
        }
        return ['ok' => true, 'text' => trim((string)$text), 'code' => $code];
    }

    /** تست اتصال از صفحه‌ی تنظیمات (پروایدرهای فعال به ترتیب اولویت امتحان می‌شوند) */
    public static function test(): array
    {
        self::$usageCtx = ['source' => 'test', 'person_id' => null];
        return self::chat([
            ['role' => 'system', 'content' => 'به فارسی و کوتاه جواب بده.'],
            ['role' => 'user',   'content' => 'سلام. فقط بنویس: اتصال برقرار است.'],
        ]);
    }

    /** تست اتصال یک پروایدر مشخص (بدون درگیرکردن بقیه‌ی fallback) */
    public static function testProvider(string $key): array
    {
        $all = self::providers();
        if (!isset($all[$key])) return ['ok' => false, 'error' => 'پروایدر ناشناخته'];
        $p = $all[$key];
        if (trim($p['api_key']) === '' || trim($p['model']) === '' || trim($p['base_url']) === '') {
            return ['ok' => false, 'error' => 'این پروایدر کامل تنظیم نشده (کلید/مدل/آدرس)'];
        }
        self::$usageCtx = ['source' => 'test', 'person_id' => null];
        $r = self::callOne($p, [
            ['role' => 'system', 'content' => 'به فارسی و کوتاه جواب بده.'],
            ['role' => 'user',   'content' => 'سلام. فقط بنویس: اتصال برقرار است.'],
        ], self::all());
        $r['provider'] = $key;
        return $r;
    }

    // ==================== تحلیل رسانه (مورد ۴ نقشه‌راه: عکس/ویس ورودی) ====================

    /**
     * تحلیل یک تصویر با AI (فرمت پیام‌های چندرسانه‌ای سازگار با OpenAI: content آرایه‌ای
     * از {type:text} و {type:image_url}). چون Ai::chat() ژنریک است (فقط messages می‌گیرد)،
     * نیازی به کد جدا برای هر پروایدر نیست — فقط اگر مدل فعال «ویژن» نداشته باشد، آن
     * پروایدر خطا می‌دهد و fallback به پروایدر بعدی می‌رود (دقیقاً مثل چت معمولی).
     * ⚠️ هنوز به‌صورت خودکار از وب‌هوک دایرکت‌ساز صدا زده نمی‌شود، چون فرمت پیوست عکس در
     * dm ورودی هنوز دیده/مستند نشده — فعلاً فقط از ابزار دستی «تحلیل رسانه» در پنل استفاده کن.
     */
    public static function analyzeImage(string $imageUrl, string $prompt = ''): array
    {
        $prompt = trim($prompt) !== '' ? $prompt : 'این تصویر را به فارسی و خلاصه توصیف کن. اگر متنی داخلش هست، بخوانش.';
        return self::chat([
            ['role' => 'system', 'content' => 'به فارسی و مختصر جواب بده.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
            ]],
        ]);
    }

    /**
     * رونویسی صدا با Whisper (فقط پروایدرهای سازگار با endpoint استاندارد OpenAI
     * `/audio/transcriptions` کار می‌کنند — یعنی عملاً OpenAI و احتمالاً GapGPT/ابرآروان
     * اگر همین endpoint را proxy کرده باشند؛ OpenRouter این endpoint را ندارد).
     * فایل باید دانلود و multipart آپلود شود (بر خلاف چت متنی، اینجا URL مستقیم کافی نیست).
     */
    public static function transcribeAudio(string $audioUrl): array
    {
        $providers = self::activeProviders();
        if (!$providers) return ['ok' => false, 'error' => 'هیچ پروایدر هوش مصنوعی فعالی تنظیم نشده'];

        $tmp = tempnam(sys_get_temp_dir(), 'ebvoice_');
        $ch = curl_init($audioUrl);
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
        $dlOk = curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        if (!$dlOk || filesize($tmp) < 100) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'دانلود فایل صوتی ناموفق بود'];
        }

        $lastError = null;
        foreach ($providers as $p) {
            $url = rtrim($p['base_url'], '/') . '/audio/transcriptions';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $p['api_key']],
                CURLOPT_POSTFIELDS     => [
                    'file'  => new CURLFile($tmp, 'audio/ogg', 'voice.ogg'),
                    'model' => 'whisper-1',
                ],
            ]);
            $raw  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cErr = curl_error($ch);
            curl_close($ch);

            self::log(['provider' => $p['key'], 'url' => $url, 'action' => 'transcribe'], $raw, $code, $cErr);

            if ($raw !== false && $code >= 200 && $code < 300) {
                $j = json_decode((string)$raw, true);
                if (is_array($j) && isset($j['text'])) {
                    @unlink($tmp);
                    return ['ok' => true, 'text' => trim((string)$j['text']), 'provider' => $p['key']];
                }
            }
            $lastError = "{$p['label']}: " . ($cErr ?: "HTTP {$code}");
        }
        @unlink($tmp);
        return ['ok' => false, 'error' => "رونویسی روی هیچ پروایدری جواب نداد. آخرین خطا: {$lastError}"];
    }

    /* ==================== ۱.۰.۱۴: کلید امن، مدل‌ها، وضعیت، مصرف و هزینه ==================== */

    /** منبع درخواست جاری برای گزارش مصرف (agent، autoreply، draft، test…) و مشتری */
    public static $usageCtx = ['source' => 'ai', 'person_id' => null];

    private static function vaultKey(): string
    {
        static $k = null;
        if ($k !== null) return $k;
        $cfg = $GLOBALS['config'] ?? null;
        if (!is_array($cfg)) { $f = dirname(__DIR__) . '/config.php'; $cfg = is_file($f) ? (include $f) : []; }
        $ak = is_array($cfg) ? (string)($cfg['app_key'] ?? '') : '';
        return $k = ($ak !== '' && function_exists('openssl_encrypt')) ? hash('sha256', 'ai|' . $ak, true) : '';
    }

    public static function vaultEnc(string $v): string
    {
        $k = self::vaultKey();
        if ($v === '' || $k === '' || strpos($v, 'enc1:') === 0) return $v;
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($v, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        return $c === false ? $v : 'enc1:' . base64_encode($iv . $tag . $c);
    }

    public static function vaultDec(string $v): string
    {
        if (strpos($v, 'enc1:') !== 0) return $v;
        $k = self::vaultKey(); $raw = base64_decode(substr($v, 5));
        if ($k === '' || strlen((string)$raw) < 29) return '';
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? '' : $p;
    }

    /** فهرست مدل‌های قابل‌استفاده‌ی یک پروایدر (GET {base}/models). کلید/آدرس فرم ذخیره‌نشده هم پذیرفته می‌شود. */
    public static function listModels(string $key, string $apiKey = '', string $baseUrl = ''): array
    {
        $all = self::providers();
        if (!isset($all[$key])) return ['ok' => false, 'error' => 'پروایدر ناشناخته'];
        $p = $all[$key];
        $apiKey = trim($apiKey) !== '' ? trim($apiKey) : $p['api_key'];
        $base = ($p['editable_url'] && trim($baseUrl) !== '') ? trim($baseUrl) : $p['base_url'];
        if ($apiKey === '' || $base === '') return ['ok' => false, 'error' => 'اول آدرس و کلید API این پروایدر را وارد کنید'];
        if (!preg_match('#^https?://#i', $base)) return ['ok' => false, 'error' => 'آدرس سرویس باید با https:// شروع شود'];
        $opts = [];
        if (class_exists('NetProxy')) { try { $opts = NetProxy::curlOptionsFor('ai_' . $key); } catch (Throwable $e) {} }
        $ch = curl_init(rtrim($base, '/') . '/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => self::get('insecure') !== '1', CURLOPT_SSL_VERIFYHOST => self::get('insecure') === '1' ? 0 : 2] + $opts);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($j) || $code < 200 || $code >= 300) {
            $msg = is_array($j) && isset($j['error']) ? (is_array($j['error']) ? ($j['error']['message'] ?? '') : (string)$j['error']) : ($raw === false ? $err : 'HTTP ' . $code);
            self::setStatus($key, false, $code, (string)$msg);
            return ['ok' => false, 'code' => $code, 'error' => ($code === 401 || $code === 403 ? 'کلید API نامعتبر است یا دسترسی ندارد' : 'دریافت مدل‌ها ناموفق بود') . ($msg !== '' ? ': ' . mb_substr((string)$msg, 0, 200) : '')];
        }
        $list = [];
        foreach ((array)($j['data'] ?? $j['models'] ?? []) as $m) {
            $id = is_array($m) ? (string)($m['id'] ?? $m['name'] ?? '') : (string)$m;
            if ($id !== '') $list[] = $id;
        }
        $list = array_values(array_unique($list));
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        self::setStatus($key, true, $code, null);
        return ['ok' => true, 'models' => $list];
    }

    /** آخرین وضعیت اتصال هر پروایدر (موفق/خطا) برای نمایش در پنل */
    public static function providerStatus(string $key): ?array
    {
        try { $r = Database::one("SELECT v FROM eb_settings WHERE k = ?", ["ai.stat.{$key}"]); } catch (Throwable $e) { return null; }
        $s = json_decode((string)($r['v'] ?? ''), true);
        return is_array($s) ? $s : null;
    }

    private static function setStatus(string $key, bool $ok, int $code, ?string $err): void
    {
        try {
            $s = self::providerStatus($key) ?: [];
            if ($ok) { $s['ok_at'] = date('Y-m-d H:i:s'); }
            else { $s['err_at'] = date('Y-m-d H:i:s'); $s['err'] = mb_substr((string)$err, 0, 300); $s['code'] = $code; }
            $s['last'] = $ok ? 'ok' : 'error';
            Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", ["ai.stat.{$key}", json_encode($s, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {}
    }

    private static function ensureUsage(): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            if (Database::hasTable('eb_ai_usage')) return $ok = true;
            Database::pdo()->exec("CREATE TABLE IF NOT EXISTS eb_ai_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(30) NOT NULL,
                model VARCHAR(100) NOT NULL DEFAULT '',
                source VARCHAR(20) NOT NULL DEFAULT 'ai',
                person_id INT DEFAULT NULL,
                ok TINYINT(1) NOT NULL DEFAULT 1,
                http INT DEFAULT NULL,
                prompt_tokens INT NOT NULL DEFAULT 0,
                completion_tokens INT NOT NULL DEFAULT 0,
                cost DECIMAL(16,2) NOT NULL DEFAULT 0,
                ms INT DEFAULT NULL,
                error VARCHAR(300) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_created (created_at), KEY idx_pm (provider, model)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            Database::forgetSchemaCache();
            return $ok = true;
        } catch (Throwable $e) { error_log('[ai usage] ' . $e->getMessage()); return $ok = false; }
    }

    /** قیمت هر مدل: تومان به ازای ۱ میلیون توکن ورودی/خروجی (قابل ویرایش در پنل) */
    public static function prices(): array
    {
        try { $r = Database::one("SELECT v FROM eb_settings WHERE k = 'ai.prices'"); } catch (Throwable $e) { return []; }
        $p = json_decode((string)($r['v'] ?? ''), true);
        return is_array($p) ? $p : [];
    }

    public static function savePrices(array $in): array
    {
        $out = [];
        foreach ($in as $model => $v) {
            $model = mb_substr(trim((string)$model), 0, 100);
            if ($model === '' || !is_array($v)) continue;
            $i = max(0, (float)preg_replace('/[^\d.]/', '', (string)($v['in'] ?? 0)));
            $o = max(0, (float)preg_replace('/[^\d.]/', '', (string)($v['out'] ?? 0)));
            if ($i > 0 || $o > 0) $out[$model] = ['in' => $i, 'out' => $o];
        }
        Database::run("INSERT INTO eb_settings (k, v) VALUES ('ai.prices', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [json_encode($out, JSON_UNESCAPED_UNICODE)]);
        return $out;
    }

    /** ثبت مصرف هر درخواست (توکن، هزینه، زمان، خطا) — هم برای گزارش مصرف و هم وضعیت اتصال */
    public static function recordUsage(string $provider, string $model, $usage, bool $ok, ?int $ms = null, ?int $code = null, ?string $err = null): void
    {
        self::setStatus($provider, $ok, (int)$code, $err);
        if (!self::ensureUsage()) return;
        $pt = is_array($usage) ? (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0) : 0;
        $ct = is_array($usage) ? (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0) : 0;
        $pr = self::prices()[$model] ?? null;
        $cost = $pr ? round(($pt * (float)$pr['in'] + $ct * (float)$pr['out']) / 1000000, 2) : 0;
        try {
            Database::run("INSERT INTO eb_ai_usage (provider, model, source, person_id, ok, http, prompt_tokens, completion_tokens, cost, ms, error) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [mb_substr($provider, 0, 30), mb_substr($model, 0, 100), mb_substr((string)(self::$usageCtx['source'] ?? 'ai'), 0, 20), self::$usageCtx['person_id'] ?? null,
                 $ok ? 1 : 0, $code ?: null, $pt, $ct, $cost, $ms, $err !== null ? mb_substr($err, 0, 300) : null]);
        } catch (Throwable $e) {}
    }

    /** گزارش مصرف: جمع کل، به تفکیک پروایدر/مدل و منبع، و روزانه */
    public static function usageReport(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        if (!self::ensureUsage()) return ['totals' => [], 'by_model' => [], 'by_source' => [], 'daily' => []];
        $w = "created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";
        $sel = "COUNT(*) req, SUM(ok) ok, SUM(prompt_tokens) pt, SUM(completion_tokens) ct, SUM(cost) cost, ROUND(AVG(CASE WHEN ok = 1 THEN ms END)) ms";
        return [
            'days' => $days,
            'totals' => Database::one("SELECT $sel FROM eb_ai_usage WHERE $w") ?: [],
            'by_model' => Database::all("SELECT provider, model, $sel FROM eb_ai_usage WHERE $w GROUP BY provider, model ORDER BY cost DESC, req DESC LIMIT 50"),
            'by_source' => Database::all("SELECT source, $sel FROM eb_ai_usage WHERE $w GROUP BY source ORDER BY req DESC"),
            'daily' => Database::all("SELECT DATE(created_at) d, $sel FROM eb_ai_usage WHERE $w GROUP BY DATE(created_at) ORDER BY d"),
            'errors' => Database::all("SELECT provider, model, http, error, created_at FROM eb_ai_usage WHERE $w AND ok = 0 ORDER BY id DESC LIMIT 15"),
            'prices' => self::prices(),
            'models' => array_values(array_unique(array_merge(array_column(Database::all("SELECT DISTINCT model FROM eb_ai_usage WHERE model <> '' ORDER BY model LIMIT 60"), 'model'),
                array_values(array_filter(array_map(fn($p) => $p['enabled'] ? $p['model'] : '', self::providers()))), array_keys(self::prices())))),
        ];
    }

    private static function log(array $request, $response, int $code, string $cErr): void
    {
        if (!class_exists('ApiLog')) return;
        try {
            $resp = is_string($response) ? mb_substr($response, 0, 2000) : $response;
            ApiLog::record('ai', 'chat_completions', $request, $resp, $code,
                           ($code >= 200 && $code < 300), $cErr !== '' ? $cErr : null);
        } catch (Throwable $e) { /* لاگ نباید مسیر را بشکند */ }
    }
}
