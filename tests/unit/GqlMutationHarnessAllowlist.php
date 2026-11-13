<?php
namespace FreePBX\modules\Allowlist\Api\Gql {
	class AllowlistGqlMutationHarness extends Allowlist
	{
		public function __construct($freepbx)
		{
			$this->freepbx = $freepbx;
			$this->typeContainer = new \AllowlistUnitGqlTypeContainer();
		}

		/**
		 * @return array<string, callable>
		 */
		public function writeMutationCallbacks()
		{
			$cb = $this->mutationCallback();
			$defs = $cb();
			$out = array();
			foreach ($defs as $name => $def)
			{
				$out[$name] = $def['mutateAndGetPayload'];
			}
			return $out;
		}
	}
}
