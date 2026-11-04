<?php
$src = file_get_contents($argv[1].'/modules/Persons.php');
// extract asciiDigits + normalizePhone into a stub class
preg_match('/public static function asciiDigits.*?\n    }\n/s', $src, $a);
preg_match('/public static function normalizePhone.*?\n    }\n/s', $src, $b);
eval('class P { '.$a[0].$b[0].' }');
$c = file_get_contents($argv[1].'/crm/api_integration/CallSyncManager.php');
preg_match('/private function normalizeIranianMobile.*?\n    }\n/s', $c, $m);
eval('class S { '.str_replace('private function','public static function',$m[0]).' }');
$d = file_get_contents($argv[1].'/crm/models/Call.php');
preg_match('/public static function normalizeLeadPhone.*?\n    }\n/s', $d, $n);
eval('class L { '.$n[0].' }');
$in = ['09121234567','+989121234567','00989121234567','9121234567',912123456.7e1,'۰۹۱۲۱۲۳۴۵۶۷','0912-123-4567','02188776655','+14155552671','12345'];
printf("%-18s | %-14s | %-16s | %-14s\n",'input','Persons(EB)','CallSync(CRM)','Call::lead(CRM)');
foreach ($in as $x){ $x=(string)$x; printf("%-18s | %-14s | %-16s | %-14s\n",$x,var_export(P::normalizePhone($x),1),var_export(S::normalizeIranianMobile($x),1),var_export(L::normalizeLeadPhone($x),1)); }
