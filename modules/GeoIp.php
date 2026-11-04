<?php
/**
 * modules/GeoIp.php
 * خواننده‌ی خالصِ PHP فایل دیتابیس MaxMind (.mmdb) — بدون composer/vendor،
 * هم‌راستا با بقیه‌ی پروژه که هیچ کتابخانه‌ی بیرونی ندارد (مثل parseUa در Links.php).
 *
 * چرا خودمان نوشتیم و نه `geoip2/geoip2`؟ آن پکیج به composer autoload نیاز دارد
 * و این پروژه از اول composer ندارد — اضافه‌کردنش یعنی یک لایه‌ی وابستگی تازه
 * برای کل نصب، فقط برای یک فایل باینری که ساختارش مستند و ساده است.
 *
 * فرمت فایل (خلاصه‌ی چیزی که پیاده‌سازی شده):
 *   [ درخت جست‌وجوی دودویی ] [16 بایت جداکننده‌ی صفر] [ بخش داده (TLV) ] [ متادیتا ]
 * متادیتا از انتهای فایل با گشتن دنبال امضای «\xab\xcd\xefMaxMind.com» پیدا می‌شود.
 *
 * پشتیبانی: دیتابیس‌های GeoLite2-Country و GeoLite2-City (IPv4 و IPv6-mapped-IPv4).
 * منبع الگوریتم: مستندات عمومی MaxMind DB File Format Specification.
 */

class GeoIp
{
    private $fh;
    private int $fileSize;
    private array $meta;
    private int $treeSizeBytes = 0;
    private int $dataSectionStart = 0; // قبل از خواندن متادیتا صفر است (متادیتا معمولاً pointer ندارد)

    private static ?self $instance = null;
    private static bool $triedLoad = false;

    private function __construct(string $path)
    {
        $this->fh = fopen($path, 'rb');
        if (!$this->fh) throw new RuntimeException('mmdb باز نشد: ' . $path);
        $this->fileSize = filesize($path);
        $this->meta = $this->readMetadata();
        $this->treeSizeBytes = (int)$this->meta['node_count'] * ((int)$this->meta['record_size'] * 2) >> 3;
        $this->dataSectionStart = $this->treeSizeBytes + 16; // ۱۶ بایت جداکننده
    }

    public function __destruct()
    {
        if ($this->fh) fclose($this->fh);
    }

    /**
     * نمونه‌ی سراسری بر اساس مسیر تنظیم‌شده در eb_settings (analytics.geoip_path)
     * یا مسیر پیش‌فرض data/GeoLite2-City.mmdb. اگر فایل نبود، null برمی‌گردد
     * (سکوت — GeoIP اختیاری است، نبودش نباید کل آنالیتیکس را بترکاند).
     */
    public static function instance(): ?self
    {
        if (self::$instance !== null) return self::$instance;
        if (self::$triedLoad) return null; // یک‌بار امتحان کافی است (هزینه‌ی باز کردن فایل)
        self::$triedLoad = true;
        $path = self::resolvePath();
        if (!$path || !is_readable($path)) return null;
        try {
            self::$instance = new self($path);
        } catch (Throwable $e) {
            self::$instance = null;
        }
        return self::$instance;
    }

    public static function resolvePath(): string
    {
        try {
            $row = Database::one("SELECT v FROM eb_settings WHERE k='analytics.geoip_path'");
            if (!empty($row['v'])) return $row['v'];
        } catch (Throwable $e) { /* جدول ممکن است نبود */ }
        return dirname(__DIR__) . '/data/GeoLite2-City.mmdb';
    }

    public static function isAvailable(): bool
    {
        return self::instance() !== null;
    }

    /**
     * جست‌وجوی IP. اول تلاش با فایل MaxMind .mmdb (اگر دستی نصب شده باشد —
     * دقیق‌تر، تا سطح شهر). اگر آن فایل نبود، خودکار به دیتابیس کشورِ همراهِ
     * پروژه (GeoIpCountry) سقوط می‌کند — یعنی از همان لحظه‌ی نصب، بدون هیچ
     * تنظیم دستی، حداقل کشور درست نمایش داده می‌شود (رفع مشکل «نامعلوم»).
     * خروجی: ['country_code'=>'IR','country'=>'ایران','city'=>'Tehran'] یا null.
     */
    public static function lookup(?string $ip): ?array
    {
        if (!$ip) return null;
        $inst = self::instance();
        if ($inst) {
            try {
                $r = $inst->find($ip);
                if ($r) return $r;
            } catch (Throwable $e) { /* سقوط به دیتابیس محلی پایین */ }
        }
        if (class_exists('GeoIpCountry')) {
            return GeoIpCountry::lookup($ip);
        }
        return null;
    }

    // ==================== هسته‌ی خواندن ====================

    private function readAt(int $offset, int $len): string
    {
        fseek($this->fh, $offset);
        $s = fread($this->fh, $len);
        return $s === false ? '' : $s;
    }

    /** پیدا کردن بلوک متادیتا از انتهای فایل (حداکثر در ۱۲۸ کیلوبایت آخر) */
    private function readMetadata(): array
    {
        $marker = "\xab\xcd\xef" . "MaxMind.com";
        $searchLen = min($this->fileSize, 131072 + strlen($marker));
        $chunk = $this->readAt($this->fileSize - $searchLen, $searchLen);
        $pos = strrpos($chunk, $marker);
        if ($pos === false) throw new RuntimeException('امضای متادیتای mmdb یافت نشد — فایل معتبر نیست');
        $metaOffsetInFile = ($this->fileSize - $searchLen) + $pos + strlen($marker);
        [$decoded, ] = $this->decode($metaOffsetInFile);
        if (!is_array($decoded)) throw new RuntimeException('متادیتای mmdb نامعتبر است');
        return $decoded;
    }

    private function startNode(int $bitLength): int
    {
        // برای IPv4 داخل دیتابیس IPv6-capable باید از نودی شروع کنیم که با
        // ۹۶ بیت صفر از ریشه به آن رسیده‌ایم (چون IPv4 زیر پیشوند ::/96 نشسته).
        if ((int)$this->meta['ip_version'] !== 6 || $bitLength === 128) return 0;
        $node = 0;
        for ($i = 0; $i < 96; $i++) {
            $node = $this->readNode($node, 0);
        }
        return $node;
    }

    /** خواندن یک رکورد (چپ=0 یا راست=1) از نود شماره‌ی $node */
    private function readNode(int $node, int $index): int
    {
        $recordSize = (int)$this->meta['record_size'];
        $baseOffset = $node * ($recordSize * 2 / 8);

        if ($recordSize === 24) {
            $bytes = $this->readAt($baseOffset + $index * 3, 3);
            return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
        }
        if ($recordSize === 28) {
            // چیدمان ویژه‌ی ۲۸ بیتی: یک بایت میانی بین دو رکورد ۲۴بیتی مشترک است
            $middle = ord($this->readAt($baseOffset + 3, 1));
            if ($index === 0) {
                $bytes = $this->readAt($baseOffset, 3);
                $nibble = ($middle >> 4) & 0x0F;
                return ($nibble << 24) | (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
            }
            $bytes = $this->readAt($baseOffset + 4, 3);
            $nibble = $middle & 0x0F;
            return ($nibble << 24) | (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
        }
        if ($recordSize === 32) {
            $bytes = $this->readAt($baseOffset + $index * 4, 4);
            return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
        }
        throw new RuntimeException('record_size پشتیبانی‌نشده: ' . $recordSize);
    }

    private function find(string $ip): ?array
    {
        $bin = @inet_pton($ip);
        if ($bin === false) return null;
        $bits = '';
        foreach (str_split($bin) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $bitLength = strlen($bits) === 4 * 8 ? 32 : strlen($bits); // IPv4 پارس‌شده ۴ بایتی است

        $nodeCount = (int)$this->meta['node_count'];
        $node = $this->startNode($bitLength);

        for ($i = 0; $i < $bitLength; $i++) {
            if ($node >= $nodeCount) break; // به داده یا «یافت‌نشد» رسیدیم
            $bit = (int)$bits[$i];
            $node = $this->readNode($node, $bit);
        }

        if ($node <= $nodeCount) return null; // == یعنی صریحاً «رکوردی نیست»؛ کوچک‌تر یعنی خطا

        // نکته‌ی مهم (با فایل تستِ رسمی MaxMind اعتبارسنجی شد): با این‌که بایت
        // خام «۰» بخش داده در آفستِ (treeSizeBytes + 16) شروع می‌شود، فرمول درستِ
        // رزولوشن pointer درخت **بدون** آن ۱۶ بایت جداکننده است — یعنی
        // absolute = treeSizeBytes + (node - node_count)، نه treeSizeBytes+16+....
        // اضافه‌کردن آن ۱۶ باعث می‌شد همیشه دقیقاً ۱۶ بایت جلوتر از رکورد واقعی
        // بخوانیم (تست شد با GeoIP2-City-Test.mmdb رسمی: IPهای ژاپن/کره/سنگاپور/
        // استرالیا و anycast، هر پنج مورد قبل از این اصلاح غلط بودند).
        [$record, ] = $this->decode($this->treeSizeBytes + ($node - $nodeCount));

        return $this->extract($record);
    }

    /** استخراج فیلدهای موردنیاز ما از رکورد کامل City/Country */
    private function extract($record): ?array
    {
        if (!is_array($record)) return null;
        $countryCode = $record['country']['iso_code'] ?? ($record['registered_country']['iso_code'] ?? null);
        if (!$countryCode) return null;
        // نام فارسی از همان جدول مشترک با GeoIpCountry می‌آید — چون خودِ mmdb
        // لوکیل فارسی ندارد (لیست لوکیل‌های MaxMind: en/de/es/fr/ja/pt-BR/ru/zh-CN).
        $countryName = class_exists('GeoIpCountry')
            ? GeoIpCountry::nameFa($countryCode)
            : ($record['country']['names']['en'] ?? $countryCode);
        return [
            'country_code' => $countryCode,
            'country'      => $countryName,
            'city'         => $record['city']['names']['en'] ?? null,
        ];
    }

    // ==================== دیکودر عمومی TLV (بخش داده) ====================

    /** @return array{0: mixed, 1: int} مقدار دیکودشده و آفست بعد از آن */
    private function decode(int $offset)
    {
        $ctrl = ord($this->readAt($offset, 1));
        $offset++;
        $type = $ctrl >> 5;

        if ($type === 0) { // نوع «گسترده» — بایت بعدی + ۷
            $extra = ord($this->readAt($offset, 1));
            $offset++;
            $type = 7 + $extra;
        }

        if ($type === 1) { // pointer
            return $this->decodePointer($ctrl, $offset);
        }

        [$size, $offset] = $this->readSize($ctrl, $offset);

        switch ($type) {
            case 2: // utf8_string
                $v = $this->readAt($offset, $size);
                return [$v, $offset + $size];
            case 3: // double (8 بایت، big-endian)
                $v = unpack('E', $this->readAt($offset, 8))[1] ?? 0.0;
                return [$v, $offset + 8];
            case 4: // bytes
                return [$this->readAt($offset, $size), $offset + $size];
            case 5: // uint16
                return [$this->readUint($offset, $size), $offset + $size];
            case 6: // uint32
                return [$this->readUint($offset, $size), $offset + $size];
            case 8: // int32
                $u = $this->readUint($offset, $size);
                if ($size === 4 && $u > 0x7FFFFFFF) $u -= 0x100000000;
                return [$u, $offset + $size];
            case 9: // uint64
            case 10: // uint128 (برای اهداف ما لازم نیست دقیق باشد؛ به‌صورت رشته/عدد بزرگ برمی‌گردانیم)
                return [$this->readUint($offset, $size), $offset + $size];
            case 7: // map
                $out = [];
                $cur = $offset;
                for ($i = 0; $i < $size; $i++) {
                    [$k, $cur] = $this->decode($cur);
                    [$v, $cur] = $this->decode($cur);
                    if (is_array($k)) continue; // دفاعی: کلید نامعتبر را نادیده بگیر، کل رکورد را خراب نکن
                    $out[$k] = $v;
                }
                return [$out, $cur];
            case 11: // array
                $out = [];
                $cur = $offset;
                for ($i = 0; $i < $size; $i++) {
                    [$v, $cur] = $this->decode($cur);
                    $out[] = $v;
                }
                return [$out, $cur];
            case 14: // boolean — مقدار در خودِ size است، بایتی مصرف نمی‌شود
                return [$size !== 0, $offset];
            case 15: // float (4 بایت، big-endian)
                $v = unpack('G', $this->readAt($offset, 4))[1] ?? 0.0;
                return [$v, $offset + 4];
            case 13: // end marker / data cache container — نادیده می‌گیریم
                return [null, $offset + $size];
            default:
                return [null, $offset + $size];
        }
    }

    private function readUint(int $offset, int $size): int
    {
        if ($size === 0) return 0;
        $bytes = $this->readAt($offset, $size);
        $v = 0;
        for ($i = 0; $i < $size; $i++) { $v = ($v << 8) | ord($bytes[$i]); }
        return $v;
    }

    /** اندازه‌ی payload از ۵ بیت پایینِ کنترل‌بایت (با حالت‌های گسترش‌یافته) */
    private function readSize(int $ctrl, int $offset): array
    {
        $size = $ctrl & 0x1F;
        if ($size < 29) return [$size, $offset];
        if ($size === 29) {
            $extra = ord($this->readAt($offset, 1));
            return [29 + $extra, $offset + 1];
        }
        if ($size === 30) {
            $b = $this->readAt($offset, 2);
            $extra = (ord($b[0]) << 8) | ord($b[1]);
            return [285 + $extra, $offset + 2];
        }
        $b = $this->readAt($offset, 3);
        $extra = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
        return [65821 + $extra, $offset + 3];
    }

    /** رمزگشایی pointer طبق مشخصات (۴ حالت اندازه با مقدار پایه‌ی متفاوت) */
    private function decodePointer(int $ctrl, int $offset): array
    {
        $sizeFlag = ($ctrl & 0x18) >> 3; // بیت‌های ۳ و ۴
        $partial = $ctrl & 0x07;

        if ($sizeFlag === 0) {
            $b = $this->readAt($offset, 1);
            $ptr = ($partial << 8) | ord($b);
            $newOffset = $offset + 1;
        } elseif ($sizeFlag === 1) {
            $b = $this->readAt($offset, 2);
            $ptr = ($partial << 16) | (ord($b[0]) << 8) | ord($b[1]);
            $ptr += 2048;
            $newOffset = $offset + 2;
        } elseif ($sizeFlag === 2) {
            $b = $this->readAt($offset, 3);
            $ptr = ($partial << 24) | (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
            $ptr += 526336;
            $newOffset = $offset + 3;
        } else {
            $b = $this->readAt($offset, 4);
            $ptr = (ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3]);
            $newOffset = $offset + 4;
        }

        // pointer به آفستِ مطلقِ داخل «بخش داده» اشاره می‌کند، نه کل فایل —
        // پس باید نسبت به شروع بخش داده جمع بزنیم. اما در فایل ما همه‌چیز را
        // با آفست مطلق فایل کار می‌کنیم، پس این‌جا هم به همان شکل جمع می‌زنیم.
        // نکته: در متادیتا (که قبل از دانستنِ dataSectionStart صدا زده می‌شود)
        // این مقدار معنایی ندارد و متادیتا معمولاً pointer ندارد؛ برای بخش داده
        // dataSectionStart از قبل مقداردهی شده است.
        $absolute = ($this->dataSectionStart ?? 0) + $ptr;
        [$v, ] = $this->decode($absolute);
        return [$v, $newOffset];
    }
}
