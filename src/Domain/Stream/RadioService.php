<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\ImageUtils;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * RadioService — radio service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RadioService {
	use DatabaseAware;

	/**
	 * Create or update a radio stream from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();

		if (!InputValidator::validate('processRadio', $rData)) {
			return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
		}

		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'edit_radio')) {
				exit();
			}
			$rArray = AdminHelpers::overwriteData(StreamRepository::getById($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'add_radio')) {
				exit();
			}
			$rArray = QueryHelper::verifyPostTable('streams', $rData);
			$rArray['type'] = 4;
			$rArray['added'] = time();
			unset($rArray['id']);
		}

		$rArray['auto_restart'] = self::buildAutoRestart($rData);
		$rArray['direct_source'] = isset($rData['direct_source']) ? 1 : 0;
		$rArray['probesize_ondemand'] = isset($rData['probesize_ondemand']) ? intval($rData['probesize_ondemand']) : 128000;
		$rRestart = isset($rData['restart_on_edit']);

		if (0 >= strlen($rData['stream_source'][0])) {
			return ['status' => STATUS_NO_SOURCES, 'data' => $rData];
		}

		$rBouquetCreate = self::createMissingBouquets($db, $rData);
		$rCategoryCreate = self::createMissingCategories($db, $rData);
		$rBouquets = self::resolveSelectedIds($rData['bouquets'], $rBouquetCreate);
		$rCategories = self::resolveSelectedIds($rData['category_id'] ?? [], $rCategoryCreate);

		$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
		$rArray['stream_source'] = $rData['stream_source'];
		if (SettingsManager::get('download_images')) {
			$rArray['stream_icon'] = ImageUtils::downloadImage($rArray['stream_icon'], 4);
		}
		if (!isset($rData['edit'])) {
			$rArray['order'] = StreamRepository::getNextOrder();
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			// Insert failed — roll back the bouquets/categories created above.
			foreach ($rBouquetCreate as $rID) {
				$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
			}
			foreach ($rCategoryCreate as $rID) {
				$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
			}

			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		$rInsertID = $db->last_insert_id();

		$rStationExists = [];
		if (isset($rData['edit'])) {
			$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);
			foreach ($db->get_rows() as $rRow) {
				$rStationExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
			}
		}

		self::syncServerTree($db, $rInsertID, json_decode($rData['server_tree_data'], true), $rData['on_demand'] ?? [], $rStationExists);
		self::saveStreamOptions($db, $rInsertID, $rData);

		if ($rRestart) {
			ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => [$rInsertID]]);
		}

		self::syncBouquets($rInsertID, $rBouquets, isset($rData['edit']));
		StreamProcess::updateStream($rInsertID);

		return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
	}

	/**
	 * Build the auto_restart schedule from the form's days/time fields.
	 *
	 * @param array $rData Submitted form data.
	 * @return array|string ['days' => string[], 'at' => 'HH:MM'] for a valid
	 *                      schedule, otherwise '' (no schedule).
	 */
	private static function buildAutoRestart(array $rData): array|string {
		if (!isset($rData['days_to_restart']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', (string) ($rData['time_to_restart'] ?? ''))) {
			return '';
		}

		return ['days' => array_values($rData['days_to_restart']), 'at' => $rData['time_to_restart']];
	}

	/**
	 * Resolve submitted selections (bouquets or categories) to integer ids.
	 *
	 * A value is either the name of an entity created earlier in this request
	 * (mapped via $rCreatedMap) or an existing numeric id; anything else is
	 * skipped.
	 *
	 * @param array<int,mixed>  $rSelected   Submitted values.
	 * @param array<string,int> $rCreatedMap name => new id for entities created this request.
	 * @return int[]
	 */
	private static function resolveSelectedIds(array $rSelected, array $rCreatedMap): array {
		$rIds = [];
		foreach ($rSelected as $rValue) {
			if (isset($rCreatedMap[$rValue])) {
				$rIds[] = $rCreatedMap[$rValue];
			} elseif (is_numeric($rValue)) {
				$rIds[] = intval($rValue);
			}
		}

		return $rIds;
	}

	/**
	 * Insert any bouquets named in bouquet_create_list.
	 *
	 * @param object $db    Database handler.
	 * @param array  $rData Submitted form data.
	 * @return array<string,int> Created bouquet name => new id.
	 */
	private static function createMissingBouquets(object $db, array $rData): array {
		$rCreated = [];
		foreach (json_decode($rData['bouquet_create_list'] ?? '[]', true) ?: [] as $rBouquet) {
			$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
			$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rCreated[$rBouquet] = $db->last_insert_id();
			}
		}

		return $rCreated;
	}

	/**
	 * Insert any radio categories named in category_create_list.
	 *
	 * @param object $db    Database handler.
	 * @param array  $rData Submitted form data.
	 * @return array<string,int> Created category name => new id.
	 */
	private static function createMissingCategories(object $db, array $rData): array {
		$rCreated = [];
		foreach (json_decode($rData['category_create_list'] ?? '[]', true) ?: [] as $rCategory) {
			$rPrepare = QueryHelper::prepareArray(['category_type' => 'radio', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
			$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rCreated[$rCategory] = $db->last_insert_id();
			}
		}

		return $rCreated;
	}

	/**
	 * Replace a stream's ffmpeg option rows from the submitted form fields.
	 *
	 * Clears existing streams_options for the stream, then re-inserts the ones
	 * present in the form (user_agent, http_proxy, cookie, headers, skip_ffprobe,
	 * force_input_acodec).
	 *
	 * @param object     $db        Database handler.
	 * @param int|string $rInsertID Stream id.
	 * @param array      $rData     Submitted form data.
	 */
	private static function saveStreamOptions(object $db, int|string $rInsertID, array $rData): void {
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rInsertID);

		if (isset($rData['user_agent']) && 0 < strlen($rData['user_agent'])) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 1, ?);', $rInsertID, $rData['user_agent']);
		}
		if (isset($rData['http_proxy']) && 0 < strlen($rData['http_proxy'])) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 2, ?);', $rInsertID, $rData['http_proxy']);
		}
		if (isset($rData['cookie']) && 0 < strlen($rData['cookie'])) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 17, ?);', $rInsertID, $rData['cookie']);
		}
		if (isset($rData['headers']) && 0 < strlen($rData['headers'])) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 19, ?);', $rInsertID, $rData['headers']);
		}
		if (isset($rData['skip_ffprobe']) && ($rData['skip_ffprobe'] == 'on' || $rData['skip_ffprobe'] == 1)) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 21, ?);', $rInsertID, '1');
		}
		if (isset($rData['force_input_acodec']) && strlen(trim($rData['force_input_acodec'])) > 0) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 20, ?);', $rInsertID, trim($rData['force_input_acodec']));
		}
	}

	/**
	 * Reconcile a stream's server rows against the submitted server tree.
	 *
	 * Inserts/updates streams_servers for every node in the tree (skipping the
	 * '#' root), then removes any previously-attached server no longer present.
	 *
	 * @param object         $db             Database handler.
	 * @param int|string     $rInsertID      Stream id.
	 * @param array          $rServerTree    Decoded server_tree_data.
	 * @param array          $rOnDemand      Server ids flagged on-demand.
	 * @param array<int,int> $rStationExists Existing server_id => server_stream_id.
	 */
	private static function syncServerTree(object $db, int|string $rInsertID, array $rServerTree, array $rOnDemand, array $rStationExists): void {
		$rStreamsAdded = [];
		foreach ($rServerTree as $rServer) {
			if ($rServer['parent'] == '#') {
				continue;
			}
			$rServerID = intval($rServer['id']);
			$rStreamsAdded[] = $rServerID;
			$rOD = intval(in_array($rServerID, $rOnDemand));
			$rParent = ($rServer['parent'] == 'source') ? null : intval($rServer['parent']);

			if (isset($rStationExists[$rServerID])) {
				$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStationExists[$rServerID]);
			} else {
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
			}
		}

		foreach ($rStationExists as $rServerID => $rDBID) {
			if (!in_array($rServerID, $rStreamsAdded)) {
				StreamRepository::deleteStream($rInsertID, $rServerID, false, false);
			}
		}
	}

	/**
	 * Attach the stream to the selected bouquets, and — on edit — detach it from
	 * any bouquet no longer selected.
	 *
	 * @param int|string $rInsertID Stream id.
	 * @param int[]      $rBouquets Selected bouquet ids.
	 * @param bool       $rIsEdit   Whether this is an edit (enables detach).
	 */
	private static function syncBouquets(int|string $rInsertID, array $rBouquets, bool $rIsEdit): void {
		foreach ($rBouquets as $rBouquet) {
			BouquetService::addItems('radio', $rBouquet, $rInsertID);
		}

		if (!$rIsEdit) {
			return;
		}

		foreach (BouquetService::getAllSimple() as $rBouquet) {
			if (!in_array($rBouquet['id'], $rBouquets)) {
				BouquetService::removeItems('radio', $rBouquet['id'], $rInsertID);
			}
		}
	}

	/**
	 * Apply bulk edits to a set of selected radio streams.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (InputValidator::validate('massEditRadios', $rData)) {
			$rArray = [];

			if (!isset($rData['c_direct_source'])) {
			} else {
				if (isset($rData['direct_source'])) {
					$rArray['direct_source'] = 1;
				} else {
					$rArray['direct_source'] = 0;
				}
			}

			if (!isset($rData['c_custom_sid'])) {
			} else {
				$rArray['custom_sid'] = $rData['custom_sid'];
			}

			$rStreamIDs = json_decode($rData['streams'], true);

			if (0 >= count($rStreamIDs)) {
			} else {
				$rCategoryMap = [];

				if (!(isset($rData['c_category_id']) && in_array($rData['category_id_type'], ['ADD', 'DEL']))) {
				} else {
					$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: []);
					}
				}

				$rDeleteServers = $rStreamExists = [];
				$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				}
				$rBouquets = BouquetService::getAllSimple();
				$rAddBouquet = $rDelBouquet = [];
				$rAddQuery = '';

				foreach ($rStreamIDs as $rStreamID) {
					if (!isset($rData['c_category_id'])) {
					} else {
						$rCategories = array_map('intval', $rData['category_id']);

						if ($rData['category_id_type'] == 'ADD') {
							foreach (($rCategoryMap[$rStreamID] ?: []) as $rCategoryID) {
								if (in_array($rCategoryID, $rCategories)) {
								} else {
									$rCategories[] = $rCategoryID;
								}
							}
						} else {
							if ($rData['category_id_type'] != 'DEL') {
							} else {
								$rNewCategories = $rCategoryMap[$rStreamID];

								foreach ($rCategories as $rCategoryID) {
									if (($rKey = array_search($rCategoryID, $rNewCategories)) === false) {
									} else {
										unset($rNewCategories[$rKey]);
									}
								}
								$rCategories = $rNewCategories;
							}
						}

						$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
					}

					$rPrepare = QueryHelper::prepareArray($rArray);

					if (0 >= count($rPrepare['data'])) {
					} else {
						$rPrepare['data'][] = $rStreamID;
						$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
						$db->query($rQuery, ...$rPrepare['data']);
					}

					if (!isset($rData['c_server_tree'])) {
					} else {
						$rStreamsAdded = [];
						$rServerTree = json_decode($rData['server_tree_data'], true);

						foreach ($rServerTree as $rServer) {
							if ($rServer['parent'] == '#') {
							} else {
								$rServerID = intval($rServer['id']);

								if (in_array($rData['server_type'], ['ADD', 'SET'])) {
									$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: [])));

									if ($rServer['parent'] == 'source') {
										$rParent = null;
									} else {
										$rParent = intval($rServer['parent']);
									}

									$rStreamsAdded[] = $rServerID;

									if (isset($rStreamExists[$rStreamID][$rServerID])) {
										$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rStreamID][$rServerID]);
									} else {
										$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . (($rParent ?: 'NULL')) . ', ' . $rOD . '),';
									}
								} else {
									if (!isset($rStreamExists[$rStreamID][$rServerID])) {
									} else {
										$rDeleteServers[$rServerID][] = $rStreamID;
									}
								}
							}
						}

						if ($rData['server_type'] != 'SET') {
						} else {
							foreach ($rStreamExists[$rStreamID] as $rServerID => $rDBID) {
								if (in_array($rServerID, $rStreamsAdded)) {
								} else {
									$rDeleteServers[$rServerID][] = $rStreamID;
								}
							}
						}
					}

					if (!isset($rData['c_bouquets'])) {
					} else {
						if ($rData['bouquets_type'] == 'SET') {
							foreach ($rData['bouquets'] as $rBouquet) {
								$rAddBouquet[$rBouquet][] = $rStreamID;
							}

							foreach ($rBouquets as $rBouquet) {
								if (in_array($rBouquet['id'], $rData['bouquets'])) {
								} else {
									$rDelBouquet[$rBouquet['id']][] = $rStreamID;
								}
							}
						} else {
							if ($rData['bouquets_type'] == 'ADD') {
								foreach ($rData['bouquets'] as $rBouquet) {
									$rAddBouquet[$rBouquet][] = $rStreamID;
								}
							} else {
								if ($rData['bouquets_type'] != 'DEL') {
								} else {
									foreach ($rData['bouquets'] as $rBouquet) {
										$rDelBouquet[$rBouquet][] = $rStreamID;
									}
								}
							}
						}
					}
				}

				foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
					StreamRepository::deleteStreamsByServer($rDeleteIDs, $rServerID, false);
				}

				foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
					BouquetService::addItems('radio', $rBouquetID, $rAddIDs);
				}

				foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
					BouquetService::removeItems('radio', $rBouquetID, $rRemIDs);
				}

				if (empty($rAddQuery)) {
				} else {
					$rAddQuery = rtrim($rAddQuery, ',');
					$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}

				StreamProcess::updateStreams($rStreamIDs);

				if (!isset($rData['restart_on_edit'])) {
				} else {
					ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)]);
				}
			}

			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Bulk delete a set of selected radio streams.
	 *
	 * @param array $rData Selected radio ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		if (InputValidator::validate('massDeleteStations', $rData)) {
			$rStreams = json_decode($rData['radios'], true);
			StreamRepository::deleteStreams($rStreams, false);
			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}
}
