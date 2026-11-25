<?php

namespace XcVm\Module\Providerimport;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Domain\Stream\StreamService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Browse a provider's synced live channels (providers_streams) and import a selection. */
class ProviderImportController {

    const LIST_MAX = 3000;
    const BATCH_MAX = 200;
    const VOD_EXT = ['mp4', 'mkv', 'avi', 'mov', 'm4v', 'wmv', 'flv', 'mpg', 'mpeg', 'webm'];

    public function index() {
        global $rMobile, $rSettings, $rPermissions;
        $language = Translator::class;
        $rBouquets = BouquetService::getAllSimple();
        $rServers = ServerRepository::getStreamingSimple($rPermissions);
        $rCategories = CategoryService::getAllByType('live');
        $rMovieCats = CategoryService::getAllByType('movie');
        $rSeriesCats = CategoryService::getAllByType('series');
        $rProviders = self::providers();
        $rFallbackLabel = self::t('fallback_live');
        $_TITLE = 'ProviderImport';

        LayoutRenderer::renderHeader('admin', ['_TITLE' => $_TITLE]);
        include __DIR__ . '/views/providers.php';
        LayoutRenderer::renderFooter('admin');
        include __DIR__ . '/views/providers_scripts.php';
    }

    /** action=providerimport_pcats — provider categories with channel counts */
    public function apiCategories() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $db = DatabaseFactory::get();
        $db->query("SELECT `category_array`, COUNT(*) AS `n` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = 'live' GROUP BY `category_array`;", $rPid);
        $rCounts = [];
        foreach ($db->get_rows() as $rRow) {
            $c = self::catName($rRow['category_array']);
            $rCounts[$c] = ($rCounts[$c] ?? 0) + intval($rRow['n']);
        }
        ksort($rCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $rExisting = ProviderImportController::existingCategories();
        $rOut = [];
        foreach ($rCounts as $c => $n) {
            $rOut[] = ['name' => (string) $c, 'count' => $n, 'existing_id' => $c === '' ? null : ($rExisting[ProviderImportController::norm((string) $c)] ?? null)];
        }
        ProviderImportController::json(['result' => true, 'categories' => $rOut]);
    }

    /** action=providerimport_plist — channels of a provider (optionally one category / search) */
    public function apiList() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $rCat = RequestManager::get('category');
        $rSearch = trim((string) RequestManager::get('search'));
        $db = DatabaseFactory::get();
        $rWhere = "`provider_id` = ? AND `type` = 'live'";
        $rParams = [$rPid];
        if ($rSearch !== '') {
            $rWhere .= ' AND `stream_display_name` LIKE ?';
            $rParams[] = '%' . $rSearch . '%';
        }
        $db->query('SELECT `stream_id`, `stream_display_name`, `stream_icon`, `category_array`, `channel_id` FROM `providers_streams` WHERE ' . $rWhere . ' ORDER BY `id` ASC;', ...$rParams);
        $rRows = [];
        foreach ($db->get_rows() as $r) {
            $c = self::catName($r['category_array']);
            if ($rCat !== null && $rCat !== '__all__' && $c !== $rCat) {
                continue;
            }
            $rRows[] = ['stream_id' => intval($r['stream_id']), 'name' => (string) $r['stream_display_name'], 'icon' => (string) $r['stream_icon'], 'category' => $c, 'channel_id' => (string) $r['channel_id']];
        }
        // imported rows are dropped before the cap, so the next ones show once the first are imported
        $rImported = self::importedIds($rPid, $rRows);
        if (!empty(RequestManager::get('hide_imported'))) {
            $rAllCount = count($rRows);
            $rRows = array_values(array_filter($rRows, static fn($r) => !isset($rImported[$r['stream_id']])));
            $rHidden = $rAllCount - count($rRows);
        }
        $rTruncated = count($rRows) > self::LIST_MAX;
        $rRows = array_slice($rRows, 0, self::LIST_MAX);

        $rTypes = self::streamTypes($rPid);
        foreach ($rRows as &$r) {
            $r['imported'] = isset($rImported[$r['stream_id']]);
            $r['kind'] = (($rTypes[$r['stream_id']] ?? '') === 'created_live' || preg_match('#24\s*/\s*7|24-7|\b247\b#i', $r['name'] . ' | ' . $r['category'])) ? '247' : 'live';
        }
        unset($r);
        ProviderImportController::json(['result' => true, 'rows' => $rRows, 'truncated' => $rTruncated, 'hidden' => $rHidden ?? 0, 'types_known' => (bool) $rTypes]);
    }

    /** action=providerimport_pimport — import selected provider channels (one batch) */
    public function apiImport() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $rIds = array_slice(ProviderImportController::intList('stream_ids'), 0, ProviderImportController::BATCH_MAX);
        if (!$rIds) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_none_selected')]);
        }
        $rMode = RequestManager::get('category_mode') === 'fixed' ? 'fixed' : 'provider';
        $rFixed = intval(RequestManager::get('fixed_category'));
        $rFallback = intval(RequestManager::get('fallback_category'));
        if ($rMode === 'fixed' && $rFixed <= 0) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_choose_dest')]);
        }

        $db = DatabaseFactory::get();
        $db->query("SELECT `stream_id`, `stream_display_name`, `stream_icon`, `category_array`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = 'live' AND `stream_id` IN (" . implode(',', $rIds) . ');', $rPid);
        $rById = [];
        foreach ($db->get_rows() as $r) {
            $rById[intval($r['stream_id'])] = $r;
        }

        $rBouquets = ProviderImportController::intList('bouquets');
        $rMap = ProviderImportController::existingCategories();
        $rCreated = [];
        $rRows = [];
        foreach ($rIds as $rId) {
            if (!isset($rById[$rId])) {
                continue;
            }
            $r = $rById[$rId];
            $c = self::catName($r['category_array']);
            if ($rMode === 'fixed') {
                $rCatId = $rFixed;
            } elseif ($c === '') {
                if ($rFallback <= 0) {
                    ProviderImportController::json(['result' => false, 'error' => self::t('err_fallback_live'), 'created_categories' => $rCreated]);
                }
                $rCatId = $rFallback;
            } else {
                $rCatId = ProviderImportController::resolveCategory($c, $rMap, $rCreated);
            }
            $rUrl = ProviderService::liveSourceUrl($rPid, $rId);
            if (!$rUrl) {
                continue;
            }
            $rRows[] = [
                'stream_source' => [$rUrl],
                'stream_icon' => (string) $r['stream_icon'],
                'stream_display_name' => (string) $r['stream_display_name'],
                'epg_lang' => null,
                'channel_id' => null,
                'tvg_id' => (string) $r['channel_id'],
                'epg_id' => 0,
                'bouquets' => $rBouquets,
                'category_id' => [$rCatId],
            ];
        }
        $rStatus = ProviderImportController::importRows($rRows, ProviderImportController::intList('servers'), ProviderImportController::intList('on_demand'));
        if ($rStatus !== null) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_import_failed', ['{status}' => (string) $rStatus]), 'created_categories' => $rCreated]);
        }
        ProviderImportController::json(['result' => true, 'imported' => count($rRows), 'created_categories' => $rCreated]);
    }

    /** stream_id => stream_type straight from the provider API (live / created_live), cached 15 min */
    private static function streamTypes(int $rPid): array {
        $rCache = (defined('TMP_PATH') ? TMP_PATH : '/home/xc_vm/tmp/') . 'providerimport_ptypes_' . $rPid . '.json';
        if (is_file($rCache) && filemtime($rCache) > time() - 900) {
            return json_decode((string) file_get_contents($rCache), true) ?: [];
        }
        $rData = self::api($rPid, 'get_live_streams');
        if (!is_array($rData)) {
            return [];
        }
        $rOut = [];
        foreach ($rData as $x) {
            if (isset($x['stream_id'])) {
                $rOut[intval($x['stream_id'])] = (string) ($x['stream_type'] ?? 'live');
            }
        }
        @file_put_contents($rCache, json_encode($rOut));
        return $rOut;
    }

    /** [base url, user, pass] of a provider */
    private static function creds(int $rPid): ?array {
        $db = DatabaseFactory::get();
        $db->query('SELECT `ip`, `port`, `username`, `password`, `ssl` FROM `providers` WHERE `id` = ?;', $rPid);
        $p = $db->get_raw_row();
        if (!$p) {
            return null;
        }
        $rHost = rtrim((string) preg_replace('#^https?://#i', '', (string) $p['ip']), '/');
        return [($p['ssl'] ? 'https' : 'http') . '://' . $rHost . ':' . intval($p['port']), (string) $p['username'], (string) $p['password']];
    }

    /** provider player_api call, decoded json or null (a refused login answers an object, not a list) */
    private static function api(int $rPid, string $rAction, array $rExtra = []) {
        $c = self::creds($rPid);
        if (!$c) {
            return null;
        }
        @set_time_limit(600);
        @ini_set('memory_limit', '2048M');
        $rUrl = $c[0] . '/player_api.php?' . http_build_query(['username' => $c[1], 'password' => $c[2], 'action' => $rAction] + $rExtra);
        $rCtx = stream_context_create(['http' => ['timeout' => 240, 'user_agent' => 'Mozilla/5.0'], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $rData = json_decode((string) @file_get_contents($rUrl, false, $rCtx), true);
        if ($rAction !== 'get_series_info' && is_array($rData) && !array_is_list($rData)) {
            return isset($rData['user_info']) ? false : array_values($rData);
        }
        return $rData;
    }

    private static function vodKind(): string {
        return RequestManager::get('kind') === 'series' ? 'series' : 'movie';
    }

    /** action=providerimport_pvodcats — provider movie/series categories with counts */
    public function apiVodCategories() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $k = self::vodKind();
        $rCats = self::api($rPid, $k === 'movie' ? 'get_vod_categories' : 'get_series_categories');
        if ($rCats === false) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_provider_auth')]);
        }
        if (!is_array($rCats)) {
            ProviderImportController::json(['result' => false, 'error' => self::t($k === 'movie' ? 'err_provider_movies' : 'err_provider_series')]);
        }
        // counts are a bonus: a huge catalogue that does not answer must not hide the categories
        $rItems = self::api($rPid, $k === 'movie' ? 'get_vod_streams' : 'get_series');
        $rCount = null;
        if (is_array($rItems)) {
            $rCount = [];
            foreach ($rItems as $x) {
                $rCount[(string) ($x['category_id'] ?? '')] = ($rCount[(string) ($x['category_id'] ?? '')] ?? 0) + 1;
            }
        }
        $rOut = [];
        foreach ($rCats as $c) {
            $rOut[] = ['id' => (string) ($c['category_id'] ?? ''), 'name' => (string) ($c['category_name'] ?? ''), 'count' => $rCount === null ? null : ($rCount[(string) ($c['category_id'] ?? '')] ?? 0)];
        }
        ProviderImportController::json(['result' => true, 'categories' => $rOut, 'total' => is_array($rItems) ? count($rItems) : null]);
    }

    /** action=providerimport_pvodlist — provider movies/series of a category (or search), with "already imported" */
    public function apiVodList() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $k = self::vodKind();
        $rCat = (string) RequestManager::get('category');
        $rSearch = ProviderImportController::norm(trim((string) RequestManager::get('search')));
        $rItems = self::api($rPid, $k === 'movie' ? 'get_vod_streams' : 'get_series', $rCat !== '' && $rCat !== '__all__' ? ['category_id' => $rCat] : []);
        if ($rItems === false) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_provider_auth')]);
        }
        if (!is_array($rItems)) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_provider')]);
        }
        $c = self::creds($rPid);
        $rHave = VodImport::existing();
        $rHide = !empty(RequestManager::get('hide_imported'));
        $rRows = [];
        $rTruncated = false;
        $rHidden = 0;
        foreach ($rItems as $x) {
            if (!is_array($x)) {
                continue;
            }
            $rName = (string) ($x['name'] ?? ($x['title'] ?? ''));
            if ($rSearch !== '' && strpos(ProviderImportController::norm($rName), $rSearch) === false) {
                continue;
            }
            $rTmdb = intval($x['tmdb'] ?? ($x['tmdb_id'] ?? 0)) ?: null;
            $rYear = intval(substr((string) ($x['year'] ?? ($x['releaseDate'] ?? ($x['release_date'] ?? ''))), 0, 4));
            if ($k === 'movie') {
                $rId = intval($x['stream_id'] ?? 0);
                $rUrl = $c[0] . '/movie/' . $c[1] . '/' . $c[2] . '/' . $rId . '.' . ($x['container_extension'] ?? 'mp4');
                $rEp = $k === 'movie' && VodImport::episodeNum($rName) && VodImport::seriesName($rName) !== null;
                $rImp = VodImport::isImported($rHave, $rEp ? 'episode' : 'movie', $rUrl, $rName, $rEp ? null : $rTmdb, $rYear);
                $rIcon = (string) ($x['stream_icon'] ?? '');
            } else {
                $rId = intval($x['series_id'] ?? 0);
                $rKey = VodImport::titleKey($rName);
                $rImp = ($rTmdb && isset($rHave['series_tmdb'][$rTmdb])) || ($rKey !== '' && isset($rHave['series_titles'][$rKey]));
                $rIcon = (string) ($x['cover'] ?? '');
            }
            if ($rId <= 0) {
                continue;
            }
            if ($rHide && $rImp) {
                $rHidden++;
                continue;
            }
            if (count($rRows) >= self::LIST_MAX) {
                $rTruncated = true;
                break;
            }
            $rRows[] = ['id' => $rId, 'name' => $rName, 'icon' => $rIcon, 'year' => $rYear ? (string) $rYear : '', 'category_id' => (string) ($x['category_id'] ?? ''), 'imported' => $rImp, 'episode' => !empty($rEp)];
        }
        ProviderImportController::json(['result' => true, 'rows' => $rRows, 'truncated' => $rTruncated, 'hidden' => $rHidden, 'job' => VodImport::job()]);
    }

    /** action=providerimport_pstatus — state of the last movies/series import sent from this module */
    public function apiStatus() {
        self::postOnly();
        self::json(['result' => true, 'job' => VodImport::job()]);
    }

    /** action=providerimport_pvodqueue — selected provider movies / whole series (all episodes) to the core VOD engine */
    public function apiVodQueue() {
        ProviderImportController::postOnly();
        $rPid = self::providerId();
        $k = self::vodKind();
        $rIds = array_flip(ProviderImportController::intList('ids'));
        $c = self::creds($rPid);
        $rCats = [];
        foreach ((array) (self::api($rPid, $k === 'movie' ? 'get_vod_categories' : 'get_series_categories') ?: []) as $x) {
            $rCats[(string) $x['category_id']] = (string) $x['category_name'];
        }
        $rList = [];
        if ($k === 'movie') {
            foreach ((array) (self::api($rPid, 'get_vod_streams') ?: []) as $x) {
                if (isset($rIds[intval($x['stream_id'])])) {
                    $rList[] = ['type' => VodImport::episodeNum((string) $x['name']) && VodImport::seriesName((string) $x['name']) !== null ? 'series' : 'movie', 'title' => (string) $x['name'], 'url' => $c[0] . '/movie/' . $c[1] . '/' . $c[2] . '/' . intval($x['stream_id']) . '.' . ($x['container_extension'] ?? 'mp4'), 'group' => $rCats[(string) ($x['category_id'] ?? '')] ?? ''];
                }
            }
        } else {
            if (count($rIds) > 200) {
                ProviderImportController::json(['result' => false, 'error' => self::t('err_series_max')]);
            }
            foreach (array_keys($rIds) as $rSid) {
                $rInfo = self::api($rPid, 'get_series_info', ['series_id' => $rSid]);
                $rName = (string) ($rInfo['info']['name'] ?? '');
                foreach ((array) ($rInfo['episodes'] ?? []) as $rSeason => $rEps) {
                    foreach ((array) $rEps as $e) {
                        $rS = intval($e['season'] ?? $rSeason);
                        $rE = intval($e['episode_num'] ?? 0);
                        if ($rName === '' || $rE <= 0) {
                            continue;
                        }
                        $rList[] = ['type' => 'series', 'title' => sprintf('%s S%02dE%02d', $rName, $rS, $rE), 'url' => $c[0] . '/series/' . $c[1] . '/' . $c[2] . '/' . intval($e['id']) . '.' . ($e['container_extension'] ?? 'mp4'), 'group' => $rCats[(string) ($rInfo['info']['category_id'] ?? '')] ?? ''];
                    }
                }
            }
        }
        [$rQueued, $rCreated] = VodImport::queue($rList, VodImport::options());
        $rEps = count(array_filter($rList, static fn($x) => $x['type'] === 'series'));
        ProviderImportController::json(['result' => true, 'queued' => $rQueued, 'movies' => count($rList) - $rEps, 'episodes' => $rEps, 'created_categories' => $rCreated]);
    }

    private static function providers(): array {
        $db = DatabaseFactory::get();
        $db->query("SELECT p.`id`, p.`name`, (SELECT COUNT(*) FROM `providers_streams` s WHERE s.`provider_id` = p.`id` AND s.`type` = 'live') AS `live` FROM `providers` p ORDER BY p.`name` ASC;");
        return $db->get_rows();
    }

    private static function providerId(): int {
        $rPid = intval(RequestManager::get('provider_id'));
        $db = DatabaseFactory::get();
        $db->query('SELECT `id` FROM `providers` WHERE `id` = ?;', $rPid);
        if ($rPid <= 0 || $db->num_rows() != 1) {
            ProviderImportController::json(['result' => false, 'error' => self::t('err_provider_unknown')]);
        }
        return $rPid;
    }

    private static function catName($rArray): string {
        $a = json_decode((string) $rArray, true);
        return is_array($a) ? trim((string) ($a[0] ?? '')) : '';
    }

    /** provider stream ids already used as a source of a live stream on this panel */
    private static function importedIds(int $rPid, array $rRows): array {
        if (!$rRows) {
            return [];
        }
        $rSample = ProviderService::liveSourceUrl($rPid, $rRows[0]['stream_id']);
        if (!$rSample || !preg_match('#^(.*/)' . $rRows[0]['stream_id'] . '(\.[a-z0-9]+)?$#i', $rSample, $m)) {
            return [];
        }
        $rPrefix = str_replace('https://', 'http://', $m[1]);
        $db = DatabaseFactory::get();
        $db->query('SELECT `stream_source` FROM `streams` WHERE `type` = 1;');
        $rOut = [];
        foreach ($db->get_rows() as $rRow) {
            foreach ((array) json_decode((string) $rRow['stream_source'], true) as $rUrl) {
                $rUrl = str_replace('https://', 'http://', (string) $rUrl);
                if (strncmp($rUrl, $rPrefix, strlen($rPrefix)) === 0 && preg_match('#^(\d+)#', substr($rUrl, strlen($rPrefix)), $n)) {
                    $rOut[intval($n[1])] = true;
                }
            }
        }
        return $rOut;
    }

    /** id of the live category named $rName (case/accent-insensitive), created if missing */
    public static function resolveCategory(string $rName, array &$rMap, array &$rCreated): int {
        $k = self::norm($rName);
        if (!isset($rMap[$k])) {
            $rRet = CategoryService::process(['category_type' => 'live', 'category_name' => $rName, 'parent_id' => 0]);
            if (($rRet['status'] ?? null) != STATUS_SUCCESS || empty($rRet['data']['insert_id'])) {
                self::json(['result' => false, 'error' => self::t('err_cat_create', ['{name}' => $rName]), 'created_categories' => $rCreated]);
            }
            $rMap[$k] = intval($rRet['data']['insert_id']);
            $rCreated[] = $rName;
        }
        return $rMap[$k];
    }

    /** core import of review rows; null on success, else the failing status */
    public static function importRows(array $rRows, array $rServers, array $rOnDemand): ?int {
        if (!$rRows) {
            return null;
        }
        $rTree = [['id' => 'source', 'parent' => '#']];
        foreach ($rServers as $rSid) {
            $rTree[] = ['id' => (string) $rSid, 'parent' => 'source'];
        }
        $rRet = StreamService::process(['review' => $rRows, 'server_tree_data' => json_encode($rTree), 'on_demand' => $rOnDemand]);
        return ($rRet['status'] ?? null) == STATUS_SUCCESS ? null : intval($rRet['status'] ?? 0);
    }

    public static function intList(string $rKey): array {
        return array_values(array_filter(array_map('intval', (array) (RequestManager::get($rKey) ?? []))));
    }

    /** normalized live category name => id (first by cat_order, then id) */
    public static function existingCategories(): array {
        $db = DatabaseFactory::get();
        $db->query("SELECT `id`, `category_name` FROM `streams_categories` WHERE `category_type` = 'live' ORDER BY `cat_order` ASC, `id` ASC;");
        $rMap = [];
        foreach ($db->get_rows() as $rRow) {
            $rMap[self::norm($rRow['category_name'])] ??= intval($rRow['id']);
        }
        return $rMap;
    }

    /** case/accent/space-insensitive key */
    public static function norm(string $s): string {
        if (class_exists('\Normalizer')) {
            $s = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($s)));
    }

    /** module text in the panel language (lang/<code>.ini, English when missing) */
    public static function t(string $k, array $r = []): string {
        return Translator::get('pi_' . $k, $r);
    }

    /** texts used by the page script */
    public static function jsStrings(): array {
        $rOut = [];
        foreach (['counts', 'bad_response', 'already_imported', 'new', 'badge_movie', 'badge_series', 'badge_tv', 'no_category', 'unknown_error', 'partial_done', 'channels_imported', 'created_cats', 'no_new_cat', 'import_done', 'error', 'no_cat_label', 'exists', 'choose_provider', 'truncated', 'types_unknown', 'select_one', 'confirm_import', 'preparing', 'preparing_series', 'vod_queued', 'all_categories', 'status_running', 'status_done', 'empty_hidden', 'empty_list', 'badge_episode', 'vod_split'] as $k) {
            $rOut[$k] = self::t($k);
        }
        return $rOut;
    }

    public static function postOnly(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            self::json(['result' => false, 'error' => 'Method Not Allowed']);
        }
    }

    public static function json(array $rData): void {
        header('Content-Type: application/json');
        echo json_encode($rData);
        exit();
    }
}
