<?php
// helper functions

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
		$search=$FreePBX->Contactmanager->getNamebyNumber($number);    
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
