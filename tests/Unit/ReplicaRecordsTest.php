<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterApplyCommand;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaRecords;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Tests\Support\ReplicaFixture;

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
		file_put_contents($this->rFixture->dir() . 'crontab.rep', $this->rFixture->record('rep', ['section' => 'crontab', 'node' => $this->rFixture->rUuid, 'etag' => str_repeat('c', 64), 'data' => ['jobs' => []]], ReplicaFixture::otherPanel()));
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

	public function testADeltaRemovesBeforeItAddsAndTheAddressesComeOutSorted(): void {
		$this->rFixture->blocklist(['ip' => ['203.0.113.2'], 'asn' => []], 7);
		// Removed and added again in one delta: still blocked, as the agent's materialise has it.
		$this->rFixture->delta(8, ['203.0.113.2', '203.0.113.10', '198.51.100.1'], ['203.0.113.2']);
		$this->fromDisk();
		$this->assertSame(['198.51.100.1', '203.0.113.10', '203.0.113.2'], FileCache::getCache('blocked_ips'));
	}

	public function testEveryRecordIsCheckedForWhatItClaims(): void {
		$this->rFixture->node();
		$this->assertSame([], $this->fromDisk()['from_disk']['unverified']);
		// An ETag that is not MAIN's (64 hex digits), in a whole section and in the blocklist.
		$this->rFixture->whole('crontab', ['jobs' => []], null, ['etag' => 'x']);
		$rBlocklist = ['v' => 1, 'section' => 'blocklist', 'node' => $this->rFixture->rUuid, 'etag' => 'x', 'seq' => 7, 'data' => ['ip' => []]];
		file_put_contents($this->rFixture->dir() . 'blocklist.rep', $this->rFixture->record('rep', $rBlocklist));
		$this->assertSame(['blocklist', 'crontab'], $this->fromDisk()['from_disk']['unverified']);
		// Another section's record where the blocklist's goes, a seq and all.
		$this->rFixture->node();
		$this->rFixture->whole('settings', ['server_name' => 'Panel'], null, ['seq' => 7]);
		copy($this->rFixture->dir() . 'settings.rep', $this->rFixture->dir() . 'blocklist.rep');
		$this->assertSame(['blocklist'], $this->fromDisk()['from_disk']['unverified']);
	}

	public function testAStreamRecordIsCheckedForWhatItClaims(): void {
		$rIdentity = ReplicaRecords::identity($this->rDir . 'cluster/agent.json');
		$rData = ReplicaFixture::streamData(10, 5);
		$rEtag = $this->rFixture->stream(10, $rData, 3);
		$this->assertSame(['etag' => $rEtag, 'ver' => 3, 'data' => $rData], ReplicaRecords::stream($this->rFixture->dir(), 10, $rIdentity));
		foreach ([
			'another stream\'s record' => ['stream_id' => 11],
			'another section\'s record' => ['section' => 'settings'],
			'another node\'s record' => ['node' => '00000000-0000-4000-a000-000000000000'],
			'a version that is not an integer' => ['ver' => '3'],
			'an ETag that is not MAIN\'s' => ['etag' => strtoupper($rEtag)],
			'no data' => ['data' => 'x'],
		] as $rWhy => $rOver) {
			$this->rFixture->stream(10, $rData, 3, null, $rOver);
			$this->assertFalse(ReplicaRecords::stream($this->rFixture->dir(), 10, $rIdentity), $rWhy);
		}
		// Another stream's valid record under this one's name.
		$this->rFixture->stream(11, ReplicaFixture::streamData(11, 5));
		copy($this->rFixture->dir() . 'streams/11.rep', $this->rFixture->dir() . 'streams/10.rep');
		$this->assertFalse(ReplicaRecords::stream($this->rFixture->dir(), 10, $rIdentity), 'named after another stream');
		// A record without its .json is not one the agent stored.
		$this->rFixture->stream(10, $rData, 3);
		unlink($this->rFixture->dir() . 'streams/10.json');
		$this->assertNull(ReplicaRecords::stream($this->rFixture->dir(), 10, $rIdentity));
		$this->assertNull(ReplicaRecords::stream($this->rFixture->dir(), 12, $rIdentity), 'none at all');
	}

	public function testFromDiskRtmpPublishersAreNotResolvedAndTheWholeSectionsComeFirst(): void {
		$this->rFixture->node();
		$rRow = ['id' => 1, 'ip' => 'localhost', 'password' => 'pw', 'push' => 1, 'pull' => 0];
		$this->rFixture->blocklist(['ip' => [], 'asn' => [], 'ua' => [], 'isp' => [], 'rtmp' => [$rRow, ['ip' => '198.51.100.9'] + $rRow]], 7);
		$rReport = $this->fromDisk();
		// DNS may not answer at boot: a name stays as given until the next apply resolves it.
		$this->assertSame(['localhost', '198.51.100.9'], array_keys(FileCache::getCache('rtmp_ips')));
		$rKeys = array_keys($rReport);
		$this->assertLessThan(array_search('seq', $rKeys, true), array_search('crontab', $rKeys, true), 'the sections a boot needs are applied before the blocklist');
		ReplicaApply::run(true, 1800000000, 5);
		$this->assertSame(['127.0.0.1', '198.51.100.9'], array_keys(FileCache::getCache('rtmp_ips')));
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
