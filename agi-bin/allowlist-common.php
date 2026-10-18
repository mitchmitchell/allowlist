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
	if (in_array($n, array('dest', 'pause', 'knowncallers'), true) || strpos($n, 'did/') === 0 || strpos($n, 'autoadd/') === 0) {
		return false;
	}
	return $n;
}

function searchAllowList ($number) {
	global $AGI;
	$found = false;
	$AGI->verbose("searching allow list for \"$number\".");
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

	// Is Contact Manager enabled and active?
	try {
		$AGI->verbose("Searching all Contact Mgr groups for $number");	
		$grouplist = $FreePBX->Contactmanager->getGroups();
		foreach ($grouplist as $group) {
			$groupids[]=$group['id'];
		}
		$search=$FreePBX->Contactmanager->getNamebyNumber($number, $groupids);    
		if (strlen($search['id'])!=0) {	// dialed number is in contact manager
		// contact found
			$found = true;
		}
	} catch (\Exception $e) {
		// Contact Manager not active, or not enabled, don't do anything
		$AGI->verbose("Contact Mgr not installed or disabled - will NOT search it for $number");	
	}
	Return $found;
}

function searchAsteriskPhonebook($number) {
	global $AGI;
	$found = false;
	$AGI->verbose("searching asterisk phonebook for \"$number\".");
	$res = $AGI->database_get('cidname',$number);
	if ($res['result'] != 0) { // dialed number is in asterisk phonebook
		$found = true;
	}
	Return $found;
}
