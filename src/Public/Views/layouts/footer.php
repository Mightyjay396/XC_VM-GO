<?php

/**
 * Unified Layout Footer
 *
 * Backward-compatible global-function wrapper around XcVm\Core\Util\LayoutRenderer
 * — kept because ~150 view/controller files call renderUnifiedLayoutFooter()
 * directly. The actual logic lives in LayoutRenderer::renderFooter().
 *
 * Пример:
 *   renderUnifiedLayoutFooter('admin');
 */

use XcVm\Core\Util\LayoutRenderer;

if (!function_exists('renderUnifiedLayoutFooter')) {
    function renderUnifiedLayoutFooter($scope = 'admin', array $vars = []) {
        LayoutRenderer::renderFooter($scope, $vars);
    }
}
