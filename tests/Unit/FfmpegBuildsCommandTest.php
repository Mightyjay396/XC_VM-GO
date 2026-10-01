<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FfmpegBuildsCommand;

/**
 * FfmpegBuildsCommand: the release's asset name for this distribution, and a build
 * put in place only once it runs here, the installed one kept otherwise.
 */
final class FfmpegBuildsCommandTest extends TestCase {
	private string $rBase;

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/ffmpeg_bin_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . '7.1', 0755, true);
		file_put_contents($this->rBase . '7.1/ffmpeg', 'the installed build');
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	public function testTheAssetIsNamedForThisDistribution(): void {
		$this->assertSame('ubuntu_22', FfmpegBuildsCommand::distro('ubuntu', '22.04'));
		$this->assertSame('ubuntu_20', FfmpegBuildsCommand::distro('ubuntu', '20.04'));
		$this->assertSame('debian_12', FfmpegBuildsCommand::distro('debian', '12'));
		$this->assertSame('debian_13', FfmpegBuildsCommand::distro('debian', '13'));
		$this->assertNull(FfmpegBuildsCommand::distro('rocky', '9.4'), 'not supported: no builds');
		$this->assertNull(FfmpegBuildsCommand::distro('ubuntu', '18.04'));
		$this->assertNull(FfmpegBuildsCommand::distro('arch', ''));
		$this->assertSame(['4.0', '7.1', '8.1'], FfmpegBuildsCommand::LABELS);
	}

	public function testABuildIsPlacedOnlyOnceItRunsHere(): void {
		$rOk = "#!/bin/sh\necho ffmpeg version 7.1.5\n";
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => "Label : 7.1\n", 'extra' => 'x']), '7.1'));
		$this->assertSame($rOk, file_get_contents($this->rBase . '7.1/ffmpeg'));
		$this->assertFileExists($this->rBase . '7.1/BUILD_INFO');
		$this->assertFileDoesNotExist($this->rBase . '7.1/extra', 'only the three names a build has');
		$this->assertSame(0755, fileperms($this->rBase . '7.1/ffprobe') & 0777);
		$this->assertSame(['7.1'], $this->entries(), 'nothing left aside');

		// One that does not start here (another glibc): the installed one stays.
		$rWhy = (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => "#!/bin/sh\nexit 127\n", 'BUILD_INFO' => 'x']), '7.1');
		$this->assertSame('ffprobe does not run on this node', $rWhy);
		$this->assertSame($rOk, file_get_contents($this->rBase . '7.1/ffmpeg'));
		$rWhy = (new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk]), '7.1');
		$this->assertSame('the archive does not hold ffmpeg, ffprobe and BUILD_INFO', $rWhy);
		$this->assertSame(['7.1'], $this->entries());

		// A label the node has no build of yet.
		$this->assertNull((new FfmpegBuildsCommand($this->rBase))->place($this->archive(['ffmpeg' => $rOk, 'ffprobe' => $rOk, 'BUILD_INFO' => 'x']), '8.1'));
		$this->assertSame(['7.1', '8.1'], $this->entries());
	}

	/** @param array<string, string> $rFiles */
	private function archive(array $rFiles): string {
		$rDir = $this->rBase . '../src_' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		foreach ($rFiles as $rName => $rBody) {
			file_put_contents($rDir . $rName, $rBody);
		}
		$rArchive = $this->rBase . '.test_' . bin2hex(random_bytes(4)) . '.tar.gz';
		exec('tar -czf ' . escapeshellarg($rArchive) . ' -C ' . escapeshellarg($rDir) . ' ' . implode(' ', array_map('escapeshellarg', array_keys($rFiles))) . ' && rm -rf ' . escapeshellarg($rDir));
		return $rArchive;
	}

	/** @return list<string> what ffmpeg_bin/ holds besides the test's archives */
	private function entries(): array {
		$rAll = array_values(array_filter(scandir($this->rBase), static fn(string $rN): bool => !in_array($rN, ['.', '..'], true) && !str_starts_with($rN, '.test_')));
		sort($rAll);
		return $rAll;
	}
}
