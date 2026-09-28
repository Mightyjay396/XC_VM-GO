<?php

namespace XcVm\Domain\Cluster;

/**
 * cluster:lockdown refused: servers that would lose MAIN's database or Redis
 * (ClusterLockdown::blockers()).
 */
final class LockdownRefused extends \RuntimeException {
	/** @param array{nodes: list<int>, proxies: list<int>} $rBlockers */
	public function __construct(public readonly array $rBlockers) {
		parent::__construct('lockdown refused');
	}
}
