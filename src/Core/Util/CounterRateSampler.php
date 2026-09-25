<?php

namespace XcVm\Core\Util;

/**
 * Counter Rate Sampler
 *
 * Per-second rate of a monotonic counter (e.g. nginx's total request count)
 * whose previous reading must outlive the process: the watchdog runs one pass
 * per process and re-execs itself, so a reading kept in a variable is always
 * gone by the next pass. The previous reading lives in a small JSON state
 * file ({"v": value, "t": unix time}) instead.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class CounterRateSampler {
	/**
	 * @param string $rStateFile Where the previous reading is kept.
	 * @param int    $rMaxAge    Oldest previous reading (seconds) still used;
	 *                           an older one averages over a gap and says nothing.
	 */
	public function __construct(private string $rStateFile, private int $rMaxAge = 120) {
	}

	/**
	 * Record a reading and return the rate since the previous one.
	 *
	 * The new reading is always stored, so the next call measures from it even
	 * when this one reports nothing.
	 *
	 * @param float $rValue Current counter value.
	 * @param int   $rNow   Current unix time.
	 * @return int Units per second; 0 with no usable previous reading (none, a
	 *             corrupt state file, the same or an earlier second, one older
	 *             than the max age) or when the counter went down (a restart).
	 */
	public function sample(float $rValue, int $rNow): int {
		$rPrev = json_decode((string) @file_get_contents($this->rStateFile), true);
		$this->store($rValue, $rNow);

		if (!is_array($rPrev) || !isset($rPrev['v'], $rPrev['t']) || !is_numeric($rPrev['v']) || !is_numeric($rPrev['t'])) {
			return 0;
		}
		$rSeconds = $rNow - (int) $rPrev['t'];
		$rDelta = $rValue - (float) $rPrev['v'];
		if ($rSeconds <= 0 || $this->rMaxAge < $rSeconds || $rDelta < 0) {
			return 0;
		}

		return intval($rDelta / $rSeconds);
	}

	/**
	 * Replace the state file atomically (write a temporary file, then rename),
	 * so a reader never sees a half-written reading. Failures are silent: the
	 * next sample just reports 0.
	 */
	private function store(float $rValue, int $rNow): void {
		$rTmp = $this->rStateFile . '.' . getmypid() . '.tmp';
		if (@file_put_contents($rTmp, json_encode(['v' => $rValue, 't' => $rNow])) === false || !@rename($rTmp, $this->rStateFile)) {
			@unlink($rTmp);
		}
	}
}
