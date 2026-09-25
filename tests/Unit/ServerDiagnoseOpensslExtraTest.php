<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerDiagnoseCommand;
use XcVm\Core\Config\OpensslExtra;

/**
 * server:diagnose — compares the OPENSSL_EXTRA fingerprint a node publishes in
 * server_hardware with the MAIN's. A mismatch means every token MAIN mints for
 * a redirect is rejected by that node. A node that publishes none runs an
 * older build; proxies publish none and are not checked.
 */
final class ServerDiagnoseOpensslExtraTest extends TestCase {

	private function row(int $rID, ?string $rValue, array $rOverride = []): array {
		$rHardware = ['cores' => 4];
		if ($rValue !== null) {
			$rHardware[OpensslExtra::HARDWARE_KEY] = OpensslExtra::fingerprint($rValue);
		}

		return array_merge(['id' => $rID, 'is_main' => (int) ($rID === 1), 'server_type' => 0, 'server_hardware' => json_encode($rHardware)], $rOverride);
	}

	/** @return array{0:string,1:string[]} [output, problems] */
	private function check(array $rServer, ?array $rMain): array {
		$rMethod = new ReflectionMethod(ServerDiagnoseCommand::class, 'opensslExtraSection');
		$rMethod->setAccessible(true);
		$rProblems = [];
		ob_start();
		$rMethod->invokeArgs(new ServerDiagnoseCommand(), [$rServer, $rMain, &$rProblems]);

		return [(string) ob_get_clean(), $rProblems];
	}

	public function testAMismatchIsReportedAsAProblem(): void {
		[$rOutput, $rProblems] = $this->check($this->row(2, 'fNiu3XD448xTDa27xoY4'), $this->row(1, 'random-main-extra'));

		$this->assertStringContainsString('[WARN]', $rOutput);
		$this->assertCount(1, $rProblems);
		$this->assertStringStartsWith('OPENSSL_EXTRA mismatch: tokens minted on MAIN are rejected by this node', $rProblems[0]);
	}

	public function testMatchingValuesPass(): void {
		[$rOutput, $rProblems] = $this->check($this->row(2, 'same-extra'), $this->row(1, 'same-extra'));

		$this->assertStringContainsString('[OK]', $rOutput);
		$this->assertSame([], $rProblems);
	}

	public function testAMissingFingerprintReadsAsUnknown(): void {
		[$rOutput, $rProblems] = $this->check($this->row(2, null), $this->row(1, 'main-extra'));
		$this->assertStringContainsString('unknown (node not updated)', $rOutput);
		$this->assertSame([], $rProblems);

		[$rOutput, $rProblems] = $this->check($this->row(2, 'lb-extra'), $this->row(1, null));
		$this->assertStringContainsString('unknown (main not updated)', $rOutput);
		$this->assertSame([], $rProblems);
	}

	public function testProxiesAndAMissingMainAreSkipped(): void {
		$this->assertSame(['', []], $this->check($this->row(3, null, ['server_type' => 1]), $this->row(1, 'main-extra')));
		$this->assertSame(['', []], $this->check($this->row(2, 'lb-extra'), null));
	}
}
