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
	 * pausepin, did/..., autoadd/...) pass a CR/LF/NUL check and settingRejectReason();
	 * dest also goes through destinationSet() (A5). Everything else
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
			$isSetting = in_array($key, ['dest', 'pause', 'knowncallers', 'pausepin', 'debuglog'], true) || strpos($key, 'did/') === 0 || strpos($key, 'autoadd/') === 0;
			if($isSetting){
				if(!is_scalar($value) || preg_match('/[\r\n\x00]/', $key . (string)$value)){
					$skipped[] = $this->describeSkipped($key, _('setting contains CR, LF or NUL'));
					continue;
				}
				$reason = $this->settingRejectReason($allowlist, $key, (string)$value);
				if($reason !== null){
					$skipped[] = $this->describeSkipped($key, $reason);
					continue;
				}
				if($key === 'dest'){
					// A5: same registry check as the UI/CLI path.
					if(!$allowlist->destinationSet((string)$value)){
						$skipped[] = $this->describeSkipped($key, $allowlist->getLastError());
					}
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

	/**
	 * Value checks for restored settings (M4). Returns a reason to skip, or null.
	 * pause: numeric epoch no later than now + 24 h (0 = not paused).
	 * autoadd/*: "1,<int>,<int>". pausepin: 4-12 digits. knowncallers: '' or '1'.
	 */
	public function settingRejectReason($allowlist, $key, $value){
		if($key === 'dest'){
			return $allowlist->isValidDestination($value) ? null : _('destination is not a registered destination');
		}
		if($key === 'pause'){
			$max = time() + \FreePBX\modules\Allowlist::PAUSE_MAX_SECS;
			if(!preg_match('/^[0-9]{1,12}$/', $value) || (int)$value > $max){
				return _('pause must be a time no more than 24 hours ahead');
			}
			return null;
		}
		if($key === 'debuglog'){
			return in_array($value, ['', '0', '1'], true) ? null : _('debuglog must be 0 or 1');
		}
		if($key === 'knowncallers'){
			return in_array($value, ['', '0', '1'], true) ? null : _('knowncallers must be 0 or 1');
		}
		if($key === 'pausepin'){
			return preg_match('/^[0-9]{4,12}$/', $value) ? null : _('pause PIN must be 4 to 12 digits');
		}
		if(strpos($key, 'autoadd/') === 0){
			return preg_match('/^1,[0-9]{1,6},[0-9]{1,6}$/', $value) ? null : _('autoadd value must be 1,<int>,<int>');
		}
		return null;
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
