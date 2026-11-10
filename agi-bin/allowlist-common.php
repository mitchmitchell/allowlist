<?php
// helper functions

/**
 * Mirror of \FreePBX\modules\Allowlist::parseListIdValue() for AGI scripts. Keep
 * the two in step; tests/unit checks they agree on list id vectors.
 *
 * @return int|false Parsed id (1..9999), or false
 */
function allowlistParseListIdValue($raw) {
	if (is_int($raw)) {
		$s = (string)$raw;
	} elseif (is_string($raw)) {
		$s = $raw;
	} else {
		return false;
	}
	if (!preg_match('/^[0-9]{1,4}$/D', $s)) {
		return false;
	}
	$id = (int)$s;
	return $id >= 1 ? $id : false;
}

/**
 * Mirror of \FreePBX\modules\Allowlist::parseAutoaddMappingValue() list id handling.
 *
 * @return int|false
 */
function allowlistParseAutoaddListId($autoaddValue) {
	if (!is_string($autoaddValue) || !preg_match('/^1,[0-9]{1,6},[0-9]{1,6}(,[0-9]{1,4})?$/D', $autoaddValue)) {
		return false;
	}
	$parts = explode(',', $autoaddValue);
	if ((int)$parts[0] !== 1) {
		return false;
	}
	return count($parts) > 3 ? allowlistParseListIdValue($parts[3]) : 1;
}

/**
 * @return bool Whether list metadata exists (list 1 is always present).
 */
function allowlistListExists($listId) {
	global $AGI;
	$id = allowlistParseListIdValue($listId);
	if ($id === false || !isset($AGI) || !is_object($AGI)) {
		return false;
	}
	if ($id === 1) {
		return true;
	}
	$res = $AGI->database_get('allowlist-lists', (string)$id);
	return is_array($res) && !empty($res['result']) && isset($res['data']) && $res['data'] !== '';
}

function allowlistAuditLogNotice($message) {
	global $allowlist_test_audit_logs;
	if (isset($allowlist_test_audit_logs) && is_array($allowlist_test_audit_logs)) {
		$allowlist_test_audit_logs[] = $message;
	}
	if (function_exists('freepbx_log') && defined('FPBX_LOG_NOTICE')) {
		freepbx_log(FPBX_LOG_NOTICE, $message);
	} else {
		error_log($message);
	}
}

/**
 * @return int|false Target list id, or false after auditing a fail-closed skip
 */
function allowlistResolveAutoaddTargetList($routeId, $autoaddValue) {
	$routeKey = allowlistParseListIdValue($routeId);
	$routeLogged = $routeKey === false ? 0 : $routeKey;
	$parsed = allowlistParseAutoaddListId(is_string($autoaddValue) ? $autoaddValue : '');
	if ($parsed === false) {
		allowlistAuditLogNotice('Allowlist auto-add: outbound route ' . $routeLogged . ' skipped (unparseable autoadd value)');
		return false;
	}
	if (!allowlistListExists($parsed)) {
		allowlistAuditLogNotice('Allowlist auto-add: outbound route ' . $routeLogged . ' skipped (missing allowlist list)');
		return false;
	}
	return $parsed;
}

/**
 * @return array AGI database_put result shape
 */
function allowlistMemberPut($AGI, $listId, $number, $description) {
	$id = allowlistParseListIdValue($listId);
	if ($id === false) {
		return array('result' => 0);
	}
	if ($id === 1) {
		return $AGI->database_put('allowlist', $number, $description);
	}
	return $AGI->database_put('allowlist-list', $id . '/' . $number, $description);
}

/**
 * Mirror of \FreePBX\modules\Allowlist::normalizeNumber() for AGI scripts, which
 * run without the module class. Keep the two in step; tests/unit checks they agree.
 * Digits only, optional leading +, 1-20 digits; settings keys are never valid.
 * @return string|false Trimmed number, or false
 */
function allowlistNormalizeNumber($n) {
	if (!is_string($n)) {
		return false;
	}
	$n = trim($n);
	if (!preg_match('/^\+?[0-9]{1,20}$/D', $n)) {
		return false;
	}
	if (in_array($n, array('dest', 'pause', 'knowncallers', 'pausepin', 'debuglog'), true) || strpos($n, 'did/') === 0 || strpos($n, 'autoadd/') === 0) {
		return false;
	}
	return $n;
}

/**
 * L3: mask a caller/dialed number for verbose logs (last 4 digits only), unless
 * the AstDB key allowlist/debuglog is set to 1 (full numbers for debugging).
 */
function allowlistMask($number, $debug = null) {
	if ($debug === null) {
		$debug = allowlistDebugLog();
	}
	$s = is_scalar($number) ? (string)$number : '';
	if ($debug) {
		return $s;
	}
	$len = strlen($s);
	return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4) . substr($s, -4);
}

function allowlistDebugLog() {
	global $AGI;
	static $debug = null;
	if ($debug === null) {
		$debug = false;
		if (isset($AGI) && is_object($AGI)) {
			$res = $AGI->database_get('allowlist', 'debuglog');
			$debug = is_array($res) && !empty($res['result']) && isset($res['data']) && $res['data'] === '1';
		}
	}
	return $debug;
}

/**
 * L1: only a sane caller ID may reach AGI DATABASE GET or Contact Manager:
 * digits with an optional leading +, 1-20 digits. Quotes, spaces, empty and
 * "anonymous" never reach a lookup.
 */
function allowlistSafeCid($number) {
	return is_string($number) && preg_match('/^\+?[0-9]{1,20}$/D', $number) === 1;
}

function searchAllowList ($number, $listId = 1) {
	global $AGI;
	$found = false;
	if (!allowlistSafeCid($number)) {
		return false;
	}
	$id = allowlistParseListIdValue($listId);
	if ($id === false) {
		return false;
	}
	$AGI->verbose("searching allow list for \"" . allowlistMask($number) . "\".");
	if ($id === 1) {
		$res = $AGI->database_get('allowlist', $number);
	} else {
		$res = $AGI->database_get('allowlist-list', $id . '/' . $number);
	}
	if ($res['result'] != 0) { // dialed number is in the allowlist
		$found = true;
	}
	Return $found;
}

function searchContactManager ($number) {
	global $AGI;
	global $FreePBX;
	$found = false;
	if (!allowlistSafeCid($number)) {
		return false;
	}
	$groupids = array();

	// Is Contact Manager enabled and active?
	try {
		$AGI->verbose("Searching all Contact Mgr groups for " . allowlistMask($number));
		$grouplist = $FreePBX->Contactmanager->getGroups();
		foreach ($grouplist as $group) {
			$groupids[]=$group['id'];
		}
		$search=$FreePBX->Contactmanager->getNamebyNumber($number, $groupids);    
		if (is_array($search) && isset($search['id']) && strlen((string)$search['id']) != 0) {	// dialed number is in contact manager
		// contact found
			$found = true;
		}
	} catch (\Exception $e) {
		// Contact Manager not active, or not enabled, don't do anything
		$AGI->verbose("Contact Mgr not installed or disabled - will NOT search it for " . allowlistMask($number));
	}
	Return $found;
}

function searchAsteriskPhonebook($number) {
	global $AGI;
	$found = false;
	if (!allowlistSafeCid($number)) {
		return false;
	}
	$AGI->verbose("searching asterisk phonebook for \"" . allowlistMask($number) . "\".");
	$res = $AGI->database_get('cidname',$number);
	if ($res['result'] != 0) { // dialed number is in asterisk phonebook
		$found = true;
	}
	Return $found;
}
