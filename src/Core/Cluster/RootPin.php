<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\PanelSig;

/**
 * The node's root-owned trust anchor for root commands (plan, section 7,
 * "Root handoff"), and the hand-off between the agent's side (xc_vm) and
 * cluster:root (root).
 *
 * ```text
 * /etc/xc_vm/cluster/            root:root, not group/other writable
 *   main_sign.pub                the panel signing key, hex
 *   node                         this node's uuid
 *   root.seq                     the highest command seq root has run
 * config/cluster/root-inbox/     xc_vm: <seq>.json in (command + sig), <seq>.done out
 * ```
 *
 * The agent's own copy of the panel key lives in files xc_vm can write, so a
 * compromised panel process could plant a key; root trusts only the copy in
 * /etc, written over SSH at install (or by `cluster:pin-root` with the
 * fingerprint read on MAIN). Everything root reads from the inbox is data:
 * a command runs only when its signature verifies under the pinned key.
 */
final class RootPin {
	public const DIR = '/etc/xc_vm/cluster/';

	/** Largest command file root reads from the inbox. */
	public const MAX_COMMAND = 65536;

	private static ?string $rDir = null;

	private static ?string $rInbox = null;

	/** Tests: other directories; null restores the defaults. */
	public static function useDirs(?string $rDir, ?string $rInbox): void {
		self::$rDir = $rDir;
		self::$rInbox = $rInbox;
	}

	public static function dir(): string {
		return self::$rDir ?? self::DIR;
	}

	public static function inbox(): string {
		return self::$rInbox ?? ((defined('CONFIG_PATH') ? CONFIG_PATH : '/home/xc_vm/config/') . 'cluster/root-inbox/');
	}

	/**
	 * The pin, when it is in place and safe to trust: the directory owned by
	 * root (unless tests moved it) and writable by nobody else.
	 *
	 * @return array{pub: string, node: string}|null
	 */
	public static function read(): ?array {
		$rDir = self::dir();
		// stat() is cached per literal path string (not per resolved file), so a
		// permission change made via a differently-formed path (or simply a
		// repeated call within this same long-running process, e.g. the drain
		// loop in ClusterRootCommand) would otherwise go unnoticed for the rest
		// of the process — unacceptable for a trust check.
		clearstatcache(true, $rDir);
		$rStat = @stat($rDir);
		if ($rStat === false || is_link(rtrim($rDir, '/')) || ($rStat['mode'] & 0022) !== 0 || (self::$rDir === null && $rStat['uid'] !== 0)) {
			return null;
		}
		$rPub = @hex2bin(trim((string) @file_get_contents($rDir . 'main_sign.pub')));
		$rNode = trim((string) @file_get_contents($rDir . 'node'));
		if ($rPub === false || strlen($rPub) !== 32 || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $rNode)) {
			return null;
		}
		return ['pub' => $rPub, 'node' => $rNode];
	}

	/** Write the pin (root; the install flow does the same over SSH). */
	public static function write(string $rPub, string $rNode): bool {
		$rDir = self::dir();
		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			return false;
		}
		@chmod($rDir, 0755);
		@unlink($rDir . 'root.seq'); // a new pin starts root's seq afresh (a re-enrolled node)
		return @file_put_contents($rDir . 'main_sign.pub', bin2hex($rPub) . "\n") !== false
			&& @file_put_contents($rDir . 'node', $rNode . "\n") !== false
			&& @chmod($rDir . 'main_sign.pub', 0644) && @chmod($rDir . 'node', 0644);
	}

	/** Does the agent's panel key match root's pin? (the agent reports this) */
	public static function matches(string $rAgentPub, string $rNode): bool {
		$rPin = self::read();
		return $rPin !== null && hash_equals($rPin['pub'], $rAgentPub) && $rPin['node'] === $rNode;
	}

	/**
	 * Check a command against the pin: panel signature (tag `cmd`), a
	 * `node.root` for this node, a known action, not stale, and above root's
	 * own high-water.
	 *
	 * @param array{pub: string, node: string} $rPin
	 * @return array<string, mixed>|string the command, or why it is refused
	 */
	public static function verify(array $rPin, string $rDoc, string $rSig, int $rNow, int $rHighWater): array|string {
		if (!PanelSig::verify($rPin['pub'], 'cmd', $rDoc, $rSig)) {
			return 'bad signature';
		}
		$rCmd = json_decode($rDoc, true);
		if (!is_array($rCmd) || ($rCmd['type'] ?? null) !== 'node.root' || ($rCmd['node_uuid'] ?? null) !== $rPin['node']) {
			return 'not a root command for this node';
		}
		if ((int) ($rCmd['exp'] ?? 0) + 300 < $rNow) {
			return 'expired';
		}
		if ((int) ($rCmd['seq'] ?? 0) <= $rHighWater) {
			return 'seq not above ' . $rHighWater;
		}
		if (!in_array($rCmd['args']['action'] ?? null, NodeActions::ROOT_ACTIONS, true)) {
			return 'unknown root action';
		}
		return $rCmd;
	}

	public static function highWater(): int {
		return (int) trim((string) @file_get_contents(self::dir() . 'root.seq'));
	}

	public static function raiseHighWater(int $rSeq): bool {
		return @file_put_contents(self::dir() . 'root.seq', (string) $rSeq, LOCK_EX) !== false;
	}

	/**
	 * Write a result where xc_vm can read it, without following anything
	 * planted at that path: whatever is there is removed, and the file is
	 * created exclusively.
	 */
	public static function writeDone(string $rPath, string $rJson): bool {
		if (is_link($rPath) || file_exists($rPath)) {
			@unlink($rPath);
		}
		$rHandle = @fopen($rPath, 'x');
		if ($rHandle === false) {
			return false;
		}
		fwrite($rHandle, $rJson);
		fclose($rHandle);
		@chmod($rPath, 0644);
		return true;
	}
}
