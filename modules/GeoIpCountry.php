<?php
/**
 * modules/GeoIpCountry.php
 * جست‌وجوی کشور از روی IP — بدون نیاز به حساب MaxMind یا دانلود دستی.
 *
 * چرا این ماژول اضافه شد: خواننده‌ی GeoIp.php (فایل .mmdb) دقیق و کاملاً
 * تست‌شده است، ولی نیاز به این دارد که خودِ کاربر یک حساب رایگان MaxMind
 * بسازد و فایل را دستی دانلود/آپلود کند — همان چیزی که باعث می‌شد ستون
 * جغرافیا در نصب‌های تازه «نامعلوم» بماند (چون آن مرحله انجام نشده بود).
 *
 * این ماژول به‌جایش از یک دیتابیس کشور از پیش آماده و همراهِ خودِ پروژه
 * استفاده می‌کند (`data/geoip-country-ipv4.bin` و `...-ipv6.bin`) — یعنی از
 * همون لحظه‌ی نصب، بدون هیچ تنظیم اضافه، کشور کار می‌کند. داده از پروژه‌ی
 * متن‌باز sapics/ip-location-db گرفته شده (که خودش بازتوزیع GeoLite2 با مجوز
 * MaxMind است؛ فایل‌های مجوز MaxMind در همین پوشه‌ی data/ موجودند).
 *
 * دقت این دیتابیس در حد کشور است (نه شهر). برای شهر، اگر بعداً GeoLite2-City
 * را دستی نصب کنی، GeoIp.php خودش اولویت را می‌گیرد (نگاه کن به
 * GeoIp::lookup که این دو را با هم ترکیب می‌کند).
 *
 * فرمت فایل باینری (تولیدشده از CSV با یک اسکریپت یک‌باره):
 *   هر رکورد IPv4: ۴ بایت شروع + ۴ بایت پایان + ۲ بایت کد کشور = ۱۰ بایت
 *   هر رکورد IPv6: ۱۶ بایت شروع + ۱۶ بایت پایان + ۲ بایت کد کشور = ۳۴ بایت
 * رکوردها از قبل بر اساس IP شروع مرتب‌اند (خروجی طبیعی منبع) — جست‌وجوی
 * دودویی مستقیم روی فایل با fseek، بدون بارگذاری کل فایل در حافظه.
 */

class GeoIpCountry
{
    private const V4_RECORD_SIZE = 10; // 4+4+2
    private const V6_RECORD_SIZE = 34; // 16+16+2

    private static ?array $namesFa = null;

    public static function lookup(?string $ip): ?array
    {
        if (!$ip) return null;
        $isV6 = strpos($ip, ':') !== false;
        $bin = @inet_pton($ip);
        if ($bin === false) return null;

        $path = dirname(__DIR__) . '/data/geoip-country-' . ($isV6 ? 'ipv6' : 'ipv4') . '.bin';
        if (!is_readable($path)) return null;

        $recordSize = $isV6 ? self::V6_RECORD_SIZE : self::V4_RECORD_SIZE;
        $halfSize = $isV6 ? 16 : 4;

        $fh = @fopen($path, 'rb');
        if (!$fh) return null;
        try {
            $fileSize = filesize($path);
            $count = intdiv($fileSize, $recordSize);
            $lo = 0; $hi = $count - 1;
            $cc = null;

            while ($lo <= $hi) {
                $mid = intdiv($lo + $hi, 2);
                fseek($fh, $mid * $recordSize);
                $rec = fread($fh, $recordSize);
                if ($rec === false || strlen($rec) < $recordSize) break;
                $start = substr($rec, 0, $halfSize);
                $end = substr($rec, $halfSize, $halfSize);

                if (strcmp($bin, $start) < 0) {
                    $hi = $mid - 1;
                } elseif (strcmp($bin, $end) > 0) {
                    $lo = $mid + 1;
                } else {
                    $cc = trim(substr($rec, $halfSize * 2, 2));
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        if (!$cc) return null;
        return ['country_code' => $cc, 'country' => self::nameFa($cc), 'city' => null];
    }

    public static function nameFa(string $code): string
    {
        if (self::$namesFa === null) {
            $file = dirname(__DIR__) . '/data/country-names-fa.php';
            self::$namesFa = is_file($file) ? (require $file) : [];
        }
        return self::$namesFa[$code] ?? $code;
    }

    public static function isAvailable(): bool
    {
        return is_readable(dirname(__DIR__) . '/data/geoip-country-ipv4.bin');
    }
}
