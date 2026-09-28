<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;

/**
 * A node in mode 1 with the lease fence on (10 minutes of drain), the agent's
 * `lease_state.json` and `agent.json` in a temp directory, and a fake
 * `xcvm_core` for NodeLease's compiled verdict: `cluster_lease_state` answers
 * $rCompiledState, `cluster_lease_store` records its arguments and answers
 * $rStoreAnswer. Used by the lease-verdict tests.
 */
trait NodeLeaseFixture {
	private string $rDir;

	/** @var array<string, mixed>|false What the fake extension's cluster_lease_state answers. */
	private array|false $rCompiledState = false;

	/** @var array<string, mixed>|false What its cluster_lease_store answers. */
	private array|false $rStoreAnswer = false;

	/** @var list<array{0: string, 1: list<mixed>}> Every call the fake extension took. */
	private array $rCalls = [];

	private function leaseSetUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-lease-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::CONFIG, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]);
		NodeLease::usePath($this->rDir . '/lease_state.json');
		NodeLease::useExtension(false);
	}

	private function leaseTearDown(): void {
		NodeFlows::usePath(null);
		NodeLease::usePath(null);
		NodeLease::useExtension(null);
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The fake extension, with the agent's state file beside it. */
	private function withExtension(): void {
		NodeLease::useExtension(function (string $rMethod, mixed ...$rArgs): mixed {
			$this->rCalls[] = [$rMethod, $rArgs];
			return match ($rMethod) {
				'cluster_lease_state' => $this->rCompiledState,
				'cluster_lease_store' => $this->rStoreAnswer,
				default => false,
			};
		}, $this->rDir . '/agent.json');
	}

	/** @return list<string> the methods the fake extension was asked, in order */
	private function asked(): array {
		return array_map(static fn(array $rCall): string => $rCall[0], $this->rCalls);
	}

	/**
	 * The agent's `lease_state.json`: a lease ending $rExpOffset s after the
	 * agent's anchor, the anchor $rAnchorMs (now by default), written $rAgeMs ago.
	 */
	private function agentFile(int $rExpOffset, int $rAgeMs = 0, ?int $rAnchorMs = null): void {
		$rNowMs = (int) round(microtime(true) * 1000);
		$rAnchor = $rAnchorMs ?? $rNowMs;
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode([
			'exp' => intdiv($rAnchor, 1000) + $rExpOffset,
			'gen' => 4,
			'anchor_ms' => $rAnchor,
			'wrote_at_ms' => $rNowMs - $rAgeMs,
		]));
		NodeLease::usePath($this->rDir . '/lease_state.json');
	}

	/** The agent's `agent.json` holding a lease issued at $rIat (Go's base64 of []byte). */
	private function agentLease(int $rIat, string $rPayload = '{"typ":"xcvm-lease"}', string $rSig = 'sig'): void {
		file_put_contents($this->rDir . '/agent.json', (string) json_encode([
			'node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'lease' => ['payload' => base64_encode($rPayload), 'sig' => base64_encode($rSig), 'iat' => $rIat, 'exp' => $rIat + 3600, 'gen' => 4, 'server_id' => 7],
		]));
	}

	/** @return array<string, mixed> the extension's verdict as `cluster_lease_state()` shapes it */
	private static function compiled(string $rState, int $rExp, int $rMainTime, int $rIat = 0, string $rWhy = ''): array {
		return ['state' => $rState, 'why' => $rWhy, 'node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'iat' => $rIat, 'exp' => $rExp, 'gen' => 4, 'server_id' => 7, 'main_time' => $rMainTime, 'main_ms' => $rMainTime * 1000];
	}
}
