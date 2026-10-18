<?php
namespace FreePBX\modules\Allowlist;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
	public function runRestore(){
		$configs = $this->getConfigs();
		$this->deleteOldData();
//		foreach($configs['data'] as $item){
//			if(empty($item['number'])){
//				continue;
//			}
//			$this->FreePBX->Allowlist->numberAdd($item);
//		}
		$this->importAstDB($configs['data']);
		$this->importFeatureCodes($configs['features']);
	}
	public function processLegacy($pdo, $data, $tables, $unknownTables){
		$astdb =  $data['astdb'];
		if(!isset($astdb['allowlist'])){
			return $this;
		}
		$this->deleteOldData();
		$this->restoreAllowlistEntries($astdb['allowlist']);
		$this->restoreLegacyFeatureCodes($pdo);
	}

	/**
	 * Restore legacy allowlist AstDB entries. Settings keys (dest, pause, knowncallers,
	 * did/..., autoadd/...) are written directly after a CR/LF/NUL check; everything else
	 * must pass Allowlist::numberAdd(). Skipped entries are logged and returned.
	 * @param  array $entries key => value, keys without the /allowlist/ prefix
	 * @return array Skipped entries as "key: reason"
	 */
	public function restoreAllowlistEntries($entries){
		$allowlist = $this->FreePBX->Allowlist;
		$astman = $this->FreePBX->astman;
		$skipped = [];
		foreach($entries as $key => $value){
			$key = (string)$key;
			$isSetting = in_array($key, ['dest', 'pause', 'knowncallers'], true) || strpos($key, 'did/') === 0 || strpos($key, 'autoadd/') === 0;
			if($isSetting){
				if(!is_scalar($value) || preg_match('/[\r\n\x00]/', $key . (string)$value)){
					$skipped[] = $this->describeSkipped($key, _('setting contains CR, LF or NUL'));
					continue;
				}
				$astman->database_put('allowlist', $key, (string)$value);
				continue;
			}
			if(!$allowlist->numberAdd(['number' => $key, 'description' => is_scalar($value) ? (string)$value : null])){
				$skipped[] = $this->describeSkipped($key, $allowlist->getLastError());
			}
		}
		foreach($skipped as $line){
			$msg = sprintf(_('Allowlist restore skipped %s'), $line);
			if(method_exists($this, 'log')){
				$this->log($msg, 'WARNING');
			} else {
				error_log($msg);
			}
		}
		return $skipped;
	}

	private function describeSkipped($key, $reason){
		// Masked, truncated and HTML-escaped: restore logs can be shown in the web UI.
		$key = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '?', $key), 0, 40, 'UTF-8');
		return htmlspecialchars($key . ': ' . $reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
	public function deleteOldData(){
		$this->astman = $this->FreePBX->astman;
		if ($this->astman->connected()) {
			$this->astman->database_deltree('allowlist');
		}
	}
}
