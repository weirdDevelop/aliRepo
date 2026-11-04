<?php
/** R-13 end-to-end: real login form, real Set-Cookie headers. */
function sess_headers(string $url, string $cookie = ''): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => $cookie ? ['Cookie: ' . $cookie] : []]);
    $r = (string)curl_exec($ch); curl_close($ch);
    return substr($r, 0, strpos($r, "\r\n\r\n") ?: strlen($r));
}
it('session: cookie is HttpOnly and SameSite=Lax', function (Sandbox $sb) {
    $h = sess_headers($sb->url . '/index.php?page=login');
    ok(preg_match('/^Set-Cookie: PHPSESSID=[^\r\n]*HttpOnly/mi', $h) === 1, "no HttpOnly:\n$h");
    ok(preg_match('/^Set-Cookie: PHPSESSID=[^\r\n]*SameSite=Lax/mi', $h) === 1, "no SameSite:\n$h");
});
it('session: strict mode — an attacker-chosen session id is not adopted (session fixation)', function (Sandbox $sb) {
    $h = sess_headers($sb->url . '/index.php?page=login', 'PHPSESSID=attackerchosenid1234567890');
    ok(preg_match('/^Set-Cookie: PHPSESSID=([^;\r\n]+)/mi', $h, $m) === 1, "no new cookie issued:\n$h");
    ok($m[1] !== 'attackerchosenid1234567890', 'forged id adopted');
});
it('login: 5 wrong passwords lock the account for this IP — even the right password is refused', function (Sandbox $sb) {
    $sb->user('victim', 'operator');
    for ($i = 0; $i < 5; $i++) ok(!(new Browser($sb->url))->login('victim', 'wrong' . $i));
    ok(!(new Browser($sb->url))->login('victim', 'pw-victim'), 'correct password must be refused while locked');
    $b = new Browser($sb->url); $r = $b->request('GET', 'index.php?page=login');
    preg_match('/name="_csrf" value="([0-9a-f]+)"/', $r['body'], $m);
    $page = $b->request('POST', 'index.php?page=login', http_build_query(['_csrf' => $m[1], 'username' => 'victim', 'password' => 'x']))['body'];
    ok(strpos($page, 'دقیقه‌ی دیگر') !== false, 'lockout message not shown');
});
it('login: other users from the same IP can still log in', function (Sandbox $sb) {
    $sb->user('colleague', 'operator');
    ok((new Browser($sb->url))->login('colleague', 'pw-colleague'));
});
