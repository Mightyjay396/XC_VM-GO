<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Cli\Commands\ClusterRootCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Logging\FileLogger;
use XcVm\Streaming\Delivery\OffAirHandler;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * An artefact on the node (plan section 7, "Root artefacts": staged in
 * root-owned /etc/xc_vm/cluster/stage/ and checked for size and SHA-256
 * before exec, a mismatch refused and audited; section 14: "tampered
 * artefacts refused before extraction").
 *
 * The agent downloads what MAIN granted into its own directory
 * (config/cluster/artefacts/<cmd_id>), then hands the command on: an
 * off-air video's `artefact.fetch` to cluster:exec, which places it where
 * the node's off-air code plays it; a `node.root` that carries a grant to
 * cluster:root, which copies the download into its own stage and checks it
 * there before the action runs. Both check the bytes they use against the
 * size and SHA-256 of the signed grant, whatever the agent checked, and
 * refuse and audit anything else.
 */
final class ArtefactHashRefusalTest extends TestCase {
	private const NODE = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rBase;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rBase = sys_get_temp_dir() . '/artefact_node_' . bin2hex(random_bytes(4)) . '/';
		foreach (['etc', 'config/cluster/artefacts', 'video', 'bin/xc_agent', 'logs'] as $rDir) {
			mkdir($this->rBase . $rDir, 0755, true);
		}
		mkdir($this->rBase . 'config/cluster/root-inbox', 0700);
		file_put_contents($this->rBase . 'bin/xc_agent/xc_agent', 'the running agent');
		// The node's tree is xc_vm's (nobody here, when the suite runs as root); /etc's pin is root's.
		AgentUser::own($this->rBase . 'config', $this->rBase . 'video', $this->rBase . 'bin', $this->rBase . 'logs');
		RootPin::useDirs($this->rBase . 'etc/', $this->rBase . 'config/cluster/root-inbox/');
		ArtefactStage::useDirs($this->rBase . 'config/cluster/artefacts/', $this->rBase . 'video/cluster/');
		EventSpool::useDir($this->rBase . 'config/cluster/spool/');
		FileLogger::setLogFile($this->rBase . 'logs/error_log.log');
		$this->rCrypto = new FakeClusterCrypto();
		$this->assertTrue(RootPin::write($this->rCrypto->info()['panel_sign_pub'], self::NODE));
		$this->flows(NodeFlows::COMMANDS | NodeFlows::LOGS);
	}

	protected function tearDown(): void {
		RootPin::useDirs(null, null);
		ArtefactStage::useDirs(null, null);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		FileLogger::setLogFile(null);
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rBase . 'config/cluster/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		AgentUser::own($this->rBase . 'config/cluster/flows.json');
		NodeFlows::usePath($this->rBase . 'config/cluster/flows.json');
	}

	/** A grant as MAIN signs it into the command, for these bytes. */
	private function grant(string $rId, string $rName, string $rBytes, array $rOver = []): array {
		return $rOver + ['id' => $rId, 'name' => $rName, 'size' => strlen($rBytes), 'sha256' => hash('sha256', $rBytes), 'mtime' => 1799990000, 'exp' => 1800003600];
	}

	/** A command as the agent hands it on: the signed document, decoded, and its wire form. */
	private function command(int $rSeq, string $rType, array $rArgs): array {
		$rDoc = (string) json_encode(['v' => 1, 'type' => $rType, 'exp' => 1800003600, 'iat' => 1800000000, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $rSeq, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null, 'args' => $rArgs], JSON_UNESCAPED_SLASHES);
		return ['cmd' => json_decode($rDoc, true), 'wire' => ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign('cmd', $rDoc))]];
	}

	/** What the agent wrote after its own download: config/cluster/artefacts/<cmd_id>, xc_vm's. */
	private function download(array $rCmd, string $rBytes): string {
		$rPath = $this->rBase . 'config/cluster/artefacts/' . $rCmd['cmd_id'];
		file_put_contents($rPath, $rBytes);
		AgentUser::own($rPath);
		return $rPath;
	}

	/** @return list<array{0: string, 1: string}> the system log lines spooled for MAIN: [type, error] */
	private function audited(): array {
		$rOut = [];
		foreach (glob($this->rBase . 'config/cluster/spool/p1/*.ndjson') ?: [] as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rEvent = json_decode($rLine, true);
				foreach ($rEvent['d']['rows'] ?? [] as $rRow) {
					$rOut[] = [$rEvent['type'] . ':' . $rRow['type'], (string) $rRow['error']];
				}
			}
		}
		return $rOut;
	}

	/** @return list<string> the stage's entries */
	private function staged(): array {
		return array_values(array_diff(scandir($this->rBase . 'etc/stage') ?: [], ['.', '..']));
	}

	private function inbox(int $rSeq, array $rWire): void {
		file_put_contents($this->rBase . 'config/cluster/root-inbox/' . $rSeq . '.json', json_encode($rWire));
	}

	private function done(int $rSeq): array {
		return json_decode((string) file_get_contents($this->rBase . 'config/cluster/root-inbox/' . $rSeq . '.done'), true);
	}

	/** The happy path of an off-air video: placed where the node's off-air code plays it. */
	public function testAnOffAirVideoIsPlacedOnceItsSizeAndHashAreTheGrants(): void {
		$rBytes = random_bytes(7000);
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rBytes);
		$this->assertIsArray(ClusterExecCommand::verify($rOne['wire'], ['node_uuid' => self::NODE, 'panel_sign_pub' => base64_encode($this->rCrypto->info()['panel_sign_pub'])], 1800000000));
		ob_start();
		$rExit = ClusterExecCommand::run($rOne['cmd']);
		$rOut = json_decode((string) ob_get_clean(), true);
		$this->assertSame(0, $rExit);
		$this->assertSame(['placed' => 'custom_offline.ts', 'size' => 7000, 'sha256' => hash('sha256', $rBytes)], $rOut);
		$rPlaced = $this->rBase . 'video/cluster/custom_offline.ts';
		$this->assertSame($rBytes, file_get_contents($rPlaced));
		$this->assertSame(0644, fileperms($rPlaced) & 0777);
		if (AgentUser::root()) {
			$this->assertSame(AgentUser::UID, fileowner($rPlaced), 'the node\'s tree stays xc_vm\'s');
		}
		$this->assertFileDoesNotExist($rDownload, 'the download is spent');
		$this->assertSame(['custom_offline.ts'], array_values(array_diff(scandir($this->rBase . 'video/cluster'), ['.', '..'])), 'no temporary file left');
		$this->assertSame([], $this->audited());

		// MAIN's token names MAIN's path: the node plays its verified copy
		// when it has no file there, and its own file when it has one.
		$this->assertSame($rPlaced, OffAirHandler::localVideo('/home/xc_vm/content/video/custom_offline.ts'));
		$rLocal = $this->rBase . 'video/offline.ts';
		file_put_contents($rLocal, 'default');
		$this->assertSame($rLocal, OffAirHandler::localVideo($rLocal));
		foreach (['/home/xc_vm/content/video/other.ts', 'http://cdn/x/custom_offline.ts?a=1', '/x/../custom_offline.ts/', ''] as $rPath) {
			$this->assertSame($rPath, OffAirHandler::localVideo($rPath), $rPath);
		}
	}

	/** A download whose bytes are not the grant's is refused before it is placed, and audited for MAIN. */
	public function testATamperedVideoIsRefusedAndAudited(): void {
		$rBytes = str_repeat('v', 4000);
		file_put_contents($this->rBase . 'video/offline.ts', 'the default');
		mkdir($this->rBase . 'video/cluster');
		file_put_contents($this->rBase . 'video/cluster/custom_offline.ts', 'the one verified before');
		AgentUser::own($this->rBase . 'video');
		foreach ([
			'sha256 mismatch' => str_repeat('V', 4000),
			'size mismatch' => $rBytes . 'x',
			'size mismatch ' => substr($rBytes, 1),
		] as $rWhy => $rGot) {
			$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
			$rDownload = $this->download($rOne['cmd'], $rGot);
			$rRefused = ArtefactStage::placeOffAir($rOne['cmd']);
			$this->assertIsString($rRefused);
			$this->assertStringStartsWith('artefact refused: offair/not_on_air', $rRefused);
			$this->assertStringContainsString(trim($rWhy), $rRefused);
			$this->assertSame('the one verified before', file_get_contents($this->rBase . 'video/cluster/custom_offline.ts'), $rWhy . ': nothing replaced');
			$this->assertSame(['custom_offline.ts'], array_values(array_diff(scandir($this->rBase . 'video/cluster'), ['.', '..'])), 'no partial file left');
			$this->assertFileDoesNotExist($rDownload);
		}
		// Through cluster:exec: refused, so the agent acks it failed.
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes)]);
		$this->download($rOne['cmd'], str_repeat('V', 4000));
		ob_start();
		$rExit = ClusterExecCommand::run($rOne['cmd']);
		$this->assertSame('', ob_get_clean(), 'no result: the refusal goes to stderr');
		$this->assertSame(1, $rExit);
		$this->assertSame('the one verified before', file_get_contents($this->rBase . 'video/cluster/custom_offline.ts'));

		$rLines = $this->audited();
		$this->assertCount(4, $rLines);
		foreach ($rLines as [$rType, $rError]) {
			$this->assertSame('log.syslog:ARTEFACT', $rType);
			$this->assertStringStartsWith('Refused artefact offair/not_on_air (custom_offline.ts) for command ', $rError);
		}
		$this->assertStringContainsString('sha256 mismatch', $rLines[0][1]);

		// LOGS off: the panel's error log keeps it, never MAIN's database.
		$this->flows(NodeFlows::COMMANDS);
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $this->grant('offair/banned', 'custom_banned.ts', 'b')]);
		$this->download($rOne['cmd'], 'B');
		$this->assertIsString(ArtefactStage::placeOffAir($rOne['cmd']));
		$rLogged = array_map(static fn(string $rLine): array => json_decode((string) base64_decode($rLine), true), file($this->rBase . 'logs/error_log.log', FILE_IGNORE_NEW_LINES) ?: []);
		$this->assertSame('artefact', $rLogged[0]['type']);
		$this->assertStringContainsString('Refused artefact offair/banned', $rLogged[0]['message']);
	}

	/** Nothing is read or written for a grant the node cannot trust the shape of, nor through a link. */
	public function testAGrantItCannotTrustPlacesNothing(): void {
		$rBytes = 'vvvv';
		$rGood = $this->grant('offair/not_on_air', 'custom_offline.ts', $rBytes);
		foreach ([
			'no grant' => [],
			'a traversing id' => ['artefact' => ['id' => 'offair/../../etc/passwd'] + $rGood],
			'a traversing name' => ['artefact' => ['name' => '../../../etc/cron.d/x'] + $rGood],
			'a hidden name' => ['artefact' => ['name' => '.htaccess'] + $rGood],
			'a name with a slash' => ['artefact' => ['name' => 'a/b.ts'] + $rGood],
			'an empty artefact' => ['artefact' => ['size' => 0] + $rGood],
			'a malformed hash' => ['artefact' => ['sha256' => 'abc'] + $rGood],
			'no expiry' => ['artefact' => ['exp' => null] + $rGood],
			'a root artefact' => ['artefact' => ['id' => 'agent/amd64'] + $rGood],
		] as $rWhy => $rArgs) {
			$rOne = $this->command(1, 'artefact.fetch', $rArgs);
			$this->download($rOne['cmd'], $rBytes);
			$this->assertIsString(ArtefactStage::placeOffAir($rOne['cmd']), $rWhy);
			$this->assertFileDoesNotExist($this->rBase . 'video/cluster/custom_offline.ts', $rWhy);
			$this->assertFileDoesNotExist($this->rBase . 'etc/cron.d/x');
		}
		// No download at all.
		$rOne = $this->command(1, 'artefact.fetch', ['artefact' => $rGood]);
		$this->assertStringContainsString('not downloaded', (string) ArtefactStage::placeOffAir($rOne['cmd']));
		// A link planted where the download should be is not followed.
		file_put_contents($this->rBase . 'elsewhere', $rBytes);
		symlink($this->rBase . 'elsewhere', $this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']);
		$this->assertStringContainsString('not a file', (string) ArtefactStage::placeOffAir($rOne['cmd']));
		$this->assertFileDoesNotExist($this->rBase . 'video/cluster/custom_offline.ts');
		$this->assertSame($rBytes, file_get_contents($this->rBase . 'elsewhere'), 'what it named is untouched');
		$this->assertFalse(is_link($this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']), 'the link itself is spent');
	}

	/**
	 * The happy path of a binary: root copies the agent's download into its
	 * own stage, checks it there, and only then runs the action, which takes
	 * the staged copy (the pinned agent, installed where run.sh starts it).
	 */
	public function testRootStagesAndChecksABinaryBeforeTheActionRuns(): void {
		$rBytes = random_bytes(20000);
		$rOne = $this->command(3, 'node.root', ['action' => 'agent_binary', 'arch' => 'amd64', 'version' => '1.5.0', 'artefact' => $this->grant('agent/amd64', 'xc_agent-linux-amd64', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rBytes);
		$this->inbox(3, $rOne['wire']);
		$rSeen = [];
		$rRun = function (array $rAction) use (&$rSeen, $rBytes, $rDownload): string {
			$rStaged = ArtefactStage::current();
			$this->assertNotNull($rStaged, 'the action is handed the staged copy');
			$rSeen = [$rAction['action'], $rStaged['path'], $rStaged['grant']['id']];
			$this->assertStringStartsWith($this->rBase . 'etc/stage/', $rStaged['path'], 'in root\'s own stage');
			$this->assertSame($rBytes, file_get_contents($rStaged['path']));
			$this->assertSame(0600, fileperms($rStaged['path']) & 0777);
			$this->assertSame(0700, fileperms($this->rBase . 'etc/stage') & 0777);
			$this->assertFileDoesNotExist($rDownload, 'the agent\'s copy is gone before the action runs');
			$this->assertNull(ArtefactStage::installAgent($rStaged, $this->rBase . 'bin/xc_agent/xc_agent'));
			return 'installed';
		};
		$rDone = ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertSame('agent_binary', $rSeen[0] ?? null, json_encode($this->done(3)));
		$this->assertSame([['seq' => 3, 'ok' => true, 'detail' => 'agent_binary']], $rDone);
		$this->assertSame(['ok' => true, 'result' => 'installed'], $this->done(3));
		$this->assertNull(ArtefactStage::current(), 'only while its action runs');
		$this->assertSame([], $this->staged(), 'the staged copy goes once the action ran');
		$rAgent = $this->rBase . 'bin/xc_agent/xc_agent';
		$this->assertSame($rBytes, file_get_contents($rAgent));
		$this->assertSame(0755, fileperms($rAgent) & 0777);
		if (AgentUser::root()) {
			$this->assertSame(AgentUser::UID, fileowner($rAgent), 'installed as the agent\'s user');
		}
		$this->assertSame([], glob($this->rBase . 'bin/xc_agent/.*.new') ?: []);
	}

	/** ArtefactHashRefusal: a tampered binary is refused before its action runs, the refusal audited. */
	public function testRootRefusesATamperedBinaryAndAuditsIt(): void {
		$rBytes = random_bytes(20000);
		$rTampered = $rBytes;
		$rTampered[100] = chr(ord($rTampered[100]) ^ 1);
		$rOne = $this->command(4, 'node.root', ['action' => 'agent_binary', 'arch' => 'amd64', 'version' => '1.5.0', 'artefact' => $this->grant('agent/amd64', 'xc_agent-linux-amd64', $rBytes)]);
		$rDownload = $this->download($rOne['cmd'], $rTampered);
		$this->inbox(4, $rOne['wire']);
		$rRan = false;
		$rDone = ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan, 'refused before exec');
		$this->assertFalse($rDone[0]['ok']);
		$rResult = $this->done(4);
		$this->assertFalse($rResult['ok']);
		$this->assertStringStartsWith('refused by root: artefact refused: agent/amd64', $rResult['result']);
		$this->assertStringContainsString('sha256 mismatch', $rResult['result']);
		$this->assertSame([], $this->staged(), 'nothing left staged');
		$this->assertFileDoesNotExist($rDownload);
		$this->assertSame('the running agent', file_get_contents($this->rBase . 'bin/xc_agent/xc_agent'));
		$this->assertSame(4, RootPin::highWater(), 'spent: a replay is refused too');
		$rLines = $this->audited();
		$this->assertCount(1, $rLines);
		$this->assertSame('log.syslog:ARTEFACT', $rLines[0][0]);
		$this->assertStringContainsString('agent/amd64', $rLines[0][1]);
		$this->assertStringContainsString($rOne['cmd']['cmd_id'], $rLines[0][1]);
	}

	/** Root never reads the agent's download with its own rights, nor follows a link planted there. */
	public function testRootNeverFollowsALinkTheAgentPlanted(): void {
		$rSecret = $this->rBase . 'root_only';
		file_put_contents($rSecret, 'root only');
		chmod($rSecret, 0600);
		$rOne = $this->command(5, 'node.root', ['action' => 'agent_binary', 'arch' => 'amd64', 'version' => '1.5.0', 'artefact' => $this->grant('agent/amd64', 'xc_agent-linux-amd64', 'root only')]);
		symlink($rSecret, $this->rBase . 'config/cluster/artefacts/' . $rOne['cmd']['cmd_id']);
		$this->inbox(5, $rOne['wire']);
		$rRan = false;
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('artefact refused', $this->done(5)['result']);
		$this->assertSame([], $this->staged());
		$this->assertSame('root only', file_get_contents($rSecret));

		// Nor without a download: refused, not run.
		$rTwo = $this->command(6, 'node.root', ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'artefact' => $this->grant('module/radio/1.0', 'radio_1.0.zip', 'PK')]);
		$this->inbox(6, $rTwo['wire']);
		ClusterRootCommand::drain(static function () use (&$rRan): string {
			$rRan = true;
			return 'x';
		}, 1800000000);
		$this->assertFalse($rRan);
		$this->assertStringContainsString('not downloaded', $this->done(6)['result']);
	}

	/** The actions that use an artefact take it only from root's stage, checked: never from a signals row or a path a payload names. */
	public function testActionsTakeOnlyAStagedArtefact(): void {
		$rDb = new class {
			public function query(string $rQuery, mixed ...$rArgs): bool {
				throw new \RuntimeException('no database here: ' . $rQuery);
			}
		};
		ob_start();
		(new RootSignalsCronJob())->executeAction(['action' => 'agent_binary', 'arch' => 'amd64', 'artefact_path' => $this->rBase . 'bin/xc_agent/xc_agent'], [], $rDb);
		$rOut = (string) ob_get_clean();
		$this->assertStringContainsString('refused', $rOut, 'not staged by cluster:root: a signals row, or a payload naming a path');
		$this->assertSame('the running agent', file_get_contents($this->rBase . 'bin/xc_agent/xc_agent'));

		// module:install's archive: only root's stage, and only the grant's bytes.
		mkdir($this->rBase . 'etc/stage', 0700);
		$rGrant = $this->grant('module/radio/1.0', 'radio_1.0.zip', 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('a', 32), 'PK-archive');
		file_put_contents($this->rBase . 'etc/stage/' . str_repeat('b', 32), 'PK-evil!!!');
		file_put_contents($this->rBase . 'elsewhere.zip', 'PK-archive');
		$this->assertNull(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('a', 32), $rGrant));
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('b', 32), $rGrant), 'not the grant\'s bytes');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'elsewhere.zip', $rGrant), 'outside the stage');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/../elsewhere.zip', $rGrant), 'out of it by a path');
		$this->assertIsString(ArtefactStage::stagedArchive($this->rBase . 'etc/stage/' . str_repeat('a', 32), null), 'no grant');

		// A crash's leftovers go after an hour.
		touch($this->rBase . 'etc/stage/' . str_repeat('b', 32), time() - ArtefactStage::STAGE_TTL - 1);
		ArtefactStage::pruneStage(time());
		$this->assertSame([str_repeat('a', 32)], $this->staged());
	}
}
