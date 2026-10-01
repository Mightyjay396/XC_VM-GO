<?php

namespace XcVm\Domain\Server;

use XcVm\Core\Util\AtomicFile;

/**
 * A proxy's key for its control channel with MAIN (D8): the stats a proxy
 * posts to /admin/proxy_api each minute and the signals MAIN answers with
 * (reboot, restart, block an IP, flush the firewall) used to travel unsigned,
 * MAIN trusting the request's source address and the proxy whatever answered.
 *
 * - **The key.** K = HMAC-SHA256(MAIN's proxy secret, "xcvm proxy key v1|"
 *   ‖ server_id ‖ "|" ‖ gen). The secret is MAIN's alone (config/proxy_secret,
 *   0600, made once); `gen` is the proxy's `proxy_key_gen`, raised at each
 *   install, so a reinstalled proxy's old key opens nothing. MAIN stores no
 *   key: it derives it. The install writes it to the proxy
 *   (config/proxy.key, 0600 root, as hex).
 * - **A request** carries HEADER: `<ts ms>.<nonce: 32 hex>.<mac: 64 hex>`,
 *   mac = HMAC-SHA256(K, "xcvm proxy req v1|" ‖ server_id ‖ "|" ‖ ts ‖ "|"
 *   ‖ nonce ‖ "|" ‖ SHA-256(body)). MAIN takes it within SKEW_MS of its clock,
 *   and each nonce once (NonceStore).
 * - **The answer** is `{"payload": <the signals as a JSON string>, "mac":
 *   HMAC-SHA256(K, "xcvm proxy resp v1|" ‖ server_id ‖ "|" ‖ nonce ‖ "|"
 *   ‖ payload)}`: bound to the request's nonce, so an old answer is no answer.
 * - Once a proxy has signed one request (`proxy_signed`), MAIN refuses its
 *   unsigned ones. Until then (an archive from before this), the legacy
 *   source-address rule stands.
 */
final class ProxyKey {
	public const HEADER = 'X-XCVM-Proxy-Auth';

	/** How far a request's stamp may be from MAIN's clock. */
	public const SKEW_MS = 90000;

	private const FILE = 'proxy_secret';

	private static ?string $rFile = null;

	/** The key of proxy $rServerID at install generation $rGen (32 bytes). */
	public static function derive(string $rSecret, int $rServerID, int $rGen): string {
		return hash_hmac('sha256', 'xcvm proxy key v1|' . $rServerID . '|' . $rGen, $rSecret, true);
	}

	/** The key of proxy $rServerID at generation $rGen, from MAIN's secret. */
	public static function forServer(int $rServerID, int $rGen): ?string {
		$rSecret = self::secret();
		return $rSecret === null ? null : self::derive($rSecret, $rServerID, $rGen);
	}

	/** The request header a proxy sends (the proxy's side, and tests). */
	public static function requestAuth(string $rKey, int $rServerID, int $rTsMs, string $rNonceHex, string $rBody): string {
		return $rTsMs . '.' . $rNonceHex . '.' . self::requestMac($rKey, $rServerID, $rTsMs, $rNonceHex, $rBody);
	}

	/**
	 * The header's stamp and nonce when it is $rServerID's under $rKey for
	 * $rBody and within SKEW_MS of $rNowMs; null otherwise. The nonce is not
	 * claimed here.
	 *
	 * @return array{ts: int, nonce: string}|null
	 */
	public static function verifyRequest(string $rKey, int $rServerID, string $rHeader, string $rBody, int $rNowMs): ?array {
		if (!preg_match('/^([1-9][0-9]{12,15})\.([0-9a-f]{32})\.([0-9a-f]{64})\z/', $rHeader, $rM)) {
			return null;
		}
		$rTs = (int) $rM[1];
		if (abs($rNowMs - $rTs) > self::SKEW_MS || !hash_equals(self::requestMac($rKey, $rServerID, $rTs, $rM[2], $rBody), $rM[3])) {
			return null;
		}
		return ['ts' => $rTs, 'nonce' => $rM[2]];
	}

	/**
	 * The proxy a signed request comes from: its id, key and nonce, or null.
	 * $rRow is the claimed server's row (id, server_type, proxy_key_gen): a
	 * proxy with a key. $rClaim takes the nonce once (NonceStore::claim, as
	 * `proxy-<id>`); it runs only after the MAC verified.
	 *
	 * @param array<string, mixed>|null $rRow
	 * @param callable(string, string, int): bool $rClaim
	 * @return array{id: int, key: string, nonce: string}|null
	 */
	public static function admit(?array $rRow, string $rHeader, string $rBody, int $rNowMs, callable $rClaim): ?array {
		if (!is_array($rRow) || (int) ($rRow['server_type'] ?? 0) !== 1 || (int) ($rRow['proxy_key_gen'] ?? 0) < 1) {
			return null;
		}
		$rID = (int) $rRow['id'];
		$rKey = self::forServer($rID, (int) $rRow['proxy_key_gen']);
		$rOk = $rKey === null ? null : self::verifyRequest($rKey, $rID, $rHeader, $rBody, $rNowMs);
		if ($rOk === null || !$rClaim('proxy-' . $rID, $rOk['nonce'], $rOk['ts'])) {
			return null;
		}
		return ['id' => $rID, 'key' => (string) $rKey, 'nonce' => $rOk['nonce']];
	}

	/** The answer to a signed request: its signals, bound to the request's nonce. */
	public static function answer(string $rKey, int $rServerID, string $rNonceHex, array $rSignals): string {
		$rPayload = (string) json_encode($rSignals);
		return (string) json_encode(['payload' => $rPayload, 'mac' => self::responseMac($rKey, $rServerID, $rNonceHex, $rPayload)]);
	}

	public static function responseMac(string $rKey, int $rServerID, string $rNonceHex, string $rPayload): string {
		return hash_hmac('sha256', 'xcvm proxy resp v1|' . $rServerID . '|' . $rNonceHex . '|' . $rPayload, $rKey);
	}

	/**
	 * MAIN's proxy secret (32 bytes), made the first time it is asked for;
	 * null when it can be neither read nor made.
	 */
	public static function secret(): ?string {
		$rPath = self::file();
		$rHex = trim((string) @file_get_contents($rPath));
		if (preg_match('/^[0-9a-f]{64}\z/', $rHex)) {
			return (string) hex2bin($rHex);
		}
		if (is_file($rPath) || !AtomicFile::write($rPath, bin2hex(random_bytes(32)) . "\n", 0600)) {
			return null; // never replaced: an unreadable secret is not a missing one
		}
		if (posix_geteuid() === 0 && posix_getpwnam('xc_vm') !== false) {
			@chown($rPath, 'xc_vm'); // php-fpm reads it (proxy_api)
		}
		return self::secret();
	}

	/** Tests: another secret file; null restores the default. */
	public static function useFile(?string $rPath): void {
		self::$rFile = $rPath;
	}

	private static function file(): string {
		return self::$rFile ?? CONFIG_PATH . self::FILE;
	}

	private static function requestMac(string $rKey, int $rServerID, int $rTsMs, string $rNonceHex, string $rBody): string {
		return hash_hmac('sha256', 'xcvm proxy req v1|' . $rServerID . '|' . $rTsMs . '|' . $rNonceHex . '|' . hash('sha256', $rBody), $rKey);
	}
}
