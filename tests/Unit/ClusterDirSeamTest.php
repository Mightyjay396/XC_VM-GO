<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Domain\Cluster\ConnectionSnapshot;
use XcVm\Domain\Cluster\HeartbeatService;

/**
 * The test seams the cluster classes share as traits (DirSeam,
 * OptionalDirSeam, InstallBase): each class keeps its own directory, install
 * root and user, so moving one class's never moves another's.
 */
final class ClusterDirSeamTest extends TestCase {
	/** @var array<class-string, string|false|null> what the suite's bootstrap set, restored after */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach ([ReplicaEtagCache::class, ConnectAudit::class, SettingsAudit::class, StreamRuntime::class] as $rClass) {
			$this->rBefore[$rClass] = self::stored($rClass, 'rDir');
		}
	}

	protected function tearDown(): void {
		foreach ($this->rBefore as $rClass => $rDir) {
			$rClass::useDir($rDir);
		}
		EventSpool::useDir(null);
		ReplicaApply::useDir(null);
		ConnectionSnapshot::useDir(null);
		HeartbeatService::useDir(null);
		ClusterPool::useBase(null);
		ClusterNginxConfig::useBase(null);
	}

	private static function stored(string $rClass, string $rProperty): mixed {
		return (new \ReflectionProperty($rClass, $rProperty))->getValue();
	}

	private static function call(string $rClass, string $rMethod): mixed {
		return (new \ReflectionMethod($rClass, $rMethod))->invoke(null);
	}

	public function testEachClassKeepsItsOwnDirectory(): void {
		EventSpool::useDir('/t/spool/');
		ReplicaApply::useDir('/t/replica/');
		StreamRuntime::useDir('/t/runtime/');
		ConnectionSnapshot::useDir('/t/snapshots/');
		ReplicaEtagCache::useDir('/t/etag/');
		ConnectAudit::useDir('/t/connects/');
		SettingsAudit::useDir(false);
		HeartbeatService::useDir('/t/heartbeat/');
		$this->assertSame('/t/spool/', EventSpool::dir());
		$this->assertSame('/t/replica/', ReplicaApply::dir());
		$this->assertSame('/t/runtime/', StreamRuntime::dir());
		$this->assertSame('/t/snapshots/', self::call(ConnectionSnapshot::class, 'dir'));
		$this->assertSame('/t/etag/', ReplicaEtagCache::dir());
		$this->assertSame('/t/connects/', ConnectAudit::dir());
		$this->assertNull(SettingsAudit::dir(), 'false: none');
		$this->assertSame('/t/heartbeat/', self::call(HeartbeatService::class, 'dir'));
		EventSpool::useDir(null);
		$this->assertStringEndsWith('cluster/spool/', EventSpool::dir(), 'null restores the default');
		$this->assertSame('/t/replica/', ReplicaApply::dir(), 'and only for its own class');
		ReplicaApply::useDir(null);
		$this->assertSame(ReplicaApply::configDir() . 'cluster/replica/', ReplicaApply::dir());
	}

	public function testPrivateDirectoriesStayPrivate(): void {
		$this->assertTrue((new \ReflectionMethod(ConnectionSnapshot::class, 'dir'))->isPrivate());
		$this->assertTrue((new \ReflectionMethod(HeartbeatService::class, 'dir'))->isPrivate());
		$this->assertTrue((new \ReflectionMethod(EventSpool::class, 'dir'))->isPublic());
	}

	public function testStreamRuntimesUseDirStillForgetsWhatItRead(): void {
		(new \ReflectionProperty(StreamRuntime::class, 'rSeedFailed'))->setValue(null, 123);
		StreamRuntime::useDir('/t/runtime/');
		$this->assertNull(self::stored(StreamRuntime::class, 'rSeedFailed'));
	}

	public function testInstallRootAndUserArePerClass(): void {
		ClusterPool::useBase('/t/pool/', 'pool-user');
		ClusterNginxConfig::useBase('/t/nginx/', 'nginx-user');
		$this->assertSame('/t/pool/', self::call(ClusterPool::class, 'base'));
		$this->assertSame('/t/nginx/', self::call(ClusterNginxConfig::class, 'base'));
		$this->assertSame('pool-user', self::stored(ClusterPool::class, 'rUser'));
		$this->assertSame('nginx-user', self::stored(ClusterNginxConfig::class, 'rUser'));
		$this->assertFalse(self::call(ClusterPool::class, 'runsAsUser'));
		$rMe = function_exists('posix_getpwuid') ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
		ClusterNginxConfig::useBase('/t/nginx/', $rMe);
		$this->assertSame($rMe !== '', self::call(ClusterNginxConfig::class, 'runsAsUser'));
		$this->assertFalse(self::call(ClusterPool::class, 'runsAsUser'), 'the other class\'s user is its own');
		ClusterPool::useBase(null);
		$this->assertSame(defined('MAIN_HOME') ? (string) MAIN_HOME : null, self::call(ClusterPool::class, 'base'));
		$this->assertSame('xc_vm', self::stored(ClusterPool::class, 'rUser'));
		$this->assertSame('/t/nginx/', self::call(ClusterNginxConfig::class, 'base'));
	}
}
