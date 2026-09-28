<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;

/**
 * The legacy `/api` switch (api_legacy.conf, written by the root cron from
 * the node's own DATAPLANE flow) exists for the load balancers: it is their
 * `/api`, authenticated by a password in a URL, that the data plane retires.
 * lb_configs/nginx.conf — the nginx.conf every LB runs — had neither the
 * include nor the guard, so a DATAPLANE node kept serving `/api`. The LB
 * config must include the switch and guard both locations, and the LB build
 * must ship every file its nginx.conf includes: nginx refuses to start on a
 * missing one. MAIN's own `/api` keeps no toggle (ADR 0004): MAIN never has
 * flows, so the cron writes 1 there whatever a stray flows.json says.
 */
final class LbNginxApiLegacyTest extends TestCase {

	private string $rRoot;

	private ?string $rFlows = null;

	protected function setUp(): void {
		$this->rRoot = dirname(__DIR__, 2);
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeRole::useServers(null);
		if ($this->rFlows !== null) {
			@unlink($this->rFlows);
		}
	}

	/** lb_configs/nginx.conf, the nginx.conf of the LB archive. */
	private function lbConf(): string {
		return str_replace("\r\n", "\n", (string) file_get_contents($this->rRoot . '/lb_configs/nginx.conf'));
	}

	/** The body of `location = $rPath { … }`, its braces balanced. */
	private function location(string $rConf, string $rPath): string {
		$rStart = strpos($rConf, 'location = ' . $rPath . ' {');
		$this->assertNotFalse($rStart, 'no location = ' . $rPath);
		$rOpen = strpos($rConf, '{', $rStart);
		$rDepth = 0;
		for ($i = $rOpen, $n = strlen($rConf); $i < $n; $i++) {
			if ($rConf[$i] === '{') {
				$rDepth++;
			} elseif ($rConf[$i] === '}' && --$rDepth === 0) {
				return substr($rConf, $rOpen + 1, $i - $rOpen - 1);
			}
		}
		$this->fail('unbalanced location = ' . $rPath);
	}

	public function testTheLbConfigIncludesTheSwitchAndGuardsTheLegacyApi(): void {
		$rConf = $this->lbConf();
		$this->assertMatchesRegularExpression('/^\s*include api_legacy\.conf;$/m', $rConf);
		foreach (['/api', '/api.php'] as $rPath) {
			$rBody = $this->location($rConf, $rPath);
			$this->assertMatchesRegularExpression('/^\s*if \(\$api_legacy = 0\) \{\s*return 404;\s*\}/', $rBody, $rPath . ': the guard comes first');
			$this->assertStringContainsString('fastcgi_param XC_API internal;', $rBody, $rPath);
		}
	}

	/**
	 * The switch follows the node's own DATAPLANE flow, and MAIN has none:
	 * the same stray flows.json that retires a node's `/api` leaves MAIN's
	 * served.
	 */
	public function testMainsOwnApiKeepsNoToggle(): void {
		$this->rFlows = (string) tempnam(sys_get_temp_dir(), 'flows');
		file_put_contents($this->rFlows, json_encode(['mode' => 2, 'flows' => NodeFlows::DATAPLANE, 'state' => 'active']));

		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 0], SERVER_ID + 1 => ['is_main' => 1]]);
		NodeFlows::usePath($this->rFlows, true);
		$this->assertSame('set $api_legacy 0;', RootSignalsCronJob::apiLegacyConf(), 'a node with the data plane');

		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 1]]);
		NodeFlows::usePath($this->rFlows, true);
		$this->assertSame('set $api_legacy 1;', RootSignalsCronJob::apiLegacyConf(), 'MAIN');
	}

	public function testTheShippedDefaultKeepsTheApiServed(): void {
		$this->assertSame('set $api_legacy 1;', trim((string) file_get_contents($this->rRoot . '/src/bin/nginx/conf/api_legacy.conf')));
	}

	/**
	 * Every file lb_configs/nginx.conf includes by name (relative to
	 * bin/nginx/conf/, globs aside) is in the LB archive: tracked under src/,
	 * inside LB_DIRS and stripped by neither LB_DIRS_TO_REMOVE nor
	 * LB_FILES_TO_REMOVE.
	 */
	public function testTheLbBuildShipsEveryFileItsNginxIncludes(): void {
		if (!is_file($this->rRoot . '/Makefile') || !file_exists($this->rRoot . '/.git')) {
			$this->markTestSkipped('the LB lists are read from the Makefile of a git checkout');
		}
		$rDirs = $this->makeList('LB_DIRS');
		$rRmDirs = $this->makeList('LB_DIRS_TO_REMOVE');
		$rRmFiles = $this->makeList('LB_FILES_TO_REMOVE');

		preg_match_all('/^\s*include\s+([^\s;*]+);/m', $this->lbConf(), $rMatches);
		$this->assertContains('api_legacy.conf', $rMatches[1]);
		foreach (array_unique($rMatches[1]) as $rInclude) {
			$rPath = 'bin/nginx/conf/' . $rInclude;
			exec('git -C ' . escapeshellarg($this->rRoot) . ' ls-files --error-unmatch -- ' . escapeshellarg('src/' . $rPath) . ' 2>/dev/null', $rOut, $rCode);
			$this->assertSame(0, $rCode, $rPath . ' is not tracked');
			$this->assertContains(explode('/', $rPath)[0], $rDirs, $rPath);
			$this->assertNotContains($rPath, $rRmFiles, $rPath . ' is stripped from the LB archive');
			foreach ($rRmDirs as $rRmDir) {
				$this->assertStringStartsNotWith($rRmDir . '/', $rPath, $rPath . ' is stripped from the LB archive');
			}
		}
	}

	/** @return string[] */
	private function makeList(string $rName): array {
		exec('make -s -C ' . escapeshellarg($this->rRoot) . ' print-' . $rName . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return preg_split('/\s+/', trim(implode(' ', $rOut)), -1, PREG_SPLIT_NO_EMPTY);
	}
}
