<?php /* $Id */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//  Copyright (C) 2006 Magnus Ullberg (magnus@ullberg.us)
//  Portions Copyright (C) 2010 Mikael Carlsson (mickecamino@gmail.com)
//  Portions Copyright 2013 Schmooze Com Inc.
//  Portions Copyright 2018 Sangoma Technologies, Inc
//  Copyright 2021 Magnolia Manor Networks

include __DIR__.'/functions.migrated.php';


function allowlist_hook_core($viewing_itemid, $target_menuid) {
//	var_dump($viewing_itemid, $target_menuid);
        switch ($target_menuid) {
        case 'did':
                if (allowlist_did_get($viewing_itemid)) {
                        $enabled = true;
                } else {
                        $enabled = false;
                }
		$html= '
			<!--allowlist hook -->
			<!--Enable/Disable Allowlist on Route-->
			<div class="element-container">
				<div class="row">
					<div class="col-md-12">
						<div class="row">
							<div class="form-group">
								<div class="col-md-3">
									<label class="control-label" for="enable_allowlist">'. _("Enable Allowlist Screening").'</label>
									<i class="fa fa-question-circle fpbx-help-icon" data-for="enable_allowlist"></i>
								</div>
								<div class="col-md-9 radioset">
									<input type="radio" name="enable_allowlist" id="enable_allowlist_yes" value="yes" '. ($enabled?"CHECKED":"").'>
									<label for="enable_allowlist_yes">'. _("Yes").'</label>
									<input type="radio" name="enable_allowlist" id="enable_allowlist_no" value="no" '. ($enabled?"":"CHECKED").'>
									<label for="enable_allowlist_no">'. _("No").'</label>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="enable_allowlist-help" class="help-block fpbx-help-block">'. _("Controls whether Allowlist screening is used on the Route.").'</span>
					</div>
				</div>
			</div>
			<!--END Enable/Disable Allowlist on Route-->
			<!--END allowlist hook-->
                ';
		return $html;
        case 'routing':
                if (allowlist_route_get($viewing_itemid)) {
                        $autoadd = true;
                } else {
                        $autoadd = false;
                }
		$html= '
			<!--allowlist hook -->
                        <!--Automatically add Outbound Callers to Allowlist on Route-->
                        <div class="element-container">
                                <div class="row">
                                        <div class="col-md-12">
                                                <div class="row">
                                                        <div class="form-group">
								<div class="col-md-3">
									<label class="control-label" for="autoadd_allowlist">'. _("Allowlist Called Numbers").'</label>
									<i class="fa fa-question-circle fpbx-help-icon" data-for="autoadd_allowlist"></i>
								</div>
								<div class="col-md-9 radioset">
									<input type="radio" name="autoadd_allowlist" id="autoadd_allowlist_yes" value="yes" '. ($autoadd?"CHECKED":"").'>
									<label for="autoadd_allowlist_yes">'. _("Yes").'</label>
									<input type="radio" name="autoadd_allowlist" id="autoadd_allowlist_no" value="no" '. ($autoadd?"":"CHECKED").'>
									<label for="autoadd_allowlist_no">'. _("No").'</label>
								</div>
                                                        </div>
                                                </div>
                                        </div>
                                </div>
                                <div class="row">
                                        <div class="col-md-12">
						<span id="autoadd_allowlist-help" class="help-block fpbx-help-block">'. _("Controls whether to automatically add outbound callers on this Route to the Allowlist.").'</span>
                                        </div>
                                </div>
                        </div>
                        <!--END Automatically add Outbound Callers to Allowlist on Route-->
			<!--END allowlist hook-->
                ';
		return $html;
	}
}

function allowlist_hookProcess_core($viewing_itemid, $request) {
//	var_dump($viewing_itemid, $request);
	$allowlist = FreePBX::Allowlist();
	if (!isset($request['action']))
		return;
	switch ($request['action']) {
		case 'addIncoming':
			if ($request['enable_allowlist'] == 'yes') {
				$invalidDIDChars = array('<', '>');
				$extension = trim(str_replace($invalidDIDChars, "", $request['extension']));
				$cidnum = trim(str_replace($invalidDIDChars, "", $request['cidnum']));
				$allowlist->didAdd($extension, $cidnum);
			}
			break;
		case 'delIncoming':
			$extarray = explode('/', $request['extdisplay'], 2);
			if (count($extarray) == 2) {
				$allowlist->didDelete($extarray[0], $extarray[1]);
			}
			break;
		case 'edtIncoming':		 // deleting and adding as in core module - ensures changes to did or cid are updated for the route
			$extarray = explode('/', $request['extdisplay'], 2);
			$invalidDIDChars = array('<', '>');
			$extension = trim(str_replace($invalidDIDChars, "", $request['extension']));
			$cidnum = trim(str_replace($invalidDIDChars, "", $request['cidnum']));
			if (count($extarray) == 2) {
				$allowlist->didDelete($extarray[0], $extarray[1]);
			}
			if ($request['enable_allowlist'] == 'yes') {
				$allowlist->didAdd($extension, $cidnum);
			}
			break;
		case 'addroute':
			if ($request['autoadd_allowlist'] == 'yes') {
				$allowlist->routeAdd($request['id']);
			}
			break;
		case 'delroute':
			$allowlist->routeDelete($request['id']);
			break;
		case 'editroute':		 // deleting and adding as in core module - ensures changes are updated for the route
			$allowlist->routeDelete($request['id']);
			if ($request['autoadd_allowlist'] == 'yes') {
				$allowlist->routeAdd($request['id']);
			}
			break;
	}
}

//not sure how to make this BMO
function allowlist_hookGet_config($engine) {
	global $ext;
	switch($engine) {
		case "asterisk":
			// Code from modules/core/functions.inc.php core_get_config inbound routes
			$didlist = core_did_list();
			if (is_array($didlist)) {
				foreach ($didlist as $item) {

					$exten = trim($item['extension']);
					$cidnum = trim($item['cidnum']);

					if (allowlist_did_get($exten . "/" . $cidnum)) {

						if ($cidnum != '' && $exten == '') {
							$exten = 's';
							$pricid = ($item['pricid']) ? true:false;
						} else if (($cidnum != '' && $exten != '') || ($cidnum == '' && $exten == '')) {
							$pricid = true;
						} else {
							$pricid = false;
						}
						$context = ($pricid) ? "ext-did-0001":"ext-did-0002";

						if (function_exists("empty_freepbx")) {
							$exten = empty_freepbx($exten)?"s":$exten;
						} else {
							$exten = (empty($exten)?"s":$exten);
						}

						$exten = $exten.(empty($cidnum)?"":"/".$cidnum); //if a CID num is defined, add it
						$ext->splice($context, $exten, 'did', new ext_set('alreturnhere', '1'));
						$ext->splice($context, $exten, 'did', new ext_gosub('1', 's', 'app-allowlist-check'));
						$ext->splice($context, $exten, 'callerid', new ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}'));

						//$ext->splice($context, $exten, 'did-cid-hook', new ext_set('alreturnhere', '1'),"",1);
						//$ext->splice($context, $exten, 'did-cid-hook', new ext_gosub('1', 's', 'app-allowlist-check'),"",2);
						//$ext->splice($context, $exten, 'did-cid-hook', new ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}'),"",3);
					}
				}
			} // else no DID's defined. Not even a catchall.
			$routelist = core_routing_list();
			if (is_array($routelist)) {		// make sure we do not fail if no outbound routes are defined.
				$context = "macro-dialout-trunk";
				$exten = "s";
				$splice_position = 0;

				$ext->splice($context, $exten, 'gocall', new ext_gotoif('$["${DB_EXISTS(allowlist/autoadd/${ROUTEID})}" = "0"]', 'gocall'),"",$splice_position);
				$ext->splice($context, $exten, 'gocall', new ext_agi('allowlist-autoadd.agi'),"",$splice_position);
			}
			break;
	}
}

function allowlist_did_get($did) {
        $extarray = explode('/', $did, 2);
        if (count($extarray) == 2) {
    		return FreePBX::Allowlist()->didIsSet($extarray[0],$extarray[1]);
        }
        return false;
}

function allowlist_route_get($route_id) {
    	return FreePBX::Allowlist()->routeIsSet($route_id);
}
