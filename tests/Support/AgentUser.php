<?php

namespace XcVm\Tests\Support;

/**
 * The node's audits (SettingsAudit, ConnectAudit) do their file work as the
 * owner of the agent's directory when they run as root, and nothing when
 * that owner is root (SettingsAudit::asAgentUser). A suite run as root hands
 * a test's throwaway tree to nobody (65534), standing in for xc_vm, as a
 * node's tree is xc_vm's. Run as anyone else, it changes nothing.
 */
final class AgentUser {
	public const UID = 65534;

	public static function root(): bool {
		return function_exists('posix_geteuid') && posix_geteuid() === 0;
	}

	/** Hand each path, and everything below it, to nobody when this is root. */
	public static function own(string ...$rPaths): void {
		if (!self::root()) {
			return;
		}
		foreach ($rPaths as $rPath) {
			exec('chown -R ' . self::UID . ':' . self::UID . ' ' . escapeshellarg(rtrim($rPath, '/')));
		}
	}
}
