<?php
/**
 * جاکجاست - کلاس تبدیل تاریخ جلالی (شمسی)
 * Jakojast - Jalali Date Converter
 *
 * پیاده‌سازی الگوریتم تبدیل میلادی ↔ شمسی بدون وابستگی به ext-calendar.
 * مرجع: الگوریتم Bills / Birashk
 */

class Jalali
{
    private static $jDaysInMonth = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    /**
     * تبدیل تاریخ میلادی به شمسی
     *
     * @param int $gy  سال میلادی (۴ رقم)
     * @param int $gm  ماه میلادی (۱-۱۲)
     * @param int $gd  روز میلادی (۱-۳۱)
     * @return array   [jy, jm, jd]
     */
    public static function toJalali($gy, $gm, $gd)
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + (intval(($gy2 + 3) / 4)) - (intval(($gy2 + 99) / 100)) + (intval(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * (intval($days / 12053));
        $days %= 12053;
        $jy += 4 * (intval($days / 1461));
        $days %= 1461;
        if ($days > 365) {
            $jy += intval(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $jm = ($days < 186) ? 1 + intval($days / 31) : 7 + intval(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return [$jy, $jm, $jd];
    }

    /**
     * تبدیل تاریخ شمسی به میلادی
     *
     * @param int $jy  سال شمسی
     * @param int $jm  ماه شمسی (۱-۱۲)
     * @param int $jd  روز شمسی (۱-۳۱)
     * @return array   [gy, gm, gd]
     */
    public static function toGregorian($jy, $jm, $jd)
    {
        $gy = ($jy <= 979) ? 621 : 1600;
        $jy -= ($jy <= 979) ? 0 : 979;
        $days = (365 * $jy) + (intval($jy / 33) * 8) + (intval(($jy % 33 + 3) / 4)) + 78 + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy += 400 * (intval($days / 146097));
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * (intval(--$days / 36524));
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * (intval($days / 1461));
        $days %= 1461;
        if ($days > 365) {
            $gy += intval(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /**
     * تبدیل رشته میلادی (Y-m-d H:i:s یا Y-m-d) به شمسی (Y/m/d H:i:s)
     *
     * @param string $datetime
     * @param string $format   پیش‌فرض 'Y/m/d H:i'  (مقادیر مجاز: Y, m, d, H, i, s)
     * @return string
     */
    public static function fromGregorian($datetime, $format = 'Y/m/d H:i')
    {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00' || $datetime === '0000-00-00') {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false) return '-';

        $gy = (int)date('Y', $ts);
        $gm = (int)date('m', $ts);
        $gd = (int)date('d', $ts);
        $H  = date('H', $ts);
        $i  = date('i', $ts);
        $s  = date('s', $ts);

        list($jy, $jm, $jd) = self::toJalali($gy, $gm, $gd);

        // جایگزینی فرمت
        $out = $format;
        $out = str_replace('Y', $jy, $out);
        $out = str_replace('m', str_pad($jm, 2, '0', STR_PAD_LEFT), $out);
        $out = str_replace('d', str_pad($jd, 2, '0', STR_PAD_LEFT), $out);
        $out = str_replace('H', $H, $out);
        $out = str_replace('i', $i, $out);
        $out = str_replace('s', $s, $out);
        return $out;
    }

    /**
     * تبدیل رشته شمسی به میلادی (Y-m-d H:i:s)
     *
     * @param string $jdate   به فرمت "1403/05/15" یا "1403/05/15 14:30"
     * @return string         (Y-m-d H:i:s) یا null در صورت خطا
     */
    public static function toGregorianString($jdate)
    {
        $jdate = trim($jdate);
        if ($jdate === '' || $jdate === '-') return null;

        // تبدیل اعداد فارسی به انگلیسی
        $jdate = Helpers::toEnglishDigits($jdate);

        // جدا کردن تاریخ و زمان
        $parts = explode(' ', $jdate);
        $datePart = $parts[0];
        $timePart = $parts[1] ?? '00:00:00';

        // اگر زمان فقط ساعت:دقیقه است، ثانیه اضافه شود
        if (substr_count($timePart, ':') === 1) {
            $timePart .= ':00';
        }

        // تجزیه تاریخ شمسی
        $dp = preg_split('/[\/\-.]/', $datePart);
        if (count($dp) !== 3) return null;

        $jy = (int)$dp[0];
        $jm = (int)$dp[1];
        $jd = (int)$dp[2];

        if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }

        list($gy, $gm, $gd) = self::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02d %s', $gy, $gm, $gd, $timePart);
    }

    /**
     * نام ماه شمسی
     * @param int $month  (1-12)
     * @return string
     */
    public static function monthName($month)
    {
        $names = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
            4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
            7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
            10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
        ];
        return $names[$month] ?? '?';
    }

    /**
     * نام روز هفته
     * @param string $datetime  (تاریخ میلادی)
     * @return string
     */
    public static function dayName($datetime)
    {
        if (empty($datetime)) return '-';
        $ts = strtotime($datetime);
        if ($ts === false) return '-';
        $names = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
        return $names[date('w', $ts)];
    }

    /**
     * تاریخ و زمان فعلی به شمسی
     * @param string $format
     * @return string
     */
    public static function now($format = 'Y/m/d H:i')
    {
        return self::fromGregorian(date('Y-m-d H:i:s'), $format);
    }

    /**
     * تاریخ امروز به شمسی (بدون زمان)
     * @return string
     */
    public static function today()
    {
        return self::fromGregorian(date('Y-m-d'), 'Y/m/d');
    }

    /**
     * تعداد روزهای یک ماه شمسی
     * @param int $jy
     * @param int $jm
     * @return int
     */
    public static function daysInMonth($jy, $jm)
    {
        if ($jm <= 6) return 31;
        if ($jm <= 11) return 30;
        // اسفند: کبیسه؟
        return self::isLeapYear($jy) ? 30 : 29;
    }

    /**
     * آیا سال شمسی کبیسه است؟
     * @param int $jy
     * @return bool
     */
    public static function isLeapYear($jy)
    {
        $breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
        $jp = $breaks[0];
        $jump = 0;
        for ($i = 1; $i < count($breaks); $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) break;
            $jp = $jm;
        }
        $n = $jy - $jp;
        if ($n < $jump) {
            if ($n % 33 === 0) return true;
            return false;
        }
        // الگوریتم ساده‌شده
        $leap = ($jy % 33 === 1 || $jy % 33 === 5 || $jy % 33 === 9 ||
                 $jy % 33 === 13 || $jy % 33 === 17 || $jy % 33 === 22 ||
                 $jy % 33 === 26 || $jy % 33 === 30);
        return $leap;
    }
}
