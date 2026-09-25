<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeHealth;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * MAIN's cluster API end to end, against the real migrations' schema: SSH
 * enrolment issues epoch 1, and a test agent (doing what the Go agent does:
 * open the sealed token, derive the session keys, sign, BOX, MAC) walks the
 * node through enrol_complete, hello, heartbeat, token_refresh and revocation.
 */
final class ClusterApiTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	/** Fresh per test, as per install: the real extension's revocation floor outlives the test database. */
	private string $rUuid;

	private ClusterCrypto $rCrypto;

	private ?string $rDir = null;

	private array $rSettings;

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0];

	private string $rNodeSk;

	/** @var array{0: string, 1: string} current [epoch => eph sk] */
	private array $rEph = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '032_create_cluster_audit'] as $rName) {
			$this->rDb->exec($this->ddl((string) file_get_contents(dirname(__DIR__, 2) . '/src/migrations/database/up/' . $rName . '.sql')));
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 0)');
		DatabaseFactory::set($this->rDb);
		$this->rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];
		SettingsManager::set($this->rSettings);
		$rHex = bin2hex(random_bytes(16));
		$this->rUuid = sprintf('%s-%s-4%s-a%s-%s', substr($rHex, 0, 8), substr($rHex, 8, 4), substr($rHex, 13, 3), substr($rHex, 17, 3), substr($rHex, 20, 12));
		if (getenv('XCVM_CLUSTER_API_REAL')) {
			$this->rCrypto = $this->realExtension();
			$this->rT0 = (int) floor(microtime(true) * 1000);
		} else {
			$this->rCrypto = new FakeClusterCrypto();
		}
		ClusterClock::fix($this->rT0);
	}

	/**
	 * Opt-in: the same flows against a real test-hooks xcvm_core, with a fake
	 * licence in a throwaway XCVM_CONFIG_DIR (as ClusterExtensionIntegrationTest):
	 *
	 *   XCVM_CLUSTER_API_REAL=1 XCVM_CONFIG_DIR=$(mktemp -d) php -d extension=…/xcvm_core.so \
	 *     tests/phpunit.phar -c tests/phpunit.xml.dist --filter ClusterApiTest
	 */
	private function realExtension(): ClusterCrypto {
		$rDir = (string) getenv('XCVM_CONFIG_DIR');
		if (!class_exists('XC_VM', false) || !method_exists('XC_VM', 'cluster_session')) {
			$this->markTestSkipped('xcvm_core with the cluster API is not loaded');
		}
		if ($rDir === '' || !str_starts_with(realpath($rDir) ?: '', realpath(sys_get_temp_dir()) ?: '/tmp') || file_exists($rDir . '/config.enc')) {
			$this->markTestSkipped('XCVM_CONFIG_DIR must be a throwaway directory under the temp dir');
		}
		$this->rDir = $rDir;
		$rPair = sodium_crypto_sign_keypair();
		putenv('XCVM_TEST_VERDICT_PK_HEX=' . bin2hex(sodium_crypto_sign_publickey($rPair)));
		putenv('XCVM_TEST_CLUSTER_LIC_TTL=0');
		$rPayload = json_encode(['v' => 1, 'jti' => bin2hex(random_bytes(16)), 'hwid' => \XC_VM::install_id(), 'exp' => null, 'kid' => 1, 'iat' => time()], JSON_UNESCAPED_SLASHES);
		$rB64 = static fn(string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
		file_put_contents($rDir . '/activation_key', 'XCVM1.' . $rB64($rPayload) . '.' . $rB64(sodium_crypto_sign_detached($rPayload, sodium_crypto_sign_secretkey($rPair))));
		if (!\XC_VM::license_valid()) {
			$this->markTestSkipped('not a test-hooks build (the fake licence was not accepted)');
		}
		$rCrypto = ClusterCryptoFactory::create();
		$rCrypto->init();
		return $rCrypto;
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		if ($this->rDir !== null) {
			putenv('XCVM_TEST_VERDICT_PK_HEX');
			putenv('XCVM_TEST_CLUSTER_LIC_TTL');
			@unlink($this->rDir . '/activation_key');
		}
	}

	/** The migrations' MariaDB DDL, reduced to what SQLite accepts. */
	private function ddl(string $rSql): string {
		if (getenv('XCVM_TEST_DB_DSN')) {
			return $rSql;
		}
		$rSql = (string) preg_replace('/^--.*$/m', '', $rSql);
		$rSql = (string) preg_replace('/`id` bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $rSql);
		$rSql = (string) preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $rSql);
		$rSql = (string) preg_replace('/,\s*(UNIQUE )?KEY `\w+` \([^)]*\)/', '', $rSql);
		$rSql = (string) preg_replace('/ unsigned| COLLATE \w+/', '', $rSql);
		return (string) preg_replace('/\) ENGINE=[^;]*;/', ');', $rSql);
	}

	// ── The test agent ───────────────────────────────────────────────────

	private function enrol(): array {
		$rPair = sodium_crypto_sign_keypair();
		$this->rNodeSk = sodium_crypto_sign_secretkey($rPair);
		$rEphSk = random_bytes(32);
		$rFirst = EnrolmentService::issueFirst(
			$this->rCrypto,
			self::SID,
			$this->rUuid,
			sodium_crypto_sign_publickey($rPair),
			random_bytes(32),
			sodium_crypto_scalarmult_base($rEphSk),
			$this->rSettings,
			$this->rMain
		);
		$this->rEph = [1 => $rEphSk];
		return $rFirst;
	}

	/** Open a sealed token as the agent does; @return array{doc: array, keys: array} */
	private function openToken(string $rSealed, string $rEphSk): array {
		$rBody = Seal::open($rEphSk, 'token', $this->rUuid, $rSealed);
		$this->assertNotNull($rBody, 'the token opens with the per-epoch key');
		$rLen = unpack('N', substr($rBody, 0, 4))[1];
		$rDoc = substr($rBody, 4, $rLen);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'tok', $rDoc, substr($rBody, 4 + $rLen)));
		$rJson = json_decode($rDoc, true);
		return ['doc' => $rJson, 'keys' => ClusterReference::sessionKeys((string) hex2bin($rJson['token']))];
	}

	/**
	 * Build a request as the agent does.
	 *
	 * @param array<string, callable> $rTamper
	 * @return array{req: array, ctx: string}
	 */
	private function request(string $rOp, array $rPayload, int $rEpoch, array $rKeys, array $rTamper = []): array {
		$rNonce = $rTamper['nonce'] ?? random_bytes(16);
		$rTs = $rTamper['ts'] ?? ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . $rOp;
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => $rEpoch, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rBody = Box::box($rKeys['enc_up'], $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => (string) $rEpoch, 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'X-XCVM-Sig' => bin2hex(Canonical::mac($rKeys['mac_up'], $rCtx, $rBody)),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		if (isset($rTamper['body'])) {
			$rBody = $rTamper['body']($rBody);
		}
		if (isset($rTamper['headers'])) {
			$rHeaders = $rTamper['headers']($rHeaders);
		}
		return ['req' => ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'], 'ctx' => $rCtx];
	}

	private function call(string $rOp, array $rPayload, int $rEpoch, array $rKeys, array $rTamper = []): array {
		$r = $this->request($rOp, $rPayload, $rEpoch, $rKeys, $rTamper);
		return [ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), $r['ctx'], $r['req']];
	}

	/** Verify and open a boxed reply as the agent does. */
	private function reply(array $rRes, string $rCtx, array $rKeys): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rH = $rRes['headers'];
		$rResCtx = Canonical::response($rCtx, $rRes['status'], $rH['Content-Type'], (int) $rH['X-XCVM-Ts'], (string) hex2bin($rH['X-XCVM-Nonce']));
		$this->assertTrue(Canonical::verifyMac($rKeys['mac_down'], $rResCtx, $rRes['body'], (string) hex2bin($rH['X-XCVM-Sig'])), 'reply MAC');
		$rPlain = Box::open($rKeys['enc_down'], $rResCtx, $rRes['body']);
		$this->assertNotNull($rPlain, 'reply opens');
		return json_decode($rPlain, true);
	}

	/** Verify a denial as the agent does: panel-signed, and about this request. */
	private function denial(array $rRes, int $rStatus, string $rReason, ?array $rReq = null): array {
		$this->assertSame($rStatus, $rRes['status'], $rRes['body']);
		$rSig = Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'den', $rRes['body'], (string) $rSig), 'denial signature');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($rReason, $rDoc['reason']);
		if ($rReq !== null) {
			$this->assertSame($this->rUuid, $rDoc['node']);
			$this->assertSame($rReq['headers']['X-XCVM-Nonce'], $rDoc['req_nonce'], 'bound to the request nonce');
		}
		return $rDoc;
	}

	private function active(): array {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'boot_id' => 'boot-1', 'agent_version' => '0.1.0'], 1, $rTok['keys']);
		$this->reply($rRes, $rCtx, $rTok['keys']);
		return $rTok['keys'];
	}

	/** GET challenge?cn= as the agent does: the signed document's challenge. */
	private function challenge(): string {
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => []], $this->rSettings, $this->rMain);
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		return (string) base64_decode(json_decode($rRes['body'], true)['challenge']);
	}

	/**
	 * POST token_rekey as the agent does: epoch 0, no MAC, the body SEALed to
	 * the panel box key under the request context, node-signed.
	 *
	 * @param array<string, mixed> $rExtra Payload fields to add or override.
	 * @param array<string, callable> $rTamper
	 * @return array{0: array, 1: array} response, request
	 */
	private function rekey(string $rChallenge, string $rEphSk, array $rExtra = [], array $rTamper = []): array {
		$rNonce = random_bytes(16);
		$rTs = ClusterClock::nowMs();
		$rPath = Canonical::PATH_PREFIX . 'token_rekey';
		$rCtx = Canonical::request([
			'proto' => 1, 'agent' => 'xc_agent/0.1', 'method' => 'POST', 'path' => $rPath, 'query' => '',
			'content_type' => 'application/octet-stream', 'content_encoding' => '', 'node' => $this->rUuid,
			'epoch' => 0, 'ts_ms' => $rTs, 'nonce' => $rNonce,
		]);
		$rPayload = $rExtra + [
			'challenge' => base64_encode($rChallenge), 'eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEphSk)),
			'instance_id' => 'inst-a', 'boot_id' => 'boot-9', 'agent_version' => '0.1.2',
		];
		$rBody = Seal::seal($this->rCrypto->info()['panel_box_pub'], 'rekey', $rCtx, (string) json_encode($rPayload));
		$rHeaders = [
			'X-XCVM-Proto' => '1', 'X-XCVM-Agent' => 'xc_agent/0.1', 'X-XCVM-Node' => $this->rUuid,
			'X-XCVM-Epoch' => '0', 'X-XCVM-Ts' => (string) $rTs, 'X-XCVM-Nonce' => bin2hex($rNonce),
			'Content-Type' => 'application/octet-stream',
			'X-XCVM-Node-Sig' => bin2hex(NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
		];
		if (isset($rTamper['headers'])) {
			$rHeaders = $rTamper['headers']($rHeaders);
		}
		$rReq = ['method' => 'POST', 'path' => $rPath, 'query' => '', 'headers' => $rHeaders, 'body' => $rBody, 'ip' => '10.0.0.5'];
		return [ClusterApi::handle($this->rCrypto, $rReq, $this->rSettings, $this->rMain), $rReq];
	}

	/** Verify a re-key reply as the agent does: panel-signed `pre`, about this request; @return array{doc: array, keys: array} the new epoch. */
	private function rekeyed(array $rRes, array $rReq, string $rEphSk): array {
		$this->assertSame(200, $rRes['status'], $rRes['body']);
		$rSig = Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig']);
		$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'pre', $rRes['body'], (string) $rSig), 're-key reply signature');
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame('xcvm-rekey', $rDoc['typ']);
		$this->assertSame($this->rUuid, $rDoc['node']);
		$this->assertSame($rReq['headers']['X-XCVM-Nonce'], $rDoc['req_nonce']);
		$rTok = $this->openToken((string) base64_decode($rDoc['token_sealed']), $rEphSk);
		$this->assertSame($rDoc['epoch'], $rTok['doc']['epoch']);
		return $rTok;
	}

	/** An active node whose tokens are all gone (expiry without moving the clock, so it holds for the real extension too). */
	private function expired(): array {
		$rKeys = $this->active();
		$this->rDb->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = 5');
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq);
		return $rKeys;
	}

	// ── Tests ────────────────────────────────────────────────────────────

	public function testEnrolmentIssuesEpochOneAndClusterJson(): void {
		$rFirst = $this->enrol();
		$this->assertSame(1, $rFirst['epoch']);
		$this->assertSame(1, $rFirst['gen']);
		$this->assertSame($this->rUuid, $rFirst['cluster']['node_uuid']);
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'], $rFirst['cluster']['policy']['main_urls']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('enrolling', $rNode['state']);
		$this->assertSame(1, (int) $rNode['mode'], 'hybrid while lb_new_node_mode is legacy');
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		$this->assertSame(1, $rTok['doc']['epoch']);
		$this->assertSame(self::SID, $rTok['doc']['server_id']);
	}

	public function testEnrolCompleteActivates(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, $rCtx] = $this->call('enrol_complete', ['instance_id' => 'inst-a', 'boot_id' => 'boot-1', 'agent_version' => '0.1.0'], 1, $rTok['keys']);
		$rOut = $this->reply($rRes, $rCtx, $rTok['keys']);
		$this->assertSame('active', $rOut['state']);
		$this->assertSame($this->rT0, $rOut['main_time_ms']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('active', $rNode['state']);
		$this->assertSame('inst-a', $rNode['instance_id']);
		$this->assertNull($rNode['enrol_deadline']);
		$this->assertSame(1, (int) $rNode['epoch']);
	}

	public function testEnrolCompleteAfterDeadlineIsRefused(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		ClusterClock::fix($this->rT0 + 1801000);
		[$rRes, , $rReq] = $this->call('enrol_complete', ['instance_id' => 'inst-a'], 1, $rTok['keys']);
		$this->denial($rRes, 409, 'ENROL_EXPIRED', $rReq);
		$this->assertSame('enrolling', NodeRegistry::byServer(self::SID)['state']);
	}

	public function testHeartbeatBeforeEnrolCompleteIsNotActive(): void {
		$rFirst = $this->enrol();
		$rTok = $this->openToken($rFirst['token_sealed'], $this->rEph[1]);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rTok['keys']);
		$this->assertSame('enrolling', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
	}

	public function testHelloAndHeartbeat(): void {
		$rKeys = $this->active();
		ClusterClock::fix($this->rT0 + 5000);
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-a', 'boot_id' => 'boot-2', 'agent_version' => '0.1.1'], 1, $rKeys);
		$rOut = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame('active', $rOut['state']);
		$this->assertSame(['min' => 1, 'max' => 1], $rOut['proto']);
		$this->assertSame('boot-2', NodeRegistry::byServer(self::SID)['boot_id']);

		[$rRes, $rCtx] = $this->call('heartbeat', ['telemetry' => ['cpu' => 3]], 1, $rKeys, ['ts' => $this->rT0 + 5250]);
		$this->assertSame(0, $this->reply($rRes, $rCtx, $rKeys)['pending']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame($this->rT0 + 5000, (int) $rNode['last_seen_at']);
		$this->assertSame(250, (int) $rNode['clock_offset_ms']);
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['status'], 'first authenticated heartbeat marks the server up');
	}

	public function testHelloFromAnotherInstanceQuarantines(): void {
		$rKeys = $this->active();
		[$rRes, $rCtx] = $this->call('hello', ['instance_id' => 'inst-CLONE'], 1, $rKeys);
		$this->assertSame('quarantined', $this->reply($rRes, $rCtx, $rKeys)['state']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame('quarantined', $rNode['state']);
		$this->assertSame('inst-a', $rNode['instance_id'], 'the enrolled instance is kept');
	}

	public function testReplayIsRefused(): void {
		$rKeys = $this->active();
		$r = $this->request('heartbeat', [], 1, $rKeys);
		$this->assertSame(200, ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain)['status']);
		$this->denial(ClusterApi::handle($this->rCrypto, $r['req'], $this->rSettings, $this->rMain), 401, 'REPLAY', $r['req']);
	}

	public function testBadMacBurnsNoNonce(): void {
		$rKeys = $this->active();
		$rNonce = random_bytes(16);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys, ['nonce' => $rNonce, 'body' => static fn($b) => $b . 'x']);
		$this->denial($rRes, 401, 'BAD_MAC', $rReq);
		// The genuine request with that nonce still goes through.
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys, ['nonce' => $rNonce]);
		$this->assertSame(200, $rRes['status']);
	}

	public function testRefreshNeedsTheNodeSignature(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		[$rRes, , $rReq] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys, [
			'headers' => static function ($h) {
				$h['X-XCVM-Node-Sig'] = str_repeat('00', 64);
				return $h;
			},
		]);
		$this->denial($rRes, 401, 'BAD_NODE_SIG', $rReq);
	}

	public function testClockSkewAndProto(): void {
		$rKeys = $this->active();
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys, ['ts' => $this->rT0 - 91000]);
		$this->denial($rRes, 401, 'CLOCK_SKEW', $rReq);
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys, ['headers' => static function ($h) {
			$h['X-XCVM-Proto'] = '9';
			return $h;
		}
		]);
		$rDoc = $this->denial($rRes, 426, 'PROTO');
		$this->assertSame([1, 1], [$rDoc['min'], $rDoc['max']]);
	}

	public function testTokenRefreshIsIdempotentAndRotates(): void {
		$rKeys = $this->active();
		$rEph = random_bytes(32);
		$rPayload = ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))];
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rFirst = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(2, $rFirst['epoch']);

		// The reply was lost: the retry with the same key gets the same token.
		[$rRes, $rCtx] = $this->call('token_refresh', $rPayload, 1, $rKeys);
		$rAgain = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame($rFirst['token_sealed'], $rAgain['token_sealed']);

		// A retry with a new key replaces the unused epoch 2 rather than adding 3.
		$rEph = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph))], 1, $rKeys);
		$rNew = $this->reply($rRes, $rCtx, $rKeys);
		$this->assertSame(2, $rNew['epoch']);
		$this->assertNotSame($rFirst['token_sealed'], $rNew['token_sealed']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(2, (int) $this->rDb->get_row()['n'], 'at most two epochs');

		// The node switches to epoch 2; the next refresh mints 3.
		$rTok2 = $this->openToken((string) base64_decode($rNew['token_sealed']), $rEph);
		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok2['keys']);
		$this->reply($rRes, $rCtx, $rTok2['keys']);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['epoch']);
		$rEph3 = random_bytes(32);
		[$rRes, $rCtx] = $this->call('token_refresh', ['eph_pub' => base64_encode(sodium_crypto_scalarmult_base($rEph3))], 2, $rTok2['keys']);
		$this->assertSame(3, $this->reply($rRes, $rCtx, $rTok2['keys'])['epoch']);
	}

	public function testExpiredEpochIsRefused(): void {
		$rKeys = $this->active();
		ClusterClock::fix($this->rT0 + (75 * 60 + 1) * 1000);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'TOKEN_EXPIRED', $rReq);
		TokenService::prune();
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'z is erased with the row');
	}

	public function testRevokedNodeIsRefusedEvenWithItsRowRestored(): void {
		$rKeys = $this->active();
		$this->rDb->query('SELECT * FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$rEpochRow = $this->rDb->get_row();
		$rNodeRow = NodeRegistry::byServer(self::SID);
		$this->assertTrue(NodeRegistry::revoke(self::SID, $this->rCrypto));
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->assertSame(2, $this->denial($rRes, 403, 'NODE_REVOKED', $rReq)['revoked_gen']);

		// Restore both rows from a "backup": the extension's floor still refuses.
		$this->rDb->query('UPDATE `cluster_nodes` SET `state` = ?, `gen` = ? WHERE `server_id` = 5', 'active', (int) $rNodeRow['gen']);
		$this->rDb->query(
			'INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `token_sealed`, `nbf`, `exp`, `refresh_at`, `used`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
			5,
			1,
			$rEpochRow['record'],
			$rEpochRow['token_sealed'],
			$rEpochRow['nbf'],
			$rEpochRow['exp'],
			$rEpochRow['refresh_at'],
			1,
			$rEpochRow['created_at']
		);
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 403, 'NODE_REVOKED', $rReq);
	}

	public function testUnknownNodeAndDisabledApi(): void {
		$rKeys = ClusterReference::sessionKeys(random_bytes(32));
		$this->rNodeSk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
		[$rRes, , $rReq] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 401, 'UNKNOWN_NODE', $rReq);

		$this->rSettings['cluster_api_enabled'] = 0;
		[$rRes] = $this->call('heartbeat', [], 1, $rKeys);
		$this->denial($rRes, 503, 'DISABLED');
	}

	public function testHealthAndChallengeArePanelSigned(): void {
		$rPub = $this->rCrypto->info()['panel_sign_pub'];
		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/health', 'headers' => []], ['cluster_api_enabled' => 0], $this->rMain);
		$this->assertSame(200, $rRes['status']);
		$this->assertTrue(PanelSig::verify($rPub, 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$this->assertSame(base64_encode($rPub), json_decode($rRes['body'], true)['panel_sign_pub']);

		$rRes = ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/challenge', 'query' => 'cn=' . $this->rUuid, 'headers' => []], $this->rSettings, $this->rMain);
		$this->assertSame(200, $rRes['status']);
		$this->assertTrue(PanelSig::verify($rPub, 'hlt', $rRes['body'], (string) Enc::b64urlDecode($rRes['headers']['X-XCVM-Panel-Sig'])));
		$rDoc = json_decode($rRes['body'], true);
		$this->assertSame($this->rUuid, $rDoc['cn']);
		$this->assertSame(32, strlen((string) base64_decode($rDoc['challenge'])));

		$this->denial(ClusterApi::handle($this->rCrypto, ['method' => 'GET', 'path' => '/cluster/v1/nope', 'headers' => []], $this->rSettings, $this->rMain), 404, 'UNKNOWN_OP');
		$this->denial(ClusterApi::handle($this->rCrypto, ['method' => 'POST', 'path' => '/cluster/v1/health', 'headers' => []], $this->rSettings, $this->rMain), 405, 'BAD_REQUEST');
	}

	public function testReEnrolmentRaisesTheGeneration(): void {
		$rOld = $this->active();
		$rSecond = $this->enrol();
		$this->assertSame(2, $rSecond['gen']);
		[$rRes] = $this->call('heartbeat', [], 1, $rOld);
		$this->assertSame(401, $rRes['status'], 'the old generation\'s keys no longer authenticate');
	}

	public function testNodeHealth(): void {
		$this->assertSame('unknown', NodeHealth::state(null, 0, 100000, 30));
		$this->assertSame('ok', NodeHealth::state(90000, 0, 100000, 30));
		$this->assertSame('suspect', NodeHealth::state(80000, 0, 100000, 30));
		$this->assertSame('offline', NodeHealth::state(60000, 0, 100000, 30));
		$this->assertSame('ok', NodeHealth::state(1000, 95000, 100000, 30), 'MAIN downtime does not count');
	}

	public function testPolicy(): void {
		$rMain = $this->rMain + ['domain_name' => 'panel.example.com', 'https_broadcast_port' => 443];
		$rMain['enable_https'] = 1;
		$this->assertSame(
			['https://panel.example.com:443/cluster/v1/', 'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current(['cluster_transport' => 'https_preferred'], $rMain)['main_urls']
		);
		$this->assertSame(['https://panel.example.com:443/cluster/v1/'], ClusterPolicy::current(['cluster_transport' => 'https_required'], $rMain)['main_urls']);
		$this->assertCount(2, ClusterPolicy::current(['cluster_transport' => 'auto'], $rMain, false)['main_urls']);
		$this->assertSame('http://10.0.0.1:31200/cluster/v1/', ClusterPolicy::current(['cluster_api_port' => 31200], ['server_ip' => '10.0.0.1'])['main_urls'][0]);
	}

	public function testSas(): void {
		$rSas = EnrolmentService::sas($this->rUuid, str_repeat("\x01", 32), str_repeat("\x02", 32));
		$this->assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){5}$/', $rSas);
		$this->assertNotSame($rSas, EnrolmentService::sas($this->rUuid, str_repeat("\x01", 32), str_repeat("\x03", 32)));
	}

	public function testInitRecordsThePanelKeysAndReadiness(): void {
		$rFirst = ClusterMeta::init($this->rCrypto);
		$rFp = bin2hex((string) $this->rCrypto->info()['panel_fp']);
		$this->assertSame($rFp, $rFirst['panel_fp']);
		$this->assertSame($rFp, ClusterMeta::get('panel_fp'));
		$this->assertSame(base64_encode((string) $this->rCrypto->info()['panel_sign_pub']), ClusterMeta::get('panel_sign_pub'));
		$this->assertSame($this->rT0, ClusterMeta::readyAtMs());

		ClusterClock::fix($this->rT0 + 60000);
		$this->assertFalse(ClusterMeta::init($this->rCrypto)['created'], 'idempotent');
		$this->assertSame($this->rT0 + 60000, ClusterMeta::readyAtMs(), 'a restart restarts the silence clock');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_meta` WHERE `name` = 'panel_fp'");
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
	}

	public function testRekeyAfterExpiryIssuesAFreshEpoch(): void {
		$this->expired();
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$rTok = $this->rekeyed($rRes, $rReq, $rEph);
		$this->assertSame(2, $rTok['doc']['epoch'], 'after the current epoch');
		$this->assertSame(self::SID, $rTok['doc']['server_id']);

		[$rRes, $rCtx] = $this->call('heartbeat', [], 2, $rTok['keys']);
		$this->reply($rRes, $rCtx, $rTok['keys']);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame(2, (int) $rNode['epoch']);
		$this->assertSame('boot-9', $rNode['boot_id']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
	}

	public function testRekeyReplacesAnEpochWhoseReplyWasLost(): void {
		$this->expired();
		$rLost = random_bytes(32);
		[$rRes] = $this->rekey($this->challenge(), $rLost);
		$this->assertSame(200, $rRes['status']);
		ClusterClock::fix($this->rT0 + 61000);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$this->assertSame(3, $this->rekeyed($rRes, $rReq, $rEph)['doc']['epoch']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_node_epochs` WHERE `server_id` = 5');
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'the unused epoch 2 is gone');
	}

	public function testRekeyChallengeIsSingleUseAndIssued(): void {
		$this->expired();
		[$rRes, $rReq] = $this->rekey(random_bytes(32), random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);

		$rChallenge = $this->challenge();
		ClusterClock::fix($this->rT0 + 61000);
		[$rRes] = $this->rekey($rChallenge, random_bytes(32));
		$this->assertSame(200, $rRes['status']);
		ClusterClock::fix($this->rT0 + 122000);
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);

		// A challenge lives 180 s.
		$rOld = $this->challenge();
		ClusterClock::fix($this->rT0 + 122000 + 181000);
		[$rRes, $rReq] = $this->rekey($rOld, random_bytes(32));
		$this->denial($rRes, 401, 'CHALLENGE', $rReq);
	}

	public function testRekeyIsLimitedToOncePerMinute(): void {
		$this->expired();
		[$rRes] = $this->rekey($this->challenge(), random_bytes(32));
		$this->assertSame(200, $rRes['status']);
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->assertGreaterThan(0, $this->denial($rRes, 429, 'RATE_LIMITED', $rReq)['retry_after_ms']);
	}

	public function testRekeyNeedsTheNodeSignatureAndChargesNothingWithout(): void {
		$this->expired();
		$rChallenge = $this->challenge();
		[$rRes, $rReq] = $this->rekey($rChallenge, random_bytes(32), [], ['headers' => static function ($h) {
			$h['X-XCVM-Node-Sig'] = str_repeat('00', 64);
			return $h;
		}
		]);
		$this->denial($rRes, 401, 'BAD_NODE_SIG', $rReq);
		// Neither the minute nor the challenge was spent.
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($rChallenge, $rEph);
		$this->rekeyed($rRes, $rReq, $rEph);

		// A MAC'd session header has no place in a re-key.
		[$rRes] = $this->rekey($this->challenge(), random_bytes(32), [], ['headers' => static function ($h) {
			$h['X-XCVM-Sig'] = str_repeat('00', 32);
			return $h;
		}
		]);
		$this->denial($rRes, 400, 'BAD_REQUEST');
	}

	public function testRekeyRefusedWithoutLicenceKeepsTheNode(): void {
		if (!$this->rCrypto instanceof FakeClusterCrypto) {
			$this->markTestSkipped('the licence cannot be withdrawn from the real extension mid-test');
		}
		$this->expired();
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 403, 'LICENCE_INVALID', $rReq);
		$this->assertSame('active', NodeRegistry::byServer(self::SID)['state']);

		// Re-licensed: the next minute's re-key goes through.
		$this->rCrypto->rRefuseIssue = null;
		ClusterClock::fix($this->rT0 + 61000);
		$rEph = random_bytes(32);
		[$rRes, $rReq] = $this->rekey($this->challenge(), $rEph);
		$this->rekeyed($rRes, $rReq, $rEph);
	}

	public function testRekeyFromAnotherInstanceQuarantines(): void {
		$this->expired();
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32), ['instance_id' => 'inst-CLONE']);
		$this->assertSame('quarantined', $this->denial($rRes, 409, 'NOT_ACTIVE', $rReq)['state']);
		$this->assertSame('quarantined', NodeRegistry::byServer(self::SID)['state']);

		// A quarantined node waits for the admin.
		ClusterClock::fix($this->rT0 + 61000);
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 409, 'NOT_ACTIVE', $rReq);
	}

	public function testRekeyOfARevokedNodeIsRefused(): void {
		$this->expired();
		$this->assertTrue(NodeRegistry::revoke(self::SID, $this->rCrypto));
		[$rRes, $rReq] = $this->rekey($this->challenge(), random_bytes(32));
		$this->denial($rRes, 403, 'NODE_REVOKED', $rReq);
	}
}
