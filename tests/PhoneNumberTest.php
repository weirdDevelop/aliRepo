<?php
require_once EB_ROOT_DIR . '/core/PhoneNumber.php';
require_once __DIR__ . '/fixtures/LegacyPhone.php';

test('phone: Iranian mobile forms all map to 09…', function () {
    foreach (['09121234567', '9121234567', '+989121234567', '00989121234567', '989121234567',
              '+98 912 123 4567', '0912-123-4567', '(0912) 123 4567', '۰۹۱۲۱۲۳۴۵۶۷', '+۹۸۹۱۲۱۲۳۴۵۶۷', '٠٩١٢١٢٣٤٥٦٧'] as $in) {
        eq('09121234567', PhoneNumber::normalize($in), $in);
        eq('09121234567', PhoneNumber::iranMobile($in), $in);
    }
});
test('phone: JSON numbers from Telefonchy keep the leading zero', function () {
    eq('09124912072', PhoneNumber::normalize(9124912072));
    eq('09124912072', PhoneNumber::normalize(9124912072.0));
    eq('09124912072', PhoneNumber::callParty(9124912072));
});
test('phone: landlines are Iranian numbers but not mobiles', function () {
    eq('02188776655', PhoneNumber::normalize('021-88776655'));
    eq('02188776655', PhoneNumber::normalize('+982188776655'));
    eq(null, PhoneNumber::iranMobile('02188776655'));
    eq('landline', PhoneNumber::kind('02188776655'));
});
test('phone: international only when written with + or 00', function () {
    eq('+14155552671', PhoneNumber::normalize('+1 415 555 2671'));
    eq('+14155552671', PhoneNumber::normalize('0014155552671'));
    eq(null, PhoneNumber::normalize('14155552671'));      // never guessed
    eq('intl', PhoneNumber::kind('+14155552671'));
    eq(null, PhoneNumber::iranNumber('+14155552671'));
});
test('phone: junk and free text are rejected', function () {
    foreach (['', '   ', '12345', 'abc', 'my number is 0912…', '+98123', null, true, [], 1.5] as $in)
        eq(null, PhoneNumber::normalize($in), var_export($in, true));
});
test('phone: e164 for web services', function () {
    eq('+989121234567', PhoneNumber::e164('09121234567'));
    eq('+14155552671', PhoneNumber::e164('+14155552671'));
});
test('phone: call parties keep extensions and codes', function () {
    eq('101', PhoneNumber::callParty('101'));
    eq('101', PhoneNumber::callParty(101));
    eq('09121234567', PhoneNumber::callParty('+989121234567'));   // was "989121234567" before 2.1.5
    eq('09121234567', PhoneNumber::callParty('۰۹۱۲۱۲۳۴۵۶۷'));     // was NULL before 2.1.5
    eq(null, PhoneNumber::callParty(''));
    eq(null, PhoneNumber::callParty(null));
});
test('phone: legacyCallParty reproduces the old CRM sync value', function () {
    foreach (['+989121234567', '00989121234567', '9121234567', '101', '02188776655'] as $in)
        eq(LegacyCallSync::normalizeIranianMobile($in), PhoneNumber::legacyCallParty($in), $in);
});

/** Seeded fuzz shared by the differential tests */
function phone_fuzz_inputs(int $n): array {
    mt_srand(20261104);
    $pre = ['', '+', '00', '0', '98', '+98', '0098', '098', '+980', '980', '9800'];
    $sep = ['', '-', ' ', '.', '(', ')', "\u{200c}"];
    $fa = ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'];
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        switch (mt_rand(0, 5)) {
            case 0: $b = '9' . mt_rand(100000000, 999999999); break;
            case 1: $b = '2' . mt_rand(100000000, 999999999); break;
            case 2: $b = (string)mt_rand(1, 99999); break;
            case 3: $b = '1' . mt_rand(1000000000, 9999999999); break;
            case 4: $b = '09' . mt_rand(100000000, 999999999); break;
            default: $b = (string)mt_rand(10000000, 999999999999);
        }
        $s = $pre[array_rand($pre)] . $b;
        if (strlen($s) > 1 && mt_rand(0, 3) === 0) { $p = mt_rand(1, strlen($s) - 1); $s = substr($s, 0, $p) . $sep[array_rand($sep)] . substr($s, $p); }
        if (mt_rand(0, 9) === 0) $s = strtr($s, $fa);
        $out[] = $s;
    }
    return $out;
}
test('phone: differential — iranMobile is identical to legacy Persons::normalizePhone (merge key)', function () {
    $diff = 0; $first = '';
    foreach (phone_fuzz_inputs(40000) as $s)
        if (LegacyPersons::normalizePhone($s) !== PhoneNumber::iranMobile($s)) { $diff++; $first = $first ?: $s; }
    eq(0, $diff, "first differing input: $first");
});
test('phone: differential — iranNumber never changes a value legacy lead conversion accepted', function () {
    $bad = 0; $first = '';
    foreach (phone_fuzz_inputs(40000) as $s) {
        $old = LegacyCall::normalizeLeadPhone($s);
        if ($old !== null && $old !== PhoneNumber::iranNumber($s)) { $bad++; $first = $first ?: $s; }
    }
    eq(0, $bad, "first regressing input: $first");
});
