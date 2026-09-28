<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;

/**
 * Where PHP finds the agent's files (AgentPaths), and each caller's answer
 * for a process where CONFIG_PATH is not defined yet: the install's config
 * directory, null, or MAIN_HOME's config/. The constants are defined once
 * per process, so each case runs in a child PHP.
 */
final class AgentPathsTest extends TestCase {
	private string $rDir = '';

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_agentpaths_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			unlink($rFile);
		}
		@rmdir($this->rDir);
	}

	public function testTheFilesAreTheAgentsNames(): void {
		$this->assertSame('cluster/agent.json', AgentPaths::STATE);
		$this->assertSame('cluster/agent.sock', AgentPaths::SOCKET);
		$this->assertSame('cluster/flows.json', NodeFlows::FILE);
		$this->assertSame('cluster/lease_state.json', NodeLease::FILE);
	}

	public function testWithoutAnyConstant(): void {
		$this->assertSame([
			'configDir' => '/home/xc_vm/config/',
			'file' => '/home/xc_vm/config/cluster/agent.json',
			'fileOrNull' => null,
			'fileEarly' => null,
		], $this->child(''));
	}

	public function testWithMainHomeOnlyTheEarlyReadUsesIt(): void {
		$this->assertSame([
			'configDir' => '/home/xc_vm/config/',
			'file' => '/home/xc_vm/config/cluster/agent.json',
			'fileOrNull' => null,
			'fileEarly' => '/opt/panel/config/cluster/agent.json',
		], $this->child("define('MAIN_HOME', '/opt/panel/');"));
	}

	public function testConfigPathWinsEverywhere(): void {
		$rWant = ['configDir' => '/etc/xc/', 'file' => '/etc/xc/cluster/agent.json', 'fileOrNull' => '/etc/xc/cluster/agent.json', 'fileEarly' => '/etc/xc/cluster/agent.json'];
		$this->assertSame($rWant, $this->child("define('CONFIG_PATH', '/etc/xc/');"));
		$this->assertSame($rWant, $this->child("define('MAIN_HOME', '/opt/panel/'); define('CONFIG_PATH', '/etc/xc/');"));
	}

	public function testTheStateReadsAsAnArrayOrNothing(): void {
		$this->assertSame([], AgentPaths::readState($this->rDir . 'missing.json'));
		foreach (['', 'not json', '"a string"', '42', 'null'] as $rBody) {
			file_put_contents($this->rDir . 'agent.json', $rBody);
			$this->assertSame([], AgentPaths::readState($this->rDir . 'agent.json'), var_export($rBody, true));
		}
		file_put_contents($this->rDir . 'agent.json', '{"node_uuid":"n","policy_ver":3}');
		$this->assertSame(['node_uuid' => 'n', 'policy_ver' => 3], AgentPaths::readState($this->rDir . 'agent.json'));
	}

	/** @return array<string, string|null> */
	private function child(string $rDefines): array {
		$rCode = $rDefines . ' require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';'
			. ' use XcVm\Core\Cluster\AgentPaths;'
			. ' echo json_encode(["configDir" => AgentPaths::configDir(), "file" => AgentPaths::file(AgentPaths::STATE),'
			. ' "fileOrNull" => AgentPaths::fileOrNull(AgentPaths::STATE), "fileEarly" => AgentPaths::fileEarly(AgentPaths::STATE)]);';
		$rOut = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($rCode) . ' 2>&1');
		$rData = json_decode($rOut, true);
		$this->assertIsArray($rData, $rOut);
		return $rData;
	}
}
