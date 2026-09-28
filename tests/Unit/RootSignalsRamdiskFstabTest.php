<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * The enable_ramdisk / disable_ramdisk root actions edit the streams tmpfs
 * line in /etc/fstab. They compared a 31/32-character substr() with the
 * 33/34-character mount line, which never matched, so the toggle never
 * touched fstab and the ramdisk came back (or stayed off) on the next boot.
 */
final class RootSignalsRamdiskFstabTest extends TestCase {

	private const STREAMS = 'tmpfs /home/xc_vm/content/streams tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0';
	private const STREAMS_LB = 'tmpfs /home/xc_vm/content/streams/ tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0';
	private const TMP = 'tmpfs /home/xc_vm/tmp tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=6G 0 0';
	private const ROOT = 'UUID=abcd / ext4 errors=remount-ro 0 1';

	private function fstab(string ...$rLines): string {
		return implode("\n", $rLines) . "\n";
	}

	public function testDisableCommentsOutOnlyTheStreamsMount(): void {
		foreach ([self::STREAMS, self::STREAMS_LB] as $rMount) {
			$this->assertSame(
				$this->fstab(self::ROOT, '#' . $rMount, self::TMP),
				RootSignalsCronJob::ramdiskFstab($this->fstab(self::ROOT, $rMount, self::TMP), false),
				$rMount
			);
		}
	}

	public function testEnableUncommentsTheStreamsMount(): void {
		foreach ([self::STREAMS, self::STREAMS_LB] as $rMount) {
			$this->assertSame(
				$this->fstab(self::ROOT, $rMount, self::TMP),
				RootSignalsCronJob::ramdiskFstab($this->fstab(self::ROOT, '#' . $rMount, self::TMP), true),
				$rMount
			);
		}
	}

	public function testALineAlreadyInTheWantedStateIsLeftAlone(): void {
		$rOn = $this->fstab(self::ROOT, self::STREAMS, self::TMP);
		$rOff = $this->fstab(self::ROOT, '#' . self::STREAMS, self::TMP);
		$this->assertSame($rOn, RootSignalsCronJob::ramdiskFstab($rOn, true));
		$this->assertSame($rOff, RootSignalsCronJob::ramdiskFstab($rOff, false));
	}

	public function testDisableThenEnableRoundTrips(): void {
		$rOn = $this->fstab(self::ROOT, self::STREAMS, '#' . self::TMP);
		$this->assertSame($rOn, RootSignalsCronJob::ramdiskFstab(RootSignalsCronJob::ramdiskFstab($rOn, false), true));
	}
}
