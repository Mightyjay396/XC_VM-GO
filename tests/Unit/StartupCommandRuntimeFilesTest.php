<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StartupCommand;

/**
 * Coverage for the boot self-healing helpers extracted from StartupCommand:
 * restoring the executable bit on core scripts and regenerating missing
 * php-fpm pool configs from the template.
 */
final class StartupCommandRuntimeFilesTest extends TestCase {

	private string $dir;

	/**
	 * Call a private helper. Whether it is static is an implementation detail
	 * (Rector's LocallyCalledStaticMethodToNonStatic flips it), so bind an
	 * instance only when the method actually needs one.
	 */
	private function call(string $rMethod, ...$rArgs) {
		$rM = new ReflectionMethod(StartupCommand::class, $rMethod);
		$rM->setAccessible(true);
		return $rM->invoke($rM->isStatic() ? null : new StartupCommand(), ...$rArgs);
	}

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm_startup_' . uniqid() . '/';
		mkdir($this->dir . 'bin/php/etc', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testEnsureExecutableScriptsSetsBitAndSkipsMissing(): void {
		file_put_contents($this->dir . 'service', "#!/bin/sh\n");
		chmod($this->dir . 'service', 0644);

		$this->call('ensureExecutableScripts', ['service', 'does-not-exist'], $this->dir);

		$this->assertTrue(is_executable($this->dir . 'service'));
	}

	public function testEnsurePhpFpmPoolConfigsGeneratesOnlyMissing(): void {
		file_put_contents($this->dir . 'bin/php/etc/template', 'id=#ID# path=#PATH#');
		file_put_contents($this->dir . 'bin/php/etc/1.conf', 'KEEP'); // existing → untouched

		$this->call('ensurePhpFpmPoolConfigs', $this->dir);

		$this->assertSame('KEEP', file_get_contents($this->dir . 'bin/php/etc/1.conf'));
		$this->assertSame('id=2 path=' . $this->dir, file_get_contents($this->dir . 'bin/php/etc/2.conf'));
		$this->assertFileExists($this->dir . 'bin/php/etc/4.conf');
	}

	public function testEnsurePhpFpmPoolConfigsWithoutTemplateIsNoop(): void {
		$this->call('ensurePhpFpmPoolConfigs', $this->dir);
		$this->assertFileDoesNotExist($this->dir . 'bin/php/etc/1.conf');
	}
}
