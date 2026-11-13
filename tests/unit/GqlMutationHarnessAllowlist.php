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

		/**
		 * @return array<string, callable>
		 */
		public function readQueryResolvers()
		{
			$cb = $this->queryCallback();
			if ($cb === null)
			{
				return array();
			}
			$defs = $cb();
			$out = array();
			foreach ($defs as $name => $def)
			{
				if (isset($def['resolve']))
				{
					$out[$name] = $def['resolve'];
				}
			}
			return $out;
		}

		public function invokeAllowlistGetNode($localId)
		{
			$this->initializeTypes();
			return $this->typeContainer->get('allowlist')->invokeGetNode($localId);
		}

		public function invokeAllowlistListGetNode($listIdLocal)
		{
			$this->initializeTypes();
			return $this->typeContainer->get('allowlistList')->invokeGetNode($listIdLocal);
		}

		public function invokeAllAllowlistsTotalCount(array $args)
		{
			$resolvers = $this->readQueryResolvers();
			$conn = $resolvers['allAllowlists'](null, $args);
			$this->initializeTypes();
			return $this->typeContainer->get('allowlist')->resolveConnectionField('totalCount', $conn);
		}

		public function resolveTypeFields($typeName, array $row)
		{
			$this->initializeTypes();
			return $this->typeContainer->get($typeName)->resolveFieldsForRow($row);
		}
	}
}
