<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * Panel-signed tickets carried in headers: relay tickets (`rly`,
 * `X-XCVM-Relay`) and file tickets (`fil`, `/xfile/<k>/<ticket_id>`).
 *
 * ```text
 * wire = b64url(doc) "." b64url(Ed25519 panel sig over "xcvm-sig-v1" ‖ lp(tag) ‖ doc)
 * doc  = JSON {"v":1, "typ", "tid", "iat", "exp", ...}
 * ```
 *
 * MAIN mints them (the doc here, the signature by `xcvm_core`); nodes verify
 * against the pinned panel key. A ticket names the child node's key and
 * generation, so the parent also checks the signed node list (not here).
 */
final class Ticket {
	/** tag => [typ, max lifetime in seconds]. */
	public const KINDS = [
		'rly' => ['xcvm-relay', 86400],
		'fil' => ['xcvm-file', 21600],
	];

	/** Clock tolerance for `iat` in the future. */
	public const SKEW = 120;

	/**
	 * The document to sign. Keys are sorted so the same ticket always has the
	 * same bytes (the signature is cached per content hash).
	 *
	 * @param array<string, mixed> $rFields Ticket-specific fields.
	 */
	public static function document(string $rTag, string $rTid, int $rIat, int $rExp, array $rFields): string {
		[$rTyp, $rMax] = self::kind($rTag);
		if ($rExp <= $rIat || $rExp - $rIat > $rMax) {
			throw new \InvalidArgumentException('ticket lifetime');
		}
		if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $rTid)) {
			throw new \InvalidArgumentException('ticket id');
		}
		$rDoc = ['v' => 1, 'typ' => $rTyp, 'tid' => $rTid, 'iat' => $rIat, 'exp' => $rExp] + $rFields;
		ksort($rDoc);
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES);
	}

	public static function wire(string $rDoc, string $rSig): string {
		return Enc::b64url($rDoc) . '.' . Enc::b64url($rSig);
	}

	/**
	 * Verify a wire ticket and return its document, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify(string $rPanelSignPub, string $rTag, string $rWire, int $rNow): ?array {
		[$rTyp, $rMax] = self::kind($rTag);
		if (strlen($rWire) > 4096 || substr_count($rWire, '.') !== 1) {
			return null;
		}
		[$rDocPart, $rSigPart] = explode('.', $rWire);
		$rDoc = Enc::b64urlDecode($rDocPart);
		$rSig = Enc::b64urlDecode($rSigPart);
		if ($rDoc === null || $rSig === null || !PanelSig::verify($rPanelSignPub, $rTag, $rDoc, $rSig)) {
			return null;
		}
		$rData = json_decode($rDoc, true);
		if (!is_array($rData) || ($rData['v'] ?? null) !== 1 || ($rData['typ'] ?? null) !== $rTyp
			|| !is_int($rData['iat'] ?? null) || !is_int($rData['exp'] ?? null) || !is_string($rData['tid'] ?? null)
		) {
			return null;
		}
		if ($rData['exp'] - $rData['iat'] > $rMax || $rData['iat'] - self::SKEW > $rNow || $rNow >= $rData['exp']) {
			return null;
		}
		return $rData;
	}

	/** @return array{0: string, 1: int} */
	private static function kind(string $rTag): array {
		if (!isset(self::KINDS[$rTag])) {
			throw new \InvalidArgumentException('Not a ticket tag: ' . $rTag);
		}
		return self::KINDS[$rTag];
	}
}
