<?php
namespace FreePBX\modules;
// vim: set ai ts=4 sw=4 ft=php expandtab:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//      Copyright 2021 Magnolia Manor Networks

use BMO;
use RuntimeException;

class Allowlist implements BMO
{

    public function __construct($freepbx = null)
    {
        if ($freepbx == null)
        {
            throw new RuntimeException('Not given a FreePBX Object');
        }
        $this->FreePBX = $freepbx;
        $this->astman = $this->FreePBX->astman;

        if (false)
        {
            _('Allow a number');
            _('Remove a number from the allowlist');
            _('Allow the last caller');
            _('Allowlist');
            _('Adds a number to the Allowlist Module.  All calls from that number to the system will be allowed to proceed normally.  Manage these in the Allowlist module.');
            _('Removes a number from the Allowlist Module');
            _('Adds the last caller to the Allowlist Module.  All calls from that number to the system will be allowed to proceed normally.');
        }
    }

    public function ajaxRequest($req, &$setting)
    {
        switch ($req)
        {
            case 'add':
            case 'edit':
            case 'del':
            case 'block':
            case 'pause':
            case 'bulkdelete':
            case 'getJSON':
            case 'calllog':
                return true;
            break;
        }
        return false;
    }

    public function ajaxHandler()
    {

        $request = $_REQUEST;
        if (!empty($_REQUEST['oldval']) && $_REQUEST['command'] == 'add')
        {
            $_REQUEST['command'] = 'edit';
        }
        switch ($_REQUEST['command'])
        {
            case 'add':
                $this->numberAdd($request);
                return array(
                    'status' => true
                );
            break;
            case 'edit':
                $this->numberDel($request['oldval']);
                $this->numberAdd($request);
                return array(
                    'status' => true
                );
            break;
            case 'bulkdelete':
                $numbers = isset($_REQUEST['numbers']) ? $_REQUEST['numbers'] : array();
                $numbers = json_decode($numbers, true);
                foreach ($numbers as $number)
                {
                    $this->numberDel($number);
                }
                return array(
                    'status' => 'true',
                    'message' => _("Numbers Deleted")
                );
            break;
            case 'del':
                $ret = $this->numberDel($request['number']);
                return array(
                    'status' => $ret
                );
            break;
            case 'block':
                $ret = $this->numberBlock($request);
                return array(
                    'status' => $ret
                );
            break;
            case 'pause':
                $ret = $this->pauseSet($request['pause']);
                return array(
                    'status' => $ret
                );
            break;
            case 'calllog':
                $number = $request['number'];
                $sql = sprintf('SELECT DISTINCT calldate FROM %s WHERE src = ?', $this->FreePBX->Cdr->getDbTable());
                $cdrdbh =  $this->FreePBX->Cdr->getCdrDbHandle(); 
                $stmt = $cdrdbh->prepare($sql);
                $stmt->execute(array($number));
                $ret = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                return $ret;
            break;
            case 'getJSON':
                switch ($request['jdata'])
                {
                    case 'grid':
                        $ret = array();
                        $allowlist = $this->getAllowlist();
                        foreach ($allowlist as $item)
                        {
                            $number = $item['number'];
                            $description = $item['description'];
                            if ($number == 'dest' || $number == 'pause' || $number == 'knowncallers' || substr($number, 0, 7) == 'autoadd' || substr($number, 0, 3) == 'did')
                            {
                                continue;
                            }
                            else
                            {
                                $ret[] = array(
                                    'number' => $number,
                                    'description' => $description
                                );
                            }
                        }
                        return $ret;
                    break;
                }
            break;
        }
    }

    //BMO Methods
    public function install()
    {
        $fcc = new \featurecode('allowlist', 'allowlist_add');
        $fcc->setDescription('Add a number to the allowlist');
        $fcc->setHelpText('Adds a number to the Allowlist Module.  All calls from that number to the system will will be allowed to proceed normally.  Manage these in the Allowlist module.');
        $fcc->setDefault('*38');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_remove');
        $fcc->setDescription('Remove a number from the allowlist');
        $fcc->setHelpText('Removes a number from the Allowlist Module');
        $fcc->setDefault('*39');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_last');
        $fcc->setDescription('Add the last caller to the allowlist');
        $fcc->setHelpText('Adds the last caller to the Allowlist Module.  All calls from that number to the system will be allowed to proceed normally.');
        $fcc->setDefault('*40');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_pause_toggle');
        $fcc->setDescription('Pause or unpause Allowlist checking');
        $fcc->setHelpText('Temporarily pause or unpause the Allowlist module operation system wide');
        $fcc->setDefault('*41');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);
    }
    public function uninstall()
    {
    }

    public function doConfigPageInit($page)
    {
        $dispnum = 'allowlist';
        $astver = $this->FreePBX->Config->get('ASTVERSION');
        $request = $_REQUEST;

        if (isset($request['goto0']))
        {
            $destination = $request[$request['goto0'] . '0'];
        }
        isset($request['action']) ? $action = $request['action'] : $action = '';
        isset($request['oldval']) ? $action = 'edit' : $action;
        isset($request['number']) ? $number = $request['number'] : $number = '';
        isset($request['description']) ? $description = $request['description'] : $description = '';

        if (isset($request['action']))
        {
            switch ($action)
            {
                case 'settings':
                    $this->destinationSet($destination);
                    $this->pauseSet($request['pause']);
                    $this->allowknowncallersSet($request['knowncallers']);
                break;
                case 'import':
                    if ($_FILES['file']['error'] > 0)
                    {
                        echo '<div class="alert alert-danger" role="alert">' . _('There was an error uploading the file') . '</div>';
                    }
                    else
                    {
                        if (pathinfo($_FILES['allowlistfile']['name'], PATHINFO_EXTENSION) == 'csv')
                        {
                            $path = sys_get_temp_dir() . '/' . $_FILES['allowlistfile']['name'];
                            move_uploaded_file($_FILES['allowlistfile']['tmp_name'], $path);
                            if (file_exists($path))
                            {
                                ini_set('auto_detect_line_endings', true);
                                $handle = fopen($path, 'r');
                                set_time_limit(0);
                                while (($data = fgetcsv($handle)) !== false)
                                {
                                    if ($data[0] == 'number' && $data[1] == 'description')
                                    {
                                        continue;
                                    }
                                    allowlist_add(array(
                                        'number' => $data[0],
                                        'description' => $data[1]
                                    ));
                                }
                                unlink($path);
                                echo '<div class="alert alert-success" role="alert">' . _('Sucessfully imported all entries') . '</div>';
                            }
                            else
                            {
                                echo '<div class="alert alert-danger" role="alert">' . _('Could not find file after upload') . '</div>';
                            }
                        }
                        else
                        {
                            echo '<div class="alert alert-danger" role="alert">' . _('The file must be in CSV format!') . '</div>';
                        }
                    }
                break;
                case 'export':
                    $list = $this->getAllowlist();
                    if (!empty($list))
                    {
                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename=allowlist.csv');
                        $output = fopen('php://output', 'w');
                        fputcsv($output, array(
                            'number',
                            'description'
                        ));
                        foreach ($list as $l)
                        {
                            fputcsv($output, $l);
                        }
                    }
                    else
                    {
                        header('HTTP/1.0 404 Not Found');
                        echo _('No Entries to export');
                    }
                    die();
                break;
            }
        }
    }

    public function myDialplanHooks()
    {
        return 400;
    }

    public function doDialplanHook(&$ext, $engine, $priority)
    {
        $modulename = 'allowlist';
        //Add
        $fcc = new \featurecode($modulename, 'allowlist_add');
        $addfc = $fcc->getCodeActive();
        unset($fcc);
        //Delete
        $fcc = new \featurecode($modulename, 'allowlist_remove');
        $delfc = $fcc->getCodeActive();
        unset($fcc);
        //Last
        $fcc = new \featurecode($modulename, 'allowlist_last');
        $lastfc = $fcc->getCodeActive();
        unset($fcc);
	//pause toggle
        $fcc = new \featurecode($modulename, 'allowlist_pause_toggle');
        $togglefc = $fcc->getCodeActive();
        unset($fcc);		

        $id = 'app-allowlist';
        $c = 's';
        $ext->addInclude('from-internal-additional', $id); // Add the include from from-internal
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));

        $id = 'app-allowlist-check';
        $ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS(app-allowlist-check-predial-hook,s,1)}]', 'app-allowlist-check-predial-hook,s,1'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${callerallowed}"="1"]', 'returnto'));

	// check pause time and exit if pause is enabled
        $ext->add($id, $c, '', new \ext_gosub('app-allowlist-pause-check,s,1'));
	$ext->add($id, $c, '', new \ext_gotoif('$["${DB_EXISTS(allowlist/pause)}"="1"]', 'returnto'));

        $ext->add($id, $c, 'check-list', new \ext_gotoif('$["${DB_EXISTS(allowlist/${CALLERID(num)})}"="0"]', 'check-contacts'));
        $ext->add($id, $c, '', new \ext_setvar('CALLED_ALLOWLIST', '1'));
        $ext->add($id, $c, '', new \ext_return(''));

        $ext->add($id, $c, 'check-contacts', new \ext_gotoif('$["${DB_EXISTS(allowlist/knowncallers)}" = "0"]', 'nonallowlisted'));
        $ext->add($id, $c, '', new \ext_agi('allowlist-check.agi,"allowlisted"'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${allowlisted}"="false"]', 'nonallowlisted'));
        $ext->add($id, $c, '', new \ext_setvar('CALLED_ALLOWLIST', '1'));
        $ext->add($id, $c, '', new \ext_return(''));

        $ext->add($id, $c, 'nonallowlisted', new \ext_answer(''));
        $ext->add($id, $c, '', new \ext_set('ALDEST', '${DB(allowlist/dest)}'));

        $ext->add($id, $c, '', new \ext_execif('$["${ALDEST}"=""]', 'Set', 'ALDEST=app-blackhole,hangup,1'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${alreturnhere}"="1"]', 'returnto'));
        $ext->add($id, $c, '', new \ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}', 'app-blackhole,zapateller,1'));

        //		$ext->add($id, $c, '', new \ext_gotoif('$["${alreturnhere}"="1"]', 'returnto'));
        //		$ext->add($id, $c, '', new \ext_gotoif('${LEN(${ALDEST})}', '${ALDEST}', 'returnto'));
        $ext->add($id, $c, 'returnto', new \ext_return());

        //Dialplan for add
        if (!empty($addfc))
        {
            $ext->add('app-allowlist', $addfc, '', new \ext_goto('1', 's', 'app-allowlist-add'));
        }
        $id = 'app-allowlist-add';
        $c = 's';
        $ext->add($id, $c, '', new \ext_answer());
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_set('NumLoops', 0));
        $ext->add($id, $c, 'start', new \ext_digittimeout(5));
        $ext->add($id, $c, '', new \ext_responsetimeout(10));
        $ext->add($id, $c, '', new \ext_read('allownr', 'enter-num-whitelist&vm-then-pound'));
        $ext->add($id, $c, '', new \ext_saydigits('${allownr}'));
        // i18n - Some languages need this is a different format. If we don't
        // know about the language, assume english
        $ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS(' . $id . ',${CHANNEL(language)})}]', $id . ',${CHANNEL(language)},1', $id . ',en,1'));
        // en - default
        $ext->add($id, 'en', '', new \ext_digittimeout(1));
        $ext->add($id, 'en', '', new \ext_read('confirm', 'if-correct-press&digits/1&to-enter-a-diff-number&press&digits/2'));
        $ext->add($id, 'en', '', new \ext_return());
        // ja
        $ext->add($id, 'ja', '', new \ext_digittimeout(1));
        $ext->add($id, 'ja', '', new \ext_read('if-correct-press&digits/1&pleasepress'));
        $ext->add($id, 'ja', '', new \ext_return());

        $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]', 'app-allowlist-add,1,1'));
        $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "2" ]', 'app-allowlist-add,2,1'));
        $ext->add($id, $c, '', new \ext_goto('app-allowlist-add-invalid,s,1'));

        $c = '1';
        $ext->add($id, $c, '', new \ext_gotoif('$[ "${allownr}" != ""]', '', 'app-allowlist-add-invalid,s,1'));
        $ext->add($id, $c, '', new \ext_set('DB(allowlist/${allownr})', 1));
        $ext->add($id, $c, '', new \ext_playback('num-was-successfully&added'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_hangup());

        $c = '2';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-allowlist-add,s,start'));
        $ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        $id = 'app-allowlist-add-invalid';
        $c = 's';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_playback('pm-invalid-option'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-allowlist-add,s,start'));
        $ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        //Del
        if (!empty($delfc))
        {
            $ext->add('app-allowlist', $delfc, '', new \ext_goto('1', 's', 'app-allowlist-remove'));
        }
        $id = 'app-allowlist-remove';
        $c = 's';
        $ext->add($id, $c, '', new \ext_answer());
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));
        $ext->add($id, $c, '', new \ext_set('NumLoops', 0));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, 'start', new \ext_digittimeout(5));
        $ext->add($id, $c, '', new \ext_responsetimeout(10));
        $ext->add($id, $c, '', new \ext_read('allownr', 'entr-num-rmv-allowlist&vm-then-pound'));
        $ext->add($id, $c, '', new \ext_saydigits('${allownr}'));
        // i18n - Some languages need this is a different format. If we don't
        // know about the language, assume english
        $ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS(' . $id . ',${CHANNEL(language)})}]', $id . ',${CHANNEL(language)},1', $id . ',en,1'));
        // en - default
        $ext->add($id, 'en', '', new \ext_digittimeout(1));
        $ext->add($id, 'en', '', new \ext_read('confirm', 'if-correct-press&digits/1&to-enter-a-diff-number&press&digits/2'));
        $ext->add($id, 'en', '', new \ext_return());
        // ja
        $ext->add($id, 'ja', '', new \ext_digittimeout(1));
        $ext->add($id, 'ja', '', new \ext_read('confirm', 'if-correct-press&digits/1&pleasepress'));
        $ext->add($id, 'ja', '', new \ext_return());

        $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]', 'app-allowlist-remove,1,1'));
        $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "2" ]', 'app-allowlist-remove,2,1'));
        $ext->add($id, $c, '', new \ext_goto('app-allowlist-add-invalid,s,1'));

        $c = '1';
        $ext->add($id, $c, '', new \ext_dbdel('allowlist/${allownr}'));
        $ext->add($id, $c, '', new \ext_playback('num-was-successfully&removed'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_hangup());

        $c = '2';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-allowlist-remove,s,start'));
        $ext->add($id, $c, '', new \ext_playback('goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        $id = 'app-allowlist-remove-invalid';
        $c = 's';
        $ext->add($id, $c, '', new \ext_set('NumLoops', '$[${NumLoops} + 1]'));
        $ext->add($id, $c, '', new \ext_playback('pm-invalid-option'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${NumLoops} < 3]', 'app-allowlist-remove,s,start'));
        $ext->add($id, $c, '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        //Last
        if (!empty($lastfc))
        {
            $ext->add('app-allowlist', $lastfc, '', new \ext_goto('1', 's', 'app-allowlist-last'));
        }
        $id = 'app-allowlist-last';
        $c = 's';
        $ext->add($id, $c, '', new \ext_answer());
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_setvar('lastcaller', '${DB(CALLTRACE/${AMPUSER})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$[ $[ "${lastcaller}" = "" ] | $[ "${lastcaller}" = "unknown" ] ]', 'noinfo'));
        $ext->add($id, $c, '', new \ext_playback('privacy-to-whitelist-last-caller&telephone-number'));
        $ext->add($id, $c, '', new \ext_saydigits('${lastcaller}'));
        $ext->add($id, $c, '', new \ext_setvar('TIMEOUT(digit)', '1'));
        $ext->add($id, $c, '', new \ext_setvar('TIMEOUT(response)', '7'));
        // i18n - Some languages need this is a different format. If we don't
        // know about the language, assume english
        $ext->add($id, $c, '', new \ext_gosubif('$[${DIALPLAN_EXISTS(' . $id . ',${CHANNEL(language)})}]', $id . ',${CHANNEL(language)},1', $id . ',en,1'));
        // en - default
        $ext->add($id, 'en', '', new \ext_read('confirm', 'if-correct-press&digits/1'));
        $ext->add($id, 'en', '', new \ext_return());
        // ja
        $ext->add($id, 'ja', '', new \ext_read('confirm', 'if-correct-press&digits/1&pleasepress'));
        $ext->add($id, 'ja', '', new \ext_return());

        $ext->add($id, $c, '', new \ext_gotoif('$[ "${confirm}" = "1" ]', 'app-allowlist-last,1,1'));
        $ext->add($id, $c, '', new \ext_goto('end'));
        $ext->add($id, $c, 'noinfo', new \ext_playback('unidentified-no-callback'));
        $ext->add($id, $c, '', new \ext_hangup());
        $ext->add($id, $c, '', new \ext_noop('Waiting for input'));
        $ext->add($id, $c, 'end', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, $c, '', new \ext_hangup());

        $c = '1';
        $ext->add($id, $c, '', new \ext_set('DB(allowlist/${lastcaller})', 1));
        $ext->add($id, $c, '', new \ext_playback('num-was-successfully'));
        $ext->add($id, $c, '', new \ext_playback('added'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_hangup());

        $ext->add($id, 'i', '', new \ext_playback('sorry-youre-having-problems&goodbye'));
        $ext->add($id, 'i', '', new \ext_hangup());


        //Toggle
        if (!empty($togglefc))
        {
            $ext->add('app-allowlist', $togglefc, '', new \ext_goto('1', 's', 'app-allowlist-pause-toggle'));
        }		
	$id = 'app-allowlist-pause-toggle';
        $c = 's';
        $ext->add($id, $c, '', new \ext_gosubif('$[${DB_EXISTS(allowlist/pause)}]', 'app-allowlist-pause-disable,s,1:app-allowlist-pause-enable,s,1'));
        $ext->add($id, $c, '', new \ext_answer());
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));
        $ext->add($id, $c, '', new \ext_wait(1));
        $ext->add($id, $c, '', new \ext_gotoif('$[${DB_EXISTS(allowlist/pause)}] ]', 'paused:unpaused'));
        $ext->add($id, $c, 'paused', new \ext_playback('dictate/pause&enabled'));		
        $ext->add($id, $c, '', new \ext_hangup());
        $ext->add($id, $c, 'unpaused', new \ext_playback('dictate/pause&disabled'));		
        $ext->add($id, $c, '', new \ext_hangup());
		
	$id = 'app-allowlist-pause-enable';
        $c = 's';
	$ext->add($id, $c, '', new \ext_noop('Current time ${EPOCH}'));		
	$ext->add($id, $c, '', new \ext_set('DB(allowlist/pause)', '${MATH(${EPOCH}+86400,int)}'));   // set timer 24 hr into future
        $ext->add($id, $c, '', new \ext_return());
		
	$id = 'app-allowlist-pause-disable';
        $c = 's';
        $ext->add($id, $c, '', new \ext_dbdel('allowlist/pause'));		
        $ext->add($id, $c, '', new \ext_return());
		
	$id = 'app-allowlist-pause-check';
        $c = 's';
	$ext->add($id, $c, '', new \ext_noop('Current time ${EPOCH}'));
	$ext->add($id, $c, '', new \ext_noop('Pause timer expire ${DB(allowlist/pause)}'));
        $ext->add($id, $c, '', new \ext_gosubif('$[${DB_EXISTS(allowlist/pause)} && "${DB(allowlist/pause)}"<"${EPOCH}"]', 'app-allowlist-pause-disable,s,1'));
        $ext->add($id, $c, '', new \ext_return());
    }

    public function getActionBar($request)
    {
        $buttons = array();
        switch ($request['display'])
        {
            case 'allowlist':
                $buttons = array(
                    'reset' => array(
                        'name' => 'reset',
                        'id' => 'Reset',
                        'class' => 'hidden',
                        'value' => _('Reset') ,
                    ) ,
                    'submit' => array(
                        'name' => 'submit',
                        'class' => 'hidden',
                        'id' => 'Submit',
                        'value' => _('Submit') ,
                    ) ,
                );

                return $buttons;
        }
    }

    //Allowlist Methods
    public function showPage()
    {
        $allowlistitems = $this->getAllowlist();
        $destination = $this->destinationGet();
        $filter_knowncallers = $this->allowknowncallersGet() == 1;
        $pause = $this->pauseGet() != 0;
        $view = isset($_REQUEST['view']) ? $_REQUEST['view'] : '';
        switch ($view)
        {
            case 'grid':
                return load_view(__DIR__ . '/views/algrid.php', array(
                    'allowlist' => $allowlistitems
                ));
            default:
                return load_view(__DIR__ . '/views/general.php', array(
                    'allowlist' => $allowlistitems,
                    'destination' => $destination,
                    'filter_knowncallers' => $filter_knowncallers,
                    'pause' => $pause
                ));
        }
    }

    /**
     * Get lists
     * @return array Allow listed numbers
     */
    public function getAllowlist()
    {
        if ($this->astman->connected())
        {
            $list = $this->astman->database_show('allowlist');
            $allowlisted = array();
            foreach ($list as $k => $v)
            {
                if ($k == '/allowlist/dest' || $k == '/allowlist/pause' || $k == '/allowlist/knowncallers' || substr($k, 0, 18) == '/allowlist/autoadd' || substr($k, 0, 14) == '/allowlist/did')
                {
                    continue;
                }
                $numbers = substr($k, 11);
                $allowlisted[] = array(
                    'number' => $numbers,
                    'description' => $v
                );
            }
            return $allowlisted;
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Add Number
     * @param  array $post Array of allowlist params
     */
    public function numberAdd($post)
    {
        if ($this->astman->connected())
        {
            $post['description'] == '' ? $post['description'] = '1' : $post['description'];
            // $this->astman->database_put('allowlist', $post['number'], $post['description']);
            $this->astman->database_put('allowlist', $post['number'], htmlentities($post['description'], ENT_COMPAT | ENT_HTML401, "UTF-8"));
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
        return $post['number'];
    }

    /**
     * Delete a number
     * @param  string $number Number to delete
     * @return boolean         Status of deletion
     */
    public function numberDel($number)
    {
        if ($this->astman->connected())
        {
            return ($this->astman->database_del('allowlist', $number));
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Block a number - move the number to the blacklist database
     * @param  array $post Array of allowlist/blacklist params
     * @return boolean         Status of block
     */
    public function numberBlock($post)
    {
        if ($this->astman->connected())
        {
           // $this->FreePBX->Blacklist->numberAdd($post);
            $this->FreePBX->Blacklist->numberAdd($post);
            return ($this->numberDel($post['number']));
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Set allowlist destination
     * @param  string $dest Destination
     * @return boolean       Status of set
     */
    public function destinationSet($dest)
    {
        if ($this->astman->connected())
        {
            $this->astman->database_del('allowlist', 'dest');
            if (!empty($dest))
            {
                return $this->astman->database_put('allowlist', 'dest', $dest);
            }
            else
            {
                return true;
            }
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Get the destination
     * @return string The destination
     */
    public function destinationGet()
    {
        if ($this->astman->connected())
        {
            return $this->astman->database_get('allowlist', 'dest');
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }


    /**
     * Whether to pause allowlist checking globally
     * @param  boolean $pause True to pause, false otherwise
     */
    public function pauseSet($pause)
    {
        if ($this->astman->connected())
        {
            // Remove filtering for pauseed/unknown cid
            $this->astman->database_del('allowlist', 'pause');
            // Add it back if it's checked
            if (!empty($pause))
            {
                $secs = strtotime(date('Y-m-d H:i:s')) + 86400;
                $this->astman->database_put('allowlist', 'pause', $secs);
            }
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Get status of pause flag
     * @return string 1 if paused, 0 otherwise
     */
    public function pauseGet()
    {
        if ($this->astman->connected())
        {
            return $this->astman->database_get('allowlist', 'pause') != 0;
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }


    /**
     * Whether to allow contact manager callers
     * @param  boolean $knowncallers True to allow, false otherwise
     */
    public function allowknowncallersSet($knowncallers)
    {
        if ($this->astman->connected())
        {
            // Remove filtering for knowncallers cid
            $this->astman->database_del('allowlist', 'knowncallers');
            // Add it back if it's checked
            if (!empty($knowncallers))
            {
                $this->astman->database_put('allowlist', 'knowncallers', '1');
            }
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    /**
     * Get status of knowncallers allowed
     * @return string 1 if knowncallers allowed, 0 otherwise
     */
    public function allowknowncallersGet()
    {
        if ($this->astman->connected())
        {
            return $this->astman->database_get('allowlist', 'knowncallers');
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    //BulkHandler hooks
    public function bulkhandlerGetTypes()
    {
        return array(
            'allowlist' => array(
                'name' => _('Allowlist') ,
                'description' => _('Import/Export Caller Allowlist')
            )
        );
    }
    public function bulkhandlerGetHeaders($type)
    {
        switch ($type)
        {
            case 'allowlist':
                $headers = array();
                $headers['number'] = array(
                    'required' => true,
                    'identifier' => _("Phone Number") ,
                    'description' => _("The number as it appears in the callerid display")
                );
                $headers['description'] = array(
                    'required' => false,
                    'identifier' => _("Description") ,
                    'description' => _("Description of number allowlisted")
                );
            break;
        }
        return $headers;
    }
    public function bulkhandlerImport($type, $rawData, $replaceExisting = true)
    {
        $blistnums = array();
        if (!$replaceExisting)
        {
            $blist = $this->getAllowlist();
            foreach ($blist as $value)
            {
                $blistnums[] = $value['number'];
            }
        }
        switch ($type)
        {
            case 'allowlist':
                foreach ($rawData as $data)
                {
                    if (empty($data['number']))
                    {
                        return array(
                            'status' => false,
                            'message' => _('Phone Number Required')
                        );
                    }
                    //Skip existing numbers. Array is only populated if replace is false.
                    if (in_array($data['number'], $blistnums))
                    {
                        continue;
                    }
                    $this->numberAdd($data);
               }
            break;
        }
        return array(
            'status' => true
        );
    }
    public function bulkhandlerExport($type)
    {
        $data = NULL;
        switch ($type)
        {
            case 'allowlist':
                $data = $this->getAllowlist();
            break;
        }
        return $data;
    }

    public function didAdd($did, $cid)
    {
        if ($this->astman->connected())
        {
            // Add in did/cid pair
            $exten = $did . ($cid == "" ? "" : '/' . $cid);
            $this->astman->database_put('allowlist', 'did/' . $exten, true);
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    public function didDelete($did, $cid)
    {
        if ($this->astman->connected())
        {
            // Remove did/cid pair
            $exten = $did . ($cid == "" ? "" : '/' . $cid);
            $this->astman->database_del('allowlist', 'did/' . $exten);
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    public function didIsSet($did, $cid)
    {
        if ($this->astman->connected())
        {
            $exten = $did . ($cid == "" ? "" : '/' . $cid);
            $var = $this->astman->database_get('allowlist', 'did/' . $exten);
            if ($var)
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    public function routeAdd($id)
    {
        if ($this->astman->connected())
        {
            $this->astman->database_put('allowlist', 'autoadd/' . $id, true);
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    public function routeDelete($id)
    {
        if ($this->astman->connected())
        {
            $this->astman->database_del('allowlist', 'autoadd/' . $id);
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

    public function routeIsSet($id)
    {
        if ($this->astman->connected())
        {
            $var = $this->astman->database_get('allowlist', 'autoadd/' . $id);
            if ($var)
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            throw new RuntimeException('Cannot connect to Asterisk Manager, is Asterisk running?');
        }
    }

}

