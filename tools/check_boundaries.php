<?php
/**
 * tools/check_boundaries.php — bounded-context rule for src/ (ADR-002). Dev tool; not deployed.
 * A file in src/<A>/ may reference another context <B> only as Eb\<B>\Api or Eb\<B>\Events\*.
 * Usage: php tools/check_boundaries.php [srcDir]   → exit 1 and lists violations.
 */
function eb_boundary_violations(string $srcDir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = substr($f->getPathname(), strlen(rtrim($srcDir, '/')) + 1);
        if (strpos($rel, '/') === false) continue;                 // src/bootstrap.php etc. are kernel glue
        $ctx = strstr($rel, '/', true);
        $code = file_get_contents($f->getPathname());
        // strip comments and strings so prose does not count
        $tokens = token_get_all($code);
        $clean = '';
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
            $clean .= is_array($t) ? $t[1] : $t;
        }
        preg_match_all('/\\\\?Eb\\\\([A-Z][A-Za-z0-9_]*)\\\\([A-Z][A-Za-z0-9_]*)/', $clean, $m, PREG_SET_ORDER);
        foreach ($m as [$full, $other, $member]) {
            if ($other === $ctx) continue;
            if ($member === 'Api' || $member === 'Events') continue;
            $out[] = "$rel → " . ltrim($full, '\\');
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $v = eb_boundary_violations($argv[1] ?? dirname(__DIR__) . '/src');
    echo $v ? "boundary violations:\n  " . implode("\n  ", $v) . "\n" : "no boundary violations\n";
    exit($v ? 1 : 0);
}
