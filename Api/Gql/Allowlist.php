<?php

namespace FreePBX\modules\Allowlist\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;
use GraphQL\Type\Definition\ObjectType;

class Allowlist extends Base {
	protected $module = 'allowlist';

	public function mutationCallback() {
		if($this->checkAllWriteScope()) {
			return function() {
				return [
					'addAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'addAllowlist',
						'description' => 'Add a new number to the allowlist',
						'inputFields' => [
							'number' => [
								'type' => Type::nonNull(Type::string())
							],
							'description' => [
								'type' => Type::string()
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
							$this->freepbx->Allowlist->numberAdd($input);
							$list = $this->freepbx->Allowlist->getAllowlist();
							$item = array_search($input['number'], array_column($list, 'number'));
							return isset($list[$item]) ? $list[$item] : null;
						}
					]),
					'removeAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'removeAllowlist',
						'description' => 'Remove a number from the allowlist',
						'inputFields' => [
							'number' => [
								'type' => Type::nonNull(Type::string())
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
							$this->freepbx->Allowlist->numberDel($input['number']);
							return ['id' => $input['number']];
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
						'description' => 'Used to manage a system wide list of allowed callers',
						'args' => Relay::connectionArgs(),
						'resolve' => function($root, $args) {
							return Relay::connectionFromArray($this->freepbx->Allowlist->getAllowlist(), $args);
						},
					],
					'allowlist' => [
						'type' => $this->typeContainer->get('allowlist')->getObject(),
						'args' => [
							'id' => [
								'type' => Type::id(),
								'description' => 'The ID',
							]
						],
						'resolve' => function($root, $args) {
							$list = $this->freepbx->Allowlist->getAllowlist();
							$item = array_search(Relay::fromGlobalId($args['id'])['id'], array_column($list, 'number'));
							return isset($list[$item]) ? $list[$item] : null;
						}
					],
					'allowlistSettings' => [
						'type' => $this->typeContainer->get('allowlistsettings')->getObject(),
						'description' => 'Allowlist Settings',
						'resolve' => function($root, $args) {
							return []; //trick the resolver into not thinking this is null
						}
					]
				];
			};
		}
	}

	public function initializeTypes() {
		$user = $this->typeContainer->create('allowlistsettings','object');
		$user->setDescription('Allowlist Settings');
		$user->addFieldCallback(function() {
			return [
				'destinationConnection' => [
					'type' => $this->typeContainer->get('destination')->getObject(),
					'description' => 'Destination for non allowlisted calls',
					'resolve' => function($root, $args) {
						return $this->typeContainer->get('destination')->resolveValue($this->freepbx->Allowlist->destinationGet());
					}
				]
			];
		});

		$user = $this->typeContainer->create('allowlist');
		$user->setDescription('Used to manage a system wide list of allowed callers');

		$user->addInterfaceCallback(function() {
			return [$this->getNodeDefinition()['nodeInterface']];
		});

		$user->setGetNodeCallback(function($id) {
			$list = $this->freepbx->Allowlist->getAllowlist();
			$item = array_search($id, array_column($list, 'number'));
			return isset($list[$item]) ? $list[$item] : null;
		});

		$user->addFieldCallback(function() {
			return [
				'id' => Relay::globalIdField('allowlist', function($row) {
					return $row['number'];
				}),
				'number' => [
					'type' => Type::string(),
					'description' => 'The number to allow'
				],
				'description' => [
					'type' => Type::string(),
					'description' => 'Description of the allowed number'
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
						return count($this->freepbx->Allowlist->getAllowlist());
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
