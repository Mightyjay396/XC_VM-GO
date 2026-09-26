<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;

/**
 * The transport policy nodes follow: which MAIN URLs to use, in order, and
 * whether HTTPS is tried, preferred or required (plan, "Endpoints and HTTPS").
 *
 * Plain HTTP on the MAIN's private (or public) IP is always listed, except
 * under `https_required`. HTTPS is listed first when `https_preferred`, or
 * when `auto` and MAIN's own certificate verifies. The old ports and URLs
 * kept for 7 days after an endpoint change (ClusterEndpoint) come last, by
 * the same rules: a kept https:// URL only while HTTPS is listed, a kept
 * http:// one never under `https_required`.
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
		$rHttpsListed = $rHttpsWanted && in_array((int) ($rMain['enable_https'] ?? 0), [1, 2], true);
		if ($rHttpsListed) {
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
		// MAIN's old cluster API ports after a port change (the broadcast port
		// or cluster_api_port): still served for the cluster API
		// (ClusterEndpoint, ClusterNginxConfig), listed last so nodes that
		// missed the change find MAIN and move on.
		$rOld = [];
		foreach (array_keys(ClusterEndpoint::legacyPorts($rSettings)) as $rPort) {
			if ($rPort !== $rHttpPort) {
				foreach ($rHosts as $rHost) {
					$rOld[] = 'http://' . self::hostPort($rHost, $rPort) . '/cluster/v1/';
				}
			}
		}
		// MAIN's old URLs after a change of its address or HTTPS port
		// (ClusterEndpoint), the latest change first, listed while MAIN serves
		// their port with their scheme: plain HTTP on a port the API answers
		// over HTTP, HTTPS on any other (MAIN's HTTPS ports, or an old one
		// ClusterNginxConfig serves over TLS). The transport rules hold for
		// them too: HTTPS only while the policy lists it, no plain HTTP under
		// https_required.
		$rPlain = self::plainPorts($rSettings, $rMain, $rHttpPort);
		$rKept = [];
		foreach (array_keys(ClusterEndpoint::legacyUrls($rSettings)) as $rUrl) {
			$rParsed = ClusterEndpoint::parseUrl($rUrl);
			$rTls = $rParsed !== null && $rParsed[0] === 'https';
			if ($rParsed !== null && $rTls !== in_array($rParsed[1], $rPlain, true) && ($rTls ? $rHttpsListed : $rTransport !== 'https_required')) {
				$rKept[] = $rUrl;
			}
		}
		$rUrls = $rTransport === 'https_required' ? array_merge($rHttps, $rKept) : array_merge($rHttps, $rHttp, $rOld, $rKept);
		return [
			'policy_ver' => intval($rSettings['cluster_policy_ver'] ?? 1),
			'transport' => $rTransport,
			'main_urls' => array_values(array_unique($rUrls)),
		];
	}

	/**
	 * The ports MAIN answers the cluster API on over plain HTTP: the API's
	 * own, the public server's HTTP ports and the old ports kept.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return list<int>
	 */
	private static function plainPorts(array $rSettings, array $rMain, int $rHttpPort): array {
		$rPorts = array_merge([$rHttpPort, intval($rMain['http_broadcast_port'] ?? 0)], array_keys(ClusterEndpoint::legacyPorts($rSettings)));
		foreach (explode(',', (string) ($rMain['http_ports_add'] ?? '')) as $rPort) {
			$rPorts[] = intval($rPort);
		}
		return array_values(array_unique(array_filter($rPorts, static fn(int $rPort): bool => $rPort > 0)));
	}

	private static function hostPort(string $rHost, int $rPort): string {
		return (str_contains($rHost, ':') ? '[' . $rHost . ']' : $rHost) . ':' . $rPort;
	}
}
