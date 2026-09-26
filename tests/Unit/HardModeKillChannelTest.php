<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\ClusterReference;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Kills keep reaching nodes when MAIN has no licence (plan section 4,
 * "Licence revocation stops token generation"; Phase 4 acceptance: "An
 * unlicensed MAIN still delivers kills").
 *
 * Kills, drops and closes are restrictive commands: the extension signs them
 * without a licence, and nothing granting is signed. With the default
 * `lb_revocation_mode=graceful` the node's session still works, so they go
 * through the `commands` long-poll as always. With `hard`, the extension
 * refuses the session itself, so the node's next request (a heartbeat,
 * within 2 s) gets a panel-signed `LICENCE_INVALID` denial that carries its
 * pending restrictive commands, each under its own `cmd` signature.
 *
 * The test agent does what the Go agent does: opens the sealed token,
 * derives the session keys, BOXes and MACs its requests, and checks every
 * command it is handed against the pinned panel key.
 */
final class HardModeKillChannelTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rUuid = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rNodeSk = '';

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
	}

	protected function tearDown(): void {
		ClusterRoute::useCrypto(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	private function mode(string $rMode): void {
		$this->rSettings['lb_revocation_mode'] = $rMode;
		SettingsManager::set($this->rSettings);
	}

	/** Enrol, complete, and switch COMMANDS on, as the admin does; @return array<string, string> the session keys */
	private function activeNode(): array {
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
		NodeRegistry::update(self::SID, ['flows' => NodeRegistry::FLOW_COMMANDS]);
		return $rKeys;
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
			'X-XCVM-Node-Sig' => bin2hex(\XcVm\Core\Cluster\Crypto\NodeSig::sign($this->rNodeSk, 'request', $rCtx . hash('sha256', $rBody, true))),
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
	 * The commands as the agent accepts them: each panel-signed with tag
	 * `cmd` under the pinned key, for this node and generation, not expired.
	 *
	 * @param list<array{doc: string, sig: string, seq: int}> $rCommands
	 * @return list<array{0: string, 1: array<string, mixed>}> [type, args] in seq order
	 */
	private function accepted(array $rCommands): array {
		$rOut = [];
		$rSeq = 0;
		foreach ($rCommands as $rOne) {
			$this->assertTrue(PanelSig::verify($this->rCrypto->info()['panel_sign_pub'], 'cmd', $rOne['doc'], (string) Enc::b64urlDecode($rOne['sig'])), 'panel-signed, tag cmd');
			$rDoc = json_decode($rOne['doc'], true);
			$this->assertSame([$this->rUuid, 1, $rOne['seq']], [$rDoc['node_uuid'], $rDoc['gen'], $rDoc['seq']]);
			$this->assertGreaterThan($rSeq, $rDoc['seq'], 'in seq order');
			$this->assertGreaterThan(ClusterClock::now(), $rDoc['exp']);
			$rSeq = $rDoc['seq'];
			$rOut[] = [$rDoc['type'], $rDoc['args']];
		}
		return $rOut;
	}

	/** @return list<string> the queued commands' states, in seq order */
	private function states(): array {
		$this->rDb->query('SELECT `state` FROM `cluster_commands` WHERE `server_id` = ? ORDER BY `seq`', self::SID);
		return array_column($this->rDb->get_rows(), 'state');
	}

	public function testAnUnlicensedMainStillSignsAndDeliversKills(): void {
		$this->mode('graceful');
		$rKeys = $this->activeNode();
		$this->rCrypto->rLicensed = false;

		$this->assertSame([true, true], ClusterRoute::kill(self::SID, 4242, false), 'a kill is restrictive: signed without a licence');
		$this->assertSame([true, true], ClusterRoute::drop(self::SID, 'viewer1'));
		$this->assertSame([true, false], ClusterRoute::send(self::SID, ['action' => 'free_temp']), 'an RPC grants: not signed without a licence');

		// Graceful mode: the session still works, and the long-poll carries them.
		[$rRes, $rCtx] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], $rKeys);
		$this->assertSame([['conn.kill_worker', ['pid' => 4242, 'rtmp' => false]], ['conn.drop', ['uuid' => 'viewer1']]], $this->accepted($this->reply($rRes, $rCtx, $rKeys)['commands']));
	}

	public function testInHardModeTheDenialCarriesThePendingKills(): void {
		$this->mode('hard');
		$rKeys = $this->activeNode();
		[$rRes] = $this->call('heartbeat', [], $rKeys);
		$this->assertSame(200, $rRes['status'], 'licensed: the session works in hard mode too');
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'free_temp']), 'queued while still licensed');

		// The licence is revoked. Only restrictive commands are signed now.
		$this->rCrypto->rLicensed = false;
		$this->assertSame([true, true], ClusterRoute::kill(self::SID, 4242, false));
		$this->assertSame([true, true], ClusterRoute::drop(self::SID, 'viewer1'));
		$this->assertSame([true, false], ClusterRoute::send(self::SID, ['action' => 'free_temp']));

		// The node's next heartbeat: no session, but a signed denial with the kills.
		[$rRes, , $rReq] = $this->call('heartbeat', [], $rKeys);
		$rDoc = $this->denial($rRes, $rReq, 403, 'LICENCE_INVALID');
		$this->assertSame([['conn.kill_worker', ['pid' => 4242, 'rtmp' => false]], ['conn.drop', ['uuid' => 'viewer1']]], $this->accepted($rDoc['commands'] ?? []), 'the kills, and not the RPC queued before');

		// So does its long-poll, and nothing changed before authentication.
		[$rRes, , $rReq] = $this->call('commands', ['after_seq' => 0, 'wait_ms' => 0], $rKeys);
		$this->assertCount(2, $this->accepted($this->denial($rRes, $rReq, 403, 'LICENCE_INVALID')['commands'] ?? []));
		$this->assertSame(['queued', 'queued', 'queued'], $this->states(), 'an unauthenticated request marks nothing delivered');
	}

	public function testOnlyANodeThatTakesCommandsIsHandedAny(): void {
		$this->mode('hard');
		$rKeys = $this->activeNode();
		$this->rCrypto->rLicensed = false;
		ClusterRoute::kill(self::SID, 4242, false);
		NodeRegistry::update(self::SID, ['flows' => 0]);
		[$rRes, , $rReq] = $this->call('heartbeat', [], $rKeys);
		$this->assertArrayNotHasKey('commands', $this->denial($rRes, $rReq, 403, 'LICENCE_INVALID'), 'its COMMANDS flow is off');
	}
}
