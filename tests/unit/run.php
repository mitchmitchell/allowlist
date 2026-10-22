<?php
// Self-contained unit tests (no composer). Run: php tests/unit/run.php
// Covers security round 1: A1, A2 (incl. X1-X4), A4, A5, A6, A7. A3 is tests/unit/algrid.test.js.
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__, 2);
require $root . '/Allowlist.class.php';
require $root . '/agi-bin/allowlist-common.php';
require $root . '/Restore.php';

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
check($guardSet['app'] === 'ext_set' && $guardSet['args'] === array('ALCIDOK', '${REGEX("^[+]?[0-9]{1,20}$" ${CALLERID(num)})}'), 'A1(a) CID REGEX set right before check-list');
check($guardGoto['app'] === 'ext_gotoif' && $guardGoto['args'] === array('$["${ALCIDOK}"!="1"]', 'check-contacts'), 'A1(b) non-digit CID goes to check-contacts');
$add1 = $ctx('app-allowlist-add', '1');
$iPut = array_search('DB(allowlist/${allownr})', array_map(function ($l) { return $l['args'][0] ?? null; }, $add1), true);
check($iPut >= 2 && $add1[$iPut - 1]['args'] === array('$["${ALNROK}"!="1"]', 'app-allowlist-add-invalid,s,1') && strpos($add1[$iPut - 2]['args'][1], '${REGEX("^[+]?[0-9]{1,20}$" ${allownr})}') !== false, 'A1(d) *38 guards ${allownr} before the DB write');
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
$bounded = 0;
foreach ($ext->lines as $l) { foreach ($l['args'] as $a) { if (is_string($a) && strpos($a, 'REGEX(') !== false) { $bounded += strpos($a, '^[+]?[0-9]{1,20}$') !== false ? 1 : -100; } } }
check($bounded === 4, 'should-fix (a): all four dialplan REGEX guards cap at 20 digits like PHP/AGI');
$toggle = $ctx('app-allowlist-pause-toggle', 's');
$pauseExpr = array_values(array_filter($toggle, function ($l) { return $l['app'] === 'ext_gotoif' && $l['args'][1] === 'paused:unpaused'; }));
check(count($pauseExpr) === 1 && $pauseExpr[0]['args'][0] === '$[${DB_EXISTS(allowlist/pause)}]', 'A7 pause expression has no stray ]');


// ---- Blocker 1: legacy restore keeps settings, validates numbers, reports skips
list($fp, $al) = fresh();
$fp->Allowlist = $al;
$fp->astman->db['/allowlist/2029999999'] = 'pre-restore entry';
$restore = new \FreePBX\modules\Allowlist\Restore($fp);
$legacy = array('astdb' => array('allowlist' => array(
	'dest' => 'tst-blocked,s,1', 'pause' => '1760800000', 'knowncallers' => '1',
	'did/5550100/' => '1', 'autoadd/3' => '1,1,10',
	'2025550111' => 'Alice', '+12025550112' => 'Bob', 911 => '1',
	'sipuser' => 'legacy name', 'pause2' => 'x',
	"autoadd/4" => "1,0,99\r\nAction: Command", "dest\n" => 'x',
)));
$restore->processLegacy(null, $legacy, array(), array());
$db = $fp->astman->db;
check(($db['/allowlist/dest'] ?? null) === 'tst-blocked,s,1' && ($db['/allowlist/pause'] ?? null) === '1760800000' && ($db['/allowlist/knowncallers'] ?? null) === '1', 'legacy restore keeps dest, pause, knowncallers');
check(($db['/allowlist/did/5550100/'] ?? null) === '1' && ($db['/allowlist/autoadd/3'] ?? null) === '1,1,10', 'legacy restore keeps did/ and autoadd/ keys');
check(($db['/allowlist/2025550111'] ?? null) === 'Alice' && isset($db['/allowlist/+12025550112'], $db['/allowlist/911']), 'legacy restore adds valid numbers via numberAdd');
check(!isset($db['/allowlist/sipuser']) && !isset($db['/allowlist/pause2']), 'legacy restore skips non-digit entries');
check(!isset($db['/allowlist/autoadd/4']) && !isset($db["/allowlist/dest\n"]), 'legacy restore rejects settings with CR/LF');
check(!isset($db['/allowlist/2029999999']), 'legacy restore still clears old data first');
$logged = implode("\n", array_column($restore->logs, 1));
check(count($restore->logs) === 4 && strpos($logged, 'sipuser') !== false && strpos($logged, 'pause2') !== false && strpos($logged, 'autoadd/4') !== false, 'legacy restore logs each skipped entry (4)');
check(strpos($logged, "\r") === false && strpos($logged, 'Action: Command') === false, 'skip log does not echo CR/LF payloads');
check($restore->featureCodesRestored === true, 'legacy restore still restores feature codes');
$r = $restore->processLegacy(null, array('astdb' => array()), array(), array());
check($r === $restore && ($fp->astman->db['/allowlist/dest'] ?? null) === 'tst-blocked,s,1', 'legacy restore without allowlist data changes nothing');

// ---- Blocker 2: bulk import reports rejected rows
list($fp, $al) = fresh();
$r = $al->bulkhandlerImport('allowlist', array(
	array('number' => '2025550130', 'description' => 'ok'),
	array('number' => 'dest', 'description' => 'evil'),
	array('number' => '', 'description' => 'blank'),
	array('number' => '2025550131', 'description' => str_repeat('a', 300)),
	array('number' => "+12025550132", 'description' => ''),
));
check($r['status'] === false && count($r['rejected']) === 3, 'bulk import returns status false with 3 rejected rows');
check(strpos($r['message'], 'row 2 (dest)') !== false && strpos($r['message'], 'row 3 ') !== false && strpos($r['message'], 'row 4 (2025550131)') !== false, 'bulk import message names the real rejected rows (multi-row call)');
check(isset($fp->astman->db['/allowlist/2025550130'], $fp->astman->db['/allowlist/+12025550132']) && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'bulk import adds valid rows, dest untouched');
$r = $al->bulkhandlerImport('allowlist', array(array('number' => '2025550133', 'description' => 'x')));
check($r === array('status' => true), 'bulk import of valid rows returns status true');
$r = $al->bulkhandlerImport('allowlist', array(array('number' => '2025550133', 'description' => 'x'), array('number' => 'pause')), false);
check($r['status'] === false && count($r['rejected']) === 1, 'bulk import (no replace) skips existing, still reports invalid');

// ---- Should-fix (c): partial bulkdelete
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'bulkdelete', 'numbers' => json_encode(array('2025550113', 'dest', 'sipuser'))));
check($r['status'] === true && $r['deleted'] === 2 && $r['rejected'] === array('dest') && !empty($r['message']), 'partial bulkdelete: status true (grid refreshes), rejected listed');
check(!isset($fp->astman->db['/allowlist/2025550113']) && isset($fp->astman->db['/allowlist/dest']), 'partial bulkdelete deleted valid, kept dest');

// ---- Should-fix (b): CLI reports a rejected destination
$console = file_get_contents($root . '/Console/Allowlist.class.php');
check(preg_match('/if \(!\$allowlist->destinationSet\(\$destnames\[\$id\]\[2\]\)\) \{\s*\$output->writeln\(.<error>. \. \$allowlist->getLastError\(\)/', $console) === 1, 'CLI checks destinationSet() and prints the error');

// ---- #1 review 5564688977: bulk import message is HTML-safe (bulkhandler UI uses innerHTML)
list($fp, $al) = fresh();
$xss = array('<img src=x onerror=alert(1)>', '"><svg onload=alert(1)>', "' onmouseover='alert(1)", '<script>alert(1)</script>');
foreach ($xss as $p) {
	$r = $al->bulkhandlerImport('allowlist', array(array('number' => $p, 'description' => 'x')));
	$m = $r['message'];
	check($r['status'] === false && strpos($m, '<') === false && strpos($m, '>') === false && strpos($m, '"') === false && strpos($m, "'") === false, 'bulk import message escapes ' . $p);
	check(strpos($m, 'row ') === false, 'single-row request (UI path) has no misleading row number: ' . $p);
}
$r = $al->bulkhandlerImport('allowlist', array(array('number' => '<img src=x onerror=alert(1)>')));
check(strpos($r['message'], '&lt;img src=x onerror=alert(1)&gt;') !== false, 'escaped payload is still readable');
$r = $al->bulkhandlerImport('allowlist', array(array('number' => str_repeat('é', 60))));
check(preg_match('//u', $r['message']) === 1 && strpos($r['message'], '(' . str_repeat('é', 40) . ')') !== false, 'number truncated to 40 characters with mb_substr (valid UTF-8)');
list($fp, $al) = fresh();
$fp->Allowlist = $al;
$restore = new \FreePBX\modules\Allowlist\Restore($fp);
$skipped = $restore->restoreAllowlistEntries(array('<img src=x onerror=alert(1)>' => 'x', str_repeat('ü', 50) => 'y'));
$logged = implode("\n", array_column($restore->logs, 1));
check(count($skipped) === 2 && strpos($logged, '<') === false && strpos($logged, '&lt;img') !== false, 'restore skip-log is HTML-escaped');
check(preg_match('//u', $logged) === 1 && strpos($logged, str_repeat('ü', 40) . ':') !== false, 'restore skip-log truncates with mb_substr');

// --- M2: pause authorization + audit ---------------------------------------
list($fp, $al) = fresh();
foreach (array('0', '', 'false', 'no', 'off', null, array(1)) as $v) {
	$al->pauseSet($v, 'test');
	check(!isset($fp->astman->db['/allowlist/pause']), 'pauseSet(' . var_export($v, true) . ') does not pause');
}
$t0 = time();
$al->pauseSet('1', 'test');
$p = (int)$fp->astman->db['/allowlist/pause'];
check($p > $t0 && $p <= time() + 86400, "pauseSet('1') pauses for at most 24 hours");
check(in_array('Allowlist screening paused for 24 hours by test', $al->auditEntries, true), 'pause is audit-logged with its source');
$al->pauseSet('0', 'test');
check(in_array('Allowlist screening unpaused by test', $al->auditEntries, true), 'unpause is audit-logged');
$r = ajax($al, array('command' => 'pause', 'pause' => 'false'));
check($r['status'] === true && !isset($fp->astman->db['/allowlist/pause']), "AJAX pause='false' does not pause");
check($al->pausePinSet('12') === false && !isset($fp->astman->db['/allowlist/pausepin']), 'short PIN rejected');
check($al->pausePinSet('12a4') === false, 'non-digit PIN rejected');
check($al->pausePinSet('0000') === true && $al->pausePinIsSet(), 'dummy 4-digit PIN accepted');
check(strpos(implode("\n", $al->auditEntries), '0000') === false, 'audit log never contains the PIN');
check(Allowlist::findInList($al->getAllowlist(), 'pausepin') === null, 'PIN key never appears in the allowlist grid');
check($al->numberDel('pausepin') === false && isset($fp->astman->db['/allowlist/pausepin']), 'PIN key cannot be deleted as a number');
check($al->pausePinSet('') === true && !$al->pausePinIsSet(), 'PIN can be cleared');
$ext = new FakeExt();
$al->doDialplanHook($ext, 'x', 'x');
$tog = array_values(array_filter($ext->lines, function ($l) { return $l['ctx'] === 'app-allowlist-pause-toggle'; }));
$apps = array_column($tog, 'app');
$auth = array_search('ext_authenticate', $apps, true);
$gosub = array_search('ext_gosubif', $apps, true);
check($auth !== false && $gosub !== false && $auth < $gosub, 'toggle dialplan authenticates before toggling');
check($tog[$auth]['args'][0] === '${DB(allowlist/pausepin)}' && $tog[$auth]['label'] === 'pin', 'Authenticate uses the stored PIN by reference only');
check(in_array('ext_log', $apps, true), 'toggle dialplan writes an audit log line');

// --- M4: restore applies A5 and bounds pause ---------------------------------
list($fp, $al) = fresh();
$fp->Allowlist = $al;
$restore = new \FreePBX\modules\Allowlist\Restore($fp);
$skipped = $restore->restoreAllowlistEntries(array('dest' => 'bogus-ctx,s,1', 'pause' => '9999999999', 'autoadd/1' => 'x', 'knowncallers' => 'yes', 'pausepin' => '12'));
$db = $fp->astman->db;
check(count($skipped) === 5, 'restore skips bogus dest, far-future pause, bad autoadd, knowncallers, PIN');
check($db['/allowlist/dest'] === 'app-blackhole,hangup,1' && $db['/allowlist/pause'] === '0' && $db['/allowlist/autoadd/1'] === '1,0,99' && $db['/allowlist/knowncallers'] === '1', 'skipped settings leave stored values untouched');
$soon = (string)(time() + 3600);
$skipped = $restore->restoreAllowlistEntries(array('dest' => 'tst-blocked,s,1', 'pause' => $soon, 'autoadd/2' => '1,2,3', 'pausepin' => '0000'));
$db = $fp->astman->db;
check($skipped === array() && $db['/allowlist/dest'] === 'tst-blocked,s,1' && $db['/allowlist/pause'] === $soon && $db['/allowlist/autoadd/2'] === '1,2,3' && $db['/allowlist/pausepin'] === '0000', 'valid settings (registered dest, pause within 24 h) restore');
$skipped = $restore->restoreAllowlistEntries(array('pause' => (string)(time() + 86400 + 120)));
check(count($skipped) === 1, 'pause just over 24 hours ahead is skipped');
check(strpos(implode("\n", array_column($restore->logs, 1)), '0000') === false, 'restore log never contains a PIN');

echo "\n$count checks, $fails failed\n";
exit($fails ? 1 : 0);
