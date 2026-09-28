<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaRecords;

/**
 * A 32-byte key from the agent's state (ReplicaRecords::key32), as
 * identity() and cluster:pin-root read `panel_sign_pub`: standard base64,
 * strict, exactly 32 bytes.
 */
final class ReplicaRecordsKeyTest extends TestCase {
	public function testA32ByteKeyInStandardBase64IsRead(): void {
		$rKey = random_bytes(32);
		$this->assertSame($rKey, ReplicaRecords::key32(['panel_sign_pub' => base64_encode($rKey)], 'panel_sign_pub'));
	}

	public function testAnythingElseIsNull(): void {
		$rKey = base64_encode(str_repeat("\xfb", 32));
		foreach ([
			[], ['panel_sign_pub' => null], ['panel_sign_pub' => 42], ['panel_sign_pub' => [$rKey]], ['panel_sign_pub' => ''],
			['panel_sign_pub' => base64_encode(random_bytes(31))], ['panel_sign_pub' => base64_encode(random_bytes(33))],
			['panel_sign_pub' => strtr($rKey, '+/', '-_')], ['panel_sign_pub' => $rKey . '!'], ['other' => $rKey],
		] as $rState) {
			$this->assertNull(ReplicaRecords::key32($rState, 'panel_sign_pub'), var_export($rState, true));
		}
	}
}
