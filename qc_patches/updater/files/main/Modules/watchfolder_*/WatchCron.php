<?php

namespace XcVm\Module\Watchfolder;

use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Process\Multithread;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Core\Events\EventDispatcher; // QC-VOD-SELFHEAL
use XcVm\Core\Events\Stream\StreamsChangedEvent; // QC-VOD-SELFHEAL
use XcVm\Domain\Stream\StreamProcess; // QC-VOD-SELFHEAL

/**
 * WatchCron — cron task for watch folders.
 *
 * This class implements the scheduled job that scans configured watch
 * directories (local or rclone), detects new media files, prepares
 * work items and dispatches core `vod_import_item` console jobs to import or
 * update streams and bouquets.
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

/**
 * Class WatchCron
 *
 * Provides utility methods and the entrypoint for the watch cron job.
 */
class WatchCron {
    use \XcVm\Infrastructure\Database\DatabaseAware;

    /** Extensions scanned by default when a folder has none configured. */
    private const DEFAULT_WATCH_EXTENSIONS = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'flv', 'wmv', 'mov', 'ts');

    /** Subtitle extensions auto_subtitles looks for next to a media file. */
    private const SUBTITLE_EXTENSIONS = array('srt', 'sub', 'sbv');

    /** A file counts as "done writing" and ready to process N seconds after its last change. */
    private const FILE_STABLE_SECONDS = 30;


    /**
        * Get bouquet by its ID.
        *
        * @param int $rID Bouquet identifier.
        * @return array|null Bouquet row array or null when not found.
     */
    public static function getBouquet($rID) {
        $db = self::db();
        $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
        if ($db->num_rows() == 1) {
            return $db->get_row();
        }
    }

    /**
        * Process bouquet files found in the temporary directory.
        *
        * Reads `.bouquet` JSON files from the configured temporary path and
        * merges their contents into the corresponding bouquets in the
        * database.
     */
    public static function checkBouquets() {
        $db = self::db();
        $rBouquetUpdates = array();
        $rBouquetFiles = glob(WATCH_TMP_PATH . '*.bouquet');
        foreach ($rBouquetFiles as $rBouquetFile) {
            $rBouquet = json_decode(file_get_contents($rBouquetFile), true);
            if (!isset($rBouquetUpdates[$rBouquet['bouquet_id']])) {
                $rBouquetUpdates[$rBouquet['bouquet_id']] = array('movie' => array(), 'series' => array());
            }
            $rBouquetUpdates[$rBouquet['bouquet_id']][$rBouquet['type']][] = $rBouquet['id'];
            unlink($rBouquetFile);
        }
        foreach ($rBouquetUpdates as $rBouquetID => $rBouquetData) {
            $rBouquet = self::getBouquet($rBouquetID);
            if ($rBouquet) {
                foreach (array('movie', 'series') as $rType) {
                    $rColumn = ($rType == 'movie') ? 'bouquet_movies' : 'bouquet_series';
                    $rChannels = json_decode($rBouquet[$rColumn], true);
                    foreach ($rBouquetData[$rType] as $rID) {
                        if (intval($rID) > 0 && !in_array($rID, $rChannels)) {
                            $rChannels[] = $rID;
                        }
                    }
                    $db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
                }
            }
        }
    }

    /**
     * Cleanup missing streams for a folder.
     *
     * Removes streams from the database when their source files no longer
     * exist in the scanned file list and the folder is configured with
     * `delete_missing` enabled.
     *
     * @param array $rFolderRow Watch folder row from DB.
     * @param array $rExistingFiles Array of found file paths for the folder.
     * @param int|null $rServerID The folder's server (null: this one).
     * @return void
     */
    public static function cleanupMissing($rFolderRow, $rExistingFiles, $rServerID = null) {
        $rServerID = intval($rServerID ?? SERVER_ID);
        $db = self::db();
        $rTypeMap = array('movie' => 2, 'series' => 5);
        $rType = $rTypeMap[$rFolderRow['type']] ?? 0;
        if (!$rType) return;
        $rExistingLookup = array_flip($rExistingFiles);
        $rDir = rtrim($rFolderRow['directory'], '/') . '/';
        $db->query('SELECT s.id, s.stream_source FROM streams s LEFT JOIN streams_servers ss ON ss.stream_id = s.id WHERE s.type = ? AND ss.server_id = ?', $rType, $rServerID);
        $rDeleted = 0;
        foreach ($db->get_rows() as $rStream) {
            $rSource = json_decode($rStream['stream_source'], true);
            if (!$rSource || empty($rSource[0])) continue;
            $rPrefix = 's:' . $rServerID . ':';
            if (substr($rSource[0], 0, strlen($rPrefix)) !== $rPrefix) continue;
            $rFilePath = substr($rSource[0], strlen($rPrefix));
            if ($rFilePath && substr($rFilePath, 0, strlen($rDir)) === $rDir && !isset($rExistingLookup[$rFilePath])) {
                StreamRepository::deleteStream($rStream['id'], $rServerID, true, true);
                $rDeleted++;
            }
        }
        if ($rDeleted > 0) {
            echo 'Deleted' . $rDeleted . ' missing from ' . $rFolderRow['directory'] . "\n";
        }
    }

    /**
     * Scan interval (seconds), parallel imports and files per folder per scan
     * (0 = no limit). The interval falls back to the original XUI's 3600; the
     * parallel imports are core's setting (Settings → VOD Import).
     *
     * @param array $rSettings
     * @return int[] [scan seconds, threads, max items]
     */
    public static function scanSettings(array $rSettings) {
        return array(intval($rSettings['scan_seconds'] ?? 0) ?: 3600, VodItemImporter::importThreads(), intval($rSettings['max_items'] ?? 0));
    }

    /**
     * The servers whose watch folders this one scans: its own and, on MAIN,
     * every load balancer enrolled in the cluster API (mode 1 or 2). Such a
     * node reads its settings from MAIN's replica, which never carries the
     * TMDb key, and in mode 2 cannot reach MAIN's database at all, so MAIN
     * lists its folders through the node's system API and imports for it
     * (scannedByMain() is the node's side of it).
     *
     * @return int[]
     */
    public static function scannedServers() {
        $rServers = array(intval(SERVER_ID));
        if (NodeRole::isMain() && class_exists(NodeRegistry::class)) {
            foreach (NodeRegistry::enrolled() as $rServerID => $rNode) {
                if (intval($rNode['mode'] ?? 0) >= 1) {
                    $rServers[] = intval($rServerID);
                }
            }
        }
        return array_values(array_unique($rServers));
    }

    /** Does MAIN scan this server's watch folders (a node in mode 1 or 2)? */
    public static function scannedByMain() {
        return !NodeRole::isMain() && intval(NodeFlows::current()['mode'] ?? 0) >= 1;
    }

    /**
     * Run the watch cron.
     *
     * Scans the watch folders of every server this one scans (scannedServers()),
     * prepares import jobs for newly discovered media files and dispatches
     * processing commands. When `$rForce` is provided, only the folder with
     * that ID is processed.
     *
     * @param int|false $rForce Folder ID to force-run, or false to run normal schedule.
     * @return void
     */
    public static function run($rForce) {
        $db = self::db();
        $rSettings = SettingsManager::getAll();
        list($rScanOffset, $rThreadCount, $rMaxFilesPerRun) = self::scanSettings($rSettings);
        if (count(glob(WATCH_TMP_PATH . '*.bouquet')) > 0) {
            self::checkBouquets();
        }
        $rServers = self::scannedServers();
        $rIn = implode(',', array_fill(0, count($rServers), '?'));
        if (!$rForce) {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` IN (" . $rIn . ") AND `active` = 1 AND (UNIX_TIMESTAMP() - `last_run` > ? OR `last_run` IS NULL) ORDER BY `id` ASC;", ...array_merge($rServers, array($rScanOffset)));
        } else {
            $db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' AND `server_id` IN (" . $rIn . ") AND `id` = ?;", ...array_merge($rServers, array($rForce)));
        }
        $rByServer = array();
        foreach ($db->get_rows() as $rRow) {
            $rByServer[intval($rRow['server_id'])][] = $rRow;
        }
        if (count($rByServer) > 0) {
            shell_exec('rm -f ' . WATCH_TMP_PATH . '*.wpid');
        }
        foreach ($rByServer as $rServerID => $rRows) {
            $rStreamDatabaseSet = self::buildCaches($rServerID);
            foreach ($rRows as $rRow) {
                self::scanFolder($rRow, $rServerID, $rStreamDatabaseSet, $rSettings, $rThreadCount, $rMaxFilesPerRun);
            }
        }
        self::sortBouquetsByYear();
    }

    /** Keep movies and series newest year first in every bouquet. */
    public static function sortBouquetsByYear() {
        $db = self::db();
        $rMeta = array('movies' => array(), 'series' => array());
        $db->query('SELECT `id`, `year`, `added` FROM `streams` WHERE `type` = 2;');
        foreach ($db->get_rows() as $rRow) {
            $rMeta['movies'][intval($rRow['id'])] = array(intval($rRow['year']), intval($rRow['added']));
        }
        $db->query('SELECT `id`, `year`, `last_modified` FROM `streams_series`;');
        foreach ($db->get_rows() as $rRow) {
            $rMeta['series'][intval($rRow['id'])] = array(intval($rRow['year']), intval($rRow['last_modified']));
        }
        $rSort = function (array $rIDs, array $rInfo) {
            $rIDs = array_values(array_unique(array_map('intval', $rIDs)));
            usort($rIDs, function ($a, $b) use ($rInfo) {
                $rA = $rInfo[$a] ?? array(0, 0);
                $rB = $rInfo[$b] ?? array(0, 0);
                return array($rB[0], $rB[1], $b) <=> array($rA[0], $rA[1], $a);
            });
            return $rIDs;
        };
        $db->query('SELECT `id`, `bouquet_movies`, `bouquet_series` FROM `bouquets`;');
        $rChanged = 0;
        foreach ($db->get_rows() as $rBouquet) {
            $rMoviesIn = array_map('intval', json_decode((string) $rBouquet['bouquet_movies'], true) ?: array());
            $rSeriesIn = array_map('intval', json_decode((string) $rBouquet['bouquet_series'], true) ?: array());
            $rMoviesOut = $rSort($rMoviesIn, $rMeta['movies']);
            $rSeriesOut = $rSort($rSeriesIn, $rMeta['series']);
            if ($rMoviesIn !== $rMoviesOut || $rSeriesIn !== $rSeriesOut) {
                $db->query('UPDATE `bouquets` SET `bouquet_movies` = ?, `bouquet_series` = ? WHERE `id` = ?;', json_encode($rMoviesOut), json_encode($rSeriesOut), intval($rBouquet['id']));
                $rChanged++;
            }
        }
        if ($rChanged > 0) {
            echo 'Sorted movies/series newest year first in ' . $rChanged . ' bouquet(s)' . "\n";
        }
    }

    /**
     * One server's import caches: the TMDb ids of its movies and episodes
     * (WATCH_TMP_PATH, read by the importer for duplicates and upgrades) and
     * the sources it already has, as a set.
     *
     * @return array<string, int> json_encode(stream_source) => index
     */
    private static function buildCaches($rServerID) {
        $db = self::db();
        $rSeriesTMDB = $rStreamDatabase = array();
        $rTMDBDatabase = array('movie' => array(), 'series' => array());
        echo 'Generating cache...' . "\n";
        $db->query('SELECT `id`, `tmdb_id` FROM `streams_series` WHERE `tmdb_id` IS NOT NULL AND `tmdb_id` > 0;');
        foreach ($db->get_rows() as $rRow) {
            $rSeriesTMDB[$rRow['id']] = $rRow['tmdb_id'];
        }
        $db->query('SELECT `streams`.`id`, `streams_episodes`.`series_id`, `streams_episodes`.`season_num`, `streams_episodes`.`episode_num`, `streams`.`stream_source` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams_servers`.`server_id` = ?;', $rServerID);
        foreach ($db->get_rows() as $rRow) {
            $rStreamDatabase[] = $rRow['stream_source'];
            $rTMDBID = $rSeriesTMDB[$rRow['series_id']] ?? null;
            if ($rTMDBID) {
                list($rSource) = json_decode($rRow['stream_source'], true);
                $rTMDBDatabase['series'][$rTMDBID][$rRow['season_num'] . '_' . $rRow['episode_num']] = array('id' => $rRow['id'], 'source' => $rSource);
            }
        }
        $db->query('SELECT `streams`.`id`, `streams`.`stream_source`, `streams`.`movie_properties` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 2 AND `streams_servers`.`server_id` = ?;', $rServerID);
        foreach ($db->get_rows() as $rRow) {
            $rStreamDatabase[] = $rRow['stream_source'];
            $rTMDBID = (json_decode($rRow['movie_properties'], true)['tmdb_id'] ?? null) ?: null;
            if ($rTMDBID) {
                list($rSource) = json_decode($rRow['stream_source'], true);
                $rTMDBDatabase['movie'][$rTMDBID] = array('id' => $rRow['id'], 'source' => $rSource);
            }
        }
        exec('find ' . WATCH_TMP_PATH . ' -maxdepth 1 -name "*.cache" -print0 | xargs -0 -r rm');
        foreach ($rTMDBDatabase['series'] as $rTMDBID => $rData) {
            file_put_contents(WATCH_TMP_PATH . 'series_' . $rTMDBID . '.cache', json_encode($rData));
        }
        foreach ($rTMDBDatabase['movie'] as $rTMDBID => $rData) {
            file_put_contents(WATCH_TMP_PATH . 'movie_' . $rTMDBID . '.cache', json_encode($rData));
        }
        echo 'Finished generating cache!' . "\n";
        return array_flip($rStreamDatabase);
    }

    /**
     * A load balancer's folder through its system API: its media files as
     * path => modification time (null each, from a node that predates the
     * listing's `stat` option) and its subtitle files. Null when the node did
     * not answer or refused the folder (it lists only under its Scan Roots).
     *
     * @return array{files: array<string, int|null>, subtitles: list<string>}|null
     */
    private static function remoteListing($rServerID, array $rRow, array $rExtensions) {
        $rFiles = ApiClient::scanRecursive($rServerID, $rRow['directory'], $rExtensions, true);
        if (!is_array($rFiles) || ($rFiles['result'] ?? null) === false) {
            return null;
        }
        if (array_is_list($rFiles)) {
            $rFiles = array_fill_keys(array_map('strval', $rFiles), null);
        }
        $rSubtitles = array();
        if (isset($rRow['auto_subtitles'])) {
            $rFound = ApiClient::scanRecursive($rServerID, $rRow['directory'], self::SUBTITLE_EXTENSIONS);
            $rSubtitles = is_array($rFound) && array_is_list($rFound) ? array_map('strval', $rFound) : array();
        }
        return array('files' => $rFiles, 'subtitles' => $rSubtitles);
    }

    /**
     * The files of a folder listed without times that have now been seen for
     * FILE_STABLE_SECONDS: the stand-in for "not written to lately" on a node
     * whose listing has no times. Kept per folder in WATCH_TMP_PATH; a file no
     * longer listed is forgotten.
     *
     * @param string[] $rFiles
     * @return array<string, true>
     */
    public static function settled($rFolderID, array $rFiles, $rNow) {
        $rPath = WATCH_TMP_PATH . 'seen_' . intval($rFolderID) . '.json';
        $rSeen = json_decode((string) @file_get_contents($rPath), true);
        $rSeen = is_array($rSeen) ? $rSeen : array();
        $rNext = $rOut = array();
        foreach ($rFiles as $rFile) {
            $rNext[$rFile] = intval($rSeen[$rFile] ?? $rNow);
            if ($rNow - $rNext[$rFile] >= self::FILE_STABLE_SECONDS) {
                $rOut[$rFile] = true;
            }
        }
        file_put_contents($rPath, json_encode($rNext, JSON_UNESCAPED_UNICODE));
        return $rOut;
    }

    /**
     * The `find` that lists a folder's files with one of $rExtensions (every
     * file when none is given). The folder is one argument whatever it holds:
     * it stood between double quotes through escapeshellcmd(), which leaves
     * paired quotes alone, so a path with two of them added arguments to find
     * (-delete, -exec). An extension is letters and digits, or matches
     * nothing, and a folder that is not an absolute path lists nothing (one
     * starting with `-` would be read as an option).
     *
     * @param list<string> $rExtensions
     */
    public static function findCommand(string $rDirectory, array $rExtensions): string {
        if (substr($rDirectory, 0, 1) !== '/') {
            return '/usr/bin/find /dev/null -false';
        }
        $rCommand = '/usr/bin/find ' . escapeshellarg($rDirectory);
        if (count($rExtensions) == 0) {
            return $rCommand;
        }
        $rValid = array_values(array_filter(array_map('strval', $rExtensions), static fn(string $rExtension): bool => (bool) preg_match('/^[A-Za-z0-9]{1,16}\z/', $rExtension)));
        if (count($rValid) == 0) {
            return $rCommand . ' -false';
        }
        return $rCommand . ' -regex ' . escapeshellarg('.*\.\(' . implode('\|', $rValid) . '\)');
    }

    /** Scan one folder of $rServerID and import its new files. */
    private static function scanFolder(array $rRow, $rServerID, array $rStreamDatabaseSet, array $rSettings, $rThreadCount, $rMaxFilesPerRun) {
        $db = self::db();
        // Another server's folder: MAIN lists it through the node and imports for it.
        $rRemote = $rServerID !== intval(SERVER_ID);
        $db->query('UPDATE `watch_folders` SET `last_run` = UNIX_TIMESTAMP() WHERE `id` = ?;', $rRow['id']);
        $rExtensions = json_decode($rRow['allowed_extensions'], true);
        if (!$rExtensions) {
            $rExtensions = array();
        }
        if (count($rExtensions) == 0) {
            $rExtensions = self::DEFAULT_WATCH_EXTENSIONS;
        }
        $rSubtitles = $rFiles = array();
        $rTimes = null;
        if (0 < strlen($rRow['rclone_dir'])) {
            $rCommand = 'rclone --config "' . CONFIG_PATH . 'rclone.conf" lsjson ' . escapeshellarg($rRow['rclone_dir']) . ' -R --fast-list --files-only';
            exec($rCommand, $rRcloneFiles);
            $rData = implode(' ', $rRcloneFiles);
            if (substr($rData, 0, 1) !== '[') {
                $rData = '[' . explode('[', $rData, 2)[1];
            }
            $rRcloneFiles = json_decode($rData, true);
            foreach ($rRcloneFiles as $rFile) {
                $rFile['Path'] = rtrim($rRow['directory'], '/') . '/' . $rFile['Path'];
                if (count($rExtensions) == 0 || in_array(strtolower(pathinfo($rFile['Name'])['extension']), $rExtensions)) {
                    $rFiles[] = $rFile['Path'];
                }
                if (isset($rRow['auto_subtitles'])) {
                    if (in_array(strtolower(pathinfo($rFile['Path'])['extension']), self::SUBTITLE_EXTENSIONS)) {
                        $rSubtitles[] = $rFile['Path'];
                    }
                }
            }
        } elseif ($rRemote) {
            $rListing = self::remoteListing($rServerID, $rRow, $rExtensions);
            if ($rListing === null) {
                // Nothing listed is not "every file is gone": no cleanup either.
                echo 'Server #' . $rServerID . ' did not list ' . $rRow['directory'] . ' (offline, or outside its Scan Roots).' . "\n";
                return;
            }
            $rTimes = $rListing['files'];
            $rFiles = array_keys($rTimes);
            $rSubtitles = $rListing['subtitles'];
        } else {
            // The folder is one escapeshellarg()'d argument and each extension letters and digits (findCommand()).
            // nosemgrep: php.lang.security.exec-use.exec-use
            exec(self::findCommand((string) $rRow['directory'], $rExtensions), $rFiles);
            if (isset($rRow['auto_subtitles'])) {
                // nosemgrep: php.lang.security.exec-use.exec-use
                exec(self::findCommand((string) $rRow['directory'], self::SUBTITLE_EXTENSIONS), $rSubtitles);
            } else {
                $rSubtitles = array();
            }
        }
        $rNow = time();
        // QC-VOD-SELFHEAL: renamed/replaced files and never-started items heal here.
        self::retryUnstarted($rRow, $rServerID, $rFiles, $rNow);
        $rStreamDatabaseSet += self::healMoved($rRow, $rServerID, $rFiles);
        $rSettled = ($rTimes !== null && in_array(null, $rTimes, true)) ? self::settled($rRow['id'], $rFiles, $rNow) : array();
        $rThreadData = self::importItems($rRow, $rServerID, $rFiles, $rTimes, $rSettled, $rSubtitles, $rStreamDatabaseSet, $rSettings, $rMaxFilesPerRun, $rNow);
        if (count($rThreadData) > 0) {
            echo 'Scan complete! Adding ' . count($rThreadData) . ' files...' . "\n";
        }
        $rCommands = array();
        foreach ($rThreadData as $rData) {
            $rCommands[] = '/usr/bin/timeout 60 ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php vod_import_item ' . escapeshellarg(base64_encode(json_encode($rData, JSON_UNESCAPED_UNICODE)));
        }
        $db->close_mysql();
        Multithread::pool($rCommands, $rThreadCount);
        $db->db_connect();
        if (!empty($rRow['delete_missing'])) {
            self::cleanupMissing($rRow, $rFiles, $rServerID);
        }
        self::checkBouquets();
    }

    // QC-VOD-SELFHEAL: at most this many never-started items per folder per scan, each once per cooldown.
    private const HEAL_BATCH = 100;
    private const HEAL_COOLDOWN = 21600;

    /** A stream's path on $rServerID (`s:<server>:<path>`), null for any other source. */
    private static function serverPath($rSource, $rServerID) {
        $rList = json_decode((string) $rSource, true);
        $rPrefix = 's:' . $rServerID . ':';
        $rFirst = is_array($rList) ? (string) ($rList[0] ?? '') : '';
        return strpos($rFirst, $rPrefix) === 0 ? substr($rFirst, strlen($rPrefix)) : null;
    }

    /** A release's name up to its quality: "Show - S01E02 - ", "Title (Year) [", else "name." (another container). */
    private static function releaseKey($rPath) {
        $rBase = basename($rPath);
        $rParts = explode(' - ', $rBase);
        if (count($rParts) >= 3 && preg_match('/^S\d+E\d+/i', $rParts[1])) {
            return $rParts[0] . ' - ' . $rParts[1] . ' - ';
        }
        $rPos = strpos($rBase, ' [');
        return $rPos !== false ? substr($rBase, 0, $rPos + 2) : pathinfo($rBase, PATHINFO_FILENAME) . '.';
    }

    /**
     * Radarr/Sonarr rename or replace a file (upgrade): the stream kept the old
     * path, its link dangled and it played the offline video, and the new file
     * was only an upgrade when both could be compared. The new file is the one
     * file of the same folder with the same release key that no stream has: the
     * stream takes it, its row starts over and it starts again on its server.
     *
     * @return array<string, int> the new sources, keyed as importItems() looks them up
     */
    private static function healMoved(array $rRow, $rServerID, array $rFiles) {
        $rType = array('movie' => 2, 'series' => 5)[$rRow['type']] ?? 0;
        if (!$rType || count($rFiles) == 0) {
            return array();
        }
        $db = self::db();
        $rDir = rtrim($rRow['directory'], '/') . '/';
        $rListed = array_flip($rFiles);
        $rUsed = $rMissing = array();
        $db->query('SELECT s.id, s.stream_source FROM `streams` s INNER JOIN `streams_servers` ss ON ss.stream_id = s.id AND ss.server_id = ? WHERE s.type = ?;', $rServerID, $rType);
        foreach ($db->get_rows() as $rStream) {
            $rPath = self::serverPath($rStream['stream_source'], $rServerID);
            if ($rPath === null || strpos($rPath, $rDir) !== 0) {
                continue;
            }
            if (isset($rListed[$rPath])) {
                $rUsed[$rPath] = true;
            } else {
                $rMissing[intval($rStream['id'])] = $rPath;
            }
        }
        // A listing that lost much of the folder (disk not mounted, node restarting) moves nothing.
        if (count($rMissing) == 0 || count($rMissing) > max(500, count($rUsed) / 10)) {
            if (count($rMissing) > 0) {
                echo 'Self-heal skipped: ' . count($rMissing) . ' sources missing from ' . $rDir . "\n";
            }
            return array();
        }
        $rFree = array();
        foreach ($rFiles as $rFile) {
            if (!isset($rUsed[$rFile])) {
                $rFree[dirname($rFile)][$rFile] = true;
            }
        }
        $rNew = $rIDs = $rChanged = array();
        foreach ($rMissing as $rID => $rPath) {
            $rKey = self::releaseKey($rPath);
            $rMatches = array();
            foreach (array_keys($rFree[dirname($rPath)] ?? array()) as $rFile) {
                if (strpos(basename($rFile), $rKey) === 0) {
                    $rMatches[] = $rFile;
                }
            }
            if (count($rMatches) != 1) {
                continue;
            }
            $rFile = $rMatches[0];
            unset($rFree[dirname($rPath)][$rFile]);
            $rSource = json_encode(array('s:' . $rServerID . ':' . $rFile), JSON_UNESCAPED_UNICODE);
            $rExt = strtolower(pathinfo($rFile, PATHINFO_EXTENSION));
            $db->query('UPDATE `streams` SET `stream_source` = ?, `target_container` = ? WHERE `id` = ?;', $rSource, $rExt, $rID);
            // Same container: the node relinks it itself (cron:vod self-heal) once its replica has the new
            // source; a start queued now could run on the old one. Another container needs a new link: started.
            if ($rExt !== strtolower(pathinfo($rPath, PATHINFO_EXTENSION))) {
                $db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `compatible` = 0, `video_codec` = NULL, `audio_codec` = NULL, `resolution` = NULL, `stream_status` = 0 WHERE `stream_id` = ? AND `server_id` = ?;', $rID, $rServerID);
                $rIDs[] = $rID;
            }
            $rNew[$rSource] = 0;
            $rChanged[] = $rID;
            echo 'Self-heal #' . $rID . ': ' . basename($rPath) . ' -> ' . basename($rFile) . "\n";
        }
        if (count($rChanged) > 0) {
            EventDispatcher::dispatch(new StreamsChangedEvent($rChanged));
        }
        if (count($rIDs) > 0) {
            StreamProcess::queueMovies($rIDs, $rServerID);
        }
        return $rNew;
    }

    /**
     * Items of this folder whose start never happened (pid NULL: a queued start
     * lost while the node was busy) are queued again, HEAL_BATCH at a time and
     * each at most once per HEAL_COOLDOWN, when their file is listed.
     */
    private static function retryUnstarted(array $rRow, $rServerID, array $rFiles, $rNow) {
        $rType = array('movie' => 2, 'series' => 5)[$rRow['type']] ?? 0;
        if (!$rType || count($rFiles) == 0) {
            return;
        }
        $db = self::db();
        $rState = WATCH_TMP_PATH . 'retry_' . intval($rRow['id']) . '.json';
        $rTried = json_decode((string) @file_get_contents($rState), true);
        $rTried = array_filter(is_array($rTried) ? $rTried : array(), function ($rAt) use ($rNow) {
            return $rNow - intval($rAt) < self::HEAL_COOLDOWN;
        });
        $rListed = array_flip($rFiles);
        $rIDs = array();
        $db->query('SELECT s.id, s.stream_source FROM `streams` s INNER JOIN `streams_servers` ss ON ss.stream_id = s.id AND ss.server_id = ? WHERE s.type = ? AND ss.pid IS NULL AND s.direct_source = 0;', $rServerID, $rType);
        foreach ($db->get_rows() as $rStream) {
            $rID = intval($rStream['id']);
            $rPath = self::serverPath($rStream['stream_source'], $rServerID);
            if ($rPath === null || !isset($rListed[$rPath]) || isset($rTried[$rID])) {
                continue;
            }
            $rTried[$rID] = $rNow;
            $rIDs[] = $rID;
            if (count($rIDs) >= self::HEAL_BATCH) {
                break;
            }
        }
        file_put_contents($rState, json_encode($rTried));
        if (count($rIDs) > 0) {
            StreamProcess::queueMovies($rIDs, $rServerID);
            echo 'Self-heal: queued ' . count($rIDs) . ' never-started items of ' . $rRow['directory'] . "\n";
        }
    }

    /**
     * The import jobs for a folder's new files: those settled (not written to
     * for FILE_STABLE_SECONDS, by their time, or by settled() when the listing
     * had none) and not yet a stream of $rServerID. Another server's file is
     * imported for it (import mode, `s:<server>:<path>`, no ffprobe here).
     *
     * @param string[] $rFiles
     * @param array<string, int|null>|null $rTimes a remote listing's times; null: stat the files here
     * @param array<string, true> $rSettled
     * @param string[] $rSubtitles
     * @return list<array<string, mixed>>
     */
    public static function importItems(array $rRow, $rServerID, array $rFiles, $rTimes, array $rSettled, array $rSubtitles, array $rStreamDatabaseSet, array $rSettings, $rMaxFilesPerRun, $rNow) {
        $rRemote = $rServerID !== intval(SERVER_ID);
        $rSubtitlesSet = array_flip($rSubtitles);
        $rThreadData = array();
        foreach ($rFiles as $rFile) {
            if ($rTimes !== null) {
                $rStable = $rTimes[$rFile] !== null ? $rNow - $rTimes[$rFile] >= self::FILE_STABLE_SECONDS : isset($rSettled[$rFile]);
            } else {
                $rStable = $rNow - (int) @filemtime($rFile) >= self::FILE_STABLE_SECONDS;
            }
            if ($rStable) {
                if (!isset($rStreamDatabaseSet[json_encode(array('s:' . $rServerID . ':' . $rFile), JSON_UNESCAPED_UNICODE)])) {
                    $rPathInfo = pathinfo($rFile);
                    $rSubtitleData = array();
                    if (isset($rRow['auto_subtitles'])) {
                        foreach (self::SUBTITLE_EXTENSIONS as $rExt) {
                            $rSubtitle = $rPathInfo['dirname'] . '/' . $rPathInfo['filename'] . '.' . $rExt;
                            if (isset($rSubtitlesSet[$rSubtitle])) {
                                $rSubtitleData = array('files' => array($rSubtitle), 'names' => array('Subtitles'), 'charset' => array('UTF-8'), 'location' => $rServerID);
                                break;
                            }
                        }
                    }
                    $rData = array('type' => $rRow['type'], 'directory' => $rRow['directory'], 'file' => $rFile, 'subtitles' => $rSubtitleData, 'category_id' => $rRow['category_id'], 'bouquets' => $rRow['bouquets'], 'disable_tmdb' => $rRow['disable_tmdb'], 'ignore_no_match' => $rRow['ignore_no_match'], 'fb_bouquets' => $rRow['fb_bouquets'], 'fb_category_id' => $rRow['fb_category_id'], 'language' => $rRow['language'], 'read_native' => $rRow['read_native'], 'movie_symlink' => $rRow['movie_symlink'], 'remove_subtitles' => $rRow['remove_subtitles'], 'auto_encode' => $rRow['auto_encode'], 'auto_upgrade' => $rRow['auto_upgrade'], 'fallback_title' => $rRow['fallback_title'], 'ffprobe_input' => $rRow['ffprobe_input'], 'extract_metadata' => $rRow['extract_metadata'], 'transcode_profile_id' => $rRow['transcode_profile_id'], 'max_genres' => intval($rSettings['max_genres']), 'duplicate_tmdb' => $rRow['duplicate_tmdb'], 'target_container' => $rRow['target_container'], 'alternative_titles' => $rSettings['alternative_titles'], 'fallback_parser' => $rSettings['fallback_parser']);
                    if ($rRemote) {
                        // Imported here for that server, as Movies → Import does: the stream
                        // points at its file and runs there. ffprobe would run here, where the
                        // file is not, so the match is on the file name: `title`, the only
                        // name an import is matched on. A file on a server's disk is no
                        // direct source.
                        $rData = array_merge($rData, array('import' => true, 'file' => 's:' . $rServerID . ':' . $rFile, 'title' => $rPathInfo['filename'], 'servers' => array($rServerID), 'direct_source' => 0, 'direct_proxy' => 0, 'ffprobe_input' => 0, 'extract_metadata' => 0));
                    }
                    $rThreadData[] = $rData;
                    if (0 < $rMaxFilesPerRun && count($rThreadData) == $rMaxFilesPerRun) {
                        break;
                    }
                }
            }
        }
        return $rThreadData;
    }
}
