<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ArtefactGrants;
use XcVm\Domain\Cluster\ArtefactRegistry;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\ClusterSemaphore;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The `artefact` op on MAIN (plan section 7: off-air videos and pinned
 * binaries, in chunks of at most 4 MB, on the bulk lane, "granted by a
 * signed command"; section 8: "Off-air video_path in the token -> replica
 * basenames; custom videos via artefact").
 *
 * What MAIN may hand out is its registry's alone (ArtefactRegistry): the
 * admin's custom off-air videos, the archives of custom modules and the
 * agent binary MAIN pinned. A node gets an artefact only through a grant:
 * a `cmd`-signed command for it (`artefact.fetch`, or a `node.root` that
 * carries one) naming the artefact's id, size, SHA-256 and expiry. The op
 * then serves chunks of the file the grant names, while the grant lives,
 * to that node only, and never a path a node names.
 *
 * The test agent does what the Go agent does: opens the sealed token,
 * derives the session keys, BOXes and MACs its requests, and checks every
 * command against the pinned panel key.
 */
final class ClusterArtefactTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rNodeSk = '';

	private string $rDir;

	private array $rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit'] as $rName) {
			$rSql = (string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec((string) preg_replace(
				['/^--.*$/m', '/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '/,\s*PRIMARY KEY \(`id`\)/', '/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '/ unsigned| COLLATE \w+/', '/\) ENGINE=[^;]*;/'],
				['', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', '', '', '', ');'],
				$rSql
			));
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 0)');
		DatabaseFactory::set($this->rDb);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix(1800000000000);
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		// No cluster bus: never one at the checkout's default socket.
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		$this->rDir = sys_get_temp_dir() . '/artefact_main_' . bin2hex(random_bytes(4)) . '/';
		foreach (['video', 'modules_archives', 'agent_cache'] as $rSub) {
			mkdir($this->rDir . $rSub, 0755, true);
		}
		ArtefactRegistry::useDirs($this->rDir . 'modules_archives/', $this->rDir . 'agent_cache/');
		SettingsManager::set($this->rSettings);
	}

	protected function tearDown(): void {
		ArtefactRegistry::useDirs(null, null);
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The admin's custom video for an off-air name, as MAIN's settings name it. */
	private function video(string $rName, string $rFile, string $rBytes): string {
		$rPath = $this->rDir . 'video/' . $rFile;
		file_put_contents($rPath, $rBytes);
		$this->rSettings[\XcVm\Core\Cluster\ReplicaSections::OFF_AIR[$rName]] = $rPath;
		SettingsManager::set($this->rSettings);
		return $rPath;
	}

	/** Enrol, complete, and switch COMMANDS on, as the admin does; @return array<string, string> the session keys */
	private function activeNode(?string $rFeatures = 'artefact', int $rRootReady = 1): array {
		$rPair = sodium_crypto_sign_keypair();
		$this->rNodeSk = sodium_crypto_sign_secretkey($rPair);
		$rEphSk = random_bytes(32);
		$rFirst = EnrolmentService::issueFirst($this->rCrypto, self::SID, $this->rUuid, sodium_crypto_sign_publickey($rPair), sodium_crypto_scalarmult_base(random_bytes(32)), sodium_crypto_scalarmult_base($rEphSk), $this->rSettings, $this->rMain);
		$rBody = Seal::open($rEphSk, 'token', $this->rUuid, (string) $rFirst['token_sealed']);
		$this->assertNotNull($rBody);
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rKeys = ClusterReference::sessionKeys((string) hex2bin(json_decode(substr($rBody, 4, $rLen), true)['token']));
		[$rRes] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'agent_version' => '0.1.0'], $rKeys);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		NodeRegistry::update(self::SID, ['mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS, 'features' => $rFeatures, 'root_ready' => $rRootReady]);
		return $rKeys;
	}

	/** Another node, active and taking artefacts, with no session of its own here. */
	private function otherNode(int $rServerID): void {
		NodeRegistry::startEnrolment($rServerID, '1b4e28ba-2fa1-41d2-883f-0016d3cca427', random_bytes(32), sodium_crypto_scalarmult_base(random_bytes(32)), 1);
		NodeRegistry::update($rServerID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS, 'features' => 'artefact', 'root_ready' => 1]);
	}

	/** @return array{0: array, 1: string, 2: array} response, request context, request */
	private function call(string $rOp, array $rPayload, array $rKeys): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 1, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rBody = Box::box($rKeys['enc_up'], $rCtx, (string) json_encode($rPayload));
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'ip' => '10.0.0.5', 'body' => $rBody, 'headers' => [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid, 'X-XCVM-Epoch' => '1',
			'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce), 'Content-Type' => 'application/octet-stream',
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rKeys['mac_up'], $rCtx, $rBody)),
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		]];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->rSettings, $this->rMain), $rCtx, $rReq];
	}

	/** A session reply, verified and opened as the agent does. */
	private function reply(array $rRes, string $rCtx, array $rKeys): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, 200, $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		$this->assertTrue(Canonical::verifyMac($rKeys['mac_down'], $rResCtx, $rRes['body'], (string) hex2bin($rH['X-XCVM-Sig'])));
		return json_decode((string) Box::open($rKeys['enc_down'], $rResCtx, $rRes['body']), true);
	}

	/** A denial, verified as the agent does: panel-signed, about this node and request. */
	private function denial(array $rRes, array $rReq, int $rStatus, string $rReason): array {
		$this->assertSame($rStatus, $rRes['status'], $rRes['body']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame([$rReason, $this->rUuid, $rReq['headers']['X-XCVM-Nonce']], [$rDoc['reason'], $rDoc['node'], $rDoc['req_nonce']]);
		return $rDoc;
	}

	/**
	 * The node's commands, as the agent takes them off the long-poll: each
	 * panel-signed with tag `cmd` under the pinned key, for this node.
	 *
	 * @return list<array<string, mixed>> the signed documents
	 */
	private function commands(array $rKeys): array {
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], $rKeys);
		$rOut = [];
		foreach ($this->reply($rRes, $rCtx, $rKeys)['commands'] as $rOne) {
			$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'cmd', $rOne['doc'], (string) Enc::b64urlDecode($rOne['sig'])), 'panel-signed, tag cmd');
			$rDoc = json_decode($rOne['doc'], true);
			$this->assertSame($this->rUuid, $rDoc['node_uuid']);
			$rOut[] = $rDoc;
		}
		return $rOut;
	}

	/**
	 * Fetch a granted artefact whole, chunk by chunk, as the agent does.
	 *
	 * @return array{0: string, 1: list<array<string, mixed>>} the bytes, and each reply without its data
	 */
	private function fetch(array $rKeys, string $rGrant, int $rChunk): array {
		$rBytes = '';
		$rReplies = [];
		for ($rOffset = 0;;) {
			[$rRes, $rCtx] = $this->call('artefact', ['grant' => $rGrant, 'offset' => $rOffset, 'length' => $rChunk], $rKeys);
			$rOut = $this->reply($rRes, $rCtx, $rKeys);
			$rData = base64_decode((string) $rOut['data'], true);
			$this->assertIsString($rData);
			$this->assertSame(strlen($rData), $rOut['length']);
			$this->assertSame($rOffset, $rOut['offset']);
			$rBytes .= $rData;
			unset($rOut['data']);
			$rReplies[] = $rOut;
			$rOffset += strlen($rData);
			if ($rOut['eof']) {
				return [$rBytes, $rReplies];
			}
			$this->assertLessThan(64, count($rReplies), 'the download ends');
		}
	}

	/** @return list<array<string, mixed>> the audit rows, oldest first */
	private function audit(string $rEvent): array {
		$this->rDb->query('SELECT `server_id`, `actor`, `detail` FROM `cluster_audit` WHERE `event` = ? ORDER BY `id`', $rEvent);
		return $this->rDb->get_rows();
	}

	public function testTheRegistryServesOnlyWhatMainNamesItself(): void {
		$rPath = $this->video('not_on_air', 'custom_offline.ts', str_repeat("\x47", 1880));
		$rDesc = ArtefactRegistry::describe('offair/not_on_air', $this->rSettings);
		$this->assertSame(['offair/not_on_air', 'custom_offline.ts', 1880, hash_file('sha256', $rPath)], [$rDesc['id'], $rDesc['name'], $rDesc['size'], $rDesc['sha256']]);
		$this->assertSame(['offair/not_on_air'], ArtefactRegistry::offAirIds($this->rSettings), 'the off-air names with a custom video, and only those');

		// A module archive and the pinned agent, from MAIN's own directories.
		file_put_contents($this->rDir . 'modules_archives/radio_1.2.0.zip', 'PK' . str_repeat('z', 100));
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-arm64', str_repeat("\x7f", 300));
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-arm64.version', "1.4.2\n");
		$this->assertSame(['radio_1.2.0.zip', 102], [ArtefactRegistry::describe('module/radio/1.2.0', [])['name'], ArtefactRegistry::describe('module/radio/1.2.0', [])['size']]);
		$rAgent = ArtefactRegistry::describe('agent/arm64', []);
		$this->assertSame(['xc_agent-linux-arm64', '1.4.2'], [$rAgent['name'], $rAgent['version']]);
		$this->assertNull(ArtefactRegistry::describe('agent/amd64', []), 'not cached');
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64', 'x');
		$this->assertNull(ArtefactRegistry::describe('agent/amd64', []), 'a binary without its verified version is not pinned');

		// Anything else: never a path, never outside the registry.
		foreach ([
			'offair/../../etc/passwd', 'offair/unknown', 'module/../../../etc/passwd/1', 'module/radio/../../x', 'module/Radio/1.2.0',
			'module/radio/1..2', 'agent/../../bin/sh', 'agent/sparc', '/etc/passwd', 'file:///etc/passwd', '', 'offair/not_on_air/', 'offair/not_on_air/x',
		] as $rId) {
			$this->assertNull(ArtefactRegistry::locate($rId, $this->rSettings), $rId);
			$this->assertFalse(ArtefactStage::validId($rId), $rId);
		}
		// An off-air setting that is not a local video file MAIN can serve.
		foreach (['http://cdn.example/offline.ts', 'relative/offline.ts', $this->rDir . 'video/missing.ts', $this->rDir . 'video', '/etc/passwd', $this->rDir . 'video/.hidden.ts'] as $rSetting) {
			if ($rSetting === $this->rDir . 'video/.hidden.ts') {
				file_put_contents($rSetting, 'x');
			}
			$this->assertNull(ArtefactRegistry::locate('offair/banned', ['banned_video_path' => $rSetting]), $rSetting);
		}
		$this->assertNull(ArtefactRegistry::locate('offair/banned', ['banned_video_path' => '']), 'no custom video: the node plays its own');
		// A link to a file outside MAIN's own tree is not followed out of it.
		symlink('/etc/hostname', $this->rDir . 'modules_archives/evil_1.0.zip');
		$this->assertNull(ArtefactRegistry::locate('module/evil/1.0', []));
	}

	public function testOffAirVideosAreGrantedToNodesThatTakeThem(): void {
		$rKeys = $this->activeNode(null);
		$rPath = $this->video('not_on_air', 'custom_offline.ts', str_repeat("\x47", 5000));
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'today\'s agent (no `artefact` at hello) is never granted one');
		$this->assertSame([], $this->commands($rKeys));

		NodeRegistry::update(self::SID, ['features' => 'artefact,streams']);
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		$rCommands = $this->commands($rKeys);
		$this->assertCount(1, $rCommands);
		$this->assertSame(ArtefactGrants::TYPE, $rCommands[0]['type']);
		$rGrant = $rCommands[0]['args']['artefact'];
		$this->assertSame(['offair/not_on_air', 'custom_offline.ts', 5000, hash_file('sha256', $rPath), $rCommands[0]['exp']], [$rGrant['id'], $rGrant['name'], $rGrant['size'], $rGrant['sha256'], $rGrant['exp']], 'id, size, SHA-256, and the command\'s own expiry');
		$this->assertSame(ClusterClock::now() + CommandBus::TTL['artefact.'], $rGrant['exp']);
		$this->assertIsArray(ArtefactStage::grant($rCommands[0]), 'the node takes its shape');

		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'granted once, not every minute');
		// A new video under the same name: granted again.
		clearstatcache();
		file_put_contents($rPath, str_repeat("\x47", 4000));
		touch($rPath, time() + 5);
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		// A node without COMMANDS, or quarantined, gets none.
		$this->video('banned', 'custom_banned.ts', 'b');
		NodeRegistry::update(self::SID, ['flows' => 0]);
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS, 'state' => 'quarantined']);
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
	}

	/** What a node was offered belongs to its generation: a re-enrolled node (a reinstall) is offered every video afresh. */
	public function testAReEnrolledNodeIsOfferedAfresh(): void {
		$rKeys = $this->activeNode();
		$this->video('not_on_air', 'custom_offline.ts', str_repeat('n', 300));
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $this->commands($rKeys)[0]['cmd_id'], 'ok' => true, 'result' => ''], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		NodeRegistry::update(self::SID, ['gen' => 2]);
		$this->assertSame(1, ArtefactGrants::offerOffAir(fn() => $this->rCrypto, $this->rSettings), 'the extension asked for only when there is a grant to sign');
		$this->rDb->query('SELECT `payload` FROM `cluster_commands` ORDER BY `seq` DESC LIMIT 1');
		$this->assertSame(2, json_decode($this->rDb->get_row()['payload'], true)['gen']);
		$this->assertSame(0, ArtefactGrants::offerOffAir(static function (): never {
			throw new \LogicException('nothing to sign: the extension is not needed');
		}, $this->rSettings));
	}

	/** A node's refusal reaches MAIN's system log as root's line on that node (type ARTEFACT). */
	public function testMainKeepsANodesArtefactRefusalInItsSystemLog(): void {
		$this->activeNode();
		$this->rDb->exec('CREATE TABLE `mysql_syslog` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `type` varchar(50), `error` text, `username` varchar(64), `ip` varchar(64), `database` varchar(64), `date` int, `server_id` int DEFAULT 1)');
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_LOGS]);
		$rOut = EventIngest::ingest((array) NodeRegistry::byServer(self::SID), 'p1', 1, [['type' => 'log.syslog', 'd' => ['rows' => [
			['server_id' => 5, 'type' => 'ARTEFACT', 'error' => 'Refused artefact agent/amd64 (xc_agent-linux-amd64) for command ' . str_repeat('a', 32) . ': sha256 mismatch', 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => 1799999000],
		]]]]);
		$this->assertSame(1, $rOut['applied']);
		$this->rDb->query('SELECT `server_id`, `type`, `username` FROM `mysql_syslog`');
		$this->assertSame([['server_id' => 5, 'type' => 'ARTEFACT', 'username' => 'root']], array_map(static fn($r) => ['server_id' => (int) $r['server_id'], 'type' => $r['type'], 'username' => $r['username']], $this->rDb->get_rows()));
	}

	/**
	 * The happy path of an off-air video: granted, then fetched whole in
	 * chunks on the bulk lane, each inside the BOX, the whole matching the
	 * grant's size and SHA-256.
	 */
	public function testANodeFetchesItsGrantInChunks(): void {
		$rKeys = $this->activeNode();
		$rBytes = random_bytes(10000);
		$this->video('connected', 'second_ip.ts', $rBytes);
		ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings);
		$rCmd = $this->commands($rKeys)[0];
		[$rGot, $rReplies] = $this->fetch($rKeys, $rCmd['cmd_id'], 4096);
		$this->assertSame($rBytes, $rGot);
		$this->assertSame([4096, 4096, 1808], array_column($rReplies, 'length'));
		$this->assertSame([false, false, true], array_column($rReplies, 'eof'));
		foreach ($rReplies as $rOut) {
			$this->assertSame([$rCmd['cmd_id'], 10000, hash('sha256', $rBytes)], [$rOut['grant'], $rOut['size'], $rOut['sha256']]);
		}
		// Once acked the grant is spent.
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rCmd['cmd_id'], 'ok' => true, 'result' => '{"placed":"second_ip.ts"}'], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rCmd['cmd_id'], 'offset' => 0, 'length' => 4096], $rKeys);
		$this->denial($rRes, $rReq, 403, 'GRANT_INVALID');
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'the node holds it: not granted again');

		// The op is an ingest op on the bulk lane (its permit, ClusterSemaphore::runIngest) and reads no MAIN row.
		$this->assertContains('artefact', ClusterPool::INGEST_OPS);
		$this->assertSame(ClusterSemaphore::LANE_BULK, ClusterSemaphore::ingestLane('artefact', ['lane' => 'p0']));
		$this->assertFalse(ClusterApi::readsMain(Canonical::PATH_PREFIX . 'artefact'));
	}

	/** A chunk is at most 4 MiB; a range outside the artefact, or a malformed one, is refused. */
	public function testRangesAreChecked(): void {
		$rKeys = $this->activeNode();
		$rBytes = str_repeat('0123456789abcdef', 262144) . 'tail';
		$this->video('expired', 'expired_custom.ts', $rBytes);
		ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings);
		$rGrant = $this->commands($rKeys)[0]['cmd_id'];

		[$rRes, $rCtx] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => ArtefactStage::MAX_CHUNK], $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame([ArtefactStage::MAX_CHUNK, false], [$rOut['length'], $rOut['eof']], 'a whole 4 MiB chunk in one reply');
		$this->assertSame(substr($rBytes, 0, ArtefactStage::MAX_CHUNK), base64_decode($rOut['data']));
		[$rRes, $rCtx] = $this->call('artefact', ['grant' => $rGrant, 'offset' => ArtefactStage::MAX_CHUNK, 'length' => ArtefactStage::MAX_CHUNK], $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(['tail', true], [base64_decode($rOut['data']), $rOut['eof']], 'the last chunk stops at the end');

		foreach ([
			[['offset' => strlen($rBytes), 'length' => 1], 416, 'BAD_RANGE'],
			[['offset' => strlen($rBytes) + 100, 'length' => 1], 416, 'BAD_RANGE'],
			[['offset' => 0, 'length' => ArtefactStage::MAX_CHUNK + 1], 416, 'BAD_RANGE'],
			[['offset' => -1, 'length' => 10], 400, 'BAD_REQUEST'],
			[['offset' => 0, 'length' => 0], 400, 'BAD_REQUEST'],
			[['offset' => '0', 'length' => 10], 400, 'BAD_REQUEST'],
			[['offset' => 0], 400, 'BAD_REQUEST'],
			[['offset' => 1.5, 'length' => 10], 400, 'BAD_REQUEST'],
		] as [$rAsk, $rStatus, $rReason]) {
			[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rGrant] + $rAsk, $rKeys);
			$rDoc = $this->denial($rRes, $rReq, $rStatus, $rReason);
			if ($rReason === 'BAD_RANGE') {
				$this->assertSame([strlen($rBytes), ArtefactStage::MAX_CHUNK], [$rDoc['size'], $rDoc['max_chunk']]);
			}
		}
	}

	/** No grant, another node's, a spent or expired one, one that is not an artefact's: refused alike. */
	public function testOnlyALiveGrantToThisNodeServes(): void {
		$rKeys = $this->activeNode();
		$this->otherNode(6);
		$this->video('banned', 'custom_banned.ts', str_repeat('b', 3000));
		ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings);
		$this->rDb->query('SELECT `server_id`, `cmd_id` FROM `cluster_commands` ORDER BY `server_id`');
		[$rMine, $rTheirs] = array_column($this->rDb->get_rows(), 'cmd_id');
		$rRpc = CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);

		foreach ([
			'no grant at all' => bin2hex(random_bytes(16)),
			'another node\'s grant' => $rTheirs,
			'a command that grants nothing' => $rRpc,
		] as $rWhy => $rGrant) {
			[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => 100], $rKeys);
			$this->denial($rRes, $rReq, 403, 'GRANT_INVALID');
		}
		foreach (['../../etc/passwd', str_repeat('A', 32), '', 42] as $rBad) {
			[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rBad, 'offset' => 0, 'length' => 100], $rKeys);
			$this->denial($rRes, $rReq, 400, 'BAD_REQUEST');
		}
		// What a node names besides the grant is never read: the grant's own file is served.
		[$rRes, $rCtx] = $this->call('artefact', ['grant' => $rMine, 'offset' => 0, 'length' => 100, 'id' => 'agent/amd64', 'path' => '/etc/passwd', 'name' => '../../etc/shadow'], $rKeys);
		$this->assertSame(str_repeat('b', 100), base64_decode($this->reply($rRes, $rCtx, $rKeys)['data']));

		// Past its expiry (the command's own): refused, though the session still works.
		ClusterClock::fix(1800000000000 + (CommandBus::TTL['artefact.'] + 1) * 1000);
		[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rMine, 'offset' => 0, 'length' => 100], $rKeys);
		$this->denial($rRes, $rReq, 403, 'GRANT_INVALID');
		[$rRes] = $this->call('heartbeat', [], $rKeys);
		$this->assertSame(200, $rRes['status']);
	}

	/** A grant whose id is not the registry's (a row written by hand) never reads a file. */
	public function testAForgedGrantRowNamesNoPath(): void {
		$rKeys = $this->activeNode();
		file_put_contents($this->rDir . 'secret', 'do not serve');
		foreach (['offair/../../' . ltrim($this->rDir, '/') . 'secret', 'module/../secret/1', '../secret'] as $rId) {
			$rCmd = CommandBus::enqueue($this->rCrypto, self::SID, ArtefactGrants::TYPE, ['artefact' => ['id' => $rId, 'name' => 'secret', 'size' => 12, 'sha256' => hash('sha256', 'do not serve'), 'mtime' => filemtime($this->rDir . 'secret')]]);
			[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rCmd, 'offset' => 0, 'length' => 12], $rKeys);
			$this->denial($rRes, $rReq, 403, 'GRANT_INVALID');
			$this->assertStringNotContainsString('do not serve', $rRes['body']);
		}
	}

	/** MAIN's file changed since the grant (a new video under the same path): the node starts over on a new grant. */
	public function testAChangedFileIsNotServedUnderAnOldGrant(): void {
		$rKeys = $this->activeNode();
		$rPath = $this->video('expiring', 'expiring_custom.ts', str_repeat('e', 2000));
		ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings);
		$rGrant = $this->commands($rKeys)[0]['cmd_id'];
		file_put_contents($rPath, str_repeat('E', 2000));
		touch($rPath, time() + 10);
		clearstatcache();
		[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => 100], $rKeys);
		$this->denial($rRes, $rReq, 409, 'ARTEFACT_CHANGED');
		// The setting now names another file: the old grant's id resolves elsewhere.
		$this->video('expiring', 'other.ts', str_repeat('e', 2000));
		[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => 100], $rKeys);
		$this->denial($rRes, $rReq, 409, 'ARTEFACT_CHANGED');
		unlink($this->rDir . 'video/other.ts');
		[$rRes, , $rReq] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => 100], $rKeys);
		$this->denial($rRes, $rReq, 409, 'ARTEFACT_CHANGED');
	}

	/**
	 * Pinned binaries and module archives ride the `node.root` command that
	 * needs them (plan section 7, "Root artefacts"), for root to stage and
	 * check before it runs the action: the binary happy path.
	 */
	public function testRootActionsCarryTheirArtefactsGrant(): void {
		$rKeys = $this->activeNode();
		$rBinary = random_bytes(9000);
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64', $rBinary);
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64.version', "1.5.0\n");
		file_put_contents($this->rDir . 'modules_archives/radio_2.0.1.zip', 'PK' . str_repeat('m', 700));

		$this->assertTrue(NodeActions::agentBinary(self::SID, 'amd64'));
		$this->assertTrue(NodeActions::send(self::SID, ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '2.0.1']));
		$this->assertTrue(NodeActions::send(self::SID, ['action' => 'install_module', 'source' => 'platform', 'name' => 'store', 'version' => '1.0.0']));
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		$rCommands = $this->commands($rKeys);
		$this->assertSame(['node.root', 'node.root', 'node.root', 'node.root'], array_column($rCommands, 'type'));
		[$rAgent, $rModule, $rStore, $rReload] = array_column($rCommands, 'args');
		$this->assertSame(['agent_binary', 'amd64', '1.5.0'], [$rAgent['action'], $rAgent['arch'], $rAgent['version']]);
		$this->assertSame(['agent/amd64', 'xc_agent-linux-amd64', 9000, hash('sha256', $rBinary), $rCommands[0]['exp']], [$rAgent['artefact']['id'], $rAgent['artefact']['name'], $rAgent['artefact']['size'], $rAgent['artefact']['sha256'], $rAgent['artefact']['exp']]);
		$this->assertSame(['module/radio/2.0.1', 702], [$rModule['artefact']['id'], $rModule['artefact']['size']]);
		$this->assertArrayNotHasKey('artefact', $rStore, 'a store module comes from the platform, not MAIN');
		$this->assertArrayNotHasKey('artefact', $rReload);

		[$rGot] = $this->fetch($rKeys, $rCommands[0]['cmd_id'], 4000);
		$this->assertSame($rBinary, $rGot);

		// No pinned binary for the arch: nothing is sent, and never through the signals table.
		$this->assertFalse(NodeActions::agentBinary(self::SID, 'arm64'));
		$this->assertCount(4, $this->commands($rKeys));
	}

	/** Today's agent never names `artefact` at hello: its root commands stay as they were, and no binary is pushed to it. */
	public function testANodeWithoutTheFeatureKeepsTheLegacyPaths(): void {
		$rKeys = $this->activeNode(null);
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64', 'bin');
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64.version', "1.5.0\n");
		file_put_contents($this->rDir . 'modules_archives/radio_2.0.1.zip', 'PK');
		$this->assertFalse(NodeActions::agentBinary(self::SID, 'amd64'));
		$this->assertTrue(NodeActions::send(self::SID, ['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '2.0.1']));
		$rCommands = $this->commands($rKeys);
		$this->assertCount(1, $rCommands);
		$this->assertSame(['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '2.0.1'], $rCommands[0]['args'], 'as before: the node pulls the archive the legacy way');
	}

	/** A grant is a granting command: signed only under a licence, like every other. */
	public function testGrantsNeedALicence(): void {
		$rKeys = $this->activeNode();
		$this->video('not_on_air', 'custom_offline.ts', str_repeat('n', 3000));
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64', 'bin');
		file_put_contents($this->rDir . 'agent_cache/xc_agent-linux-amd64.version', "1.5.0\n");
		// Granted while licensed.
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
		$rGrant = $this->commands($rKeys)[0]['cmd_id'];

		$this->rCrypto->rLicensed = false;
		$this->video('banned', 'custom_banned.ts', 'b');
		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'nothing signed without a licence');
		$this->assertFalse(NodeActions::agentBinary(self::SID, 'amd64'));
		$this->assertCount(1, $this->commands($rKeys));
		// In graceful mode the session still works, and a grant signed before the lapse still serves.
		[$rRes, $rCtx] = $this->call('artefact', ['grant' => $rGrant, 'offset' => 0, 'length' => 10], $rKeys);
		$this->assertSame('nnnnnnnnnn', base64_decode($this->reply($rRes, $rCtx, $rKeys)['data']));

		// Licensed again: the video not granted meanwhile is offered at the next pass.
		$this->rCrypto->rLicensed = true;
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings));
	}

	/** A node that refuses what it fetched (its size or SHA-256 differs) acks the command failed: MAIN audits it, and offers again later. */
	public function testARefusedArtefactIsAuditedOnMain(): void {
		$rKeys = $this->activeNode();
		$this->video('not_on_air', 'custom_offline.ts', str_repeat('n', 3000));
		ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings);
		$rCmd = $this->commands($rKeys)[0];
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rCmd['cmd_id'], 'ok' => false, 'result' => 'artefact refused: offair/not_on_air: sha256 mismatch'], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		// A repeated ack (its reply was lost) is not audited twice.
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rCmd['cmd_id'], 'ok' => false, 'result' => 'artefact refused: offair/not_on_air: sha256 mismatch'], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$rRows = $this->audit('artefact.refused');
		$this->assertCount(1, $rRows);
		$this->assertSame([self::SID, 'node'], [(int) $rRows[0]['server_id'], $rRows[0]['actor']]);
		$rDetail = json_decode($rRows[0]['detail'], true);
		$this->assertSame([$rCmd['cmd_id'], 'offair/not_on_air', 'artefact refused: offair/not_on_air: sha256 mismatch'], [$rDetail['cmd_id'], $rDetail['id'], $rDetail['result']]);

		$this->assertSame(0, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'not again at once');
		ClusterClock::fix(1800000000000 + ArtefactGrants::RETRY_AFTER * 1000);
		$this->assertSame(1, ArtefactGrants::offerOffAir($this->rCrypto, $this->rSettings), 'again an hour later');

		// A failure that is not a refusal (the download stopped) is audited as a failure.
		$rAgain = $this->commands($rKeys)[0]['cmd_id'];
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rAgain, 'ok' => false, 'result' => 'artefact offair/not_on_air: GRANT_INVALID'], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertCount(1, $this->audit('artefact.failed'));
		// A failed command that grants nothing is not an artefact's.
		$rRpc = CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['action' => 'get_pids']);
		[$rRes, $rCtx] = $this->call('ack', ['cmd_id' => $rRpc, 'ok' => false, 'result' => 'artefact refused: boom'], $rKeys);
		$this->reply($rRes, $rCtx, $rKeys);
		$this->assertCount(1, $this->audit('artefact.refused'));
		$this->assertCount(1, $this->audit('artefact.failed'));
	}
}
