<?php
/**
 * core/PhoneNumber.php — the single phone normaliser shared by Elanban and the CRM.
 *
 * Why: three separate normalisers (Persons::normalizePhone, CRM CallSyncManager,
 * CRM Call::normalizeLeadPhone) disagreed — e.g. a Telefonchy call from
 * +989121234567 was stored as "989121234567" while the customer was "09121234567".
 * See docs/discovery/evidence/phone-normalizer-comparison.md.
 *
 * Canonical forms returned by normalize():
 *   Iranian mobile    09XXXXXXXXX      (11 digits)
 *   Iranian landline  0[1-8]XXXXXXXXX  (11 digits incl. area code)
 *   International     +<8..15 digits>  (E.164; only when written with + or 00)
 * Anything else → null. Persian/Arabic-Indic digits and common separators are accepted.
 * Pure functions: no DB, no I/O.
 */
final class PhoneNumber
{
    private const DIGITS = [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ];

    /** Persian/Arabic-Indic digits → ASCII. */
    public static function asciiDigits(string $s): string
    {
        return strtr($s, self::DIGITS);
    }

    /** Scalar input (JSON numbers included) → trimmed ASCII string, or null. */
    private static function str($raw): ?string
    {
        if ($raw === null || is_bool($raw) || is_array($raw) || is_object($raw)) return null;
        if (is_float($raw)) {
            if (!is_finite($raw) || floor($raw) != $raw) return null;
            $raw = sprintf('%.0f', $raw);           // 9124912072.0 → "9124912072", never "9.12E+9"
        }
        $s = trim(self::asciiDigits((string)$raw));
        return $s === '' ? null : $s;
    }

    /**
     * Canonical number, or null if not a recognisable phone number.
     *
     * Order matters and is deliberate: the Iranian parse runs first and is bit-for-bit
     * compatible with the pre-2.1.5 Persons::normalizePhone for mobiles (proven by the
     * differential test in tests/PhoneNumberTest.php), because eb_persons.phone is the
     * identity merge key and existing rows were written with that algorithm.
     * Consequence (kept for compatibility): "+9123456789" is read as an Iranian mobile.
     * Only when the Iranian parse fails and the number was written with + or 00 is it
     * accepted as international (E.164).
     */
    public static function normalize($raw): ?string
    {
        $s = self::str($raw);
        if ($s === null) return null;
        // Only digits and common separators (incl. ZWNJ and bidi marks); free text is not a phone number
        if (preg_match('/[^\d\s\-\+\.\(\)\/\x{200c}\x{200e}\x{200f}\x{202a}-\x{202c}]/u', $s)) return null;
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return null;

        // 1) Iranian national number (legacy-compatible prefix handling)
        $n = $d;
        if (strpos($n, '0098') === 0) $n = substr($n, 4);
        elseif (strpos($n, '098') === 0 && strlen($n) === 13) $n = substr($n, 3);
        if (strpos($n, '98') === 0 && (strlen($n) === 12 || (strlen($n) === 13 && $n[2] === '0'))) $n = substr($n, 2);
        if (strpos($n, '0') === 0) $n = substr($n, 1);
        if (preg_match('/^9\d{9}$/', $n)) return '0' . $n;          // mobile
        if (preg_match('/^[1-8]\d{9}$/', $n)) return '0' . $n;      // landline (area code + number)

        // 2) International, only if explicitly written with + or 00 (never guessed)
        $intl = ($s[0] === '+');
        if (strpos($d, '00') === 0) { $intl = true; $d = substr($d, 2); }
        if (!$intl || strpos($d, '98') === 0) return null;         // +98 that failed the Iranian parse is junk
        return preg_match('/^[1-9]\d{7,14}$/', $d) ? '+' . $d : null;
    }

    /** Iranian mobile in 09XXXXXXXXX form, or null (the Elanban identity merge key). */
    public static function iranMobile($raw): ?string
    {
        $n = self::normalize($raw);
        return ($n !== null && preg_match('/^09\d{9}$/', $n)) ? $n : null;
    }

    /** Iranian mobile or landline (0XXXXXXXXXX), or null. */
    public static function iranNumber($raw): ?string
    {
        $n = self::normalize($raw);
        return ($n !== null && $n[0] === '0') ? $n : null;
    }

    /** 'mobile' | 'landline' | 'intl' | null */
    public static function kind($raw): ?string
    {
        $n = self::normalize($raw);
        if ($n === null) return null;
        if ($n[0] === '+') return 'intl';
        return $n[1] === '9' ? 'mobile' : 'landline';
    }

    /** E.164 (+98…) for web services that require it; never for storage. */
    public static function e164($raw): ?string
    {
        $n = self::normalize($raw);
        if ($n === null) return null;
        return $n[0] === '+' ? $n : '+98' . substr($n, 1);
    }

    /**
     * A party of a phone call (src/dst from telephony). Real numbers are canonicalised;
     * anything else (internal extensions like "101", queue/trunk codes) is kept as its
     * digits so it still matches staff.exten_number. Returns null only for empty input.
     */
    public static function callParty($raw): ?string
    {
        $n = self::normalize($raw);
        if ($n !== null) return $n;
        $s = self::str($raw);
        if ($s === null) return null;
        $d = preg_replace('/\D+/', '', $s);
        return $d === '' ? null : $d;
    }

    /**
     * The value the pre-2.1.5 CRM call sync would have stored for the same input.
     * Used only to keep matching legacy rows (e.g. "989121234567") during de-duplication.
     */
    public static function legacyCallParty($raw): ?string
    {
        $s = self::str($raw);
        if ($s === null) return null;
        $d = preg_replace('/\D+/', '', $s);
        if ($d === '') return null;
        if (strlen($d) === 10 && $d[0] === '9') $d = '0' . $d;
        return $d;
    }
}
