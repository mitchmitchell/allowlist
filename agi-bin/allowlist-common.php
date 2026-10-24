<?php
// helper functions

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

function searchAllowList ($number) {
	global $AGI;
	$found = false;
	if (!allowlistSafeCid($number)) {
		return false;
	}
	$AGI->verbose("searching allow list for \"" . allowlistMask($number) . "\".");
	$res = $AGI->database_get('allowlist',$number);
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
