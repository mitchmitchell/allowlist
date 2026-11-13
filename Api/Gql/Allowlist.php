<?php

namespace FreePBX\modules\Allowlist\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Error\UserError;
use FreePBX\modules\Allowlist as AllowlistModule;

class Allowlist extends Base {
	protected $module = 'allowlist';

	/**
	 * @return int
	 */
	private function resolveMemberListIdArg($raw, $required = false)
	{
		$allowlist = $this->freepbx->Allowlist;
		$id = $allowlist->resolveGraphqlListIdArg($raw, $required);
		if ($id === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $id;
	}

	private function memberRowsForGraphql($listId)
	{
		$rows = $this->freepbx->Allowlist->listMemberGetAll($listId);
		foreach ($rows as $i => $row)
		{
			$rows[$i]['listId'] = $listId;
		}
		return $rows;
	}

	protected function gqlMutateAddAllowlist(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$row = $allowlist->applyGraphqlAddAllowlistMember(
			isset($input['listId']) ? $input['listId'] : null,
			isset($input['number']) ? $input['number'] : null,
			isset($input['description']) ? $input['description'] : null
		);
		if ($row === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $row;
	}

	protected function gqlMutateRemoveAllowlist(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$payload = $allowlist->applyGraphqlRemoveAllowlistMember(
			isset($input['listId']) ? $input['listId'] : null,
			isset($input['number']) ? $input['number'] : null
		);
		if ($payload === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $payload;
	}

	protected function gqlMutateAddAllowlistList(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$row = $allowlist->applyGraphqlCreateAllowlistList(isset($input['name']) ? $input['name'] : '');
		if ($row === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $row;
	}

	protected function gqlMutateRenameAllowlistList(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$row = $allowlist->applyGraphqlRenameAllowlistList(
			isset($input['id']) ? $input['id'] : null,
			isset($input['name']) ? $input['name'] : ''
		);
		if ($row === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $row;
	}

	protected function gqlMutateRemoveAllowlistList(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$payload = $allowlist->applyGraphqlRemoveAllowlistList(isset($input['id']) ? $input['id'] : null);
		if ($payload === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return $payload;
	}

	protected function gqlMutateSetInboundRouteAllowlist(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$did = isset($input['did']) ? (string)$input['did'] : '';
		$cid = isset($input['cid']) ? (string)$input['cid'] : '';
		$listIdProvided = array_key_exists('listId', $input);
		$listIdRaw = $listIdProvided ? $input['listId'] : null;
		$result = $allowlist->applyGraphqlInboundRouteAllowlist($did, $cid, $listIdRaw, $listIdProvided);
		if ($result === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return array(
			'listId' => $result['listId'],
		);
	}

	protected function gqlMutateSetOutboundRouteAllowlist(array $input)
	{
		$allowlist = $this->freepbx->Allowlist;
		$routeId = isset($input['routeId']) ? $input['routeId'] : null;
		$remove = isset($input['remove']) ? $input['remove'] : null;
		$store = isset($input['store']) ? $input['store'] : null;
		$listIdProvided = array_key_exists('listId', $input);
		$listIdRaw = $listIdProvided ? $input['listId'] : null;
		$result = $allowlist->applyGraphqlOutboundRouteAllowlist($routeId, $remove, $store, $listIdRaw, $listIdProvided);
		if ($result === false)
		{
			throw new UserError($allowlist->getLastError());
		}
		return array(
			'listId' => $result['listId'],
		);
	}

	public function mutationCallback() {
		if($this->checkAllWriteScope()) {
			return function() {
				return [
					'addAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'addAllowlist',
						'description' => _('Add a new number to the allowlist'),
						'inputFields' => [
							'number' => [
								'type' => Type::nonNull(Type::string()),
								'description' => _('Number to allow: digits only, optional leading +, up to 20 digits')
							],
							'description' => [
								'type' => Type::string(),
								'description' => _('Optional label for the number')
							],
							'listId' => [
								'type' => Type::id(),
								'description' => _('Allowlist to add to (defaults to the default list)')
							]
						],
						'outputFields' => [
							'allowlist' => [
								'type' => $this->typeContainer->get('allowlist')->getObject(),
								'resolve' => function ($payload) {
									return $payload;
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateAddAllowlist($input);
						}
					]),
					'removeAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'removeAllowlist',
						'description' => _('Remove a number from the allowlist'),
						'inputFields' => [
							'number' => [
								'type' => Type::nonNull(Type::string()),
								'description' => _('Number to remove from the allowlist')
							],
							'listId' => [
								'type' => Type::id(),
								'description' => _('Allowlist to remove from (defaults to the default list)')
							]
						],
						'outputFields' => [
							'deletedId' => [
								'type' => Type::nonNull(Type::id()),
								'resolve' => function ($payload) {
									return $payload['id'];
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateRemoveAllowlist($input);
						}
					]),
					'addAllowlistList' => Relay::mutationWithClientMutationId([
						'name' => 'addAllowlistList',
						'description' => _('Create a new named allowlist'),
						'inputFields' => [
							'name' => [
								'type' => Type::nonNull(Type::string()),
								'description' => _('Display name for the new allowlist')
							]
						],
						'outputFields' => [
							'allowlistList' => [
								'type' => $this->typeContainer->get('allowlistList')->getObject(),
								'resolve' => function ($payload) {
									return $payload;
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateAddAllowlistList($input);
						}
					]),
					'renameAllowlistList' => Relay::mutationWithClientMutationId([
						'name' => 'renameAllowlistList',
						'description' => _('Rename an allowlist'),
						'inputFields' => [
							'id' => [
								'type' => Type::nonNull(Type::id()),
								'description' => _('Allowlist id')
							],
							'name' => [
								'type' => Type::nonNull(Type::string()),
								'description' => _('New display name')
							]
						],
						'outputFields' => [
							'allowlistList' => [
								'type' => $this->typeContainer->get('allowlistList')->getObject(),
								'resolve' => function ($payload) {
									return $payload;
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateRenameAllowlistList($input);
						}
					]),
					'removeAllowlistList' => Relay::mutationWithClientMutationId([
						'name' => 'removeAllowlistList',
						'description' => _('Delete a named allowlist'),
						'inputFields' => [
							'id' => [
								'type' => Type::nonNull(Type::id()),
								'description' => _('Allowlist id')
							]
						],
						'outputFields' => [
							'deletedId' => [
								'type' => Type::nonNull(Type::id()),
								'resolve' => function ($payload) {
									return $payload['id'];
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateRemoveAllowlistList($input);
						}
					]),
					'setInboundRouteAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'setInboundRouteAllowlist',
						'description' => _('Assign allowlist screening to an inbound route, or turn screening off'),
						'inputFields' => [
							'did' => [
								'type' => Type::nonNull(Type::string()),
								'description' => _('Inbound route DID')
							],
							'cid' => [
								'type' => Type::string(),
								'description' => _('Inbound route caller ID match (may be empty)')
							],
							'listId' => [
								'type' => Type::id(),
								'description' => _('Allowlist id, or null to turn screening off')
							]
						],
						'outputFields' => [
							'listId' => [
								'type' => Type::id(),
								'description' => _('Assigned allowlist id, or null when screening is off'),
								'resolve' => function ($payload) {
									return $payload['listId'];
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateSetInboundRouteAllowlist($input);
						}
					]),
					'setOutboundRouteAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'setOutboundRouteAllowlist',
						'description' => _('Assign allowlist auto-add to an outbound route, or turn it off'),
						'inputFields' => [
							'routeId' => [
								'type' => Type::nonNull(Type::id()),
								'description' => _('Outbound route id')
							],
							'listId' => [
								'type' => Type::id(),
								'description' => _('Allowlist id, or null to turn auto-add off')
							],
							'remove' => [
								'type' => Type::int(),
								'description' => _('Digits to remove from the start of dialed numbers (required when enabling auto-add)')
							],
							'store' => [
								'type' => Type::int(),
								'description' => _('Minimum stored number length for auto-add (required when enabling auto-add)')
							]
						],
						'outputFields' => [
							'listId' => [
								'type' => Type::id(),
								'description' => _('Assigned allowlist id, or null when auto-add is off'),
								'resolve' => function ($payload) {
									return $payload['listId'];
								}
							]
						],
						'mutateAndGetPayload' => function ($input) {
							return $this->gqlMutateSetOutboundRouteAllowlist($input);
						}
					])
				];
			};
		}
	}

	public function queryCallback() {
		if($this->checkAllReadScope()) {
			return function() {
				return [
					'allAllowlists' => [
						'type' => $this->typeContainer->get('allowlist')->getConnectionType(),
						'description' => _('Used to manage a system wide list of allowed callers'),
						'args' => array_merge(Relay::connectionArgs(), [
							'listId' => [
								'type' => Type::id(),
								'description' => _('Allowlist to query (defaults to the default list)')
							]
						]),
						'resolve' => function($root, $args) {
							$listId = $this->resolveMemberListIdArg(isset($args['listId']) ? $args['listId'] : null);
							$data = $this->memberRowsForGraphql($listId);
							$conn = Relay::connectionFromArray($data, $args);
							$conn['allowlistQueryListId'] = $listId;
							return $conn;
						},
					],
					'allowlist' => [
						'type' => $this->typeContainer->get('allowlist')->getObject(),
						'args' => [
							'id' => [
								'type' => Type::id(),
								'description' => _('The ID'),
							]
						],
						'resolve' => function($root, $args) {
							if (!isset($args['id'])) {
								return null;
							}
							$relay = Relay::fromGlobalId($args['id']);
							if (!isset($relay['type']) || $relay['type'] !== 'allowlist') {
								return null;
							}
							$localId = isset($relay['id']) ? $relay['id'] : '';
							$parsed = AllowlistModule::parseGqlAllowlistNodeLocalId($localId);
							if ($parsed === false)
							{
								return null;
							}
							$row = $this->freepbx->Allowlist->findListMember($parsed['listId'], $parsed['number']);
							if ($row === null)
							{
								return null;
							}
							$row['listId'] = $parsed['listId'];
							return $row;
						}
					],
					'allowlistSettings' => [
						'type' => $this->typeContainer->get('allowlistsettings')->getObject(),
						'description' => _('Allowlist Settings'),
						'resolve' => function($root, $args) {
							return []; //trick the resolver into not thinking this is null
						}
					],
					'allowlistLists' => [
						'type' => $this->typeContainer->get('allowlistList')->getConnectionType(),
						'description' => _('Named and default allowlists with route assignments'),
						'args' => Relay::connectionArgs(),
						'resolve' => function($root, $args) {
							return Relay::connectionFromArray($this->freepbx->Allowlist->summarizeAllowlistsForGraphql(), $args);
						}
					]
				];
			};
		}
	}

	public function initializeTypes() {
		$user = $this->typeContainer->create('allowlistsettings','object');
		$user->setDescription(_('Allowlist Settings'));
		$user->addFieldCallback(function() {
			return [
				'pauseAllowlist' => [
					'type' => Type::boolean(),
					'description' => _('Pause or unpause allowlist processing globally'),
					'resolve' => function ($root, $args) {
						return $this->freepbx->Allowlist->pauseGet() == 1 ? true : false;
					}
				],
				'destinationConnection' => [
					'type' => $this->typeContainer->get('destination')->getObject(),
					'description' => _('Destination for non allowlisted calls'),
					'resolve' => function($root, $args) {
						return $this->typeContainer->get('destination')->resolveValue($this->freepbx->Allowlist->destinationGet());
					}
				]
			];
		});

		$listType = $this->typeContainer->create('allowlistList', 'object');
		$listType->setDescription(_('An allowlist (default or named)'));
		$listType->addInterfaceCallback(function() {
			return [$this->getNodeDefinition()['nodeInterface']];
		});
		$listType->setGetNodeCallback(function ($id) {
			if (!$this->checkAllReadScope()) {
				return null;
			}
			return $this->freepbx->Allowlist->findAllowlistListSummaryById($id);
		});
		$listType->addFieldCallback(function() {
			return [
				'id' => Relay::globalIdField('allowlistList', function ($row) {
					return (string)($row['id'] ?? '');
				}),
				'name' => [
					'type' => Type::nonNull(Type::string()),
					'description' => _('List name returned as stored (raw, unescaped); escape before showing in HTML.'),
					'resolve' => function ($row) {
						return isset($row['name']) ? $row['name'] : '';
					}
				],
				'memberCount' => [
					'type' => Type::nonNull(Type::int()),
					'description' => _('Number of members on this list'),
					'resolve' => function ($row) {
						return isset($row['memberCount']) ? (int)$row['memberCount'] : 0;
					}
				],
				'inboundRoutes' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
					'description' => _('Inbound routes using this list for screening'),
					'resolve' => function ($row) {
						return isset($row['inboundRoutes']) ? $row['inboundRoutes'] : array();
					}
				],
				'outboundRoutes' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
					'description' => _('Outbound routes using this list for auto-add'),
					'resolve' => function ($row) {
						return isset($row['outboundRoutes']) ? $row['outboundRoutes'] : array();
					}
				]
			];
		});
		$listType->setConnectionResolveNode(function ($edge) {
			return $edge['node'];
		});

		$user = $this->typeContainer->create('allowlist');
		$user->setDescription(_('Used to manage a system wide list of allowed callers'));

		$user->addInterfaceCallback(function() {
			return [$this->getNodeDefinition()['nodeInterface']];
		});

		$user->setGetNodeCallback(function($id) {
			if (!$this->checkAllReadScope()) {
				return null;
			}
			$parsed = AllowlistModule::parseGqlAllowlistNodeLocalId($id);
			if ($parsed === false)
			{
				return null;
			}
			$row = $this->freepbx->Allowlist->findListMember($parsed['listId'], $parsed['number']);
			if ($row === null)
			{
				return null;
			}
			$row['listId'] = $parsed['listId'];
			return $row;
		});

		$user->addFieldCallback(function() {
			return [
				'id' => Relay::globalIdField('allowlist', function($row) {
					$listId = isset($row['listId']) ? $row['listId'] : AllowlistModule::DEFAULT_LIST_ID;
					$localId = AllowlistModule::formatGqlAllowlistNodeLocalId($listId, $row['number'] ?? '');
					return $localId !== false ? $localId : '';
				}),
				'number' => [
					'type' => Type::string(),
					'description' => _('The number to allow')
				],
				'description' => [
					'type' => Type::string(),
					'description' => _('Description of the allowed number')
				]
			];
		});

		$user->setConnectionResolveNode(function ($edge) {
			return $edge['node'];
		});

		$user->setConnectionFields(function() {
			return [
				'totalCount' => [
					'type' => Type::int(),
					'resolve' => function($value) {
						$listId = isset($value['allowlistQueryListId'])
							? $value['allowlistQueryListId']
							: AllowlistModule::DEFAULT_LIST_ID;
						return count($this->freepbx->Allowlist->listMemberGetAll($listId));
					}
				],
				'allowlists' => [
					'type' => Type::listOf($this->typeContainer->get('allowlist')->getObject()),
					'resolve' => function($root, $args) {
						$data = array_map(function($row){
							return $row['node'];
						},$root['edges']);
						return $data;
					}
				]
			];
		});
	}
}
