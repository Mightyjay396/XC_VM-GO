<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;

/**
 * FanoutBinaryCommand — install/update the xc_fanout daemon binary (ADR 0003).
 *
 * The daemon lives in its own repo (GIT_REPO_FANOUT) and ships its per-arch
 * static binaries as GitHub **release assets** (not committed to the tree). This
 * command is the panel-side installer/updater, modelled on the binaries/maxmind
 * updaters. The installed version is tracked in a sidecar file
 * (`xc_fanout.version`) rather than derived from the binary, so a locally-built
 * or custom-signed test build is not force-overwritten just because its
 * self-reported version differs from the latest GitHub release — pin it by
 * writing the file. Independently, the binary is probed with `xc_fanout
 * -version`: a binary that does not answer is treated as missing/corrupt and
 * reinstalled regardless of the recorded version. When an update is due it
 * downloads the arch-matched asset, verifies its SHA-256, installs it
 * atomically, records the version file, and restarts the daemon (the `service`
 * keepalive respawns it with the new binary). The release channel is the
 * per-repository FANOUT channel ({@see UpdateChannels}).
 *
 * Usage: `console.php fanout_binary` (add `force` to reinstall the same version).
 * On MAIN, `console.php fanout_binary cache [arch…]` keeps the verified binary
 * for each arch its nodes run (AgentBinaryCommand's cache, FANOUT_PREFIX),
 * which it hands nodes over the cluster API (`node.root fanout_binary`); its
 * own arch's is kept at each install.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutBinaryCommand implements CommandInterface {
	/** Sidecar file (next to the binary) recording the installed version. */
	private const VERSION_FILE = 'xc_fanout.version';

	public function getName(): string {
		return 'fanout_binary';
	}

	public function getDescription(): string {
		return 'Install/update the xc_fanout daemon binary from its release';
	}

	public function execute(array $rArgs): int {
		if (($rArgs[0] ?? '') === 'cache') {
			$rArches = array_values(array_intersect($rArgs, array_unique(ReleaseAsset::ARCH_MAP)));
			$rArches = $rArches !== [] ? $rArches : array_values(array_filter([ReleaseAsset::arch(trim(php_uname('m')))]));
			$rFailed = 0;
			foreach ($rArches as $rArch) {
				$rFailed += AgentBinaryCommand::cached($rArch, in_array('force', $rArgs, true), static function (string $rLine): void {
					echo $rLine . "\n";
				}, AgentBinaryCommand::FANOUT_PREFIX) === null ? 1 : 0;
			}
			return $rFailed === 0 && $rArches !== [] ? 0 : 1;
		}
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rForce = in_array('force', $rArgs, true);

		$rMachine = trim(php_uname('m'));
		$rArch = ReleaseAsset::arch($rMachine);
		if ($rArch === null) {
			echo "Unsupported architecture: {$rMachine}\n";
			return 1;
		}

		$rDir = BIN_PATH . 'xc_fanout/';
		$rBinary = $rDir . 'xc_fanout';
		$rVerFile = $rDir . self::VERSION_FILE;

		// Integrity probe: the binary must answer `-version`. A binary that does
		// not respond is missing or corrupted ("bit-rotted") and must be
		// reinstalled regardless of the recorded version.
		$rReported = $this->binaryVersion($rBinary);
		$rHealthy = $rReported !== null;

		// Installed version is tracked in a sidecar file, not derived from the
		// binary, so a locally-built/signed test build is not force-overwritten
		// just because its self-reported version differs from the latest release.
		// Seed the file from the running binary on first run after upgrade
		// (migration) so an already up-to-date host is not needlessly reinstalled.
		$rInstalled = $this->readVersionFile($rVerFile);
		if ($rInstalled === null && $rHealthy) {
			$this->writeVersionFile($rVerFile, $rReported);
			$rInstalled = $rReported;
		}

		$rChannel = UpdateChannels::fanout();

		try {
			$rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_FANOUT, $rChannel);
			$rGit->setTimeout(20);
			// `force` means "recheck everything from scratch": drop the 30-minute
			// releases cache so a forced reinstall resolves against a fresh GitHub
			// response instead of a stale one that could pin an old "latest".
			if ($rForce) {
				$rGit->clearCache();
			}
			$rReleases = $rGit->getReleases();
		} catch (\Exception $e) {
			echo 'Failed to check xc_fanout releases: ' . $e->getMessage() . "\n";
			return 1;
		}
		if (empty($rReleases[0])) {
			echo "Failed to resolve the latest xc_fanout release.\n";
			return 1;
		}
		$rTag = trim($rReleases[0]);
		$rLatest = ltrim($rTag, 'vV');

		if (!$rForce && $rHealthy && $rInstalled !== null && $rInstalled === $rLatest) {
			echo "xc_fanout is up to date ({$rInstalled}).\n";
			return 0;
		}
		$rReason = !$rHealthy
			? 'binary not responding (missing/corrupt)'
			: 'installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest;
		echo 'xc_fanout: ' . $rReason . " → updating\n";

		$rBase = ReleaseAsset::baseUrl(GIT_OWNER, GIT_REPO_FANOUT, $rTag);
		$rAsset = 'xc_fanout-linux-' . $rArch;

		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			echo "Failed to create {$rDir}\n";
			return 1;
		}
		$rTmp = $rDir . '.xc_fanout.new';

		if (!ReleaseAsset::download($rBase . $rAsset, $rTmp)) {
			echo "Failed to download {$rAsset}\n";
			@unlink($rTmp);
			return 1;
		}

		$rExpected = ReleaseAsset::expectedSha256($rBase . 'SHA256SUMS', $rAsset);
		if ($rExpected === null) {
			echo "Failed to fetch SHA256SUMS\n";
			@unlink($rTmp);
			return 1;
		}
		if (!hash_equals($rExpected, hash_file('sha256', $rTmp))) {
			echo "Checksum mismatch for {$rAsset} — aborting\n";
			@unlink($rTmp);
			return 1;
		}

		$rFailed = self::install($rTmp, $rLatest);
		if ($rFailed !== null) {
			echo $rFailed . " — aborting\n";
			return 1;
		}
		// MAIN keeps its own arch's binary for the nodes it serves.
		if (NodeRole::mainBuild()) {
			AgentBinaryCommand::cached($rArch, false, null, AgentBinaryCommand::FANOUT_PREFIX);
		}
		echo "xc_fanout {$rLatest} installed.\n";
		return 0;
	}

	/**
	 * Install $rTmp, a verified xc_fanout binary of version $rVersion in
	 * bin/xc_fanout/ (the download, or the copy root staged from MAIN's
	 * grant): it must run here (`-version`), then it replaces the binary
	 * atomically, the version is recorded beside it, and the daemon restarts.
	 * $rTmp is gone either way. Null once installed, else why not.
	 */
	public static function install(string $rTmp, string $rVersion): ?string {
		$rDir = BIN_PATH . 'xc_fanout/';
		@chmod($rTmp, 0755);
		if (trim((string) shell_exec(escapeshellarg($rTmp) . ' -version 2>/dev/null')) === '') {
			@unlink($rTmp);
			return 'the binary does not run on this host';
		}
		if (!@rename($rTmp, $rDir . 'xc_fanout')) { // atomic replace
			@unlink($rTmp);
			return 'cannot install ' . $rDir . 'xc_fanout';
		}
		@chown($rDir . 'xc_fanout', 'xc_vm');
		@chgrp($rDir . 'xc_fanout', 'xc_vm');
		// Record the installed version in the sidecar file so subsequent runs
		// compare this file against GitHub (not the binary's self-report).
		if (@file_put_contents($rDir . self::VERSION_FILE, ltrim($rVersion, 'vV') . "\n") !== false) {
			@chown($rDir . self::VERSION_FILE, 'xc_vm');
			@chgrp($rDir . self::VERSION_FILE, 'xc_vm');
		}
		// Restart: kill ONLY the daemon process (match the exact process NAME, not
		// the cmdline) so the service keepalive loop — whose bash cmdline contains
		// the same binary path — survives and respawns it with the new binary.
		// Harmless no-op if it isn't running yet.
		shell_exec("pkill -u xc_vm -x xc_fanout 2>/dev/null");
		return null;
	}

	/** The version recorded beside the installed binary (the node reports it), or null. */
	public static function installedVersion(): ?string {
		$rVal = trim((string) @file_get_contents(BIN_PATH . 'xc_fanout/' . self::VERSION_FILE));
		return $rVal !== '' ? ltrim($rVal, 'vV') : null;
	}

	/**
	 * Version the binary reports via `-version`, or null when it is absent or
	 * does not respond (missing/corrupt). Doubles as the integrity probe.
	 */
	private function binaryVersion(string $rBinary): ?string {
		if (!is_file($rBinary) || !is_executable($rBinary)) {
			return null;
		}
		$rOut = trim((string) shell_exec(escapeshellarg($rBinary) . ' -version 2>/dev/null'));
		return $rOut !== '' ? ltrim($rOut, 'vV') : null;
	}

	/** Recorded installed version from the sidecar file, or null if absent/empty. */
	private function readVersionFile(string $rFile): ?string {
		if (!is_file($rFile)) {
			return null;
		}
		$rVal = trim((string) @file_get_contents($rFile));
		return $rVal !== '' ? ltrim($rVal, 'vV') : null;
	}

	/** Persist the installed version to the sidecar file (best-effort). */
	private function writeVersionFile(string $rFile, string $rVersion): void {
		if (@file_put_contents($rFile, ltrim($rVersion, 'vV') . "\n") !== false) {
			@chown($rFile, 'xc_vm');
			@chgrp($rFile, 'xc_vm');
		}
	}
}
