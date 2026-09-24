# Reseller navbar registry — design spec

**Date:** 2026-09-24
**Status:** approved (brainstorming), pending implementation plan

## Problem

Admin's sidebar is built from a class-based extension point: `NavbarItem` value
objects registered into the static `NavbarRegistry` by `CoreNavbarProvider`
(core items) and by any module implementing `NavbarProviderInterface`
(`ModuleLoader::bootAll()` calls `registerNavbar()` on every module after core
registers). The reseller sidebar has no equivalent: it is a hardcoded PHP
array (`$xmMenuSections`) defined directly inside
`src/Public/Views/layouts/reseller/header.php`, rendered by a hand-rolled
recursive function in the same file. Modules have no way to add a reseller
sidebar entry — the only "extension point" is manually editing that template,
which defeats the module system's isolation (`ModuleLoader` topological boot,
`module.json` manifests, uninstall cleanup, etc. all assume core doesn't need
touching for a module to add UI surface).

Goal: give resellers the same module-extensible navigation mechanism admins
have, so a module implements one interface and its reseller sidebar entry
just appears — permission-gated, ordered, sectioned — with no edits to core
view templates.

## Constraints discovered during research

- `ModuleLoader::bootAll()` runs on **every reseller request**, not just
  admin/CLI: `ResellerScopeBootstrap::bootFunctions()` calls
  `XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN)` — the same context admin
  uses — so `CoreNavbarProvider`, `TopbarRegistry`, `TableRegistry`,
  `PermissionRegistry`, `QuickToolsRegistry` are already populated during a
  reseller page load; they're just never consulted by the reseller header.
- `NavbarItem` (`src/Core/Module/NavbarItem.php`) is already fully
  scope-agnostic in shape: `key`/`parent`/`url`/`translationKey`/`fallbackTitle`/
  `permissions`/`order`/`icon`/`desktopOnly`/`noMobileSubmenu`/`submenuClass`/
  `settingDisabled`/`divider`. Nothing admin-specific is baked into the value
  object — only the *render-time permission check* differs.
- Admin's permission check (`_xc_nav_visible()` in `admin/header.php`) calls
  `Authorization::check('adv', $perm)`. Reseller already has an equivalent,
  unused-by-navbar predicate: `Authorization::hasResellerPermissions($perm)`
  (`src/Core/Auth/Authorization.php`), which checks `$rPermissions[$perm]`
  truthy — exactly the model the current hardcoded reseller array already
  uses (`!empty($xmPermissions['create_line'])`).
- Section captions ("Users", "Catalog", …) are a **view-level** concept in
  admin too: `admin/menu.php` builds `$_menuSections = [['title' => ..., 'keys'
  => [...]]]` and `XcNewuiMenuBuilder::renderInner()` groups already-registered
  top-level registry items under captions, auto-hiding a caption when every
  item under it was permission-filtered out. This is structurally identical
  to the reseller's own hardcoded `$xmMenuSections` shape.
- `PermissionRegistry` + `PermissionProviderInterface`
  (`src/Core/Module/PermissionRegistry.php`) already exist for exactly this
  kind of reseller-specific module extension: a module registers reseller
  sub-permission keys that get merged into the group editor's permission
  catalogue. This is the direct precedent/template for the new registry.
- The reseller sidebar's "Generate Trial" entries (under Lines/MAG/Enigma) are
  gated by a *computed* capability, `LineService::canGenerateTrials()`, not a
  stored permission. `ResellerScopeBootstrap`/the reseller header already fold
  several computed values into `$rPermissions` (`all_reports`, `stream_ids`,
  etc.), so this is not a new concept — just one more merged key.
- `$GLOBALS['rGenTrials']` (set in `layouts/reseller/header.php:27-28`) is
  read directly by `reseller/line.php`, `mag.php`, `enigma.php` (trial-banner
  visibility) and is explicitly named in
  `Public/Controllers/Admin/BaseAdminController.php:117` as a global the
  reseller header propagates. It must not be renamed or removed.

## Rejected approaches

- **Single registry with a `scope` property on `NavbarItem`.** Rejected:
  breaks the established "one static registry per concern" convention
  (Topbar/Table/Permission/QuickTools registries are all separate small
  classes); admin and reseller trees share top-level key names (`dashboard`,
  `lines`, `active_codes`, …), so a single flat map would need key uniqueness
  changed to a `(key, scope)` tuple — a more invasive change to a class
  every existing admin module already calls.
- **Single registry, `reseller.`-prefixed key convention.** Rejected: no
  structural protection against key collisions (relies on module authors
  remembering the prefix); render-time helpers would need to branch on which
  permission-check semantics apply to which item, mixing two conceptually
  different trees in one flat structure.
- **Generalizing `XcNewuiMenuBuilder` into a shared parametrized builder** for
  both scopes (icon-mapper, page-aliases, permission-check as injected
  callbacks). Rejected for now: the reseller builder's trial
  mutual-exclusion matching and page-alias table are narrow, reseller-only
  quirks; threading them through a stable, unrelated-scope admin file for a
  modest DRY win has a higher blast radius than a small duplicated builder.
  Noted as a conscious call, not an oversight — revisit if a third scope
  (e.g. player) ever needs the same registry-driven sidebar.

## Design

### 1. New sibling registry + provider interface

- `src/Core/Module/ResellerNavbarRegistry.php` — structural copy of
  `NavbarRegistry` (own `private static array $items`; same `add()`,
  `getTopLevel()`, `getChildren()`, `hasChildren()`, `collapseDividers()`,
  `reset()`, private `_sorted()`). Stores the **same** `NavbarItem` objects —
  no new value-object class.
- `src/Core/Module/Contract/ResellerNavbarProviderInterface.php`:
  ```php
  interface ResellerNavbarProviderInterface {
      public function registerResellerNavbar(ResellerNavbarRegistry $registry): void;
  }
  ```
- A module wanting entries in both panels implements both
  `NavbarProviderInterface` and `ResellerNavbarProviderInterface`, using the
  identical fluent `NavbarItem` builder in each, against the appropriate
  registry.

### 2. `CoreResellerNavbarProvider` — full migration of the hardcoded menu

New `src/Core/Module/CoreResellerNavbarProvider.php` (mirrors
`CoreNavbarProvider`'s structure: one `register()` entry point delegating to
private per-group methods). Migrates every entry from today's
`$xmMenuSections` (`layouts/reseller/header.php:51-168`) into registry items.
Flat leaf entries (Streams, Movies, …) become independent top-level
`NavbarItem`s — the "Content" *section* is purely a view-level grouping of
those keys, not a registry parent — matching how admin registers standalone
top-level items like `management.tickets`.

Mapping (permission keys reuse the exact reseller `$rPermissions` keys the
current hardcoded array already checks):

| Registry item(s) | Permission gate | Order |
|---|---|---|
| `dashboard` | — (always) | 100 |
| `user_lines` (+ `.add`, `.trial`, `.manage`) | parent: `create_line`; `.trial`: `can_generate_trials` | 200 |
| `mag_devices` (+ children) | parent: `create_mag`; `.trial`: `can_generate_trials` | 210 |
| `enigma_devices` (+ children) | parent: `create_enigma`; `.trial`: `can_generate_trials` | 220 |
| `active_codes` (+ children) | parent: `create_line` (matches current condition) | 230 |
| `sub_resellers` (+ children) | parent: `create_sub_resellers` | 240 |
| `streams`, `created_channels`, `movies`, `episodes`, `radios` | each: `can_view_vod` | 300–340 |
| `tv_guide` | `can_view_vod` + `desktopOnly()` | 350 |
| `category_templates` | — (always) | 400 |
| `tickets` | — (always) | 500 |
| `logs` (+ `.live_connections`, `.activity_logs` gated on `reseller_client_connection_logs`; `.user_logs` open) | as noted | 510 |

Section-level auto-hide (e.g. "hide the whole Content group when
`can_view_vod` is false") requires no special-case logic: it falls out of
giving each item its own permission and letting the caption-rendering loop
skip a section whose body rendered empty — the same mechanism admin already
relies on.

### 3. Rendering

- `_xc_reseller_nav_visible(NavbarItem $item, bool $mobile, array $settings): bool`
  in the reseller header — same shape as admin's `_xc_nav_visible()`, with the
  permission-check line swapped to
  `Authorization::hasResellerPermissions($_p)` (OR across `$item->permissions`).
  `desktopOnly`, `settingDisabled`, and the "`#`-parent visible if any child
  visible" recursion are copied unchanged (generic checks against
  `$rMobile`/`$rSettings`).
- New `src/Public/Views/reseller/menu.php` (placement mirrors
  `admin/menu.php`) defines `XcResellerMenuBuilder`, walking
  `ResellerNavbarRegistry::getTopLevel()/getChildren()`. Keeps two
  reseller-only quirks from today's code:
  - Its own `PAGE_ALIASES` (`category_template → category_templates`,
    `ticket`/`ticket_view → tickets`).
  - The trial mutual-exclusion active-match: `line`/`mag`/`enigma` share a URL
    basename with their `?trial=1` sibling; active-matching branches on the
    GET param exactly as `_xc_reseller_node_is_current()` does today.
  - Does **not** need admin's `_xc_newui_icon()` legacy-class translator —
    reseller items use `ti tabler-*` directly already.
- `layouts/reseller/header.php` shrinks to building `$_resellerMenuSections`
  (same 5 caption groups as today: `''`/`clients`/`content`/
  `category_templates`/`tickets_and_logs`, now listing registry **keys**
  instead of inline item arrays) and calling
  `(new XcResellerMenuBuilder(...))->renderInner($_resellerMenuSections)`.
  The current ~100-line array and its three helper functions
  (`_xc_reseller_menu_node`, `_xc_reseller_section_header`,
  `_xc_reseller_node_is_current`) are deleted.

### 4. Trial gating — one added line, no new NavbarItem concept

In `layouts/reseller/header.php`, right where `$rGenTrials` is computed:

```php
$rGenTrials = LineService::canGenerateTrials($rUserInfo['id']);
$GLOBALS['rGenTrials'] = $rGenTrials;                     // unchanged
$rPermissions['can_generate_trials'] = $rGenTrials;       // new
```

The `.trial` `NavbarItem`s declare `->permissions(['can_generate_trials'])`
like any other gated item; `Authorization::hasResellerPermissions()` reads it
off `$rPermissions` like a real permission. `$GLOBALS['rGenTrials']` and its
existing consumers (`reseller/line.php`, `mag.php`, `enigma.php`,
`BaseAdminController.php`) are untouched.

### 5. `ModuleLoader::bootAll()` wiring

Alongside the existing `TopbarRegistry`/`TableRegistry`/`PermissionRegistry`/
`QuickToolsRegistry` setup (`src/Core/Module/ModuleLoader.php:230-251`):

```php
$resellerNavbarRegistry = new ResellerNavbarRegistry();
(new CoreResellerNavbarProvider())->registerNavbar($resellerNavbarRegistry);
ResellerNavbarRegistry::reset(); // placed per existing sibling-registry convention: reset before core registers, so a re-boot in the same process (tests/CLI) doesn't accumulate stale entries
```

and inside the per-module loop, next to the existing `NavbarProviderInterface`
check:

```php
if ($module instanceof ResellerNavbarProviderInterface) {
    $module->registerResellerNavbar($resellerNavbarRegistry);
}
```

### 6. Module usage example

```php
class MyModule extends BaseModule implements ResellerNavbarProviderInterface {
    public function registerResellerNavbar(ResellerNavbarRegistry $registry): void {
        $registry->add((new NavbarItem('my_module.reseller_entry'))
            ->url('my_module_page')
            ->label('my_module_label')
            ->icon('ti tabler-puzzle')
            ->permissions(['my_module_reseller_perm'])
            ->order(250));
    }
}
```
If `my_module_reseller_perm` is module-owned, the module also implements the
existing `PermissionProviderInterface` (`PermissionRegistry::add(...)`) — the
two extension points already compose, since `PermissionRegistry` exists
specifically to let modules add keys to the reseller group-editor catalogue.

## Testing

- Unit tests for `ResellerNavbarRegistry` mirroring existing `NavbarRegistry`
  test coverage (add/getTopLevel/getChildren/hasChildren/collapseDividers/
  reset), if such tests exist for `NavbarRegistry` today.
- A test module (or extending an existing test fixture module) implementing
  `ResellerNavbarProviderInterface`, asserting `ModuleLoader::bootAll()`
  invokes it and the item appears via `getTopLevel()`.
- Manual/e2e check: reseller login → sidebar renders identically to the
  current hardcoded output for a full-permission group and a
  restricted-permission group (spot-check that a hidden-permission section
  caption disappears, that trial entries appear only when
  `canGenerateTrials()` is true, that active-page highlighting still works
  including the line/mag/enigma vs. `?trial=1` disambiguation).
- `make gates`, `make phpstan`, `make cs`, full PHPUnit suite — standard
  regression gates for a Core/Module change.

## Out of scope

- Admin's `NavbarRegistry`/`NavbarItem`/`CoreNavbarProvider` — untouched.
- Player/portal navigation — no registry-driven equivalent requested or
  designed here.
- Any change to the reseller *permission model* itself beyond the one
  `can_generate_trials` synthetic key.
