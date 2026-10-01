<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\AgentUpgrades;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\NodeRegistry;

/** cluster_nodes rows and a cluster_meta the test can read back. */
class AgentUpgradeDb extends DatabaseHandler {
	/** @var list<array<string, mixed>> */
	public array $rNodes = [];
	/** @var array<string, string> */
	public array $rMeta = [];
	/** @var list<string> */
	public array $rQueries = [];
	/** @var list<array<string, mixed>> */
	private array $rRows = [];

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
		$this->rQueries[] = (string) $query;
		$rArgs = array_slice(func_get_args(), 1);
		if (str_contains($query, 'FROM `cluster_nodes`')) {
			$this->rRows = $this->rNodes;
		} elseif (str_contains($query, 'SELECT `value` FROM `cluster_meta`')) {
			$rName = (string) ($rArgs[0] ?? '');
			$this->rRows = isset($this->rMeta[$rName]) ? [['value' => $this->rMeta[$rName]]] : [];
		} elseif (str_starts_with($query, 'INSERT INTO `cluster_meta`')) {
			$this->rMeta[(string) $rArgs[0]] = (string) $rArgs[1];
			$this->rRows = [];
		} elseif (str_starts_with($query, 'DELETE FROM `cluster_meta`')) {
			unset($this->rMeta[(string) $rArgs[0]]);
			$this->rRows = [];
		} else {
			$this->rRows = [];
		}
		return true;
	}

	public function num_rows() {
		return count($this->rRows);
	}

	public function get_row() {
		return $this->rRows[0] ?? false;
	}

	public function get_rows(bool $use_id = false, string $column_as_id = '', bool $unique_row = true, string $sub_row_id = '') {
		return $this->rRows;
	}
}

/**
 * Every node runs the agent MAIN pinned (plan, section 5). MAIN keeps one
 * verified binary per arch and the install flow puts it on the node — and then
 * nothing ever offered an upgrade. cron:cluster does now: a node reporting
 * another version is sent `node.root agent_binary`, once, until it reports the
 * new one or RETRY_SEC passes.
 */
final class AgentUpgradeTest extends TestCase {

	private const NOW = 1800000000;

	private AgentUpgradeDb $rDb;

	private string $rCache;

	protected function setUp(): void {
		$this->rDb = new AgentUpgradeDb();
		foreach ([AgentUpgrades::class, ClusterMeta::class, ClusterAudit::class] as $rClass) {
			$rClass::setDb($this->rDb);
		}
		$this->rCache = BIN_PATH . 'xc_agent/cache/';
		@mkdir($this->rCache, 0o777, true);
		$this->pinned('amd64', '1.4.0');
	}

	protected function tearDown(): void {
		foreach ([AgentUpgrades::class, ClusterMeta::class, ClusterAudit::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		array_map('unlink', glob($this->rCache . '*') ?: []);
		array_map('unlink', glob(BIN_PATH . 'xcvm_core/cache/*') ?: []);
	}

	/**
	 * The pushes push() asked for of $rKind (fanout, core), each node's
	 * watchdog reporting $rVersions.
	 *
	 * @param array<string, mixed> $rVersions
	 * @return list<array{0: int, 1: string}>
	 */
	private function pushKind(string $rKind, array $rVersions, array $rOverride = []): array {
		$this->rDb->rNodes = [$this->node(['watchdog_data' => json_encode(['versions' => $rVersions])] + $rOverride)];
		$rSent = [];
		AgentUpgrades::push(function (int $rServerID, string $rFor) use (&$rSent): bool {
			$rSent[] = [$rServerID, $rFor];
			return true;
		}, self::NOW, $rKind);
		return $rSent;
	}

	/**
	 * The fanout daemon and xcvm_core follow the agent's path on nodes in
	 * mode 1 and 2 (plan, section 5): a node behind MAIN's copy for its arch
	 * or PHP group is offered it, never one ahead (it updates from GitHub
	 * itself in mode 1), nor a node in mode 0.
	 */
	public function testTheFanoutDaemonAndXcvmCoreFollowTheAgentsPath(): void {
		file_put_contents($this->rCache . 'xc_fanout-linux-amd64', 'ELF');
		file_put_contents($this->rCache . 'xc_fanout-linux-amd64.version', "0.14.1\n");
		@mkdir(BIN_PATH . 'xcvm_core/cache/', 0o777, true);
		file_put_contents(BIN_PATH . 'xcvm_core/cache/xcvm_core-php8.1.tar.gz', 'gz');
		file_put_contents(BIN_PATH . 'xcvm_core/cache/xcvm_core-php8.1.tar.gz.version', "2.3.3\n");

		$this->assertSame([[5, 'amd64']], $this->pushKind('fanout', ['xc_fanout' => '0.13.7', 'xcvm_core' => '2.3.1', 'php' => 'php8.1']));
		$this->assertSame([[5, 'php8.1']], $this->pushKind('core', ['xc_fanout' => '0.13.7', 'xcvm_core' => '2.3.1', 'php' => 'php8.1']));
		$this->assertSame([], $this->pushKind('fanout', ['xc_fanout' => '0.14.1', 'php' => 'php8.1'], ['server_id' => 6]), 'on MAIN\'s version');
		$this->assertSame([], $this->pushKind('fanout', ['xc_fanout' => '0.15.0', 'php' => 'php8.1'], ['server_id' => 7]), 'never a downgrade');
		$this->assertSame([], $this->pushKind('core', ['xcvm_core' => '2.3.1', 'php' => 'php8.4'], ['server_id' => 8]), 'no archive for its PHP');
		$this->assertSame([], $this->pushKind('fanout', ['xc_fanout' => '0.13.7'], ['server_id' => 9, 'mode' => 0]), 'mode 0: GitHub, as before');
		$this->assertSame([], $this->pushKind('fanout', [], ['server_id' => 10]), 'a node that reports no daemon');
		$this->assertSame([], $this->pushKind('core', ['xcvm_core' => '2.3.1', 'php' => 'php8.1'], ['server_id' => 11, 'features' => '']), 'an agent that takes no artefacts');

		// Each kind keeps its own offers: the agent's rollout is untouched.
		$this->assertArrayHasKey('fanout_push:5', $this->rDb->rMeta);
		$this->assertArrayHasKey('core_push:5', $this->rDb->rMeta);
		$this->assertArrayNotHasKey('agent_push:5', $this->rDb->rMeta);
		$this->assertSame([[5, 'amd64']], $this->push($this->node()), 'the agent is still offered its own');
	}

	/** MAIN's cached binary for an arch, at a version. */
	private function pinned(string $rArch, string $rVersion): void {
		file_put_contents($this->rCache . 'xc_agent-linux-' . $rArch, 'ELF');
		file_put_contents($this->rCache . 'xc_agent-linux-' . $rArch . '.version', $rVersion . "\n");
	}

	/** @param array<string, mixed> $rOverride */
	private function node(array $rOverride = []): array {
		return array_merge([
			'server_id' => 5, 'gen' => 1, 'state' => 'active', 'mode' => 1, 'arch' => 'amd64',
			'agent_version' => '1.3.0', 'features' => 'artefact', 'root_ready' => 1,
			'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_TELEMETRY,
		], $rOverride);
	}

	/** @return list<array{0: int, 1: string}> The pushes push() asked for. */
	private function push(array ...$rNodes): array {
		$this->rDb->rNodes = $rNodes;
		$rSent = [];
		AgentUpgrades::push(function (int $rServerID, string $rArch) use (&$rSent): bool {
			$rSent[] = [$rServerID, $rArch];
			return true;
		}, self::NOW);
		return $rSent;
	}

	public function testANodeOnAnOlderAgentIsOfferedThePinnedOne(): void {
		$this->assertSame([[5, 'amd64']], $this->push($this->node()));
	}

	public function testTheRolloutIsStagedByTheParallelSetting(): void {
		$rNodes = [$this->node(['server_id' => 5]), $this->node(['server_id' => 6]), $this->node(['server_id' => 7])];

		// The default is one node at a time, lowest server id first.
		$this->assertSame([[5, 'amd64']], $this->push(...$rNodes));
		// 5 has not come back on the new version yet: it still holds the slot.
		$this->assertSame([], $this->push(...$rNodes));

		SettingsManager::set(['cluster_agent_upgrade_parallel' => 3]);
		try {
			// 5 is still in flight, so only two more start.
			$this->assertSame([[6, 'amd64'], [7, 'amd64']], $this->push(...$rNodes));
		} finally {
			SettingsManager::set([]);
		}
	}

	public function testTheSameVersionIsNotOfferedTwiceInAWindow(): void {
		$this->assertSame([[5, 'amd64']], $this->push($this->node()));
		$this->assertSame([], $this->push($this->node()), 'already offered');

		// The node still has not taken it: offered again after the retry window.
		$this->rDb->rNodes = [$this->node()];
		$rSent = [];
		AgentUpgrades::push(function (int $rServerID, string $rArch) use (&$rSent): bool {
			$rSent[] = [$rServerID, $rArch];
			return true;
		}, self::NOW + AgentUpgrades::RETRY_SEC);
		$this->assertSame([[5, 'amd64']], $rSent);
	}

	/** @return list<array{0: int, 1: string}> The pushes push() asked for at $rNow. */
	private function pushAt(int $rNow, array ...$rNodes): array {
		$this->rDb->rNodes = $rNodes;
		$rSent = [];
		AgentUpgrades::push(function (int $rServerID, string $rArch) use (&$rSent): bool {
			$rSent[] = [$rServerID, $rArch];
			return true;
		}, $rNow);
		return $rSent;
	}

	/**
	 * A canary: the first node offered a version that is not running it
	 * RETRY_SEC later holds that version back from the nodes not yet offered
	 * it. It is offered it again itself.
	 */
	public function testAVersionThatFailedOnOneNodeIsHeldFromTheOthers(): void {
		$rNodes = [$this->node(['server_id' => 5]), $this->node(['server_id' => 6]), $this->node(['server_id' => 7])];
		$this->assertSame([[5, 'amd64']], $this->push(...$rNodes));
		SettingsManager::set(['cluster_agent_upgrade_parallel' => 3]);
		try {
			$this->assertSame([[5, 'amd64']], $this->pushAt(self::NOW + AgentUpgrades::RETRY_SEC, ...$rNodes), 'only the node it failed on');
			// Once node 5 runs it, the others follow.
			$rNodes[0] = $this->node(['server_id' => 5, 'agent_version' => '1.4.0']);
			$this->assertSame([[6, 'amd64'], [7, 'amd64']], $this->pushAt(self::NOW + 2 * AgentUpgrades::RETRY_SEC, ...$rNodes));
		} finally {
			SettingsManager::set([]);
		}
	}

	/**
	 * An operator who judges a failure not the version's (the canary was
	 * down, MAIN unreachable) releases the hold: the failed node is offered it
	 * afresh, and the rest follow.
	 */
	public function testAReleasedRolloutIsOfferedAgainToAll(): void {
		$rNodes = [$this->node(['server_id' => 5]), $this->node(['server_id' => 6])];
		SettingsManager::set(['cluster_agent_upgrade_parallel' => 3]);
		try {
			$this->assertSame([[5, 'amd64'], [6, 'amd64']], $this->push(...$rNodes));
			// Neither took it: both are failures, and a node offered it twice more is given up.
			$this->assertSame([[5, 'amd64'], [6, 'amd64']], $this->pushAt(self::NOW + AgentUpgrades::RETRY_SEC, ...$rNodes));
			$rLater = self::NOW + 2 * AgentUpgrades::RETRY_SEC;
			$this->assertSame(2, AgentUpgrades::release('1.4.0', 'test', $rLater));
			$this->assertSame([[5, 'amd64'], [6, 'amd64']], $this->pushAt($rLater + 60, ...$rNodes), 'offered afresh');
			$this->assertSame(0, AgentUpgrades::release('1.4.0', 'test', $rLater + 120), 'offers still installing are left alone');
		} finally {
			SettingsManager::set([]);
		}
	}

	public function testANodeIsOfferedAVersionAtMostMaxTries(): void {
		$rNode = $this->node();
		for ($i = 0; $i < AgentUpgrades::MAX_TRIES; $i++) {
			$this->assertSame([[5, 'amd64']], $this->pushAt(self::NOW + $i * AgentUpgrades::RETRY_SEC, $rNode), 'try ' . ($i + 1));
		}
		$this->assertSame([], $this->pushAt(self::NOW + AgentUpgrades::MAX_TRIES * AgentUpgrades::RETRY_SEC, $rNode), 'given up');
	}

	public function testAReEnrolledNodeIsOfferedItAgain(): void {
		$this->assertSame([[5, 'amd64']], $this->push($this->node()));
		$this->assertSame([[5, 'amd64']], $this->push($this->node(['gen' => 2])));
	}

	public function testNothingIsOfferedWithoutAnArchAVersionOrACachedBinary(): void {
		$this->assertSame([], $this->push($this->node(['arch' => null])), 'MAIN never guesses the arch');
		$this->assertSame([], $this->push($this->node(['arch' => 'sparc'])));
		$this->assertSame([], $this->push($this->node(['agent_version' => null])), 'the node has not said yet');
		$this->assertSame([], $this->push($this->node(['arch' => 'arm64'])), 'no binary cached for that arch');
	}

	public function testANodeAlreadyOnThePinnedVersionIsLeftAlone(): void {
		$this->assertSame([], $this->push($this->node(['agent_version' => '1.4.0'])));
	}

	/**
	 * An operator may move the fleet to https_required only once every active
	 * node has reached MAIN over HTTPS, which its agent reports as the `https`
	 * feature. Before this the guard refused while any active node existed at
	 * all, so the setting could never be switched on a running cluster.
	 */
	public function testHttpsRequiredNeedsEveryActiveNodeToHaveHttps(): void {
		NodeRegistry::setDb($this->rDb);
		try {
			$this->rDb->rNodes = [];
			$this->assertTrue(NodeRegistry::allActiveHaveFeature('https'), 'no node, nobody to lose');

			$this->rDb->rNodes = [['features' => 'artefact,https'], ['features' => 'https']];
			$this->assertTrue(NodeRegistry::allActiveHaveFeature('https'));

			$this->rDb->rNodes = [['features' => 'https'], ['features' => 'artefact']];
			$this->assertFalse(NodeRegistry::allActiveHaveFeature('https'));

			$this->rDb->rNodes = [['features' => null]];
			$this->assertFalse(NodeRegistry::allActiveHaveFeature('https'));
		} finally {
			(new ReflectionProperty(NodeRegistry::class, 'db'))->setValue(null, null);
		}
	}

	public function testOnlyAnAgentThatTakesArtefactsIsSentABinary(): void {
		$this->assertSame([], $this->push($this->node(['features' => 'config_changed'])));
		$this->assertSame([], $this->push($this->node(['flows' => NodeRegistry::FLOW_TELEMETRY])), 'no commands');
		$this->assertSame([], $this->push($this->node(['mode' => 0])));
	}
}
