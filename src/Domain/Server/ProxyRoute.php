<?php

namespace XcVm\Domain\Server;

/**
 * The URL segment that routes a viewer through proxy P to its parent: the
 * proxy's nginx maps `/<segment>/…` to that parent (servers/<parent>.conf,
 * written by its install) and strips it. It picks the parent and
 * authenticates nothing: the parent still checks the viewer's token, which
 * per-node viewer keys bind to the parent (ViewerKey).
 *
 * A proxy installed by a panel with ProxyKey (`proxy_key_gen` > 0, a
 * replicated server field, so a node that builds a proxied playlist knows it)
 * has an HMAC-SHA256 segment (current()); one from before keeps the md5
 * segment its nginx holds until its next install.
 */
final class ProxyRoute {
	/**
	 * Proxy $rProxyID's segment to parent $rParentID.
	 *
	 * @param array<int, array<string, mixed>>|null $rServers ServerRepository::getAll(), or null to read it
	 */
	public static function segment(int $rProxyID, int $rParentID, ?array $rServers = null): string {
		$rServers ??= ServerRepository::getAll();
		return (int) ($rServers[$rProxyID]['proxy_key_gen'] ?? 0) > 0 ? self::current($rProxyID, $rParentID) : self::legacy($rProxyID, $rParentID);
	}

	/** The segment an install writes into the proxy's nginx (ProxyInstallFlow), and every server routes by since. */
	public static function current(int $rProxyID, int $rParentID): string {
		return substr(hash_hmac('sha256', 'xcvm proxy route v2|' . $rProxyID . '|' . $rParentID, (string) OPENSSL_EXTRA), 0, 32);
	}

	/** The segment of a proxy installed before ProxyKey, as its nginx holds it. */
	private static function legacy(int $rProxyID, int $rParentID): string {
		// A path, not a signature (see the class): the proxy's existing nginx expects it.
		// nosemgrep: php.lang.security.weak-crypto.weak-crypto
		return md5($rProxyID . '_' . $rParentID . '_' . OPENSSL_EXTRA);
	}
}
