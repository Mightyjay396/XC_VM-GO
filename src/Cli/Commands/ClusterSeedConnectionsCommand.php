<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * ClusterSeedConnectionsCommand — load this node's viewers from MAIN's store
 * into its agent's registry (cluster plan, Phase 6, "The CONNECTIONS switch
 * on a live node"). Run on the node while it still reaches MAIN's store,
 * before the CONNECTIONS flow is switched on: the agent then holds the viewers
 * already open, and its first heartbeat digest agrees with MAIN's, so no
 * snapshot is needed. Loading sends no event.
 *
 * Only the registry record's keys are sent (AgentConnections::RECORD_KEYS),
 * never the line's other columns.
 *
 * Usage: `console.php cluster:seed-connections`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterSeedConnectionsCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:seed-connections';
	}

	public function getDescription(): string {
		return "Load this node's viewers from MAIN's store into its agent (before the CONNECTIONS switch)";
	}

	public function execute(array $rArgs): int {
		if (!defined('SERVER_ID')) {
			echo "SERVER_ID is not defined. Exiting\n";
			return 1;
		}
		try {
			$rRecords = self::stored((int) SERVER_ID);
		} catch (\Throwable $rE) {
			echo "Cannot read MAIN's store: " . $rE->getMessage() . "\n";
			return 1;
		}
		$rSeeded = AgentConnections::seed($rRecords);
		if ($rSeeded === null) {
			echo "The agent did not answer (config/cluster/agent.sock). Nothing seeded\n";
			return 1;
		}
		echo 'OK: ' . $rSeeded . ' of ' . count($rRecords) . " connections loaded into the agent's registry\n";
		return 0;
	}

	/**
	 * This node's connections in MAIN's store, as registry records.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function stored(int $rServerID): array {
		$rOut = [];
		if (SettingsManager::get('redis_handler')) {
			$rRedis = RedisManager::instance();
			$rKeys = $rRedis instanceof \Redis ? $rRedis->zRangeByScore('SERVER#' . $rServerID, '-inf', '+inf') : false;
			if (!is_array($rKeys)) {
				throw new \RuntimeException('redis unavailable');
			}
			foreach (array_chunk($rKeys, 1000) as $rChunk) {
				$rData = $rRedis->mGet($rChunk);
				foreach (is_array($rData) ? $rData : [] as $rRaw) {
					$rRecord = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
					if (is_array($rRecord) && isset($rRecord['uuid']) && (int) ($rRecord['server_id'] ?? 0) === $rServerID) {
						$rOut[] = array_intersect_key($rRecord, array_flip(AgentConnections::RECORD_KEYS));
					}
				}
			}
			return $rOut;
		}
		$rDb = DatabaseFactory::get();
		if ($rDb === null) {
			throw new \RuntimeException('no database');
		}
		$rColumns = array_diff(AgentConnections::RECORD_KEYS, ['identity', 'on_demand']);
		$rDb->query('SELECT `' . implode('`, `', $rColumns) . '` FROM `lines_live` WHERE `server_id` = ? AND `uuid` IS NOT NULL;', $rServerID);
		foreach ($rDb->get_rows() as $rRow) {
			$rRow['identity'] = !empty($rRow['user_id']) ? $rRow['user_id'] : $rRow['hmac_id'] . '_' . ($rRow['hmac_identifier'] ?? '');
			$rOut[] = $rRow;
		}
		return $rOut;
	}
}
