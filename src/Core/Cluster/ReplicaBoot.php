<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;

/**
 * Booting from the node replica instead of MAIN's database (plan, section
 * 10, step 2; Core\Bootstrap\Stage\ReplicaStage). Lives in Core: it ships to
 * LBs, where Domain\Cluster does not.
 *
 * - A node in mode 2 (api) boots its CLI processes and web API endpoints
 *   from the caches its replica built (ReplicaApply), once an apply has
 *   built the settings and servers caches since the last reboot. Until then
 *   it falls back to the boot through MAIN's database, whose connect mode 2
 *   refuses: the process ends at its boot (fails closed).
 * - `cluster:apply` always boots from the replica, whatever the mode: its
 *   work is to build those caches, at boot too (`service` runs it with
 *   `--from-disk` before the daemons), when MAIN may be unreachable.
 * - Nodes in mode 0 or 1, and MAIN, boot as before.
 *
 * Once a process has booted from the replica (start()), its settings and
 * servers never come from MAIN's database: SettingsRepository and
 * ServerRepository answer the caches however old, or nothing. Any other
 * query opens MAIN's database, lazily, on first use: that is the connect
 * ConnectAudit counts, and on a node in mode 2 refuses (plan, section 10,
 * step 1).
 */
final class ReplicaBoot {
	/** Commands that boot from the replica in every mode. */
	public const COMMANDS = ['cluster:apply'];

	/** The boot option: boot from the replica even before an apply built its caches (cluster:apply). */
	public const ALWAYS = 'always';

	/** The boot option: boot from the replica once an apply built its caches, else as before (mode 2). */
	public const WHEN_READY = 'ready';

	private static bool $rActive = false;

	/**
	 * Does this node boot from its replica? Mode 2, as the agent's
	 * flows.json says, for a node MAIN counts as active or quarantined. Read
	 * from the file alone, before the settings, the servers or a database
	 * handle exist: MAIN runs no agent, so it has no such file.
	 */
	public static function wanted(): bool {
		$rFlows = NodeFlows::declared();
		return $rFlows['mode'] === 2 && in_array($rFlows['state'], ['active', 'quarantined'], true);
	}

	/**
	 * Has an apply built the settings and servers caches the replica owns
	 * since the last reboot (`replica_owned`, beside the caches in tmp/)?
	 */
	public static function ready(): bool {
		return ReplicaApply::built(ReplicaSections::SETTINGS) && ReplicaApply::built(ReplicaSections::SERVERS);
	}

	/**
	 * The boot option for a console command line: ALWAYS for the commands
	 * that boot from the replica in every mode, else null (the node's mode
	 * decides).
	 *
	 * @param list<string> $rArgv
	 */
	public static function forArgv(array $rArgv): ?string {
		return in_array($rArgv[1] ?? null, self::COMMANDS, true) ? self::ALWAYS : null;
	}

	/** This process booted from the replica (ReplicaStage). */
	public static function start(): void {
		self::$rActive = true;
	}

	/** Did this process boot from the replica? Its settings and servers then never come from MAIN's database. */
	public static function active(): bool {
		return self::$rActive;
	}

	/** Tests: forget the boot. */
	public static function reset(): void {
		self::$rActive = false;
	}

	/**
	 * A cache as the node holds it, however old, or [] without one: what a
	 * process booted from the replica reads where it would have read MAIN's
	 * database (the bouquets and categories, which no section carries yet).
	 *
	 * @return array<mixed>
	 */
	public static function cached(string $rKey): array {
		$rCache = FileCache::getCache($rKey);
		return is_array($rCache) ? $rCache : [];
	}
}
