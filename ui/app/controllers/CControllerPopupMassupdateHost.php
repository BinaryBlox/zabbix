<?php declare(strict_types = 1);
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/../../include/forms.inc.php';

class CControllerPopupMassupdateHost extends CController {

	protected function init() {
		$this->disableSIDvalidation();
	}

	protected function checkInput() {
		$fields = [
			'ids' => 'required|array',
			'update' => 'in 1',
			'visible' => 'array',
			'tags' => 'array',
			'macros' => 'array',
			'groups' => 'array',
			'host_inventory' => 'array',
			'templates' => 'array',
			'inventories' => 'array',
			'description' => 'string',
			'proxy_hostid' => 'string',
			'ipmi_username' => 'string',
			'ipmi_password' => 'string',
			'tls_issuer' => 'string',
			'tls_subject' => 'string',
			'tls_psk_identity' => 'string',
			'tls_psk' => 'string',
			'macros_add' => 'in 0,1',
			'macros_update' => 'in 0,1',
			'macros_remove' => 'in 0,1',
			'macros_remove_all' => 'in 0,1',
			'mass_clear_tpls' => 'in 0,1',
			'mass_action_tpls' => 'in '.implode(',', [ZBX_ACTION_ADD, ZBX_ACTION_REPLACE, ZBX_ACTION_REMOVE]),
			'mass_update_groups' => 'in '.implode(',', [ZBX_ACTION_ADD, ZBX_ACTION_REPLACE, ZBX_ACTION_REMOVE]),
			'mass_update_tags' => 'in '.implode(',', [ZBX_ACTION_ADD, ZBX_ACTION_REPLACE, ZBX_ACTION_REMOVE]),
			'mass_update_macros' => 'in '.implode(',', [ZBX_ACTION_ADD, ZBX_ACTION_REPLACE, ZBX_ACTION_REMOVE, ZBX_ACTION_REMOVE_ALL]),
			'inventory_mode' => 'in '.implode(',', [HOST_INVENTORY_DISABLED, HOST_INVENTORY_MANUAL, HOST_INVENTORY_AUTOMATIC]),
			'status' => 'in '.implode(',', [HOST_STATUS_MONITORED, HOST_STATUS_NOT_MONITORED]),
			'tls_connect' => 'in '.implode(',', [HOST_ENCRYPTION_NONE, HOST_ENCRYPTION_PSK, HOST_ENCRYPTION_CERTIFICATE]),
			'tls_accept' => 'ge 0|le '.(HOST_ENCRYPTION_NONE | HOST_ENCRYPTION_PSK | HOST_ENCRYPTION_CERTIFICATE),
			'ipmi_authtype' => 'in '.implode(',', [IPMI_AUTHTYPE_DEFAULT, IPMI_AUTHTYPE_NONE, IPMI_AUTHTYPE_MD2, IPMI_AUTHTYPE_MD5, IPMI_AUTHTYPE_STRAIGHT, IPMI_AUTHTYPE_OEM, IPMI_AUTHTYPE_RMCP_PLUS]),
			'ipmi_privilege' => 'in '.implode(',', [IPMI_PRIVILEGE_CALLBACK, IPMI_PRIVILEGE_USER, IPMI_PRIVILEGE_OPERATOR, IPMI_PRIVILEGE_ADMIN, IPMI_PRIVILEGE_OEM])
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$output = [];
			if (($messages = getMessages()) !== null) {
				$output['errors'] = $messages->toString();
			}

			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode($output)]))->disableView()
			);
		}

		return $ret;
	}

	protected function checkPermissions() {
		$hosts = API::Host()->get([
			'output' => [],
			'hostids' => $this->getInput('ids', []),
			'editable' => true
		]);

		if (!$hosts) {
			return false;
		}

		return true;
	}

	protected function doAction() {
		if ($this->hasInput('update')) {
			$output = [];
			$hostids = $this->getInput('ids', []);
			$visible = $this->getInput('visible', []);
			$macros = array_filter(cleanInheritedMacros($this->getInput('macros', [])),
				function (array $macro): bool {
					return (bool) array_filter(
						array_intersect_key($macro, array_flip(['hostmacroid', 'macro', 'value', 'description']))
					);
				}
			);
			$tags = array_filter($this->getInput('tags', []),
				function (array $tag): bool {
					return ($tag['tag'] !== '' || $tag['value'] !== '');
				}
			);

			$result = true;

			try {
				DBstart();

				// filter only normal and discovery created hosts
				$options = [
					'output' => ['hostid', 'inventory_mode'],
					'hostids' => $hostids,
					'filter' => ['flags' => [ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED]]
				];

				if (array_key_exists('groups', $visible)) {
					$options['selectGroups'] = ['groupid'];
				}

				if (array_key_exists('templates', $visible)
						&& !($this->getInput('mass_action_tpls') == ZBX_ACTION_REPLACE
							&& !$this->hasInput('mass_clear_tpls'))) {
					$options['selectParentTemplates'] = ['templateid'];
				}

				if (array_key_exists('tags', $visible)) {
					$mass_update_tags = $this->getInput('mass_update_tags', ZBX_ACTION_ADD);

					if ($mass_update_tags == ZBX_ACTION_ADD || $mass_update_tags == ZBX_ACTION_REMOVE) {
						$options['selectTags'] = ['tag', 'value'];
					}

					$unique_tags = [];

					foreach ($tags as $tag) {
						$unique_tags[$tag['tag'].':'.$tag['value']] = $tag;
					}

					$tags = array_values($unique_tags);
				}

				if (array_key_exists('macros', $visible)) {
					$mass_update_macros = $this->getInput('mass_update_macros', ZBX_ACTION_ADD);

					if ($mass_update_macros == ZBX_ACTION_ADD || $mass_update_macros == ZBX_ACTION_REPLACE
							|| $mass_update_macros == ZBX_ACTION_REMOVE) {
						$options['selectMacros'] = ['hostmacroid', 'macro'];
					}
				}

				$hosts = API::Host()->get($options);

				if (array_key_exists('groups', $visible)) {
					$new_groupids = [];
					$remove_groupids = [];
					$mass_update_groups = $this->getInput('mass_update_groups', ZBX_ACTION_ADD);

					if ($mass_update_groups == ZBX_ACTION_ADD || $mass_update_groups == ZBX_ACTION_REPLACE) {
						if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
							$ins_groups = [];

							foreach ($this->getInput('groups', []) as $new_group) {
								if (is_array($new_group) && array_key_exists('new', $new_group)) {
									$ins_groups[] = ['name' => $new_group['new']];
								}
								else {
									$new_groupids[] = $new_group;
								}
							}

							if ($ins_groups) {
								if (!$result = API::HostGroup()->create($ins_groups)) {
									throw new Exception();
								}

								$new_groupids = array_merge($new_groupids, $result['groupids']);
							}
						}
						else {
							$new_groupids = $this->getInput('groups', []);
						}
					}
					elseif ($mass_update_groups == ZBX_ACTION_REMOVE) {
						$remove_groupids = $this->getInput('groups', []);
					}
				}

				$properties = ['description', 'proxy_hostid', 'ipmi_authtype', 'ipmi_privilege', 'ipmi_username',
					'ipmi_password'
				];

				$new_values = [];
				foreach ($properties as $property) {
					if (array_key_exists($property, $visible)) {
						$new_values[$property] = $this->getInput($property);
					}
				}

				if (array_key_exists('status', $visible)) {
					$new_values['status'] = $this->getInput('status', HOST_STATUS_NOT_MONITORED);
				}

				$host_inventory = array_intersect_key($this->getInput('host_inventory', []), $visible);

				if (array_key_exists('inventory_mode', $visible)) {
					$new_values['inventory_mode'] = $this->getInput('inventory_mode', HOST_INVENTORY_DISABLED);

					if ($new_values['inventory_mode'] == HOST_INVENTORY_DISABLED) {
						$host_inventory = [];
					}
				}

				if (array_key_exists('encryption', $visible)) {
					$new_values['tls_connect'] = $this->getInput('tls_connect', HOST_ENCRYPTION_NONE);
					$new_values['tls_accept'] = $this->getInput('tls_accept', HOST_ENCRYPTION_NONE);

					if ($new_values['tls_connect'] == HOST_ENCRYPTION_PSK
							|| ($new_values['tls_accept'] & HOST_ENCRYPTION_PSK)) {
						$new_values['tls_psk_identity'] = $this->getInput('tls_psk_identity', '');
						$new_values['tls_psk'] = $this->getInput('tls_psk', '');
					}

					if ($new_values['tls_connect'] == HOST_ENCRYPTION_CERTIFICATE
							|| ($new_values['tls_accept'] & HOST_ENCRYPTION_CERTIFICATE)) {
						$new_values['tls_issuer'] = $this->getInput('tls_issuer', '');
						$new_values['tls_subject'] = $this->getInput('tls_subject', '');
					}
				}

				$host_macros_add = [];
				$host_macros_update = [];
				$host_macros_remove = [];
				foreach ($hosts as &$host) {
					if (array_key_exists('groups', $visible)) {
						if ($new_groupids && $mass_update_groups == ZBX_ACTION_ADD) {
							$current_groupids = array_column($host['groups'], 'groupid');
							$host['groups'] = zbx_toObject(array_unique(array_merge($current_groupids, $new_groupids)),
								'groupid'
							);
						}
						elseif ($new_groupids && $mass_update_groups == ZBX_ACTION_REPLACE) {
							$host['groups'] = zbx_toObject($new_groupids, 'groupid');
						}
						elseif ($remove_groupids) {
							$current_groupids = array_column($host['groups'], 'groupid');
							$host['groups'] = zbx_toObject(array_diff($current_groupids, $remove_groupids), 'groupid');
						}
					}

					if (array_key_exists('templates', $visible)) {
						$host_templateids = array_key_exists('parentTemplates', $host)
							? array_column($host['parentTemplates'], 'templateid')
							: [];

						switch ($this->getInput('mass_action_tpls')) {
							case ZBX_ACTION_ADD:
								$host['templates'] = array_unique(
									array_merge($host_templateids, $this->getInput('templates', []))
								);
								break;

							case ZBX_ACTION_REPLACE:
								$host['templates'] = $this->getInput('templates', []);
								if ($this->hasInput('mass_clear_tpls')) {
									$host['templates_clear'] = array_unique(
										array_diff($host_templateids, $this->getInput('templates', []))
									);
								}
								break;

							case ZBX_ACTION_REMOVE:
								$host['templates'] = array_unique(
									array_diff($host_templateids, $this->getInput('templates', []))
								);
								if ($this->hasInput('mass_clear_tpls')) {
									$host['templates_clear'] = array_unique($this->getInput('templates', []));
								}
								break;
						}
					}

					if (array_key_exists('inventory_mode', $new_values)) {
						$host['inventory'] = $host_inventory;
					}
					elseif ($host['inventory_mode'] != HOST_INVENTORY_DISABLED) {
						$host['inventory'] = $host_inventory;
					}
					else {
						$host['inventory'] = [];
					}

					if (array_key_exists('tags', $visible)) {
						if ($tags && $mass_update_tags == ZBX_ACTION_ADD) {
							$unique_tags = [];

							foreach (array_merge($host['tags'], $tags) as $tag) {
								$unique_tags[$tag['tag'].':'.$tag['value']] = $tag;
							}

							$host['tags'] = array_values($unique_tags);
						}
						elseif ($mass_update_tags == ZBX_ACTION_REPLACE) {
							$host['tags'] = $tags;
						}
						elseif ($tags && $mass_update_tags == ZBX_ACTION_REMOVE) {
							$diff_tags = [];

							foreach ($host['tags'] as $a) {
								foreach ($tags as $b) {
									if ($a['tag'] === $b['tag'] && $a['value'] === $b['value']) {
										continue 2;
									}
								}

								$diff_tags[] = $a;
							}

							$host['tags'] = $diff_tags;
						}
					}

					if (array_key_exists('macros', $visible)) {
						switch ($mass_update_macros) {
							case ZBX_ACTION_ADD:
								if ($macros) {
									$update_existing = $this->getInput('macros_add', 0);

									foreach ($macros as $macro) {
										foreach ($host['macros'] as $host_macro) {
											if ($macro['macro'] === $host_macro['macro']) {
												if ($update_existing) {
													$macro['hostmacroid'] = $host_macro['hostmacroid'];
													$host_macros_update[] = $macro;
												}

												continue 2;
											}
										}

										$macro['hostid'] = $host['hostid'];
										$host_macros_add[] = $macro;
									}
								}
								break;

							case ZBX_ACTION_REPLACE: // In Macros its update.
								if ($macros) {
									$add_missing = $this->getInput('macros_update', 0);

									foreach ($macros as $macro) {
										foreach ($host['macros'] as $host_macro) {
											if ($macro['macro'] === $host_macro['macro']) {
												$macro['hostmacroid'] = $host_macro['hostmacroid'];
												$host_macros_update[] = $macro;

												continue 2;
											}
										}

										if ($add_missing) {
											$macro['hostid'] = $host['hostid'];
											$host_macros_add[] = $macro;
										}
									}
								}
								break;

							case ZBX_ACTION_REMOVE:
								if ($macros) {
									$except_selected = $this->getInput('macros_remove', 0);

									$macro_names = array_column($macros, 'macro');

									foreach ($host['macros'] as $host_macro) {
										if ((!$except_selected && in_array($host_macro['macro'], $macro_names))
												|| ($except_selected && !in_array($host_macro['macro'], $macro_names))) {
											$host_macros_remove[] = $host_macro['hostmacroid'];
										}
									}
								}
								break;

							case ZBX_ACTION_REMOVE_ALL:
								if (!$this->getInput('macros_remove_all', 0)) {
									throw new Exception();
								}

								$host['macros'] = [];
								break;
						}

						if ($mass_update_macros != ZBX_ACTION_REMOVE_ALL) {
							unset($host['macros']);
						}
					}

					unset($host['parentTemplates']);

					$host = $new_values + $host;
				}
				unset($host);

				if (!API::Host()->update($hosts)) {
					throw new Exception();
				}

				/**
				 * Macros must be updated separately, since calling API::UserMacro->replaceMacros() inside
				 * API::Host->update() results in loss of secret macro values.
				 */
				if ($host_macros_remove) {
					if (!API::UserMacro()->delete($host_macros_remove)) {
						throw new Exception();
					}
				}

				if ($host_macros_add) {
					if (!API::UserMacro()->create($host_macros_add)) {
						throw new Exception();
					}
				}

				if ($host_macros_update) {
					if (!API::UserMacro()->update($host_macros_update)) {
						throw new Exception();
					}
				}

				DBend(true);
			}
			catch (Exception $e) {
				DBend(false);

				CMessageHelper::setErrorTitle(_('Cannot update hosts'));

				$result = false;
			}

			if ($result) {
				$messages = CMessageHelper::getMessages();
				$output = ['title' => _('Hosts updated')];
				if (count($messages)) {
					$output['messages'] = array_column($messages, 'message');
				}
			}
			else {
				$output['errors'] = makeMessageBox(false, filter_messages(), CMessageHelper::getTitle())->toString();
			}

			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode($output)]))->disableView()
			);
		}
		else {
			$data = [
				'title' => _('Mass update'),
				'user' => [
					'debug_mode' => $this->getDebugMode()
				],
				'ids' => $this->getInput('ids', []),
				'inventories' => zbx_toHash(getHostInventories(), 'db_field'),
				'location_url' => 'hosts.php'
			];

			$data['proxies'] = API::Proxy()->get([
				'output' => ['hostid', 'host'],
				'filter' => [
					'status' => [HOST_STATUS_PROXY_ACTIVE, HOST_STATUS_PROXY_PASSIVE]
				],
				'sortfield' => 'host'
			]);

			$this->setResponse(new CControllerResponseData($data));
		}
	}
}
