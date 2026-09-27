<?php

use PHPUnit\Framework\TestCase;
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

	public function testOnlyAnAgentThatTakesArtefactsIsSentABinary(): void {
		$this->assertSame([], $this->push($this->node(['features' => 'config_changed'])));
		$this->assertSame([], $this->push($this->node(['flows' => NodeRegistry::FLOW_TELEMETRY])), 'no commands');
		$this->assertSame([], $this->push($this->node(['mode' => 0])));
	}
}
