<?php
// Minimal stand-ins so module classes load without a FreePBX install. No network, no AstDB.
namespace {
	interface BMO {}
	if (!function_exists('_')) {
		function _($s) { return $s; }
	}
	class ext_gosub {
		public $args;
		private $pri;
		private $ext;
		private $context;
		private $gosubArgs;
		public function __construct($pri, $ext = false, $context = false, $args = '') {
			$this->args = func_get_args();
			$this->pri = $pri;
			$this->ext = $ext;
			$this->context = $context;
			$this->gosubArgs = $args;
		}
		public function output() {
			return 'Gosub(' . ($this->context ? $this->context . ',' : '') . ($this->ext ? $this->ext . ',' : '') . $this->pri . '(' . $this->gosubArgs . '))';
		}
	}
	spl_autoload_register(function ($class) {
		if ($class === 'ext_gosub') {
			return;
		}
		if (strpos($class, 'ext_') === 0 && preg_match('/^ext_[a-z_]+$/', $class)) {
			eval('class ' . $class . ' { public $args; public function __construct(...$a) { $this->args = $a; } }');
		}
	});
	class featurecode {
		private $f;
		public function __construct($module, $feature) { $this->f = $feature; }
		public function getCodeActive() { return array('allowlist_add' => '*38', 'allowlist_remove' => '*39', 'allowlist_last' => '*40', 'allowlist_pause_toggle' => '*41')[$this->f]; }
		public function setDescription($x) {}
		public function setHelpText($x) {}
		public function setDefault($x) {}
		public function setProvideDest($x) {}
		public function update() {}
	}
	class FakeExt {
		public $lines = array();
		public $splices = array();
		private static function recordDialplan($obj) {
			$rendered = ($obj instanceof ext_gosub) ? $obj->output() : null;
			return array('app' => get_class($obj), 'args' => $obj->args, 'rendered' => $rendered);
		}
		public function add($ctx, $exten, $label, $obj) {
			$row = self::recordDialplan($obj);
			$this->lines[] = array('ctx' => $ctx, 'exten' => $exten, 'label' => $label) + $row;
		}
		public function addInclude($a, $b) {}
		public function splice($ctx, $exten, $pos, $obj, $label = '', $prio = 1) {
			$row = self::recordDialplan($obj);
			$this->splices[] = array('ctx' => $ctx, 'exten' => $exten, 'pos' => $pos, 'label' => $label, 'prio' => $prio) + $row;
		}
	}
	class FakeAstman {
		public $db = array();
		public $failPut = false;
		public $failDel = false;
		public $failPutAfter = -1; // >= 0: allow that many more puts, then fail
		public $connectedState = true;
		public function connected() { return $this->connectedState; }
		public function database_put($fam, $key, $val) { if ($this->failPut) { return false; } if ($this->failPutAfter === 0) { return false; } if ($this->failPutAfter > 0) { $this->failPutAfter--; } $this->db["/$fam/$key"] = (string)$val; return true; }
		public function database_get($fam, $key) { return isset($this->db["/$fam/$key"]) ? $this->db["/$fam/$key"] : false; }
		public function database_del($fam, $key) { if ($this->failDel) { return false; } $had = isset($this->db["/$fam/$key"]); unset($this->db["/$fam/$key"]); return $had; }
		public function database_deltree($fam) { foreach (array_keys($this->db) as $k) { if (strpos($k, "/$fam/") === 0) { unset($this->db[$k]); } } return true; }
		public function database_show($fam) {
			$out = array();
			foreach ($this->db as $k => $v) { if (strpos($k, "/$fam/") === 0) { $out[$k] = $v; } }
			return $out;
		}
	}
	class FakeBlacklist { public $added = array(); public function numberAdd($post) { $this->added[] = $post; return true; } }
	class FakeDestinations {
		public function getAllDestinations() {
			return array('tst-blocked,s,1' => array('description' => 'TST blocked'), 'app-blackhole,hangup,1' => array('description' => 'Hangup'));
		}
	}
	class FakeConfig {
		private $vals = array();
		public function __construct($vals = array()) { $this->vals = $vals; }
		public function get($k) { return array_key_exists($k, $this->vals) ? $this->vals[$k] : false; }
	}
	class FakeFreePBX {
		public $astman, $Blacklist, $Destinations, $Config;
		public function __construct() {
			$this->astman = new FakeAstman();
			$this->Blacklist = new FakeBlacklist();
			$this->Destinations = new FakeDestinations();
			$this->Config = new FakeConfig(array(
				'ASTSPOOLDIR' => sys_get_temp_dir(),
				'AMPASTERISKUSER' => 'asterisk',
				'AMPASTERISKGROUP' => 'asterisk',
			));
		}
	}
}

namespace FreePBX\modules\Backup {
	// Stand-in for the Backup module's RestoreBase (only what Allowlist\Restore uses).
	class RestoreBase {
		public $FreePBX;
		public $logs = array();
		public $featureCodesRestored = false;
		public function __construct($freepbx) { $this->FreePBX = $freepbx; }
		public function log($message, $level = 'INFO') { $this->logs[] = array($level, $message); }
		public function restoreLegacyFeatureCodes($pdo) { $this->featureCodesRestored = true; }
	}
}
