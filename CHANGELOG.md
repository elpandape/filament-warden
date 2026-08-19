# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API may change between minor versions.

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
