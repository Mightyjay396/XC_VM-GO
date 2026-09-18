<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Integrity\AttributionGuard;

/**
 * Lock the panel UI when its required author/licence attribution has been removed
 * (AGPL-3.0 §7(b)). HTTP UI contexts only — self-skips on CLI so the operator can
 * always restore the notice from the command line.
 *
 * The check is live on every request: restoring the attribution unlocks the panel
 * on the next boot. Nothing is mutated and no data is touched — the lock is fully
 * reversible. Streaming is intentionally NOT gated here (removing a UI footer must
 * not cut off the reseller's end-customers); stream enforcement is the licence
 * layer's concern.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AttributionVerificationStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->isCli()) {
			return;
		}

		if (!AttributionGuard::verify()) {
			\generateError('ATTRIBUTION_REMOVED');
		}
	}
}
