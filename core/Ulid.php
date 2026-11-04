<?php
/**
 * core/Ulid.php — 26-char sortable unique ids (Crockford base32: 48-bit ms time + 80-bit random).
 * Used as public ids and event ids (ADR-008, docs/architecture/data-ownership.md §4). Pure.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $ms = null): string
    {
        $ms = $ms ?? (int)floor(microtime(true) * 1000);
        $time = '';
        for ($i = 9; $i >= 0; $i--) {           // 10 chars × 5 bits = 50 bits ≥ 48-bit time
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $rand = '';
        $bytes = random_bytes(10);               // 80 bits → 16 chars
        $bits = '';
        foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        foreach (str_split($bits, 5) as $chunk) $rand .= self::ALPHABET[bindec($chunk)];
        return $time . $rand;
    }

    public static function isValid(string $s): bool
    {
        return (bool)preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $s);
    }
}
