<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\OpensslExtra;
use XcVm\Domain\Cluster\ClusterRoute;

/**
 * Node Actions
 *
 * Privileged work MAIN asks a node's root side to do: reboot, restart or
 * update the services, apply ports, sysctl or governor settings, renew
 * certificates, install or remove modules, flush the IP blocklist. The
 * node's root cron (RootSignalsCronJob) executes them.
 *
 * Callers queued the raw JSON through SignalDispatcher::rootAction() (a
 * `signals` row), which is still the only transport. In API mode (Phase 4)
 * each becomes a signed `node.root{action}` command for `cluster:root`.
 * ROOT_ACTIONS is the catalogue RootSignalsCronJob handles, and a payload
 * outside it is refused rather than queued to be ignored.
 */
final class NodeActions {
	/** Every action RootSignalsCronJob executes. */
	public const ROOT_ACTIONS = [
		'reboot', 'restart_services', 'stop_services', 'reload_nginx',
		'disable_ramdisk', 'enable_ramdisk', 'certbot_generate', 'update_binaries',
		'install_module', 'delete_module', 'update', 'rollback',
		'set_services', 'set_governor', 'set_sysctl', 'set_port', 'flush',
		OpensslExtra::SIGNAL_ACTION,
	];

	public static function reboot(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'reboot'], $rDb);
	}

	public static function restartServices(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'restart_services'], $rDb);
	}

	public static function reloadNginx(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'reload_nginx'], $rDb);
	}

	public static function update(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'update'], $rDb);
	}

	public static function rollback(int $rServerID, string $rVersion, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'rollback', 'version' => $rVersion], $rDb);
	}

	public static function updateBinaries(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'update_binaries'], $rDb);
	}

	public static function setRamdisk(int $rServerID, bool $rEnabled, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => $rEnabled ? 'enable_ramdisk' : 'disable_ramdisk'], $rDb);
	}

	/** @param mixed $rPorts As ServerService builds it for the port type. */
	public static function setPorts(int $rServerID, int $rType, mixed $rPorts, mixed $rReload, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_port', 'type' => $rType, 'ports' => $rPorts, 'reload' => $rReload], $rDb);
	}

	public static function setServices(int $rServerID, int $rCount, mixed $rReload, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_services', 'count' => $rCount, 'reload' => $rReload], $rDb);
	}

	public static function setGovernor(int $rServerID, mixed $rGovernor, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_governor', 'data' => $rGovernor], $rDb);
	}

	public static function setSysctl(int $rServerID, mixed $rSysCtl, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_sysctl', 'data' => $rSysCtl], $rDb);
	}

	/** Clear the node's firewall blocklist; RootSignalsCronJob matches this exact payload. */
	public static function flushBlocklist(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'flush'], $rDb);
	}

	/** @param list<string> $rDomains */
	public static function certbot(int $rServerID, array $rDomains, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'certbot_generate', 'domain' => array_values($rDomains)], $rDb);
	}

	/**
	 * Queue any catalogued action, for callers that build the payload
	 * themselves (module install/delete, the OPENSSL_EXTRA sync).
	 *
	 * @param array<string, mixed>|string $rPayload Array or its JSON.
	 */
	public static function send(int $rServerID, array|string $rPayload, ?object $rDb = null): bool {
		$rData = is_string($rPayload) ? json_decode($rPayload, true) : $rPayload;
		$rAction = is_array($rData) ? ($rData['action'] ?? null) : null;
		if (!is_string($rAction) || !in_array($rAction, self::ROOT_ACTIONS, true)) {
			throw new \InvalidArgumentException('Unknown node root action: ' . (is_string($rAction) ? $rAction : 'none'));
		}
		// A node with the COMMANDS flow and its root pin gets a signed node.root (MAIN only).
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::root($rServerID, $rData);
			if ($rRouted) {
				return $rQueued;
			}
		}
		return SignalDispatcher::rootAction($rServerID, $rPayload, $rDb);
	}
}
