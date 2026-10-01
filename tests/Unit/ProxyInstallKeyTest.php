<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ProxyInstallFlow;
use XcVm\Domain\Server\ProxyKey;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A proxy's install writes it the key of a new generation (ProxyKey, D8), and
 * MAIN hears it unsigned again until it signs.
 */
final class ProxyInstallKeyTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_proxyinst_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		ProxyKey::useFile($this->rDir . 'proxy_secret');
	}

	protected function tearDown(): void {
		ProxyKey::useFile(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testTheInstallWritesTheKeyOfANewGeneration(): void {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::serversTable());
		$rDb->query("INSERT INTO `servers` (`id`, `server_type`, `server_name`, `proxy_key_gen`, `proxy_signed`) VALUES (7, 1, 'proxy', 2, 1)");
		$rSent = [];
		$rSend = static function ($rConn, string $rLocal, string $rRemote) use (&$rSent): bool {
			$rSent[$rRemote] = [file_get_contents($rLocal), fileperms($rLocal) & 0777];
			return true;
		};
		$rRun = [];
		$rSSH = static function ($rConn, string $rCommand) use (&$rRun): array {
			$rRun[] = $rCommand;
			return ['output' => ''];
		};

		$this->assertTrue(ProxyInstallFlow::provisionKey(null, $rSend, $rSSH, 7, $rDb));

		$rDb->query('SELECT `proxy_key_gen`, `proxy_signed` FROM `servers` WHERE `id` = 7');
		$this->assertSame(['3', '0'], array_map('strval', array_values($rDb->get_row())), 'a new generation, heard unsigned until it signs');
		$this->assertSame([MAIN_HOME . 'config/proxy.key' => [bin2hex((string) ProxyKey::forServer(7, 3)) . "\n", 0600]], $rSent);
		$this->assertStringContainsString('chmod 0600 ' . MAIN_HOME . 'config/proxy.key', $rRun[0]);
		$this->assertSame([], glob(sys_get_temp_dir() . '/pk*') === false ? [] : array_filter(glob(sys_get_temp_dir() . '/pk*'), static fn(string $f): bool => str_contains((string) @file_get_contents($f), bin2hex((string) ProxyKey::forServer(7, 3)))), 'no copy of the key is left behind');
	}
}
