<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;

/**
 * The transport policy nodes follow: which MAIN URLs to use, in order, and
 * whether HTTPS is tried, preferred or required (plan, "Endpoints and HTTPS").
 *
 * Plain HTTP on the MAIN's private (or public) IP is always listed, except
 * under `https_required`. HTTPS is listed first when `https_preferred`, or
 * when `auto` and MAIN's own certificate verifies.
 */
final class ClusterPolicy {
	/**
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return array{policy_ver: int, transport: string, main_urls: list<string>}
	 */
	public static function current(array $rSettings, array $rMain, ?bool $rHttpsOk = null): array {
		$rTransport = (string) ($rSettings['cluster_transport'] ?? 'auto');
		$rHttpPort = intval($rSettings['cluster_api_port'] ?? 0) ?: intval($rMain['http_broadcast_port'] ?? 80);
		$rHosts = [];
		foreach (['private_ip', 'server_ip'] as $rKey) {
			if (!empty($rMain[$rKey])) {
				$rHosts[] = (string) $rMain[$rKey];
			}
		}
		$rName = (string) ($rSettings['cluster_main_host'] ?? '');
		if ($rName !== '') {
			$rHosts[] = $rName;
		}
		$rHosts = array_values(array_unique($rHosts));

		$rHttp = array_map(static fn($rHost) => 'http://' . self::hostPort($rHost, $rHttpPort) . '/cluster/v1/', $rHosts);
		$rHttps = [];
		$rHttpsWanted = $rTransport === 'https_preferred' || $rTransport === 'https_required'
			|| ($rTransport === 'auto' && ($rHttpsOk ?? false));
		if ($rHttpsWanted && in_array((int) ($rMain['enable_https'] ?? 0), [1, 2], true)) {
			$rTlsName = $rName !== '' ? $rName : null;
			foreach (explode(',', (string) ($rMain['domain_name'] ?? '')) as $rDomain) {
				$rDomain = strtolower(trim($rDomain));
				if ($rTlsName === null && $rDomain !== '' && ClusterSettings::validHostname($rDomain)) {
					$rTlsName = $rDomain;
				}
			}
			if ($rTlsName !== null) {
				$rHttps[] = 'https://' . self::hostPort($rTlsName, intval($rMain['https_broadcast_port'] ?? 443)) . '/cluster/v1/';
			}
		}
		// MAIN's old HTTP ports after a port change: still served for the
		// cluster API (ClusterEndpoint), listed last so nodes that missed the
		// change find MAIN and move on.
		$rOld = [];
		if ((int) ($rSettings['cluster_api_port'] ?? 0) === 0) {
			foreach (array_keys(ClusterEndpoint::legacyPorts($rSettings)) as $rPort) {
				if ($rPort !== $rHttpPort) {
					foreach ($rHosts as $rHost) {
						$rOld[] = 'http://' . self::hostPort($rHost, $rPort) . '/cluster/v1/';
					}
				}
			}
		}
		$rUrls = $rTransport === 'https_required' ? $rHttps : array_merge($rHttps, $rHttp, $rOld);
		return [
			'policy_ver' => intval($rSettings['cluster_policy_ver'] ?? 1),
			'transport' => $rTransport,
			'main_urls' => $rUrls,
		];
	}

	private static function hostPort(string $rHost, int $rPort): string {
		return (str_contains($rHost, ':') ? '[' . $rHost . ']' : $rHost) . ':' . $rPort;
	}
}
