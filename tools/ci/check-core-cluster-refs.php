<?php

/**
 * Gate: `src/Core/` must not use a `XcVm\Domain\Cluster\*` class unguarded.
 *
 * Core ships to load balancers; `Domain/Cluster` does not (the `make lb`
 * archive strips it, and tools/ci/verify-lb-archive.sh enforces that). A Core
 * class that calls MAIN's cluster domain therefore fatals on a node the moment
 * that line runs. The established pattern is a `class_exists()` guard with a
 * legacy path behind it (NodeActions, SignalDispatcher, NodeRpc).
 *
 * The rule is deliberately limited to `src/Core/`: files elsewhere also ship
 * without the trees they name (admin controllers reach Domain\User, which the
 * LB build strips too), but a node never executes them. Core is the tree that
 * by definition runs on both sides.
 *
 * Usage: php tools/ci/check-core-cluster-refs.php [src-dir]
 */

$rRoot = rtrim($argv[1] ?? dirname(__DIR__, 2) . '/src', '/') . '/';
$rCore = $rRoot . 'Core/';
if (!is_dir($rCore)) {
	fwrite(STDERR, "check-core-cluster-refs: no such directory: " . $rCore . "\n");
	exit(1);
}

$rBad = [];
$rFiles = 0;
$rRefs = 0;

/** @var SplFileInfo $rFile */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rCore, FilesystemIterator::SKIP_DOTS)) as $rFile) {
	if ($rFile->getExtension() !== 'php') {
		continue;
	}
	$rFiles++;
	$rLines = file($rFile->getPathname()) ?: [];
	$rText = implode('', $rLines);
	// The classes this file imports from the cluster domain, and their guards.
	preg_match_all('/^use\s+XcVm\\\\Domain\\\\Cluster\\\\(\w+);/m', $rText, $rImports);

	foreach ($rLines as $rNo => $rLine) {
		$rTrimmed = ltrim($rLine);
		if ($rTrimmed === '' || $rTrimmed[0] === '*' || str_starts_with($rTrimmed, '//') || str_starts_with($rTrimmed, '/*') || str_starts_with($rTrimmed, 'use ')) {
			continue;
		}
		// Either the fully qualified name, or a class imported above.
		$rNames = [];
		if (preg_match_all('/XcVm\\\\Domain\\\\Cluster\\\\(\w+)/', $rLine, $rM)) {
			$rNames = $rM[1];
		}
		foreach ($rImports[1] as $rImported) {
			if (preg_match('/\b' . preg_quote($rImported, '/') . '::/', $rLine)) {
				$rNames[] = $rImported;
			}
		}
		foreach (array_unique($rNames) as $rName) {
			$rRefs++;
			if (str_contains($rLine, 'class_exists(') || guarded($rLines, $rNo, $rName)) {
				continue;
			}
			$rBad[] = str_replace($rRoot, '', $rFile->getPathname()) . ':' . ($rNo + 1) . ': ' . $rName . ' used without a class_exists() guard';
		}
	}
}

if ($rBad !== []) {
	fwrite(STDERR, "core-cluster-refs: Core must not reach MAIN's cluster domain unguarded (it is stripped from the LB build):\n");
	foreach ($rBad as $rLine) {
		fwrite(STDERR, '  ' . $rLine . "\n");
	}
	exit(1);
}

echo 'OK: ' . $rFiles . ' Core files, ' . $rRefs . " Domain\\Cluster references, every one behind a class_exists() guard.\n";

/**
 * Is the reference inside a block a `class_exists()` of the same class opens?
 * The guard is looked for above the line, within the enclosing function, which
 * is how every case in the tree is written.
 *
 * @param list<string> $rLines
 */
function guarded(array $rLines, int $rNo, string $rName): bool {
	for ($i = $rNo - 1; $i >= 0 && $i > $rNo - 40; $i--) {
		$rLine = $rLines[$i];
		if (str_contains($rLine, 'class_exists(' . $rName . '::class)')) {
			return true;
		}
		// The start of the enclosing function: no guard between it and the line.
		if (preg_match('/^\t(public|private|protected|static|function)/', $rLine)) {
			return false;
		}
	}
	return false;
}
