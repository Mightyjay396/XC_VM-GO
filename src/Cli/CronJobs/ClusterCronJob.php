<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Domain\Cluster\TokenService;

/**
 * ClusterCronJob — MAIN's cluster API housekeeping, every minute:
 *
 * - expired token epochs are deleted, which erases their `z`;
 * - the replay cache and single-use challenges past their 180 s go;
 * - enrolment codes nobody used, and decided requests after a day, go.
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
		if (!NodeRole::isMain() || empty(SettingsManager::get('cluster_api_enabled'))) {
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
		] as $rStep => $rRun) {
			try {
				$rRun();
			} catch (\Throwable $rE) {
				ClusterAudit::log('cron.error', null, ['step' => $rStep, 'error' => substr($rE->getMessage(), 0, 200)], 'cron');
			}
		}
	}
}
