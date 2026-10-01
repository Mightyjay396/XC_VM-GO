<?php

namespace XcVm\Core\Updates;

/**
 * Download and verification of a GitHub release asset: the per-arch binaries
 * of the XC_VM_Fanout release (`xc_fanout-linux-<arch>`, `xc_agent-linux-<arch>`),
 * checked against the release's SHA256SUMS.
 *
 * @package XC_VM_Core_Updates
 */
final class ReleaseAsset {
	/** uname -m → release asset arch suffix. */
	public const ARCH_MAP = [
		'x86_64'  => 'amd64',
		'amd64'   => 'amd64',
		'aarch64' => 'arm64',
		'arm64'   => 'arm64',
		'armv7l'  => 'armv7',
		'armv7'   => 'armv7',
		'armhf'   => 'armv7',
		'i386'    => '386',
		'i686'    => '386',
	];

	/** The release asset arch for a `uname -m`, or null when unsupported. */
	public static function arch(string $rMachine): ?string {
		return self::ARCH_MAP[trim($rMachine)] ?? null;
	}

	/** Download a URL to a file (following redirects). */
	public static function download(string $rUrl, string $rDest): bool {
		$rFp = @fopen($rDest, 'wb');
		if (!$rFp) {
			return false;
		}
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_URL            => $rUrl,
			CURLOPT_FILE           => $rFp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FAILONERROR    => true,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rOk = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		fclose($rFp);

		return $rOk !== false && $rCode >= 200 && $rCode < 300 && filesize($rDest) > 0;
	}

	/** Is $rUrl there: a HEAD that ends in a 2xx, redirects followed? */
	public static function exists(string $rUrl): bool {
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_URL            => $rUrl,
			CURLOPT_NOBODY         => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rOk = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		return $rOk !== false && $rCode >= 200 && $rCode < 300;
	}

	/**
	 * The runtime bundle's asset for a distribution (os-release's ID and
	 * VERSION_ID), as bin/install/update_binaries.sh picks it; null for one
	 * XC_VM does not support (only Ubuntu 20, 22, 24 and Debian 12, 13: not
	 * Ubuntu 18, Debian 11 or the RHEL family).
	 */
	public static function bundleFor(string $rId, string $rVersion): ?string {
		$rMajor = (int) explode('.', $rVersion)[0];
		return match (true) {
			$rId === 'ubuntu' && in_array($rMajor, [20, 22, 24], true) => 'ubuntu_' . $rMajor . '.tar.gz',
			$rId === 'debian' && in_array($rMajor, [12, 13], true) => 'debian_' . $rMajor . '.tar.gz',
			default => null,
		};
	}

	/** Expected sha256 for $rAsset from a SHA256SUMS file (`<hash>  <name>`). */
	public static function expectedSha256(string $rUrl, string $rAsset): ?string {
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_URL            => $rUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		if (!is_string($rBody) || $rCode < 200 || $rCode >= 300) {
			return null;
		}
		return self::sumFor($rBody, $rAsset);
	}

	/** The hash SHA256SUMS text lists for an asset, or null. */
	public static function sumFor(string $rSums, string $rAsset): ?string {
		foreach (explode("\n", $rSums) as $rLine) {
			$rParts = preg_split('/\s+/', trim($rLine), 2);
			if (count($rParts) === 2 && ltrim(trim($rParts[1]), '*./') === $rAsset && preg_match('/^[0-9a-f]{64}$/i', $rParts[0])) {
				return strtolower(trim($rParts[0]));
			}
		}
		return null;
	}

	/** The download base URL of a release tag. */
	public static function baseUrl(string $rOwner, string $rRepo, string $rTag): string {
		return 'https://github.com/' . $rOwner . '/' . $rRepo . '/releases/download/' . rawurlencode($rTag) . '/';
	}
}
