<?php

namespace FreePBX\modules\Allowlist\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Error\UserError;

class Allowlist extends Base {
	protected $module = 'allowlist';

	public function mutationCallback() {
		if($this->checkAllWriteScope()) {
			return function() {
				return [
					'addAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'addAllowlist',
						'description' => _('Add a new number to the allowlist'),
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
							$allowlist = $this->freepbx->Allowlist;
							$entry = $allowlist->checkEntry([
								'number' => isset($input['number']) ? $input['number'] : null,
								'description' => isset($input['description']) ? $input['description'] : null,
							]);
							if (!$entry || !$allowlist->numberAdd($entry)) {
								throw new UserError($allowlist->getLastError());
							}
							return $allowlist->findNumber($entry['number']);
						}
					]),
					'removeAllowlist' => Relay::mutationWithClientMutationId([
						'name' => 'removeAllowlist',
						'description' => _('Remove a number from the allowlist'),
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
							$allowlist = $this->freepbx->Allowlist;
							if (!$allowlist->numberDel(isset($input['number']) ? $input['number'] : null)) {
								throw new UserError($allowlist->getLastError());
							}
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
						'description' => _('Used to manage a system wide list of allowed callers'),
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
								'description' => _('The ID'),
							]
						],
						'resolve' => function($root, $args) {
							if (!isset($args['id'])) {
								return null;
							}
							return $this->freepbx->Allowlist->findNumber(Relay::fromGlobalId($args['id'])['id']);
						}
					],
					'allowlistSettings' => [
						'type' => $this->typeContainer->get('allowlistsettings')->getObject(),
						'description' => _('Allowlist Settings'),
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

		$user = $this->typeContainer->create('allowlist');
		$user->setDescription(_('Used to manage a system wide list of allowed callers'));

		$user->addInterfaceCallback(function() {
			return [$this->getNodeDefinition()['nodeInterface']];
		});

		$user->setGetNodeCallback(function($id) {
			return $this->freepbx->Allowlist->findNumber($id);
		});

		$user->addFieldCallback(function() {
			return [
				'id' => Relay::globalIdField('allowlist', function($row) {
					return $row['number'];
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
