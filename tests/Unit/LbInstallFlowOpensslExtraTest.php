<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * LbInstallFlow::provisionOpensslExtra — an LB added with server:install must
 * end up with the MAIN's OPENSSL_EXTRA, or every token MAIN mints for a
 * redirect is rejected there. The MAIN's config/openssl_extra is shipped when
 * it holds a value; otherwise both sides run on the built-in default. Whatever
 * a previous MAIN left on a reused host is removed first.
 */
final class LbInstallFlowOpensslExtraTest extends TestCase {

	private string $rLocal;

	/** @var array<int,array<int,string>> */
	private array $rCalls = [];

	public static function setUpBeforeClass(): void {
		// The value init() gives it; the module loader's fallbacks resolve the same directory.
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', MAIN_HOME . 'config/');
		}
	}

	protected function setUp(): void {
		$this->rLocal = sys_get_temp_dir() . '/xcvm_lb_openssl_extra_' . uniqid();
		$this->rCalls = [];
	}

	protected function tearDown(): void {
		@unlink($this->rLocal);
	}

	private function provision(bool $rSendOk = true): bool {
		$rRunSSH = function ($rConn, string $rCommand): array {
			$this->rCalls[] = ['run', $rCommand];
			return ['output' => '', 'error' => ''];
		};
		$rSendFileSSH = function ($rConn, string $rPath, string $rOutput, bool $rWarn = false) use ($rSendOk): bool {
			$this->rCalls[] = ['send', $rPath, $rOutput];
			return $rSendOk;
		};

		return LbInstallFlow::provisionOpensslExtra(null, $rRunSSH, $rSendFileSSH, $this->rLocal);
	}

	private function clear(): array {
		return ['run', 'sudo rm -f ' . CONFIG_PATH . 'openssl_extra'];
	}

	public function testShipsTheMainsValueAndLocksItDown(): void {
		file_put_contents($this->rLocal, 'main-extra');

		$this->assertTrue($this->provision());
		$this->assertSame([
			$this->clear(),
			['send', $this->rLocal, CONFIG_PATH . 'openssl_extra'],
			['run', 'sudo chmod 600 ' . CONFIG_PATH . 'openssl_extra'],
		], $this->rCalls);
	}

	public function testWithoutAValueOnTheMainOnlyAStaleFileIsRemoved(): void {
		$this->assertTrue($this->provision(), 'no file');
		$this->assertSame([$this->clear()], $this->rCalls);

		foreach (['', " \n"] as $rEmpty) {
			$this->rCalls = [];
			file_put_contents($this->rLocal, $rEmpty);
			$this->assertTrue($this->provision());
			$this->assertSame([$this->clear()], $this->rCalls);
		}
	}

	public function testAFailedUploadFailsTheStep(): void {
		file_put_contents($this->rLocal, 'main-extra');

		$this->assertFalse($this->provision(false));
		$this->assertNotContains(['run', 'sudo chmod 600 ' . CONFIG_PATH . 'openssl_extra'], $this->rCalls);
	}

	/** Shipped as root before config/ is handed to xc_vm, so FPM can read it. */
	public function testProvisionConfigShipsTheValueBeforeHandingConfigToXcVm(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$rStart = strpos($rSource, 'function provisionConfig(');
		$this->assertNotFalse($rStart);
		$rEnd = strpos($rSource, "\n\t}\n", $rStart);
		$rBody = substr($rSource, $rStart, $rEnd - $rStart);

		$rProvision = strpos($rBody, 'self::provisionOpensslExtra(');
		$rChown = strpos($rBody, "'sudo chown -R xc_vm:xc_vm '");
		$this->assertNotFalse($rProvision, 'provisionConfig ships OPENSSL_EXTRA');
		$this->assertNotFalse($rChown);
		$this->assertLessThan($rChown, $rProvision);
	}
}
