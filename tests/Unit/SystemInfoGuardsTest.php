<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\SystemInfo;

/**
 * Two SystemInfo::getStats() fatals that aborted the watchdog pass, so the
 * node wrote no heartbeat and showed offline:
 *
 * - the memory percentage divided by total_mem, which getMemory() reports as
 *   0 when /proc/meminfo has no MemAvailable or cannot be read
 *   (DivisionByZeroError);
 * - nvidia-smi XML with exactly one GPU process decodes process_info as one
 *   associative array, so iterating it yielded strings and `$rProcess['pid']`
 *   threw a TypeError.
 */
final class SystemInfoGuardsTest extends TestCase {

	public function testMemoryPercentWithNoTotalIsZero(): void {
		$this->assertSame(0.0, SystemInfo::memUsedPercent(0, 0));
		$this->assertSame(0.0, SystemInfo::memUsedPercent(512, 0));
	}

	public function testMemoryPercentIsRoundedToTwoPlaces(): void {
		$this->assertSame(25.0, SystemInfo::memUsedPercent(1024, 4096));
		$this->assertSame(33.33, SystemInfo::memUsedPercent(1, 3));
	}

	public function testASingleGpuProcessIsWrappedInAList(): void {
		$rInstance = ['processes' => ['process_info' => ['pid' => '4242', 'type' => 'C', 'process_name' => 'ffmpeg', 'used_memory' => '312 MiB']]];

		$this->assertSame([['pid' => 4242, 'memory' => '312 MiB']], SystemInfo::gpuProcesses($rInstance));
	}

	public function testAListOfGpuProcessesIsMappedInOrder(): void {
		$rInstance = ['processes' => ['process_info' => [
			['pid' => '11', 'used_memory' => '100 MiB'],
			['pid' => '12', 'used_memory' => '200 MiB'],
		]]];

		$this->assertSame(
			[['pid' => 11, 'memory' => '100 MiB'], ['pid' => 12, 'memory' => '200 MiB']],
			SystemInfo::gpuProcesses($rInstance)
		);
	}

	public function testNoGpuProcessesIsAnEmptyList(): void {
		$this->assertSame([], SystemInfo::gpuProcesses([]));
		// An empty <processes/> element decodes to an empty array.
		$this->assertSame([], SystemInfo::gpuProcesses(['processes' => []]));
		$this->assertSame([], SystemInfo::gpuProcesses(['processes' => 'N/A']));
		$this->assertSame([], SystemInfo::gpuProcesses(['processes' => ['process_info' => []]]));
	}

	public function testGpuProcessesDecodedFromNvidiaSmiXml(): void {
		$rXML = '<?xml version="1.0" ?><nvidia_smi_log><gpu id="00000000:01:00.0"><processes>'
			. '<process_info><pid>777</pid><type>C</type><process_name>ffmpeg</process_name><used_memory>150 MiB</used_memory></process_info>'
			. '</processes></gpu></nvidia_smi_log>';
		$rGPU = json_decode(json_encode(simplexml_load_string($rXML)), true)['gpu'];

		$this->assertSame([['pid' => 777, 'memory' => '150 MiB']], SystemInfo::gpuProcesses($rGPU));
	}

	public function testGetStatsUsesTheGuardedHelpers(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Core/Util/SystemInfo.php');

		$this->assertStringContainsString("self::memUsedPercent(\$rJSON['total_mem_used'], \$rJSON['total_mem'])", $rSource);
		$this->assertStringContainsString("\$rArray['processes'] = self::gpuProcesses(\$rInstance);", $rSource);
		$this->assertStringNotContainsString("\$rInstance['processes']['process_info'] as", $rSource);
	}
}
