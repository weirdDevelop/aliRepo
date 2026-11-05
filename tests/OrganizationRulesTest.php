<?php
require_once EB_ROOT_DIR . '/src/Organization/Rules.php';
use Eb\Organization\Rules;

// 1 ─ 2 ─ 4
//  └─ 3 ─ 5 ─ 6
$GLOBALS['tree'] = [1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 3, 6 => 5, 7 => null];

test('org: ancestors and descendants', function () {
    eq([5, 3, 1], Rules::ancestors($GLOBALS['tree'], 6));
    eq([], Rules::ancestors($GLOBALS['tree'], 1));
    eq([2, 3, 4, 5, 6], Rules::descendants($GLOBALS['tree'], 1));
    eq([5, 6], Rules::descendants($GLOBALS['tree'], 3));
    eq([], Rules::descendants($GLOBALS['tree'], 7));
});
test('org: moving a unit under itself or a descendant is a cycle; elsewhere is fine', function () {
    ok(Rules::wouldCycle($GLOBALS['tree'], 3, 3));
    ok(Rules::wouldCycle($GLOBALS['tree'], 3, 6), 'under own grandchild');
    ok(Rules::wouldCycle($GLOBALS['tree'], 1, 4));
    ok(!Rules::wouldCycle($GLOBALS['tree'], 3, 2));
    ok(!Rules::wouldCycle($GLOBALS['tree'], 6, 7));
    ok(!Rules::wouldCycle($GLOBALS['tree'], 2, null), 'to root');
});
test('org: walks terminate even on corrupted data that already contains a cycle', function () {
    $bad = [1 => 3, 2 => 1, 3 => 2];   // 1→3→2→1
    eq([3, 2], Rules::ancestors($bad, 1));
    eq([2, 3], Rules::descendants($bad, 1));
});
test('org: inclusive date periods and overlap', function () {
    ok(Rules::overlaps('2026-01-01', '2026-01-31', '2026-01-31', null), 'touching end day overlaps (inclusive)');
    ok(!Rules::overlaps('2026-01-01', '2026-01-30', '2026-01-31', null));
    ok(Rules::overlaps('2026-01-01', null, '2030-05-05', '2030-05-06'));
    ok(Rules::activeOn('2026-01-01', '2026-01-31', '2026-01-31'));
    ok(!Rules::activeOn('2026-01-01', '2026-01-31', '2026-02-01'));
    ok(!Rules::activeOn('2026-02-01', null, '2026-01-31'));
});
test('org: date validation and name normalisation', function () {
    ok(Rules::validDate('2026-02-28')); ok(!Rules::validDate('2026-02-30')); ok(!Rules::validDate('1405/08/10')); ok(!Rules::validDate(null));
    eq(Rules::normName(' واحد  فروش '), Rules::normName('واحد فروش'));
    eq(Rules::normName('پشتيباني'), Rules::normName('پشتیبانی'), 'Arabic yeh vs Persian yeh');
    eq(Rules::normName('بازاریابی‌آنلاین'), Rules::normName('بازاریابی آنلاین'), 'ZWNJ vs space');
});
