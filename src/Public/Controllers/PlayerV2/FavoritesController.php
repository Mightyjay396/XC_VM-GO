<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * FavoritesController — Centralized Favorites & Bookmarks Vault for Web Player V2.
 *
 * Synchronizes client-side starred items (Live Channels, Movies, TV Series, Radio)
 * with the server for rich metadata cards and 1-click playback.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class FavoritesController extends BasePlayerV2Controller {
	public function index() {
		global $db, $rUserInfo;

		if (empty($rUserInfo) || empty($rUserInfo['id'])) {
			header('Location: login');
			exit;
		}

		// If this is an SPA navigation request, render the view template JSON
		if (!empty($_SERVER['HTTP_X_SPA_REQUEST'])) {
			$this->render('favorites', [
				'_PAGE'  => 'favorites',
				'_TITLE' => 'My Favorites Vault',
			]);
			return;
		}

		// Only handle AJAX data fetching when explicitly requested via POST or ?ajax=1
		$isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST')
			|| RequestManager::has('ajax')
			|| RequestManager::get('format') === 'json';

		if ($isAjax) {
			$this->handleAjaxFavorites();
			return;
		}

		$this->render('favorites', [
			'_PAGE'  => 'favorites',
			'_TITLE' => 'My Favorites Vault',
		]);
	}

	/**
	 * Respond to AJAX requests with populated cards for favorited IDs.
	 */
	private function handleAjaxFavorites(): void {
		global $db, $rUserInfo;

		$rawInput = @file_get_contents('php://input');
		$jsonData = json_decode($rawInput, true) ?: [];
		$req = array_merge(RequestManager::getAll(), $_GET, $_POST, $jsonData);

		$liveIds = $this->sanitizeIdList($req['live_ids'] ?? []);
		$movieIds = $this->sanitizeIdList($req['movie_ids'] ?? []);
		$seriesIds = $this->sanitizeIdList($req['series_ids'] ?? []);
		$radioIds = $this->sanitizeIdList($req['radio_ids'] ?? []);

		// IDOR guard: only surface metadata for content this line is entitled to.
		// Intersect the requested ids with the viewer's allowed id lists so a
		// subscriber cannot read titles/covers/ratings of packages they don't own.
		$liveIds   = array_values(array_intersect($liveIds, array_map('intval', $rUserInfo['live_ids'] ?? [])));
		$movieIds  = array_values(array_intersect($movieIds, array_map('intval', $rUserInfo['vod_ids'] ?? [])));
		$seriesIds = array_values(array_intersect($seriesIds, array_map('intval', $rUserInfo['series_ids'] ?? [])));
		$radioIds  = array_values(array_intersect($radioIds, array_map('intval', $rUserInfo['radio_ids'] ?? [])));

		// Categories map
		$db->query('SELECT `id`, `category_name` FROM `streams_categories`;');
		$catRows = $db->get_rows() ?: [];
		$categoryMap = [];
		foreach ($catRows as $cat) {
			$categoryMap[$cat['id']] = $cat['category_name'];
		}

		$items = [
			'live'   => [],
			'movies' => [],
			'series' => [],
			'radio'  => [],
		];

		// 1. Fetch Live Channels
		if ($liveIds !== []) {
			$livePh = implode(',', array_fill(0, count($liveIds), '?'));
			$db->query("SELECT `id`, `stream_display_name`, `stream_icon`, `category_id` FROM `streams` WHERE `type` = 1 AND `id` IN ({$livePh});", ...$liveIds);
			$rows = $db->get_rows() ?: [];
			foreach ($rows as $r) {
				$catId = $this->parseCatId($r['category_id']);
				$items['live'][] = [
					'id'          => (int) $r['id'],
					'type'        => 'live',
					'title'       => $r['stream_display_name'],
					'icon'        => ImageUtils::validateURL($r['stream_icon'] ?? '') ?: '',
					'category'    => $categoryMap[$catId] ?? 'Live Channel',
					'play_url'    => 'live?channel=' . (int) $r['id'],
				];
			}
		}

		// 2. Fetch Movies
		if ($movieIds !== []) {
			$moviePh = implode(',', array_fill(0, count($movieIds), '?'));
			$db->query("SELECT `id`, `stream_display_name`, `stream_icon`, `movie_properties`, `rating`, `year`, `category_id` FROM `streams` WHERE `type` = 2 AND `id` IN ({$moviePh});", ...$movieIds);
			$rows = $db->get_rows() ?: [];
			foreach ($rows as $r) {
				$props = json_decode($r['movie_properties'] ?? '', true) ?: [];
				$catId = $this->parseCatId($r['category_id']);
				$cover = ImageUtils::validateURL($props['movie_image'] ?? ($r['stream_icon'] ?? '')) ?: '';
				$items['movies'][] = [
					'id'          => (int) $r['id'],
					'type'        => 'movie',
					'title'       => $r['stream_display_name'],
					'cover'       => $cover,
					'rating'      => $r['rating'] ?? ($props['rating'] ?? null),
					'year'        => $r['year'] ?? (!empty($props['release_date']) ? substr($props['release_date'], 0, 4) : null),
					'category'    => $categoryMap[$catId] ?? 'Cinema Film',
					'details_url' => 'movie?id=' . (int) $r['id'],
					'play_url'    => 'watch?type=movie&id=' . (int) $r['id'],
				];
			}
		}

		// 3. Fetch Series
		if ($seriesIds !== []) {
			$seriesPh = implode(',', array_fill(0, count($seriesIds), '?'));
			$db->query("SELECT `id`, `title`, `cover`, `rating`, `year`, `category_id`, `genre`, `seasons` FROM `streams_series` WHERE `id` IN ({$seriesPh});", ...$seriesIds);
			$rows = $db->get_rows() ?: [];
			foreach ($rows as $r) {
				$catId = $this->parseCatId($r['category_id']);
				$seasonsCount = 1;
				if (!empty($r['seasons'])) {
					$sData = json_decode($r['seasons'], true);
					if (is_array($sData)) {
						$seasonsCount = max(1, count($sData));
					}
				}
				$items['series'][] = [
					'id'            => (int) $r['id'],
					'type'          => 'series',
					'title'         => $r['title'],
					'cover'         => ImageUtils::validateURL($r['cover'] ?? '') ?: '',
					'rating'        => $r['rating'] ?? null,
					'year'          => $r['year'] ?? null,
					'genre'         => $r['genre'] ?? ($categoryMap[$catId] ?? 'TV Series'),
					'category'      => $categoryMap[$catId] ?? 'TV Series',
					'seasons_count' => $seasonsCount,
					'details_url'   => 'series?id=' . (int) $r['id'],
				];
			}
		}

		// 4. Fetch Radio Stations
		if ($radioIds !== []) {
			$radioPh = implode(',', array_fill(0, count($radioIds), '?'));
			$db->query("SELECT `id`, `stream_display_name`, `stream_icon`, `category_id` FROM `streams` WHERE `type` = 4 AND `id` IN ({$radioPh});", ...$radioIds);
			$rows = $db->get_rows() ?: [];
			foreach ($rows as $r) {
				$catId = $this->parseCatId($r['category_id']);
				$cleanTitle = preg_replace('/^[\?\s\x{1F300}-\x{1F6FF}\x{2600}-\x{26FF}]+/u', '', $r['stream_display_name']);
				$items['radio'][] = [
					'id'          => (int) $r['id'],
					'type'        => 'radio',
					'title'       => trim($cleanTitle) ?: $r['stream_display_name'],
					'icon'        => ImageUtils::validateURL($r['stream_icon'] ?? '') ?: '',
					'category'    => $categoryMap[$catId] ?? 'Radio Station',
					'play_url'    => 'radio?station=' . (int) $r['id'],
				];
			}
		}

		$counts = [
			'all'    => count($items['live']) + count($items['movies']) + count($items['series']) + count($items['radio']),
			'live'   => count($items['live']),
			'movies' => count($items['movies']),
			'series' => count($items['series']),
			'radio'  => count($items['radio']),
		];

		header('Content-Type: application/json; charset=utf-8');
		echo json_encode([
			'status' => 'success',
			'counts' => $counts,
			'items'  => $items,
		]);
		exit;
	}

	private function sanitizeIdList($raw): array {
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$raw = $decoded;
			} else {
				$raw = explode(',', $raw);
			}
		}
		if (!is_array($raw)) {
			return [];
		}
		return array_values(array_unique(array_filter(array_map('intval', $raw), fn($id) => $id > 0)));
	}

	private function parseCatId($raw): int {
		if (is_numeric($raw)) {
			return (int) $raw;
		}
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) && $decoded !== []) {
				return (int) $decoded[0];
			}
		}
		return 0;
	}
}
