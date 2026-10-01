<?php

namespace XcVm\Streaming\Codec;

/**
 * FfmpegPaths — value-object holding FFmpeg/FFprobe binary paths.
 *
 * Resolves paths once based on the configured ffmpeg version from settings.
 *
 * @package XC_VM_Streaming_Codec
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FfmpegPaths {
	private static $cpu;

	private static $gpu;

	private static $probe;

	private static $resolved = false;

	/**
	 * Resolve the CPU/GPU/probe binary paths from the configured version strings.
	 *
	 * Paths are built dynamically from BIN_PATH/ffmpeg_bin/<version>/ so any build
	 * dropped into that folder is usable without touching this class — no version
	 * switch to maintain. This runs on the bootstrap hot path, so it stays cheap:
	 * a format check plus an is_file() stat, never a shell probe (that lives in
	 * {@see FfmpegBinaries}). A version the node does not have takes the newest
	 * build of its major it has (8.0 for 8.1 until the node fetched 8.1, or the
	 * reverse), else the legacy 4.0 build; the GPU binary falls back to the
	 * resolved CPU binary when ffmpeg_gpu is empty or points at a missing build.
	 *
	 * Called once during bootstrap; subsequent calls are no-ops.
	 *
	 * @param string      $cpuVersion e.g. '8.1', '7.1', '4.0'
	 * @param string|null $gpuVersion GPU ffmpeg version, or null to reuse the CPU build
	 */
	public static function resolve(string $cpuVersion, ?string $gpuVersion = null) {
		if (self::$resolved) {
			return;
		}

		self::$cpu   = self::binary($cpuVersion, 'ffmpeg') ?? FFMPEG_BIN_40;
		self::$probe = self::binary($cpuVersion, 'ffprobe') ?? FFPROBE_BIN_40;

		$rGpu = ($gpuVersion !== null && $gpuVersion !== '') ? self::binary($gpuVersion, 'ffmpeg') : null;
		self::$gpu = $rGpu ?? self::$cpu;

		self::$resolved = true;
	}

	/**
	 * Build a binary path for a version folder, or that of the newest folder of
	 * the same major when it is absent; null when the version string is
	 * malformed or the node has no build of that major.
	 *
	 * @param string $version Version folder name (e.g. '8.1')
	 * @param string $name    'ffmpeg' or 'ffprobe'
	 */
	private static function binary(string $version, string $name): ?string {
		if (!preg_match('/^(\d+)\.\d+$/', $version, $rMatch)) {
			return null;
		}
		$rPath = BIN_PATH . 'ffmpeg_bin/' . $version . '/' . $name;
		if (is_file($rPath)) {
			return $rPath;
		}
		$rSame = array_filter(glob(BIN_PATH . 'ffmpeg_bin/' . $rMatch[1] . '.*/' . $name) ?: [], static fn(string $rP): bool => (bool) preg_match('/^\d+\.\d+$/', basename(dirname($rP))));
		usort($rSame, static fn(string $a, string $b): int => version_compare(basename(dirname($b)), basename(dirname($a))));
		return $rSame[0] ?? null;
	}

	/**
	 * Does the 4.0 build take `-nofix_dts`? XUI's does (its own switch); one
	 * FfmpegBuildsCommand installed (BUILD_INFO beside it) is stock ffmpeg, which
	 * would refuse it.
	 */
	public static function fixDts(): bool {
		return !is_file(dirname(FFMPEG_BIN_40) . '/BUILD_INFO');
	}

	/** @return string Path to CPU-optimized ffmpeg binary */
	public static function cpu() {
		return self::$cpu;
	}

	/** @return string Path to GPU-capable ffmpeg binary */
	public static function gpu() {
		return self::$gpu;
	}

	/** @return string Path to ffprobe binary */
	public static function probe() {
		return self::$probe;
	}
}
