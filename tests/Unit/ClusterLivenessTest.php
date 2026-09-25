<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 3 liveness: nodes whose agent reports telemetry are judged by their
 * silence every second (suspect after 10 s, offline after 30 s), published
 * for routing, and held by the fleet silence guard when most go quiet at once.
 */
final class ClusterLivenessTest extends TestCase {
	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	private string $rHealth;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) preg_replace(['/^--.*$/m', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'], ['', '', '', ');'], (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/029_create_cluster_nodes.sql')));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rT0);
		\XcVm\Domain\Cluster\ClusterMeta::set('ready_at', (string) ($this->rT0 - 600000));
		foreach ([5, 6, 7] as $rID) {
			NodeRegistry::startEnrolment($rID, sprintf('00000000-0000-4000-a000-%012d', $rID), str_repeat("\1", 32), str_repeat("\2", 32), 1);
			NodeRegistry::update($rID, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY, 'last_seen_at' => $this->rT0]);
		}
		$this->rHealth = sys_get_temp_dir() . '/health_' . bin2hex(random_bytes(4)) . '.json';
		ClusterHealth::usePath($this->rHealth);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		@unlink($this->rHealth);
		ClusterHealth::usePath(null);
	}

	private function at(int $rMs): array {
		ClusterClock::fix($this->rT0 + $rMs);
		return LivenessService::tick(30);
	}

	private function audit(string $rEvent): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = ?', $rEvent);
		return (int) $this->rDb->get_row()['n'];
	}

	public function testSilenceMakesANodeSuspectThenOffline(): void {
		$this->assertSame([5 => [null, 'ok'], 6 => [null, 'ok'], 7 => [null, 'ok']], $this->at(0));
		$this->assertSame([], $this->at(1000), 'no transition, no rewrite');

		// 5 and 6 keep heartbeating; 7 falls silent.
		$rBeat = fn(int $rMs) => $this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` IN (5, 6)', $this->rT0 + $rMs);
		$rBeat(10000);
		$this->assertSame([], $this->at(10000));
		$rBeat(11000);
		$this->assertSame([7 => ['ok', 'suspect']], $this->at(11000));
		$this->assertSame('suspect', ClusterHealth::state(7));
		$this->assertSame(2.0, ClusterHealth::weight(7), 'a suspect node weighs double');
		$rBeat(31000);
		$this->assertSame([7 => ['suspect', 'offline']], $this->at(31000));
		$this->assertSame(1.0, ClusterHealth::weight(7));

		// It speaks again.
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 7', $this->rT0 + 32000);
		$rBeat(32000);
		$this->assertSame([7 => ['offline', 'ok']], $this->at(32000));
		$this->assertSame(3, $this->audit('node.health'), 'ok→suspect, suspect→offline, offline→ok; the first publication is not a transition');
	}

	public function testFleetSilenceHoldsNodesInsteadOfMarkingThemOffline(): void {
		$this->at(0);
		// 6 and 7 of 3 fall silent together: MAIN suspects itself.
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 5', $this->rT0 + 40000);
		$this->assertSame([], $this->at(40000), 'held at ok, not marked offline');
		$this->assertTrue(ClusterHealth::read()['guard']);
		$this->assertSame(1, $this->audit('cluster.fleet_silence'));
		$this->assertSame('ok', ClusterHealth::state(7));

		// They come back: the guard clears.
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` IN (5, 6, 7)', $this->rT0 + 45000);
		$this->at(45000);
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame(1, $this->audit('cluster.fleet_silence_clear'));
	}

	public function testOnlyNodesWithTheFlowAreJudged(): void {
		NodeRegistry::update(6, ['flows' => 0]);
		NodeRegistry::update(7, ['state' => 'quarantined']);
		$this->assertSame([5 => [null, 'ok']], $this->at(0));
		$this->assertNull(ClusterHealth::state(6), 'legacy rule for a node without the flow');

		// Turning the flow off drops the node from the published states.
		NodeRegistry::update(5, ['flows' => 0]);
		$this->assertSame([5 => ['ok', null]], $this->at(1000));
		$this->assertNull(ClusterHealth::state(5));
	}

	public function testANodeNeverHeardIsOfflineOnceTheLoopHasWaited(): void {
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = NULL WHERE `server_id` = 5');
		$this->at(0);
		$this->assertSame('offline', ClusterHealth::state(5));
		$this->rDb->query("UPDATE `cluster_meta` SET `value` = ? WHERE `name` = 'ready_at'", (string) ($this->rT0 + 5000));
		$this->rDb->query("UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` IN (6, 7)", $this->rT0 + 10000);
		$this->at(10000);
		$this->assertSame('suspect', ClusterHealth::state(5), 'MAIN just restarted: not yet');
	}
}
