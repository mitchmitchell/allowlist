<?php
namespace FreePBX\modules\Allowlist;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
	public function runBackup($id,$transaction){
		$this->addConfigs([
			'data' => $this->FreePBX->Allowlist->getAllowlist(),
			'features' => $this->dumpFeatureCodes()
		]);
	}
}