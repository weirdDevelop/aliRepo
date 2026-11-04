<?php
/**
 * modules/SiteWidgets.php
 * پیکربندی ابزارهای روی سایت مشتری: «دکمه‌های شناور تماس» و طراحی کامل «گفتگوی آنلاین».
 * هر دو با همان یک خط اسکریپت widget.js روی سایت نمایش داده می‌شوند.
 *
 * ذخیره: eb_settings با کلیدهای fab.config و wcx.design (JSON). تنظیمات پایه‌ی گفتگو
 * (عنوان، خوش‌آمد، حالت شروع، …) همچنان در کلیدهای webchat.* می‌ماند تا صفحه‌ی قبلی هم کار کند.
 */
class SiteWidgets
{
    /** ۲.۱.۱: کلید تنظیمات برای سایت جاری */
    private static function K(string $k): string { return (class_exists('SiteCtx') ? SiteCtx::pfx() : '') . $k; }

    /** نوع دکمه‌ها: [برچسب، آیکون، رنگ پیش‌فرض، راهنمای مقدار] */
    const TYPES = [
        'whatsapp'  => ['واتساپ', 'whatsapp-logo', '#1FA855', 'شماره با کد کشور، مثل 989121234567'],
        'telegram'  => ['تلگرام', 'telegram-logo', '#2AA3DF', 'نام کاربری بدون @'],
        'bale'      => ['بله', 'chat-centered-dots', '#2C9C6A', 'نام کاربری بدون @'],
        'eitaa'     => ['ایتا', 'chat-circle-text', '#E57B22', 'نام کاربری'],
        'rubika'    => ['روبیکا', 'chat-teardrop-dots', '#7B3FE4', 'نام کاربری'],
        'instagram' => ['اینستاگرام', 'instagram-logo', '#C13584', 'نام کاربری پیج بدون @'],
        'phone'     => ['تماس تلفنی', 'phone', '#1D5C4D', 'شماره تلفن'],
        'sms'       => ['پیامک', 'chat-text', '#3D6A9E', 'شماره موبایل'],
        'email'     => ['ایمیل', 'envelope-simple', '#8A5A1C', 'آدرس ایمیل'],
        'map'       => ['آدرس روی نقشه', 'map-pin', '#B4432F', 'لینک نشان، بلد یا گوگل‌مپ'],
        'link'      => ['لینک دلخواه', 'link-simple', '#4B5563', 'آدرس کامل https://'],
        'webchat'   => ['گفتگوی آنلاین', 'chat-circle-dots', '#1D5C4D', 'پنجره‌ی گفتگوی سایت باز می‌شود'],
    ];

    const ICONS = ['chat-circle-dots', 'chat-teardrop-dots', 'headset', 'phone', 'plus', 'question', 'hand-waving', 'paper-plane-tilt', 'lifebuoy', 'chats-circle', 'storefront', 'sparkle'];

    /* ---------------- دکمه‌های شناور ---------------- */

    public static function fabDefaults(): array
    {
        return [
            'enabled' => false,
            'layout' => 'menu',              // menu: یک دکمه که باز می‌شود | stack: همه‌ی دکمه‌ها همیشه دیده می‌شوند | bar: نوار پایین موبایل
            'position' => ['side' => 'right', 'bottom' => 24, 'offset' => 24, 'm_bottom' => 20, 'm_offset' => 16],
            'main' => ['icon' => 'chat-circle-dots', 'image' => '', 'label' => 'تماس با ما', 'color' => '#1D5C4D', 'text_color' => '#FFFFFF',
                       'size' => 58, 'shape' => 'circle', 'show_label' => 'hover', 'animation' => 'pulse', 'close_icon' => true],
            'items' => ['size' => 46, 'shape' => 'circle', 'show_label' => 'always', 'label_bg' => '#FFFFFF', 'label_text' => '#1F2A26', 'gap' => 10],
            'greeting' => ['enabled' => true, 'text' => 'سوالی دارید؟ همین‌جا بپرسید', 'delay' => 5, 'once' => true],
            'rules' => ['devices' => 'all', 'include' => '', 'exclude' => '', 'delay' => 0, 'scroll' => 0, 'hours_only' => false],
            'tracking' => true,
            'buttons' => [
                ['key' => 'b1', 'type' => 'webchat', 'label' => 'گفتگوی آنلاین', 'value' => '', 'color' => '#1D5C4D', 'icon' => 'chat-circle-dots', 'enabled' => true, 'devices' => 'all', 'hours_only' => false],
                ['key' => 'b2', 'type' => 'phone', 'label' => 'تماس تلفنی', 'value' => '', 'color' => '#2E6670', 'icon' => 'phone', 'enabled' => true, 'devices' => 'all', 'hours_only' => true],
            ],
        ];
    }

    public static function fab(): array
    {
        $raw = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = ?", [self::K('fab.config')])['v'] ?? ''), true);
        return self::cleanFab(is_array($raw) ? $raw : []);
    }

    public static function saveFab(array $in): array
    {
        $c = self::cleanFab($in);
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::K('fab.config'), json_encode($c, JSON_UNESCAPED_UNICODE)]);
        return $c;
    }

    private static function cleanFab(array $in): array
    {
        $d = self::fabDefaults();
        $o = [
            'enabled' => !empty($in['enabled'] ?? $d['enabled']),
            'layout' => self::pick($in['layout'] ?? '', ['menu', 'stack', 'bar'], $d['layout']),
            'position' => self::position($in['position'] ?? [], $d['position']),
            'main' => [
                'icon' => self::pick($in['main']['icon'] ?? '', self::ICONS, $d['main']['icon']),
                'image' => self::path($in['main']['image'] ?? ''),
                'label' => self::text($in['main']['label'] ?? $d['main']['label'], 40),
                'color' => self::color($in['main']['color'] ?? '', $d['main']['color']),
                'text_color' => self::color($in['main']['text_color'] ?? '', $d['main']['text_color']),
                'size' => self::num($in['main']['size'] ?? $d['main']['size'], 40, 84),
                'shape' => self::pick($in['main']['shape'] ?? '', ['circle', 'rounded', 'square', 'pill'], $d['main']['shape']),
                'show_label' => self::pick($in['main']['show_label'] ?? '', ['hover', 'always', 'never'], $d['main']['show_label']),
                'animation' => self::pick($in['main']['animation'] ?? '', ['none', 'pulse', 'bounce', 'shake', 'glow', 'wiggle'], $d['main']['animation']),
                'close_icon' => !empty($in['main']['close_icon'] ?? true),
            ],
            'items' => [
                'size' => self::num($in['items']['size'] ?? $d['items']['size'], 34, 72),
                'shape' => self::pick($in['items']['shape'] ?? '', ['circle', 'rounded', 'square', 'pill'], $d['items']['shape']),
                'show_label' => self::pick($in['items']['show_label'] ?? '', ['hover', 'always', 'never'], $d['items']['show_label']),
                'label_bg' => self::color($in['items']['label_bg'] ?? '', $d['items']['label_bg']),
                'label_text' => self::color($in['items']['label_text'] ?? '', $d['items']['label_text']),
                'gap' => self::num($in['items']['gap'] ?? $d['items']['gap'], 4, 24),
            ],
            'greeting' => [
                'enabled' => !empty($in['greeting']['enabled'] ?? $d['greeting']['enabled']),
                'text' => self::text($in['greeting']['text'] ?? $d['greeting']['text'], 140),
                'delay' => self::num($in['greeting']['delay'] ?? $d['greeting']['delay'], 0, 120),
                'once' => !empty($in['greeting']['once'] ?? true),
            ],
            'rules' => self::rules($in['rules'] ?? [], $d['rules']),
            'tracking' => !empty($in['tracking'] ?? true),
            'buttons' => [],
        ];
        $seen = [];
        foreach (array_slice((array)($in['buttons'] ?? $d['buttons']), 0, 12) as $b) {
            if (!is_array($b) || !isset(self::TYPES[$b['type'] ?? ''])) continue;
            $t = self::TYPES[$b['type']];
            $key = preg_match('/^[a-z0-9]{1,12}$/', (string)($b['key'] ?? '')) ? $b['key'] : 'b' . substr(bin2hex(random_bytes(3)), 0, 5);
            if (isset($seen[$key])) $key = 'b' . substr(bin2hex(random_bytes(3)), 0, 5);
            $seen[$key] = 1;
            $o['buttons'][] = [
                'key' => $key, 'type' => $b['type'],
                'label' => self::text($b['label'] ?? $t[0], 40) ?: $t[0],
                'value' => self::text($b['value'] ?? '', 300),
                'message' => self::text($b['message'] ?? '', 300),
                'color' => self::color($b['color'] ?? '', $t[2]),
                'icon' => preg_match('/^[a-z0-9-]{2,40}$/', (string)($b['icon'] ?? '')) ? $b['icon'] : $t[1],
                'enabled' => !empty($b['enabled'] ?? true),
                'devices' => self::pick($b['devices'] ?? '', ['all', 'desktop', 'mobile'], 'all'),
                'hours_only' => !empty($b['hours_only']),
            ];
        }
        return $o;
    }

    /** لینک نهایی هر دکمه */
    public static function href(array $b): string
    {
        $v = trim((string)$b['value']);
        $digits = preg_replace('/\D+/', '', strtr($v, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
        $user = ltrim(preg_replace('~^https?://[^/]+/~', '', $v), '@/');
        $msg = trim((string)($b['message'] ?? ''));
        switch ($b['type']) {
            case 'whatsapp':
                if (strpos($digits, '0') === 0) $digits = '98' . substr($digits, 1);
                return 'https://wa.me/' . $digits . ($msg !== '' ? '?text=' . rawurlencode($msg) : '');
            case 'telegram': return 'https://t.me/' . rawurlencode($user);
            case 'bale': return 'https://ble.ir/' . rawurlencode($user);
            case 'eitaa': return 'https://eitaa.com/' . rawurlencode($user);
            case 'rubika': return 'https://rubika.ir/' . rawurlencode($user);
            case 'instagram': return 'https://ig.me/m/' . rawurlencode($user);
            case 'phone': return 'tel:' . preg_replace('/[^\d+]/', '', $v);
            case 'sms': return 'sms:' . preg_replace('/[^\d+]/', '', $v) . ($msg !== '' ? '?body=' . rawurlencode($msg) : '');
            case 'email': return 'mailto:' . $v . ($msg !== '' ? '?subject=' . rawurlencode($msg) : '');
            case 'webchat': return '#webchat';
            default: return preg_match('~^https?://~i', $v) ? $v : '';
        }
    }

    /* ---------------- طراحی گفتگوی آنلاین ---------------- */

    public static function designDefaults(): array
    {
        return [
            'launcher' => ['style' => 'circle', 'icon' => 'chat-circle-dots', 'image' => '', 'label' => 'گفتگو با ما', 'show_label' => 'hover', 'size' => 58,
                           'color' => '', 'text_color' => '#FFFFFF', 'animation' => 'none', 'badge' => true],
            'greeting' => ['enabled' => false, 'text' => 'سلام! سوالی دارید؟', 'delay' => 8, 'once' => true],
            'position' => ['side' => 'right', 'bottom' => 20, 'offset' => 20, 'm_bottom' => 78, 'm_offset' => 14],
            'panel' => ['width' => 370, 'height' => 560, 'radius' => 18, 'shadow' => 'soft', 'mobile_full' => true],
            'colors' => ['header_bg' => '', 'header_text' => '#FFFFFF', 'body_bg' => '#F6F5F1', 'agent_bubble' => '#FFFFFF', 'agent_text' => '#1F2A26',
                         'visitor_bubble' => '', 'visitor_text' => '#FFFFFF', 'input_bg' => '#FFFFFF'],
            'header' => ['avatar' => 'logo', 'avatar_url' => '', 'show_status' => true, 'show_team' => true, 'team_text' => 'تیم پشتیبانی'],
            'font' => ['family' => '', 'size' => 13.5],
            'behaviour' => ['auto_open' => 0, 'sound' => true, 'quick_replies' => [], 'hide_offline' => false, 'devices' => 'all',
                            'include' => '', 'exclude' => '', 'emoji' => true, 'attach' => false],
            'proactive' => [],
            'prechat' => ['email' => false, 'email_required' => false, 'note' => ''],
            'footer' => ['branding' => true, 'text' => ''],
        ];
    }

    public static function design(): array
    {
        $raw = json_decode((string)(Database::one("SELECT v FROM eb_settings WHERE k = ?", [self::K('wcx.design')])['v'] ?? ''), true);
        return self::cleanDesign(is_array($raw) ? $raw : []);
    }

    public static function saveDesign(array $in): array
    {
        $c = self::cleanDesign($in);
        Database::run("INSERT INTO eb_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [self::K('wcx.design'), json_encode($c, JSON_UNESCAPED_UNICODE)]);
        return $c;
    }

    private static function cleanDesign(array $in): array
    {
        $d = self::designDefaults();
        $L = $in['launcher'] ?? []; $G = $in['greeting'] ?? []; $P = $in['panel'] ?? []; $C = $in['colors'] ?? []; $H = $in['header'] ?? [];
        $F = $in['font'] ?? []; $B = $in['behaviour'] ?? []; $Q = $in['prechat'] ?? []; $Ft = $in['footer'] ?? [];
        $qr = [];
        foreach (array_slice((array)($B['quick_replies'] ?? []), 0, 8) as $x) { $x = self::text($x, 60); if ($x !== '') $qr[] = $x; }
        $pro = [];
        foreach (array_slice((array)($in['proactive'] ?? []), 0, 10) as $x) {
            if (!is_array($x)) continue;
            $t = self::text($x['text'] ?? '', 200);
            if ($t === '') continue;
            $pro[] = ['path' => self::text($x['path'] ?? '', 200), 'text' => $t, 'delay' => self::num($x['delay'] ?? 10, 0, 600), 'open' => !empty($x['open'])];
        }
        return [
            'launcher' => [
                'style' => self::pick($L['style'] ?? '', ['circle', 'rounded', 'square', 'pill'], $d['launcher']['style']),
                'icon' => self::pick($L['icon'] ?? '', self::ICONS, $d['launcher']['icon']),
                'image' => self::path($L['image'] ?? ''),
                'label' => self::text($L['label'] ?? $d['launcher']['label'], 40),
                'show_label' => self::pick($L['show_label'] ?? '', ['hover', 'always', 'never'], 'hover'),   // ۱.۰.۱۳: متن کنار دکمه
                'size' => self::num($L['size'] ?? $d['launcher']['size'], 40, 84),
                'color' => self::color($L['color'] ?? '', ''),
                'text_color' => self::color($L['text_color'] ?? '', $d['launcher']['text_color']),
                'animation' => self::pick($L['animation'] ?? '', ['none', 'pulse', 'bounce', 'shake', 'glow', 'wiggle'], 'none'),
                'badge' => !empty($L['badge'] ?? true),
            ],
            'greeting' => ['enabled' => !empty($G['enabled']), 'text' => self::text($G['text'] ?? $d['greeting']['text'], 140), 'delay' => self::num($G['delay'] ?? 8, 0, 300), 'once' => !empty($G['once'] ?? true)],
            'position' => self::position($in['position'] ?? [], $d['position']),
            'panel' => [
                'width' => self::num($P['width'] ?? 370, 300, 480), 'height' => self::num($P['height'] ?? 560, 400, 760),
                'radius' => self::num($P['radius'] ?? 18, 0, 28), 'shadow' => self::pick($P['shadow'] ?? '', ['soft', 'strong', 'none'], 'soft'),
                'mobile_full' => !empty($P['mobile_full'] ?? true),
            ],
            'colors' => array_combine(array_keys($d['colors']), array_map(fn($k) => self::color($C[$k] ?? '', $d['colors'][$k]), array_keys($d['colors']))),
            'header' => [
                'avatar' => self::pick($H['avatar'] ?? '', ['logo', 'team', 'custom', 'none'], 'logo'), 'avatar_url' => self::path($H['avatar_url'] ?? ''),
                'show_status' => !empty($H['show_status'] ?? true), 'show_team' => !empty($H['show_team'] ?? true), 'team_text' => self::text($H['team_text'] ?? 'تیم پشتیبانی', 40),
            ],
            'font' => ['family' => self::text($F['family'] ?? '', 80), 'size' => max(12, min(16, (float)($F['size'] ?? 13.5)))],
            'behaviour' => [
                'auto_open' => self::num($B['auto_open'] ?? 0, 0, 600), 'sound' => !empty($B['sound'] ?? true), 'quick_replies' => $qr,
                'hide_offline' => !empty($B['hide_offline']), 'devices' => self::pick($B['devices'] ?? '', ['all', 'desktop', 'mobile'], 'all'),
                'include' => self::text($B['include'] ?? '', 500), 'exclude' => self::text($B['exclude'] ?? '', 500),
                'emoji' => !empty($B['emoji'] ?? true), 'attach' => false,
            ],
            'proactive' => $pro,
            'prechat' => ['email' => !empty($Q['email']), 'email_required' => !empty($Q['email_required']), 'note' => self::text($Q['note'] ?? '', 200)],
            'footer' => ['branding' => !empty($Ft['branding'] ?? true), 'text' => self::text($Ft['text'] ?? '', 60)],
        ];
    }

    /* ---------------- خروجی عمومی برای widget.js ---------------- */

    public static function publicBoot(): array
    {
        $fab = self::fab();
        $out = ['fab' => null, 'chat' => null, 'online' => class_exists('Webchat') ? Webchat::isOnline() : true];
        if ($fab['enabled']) {
            foreach ($fab['buttons'] as $i => $b) {
                if (!$b['enabled']) { unset($fab['buttons'][$i]); continue; }
                $fab['buttons'][$i]['href'] = self::href($b);
                unset($fab['buttons'][$i]['enabled'], $fab['buttons'][$i]['value'], $fab['buttons'][$i]['message']);
            }
            $fab['buttons'] = array_values($fab['buttons']);
            unset($fab['enabled']);
            $out['fab'] = $fab;
        }
        if (class_exists('Webchat') && Webchat::isEnabled()) {
            $out['chat'] = Webchat::publicSettings() + ['design' => self::design(), 'team' => self::teamFaces(),
                'agent' => class_exists('SalesAgent') ? SalesAgent::publicPersona() : null];   // ۱.۰.۱۱: نام و تصویر دستیار فروش
        }
        return $out;
    }

    /** چند اپراتور برای نمایش در سربرگ (حرف اول و رنگ) */
    private static function teamFaces(): array
    {
        try {
            $av = Database::hasColumn('eb_users', 'avatar') ? 'avatar' : "''";
            $rows = Database::all("SELECT COALESCE(NULLIF(TRIM(display_name),''), username) n, color, $av a FROM eb_users WHERE is_active = 1 AND role <> 'viewer' ORDER BY (last_seen_at IS NULL), last_seen_at DESC LIMIT 3");
        } catch (Throwable $e) { return []; }
        return array_map(fn($r) => ['i' => mb_substr((string)$r['n'], 0, 1), 'c' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['color']) ? $r['color'] : '#2E6670', 'a' => (string)($r['a'] ?? '')], $rows);
    }

    public static function logClick(string $key, string $kind, string $page, string $device): void
    {
        if (!preg_match('/^[a-z0-9]{1,12}$/', $key)) return;
        Database::run("INSERT INTO eb_fab_clicks (button_key, kind, page_url, device) VALUES (?, ?, ?, ?)",
            [$key, mb_substr($kind, 0, 30), mb_substr($page, 0, 500), $device === 'mobile' ? 'mobile' : 'desktop']);
    }

    public static function clickStats(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $by = Database::all("SELECT button_key k, MAX(kind) kind, COUNT(*) c, SUM(device = 'mobile') m FROM eb_fab_clicks WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY button_key ORDER BY c DESC", [$days]);
        $daily = Database::all("SELECT DATE(created_at) d, COUNT(*) c FROM eb_fab_clicks WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY d ORDER BY d", [$days]);
        $pages = Database::all("SELECT page_url u, COUNT(*) c FROM eb_fab_clicks WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY page_url ORDER BY c DESC LIMIT 10", [$days]);
        return ['by_button' => $by, 'daily' => $daily, 'pages' => $pages, 'total' => array_sum(array_column($by, 'c'))];
    }

    /* ---------------- کمکی‌ها ---------------- */

    private static function position($p, array $d): array
    {
        $p = is_array($p) ? $p : [];
        return [
            'side' => self::pick($p['side'] ?? '', ['right', 'left'], $d['side']),
            'bottom' => self::num($p['bottom'] ?? $d['bottom'], 0, 300), 'offset' => self::num($p['offset'] ?? $d['offset'], 0, 200),
            'm_bottom' => self::num($p['m_bottom'] ?? $d['m_bottom'], 0, 300), 'm_offset' => self::num($p['m_offset'] ?? $d['m_offset'], 0, 200),
        ];
    }

    private static function rules($r, array $d): array
    {
        $r = is_array($r) ? $r : [];
        return [
            'devices' => self::pick($r['devices'] ?? '', ['all', 'desktop', 'mobile'], $d['devices']),
            'include' => self::text($r['include'] ?? '', 500), 'exclude' => self::text($r['exclude'] ?? '', 500),
            'delay' => self::num($r['delay'] ?? 0, 0, 120), 'scroll' => self::num($r['scroll'] ?? 0, 0, 100),
            'hours_only' => !empty($r['hours_only']),
        ];
    }

    private static function pick($v, array $allowed, $def) { return in_array($v, $allowed, true) ? $v : $def; }
    private static function num($v, int $min, int $max): int { return max($min, min($max, (int)$v)); }
    private static function text($v, int $max): string { return mb_substr(trim(strip_tags((string)$v)), 0, $max); }
    private static function color($v, string $def): string { $v = trim((string)$v); return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $def; }
    private static function path($v): string { $v = trim((string)$v); return preg_match('~^uploads/[A-Za-z0-9_.\-]+$~', $v) ? $v : ''; }
}
