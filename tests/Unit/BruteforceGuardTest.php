<?php

use XcVm\Core\Auth\BruteforceGuard;
use PHPUnit\Framework\TestCase;

/**
 * BruteforceGuard::truncateAttempts — drops attempts older than the flood
 * window. The rest of the guard is IO-bound (DB inserts, signals, flood files);
 * this pure helper is the reusable core of every flood check, in both the
 * indexed (list) and associative shapes.
 */
final class BruteforceGuardTest extends TestCase {

	public function testKeepsRecentAndDropsExpiredInListMode(): void {
		$now = time();
		$attempts = [$now - 5, $now - 7200];

		$kept = BruteforceGuard::truncateAttempts($attempts, 3600, true);

		$this->assertSame([$now - 5], array_values($kept), 'reindexed list of survivors');
	}

	public function testKeepsRecentAndDropsExpiredInAssociativeMode(): void {
		$now = time();
		$attempts = ['alice' => $now - 5, 'bob' => $now - 7200];

		$kept = BruteforceGuard::truncateAttempts($attempts, 3600, false);

		$this->assertSame(['alice' => $now - 5], $kept, 'keys preserved for survivors');
		$this->assertArrayNotHasKey('bob', $kept);
	}

	public function testEverythingWithinWindowIsKept(): void {
		$now = time();
		$attempts = [$now, $now - 1, $now - 10];
		$this->assertCount(3, BruteforceGuard::truncateAttempts($attempts, 3600, true));
	}

	public function testEmptyInputYieldsEmpty(): void {
		$this->assertSame([], BruteforceGuard::truncateAttempts([], 60, true));
		$this->assertSame([], BruteforceGuard::truncateAttempts([], 60, false));
	}
}
