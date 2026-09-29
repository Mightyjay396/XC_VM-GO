<?php

namespace XcVm\Core\Cluster;

/**
 * Where MAIN's data-plane client (ADR 0004, Phase 9's eighth increment) and
 * MAIN's PHP meet on disk, beside a node agent's files under
 * `config/cluster/`:
 *
 * ```text
 * main_agent.json   MAIN's data-plane key (`xc_agent keygen`), 0600: read by the agent only
 * main.json         {v, server_id, node_uuid, gen, panel_sign_pub, dataplane}: which key,
 *                   which generation, and whether MAIN pulls through its agent
 * replica/servers.json, replica/tickets.json
 *                   the servers section and MAIN's own tickets, for the agent's proxy
 * relay.key         the loopback key, as on a node (DataPlane::KEY_FILE)
 * ```
 *
 * `xc_agent run -role main -state main_agent.json` serves the loopback proxy
 * with that key (XC_VM_Fanout, `mainrole.go`); MainDataPlane writes the rest.
 * Lives in Core because DataPlane asks it: a load balancer has no main.json,
 * and reads nothing here.
 */
final class MainAgentFiles {
	/** MAIN's identity file, relative to the config directory. */
	public const IDENTITY = AgentPaths::DIR . 'main.json';

	/** MAIN's key state, relative to the config directory. */
	public const STATE = AgentPaths::DIR . 'main_agent.json';

	/** The directory of the servers section and tickets MAIN's agent reads. */
	public const REPLICA = AgentPaths::DIR . 'replica/';

	/** What MAIN's agent reports of chunk digests that named no request (XC_VM_Fanout, MainDigestN1File). */
	public const DIGEST_N1 = AgentPaths::DIR . 'main_digest_n1.json';

	/** How long main.json is taken as it was read, in seconds. */
	private const TTL = 5;

	private static ?string $rPath = null;

	/** @var array{0: int, 1: array<string, mixed>|null}|null */
	private static ?array $rRead = null;

	/** Tests: read another file, and forget what was read. */
	public static function usePath(?string $rPath): void {
		self::$rPath = $rPath;
		self::$rRead = null;
	}

	/** main.json's path. */
	public static function identityPath(): string {
		return self::$rPath ?? AgentPaths::file(self::IDENTITY);
	}

	/**
	 * main.json when it is a whole identity, else null; read again at most
	 * every TTL seconds.
	 *
	 * @return array{v: int, server_id: int, node_uuid: string, gen: int, panel_sign_pub: string, dataplane: bool}|null
	 */
	public static function identity(): ?array {
		if (self::$rRead !== null && time() - self::$rRead[0] < self::TTL) {
			return self::$rRead[1];
		}
		$rDoc = json_decode((string) @file_get_contents(self::identityPath()), true);
		$rOut = null;
		if (is_array($rDoc) && ($rDoc['v'] ?? null) === 1 && is_int($rDoc['server_id'] ?? null) && $rDoc['server_id'] > 0 && is_int($rDoc['gen'] ?? null) && $rDoc['gen'] > 0
			&& is_string($rDoc['node_uuid'] ?? null) && is_string($rDoc['panel_sign_pub'] ?? null) && is_bool($rDoc['dataplane'] ?? null)
		) {
			$rOut = $rDoc;
		}
		self::$rRead = [time(), $rOut];
		return $rOut;
	}

	/** Does MAIN pull through its agent (main.json's `dataplane`)? */
	public static function on(): bool {
		return (self::identity()['dataplane'] ?? false) === true;
	}
}
