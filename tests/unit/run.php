<?php
// Self-contained unit tests (no composer). Run: php tests/unit/run.php
// Covers security round 1: A1, A2 (incl. X1-X4), A4, A5, A6, A7. A3 is tests/unit/algrid.test.js.
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__, 2);
require $root . '/Allowlist.class.php';
require $root . '/agi-bin/allowlist-common.php';

use FreePBX\modules\Allowlist;

$fails = 0; $count = 0;
function check($cond, $name) {
	global $fails, $count; $count++;
	if ($cond) { echo "ok   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}
function fresh() {
	$fp = new FakeFreePBX();
	$fp->astman->db = array(
		'/allowlist/dest' => 'app-blackhole,hangup,1',
		'/allowlist/pause' => '0',
		'/allowlist/knowncallers' => '1',
		'/allowlist/did/5550100/' => '1',
		'/allowlist/autoadd/1' => '1,0,99',
		'/allowlist/2025550113' => 'old entry',
		'/allowlist/sipuser' => 'legacy',
	);
	return array($fp, new Allowlist($fp));
}
function ajax($al, $req) { $_REQUEST = $req; return $al->ajaxHandler(); }

// ---- A2(a)/(f), A2(g): number vectors, PHP and AGI mirror agree (SEC-04, SEC-07)
$valid = array('+12025550111' => '+12025550111', '2025550111' => '2025550111', '911' => '911', "2025550111\n" => '2025550111', ' 911 ' => '911', "911\0" => '911', str_repeat('9', 20) => str_repeat('9', 20));
$invalid = array('dest', 'pause', 'knowncallers', 'did/5550100', 'autoadd/1', 'abc', '', '+', '++1', '1e3', str_repeat('1', 21),
	"202\r\n5550111", "202\n5550111", '202 5550111', "202'5550111", '202"5550111', '<script>', "9\0" . "11", '0x1F', '-1', '1.5');
foreach ($valid as $in => $want) {
	check(Allowlist::normalizeNumber((string)$in) === $want, 'valid ' . json_encode((string)$in));
	check(allowlistNormalizeNumber((string)$in) === $want, 'AGI valid ' . json_encode((string)$in));
}
foreach ($invalid as $in) {
	check(Allowlist::normalizeNumber($in) === false, 'invalid ' . json_encode($in));
	check(allowlistNormalizeNumber($in) === false, 'AGI invalid ' . json_encode($in));
}
foreach (array(null, 2025550111, 1.5, true, array('911')) as $in) {
	check(Allowlist::normalizeNumber($in) === false && allowlistNormalizeNumber($in) === false, 'non-string ' . json_encode($in));
}

// ---- A2(b): numberAdd contract
list($fp, $al) = fresh();
$before = $fp->astman->db;
foreach (array('dest', 'pause', 'knowncallers', 'did/5550100/', 'autoadd/1', 'abc') as $bad) {
	$threw = false; $r = null;
	try { $r = $al->numberAdd(array('number' => $bad, 'description' => 'x')); } catch (\Throwable $e) { $threw = true; }
	check($r === false && !$threw, "numberAdd('$bad') returns false without throwing");
}
check($fp->astman->db === $before, 'rejected numberAdd wrote nothing (settings intact)');
check($al->numberAdd(array('number' => " +12025550111\n", 'description' => 'ok')) === true, 'numberAdd valid returns true');
check(isset($fp->astman->db['/allowlist/+12025550111']), 'numberAdd stores the trimmed value');
check($al->numberAdd(array('number' => '2025550112')) === true && $fp->astman->db['/allowlist/2025550112'] === '1', 'missing description stores 1 (as before)');

// ---- A6: description cap and control characters (SEC-10)
check($al->numberAdd(array('number' => '2025550120', 'description' => str_repeat('a', 255))) === true, '255-char description accepted');
check($al->numberAdd(array('number' => '2025550121', 'description' => str_repeat('é', 255))) === true, '255 multibyte chars accepted (measured in characters)');
check($al->numberAdd(array('number' => '2025550122', 'description' => str_repeat('a', 256))) === false, '256-char description rejected');
check($al->numberAdd(array('number' => '2025550123', 'description' => str_repeat('a', 10000))) === false, '10,000-char description rejected');
check($al->numberAdd(array('number' => '2025550124', 'description' => "a\r\nb")) === false, 'CR/LF description rejected');
check($al->numberAdd(array('number' => '2025550125', 'description' => "a\0b")) === false, 'NUL description rejected');
check($al->numberAdd(array('number' => '2025550126', 'description' => "a\x7Fb")) === false, 'DEL description rejected');
check($al->numberAdd(array('number' => '2025550127', 'description' => "\xC3\x28")) === false, 'invalid UTF-8 description rejected');
check(!isset($fp->astman->db['/allowlist/2025550122']) && !isset($fp->astman->db['/allowlist/2025550124']), 'rejected descriptions wrote nothing');
check($al->getLastError() !== '', 'rejection sets a message');

// ---- A2(c)/X3: numberDel only deletes allowlist numbers
list($fp, $al) = fresh();
foreach (array('dest', 'pause', 'knowncallers', 'did/5550100/', 'autoadd/1', '9999999', '', null, array('x'), "sip\nuser", 'a b', 'a"b', "a'b", 'a\\b') as $bad) {
	check($al->numberDel($bad) === false, 'numberDel refuses ' . json_encode($bad));
}
check(isset($fp->astman->db['/allowlist/dest'], $fp->astman->db['/allowlist/pause'], $fp->astman->db['/allowlist/knowncallers'], $fp->astman->db['/allowlist/did/5550100/'], $fp->astman->db['/allowlist/autoadd/1']), 'settings keys survive numberDel attempts');
check($al->numberDel('sipuser') === true && !isset($fp->astman->db['/allowlist/sipuser']), 'legacy non-numeric entry still deletable');
check($al->numberDel('2025550113') === true, 'numeric entry deletable');

// ---- A2(d)/X2/X3: AJAX (SEC-04 a-c, e)
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => 'dest', 'description' => 'x'));
check($r['status'] === false && !empty($r['message']), 'AJAX edit to dest rejected with message');
check(isset($fp->astman->db['/allowlist/2025550113']) && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'AJAX edit rejection keeps old entry and dest');
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
check($r['status'] === true && isset($fp->astman->db['/allowlist/2025550114']) && !isset($fp->astman->db['/allowlist/2025550113']), 'AJAX edit valid replaces entry');
$r = ajax($al, array('command' => 'block', 'number' => 'dest', 'description' => ''));
check($r['status'] === false && $fp->Blacklist->added === array() && isset($fp->astman->db['/allowlist/dest']), 'AJAX block dest rejected, nothing blacklisted, dest kept');
$r = ajax($al, array('command' => 'block', 'number' => '2025550114', 'description' => 'new'));
check($r['status'] === true && count($fp->Blacklist->added) === 1 && !isset($fp->astman->db['/allowlist/2025550114']), 'AJAX block valid number works');
$r = ajax($al, array('command' => 'bulkdelete', 'numbers' => json_encode(array('dest', 'pause', 'knowncallers'))));
check($r['status'] === false && isset($fp->astman->db['/allowlist/dest'], $fp->astman->db['/allowlist/pause'], $fp->astman->db['/allowlist/knowncallers']), 'AJAX bulkdelete of settings keys leaves settings');
$r = ajax($al, array('command' => 'bulkdelete', 'numbers' => '"notalist"'));
check($r['status'] === false, 'AJAX bulkdelete with a non-list is rejected');
$r = ajax($al, array('command' => 'del', 'number' => 'pause'));
check($r['status'] === false && isset($fp->astman->db['/allowlist/pause']), 'AJAX del pause rejected');
$r = ajax($al, array('command' => 'add', 'number' => '+12025550115', 'description' => 'p'));
check($r['status'] === true, 'AJAX add valid');
$r = ajax($al, array('command' => 'del', 'number' => '+12025550115'));
check($r['status'] === true && !isset($fp->astman->db['/allowlist/+12025550115']), 'AJAX del valid');
$r = ajax($al, array('command' => 'add', 'number' => 'knowncallers', 'description' => ''));
check($r['status'] === false, 'AJAX add knowncallers rejected');

// ---- A4: strict lookup (SEC-08)
$list = array(array('number' => '1000', 'description' => 'a'), array('number' => '2025550111', 'description' => 'b'));
check(Allowlist::findInList($list, '1e3') === null, "strict: '1e3' does not match '1000'");
check(Allowlist::findInList($list, '9999') === null, 'unknown number returns null (not the first entry)');
check(Allowlist::findInList(array(array('number' => '1000', 'description' => 'a')), '1') === null, 'one-entry list, unknown returns null');
check(Allowlist::findInList($list, '2025550111')['description'] === 'b', 'known number found');
check(Allowlist::findInList($list, array()) === null, 'non-scalar id returns null');
$gql = file_get_contents($root . '/Api/Gql/Allowlist.php');
check(strpos($gql, 'array_search') === false && substr_count($gql, '->findNumber(') === 3, 'GraphQL uses the strict helper at all three call sites');
check(strpos($gql, 'numberAdd($input)') === false && strpos($gql, 'UserError') !== false, 'GraphQL passes explicit args and returns a user error on rejection');

// ---- A5: destination registry (SEC-06)
list($fp, $al) = fresh();
check($al->destinationSet('custom-evil,s,1') === false && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'unregistered destination rejected, old kept');
check($al->destinationSet("tst-blocked,s,1\r\nAction: Command") === false && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'CR/LF destination rejected, old kept');
check($al->destinationSet('tst-blocked,s,1') === true && $fp->astman->db['/allowlist/dest'] === 'tst-blocked,s,1', 'registered destination saved');
check($al->destinationSet('') === true && !isset($fp->astman->db['/allowlist/dest']), 'empty destination clears');

// ---- A1 + A7: generated dialplan (SEC-01..03, SEC-11)
list($fp, $al) = fresh();
$ext = new FakeExt();
$al->doDialplanHook($ext, 'asterisk', 0);
$ctx = function ($c, $e = null) use ($ext) { return array_values(array_filter($ext->lines, function ($l) use ($c, $e) { return $l['ctx'] === $c && ($e === null || $l['exten'] === $e); })); };
$check = $ctx('app-allowlist-check', 's');
$labels = array_column($check, 'label');
$iList = array_search('check-list', $labels, true);
$guardSet = $check[$iList - 2]; $guardGoto = $check[$iList - 1];
check($guardSet['app'] === 'ext_set' && $guardSet['args'] === array('ALCIDOK', '${REGEX("^[+]?[0-9]+$" ${CALLERID(num)})}'), 'A1(a) CID REGEX set right before check-list');
check($guardGoto['app'] === 'ext_gotoif' && $guardGoto['args'] === array('$["${ALCIDOK}"!="1"]', 'check-contacts'), 'A1(b) non-digit CID goes to check-contacts');
$add1 = $ctx('app-allowlist-add', '1');
$iPut = array_search('DB(allowlist/${allownr})', array_map(function ($l) { return $l['args'][0] ?? null; }, $add1), true);
check($iPut >= 2 && $add1[$iPut - 1]['args'] === array('$["${ALNROK}"!="1"]', 'app-allowlist-add-invalid,s,1') && strpos($add1[$iPut - 2]['args'][1], '${REGEX("^[+]?[0-9]+$" ${allownr})}') !== false, 'A1(d) *38 guards ${allownr} before the DB write');
$lastS = $ctx('app-allowlist-last', 's');
$hasLastGuard = false;
foreach ($lastS as $i => $l) {
	if ($l['app'] === 'ext_set' && $l['args'][0] === 'ALCIDOK' && strpos($l['args'][1], '${lastcaller}') !== false && $lastS[$i + 1]['args'] === array('$["${ALCIDOK}"!="1"]', 'noinfo')) { $hasLastGuard = true; }
}
check($hasLastGuard, 'A1(c) *40 guards ${lastcaller}, no match goes to noinfo');
$last1 = $ctx('app-allowlist-last', '1');
check($last1[0]['args'][0] === 'ALCIDOK' && $last1[1]['args'][1] === 's,noinfo' && $last1[2]['args'][0] === 'DB(allowlist/${lastcaller})', 'A1(c) *40 write is guarded too');
$noBackslash = true;
foreach ($ext->lines as $l) { foreach ($l['args'] as $a) { if (is_string($a) && strpos($a, 'REGEX') !== false && strpos($a, '\\') !== false) { $noBackslash = false; } } }
check($noBackslash, 'A1 REGEX arguments contain no backslash');
$toggle = $ctx('app-allowlist-pause-toggle', 's');
$pauseExpr = array_values(array_filter($toggle, function ($l) { return $l['app'] === 'ext_gotoif' && $l['args'][1] === 'paused:unpaused'; }));
check(count($pauseExpr) === 1 && $pauseExpr[0]['args'][0] === '$[${DB_EXISTS(allowlist/pause)}]', 'A7 pause expression has no stray ]');

echo "\n$count checks, $fails failed\n";
exit($fails ? 1 : 0);
