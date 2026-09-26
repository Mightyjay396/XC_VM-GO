<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ClusterReenrolCommand — re-enrol the fleet over SSH, node by node, the way
 * `server:enrol` enrols one (MAIN ↔ LB plan, section 6: MAIN replaced with no
 * DR bundle, `cluster:init`, then re-enrol all). Each node gets a new identity
 * and generation, so every token of its old one stops working.
 *
 * `--all` takes the enrolled nodes in state `enrolling` or `active`;
 * `--state=` names other states. A revoked or quarantined node was the
 * admin's decision, so it is re-enrolled only when asked. Nodes named by id
 * are taken whatever their state.
 *
 * Each node meets server:enrol's requirements (ServerEnrolCommand::enrol):
 *   - its SSH host key matches the one the credential file gives for it, else
 *     the one stored at its install; a node with neither is not contacted
 *     (never trust on first use);
 *   - it runs this release;
 *   - its new keys match their SAS.
 * A node that fails is reported and the run goes on; it keeps serving the
 * legacy way. A licence refusal stops the run: every later node would have
 * its agent stopped only to be refused the same way.
 *
 * Credentials: one owner-only (0600) JSON file in bin/install/, read and
 * deleted before the first connection. `--dry-run` keeps it and contacts no
 * node. The top level is every node's default; a node's entry overrides it:
 *
 *   {"u": "root", "p": "…", "port": 22,
 *    "nodes": {"7": {"p": "…", "port": 2222, "hostkey": "SHA1:…"}}}
 *
 * The SSH port is the node's entry, else the one its install used
 * (bin/install/<id>.json), else the file's top level, else 22.
 *
 * Usage: `console.php cluster:reenrol (--all [--state=enrolling,active] | <serverID>...) --cred-file=<path> [--dry-run]`.
 * MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterReenrolCommand implements CommandInterface {
	use DatabaseAware;

	public const USAGE = "Usage: cluster:reenrol (--all [--state=enrolling,active] | <serverID>...) --cred-file=<path> [--dry-run]\n";

	/** The states --all takes by default. */
	public const DEFAULT_STATES = ['enrolling', 'active'];

	/** What a credential entry may hold, and the key it is read into. */
	private const FIELDS = ['u' => 'username', 'p' => 'password', 'port' => 'port', 'hostkey' => 'hostkey'];

	public function getName(): string {
		return 'cluster:reenrol';
	}

	public function getDescription(): string {
		return 'Re-enrol every enrolled load balancer (or those named) over SSH';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		$rArgs = self::parseArgs($rArgs);
		if (is_string($rArgs)) {
			echo $rArgs . "\n" . self::USAGE;
			return 1;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			echo "The cluster API is disabled (Settings → Cluster). Exiting\n";
			return 1;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'Cluster API unavailable: ' . $rE->getMessage() . ". Exiting\n";
			return 1;
		}
		$rCreds = null;
		if ($rArgs['cred-file'] !== null) {
			$rCreds = self::readCredentials($rArgs['cred-file'], $rArgs['dry-run']);
			if (is_string($rCreds)) {
				echo 'Credential file refused: ' . $rCreds . ". Exiting\n";
				return 1;
			}
		}
		set_time_limit(0);
		return self::run(ServerRepository::getAll(true), $rArgs['ids'], $rArgs['states'], $rCreds, $rArgs['dry-run'], $rCrypto);
	}

	/**
	 * @param list<string> $rArgs
	 * @return array{ids: list<int>|null, states: list<string>, cred-file: string|null, dry-run: bool}|string The run, or what is wrong.
	 */
	public static function parseArgs(array $rArgs): array|string {
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rAll = false;
		$rDryRun = false;
		$rIDs = [];
		foreach ($rArgs as $rArg) {
			if ($rArg === '--all') {
				$rAll = true;
			} elseif ($rArg === '--dry-run') {
				$rDryRun = true;
			} elseif (preg_match('/^[1-9]\d*$/', (string) $rArg)) {
				$rIDs[(int) $rArg] = (int) $rArg;
			} else {
				return "Unknown argument '{$rArg}'";
			}
		}
		$rUnknown = array_diff(array_keys($rOptions), ['cred-file', 'state']);
		if ($rUnknown !== []) {
			return "Unknown option '--" . reset($rUnknown) . "'" . (reset($rUnknown) === 'expect-hostkey' ? ' (give each node\'s "hostkey" in the credential file)' : '');
		}
		if ($rAll === ($rIDs !== [])) {
			return 'Name the nodes, or pass --all';
		}
		$rStates = [];
		if (isset($rOptions['state'])) {
			if (!$rAll) {
				return '--state goes with --all';
			}
			$rStates = array_values(array_unique(array_filter(array_map('trim', explode(',', $rOptions['state'])), static fn(string $rS): bool => $rS !== '')));
			$rBad = array_diff($rStates, NodeRegistry::STATES);
			if ($rStates === [] || $rBad !== []) {
				return 'Node states are ' . implode(', ', NodeRegistry::STATES);
			}
		} elseif ($rAll) {
			$rStates = self::DEFAULT_STATES;
		}
		$rCredFile = $rOptions['cred-file'] ?? null;
		if ($rCredFile === null && !$rDryRun) {
			return 'The SSH credentials are missing (--cred-file)';
		}
		return ['ids' => $rAll ? null : array_values($rIDs), 'states' => $rStates, 'cred-file' => $rCredFile, 'dry-run' => $rDryRun];
	}

	/**
	 * Read the credential file: only a `.cred` file directly in bin/install/,
	 * readable by its owner alone. It is deleted once read, unless $rKeep.
	 *
	 * @return array{default: array<string, mixed>, nodes: array<int, array<string, mixed>>}|string The credentials, or why the file is refused.
	 */
	public static function readCredentials(string $rPath, bool $rKeep, ?string $rDir = null): array|string {
		$rDir ??= InstallCredentials::dir();
		$rReal = realpath($rPath);
		if (realpath($rDir) === false || $rReal === false || dirname($rReal) !== realpath($rDir) || !preg_match('/^[A-Za-z0-9_-]+\.cred$/', basename($rReal)) || !is_file($rReal)) {
			return 'it must be a .cred file in ' . $rDir;
		}
		if ((fileperms($rReal) & 0077) !== 0) {
			return 'its group or others can open it (chmod 600 it)';
		}
		$rJson = @file_get_contents($rReal);
		if (!$rKeep) {
			@unlink($rReal);
		}
		return $rJson === false ? 'it cannot be read' : self::parseCredentials($rJson);
	}

	/**
	 * @return array{default: array<string, mixed>, nodes: array<int, array<string, mixed>>}|string
	 */
	public static function parseCredentials(string $rJson): array|string {
		$rData = json_decode($rJson, true);
		if (!is_array($rData) || $rData === [] || array_is_list($rData)) {
			return 'it is not a JSON object of credentials';
		}
		$rNodes = $rData['nodes'] ?? [];
		unset($rData['nodes']);
		if (isset($rData['hostkey'])) {
			return 'a "hostkey" belongs in the entry of its node';
		}
		$rDefault = self::entry($rData);
		if (is_string($rDefault)) {
			return $rDefault;
		}
		if (!is_array($rNodes) || ($rNodes !== [] && array_is_list($rNodes))) {
			return '"nodes" must map server ids to entries';
		}
		$rOut = ['default' => $rDefault, 'nodes' => []];
		foreach ($rNodes as $rID => $rEntry) {
			if (!preg_match('/^[1-9]\d*$/', (string) $rID) || !is_array($rEntry)) {
				return "\"nodes\" must map server ids to entries (not '{$rID}')";
			}
			$rEntry = self::entry($rEntry);
			if (is_string($rEntry)) {
				return "node {$rID}: {$rEntry}";
			}
			$rOut['nodes'][(int) $rID] = $rEntry;
		}
		return $rOut;
	}

	/** @return array<string, mixed>|string One entry, with its keys spelled out, or what is wrong with it. */
	private static function entry(array $rEntry): array|string {
		$rUnknown = array_diff(array_keys($rEntry), array_keys(self::FIELDS));
		if ($rUnknown !== []) {
			return 'unknown key "' . reset($rUnknown) . '" (entries hold ' . implode(', ', array_keys(self::FIELDS)) . ')';
		}
		$rOut = [];
		foreach (self::FIELDS as $rKey => $rName) {
			if (!array_key_exists($rKey, $rEntry)) {
				continue;
			}
			$rValue = $rEntry[$rKey];
			if ($rKey === 'port') {
				if (!is_int($rValue) || $rValue < 1 || $rValue > 65535) {
					return '"port" must be a number from 1 to 65535';
				}
			} elseif (!is_string($rValue) || $rValue === '') {
				return "\"{$rKey}\" must be a non-empty string";
			} elseif ($rKey === 'hostkey') {
				$rValue = InstallCredentials::normalizeHostKey($rValue);
				if ($rValue === null) {
					return '"hostkey" is not a SHA-1 fingerprint (ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub)';
				}
			}
			$rOut[$rName] = $rValue;
		}
		return $rOut;
	}

	/**
	 * Re-enrol the chosen nodes, one after the other, and report each.
	 *
	 * @param array<int, array<string, mixed>> $rServers     ServerRepository::getAll(true)
	 * @param list<int>|null                   $rIDs         The nodes named, or null for --all.
	 * @param list<string>                     $rStates      The states --all takes.
	 * @param array{default: array<string, mixed>, nodes: array<int, array<string, mixed>>}|null $rCreds readCredentials(); null only for a dry run.
	 * @param SshSession|null                  $rSsh         Tests.
	 * @param callable|null                    $rAgentBinary Tests (as for provisionCluster).
	 * @param string|null                      $rInstallDir  Where <id>.json keeps the port of each install (tests).
	 * @return int 0 when every chosen node was (or would be) re-enrolled.
	 */
	public static function run(array $rServers, ?array $rIDs, array $rStates, ?array $rCreds, bool $rDryRun, ClusterCrypto $rCrypto, ?SshSession $rSsh = null, ?callable $rAgentBinary = null, ?string $rInstallDir = null): int {
		if (empty($rCrypto->info()['licensed'])) {
			echo "CLUSTER_LICENCE_REQUIRED: this panel's extension issues no tokens, so no node can be re-enrolled. Nothing was touched.\n";
			return 1;
		}
		$rTargets = self::targets($rServers, $rIDs, $rStates, $rCreds, $rInstallDir ?? InstallCredentials::dir());
		if ($rTargets === []) {
			echo "No enrolled node to re-enrol.\n";
			return 0;
		}
		echo $rDryRun ? "Dry run: no node is contacted and nothing changes.\n" : "Re-enrolling over SSH, one node at a time. Each gets a new identity and generation.\n";

		$rResults = [];
		$rOk = [];
		$rFailed = [];
		$rStopped = false;
		foreach ($rTargets as $rID => $rTarget) {
			$rAccess = $rTarget['access'];
			if ($rTarget['skip'] !== null) {
				$rResults[$rID] = ['skipped', $rTarget['skip']];
			} elseif ($rAccess === null) {
				$rResults[$rID] = ['not attempted', (string) $rTarget['why']];
			} elseif ($rDryRun) {
				$rWho = $rCreds === null ? ' (no credential file given) at ' : ' as ' . $rAccess['username'] . '@';
				$rResults[$rID] = ['would re-enrol', $rWho . $rAccess['host'] . ':' . $rAccess['port'] . ', host key ' . $rAccess['hostkey'] . ' (' . $rAccess['source'] . ')'];
			} elseif ($rStopped) {
				$rResults[$rID] = ['not attempted', 'the run stopped at the licence refusal'];
			} else {
				echo "\n== #{$rID} {$rTarget['name']} ({$rAccess['host']}:{$rAccess['port']})\n";
				try {
					$rWhy = ServerEnrolCommand::enrol($rServers, $rID, $rAccess['port'], ['username' => $rAccess['username'], 'password' => $rAccess['password']], $rAccess['expected'], $rCrypto, $rSsh, $rAgentBinary);
				} catch (\Throwable $rE) {
					$rWhy = 'error: ' . $rE->getMessage();
					echo $rWhy . "\n";
				}
				if ($rWhy === null) {
					$rNode = (array) NodeRegistry::byServer($rID);
					$rResults[$rID] = ['re-enrolled', ' (node ' . ($rNode['node_uuid'] ?? '?') . ', gen ' . ($rNode['gen'] ?? '?') . ')'];
					$rOk[] = $rID;
				} else {
					$rResults[$rID] = ['failed', $rWhy];
					$rFailed[] = $rID;
					if (str_starts_with($rWhy, 'CLUSTER_LICENCE_REQUIRED')) {
						$rStopped = true;
					}
				}
			}
		}
		if (!$rDryRun && ($rOk !== [] || $rFailed !== [])) {
			ClusterAudit::log('cluster.reenrol', null, ['ok' => $rOk, 'failed' => $rFailed], 'cli');
		}

		echo "\n";
		$rCount = ['re-enrolled' => 0, 'would re-enrol' => 0, 'failed' => 0, 'not attempted' => 0, 'skipped' => 0];
		foreach ($rResults as $rID => [$rStatus, $rDetail]) {
			$rCount[$rStatus]++;
			$rName = $rTargets[$rID]['name'] === '' ? '' : ' ' . $rTargets[$rID]['name'];
			$rSep = in_array($rStatus, ['re-enrolled', 'would re-enrol'], true) ? '' : ': ';
			echo "#{$rID}{$rName}: {$rStatus}{$rSep}{$rDetail}\n";
		}
		if ($rDryRun) {
			echo "{$rCount['would re-enrol']} would be re-enrolled, {$rCount['not attempted']} not attempted, {$rCount['skipped']} skipped.\n";
		} else {
			echo "{$rCount['re-enrolled']} re-enrolled, {$rCount['failed']} failed, {$rCount['not attempted']} not attempted, {$rCount['skipped']} skipped.\n";
		}
		return $rCount['failed'] + $rCount['not attempted'] === 0 ? 0 : 1;
	}

	/**
	 * The nodes a run considers, in server id order: why each is skipped (not
	 * chosen) or cannot be attempted, else how to reach it.
	 *
	 * @return array<int, array{name: string, skip: string|null, why: string|null, access: array{host: string, port: int, username: string, password: string, hostkey: string, expected: string|null, source: string}|null}>
	 */
	private static function targets(array $rServers, ?array $rIDs, array $rStates, ?array $rCreds, string $rInstallDir): array {
		self::db()->query('SELECT `server_id`, `state` FROM `cluster_nodes` ORDER BY `server_id`;');
		$rEnrolled = [];
		foreach (self::db()->get_rows() as $rRow) {
			$rEnrolled[(int) $rRow['server_id']] = (string) $rRow['state'];
		}
		$rTargets = [];
		foreach ($rIDs ?? array_keys($rEnrolled) as $rID) {
			$rServer = $rServers[$rID] ?? null;
			$rIsLb = $rServer !== null && empty($rServer['is_main']) && intval($rServer['server_type'] ?? 0) === 0;
			$rTarget = ['name' => (string) ($rServer['server_name'] ?? ''), 'skip' => null, 'why' => null, 'access' => null];
			if ($rIDs === null && !$rIsLb) {
				$rTarget['skip'] = 'no load balancer with this id';
			} elseif ($rIDs === null && !in_array($rEnrolled[$rID], $rStates, true)) {
				$rTarget['skip'] = $rEnrolled[$rID] . ' (name it, or pass --state=' . $rEnrolled[$rID] . ')';
			} elseif (!$rIsLb) {
				$rTarget['why'] = 'not a load balancer';
			} elseif (!isset($rEnrolled[$rID])) {
				$rTarget['why'] = 'not enrolled (server:enrol enrols it)';
			} else {
				$rAccess = self::access($rID, (array) $rServer, $rCreds, $rInstallDir);
				if (is_string($rAccess)) {
					$rTarget['why'] = $rAccess;
				} else {
					$rTarget['access'] = $rAccess;
				}
			}
			$rTargets[$rID] = $rTarget;
		}
		ksort($rTargets);
		return $rTargets;
	}

	/**
	 * How to reach a node: its credentials and port, and the host key to hold
	 * it to (the credential file's, else the stored one).
	 *
	 * @return array{host: string, port: int, username: string, password: string, hostkey: string, expected: string|null, source: string}|string
	 */
	private static function access(int $rID, array $rServer, ?array $rCreds, string $rInstallDir): array|string {
		$rEntry = ($rCreds['nodes'][$rID] ?? []) + ($rCreds['default'] ?? []);
		$rExpected = $rCreds['nodes'][$rID]['hostkey'] ?? null;
		$rStored = (string) ($rServer['ssh_hostkey_sha1'] ?? '');
		if ($rExpected === null && $rStored === '') {
			return 'no SSH host key to check (none was stored at its install): give its "hostkey" in the credential file, read on the node with ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub';
		}
		if ($rCreds !== null && (!isset($rEntry['username']) || !isset($rEntry['password']))) {
			return 'no SSH credentials for it in the credential file';
		}
		$rInstall = json_decode((string) @file_get_contents($rInstallDir . $rID . '.json'), true);
		$rInstallPort = is_array($rInstall) && is_int($rInstall['ssh_port'] ?? null) && $rInstall['ssh_port'] > 0 ? $rInstall['ssh_port'] : null;
		return [
			'host' => (string) ($rServer['server_ip'] ?? ''),
			'port' => (int) ($rCreds['nodes'][$rID]['port'] ?? $rInstallPort ?? $rCreds['default']['port'] ?? 22),
			'username' => (string) ($rEntry['username'] ?? ''),
			'password' => (string) ($rEntry['password'] ?? ''),
			'hostkey' => (string) ($rExpected ?? $rStored),
			'expected' => $rExpected,
			'source' => $rExpected === null ? 'stored at its install' : 'credential file',
		];
	}
}
