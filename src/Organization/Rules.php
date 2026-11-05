<?php
namespace Eb\Organization;

/**
 * Pure organisation rules (2.4.0): tree walks that can never loop, cycle prevention, and
 * inclusive date periods ([from, to], to = null means open-ended). No DB.
 */
final class Rules
{
    const TYPES = ['division', 'department', 'team', 'unit'];
    const MEMBER_ROLES = ['member', 'manager', 'deputy'];

    /** @param array<int,?int> $parentOf unit id => parent id */
    public static function ancestors(array $parentOf, int $id): array
    {
        $out = []; $seen = [$id => true];
        $p = $parentOf[$id] ?? null;
        while ($p !== null && !isset($seen[$p])) {
            $out[] = $p; $seen[$p] = true;
            $p = $parentOf[$p] ?? null;
        }
        return $out;
    }

    /** @param array<int,?int> $parentOf */
    public static function descendants(array $parentOf, int $id): array
    {
        $kids = [];
        foreach ($parentOf as $u => $p) if ($p !== null) $kids[$p][] = $u;
        $out = []; $seen = [$id => true]; $stack = $kids[$id] ?? [];
        while ($stack) {
            $c = array_pop($stack);
            if (isset($seen[$c])) continue;
            $seen[$c] = true; $out[] = $c;
            foreach ($kids[$c] ?? [] as $k) $stack[] = $k;
        }
        sort($out);
        return $out;
    }

    /** Would putting $unit under $newParent create a cycle? */
    public static function wouldCycle(array $parentOf, int $unit, ?int $newParent): bool
    {
        if ($newParent === null) return false;
        if ($newParent === $unit) return true;
        return in_array($newParent, self::descendants($parentOf, $unit), true);
    }

    public static function validDate(?string $d): bool
    {
        if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y);
    }

    /** Inclusive periods; null end = open. */
    public static function overlaps(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return ($aTo === null || $bFrom <= $aTo) && ($bTo === null || $aFrom <= $bTo);
    }

    public static function activeOn(string $from, ?string $to, string $date): bool
    {
        return $from <= $date && ($to === null || $to >= $date);
    }

    /** Name normalisation for duplicate detection on import (Arabic/Persian letter variants, spaces, case). */
    public static function normName(string $s): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\u{200c}" => ' ', 'ۀ' => 'ه']);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        return mb_strtolower($s);
    }
}
