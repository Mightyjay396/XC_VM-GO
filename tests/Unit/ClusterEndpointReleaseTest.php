<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterEndpoint;

/**
 * What MAIN records of the URL each node uses (plan §3, "Endpoint changes":
 * the old port stays "until every node uses the new URL, or 7 days"): the
 * policy version its hello and heartbeat say it dials, and the MAIN port
 * nginx took them on.
 */
final class ClusterEndpointReleaseTest extends TestCase {
	/**
	 * What a hello or heartbeat records of the node: the policy version it
	 * says it uses (0 when it says nothing or something else) and the port
	 * nginx took the request on, each only when it changed, the port only
	 * once migration 046 added its column.
	 */
	public function testTheNodeSaysWhichPolicyItUses(): void {
		$rNode = ['server_id' => 2, 'policy_ver' => 0, 'main_port' => null];
		$this->assertSame(['policy_ver' => 4, 'main_port' => 8080], ClusterEndpoint::nodeUses($rNode, ['policy_ver' => 4], 8080));
		$this->assertSame([], ClusterEndpoint::nodeUses(['policy_ver' => 4, 'main_port' => 8080] + $rNode, ['policy_ver' => 4], 8080), 'nothing changed: nothing to write');
		$this->assertSame(['policy_ver' => 0], ClusterEndpoint::nodeUses(['policy_ver' => 4, 'main_port' => 8080] + $rNode, [], 8080), 'an agent that stops saying it (a downgrade) is unknown again');
		foreach (['4', 4.0, -1, 4294967296, true, null, [4]] as $rBad) {
			$this->assertSame([], ClusterEndpoint::nodeUses($rNode, ['policy_ver' => $rBad], 0), var_export($rBad, true));
		}
		$this->assertSame([], ClusterEndpoint::nodeUses($rNode, [], 0), 'no port from nginx: not observed');
		$this->assertSame([], ClusterEndpoint::nodeUses($rNode, [], 65536));
		$this->assertSame([], ClusterEndpoint::nodeUses(['server_id' => 2, 'policy_ver' => 0], [], 8080), 'before migration 046: no main_port column');
	}

	/** Migration 046 adds the port a node last reached MAIN on; database.sql has it for fresh installs. */
	public function testTheSchema(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';
		$rUp = (string) file_get_contents($rSrc . 'migrations/database/up/046_add_cluster_node_main_port.sql');
		$this->assertStringContainsString("ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `main_port` smallint(5) unsigned DEFAULT NULL AFTER `policy_ver`;", $rUp);
		$this->assertStringContainsString('ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `main_port`;', (string) file_get_contents($rSrc . 'migrations/database/down/046_add_cluster_node_main_port.sql'));
		$this->assertStringContainsString("  `policy_ver` int(10) unsigned NOT NULL DEFAULT '0',\n  `main_port` smallint(5) unsigned DEFAULT NULL,\n", (string) file_get_contents($rSrc . 'bin/install/database.sql'));
	}
}
