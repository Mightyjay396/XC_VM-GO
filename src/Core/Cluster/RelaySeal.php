<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\Seal;

/**
 * AEAD-framed relays (the plan's D11): the bytes a parent sends a child that
 * relays one of its streams, sealed so a passive observer reads nothing and an
 * active one can change, drop or reorder nothing undetected.
 *
 * - **The key.** The child's agent picks a fresh 32-byte session key per
 *   connect and SEALs it to the parent's box key (purpose `relay`, context
 *   context()): a node's own box key, or MAIN's panel box key, which only the
 *   extension holds (`cluster_open_sealed`, since xcvm_core with the `relay`
 *   purpose). The sealed key travels in the request target (PARAM), which the
 *   child's relay proof signs, so it cannot be swapped.
 * - **The frames.** The parent answers with HEADER: VERSION and seals its
 *   output in frames: `u32 len ‖ AES-256-GCM(key, nonce = 0⁴ ‖ u64 counter,
 *   aad = AAD)`, each frame's plaintext at most FRAME bytes, the counter from 0
 *   for the connection. A frame that does not open, or comes out of order,
 *   ends the child's read.
 * - **Downgrade.** A server that can open relay keys reports it
 *   (`servers.relay_seal`, supported()); the signed servers section carries
 *   it, and a child pulls a stream from such a parent only sealed.
 *
 * A file chunk (`/xfile`) is sealed the same way when its fetcher asks
 * (FileTicketServer, fileContext()), on top of its owner's signed digest.
 * Viewer bytes stay direct.
 */
final class RelaySeal {
	public const PURPOSE = 'relay';

	/** The response header a sealed answer carries. */
	public const HEADER = 'X-XCVM-Relay-Seal';

	public const VERSION = 'v1';

	/** The request parameter carrying the sealed session key (base64url). */
	public const PARAM = 'rk';

	/** Largest plaintext in one frame. */
	public const FRAME = 65536;

	private const AAD = 'xcvm relay v1';

	private const TAG = 16;

	private string $rKey;

	private int $rCounter = 0;

	public function __construct(string $rKey) {
		$this->rKey = $rKey;
	}

	/** The SEAL context of stream $rStreamID's key for parent $rParentID: bound, so it opens for no other. */
	public static function context(int $rParentID, int $rStreamID): string {
		return 'relay|' . $rParentID . '|' . $rStreamID;
	}

	/** The SEAL context of a file chunk's key for owner $rOwnerID under file ticket $rTid (`/xfile`). */
	public static function fileContext(int $rOwnerID, string $rTid): string {
		return 'file|' . $rOwnerID . '|' . $rTid;
	}

	/**
	 * The session key a child sealed to this server under $rContext (context()
	 * for a relay, fileContext() for a file chunk) as PARAM (base64url), or
	 * null when it does not open: MAIN opens with the panel box key through
	 * the extension, a node with its own box key.
	 */
	public static function openKey(string $rParam, string $rContext): ?string {
		$rSealed = base64_decode(strtr($rParam, '-_', '+/'), true);
		if (!is_string($rSealed) || $rSealed === '') {
			return null;
		}
		try {
			$rKey = DataPlaneTrust::main()
				? ClusterCryptoFactory::create()->openSealed(self::PURPOSE, $rSealed, $rContext)
				: Seal::open((string) DataPlaneTrust::boxSecret(), self::PURPOSE, $rContext, $rSealed);
		} catch (\Throwable) {
			return null;
		}
		return is_string($rKey) && strlen($rKey) === 32 ? $rKey : null;
	}

	/**
	 * Seal everything this response writes from here on: the header, then an
	 * output buffer that frames each chunk (flush() pushes a partial one out).
	 */
	public static function start(string $rKey): void {
		header(self::HEADER . ': ' . self::VERSION);
		ob_start([new self($rKey), 'frames'], self::FRAME);
	}

	/** Push what is buffered out as a frame now (a relay waiting for its next segment). */
	public static function flush(): void {
		if (ob_get_level() > 0 && ob_get_length() > 0) {
			ob_flush();
		}
		flush();
	}

	/** The output buffer's callback: $rData as frames of at most FRAME bytes. */
	public function frames(string $rData): string {
		$rOut = '';
		foreach (str_split($rData, self::FRAME) as $rPiece) {
			if ($rPiece !== '') {
				$rOut .= $this->frame($rPiece);
			}
		}
		return $rOut;
	}

	private function frame(string $rPlain): string {
		$rTag = '';
		$rCipher = (string) openssl_encrypt($rPlain, 'aes-256-gcm', $this->rKey, OPENSSL_RAW_DATA, self::nonce($this->rCounter++), $rTag, self::AAD, self::TAG);
		return pack('N', strlen($rCipher) + self::TAG) . $rCipher . $rTag;
	}

	private static function nonce(int $rCounter): string {
		return "\0\0\0\0" . pack('J', $rCounter);
	}

	/**
	 * The plaintext of $rFrames sealed under $rKey, or null when any frame does
	 * not open, is cut short or out of order (tests, and the reference for the
	 * agent's reader).
	 */
	public static function open(string $rKey, string $rFrames): ?string {
		$rOut = '';
		$rAt = 0;
		for ($rCounter = 0; $rAt < strlen($rFrames); $rCounter++) {
			if (strlen($rFrames) - $rAt < 4) {
				return null;
			}
			$rLen = unpack('N', substr($rFrames, $rAt, 4))[1];
			$rAt += 4;
			if ($rLen < self::TAG || $rLen > self::FRAME + self::TAG || strlen($rFrames) - $rAt < $rLen) {
				return null;
			}
			$rPlain = openssl_decrypt(substr($rFrames, $rAt, $rLen - self::TAG), 'aes-256-gcm', $rKey, OPENSSL_RAW_DATA, self::nonce($rCounter), substr($rFrames, $rAt + $rLen - self::TAG, self::TAG), self::AAD);
			if ($rPlain === false) {
				return null;
			}
			$rOut .= $rPlain;
			$rAt += $rLen;
		}
		return $rOut;
	}

	/**
	 * Can this server open relay keys (its `servers.relay_seal`)? A node holds
	 * its box key (its agent's state); MAIN's extension must open the `relay`
	 * purpose, tried once with a key sealed to its own panel box key.
	 */
	public static function supported(): bool {
		if (!DataPlaneTrust::main()) {
			return DataPlaneTrust::boxSecret() !== null;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
			$rPub = (string) ($rCrypto->info()['panel_box_pub'] ?? '');
			if (strlen($rPub) !== 32) {
				return false;
			}
			$rProbe = random_bytes(32);
			return $rCrypto->openSealed(self::PURPOSE, Seal::seal($rPub, self::PURPOSE, 'relay|probe', $rProbe), 'relay|probe') === $rProbe;
		} catch (\Throwable) {
			return false;
		}
	}
}
