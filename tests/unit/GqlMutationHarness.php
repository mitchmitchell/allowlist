<?php
namespace {
	class AllowlistUnitUserError extends \Exception
	{
	}
	if (!class_exists('GraphQL\\Error\\UserError', false)) {
		class_alias('AllowlistUnitUserError', 'GraphQL\\Error\\UserError');
	}

	class AllowlistUnitGqlTypeContainer
	{
		private $types = array();
		public function create($name, $kind = null)
		{
			$t = new AllowlistUnitGqlTypeStub($name);
			$this->types[$name] = $t;
			return $t;
		}
		public function get($name)
		{
			if (!isset($this->types[$name]))
			{
				$this->types[$name] = new AllowlistUnitGqlTypeStub($name);
			}
			return $this->types[$name];
		}
	}

	class AllowlistUnitGqlTypeStub
	{
		private $name;
		private $object;
		private $getNodeCallback;
		private $connectionFieldsCallback;
		private $fieldCallbackFactories = array();
		public function __construct($name)
		{
			$this->name = $name;
			$this->object = new AllowlistUnitGqlObjectStub();
		}
		public function getObject()
		{
			return $this->object;
		}
		public function getConnectionType()
		{
			return 'Conn';
		}
		public function setDescription($d)
		{
		}
		public function addInterfaceCallback($cb)
		{
		}
		public function setGetNodeCallback($cb)
		{
			$this->getNodeCallback = $cb;
		}
		public function invokeGetNode($id)
		{
			if ($this->getNodeCallback === null)
			{
				return null;
			}
			return ($this->getNodeCallback)($id);
		}
		public function addFieldCallback($cb)
		{
			$this->fieldCallbackFactories[] = $cb;
		}
		public function resolveFieldsForRow($row)
		{
			$out = array();
			foreach ($this->fieldCallbackFactories as $fieldCb)
			{
				$fields = $fieldCb();
				foreach ($fields as $fieldName => $def)
				{
					if (!is_array($def) || !isset($def['resolve']))
					{
						continue;
					}
					$out[$fieldName] = $def['resolve']($row, array());
				}
			}
			return $out;
		}
		public function setConnectionResolveNode($cb)
		{
		}
		public function setConnectionFields($cb)
		{
			$this->connectionFieldsCallback = $cb;
		}
		public function resolveConnectionField($field, array $connValue)
		{
			if ($this->connectionFieldsCallback === null)
			{
				return null;
			}
			$fields = ($this->connectionFieldsCallback)();
			if (!isset($fields[$field]['resolve']))
			{
				return null;
			}
			return $fields[$field]['resolve']($connValue);
		}
		public function resolveValue($v)
		{
			return $v;
		}
	}

	class AllowlistUnitGqlObjectStub
	{
	}

	function allowlistUnitGqlHarnessFresh($fp, $al)
	{
		$fp->Allowlist = $al;
		return new \FreePBX\modules\Allowlist\Api\Gql\AllowlistGqlMutationHarness($fp);
	}

	/**
	 * @return array{ok:bool,result:mixed,error:string}
	 */
	function allowlistUnitGqlRunQuery($gqlHarness, $field, array $args)
	{
		$resolvers = $gqlHarness->readQueryResolvers();
		if (!isset($resolvers[$field]))
		{
			return array('ok' => false, 'result' => null, 'error' => 'unknown query');
		}
		try
		{
			$result = $resolvers[$field](null, $args);
			return array('ok' => true, 'result' => $result, 'error' => '');
		}
		catch (\GraphQL\Error\UserError $e)
		{
			return array('ok' => false, 'result' => null, 'error' => $e->getMessage());
		}
	}

	function allowlistUnitGqlRunMutation($gqlHarness, $name, array $input)
	{
		$mutations = $gqlHarness->writeMutationCallbacks();
		if (!isset($mutations[$name]))
		{
			return array('ok' => false, 'result' => null, 'error' => 'unknown mutation');
		}
		try
		{
			$result = $mutations[$name]($input);
			return array('ok' => true, 'result' => $result, 'error' => '');
		}
		catch (\GraphQL\Error\UserError $e)
		{
			return array('ok' => false, 'result' => null, 'error' => $e->getMessage());
		}
	}
}

namespace GraphQL\Type\Definition {
	class Type
	{
		public static function nonNull($t)
		{
			return $t;
		}
		public static function string()
		{
			return 'String';
		}
		public static function id()
		{
			return 'ID';
		}
		public static function int()
		{
			return 'Int';
		}
		public static function boolean()
		{
			return 'Boolean';
		}
		public static function listOf($t)
		{
			return array();
		}
	}
	class ObjectType
	{
	}
}

namespace GraphQLRelay {
	class Relay
	{
		public static function mutationWithClientMutationId($cfg)
		{
			return $cfg;
		}
		public static function connectionArgs()
		{
			return array();
		}
		public static function connectionFromArray($data, $args)
		{
			$total = count($data);
			$first = isset($args['first']) ? (int)$args['first'] : $total;
			if ($first < 0)
			{
				$first = 0;
			}
			$slice = $first === 0 ? array() : array_slice($data, 0, $first);
			$edges = array();
			foreach ($slice as $row)
			{
				$edges[] = array('node' => $row, 'cursor' => '');
			}
			return array(
				'edges' => $edges,
				'pageInfo' => array(
					'hasNextPage' => $total > count($slice),
					'hasPreviousPage' => false,
				),
			);
		}
		public static function fromGlobalId($id)
		{
			$decoded = base64_decode($id, true);
			if (!is_string($decoded) || $decoded === '')
			{
				return array('type' => '', 'id' => '');
			}
			$colon = strpos($decoded, ':');
			if ($colon === false)
			{
				return array('type' => '', 'id' => '');
			}
			return array(
				'type' => substr($decoded, 0, $colon),
				'id' => substr($decoded, $colon + 1),
			);
		}
		public static function globalIdField($type, $cb)
		{
			return array(
				'type' => 'ID',
				'resolve' => function ($row) use ($type, $cb) {
					$local = $cb($row);
					return base64_encode($type . ':' . $local);
				},
			);
		}
	}
}

namespace FreePBX\modules\Api\Gql {
	class Base
	{
		public $freepbx;
		public $typeContainer;
		protected $module = 'allowlist';
		public function checkAllWriteScope()
		{
			return true;
		}
		public function checkAllReadScope()
		{
			return true;
		}
		protected function getNodeDefinition()
		{
			return array('nodeInterface' => 'Node');
		}
	}
}
