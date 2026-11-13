<?php
// Self-contained unit tests (no composer). Run: php tests/unit/run.php
// Covers security round 1: A1, A2 (incl. X1-X4), A4, A5, A6, A7. A3 is tests/unit/algrid.test.js.
require __DIR__ . '/bootstrap.php';
$root = dirname(__DIR__, 2);
require $root . '/Allowlist.class.php';
require __DIR__ . '/AllowlistListMutationTestHarness.php';
require __DIR__ . '/GqlMutationHarness.php';
require $root . '/Api/Gql/Allowlist.php';
require __DIR__ . '/GqlMutationHarnessAllowlist.php';
require $root . '/agi-bin/allowlist-common.php';
require $root . '/Restore.php';

use FreePBX\modules\Allowlist;

$fails = 0; $count = 0;
function check($cond, $name) {
	global $fails, $count; $count++;
	if ($cond) { echo "ok   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}
function allowlistUnitWriteDb($fp, $path) {
	file_put_contents($path, json_encode($fp->astman->db));
}
function allowlistUnitDeleteListForHook($al, $listId) {
	$al->testBeforeListMutationLockAcquire = null;
	$al->listDelete($listId);
}
function allowlistUnitFunctionBodyContains($src, $funcName, $needle) {
	if (!preg_match('/function\s+' . preg_quote($funcName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return false;
	}
	$start = $m[0][1];
	$open = strpos($src, '{', $start);
	if ($open === false) {
		return false;
	}
	$depth = 0;
	$len = strlen($src);
	for ($i = $open; $i < $len; $i++) {
		$ch = $src[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				$body = substr($src, $open, $i - $open + 1);
				return strpos($body, $needle) !== false;
			}
		}
	}
	return false;
}
function allowlistUnitExtractFunctionBody($src, $funcName) {
	if (!preg_match('/function\s+' . preg_quote($funcName, '/') . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = $m[0][1];
	$open = strpos($src, '{', $start);
	if ($open === false) {
		return '';
	}
	$depth = 0;
	$len = strlen($src);
	for ($i = $open; $i < $len; $i++) {
		$ch = $src[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $open, $i - $open + 1);
			}
		}
	}
	return '';
}
function allowlistUnitGqlMutateCallbackBody($gql, $mutationName) {
	if (!preg_match("/'" . preg_quote($mutationName, '/') . "'\s*=>\s*Relay::mutationWithClientMutationId/s", $gql, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = $m[0][1];
	$mutate = strpos($gql, "'mutateAndGetPayload'", $start);
	if ($mutate === false) {
		return '';
	}
	$func = strpos($gql, 'function', $mutate);
	$open = strpos($gql, '{', $func);
	if ($open === false) {
		return '';
	}
	$depth = 0;
	$len = strlen($gql);
	for ($i = $open; $i < $len; $i++) {
		$ch = $gql[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($gql, $open, $i - $open + 1);
			}
		}
	}
	return '';
}
function allowlistUnitGqlMutationFieldTypesBody($gql, $mutationName) {
	if (!preg_match("/'" . preg_quote($mutationName, '/') . "'\s*=>\s*Relay::mutationWithClientMutationId\(\[[\s\S]*?'inputFields'\s*=>\s*\[/", $gql, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = $m[0][1];
	$input = strpos($gql, "'inputFields'", $start);
	$open = strpos($gql, '[', $input);
	if ($open === false) {
		return '';
	}
	$depth = 0;
	$len = strlen($gql);
	for ($i = $open; $i < $len; $i++) {
		$ch = $gql[$i];
		if ($ch === '[') {
			$depth++;
		} elseif ($ch === ']') {
			$depth--;
			if ($depth === 0) {
				return substr($gql, $open, $i - $open + 1);
			}
		}
	}
	return '';
}
function allowlistUnitNormalizePhpSnippet($snippet) {
	return trim(preg_replace('/\s+/', ' ', $snippet));
}
/**
 * @return array{ok:bool,result:mixed,error:string}
 */
function allowlistUnitGqlInvokeExtractor($gqlHarness, $method, array $input) {
	try {
		$ref = new ReflectionMethod($gqlHarness, $method);
		$ref->setAccessible(true);
		$result = $ref->invoke($gqlHarness, $input);
		return array('ok' => true, 'result' => $result, 'error' => '');
	} catch (\GraphQL\Error\UserError $e) {
		return array('ok' => false, 'result' => null, 'error' => $e->getMessage());
	}
}
function allowlistUnitGqlMutateDelegationExpected($mutationName) {
	$map = array(
		'addAllowlist' => 'gqlMutateAddAllowlist',
		'removeAllowlist' => 'gqlMutateRemoveAllowlist',
		'addAllowlistList' => 'gqlMutateAddAllowlistList',
		'renameAllowlistList' => 'gqlMutateRenameAllowlistList',
		'removeAllowlistList' => 'gqlMutateRemoveAllowlistList',
		'setInboundRouteAllowlist' => 'gqlMutateSetInboundRouteAllowlist',
		'setOutboundRouteAllowlist' => 'gqlMutateSetOutboundRouteAllowlist',
	);
	return isset($map[$mutationName]) ? "return \$this->{$map[$mutationName]}(\$input);" : '';
}
function allowlistUnitUnlinkIfExists($path) {
	if (is_string($path) && $path !== '' && (is_file($path) || is_link($path))) {
		unlink($path);
	}
}
function freshHarness() {
	$fp = new FakeFreePBX();
	$fp->astman->db = array(
		'/allowlist/dest' => 'app-blackhole,hangup,1',
		'/allowlist/pause' => '0',
		'/allowlist/knowncallers' => '1',
		'/allowlist/did/5550100/' => '1',
		'/allowlist/autoadd/1' => '1,0,99',
		'/allowlist/2025550113' => 'old entry',
		'/allowlist/sipuser' => 'legacy',
		'/allowlist-lists/1' => 'Default',
	);
	return array($fp, new \FreePBX\modules\AllowlistListMutationTestHarness($fp));
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
		'/allowlist-lists/1' => 'Default',
	);
	return array($fp, new Allowlist($fp));
}
function ajax($al, $req) { if (!array_key_exists('token', $req)) { $req['token'] = $al->csrfToken(); } $_REQUEST = $req; return $al->ajaxHandler(); }

// ---- A2(a)/(f), A2(g): number vectors, PHP and AGI mirror agree (SEC-04, SEC-07)
$valid = array('+12025550111' => '+12025550111', '2025550111' => '2025550111', '911' => '911', "2025550111\n" => '2025550111', ' 911 ' => '911', "911\0" => '911', str_repeat('9', 20) => str_repeat('9', 20));
$invalid = array('dest', 'pause', 'knowncallers', 'did/5550100', 'autoadd/1', 'abc', '', '+', '++1', '1e3', str_repeat('1', 21),
	"202\r\n5550111", "202\n5550111", '202 5550111', "202'5550111", '202"5550111', '<script>', "9\0" . "11", '0x1F', '-1', '1.5');
foreach ($valid as $in => $want) {
	check(Allowlist::normalizeNumber((string)$in) === $want, 'valid ' . json_encode((string)$in));
	check(allowlistNormalizeNumber((string)$in) === $want, 'AGI valid ' . json_encode((string)$in));
}
$listValid = array('1' => 1, '42' => 42, '9999' => 9999);
$listInvalid = array('0', '-1', '1;Hangup', '2/../x', '10000', '', 'abc');
foreach ($listValid as $raw => $want) {
	check(Allowlist::parseListIdValue($raw) === $want && allowlistParseListIdValue($raw) === $want, 'PHP and AGI list id agree on ' . $raw);
}
foreach ($listInvalid as $bad) {
	check(Allowlist::parseListIdValue($bad) === false && allowlistParseListIdValue($bad) === false, 'PHP and AGI list id reject ' . json_encode($bad));
}
check(allowlistParseAutoaddListId('1,0,99') === 1 && allowlistParseAutoaddListId('1,1,10,3') === 3, 'AGI autoadd parser defaults and reads list id');
check(allowlistParseAutoaddListId('2,0,99') === false && allowlistParseAutoaddListId('1,0,99,0') === false, 'AGI autoadd parser rejects invalid values');
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
check(strpos($gql, 'array_search') === false && substr_count($gql, '->findListMember(') >= 2, 'GraphQL resolvers use findListMember for strict entry lookup');
check(strpos($gql, 'numberAdd($input)') === false && strpos($gql, 'UserError') !== false, 'GraphQL passes explicit args and returns a user error on rejection');
check(strpos($gql, 'allowlistLists') !== false && strpos($gql, 'setInboundRouteAllowlist') !== false, 'GraphQL exposes multi-list queries and route mutations');
check(preg_match('/checkAllWriteScope\(\)\)[\s\S]*addAllowlistList/', $gql) === 1, 'list/route GraphQL mutations sit behind checkAllWriteScope');
check(preg_match('/checkAllReadScope\(\)\)[\s\S]*allowlistLists/', $gql) === 1, 'allowlistLists sits behind checkAllReadScope');
check(strpos($gql, 'formatListNameForDisplay') === false, 'GraphQL schema does not html-escape list names');
check(preg_match('/setGetNodeCallback\(function \(\$id\) \{[\s\S]*checkAllReadScope/', $gql) === 1, 'allowlistList getNode checks read scope');
check(preg_match('/setGetNodeCallback\(function\(\$id\) \{[\s\S]*checkAllReadScope/', $gql) === 1, 'allowlist getNode checks read scope');
check(substr_count($gql, "'addAllowlistList' =>") === 1, 'addAllowlistList defined once (write scope gate)');
$alSrc = file_get_contents($root . '/Allowlist.class.php');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlAddAllowlistMember', 'findListMember'), 'applyGraphqlAddAllowlistMember reloads entry via findListMember');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlAddAllowlistMember', 'listMemberAdd'), 'applyGraphqlAddAllowlistMember checks listMemberAdd result');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlRemoveAllowlistList', 'listDelete'), 'applyGraphqlRemoveAllowlistList checks listDelete result');
$removeMemberApply = allowlistUnitExtractFunctionBody($alSrc, 'applyGraphqlRemoveAllowlistMember');
check(strpos($removeMemberApply, 'DEFAULT_LIST_ID') !== false && strpos($removeMemberApply, 'listMemberDel') < strpos($removeMemberApply, 'checkEntry'), 'applyGraphqlRemoveAllowlistMember skips checkEntry on default list');
check(strpos($removeMemberApply, 'checkEntry') !== false && strpos($removeMemberApply, 'checkEntry') < strrpos($removeMemberApply, 'listMemberDel'), 'applyGraphqlRemoveAllowlistMember validates named-list entry before delete');
check(strpos($removeMemberApply, 'formatGqlAllowlistEntryRelayGlobalId') === false, 'applyGraphqlRemoveAllowlistMember does not use entry Relay global id');
$removeListApply = allowlistUnitExtractFunctionBody($alSrc, 'applyGraphqlRemoveAllowlistList');
check(strpos($removeListApply, 'graphqlRemoveAllowlistListDeletedIdPayload') !== false && strpos($removeListApply, 'listDelete') !== false && strpos($removeListApply, 'graphqlRemoveAllowlistListDeletedIdPayload') < strpos($removeListApply, 'listDelete'), 'applyGraphqlRemoveAllowlistList formats deletedId before listDelete');
check(strpos($gql, 'resolveRouteListIdArg') === false, 'dead resolveRouteListIdArg removed from GraphQL layer');
check(allowlistUnitFunctionBodyContains($alSrc, 'resolveGraphqlListIdArg', 'listExists($id)'), 'resolveGraphqlListIdArg enforces listExists');
check(allowlistUnitFunctionBodyContains($alSrc, 'resolveGraphqlListIdArg', 'DEFAULT_LIST_ID'), 'resolveGraphqlListIdArg pins default list 1');
check(allowlistUnitFunctionBodyContains($alSrc, 'resolveGraphqlListIdArg', '$required'), 'resolveGraphqlListIdArg pins required allowlist id branch');
check(allowlistUnitFunctionBodyContains($alSrc, 'resolveGraphqlRouteListIdArg', 'listExists($id)'), 'resolveGraphqlRouteListIdArg enforces listExists');
check(strpos($gql, "array_key_exists('listId', \$input)") !== false, 'route setters require listId key in GraphQL input');
check(allowlistUnitFunctionBodyContains($gql, 'resolveMemberListIdArg', 'throw new UserError'), 'resolveMemberListIdArg throws UserError on unknown list');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlInboundRouteAllowlist', 'signalRouteScreeningDialplanReload'), 'inbound GraphQL route assign signals dialplan reload');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlOutboundRouteAllowlist', 'signalRouteScreeningDialplanReload'), 'outbound GraphQL route assign signals dialplan reload');
foreach (array('addAllowlist', 'removeAllowlist', 'addAllowlistList', 'renameAllowlistList', 'removeAllowlistList', 'setInboundRouteAllowlist', 'setOutboundRouteAllowlist') as $gqlMutName) {
	$mutBody = allowlistUnitGqlMutateCallbackBody($gql, $gqlMutName);
	$expected = allowlistUnitGqlMutateDelegationExpected($gqlMutName);
	check(allowlistUnitNormalizePhpSnippet($mutBody) === allowlistUnitNormalizePhpSnippet('{ ' . $expected . ' }'), $gqlMutName . ' mutateAndGetPayload is one-line gqlMutate delegation');
}
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateSetInboundRouteAllowlist', 'applyGraphqlInboundRouteAllowlist'), 'gqlMutateSetInboundRouteAllowlist delegates to applyGraphqlInboundRouteAllowlist');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateSetOutboundRouteAllowlist', 'applyGraphqlOutboundRouteAllowlist'), 'gqlMutateSetOutboundRouteAllowlist delegates to applyGraphqlOutboundRouteAllowlist');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateSetInboundRouteAllowlist', "'listId' => \$result['listId']"), 'gqlMutateSetInboundRouteAllowlist returns listId payload field');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateSetOutboundRouteAllowlist', "'listId' => \$result['listId']"), 'gqlMutateSetOutboundRouteAllowlist returns listId payload field');
$setOutboundFields = allowlistUnitGqlMutationFieldTypesBody($gql, 'setOutboundRouteAllowlist');
check(strpos($setOutboundFields, "'remove'") !== false && preg_match("/'remove'\\s*=>\\s*\\[[^\\]]*'type'\\s*=>\\s*Type::int\\(\\)/s", $setOutboundFields) === 1, 'setOutboundRouteAllowlist remove is nullable int');
check(strpos($setOutboundFields, "'store'") !== false && preg_match("/'store'\\s*=>\\s*\\[[^\\]]*'type'\\s*=>\\s*Type::int\\(\\)/s", $setOutboundFields) === 1, 'setOutboundRouteAllowlist store is nullable int');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlInboundRouteAllowlist', 'formatGraphqlListIdPayload'), 'applyGraphqlInboundRouteAllowlist formats enable listId payload');
check(allowlistUnitFunctionBodyContains($alSrc, 'applyGraphqlOutboundRouteAllowlist', 'formatGraphqlListIdPayload'), 'applyGraphqlOutboundRouteAllowlist formats enable listId payload');
$inApplyBody = allowlistUnitExtractFunctionBody($alSrc, 'applyGraphqlInboundRouteAllowlist');
$outApplyBody = allowlistUnitExtractFunctionBody($alSrc, 'applyGraphqlOutboundRouteAllowlist');
check(substr_count($inApplyBody, 'inboundRouteExistsInCore') === 1, 'applyGraphqlInboundRouteAllowlist checks core route only on enable');
check(substr_count($outApplyBody, 'outboundRouteExistsInCore') === 1, 'applyGraphqlOutboundRouteAllowlist checks core route only on enable');
check(strpos($outApplyBody, 'remove and store are required when enabling outbound auto-add.') !== false, 'applyGraphqlOutboundRouteAllowlist pins remove/store guard message');
check(strpos($inApplyBody, 'validDidCid') !== false && strpos($inApplyBody, 'validDidCid') < strpos($inApplyBody, 'listIdRaw === null'), 'applyGraphqlInboundRouteAllowlist validates DID/CID before clear');
check(strpos($outApplyBody, 'preg_match') !== false && strpos($outApplyBody, 'preg_match') < strpos($outApplyBody, 'listIdRaw === null'), 'applyGraphqlOutboundRouteAllowlist validates routeId before clear');
check(strpos($inApplyBody, 'if (!$this->didAdd') !== false, 'applyGraphqlInboundRouteAllowlist checks didAdd result');
check(strpos($outApplyBody, 'if (!$this->routeAdd') !== false, 'applyGraphqlOutboundRouteAllowlist checks routeAdd result');
check(strpos($gql, 'protected function gqlMutateAddAllowlist') !== false, 'gqlMutateAddAllowlist is protected extractor');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateAddAllowlist', 'applyGraphqlAddAllowlistMember'), 'gqlMutateAddAllowlist wraps applyGraphqlAddAllowlistMember');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateRemoveAllowlist', 'applyGraphqlRemoveAllowlistMember'), 'gqlMutateRemoveAllowlist wraps applyGraphqlRemoveAllowlistMember');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateAddAllowlistList', 'applyGraphqlCreateAllowlistList'), 'gqlMutateAddAllowlistList wraps applyGraphqlCreateAllowlistList');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateRenameAllowlistList', 'applyGraphqlRenameAllowlistList'), 'gqlMutateRenameAllowlistList wraps applyGraphqlRenameAllowlistList');
check(allowlistUnitFunctionBodyContains($gql, 'gqlMutateRemoveAllowlistList', 'applyGraphqlRemoveAllowlistList'), 'gqlMutateRemoveAllowlistList wraps applyGraphqlRemoveAllowlistList');
preg_match('/function queryCallback\(\)[\s\S]*?function initializeTypes/s', $gql, $gqlQueryBlock);
$gqlQuerySection = $gqlQueryBlock[0] ?? '';
check(strpos($gqlQuerySection, "'addAllowlistList'") === false && strpos($gqlQuerySection, "'setOutboundRouteAllowlist'") === false, 'write mutations not in queryCallback');
foreach (array('addAllowlistList', 'renameAllowlistList', 'removeAllowlistList', 'setInboundRouteAllowlist', 'setOutboundRouteAllowlist') as $gqlWriteMut) {
	check(preg_match('/function mutationCallback\(\)[\s\S]*checkAllWriteScope\(\)\)[\s\S]*\'' . $gqlWriteMut . '\'\s*=>/', $gql) === 1, $gqlWriteMut . ' registered only under checkAllWriteScope');
}

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
$allistLine = array_values(array_filter($check, function ($l) { return $l['app'] === 'ext_set' && ($l['args'][0] ?? null) === 'LOCAL(ALLIST)'; }));
check(count($allistLine) === 1 && strpos($allistLine[0]['args'][1], '${ARG1}') !== false, 'runtime: LOCAL(ALLIST) defaults invalid ARG1 before list branch');
check($guardSet['app'] === 'ext_set' && $guardSet['args'] === array('ALCIDOK', '${REGEX("^[+]?[0-9]{1,20}$" ${CALLERID(num)})}'), 'A1(a) CID REGEX set right before check-list');
check($guardGoto['app'] === 'ext_gotoif' && $guardGoto['args'] === array('$["${ALCIDOK}"!="1"]', 'check-contacts'), 'A1(b) non-digit CID goes to check-contacts');
$iDefault = array_search('check-list-default', $labels, true);
check($iDefault !== false && $check[$iList]['args'] === array('$["${LOCAL(ALLIST)}"="1"]', 'check-list-default'), 'runtime: check-list branches to Default list lookup');
check($check[$iList + 1]['args'][0] === '$["${DB_EXISTS(allowlist-list/${LOCAL(ALLIST)}/${CALLERID(num)})}"="0"]', 'runtime: named list uses allowlist-list family');
check($check[$iDefault]['args'][0] === '$["${DB_EXISTS(allowlist/${CALLERID(num)})}"="0"]', 'runtime: Default list keeps allowlist family lookup');
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
foreach ($ext->lines as $l) { foreach ($l['args'] as $a) { if (is_string($a) && strpos($a, 'REGEX(') !== false && strpos($a, '^[0-9]{1,4}$') === false) { $bounded += strpos($a, '^[+]?[0-9]{1,20}$') !== false ? 1 : -100; } } }
check($bounded === 5, 'should-fix (a) + L7: all five dialplan REGEX guards cap at 20 digits like PHP/AGI');
$listIdBounded = 0;
foreach ($ext->lines as $l) { foreach ($l['args'] as $a) { if (is_string($a) && strpos($a, 'REGEX(') !== false && strpos($a, '^[0-9]{1,4}$') !== false) { $listIdBounded++; } } }
check($listIdBounded === 1, 'runtime: ALLIST uses 1-4 digit list id REGEX like parseListIdValue');
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

// --- M3: CSRF token on state-changing requests -----------------------------
list($fp, $al) = fresh();
$tok = $al->csrfToken();
check(is_string($tok) && strlen($tok) === 64 && $al->csrfToken() === $tok, 'CSRF token is stable per session');
foreach (array('add' => array('number' => '2025550199'), 'del' => array('number' => '2025550113'), 'pause' => array('pause' => '1'), 'block' => array('number' => '2025550113'), 'bulkdelete' => array('numbers' => '["2025550113"]')) as $cmd => $args) {
	$before = $fp->astman->db;
	foreach (array(null, '', 'forged') as $bad) {
		$r = ajax($al, array('command' => $cmd, 'token' => $bad) + $args);
		check($r['status'] === false && $fp->astman->db === $before, "AJAX $cmd without a valid token is rejected and changes nothing (" . var_export($bad, true) . ')');
	}
}
check($fp->Blacklist->added === array(), 'no blacklist write without a token');
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550198', 'token' => 'x'));
check($r['status'] === false && isset($fp->astman->db['/allowlist/2025550113']), 'edit (add+oldval) also needs the token');
$fp->Config = new class { public function get($k) { return '20.0'; } };
ob_start(); $_REQUEST = array('action' => 'settings', 'pause' => '1', 'goto0' => 'x', 'x0' => 'tst-blocked,s,1');
$al->doConfigPageInit('allowlist'); $html = ob_get_clean();
check(strpos($html, 'nothing was changed') !== false && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1' && $fp->astman->db['/allowlist/pause'] === '0', 'settings form without a token changes nothing');

// --- L1: unsafe caller IDs never reach AGI or Contact Manager ----------------
class RecAGI { public $cmds = array(); public function verbose($m) {} public function database_get($f, $k) { $this->cmds[] = "$f/$k"; return array('result' => 0); } }
$AGI = new RecAGI();
$FreePBX = new FakeFreePBX();
foreach (array('"', '', 'anonymous', '123 456', "1\n2", '+') as $cid) {
	check(searchAsteriskPhonebook($cid) === false && searchContactManager($cid) === false && searchAllowList($cid) === false, 'unsafe CID ' . json_encode($cid) . ' returns false');
}
check($AGI->cmds === array(), 'no AGI DATABASE GET was issued for unsafe CIDs');
searchAsteriskPhonebook('2025550100');
check(in_array('cidname/2025550100', $AGI->cmds, true), 'a sane CID is still looked up');

// --- L3: numbers masked in AGI logs -----------------------------------------
check(allowlistMask('15551234567', false) === '*******4567', 'mask keeps the last 4 digits');
check(allowlistMask('123', false) === '***' && allowlistMask('15551234567', true) === '15551234567', 'short numbers fully masked; debug shows full');
$agiSrc = file_get_contents($root . '/agi-bin/allowlist-check.agi') . file_get_contents($root . '/agi-bin/allowlist-autoadd.agi') . file_get_contents($root . '/agi-bin/allowlist-common.php');
$rawNumberInLog = false;
foreach (preg_split('/\R/', $agiSrc) as $line) {
	if (strpos($line, 'verbose(') === false) { continue; }
	$line = preg_replace('/allowlistMask\([^)]*\)/', '', $line);
	if (preg_match('/(?<!\\\\)\$(callerid|dial_number|number)\b/', $line)) { $rawNumberInLog = true; }
}
check(!$rawNumberInLog, 'no AGI verbose() logs a raw number');

// --- L2: bulk delete cost and cap -------------------------------------------
list($fp, $al) = fresh();
$shows = 0; $orig = $fp->astman;
$fp->astman = new class($orig, $shows) extends FakeAstman { public $n = 0; public function __construct($o) { $this->db = $o->db; } public function database_show($f) { $this->n++; return parent::database_show($f); } };
$al = new Allowlist($fp);
$nums = array(); for ($i = 0; $i < 50; $i++) { $nums[] = (string)(2025551000 + $i); $fp->astman->db['/allowlist/' . (2025551000 + $i)] = 'x'; }
$fp->astman->n = 0;
$r = ajax($al, array('command' => 'bulkdelete', 'numbers' => json_encode($nums)));
check($r['status'] === 'true' && $fp->astman->n === 1, 'bulkdelete of 50 numbers lists AstDB once');
$r = ajax($al, array('command' => 'bulkdelete', 'numbers' => json_encode(array_fill(0, 1001, '2025550113'))));
check($r['status'] === false && isset($fp->astman->db['/allowlist/2025550113']), 'bulkdelete over 1000 numbers is rejected');

// --- L4: legacy CSV upload --------------------------------------------------
list($fp, $al) = fresh();
$src = tempnam(sys_get_temp_dir(), 'altest'); file_put_contents($src, "number,description\n2025550161,ok\ndest,x\n2025550162,\"=1+1\"\n");
$mv = function ($from, $to) { return copy($from, $to); };
$res = $al->importUploadedCsv(array('error' => UPLOAD_ERR_OK, 'name' => 'list.csv', 'tmp_name' => $src), $mv);
check($res['ok'] === false && strpos($res['message'], '1 rows were rejected') !== false && isset($fp->astman->db['/allowlist/2025550161']), 'CSV import reports rejected rows, keeps valid ones');
check($fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'CSV import cannot write settings keys');
$res = $al->importUploadedCsv(array('error' => UPLOAD_ERR_OK, 'name' => 'x.txt', 'tmp_name' => $src), $mv);
check($res['ok'] === false, 'non-CSV upload rejected');
check($al->importUploadedCsv(null)['ok'] === false, 'missing allowlistfile entry rejected');
$cls = file_get_contents($root . '/Allowlist.class.php');
check(strpos($cls, "sys_get_temp_dir() . '/' . \$_FILES") === false && strpos($cls, "\$_FILES['file']") === false, 'no client filename in /tmp, no wrong $_FILES key');
unlink($src);

// --- L5: block forwards only number + description ----------------------------
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'block', 'number' => '2025550113', 'description' => 'spam', 'extra' => 'x', 'module' => 'allowlist'));
check($r['status'] === true && $fp->Blacklist->added === array(array('number' => '2025550113', 'description' => 'spam')), 'Blacklist gets exactly number and description');
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'block', 'number' => '2025550113', 'description' => "a\r\nb"));
check($r['status'] === false && $fp->Blacklist->added === array() && isset($fp->astman->db['/allowlist/2025550113']), 'block with a bad description is rejected');

// --- L6: CSV export formula guard ---------------------------------------------
foreach (array('=HYPERLINK("x")', '+1', '-1', '@SUM(A1)') as $v) {
	check(Allowlist::csvSafeCell($v) === "'" . $v, 'export neutralises ' . $v);
}
check(Allowlist::csvSafeCell('Alice') === 'Alice' && Allowlist::csvSafeCell('+12025550111') === "'+12025550111", 'plain cells unchanged; leading + is quoted');

// --- L7: remove feature code guarded --------------------------------------------
$rm = array_values(array_filter($ext->lines, function ($l) { return $l['ctx'] === 'app-allowlist-remove' && $l['exten'] === '1'; }));
check($rm[0]['app'] === 'ext_set' && $rm[0]['args'][0] === 'ALNROK' && $rm[2]['app'] === 'ext_dbdel', 'remove sets ALNROK and checks it before DBdel');

// --- L8: route/DID writers validated --------------------------------------------
list($fp, $al) = fresh();
check($al->routeAdd(1, 'a', '10') === false && $al->routeAdd(1, '0', '3') === false && $al->routeAdd('1/x', '0', '10') === false, 'routeAdd rejects non-ints and stocount < 7');
check($al->routeAdd(9, -1, 10) === false && $al->routeAdd(9, 0, 1000000) === false, 'routeAdd rejects negative remove and seven-digit store ints');
check($al->routeAdd(20, 0, 10) === true && $al->routeAdd(21, 0, 999999) === true && $fp->astman->db['/allowlist/autoadd/21'] === '1,0,999999', 'routeAdd accepts zero remove and max six-digit store ints');
check($al->routeAdd('2', '1', '10') === true && $fp->astman->db['/allowlist/autoadd/2'] === '1,1,10', 'routeAdd valid stores ints');
check($al->didAdd('5550101', '') === true && isset($fp->astman->db['/allowlist/did/5550101']), 'didAdd digits ok');
check($al->didAdd('', '') === true && $al->didAdd('_X.', '') === true, 'didAdd keeps any-DID and pattern routes');
check($al->didAdd('555"0', '') === false && $al->didAdd('555', 'a/b') === false, 'didAdd rejects quotes and slashes');

// --- AstDB write/delete failures surface ----------------------------------------
list($fp, $al) = fresh();
$fp->astman->failPut = true;
$before = $fp->astman->db;
check($al->numberAdd(array('number' => '2025550199', 'description' => 'x')) === false, 'numberAdd returns false when database_put fails');
check($al->getLastError() !== '' && $fp->astman->db === $before, 'failed put sets lastError and stores nothing');
$fp->astman->failPut = false;
check($al->numberAdd(array('number' => '2025550199')) === true, 'numberAdd succeeds once put works');
$fp->astman->failDel = true;
check($al->numberDel('2025550199') === false && $al->getLastError() !== '', 'numberDel returns false with a message when database_del fails');
$fp->astman->failDel = false;

// --- N1: rejected route-hook settings reach the admin ------------------------
class FakeNotifications { public $warnings = array(); public function add_warning(...$a) { $this->warnings[] = $a; return true; } }
list($fp, $al) = fresh();
$fp->Notifications = new FakeNotifications();
check($al->didAdd('55/01', '') === false, 'didAdd rejects a bad DID');
$msg = $al->reportHookRejection('inbound route');
check(count($fp->Notifications->warnings) === 1 && $fp->Notifications->warnings[0][0] === 'allowlist' && strpos($fp->Notifications->warnings[0][3], $al->getLastError()) !== false, 'rejected didAdd raises an admin notification with the reason');
check(strpos($msg, '55') === false, 'hook notification never contains the DID');
list($fp, $al) = fresh();
check($al->routeAdd('1', '0', '0') === false && is_string($al->reportHookRejection('outbound route auto-add')), 'reporting works without a Notifications module');
$fn = file_get_contents($root . '/functions.inc.php');
check(preg_match_all('/if \(!\$allowlist->(didAdd|routeAdd)\(/', $fn) === 4 && preg_match_all('/^\s*\$allowlist->(didAdd|routeAdd)\(/m', $fn) === 0, 'every didAdd/routeAdd hook call checks the result');
check(preg_match_all('/if \(\$allowlist->(didDelete|routeDelete)\([^)]*\) === false\)/', $fn) === 5 && preg_match_all('/^\s*\$allowlist->(didDelete|routeDelete)\(/m', $fn) === 0, 'every didDelete/routeDelete hook call checks the result');

// --- N2: restore skip-log masks number keys -----------------------------------
list($fp, $al) = fresh();
$fp->Allowlist = $al;
$restore = new \FreePBX\modules\Allowlist\Restore($fp);
$restore->restoreAllowlistEntries(array('2025550188' => str_repeat('a', 300)));
$logged = implode("\n", array_column($restore->logs, 1));
check(strpos($logged, '2025550188') === false && strpos($logged, '******0188') !== false, 'restore skip-log masks a number key to its last 4 digits');
$restore->restoreAllowlistEntries(array('dest' => 'bogus-ctx,s,1'));
check(strpos(implode("\n", array_column($restore->logs, 1)), 'dest:') !== false, 'restore skip-log keeps setting names readable');

// --- Bugbot: edit and destination writes are all-or-nothing ---------------------
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'edit', 'oldval' => '2025550113', 'number' => '20255501x3', 'description' => 'new'));
check($r['status'] === false && $fp->astman->db['/allowlist/2025550113'] === 'old entry', 'edit with an invalid new number keeps the old entry');
$fp->astman->failPut = true;
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
check($r['status'] === false && $r['message'] !== '' && $fp->astman->db['/allowlist/2025550113'] === 'old entry' && !isset($fp->astman->db['/allowlist/2025550114']), 'edit with failed put keeps the old entry');
$fp->astman->failPut = false;
$fp->astman->failDel = true;
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
$fp->astman->failDel = false;
check($r['status'] === false && $r['message'] !== '' && isset($fp->astman->db['/allowlist/2025550113']), 'edit with failed delete reports failure and keeps the old entry');
// failDel also blocks the rollback delete in this fake, so check the restore path with an existing target
list($fp, $al) = fresh();
$fp->astman->db['/allowlist/2025550114'] = 'target before';
$fp->astman->failDel = true;
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
$fp->astman->failDel = false;
check($r['status'] === false && $fp->astman->db['/allowlist/2025550114'] === 'target before', 'edit with failed delete restores the prior value of the new key');
// Bugbot/Claude: a failed rollback is reported, not hidden behind "nothing was changed"
list($fp, $al) = fresh();
$fp->astman->failDel = true; // blocks the old-key delete and the rollback delete
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
$fp->astman->failDel = false;
check($r['status'] === false && strpos($r['message'], 'may still be') !== false && strpos($r['message'], 'nothing was changed') === false, 'edit with failed rollback delete says the new number may remain');
list($fp, $al) = fresh();
$fp->astman->db['/allowlist/2025550114'] = 'target before';
$fp->astman->failDel = true;
$fp->astman->failPutAfter = 1; // the edit put succeeds, the rollback put fails
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
$fp->astman->failDel = false; $fp->astman->failPutAfter = -1;
check($r['status'] === false && strpos($r['message'], 'may still be') !== false, 'edit with failed rollback put says the new number may remain');
list($fp, $al) = fresh();
$fp->astman->db['/allowlist/2025550114'] = 'target before';
$fp->astman->failDel = true;
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
$fp->astman->failDel = false;
check($r['status'] === false && strpos($r['message'], 'nothing was changed') !== false, 'edit with a successful rollback still says nothing was changed');
list($fp, $al) = fresh();
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550113', 'description' => 'renamed'));
check($r['status'] === true && $fp->astman->db['/allowlist/2025550113'] === 'renamed', 'edit of the description only keeps the entry');
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550113', 'number' => '2025550114', 'description' => 'new'));
check($r['status'] === true && !isset($fp->astman->db['/allowlist/2025550113']) && $fp->astman->db['/allowlist/2025550114'] === 'new', 'edit to a new number writes it, then removes the old one');
$r = ajax($al, array('command' => 'add', 'oldval' => '2025550177', 'number' => '2025550115', 'description' => 'x'));
check($r['status'] === false && !isset($fp->astman->db['/allowlist/2025550115']), 'edit of a missing old number writes nothing');
list($fp, $al) = fresh();
$fp->astman->failPut = true;
check($al->destinationSet('tst-blocked,s,1') === false && $al->getLastError() !== '' && $fp->astman->db['/allowlist/dest'] === 'app-blackhole,hangup,1', 'destinationSet failed put keeps previous dest with a message');
$fp->astman->failPut = false;

// Route hooks (core module edit paths)
if (!defined('FREEPBX_IS_AUTH')) { define('FREEPBX_IS_AUTH', true); }
if (!class_exists('FreePBX')) { eval('class FreePBX { public static $al; public static function Allowlist() { return self::$al; } }'); }
require_once $root . '/functions.inc.php';
list($fp, $al) = fresh();
$fp->Notifications = new FakeNotifications();
FreePBX::$al = $al;
unset($fp->astman->db['/allowlist/did/5550100/']);
$fp->astman->db['/allowlist/did/5550100'] = '1'; // the key didAdd/didDelete use for a route with no CID
$base = array('action' => 'edtIncoming', 'extdisplay' => '5550100/', 'enable_allowlist' => 'yes', 'cidnum' => '');
allowlist_hookProcess_core(null, $base + array('extension' => '555"0100'));
check(isset($fp->astman->db['/allowlist/did/5550100']), 'edtIncoming with an invalid DID keeps the old setting');
check(count($fp->Notifications->warnings) === 1, 'edtIncoming with an invalid DID reports one error');
$fp->astman->failPut = true;
allowlist_hookProcess_core(null, $base + array('extension' => '5550102'));
$fp->astman->failPut = false;
check(isset($fp->astman->db['/allowlist/did/5550100']) && !isset($fp->astman->db['/allowlist/did/5550102']) && count($fp->Notifications->warnings) === 2, 'edtIncoming whose write fails keeps the old setting and reports it');
allowlist_hookProcess_core(null, $base + array('extension' => '5550102'));
check(!isset($fp->astman->db['/allowlist/did/5550100']) && isset($fp->astman->db['/allowlist/did/5550102']) && count($fp->Notifications->warnings) === 2, 'edtIncoming with a valid DID writes the new setting, then removes the old one');
allowlist_hookProcess_core(null, array('action' => 'edtIncoming', 'extdisplay' => '5550102/', 'enable_allowlist' => 'no', 'extension' => '5550102', 'cidnum' => ''));
check(!isset($fp->astman->db['/allowlist/did/5550102']), 'edtIncoming with allowlist turned off removes the setting');
$rt = array('action' => 'editroute', 'id' => '1', 'autoadd_allowlist' => 'yes', 'remcount' => '0');
allowlist_hookProcess_core(null, $rt + array('stocount' => '3'));
check($fp->astman->db['/allowlist/autoadd/1'] === '1,0,99' && count($fp->Notifications->warnings) === 3, 'editroute with an invalid count keeps the old auto-add setting and reports it');
$fp->astman->failPut = true;
allowlist_hookProcess_core(null, $rt + array('stocount' => '10'));
$fp->astman->failPut = false;
check($fp->astman->db['/allowlist/autoadd/1'] === '1,0,99' && count($fp->Notifications->warnings) === 4, 'editroute whose write fails keeps the old setting and reports it');
allowlist_hookProcess_core(null, $rt + array('stocount' => '10'));
check($fp->astman->db['/allowlist/autoadd/1'] === '1,0,10', 'editroute with valid counts updates the setting');
allowlist_hookProcess_core(null, array('action' => 'editroute', 'id' => '1', 'autoadd_allowlist' => 'no'));
check(!isset($fp->astman->db['/allowlist/autoadd/1']), 'editroute with auto-add turned off removes the setting');

list($fp, $al) = fresh();
$fp->Notifications = new FakeNotifications();
FreePBX::$al = $al;
$warn0 = count($fp->Notifications->warnings);
allowlist_hookProcess_core(null, array('action' => 'delIncoming', 'extdisplay' => '5550301/'));
check(count($fp->Notifications->warnings) === $warn0, 'delIncoming with no did mapping adds no HOOKREJECT warning');
allowlist_hookProcess_core(null, array('action' => 'edtIncoming', 'extdisplay' => '5550302/', 'enable_allowlist' => 'no', 'extension' => '5550302', 'cidnum' => ''));
check(count($fp->Notifications->warnings) === $warn0, 'edtIncoming screening off with no mapping adds no HOOKREJECT warning');
allowlist_hookProcess_core(null, array('action' => 'delroute', 'id' => '88'));
check(count($fp->Notifications->warnings) === $warn0, 'delroute with no autoadd mapping adds no HOOKREJECT warning');
allowlist_hookProcess_core(null, array('action' => 'editroute', 'id' => '88', 'autoadd_allowlist' => 'no'));
check(count($fp->Notifications->warnings) === $warn0, 'editroute autoadd off with no mapping adds no HOOKREJECT warning');
$fp->astman->db['/allowlist/did/5550303'] = '1';
$fp->astman->failDel = true;
allowlist_hookProcess_core(null, array('action' => 'delIncoming', 'extdisplay' => '5550303/'));
check(count($fp->Notifications->warnings) === $warn0 + 1 && $al->getLastError() !== '' && strpos($fp->Notifications->warnings[$warn0][3], $al->getLastError()) !== false, 'delIncoming reports non-empty reason on real delete failure');
$fp->astman->failDel = false;
check($al->didDelete('5550998', '') === true && $al->getLastError() === '', 'didDelete missing key succeeds without lastError');
check($al->routeDelete(77) === true && $al->getLastError() === '', 'routeDelete missing key succeeds without lastError');
$smokeDid = $al->defaultSmokeListMutationDid();
$fp->astman->db['/allowlist/did/' . $smokeDid] = '1';
check($al->didIsSet($smokeDid, '') === true, 'smoke DID collision is detectable before assignment');
$listMetaBefore = array_filter(array_keys($fp->astman->db), function ($k) { return strpos($k, '/allowlist-lists/') === 0; });
if (!$al->didIsSet($smokeDid, '')) {
	$al->listCreate('zz-smoke-abort');
}
$listMetaAfter = array_filter(array_keys($fp->astman->db), function ($k) { return strpos($k, '/allowlist-lists/') === 0; });
check($listMetaBefore === $listMetaAfter, 'smoke DID pre-check abort writes no list metadata');

list($fp, $al) = fresh();
$smokeListId = $al->listCreate('zz-smoke-assign');
$fp->astman->db['/allowlist/did/5550911'] = '1';
check($al->didAddRouteIfUnassigned('5550911', '', $smokeListId) === false && $al->getLastError() !== '', 'didAddRouteIfUnassigned refuses occupied did under lock');
check($fp->astman->db['/allowlist/did/5550911'] === '1', 'didAddRouteIfUnassigned does not overwrite existing did mapping');
$didAssigned = false;
$createdListId = $smokeListId;
$leftoverPartial = $al->cleanupSmokeListMutationArtifacts('5550911', $createdListId, $didAssigned);
check($createdListId === false && !isset($fp->astman->db['/allowlist-lists/' . $smokeListId]), 'smoke cleanup deletes list when route was never assigned');

list($fp, $al) = fresh();
$smokeDidRetry = '5550914';
$createdListId = $al->listCreate('zz-smoke-retry');
$listIdRetry = $createdListId;
$didAssigned = ($al->didAddRouteIfUnassigned($smokeDidRetry, '', $createdListId) === true);
$fp->astman->failDel = true;
$al->didDelete($smokeDidRetry, '');
check($didAssigned === true && isset($fp->astman->db['/allowlist/did/' . $smokeDidRetry]), 'smoke try unassign failure leaves didAssigned set for finally retry');
$fp->astman->failDel = false;
$leftoverRetry = $al->cleanupSmokeListMutationArtifacts($smokeDidRetry, $createdListId, $didAssigned);
check($didAssigned === false && $createdListId === false && $leftoverRetry['did'] === false && $leftoverRetry['listId'] === false, 'smoke cleanup retries did unassign and deletes list');
check(!isset($fp->astman->db['/allowlist/did/' . $smokeDidRetry]) && !isset($fp->astman->db['/allowlist-lists/' . $listIdRetry]), 'smoke cleanup retry leaves no DID or list metadata');

list($fp, $al) = fresh();
$smokeDidFail = '5550915';
$createdListId = $al->listCreate('zz-smoke-leftover');
$didAssigned = ($al->didAddRouteIfUnassigned($smokeDidFail, '', $createdListId) === true);
$fp->astman->failDel = true;
$leftoverFail = $al->cleanupSmokeListMutationArtifacts($smokeDidFail, $createdListId, $didAssigned);
$msgs = $al->smokeListMutationCleanupFailureMessages($leftoverFail);
check($leftoverFail['did'] === $smokeDidFail && $leftoverFail['listId'] !== false, 'smoke cleanup reports leftover DID and list when teardown fails');
check(count($msgs) >= 2 && strpos($msgs[0], $smokeDidFail) !== false && strpos($msgs[1], 'zz-smoke-leftover') !== false, 'smoke cleanup failure messages name leftover DID and list');

list($fp, $al) = fresh();
$smokeListId2 = $al->listCreate('zz-smoke-lockdid');
$raceDid = '5550912';
check($al->didAddRouteIfUnassigned($raceDid, '', $smokeListId2) === true, 'didAddRouteIfUnassigned assigns free did');
$al->listDelete($smokeListId2);

list($fp, $al) = fresh();
$fp->astman->db['/allowlist/autoadd/9'] = '1,0,10';
$fp->astman->failDel = true;
$fp->astman->failDelDropsConnection = true;
check($al->routeDelete(9) === false && $al->getLastError() !== '' && isset($fp->astman->db['/allowlist/autoadd/9']), 'routeDelete fails when AMI drops during delete with key still present');
list($fp, $al) = fresh();
$fp->Notifications = new FakeNotifications();
FreePBX::$al = $al;
$fp->astman->db['/allowlist/autoadd/10'] = '1,0,10';
$fp->astman->failDel = true;
$fp->astman->failDelDropsConnection = true;
$warnRoute = count($fp->Notifications->warnings);
allowlist_hookProcess_core(null, array('action' => 'editroute', 'id' => '10', 'autoadd_allowlist' => 'no'));
check(count($fp->Notifications->warnings) === $warnRoute + 1 && $al->getLastError() !== '' && strpos($fp->Notifications->warnings[$warnRoute][3], $al->getLastError()) !== false, 'editroute autoadd off reports failure when AMI drops during routeDelete');

// --- Restore skip-log masks did/<DID>/<CID> keys ------------------------------
list($fp, $al) = fresh();
$fp->Allowlist = $al;
$restore = new \FreePBX\modules\Allowlist\Restore($fp);
$restore->restoreAllowlistEntries(array('did/15551230000/15559870000' => "bad\nvalue"));
$logged = implode("\n", array_column($restore->logs, 1));
check(strpos($logged, 'did/') !== false && substr_count($logged, '*******0000') === 2 && !preg_match('/\d{5,}/', $logged), 'restore skip-log masks did/<DID>/<CID> keys to the last 4 digits of each number');

// --- D1: editing a legacy non-numeric entry to a valid number removes it -------
list($fp, $al) = fresh();
$fp->astman->failPut = true;
$r = ajax($al, array('command' => 'edit', 'oldval' => 'sipuser', 'number' => '2025550120', 'description' => 'x'));
$fp->astman->failPut = false;
check($r['status'] === false && $fp->astman->db['/allowlist/sipuser'] === 'legacy', 'legacy edit whose write fails keeps the legacy entry');
$r = ajax($al, array('command' => 'edit', 'oldval' => 'sipuser', 'number' => 'not-a-number', 'description' => 'x'));
check($r['status'] === false && $fp->astman->db['/allowlist/sipuser'] === 'legacy', 'legacy edit to an invalid number keeps the legacy entry');
$r = ajax($al, array('command' => 'edit', 'oldval' => 'sipuser', 'number' => '2025550120', 'description' => 'x'));
check($r['status'] === true && !isset($fp->astman->db['/allowlist/sipuser']) && isset($fp->astman->db['/allowlist/2025550120']), 'legacy edit to a valid number saves it, then removes the legacy key');
$r = ajax($al, array('command' => 'edit', 'oldval' => 'dest', 'number' => '2025550121', 'description' => 'x'));
check(isset($fp->astman->db['/allowlist/dest']), 'edit never removes a settings key named as oldval');

foreach (array('1' => 1, '42' => 42, '9999' => 9999) as $raw => $want) {
	check(Allowlist::parseListIdValue($raw) === $want, 'parseListIdValue accepts ' . $raw);
	check(Allowlist::parseDidMappingValue($raw) === $want, 'parseDidMappingValue accepts ' . $raw);
}
foreach (array('0', '-1', '1;Hangup', '2/../x', '10000', '', 'abc') as $bad) {
	check(Allowlist::parseListIdValue($bad) === false, 'parseListIdValue rejects ' . json_encode($bad));
	check(Allowlist::parseDidMappingValue($bad) === false, 'parseDidMappingValue rejects ' . json_encode($bad));
}
check(Allowlist::parseAutoaddMappingValue('1,0,99')['listId'] === 1, 'autoadd parse defaults list id to 1');
check(Allowlist::parseAutoaddMappingValue('1,1,10,3') === array('rem' => 1, 'sto' => 10, 'listId' => 3), 'autoadd parse reads list id');
check(Allowlist::formatAutoaddMappingValue(0, 99) === '1,0,99', 'autoadd format omits default list id');
check(Allowlist::formatAutoaddMappingValue(0, 99, 2) === '1,0,99,2', 'autoadd format includes named list id');
list($fp, $al) = fresh();
unset($fp->astman->db['/allowlist-lists/1']);
check($al->listExists(1) === true && $al->listGetName(1) === 'Default' && ($al->listGetAll()[1] ?? null) === 'Default', 'list 1 exists with Default name before metadata key');
check($al->didAdd('5550299', '') === true && $al->routeAdd(7, '0', '10') === true, 'didAdd and routeAdd default list succeed without metadata key');
check($al->upgradeAllowlistDataModel() === true && ($fp->astman->db['/allowlist-lists/1'] ?? null) === 'Default', 'upgrade writes Default list metadata once');
$dbAfterFirst = $fp->astman->db;
check($al->upgradeAllowlistDataModel() === true && $fp->astman->db === $dbAfterFirst, 'upgrade is idempotent');
$fp->astman->db['/allowlist/did/5550199/'] = 'yes';
$fp->astman->db['/allowlist/autoadd/9'] = '2,0,99';
$bad = $al->scanUnparseableAllowlistMappings();
check(count($bad) === 2, 'upgrade scan logs unparseable did and autoadd keys');
check($fp->astman->db['/allowlist/did/5550199/'] === 'yes', 'upgrade scan does not rewrite bad values');
list($fp, $al) = fresh();
$al->auditEntries = array();
$fp->astman->db['/allowlist/did/15551230000/15559870000'] = 'not-valid';
$al->scanUnparseableAllowlistMappings();
$logged = implode("\n", $al->auditEntries);
check(strpos($logged, '15551230000') === false && strpos($logged, '15559870000') === false && substr_count($logged, '*******0000') >= 1, 'upgrade scan audit masks did route numbers');

list($fp, $al) = freshHarness();
$unitLockPath = sys_get_temp_dir() . '/allowlist-unit-mutation-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$unitDbPath = sys_get_temp_dir() . '/allowlist-unit-mutation-db-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
$unitPeerReadyPath = sys_get_temp_dir() . '/allowlist-unit-mutation-ready-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.flag';
$al->testListMutationLockPath = $unitLockPath;
allowlistUnitWriteDb($fp, $unitDbPath);
$unitLockHeld = fopen($unitLockPath, 'c+');
check($unitLockHeld !== false && flock($unitLockHeld, LOCK_EX), 'parent holds mutation lock for peer test');
$peerCmd = array(PHP_BINARY, __DIR__ . '/list_mutation_lock_peer.php', $unitLockPath, $unitDbPath, 'Second', $unitPeerReadyPath);
$peerSpec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$peer = proc_open($peerCmd, $peerSpec, $peerPipes);
check(is_resource($peer), 'list mutation peer process starts');
$peerReadyDeadline = microtime(true) + 5;
while (!is_readable($unitPeerReadyPath) && microtime(true) < $peerReadyDeadline)
{
	usleep(10000);
}
check(is_readable($unitPeerReadyPath), 'peer blocks acquiring mutation lock before parent listCreate');
flock($unitLockHeld, LOCK_UN);
fclose($unitLockHeld);
$al->testAfterListCreateWrite = function ($locked, $next) use ($unitLockPath, $fp, $unitDbPath) {
	$h = fopen($unitLockPath, 'c+');
	$contended = flock($h, LOCK_EX | LOCK_NB);
	check(!$contended, 'listCreate holds exclusive mutation lock');
	fclose($h);
	check($next === 2, 'first list id allocated under lock');
	allowlistUnitWriteDb($fp, $unitDbPath);
};
$id2 = $al->listCreate('First');
$al->testAfterListCreateWrite = null;
check($id2 === 2 && $al->listGetName(2) === 'First', 'listCreate stores first named list under lock');
$peerOut = stream_get_contents($peerPipes[1]);
proc_close($peer);
check(trim($peerOut) === '3', 'blocked listCreate observes first id and allocates 3');
allowlistUnitUnlinkIfExists($unitLockPath);
allowlistUnitUnlinkIfExists($unitDbPath);
allowlistUnitUnlinkIfExists($unitPeerReadyPath);
list($fp, $al) = freshHarness();
$al->testListMutationLockPath = $unitLockPath;
$al->testListMutationLockWaitSec = 0.35;
$beforeLists = $fp->astman->db;
$extHeld = fopen($unitLockPath, 'c+');
check($extHeld !== false && flock($extHeld, LOCK_EX), 'test holds mutation lock');
$blocked = $al->listCreate('Blocked');
flock($extHeld, LOCK_UN);
fclose($extHeld);
check($blocked === false && $al->getLastError() !== '' && $fp->astman->db === $beforeLists, 'listCreate fails when lock not acquired');
check($fp->astman->db === $beforeLists, 'lock-timeout listCreate writes no metadata');
allowlistUnitUnlinkIfExists($unitLockPath);

list($fp, $al) = freshHarness();
$unitLockPath2 = sys_get_temp_dir() . '/allowlist-unit-member-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$unitDbPath2 = sys_get_temp_dir() . '/allowlist-unit-member-db-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
$unitMemberReadyPath = sys_get_temp_dir() . '/allowlist-unit-member-ready-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.flag';
$id2 = $al->listCreate('Gone');
check($id2 === 2, 'member race test creates list 2');
$al->testListMutationLockPath = $unitLockPath2;
$al->testAfterListDeleteWrite = function ($locked, $deletedId) use ($fp, $unitDbPath2) {
	allowlistUnitWriteDb($fp, $unitDbPath2);
};
allowlistUnitWriteDb($fp, $unitDbPath2);
$memberHeld = fopen($unitLockPath2, 'c+');
check($memberHeld !== false && flock($memberHeld, LOCK_EX), 'parent holds lock for member-delete race');
$memberPeerCmd = array(PHP_BINARY, __DIR__ . '/list_mutation_member_peer.php', $unitLockPath2, $unitDbPath2, '2', '2025550299', $unitMemberReadyPath);
$memberPeerSpec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$memberPeer = proc_open($memberPeerCmd, $memberPeerSpec, $memberPeerPipes);
check(is_resource($memberPeer), 'member peer process starts');
$memberReadyDeadline = microtime(true) + 5;
while (!is_readable($unitMemberReadyPath) && microtime(true) < $memberReadyDeadline)
{
	usleep(10000);
}
check(is_readable($unitMemberReadyPath), 'member peer blocks on mutation lock');
flock($memberHeld, LOCK_UN);
fclose($memberHeld);
check($al->listDelete(2) === true, 'listDelete completes under race with member add');
$al->testAfterListDeleteWrite = null;
allowlistUnitWriteDb($fp, $unitDbPath2);
$memberErr = stream_get_contents($memberPeerPipes[2]);
$memberOut = stream_get_contents($memberPeerPipes[1]);
proc_close($memberPeer);
check(trim($memberOut) === '' && trim($memberErr) !== '' && strpos($memberErr, 'Unknown allowlist') !== false, 'member add after list delete sets lastError');
$memberPeerDb = is_readable($unitDbPath2) ? json_decode(file_get_contents($unitDbPath2), true) : array();
check(!is_array($memberPeerDb) || !isset($memberPeerDb['/allowlist-list/2/2025550299']), 'member add after list delete writes no member key in peer db');
allowlistUnitUnlinkIfExists($unitLockPath2);
allowlistUnitUnlinkIfExists($unitDbPath2);
allowlistUnitUnlinkIfExists($unitMemberReadyPath);

list($fpNoSpool, $alNoSpool) = fresh();
unset($alNoSpool->FreePBX->Config);
$beforeNoSpool = $fpNoSpool->astman->db;
check($alNoSpool->listCreate('NoSpool') === false && strpos($alNoSpool->getLastError(), 'spool') !== false, 'listCreate fails closed without ASTSPOOLDIR');
check($fpNoSpool->astman->db === $beforeNoSpool, 'no-spool listCreate writes nothing');

list($fp, $al) = freshHarness();
$runAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if (!$runAsRoot) {
	$badLockPath = '/root/allowlist-unit-lock-deny-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
	$al->testListMutationLockPath = $badLockPath;
	$beforeBadLock = $fp->astman->db;
	check($al->listCreate('BadLock') === false && strpos($al->getLastError(), 'lock file') !== false, 'listCreate reports lock open failure');
	check($fp->astman->db === $beforeBadLock, 'lock open failure writes no metadata');
} else {
	check(true, 'listCreate lock open failure test skipped when run as root');
}

list($fp, $al) = freshHarness();
$provisionLockPath = sys_get_temp_dir() . '/allowlist-unit-provision-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $provisionLockPath;
$al->provisionListMutationLockForInstall();
check($al->listCreate('Provisioned') === 2 && is_file($provisionLockPath), 'listCreate creates provisioned lock file');
check((fileperms($provisionLockPath) & 0777) === 0660, 'provisioned lock file mode is 0660');
allowlistUnitUnlinkIfExists($provisionLockPath);

list($fp, $al) = freshHarness();
$nestedLockPath = sys_get_temp_dir() . '/allowlist-unit-nested-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $nestedLockPath;
$al->testAfterListCreateWrite = function ($locked, $next) use ($nestedLockPath, $al) {
	$h = fopen($nestedLockPath, 'r');
	$contended = flock($h, LOCK_EX | LOCK_NB);
	check(!$contended, 'outer mutation lock stays held for nested call');
	fclose($h);
	check($al->listRename($next, 'NestedName') === true && $al->listGetName($next) === 'NestedName', 'nested listRename succeeds under reentrant lock');
};
check($al->listCreate('Nested') === 2, 'listCreate with nested rename completes');
$al->testAfterListCreateWrite = null;
allowlistUnitUnlinkIfExists($nestedLockPath);

list($fp, $al) = freshHarness();
$raceLock = sys_get_temp_dir() . '/allowlist-unit-didrace-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $raceLock;
check($al->listCreate('RaceDid') === 2, 'didAdd race test creates list 2');
$al->testBeforeListMutationLockAcquire = function ($me) {
	allowlistUnitDeleteListForHook($me, 2);
};
check($al->didAdd('5550888', '', 2) === false && $al->getLastError() !== '', 'didAdd after concurrent delete sets lastError');
check(!isset($fp->astman->db['/allowlist/did/5550888']), 'didAdd after delete writes no did mapping');
$al->testBeforeListMutationLockAcquire = null;
allowlistUnitUnlinkIfExists($raceLock);

list($fp, $al) = freshHarness();
$raceLock2 = sys_get_temp_dir() . '/allowlist-unit-routerace-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $raceLock2;
check($al->listCreate('RaceRoute') === 2, 'routeAdd race test creates list 2');
$al->testBeforeListMutationLockAcquire = function ($me) {
	allowlistUnitDeleteListForHook($me, 2);
};
check($al->routeAdd(8, '0', '10', 2) === false && $al->getLastError() !== '', 'routeAdd after concurrent delete sets lastError');
check(!isset($fp->astman->db['/allowlist/autoadd/8']), 'routeAdd after delete writes no autoadd mapping');
$al->testBeforeListMutationLockAcquire = null;
allowlistUnitUnlinkIfExists($raceLock2);

list($fp, $al) = freshHarness();
$renameRaceLock = sys_get_temp_dir() . '/allowlist-unit-renamerace-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $renameRaceLock;
check($al->listCreate('RenameRace') === 2, 'listRename race test creates list 2');
$al->testBeforeListMutationLockAcquire = function ($me) {
	allowlistUnitDeleteListForHook($me, 2);
};
check($al->listRename(2, 'Lost') === false && $al->getLastError() !== '', 'listRename after delete sets lastError');
check(!isset($fp->astman->db['/allowlist-lists/2']), 'listRename after delete writes no list metadata');
$al->testBeforeListMutationLockAcquire = null;
allowlistUnitUnlinkIfExists($renameRaceLock);

list($fp, $al) = freshHarness();
$memDelRaceLock = sys_get_temp_dir() . '/allowlist-unit-memdelrace-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $memDelRaceLock;
check($al->listCreate('MemDelRace') === 2, 'listMemberDel race test creates list 2');
$al->listMemberAdd(2, array('number' => '2025550881'));
$al->testBeforeListMutationLockAcquire = function ($me) {
	allowlistUnitDeleteListForHook($me, 2);
};
check($al->listMemberDel(2, '2025550881') === false && $al->getLastError() !== '', 'listMemberDel after delete sets lastError');
check(!isset($fp->astman->db['/allowlist-list/2/2025550881']), 'listMemberDel after delete removes no member key');
$al->testBeforeListMutationLockAcquire = null;
allowlistUnitUnlinkIfExists($memDelRaceLock);

list($fp, $al) = freshHarness();
$delPeerLock = sys_get_temp_dir() . '/allowlist-unit-delpeer-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$delPeerDb = sys_get_temp_dir() . '/allowlist-unit-delpeer-db-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.json';
$delPeerReady = sys_get_temp_dir() . '/allowlist-unit-delpeer-ready-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.flag';
$al->testListMutationLockPath = $delPeerLock;
check($al->listCreate('PeerDid') === 2, 'peer didAdd race creates list 2');
allowlistUnitWriteDb($fp, $delPeerDb);
$delHeld = fopen($delPeerLock, 'c+');
check($delHeld !== false && flock($delHeld, LOCK_EX), 'parent holds lock for delete peer didAdd race');
$delPeerCmd = array(PHP_BINARY, __DIR__ . '/list_mutation_delete_peer.php', $delPeerLock, $delPeerDb, '2', $delPeerReady);
$delPeerSpec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$delPeerProc = proc_open($delPeerCmd, $delPeerSpec, $delPeerPipes);
check(is_resource($delPeerProc), 'delete peer starts for didAdd race');
$delReadyDeadline = microtime(true) + 5;
while (!is_readable($delPeerReady) && microtime(true) < $delReadyDeadline) { usleep(10000); }
check(is_readable($delPeerReady), 'delete peer blocks on lock');
flock($delHeld, LOCK_UN);
fclose($delHeld);
proc_close($delPeerProc);
$al->testListMutationDbReloadPath = $delPeerDb;
check($al->didAdd('5550777', '', 2) === false && $al->getLastError() !== '', 'didAdd with peer delete race sets lastError');
check(!isset($fp->astman->db['/allowlist/did/5550777']), 'didAdd with peer delete writes no did key');
$al->testListMutationDbReloadPath = null;
allowlistUnitUnlinkIfExists($delPeerLock);
allowlistUnitUnlinkIfExists($delPeerDb);
allowlistUnitUnlinkIfExists($delPeerReady);

list($fp, $al) = freshHarness();
$al->testListMutationLockPath = $delPeerLock;
$al->testListMutationLockWaitSec = 0.35;
$fp->astman->db['/allowlist/did/5550666'] = '1';
$didHeld = fopen($delPeerLock, 'c+');
check($didHeld !== false && flock($didHeld, LOCK_EX), 'test holds lock for didDelete timeout');
check($al->didDelete('5550666', '') === false && $al->getLastError() !== '', 'didDelete fails when lock not acquired');
check(isset($fp->astman->db['/allowlist/did/5550666']), 'didDelete lock timeout keeps did mapping');
flock($didHeld, LOCK_UN);
fclose($didHeld);
$fp->astman->db['/allowlist/autoadd/6'] = '1,0,10';
$routeHeld = fopen($delPeerLock, 'c+');
check($routeHeld !== false && flock($routeHeld, LOCK_EX), 'test holds lock for routeDelete timeout');
check($al->routeDelete(6) === false && $al->getLastError() !== '', 'routeDelete fails when lock not acquired');
check(isset($fp->astman->db['/allowlist/autoadd/6']), 'routeDelete lock timeout keeps autoadd mapping');
flock($routeHeld, LOCK_UN);
fclose($routeHeld);
allowlistUnitUnlinkIfExists($delPeerLock);

list($fp, $al) = freshHarness();
$uninstallLockPath = sys_get_temp_dir() . '/allowlist-unit-uninstall-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.lock';
$al->testListMutationLockPath = $uninstallLockPath;
$al->provisionListMutationLockForInstall();
check(is_file($uninstallLockPath), 'uninstall test provisions lock file');
$al->uninstall();
check(!is_file($uninstallLockPath), 'uninstall removes list-mutation lock file');

list($fp, $al) = fresh();
$id2 = $al->listCreate('Sales');
check($id2 === 2 && $al->listGetName(2) === 'Sales', 'listCreate stores named list');
check($al->listMemberAdd(2, array('number' => '2025550200', 'description' => 'Bob')) === true && isset($fp->astman->db['/allowlist-list/2/2025550200']), 'named list member stored under allowlist-list');
check($al->listMemberAdd(1, array('number' => '2025550201')) === true && isset($fp->astman->db['/allowlist/2025550201']), 'list 1 members stay under allowlist');
check(Allowlist::formatListNameForDisplay('<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;', 'list name htmlentities on output helper');
check(Allowlist::normalizeListName("a\n") === false && $al->listCreate('a/b') === false, 'list name rejects CR/LF and slash');

list($fp, $al) = fresh();
check($al->listDelete(1) === false && strpos($al->getLastError(), 'default') !== false, 'listDelete refuses list 1');
$id2 = $al->listCreate('Assigned');
$al->didAdd('5550202', '', 2);
check($al->listDelete(2) === false && strpos($al->getLastError(), '5550202') !== false, 'listDelete refuses assigned inbound route');
$al->didDelete('5550202', '');
$al->routeAdd(5, '0', '10', 2);
check($al->listDelete(2) === false && strpos($al->getLastError(), '5') !== false, 'listDelete refuses assigned outbound route');
$al->routeDelete(5);
check($al->listDelete(2) === true && !isset($fp->astman->db['/allowlist-lists/2']), 'listDelete removes metadata when unassigned');
list($fp, $al) = fresh();
$id2 = $al->listCreate('FailDel');
$al->listMemberAdd(2, array('number' => '2025550210'));
$fp->astman->failDel = true;
check($al->listDelete(2) === false && $al->getLastError() !== '' && isset($fp->astman->db['/allowlist-lists/2']), 'listDelete with failed member delete keeps metadata');
$fp->astman->failDel = false;
list($fp, $al) = fresh();
$id2 = $al->listCreate('MemDel');
$al->listMemberAdd(2, array('number' => '2025550211'));
$fp->astman->failDel = true;
check($al->listMemberDel(2, '2025550211') === false && $al->getLastError() !== '', 'listMemberDel sets lastError when delete fails');
$fp->astman->failDel = false;

list($fp, $al) = fresh();
$fp->astman->connectedState = false;
$threw = false;
try { $al->upgradeAllowlistDataModel(); } catch (\Throwable $e) { $threw = true; }
check($threw, 'upgradeAllowlistDataModel throws when Asterisk Manager is down');
$fp->Notifications = new FakeNotifications();
$threw = false;
try { $al->install(); } catch (\Throwable $e) { $threw = true; }
check(!$threw && count($fp->Notifications->warnings) === 1, 'install skips upgrade and warns when Asterisk Manager is down');
$fp->astman->connectedState = true;

list($fp, $al) = fresh();
foreach (array('0', '-1', '1;Hangup', '2/../x', 99) as $bad) {
	check($al->didAdd('5550203', '', $bad) === false, 'didAdd rejects list id ' . json_encode($bad));
}
check($al->didAdd('5550203', '', 99) === false, 'didAdd rejects nonexistent list id');
$al->listCreate('Target');
check($al->didAdd('5550203', '', 2) === true && $fp->astman->db['/allowlist/did/5550203'] === '2', 'didAdd stores one list id per route');
check($al->didAdd('5550203', '', 2) === true && $fp->astman->db['/allowlist/did/5550203'] === '2', 'didAdd replace never appends');
check($al->didGetListId('5550203', '') === 2, 'didGetListId reads stored mapping');

list($fp, $al) = fresh();
check($al->routeAdd(3, '0', '10', '0') === false, 'routeAdd rejects list id 0');
$al->listCreate('Outbound');
check($al->routeAdd(3, '0', '10', 2) === true && $fp->astman->db['/allowlist/autoadd/3'] === '1,0,10,2', 'routeAdd stores optional list id');
check($al->routeGetListId(3) === 2 && $al->routeRemCount(3) === '0' && $al->routeStoCount(3) === '10', 'route getters parse four-field autoadd value');
$al->routeAdd(3, '1', '11', 1);
check($fp->astman->db['/allowlist/autoadd/3'] === '1,1,11', 'routeAdd replace drops prior value');
check(substr_count($fp->astman->db['/allowlist/autoadd/3'], ',') === 2, 'route setter never stores two autoadd records');

list($fp, $al) = fresh();
$al->auditEntries = array();
$fp->astman->db['/allowlist/did/5550300'] = '99';
check($al->dialplanScreenListId('5550300', '') === false && strpos(implode("\n", $al->auditEntries), 'unscreened') !== false, 'missing-list route is left unscreened and audit-logged');
check(strpos(implode("\n", $al->auditEntries), '5550300') === false, 'missing-list audit masks the DID');

list($fp, $al) = fresh();
foreach (array('0', '-1', '1;Hangup') as $bad) {
	$fp->astman->db['/allowlist/did/5550301'] = $bad;
	check($al->didGetListId('5550301', '') === false && $al->dialplanScreenListId('5550301', '') === false, 'dialplan rejects stored list id ' . json_encode($bad));
}
list($fp, $al) = fresh();
$al->auditEntries = array();
$fp->astman->db['/allowlist/did/5550302'] = '1;Hangup';
check($al->dialplanScreenListId('5550302', '') === false && strpos(implode("\n", $al->auditEntries), 'unparseable allowlist id') !== false, 'unparseable did mapping is unscreened and audit-logged');
check(strpos(implode("\n", $al->auditEntries), '5550302') === false, 'unparseable mapping audit masks DID');

class RecAutoaddAGI {
	public $puts = array();
	public $cmds = array();
	public function verbose($m) {}
	public function database_get($f, $k) {
		$this->cmds[] = "$f/$k";
		if ($f === 'allowlist-lists' && $k === '2') {
			return array('result' => 1, 'data' => 'Sales');
		}
		if ($f === 'allowlist-list' && $k === '2/2025550300') {
			return array('result' => 0);
		}
		if ($f === 'allowlist' && $k === '2025550300') {
			return array('result' => 0);
		}
		return array('result' => 0);
	}
	public function database_put($f, $k, $v) {
		$this->puts[] = array($f, $k, $v);
		return array('result' => 1);
	}
}
$AGI = new RecAutoaddAGI();
$FreePBX = new FakeFreePBX();
check(allowlistListExists(2) === true && allowlistListExists(99) === false, 'AGI listExists honors metadata and rejects missing lists');
check(searchAllowList('2025550300', 2) === false && in_array('allowlist-list/2/2025550300', $AGI->cmds, true), 'searchAllowList uses allowlist-list for named lists');
check(searchAllowList('2025550300', 1) === false && in_array('allowlist/2025550300', $AGI->cmds, true), 'searchAllowList uses allowlist for list 1');
check(searchAllowList('2025550300', '1;Hangup') === false, 'searchAllowList rejects invalid list id');
$allowlist_test_audit_logs = array();
$AGI = new RecAutoaddAGI();
check(allowlistResolveAutoaddTargetList('3', '1,,99') === false && end($allowlist_test_audit_logs) === 'Allowlist auto-add: outbound route 3 skipped (unparseable autoadd value)', 'autoadd AGI audits unparseable route mapping');
$allowlist_test_audit_logs = array();
check(allowlistResolveAutoaddTargetList('4', '1,0,10,88') === false && end($allowlist_test_audit_logs) === 'Allowlist auto-add: outbound route 4 skipped (missing allowlist list)', 'autoadd AGI audits missing-list route mapping');
$putAgi = new RecAutoaddAGI();
$done = allowlistMemberPut($putAgi, 2, '2025550400', 'automatically added');
check($done['result'] === 1 && $putAgi->puts[0][0] === 'allowlist-list' && $putAgi->puts[0][1] === '2/2025550400', 'autoadd member put uses allowlist-list for named lists');
unset($allowlist_test_audit_logs);

if (!function_exists('core_did_list')) {
	function core_did_list() {
		global $unit_core_did_list;
		return $unit_core_did_list;
	}
	function core_routing_list() {
		global $unit_core_routing_list;
		return $unit_core_routing_list;
	}
	function empty_freepbx($x) {
		return empty($x);
	}
}
if (!function_exists('needreload')) {
	function needreload() {
		global $unit_needreload_count;
		$unit_needreload_count++;
	}
}
global $unit_core_did_list, $unit_core_routing_list, $unit_needreload_count;
$unit_core_did_list = array(array('extension' => '5550888', 'cidnum' => '', 'pricid' => false));
$unit_core_routing_list = array(array('route_id' => 9, 'name' => 'UnitRoute'));
$unit_needreload_count = 0;
check(Allowlist::formatGqlAllowlistNodeLocalId(1, '2025550998') === '2025550998', 'GQL default-list node id is bare number');
check(Allowlist::formatGqlAllowlistNodeLocalId(2, '2025550999') === '2:2025550999', 'GQL named-list node id is listId:number');
check(Allowlist::parseGqlAllowlistNodeLocalId('2:2025550999') === array('listId' => 2, 'number' => '2025550999'), 'GQL parse named-list node id');
check(Allowlist::parseGqlAllowlistNodeLocalId('2025550998') === array('listId' => 1, 'number' => '2025550998'), 'GQL parse default-list bare node id');
check(Allowlist::parseGqlAllowlistNodeLocalId('1:2025550999') === false, 'GQL rejects composite id for list 1');
foreach (array('0', '-1', '1;Hangup', '2/../x') as $badListInNode) {
	check(Allowlist::parseGqlAllowlistNodeLocalId($badListInNode . ':2025550100') === false, 'GQL node id rejects bad list part ' . json_encode($badListInNode));
}
list($fp, $al) = fresh();
$gqlListId = $al->listCreate('GraphQL');
check($gqlListId === 2, 'GQL list tests allocate list 2');
check($al->listCreate(str_repeat('n', 65)) === false, 'GQL listCreate rejects oversized name');
check($al->listCreate("New\nLine") === false, 'GQL listCreate rejects newline in name');
check($al->listDelete(1) === false && $al->getLastError() !== '', 'GQL listDelete rejects default list');
$al->didAdd('5550777', '', 2);
check($al->listDelete(2) === false && strpos($al->getLastError(), 'assigned') !== false, 'GQL listDelete rejects list still assigned to routes');
check($al->applyGraphqlRemoveAllowlistList('2') === false && $al->listExists(2), 'applyGraphqlRemoveAllowlistList fails when list assigned to inbound route');
$al->listMemberAdd(2, array('number' => '2025550777', 'description' => 'gql'));
$row = $al->findListMember(2, '2025550777');
check($row !== null && $row['description'] === 'gql' && $al->findListMember(2, '2025550000') === null, 'findListMember strict lookup on named list');
$roundTrip = Allowlist::formatGqlAllowlistNodeLocalId(2, '2025550777');
$parsed = Allowlist::parseGqlAllowlistNodeLocalId($roundTrip);
check($parsed['listId'] === 2 && $parsed['number'] === '2025550777' && $al->findListMember($parsed['listId'], $parsed['number']) !== null, 'named-list GQL node id round-trip resolves member');
$al->listMemberAdd(1, array('number' => '2025553111', 'description' => 'cross-a'));
$al->listMemberAdd(2, array('number' => '2025553222', 'description' => 'cross-b'));
check($al->findListMember(2, '2025553111') === null, 'findListMember does not return members from a different list');
$addedGql = $al->applyGraphqlAddAllowlistMember(null, '2025553333', 'gql-apply-add');
check($addedGql !== false && $addedGql['listId'] === 1 && $al->findListMember(1, '2025553333') !== null, 'applyGraphqlAddAllowlistMember adds to default list');
$fp->astman->failPut = true;
check($al->applyGraphqlAddAllowlistMember(null, '2025553344', 'fail-write') === false && $al->findListMember(1, '2025553344') === null, 'applyGraphqlAddAllowlistMember rejects without success when listMemberAdd write fails');
$fp->astman->failPut = false;
$al->listMemberAdd(1, array('number' => '2025553355', 'description' => 'keep-desc'));
$fp->astman->failPut = true;
check($al->applyGraphqlAddAllowlistMember(null, '2025553355', 'new-desc') === false && $al->findListMember(1, '2025553355')['description'] === 'keep-desc', 'applyGraphqlAddAllowlistMember write failure on existing number does not report success');
$fp->astman->failPut = false;
$removedGql = $al->applyGraphqlRemoveAllowlistMember(null, '2025553333');
check($removedGql !== false && $removedGql['id'] === '2025553333' && $al->findListMember(1, '2025553333') === null, 'applyGraphqlRemoveAllowlistMember default list returns plain number deletedId');
$removedLegacyKey = $al->applyGraphqlRemoveAllowlistMember(null, 'sipuser');
check($removedLegacyKey !== false && $removedLegacyKey['id'] === 'sipuser' && $al->findListMember(1, 'sipuser') === null, 'applyGraphqlRemoveAllowlistMember list 1 removes legacy sipuser and returns sipuser deletedId');
$fp->astman->db['/allowlist/sipuser'] = 'legacy';
$fp->astman->db['/allowlist/02025550177'] = 'zero-pad';
$padInput = '02025550177';
check($al->numberDel($padInput) === true && !isset($fp->astman->db['/allowlist/02025550177']), 'numberDel removes zero-padded allowlist key');
$fp->astman->db['/allowlist/02025550177'] = 'zero-pad';
$padRemoved = $al->applyGraphqlRemoveAllowlistMember(null, $padInput);
check($padRemoved !== false && $padRemoved['id'] === $padInput && !isset($fp->astman->db['/allowlist/02025550177']), 'applyGraphqlRemoveAllowlistMember list 1 preserves zero-padded input as deletedId');
foreach (array('dest', '', '.../x') as $badRemoveList1) {
	$al->numberDel($badRemoveList1);
	$baseRemoveErr = $al->getLastError();
	check($al->applyGraphqlRemoveAllowlistMember(null, $badRemoveList1) === false && $al->getLastError() === $baseRemoveErr, 'applyGraphqlRemoveAllowlistMember list 1 error matches numberDel for ' . json_encode($badRemoveList1));
}
$fp->astman->db['/allowlist/sipuser'] = 'legacy';
$al->listMemberAdd(2, array('number' => '2025554444', 'description' => 'list2-only'));
check($al->applyGraphqlRemoveAllowlistMember('2', 'not-a-phone;') === false && $al->findListMember(2, '2025554444') !== null, 'applyGraphqlRemoveAllowlistMember rejects invalid named-list number before delete');
$addedList2 = $al->applyGraphqlAddAllowlistMember('2', '2025554555', 'list2-gql');
check($addedList2 !== false && (int)$addedList2['listId'] === 2 && $al->findListMember(2, '2025554555') !== null && $al->findListMember(1, '2025554555') === null, 'applyGraphqlAddAllowlistMember list 2 does not add to list 1');
check($al->applyGraphqlRemoveAllowlistMember(null, '2025559999888') === false, 'applyGraphqlRemoveAllowlistMember missing member does not report success');
check($al->applyGraphqlRemoveAllowlistMember('2', '2025557777001') === false && $al->findListMember(2, '2025554444') !== null, 'applyGraphqlRemoveAllowlistMember list 2 rejects missing member');
$fp->astman->db['/allowlist/2025550100'] = 'ws-pad-target';
$spacePadInput = ' 2025550100';
check($al->applyGraphqlRemoveAllowlistMember(null, $spacePadInput) === false && isset($fp->astman->db['/allowlist/2025550100']) && $al->getLastError() === _('Not an allowlist number.'), 'applyGraphqlRemoveAllowlistMember list 1 rejects whitespace-padded input with numberDel error');
$removedList2 = $al->applyGraphqlRemoveAllowlistMember('2', '2025554555');
check($removedList2 !== false && $removedList2['id'] === '2:2025554555' && $al->findListMember(2, '2025554555') === null && $al->findListMember(2, '2025554444') !== null, 'applyGraphqlRemoveAllowlistMember list 2 returns composite deletedId');
check($al->applyGraphqlAddAllowlistMember('2', 'bad', 'x') === false && $al->findListMember(2, 'bad') === null, 'applyGraphqlAddAllowlistMember failure does not leave a member');
$createRow = $al->applyGraphqlCreateAllowlistList('HarnessList');
check($createRow !== false && (int)$createRow['id'] >= 3 && $createRow['name'] === 'HarnessList' && $al->listExists((int)$createRow['id']), 'applyGraphqlCreateAllowlistList returns created list summary');
$renameRow = $al->applyGraphqlRenameAllowlistList((string)$createRow['id'], 'HarnessRenamed');
check($renameRow !== false && $renameRow['id'] === $createRow['id'] && $renameRow['name'] === 'HarnessRenamed', 'applyGraphqlRenameAllowlistList returns renamed list');
check($al->applyGraphqlRenameAllowlistList((string)$createRow['id'], '') === false && $renameRow['name'] === 'HarnessRenamed', 'applyGraphqlRenameAllowlistList failure leaves name unchanged');
$removeListPayload = $al->applyGraphqlRemoveAllowlistList((string)$createRow['id']);
check($removeListPayload !== false && $removeListPayload['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId((int)$createRow['id']) && $removeListPayload['id'] !== (string)$createRow['id'], 'applyGraphqlRemoveAllowlistList deletedId is Relay global not raw list id');
check($al->applyGraphqlRemoveAllowlistList((string)$createRow['id']) === false && $al->listExists((int)$createRow['id']) === false, 'applyGraphqlRemoveAllowlistList failure after delete does not report success');
$al->listMemberDel(1, '2025553111');
$al->listMemberDel(2, '2025553222');
$al->auditEntries = array();
$al->logGraphqlInboundRouteAllowlistChange('5550777', '', 2);
$inAudit = implode("\n", $al->auditEntries);
check(strpos($inAudit, '5550777') === false && strpos($inAudit, 'list 2') !== false, 'GraphQL inbound route audit masks DID');
check($al->listExists(88) === false, 'nonexistent list id for GraphQL resolve');
$sums = $al->summarizeAllowlistsForGraphql();
$defaultRow = null;
foreach ($sums as $sumRow) {
	if ((int)($sumRow['id'] ?? 0) === 1) {
		$defaultRow = $sumRow;
		break;
	}
}
check($defaultRow !== null && $defaultRow['name'] === 'Default', 'summarizeAllowlistsForGraphql includes default list');
$listRelayGlobal = Allowlist::formatGqlAllowlistListRelayGlobalId(2);
check($listRelayGlobal !== false && Allowlist::parseGqlAllowlistListIdInput($listRelayGlobal) === 2, 'parseGqlAllowlistListIdInput decodes allowlistList global id');
check($al->resolveGraphqlListIdArg($listRelayGlobal) === 2 && $al->resolveGraphqlListIdArg('2') === 2, 'resolveGraphqlListIdArg accepts global and raw list id');
check($al->resolveGraphqlRouteListIdArg($listRelayGlobal) === 2, 'resolveGraphqlRouteListIdArg accepts global list id');
$wrongAllowlistEntryGlobal = base64_encode('allowlist:2');
check($al->listExists(2) === true, 'list 2 exists so wrong-type pin tests local id validity only');
check(Allowlist::parseGqlAllowlistListIdInput($wrongAllowlistEntryGlobal) === false, 'parseGqlAllowlistListIdInput rejects allowlist entry global id for existing list 2');
check($al->resolveGraphqlListIdArg($wrongAllowlistEntryGlobal) === false, 'resolveGraphqlListIdArg rejects allowlist entry global id for existing list 2');
$wrongUserListGlobal = base64_encode('User:1');
check($al->listExists(1) === true, 'list 1 exists for User Relay type rejection pin');
check(Allowlist::parseGqlAllowlistListIdInput($wrongUserListGlobal) === false, 'parseGqlAllowlistListIdInput rejects User Relay type for existing list 1');
check($al->resolveGraphqlListIdArg($wrongUserListGlobal) === false, 'resolveGraphqlListIdArg rejects User Relay type for existing list 1');
$ghostListGlobal = Allowlist::formatGqlAllowlistListRelayGlobalId(99);
check($al->resolveGraphqlListIdArg($ghostListGlobal) === false, 'resolveGraphqlListIdArg rejects nonexistent list global id');
check($al->resolveGraphqlRouteListIdArg($ghostListGlobal) === false, 'resolveGraphqlRouteListIdArg rejects nonexistent list global id');
check($al->resolveGraphqlListIdArg(null, false) === 1 && $al->resolveGraphqlListIdArg('', false) === 1, 'resolveGraphqlListIdArg defaults omitted list id to 1');
check($al->resolveGraphqlListIdArg(null, true) === false && $al->resolveGraphqlListIdArg('', true) === false, 'resolveGraphqlListIdArg rejects empty list id when required');
check($al->applyGraphqlRenameAllowlistList('', 'BadName') === false, 'applyGraphqlRenameAllowlistList rejects empty id without touching list 1');
check($al->applyGraphqlRemoveAllowlistList('') === false, 'applyGraphqlRemoveAllowlistList rejects empty id');
check($al->inboundRouteExistsInCore('5550000', '') === false, 'inboundRouteExistsInCore rejects unknown DID');
check($al->outboundRouteExistsInCore(9) === true && $al->outboundRouteExistsInCore(77) === false, 'outboundRouteExistsInCore honors core_routing_list');
check($al->applyGraphqlInboundRouteAllowlist('5550888', '', null, false) === false, 'omitted inbound listId does not clear screening');
check($al->applyGraphqlOutboundRouteAllowlist(9, 0, 10, null, false) === false, 'omitted outbound listId does not clear auto-add');
$unit_needreload_count = 0;
$applied = $al->applyGraphqlInboundRouteAllowlist('5550888', '', '1', true);
check($applied !== false && $unit_needreload_count === 1 && isset($fp->astman->db['/allowlist/did/5550888']), 'applyGraphqlInboundRouteAllowlist calls needreload');
$unit_needreload_count = 0;
check($al->applyGraphqlOutboundRouteAllowlist(77, 0, 10, '1', true) === false && $unit_needreload_count === 0, 'unknown outbound route rejects without needreload');
$unit_needreload_count = 0;
check($al->applyGraphqlOutboundRouteAllowlist(9, 0, 10, '1', true) !== false && $unit_needreload_count === 1, 'applyGraphqlOutboundRouteAllowlist calls needreload');
$listSummaryNodeId = Allowlist::formatGqlAllowlistListRelayGlobalId(2);
check($listSummaryNodeId === Allowlist::formatGraphqlListIdPayload(2), 'allowlistList.id matches formatGraphqlListIdPayload');
$delListPayloadId = $al->graphqlRemoveAllowlistListDeletedIdPayload(2);
check($delListPayloadId === $listSummaryNodeId, 'removeAllowlistList deletedId matches allowlistList.id');
$inPayload = $al->applyGraphqlInboundRouteAllowlist('5550888', '', '1', true);
check($inPayload['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(1), 'setInboundRouteAllowlist listId payload matches allowlistList.id');
check($al->findAllowlistListSummaryById(Allowlist::parseGqlAllowlistListIdInput($inPayload['listId'])) !== null, 'inbound payload listId resolves allowlistList node');
$unit_needreload_count = 0;
$outPayload = $al->applyGraphqlOutboundRouteAllowlist(9, 0, 10, '1', true);
check($outPayload['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(1), 'setOutboundRouteAllowlist listId payload matches allowlistList.id');
check($al->findAllowlistListSummaryById(Allowlist::parseGqlAllowlistListIdInput($outPayload['listId'])) !== null, 'outbound payload listId resolves allowlistList node');
$offInbound = $al->applyGraphqlInboundRouteAllowlist('5550888', '', null, true);
check($offInbound['listId'] === null, 'setInboundRouteAllowlist returns null listId when screening off');
$offOutbound = $al->applyGraphqlOutboundRouteAllowlist(9, null, null, null, true);
check($offOutbound['listId'] === null, 'setOutboundRouteAllowlist disable omits remove and store');
$outboundEnableGuardMsg = 'remove and store are required when enabling outbound auto-add.';
check($al->applyGraphqlOutboundRouteAllowlist(9, null, null, '1', true) === false && $al->getLastError() === $outboundEnableGuardMsg, 'setOutboundRouteAllowlist enable requires remove and store with exact error');
check($al->applyGraphqlOutboundRouteAllowlist(9, 0, null, '1', true) === false && $al->getLastError() === $outboundEnableGuardMsg, 'outbound enable with remove-only rejects with exact error');
check($al->applyGraphqlOutboundRouteAllowlist(9, null, 10, '1', true) === false && $al->getLastError() === $outboundEnableGuardMsg, 'outbound enable with store-only rejects with exact error');
$unit_core_routing_list = array(array('route_id' => 22, 'name' => 'IntBoundRoute'));
check($al->applyGraphqlOutboundRouteAllowlist(22, -1, 10, '1', true) === false && $al->applyGraphqlOutboundRouteAllowlist(22, 0, 1000000, '1', true) === false, 'setOutboundRouteAllowlist rejects negative remove and oversize store ints');
check($al->applyGraphqlOutboundRouteAllowlist(22, 0, 10, '1', true) !== false && $fp->astman->db['/allowlist/autoadd/22'] === '1,0,10', 'setOutboundRouteAllowlist accepts zero remove int');
check($al->applyGraphqlOutboundRouteAllowlist(22, 0, 999999, '1', true) !== false && $fp->astman->db['/allowlist/autoadd/22'] === '1,0,999999', 'setOutboundRouteAllowlist accepts max six-digit store int');
$boundDeleteListId = $al->listCreate('BoundDeleteGuard');
$unit_core_routing_list = array(array('route_id' => 23, 'name' => 'BoundDeleteRoute'));
check($al->routeAdd(23, 0, 10, $boundDeleteListId) === true, 'routeAdd assigns bounded list to outbound route for delete guard');
check($al->listDelete($boundDeleteListId) === false && $al->listExists($boundDeleteListId), 'listDelete rejects list in use on outbound route with valid bounded mapping');
$fp->astman->db['/allowlist/autoadd/77'] = '1,0,99';
$unit_core_routing_list = array();
check($al->applyGraphqlOutboundRouteAllowlist(77, null, null, null, true) !== false && !isset($fp->astman->db['/allowlist/autoadd/77']), 'orphan outbound autoadd cleared without core route');
$fp->astman->db['/allowlist/did/5550100/'] = '1';
$unit_needreload_count = 0;
check($al->applyGraphqlInboundRouteAllowlist('bad;did', '', null, true) === false && $unit_needreload_count === 0 && isset($fp->astman->db['/allowlist/did/5550100/']), 'inbound clear rejects invalid DID without mutating AstDB');
$fp->astman->db['/allowlist/autoadd/0'] = '1,0,99';
check($al->applyGraphqlOutboundRouteAllowlist('abc', null, null, null, true) === false && $al->getLastError() === 'Invalid outbound route id.' && isset($fp->astman->db['/allowlist/autoadd/0']), 'outbound clear rejects non-numeric routeId without clearing autoadd/0');
$fp->astman->failPut = true;
$unit_needreload_count = 0;
$unit_core_did_list = array(array('extension' => '5550888', 'cidnum' => '', 'pricid' => false));
check($al->applyGraphqlInboundRouteAllowlist('5550888', '', '1', true) === false && $unit_needreload_count === 0 && !isset($fp->astman->db['/allowlist/did/5550888']), 'inbound enable rejects without reload when didAdd write fails');
$fp->astman->failPut = false;
$unit_core_routing_list = array(array('route_id' => 15));
unset($fp->astman->db['/allowlist/autoadd/15']);
$unit_needreload_count = 0;
check($al->applyGraphqlOutboundRouteAllowlist(15, 0, 10, '1', true) !== false && $unit_needreload_count === 1 && isset($fp->astman->db['/allowlist/autoadd/15']), 'outbound enable persists autoadd route key');
unset($fp->astman->db['/allowlist/autoadd/15']);
$fp->astman->failPut = true;
$unit_needreload_count = 0;
check($al->applyGraphqlOutboundRouteAllowlist(15, 0, 10, '1', true) === false && $unit_needreload_count === 0 && !isset($fp->astman->db['/allowlist/autoadd/15']), 'outbound enable rejects without reload when routeAdd write fails');
$fp->astman->failPut = false;
$unit_needreload_count = 0;
check($al->applyGraphqlOutboundRouteAllowlist(77, 0, 10, '1', true) === false && $unit_needreload_count === 0 && !isset($fp->astman->db['/allowlist/autoadd/77']), 'enable outbound on unknown route rejects without reload or write');
$fp->astman->db['/allowlist/did/5550666'] = '2';
$unit_core_did_list = array();
$unit_needreload_count = 0;
check($al->applyGraphqlInboundRouteAllowlist('5550666', '', null, true) !== false && $unit_needreload_count === 1 && !isset($fp->astman->db['/allowlist/did/5550666']), 'orphan inbound screening cleared without core route');
$unit_core_did_list = array(array('extension' => '5550888', 'cidnum' => '', 'pricid' => false));
$unit_needreload_count = 0;
check($al->applyGraphqlInboundRouteAllowlist('5559999', '', '1', true) === false && $unit_needreload_count === 0 && !isset($fp->astman->db['/allowlist/did/5559999']), 'enable inbound on unknown DID rejects without reload or write');
check(Allowlist::isLegacyDefaultListMemberKey('pause') === false && Allowlist::isLegacyDefaultListMemberKey('dest') === false, 'isLegacyDefaultListMemberKey rejects reserved keys');
check(Allowlist::isLegacyDefaultListMemberKey('did/foo') === false, 'isLegacyDefaultListMemberKey rejects reserved prefixes');
check(Allowlist::isLegacyDefaultListMemberKey('a/b') === false, 'isLegacyDefaultListMemberKey rejects slash in member key');
$legacyKeyBody = allowlistUnitExtractFunctionBody($alSrc, 'isLegacyDefaultListMemberKey');
check(strpos($legacyKeyBody, 'in_array($key, self::RESERVED_KEYS') !== false && strpos($legacyKeyBody, '[^\\/\\x00-\\x1F\\x7F]+') !== false, 'isLegacyDefaultListMemberKey pins reserved and slash guards');
$unit_core_routing_list = array(array('route_id' => 9, 'name' => 'UnitRoute'));
list($fpHarness, $alHarness) = fresh();
$alHarness->listCreate('ClosureList');
$gqlHarness = allowlistUnitGqlHarnessFresh($fpHarness, $alHarness);
$closureAdd = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlist', array('number' => '2025558100', 'description' => 'closure'));
check($closureAdd['ok'] && $alHarness->findListMember(1, '2025558100') !== null, 'GQL addAllowlist closure adds member');
$closureRemove = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlist', array('number' => '2025558100'));
check($closureRemove['ok'] && $closureRemove['result']['id'] === '2025558100', 'GQL removeAllowlist closure returns plain deletedId');
$closureAddList = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlistList', array('name' => 'FromClosure'));
check($closureAddList['ok'] && $closureAddList['result']['name'] === 'FromClosure', 'GQL addAllowlistList closure returns created list');
$closureListId = (int)$closureAddList['result']['id'];
$closureRename = allowlistUnitGqlRunMutation($gqlHarness, 'renameAllowlistList', array('id' => (string)$closureListId, 'name' => 'RenamedClosure'));
check($closureRename['ok'] && $closureRename['result']['name'] === 'RenamedClosure', 'GQL renameAllowlistList closure returns renamed list');
$closureRemoveList = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlistList', array('id' => (string)$closureListId));
check($closureRemoveList['ok'] && $closureRemoveList['result']['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId($closureListId), 'GQL removeAllowlistList closure returns Relay deletedId');
$closureInbound = allowlistUnitGqlRunMutation($gqlHarness, 'setInboundRouteAllowlist', array('did' => '5550888', 'cid' => '', 'listId' => '1'));
check($closureInbound['ok'] && $closureInbound['result']['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(1), 'GQL setInboundRouteAllowlist closure returns listId payload');
$closureOutboundOk = allowlistUnitGqlRunMutation($gqlHarness, 'setOutboundRouteAllowlist', array('routeId' => '9', 'listId' => '1', 'remove' => 0, 'store' => 10));
check($closureOutboundOk['ok'] && isset($fpHarness->astman->db['/allowlist/autoadd/9']), 'GQL setOutboundRouteAllowlist closure enables auto-add');
$closureOutboundGuard = allowlistUnitGqlRunMutation($gqlHarness, 'setOutboundRouteAllowlist', array('routeId' => '9', 'listId' => '1', 'remove' => 0, 'store' => null));
check(!$closureOutboundGuard['ok'] && $closureOutboundGuard['error'] === $outboundEnableGuardMsg, 'GQL setOutboundRouteAllowlist closure pins outbound enable guard message');
$closureBadAdd = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlist', array('number' => 'x', 'description' => 'bad'));
check(!$closureBadAdd['ok'], 'GQL addAllowlist closure failure throws UserError not success');
$closureBadRemove = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlist', array('number' => '202555919191'));
check(!$closureBadRemove['ok'], 'GQL removeAllowlist closure failure throws UserError not success');
$closureBadAddList = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlistList', array('name' => str_repeat('n', 65)));
check(!$closureBadAddList['ok'], 'GQL addAllowlistList closure failure throws UserError not success');
$closureBadRename = allowlistUnitGqlRunMutation($gqlHarness, 'renameAllowlistList', array('id' => '', 'name' => 'X'));
check(!$closureBadRename['ok'], 'GQL renameAllowlistList closure failure throws UserError not success');
$closureBadRemoveList = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlistList', array('id' => '1'));
check(!$closureBadRemoveList['ok'], 'GQL removeAllowlistList closure rejects default list delete');
$closureBadInbound = allowlistUnitGqlRunMutation($gqlHarness, 'setInboundRouteAllowlist', array('did' => 'bad;did', 'cid' => '', 'listId' => null));
check(!$closureBadInbound['ok'], 'GQL setInboundRouteAllowlist closure failure throws UserError not success');
$closureAddL2 = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlist', array('number' => '2025558200', 'listId' => '2', 'description' => 'closure-l2'));
check($closureAddL2['ok'] && $alHarness->findListMember(2, '2025558200') !== null && $alHarness->findListMember(1, '2025558200') === null, 'GQL addAllowlist closure targets list 2');
$closureRemoveL2 = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlist', array('number' => '2025558200', 'listId' => '2'));
check($closureRemoveL2['ok'] && $closureRemoveL2['result']['id'] === '2:2025558200', 'GQL removeAllowlist closure returns composite deletedId for list 2');
$fpHarness->astman->db['/allowlist/did/5550888'] = '1';
$closureInboundOmit = allowlistUnitGqlRunMutation($gqlHarness, 'setInboundRouteAllowlist', array('did' => '5550888', 'cid' => ''));
check(!$closureInboundOmit['ok'] && isset($fpHarness->astman->db['/allowlist/did/5550888']), 'GQL setInboundRouteAllowlist omitted listId does not clear screening');
$fpHarness->astman->db['/allowlist/autoadd/9'] = '0,0,10';
$closureOutboundOmit = allowlistUnitGqlRunMutation($gqlHarness, 'setOutboundRouteAllowlist', array('routeId' => '9'));
check(!$closureOutboundOmit['ok'] && isset($fpHarness->astman->db['/allowlist/autoadd/9']), 'GQL setOutboundRouteAllowlist omitted listId does not clear auto-add');
$assignedListId = $alHarness->listCreate('RouteBound');
$unit_core_did_list = array(array('extension' => '5550998', 'cidnum' => '', 'pricid' => false));
$alHarness->didAdd('5550998', '', $assignedListId);
$closureRemoveAssigned = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlistList', array('id' => (string)$assignedListId));
check(!$closureRemoveAssigned['ok'] && $alHarness->listExists($assignedListId), 'GQL removeAllowlistList closure rejects list assigned to route');
$fpHarness->astman->failPut = true;
$closureAddWriteFail = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlist', array('number' => '2025558300', 'description' => 'write-fail'));
check(!$closureAddWriteFail['ok'] && $alHarness->findListMember(1, '2025558300') === null, 'GQL addAllowlist closure rejects when underlying write fails');
$fpHarness->astman->failPut = false;
foreach (array(
	'removeAllowlist' => $closureBadRemove,
	'addAllowlistList' => $closureBadAddList,
	'renameAllowlistList' => $closureBadRename,
	'removeAllowlistList' => $closureBadRemoveList,
	'setInboundRouteAllowlist' => $closureBadInbound,
	'setOutboundRouteAllowlist' => $closureOutboundGuard,
) as $failMut => $failRes) {
	check(!$failRes['ok'] && $failRes['error'] !== '', 'GQL ' . $failMut . ' failure returns UserError message');
}
$extractorAddNullList = allowlistUnitGqlInvokeExtractor($gqlHarness, 'gqlMutateAddAllowlist', array('number' => '2025558400', 'listId' => null, 'description' => 'null-list'));
check($extractorAddNullList['ok'] && $alHarness->findListMember(1, '2025558400') !== null, 'gqlMutateAddAllowlist treats null listId as default list');
$alHarness->listMemberDel(1, '2025558400');
$fpHarness->astman->db['/allowlist/sipuser'] = 'legacy';
$extractorRemoveNullList = allowlistUnitGqlInvokeExtractor($gqlHarness, 'gqlMutateRemoveAllowlist', array('number' => 'sipuser', 'listId' => null));
check($extractorRemoveNullList['ok'] && $extractorRemoveNullList['result']['id'] === 'sipuser', 'gqlMutateRemoveAllowlist null listId returns bare deletedId on default list');
$fpHarness->astman->db['/allowlist/sipuser'] = 'legacy';
$alHarness->listMemberAdd(2, array('number' => '2025558500', 'description' => 'extractor'));
$extractorRemoveNamed = allowlistUnitGqlInvokeExtractor($gqlHarness, 'gqlMutateRemoveAllowlist', array('number' => '2025558500', 'listId' => '2'));
check($extractorRemoveNamed['ok'] && $extractorRemoveNamed['result']['id'] === '2:2025558500', 'gqlMutateRemoveAllowlist formats named-list deletedId');
$extractorInboundOmit = allowlistUnitGqlInvokeExtractor($gqlHarness, 'gqlMutateSetInboundRouteAllowlist', array('did' => '5550888', 'cid' => ''));
check(!$extractorInboundOmit['ok'] && isset($fpHarness->astman->db['/allowlist/did/5550888']), 'gqlMutateSetInboundRouteAllowlist omitted listId leaves screening unchanged');
$alHarness->listMemberAdd(2, array('number' => '2025558600', 'description' => 'q-filter-a'));
$alHarness->listMemberAdd(1, array('number' => '2025558601', 'description' => 'q-filter-b'));
$fpHarness->astman->db['/allowlist/sipuser'] = 'legacy node';
$gqlQueries = $gqlHarness->readQueryResolvers();
$connList2 = $gqlQueries['allAllowlists'](null, array('listId' => '2', 'first' => 50));
$list2Numbers = array_map(function ($edge) {
	return $edge['node']['number'];
}, $connList2['edges']);
check(!in_array('2025558601', $list2Numbers, true) && in_array('2025558600', $list2Numbers, true), 'allAllowlists listId filters members to list 2');
$unknownListQuery = allowlistUnitGqlRunQuery($gqlHarness, 'allAllowlists', array('listId' => '99'));
check(!$unknownListQuery['ok'] && $unknownListQuery['error'] === _('Unknown allowlist.'), 'allAllowlists unknown listId throws exact Unknown allowlist error');
$countList1 = count($alHarness->listMemberGetAll(1));
$countList2 = count($alHarness->listMemberGetAll(2));
check($countList2 !== $countList1, 'harness list 1 and list 2 have distinct member counts');
check((int)$connList2['allowlistQueryListId'] === 2, 'allAllowlists sets allowlistQueryListId on connection');
check($gqlHarness->invokeAllAllowlistsTotalCount(array('listId' => '2')) === $countList2, 'allAllowlists totalCount matches list 2 member count');
$memberRowL2 = $alHarness->findListMember(2, '2025558600');
$memberRowL2['listId'] = 2;
$memberFieldsL2 = $gqlHarness->resolveTypeFields('allowlist', $memberRowL2);
check(\GraphQLRelay\Relay::fromGlobalId($memberFieldsL2['id'])['id'] === '2:2025558600', 'allowlist id field resolver emits listId:number local id');
foreach ($connList2['edges'] as $edgeL2) {
	$edgeFields = $gqlHarness->resolveTypeFields('allowlist', $edgeL2['node']);
	$edgeLocal = \GraphQLRelay\Relay::fromGlobalId($edgeFields['id']);
	$roundNode = $gqlHarness->invokeAllowlistGetNode($edgeLocal['id']);
	check($roundNode !== null && $roundNode['number'] === $edgeL2['node']['number'], 'allAllowlists list-2 edge global id round-trips through getNode');
}
$fpHarness->astman->db['/allowlist-lists/2'] = '<b>Esc</b>';
$list2SummaryField = $alHarness->findAllowlistListSummaryById('2');
$listFieldsHarness = $gqlHarness->resolveTypeFields('allowlistList', $list2SummaryField);
check($listFieldsHarness['name'] === '<b>Esc</b>', 'allowlistList name field returns raw stored name');
$gqlRawName = 'A&B <x>';
$renameRaw = $alHarness->applyGraphqlRenameAllowlistList('2', $gqlRawName);
check($renameRaw !== false && $renameRaw['name'] === $gqlRawName, 'GraphQL rename stores raw list name');
$list2SummaryField = $alHarness->findAllowlistListSummaryById('2');
$listFieldsHarness = $gqlHarness->resolveTypeFields('allowlistList', $list2SummaryField);
check($listFieldsHarness['name'] === $gqlRawName, 'GraphQL name field round-trip preserves special characters raw');
check(Allowlist::formatListNameForDisplay($gqlRawName) === 'A&amp;B &lt;x&gt;', 'UI list name display helper html-escapes for views');
$list1SummaryGql = $alHarness->findAllowlistListSummaryById('1');
$list2SummaryGql = $alHarness->findAllowlistListSummaryById('2');
$list1IdField = $gqlHarness->resolveTypeFields('allowlistList', $list1SummaryGql);
$list2IdField = $gqlHarness->resolveTypeFields('allowlistList', $list2SummaryGql);
check($list1IdField['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId(1), 'allowlistList id field exact global id for list 1');
check($list2IdField['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId(2), 'allowlistList id field exact global id for list 2');
check($list1IdField['id'] !== $list2IdField['id'], 'allowlistList id field global ids differ by list id');
$list1SummaryDecoded = \GraphQLRelay\Relay::fromGlobalId($list1IdField['id']);
$list2SummaryDecoded = \GraphQLRelay\Relay::fromGlobalId($list2IdField['id']);
check($list1SummaryDecoded['type'] === 'allowlistList' && $list1SummaryDecoded['id'] === '1', 'allowlistList id field decodes type and local id for list 1');
check($list2SummaryDecoded['type'] === 'allowlistList' && $list2SummaryDecoded['id'] === '2', 'allowlistList id field decodes type and local id for list 2');
$alHarness->listMemberAdd(1, array('number' => '2025550700', 'description' => 'd55-member-l1'));
$list1MemberGqlRow = $alHarness->findListMember(1, '2025550700');
$list1MemberGqlRow['listId'] = 1;
$list1MemberIdField = $gqlHarness->resolveTypeFields('allowlist', $list1MemberGqlRow);
check($list1MemberIdField['id'] === base64_encode('allowlist:2025550700'), 'allowlist id field exact global id for list-1 bare member');
$list1MemberDecoded = \GraphQLRelay\Relay::fromGlobalId($list1MemberIdField['id']);
check($list1MemberDecoded['type'] === 'allowlist' && $list1MemberDecoded['id'] === '2025550700', 'allowlist id field relay type and local id for list 1');
check(strpos($list1MemberDecoded['id'], ':') === false, 'allowlist list-1 member local id is bare not 1:n');
$alHarness->listMemberAdd(2, array('number' => '2025550701', 'description' => 'd55-member-l2'));
$list2MemberGqlRow = $alHarness->findListMember(2, '2025550701');
$list2MemberGqlRow['listId'] = 2;
$list2MemberIdField = $gqlHarness->resolveTypeFields('allowlist', $list2MemberGqlRow);
check($list2MemberIdField['id'] === base64_encode('allowlist:2:2025550701'), 'allowlist id field exact global id for named-list member');
$list2MemberDecoded = \GraphQLRelay\Relay::fromGlobalId($list2MemberIdField['id']);
check($list2MemberDecoded['type'] === 'allowlist' && $list2MemberDecoded['id'] === '2:2025550701', 'allowlist id field relay type and local id for list 2');
if (!$alHarness->listExists(3)) {
	$alHarness->listCreate('PaginateThree');
}
if (!$alHarness->listExists(4)) {
	$alHarness->listCreate('PaginateFour');
}
$listsAllCount = count($gqlQueries['allowlistLists'](null, array())['edges']);
$listsPageFirst = $gqlQueries['allowlistLists'](null, array('first' => 2));
check($listsAllCount >= 3 && count($listsPageFirst['edges']) === 2 && !empty($listsPageFirst['pageInfo']['hasNextPage']), 'allowlistLists honors first and omits trailing lists');
$listsPageOne = $gqlQueries['allowlistLists'](null, array('first' => 1));
check(count($listsPageOne['edges']) === 1 && $listsAllCount > 1, 'allowlistLists first returns a single edge when more lists exist');
$listsPageAfter = $gqlQueries['allowlistLists'](null, array('first' => 2, 'after' => $listsPageFirst['pageInfo']['endCursor']));
$idsPageFirst = array_map(function ($edge) { return (int)$edge['node']['id']; }, $listsPageFirst['edges']);
$idsPageAfter = array_map(function ($edge) { return (int)$edge['node']['id']; }, $listsPageAfter['edges']);
check(count($idsPageAfter) > 0 && count(array_intersect($idsPageFirst, $idsPageAfter)) === 0, 'allowlistLists after returns a distinct page');
$listsPageLast = $gqlQueries['allowlistLists'](null, array('last' => 2));
check(count($listsPageLast['edges']) === 2 && !empty($listsPageLast['pageInfo']['hasPreviousPage']), 'allowlistLists last limits edge count');
$listsPageBefore = $gqlQueries['allowlistLists'](null, array('last' => 2, 'before' => $listsPageLast['pageInfo']['startCursor']));
check(count($listsPageBefore['edges']) > 0, 'allowlistLists before returns a page');
$fpHarness->astman->db['/allowlist/sipuser'] = 'relay-guard-sip';
if ($alHarness->findListMember(1, '2025550600') === null) {
	$alHarness->listMemberAdd(1, array('number' => '2025550600', 'description' => 'relay-guard-num'));
}
if ($alHarness->findListMember(2, '2025550605') === null) {
	$alHarness->listMemberAdd(2, array('number' => '2025550605', 'description' => 'relay-guard-l2'));
}
foreach (array(
	array('2025550600', 1),
	array('sipuser', 1),
	array('2:2025550605', 2),
) as $relayGuardCase) {
	$relayLocalId = $relayGuardCase[0];
	$expectListId = $relayGuardCase[1];
	foreach (array('allowlistList', 'extension') as $wrongRelayType) {
		$wrongWrap = $gqlQueries['allowlist'](null, array('id' => base64_encode($wrongRelayType . ':' . $relayLocalId)));
		check($wrongWrap === null, 'allowlist query rejects ' . $wrongRelayType . ' relay type for existing member ' . $relayLocalId);
	}
	$goodWrap = $gqlQueries['allowlist'](null, array('id' => base64_encode('allowlist:' . $relayLocalId)));
	check($goodWrap !== null && (int)$goodWrap['listId'] === $expectListId, 'allowlist query accepts allowlist relay type for member ' . $relayLocalId);
}
$addListMutOut = allowlistUnitGqlRunMutation($gqlHarness, 'addAllowlistList', array('name' => 'MutOutList'));
check($addListMutOut['ok'], 'addAllowlistList mutation succeeds for output field harness');
$addListPayloadOut = $gqlHarness->resolveMutationOutputFields('addAllowlistList', $addListMutOut['result']);
$addListNodeFields = $gqlHarness->resolveTypeFields('allowlistList', $addListPayloadOut['allowlistList']);
$mutOutListId = (int)$addListPayloadOut['allowlistList']['id'];
check($addListNodeFields['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId($mutOutListId) && $addListNodeFields['name'] === 'MutOutList', 'addAllowlistList outputFields allowlistList id and name');
$addListIdDecoded = \GraphQLRelay\Relay::fromGlobalId($addListNodeFields['id']);
check($addListIdDecoded['type'] === 'allowlistList' && $addListIdDecoded['id'] === (string)$mutOutListId, 'addAllowlistList outputFields allowlistList id decodes type and local id');
$renameListMutOut = allowlistUnitGqlRunMutation($gqlHarness, 'renameAllowlistList', array('id' => (string)$mutOutListId, 'name' => 'MutOutRenamed'));
check($renameListMutOut['ok'], 'renameAllowlistList mutation succeeds for output field harness');
$renameListPayloadOut = $gqlHarness->resolveMutationOutputFields('renameAllowlistList', $renameListMutOut['result']);
$renameListNodeFields = $gqlHarness->resolveTypeFields('allowlistList', $renameListPayloadOut['allowlistList']);
check($renameListNodeFields['id'] === Allowlist::formatGqlAllowlistListRelayGlobalId($mutOutListId) && $renameListNodeFields['name'] === 'MutOutRenamed', 'renameAllowlistList outputFields allowlistList id and name');
$renameListIdDecoded = \GraphQLRelay\Relay::fromGlobalId($renameListNodeFields['id']);
check($renameListIdDecoded['type'] === 'allowlistList' && $renameListIdDecoded['id'] === (string)$mutOutListId, 'renameAllowlistList outputFields allowlistList id decodes type and local id');
$removeListMutOut = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlistList', array('id' => (string)$mutOutListId));
check($removeListMutOut['ok'], 'removeAllowlistList mutation succeeds for output field harness');
$removeListPayloadOut = $gqlHarness->resolveMutationOutputFields('removeAllowlistList', $removeListMutOut['result']);
check($removeListPayloadOut['deletedId'] === Allowlist::formatGqlAllowlistListRelayGlobalId($mutOutListId), 'removeAllowlistList outputFields deletedId global id');
$removeListIdDecoded = \GraphQLRelay\Relay::fromGlobalId($removeListPayloadOut['deletedId']);
check($removeListIdDecoded['type'] === 'allowlistList' && $removeListIdDecoded['id'] === (string)$mutOutListId, 'removeAllowlistList outputFields deletedId decodes type and local id');
$unit_core_did_list = array(array('extension' => '5550702', 'cidnum' => '', 'pricid' => false));
$setInboundMutOut = allowlistUnitGqlRunMutation($gqlHarness, 'setInboundRouteAllowlist', array('did' => '5550702', 'cid' => '', 'listId' => '1'));
check($setInboundMutOut['ok'], 'setInboundRouteAllowlist mutation succeeds for output field harness');
$setInboundPayloadOut = $gqlHarness->resolveMutationOutputFields('setInboundRouteAllowlist', $setInboundMutOut['result']);
check($setInboundPayloadOut['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(1), 'setInboundRouteAllowlist outputFields listId global id');
$setInboundListDecoded = \GraphQLRelay\Relay::fromGlobalId($setInboundPayloadOut['listId']);
check($setInboundListDecoded['type'] === 'allowlistList' && $setInboundListDecoded['id'] === '1', 'setInboundRouteAllowlist outputFields listId decodes type and local id');
$unit_core_routing_list = array(array('route_id' => 9, 'name' => 'UnitRoute'));
$setOutboundMutOut = allowlistUnitGqlRunMutation($gqlHarness, 'setOutboundRouteAllowlist', array('routeId' => '9', 'listId' => '2', 'remove' => 0, 'store' => 10));
check($setOutboundMutOut['ok'], 'setOutboundRouteAllowlist mutation succeeds for output field harness');
$setOutboundPayloadOut = $gqlHarness->resolveMutationOutputFields('setOutboundRouteAllowlist', $setOutboundMutOut['result']);
check($setOutboundPayloadOut['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(2), 'setOutboundRouteAllowlist outputFields listId global id');
$setOutboundListDecoded = \GraphQLRelay\Relay::fromGlobalId($setOutboundPayloadOut['listId']);
check($setOutboundListDecoded['type'] === 'allowlistList' && $setOutboundListDecoded['id'] === '2', 'setOutboundRouteAllowlist outputFields listId decodes type and local id');
check(strpos($gql, 'returned as stored (raw, unescaped)') !== false, 'allowlistList name field description documents raw unescaped value');
check((int)$listFieldsHarness['memberCount'] === $countList2, 'allowlistList memberCount field matches AstDB members');
$alHarness->didAdd('5550990', '', 2);
$unit_core_did_list = array(array('extension' => '5550990', 'cidnum' => '', 'pricid' => false));
$list2SummaryField = $alHarness->findAllowlistListSummaryById('2');
$listFieldsHarness = $gqlHarness->resolveTypeFields('allowlistList', $list2SummaryField);
check(in_array('5550990', $listFieldsHarness['inboundRoutes'], true), 'allowlistList inboundRoutes field lists screening route');
$unit_core_routing_list = array(
	array('route_id' => 9, 'name' => 'UnitRoute'),
	array('route_id' => 19, 'name' => 'GqlRead19'),
);
$alHarness->routeAdd(19, 0, 10, 2);
$list2SummaryField = $alHarness->findAllowlistListSummaryById('2');
$listFieldsHarness = $gqlHarness->resolveTypeFields('allowlistList', $list2SummaryField);
check(in_array('19', $listFieldsHarness['outboundRoutes'], true), 'allowlistList outboundRoutes field lists auto-add route');
$listsConn = $gqlQueries['allowlistLists'](null, array('first' => 50));
$listSummaryNodes = array();
foreach ($listsConn['edges'] as $listEdge) {
	$listSummaryNodes[(int)$listEdge['node']['id']] = $listEdge['node'];
}
check(isset($listSummaryNodes[1]) && $listSummaryNodes[1]['name'] === 'Default', 'allowlistLists connection includes default list summary');
check(isset($listSummaryNodes[2]), 'allowlistLists connection includes named list summary');
$list2Resolved = $gqlHarness->resolveTypeFields('allowlistList', $listSummaryNodes[2]);
check((int)$list2Resolved['memberCount'] === count($alHarness->listMemberGetAll(2)), 'allowlistLists row memberCount via field resolver');
check(is_array($list2Resolved['inboundRoutes']) && in_array('5550990', $list2Resolved['inboundRoutes'], true), 'allowlistLists row inboundRoutes via field resolver');
for ($pi = 0; $pi < 4; $pi++) {
	$alHarness->listMemberAdd(2, array('number' => '202555879' . $pi, 'description' => 'pg'));
}
$connPage = $gqlQueries['allAllowlists'](null, array('listId' => '2', 'first' => 3));
check(count($connPage['edges']) === 3 && !empty($connPage['pageInfo']['hasNextPage']), 'allAllowlists paginates with first and hasNextPage');
$list2NodeRow = $gqlHarness->invokeAllowlistGetNode('2:2025558600');
check($list2NodeRow !== null && (int)$list2NodeRow['listId'] === 2 && $list2NodeRow['number'] === '2025558600', 'allowlist getNode returns list-2 member with listId and number');
$list1NodeRow = $gqlHarness->invokeAllowlistGetNode('sipuser');
check($list1NodeRow !== null && (int)$list1NodeRow['listId'] === 1 && $list1NodeRow['number'] === 'sipuser', 'allowlist getNode returns list-1 bare-id member');
$presentListNode = $gqlHarness->invokeAllowlistListGetNode('2');
check($presentListNode !== null && (int)$presentListNode['id'] === 2, 'allowlistList getNode returns summary for existing list');
check($gqlHarness->invokeAllowlistListGetNode('99') === null, 'allowlistList getNode null for nonexistent list');
$gqlHarness->unitGqlReadScopeAllowed = false;
check($gqlHarness->invokeAllowlistGetNode('sipuser') === null && $gqlHarness->invokeAllowlistListGetNode('2') === null, 'getNode lookups null without allowlist read scope');
$gqlHarness->unitGqlReadScopeAllowed = true;
check($gqlHarness->invokeAllowlistGetNode('sipuser') !== null && $gqlHarness->invokeAllowlistListGetNode('2') !== null, 'getNode lookups resolve with allowlist read scope');
foreach (array('2025550600', '2025550601', '2025550602') as $defaultListGetNodeNum) {
	$alHarness->listMemberAdd(1, array('number' => $defaultListGetNodeNum, 'description' => 'getnode-local'));
	$defaultListGetNodeRow = $gqlHarness->invokeAllowlistGetNode($defaultListGetNodeNum);
	check($defaultListGetNodeRow !== null && (int)$defaultListGetNodeRow['listId'] === 1 && $defaultListGetNodeRow['number'] === $defaultListGetNodeNum, 'allowlist getNode resolves default-list local id ' . $defaultListGetNodeNum);
}
$alHarness->listMemberAdd(2, array('number' => '2025550605', 'description' => 'getnode-named'));
$namedListGetNodeRow = $gqlHarness->invokeAllowlistGetNode('2:2025550605');
check($namedListGetNodeRow !== null && (int)$namedListGetNodeRow['listId'] === 2 && $namedListGetNodeRow['number'] === '2025550605', 'allowlist getNode resolves named-list composite local id');
$badNode = $gqlQueries['allowlist'](null, array('id' => base64_encode('allowlist:../x')));
check($badNode === null, 'allowlist query resolve rejects unparseable node id');
$goodNode = $gqlQueries['allowlist'](null, array('id' => base64_encode('allowlist:2:2025558600')));
check($goodNode !== null && (int)$goodNode['listId'] === 2, 'allowlist query resolve returns named-list member');
$alHarness->listMemberDel(2, '2025558600');
$goneNodeQuery = $gqlQueries['allowlist'](null, array('id' => base64_encode('allowlist:2:2025558600')));
check($goneNodeQuery === null, 'allowlist query resolve null after member removed');
check($gqlHarness->invokeAllowlistGetNode('2:2025558600') === null, 'allowlist getNode null after member removed');
check($gqlHarness->invokeAllowlistGetNode('../x') === null, 'allowlist getNode rejects unparseable local id');
$closureRemoveMissingL2 = allowlistUnitGqlRunMutation($gqlHarness, 'removeAllowlist', array('number' => '2025557777888', 'listId' => '2'));
check(!$closureRemoveMissingL2['ok'] && $closureRemoveMissingL2['error'] !== '', 'GQL removeAllowlist list 2 missing member returns UserError');
$extractorOutboundRawList = allowlistUnitGqlInvokeExtractor($gqlHarness, 'gqlMutateSetOutboundRouteAllowlist', array('routeId' => '9', 'listId' => '2', 'remove' => 0, 'store' => 10));
check($extractorOutboundRawList['ok'] && $extractorOutboundRawList['result']['listId'] === Allowlist::formatGqlAllowlistListRelayGlobalId(2) && strpos($fpHarness->astman->db['/allowlist/autoadd/9'], ',2') !== false, 'gqlMutateSetOutboundRouteAllowlist accepts raw numeric listId');
$fp->astman->db['/allowlist/sipuser'] = 'legacy label';
$legacyLocalId = Allowlist::formatGqlAllowlistNodeLocalId(1, 'sipuser');
check($legacyLocalId === 'sipuser', 'legacy list-1 member keeps bare local node id');
$legacyParsed = Allowlist::parseGqlAllowlistNodeLocalId($legacyLocalId);
check($legacyParsed !== false && $al->findListMember($legacyParsed['listId'], $legacyParsed['number']) !== null, 'legacy list-1 node id resolves via node lookup');
check($al->listMemberAdd($al->resolveGraphqlListIdArg($listRelayGlobal), array('number' => '2025550880')) === true, 'addAllowlist listId round-trip accepts allowlistList global id');
check($al->findAllowlistListSummaryById('2') !== null && $al->findAllowlistListSummaryById('2')['id'] === 2, 'allowlistList node lookup by local id');
$al->listMemberDel($al->resolveGraphqlListIdArg($listRelayGlobal), '2025550880');
check($al->findListMember(2, '2025550880') === null, 'removeAllowlist listId round-trip via global id');
check(is_array($al->listMemberGetAll($al->resolveGraphqlListIdArg($listRelayGlobal))), 'allAllowlists listId arg accepts allowlistList global id');
check($al->listRename($al->resolveGraphqlListIdArg($listRelayGlobal), 'RelayRename') === true, 'renameAllowlistList id accepts global id');
check($al->didAdd('5550888', '', $al->resolveGraphqlRouteListIdArg($listRelayGlobal)) === true, 'setInboundRouteAllowlist listId accepts global id');
check($al->routeAdd(9, 0, 10, $al->resolveGraphqlRouteListIdArg($listRelayGlobal)) === true, 'setOutboundRouteAllowlist listId accepts global id');
$al->didDelete('5550888', '');
$al->didDelete('5550777', '');
$al->routeDelete(9);
$al->listMemberDel(2, '2025550777');
check($al->listDelete($al->resolveGraphqlListIdArg($listRelayGlobal)) === true, 'removeAllowlistList id accepts global id');
$unit_core_routing_list = array();

if (!function_exists('core_did_list')) {
	function core_did_list() {
		global $unit_core_did_list;
		return $unit_core_did_list;
	}
	function core_routing_list() {
		return array();
	}
	function empty_freepbx($x) {
		return empty($x);
	}
}
$fixture = json_decode(file_get_contents($root . '/tests/fixtures/astdb-17.0.1.1-screening.json'), true);
list($fp, $al) = fresh();
$fp->astman->db = $fixture['astdb'];
FreePBX::$al = $al;
$unit_core_did_list = $fixture['dids'];
global $ext;
$ext = new FakeExt();
allowlist_hookGet_config('asterisk');
$gosubs = array_values(array_filter($ext->splices, function ($s) { return $s['app'] === 'ext_gosub'; }));
check(count($gosubs) === 3, '17.0.1.1 fixture emits exactly one screening Gosub per screened DID (3)');
foreach ($gosubs as $g) {
	check(($g['rendered'] ?? '') === 'Gosub(app-allowlist-check,s,1(1))', 'legacy single-list install Gosub output on ' . $g['ctx'] . '/' . $g['exten']);
}
$wantLegacy = array(
	array('ext-did-0002', '5550100', 'Gosub(app-allowlist-check,s,1(1))'),
	array('ext-did-0001', 's/15559870000', 'Gosub(app-allowlist-check,s,1(1))'),
	array('ext-did-0001', '15551230000/15559870000', 'Gosub(app-allowlist-check,s,1(1))'),
);
foreach ($wantLegacy as $i => $w) {
	check($gosubs[$i]['ctx'] === $w[0] && $gosubs[$i]['exten'] === $w[1] && ($gosubs[$i]['rendered'] ?? '') === $w[2], '17.0.1.1 fixture screening block ' . ($i + 1) . ' matches expected context/exten');
}
list($fp, $al) = fresh();
$al->listCreate('Sales');
$al->didAdd('5550400', '', 2);
FreePBX::$al = $al;
$unit_core_did_list = array(array('extension' => '5550400', 'cidnum' => '', 'pricid' => false));
$ext = new FakeExt();
allowlist_hookGet_config('asterisk');
$gosubs = array_values(array_filter($ext->splices, function ($s) { return $s['app'] === 'ext_gosub'; }));
check(count($gosubs) === 1 && ($gosubs[0]['rendered'] ?? '') === 'Gosub(app-allowlist-check,s,1(2))', 'named-list route renders Gosub(app-allowlist-check,s,1(2))');
list($fp, $al) = fresh();
$al->auditEntries = array();
$fp->astman->db['/allowlist/did/5550401'] = '88';
FreePBX::$al = $al;
$unit_core_did_list = array(array('extension' => '5550401', 'cidnum' => '', 'pricid' => false));
$ext = new FakeExt();
allowlist_hookGet_config('asterisk');
check(count(array_filter($ext->splices, function ($s) { return $s['app'] === 'ext_gosub'; })) === 0, 'route with missing list id produces no screening Gosub');
check(strpos(implode("\n", $al->auditEntries), 'unscreened') !== false && strpos(implode("\n", $al->auditEntries), '5550401') === false, 'hookGet_config missing-list audit proves mapping was read');

list($fp, $al) = fresh();
$al->auditEntries = array();
$fp->astman->connectedState = false;
$fp->astman->db['/allowlist/did/5550500'] = '1';
FreePBX::$al = $al;
$unit_core_did_list = array(array('extension' => '5550500', 'cidnum' => '', 'pricid' => false));
$ext = new FakeExt();
$hookThrew = false;
try {
	allowlist_hookGet_config('asterisk');
} catch (\Throwable $e) {
	$hookThrew = true;
}
check(!$hookThrew && count(array_filter($ext->splices, function ($s) { return $s['app'] === 'ext_gosub'; })) === 0, 'hookGet_config without AMI does not throw and emits no screening Gosub');
check(strpos(implode("\n", $al->auditEntries), 'Asterisk Manager not connected') !== false && strpos(implode("\n", $al->auditEntries), '5550500') === false, 'AMI-down dialplan audit has no route numbers');

echo "\n$count checks, $fails failed\n";
exit($fails ? 1 : 0);
