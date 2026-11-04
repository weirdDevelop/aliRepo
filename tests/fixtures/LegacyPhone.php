<?php
/**
 * FROZEN copies of the pre-2.1.5 phone normalisers, extracted verbatim from baseline commit 30d3577.
 * Used only by tests to prove PhoneNumber keeps legacy behaviour where it must. Do not edit.
 */
final class LegacyPersons {
    public static function asciiDigits(string $s): string
    {
        return strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }
    public static function normalizePhone($raw): ?string
    {
        if ($raw === null) return null;
        $s = trim(self::asciiDigits((string) $raw));
        if ($s === '') return null;
        // فقط رقم و جداکننده‌های رایج؛ متن آزاد («شماره‌ام ۰۹۱۲…») با extractPhone خوانده می‌شود
        if (preg_match('/[^\d\s\-\+\.\(\)\/\x{200c}\x{200e}\x{200f}\x{202a}-\x{202c}]/u', $s)) return null;
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return null;
        if (strpos($d, '0098') === 0) $d = substr($d, 4);
        elseif (strpos($d, '098') === 0 && strlen($d) === 13) $d = substr($d, 3);
        if (strpos($d, '98') === 0 && (strlen($d) === 12 || (strlen($d) === 13 && $d[2] === '0'))) $d = substr($d, 2);
        if (strpos($d, '0') === 0) $d = substr($d, 1);
        if (!preg_match('/^9\d{9}$/', $d)) return null;
        return '0' . $d;
    }
}
final class LegacyCallSync {
    public static function normalizeIranianMobile($number)
    {
        if ($number === null || $number === '') return null;
        $digits = preg_replace('/\D+/', '', (string)$number);
        if ($digits === '') return null;
        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '0' . $digits;
        }
        return $digits;
    }
}
final class LegacyCall {
    public static function normalizeLeadPhone($number)
    {
        $d = preg_replace('/\D+/', '', (string)$number);
        if (strpos($d, '0098') === 0) $d = '0' . substr($d, 4);
        elseif (strlen($d) === 12 && strpos($d, '98') === 0) $d = '0' . substr($d, 2);
        elseif (strlen($d) === 10 && $d[0] !== '0') $d = '0' . $d;
        return preg_match('/^0[1-9]\d{9}$/', $d) ? $d : null;
    }
}
