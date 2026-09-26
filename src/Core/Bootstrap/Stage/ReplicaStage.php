<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Core\Init\LegacyInitializer;
use XcVm\Infrastructure\Bootstrap\DomainDatabaseWiring;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Boot from the node replica instead of MAIN's database (plan, section 10,
 * step 2): in place of DatabaseStage and LegacyCoreStage, in the CLI profile
 * and in WebApiBootstrap, for a node in mode 2, and for `cluster:apply` in
 * every mode (ReplicaBoot).
 *
 * It leaves what those two stages leave, from the replica's caches: the
 * global $db (a LazyDatabaseHandler, which opens nothing until a query
 * needs it), DatabaseFactory and the domain wiring, SERVER_ID, the settings
 * (SettingsManager, $rSettings, core.settings), the servers ($rServers,
 * core.servers), the request, the FFmpeg paths, core.config, and the
 * bouquets and categories caches (core.bouquets, core.categories). The
 * xc_vm crontab is regenerated only from jobs the replica owns.
 *
 * WHEN_READY (mode 2) boots as DatabaseStage and LegacyCoreStage do while no
 * apply has built the replica's settings and servers caches since the
 * reboot: until then there is nothing to boot from, and mode 2's refusal,
 * once it exists, turns that boot into the fail-closed answer.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 */
class ReplicaStage implements BootStageInterface {
	/** @var list<BootStageInterface> */
	private array $rFallback;

	/**
	 * @param bool $rCached WebApi's cached endpoints (as LegacyCoreStage)
	 * @param string $rWhen ReplicaBoot::WHEN_READY or ReplicaBoot::ALWAYS
	 * @param list<BootStageInterface>|null $rFallback tests: the stages run
	 *                                                while not ready
	 */
	public function __construct(private bool $rCached = false, private string $rWhen = ReplicaBoot::WHEN_READY, ?array $rFallback = null) {
		$this->rFallback = $rFallback ?? [new DatabaseStage(), new LegacyCoreStage($rCached)];
	}

	public function run(BootState $state): void {
		if ($state->coreReady) {
			return;
		}
		if ($this->rWhen !== ReplicaBoot::ALWAYS && !ReplicaBoot::ready()) {
			foreach ($this->rFallback as $rStage) {
				$rStage->run($state);
			}
			return;
		}

		global $db;

		$db = new LazyDatabaseHandler();
		DatabaseFactory::set($db);
		DomainDatabaseWiring::wire($db);
		ReplicaBoot::start();

		LegacyInitializer::initCore($this->rCached);

		$state->db = $db;
		$state->databaseReady = true;
		$state->coreReady = true;
		$state->replica = true;
	}
}
