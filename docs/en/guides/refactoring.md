# Automated Refactoring (Rector)

XC_VM uses [Rector](https://getrector.com/) to modernise legacy PHP mechanically and
safely. Rector rewrites code on the **AST** (not text), so every transformation is
deterministic and reproducible. It complements the existing tools rather than replacing
them:

| Tool | Role |
| --- | --- |
| **Rector** | *Changes* code — mechanical modernisation and simplification |
| PHPStan | Reports type/logic problems (`make phpstan`) |
| phpcs / Slevomat | Reports and fixes coding style (`make cs` / `make cs-fix`) |
| PHPUnit | Verifies behaviour (`tools/.bin/phpunit.phar`) |

**Golden rule: detect → show the diff → verify → only then apply.** Never mass-apply Rector
to production code without reviewing a dry-run first.

## Install

Rector is a `require-dev` dependency of `src/composer.json` (like PHPStan and phpcs). It is
**never** shipped: the committed `src/vendor/` is production-only.

```bash
make dev-tools     # composer install (incl. require-dev) — adds Rector to src/vendor
```

Before committing, always prune it back:

```bash
make dev-clean     # composer install --no-dev — restores a production-only vendor
```

The `check-vendor-prod-only` gate fails if Rector ever lands in the committed vendor, so
`make dev-clean` is mandatory before any commit that touched dependencies.

## Run

```bash
make rector        # dry-run: prints the diff, writes nothing (non-zero exit if changes pend)
make rector-fix    # applies the changes in place
```

Equivalent Composer scripts (run from `src/`):

```bash
composer refactor:dry
composer refactor
```

After **applying** changes, always run the full verification pipeline and review the diff:

```bash
make cs-fix        # reconcile style (tabs / K&R) with the rewritten files
make phpstan
php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist
```

## Configuration

The single config is [`build/rector.php`](https://github.com/Vateron-Media/XC_VM/blob/main/build/rector.php)
(alongside `build/phpstan.dist.neon` and `build/phpcs.xml.dist`). Paths are anchored with
`__DIR__`, so it behaves the same from the repo root or from `src/`.

### Scope

Only the PSR-4, class-based trees are in scope:

```text
src/Core  src/Domain  src/Cli  src/Infrastructure
```

Everything else is **excluded** and must stay excluded:

- `src/Public/**`, `src/Ministra/**` — view templates use short tags (`<?`/`<?=`); procedural
  entry points depend on positional `use` imports enforced by the `check-procedural-use` gate.
- `src/Infrastructure/Tmdb/lib/**` — the legacy global `\TMDB` library (not PSR-4).
- `src/Modules/**` — runtime-installed (may be ionCube-encoded).
- `src/vendor/**`, `src/migrations/**`, `src/bin/**`, runtime dirs (`tmp`, `backups`, …).
- **Streaming hot-path** (`src/Streaming/**`, `src/Public/stream/**`, the streaming bootstraps,
  `Fanout*Command`) — higher risk, refactored later in its own cautious phase.

### Enabled rules

The config enables the `deadCode` and `codeQuality` prepared sets — behaviour-preserving
simplification and dead-code removal. Notably, the "empty-`if` with `else`" anti-pattern
(pervasive in the legacy code) is collapsed by the built-in `RemoveDeadIfForeachForRector`:

```php
if (!$user) {
} else {
    doThing();
}
// becomes:
if ($user) {
    doThing();
}
```

It leaves non-empty bodies, commented empty bodies, and `elseif` chains untouched. No custom
rule is needed for this — a built-in already does it, and does it cleanly.

### Deliberately disabled (behaviour-changing) rules

Two rules are **skipped** because they can change runtime behaviour on loosely-typed legacy
code. Opt into them later, per file, after review — never as part of the mechanical pass:

- `SafeDeclareStrictTypesRector` — adds `declare(strict_types=1)`, changing int/string coercion.
- `UseIdenticalOverEqualWithSameTypeRector` — `==` → `===`, which is type-sensitive.

Import-adding rules are also left off (the default), to protect the `check-procedural-use` gate.

## Adding a project-specific rule

Prefer a built-in rule when one exists. If you genuinely need an XC_VM-specific transformation:

1. Add a class under `tools/rector/src/` (a Rector rule extends `Rector\Rector\AbstractRector`).
2. Wire its namespace via an `autoload-dev` PSR-4 entry in `src/composer.json`, then
   `composer dump-autoload` from `src/`.
3. Register it in `build/rector.php` with `->withRules([...])`.
4. Add fixture tests (Rector's `AbstractRectorTestCase`, `before/after` split by `-----`) in a
   **separate** PHPUnit suite — not `tests/Unit/`, because the main test job runs against the
   production-only vendor where Rector's test classes are absent.

## CI

There is no Rector CI job yet. It is planned as a separate check-only job (dry-run) that
reports but never modifies the repository — see the project's refactoring roadmap.
