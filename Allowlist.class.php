<?php
namespace FreePBX\modules;
// vim: set ai ts=4 sw=4 ft=php expandtab:
//	License for all code of this FreePBX module can be found in the license file inside the module directory
//      Copyright 2021 Magnolia Manor Networks

use BMO;
use RuntimeException;

#[\AllowDynamicProperties]
class Allowlist implements BMO
{
    /** Settings keys that share the allowlist AstDB family (never valid numbers). */
    const RESERVED_KEYS = array('dest', 'pause', 'knowncallers', 'pausepin', 'debuglog');
    /** L2: most numbers one bulk delete may name. */
    const BULK_MAX = 1000;
    /** L8: shortest stored-number length the auto-add route may keep. */
    const AUTOADD_MIN_STOCOUNT = 7;
    /** Commands that change state: a valid CSRF token is required (M3). */
    const CSRF_COMMANDS = array('add', 'edit', 'del', 'block', 'pause', 'bulkdelete');
    /** Longest pause any path may set (seconds). */
    const PAUSE_MAX_SECS = 86400;
    const RESERVED_PREFIXES = array('did/', 'autoadd/');
    /** Digits only, optional leading +, 1-20 digits (ADR-AL-1). Mirrored in agi-bin/allowlist-common.php. */
    const NUMBER_PATTERN = '/^\+?[0-9]{1,20}$/D';
    /** Dialplan form of NUMBER_PATTERN; [+] keeps backslashes out of extensions_additional.conf. */
    const DIALPLAN_NUMBER_REGEX = '^[+]?[0-9]{1,20}$';
    const DESCRIPTION_MAX = 255;

    private $lastError = '';

    /**
     * Validate an allowlist number.
     * @param  mixed $n Candidate number
     * @return string|false Trimmed number, or false if it is not digits-only or is a reserved key
     */
    public static function normalizeNumber($n)
    {
        if (!is_string($n))
        {
            return false;
        }
        $n = trim($n);
        if (!preg_match(self::NUMBER_PATTERN, $n))
        {
            return false;
        }
        // Second check, even though digits-only already excludes these.
        if (in_array($n, self::RESERVED_KEYS, true))
        {
            return false;
        }
        foreach (self::RESERVED_PREFIXES as $prefix)
        {
            if (strpos($n, $prefix) === 0)
            {
                return false;
            }
        }
        return $n;
    }

    /**
     * Validate a description (before htmlentities).
     * @param  mixed $d Candidate description (null means empty)
     * @return string|false The description, or false if too long, not UTF-8 or containing control characters
     */
    public static function normalizeDescription($d)
    {
        if ($d === null)
        {
            return '';
        }
        if (!is_string($d) || !preg_match('//u', $d))
        {
            return false;
        }
        $len = function_exists('mb_strlen') ? mb_strlen($d, 'UTF-8') : preg_match_all('/./us', $d);
        if ($len > self::DESCRIPTION_MAX || preg_match('/[\x00-\x1F\x7F]/', $d))
        {
            return false;
        }
        return $d;
    }

    /**
     * Strict lookup of an entry in a getAllowlist()-style list.
     * @param  array $list  Rows with 'number' and 'description'
     * @param  mixed $number Number to find (compared as a string, strictly)
     * @return array|null The row, or null if absent
     */
    public static function findInList($list, $number)
    {
        if (!is_scalar($number))
        {
            return null;
        }
        $idx = array_search((string)$number, array_column($list, 'number'), true);
        return $idx === false ? null : $list[$idx];
    }

    /**
     * Find an allowlist entry by number (strict match).
     * @return array|null
     */
    public function findNumber($number)
    {
        return self::findInList($this->getAllowlist(), $number);
    }

    /** Message for the last rejected numberAdd/numberDel/numberBlock/destinationSet call. */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Whether a stored key may be deleted as an allowlist number: it must be in the
     * number list (never a settings key) and hold no '/', whitespace, quote, backslash
     * or control character. Legacy non-numeric entries stay deletable.
     */
    public function isDeletableNumber($number, $list = null)
    {
        if (!is_string($number) || $number === '' || preg_match('#[/\s"\'\\\\\x00-\x1F\x7F]#', $number))
        {
            return false;
        }
        return self::findInList(is_array($list) ? $list : $this->getAllowlist(), $number) !== null;
    }

    /**
     * Whether a destination may be stored: empty (clear) or registered with FreePBX.
     */
    public function isValidDestination($dest)
    {
        if ($dest === null || $dest === '')
        {
            return true;
        }
        if (!is_string($dest) || preg_match('/[\r\n\x00]/', $dest))
        {
            return false;
        }
        $all = $this->FreePBX->Destinations->getAllDestinations();
        return is_array($all) && isset($all[$dest]);
    }

    /** @var string[] audit messages written during this request (for tests) */
    public $auditEntries = array();

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

    /**
     * M3: per-session anti-CSRF token for this module's state-changing requests.
     * Defence in depth: it does not rely on whether the framework checks one.
     */
    public function csrfToken()
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent())
        {
            @session_start();
        }
        if (!isset($_SESSION) || !is_array($_SESSION))
        {
            $_SESSION = array();
        }
        if (empty($_SESSION['allowlist_csrf']) || !is_string($_SESSION['allowlist_csrf']))
        {
            $_SESSION['allowlist_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['allowlist_csrf'];
    }

    public function csrfValid($token)
    {
        $expected = isset($_SESSION['allowlist_csrf']) ? $_SESSION['allowlist_csrf'] : '';
        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public function ajaxHandler()
    {
        $request = $_REQUEST;
        $command = isset($_REQUEST['command']) ? $_REQUEST['command'] : '';
        if ($command === 'add' && !empty($_REQUEST['oldval']))
        {
            $command = 'edit';
        }
        if (in_array($command, self::CSRF_COMMANDS, true) && !$this->csrfValid(isset($_REQUEST['token']) ? $_REQUEST['token'] : null))
        {
            return array('status' => false, 'message' => _('Your session has expired or the request was not sent from this page; reload and try again.'));
        }
        if (!empty($_REQUEST['oldval']) && $_REQUEST['command'] == 'add')
        {
            $_REQUEST['command'] = 'edit';
        }
        switch ($_REQUEST['command'])
        {
            case 'add':
                if (!$this->numberAdd($request))
                {
                    return array('status' => false, 'message' => $this->lastError);
                }
                return array(
                    'status' => true
                );
            break;
            case 'edit':
                // Validate the new entry before touching the old one.
                if (!$this->checkEntry($request))
                {
                    return array('status' => false, 'message' => $this->lastError);
                }
                $this->numberDel(isset($request['oldval']) ? $request['oldval'] : null);
                if (!$this->numberAdd($request))
                {
                    return array('status' => false, 'message' => $this->lastError);
                }
                return array(
                    'status' => true
                );
            break;
            case 'bulkdelete':
                $numbers = isset($_REQUEST['numbers']) ? $_REQUEST['numbers'] : '[]';
                $numbers = is_string($numbers) ? json_decode($numbers, true) : null;
                if (!is_array($numbers) || count($numbers) > self::BULK_MAX)
                {
                    return array('status' => false, 'message' => _('Invalid request'));
                }
                $rejected = array();
                $deleted = 0;
                $list = $this->getAllowlist(); // L2: one AMI listing per request
                foreach ($numbers as $number)
                {
                    if ($this->numberDel($number, $list))
                    {
                        $deleted++;
                    }
                    else
                    {
                        $rejected[] = is_scalar($number) ? (string)$number : '?';
                    }
                }
                if (!empty($rejected))
                {
                    // Partial success still reports status true so the grid refreshes; rejected entries are listed.
                    return array(
                        'status' => $deleted > 0,
                        'deleted' => $deleted,
                        'rejected' => $rejected,
                        'message' => sprintf(_('Not deleted (not allowlist numbers): %s'), implode(', ', $rejected))
                    );
                }
                return array(
                    'status' => 'true',
                    'message' => _("Numbers Deleted")
                );
            break;
            case 'del':
                $ret = $this->numberDel(isset($request['number']) ? $request['number'] : null);
                return $ret ? array('status' => $ret) : array('status' => false, 'message' => $this->lastError);
            break;
            case 'block':
                $ret = $this->numberBlock($request);
                return $ret ? array('status' => $ret) : array('status' => false, 'message' => $this->lastError);
            break;
            case 'pause':
                $ret = $this->pauseSet(isset($request['pause']) ? $request['pause'] : false, 'admin web UI');
                return array(
                    'status' => $ret
                );
            break;
            case 'calllog':
                $mod_cdr = $this->FreePBX->Cdr;
				$number = $request['number'];
				$sql = sprintf('SELECT DISTINCT calldate FROM %s WHERE src = ?', $mod_cdr->getDbTable());
				$stmt = $mod_cdr->getCdrDbHandle()->prepare($sql);
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
                            $array_continue = array(
                                'dest',
                                'pause',
                                'knowncallers'
                            );
                            if ( in_array($number, $array_continue) || substr($number, 0, 7) == 'autoadd' || substr($number, 0, 3) == 'did')
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
        $fcc->setDescription(_('Add a number to the allowlist'));
        $fcc->setHelpText(_('Adds a number to the Allowlist Module. All calls from that number to the system will will be allowed to proceed normally. Manage these in the Allowlist module.'));
        $fcc->setDefault('*38');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_remove');
        $fcc->setDescription(_('Remove a number from the allowlist'));
        $fcc->setHelpText(_('Removes a number from the Allowlist Module'));
        $fcc->setDefault('*39');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_last');
        $fcc->setDescription(_('Add the last caller to the allowlist'));
        $fcc->setHelpText(_('Adds the last caller to the Allowlist Module. All calls from that number to the system will be allowed to proceed normally.'));
        $fcc->setDefault('*40');
        $fcc->setProvideDest(true);
        $fcc->update();
        unset($fcc);

        $fcc = new \featurecode('allowlist', 'allowlist_pause_toggle');
        $fcc->setDescription(_('Pause or unpause Allowlist checking'));
        $fcc->setHelpText(_('Temporarily pause or unpause the Allowlist module operation system wide'));
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

        if (isset($request['action']) && in_array($action, array('settings', 'import'), true)
            && !$this->csrfValid(isset($request['token']) ? $request['token'] : null))
        {
            echo '<div class="alert alert-danger" role="alert">' . _('Your session has expired or the request was not sent from this page; nothing was changed.') . '</div>';
            return;
        }
        if (isset($request['action']))
        {
            switch ($action)
            {
                case 'settings':
                    if (!$this->destinationSet(isset($destination) ? $destination : ''))
                    {
                        echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($this->lastError, ENT_QUOTES, 'UTF-8') . '</div>';
                    }
                    $this->pauseSet(isset($request['pause']) ? $request['pause'] : false, 'admin settings page');
                    $this->allowknowncallersSet($request['knowncallers']);
                    if (!empty($request['pausepin_clear']))
                    {
                        $this->pausePinSet('');
                    }
                    elseif (isset($request['pausepin']) && $request['pausepin'] !== '')
                    {
                        if (!$this->pausePinSet($request['pausepin']))
                        {
                            echo '<div class="alert alert-danger" role="alert">' . htmlspecialchars($this->lastError, ENT_QUOTES, 'UTF-8') . '</div>';
                        }
                    }
                break;
                case 'import':
                    // L4: correct upload key, private temp file, honest result.
                    $up = isset($_FILES['allowlistfile']) && is_array($_FILES['allowlistfile']) ? $_FILES['allowlistfile'] : null;
                    $msg = $this->importUploadedCsv($up);
                    echo '<div class="alert alert-' . ($msg['ok'] ? 'success' : 'danger') . '" role="alert">' . htmlspecialchars($msg['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
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
                            fputcsv($output, array_map(array(__CLASS__, 'csvSafeCell'), $l));
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

        // A1: only digit caller IDs (optional leading +) are looked up in the shared family.
        $ext->add($id, $c, '', new \ext_set('ALCIDOK', '${REGEX("' . self::DIALPLAN_NUMBER_REGEX . '" ${CALLERID(num)})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${ALCIDOK}"!="1"]', 'check-contacts'));
        $ext->add($id, $c, 'check-list', new \ext_gotoif('$["${DB_EXISTS(allowlist/${CALLERID(num)})}"="0"]', 'check-contacts'));
        $ext->add($id, $c, '', new \ext_setvar('CALLED_ALLOWLIST', '1'));
        $ext->add($id, $c, '', new \ext_return(''));

        $ext->add($id, $c, 'check-contacts', new \ext_gotoif('$["${DB_EXISTS(allowlist/knowncallers)}" = "0"]', 'nonallowlisted'));
        $ext->add($id, $c, '', new \ext_agi('allowlist-check.agi,"allowlisted"'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${allowlisted}"="false"]', 'nonallowlisted'));
        $ext->add($id, $c, '', new \ext_setvar('CALLED_ALLOWLIST', '1'));
        $ext->add($id, $c, '', new \ext_return(''));

        $ext->add($id, $c, 'nonallowlisted', new \ext_noop('Caller does not apper on allowlists'));      
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
        $ext->add($id, $c, '', new \ext_set('ALNROK', '${REGEX("' . self::DIALPLAN_NUMBER_REGEX . '" ${allownr})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${ALNROK}"!="1"]', 'app-allowlist-add-invalid,s,1'));
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
        // L7: same digits-only guard as the add path before touching AstDB.
        $ext->add($id, $c, '', new \ext_set('ALNROK', '${REGEX("' . self::DIALPLAN_NUMBER_REGEX . '" ${allownr})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${ALNROK}"!="1"]', 'app-allowlist-add-invalid,s,1'));
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
        $ext->add($id, $c, '', new \ext_set('ALCIDOK', '${REGEX("' . self::DIALPLAN_NUMBER_REGEX . '" ${lastcaller})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${ALCIDOK}"!="1"]', 'noinfo'));
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
        $ext->add($id, $c, '', new \ext_set('ALCIDOK', '${REGEX("' . self::DIALPLAN_NUMBER_REGEX . '" ${lastcaller})}'));
        $ext->add($id, $c, '', new \ext_gotoif('$["${ALCIDOK}"!="1"]', 's,noinfo'));
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
        $ext->add($id, $c, '', new \ext_answer());
        $ext->add($id, $c, '', new \ext_macro('user-callerid'));
        $ext->add($id, $c, '', new \ext_wait(1));
        // M2: optional PIN. Authenticate hangs up after 3 bad tries, so the toggle never runs.
        $ext->add($id, $c, '', new \ext_gotoif('$[${DB_EXISTS(allowlist/pausepin)}]', 'pin:toggle'));
        $ext->add($id, $c, 'pin', new \ext_authenticate('${DB(allowlist/pausepin)}'));
        $ext->add($id, $c, 'toggle', new \ext_gosubif('$[${DB_EXISTS(allowlist/pause)}]', 'app-allowlist-pause-disable,s,1:app-allowlist-pause-enable,s,1'));
        // M2: audit who toggled (internal extension only; never the PIN).
        $ext->add($id, $c, '', new \ext_log('NOTICE', 'Allowlist pause toggled by extension ${AMPUSER} now ${IF($[${DB_EXISTS(allowlist/pause)}]?paused:unpaused)}'));
        $ext->add($id, $c, '', new \ext_gotoif('$[${DB_EXISTS(allowlist/pause)}]', 'paused:unpaused'));
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
            break;
        }
        return $buttons;
    }

    /** L6: neutralise spreadsheet formulas in exported cells. */
    public static function csvSafeCell($v)
    {
        $s = is_scalar($v) ? (string)$v : '';
        return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
    }

    /**
     * L4: import a legacy CSV upload. Uses the 'allowlistfile' key, a private
     * tempnam() file, and reports rejected rows instead of claiming success.
     * @param array|null $up one $_FILES entry
     * @return array ['ok' => bool, 'message' => string]
     */
    public function importUploadedCsv($up, $moveFn = null)
    {
        if (!is_array($up) || !isset($up['error'], $up['name'], $up['tmp_name']) || $up['error'] !== UPLOAD_ERR_OK)
        {
            return array('ok' => false, 'message' => _('There was an error uploading the file'));
        }
        if (strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)) !== 'csv')
        {
            return array('ok' => false, 'message' => _('The file must be in CSV format!'));
        }
        $path = tempnam(sys_get_temp_dir(), 'allowlist-import-');
        $moveFn = $moveFn ?: 'move_uploaded_file';
        if ($path === false || !$moveFn($up['tmp_name'], $path))
        {
            if ($path !== false) { @unlink($path); }
            return array('ok' => false, 'message' => _('Could not find file after upload'));
        }
        $added = 0;
        $rejected = 0;
        $handle = fopen($path, 'r');
        while ($handle && ($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false)
        {
            if (isset($data[0], $data[1]) && $data[0] === 'number' && $data[1] === 'description')
            {
                continue;
            }
            $ok = $this->numberAdd(array('number' => isset($data[0]) ? $data[0] : null, 'description' => isset($data[1]) ? $data[1] : null));
            $ok ? $added++ : $rejected++;
        }
        if ($handle) { fclose($handle); }
        @unlink($path);
        if ($rejected > 0)
        {
            return array('ok' => false, 'message' => sprintf(_('Imported %d entries; %d rows were rejected (invalid number or description).'), $added, $rejected));
        }
        return array('ok' => true, 'message' => sprintf(_('Imported %d entries.'), $added));
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
                    'pause' => $pause,
                    'pausepin_set' => $this->pausePinIsSet(),
                    'csrf_token' => $this->csrfToken()
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
                $array_continue = array(
                    '/allowlist/dest',
                    '/allowlist/pause',
                    '/allowlist/knowncallers',
                    '/allowlist/pausepin',
                    '/allowlist/debuglog'
                );
                if (in_array($k, $array_continue) || substr($k, 0, 18) == '/allowlist/autoadd' || substr($k, 0, 14) == '/allowlist/did')
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    /**
     * Add Number
     * @param  array $post Array of allowlist params
     */
    public function numberAdd($post)
    {
        $entry = $this->checkEntry($post);
        if (!$entry)
        {
            return false;
        }
        if ($this->astman->connected())
        {
            $description = $entry['description'] === '' ? '1' : $entry['description'];
            $this->astman->database_put('allowlist', $entry['number'], htmlentities($description, ENT_COMPAT | ENT_HTML401, "UTF-8"));
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
        return true;
    }

    /**
     * Validate a number/description pair without storing it.
     * @param  array $post Array with 'number' and optional 'description'
     * @return array|false Normalized ['number', 'description'], or false (see getLastError())
     */
    public function checkEntry($post)
    {
        $this->lastError = '';
        $number = self::normalizeNumber(is_array($post) && isset($post['number']) ? $post['number'] : null);
        if ($number === false)
        {
            $this->lastError = _('Invalid number: use digits only, with an optional leading +, up to 20 digits.');
            return false;
        }
        $description = self::normalizeDescription(is_array($post) && isset($post['description']) ? $post['description'] : null);
        if ($description === false)
        {
            $this->lastError = sprintf(_('Invalid description: at most %d characters and no control characters.'), self::DESCRIPTION_MAX);
            return false;
        }
        return array('number' => $number, 'description' => $description);
    }

    /**
     * Delete a number
     * @param  string $number Number to delete
     * @return boolean         Status of deletion
     */
    public function numberDel($number, $list = null)
    {
        $this->lastError = '';
        if ($this->astman->connected())
        {
            if (!$this->isDeletableNumber($number, $list))
            {
                $this->lastError = _('Not an allowlist number.');
                return false;
            }
            return ($this->astman->database_del('allowlist', $number));
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    /**
     * Block a number - move the number to the blacklist database
     * @param  array $post Array of allowlist/blacklist params
     * @return boolean         Status of block
     */
    public function numberBlock($post)
    {
        $this->lastError = '';
        if ($this->astman->connected())
        {
            $number = is_array($post) && isset($post['number']) ? $post['number'] : null;
            if (!$this->isDeletableNumber($number))
            {
                $this->lastError = _('Not an allowlist number.');
                return false;
            }
            // L5: hand the Blacklist module only the number and a normalized description.
            $desc = self::normalizeDescription(isset($post['description']) ? $post['description'] : null);
            if ($desc === false)
            {
                $this->lastError = _('Description must be at most 255 characters with no control characters.');
                return false;
            }
            $this->FreePBX->Blacklist->numberAdd(array('number' => $number, 'description' => $desc));
            return ($this->numberDel($number));
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    /**
     * Set allowlist destination
     * @param  string $dest Destination
     * @return boolean       Status of set
     */
    public function destinationSet($dest)
    {
        $this->lastError = '';
        if ($this->astman->connected())
        {
            // Validate before deleting, so a rejected value keeps the stored destination.
            if (!$this->isValidDestination($dest))
            {
                $this->lastError = _('Invalid destination for non-allowlisted calls; the previous destination was kept.');
                return false;
            }
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }


    /**
     * Interpret a pause request strictly: only true-like values pause.
     * '0', '', 'false', 'no', 'off', null and arrays do not.
     */
    public static function pauseRequested($pause)
    {
        if (is_bool($pause))
        {
            return $pause;
        }
        if (!is_scalar($pause))
        {
            return false;
        }
        return filter_var((string)$pause, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Whether to pause allowlist checking globally (for at most PAUSE_MAX_SECS).
     * @param  boolean $pause True to pause, false otherwise
     * @param  string  $source Who asked (audit log); never a number or PIN
     * @return boolean True on success
     */
    public function pauseSet($pause, $source = 'unknown')
    {
        if ($this->astman->connected())
        {
            $on = self::pauseRequested($pause);
            $was = $this->astman->database_get('allowlist', 'pause');
            // Remove filtering for pauseed/unknown cid
            $this->astman->database_del('allowlist', 'pause');
            // Add it back if it's checked
            if ($on)
            {
                $secs = time() + self::PAUSE_MAX_SECS;
                $this->astman->database_put('allowlist', 'pause', $secs);
            }
            if ($on || ($was !== false && $was !== '' && $was !== '0'))
            {
                $this->auditLog(sprintf('Allowlist screening %s by %s', $on ? 'paused for 24 hours' : 'unpaused', $source));
            }
            return true;
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    /**
     * Set (digits, 4-12) or clear ('') the optional PIN required by the pause feature code.
     * The PIN is never logged or returned by any API.
     */
    public function pausePinSet($pin)
    {
        $this->lastError = '';
        if (!$this->astman->connected())
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
        if ($pin === '' || $pin === null)
        {
            $this->astman->database_del('allowlist', 'pausepin');
            $this->auditLog('Allowlist pause PIN cleared');
            return true;
        }
        if (!is_string($pin) || !preg_match('/^[0-9]{4,12}$/', $pin))
        {
            $this->lastError = _('Pause PIN must be 4 to 12 digits; the previous PIN was kept.');
            return false;
        }
        $this->astman->database_put('allowlist', 'pausepin', $pin);
        $this->auditLog('Allowlist pause PIN changed');
        return true;
    }

    /** True when a pause PIN is configured (the PIN itself is never exposed). */
    public function pausePinIsSet()
    {
        $v = $this->astman->database_get('allowlist', 'pausepin');
        return is_string($v) && $v !== '';
    }

    /** Audit trail for security-relevant changes. Messages never contain numbers or PINs. */
    public function auditLog($msg)
    {
        $this->auditEntries[] = $msg;
        if (function_exists('freepbx_log') && defined('FPBX_LOG_NOTICE'))
        {
            freepbx_log(FPBX_LOG_NOTICE, $msg);
        }
        else
        {
            error_log($msg);
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
        $headers = array();
        switch ($type)
        {
            case 'allowlist':
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
                $rejected = array();
                // The bulkhandler UI posts one row per request and prints its own row number,
                // so only multi-row calls (direct import, CLI) label rows here.
                $multi = count($rawData) > 1;
                $rowNo = 0;
                foreach ($rawData as $data)
                {
                    $rowNo++;
                    $where = $multi ? sprintf(_('row %d'), $rowNo) . ' ' : '';
                    $number = is_array($data) && isset($data['number']) ? $data['number'] : '';
                    if ($number === '' || $number === null)
                    {
                        $rejected[] = $where . _('Phone Number Required');
                        continue;
                    }
                    //Skip existing numbers. Array is only populated if replace is false.
                    if (in_array($number, $blistnums, true))
                    {
                        continue;
                    }
                    if (!$this->numberAdd($data))
                    {
                        // The message is inserted with innerHTML by the bulkhandler UI: escape the echoed number.
                        $shown = is_scalar($number) ? preg_replace('/[\x00-\x1F\x7F]/', '?', (string)$number) : '?';
                        $shown = htmlspecialchars(mb_substr($shown, 0, 40, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $rejected[] = sprintf('%s(%s): %s', $where, $shown, htmlspecialchars($this->lastError, ENT_QUOTES, 'UTF-8'));
                    }
               }
               if (!empty($rejected))
               {
                   return array(
                       'status' => false,
                       'rejected' => $rejected,
                       'message' => sprintf(_('%d row(s) were not imported: %s'), count($rejected), implode('; ', array_slice($rejected, 0, 20)) . (count($rejected) > 20 ? ' ...' : ''))
                   );
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

    /**
     * L8: DID and CID values allowed in did/ keys. Either may be empty (FreePBX's
     * any-DID / any-CID route) or use the inbound-route pattern charset (digits, +,
     * and the X N Z . _ [ ] - pattern characters); never '/', quotes, spaces or
     * control characters.
     */
    public static function validDidCid($did, $cid)
    {
        $ok = function ($v) {
            return $v === null || $v === '' || (is_scalar($v) && preg_match('/^[0-9+XNZxnz._\[\]-]{1,40}$/D', (string)$v) === 1);
        };
        return $ok($did) && $ok($cid);
    }

    public function didAdd($did, $cid)
    {
        $this->lastError = '';
        if (!self::validDidCid($did, $cid))
        {
            $this->lastError = _('DID and caller ID may only contain digits, + and dial-pattern characters.');
            $this->auditLog('Allowlist: rejected an inbound-route allowlist setting with an invalid DID/CID');
            return false;
        }
        if ($this->astman->connected())
        {
            // Add in did/cid pair
            $exten = $did . ($cid == "" ? "" : '/' . $cid);
            $this->astman->database_put('allowlist', 'did/' . $exten, true);
            return true;
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    public function routeAdd($id,$ld,$sd)
    {
        $this->lastError = '';
        $int = function ($v) { return is_int($v) || (is_string($v) && preg_match('/^[0-9]{1,6}$/D', $v)); };
        if (!$int($id) || !$int($ld) || !$int($sd) || (int)$sd < self::AUTOADD_MIN_STOCOUNT)
        {
            $this->lastError = sprintf(_('Route id and digit counts must be whole numbers; digits to keep must be at least %d.'), self::AUTOADD_MIN_STOCOUNT);
            $this->auditLog('Allowlist: rejected an outbound-route auto-add setting (invalid counts)');
            return false;
        }
        if ($this->astman->connected())
        {
            $this->astman->database_put('allowlist', 'autoadd/' . (int)$id, '1,' . (int)$ld . ',' . (int)$sd);
            return true;
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
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
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    public function routeRemCount($id)
    {
        if ($this->astman->connected())
        {
            $var = $this->astman->database_get('allowlist', 'autoadd/' . $id);
            if (($var) && (str_contains($var, ",")) && (count(explode(",",$var)) > 1))
            {
                return explode(",",$var)[1];
            }
            else
            {
                return null;
            }
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

    public function routeStoCount($id)
    {
        if ($this->astman->connected())
        {
            $var = $this->astman->database_get('allowlist', 'autoadd/' . $id);
            if (($var) && (str_contains($var, ",")) && (count(explode(",",$var)) > 2))
            {
                return explode(",",$var)[2];
            }
            else
            {
                return null;
            }
        }
        else
        {
            throw new RuntimeException(_('Cannot connect to Asterisk Manager, is Asterisk running?'));
        }
    }

}

