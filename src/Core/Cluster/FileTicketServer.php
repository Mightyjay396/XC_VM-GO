<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\FileDigest;
use XcVm\Core\Cluster\Crypto\RelayAuth;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\Ticket;

/**
 * The owner's side of `/xfile` (ADR 0004, Phase 8): a file another server
 * reads from this one without a password in its URL (a movie's source, its
 * subtitles, a created channel's item).
 *
 * ```text
 * GET /xfile?o=<offset>&n=<length>
 * X-XCVM-File       a panel-signed `fil` ticket: fetcher_sid, fetcher_gen, owner_sid, ref, file
 * X-XCVM-File-Auth  the fetcher's node-key proof over the ticket, the method and this target
 * ->  200, the chunk (at most FileDigest::CHUNK bytes), and
 *     X-XCVM-File-Digest  this server's signature over {tid, owner_sid, offset, size, total, sha256, iat, nonce}
 * ```
 *
 * The ticket must verify under the panel key, name this server as the owner
 * and a fetcher the signed node list has active at its generation; the
 * proof must be the fetcher's, fresh (±90 s) and its nonce unseen. The path
 * is never on the wire: `file` is sealed by MAIN to this server (`n.`: to a
 * node's box key, which its agent keeps; `m.`: by MAIN to itself), and it
 * must hash to the ticket's `ref`. The path then passes the same rule the
 * legacy `getFile` had (an allowed extension, under the scan roots or the
 * panel's own directory).
 *
 * The digest is signed per chunk, so the fetcher's agent checks each chunk
 * before any of its bytes reach the reader (a tampered or moved chunk is
 * refused), and it names the nonce of the request it answers, so an old
 * answer for the same chunk is refused too. A server that cannot sign serves
 * nothing.
 */
final class FileTicketServer {
	public const TICKET = 'HTTP_X_XCVM_FILE';
	public const AUTH = 'HTTP_X_XCVM_FILE_AUTH';

	/** The extensions getFile served, and nothing else. */
	public const EXTENSIONS = ['log', 'gz', 'zip', 'm3u8', 'mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv', 'jpg', 'png', 'bmp', 'jpeg', 'gif', 'tif'];

	/** The local seal MAIN puts its own paths under (ClusterCrypto::sealLocal). */
	public const MAIN_SEAL = 'xfile';

	/**
	 * Serve one request.
	 *
	 * @param array<string, mixed> $rServer $_SERVER
	 * @param array<string, mixed> $rQuery  $_GET
	 * @param array<string, mixed> $rSettings the settings (lb_scan_roots)
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function serve(array $rServer, array $rQuery, array $rSettings, ?int $rNowMs = null): array {
		$rNowMs ??= DataPlaneTrust::nowMs();
		$rDenied = ['status' => 404, 'headers' => [], 'body' => ''];
		$rWire = $rServer[self::TICKET] ?? null;
		$rHeader = $rServer[self::AUTH] ?? null;
		$rPanel = DataPlaneTrust::panelPub();
		if (!is_string($rWire) || !is_string($rHeader) || $rPanel === null || !defined('SERVER_ID') || strtoupper((string) ($rServer['REQUEST_METHOD'] ?? '')) !== 'GET') {
			return $rDenied;
		}
		$rTicket = Ticket::verify($rPanel, 'fil', $rWire, intdiv($rNowMs, 1000));
		if ($rTicket === null || ($rTicket['owner_sid'] ?? null) !== (int) SERVER_ID || !is_int($rTicket['fetcher_sid'] ?? null) || !is_int($rTicket['fetcher_gen'] ?? null)
			|| !is_string($rTicket['ref'] ?? null) || !is_string($rTicket['file'] ?? null)
		) {
			return $rDenied;
		}
		$rFetcher = RelayGuard::child($rTicket['fetcher_sid'], $rTicket['fetcher_gen']);
		if ($rFetcher === null) {
			return $rDenied;
		}
		$rAuth = RelayAuth::verify($rFetcher['ed_pub'], $rHeader, $rWire, 'GET', (string) ($rServer['REQUEST_URI'] ?? ''), $rNowMs);
		if ($rAuth === null || !DataPlaneTrust::spendNonce($rFetcher['sid'], $rAuth['nonce'], $rAuth['ts_ms'])) {
			return $rDenied;
		}
		$rOffset = self::uint($rQuery['o'] ?? null);
		$rLength = self::uint($rQuery['n'] ?? null);
		if ($rOffset === null || $rLength === null || $rLength < 1 || $rLength > FileDigest::CHUNK) {
			return ['status' => 400, 'headers' => [], 'body' => ''];
		}
		$rPath = self::path($rTicket['file'], $rTicket['ref']);
		if ($rPath === null || !self::allowed($rPath, $rSettings)) {
			return $rDenied;
		}
		clearstatcache(true, $rPath);
		$rTotal = @filesize($rPath);
		$rFP = $rTotal === false ? false : @fopen($rPath, 'rb');
		if ($rFP === false || $rTotal === false) {
			return $rDenied;
		}
		if ($rOffset > $rTotal) {
			fclose($rFP);
			return ['status' => 416, 'headers' => ['Content-Range' => 'bytes */' . $rTotal], 'body' => ''];
		}
		$rBytes = '';
		if ($rOffset < $rTotal && fseek($rFP, $rOffset) === 0) {
			$rWant = min($rLength, $rTotal - $rOffset);
			while (strlen($rBytes) < $rWant && !feof($rFP)) {
				$rPart = fread($rFP, $rWant - strlen($rBytes));
				if ($rPart === false || $rPart === '') {
					break;
				}
				$rBytes .= $rPart;
			}
		}
		fclose($rFP);
		// A file cut short while it was read is served as it read; its digest
		// says so, and the next chunk the fetcher asks for will not match.
		$rDigest = DataPlaneTrust::signDigest((string) $rTicket['tid'], (int) SERVER_ID, $rOffset, max($rTotal, $rOffset + strlen($rBytes)), $rBytes, intdiv($rNowMs, 1000), $rAuth['nonce']);
		if ($rDigest === null) {
			return ['status' => 503, 'headers' => [], 'body' => ''];
		}
		$rHeaders = ['Content-Type' => 'application/octet-stream', 'X-XCVM-File-Digest' => $rDigest, 'Cache-Control' => 'no-store'];
		// A fetcher that sealed a key to this server (RelaySeal, D11) gets the
		// chunk framed under it; the digest stays over the plaintext.
		$rSealedKey = $rQuery[RelaySeal::PARAM] ?? null;
		if (is_string($rSealedKey) && $rSealedKey !== '') {
			$rKey = RelaySeal::openKey($rSealedKey, RelaySeal::fileContext((int) SERVER_ID, (string) $rTicket['tid']));
			if ($rKey === null) {
				return $rDenied;
			}
			$rBytes = (new RelaySeal($rKey))->frames($rBytes);
			$rHeaders[RelaySeal::HEADER] = RelaySeal::VERSION;
		}
		return ['status' => 200, 'headers' => $rHeaders + ['Content-Length' => (string) strlen($rBytes)], 'body' => $rBytes];
	}

	/**
	 * The path a ticket's `file` names, once it opens for this server and
	 * hashes to its `ref`; null otherwise.
	 */
	public static function path(string $rFile, string $rRef): ?string {
		$rPrefix = substr($rFile, 0, 2);
		$rBlob = Enc::b64urlDecode(substr($rFile, 2));
		if ($rBlob === null) {
			return null;
		}
		$rPath = null;
		if ($rPrefix === DataPlane::SEAL_NODE) {
			$rSk = DataPlaneTrust::boxSecret();
			$rPath = $rSk === null ? null : Seal::open($rSk, DataPlane::SEAL_PURPOSE, $rRef, $rBlob);
		} elseif ($rPrefix === DataPlane::SEAL_MAIN) {
			$rPath = DataPlaneTrust::openMain($rBlob, $rRef, self::MAIN_SEAL);
		}
		if (!is_string($rPath) || $rPath === '' || !hash_equals($rRef, DataPlane::ref((int) SERVER_ID, $rPath))) {
			return null;
		}
		return $rPath;
	}

	/** getFile's rule: an allowed extension, a readable file, under the scan roots or the panel's own directory. */
	public static function allowed(string $rPath, array $rSettings): bool {
		$rExt = strtolower((string) pathinfo($rPath, PATHINFO_EXTENSION));
		return in_array($rExt, self::EXTENSIONS, true) && is_file($rPath) && is_readable($rPath)
			&& ClusterSettings::pathAllowed($rPath, $rSettings['lb_scan_roots'] ?? null, defined('MAIN_HOME') ? [MAIN_HOME] : []);
	}

	private static function uint(mixed $rValue): ?int {
		return is_string($rValue) && preg_match('/^(0|[1-9][0-9]{0,15})\z/', $rValue) ? (int) $rValue : null;
	}
}
