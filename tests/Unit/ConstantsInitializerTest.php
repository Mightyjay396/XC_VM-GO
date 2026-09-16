<?php

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;

/**
 * Unit coverage for the constants source of truth. The pure map methods are the
 * point of the refactor: they can be evaluated with different MAIN_HOME/BIN_PATH
 * values in a single process — the raw define() constants they feed cannot.
 */
final class ConstantsInitializerTest extends TestCase {
	public function testPathsDeriveFromMainHome(): void {
		$paths = ConstantsInitializer::paths('/home/xc_vm/');

		$this->assertSame('/home/xc_vm/content/', $paths['CONTENT_PATH']);
		$this->assertSame('/home/xc_vm/tmp/', $paths['TMP_PATH']);
		$this->assertSame('/home/xc_vm/config/', $paths['CONFIG_PATH']);
		$this->assertSame('/home/xc_vm/bin/', $paths['BIN_PATH']);
		$this->assertSame('/home/xc_vm/content/streams/', $paths['STREAMS_PATH']);
		$this->assertSame('/home/xc_vm/tmp/cache/streams/', $paths['STREAMS_TMP_PATH']);
		$this->assertSame('/home/xc_vm/storage/images/enigma2/', $paths['E2_IMAGES_PATH']);
		$this->assertSame('/home/xc_vm/bin/xc_fanout/sockets/control.sock', $paths['FANOUT_CTL_SOCK']);
	}

	public function testPathsCount(): void {
		$this->assertCount(33, ConstantsInitializer::paths('/x/'));
	}

	/** The core win: two roots resolved in ONE process — impossible with define(). */
	public function testPathsVaryByRootInOneProcess(): void {
		$a = ConstantsInitializer::paths('/srv/a/');
		$b = ConstantsInitializer::paths('/srv/b/');

		$this->assertSame('/srv/a/content/streams/', $a['STREAMS_PATH']);
		$this->assertSame('/srv/b/content/streams/', $b['STREAMS_PATH']);
		$this->assertNotSame($a['STREAMS_PATH'], $b['STREAMS_PATH']);
	}

	public function testAppConfigValues(): void {
		$cfg = ConstantsInitializer::appConfig();

		$this->assertCount(12, $cfg);
		$this->assertSame('2.5.1', $cfg['XC_VM_VERSION']);
		$this->assertFalse($cfg['DEV_MODE']);
		$this->assertFalse($cfg['DB_ACCESS_ENABLED']);
		$this->assertSame('', $cfg['DB_ACCESS_PWD']);
		$this->assertSame('Vateron-Media', $cfg['GIT_OWNER']);
		$this->assertSame('XC_VM', $cfg['GIT_REPO_MAIN']);
		$this->assertSame(3, $cfg['MONITOR_CALLS']);
	}

	public function testBinariesDeriveFromBinPath(): void {
		$bin = ConstantsInitializer::binaries('/home/xc_vm/bin/');

		$this->assertCount(8, $bin);
		$this->assertSame('/home/xc_vm/bin/php/bin/php', $bin['PHP_BIN']);
		$this->assertSame('/home/xc_vm/bin/ffmpeg_bin/4.0/ffmpeg', $bin['FFMPEG_BIN_40']);
		$this->assertSame('/home/xc_vm/bin/ffmpeg_bin/4.0/ffprobe', $bin['FFPROBE_BIN_40']);
		$this->assertSame('/home/xc_vm/bin/maxmind/GeoIP2-ISP.mmdb', $bin['GEOISP_BIN']);
	}

	public function testStatusesAreSequentialFromZero(): void {
		$statuses = ConstantsInitializer::statuses();

		$this->assertCount(49, $statuses);
		$this->assertSame(0, $statuses['STATUS_FAILURE']);
		$this->assertSame(1, $statuses['STATUS_SUCCESS']);
		$this->assertSame(48, $statuses['STATUS_NO_SOURCE']);
		$this->assertSame(range(0, 48), array_values($statuses));
	}

	/**
	 * Isolated so the define()s do not leak into the shared test process and
	 * pre-empt constants other tests define themselves (e.g. STREAMS_PATH).
	 * Only constants NOT already defined by tests/bootstrap.php are asserted
	 * (BIN_PATH/PHP_BIN/VOD_PATH are pre-defined there and stay guarded).
	 */
	#[RunInSeparateProcess]
	public function testInitDefinesConstantsFromRoot(): void {
		ConstantsInitializer::init('/opt/xcvm/');

		$this->assertSame('/opt/xcvm/content/', CONTENT_PATH);
		$this->assertSame('/opt/xcvm/config/', CONFIG_PATH);
		$this->assertSame('/opt/xcvm/content/streams/', STREAMS_PATH);
		$this->assertSame('2.5.1', XC_VM_VERSION);
	}

	/** Isolated: STATUS_* are process-global one-shot constants. */
	#[RunInSeparateProcess]
	public function testInitStatusDefinesStatusConstants(): void {
		ConstantsInitializer::initStatus();

		$this->assertSame(0, STATUS_FAILURE);
		$this->assertSame(1, STATUS_SUCCESS);
		$this->assertSame(48, STATUS_NO_SOURCE);
	}
}
