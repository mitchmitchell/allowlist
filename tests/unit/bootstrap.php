<?php
// Minimal stand-ins so module classes load without a FreePBX install. No network, no AstDB.
namespace {
	interface BMO {}
	if (!function_exists('_')) {
		function _($s) { return $s; }
	}
	// Any \ext_* dialplan class becomes a recorder of its constructor arguments.
	spl_autoload_register(function ($class) {
		if (strpos($class, 'ext_') === 0 && preg_match('/^ext_[a-z_]+$/', $class)) {
			eval('class ' . $class . ' { public $args; public function __construct(...$a) { $this->args = $a; } }');
		}
	});
	class featurecode {
		private $f;
		public function __construct($module, $feature) { $this->f = $feature; }
		public function getCodeActive() { return array('allowlist_add' => '*38', 'allowlist_remove' => '*39', 'allowlist_last' => '*40', 'allowlist_pause_toggle' => '*41')[$this->f]; }
	}
	class FakeExt {
		public $lines = array();
		public function add($ctx, $exten, $label, $obj) { $this->lines[] = array('ctx' => $ctx, 'exten' => $exten, 'label' => $label, 'app' => get_class($obj), 'args' => $obj->args); }
		public function addInclude($a, $b) {}
	}
	class FakeAstman {
		public $db = array();
		public function connected() { return true; }
		public function database_put($fam, $key, $val) { $this->db["/$fam/$key"] = (string)$val; return true; }
		public function database_get($fam, $key) { return isset($this->db["/$fam/$key"]) ? $this->db["/$fam/$key"] : false; }
		public function database_del($fam, $key) { $had = isset($this->db["/$fam/$key"]); unset($this->db["/$fam/$key"]); return $had; }
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
	class FakeFreePBX {
		public $astman, $Blacklist, $Destinations;
		public function __construct() { $this->astman = new FakeAstman(); $this->Blacklist = new FakeBlacklist(); $this->Destinations = new FakeDestinations(); }
	}
}
