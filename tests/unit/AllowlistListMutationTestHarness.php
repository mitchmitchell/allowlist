<?php
namespace {
	require_once __DIR__ . '/../../Allowlist.class.php';
}

namespace FreePBX\modules {
	class AllowlistListMutationTestHarness extends Allowlist
	{
		public $testListMutationLockPath = null;
		public $testListMutationLockWaitSec = null;
		public $testListMutationLockWaitingPath = null;
		public $testListMutationDbReloadPath = null;
		public $testAfterListCreateWrite = null;
		public $testAfterListDeleteWrite = null;
		public $testBeforeListMutationLockAcquire = null;

		protected function beforeListMutationLockAcquire()
		{
			if ($this->testBeforeListMutationLockAcquire !== null)
			{
				($this->testBeforeListMutationLockAcquire)($this);
			}
		}

		protected function listMutationLockPath()
		{
			if ($this->testListMutationLockPath !== null && $this->testListMutationLockPath !== '')
			{
				return $this->testListMutationLockPath;
			}
			return parent::listMutationLockPath();
		}

		protected function listMutationLockWaitSec()
		{
			if ($this->testListMutationLockWaitSec !== null)
			{
				return (float)$this->testListMutationLockWaitSec;
			}
			return parent::listMutationLockWaitSec();
		}

		protected function onListMutationLockWaiting()
		{
			if ($this->testListMutationLockWaitingPath !== null && $this->testListMutationLockWaitingPath !== '')
			{
				file_put_contents($this->testListMutationLockWaitingPath, '1');
			}
		}

		protected function refreshListMutationState()
		{
			if ($this->testListMutationDbReloadPath === null || $this->testListMutationDbReloadPath === '')
			{
				return;
			}
			if (!is_readable($this->testListMutationDbReloadPath))
			{
				return;
			}
			$raw = file_get_contents($this->testListMutationDbReloadPath);
			if ($raw === false)
			{
				return;
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded))
			{
				return;
			}
			$this->astman->db = $decoded;
		}

		protected function afterListCreateWrite($listId)
		{
			if ($this->testAfterListCreateWrite !== null)
			{
				($this->testAfterListCreateWrite)($this, $listId);
			}
		}

		protected function afterListDeleteWrite($listId)
		{
			if ($this->testAfterListDeleteWrite !== null)
			{
				($this->testAfterListDeleteWrite)($this, $listId);
			}
		}
	}
}
