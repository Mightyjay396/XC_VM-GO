<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\BlocklistDelta;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterCronJob — MAIN's cluster API housekeeping, every minute:
 *
 * - expired token epochs are deleted, which erases their `z`;
 * - the replay cache and single-use challenges past their 180 s go;
 * - enrolment codes nobody used, and decided requests after a day, go;
 * - the blocklist's change log keeps seven days (also with the API off);
 * - the liveness loop runs once (the signals daemon runs it every second).
 *
 * The crontab row (`cluster`, role `main`) is copied to load balancers with
 * the rest; there the job returns before touching anything, as the cluster
 * domain classes are not in the LB build.
 *
 * @package XC_VM_CLI_CronJobs
 */
class ClusterCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:cluster';
	}

	public function getDescription(): string {
		return 'Cron: cluster API housekeeping (expired epochs, nonces, enrolment codes)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!NodeRole::isMain()) {
			return 0;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			// The blocklist's change log is written either way; it is kept short.
			try {
				BlocklistDelta::prune();
			} catch (\Throwable) {
				// The next minute tries again.
			}
			return 0;
		}
		$this->setProcessTitle('XC_VM[Cluster]');
		$this->acquireCronLock();
		self::housekeep();
		@unlink($this->rIdentifier);
		return 0;
	}

	/** One pass; each step on its own, so one failing table does not stop the others. */
	public static function housekeep(): void {
		foreach ([
			'epochs' => static fn() => TokenService::prune(),
			'nonces' => static fn() => NonceStore::purge(),
			'enrol_codes' => static fn() => EnrolCodeService::prune(),
			'commands' => static fn() => CommandBus::prune(),
			'blocklist_changes' => static fn() => BlocklistDelta::prune(),
			// MAIN's old HTTP ports past their 7 days: release them in nginx.
			'endpoint' => static function () {
				if (ClusterEndpoint::prune(SettingsManager::getAll()) && defined('SERVER_ID')) {
					$rMain = ServerRepository::getAll(true)[SERVER_ID] ?? [];
					$rPorts = [];
					foreach (array_merge([intval($rMain['http_broadcast_port'] ?? 0)], explode(',', (string) ($rMain['http_ports_add'] ?? ''))) as $rPort) {
						if (is_numeric($rPort) && (int) $rPort > 0 && (int) $rPort <= 65535) {
							$rPorts[] = (int) $rPort;
						}
					}
					if ($rPorts !== []) {
						NodeActions::setPorts(SERVER_ID, 0, $rPorts, true);
					}
				}
			},
			// The signals daemon runs this every second; the minute is its fallback.
			'liveness' => static function () {
				if (LivenessService::tick(max(10, min(300, intval(SettingsManager::get('cluster_offline_after_sec') ?: 30)))) !== []) {
					ServerRepository::getAll(true);
				}
			},
		] as $rStep => $rRun) {
			try {
				$rRun();
			} catch (\Throwable $rE) {
				ClusterAudit::log('cron.error', null, ['step' => $rStep, 'error' => substr($rE->getMessage(), 0, 200)], 'cron');
			}
		}
	}
}
