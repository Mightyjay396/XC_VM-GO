<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;

/**
 * Which servers rows are load balancers: what the Cluster Nodes page lists
 * for a code, and what cluster:enrol-code, cluster:reenrol and server:enrol
 * accept.
 */
final class ClusterAdminLoadBalancerTest extends TestCase {
	public function testALoadBalancerIsNeitherMainNorAProxy(): void {
		$this->assertTrue(ClusterAdmin::isLoadBalancer(['is_main' => 0, 'server_type' => 0]));
		$this->assertTrue(ClusterAdmin::isLoadBalancer(['is_main' => '0', 'server_type' => '0']));
		$this->assertTrue(ClusterAdmin::isLoadBalancer([]), 'no type: a server, as the servers table defaults it');
		$this->assertFalse(ClusterAdmin::isLoadBalancer(null));
		$this->assertFalse(ClusterAdmin::isLoadBalancer(['is_main' => 1, 'server_type' => 0]));
		$this->assertFalse(ClusterAdmin::isLoadBalancer(['is_main' => 0, 'server_type' => 1]));
		$this->assertFalse(ClusterAdmin::isLoadBalancer(['is_main' => 0, 'server_type' => '1']));
	}

	public function testThePageListsOnlyLoadBalancers(): void {
		$rServers = [
			1 => ['is_main' => 1, 'server_type' => 0, 'server_name' => 'Main'],
			2 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'LB 1'],
			3 => ['is_main' => 0, 'server_type' => 1, 'server_name' => 'Proxy'],
			4 => ['is_main' => 0, 'server_type' => 0],
		];
		$this->assertSame([2 => 'LB 1', 4 => '#4'], ClusterAdmin::loadBalancers($rServers));
	}
}
