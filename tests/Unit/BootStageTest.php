<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\Stage\AdminShutdownStage;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\ProcessTitleStage;
use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Bootstrap\Stage\StatusConstantsStage;
use XcVm\Core\Bootstrap\Stage\WebApiLoggerStage;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Infrastructure\Database\DatabaseFactory;

require_once __DIR__ . '/../Support/TestDb.php';

/**
 * Isolated coverage for the boot pieces that do not need a DB connection or the
 * config extension: BootState, ContainerPopulateStage's no-DB path,
 * HealthCheckStage, and the DatabaseFactory::reset() test seam.
 */
final class BootStageTest extends TestCase {
	protected function tearDown(): void {
		ServiceContainer::resetInstance();
		EventDispatcher::resetInstance();
		DatabaseFactory::reset();
	}

	private function freshState(): BootState {
		return new BootState(BootContext::Minimal, [], ServiceContainer::getInstance());
	}

	public function testBootStateReadinessDefaultsFalse(): void {
		$state = $this->freshState();

		$this->assertFalse($state->booted);
		$this->assertFalse($state->databaseReady);
		$this->assertFalse($state->coreReady);
		$this->assertFalse($state->redisReady);
		$this->assertNull($state->db);
		$this->assertSame(BootContext::Minimal, $state->context);
	}

	public function testBootStateIsCliUnderCliSapi(): void {
		// The test suite itself runs under the CLI SAPI.
		$this->assertTrue($this->freshState()->isCli());
	}

	public function testContainerPopulateAlwaysRegistersEvents(): void {
		$state = $this->freshState();

		(new ContainerPopulateStage())->run($state);

		$this->assertTrue($state->container->has('events'));
		$this->assertInstanceOf(EventDispatcher::class, $state->container->get('events'));
	}

	public function testContainerPopulateSkipsDbWhenNotReady(): void {
		$state = $this->freshState();

		(new ContainerPopulateStage())->run($state);

		$this->assertFalse($state->container->has('db'));
		$this->assertFalse($state->container->has('settings'));
	}

	public function testHealthCheckPassesWhenEventsPresent(): void {
		$state = $this->freshState();
		$state->container->set('events', new EventDispatcher());

		(new HealthCheckStage())->run($state);

		$this->assertTrue(true); // reached without throwing
	}

	public function testHealthCheckThrowsWhenEventsMissing(): void {
		$state = $this->freshState();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('events');
		(new HealthCheckStage())->run($state);
	}

	public function testHealthCheckRequiresDbWhenDatabaseReady(): void {
		$state = $this->freshState();
		$state->container->set('events', new EventDispatcher());
		$state->databaseReady = true;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('db');
		(new HealthCheckStage())->run($state);
	}

	public function testDatabaseFactoryResetClearsRegistry(): void {
		$db = new TestDb();
		DatabaseFactory::set($db);
		$this->assertSame($db, DatabaseFactory::get());

		DatabaseFactory::reset();

		$this->assertNull(DatabaseFactory::get());
	}

	// ── HTTP-only stages self-skip under the CLI SAPI (the test runner) ──

	public function testSessionStageSkipsOnCli(): void {
		$state = $this->freshState();

		(new SessionStage())->run($state);

		// The early return happens before sessionStarted is set.
		$this->assertFalse($state->sessionStarted);
	}

	/** Flood/host guards are no-ops on CLI: they must neither exit nor throw. */
	public function testFloodAndHostStagesAreInertOnCli(): void {
		$this->expectNotToPerformAssertions();
		$state = $this->freshState();

		(new FloodProtectionStage())->run($state);
		(new HostVerificationStage())->run($state);
	}

	public function testProcessTitleStageRunsForEmptyAndNonEmptyNames(): void {
		$this->expectNotToPerformAssertions();
		$state = $this->freshState();

		(new ProcessTitleStage(''))->run($state);
		(new ProcessTitleStage('xcvm-unit-test'))->run($state);
	}

	public function testAdminShutdownStageRegistersWithoutError(): void {
		$this->expectNotToPerformAssertions();

		(new AdminShutdownStage())->run($this->freshState());
	}

	public function testStatusConstantsStageDefinesStatusCodes(): void {
		(new StatusConstantsStage())->run($this->freshState());

		$this->assertTrue(defined('STATUS_FAILURE'));
		$this->assertSame(0, STATUS_FAILURE);
	}

	/**
	 * Logger::init() registers global error/exception handlers as a side
	 * effect — restore the previous ones after the assertion so this test
	 * cannot change how errors in later tests of the suite are reported.
	 */
	public function testWebApiLoggerStageDefinesPhpErrorsAndInitsLogger(): void {
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm_weblogger_cache_' . bin2hex(random_bytes(4)) . '/');
		}
		if (!defined('LOGS_TMP_PATH')) {
			define('LOGS_TMP_PATH', sys_get_temp_dir() . '/xcvm_weblogger_logs_' . bin2hex(random_bytes(4)) . '/');
		}

		(new WebApiLoggerStage())->run($this->freshState());

		$this->assertTrue(defined('PHP_ERRORS'));

		restore_error_handler();
		restore_exception_handler();
	}
}
