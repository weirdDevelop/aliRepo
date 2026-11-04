<?php
/**
 * modules/Carousel.php  (۲.۱.۰)
 * کروسل (کارت‌های افقی با عکس، عنوان، زیرعنوان و حداکثر ۳ دکمه) — مشابه کروسل دایرکت اینستاگرام.
 *
 * فرمت ذخیره‌شده (مستقل از کانال):
 *   [ { "title":"…", "subtitle":"…", "image":"uploads/img_x.jpg | https://…",
 *       "buttons":[ {"text":"خرید حضوری","url":"https://…"}, {"text":"بیشتر","goto":"node_key"}, {"text":"x","callback":"nav:y"} ] } ]
 *
 * فرمت پیام داخلی: $message['carousel'] = همان آرایه. فقط BotReply/Sender اینجا را صدا می‌زنند (dispatch)
 * و هر کانال به شکل خودش می‌فرستد:
 *   - اینستاگرام  → SendShowcase (عکس‌ها آپلود می‌شوند؛ حداکثر ۱۰ کارت، ۳ دکمه)
 *   - چت سایت     → cards ویجت (ChatPlus)
 *   - بقیه        → متن + برای هر کارت یک پیام (عکس + دکمه)
 */
class Carousel
{
    public const MAX_CARDS = 10;
    public const MAX_BTNS  = 3;

    /** ورودی خام (آرایه یا JSON) → کارت‌های تمیز؛ کارت بی‌عنوان حذف می‌شود. */
    public static function normalize($raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) continue;
            $title = trim((string)($c['title'] ?? ''));
            if ($title === '') continue;
            $card = [
                'title'    => mb_substr($title, 0, 80),
                'subtitle' => mb_substr(trim((string)($c['subtitle'] ?? '')), 0, 80),
                'image'    => self::cleanImage((string)($c['image'] ?? '')),
                'buttons'  => [],
            ];
            foreach ((array)($c['buttons'] ?? []) as $b) {
                if (!is_array($b)) continue;
                $t = mb_substr(trim((string)($b['text'] ?? '')), 0, 20);
                if ($t === '') continue;
                if (!empty($b['url']) && preg_match('#^https?://#i', (string)$b['url'])) $card['buttons'][] = ['text' => $t, 'url' => trim((string)$b['url'])];
                elseif (!empty($b['goto']))     $card['buttons'][] = ['text' => $t, 'goto' => trim((string)$b['goto'])];
                elseif (!empty($b['callback'])) $card['buttons'][] = ['text' => $t, 'callback' => trim((string)$b['callback'])];
                if (count($card['buttons']) >= self::MAX_BTNS) break;
            }
            $out[] = $card;
            if (count($out) >= self::MAX_CARDS) break;
        }
        return $out;
    }

    public static function toJson($raw): ?string
    {
        $c = self::normalize($raw);
        return $c ? json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private static function cleanImage(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (preg_match('#^https?://#i', $s)) return $s;
        $s = ltrim(str_replace('\\', '/', $s), '/');
        return preg_match('#^uploads/[A-Za-z0-9_.\-]+$#', $s) ? $s : '';
    }

    /** مسیر فایل روی دیسک برای تصویر محلی (یا null) */
    private static function localPath(string $image): ?string
    {
        if (!preg_match('#^uploads/([A-Za-z0-9_.\-]+)$#', $image, $m)) return null;
        $cfg = $GLOBALS['config'] ?? [];
        $dir = rtrim($cfg['paths']['uploads'] ?? (__DIR__ . '/../uploads/'), '/') . '/';
        $p = $dir . $m[1];
        return is_file($p) ? $p : null;
    }

    /** آدرس عمومی تصویر (برای ویجت سایت) */
    public static function imageUrl(string $image): string
    {
        if ($image === '') return '';
        if (preg_match('#^https?://#i', $image)) return $image;
        $cfg = $GLOBALS['config'] ?? [];
        $base = function_exists('app_base_url') ? app_base_url($cfg) : '';
        return $base !== '' ? $base . '/' . $image : '';
    }

    /** دکمه‌ی ذخیره‌شده → دکمه‌ی پیام داخلی (goto ⇒ nav:KEY) */
    private static function msgButtons(array $btns): array
    {
        $row = [];
        foreach ($btns as $b) {
            if (!empty($b['url'])) $row[] = ['text' => $b['text'], 'url' => $b['url']];
            elseif (!empty($b['goto'])) $row[] = ['text' => $b['text'], 'callback' => 'nav:' . $b['goto']];
            elseif (!empty($b['callback'])) $row[] = ['text' => $b['text'], 'callback' => $b['callback']];
        }
        return $row;
    }

    /** نگاشت به کارت‌های ویجت چت سایت {t,u,img,pf,d,id,b} */
    public static function toWebchatCards(array $cards): array
    {
        $out = [];
        foreach ($cards as $c) {
            $link = ''; $b = [];
            foreach (self::msgButtons($c['buttons'] ?? []) as $x) {
                if (!empty($x['url']) && $link === '') $link = $x['url'];
                $b[] = isset($x['url']) ? ['t' => $x['text'], 'u' => $x['url']] : ['t' => $x['text'], 'c' => $x['callback']];
            }
            $out[] = ['t' => $c['title'], 'u' => $link, 'img' => self::imageUrl($c['image'] ?? ''), 'pf' => '', 'd' => $c['subtitle'] ?? '', 'id' => '', 'b' => $b, 'g' => 1];
        }
        return $out;
    }

    /** متن جایگزین برای ثبت در تاریخچه‌ی گفتگو */
    public static function summary(array $cards): string
    {
        $l = [];
        foreach ($cards as $i => $c) $l[] = ($i + 1) . '. ' . $c['title'] . (!empty($c['subtitle']) ? ' — ' . $c['subtitle'] : '');
        return "🎠 کروسل (" . count($cards) . " کارت):\n" . implode("\n", $l);
    }

    /** عکس را برای آپلود به اینستاگرام (فقط jpg) آماده می‌کند؛ مسیر موقت یا null */
    public static function prepareJpg(string $image, array &$tmpFiles): ?string
    {
        $raw = null;
        $local = self::localPath($image);
        if ($local) $raw = @file_get_contents($local);
        elseif (preg_match('#^https?://#i', $image)) {
            $ch = curl_init($image);
            curl_setopt_array($ch, [CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Elanban/2)', CURLOPT_ENCODING => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5]);
            $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($r !== false && $code === 200) $raw = $r;
        }
        if ($raw === false || $raw === null || strlen($raw) < 200 || strlen($raw) > 12000000) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'ebc'); $tmpFiles[] = $tmp;
        $isJpg = strncmp($raw, "\xFF\xD8", 2) === 0;
        if ($isJpg && strlen($raw) <= 8000000) { $p = $tmp . '.jpg'; file_put_contents($p, $raw); $tmpFiles[] = $p; return $p; }
        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($raw);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im);
                if (max($w, $h) > 1600) {
                    $k = 1600 / max($w, $h); $n = imagecreatetruecolor((int)($w * $k), (int)($h * $k));
                    imagecopyresampled($n, $im, 0, 0, 0, 0, (int)($w * $k), (int)($h * $k), $w, $h); imagedestroy($im); $im = $n;
                }
                $bg = imagecreatetruecolor(imagesx($im), imagesy($im)); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
                $p = $tmp . '.jpg'; imagejpeg($bg, $p, 88); imagedestroy($im); imagedestroy($bg); $tmpFiles[] = $p;
                return $p;
            }
        }
        return null;
    }

    /** ارسال کروسل به اینستاگرام. خروجی: ['ok','error'] */
    public static function sendIg($driver, string $uid, array $cards): array
    {
        if (!method_exists($driver, 'sendShowcase') || !method_exists($driver, 'uploadImage')) return ['ok' => false, 'error' => 'کانال از کروسل پشتیبانی نمی‌کند'];
        $tmp = []; $out = [];
        try {
            foreach ($cards as $c) {
                $card = ['title' => $c['title'], 'subtitle' => $c['subtitle'] ?? ''];
                if (!empty($c['image'])) {
                    $p = self::prepareJpg($c['image'], $tmp);
                    if (!$p) return ['ok' => false, 'error' => 'عکس کارت «' . $c['title'] . '» خوانده نشد'];
                    $up = $driver->uploadImage($p);
                    if (empty($up['ok']) || empty($up['photo'])) return ['ok' => false, 'error' => 'آپلود عکس کارت «' . $c['title'] . '» ناموفق: ' . ($up['error'] ?? '')];
                    $card['photo'] = $up['photo'];
                }
                if (!empty($c['buttons'])) $card['buttons'] = self::msgButtons($c['buttons']);
                $out[] = $card;
            }
        } finally {
            foreach ($tmp as $f) @unlink($f);
        }
        if (!$out) return ['ok' => false, 'error' => 'کارتی برای ارسال نیست'];
        $r = $driver->sendShowcase($uid, $out);
        return ['ok' => !empty($r['ok']), 'error' => $r['error'] ?? null, 'raw' => $r['raw'] ?? null];
    }

    /**
     * نقطه‌ی ورود مشترک: اگر پیام کروسل دارد، مطابق کانال می‌فرستد؛ وگرنه معمولی.
     * $recipient برای driver->send؛ $channel نام کانال (برای تشخیص اینستاگرام/چت سایت).
     */
    public static function dispatch($driver, string $channel, array $recipient, array $message): array
    {
        $cards = self::normalize($message['carousel'] ?? null);
        if (!$cards) { unset($message['carousel']); return $driver->send($recipient, $message); }
        unset($message['carousel']);
        $uid = (string)($recipient['channel_uid'] ?? '');

        if (strpos($channel, 'instagram') === 0) {
            if (trim((string)($message['text'] ?? '')) !== '' || !empty($message['buttons'])) {
                $r0 = $driver->send($recipient, $message);
                if (empty($r0['ok'])) return $r0;
            }
            return self::sendIg($driver, $uid, $cards);
        }
        if ($channel === 'webchat') {
            $message['cards'] = self::toWebchatCards($cards);
            return $driver->send($recipient, $message);
        }
        // کانال‌های دیگر: متن، سپس هر کارت یک پیام
        if (trim((string)($message['text'] ?? '')) !== '') {
            $r0 = $driver->send($recipient, ['text' => $message['text']]);
            if (empty($r0['ok'])) return $r0;
        }
        $last = ['ok' => true];
        foreach ($cards as $c) {
            $m = ['text' => $c['title'] . (!empty($c['subtitle']) ? "\n" . $c['subtitle'] : '')];
            $lp = self::localPath($c['image'] ?? '');
            if ($lp) $m['photo'] = $lp;
            $b = self::msgButtons($c['buttons'] ?? []);
            if ($b) $m['buttons'] = array_map(fn($x) => [$x], $b);
            $last = $driver->send($recipient, $m);
        }
        return $last;
    }
}
