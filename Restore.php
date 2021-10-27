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
		foreach($astdb['allowlist'] as $number => $desc){
			$this->FreePBX->Allowlist->numberAdd(['number' => $number, 'description' => $desc]);
		}
		$this->restoreLegacyFeatureCodes($pdo);
	}
	public function deleteOldData(){
		$this->astman = $this->FreePBX->astman;
		if ($this->astman->connected()) {
			$this->astman->database_deltree('allowlist');
		}
	}
}
