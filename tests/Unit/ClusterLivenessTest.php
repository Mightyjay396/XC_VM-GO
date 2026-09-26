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
 * for routing, and held by the fleet silence guard when most go quiet at once,
 * or while the cluster_ctl pool's listen queue lasts over 5 s (plan §8,
 * "Liveness"). The queue's reader is faked here: it says for how long the
 * queue has lasted (ms), 0 for none, null when the pool cannot tell.
 */
final class ClusterLivenessTest extends TestCase {
	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	private string $rHealth;

	/** What the fake cluster_ctl queue reader says: the queue's age in ms, 0, or null. */
	private ?int $rQueue = 0;

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
		LivenessService::useQueueReader(fn(): ?int => $this->rQueue);
	}

	protected function tearDown(): void {
		LivenessService::useQueueReader(null);
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

	/** @return array<string, mixed> the detail of the event's last audit row */
	private function auditDetail(string $rEvent): array {
		$this->rDb->query('SELECT `detail` FROM `cluster_audit` WHERE `event` = ? ORDER BY `id` DESC LIMIT 1', $rEvent);
		return (array) json_decode((string) ($this->rDb->get_row()['detail'] ?? ''), true);
	}

	/** @param list<int> $rIDs the nodes heard at $rMs */
	private function beat(int $rMs, array $rIDs = [5, 6, 7]): void {
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` IN (' . implode(',', $rIDs) . ')', $this->rT0 + $rMs);
	}

	/** A pass at $rMs whose queue reader says $rQueue. */
	private function queuedAt(int $rMs, ?int $rQueue): array {
		$this->rQueue = $rQueue;
		return $this->at($rMs);
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

		// It speaks again: suspect at once, ok after steady health (NodeHealth::RECOVER_MS).
		$rAll = fn(int $rMs) => $this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ?', $this->rT0 + $rMs);
		$rAll(32000);
		$this->assertSame([7 => ['offline', 'suspect']], $this->at(32000));
		$rAll(61000);
		$this->assertSame([], $this->at(61000), 'still recovering');
		$rAll(62000);
		$this->assertSame([7 => ['suspect', 'ok']], $this->at(62000));
		$this->assertSame(4, $this->audit('node.health'), 'ok→suspect, suspect→offline, offline→suspect, suspect→ok; the first publication is not a transition');
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

	public function testAListenQueueOfFiveSecondsRaisesNothing(): void {
		$this->queuedAt(0, 0);
		// A queue from 0 that drains after exactly 5 s: not over 5 s.
		foreach ([1000, 2000, 3000, 4000, 5000] as $rMs) {
			$this->beat($rMs);
			$this->assertSame([], $this->queuedAt($rMs, $rMs));
			$this->assertFalse(ClusterHealth::read()['guard'], 'at ' . $rMs . ' ms');
		}
		$this->beat(6000);
		$this->queuedAt(6000, 0);
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame([], ClusterHealth::read()['reasons']);
		$this->assertNull(ClusterHealth::read()['ctl_queue'], 'drained');
		$this->assertSame(0, $this->audit('cluster.ctl_queue'));
		$this->assertSame(0, $this->audit('cluster.ctl_queue_clear'));
	}

	public function testAListenQueueOverFiveSecondsHoldsTheNodesUntilItDrains(): void {
		$this->queuedAt(0, 0);
		// 7 falls silent; alone it is no fleet silence (1 of 3).
		foreach ([1000, 2000, 3000, 4000, 5000] as $rMs) {
			$this->beat($rMs, [5, 6]);
			$this->queuedAt($rMs, $rMs);
		}
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->beat(6000, [5, 6]);
		$this->queuedAt(6000, 6000);
		$this->assertTrue(ClusterHealth::read()['guard'], 'over 5 s: MAIN suspects its own pool');
		$this->assertSame(['ctl_queue'], ClusterHealth::read()['reasons']);
		$this->assertSame(['since' => $this->rT0, 'at' => $this->rT0 + 6000], ClusterHealth::read()['ctl_queue']);
		$this->assertSame(1, $this->audit('cluster.ctl_queue'));
		$this->assertSame(['queued_ms' => 6000], $this->auditDetail('cluster.ctl_queue'));
		$this->assertSame(0, $this->audit('cluster.fleet_silence'), 'a reason of its own');

		// The guard suspends offline marking: 7 gets worse only to suspect.
		for ($rMs = 7000; $rMs <= 40000; $rMs += 1000) {
			$this->beat($rMs, [5, 6]);
			$this->queuedAt($rMs, $rMs);
		}
		$this->assertSame('suspect', ClusterHealth::state(7), 'held, not offline after 40 s');
		$this->assertTrue(ClusterHealth::read()['guard']);
		$this->assertSame(1, $this->audit('cluster.ctl_queue'), 'raised once');

		// It drains: the guard clears, and 7 is marked offline at once.
		$this->beat(41000, [5, 6]);
		$this->assertSame([7 => ['suspect', 'offline']], $this->queuedAt(41000, 0));
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame([], ClusterHealth::read()['reasons']);
		$this->assertSame(1, $this->audit('cluster.ctl_queue_clear'));
		$this->assertSame(['lasted_ms' => 40000, 'queue' => 'drained'], $this->auditDetail('cluster.ctl_queue_clear'));
	}

	public function testAPoolThatCannotTellNeverRaisesTheGuard(): void {
		// The pool is down, or has no status page, for a minute.
		for ($rMs = 0; $rMs <= 60000; $rMs += 1000) {
			$this->beat($rMs);
			$this->queuedAt($rMs, null);
		}
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertNull(ClusterHealth::read()['ctl_queue']);

		// A pass that cannot tell ends a run: 4 s, a gap, then 4 s more.
		foreach ([61000 => 1000, 62000 => 2000, 63000 => 3000, 64000 => 4000, 65000 => null, 66000 => 1000, 67000 => 2000, 68000 => 3000, 69000 => 4000] as $rMs => $rQueue) {
			$this->beat($rMs);
			$this->queuedAt($rMs, $rQueue);
			$this->assertFalse(ClusterHealth::read()['guard'], 'at ' . $rMs . ' ms');
		}

		// A reader that fails is one that cannot tell.
		LivenessService::useQueueReader(static function (): ?int {
			throw new \RuntimeException('no pool');
		});
		$this->beat(70000);
		$this->assertSame([], $this->at(70000));
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertNull(ClusterHealth::read()['ctl_queue']);
		$this->assertSame(0, $this->audit('cluster.ctl_queue'));
	}

	public function testTheListenQueueAndTheFleetSilenceAreOneGuard(): void {
		$this->queuedAt(0, 0);
		// The queue first: 6 and 7 fall silent while it lasts.
		$this->beat(6000, [5]);
		$this->queuedAt(6000, 6000);
		$this->assertSame(['ctl_queue'], ClusterHealth::read()['reasons']);
		$this->beat(40000, [5]);
		$this->assertSame([], $this->queuedAt(40000, 40000), 'held at ok');
		$this->assertSame(['silence', 'ctl_queue'], ClusterHealth::read()['reasons']);
		$this->assertSame(1, $this->audit('cluster.fleet_silence'));

		// The queue drains, the silence goes on: still guarded.
		$this->beat(41000, [5]);
		$this->assertSame([], $this->queuedAt(41000, 0));
		$this->assertSame(['silence'], ClusterHealth::read()['reasons']);
		$this->assertTrue(ClusterHealth::read()['guard']);
		$this->assertSame([1, 0], [$this->audit('cluster.ctl_queue_clear'), $this->audit('cluster.fleet_silence_clear')]);
		$this->beat(45000);
		$this->queuedAt(45000, 0);
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame(1, $this->audit('cluster.fleet_silence_clear'));

		// The silence first, then a queue from 45 s: the silence clears, the queue holds on.
		$this->beat(52000);
		$this->queuedAt(52000, 7000);
		$this->assertSame(['ctl_queue'], ClusterHealth::read()['reasons']);
		$this->beat(86000, [5]);
		$this->assertSame([], $this->queuedAt(86000, 41000), 'held at ok');
		$this->assertSame(['silence', 'ctl_queue'], ClusterHealth::read()['reasons']);
		$this->beat(87000);
		$this->queuedAt(87000, 42000);
		$this->assertSame(['ctl_queue'], ClusterHealth::read()['reasons']);
		$this->assertSame(2, $this->audit('cluster.fleet_silence_clear'));
		$this->beat(88000);
		$this->queuedAt(88000, 0);
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame([2, 2, 2, 2], [$this->audit('cluster.ctl_queue'), $this->audit('cluster.ctl_queue_clear'), $this->audit('cluster.fleet_silence'), $this->audit('cluster.fleet_silence_clear')]);
		$this->assertSame(['ok', 'ok', 'ok'], [ClusterHealth::state(5), ClusterHealth::state(6), ClusterHealth::state(7)], 'no node was marked offline');
	}

	public function testAnotherPassJoinsTheSameRun(): void {
		$this->queuedAt(0, 0);
		$this->beat(6000);
		$this->queuedAt(6000, 6000);
		$this->assertTrue(ClusterHealth::read()['guard']);
		// cron:cluster beside the signals daemon: its own request has waited 250 ms.
		$this->beat(6500);
		$this->queuedAt(6500, 250);
		$this->assertSame(['since' => $this->rT0, 'at' => $this->rT0 + 6500], ClusterHealth::read()['ctl_queue'], 'one run');
		$this->assertTrue(ClusterHealth::read()['guard']);
		$this->beat(7000);
		$this->queuedAt(7000, 7000);
		$this->assertSame(0, $this->audit('cluster.ctl_queue_clear'));

		// No pass for 20 s: whether it drained meanwhile is unknown, so a new run starts.
		$this->beat(27000);
		$this->queuedAt(27000, 250);
		$this->assertSame(['since' => $this->rT0 + 26750, 'at' => $this->rT0 + 27000], ClusterHealth::read()['ctl_queue']);
		$this->assertFalse(ClusterHealth::read()['guard']);
		$this->assertSame(['lasted_ms' => 7000, 'queue' => 'unknown'], $this->auditDetail('cluster.ctl_queue_clear'));
		// The reader's own age still counts: one request waiting 6 s is a queue of 6 s.
		$this->beat(28000);
		$this->queuedAt(28000, 6000);
		$this->assertTrue(ClusterHealth::read()['guard']);
		$this->assertSame(2, $this->audit('cluster.ctl_queue'));
	}

	public function testAGuardWithoutAReasonIsTheFleetSilence(): void {
		// health.json as written before the listen queue had a reason of its own.
		file_put_contents($this->rHealth, '{"states":{"5":"ok"},"guard":true,"ok_since":{}}');
		ClusterHealth::usePath($this->rHealth);
		$this->assertSame(['silence'], ClusterHealth::read()['reasons']);
		$this->assertNull(ClusterHealth::read()['ctl_queue']);
		ClusterHealth::write([5 => 'ok'], true);
		$this->assertSame(['silence'], ClusterHealth::read()['reasons']);
		ClusterHealth::write([5 => 'ok'], false, [], ['ctl_queue']);
		$this->assertSame([false, []], [ClusterHealth::read()['guard'], ClusterHealth::read()['reasons']], 'no reason without the guard');
		ClusterHealth::write([5 => 'ok'], true, [], ['ctl_queue', 'bogus', 'silence']);
		ClusterHealth::usePath($this->rHealth);
		$this->assertSame(['silence', 'ctl_queue'], ClusterHealth::read()['reasons'], 'known reasons, in one order');
	}
}
