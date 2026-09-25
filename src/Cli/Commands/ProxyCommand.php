<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * ProxyCommand — the pre-fanout producer for proxy live streams.
 *
 * Runs only while fanout is switched off (FanoutMode); with fanout on, the
 * xc_fanout daemon pulls proxy streams itself. live.php starts one per stream
 * (StreamProcess::startProxy) when a viewer arrives and none is running. It
 * reads the source as MPEG-TS (directly, or through an ffmpeg remux for an HLS
 * source) and sends every read to each viewer's unix datagram socket under
 * CONS_TMP_PATH/<id>/, where live.php relays it to the client. A new viewer first
 * gets the prebuffer, which starts on the last keyframe. With no viewer socket
 * for CLOSE_EMPTY ms the producer exits.
 *
 * Restored from before ADR 0003 Phase E1, with these fixes: the read loop no
 * longer ends after its first read, it no longer spins on an idle source, and
 * the producer exits when the last viewer has gone (which it only reported).
 * The source's Content-Type is read case-insensitively. The HTTP proxy
 * argument reaches ffmpeg as its value, and a source that cannot be opened
 * fails the start instead of running ffmpeg on no input.
 *
 * @package XC_VM_CLI_Commands
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ProxyCommand implements CommandInterface {
	private const PACKET_SIZE = 188;
	private const BUFFER_SIZE = 12032;
	private const PAT_HEADER = "\xB0\x0D";
	private const PAT_PERIOD = 2;
	private const TIMEOUT = 20;
	/** Milliseconds without a viewer socket before the producer exits. */
	private const CLOSE_EMPTY = 3000;
	private const STORE_PREBUFFER = 1128000;
	private const MAX_PREBUFFER = 10528000;

	public function getName(): string {
		return 'proxy';
	}

	public function getDescription(): string {
		return 'Proxy — MPEG-TS stream proxying via sockets (fanout off)';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		$rStreamID = intval($rArgs[0]);

		global $db;

		$this->checkRunning($rStreamID);

		$rFP = null;

		register_shutdown_function(function () use ($rStreamID, &$rFP) {
			@unlink(STREAMS_PATH . $rStreamID . '_.monitor');
			@unlink(STREAMS_PATH . $rStreamID . '_.pid');
			shell_exec('rm -rf ' . escapeshellarg(CONS_TMP_PATH . $rStreamID . '/'));
			if (is_resource($rFP)) {
				@fclose($rFP);
			}
		});

		set_time_limit(0);
		cli_set_process_title('XC_VMProxy[' . $rStreamID . ']');

		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
		if ($db->num_rows() <= 0) {
			StreamProcess::stopStream($rStreamID);
			return 0;
		}

		file_put_contents(STREAMS_PATH . $rStreamID . '_.monitor', getmypid());
		@unlink(STREAMS_PATH . $rStreamID . '_.pid');
		$rStreamInfo = $db->get_row();
		$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
		$rStreamArguments = $db->get_rows(true, 'argument_key');

		$this->startProxy($rStreamID, $rStreamInfo, $rStreamArguments, $rFP);

		return 0;
	}

	private function startProxy(int $rStreamID, array $rStreamInfo, array $rStreamArguments, &$rFP): void {
		global $db;
		if (!file_exists(CONS_TMP_PATH . $rStreamID . '/')) {
			mkdir(CONS_TMP_PATH . $rStreamID);
		}
		$rUserAgent = (isset($rStreamArguments['user_agent']) ? ($rStreamArguments['user_agent']['value'] ?: $rStreamArguments['user_agent']['argument_default_value']) : 'Mozilla/5.0');
		$rOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true], 'http' => ['method' => 'GET', 'user_agent' => $rUserAgent, 'timeout' => self::TIMEOUT, 'header' => '']];
		$rProxy = (string) ($rStreamArguments['proxy']['value'] ?? '');
		if ($rProxy !== '') {
			$rOptions['http']['proxy'] = 'tcp://' . $rProxy;
			$rOptions['http']['request_fulluri'] = true;
		}
		if (!empty($rStreamArguments['cookie']['value'])) {
			$rOptions['http']['header'] .= 'Cookie: ' . $rStreamArguments['cookie']['value'] . "\r\n";
		}
		if (SettingsManager::getBool('request_prebuffer')) {
			$rOptions['http']['header'] .= 'X-XC_VM-Prebuffer: 1' . "\r\n";
		}
		$rContext = stream_context_create($rOptions);
		$rURLs = json_decode((string) $rStreamInfo['stream_source'], true);
		$rFP = $this->getActiveStream(is_array($rURLs) ? $rURLs : [], $rContext);
		if (is_string($rFP)) {
			// An HLS source: ffmpeg remuxes it to MPEG-TS on stdout.
			$rHeaders = (!empty($rOptions['http']['header']) ? '-headers ' . escapeshellarg($rOptions['http']['header']) : '');
			$rProxyArg = ($rProxy !== '' ? '-http_proxy ' . escapeshellarg($rProxy) : '');
			$rCommand = FfmpegPaths::cpu() . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -user_agent ' . escapeshellarg($rUserAgent) . ' ' . $rHeaders . ' ' . $rProxyArg . ' -i ' . escapeshellarg($rFP) . ' -map 0 -c copy -mpegts_flags +initial_discontinuity -pat_period ' . self::PAT_PERIOD . ' -f mpegts -';
			$rFP = popen($rCommand, 'rb');
		}
		if (!is_resource($rFP)) {
			echo 'Failed!' . "\n";
			StreamProcess::streamLog($rStreamID, SERVER_ID, 'STREAM_START_FAIL');
			StreamStateWriter::updateRow(intval($rStreamInfo['server_stream_id']), ['monitor_pid' => null, 'pid' => null, 'stream_status' => 1], $db);
			if (SettingsManager::getBool('enable_cache')) {
				StreamProcess::updateStream($rStreamID);
			}
			return;
		}

		StreamStateWriter::updateRow(intval($rStreamInfo['server_stream_id']), ['monitor_pid' => getmypid(), 'pid' => getmypid(), 'stream_started' => time(), 'stream_status' => 0, 'to_analyze' => 0], $db);
		if (SettingsManager::getBool('enable_cache')) {
			StreamProcess::updateStream($rStreamID);
		}
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		file_put_contents(STREAMS_PATH . $rStreamID . '_.pid', getmypid());
		$db->close_mysql();

		$rLastSocket = null;
		stream_set_blocking($rFP, false);
		$rExcessBuffer = $rAnalyseBuffer = $rPrebuffer = $rBuffer = '';
		$rHasPrebuffer = $rPATHeaders = [];
		$rAnalysed = $rPAT = $rFirstKeyframe = false;
		$rLastData = time();
		while (!feof($rFP)) {
			$rRead = fread($rFP, self::BUFFER_SIZE - strlen($rBuffer . $rExcessBuffer));
			if ($rRead === false || $rRead === '') {
				if (time() - $rLastData > self::TIMEOUT) {
					echo 'Source timed out' . "\n";
					break;
				}
				usleep(10000); // idle source: do not spin
			} else {
				$rLastData = time();
			}
			$rBuffer = $rBuffer . $rExcessBuffer . (string) $rRead;
			$rExcessBuffer = '';
			$rPacketNum = intdiv(strlen($rBuffer), self::PACKET_SIZE);
			if (0 < $rPacketNum) {
				if (strlen($rBuffer) != $rPacketNum * self::PACKET_SIZE) {
					$rExcessBuffer = substr($rBuffer, $rPacketNum * self::PACKET_SIZE);
					$rBuffer = substr($rBuffer, 0, $rPacketNum * self::PACKET_SIZE);
				}
				foreach (str_split($rBuffer, self::PACKET_SIZE) as $rPacket) {
					[, $rHeader] = unpack('N', substr($rPacket, 0, 4));
					if (($rHeader >> 24 & 255) != 71) {
						continue;
					}
					if (substr($rPacket, 6, 2) == self::PAT_HEADER) {
						$rPAT = true;
						$rPATHeaders = [];
					} elseif ((($rHeader >> 4 & 3) & 2) === 2) {
						if (0 < count($rPATHeaders) && substr($rPacket, 4, 2) == "\x07P") {
							if (!$rPrebuffer || self::STORE_PREBUFFER <= strlen($rPrebuffer)) {
								$rPrebuffer = implode('', $rPATHeaders) . $rPacket;
							}
							$rFirstKeyframe = true;
							$rPAT = false;
							$rPATHeaders = [];
						}
					}
					if ($rPAT && count($rPATHeaders) < 10) {
						$rPATHeaders[] = $rPacket;
					}
					if ($rFirstKeyframe && strlen($rPrebuffer) < self::MAX_PREBUFFER) {
						$rPrebuffer .= $rPacket;
					}
					if (!$rAnalysed) {
						$rAnalyseBuffer .= $rPacket;
						if (3000 * self::PACKET_SIZE <= strlen($rAnalyseBuffer)) {
							file_put_contents(STREAMS_PATH . $rStreamID . '.analyse', $rAnalyseBuffer);
							$rAnalyseBuffer = '';
							$rAnalysed = true;
						}
					}
				}
			}

			$rSockets = $this->getSockets($rStreamID);
			$rNow = (int) round(microtime(true) * 1000);
			if (0 < count($rSockets)) {
				$rLastSocket = $rNow;
				foreach ($rSockets as $rSocketID) {
					$rSocketFile = CONS_TMP_PATH . $rStreamID . '/' . $rSocketID;
					if (!isset($rHasPrebuffer[$rSocketID])) {
						// A new viewer: the prebuffer (from the last keyframe) first.
						if ($rPrebuffer !== '') {
							$rHasPrebuffer[$rSocketID] = true;
							$this->send($rSocketFile, $rPrebuffer);
						}
					} elseif ($rBuffer !== '') {
						$this->send($rSocketFile, $rBuffer);
					}
				}
			} else {
				$rLastSocket = $rLastSocket ?? $rNow;
				if (self::CLOSE_EMPTY <= $rNow - $rLastSocket) {
					echo 'No sockets waiting, close stream' . "\n";
					break;
				}
			}
			$rBuffer = '';
		}
		fclose($rFP);
		$rFP = null;
		$db->db_connect();
		StreamStateWriter::updateRow(intval($rStreamInfo['server_stream_id']), ['monitor_pid' => null, 'pid' => null, 'stream_status' => 1], $db);
		if (SettingsManager::getBool('enable_cache')) {
			StreamProcess::updateStream($rStreamID);
		}
	}

	/** Send data to one viewer's datagram socket, in BUFFER_SIZE datagrams. */
	private function send(string $rSocketFile, string $rData): void {
		if (!file_exists($rSocketFile)) {
			return;
		}
		$rSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
		if ($rSocket === false) {
			return;
		}
		socket_set_nonblock($rSocket);
		foreach (str_split($rData, self::BUFFER_SIZE) as $rChunk) {
			@socket_sendto($rSocket, $rChunk, strlen($rChunk), 0, $rSocketFile);
		}
		socket_close($rSocket);
	}

	private function getSockets(int $rStreamID): array {
		$rSockets = [];
		$rHandle = @opendir(CONS_TMP_PATH . $rStreamID . '/');
		if ($rHandle) {
			while (false !== ($rFilename = readdir($rHandle))) {
				if ($rFilename != '.' && $rFilename != '..') {
					$rSockets[] = $rFilename;
				}
			}
			closedir($rHandle);
		}
		return $rSockets;
	}

	/**
	 * Open the first source that answers: a video/mp2t one as a read handle, an
	 * HLS one as its URL (for ffmpeg), or null when none answers.
	 *
	 * @return resource|string|null
	 */
	private function getActiveStream(array $rURLs, $rContext) {
		foreach ($rURLs as $rURL) {
			$rURL = StreamUtils::parseStreamURL((string) $rURL);
			$rFP = @fopen($rURL, 'rb', false, $rContext);
			if (!$rFP) {
				continue;
			}
			$rContentType = '';
			foreach ((stream_get_meta_data($rFP)['wrapper_data'] ?? []) as $rLine) {
				// The last Content-Type wins: redirects list one per response.
				if (is_string($rLine) && stripos($rLine, 'content-type:') === 0) {
					$rContentType = strtolower(trim(explode(';', substr($rLine, 13))[0]));
				}
			}
			if ($rContentType == 'video/mp2t') {
				return $rFP;
			}
			fclose($rFP);
			if (in_array($rContentType, ['application/x-mpegurl', 'application/vnd.apple.mpegurl', 'audio/x-mpegurl'], true)) {
				return $rURL;
			}
		}
		return null;
	}

	private function checkRunning(int $rStreamID): void {
		clearstatcache(true);
		$rPID = null;
		if (file_exists(STREAMS_PATH . $rStreamID . '_.monitor')) {
			$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.monitor'));
		}
		if (empty($rPID)) {
			shell_exec("kill -9 `ps -ef | grep 'XC_VMProxy\\[" . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
		} elseif (file_exists('/proc/' . $rPID)) {
			$rCommand = trim(file_get_contents('/proc/' . $rPID . '/cmdline'));
			if ($rCommand == 'XC_VMProxy[' . $rStreamID . ']' && 0 < $rPID) {
				posix_kill($rPID, 9);
			}
		}
	}
}
