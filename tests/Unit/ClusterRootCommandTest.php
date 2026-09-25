<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Cli\Commands\ClusterRootCommand;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\RootPin;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Phase 4 root commands: root trusts only its own pin of the panel key, runs
 * a node.root command at most once, and writes its result without following
 * anything planted in the xc_vm-writable inbox.
 */
final class ClusterRootCommandTest extends TestCase {
	private const NODE = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rBase;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/rootpin_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . 'etc', 0755, true);
		mkdir($this->rBase . 'inbox', 0700, true);
		RootPin::useDirs($this->rBase . 'etc/', $this->rBase . 'inbox/');
		$this->rCrypto = new FakeClusterCrypto();
		$this->assertTrue(RootPin::write($this->rCrypto->info()['panel_sign_pub'], self::NODE));
	}

	protected function tearDown(): void {
		RootPin::useDirs(null, null);
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private function command(int $rSeq, array $rOver = [], string $rTag = 'cmd'): array {
		$rDoc = (string) json_encode($rOver + ['v' => 1, 'type' => 'node.root', 'exp' => 1800000600, 'iat' => 1800000000, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $rSeq, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null, 'args' => ['action' => 'reload_nginx']]);
		return ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign($rTag, $rDoc))];
	}

	private function inbox(int $rSeq, array $rCmd): void {
		file_put_contents($this->rBase . 'inbox/' . $rSeq . '.json', json_encode($rCmd));
	}

	public function testThePinIsReadOnlyFromASafeDirectory(): void {
		$rPin = RootPin::read();
		$this->assertSame([$this->rCrypto->info()['panel_sign_pub'], self::NODE], [$rPin['pub'], $rPin['node']]);
		$this->assertTrue(RootPin::matches($this->rCrypto->info()['panel_sign_pub'], self::NODE));
		$this->assertFalse(RootPin::matches(str_repeat("\1", 32), self::NODE));
		chmod($this->rBase . 'etc', 0775);
		$this->assertNull(RootPin::read(), 'a group-writable directory is not trusted');
	}

	public function testVerification(): void {
		$rPin = RootPin::read();
		$rOk = $this->command(3);
		$this->assertIsArray(RootPin::verify($rPin, $rOk['doc'], (string) Enc::b64urlDecode($rOk['sig']), 1800000000, 2));
		$rCases = [
			'bad signature' => [$this->command(3, [], 'den'), 2],
			'another node' => [$this->command(3, ['node_uuid' => '11111111-1111-4111-a111-111111111111']), 2],
			'not root' => [$this->command(3, ['type' => 'node.rpc']), 2],
			'stale' => [$this->command(3, ['exp' => 1799999000]), 2],
			'replay' => [$this->command(3), 3],
			'unknown action' => [$this->command(3, ['args' => ['action' => 'rm_rf']]), 2],
		];
		foreach ($rCases as $rName => [$rCmd, $rHigh]) {
			$this->assertIsString(RootPin::verify($rPin, $rCmd['doc'], (string) Enc::b64urlDecode($rCmd['sig']), 1800000000, $rHigh), $rName);
		}
	}

	public function testDrainRunsEachCommandOnce(): void {
		$rRan = [];
		$rRun = static function (array $rAction) use (&$rRan): string {
			$rRan[] = $rAction['action'];
			return "Reloading nginx...\n";
		};
		$this->inbox(1, $this->command(1));
		$this->inbox(2, $this->command(2, ['args' => ['action' => 'update']]));
		$rDone = ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertSame(['reload_nginx', 'update'], $rRan);
		$this->assertSame(2, RootPin::highWater());
		$this->assertSame(['ok' => true, 'result' => 'Reloading nginx...'], json_decode((string) file_get_contents($this->rBase . 'inbox/1.done'), true));
		$this->assertCount(2, $rDone);
		$this->assertSame([], glob($this->rBase . 'inbox/*.json'), 'the inbox is emptied');

		// The same command again (a replay into the inbox): refused, not run.
		$this->inbox(2, $this->command(2));
		ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertCount(2, $rRan);
		$this->assertStringContainsString('refused by root', (string) file_get_contents($this->rBase . 'inbox/2.done'));
	}

	public function testAPlantedSymlinkIsNotFollowed(): void {
		$rTarget = $this->rBase . 'precious';
		file_put_contents($rTarget, 'keep me');
		symlink($rTarget, $this->rBase . 'inbox/5.done');
		$this->inbox(5, $this->command(5));
		ClusterRootCommand::drain(static fn() => 'x', 1800000000);
		$this->assertSame('keep me', file_get_contents($rTarget));
		$this->assertFalse(is_link($this->rBase . 'inbox/5.done'));
	}

	public function testExecHandsRootCommandsOver(): void {
		ob_start();
		$rCode = ClusterExecCommand::handToRoot($this->command(7), 7, 0);
		$rOut = ob_get_clean();
		$this->assertSame(0, $rCode);
		$this->assertSame('{"queued":true}', $rOut, 'no root result yet: acked as queued');
		$this->assertFileExists($this->rBase . 'inbox/7.json');
		// Root picks it up and answers.
		ClusterRootCommand::drain(static fn() => 'done', 1800000000);
		$this->assertSame(7, RootPin::highWater());
	}
}
