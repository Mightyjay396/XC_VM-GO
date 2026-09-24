<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Util\LayoutRenderer;
use XcVm\Public\Controllers\Admin\BaseAdminController;

/**
 * BasePlayerController — базовый контроллер для player-страниц.
 *
 * Наследует BaseAdminController, переопределяя:
 *   - $scope = 'player' (для LayoutRenderer и путей views)
 *   - requirePermission() — пустой (player не использует RBAC)
 *   - render() — поддерживает player-специфичные глобальные переменные
 *
 * Player layout:
 *   1. LayoutRenderer::renderHeader('player') → player/header.php
 *   2. require Views/player/{view}.php — HTML-контент
 *   3. LayoutRenderer::renderFooter('player') → player/footer.php
 *
 * @see BaseAdminController
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BasePlayerController extends BaseAdminController {
	/** @var string Scope: 'player' */
	protected $scope = 'player';

	/**
	 * Player не использует RBAC — проверка не нужна.
	 */
	protected function requirePermission() {
		// Player авторизация обрабатывается bootstrap (PlayerScopeBootstrap).
		// Если пользователь дошёл до контроллера — он уже аутентифицирован.
	}

	/**
	 * Render: header → view → footer.
	 *
	 * Переопределяет admin render для player-specific глобалов.
	 * Player footer.php содержит page-specific JS, поэтому
	 * все глобалы должны быть доступны на момент его вызова.
	 *
	 * @param string $view Имя view-файла (без .php)
	 * @param array  $data Данные для view (extract'd в scope)
	 */
	protected function render(string $view, array $data = []) {
		// Player-специфичные глобалы (для header.php и footer.php)
		$viewGlobals = [
			// Core
			'db', 'rSettings', 'rUserInfo', '_TITLE', '_PAGE',
			// Player data
			'rStreamIDs', 'rFilterBy', 'rSortArray', 'rFilterArray',
			'rSearchBy', 'rURLs', 'rSubtitles', 'rLegacy', 'rSeries',
			'rYearStart', 'rYearEnd', 'rRatingStart', 'rRatingEnd',
		];
		foreach ($viewGlobals as $_g) {
			if (array_key_exists($_g, $GLOBALS) && !array_key_exists($_g, $data)) {
				$data[$_g] = $GLOBALS[$_g];
			}
		}
		unset($_g);

		// Export $data into $GLOBALS so footer.php can see them.
		foreach ($data as $key => $value) {
			$GLOBALS[$key] = $value;
		}

		// Deliberate: expose the view payload as local variables so the legacy
		// PHP templates can reference them by name. Refactoring this away means
		// rewriting every view.
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
		extract($data);

		$__viewsDir = MAIN_HOME . 'Public/Views/' . $this->scope . '/';

		// 1. Header
		LayoutRenderer::renderHeader($this->scope);

		// 2. View content
		$__viewFile = $__viewsDir . $view . '.php';
		if (file_exists($__viewFile)) {
			require $__viewFile;
		}

		// 3. Footer (player footer.php содержит page-specific JS)
		LayoutRenderer::renderFooter($this->scope);
	}

	/**
	 * Редирект на player home.
	 */
	protected function goPlayerHome() {
		header('Location: index');
		exit;
	}
}
