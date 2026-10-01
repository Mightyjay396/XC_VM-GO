<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;
use XcVm\Domain\Cluster\AgentUpgrades;

/**
 * AgentBinaryCommand — MAIN's cache of the LB cluster agent (`xc_agent`,
 * MAIN ↔ LB plan, section 5).
 *
 * The agent ships in the XC_VM_Fanout release beside xc_fanout
 * (`xc_agent-linux-<arch>`, same tag, same SHA256SUMS). LBs never download it
 * themselves: MAIN keeps a SHA-256-verified copy per arch in
 * `bin/xc_agent/cache/` and the install flow pushes it over SSH, so every node
 * runs the version MAIN pinned. The cached version is recorded next to each
 * binary. MAIN only (stripped from LB builds).
 *
 * Usage: `console.php agent_binary [amd64|arm64|armv7|386 …] [force]`
 * (no arch = this host's), and `console.php agent_binary release <version>`
 * to release a rollout a failing node holds back (AgentUpgrades::release).
 *
 * @package XC_VM_CLI_Commands
 */
class AgentBinaryCommand implements CommandInterface {
	public const ASSET_PREFIX = 'xc_agent-linux-';

	/** The fanout daemon, from the same release, which MAIN serves its nodes as it serves the agent. */
	public const FANOUT_PREFIX = 'xc_fanout-linux-';

	public function getName(): string {
		return 'agent_binary';
	}

	public function getDescription(): string {
		return 'Cache the xc_agent binary (per LB arch) from the fanout release';
	}

	public function execute(array $rArgs): int {
		if (($rArgs[0] ?? '') === 'release') {
			$rVersion = trim((string) ($rArgs[1] ?? ''));
			$rKind = (string) ($rArgs[2] ?? 'agent');
			if ($rVersion === '' || !in_array($rKind, AgentUpgrades::KINDS, true)) {
				echo "Usage: console.php agent_binary release <version> [agent|fanout|core]\n";
				return 1;
			}
			$rNodes = AgentUpgrades::release($rVersion, 'cli', null, $rKind);
			echo 'Released the rollout of ' . ['agent' => 'xc_agent', 'fanout' => 'xc_fanout', 'core' => 'xcvm_core'][$rKind] . ' ' . $rVersion . ': ' . $rNodes . " node(s) that did not take it are offered it again at the next cron:cluster.\n";
			return 0;
		}
		$rForce = in_array('force', $rArgs, true);
		$rArches = array_values(array_intersect($rArgs, array_unique(ReleaseAsset::ARCH_MAP)));
		if ($rArches === []) {
			$rHere = ReleaseAsset::arch(php_uname('m'));
			if ($rHere === null) {
				echo 'Unsupported architecture: ' . php_uname('m') . "\n";
				return 1;
			}
			$rArches = [$rHere];
		}
		$rFailed = 0;
		foreach ($rArches as $rArch) {
			$rPath = self::cached($rArch, $rForce, static function (string $rLine): void {
				echo $rLine . "\n";
			});
			if ($rPath === null) {
				$rFailed++;
			}
		}
		return $rFailed === 0 ? 0 : 1;
	}

	public static function cacheDir(): string {
		return BIN_PATH . 'xc_agent/cache/';
	}

	/**
	 * The version of the cached binary for $rArch (the `.version` beside it),
	 * or null when there is none. Read without asking GitHub anything, so the
	 * fleet's upgrades are decided from what MAIN actually holds. $rPrefix:
	 * ASSET_PREFIX (xc_agent) or FANOUT_PREFIX (xc_fanout).
	 */
	public static function cachedVersion(string $rArch, string $rPrefix = self::ASSET_PREFIX): ?string {
		if (!in_array($rArch, ReleaseAsset::ARCH_MAP, true) || !in_array($rPrefix, [self::ASSET_PREFIX, self::FANOUT_PREFIX], true)) {
			return null;
		}
		$rBinary = self::cacheDir() . $rPrefix . $rArch;
		$rVersion = is_file($rBinary) && is_file($rBinary . '.version') ? trim((string) file_get_contents($rBinary . '.version')) : '';
		return $rVersion === '' ? null : $rVersion;
	}

	/**
	 * The path of a verified agent binary (or, with FANOUT_PREFIX, fanout
	 * daemon) for $rArch at the current release, downloading it when the
	 * cache is missing or stale. Null when it cannot be had (GitHub
	 * unreachable, no such asset, checksum mismatch).
	 *
	 * @param callable|null $rLog fn(string $line): void
	 */
	public static function cached(string $rArch, bool $rForce = false, ?callable $rLog = null, string $rPrefix = self::ASSET_PREFIX): ?string {
		$rLog = $rLog ?? static function (string $rLine): void {
		};
		$rTool = $rPrefix === self::FANOUT_PREFIX ? 'xc_fanout' : 'xc_agent';
		if (!in_array($rArch, ReleaseAsset::ARCH_MAP, true) || !in_array($rPrefix, [self::ASSET_PREFIX, self::FANOUT_PREFIX], true)) {
			$rLog($rTool . ': unsupported arch ' . $rArch);
			return null;
		}
		$rDir = self::cacheDir();
		$rAsset = $rPrefix . $rArch;
		$rBinary = $rDir . $rAsset;
		$rVerFile = $rBinary . '.version';

		try {
			$rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_FANOUT, UpdateChannels::fanout());
			$rGit->setTimeout(20);
			if ($rForce) {
				$rGit->clearCache();
			}
			$rTag = trim((string) ($rGit->getReleases()[0] ?? ''));
		} catch (\Throwable $rE) {
			$rTag = '';
			$rLog($rTool . ': cannot check releases: ' . $rE->getMessage());
		}
		$rCachedVer = is_file($rVerFile) ? trim((string) file_get_contents($rVerFile)) : '';
		if ($rTag === '') {
			// Offline: a verified cached copy still serves.
			return is_file($rBinary) && $rCachedVer !== '' ? $rBinary : null;
		}
		$rLatest = ltrim($rTag, 'vV');
		if (!$rForce && is_file($rBinary) && $rCachedVer === $rLatest) {
			return $rBinary;
		}

		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			$rLog($rTool . ': cannot create ' . $rDir);
			return null;
		}
		$rBase = ReleaseAsset::baseUrl(GIT_OWNER, GIT_REPO_FANOUT, $rTag);
		$rTmp = $rDir . '.' . $rAsset . '.new';
		if (!ReleaseAsset::download($rBase . $rAsset, $rTmp)) {
			@unlink($rTmp);
			$rLog($rTool . ': ' . $rAsset . ' is not in release ' . $rTag);
			return is_file($rBinary) && $rCachedVer !== '' ? $rBinary : null;
		}
		$rExpected = ReleaseAsset::expectedSha256($rBase . 'SHA256SUMS', $rAsset);
		if ($rExpected === null || !hash_equals($rExpected, (string) hash_file('sha256', $rTmp))) {
			@unlink($rTmp);
			$rLog($rTool . ': checksum mismatch or missing for ' . $rAsset . ' — not cached');
			return null;
		}
		@chmod($rTmp, 0755);
		if (!@rename($rTmp, $rBinary)) {
			@unlink($rTmp);
			return null;
		}
		@file_put_contents($rVerFile, $rLatest . "\n");
		$rLog($rTool . ' ' . $rLatest . ' cached for ' . $rArch);
		return $rBinary;
	}
}
