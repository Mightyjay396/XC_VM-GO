<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Equal\UseIdenticalOverEqualWithSameTypeRector;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

/**
 * Rector configuration for XC_VM — stage 1 (scaffolding + audit).
 *
 * Scope is deliberately narrow: only the PSR-4, class-based trees
 * (Core / Domain / Cli / Infrastructure). Procedural entry points, view
 * templates (short tags), the legacy \TMDB library, runtime-installed modules,
 * the committed vendor and the streaming hot-path are excluded — see withSkip().
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
	])
	->withSkip([
		// Legacy global \TMDB library — not PSR-4, vendored verbatim.
		__DIR__ . '/../src/Infrastructure/Tmdb/lib',

		// Streaming hot-path — refactored later in its own cautious phase.
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
	])
	// Safe, behaviour-preserving sets. deadCode carries
	// RemoveDeadIfForeachForRector (the empty-if/else collapse); codeQuality
	// carries the boolean simplifiers (double-not, De Morgan, != normalisation).
	->withPreparedSets(
		deadCode: true,
		codeQuality: true,
	);
