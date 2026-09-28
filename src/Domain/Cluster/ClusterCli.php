<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;

/**
 * What the cluster CLI commands (cluster:*, server:enrol, the LB install
 * flow) share: the crypto, or the line saying why there is none. All of them
 * are MAIN's alone, as this class is: the LB build strips Domain\Cluster with
 * them. Which servers rows are load balancers is ClusterAdmin::isLoadBalancer(),
 * which the admin page asks too.
 */
final class ClusterCli {
	/** The unavailable line of the commands that stop there. */
	public const UNAVAILABLE_EXIT = "Cluster API unavailable: %s. Exiting\n";

	/** The unavailable line of the key and init commands. */
	public const UNAVAILABLE = "Cluster API unavailable: %s\n";

	/**
	 * The panel's cluster crypto, or null once why it is unavailable is
	 * printed (the extension's message, through $rFormat).
	 *
	 * @param string $rFormat A sprintf() format with one %s for the reason.
	 */
	public static function crypto(string $rFormat = self::UNAVAILABLE_EXIT): ?ClusterCrypto {
		try {
			return ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo sprintf($rFormat, $rE->getMessage());
			return null;
		}
	}
}
