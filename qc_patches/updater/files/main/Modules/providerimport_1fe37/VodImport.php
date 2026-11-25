<?php

namespace XcVm\Module\Providerimport;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Movies / series+episodes import through the core VOD engine (VodItemImporter: TMDB match, series, seasons, episodes). */
class VodImport {

    const TYPES = ['movie' => 1, 'series' => 2];

    /** "Show S01E02 ..." => "Show" (null when no episode marker) */
    public static function seriesName(string $rTitle): ?string {
        if (preg_match('/^(.*?)[\s._\-:|]*\b(S\d{1,2}\s*E\d{1,3}|\d{1,2}x\d{2,3})\b/i', $rTitle, $m)) {
            $n = trim($m[1], " \t-_.:|");
            return $n !== '' ? $n : null;
        }
        return null;
    }

    /** what the panel already has: sources by type, movie titles/tmdb, series titles/tmdb, episodes */
    public static function existing(): array {
        $db = DatabaseFactory::get();
        $x = ['urls' => [1 => [], 2 => [], 5 => []], 'movie_titles' => [], 'movie_title_year' => [], 'movie_tmdb' => [], 'series_titles' => [], 'series_tmdb' => [], 'episodes' => []];
        $db->query('SELECT `type`, `stream_source`, `stream_display_name`, `tmdb_id`, `year` FROM `streams` WHERE `type` IN (1, 2, 5);');
        foreach ($db->get_rows() as $r) {
            $t = intval($r['type']);
            foreach ((array) json_decode((string) $r['stream_source'], true) as $u) {
                $x['urls'][$t][self::urlKey((string) $u)] = true;
            }
            if ($t === 2) {
                $k = self::titleKey((string) $r['stream_display_name']);
                if ($k !== '') {
                    $x['movie_titles'][$k] = true;
                    $y = intval($r['year']) ?: self::year((string) $r['stream_display_name']);
                    if ($y) {
                        $x['movie_title_year'][$k . '|' . $y] = true;
                    }
                }
                if (intval($r['tmdb_id']) > 0) {
                    $x['movie_tmdb'][intval($r['tmdb_id'])] = true;
                }
            }
        }
        // only series that still have at least one episode whose stream exists
        $db->query('SELECT ss.`id`, ss.`title`, ss.`tmdb_id` FROM `streams_series` ss WHERE EXISTS (SELECT 1 FROM `streams_episodes` e JOIN `streams` s ON s.`id` = e.`stream_id` WHERE e.`series_id` = ss.`id`);');
        foreach ($db->get_rows() as $r) {
            $k = self::titleKey((string) $r['title']);
            if ($k !== '') {
                $x['series_titles'][$k] = intval($r['id']);
            }
            if (intval($r['tmdb_id']) > 0) {
                $x['series_tmdb'][intval($r['tmdb_id'])] = intval($r['id']);
            }
        }
        $db->query('SELECT e.`series_id`, e.`season_num`, e.`episode_num` FROM `streams_episodes` e JOIN `streams` s ON s.`id` = e.`stream_id`;');
        foreach ($db->get_rows() as $r) {
            $x['episodes'][intval($r['series_id']) . '_' . intval($r['season_num']) . '_' . intval($r['episode_num'])] = true;
        }
        return $x;
    }

    /** year written in a title, 0 when none */
    public static function year(string $t): int {
        if (preg_match('/\(((?:19|20)\d{2})\)/', $t, $m)) {
            return intval($m[1]);
        }
        return preg_match_all('/\b((?:19|20)\d{2})\b/', $t, $m) ? intval(end($m[1])) : 0;
    }

    public static function urlKey(string $u): string {
        return strtolower(preg_replace('#^https?://#i', '', trim($u)));
    }

    /** title without year / quality tags, for "already imported" matching */
    public static function titleKey(string $t): string {
        $t = preg_replace('/^[a-z]{2,3}\s*[|:\-]\s*/i', '', trim($t));
        $rKey = static fn(string $s): string => (string) preg_replace('/[^\p{L}\p{N}]+/u', '', ProviderImportController::norm($s));
        $k = $rKey((string) preg_replace('/\((19|20)\d{2}\)|\b(19|20)\d{2}\b|\b(4k|uhd|fhd|hd|sd|1080p|720p|2160p|multi|vf|vostfr|french)\b/i', ' ', $t));
        // a title that is only a year ("1917 (2019)") keeps its number
        return $k !== '' ? $k : $rKey((string) preg_replace('/\((19|20)\d{2}\)/', ' ', $t));
    }

    /** [season, episode] from "S01E02" / "1x02", or null */
    public static function episodeNum(string $rTitle): ?array {
        if (preg_match('/\bS(\d{1,2})\s*E(\d{1,3})\b/i', $rTitle, $m) || preg_match('/\b(\d{1,2})x(\d{2,3})\b/i', $rTitle, $m)) {
            return [intval($m[1]), intval($m[2])];
        }
        return null;
    }

    /** imported flag of one entry (kind live|247|movie|episode) */
    public static function isImported(array $x, string $rKind, string $rUrl, string $rTitle, ?int $rTmdb = null, int $rYear = 0): bool {
        $u = self::urlKey($rUrl);
        if ($rKind === 'live' || $rKind === '247') {
            return isset($x['urls'][1][$u]);
        }
        if ($rKind === 'movie') {
            if (isset($x['urls'][2][$u]) || ($rTmdb && isset($x['movie_tmdb'][$rTmdb]))) {
                return true;
            }
            // same title AND same year (a title alone matches remakes / other films)
            $k = self::titleKey($rTitle);
            $y = $rYear ?: self::year($rTitle);
            return $k !== '' && ($y ? isset($x['movie_title_year'][$k . '|' . $y]) : isset($x['movie_titles'][$k]));
        }
        if (isset($x['urls'][5][$u])) {
            return true;
        }
        $k = self::titleKey((string) self::seriesName($rTitle));
        $rSid = $k !== '' ? ($x['series_titles'][$k] ?? null) : null;
        $rNum = self::episodeNum($rTitle);
        return $rSid && $rNum && isset($x['episodes'][$rSid . '_' . $rNum[0] . '_' . $rNum[1]]);
    }

    /** options posted by the "Films et séries" form */
    public static function options(): array {
        return [
            'servers' => ProviderImportController::intList('vod_servers'),
            'bouquets' => ProviderImportController::intList('vod_bouquets'),
            'fallback' => ['movie' => intval(RequestManager::get('movie_fallback')), 'series' => intval(RequestManager::get('series_fallback'))],
            'create' => !empty(RequestManager::get('create_categories')),
            'direct' => RequestManager::get('vod_mode') !== 'store',
        ];
    }

    /**
     * Queue items [['type' => movie|series, 'title', 'url', 'group']] in ONE core batch.
     * Returns [queued, created categories] or exits with a json error.
     */
    public static function hasTmdbKey(): bool {
        return trim(SettingsManager::getString('tmdb_api_key')) !== '';
    }

    public static function queue(array $rList, array $o): array {
        if (!self::hasTmdbKey()) {
            ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t('err_tmdb_key')]);
        }
        if (self::running()) {
            ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t('err_running'), 'job' => self::job()]);
        }
        if (!$rList) {
            ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t('err_no_vod')]);
        }
        if (!$o['servers']) {
            ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t('err_vod_server')]);
        }
        foreach (['movie', 'series'] as $t) {
            if (!$o['create'] && $o['fallback'][$t] <= 0 && array_filter($rList, static fn($x) => $x['type'] === $t)) {
                ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t($t === 'movie' ? 'err_fallback_movie' : 'err_fallback_series')]);
            }
        }
        $rCreated = $o['create'] ? self::ensureGenreCategories() : [];
        self::seedDuplicateCaches();

        $rMaps = ['movie' => self::vodCategories('movie'), 'series' => self::vodCategories('series')];
        $rItems = [];
        foreach ($rList as $x) {
            $t = $x['type'];
            $rFb = $o['fallback'][$t] > 0 ? [$o['fallback'][$t]] : [];
            if ($o['create'] && trim((string) $x['group']) !== '') {
                $rFb = [self::vodCategory($t, trim((string) $x['group']), $rMaps[$t], $rCreated)];
            }
            $rExt = strtolower(pathinfo((string) parse_url($x['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
            $rItems[] = [
                'import' => true, 'type' => $t, 'title' => $x['title'], 'file' => $x['url'], 'subtitles' => [],
                'servers' => $o['servers'], 'fb_category_id' => $rFb, 'fb_bouquets' => $o['bouquets'],
                'disable_tmdb' => false, 'ignore_no_match' => true, 'bouquets' => $o['bouquets'], 'category_id' => [],
                'language' => SettingsManager::getString('tmdb_language'), 'read_native' => 0, 'movie_symlink' => 0,
                'remove_subtitles' => 0, 'direct_source' => $o['direct'] ? 1 : 0, 'direct_proxy' => 0, 'auto_encode' => false,
                'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false, 'transcode_profile_id' => 0,
                'target_container' => in_array($rExt, ProviderImportController::VOD_EXT, true) ? $rExt : 'mp4',
                'max_genres' => SettingsManager::getInt('max_genres'),
                // false + auto_upgrade false => a title already in the panel (same TMDB movie / episode) is skipped
                'duplicate_tmdb' => false,
            ];
        }
        $db = DatabaseFactory::get();
        $db->query('SELECT IFNULL(MAX(`id`), 0) AS `m` FROM `streams`;');
        $rFrom = intval(($db->get_rows()[0] ?? [])['m'] ?? 0);
        if (!VodItemImporter::queueBatch($rItems)) {
            ProviderImportController::json(['result' => false, 'error' => ProviderImportController::t('err_vod_engine')]);
        }
        @file_put_contents(self::jobFile(), json_encode(['started' => time(), 'total' => count($rItems), 'from_id' => $rFrom]));
        return [count($rItems), $rCreated];
    }

    private static function jobFile(): string {
        return (defined('TMP_PATH') ? TMP_PATH : '/home/xc_vm/tmp/') . 'providerimport_job.json';
    }

    /** a core VOD batch is still being processed (its file is deleted when it ends) */
    public static function running(): bool {
        return (bool) glob(WATCH_TMP_PATH . 'import_*.jsonl');
    }

    /** last batch sent from this module: total, movies/episodes created since, running or not */
    public static function job(): ?array {
        $j = is_file(self::jobFile()) ? json_decode((string) file_get_contents(self::jobFile()), true) : null;
        if (!is_array($j)) {
            return null;
        }
        $db = DatabaseFactory::get();
        $db->query('SELECT COUNT(*) AS `n` FROM `streams` WHERE `id` > ? AND `type` IN (2, 5);', intval($j['from_id']));
        $rCreated = intval(($db->get_rows()[0] ?? [])['n'] ?? 0);
        return ['running' => self::running(), 'total' => intval($j['total']), 'created' => min($rCreated, intval($j['total'])), 'started' => intval($j['started'])];
    }

    /** genres of the TMDB mapping whose category is missing get a category of that name (mapping saved) */
    private static function ensureGenreCategories(): array {
        $db = DatabaseFactory::get();
        $rCreated = [];
        foreach (self::TYPES as $t => $rType) {
            $rCats = self::vodCategories($t);
            $rIds = array_flip(array_values($rCats));
            $db->query('SELECT `id`, `genre`, `category_id` FROM `watch_categories` WHERE `type` = ?;', $rType);
            foreach ($db->get_rows() as $w) {
                if (intval($w['category_id']) > 0 && isset($rIds[intval($w['category_id'])])) {
                    continue;
                }
                $rCat = self::vodCategory($t, (string) $w['genre'], $rCats, $rCreated);
                $rIds[$rCat] = true;
                $db->query('UPDATE `watch_categories` SET `category_id` = ? WHERE `id` = ?;', $rCat, $w['id']);
            }
        }
        return $rCreated;
    }

    /** id of the $t category named $rName (case/accent-insensitive), created if missing */
    private static function vodCategory(string $t, string $rName, array &$rMap, array &$rCreated): int {
        $k = ProviderImportController::norm($rName);
        if (!isset($rMap[$k])) {
            $db = DatabaseFactory::get();
            $db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES(?, ?, 0, 99, 0);", $t, $rName);
            $rMap[$k] = intval($db->last_insert_id());
            $rCreated[] = ProviderImportController::t($t === 'movie' ? 'cat_movies' : 'cat_series') . ' : ' . $rName;
        }
        return $rMap[$k];
    }

    private static function vodCategories(string $t): array {
        $db = DatabaseFactory::get();
        $db->query('SELECT `id`, `category_name` FROM `streams_categories` WHERE `category_type` = ? ORDER BY `cat_order`, `id`;', $t);
        $rMap = [];
        foreach ($db->get_rows() as $r) {
            $rMap[ProviderImportController::norm($r['category_name'])] ??= intval($r['id']);
        }
        return $rMap;
    }

    /** the core duplicate check reads WATCH_TMP_PATH caches: fill them from the movies / episodes already in the panel */
    private static function seedDuplicateCaches(): void {
        $db = DatabaseFactory::get();
        @mkdir(WATCH_TMP_PATH, 0755, true);
        // rebuilt from the database each time: a title deleted from the panel can be imported again
        foreach (array_merge(glob(WATCH_TMP_PATH . 'movie_*.cache') ?: [], glob(WATCH_TMP_PATH . 'series_*.cache') ?: []) as $f) {
            @unlink($f);
        }
        $db->query('SELECT `id`, `tmdb_id`, `stream_source` FROM `streams` WHERE `type` = 2 AND `tmdb_id` > 0;');
        foreach ($db->get_rows() as $r) {
            file_put_contents(WATCH_TMP_PATH . 'movie_' . intval($r['tmdb_id']) . '.cache', json_encode(['id' => intval($r['id']), 'source' => (json_decode((string) $r['stream_source'], true) ?: [''])[0]]));
        }
        $db->query('SELECT ss.`tmdb_id`, e.`season_num`, e.`episode_num`, s.`id`, s.`stream_source` FROM `streams_episodes` e JOIN `streams_series` ss ON ss.`id` = e.`series_id` JOIN `streams` s ON s.`id` = e.`stream_id` WHERE ss.`tmdb_id` > 0;');
        $rSeries = [];
        foreach ($db->get_rows() as $r) {
            $rSeries[intval($r['tmdb_id'])][intval($r['season_num']) . '_' . intval($r['episode_num'])] = ['id' => intval($r['id']), 'source' => (json_decode((string) $r['stream_source'], true) ?: [''])[0]];
        }
        foreach ($rSeries as $rTmdb => $rEps) {
            file_put_contents(WATCH_TMP_PATH . 'series_' . $rTmdb . '.cache', json_encode($rEps));
        }
    }
}
