<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
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
}
