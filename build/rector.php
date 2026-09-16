<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\Equal\UseIdenticalOverEqualWithSameTypeRector;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

/**
 * Rector configuration for XC_VM — stage 2 (class-based trees).
 *
 * Scope is the PSR-4, class-based trees: Core / Domain / Cli / Infrastructure
 * plus Streaming, Public\Controllers and the Ministra classes. Deliberately
 * EXCLUDED (see withSkip / withPaths): view templates (Public/Views, short
 * tags), procedural front-controllers (Public/stream, Public/admin,
 * Ministra/portal.php), the legacy \TMDB library, runtime-installed modules,
 * the committed vendor and the streaming hot-path bootstraps.
 *
 * KNOWN BUG — review every run: several rules drop the parens around an
 * assignment-in-condition, assigning the wrong value and flipping the guard.
 * Two variants seen (PHPStan catches only some):
 *   1. empty-if/else inversion:  `if (($k = array_search(...)) === false)`
 *      -> `if ($k = array_search(...) !== false)`
 *   2. De Morgan (now disabled below): `!(($x = f()) && g())`
 *      -> `!$x = f() || !g()`
 * After each `make rector-fix`, grep the diff and restore parens on any hit:
 *   grep -rnE 'if \(\$[A-Za-z_]+ = .*(!==|===) (false|true|null)\)' src/
 *   grep -rnE '!\$[A-Za-z_]+ = .+ \|\|' src/
 *
 * Paths are anchored with __DIR__ (this file lives in build/) so the config
 * behaves the same whether invoked from the repo root (make rector) or from
 * src/ (composer refactor).
 *
 * Import-adding rules are intentionally left OFF (the default): the
 * check-procedural-use gate relies on positional `use` imports, and we never
 * want Rector to reshuffle them.
 *
 * The "empty-if with else" anti-pattern that motivated this work is handled by
 * the built-in RemoveDeadIfForeachForRector (in the deadCode set) — it collapses
 * `if (COND) {} else { BODY }` into `if (!COND) { BODY }` and leaves non-empty
 * bodies, commented empty bodies and elseif chains untouched. No custom rule is
 * needed. To add a project-specific rule later, drop a class under
 * tools/rector/src/, wire it via an autoload-dev PSR-4 entry in src/composer.json,
 * and register it here with ->withRules([...]).
 */
return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/../src/Core',
		__DIR__ . '/../src/Domain',
		__DIR__ . '/../src/Cli',
		__DIR__ . '/../src/Infrastructure',
		// Stage 2 — class-based trees that were deferred:
		__DIR__ . '/../src/Streaming',
		__DIR__ . '/../src/Public/Controllers',
		__DIR__ . '/../src/Ministra',
	])
	->withSkip([
		// Legacy global \TMDB library — not PSR-4, vendored verbatim.
		__DIR__ . '/../src/Infrastructure/Tmdb/lib',

		// Ministra procedural front-controller (short tags, injected globals) —
		// excluded from PHPStan for the same reason. PortalHandler/PortalHelpers
		// (classes, next to it) ARE analysed.
		__DIR__ . '/../src/Ministra/portal.php',

		// Streaming/web-API hot paths — deliberately excluded from the mechanical
		// pass; touched only under review (verified via the dev-container smokes).
		__DIR__ . '/../src/Infrastructure/Bootstrap/StreamingRequestBootstrap.php',
		__DIR__ . '/../src/Infrastructure/Bootstrap/WebApiBootstrap.php',
		__DIR__ . '/../src/Cli/Commands/FanoutBinaryCommand.php',
		__DIR__ . '/../src/Cli/Commands/FanoutSyncCommand.php',

		// Never touch these even if a path ever widens to include them.
		__DIR__ . '/../src/vendor',
		'*/tmp/*',
		'*/backups/*',

		// Behaviour-changing on loosely-typed legacy code — opt in later, per
		// file, after review, not as part of the safe mechanical pass:
		//  - strict_types changes runtime int/string coercion (many (int) casts).
		//  - == -> === is type-sensitive; the "same type" inference can be wrong.
		SafeDeclareStrictTypesRector::class,
		UseIdenticalOverEqualWithSameTypeRector::class,

		// UNSAFE on assignment-in-condition: negating `!(($x = f()) && g())` this
		// rule drops the assignment parens -> `!$x = f() || !g()`, which by PHP
		// precedence assigns the WRONG value to $x and flips the guard. It broke
		// 49 access-control checks (Authorization::check) in the API wrappers.
		// The cosmetic De Morgan wins aren't worth that risk — leave it off.
		SimplifyDeMorganBinaryRector::class,

		// NOTE: FollowRequireByDirRector (Rector\CodingStyle\Rector\Include_)
		// is UNSAFE here and must stay disabled if a future Rector version
		// reintroduces it. It rewrites `include "functions.php"` (resolved via
		// include_path / CWD at runtime) into `include __DIR__ . "/functions.php"`,
		// pinning it to the file's own directory. The admin/reseller
		// TableControllers include a legacy bootstrap that does NOT live next to
		// them, so __DIR__ pointed at a non-existent path and broke every
		// session-authenticated table load. It is not in the current rule set,
		// so it is documented rather than listed (an unknown class in withSkip
		// would fail config validation).
	])
	// Safe, behaviour-preserving sets. deadCode carries
	// RemoveDeadIfForeachForRector (the empty-if/else collapse); codeQuality
	// carries the boolean simplifiers (double-not, De Morgan, != normalisation).
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
	);
