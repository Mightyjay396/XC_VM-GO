<?php

namespace XcVm\Domain\Vod;

/**
 * TvdbFallback — when TMDB has no match for a Sonarr series folder ("[tvdbid-N]"),
 * build a TMDB-shaped show from TVDB data (Sonarr's public metadata server, no key)
 * so the normal watch-folder import creates the series and its episodes.
 */
class TvdbFallback {
	/** TVDB ids allowed to use the fallback; empty array = every series. */
	public const ALLOW = [321112, 272480, 298461];
	/** Same categories/bouquets as "Dans une galaxie près de chez vous" (user choice). */
	public const CATEGORIES = [182, 190, 186];
	public const BOUQUETS = [1];
	/** Fake TMDB id space for TVDB-only series (never collides with real TMDB ids). */
	public const ID_OFFSET = 900000000;

	public static function tvdbId($rPath) {
		if (preg_match_all('/tvdb(?:id)?\s*[-=:_ ]\s*(\d{1,9})/i', (string) $rPath, $rM) && !empty($rM[1])) {
			return intval(end($rM[1]));
		}
		return null;
	}

	/** @return array|null [client, show] usable in place of [$rTMDB, $rMatch]. */
	public static function match($rPath) {
		$rTVDB = self::tvdbId($rPath);
		if (!$rTVDB || (count(self::ALLOW) > 0 && !in_array($rTVDB, self::ALLOW, true))) {
			return null;
		}
		$rCtx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: XC_VM-TvdbFallback\r\nAccept: application/json\r\n"]]);
		$rData = json_decode((string) @file_get_contents('https://skyhook.sonarr.tv/v1/tvdb/shows/en/' . $rTVDB, false, $rCtx), true);
		if (empty($rData['title'])) {
			echo 'TVDB fallback: tvdb-' . $rTVDB . " not found\n";
			return null;
		}
		echo 'TVDB fallback: ' . $rData['title'] . ' (tvdb-' . $rTVDB . ")\n";
		// optional "<series dir>/.titles.json" {"S02E05": "Title"}: titles/episodes TVDB doesn't list
		$rTitles = [];
		if (preg_match('#^(.*\[tvdb(?:id)?-\d+\])/#i', (string) $rPath, $rDir) && is_file($rDir[1] . '/.titles.json')) {
			$rTitles = json_decode((string) file_get_contents($rDir[1] . '/.titles.json'), true) ?: [];
		}
		$rClient = new TvdbFallbackClient($rData, $rTitles);
		return [$rClient, $rClient->getTVShow(self::ID_OFFSET + $rTVDB)];
	}
}

class TvdbFallbackObject {
	private $rData;

	public function __construct(array $rData) {
		$this->rData = $rData;
	}

	public function get($rKey) {
		return $this->rData[$rKey] ?? null;
	}

	public function getJSON() {
		return json_encode($this->rData, JSON_UNESCAPED_UNICODE);
	}
}

class TvdbFallbackClient {
	private $rData;
	private $rTitles;

	public function __construct(array $rData, array $rTitles = []) {
		$this->rData = $rData;
		$this->rTitles = $rTitles;
	}

	public function getLang() {
		return 'fr';
	}

	private function image($rType) {
		foreach (($this->rData['images'] ?? []) as $rImage) {
			if (($rImage['coverType'] ?? '') === $rType) {
				return $rImage['url'];
			}
		}
		return '';
	}

	private function episodes() {
		$rList = [];
		foreach (($this->rData['episodes'] ?? []) as $rEp) {
			if (intval($rEp['seasonNumber'] ?? 0) > 0) {
				$rList[sprintf('S%02dE%02d', $rEp['seasonNumber'], $rEp['episodeNumber'])] = $rEp;
			}
		}
		foreach ($this->rTitles as $rKey => $rTitle) {
			if (preg_match('/^S(\d+)E(\d+)$/', $rKey, $rM)) {
				$rEp = $rList[$rKey] ?? ['seasonNumber' => intval($rM[1]), 'episodeNumber' => intval($rM[2])];
				if (in_array(strtoupper(trim((string) ($rEp['title'] ?? ''))), ['', 'TBA', 'TBD'], true)) {
					$rEp['title'] = $rTitle;
				}
				$rList[$rKey] = $rEp;
			}
		}
		ksort($rList);
		return array_values($rList);
	}

	public function getTVShow($rID, $rExtra = []) {
		$rPoster = $this->image('Poster');
		$rSeasons = [];
		foreach ($this->episodes() as $rEp) {
			$rNum = intval($rEp['seasonNumber']);
			if (!isset($rSeasons[$rNum])) {
				$rSeasons[$rNum] = ['air_date' => $rEp['airDate'] ?? null, 'episode_count' => 0, 'id' => $rNum, 'name' => 'Saison ' . $rNum,
					'overview' => '', 'season_number' => $rNum, 'vote_average' => 0, 'poster_path' => $rPoster];
			}
			$rSeasons[$rNum]['episode_count']++;
		}
		ksort($rSeasons);
		return new TvdbFallbackObject([
			'id' => intval($rID), 'name' => $this->rData['title'], 'original_name' => $this->rData['title'], 'original_language' => 'fr',
			'overview' => (string) ($this->rData['overview'] ?? ''), 'vote_average' => floatval($this->rData['rating']['value'] ?? 0),
			'first_air_date' => $this->rData['firstAired'] ?? null, 'poster_path' => $rPoster, 'backdrop_path' => $this->image('Fanart'),
			'seasons' => array_values($rSeasons), 'credits' => ['cast' => [], 'crew' => []], 'genres' => [],
			'episode_run_time' => [intval($this->rData['runtime'] ?? 0)],
		]);
	}

	public function getSeason($rID, $rSeason) {
		$rList = [];
		foreach ($this->episodes() as $rEp) {
			if (intval($rEp['seasonNumber']) !== intval($rSeason)) {
				continue;
			}
			$rTitle = trim((string) ($rEp['title'] ?? ''));
			$rList[] = ['id' => intval($rEp['tvdbId'] ?? 0), 'episode_number' => intval($rEp['episodeNumber']),
				'name' => in_array(strtoupper($rTitle), ['TBA', 'TBD'], true) ? '' : $rTitle, 'overview' => (string) ($rEp['overview'] ?? ''),
				'air_date' => $rEp['airDate'] ?? null, 'still_path' => (string) ($rEp['image'] ?? ''), 'vote_average' => 0];
		}
		return new TvdbFallbackObject(['season_number' => intval($rSeason), 'episodes' => $rList]);
	}
}
