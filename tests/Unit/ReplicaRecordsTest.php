<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterApplyCommand;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaRecords;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;

/**
 * `cluster:apply --from-disk` (cluster plan, section 9, "Storage and boot"):
 * at boot the agent may not run yet, so each section is taken from the
 * sealed, panel-signed record the agent stored, once it opens with the
 * node's key and verifies under the panel key the agent pinned, for this
 * node and this section; never from the unsigned `.json` beside it. A record
 * that does not verify writes no cache, and hands back one the replica
 * owned. The report names sections, never their content.
 */
final class ReplicaRecordsTest extends TestCase {
	private string $rDir;

	private ReplicaFixture $rFixture;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-records-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		$this->rFixture = new ReplicaFixture($this->rDir . 'cluster/');
		ReplicaApply::useDir($this->rFixture->dir());
		ReplicaApply::useConfigDir($this->rDir);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::CONFIG, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . 'flows.json');
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		OpensslExtra::usePrevFile(null);
		NodeFlows::usePath(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array<string, mixed> */
	private function fromDisk(): array {
		$rReport = ReplicaApply::run(true, 1800000000, 5, true);
		$this->assertIsArray($rReport);
		return $rReport;
	}

	public function testTheRecordsAreAppliedNotTheJsonBesideThem(): void {
		$this->rFixture->node();
		// The .json files say something else (torn, stale or planted): the signed records win.
		$this->rFixture->whole('crontab', ['jobs' => [['filename' => 'cache', 'time' => '* * * * *']]], ['jobs' => [['filename' => 'evil', 'time' => '* * * * *']]]);
		$this->rFixture->blocklist(['ip' => ['203.0.113.1'], 'asn' => [64500]], 7, ['ip' => [], 'asn' => []]);
		$rReport = $this->fromDisk();
		$this->assertSame(['verified' => ['blocklist', 'settings', 'servers', 'node', 'crontab', 'secrets'], 'unverified' => []], $rReport['from_disk']);
		$this->assertSame([['filename' => 'cache', 'time' => '* * * * *']], FileCache::getCache(ReplicaApply::CRON_CACHE));
		$this->assertSame(['203.0.113.1'], FileCache::getCache('blocked_ips'));
		$this->assertSame([64500], FileCache::getCache('blocked_servers'));
		$this->assertSame('stream-pass', FileCache::getCache('settings')['live_streaming_pass']);
		$this->assertSame([1, 5], array_keys(FileCache::getCache('servers')));

		// Without --from-disk, the .json files the agent just verified.
		ReplicaApply::run(true, 1800000000, 5);
		$this->assertSame([['filename' => 'evil', 'time' => '* * * * *']], FileCache::getCache(ReplicaApply::CRON_CACHE));
	}

	public function testARecordThatDoesNotVerifyIsNeverApplied(): void {
		$this->rFixture->node();
		$this->fromDisk();
		$this->assertTrue(ReplicaApply::built('crontab'));
		FileCache::setCache(ReplicaApply::CRON_CACHE, [['filename' => 'kept', 'time' => '* * * * *']]);

		$this->rFixture->corrupt('crontab.rep');
		$rReport = $this->fromDisk();
		$this->assertSame(['crontab'], $rReport['from_disk']['unverified']);
		$this->assertSame('refused', $rReport['crontab']['mode']);
		$this->assertFalse(ReplicaApply::built('crontab'), 'handed back to MAIN\'s table');
		$this->assertSame('applied', $rReport['settings']['mode'], 'the other sections still apply');

		// Missing, signed by another panel key, for another node, or another section's record.
		$this->rFixture->node();
		unlink($this->rFixture->dir() . 'node.rep');
		file_put_contents($this->rFixture->dir() . 'crontab.rep', $this->rFixture->record('rep', ['section' => 'crontab', 'node' => $this->rFixture->rUuid, 'etag' => str_repeat('c', 64), 'data' => ['jobs' => []]], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
		file_put_contents($this->rFixture->dir() . 'secrets.rep', $this->rFixture->record('rep', ['section' => 'secrets', 'node' => '00000000-0000-4000-a000-000000000000', 'etag' => str_repeat('c', 64), 'data' => []]));
		copy($this->rFixture->dir() . 'servers.rep', $this->rFixture->dir() . 'settings.rep');
		$rReport = $this->fromDisk();
		$this->assertSame(['blocklist', 'servers'], $rReport['from_disk']['verified']);
		$this->assertSame(['settings', 'node', 'crontab', 'secrets'], $rReport['from_disk']['unverified']);
		foreach (['secrets', 'settings', 'servers', 'crontab'] as $rPart) {
			$this->assertSame('refused', $rReport[$rPart]['mode'], $rPart);
		}
		$this->assertSame([], array_keys((array) FileCache::getCache(ReplicaApply::OWNED_CACHE)), 'every cache is MAIN\'s database\'s again');
	}

	public function testEverythingNeedsTheAgentsKeys(): void {
		$this->rFixture->node();
		// Another panel key pinned (a new root the records predate), then no state at all.
		$this->rFixture->agent(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
		$this->assertSame([], $this->fromDisk()['from_disk']['verified']);
		$this->rFixture->agent(null, '00000000-0000-4000-a000-000000000000');
		$this->assertSame([], $this->fromDisk()['from_disk']['verified'], 'sealed to this key, but for another node');
		unlink($this->rDir . 'cluster/agent.json');
		$rReport = $this->fromDisk();
		$this->assertSame([], $rReport['from_disk']['verified']);
		$this->assertFalse(FileCache::getCache('settings'));
		$this->assertNull(ReplicaRecords::identity($this->rDir . 'cluster/agent.json'));
		file_put_contents($this->rDir . 'cluster/agent.json', json_encode(['node_uuid' => $this->rFixture->rUuid, 'node_box_sk' => base64_encode('short'), 'panel_sign_pub' => base64_encode($this->rFixture->rSignPub)]));
		$this->assertNull(ReplicaRecords::identity($this->rDir . 'cluster/agent.json'));
	}

	public function testTheBlocklistsDeltasAreAppliedInOrder(): void {
		$this->rFixture->blocklist(['ip' => ['203.0.113.1', '203.0.113.2'], 'asn' => []], 7);
		$this->rFixture->delta(8, ['203.0.113.3']);
		$this->rFixture->delta(9, ['203.0.113.4'], ['203.0.113.1']);
		$rReport = $this->fromDisk();
		$this->assertSame(9, $rReport['seq']);
		$this->assertSame(['203.0.113.2', '203.0.113.3', '203.0.113.4'], FileCache::getCache('blocked_ips'));

		// A delta at or below the section's seq, or signed as a whole section, is not the agent's.
		$this->rFixture->delta(6, ['198.51.100.1']);
		$this->assertSame(['blocklist'], $this->fromDisk()['from_disk']['unverified']);
		unlink($this->rFixture->dir() . sprintf('blocklist.d/%019d.blk', 6));
		file_put_contents($this->rFixture->dir() . sprintf('blocklist.d/%019d.blk', 10), $this->rFixture->record('rep', ['seq' => 10, 'add' => ['198.51.100.1'], 'remove' => []]));
		$this->assertSame(['blocklist'], $this->fromDisk()['from_disk']['unverified']);
		$this->assertSame(['203.0.113.2', '203.0.113.3', '203.0.113.4'], FileCache::getCache('blocked_ips'), 'an unverified blocklist writes nothing');
	}

	public function testOnlyWhatTheAgentStoredAndNoContentInTheReport(): void {
		// A record without its .json is not a section the agent stored.
		$this->rFixture->node();
		unlink($this->rFixture->dir() . 'crontab.json');
		$rReport = $this->fromDisk();
		$this->assertNotContains('crontab', $rReport['from_disk']['verified']);
		$this->assertArrayNotHasKey('crontab', $rReport);
		$rJson = (string) json_encode($rReport);
		foreach (['stream-pass', 'extra-from-main', 'Panel'] as $rSecret) {
			$this->assertStringNotContainsString($rSecret, $rJson);
		}

		// Nothing stored at all: nothing to apply (exit 2).
		exec('rm -rf ' . escapeshellarg($this->rFixture->dir()));
		mkdir($this->rFixture->dir());
		$this->assertNull(ReplicaApply::run(true, 1800000000, 5, true));
		$this->rFixture->node();
		ob_start();
		$rExit = (new ClusterApplyCommand())->execute(['--from-disk']);
		$rOut = (string) ob_get_clean();
		$this->assertSame(0, $rExit);
		$this->assertSame(['blocklist', 'settings', 'servers', 'node', 'crontab', 'secrets'], json_decode($rOut, true)['from_disk']['verified']);
	}
}
