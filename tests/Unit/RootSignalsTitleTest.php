<?php

use PHPUnit\Framework\TestCase;

/**
 * cron:root_signals (root, every minute) killed every process titled
 * XC_VM[Signals] and then took that title itself: it killed the xc_vm
 * signals daemon each minute, and cron:servers took the root run for the
 * daemon and did not start it again. Each has a title of its own.
 */
final class RootSignalsTitleTest extends TestCase {
	public function testTheRootCronNeverKillsNorPassesForTheSignalsDaemon(): void {
		$rRoot = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$rDaemon = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/SignalsCommand.php');
		$this->assertStringContainsString("setProcessTitle('XC_VM[Signals]')", $rDaemon);
		$this->assertStringNotContainsString('XC_VM[Signals]', $rRoot, 'the daemon\'s title');
		$this->assertStringNotContainsString('XC_VM\\[Signals\\]', $rRoot, 'a pattern that matches the daemon');
		$this->assertStringContainsString("cli_set_process_title('XC_VM[RootSignals]')", $rRoot);
		$this->assertStringContainsString("pgrep -f 'XC_VM\\[RootSignals\\]'", $rRoot);
	}
}
