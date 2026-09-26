<?php

namespace XcVm\Core\Util;

use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Server\ServerRepository;

/**
 * System Information
 *
 * Collects server hardware/OS metrics: CPU, memory, disk, network,
 * GPU, I/O, audio/video devices.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SystemInfo {
	/**
	 * Aggregate server statistics.
	 *
	 * Collects CPU, memory, disk, network, GPU, I/O, audio/video devices.
	 * Requires ServerRepository::getAll()[SERVER_ID] for network interface selection.
	 *
	 * @return array<string, mixed>
	 */
	public static function getStats() {
		$rJSON = [];
		$rJSON['cpu'] = round(self::getTotalCPU(), 2);
		$rJSON['cpu_cores'] = intval(@shell_exec('cat /proc/cpuinfo | grep "^processor" | wc -l') ?? '0');
		$rJSON['cpu_avg'] = round((sys_getloadavg()[0] * 100) / (($rJSON['cpu_cores'] ?: 1)), 2);
		$rJSON['cpu_name'] = trim(@shell_exec("cat /proc/cpuinfo | grep 'model name' | uniq | awk -F: '{print \$2}'") ?? '');
		if ($rJSON['cpu_avg'] > 100) {
			$rJSON['cpu_avg'] = 100;
		}
		$rMemInfo = self::getMemory();
		$rJSON['total_mem'] = $rMemInfo['total'];
		$rJSON['total_mem_free'] = $rMemInfo['free'];
		$rJSON['total_mem_used'] = $rMemInfo['used'];
		$rJSON['total_mem_used_percent'] = self::memUsedPercent($rJSON['total_mem_used'], $rJSON['total_mem']);
		$rJSON['total_disk_space'] = disk_total_space(MAIN_HOME);
		$rJSON['free_disk_space'] = disk_free_space(MAIN_HOME);
		$rJSON['kernel'] = trim(@shell_exec('uname -r') ?? '');
		$rJSON['uptime'] = self::getUptime();
		$rJSON['total_running_streams'] = ProcessManager::countStreamProducers();
		$rJSON['bytes_sent'] = 0;
		$rJSON['bytes_sent_total'] = 0;
		$rJSON['bytes_received'] = 0;
		$rJSON['bytes_received_total'] = 0;
		$rJSON['network_speed'] = 0;
		$rJSON['interfaces'] = self::getNetworkInterfaces();
		$rJSON['network_speed'] = 0;
		if ($rJSON['cpu'] > 100) {
			$rJSON['cpu'] = 100;
		}
		if ($rJSON['total_mem'] < $rJSON['total_mem_used']) {
			$rJSON['total_mem_used'] = $rJSON['total_mem'];
		}
		if ($rJSON['total_mem_used_percent'] > 100) {
			$rJSON['total_mem_used_percent'] = 100;
		}

		// Network interface selection: delegate to ServerRepository for backward compat
		$rNetworkInterface = null;
		if (defined('SERVER_ID') && isset(ServerRepository::getAll()[SERVER_ID]['network_interface'])) {
			$rNetworkInterface = ServerRepository::getAll()[SERVER_ID]['network_interface'] == 'auto'
				? null
				: ServerRepository::getAll()[SERVER_ID]['network_interface'];
		}
		$rJSON['network_info'] = self::getNetwork($rNetworkInterface);

		// The bandwidth keys are initialised above, so replacing them keeps their order.
		$rJSON = array_replace($rJSON, self::aggregateNetwork($rJSON['network_info']));
		$rDevices = self::getDevices();
		$rJSON['audio_devices'] = $rDevices['audio_devices'];
		$rJSON['video_devices'] = $rDevices['video_devices'];
		$rJSON['gpu_info'] = $rDevices['gpu_info'];
		$rJSON['iostat_info'] = $rDevices['iostat_info'];
		list($rJSON['cpu_load_average']) = sys_getloadavg();
		return $rJSON;
	}

	/**
	 * Capture devices, GPUs and disk I/O, each [] when its tool (arecord,
	 * v4l2-ctl, nvidia-smi, iostat) is not installed.
	 *
	 * getStats() reports them, and so does a TELEMETRY node's watchdog in
	 * `config/cluster/local.json` (Core\Cluster\LocalTelemetry), which its
	 * agent forwards to MAIN: both probe here, so they cannot drift apart.
	 *
	 * @param int $rTimeout Seconds each tool may run (the watchdog's local.json:
	 *   a hung tool must not stall it); a tool cut short reports what it printed
	 *   by then, as a rule []. 0, getStats()'s, waits for it.
	 * @return array{audio_devices: array<mixed>, video_devices: array<mixed>, gpu_info: array<mixed>, iostat_info: array<mixed>}
	 */
	public static function getDevices(int $rTimeout = 0) {
		$rDevices = ['audio_devices' => [], 'video_devices' => [], 'gpu_info' => [], 'iostat_info' => []];
		if (@shell_exec('which iostat')) {
			$rDevices['iostat_info'] = self::getIO($rTimeout);
		}
		if (@shell_exec('which nvidia-smi')) {
			$rDevices['gpu_info'] = self::getGPUInfo($rTimeout);
		}
		if (@shell_exec('which v4l2-ctl')) {
			$rDevices['video_devices'] = self::getVideoDevices($rTimeout);
		}
		if (@shell_exec('which arecord')) {
			$rDevices['audio_devices'] = self::getAudioDevices($rTimeout);
		}
		return $rDevices;
	}

	/** $rCommand, stopped after $rTimeout seconds (killed a second later) when that is above 0. */
	private static function bounded(string $rCommand, int $rTimeout): string {
		return $rTimeout > 0 ? 'timeout -k 1 ' . $rTimeout . ' ' . $rCommand : $rCommand;
	}

	/**
	 * Get total CPU usage as percentage (sum of all processes / core count).
	 *
	 * @return float CPU usage percentage
	 */
	public static function getTotalCPU() {
		$rTotalLoad = 0;
		$processes = [];
		@exec('ps -Ao pid,pcpu', $processes);
		foreach ($processes as $process) {
			$cols = explode(' ', preg_replace('!\\s+!', ' ', trim($process)));
			if (count($cols) >= 2 && is_numeric($cols[1])) {
				$rTotalLoad += floatval($cols[1]);
			}
		}

		$cpuCores = 1;
		$coreCount = intval(@shell_exec("grep -P '^processor' /proc/cpuinfo|wc -l") ?? '0');
		if ($coreCount > 0) {
			$cpuCores = $coreCount;
		}

		return $rTotalLoad / $cpuCores;
	}

	/**
	 * Get memory info from /proc/meminfo.
	 *
	 * @return array{total: int, free: int, used: int} Memory in kB
	 */
	public static function getMemory() {
		try {
			$rFree = explode("\n", file_get_contents('/proc/meminfo'));
			$rMemory = [];

			foreach ($rFree as $rLine) {
				if (empty($rLine)) {
					continue;
				}

				$rParts = preg_split('/\s+/', trim($rLine));
				if (count($rParts) >= 2) {
					$rKey = rtrim($rParts[0], ':');
					$rValue = intval($rParts[1]);
					$rMemory[$rKey] = $rValue;
				}
			}

			if (isset($rMemory['MemTotal'], $rMemory['MemAvailable'])) {
				return [
					'total' => $rMemory['MemTotal'],
					'free'  => $rMemory['MemAvailable'],
					'used'  => $rMemory['MemTotal'] - $rMemory['MemAvailable']
				];
			}

			return ['total' => 0, 'free' => 0, 'used' => 0];
		} catch (\Exception $e) {
			return ['total' => 0, 'free' => 0, 'used' => 0];
		}
	}

	/**
	 * Used memory as a percentage of the total.
	 *
	 * getMemory() reports a total of 0 when /proc/meminfo cannot be read or has
	 * no MemAvailable (kernels before 3.14, some containers); dividing by it
	 * threw DivisionByZeroError and cost the node its heartbeat.
	 *
	 * @param int $rUsed  Used memory (kB)
	 * @param int $rTotal Total memory (kB)
	 * @return float Percentage rounded to 2 places; 0 with no total
	 */
	public static function memUsedPercent(int $rUsed, int $rTotal) {
		if ($rTotal <= 0) {
			return 0.0;
		}
		return round(($rUsed / $rTotal) * 100, 2);
	}

	/**
	 * Get system uptime as human-readable string.
	 *
	 * @return string e.g., "5d 3h 12m 4s"
	 */
	public static function getUptime() {
		if (!file_exists('/proc/uptime') || !is_readable('/proc/uptime')) {
			return '';
		}
		$tmp = explode(' ', file_get_contents('/proc/uptime'));
		return TimeUtils::secondsToTime(intval($tmp[0]));
	}

	/**
	 * List network interfaces (excluding lo and bond*).
	 *
	 * @return string[]
	 */
	public static function getNetworkInterfaces() {
		$rReturn = [];
		$rOutput = [];
		@exec('ls /sys/class/net/', $rOutput, $rReturnVar);
		foreach ($rOutput as $rInterface) {
			$rInterface = trim(rtrim($rInterface, ':'));
			if ($rInterface != 'lo' && substr($rInterface, 0, 4) != 'bond') {
				$rReturn[] = $rInterface;
			}
		}
		return $rReturn;
	}

	/**
	 * Read cached network I/O statistics.
	 *
	 * @param string|null $rInterface  Specific interface name, or null for all
	 * @return array<string, array{in_bytes: int, in_packets: int, in_errors: int, out_bytes: int, out_packets: int, out_errors: int}>
	 */
	public static function getNetwork(?string $rInterface = null) {
		$rReturn = [];
		if (file_exists(LOGS_TMP_PATH . 'network')) {
			$rNetwork = json_decode(file_get_contents(LOGS_TMP_PATH . 'network'), true);
			foreach ((is_array($rNetwork) ? $rNetwork : []) as $rLine) {
				if ((!$rInterface || $rLine[0] == $rInterface) && ($rLine[0] != 'lo' && ($rInterface || substr($rLine[0], 0, 4) != 'bond'))) {
					$rReturn[$rLine[0]] = ['in_bytes' => intval($rLine[1] / 2), 'in_packets' => $rLine[2], 'in_errors' => $rLine[3], 'out_bytes' => intval($rLine[4] / 2), 'out_packets' => $rLine[5], 'out_errors' => $rLine[6]];
				}
			}
		}
		return $rReturn;
	}

	/**
	 * Bandwidth figures over the interfaces getNetwork() selected.
	 *
	 * The per-second rates come from the cached network readings; the lifetime
	 * totals from sysfs (tx_bytes sent, rx_bytes received). Both are summed over
	 * every interface. network_speed is the first positive link speed (Mbit/s).
	 * An unreadable sysfs file counts as 0.
	 *
	 * @param array<string, array{in_bytes: int, out_bytes: int}> $rNetworkInfo getNetwork() result
	 * @param string $rSysNet sysfs network class directory (overridable for tests)
	 * @return array{bytes_sent: int, bytes_sent_total: int, bytes_received: int, bytes_received_total: int, network_speed: int}
	 */
	public static function aggregateNetwork(array $rNetworkInfo, string $rSysNet = '/sys/class/net/') {
		$rReturn = ['bytes_sent' => 0, 'bytes_sent_total' => 0, 'bytes_received' => 0, 'bytes_received_total' => 0, 'network_speed' => 0];
		foreach ($rNetworkInfo as $rInterface => $rData) {
			$rSpeed = intval(trim((string) @file_get_contents($rSysNet . $rInterface . '/speed')));
			if (0 < $rSpeed && $rReturn['network_speed'] == 0) {
				$rReturn['network_speed'] = $rSpeed;
			}
			$rReturn['bytes_sent_total'] += intval(trim((string) @file_get_contents($rSysNet . $rInterface . '/statistics/tx_bytes')));
			$rReturn['bytes_received_total'] += intval(trim((string) @file_get_contents($rSysNet . $rInterface . '/statistics/rx_bytes')));
			$rReturn['bytes_sent'] += $rData['out_bytes'];
			$rReturn['bytes_received'] += $rData['in_bytes'];
		}
		return $rReturn;
	}

	/**
	 * Total request count from an nginx stub_status body.
	 *
	 * The third line holds the accepts, handled and requests counters
	 * (" 10 10 57 "); the request count is the third of them.
	 *
	 * @param string $rStatus stub_status response body ('' when unreachable)
	 * @return float|null The request count; null when the body has none
	 */
	public static function nginxRequestCount(string $rStatus) {
		$rRequests = explode(' ', trim(explode("\n", $rStatus)[2] ?? ''))[2] ?? null;
		return is_numeric($rRequests) ? (float) $rRequests : null;
	}

	/**
	 * List V4L2 video capture devices.
	 *
	 * @param int $rTimeout Seconds v4l2-ctl may run; 0 waits (getDevices()).
	 * @return array<int, array{name: string, video_device: string}>
	 */
	public static function getVideoDevices(int $rTimeout = 0) {
		$rReturn = [];
		$rID = 0;
		try {
			// 2>/dev/null: with no camera present v4l2-ctl prints
			// "Cannot open device /dev/video0, exiting." to stderr, polluting
			// the output of every command that collects stats (watchdog, crons).
			$rDevices = array_values(array_filter(explode("\n", @shell_exec(self::bounded('v4l2-ctl --list-devices', $rTimeout) . ' 2>/dev/null') ?? '')));
			foreach ($rDevices as $rKey => $rValue) {
				if ($rKey % 2 == 0) {
					$rReturn[$rID]['name'] = $rValue;
					list(, $rReturn[$rID]['video_device']) = explode('/dev/', $rDevices[$rKey + 1]);
					$rID++;
				}
			}
		} catch (\Exception $e) {
		}
		return $rReturn;
	}

	/**
	 * List ALSA audio recording devices.
	 *
	 * @param int $rTimeout Seconds arecord may run; 0 waits (getDevices()).
	 * @return string[]
	 */
	public static function getAudioDevices(int $rTimeout = 0) {
		try {
			return array_filter(explode("\n", @shell_exec(self::bounded('arecord -L', $rTimeout) . ' | grep "hw:CARD="') ?? ''));
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Get I/O statistics via iostat (JSON mode).
	 *
	 * @param int $rTimeout Seconds iostat may run; 0 waits (getDevices()).
	 * @return array
	 */
	public static function getIO(int $rTimeout = 0) {
		$rOutput = [];
		@exec(self::bounded('iostat -o JSON -m', $rTimeout), $rOutput, $rReturnVar);
		$rOutput = implode('', $rOutput);
		$rJSON = json_decode($rOutput, true);
		if (isset($rJSON['sysstat'])) {
			return $rJSON['sysstat']['hosts'][0]['statistics'][0];
		}
		return [];
	}

	/**
	 * Get GPU information via nvidia-smi (XML mode).
	 *
	 * @param int $rTimeout Seconds nvidia-smi may run; 0 waits (getDevices()).
	 * @return array
	 */
	public static function getGPUInfo(int $rTimeout = 0) {
		$rOutput = [];
		@exec(self::bounded('nvidia-smi -x -q', $rTimeout), $rOutput, $rReturnVar);
		// Stopped by the bound (timeout's 124, or 137 once killed): half an XML document.
		if ($rTimeout > 0 && in_array($rReturnVar, [124, 137], true)) {
			return [];
		}
		$rOutput = implode('', $rOutput);
		if (stripos($rOutput, '<?xml') !== false) {
			$rJSON = json_decode(json_encode(simplexml_load_string($rOutput)), true);
			if (isset($rJSON['driver_version'])) {
				$rGPU = ['attached_gpus' => $rJSON['attached_gpus'], 'driver_version' => $rJSON['driver_version'], 'cuda_version' => $rJSON['cuda_version'], 'gpus' => []];
				if (isset($rJSON['gpu']['board_id'])) {
					$rJSON['gpu'] = [$rJSON['gpu']];
				}
				foreach ($rJSON['gpu'] as $rInstance) {
					$rArray = ['name' => $rInstance['product_name'], 'power_readings' => $rInstance['power_readings'], 'utilisation' => $rInstance['utilization'], 'memory_usage' => $rInstance['fb_memory_usage'], 'fan_speed' => $rInstance['fan_speed'], 'temperature' => $rInstance['temperature'], 'clocks' => $rInstance['clocks'], 'uuid' => $rInstance['uuid'], 'id' => intval($rInstance['pci']['pci_device']), 'processes' => []];
					$rArray['processes'] = self::gpuProcesses($rInstance);
					$rGPU['gpus'][] = $rArray;
				}
				return $rGPU;
			}
		}
		return [];
	}

	/**
	 * The processes of one decoded nvidia-smi GPU entry.
	 *
	 * The XML decodes a single <process_info> as one associative array rather
	 * than a list of one, the same way a single <gpu> decodes (see getGPUInfo()),
	 * and a GPU with no processes has no process_info at all.
	 *
	 * @param array $rInstance One decoded <gpu> element
	 * @return array<int, array{pid: int, memory: mixed}>
	 */
	public static function gpuProcesses(array $rInstance) {
		$rProcesses = $rInstance['processes']['process_info'] ?? [];
		if (!is_array($rProcesses)) {
			return [];
		}
		if (isset($rProcesses['pid'])) {
			$rProcesses = [$rProcesses];
		}
		$rReturn = [];
		foreach ($rProcesses as $rProcess) {
			if (is_array($rProcess)) {
				$rReturn[] = ['pid' => intval($rProcess['pid'] ?? 0), 'memory' => $rProcess['used_memory'] ?? null];
			}
		}
		return $rReturn;
	}
}
