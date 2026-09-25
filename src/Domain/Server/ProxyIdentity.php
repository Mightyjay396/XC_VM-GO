<?php

namespace XcVm\Domain\Server;

/**
 * Proxy Identity
 *
 * Which proxy server a proxy_api.php request comes from. The endpoint used to
 * trust the posted server_id, so any proxy could report for, and collect the
 * root-run signals of, any other server. The identity is now the proxy whose
 * server_ip or private_ip is the request's source address; a posted server_id
 * that names another server is refused.
 *
 * @package XC_VM_Domain_Server
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class ProxyIdentity {
	/**
	 * @param array<string, array<string, mixed>> $rProxiesByIP BlocklistService::getProxyIPs(): IP => proxy server row.
	 * @param mixed $rPostedID The posted server_id, if any.
	 * @return int|null The proxy's server id, or null when the source is not a
	 *                  proxy or claims another server.
	 */
	public static function resolve(array $rProxiesByIP, string $rRemoteAddr, mixed $rPostedID): ?int {
		$rServer = $rProxiesByIP[$rRemoteAddr] ?? null;
		if (!is_array($rServer) || ($rServer['server_type'] ?? null) != 1 || empty($rServer['id'])) {
			return null;
		}
		$rID = intval($rServer['id']);
		if ($rPostedID !== null && $rPostedID !== '' && intval($rPostedID) !== $rID) {
			return null;
		}
		return $rID;
	}

	/**
	 * The posted address list, reduced to valid IPs — kept as telemetry only.
	 * It no longer feeds servers.whitelist_ips, which grants allowed_ips (the
	 * LB /api source allowlist) and is maintained by the admin.
	 *
	 * @return list<string>
	 */
	public static function seenAddresses(mixed $rPosted): array {
		if (!is_array($rPosted)) {
			return [];
		}
		$rOut = [];
		foreach ($rPosted as $rIP) {
			if (is_string($rIP) && filter_var($rIP, FILTER_VALIDATE_IP)) {
				$rOut[] = $rIP;
			}
		}
		return array_values(array_unique($rOut));
	}
}
