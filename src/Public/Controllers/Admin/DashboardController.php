<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Reference\GeoReference;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * DashboardController — Dashboard page.
 *
 * Complex data-prep: theme colours, connection map queries, server stats.
 * Dashboard has NO PageAuthorization::checkPermissions() — it uses server_id validation instead.
 *
 * @renders Views/admin/dashboard.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DashboardController extends BaseAdminController {
	public function index() {
		global $db, $rUserInfo, $rServers;

		$rCountryCodes = GeoReference::countryCodes();

		// Theme colour map
		if (Theme::fromId($rUserInfo['theme'])->isDark()) {
			$rColours = [1 => ['secondary', '#7e8e9d', '#ffffff'], 2 => ['secondary', '#7e8e9d', '#ffffff'], 3 => ['secondary', '#7e8e9d', '#ffffff'], 4 => ['secondary', '#7e8e9d', '#ffffff']];
			$rColourMap = [['#7e8e9d', 'bg-map-dark-1'], ['#6c7b8a', 'bg-map-dark-2'], ['#5a6977', 'bg-map-dark-3'], ['#485765', 'bg-map-dark-4'], ['#374654', 'bg-map-dark-5'], ['#273643', 'bg-map-dark-6']];
		} else {
			$rColours = [1 => ['purple', '#675db7', '#675db7'], 2 => ['success', '#23b397', '#23b397'], 3 => ['pink', '#e36498', '#e36498'], 4 => ['info', '#56C3D6', '#56C3D6']];
			$rColourMap = [['#23b397', 'bg-success'], ['#56c2d6', 'bg-info'], ['#5089de', 'bg-primary'], ['#675db7', 'bg-purple'], ['#e36498', 'bg-pink'], ['#98a6ad', 'bg-secondary']];
		}

		// Server ID validation
		if (RequestManager::has('server_id') && !isset($rServers[RequestManager::get('server_id')])) {
			$this->redirect('dashboard');
			return;
		}

		// Connection map
		$rConnectionMap = [];
		$rConnectionCount = 0;

		if (RequestManager::has('server_id')) {
			$db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` WHERE (`server_id` = ? OR `proxy_id` = ?) GROUP BY `geoip_country_code` ORDER BY `count` DESC;', intval(RequestManager::get('server_id')), intval(RequestManager::get('server_id')));
		} else {
			$db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` GROUP BY `geoip_country_code` ORDER BY `count` DESC;');
		}

		if (0 < $db->num_rows()) {
			$i = 0;
			foreach ($db->get_rows() as $rRow) {
				if ($i < count($rColourMap)) {
					$rRow['colour'] = $rColourMap[$i];
				} else {
					$rRow['colour'] = $rColourMap[count($rColourMap) - 1];
				}
				if (isset($rCountryCodes[$rRow['geoip_country_code']])) {
					$rRow['name'] = $rCountryCodes[$rRow['geoip_country_code']];
				} else {
					$rRow['name'] = 'Unknown Country';
				}
				$rConnectionCount += $rRow['count'];
				$rConnectionMap[] = $rRow;
				$i++;
			}
		}

		// Server stats (when no server filter)
		$rServerStats = [];
		if (!RequestManager::has('server_id')) {
			$rLimit = 3600;
			$rTime = time();
			$rNearestRange = $rTime - $rLimit;
			$db->query('SELECT * FROM `servers_stats` WHERE `time` >= ? ORDER BY `time` ASC;', $rNearestRange);
			if (0 < $db->num_rows()) {
				foreach ($db->get_rows() as $rRow) {
					// {x: epoch-ms, y: cpu%} so the sparkline tooltip shows the
					// real sample time instead of a bare point index.
					$rServerStats[intval($rRow['server_id'])][] = [
						'x' => intval($rRow['time']) * 1000,
						'y' => floatval($rRow['cpu']),
					];
				}
			}
		}

		$rOrderedServers = $rServers;
		array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

		// Service-status checklist (prepared here so the view stays free of
		// filesystem / watchdog probes).
		$rStatusChecks = $this->buildStatusChecks($rOrderedServers);

		// The Bootstrap 5 dashboard renders CPU/network/connection charts with ApexCharts,
		// and (when enabled and there is data) a jsvectormap world map.
		$rVendors = ['apexcharts'];
		if (SettingsManager::get('save_closed_connection') && SettingsManager::get('dashboard_map') && $rConnectionCount > 0) {
			$rVendors[] = 'jsvectormap';
		}
		$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
			(array) ($GLOBALS['xmNewuiVendors'] ?? []),
			$rVendors
		)));

		$this->setTitle('Dashboard');
		$this->render('dashboard', ['rColours' => $rColours, 'rColourMap' => $rColourMap, 'rConnectionMap' => $rConnectionMap, 'rConnectionCount' => $rConnectionCount, 'rServerStats' => $rServerStats, 'rOrderedServers' => $rOrderedServers, 'rStatusChecks' => $rStatusChecks]);
	}

	/**
	 * Build the "Service Status" checklist: every probe is always listed with
	 * its state, so a healthy panel shows what was checked instead of nothing.
	 *
	 * @param array<int,array<string,mixed>> $orderedServers
	 * @return list<array{state:string,icon:string,title:string,detail:string,help:string}>
	 */
	private function buildStatusChecks(array $orderedServers): array {
		$bin = ['{bin}' => htmlspecialchars(defined('PHP_BIN') ? PHP_BIN : 'php')];
		$signals = CONFIG_PATH . 'signals.last';
		$now = time();

		return [
			self::serversCheck($orderedServers),
			self::schemaCheck((string) SettingsManager::get('status_uuid'), XC_VM_VERSION, $bin),
			self::cronCheck(file_exists($signals) ? filemtime($signals) : null, $now, $bin),
			self::fanoutCheck(FanoutMode::enabled(), $orderedServers, $now, $bin),
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $servers
	 * @return array{state:string,icon:string,title:string,detail:string,help:string}
	 */
	public static function serversCheck(array $servers): array {
		$enabled = array_filter($servers, fn($s) => !empty($s['enabled']));
		$offline = array_column(array_filter($enabled, fn($s) => empty($s['server_online'])), 'server_name');
		$total = count($enabled);
		$detail = Translator::get('dashboard_check_servers_ok', ['{online}' => (string) ($total - count($offline)), '{total}' => (string) $total]);

		return self::check($offline ? 'fail' : 'ok', 'tabler-server-2', 'dashboard_check_servers', self::withDown($detail, $offline));
	}

	/**
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string}
	 */
	public static function schemaCheck(string $statusUuid, string $version, array $bin): array {
		if ($statusUuid !== '' && $statusUuid === md5($version)) {
			return self::check('ok', 'tabler-database', 'dashboard_check_schema', Translator::get('dashboard_check_schema_ok', ['{version}' => $version]));
		}

		return self::check('warn', 'tabler-database', 'dashboard_check_schema', '', Translator::get('dashboard_status_db_incomplete_text', $bin));
	}

	/**
	 * Root crons touch config/signals.last each run; stale after 10 minutes.
	 *
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string}
	 */
	public static function cronCheck(?int $lastRun, int $now, array $bin): array {
		if ($lastRun === null) {
			return self::check('fail', 'tabler-clock-play', 'dashboard_check_crons', Translator::get('dashboard_check_crons_never'), Translator::get('dashboard_status_crons_text', $bin));
		}

		$ok = $now - $lastRun <= 600;
		$detail = Translator::get('dashboard_check_crons_ok', ['{ago}' => self::formatAgo($now - $lastRun)]);

		return self::check($ok ? 'ok' : 'fail', 'tabler-clock-play', 'dashboard_check_crons', $detail, $ok ? '' : Translator::get('dashboard_status_crons_text', $bin));
	}

	/**
	 * xc_fanout live-delivery daemon, judged only on servers whose watchdog
	 * reported in the last minute. Switched off by the admin → "off", not a failure.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @param array<string,string> $bin
	 * @return array{state:string,icon:string,title:string,detail:string,help:string}
	 */
	public static function fanoutCheck(bool $enabled, array $servers, int $now, array $bin): array {
		if (!$enabled) {
			return self::check('off', 'tabler-broadcast', 'dashboard_check_fanout', Translator::get('dashboard_check_fanout_disabled'));
		}

		$states = self::fanoutStates($servers, $now);
		if ($states === []) {
			return self::check('off', 'tabler-broadcast', 'dashboard_check_fanout', Translator::get('dashboard_check_fanout_nodata'));
		}

		$down = array_keys(array_filter($states, fn($running) => !$running));
		$detail = Translator::get('dashboard_check_fanout_ok', ['{running}' => (string) (count($states) - count($down)), '{total}' => (string) count($states)]);

		return self::check($down ? 'fail' : 'ok', 'tabler-broadcast', 'dashboard_check_fanout', self::withDown($detail, $down), $down ? Translator::get('dashboard_status_fanout_text', $bin) : '');
	}

	/**
	 * server name => fanout running, for servers that reported in the last minute.
	 *
	 * @param array<int,array<string,mixed>> $servers
	 * @return array<string,bool>
	 */
	private static function fanoutStates(array $servers, int $now): array {
		$states = [];
		foreach ($servers as $srv) {
			$wd = json_decode($srv['watchdog_data'] ?? '{}', true) ?: [];
			if ($now - intval($srv['last_check_ago'] ?? 0) < 60 && isset($wd['fanout']['running'])) {
				$states[(string) $srv['server_name']] = (bool) $wd['fanout']['running'];
			}
		}

		return $states;
	}

	/** @param list<string> $down */
	private static function withDown(string $detail, array $down): string {
		return $down ? $detail . ' · ' . Translator::get('dashboard_check_servers_down', ['{names}' => implode(', ', $down)]) : $detail;
	}

	/** @return array{state:string,icon:string,title:string,detail:string,help:string} */
	private static function check(string $state, string $icon, string $titleKey, string $detail, string $help = ''): array {
		return ['state' => $state, 'icon' => $icon, 'title' => Translator::get($titleKey), 'detail' => $detail, 'help' => $help];
	}

	private static function formatAgo(int $seconds): string {
		if ($seconds < 60) {
			return max(0, $seconds) . 's';
		}

		return $seconds < 3600 ? intdiv($seconds, 60) . ' min' : intdiv($seconds, 3600) . ' h';
	}
}
