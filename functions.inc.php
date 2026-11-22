<?php /* $Id */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
//        License for all code of this FreePBX module can be found in the license file inside the module directory
//  Copyright (C) 2006 Magnus Ullberg (magnus@ullberg.us)
//  Portions Copyright (C) 2010 Mikael Carlsson (mickecamino@gmail.com)
//  Portions Copyright 2013 Schmooze Com Inc.
//  Portions Copyright 2018 Sangoma Technologies, Inc
//  Copyright 2021 Magnolia Manor Networks

include __DIR__.'/functions.migrated.php';


function allowlist_hook_core($viewing_itemid, $target_menuid) {
    switch ($target_menuid) {
        case 'did':
            $current = allowlist_did_list_state($viewing_itemid);
            $select = allowlist_list_select_html('allowlist_screen_list', 'allowlist_screen_list', $current['listId'], _("No screening"));
            $stale = $current['stale'] ? '<div class="alert alert-warning">' . _("This route points at an allowlist that no longer exists, so calls on it are not screened. Choose a list and submit to screen it again.") . '</div>' : '';
            $html= '
                <!--allowlist hook -->
                <!--Allowlist used to screen this Route-->
                <div class="element-container">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="row">
                                <div class="form-group">
                                    <div class="col-md-3">
                                        <label class="control-label" for="allowlist_screen_list">'. _("Allowlist Screening").'</label>
                                        <i class="fa fa-question-circle fpbx-help-icon" data-for="allowlist_screen_list"></i>
                                    </div>
                                    <div class="col-md-9">
                                        '. $stale . $select .'
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <span id="allowlist_screen_list-help" class="help-block fpbx-help-block">'. _("Choose which allowlist screens calls on this Route, or No screening to let every caller through.").'</span>
                        </div>
                    </div>
                </div>
                <!--END Allowlist used to screen this Route-->
                <!--END allowlist hook-->
            ';
            return $html;

        case 'routing':
            $autoadd = allowlist_route_get($viewing_itemid);
            $remcount = allowlist_route_remcount($viewing_itemid);
            $stocount = allowlist_route_stocount($viewing_itemid);
            $routeListId = allowlist_route_list_id($viewing_itemid);
            $routeSelect = allowlist_list_select_html('autoadd_allowlist_list', 'autoadd_allowlist_list', $routeListId === false ? 1 : $routeListId, null);
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
                                    <div class="col-md-1 radioset">
                                        <input type="radio" name="autoadd_allowlist" id="autoadd_allowlist_yes" value="yes" '. ($autoadd?"checked":"").'>
                                        <label for="autoadd_allowlist_yes">'. _("Yes").'</label>
                                        <input type="radio" name="autoadd_allowlist" id="autoadd_allowlist_no" value="no" '. ($autoadd?"":"checked").'>
                                        <label for="autoadd_allowlist_no">'. _("No").'</label>
                                    </div>
                                    <div class="col-md-2 text-right">
                                        <label class="control-label" for="leadigits">'. _("digits to remove").'</label>
                                        <i class="fa fa-question-circle fpbx-help-icon" data-for="leadigits"></i>
                                    </div>
                                    <div class="col-md-1">
                                        <input type="number" class="form-control" id="leadigits" name="remcount" value='. ($remcount != null?"$remcount":"0").'>
                                    </div>
                                    <div class="col-md-2 text-right">
                                        <label class="control-label" for="stodigits">'. _("digits to store").'</label>
                                        <i class="fa fa-question-circle fpbx-help-icon" data-for="stodigits"></i>
                                    </div>
                                    <div class="col-md-1">
                                        <input type="number" class="form-control" id="stodigits" name="stocount" value='. ($stocount != null?"$stocount":"99").'>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="sr-only" for="autoadd_allowlist_list">'. _("Add to list").'</label>
                                        '. $routeSelect .'
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <span id="autoadd_allowlist-help" class="help-block fpbx-help-block">'. _("Controls whether to automatically add outbound callers on this Route to the Allowlist.").'</span>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <span id="leadigits-help" class="help-block fpbx-help-block">'. _("Number of leading digits to remove").'</span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <span id="stodigits-help" class="help-block fpbx-help-block">'. _("Number of digits to store").'</span>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <span id="autoadd_allowlist_list-help" class="help-block fpbx-help-block">'. _("Allowlist that called numbers are added to").'</span>
                            </div>
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
    $allowlist = FreePBX::Allowlist();
    if (!isset($request['action']))
        return;
    
    switch ($request['action']) {
        case 'addIncoming':
            $screen = allowlist_hook_requested_screen_list($request);
            if ($screen !== null) {
                $invalidDIDChars = array('<', '>');
                $extension = trim(str_replace($invalidDIDChars, "", $request['extension']));
                $cidnum = trim(str_replace($invalidDIDChars, "", $request['cidnum']));
                if (!$allowlist->didAdd($extension, $cidnum, $screen)) {
                    $allowlist->reportHookRejection(_('inbound route'));
                }
            }
            break;
        case 'delIncoming':
            $extarray = explode('/', $request['extdisplay'], 2);
            if (count($extarray) == 2) {
                if ($allowlist->didDelete($extarray[0], $extarray[1]) === false) {
                    $allowlist->reportHookRejection(_('inbound route'));
                }
            }
            break;
        case 'edtIncoming':                 // deleting and adding as in core module - ensures changes to did or cid are updated for the route
            $extarray = explode('/', $request['extdisplay'], 2);
            $invalidDIDChars = array('<', '>');
            $extension = trim(str_replace($invalidDIDChars, "", $request['extension']));
            $cidnum = trim(str_replace($invalidDIDChars, "", $request['cidnum']));
            // Validate and write the new setting first; remove the old one only
            // after that succeeds, so a rejected value never drops the route's setting.
            $old = count($extarray) == 2 ? $extarray : null;
            $screen = allowlist_hook_requested_screen_list($request);
            if ($screen !== null) {
                if (!$allowlist->didAdd($extension, $cidnum, $screen)) {
                    $allowlist->reportHookRejection(_('inbound route'));
                    break;
                }
                if ($old !== null && ($old[0] !== $extension || $old[1] !== $cidnum)) {
                    if ($allowlist->didDelete($old[0], $old[1]) === false) {
                        $allowlist->reportHookRejection(_('inbound route'));
                    }
                }
            } elseif ($old !== null) {
                if ($allowlist->didDelete($old[0], $old[1]) === false) {
                    $allowlist->reportHookRejection(_('inbound route'));
                }
            }
            break;
        case 'addroute':
            if ($request['autoadd_allowlist'] == 'yes') {
                if (!$allowlist->routeAdd($request['id'], $request['remcount'], $request['stocount'], allowlist_hook_requested_autoadd_list($request))) {
                    $allowlist->reportHookRejection(_('outbound route auto-add'));
                }
            }
            break;
        case 'delroute':
            if ($allowlist->routeDelete($request['id']) === false) {
                $allowlist->reportHookRejection(_('outbound route auto-add'));
            }
            break;
        case 'editroute':                 // deleting and adding as in core module - ensures changes are updated for the route
            // routeAdd overwrites the same key, so only delete when auto-add is
            // turned off; a rejected value leaves the existing setting in place.
            if ($request['autoadd_allowlist'] == 'yes') {
                if (!$allowlist->routeAdd($request['id'], $request['remcount'], $request['stocount'], allowlist_hook_requested_autoadd_list($request))) {
                    $allowlist->reportHookRejection(_('outbound route auto-add'));
                }
            } else {
                if ($allowlist->routeDelete($request['id']) === false) {
                    $allowlist->reportHookRejection(_('outbound route auto-add'));
                }
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

                    $screenListId = allowlist_inbound_screen_list_id($exten, $cidnum);
                    if ($screenListId !== false) {

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
                        $ext->splice($context, $exten, 'callerid', new ext_set('alreturnhere', '1'),"allowchecks",1);
                        $ext->splice($context, $exten, 'allowchecks', new ext_gosub('1', 's', 'app-allowlist-check', (int)$screenListId),"",1);
                        $ext->splice($context, $exten, 'allowchecks', new ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}'),"",2);

                        //$ext->splice($context, $exten, 'did-cid-hook', new ext_set('alreturnhere', '1'),"",1);
                        //$ext->splice($context, $exten, 'did-cid-hook', new ext_gosub('1', 's', 'app-allowlist-check'),"",2);
                        //$ext->splice($context, $exten, 'did-cid-hook', new ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}'),"",3);
                    }
                }
            } // else no DID's defined. Not even a catchall.
            $routelist = core_routing_list();
	    if (is_array($routelist) && isset($routelist[0])) {
		    $trunklist = FreePBX::Core()->getRouteTrunksByID($routelist[0]['route_id']); // fix for FC-346
	    } else {
		    $trunklist = [];
	    }
            if (is_array($routelist) && !empty($routelist) && !empty($trunklist)) { // make sure we do not fail if no outbound routes are defined.
                $context = "macro-dialout-trunk";
                $exten = "s";
                $splice_position = 0;
				$ext->splice($context, $exten, 'gocall', new ext_execif('$[${DB_EXISTS(allowlist/autoadd/${ROUTEID})}]', 'AGI', 'allowlist-autoadd.agi,'));
            }
            break;
    }
}

/*
 * List chosen on the inbound route form, or null for no screening. The select
 * posts one value; the value is passed through unvalidated so didAdd() rejects
 * anything that is not a known list id (including an array from a hand-crafted
 * multi-value POST). A form that only posts the pre-17.0.2 enable_allowlist
 * radio keeps working: "yes" means the Default list.
 */
function allowlist_hook_requested_screen_list($request) {
    if (array_key_exists('allowlist_screen_list', $request)) {
        return $request['allowlist_screen_list'] === '' ? null : $request['allowlist_screen_list'];
    }
    return (isset($request['enable_allowlist']) && $request['enable_allowlist'] == 'yes') ? 1 : null;
}

/* Target list for outbound auto-add; Default when the form does not send one. */
function allowlist_hook_requested_autoadd_list($request) {
    if (array_key_exists('autoadd_allowlist_list', $request) && $request['autoadd_allowlist_list'] !== '') {
        return $request['autoadd_allowlist_list'];
    }
    return 1;
}

/* Every list name goes through formatListNameForDisplay(); ids are cast to int. */
function allowlist_list_select_html($name, $id, $selected, $noneLabel) {
    $html = '<select class="form-control" name="' . $name . '" id="' . $id . '">';
    if ($noneLabel !== null) {
        $html .= '<option value=""' . ($selected === false ? ' selected' : '') . '>' . htmlspecialchars($noneLabel, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    foreach (FreePBX::Allowlist()->listGetAll() as $listId => $listName) {
        $html .= '<option value="' . (int)$listId . '"' . ($selected !== false && (int)$selected === (int)$listId ? ' selected' : '') . '>' . \FreePBX\modules\Allowlist::formatListNameForDisplay($listName) . '</option>';
    }
    return $html . '</select>';
}

/*
 * Screening state of the inbound route being viewed. stale is true when a did/
 * key exists but does not name a list that exists, which the dialplan treats as
 * unscreened (ADR Q5).
 */
function allowlist_did_list_state($did) {
    $extarray = explode('/', $did, 2);
    if (count($extarray) != 2) {
        return array('listId' => false, 'stale' => false);
    }
    $allowlist = FreePBX::Allowlist();
    if (!$allowlist->didIsSet($extarray[0], $extarray[1])) {
        return array('listId' => false, 'stale' => false);
    }
    $listId = $allowlist->didGetListId($extarray[0], $extarray[1]);
    if ($listId === false || !$allowlist->listExists($listId)) {
        return array('listId' => false, 'stale' => true);
    }
    return array('listId' => $listId, 'stale' => false);
}

function allowlist_route_list_id($route_id) {
    return FreePBX::Allowlist()->routeGetListId($route_id);
}

function allowlist_inbound_screen_list_id($did, $cid) {
    return FreePBX::Allowlist()->dialplanScreenListId($did, $cid);
}

function allowlist_route_get($route_id) {
    return FreePBX::Allowlist()->routeIsSet($route_id);
}

function allowlist_route_remcount($route_id) {
        return FreePBX::Allowlist()->routeRemCount($route_id);
}

function allowlist_route_stocount($route_id) {
        return FreePBX::Allowlist()->routeStoCount($route_id);
}
