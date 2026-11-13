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
		private static function connectionArrayOffsetFromCursor($cursor)
		{
			if (!is_string($cursor) || $cursor === '')
			{
				return null;
			}
			$decoded = base64_decode($cursor);
			if (!is_string($decoded) || !preg_match('/^arrayconnection:(\d+)$/', $decoded, $m))
			{
				return null;
			}
			return (int)$m[1];
		}

		private static function connectionArrayCursorFromOffset($offset)
		{
			return base64_encode('arrayconnection:' . (int)$offset);
		}

		public static function connectionFromArray($data, $args)
		{
			$total = count($data);
			$from = 0;
			$to = $total;
			if (isset($args['after']))
			{
				$after = self::connectionArrayOffsetFromCursor($args['after']);
				if ($after !== null)
				{
					$from = $after + 1;
				}
			}
			if (isset($args['before']))
			{
				$before = self::connectionArrayOffsetFromCursor($args['before']);
				if ($before !== null)
				{
					$to = $before;
				}
			}
			if ($from > $to)
			{
				$from = $to;
			}
			$window = $to - $from;
			if (isset($args['first']))
			{
				$first = (int)$args['first'];
				if ($first < 0)
				{
					$first = 0;
				}
				$window = min($first, $window);
			}
			elseif (isset($args['last']))
			{
				$last = (int)$args['last'];
				if ($last < 0)
				{
					$last = 0;
				}
				$window = min($last, $window);
				$from = $to - $window;
			}
			$slice = $window > 0 ? array_slice($data, $from, $window) : array();
			$edges = array();
			foreach ($slice as $i => $row)
			{
				$offset = $from + $i;
				$edges[] = array(
					'node' => $row,
					'cursor' => self::connectionArrayCursorFromOffset($offset),
				);
			}
			$endOffset = $from + count($slice);
			return array(
				'edges' => $edges,
				'pageInfo' => array(
					'hasNextPage' => $endOffset < $to,
					'hasPreviousPage' => $from > 0,
					'startCursor' => count($edges) > 0 ? $edges[0]['cursor'] : null,
					'endCursor' => count($edges) > 0 ? $edges[count($edges) - 1]['cursor'] : null,
				),
			);
		}
		public static function fromGlobalId($id)
		{
			$decoded = base64_decode($id);
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
		public $unitGqlReadScopeAllowed = true;
		protected $module = 'allowlist';
		public function checkAllWriteScope()
		{
			return true;
		}
		public function checkAllReadScope()
		{
			return $this->unitGqlReadScopeAllowed;
		}
		protected function getNodeDefinition()
		{
			return array('nodeInterface' => 'Node');
		}
	}
}
