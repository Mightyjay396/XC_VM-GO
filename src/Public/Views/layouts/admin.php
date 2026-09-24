<?php

/**
 * Unified Layout Header
 *
 * Backward-compatible global-function wrapper around XcVm\Core\Util\LayoutRenderer
 * — kept because ~150 view/controller files call renderUnifiedLayoutHeader()
 * directly. The actual logic lives in LayoutRenderer::renderHeader().
 *
 * Пример:
 *   renderUnifiedLayoutHeader('admin', ['_TITLE' => 'Dashboard']);
 */

use XcVm\Core\Util\LayoutRenderer;

if (!function_exists('renderUnifiedLayoutHeader')) {
    function renderUnifiedLayoutHeader($scope = 'admin', array $vars = []) {
        LayoutRenderer::renderHeader($scope, $vars);
    }
}
