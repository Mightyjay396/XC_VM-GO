<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\CounterRateSampler;

/**
 * CounterRateSampler — the per-second rate of a monotonic counter (nginx's
 * total request count) across processes. The watchdog runs one pass per
 * process and re-execs itself, so a previous reading held in a local variable
 * was always null and servers.requests_per_second was 0 on every node. The
 * sampler keeps the previous reading in a state file instead.
 */
final class CounterRateSamplerTest extends TestCase {

	private string $rFile;

	protected function setUp(): void {
		$this->rFile = sys_get_temp_dir() . '/xcvm_rate_' . uniqid('', true) . '.json';
	}

	protected function tearDown(): void {
		foreach (glob($this->rFile . '*') ?: [] as $rPath) {
			@unlink($rPath);
		}
	}

	public function testTheFirstSampleIsZeroAndStoresTheReading(): void {
		$this->assertSame(0, (new CounterRateSampler($this->rFile))->sample(1000, 100));
		$this->assertFileExists($this->rFile);
	}

	public function testRateIsTheCounterDeltaPerSecondAcrossInstances(): void {
		(new CounterRateSampler($this->rFile))->sample(1000, 100);

		// A fresh instance, as a re-exec'd watchdog pass would build.
		$this->assertSame(100, (new CounterRateSampler($this->rFile))->sample(1500, 105));
		$this->assertSame(33, (new CounterRateSampler($this->rFile))->sample(1600, 108));
	}

	public function testACounterThatWentDownIsZeroAndStillStored(): void {
		$rSampler = new CounterRateSampler($this->rFile);
		$rSampler->sample(5000, 100);

		// nginx restarted: its counter starts over.
		$this->assertSame(0, $rSampler->sample(40, 104));
		// The next reading is measured from the stored post-restart value.
		$this->assertSame(10, $rSampler->sample(80, 108));
	}

	public function testTheSameTimestampIsZero(): void {
		$rSampler = new CounterRateSampler($this->rFile);
		$rSampler->sample(1000, 100);

		$this->assertSame(0, $rSampler->sample(1500, 100));
		$this->assertSame(0, $rSampler->sample(2000, 99), 'a clock that stepped back');
	}

	public function testAReadingOlderThanMaxAgeIsZero(): void {
		$rSampler = new CounterRateSampler($this->rFile, 120);
		$rSampler->sample(1000, 100);

		$this->assertSame(0, $rSampler->sample(100000, 221));
		$this->assertSame(10, $rSampler->sample(101200, 341), '120 s is still in range');
	}

	public function testTheDefaultMaxAgeIs120Seconds(): void {
		$rSampler = new CounterRateSampler($this->rFile);
		$rSampler->sample(1000, 100);
		$this->assertSame(0, $rSampler->sample(2210, 221));
		$this->assertSame(10, $rSampler->sample(3410, 341), '120 s is in range by default');
	}

	public function testACorruptStateFileIsZeroAndOverwritten(): void {
		file_put_contents($this->rFile, 'not json {');
		$rSampler = new CounterRateSampler($this->rFile);

		$this->assertSame(0, $rSampler->sample(1000, 100));
		$this->assertSame(100, $rSampler->sample(1500, 105));

		file_put_contents($this->rFile, '{"v":"x","t":null}');
		$this->assertSame(0, $rSampler->sample(2000, 110));
	}

	public function testAnUnwritableStateFileIsZeroWithoutAWarning(): void {
		$rSampler = new CounterRateSampler(sys_get_temp_dir() . '/xcvm_missing_' . uniqid('', true) . '/state.json');

		$this->assertSame(0, $rSampler->sample(1000, 100));
		$this->assertSame(0, $rSampler->sample(1500, 105));
	}

	public function testWatchdogSamplesNginxRequestsThroughTheStateFile(): void {
		$rPath = MAIN_HOME . 'Cli/Commands/WatchdogCommand.php';
		$this->assertFileExists($rPath);
		$rSource = (string) file_get_contents($rPath);

		$this->assertStringContainsString('SystemInfo::nginxRequestCount(', $rSource);
		$this->assertStringContainsString('new CounterRateSampler(TMP_PATH . ', $rSource);
		$this->assertStringNotContainsString('$rLastRequests', $rSource, 'a per-process previous reading is always null');
	}
}
