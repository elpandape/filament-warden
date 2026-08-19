# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API may change between minor versions.

## [0.3.2] - 2026-08-19

A protected role was only half protected.

### Fixed

- **A protected role's permissions could be edited from the screen, and that
  screen could only ever take power away.** A role like `super-admin` holds the
  wildcard, which is not a cell, so nothing in the grid can grant it more — every
  edit it allows is either noise or an explicit forbid cutting a hole in what the
  role already has. The grid is now shown and locked on a protected role, and the
  save is guarded on the server: the browser's payload still reaches the field
  even when it is disabled.
- A locked grid renders its cells as marks, not as disabled buttons: it is read,
  not operated.

### Changed

- **The title of a protected role stays editable**, and that is deliberate. The
  name is the identifier — `roles.protected` matches by it, so renaming it would
  unprotect the role on the spot — and the grid is the role's powers. The title is
  a label nothing resolves by: no grant, no check, no verdict reads it. What
  changes behaviour is protected; what only changes the wording is not.
- The role form is two sections — who the role is, and what it can do — instead of
  four stacked blocks.

## [0.3.1] - 2026-08-19

What the grid was not saying, and how it looked while not saying it.

### Fixed

- **A role holding a rule over every entity read as a role holding nothing.**
  `Warden::allow($role)->everything()` writes a permission over `*`, which owns no
  cell of the grid, so it was skipped entirely — and the most dangerous role in an
  installation was drawn empty. It is now reported above the grid and every cell
  it answers is drawn dashed, which is what the fourth drawing was always for. A
  written stance still beats it, and an action no policy declares is still a dot:
  a wildcard cannot grant what nothing consults.
- **The columns are ordered by the scope map**, not by whichever policy happened
  to be walked first. `delete` came after `deleteAny` for no reason a reader could
  see.

### Changed

- The grid is denser: the action columns take what they need and the entity column
  keeps the rest, instead of the cells drifting apart across an empty table.
- The entity column stays pinned while the rest scrolls sideways.
- The legend draws the sample it names, rather than describing it in words.
- Row shortcuts appear on hovering the row, not only its first cell.

## [0.3.0] - 2026-08-19

The role grid. The first tag that is worth installing: a roles screen with the
permission grid inside it, derived from your policies.

### Added

- A roles resource — list, create, edit — whose model, slug, icon, group and sort
  all come from config, and whose labels are translated.
- `Filament\Forms\PermissionGrid`, a form field holding the whole grid: tabs per
  group with a tally, one row per entity, columns per action grouped by scope,
  plus the wildcard column warden stores as `*`. Pages, widgets and loose
  permissions are drawn as doors, because a page is not a model.
- A cell cycles between abstaining, granted and forbidden; shift walks the cycle
  backwards, from the mouse and from the keyboard, and reaches a denial in one
  step.
- Four drawings and no more: an empty box, a tick, a cross, and a dashed one for a
  cell nobody wrote that a broader rule already answers. An action the policy does
  not declare is a dot, not a control.
- `Grants\RoleGrants`, which reads what a role holds and applies the grid as a
  diff — never a sync, and never through the relation.
- A hand-written stylesheet and a hand-written Alpine component, both published by
  `php artisan filament:assets`. There is no Tailwind and no bundler in this
  package.
- Views are publishable under `filament-warden-views`, so an application can
  rewrite the grid's markup.
- Every string of the grid is translated in English and Spanish, including the
  action names, with a humanised fallback for an action only your application
  knows about.

### Changed

- `catalog.scopes` now names `deleteAny`, `restoreAny` and `forceDeleteAny`. Left
  unnamed they fell into `write`, which put "delete any" next to "create" on
  screen.
- A new `navigation` config block holds the roles resource's group, icon, sort and
  slug.

### Not included

- **A narrowed cell is shown and cannot be changed.** A permission carrying
  conditions or an ownership flag is drawn with its amber mark and left alone,
  because revoking by name would delete every twin that shares it. Editing those
  is `0.6.0`.
- No inspector: why a cell is where it is arrives in `0.4.0`, from `explain()`.
- No condition builder: `0.5.0`.
- Without javascript the grid still renders every stance the store holds, but it
  does not derive the dashed drawing of a broader rule.
- The visual polish — a pinned entity column, transitions, spacing — is `0.3.1`.

## [0.2.0] - 2026-08-18

The catalogue and the lock. The package now knows which actions exist, and closes
the doors Filament leaves open. Still no screens.

### Added

- A catalogue derived from your policies. `Catalog::for($panel)` walks a panel's
  resources, pages and widgets, plus the models and loose permissions declared in
  config, and emits one permission per policy method. A model without a policy
  contributes nothing, on purpose.
- Four scopes — `read`, `write`, `withdraw`, `irreversible` — so that listing
  records and deleting them for good do not look alike on screen. Anything the
  scope map does not name counts as a write.
- `Policies\WardenPolicy`, the base class an application's policies extend. It
  resolves through warden's `Resolver`, never through the gate, and brings no
  abilities of its own.
- Policies for warden's `Role` and `Permission`, registered by this package.
  Laravel's policy guessing never looks in this namespace, and without them
  Filament answers `allow` for both models.
- `Concerns\AccessesPanels`, the `canAccessPanel()` your account model composes.
  The permission is derived from the panel id — `panel:admin` — and can be
  overridden per panel through `guard.panel`.
- `Filament\Concerns\AuthorizesPageAccess` and `AuthorizesWidgetView`, the
  `canAccess()` and `canView()` Filament leaves returning `true`.
- `Support\Config`, through which every key of this package is read. A cached
  config never ran `mergeConfigFrom`, and a published one may predate a key.

### Changed

- `guard.panel` is a new config key: a map of panel id to permission name, empty
  by default.
- `catalog.custom` is a map of permission name to scope, not a list.

### Not included

- No screens. The role grid arrives in `0.3.0`.
- Relation managers are not walked: a model reachable only through one is declared
  in `catalog.models`.
- The boot guardian — a panel refusing to start with a page or a widget that
  decides nothing — is `0.8.0`. This version guards the screens it is composed
  into, not the ones it is not.

## [0.1.0] - 2026-08-18

Foundations. The package installs, registers itself and can be added to a panel —
and does nothing else yet, on purpose.

### Added

- A service provider that merges the package config and loads its translations,
  publishable under `filament-warden-config` and `filament-warden-translations`.
- `FilamentWardenPlugin`, implementing Filament's plugin contract, registering no
  screens.
- The configuration file, with conservative defaults: no manual creation of
  permissions, editing limited to loose ones, deletion limited to orphans.
- English and Spanish translations, pinned to each other by a test.
- A test suite on testbench with a real Filament panel and warden's four tables.
- Six quality gates: style, static analysis, refactoring, line coverage at 100 %,
  type coverage at 100 % and profanity.

### Not included

- No screens: no roles grid, no permissions resource.
- No catalogue derived from policies.
- No policies, no panel guard: **anything this package registers on a panel in a
  later version is open until `0.2.0` closes it**.
- No console commands.
