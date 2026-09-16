<?php

namespace XcVm\Streaming;

use XcVm\Core\Init\LegacyInitializer;

/**
 * StreamingBootstrap — streaming bootstrap
 *
 * @package XC_VM_Streaming
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamingBootstrap {
	public static function bootstrap($rFilename, $rSettings) {
		$rProbeEndpoints = ['probe', 'player_api'];
		$rDefaultEndpoints = ['live', 'thumb', 'subtitle', 'timeshift', 'vod', 'status'];
		$rPrivilegedEndpoints = ['rtmp', 'portal'];

		if (!in_array($rFilename, array_merge($rProbeEndpoints, $rDefaultEndpoints, $rPrivilegedEndpoints), true)) {
			return null;
		}

		$GLOBALS['rSettings'] = $rSettings;
		$GLOBALS['rAccess'] = $rFilename;
		LegacyInitializer::initStreaming();

		global $db;
		return $db;
	}
}
