<?php
/**
 * modules/AgentDemand.php — تحلیل تقاضا برای بازاریابی (۱.۰.۱۳)
 * ==========================================================================
 * هر «درخواست» = گفتگوی یک مشتری با دستیار در یک روز. از گزارش ابزارها (جستجو، پیش‌فاکتور، رزرو،
 * درخواست تماس) و متن پیام‌های مشتری ساخته می‌شود؛ جدول جدا لازم ندارد و برای گذشته هم کار می‌کند.
 *
 * برای هر درخواست: مقصد (شهر، منطقه‌ی تهران، محله)، نوع واحد، نفرات، نوع گروه (خانواده، زوج…)، تاریخ ورود،
 * چند شب، چند روز زودتر رزرو می‌کند، روز هفته، بودجه و رده‌ی قیمت (اقتصادی/متوسط/لوکس)، امکانات خواسته‌شده
 * (جکوزی، استخر…)، مناسبت، کانال، و این‌که به کجا رسید (جستجو ← پیشنهاد ← پیش‌فاکتور ← استعلام ← پرداخت)
 * و آیا جستجو بی‌نتیجه ماند (تقاضای پاسخ‌داده‌نشده).
 */
class AgentDemand
{
    const AMENITIES = ['جکوزی' => 'جکوزی|jacuzzi', 'استخر' => 'استخر', 'پارکینگ' => 'پارکینگ|پارکینک|جای\s*پارک', 'سونا' => 'سونا', 'بیلیارد' => 'بیلیارد',
        'باربیکیو' => 'باربیکیو|کباب\s*پز', 'حیاط' => 'حیاط', 'ویو' => 'ویو|منظره|چشم\s*انداز', 'آسانسور' => 'آسانسور', 'آشپزخانه' => 'آشپزخانه',
        'پروژکتور' => 'پروژکتور|ویدیو\s*پروژکتور', 'وای‌فای' => 'وای\s*فای|wifi|اینترنت', 'حیوان خانگی' => 'سگ|گربه|حیوان', 'سیستم صوتی' => 'سیستم\s*صوتی|اسپیکر|باند'];
    const GROUPS = ['خانواده' => 'خانواده|بچه|کودک|خانم\s*و\s*بچه', 'زوج' => 'زوج|همسرم|خانمم|شوهرم|متاهل|نامزد', 'مجرد / دوستان' => 'مجرد|دوستان|رفقا|بچه\s*ها|پسرونه|دخترونه',
        'همکاران / کاری' => 'همکار|ماموریت|کاری|شرکت', 'دانشجو' => 'دانشجو|کنکور|امتحان', 'بیمار / درمان' => 'بیمارستان|درمان|دکتر|عمل'];
    const OCCASIONS = ['تولد' => 'تولد', 'مهمانی / جشن' => 'مهمانی|پارتی|جشن|دورهمی', 'ماه عسل / سالگرد' => 'ماه\s*عسل|سالگرد|ولنتاین', 'استراحت / سفر' => 'استراحت|سفر|تفریح|مسافرت',
        'کار / مأموریت' => 'ماموریت|کاری|جلسه', 'مسافر شهرستان' => 'شهرستان|از\s*\S+\s*میام|مسافر'];
    const STAGES = ['search' => 'جستجو', 'offer' => 'پیشنهاد گرفت', 'quote' => 'پیش‌فاکتور', 'enquiry' => 'استعلام / رزرو', 'paid' => 'پرداخت'];

    /** درخواست‌های بازه (حداکثر ۵۰۰۰) */
    public static function requests(int $days = 30): array
    {
        ApiConnectors::ensure();
        $days = max(1, min(365, $days));
        $logs = Database::all("SELECT person_id, tool, args_json, ok, summary, created_at FROM eb_agent_log
            WHERE person_id > 0 AND kind IN ('tool','lead') AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) ORDER BY id LIMIT 60000");
        if (!$logs) return [];
        $req = [];
        foreach ($logs as $l) {
            $k = $l['person_id'] . '|' . substr($l['created_at'], 0, 10);
            if (!isset($req[$k])) $req[$k] = ['person_id' => (int)$l['person_id'], 'day' => substr($l['created_at'], 0, 10), 'at' => $l['created_at'], 'stage' => '',
                'search' => [], 'results' => null, 'prices' => [], 'quote' => null, 'nights' => null, 'callback' => false, 'enquiry' => false];
            $r = &$req[$k];
            $tool = preg_replace('/^.*__/', '', (string)$l['tool']);
            $args = json_decode((string)$l['args_json'], true) ?: [];
            $sum = (string)$l['summary'];
            if ($tool === 'search' && $l['ok']) {
                foreach ($args as $a => $v) if (!is_array($v) && $v !== '' && $v !== null) $r['search'][$a] = $v; elseif (is_array($v) && $v) $r['search'][$a] = $v;
                if (preg_match('/^(\d+)\s*نتیجه/u', $sum, $m)) $r['results'] = max((int)$r['results'], (int)$m[1]);
                elseif ($r['results'] === null && strpos($sum, '"items":[]') !== false) $r['results'] = 0;
                if (preg_match_all('/—\s*([\d,]{6,})/u', $sum, $mm)) foreach ($mm[1] as $p) $r['prices'][] = (int)str_replace(',', '', $p);
                $r['stage'] = self::up($r['stage'], (int)$r['results'] > 0 ? 'offer' : 'search');
            } elseif ($tool === 'quote' && $l['ok']) {
                if (preg_match('/total_payable_price=(\d+)/', $sum, $m)) $r['quote'] = (int)$m[1];
                if (preg_match('/nights=(\d+)/', $sum, $m)) $r['nights'] = (int)$m[1];
                foreach (['check_in', 'check_out', 'guests'] as $a) if (!empty($args[$a])) $r['search'][$a] = $args[$a];
                $r['stage'] = self::up($r['stage'], 'quote');
            } elseif ($tool === 'booking_request' && $l['ok'] && strpos($sum, 'منتظر تأیید') === false && strpos($sum, 'آزمایشی') === false) {
                $r['enquiry'] = true; $r['stage'] = self::up($r['stage'], 'enquiry');
            } elseif ($tool === 'request_callback') {
                $r['callback'] = true;
            }
            unset($r);
        }
        // پرداخت‌ها
        $pids = array_values(array_unique(array_map(fn($r) => $r['person_id'], $req)));
        $paid = [];
        foreach (array_chunk($pids, 500) as $ch) {
            $in = implode(',', array_map('intval', $ch));
            foreach (Database::all("SELECT person_id, DATE(created_at) d, amount FROM eb_agent_orders WHERE person_id IN ($in) AND status IN ('paid','confirmed','completed')") as $o) $paid[$o['person_id'] . '|' . $o['d']] = (int)$o['amount'];
        }
        // متن پیام‌های مشتری (امکانات، نوع گروه، مناسبت، بودجه)
        $texts = [];
        $chan = [];
        foreach (array_chunk($pids, 300) as $ch) {
            $in = implode(',', array_map('intval', $ch));
            foreach (Database::all("SELECT i.person_id, DATE(m.created_at) d, m.body, i.channel FROM eb_messages m JOIN eb_identities i ON i.id = m.identity_id
                                    WHERE i.person_id IN ($in) AND m.direction = 'in' AND m.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) ORDER BY m.id LIMIT 40000") as $m) {
                $k = $m['person_id'] . '|' . $m['d'];
                $texts[$k] = ($texts[$k] ?? '') . "\n" . mb_substr((string)$m['body'], 0, 500);
                $chan[$k] = $m['channel'];
            }
        }
        $eco = (int)SalesAgent::get('dm_eco') ?: 2500000;
        $lux = (int)SalesAgent::get('dm_lux') ?: 6000000;
        $out = [];
        foreach ($req as $k => $r) {
            $s = $r['search'];
            $txt = self::norm($texts[$k] ?? '');
            $am = [];
            foreach ((array)($s['amenities'] ?? []) as $a) $am[trim((string)$a)] = true;
            foreach (self::AMENITIES as $name => $re) if (preg_match('/' . $re . '/iu', $txt)) $am[$name] = true;
            $grp = '';
            foreach (self::GROUPS as $name => $re) if (preg_match('/' . $re . '/iu', $txt)) { $grp = $name; break; }
            $guests = (int)($s['guests'] ?? 0);
            if ($grp === '' && $guests === 1) $grp = 'یک نفر';
            $occ = '';
            foreach (self::OCCASIONS as $name => $re) if (preg_match('/' . $re . '/iu', $txt)) { $occ = $name; break; }
            // بودجه: سقف قیمت جستجو، یا عدد «میلیون» در متن
            $budget = (int)($s['max_price'] ?? 0);
            if (!$budget && preg_match('/(\d+(?:[.\/]\d+)?)\s*(میلیون|م\b|تومن|تومان)/u', $txt, $m)) {
                $v = (float)str_replace('/', '.', $m[1]);
                $budget = $m[2] === 'میلیون' || $m[2] === 'م' ? (int)($v * 1000000) : ($v >= 100000 ? (int)$v : 0);
            }
            $nights = $r['nights'] ?: self::nights((string)($s['check_in'] ?? ''), (string)($s['check_out'] ?? ''));
            $perNight = $budget ?: ($r["quote"] && $nights ? (int)($r["quote"] / $nights) : ($r["prices"] ? (int)(self::median($r["prices"]) / max(1, $nights ?: 1)) : 0));
            $cls = !$perNight ? '' : ($perNight < $eco ? 'اقتصادی' : ($perNight > $lux ? 'لوکس' : 'متوسط'));
            if (preg_match('/ارزان|ارزون|اقتصادی|کم\s*هزینه|قیمت\s*پایین|مناسب\s*قیمت/u', $txt)) $cls = 'اقتصادی';
            elseif ($cls === '' && preg_match('/لوکس|لاکچری|vip|خفن|بهترین/iu', $txt)) $cls = 'لوکس';
            [$city, $area, $hood] = self::place($s);
            $ci = (string)($s['check_in'] ?? '');
            $stage = isset($paid[$k]) ? 'paid' : ($r['stage'] ?: 'search');
            $out[] = [
                'person_id' => $r['person_id'], 'day' => $r['day'], 'channel' => self::chLabel((string)($chan[$k] ?? '')),
                'city' => $city, 'area' => $area, 'hood' => $hood, 'type' => trim((string)($s['space_type'] ?? '')) ?: '', 'guests' => $guests ?: null,
                'group' => $grp, 'occasion' => $occ, 'check_in' => $ci, 'nights' => $nights ?: null, 'lead_days' => self::leadDays($ci, $r['day']),
                'weekday' => self::weekday($ci), 'budget' => $budget ?: null, 'price_night' => $perNight ?: null, 'class' => $cls,
                'amenities' => array_keys($am), 'results' => $r['results'], 'unmet' => $r['results'] === 0, 'stage' => $stage,
                'enquiry' => $r['enquiry'] || $stage === 'paid', 'paid' => $stage === 'paid', 'amount' => $paid[$k] ?? ($r['quote'] ?: null), 'callback' => $r['callback'],
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['day'], $a['day']));
        return array_slice($out, 0, 5000);
    }

    /** خلاصه‌ها برای پنل */
    public static function summary(int $days = 30, array $filter = []): array
    {
        $rows = self::requests($days);
        foreach (['area', 'type', 'class', 'group', 'channel', 'city'] as $f) if (!empty($filter[$f])) $rows = array_values(array_filter($rows, fn($r) => $r[$f] === $filter[$f]));
        if (!empty($filter['amenity'])) $rows = array_values(array_filter($rows, fn($r) => in_array($filter['amenity'], $r['amenities'], true)));
        $n = count($rows);
        $by = function (callable $key, int $limit = 12) use ($rows) {
            $g = [];
            foreach ($rows as $r) foreach ((array)$key($r) as $v) {
                if ($v === '' || $v === null) continue;
                $v = (string)$v;
                $g[$v] = $g[$v] ?? ['label' => $v, 'n' => 0, 'enquiry' => 0, 'paid' => 0, 'unmet' => 0];
                $g[$v]['n']++; if ($r['enquiry']) $g[$v]['enquiry']++; if ($r['paid']) $g[$v]['paid']++; if ($r['unmet']) $g[$v]['unmet']++;
            }
            usort($g, fn($a, $b) => $b['n'] <=> $a['n']);
            return array_slice(array_values($g), 0, $limit);
        };
        $bucket = fn($v, array $edges) => $v === null ? '' : (function () use ($v, $edges) { foreach ($edges as $lim => $lab) if ($v <= $lim) return $lab; return end($edges); })();
        $stages = [];
        foreach (array_keys(self::STAGES) as $i => $s) $stages[$s] = count(array_filter($rows, fn($r) => array_search($r['stage'], array_keys(self::STAGES), true) >= $i));
        $unmet = array_values(array_filter($rows, fn($r) => $r['unmet']));
        $prices = array_values(array_filter(array_map(fn($r) => $r['price_night'], $rows)));
        return [
            'days' => $days, 'total' => $n,
            'kpi' => ['requests' => $n, 'people' => count(array_unique(array_map(fn($r) => $r['person_id'], $rows))),
                'enquiry' => count(array_filter($rows, fn($r) => $r['enquiry'])), 'paid' => count(array_filter($rows, fn($r) => $r['paid'])),
                'unmet' => count($unmet), 'callbacks' => count(array_filter($rows, fn($r) => $r['callback'])),
                'median_night' => $prices ? (int)self::median($prices) : null],
            'stages' => array_map(fn($k, $v) => ['key' => $k, 'label' => self::STAGES[$k], 'n' => $v], array_keys($stages), array_values($stages)),
            'by' => [
                'area' => $by(fn($r) => $r['area'] ?: $r['city']),
                'hood' => $by(fn($r) => $r['hood'], 15),
                'type' => $by(fn($r) => $r['type']),
                'class' => $by(fn($r) => $r['class']),
                'amenity' => $by(fn($r) => $r['amenities'], 15),
                'group' => $by(fn($r) => $r['group']),
                'guests' => $by(fn($r) => $r['guests'] === null ? '' : ($r['guests'] >= 6 ? '۶ نفر و بیشتر' : strtr((string)$r['guests'], ['1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵']) . ' نفر')),
                'nights' => $by(fn($r) => $bucket($r['nights'], [1 => '۱ شب', 2 => '۲ شب', 4 => '۳ تا ۴ شب', 7 => '۵ تا ۷ شب', 9999 => 'بیش از یک هفته'])),
                'lead' => $by(fn($r) => $bucket($r['lead_days'], [0 => 'همان روز', 1 => 'فردا', 3 => '۲ تا ۳ روز قبل', 7 => 'تا یک هفته قبل', 30 => 'تا یک ماه قبل', 9999 => 'بیش از یک ماه قبل'])),
                'weekday' => $by(fn($r) => $r['weekday'], 7),
                'occasion' => $by(fn($r) => $r['occasion']),
                'channel' => $by(fn($r) => $r['channel']),
                'price' => $by(fn($r) => $bucket($r['price_night'], [1500000 => 'تا ۱٫۵ میلیون', 2500000 => '۱٫۵ تا ۲٫۵ میلیون', 4000000 => '۲٫۵ تا ۴ میلیون', 6000000 => '۴ تا ۶ میلیون', 10000000 => '۶ تا ۱۰ میلیون', PHP_INT_MAX => 'بیش از ۱۰ میلیون'])),
            ],
            'unmet' => array_slice(array_map(fn($r) => array_intersect_key($r, array_flip(['day', 'area', 'hood', 'city', 'type', 'guests', 'check_in', 'nights', 'class', 'amenities', 'budget'])), $unmet), 0, 30),
        ];
    }

    /** خروجی CSV (UTF-8 با BOM برای اکسل) */
    public static function csv(int $days = 30): string
    {
        $cols = ['day' => 'روز گفتگو', 'channel' => 'کانال', 'city' => 'شهر', 'area' => 'منطقه', 'hood' => 'محله', 'type' => 'نوع واحد', 'guests' => 'نفرات', 'group' => 'نوع گروه',
            'occasion' => 'مناسبت', 'check_in' => 'تاریخ ورود', 'nights' => 'شب', 'lead_days' => 'چند روز زودتر', 'weekday' => 'روز هفته', 'budget' => 'بودجه', 'price_night' => 'قیمت هر شب',
            'class' => 'رده‌ی قیمت', 'amenities' => 'امکانات', 'results' => 'تعداد نتیجه', 'stage' => 'مرحله', 'amount' => 'مبلغ', 'callback' => 'درخواست تماس', 'person_id' => 'شناسه‌ی مشتری'];
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, array_values($cols));
        foreach (self::requests($days) as $r) {
            $row = [];
            foreach (array_keys($cols) as $c) {
                $v = $r[$c] ?? '';
                if ($c === 'amenities') $v = implode('، ', $v);
                if ($c === 'stage') $v = self::STAGES[$v] ?? $v;
                if ($c === 'callback') $v = $v ? 'بله' : '';
                $row[] = is_bool($v) ? ($v ? 1 : 0) : $v;
            }
            fputcsv($f, $row);
        }
        rewind($f);
        return (string)stream_get_contents($f);
    }

    /* ---------------- کمکی‌ها ---------------- */

    private static function up(string $cur, string $new): string
    {
        $o = array_keys(self::STAGES);
        return array_search($new, $o, true) > ($cur === '' ? -1 : array_search($cur, $o, true)) ? $new : $cur;
    }

    private static function place(array $s): array
    {
        $city = trim((string)($s['city'] ?? ''));
        $region = trim((string)($s['region'] ?? ''));
        $q = trim((string)($s['q'] ?? ''));
        $regions = ['shomal' => 'شمال کشور', 'jonoub' => 'جنوب کشور', 'tehran_suburbs' => 'اطراف تهران', 'markazi' => 'مرکز کشور', 'gharb' => 'غرب کشور', 'shargh' => 'شرق کشور',
            'شمال' => 'شمال کشور', 'جنوب' => 'جنوب کشور', 'اطراف تهران' => 'اطراف تهران', 'غرب' => 'غرب کشور', 'شرق' => 'شرق کشور', 'مرکز' => 'مرکز کشور'];
        $area = ''; $hood = '';
        $n = fn($x) => preg_replace('/[\s\x{200c}]+/u', '', str_replace(['ي', 'ك', 'آ'], ['ی', 'ک', 'ا'], (string)$x));
        foreach (ApiConnectors::TEHRAN_AREAS as $g => $list) {
            if ($q !== '' && $n($q) === $n($g)) { $area = $g; break; }
            foreach ($list as $h) if ($q !== '' && $n($q) === $n($h)) { $area = $g; $hood = $h; break 2; }
        }
        if ($area === '' && $region !== '') $area = $regions[$region] ?? $region;
        if ($hood === '' && $q !== '' && $area === '' && !preg_match('/^\d+$/', $q)) $hood = $q;
        if ($city === '' && ($area !== '' && strpos($area, 'تهران') !== false)) $city = 'تهران';
        return [$city, $area, $hood];
    }

    private static function chLabel(string $ch): string
    {
        if (strpos($ch, 'instagram') === 0) return 'اینستاگرام';
        return ['webchat' => 'گفتگوی سایت', 'bale' => 'بله', 'bale_safir' => 'بله', 'telegram' => 'تلگرام', 'roubika' => 'روبیکا', 'sms' => 'پیامک'][$ch] ?? $ch;
    }

    private static function norm(string $t): string
    {
        return str_replace(['ي', 'ك'], ['ی', 'ک'], strtr($t, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']));
    }

    private static function median(array $a): float
    {
        sort($a); $c = count($a);
        return $c ? ($c % 2 ? $a[intdiv($c, 2)] : ($a[$c / 2 - 1] + $a[$c / 2]) / 2) : 0;
    }

    /** شمسی YYYY-MM-DD → میلادی DateTime */
    private static function jdate(string $j): ?DateTime
    {
        if (!preg_match('/^(1[34]\d\d)-(\d\d)-(\d\d)$/', $j, $m)) return null;
        [$gy, $gm, $gd] = self::j2g((int)$m[1], (int)$m[2], (int)$m[3]);
        return new DateTime(sprintf('%04d-%02d-%02d', $gy, $gm, $gd), new DateTimeZone('Asia/Tehran'));
    }

    private static function nights(string $a, string $b): ?int
    {
        $x = self::jdate($a); $y = self::jdate($b);
        return $x && $y ? max(0, (int)$x->diff($y)->days) ?: null : null;
    }

    private static function leadDays(string $ci, string $day): ?int
    {
        $x = self::jdate($ci);
        if (!$x) return null;
        $d = (int)round(($x->getTimestamp() - strtotime($day . ' 00:00:00')) / 86400);
        return $d < 0 ? null : $d;
    }

    private static function weekday(string $ci): string
    {
        $x = self::jdate($ci);
        return $x ? ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'][(int)$x->format('w')] : '';
    }

    public static function j2g(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097); $days %= 146097;
        if ($days > 36524) { $gy += 100 * intdiv(--$days, 36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * intdiv($days, 1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $sal = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $sal[$gm]; $gm++) $gd -= $sal[$gm];
        return [$gy, $gm, $gd];
    }
}
