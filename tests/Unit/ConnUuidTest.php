<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentConnections;

/**
 * The one connection uuid rule (AgentConnections::CONN_UUID): what every
 * store, event and command naming a connection accepts, kept in one place so
 * no reader drifts from the others.
 */
final class ConnUuidTest extends TestCase {
	public function testTheRuleTakesWhatAuthMintsAndNothingElse(): void {
		foreach (['a', bin2hex(random_bytes(16)), str_repeat('Z', 64), 'a_b-C9'] as $rUUID) {
			$this->assertSame(1, preg_match(AgentConnections::CONN_UUID, $rUUID), $rUUID);
		}
		foreach (['', str_repeat('a', 65), "abc\n", 'a/b', 'a.b', 'a b', '../x', 'é'] as $rUUID) {
			$this->assertSame(0, preg_match(AgentConnections::CONN_UUID, $rUUID), json_encode($rUUID));
		}
	}

	public function testTheFragmentIsTheWholeRulesBody(): void {
		$this->assertSame('/^' . AgentConnections::CONN_UUID_CHARS . '\z/', AgentConnections::CONN_UUID);
	}

	public function testNoOtherCopyOfTheRuleInTheApplication(): void {
		$rRoot = dirname(__DIR__, 2) . '/src/';
		$rCopies = [];
		$rFiles = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rRoot, \FilesystemIterator::SKIP_DOTS));
		foreach ($rFiles as $rFile) {
			$rPath = substr((string) $rFile, strlen($rRoot));
			if (!str_ends_with($rPath, '.php') || str_starts_with($rPath, 'vendor/') || $rPath === 'Core/Cluster/AgentConnections.php') {
				continue;
			}
			if (str_contains((string) file_get_contents((string) $rFile), '[A-Za-z0-9_-]{1,64}')) {
				$rCopies[] = $rPath;
			}
		}
		$this->assertSame([], $rCopies, 'use AgentConnections::CONN_UUID');
	}
}
