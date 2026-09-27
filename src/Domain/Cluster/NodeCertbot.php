<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Certificate renewals of the nodes in mode 2 (plan, section 10). A node's
 * cron:certbot queues its own renewal as a `signals` row its root runs
 * (NodeActions). A node in mode 2 reaches no database of MAIN's and its
 * root reads no row, so MAIN's daily cron:certbot sends the renewal
 * instead, as the signed `node.root certbot_generate` root runs
 * (ClusterRoute::root), on the rule the node applied to itself: HTTPS on,
 * and the certificate it reported (`servers.certbot_ssl`, its `node.state`)
 * due within DUE, for the names in its `domain_name`, not its addresses.
 * Nodes in mode 0 and 1 keep queueing their own. A node root takes no
 * command from (no COMMANDS flow or no root pin) gets nothing: a row would
 * never be read there.
 */
final class NodeCertbot {
	use DatabaseAware;

	/** Seconds before its expiry a certificate is renewed (cron:certbot's rule). */
	public const DUE = 604800;

	/**
	 * Send the renewals due. MAIN only.
	 *
	 * @return list<int> the servers a renewal was queued for
	 */
	public static function renewDue(?int $rNow = null): array {
		$rNow ??= ClusterClock::now();
		self::db()->query("SELECT `servers`.`id`, `servers`.`enable_https`, `servers`.`domain_name`, `servers`.`certbot_ssl` FROM `servers` INNER JOIN `cluster_nodes` ON `cluster_nodes`.`server_id` = `servers`.`id` WHERE `cluster_nodes`.`mode` = 2 AND `cluster_nodes`.`state` = 'active' ORDER BY `servers`.`id` ASC;");
		$rSent = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rCertificate = json_decode((string) ($rRow['certbot_ssl'] ?? ''), true);
			if (empty($rRow['enable_https']) || !is_array($rCertificate) || (int) ($rCertificate['expiration'] ?? 0) - $rNow >= self::DUE) {
				continue;
			}
			$rDomains = [];
			foreach (explode(',', (string) ($rRow['domain_name'] ?? '')) as $rDomain) {
				$rDomain = trim($rDomain);
				if ($rDomain !== '' && !filter_var($rDomain, FILTER_VALIDATE_IP)) {
					$rDomains[] = $rDomain;
				}
			}
			if ($rDomains === []) {
				continue;
			}
			[$rRouted, $rQueued] = ClusterRoute::root((int) $rRow['id'], ['action' => 'certbot_generate', 'domain' => $rDomains]);
			if ($rRouted && $rQueued) {
				$rSent[] = (int) $rRow['id'];
			}
		}
		return $rSent;
	}
}
