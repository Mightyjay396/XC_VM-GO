<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;

/**
 * An op MAIN applies once (recording_complete) is asked again while it gets
 * no answer: MAIN refuses bulk ingest while its ingest permits are held (a
 * 503 RATE_LIMITED, ADR 0004), and today's agent relays any refusal to the
 * node's PHP as a bare 409. Giving up at once would lose the recording.
 *
 * The agent here is a stand-in on a real unix socket (as AgentAdmissionTest):
 * it logs each request and answers what the test tells it to.
 */
final class AgentClientRetryTest extends TestCase {
	private string $rDir;

	/** @var resource|null */
	private $rAgent = null;

	/** @var list<int> */
	private array $rWaits = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-agent-retry-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		AgentClient::useSocket($this->rDir . '/agent.sock');
		AgentClient::useSleep(function (int $rSec): void {
			$this->rWaits[] = $rSec;
		});
	}

	protected function tearDown(): void {
		if ($this->rAgent !== null) {
			proc_terminate($this->rAgent);
			proc_close($this->rAgent);
		}
		AgentClient::useSocket(null);
		AgentClient::useSleep(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Start the stand-in agent: it answers each request in turn with the next [status, body].
	 *
	 * @param list<array{0: int, 1: string}> $rReplies
	 */
	private function agent(array $rReplies): void {
		$rScript = $this->rDir . '/agent.php';
		file_put_contents($rScript, <<<'PHP'
<?php
[, $rSock, $rLog, $rReplies] = $argv;
$rServer = stream_socket_server('unix://' . $rSock, $rErrNo, $rErr);
foreach (json_decode((string) file_get_contents($rReplies), true) as $rReply) {
	$rConn = @stream_socket_accept($rServer, 10);
	if ($rConn === false) {
		break;
	}
	$rRaw = '';
	while (!str_contains($rRaw, "\r\n\r\n") && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rRaw .= $rChunk;
	}
	[$rHead, $rBody] = array_pad(explode("\r\n\r\n", $rRaw, 2), 2, '');
	$rLen = preg_match('/^Content-Length: (\d+)/mi', $rHead, $rM) ? (int) $rM[1] : 0;
	while (strlen($rBody) < $rLen && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
		$rBody .= $rChunk;
	}
	file_put_contents($rLog, json_encode(['line' => strtok($rHead, "\r\n"), 'body' => $rBody]) . "\n", FILE_APPEND);
	fwrite($rConn, 'HTTP/1.1 ' . $rReply[0] . " X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rReply[1]) . "\r\nConnection: close\r\n\r\n" . $rReply[1]);
	fclose($rConn);
}
PHP);
		file_put_contents($this->rDir . '/replies.json', json_encode($rReplies));
		$rNull = ['file', '/dev/null', 'w'];
		$this->rAgent = proc_open([PHP_BINARY, $rScript, $this->rDir . '/agent.sock', $this->rDir . '/requests.log', $this->rDir . '/replies.json'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 100 && !file_exists($this->rDir . '/agent.sock'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . '/agent.sock', 'the stand-in agent listens');
	}

	/** @return list<array{line: string, body: mixed}> what the agent was sent */
	private function requests(): array {
		$rOut = [];
		foreach (file($this->rDir . '/requests.log', FILE_IGNORE_NEW_LINES) ?: [] as $rLine) {
			$rReq = json_decode($rLine, true);
			$rOut[] = ['line' => $rReq['line'], 'body' => json_decode($rReq['body'], true)];
		}
		return $rOut;
	}

	public function testABusyRefusalIsAskedAgainUntilMainAnswers(): void {
		$this->agent([[409, 'MAIN refused (503 RATE_LIMITED)'], [409, 'MAIN refused (503 RATE_LIMITED)'], [200, '{"stream_id":7}']]);
		$rPayload = ['recording_id' => 3, 'stream_icon' => null];
		$this->assertSame(['stream_id' => 7], AgentClient::mainRetrying('recording_complete', $rPayload));
		$this->assertSame(array_slice(AgentClient::RETRY_WAITS_SEC, 0, 2), $this->rWaits, 'waited between tries');
		$rSent = $this->requests();
		$this->assertCount(3, $rSent);
		foreach ($rSent as $rReq) {
			$this->assertSame(['line' => 'POST /v1/main/recording_complete HTTP/1.0', 'body' => $rPayload], $rReq, 'the same call each time');
		}
	}

	public function testAnAnswerAtOnceWaitsForNothing(): void {
		$this->agent([[200, '{"stream_id":8}']]);
		$this->assertSame(['stream_id' => 8], AgentClient::mainRetrying('recording_complete', ['recording_id' => 4]));
		$this->assertSame([], $this->rWaits);
	}

	public function testItGivesUpAfterAboutTwoMinutes(): void {
		// No agent at all (nothing listens on the socket): every try fails.
		$this->assertNull(AgentClient::mainRetrying('recording_complete', ['recording_id' => 5]));
		$this->assertSame(AgentClient::RETRY_WAITS_SEC, $this->rWaits, 'each wait, once');
		$this->assertGreaterThanOrEqual(60, array_sum(AgentClient::RETRY_WAITS_SEC), 'outlasts a busy spell of MAIN\'s ingest');
		$this->assertLessThanOrEqual(180, array_sum(AgentClient::RETRY_WAITS_SEC));
	}
}
