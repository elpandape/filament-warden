# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API may change between minor versions.

## [0.8.0] - 2026-08-19

The guard and the audit. **This version changes how a panel starts.**

### Added

- **A panel no longer starts with a screen that does not decide who gets in.**
  Filament's `Page::canAccess()` and `Widget::canView()` return `true` literally
  and `->strictAuthorization()` never reaches either, so a page registered
  without a decision was open to anybody who could reach the panel — in silence,
  with nothing to read. The guard names the screens in the exception. Filament's
  own screens are its own business; this is about the ones somebody registered.
  Turn it off with `guard.pages` / `guard.widgets`, which have been in the config
  file since `0.1.0` and are read from here.
- **`filament-warden:audit`**, which writes nothing and reports six things:
  screens nobody guards, resources whose model has no policy, permissions no
  grant points at, grants for actions nothing declares any more, whole entity
  types nothing declares — a morph alias that moved — and models only a relation
  manager reaches. With `--check` it exits 1, which is how a build goes red and
  the only way the guard reaches CI at all: no artisan command ever starts a
  panel.
- **Relation managers are walked, for the half that is free.** One that declares
  `$relatedResource` hands over its model through two public statics, so its
  actions now appear in the grid without anybody declaring anything. One that
  declares only `$relationship` is named by the audit instead: reaching its model
  means running the relationship, and a `MorphTo` does not fail there — it
  quietly answers with the owner's model.

### Fixed

- **A resource pointing at a class nobody wrote no longer takes the grid with
  it.** `Resource::getModel()` guesses `App\Models\{Basename}` when the
  resource does not declare one, and the catalogue built it without asking: the
  whole grid died on an `Error` naming a class the developer never wrote. It is
  skipped now, and the audit names the resource.
- **A policy that cannot be built is no longer fatal either.**
  `Gate::getPolicyFor()` throws when the registered class does not exist, and
  anything the policy's constructor throws comes out the same way.

### Not included

- The audit deletes nothing. `warden:clean` is what removes orphans, and putting
  deletion in a command that runs on deploy turns a configuration mistake into
  lost data.
- A relation manager that declares only `$relationship` is reported, never
  resolved. Declare its model in `catalog.models`.

## [0.7.0] - 2026-08-19

Handing roles out from the account's own screen, and the way back.

### Added

- **A field for handing roles to an account.** One line in the consuming
  application's own account form:

  ```php
  use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;

  RoleAssignment::make('roles')->columnSpanFull(),
  ```

  It is a field and not a relation manager for two measured reasons. A package
  cannot attach a relation manager to a resource it does not own —
  `Resource::getRelations()` is a concrete static and nothing can write to it —
  and the actions of one, `AttachAction` and `DetachAction`, **check no policy at
  all** in Filament 5.7: they are gated only by `isReadOnly()`, which is false on
  any edit page.
- **You may hand out a role if you may edit it.** No new permission: `update`
  over a role is already in the catalogue, and property 3 of this package
  already says whoever may edit roles hands out everything. A role you cannot
  edit is shown, locked, and carries the reason.
- **An assignment narrowed to a context is shown, marked and left alone**, the
  same way a narrowed cell of the grid is. Taking it back from here would take
  every context with it, which is not what a checkbox says.
- **`filament-warden:assign {role} {authority}`** — the way back, for whoever
  locks themselves out. Same `Class:id` shape warden's own `warden:show` reads.
  It refuses a role that does not exist rather than creating one, which is what
  assigning by name would have done.

### Fixed

- **A role handed out from a screen answers immediately.** `attach()`, `detach()`
  and `sync()` all skip warden's cache bump — only its own actions make it — so
  the usual `->relationship()` approach leaves every check answering the old way,
  silently and with no expiry. Every write here goes through the fluent API.

### Not included

- The command only hands roles out. You lock yourself out by not having a role,
  never by having one, and a console command that takes access away turns a typo
  into lost access.
- Assigning a role *on* a context is not offered: contexts are `0.10.0`. An
  assignment that already has one is shown and protected.
- No bulk toggle on the field: one click that hands out every role in the
  installation, with the untouchable ones dropped server-side in silence, is not
  a convenience.

## [0.6.1] - 2026-08-19

### Fixed

- **A role holding the wildcard no longer tallies zero.** The tab counters
  counted what the role had written on each cell, and a role holding `*` over
  `*` has written nothing on any of them — `*` is not a cell. The grid drew
  every cell as granted and every counter said `0`, which is the same failure
  `0.3.1` fixed for the drawings and left in the counters. They now count what
  each cell **answers**, and the browser is handed every drawable cell so it
  counts the same way.

## [0.6.0] - 2026-08-19

The permissions screen. The catalogue, with its provenance visible.

### Added

- **A permissions resource, registered out of the box and read-only.** With the
  conservative defaults — no manual creation, editing limited to loose
  permissions, deletion limited to orphans — it is above all the screen that
  says where each row came from, which is what warden cannot tell you.
- **Provenance, derived by reading the store against the code**: from a policy,
  loose, the wildcard, or **nothing declares it** — a renamed policy method, a
  typo in a seeder, a screen that was deleted. That last one is the silent
  mistake warden has no way to detect.
- **Reach, as a column**: every row, only what it owns, with conditions, or one
  of the two states this package can read and cannot draw. A twin permission is
  otherwise indistinguishable from the plain row it twins.
- **Who holds it, as counts** — roles, accounts, everyone — with explicit
  denials counted apart, because a denial is a state and not an absence.
- **A test bench**: `explain()` asked the way the application asks it, with a
  real account and a real row. It tells four things apart that warden answers
  identically: a narrowed rule asked about the class, a key that names no row, a
  record put in front of a permission that has no model, and an entity type that
  no longer resolves.
- **The condition builder as a field of its own**, sharing its markup and its
  script with the one in the grid's inspector. The three alternatives collapse
  to two here: ownership is a checkbox of its own on this form.
- **Every field says why it is closed.** No model behind the permission, no
  ownership column on the table, a rule the installation does not allow editing
  — each one draws the reason where a person is already reading.
- All six `permissions.*` switches are read: `create`, `update`, `delete`,
  `constraints`, `only_owned` and `probe`.

### Fixed

- **Renaming a permission no longer leaves the title lying.** Warden writes it
  in the `creating` hook and only when it is null, and blanking it does not
  bring it back — the hook is not consulted on an update at all. It is
  regenerated here, and only when nobody had written one by hand.
- **Every write refreshes warden's check cache.** Nothing in warden invalidates
  it for a write made through the model layer: only its own fluent actions bump
  the version, and the permission events have no listener. An edit would have
  gone on answering the old way, silently and with no expiry.

### Not included

- Deleting a permission removes its grants through a foreign key, below Eloquent
  and with no event of its own. The confirmation names who loses it, because
  afterwards there is no trace. There is no bulk delete.
- Editing the conditions of a permission changes the rule for **everybody**
  holding that row: a constrained permission is a shared twin. That is correct
  for a catalogue and it is said on screen.
- Orphaned twins are still not pruned; the audit command is `0.8.0`.
- Handing roles out from an account's own screen is `0.7.0`.

## [0.5.1] - 2026-08-19

Two switches that did nothing, and a guarantee nothing tested.

### Fixed

- **`grid.explain` and `grid.constraints` are read.** Both were declared in the
  config file and consulted nowhere, so an installation that turned either off
  got the feature anyway. Turning the inspector off now stops the panel being
  asked why; turning the builder off stops it being asked what a condition could
  be built from, and neither is drawn.
- **Closing the builder no longer widens the rules it can no longer show.** With
  the builder off, the screen sends no reach at all — which is not the same as
  sending "every row", and reading it as the latter would have taken the
  conditions off every narrowed cell of the grid the first time somebody saved.

### Added

- **A test that a protected role survives the delete action itself**, and not
  only the check behind it. `Resource::canDelete()` does not gate the built-in
  action — Filament's actions call `getDeleteAuthorizationResponse()` directly —
  so the guarantee rests on the table's own `->visible()`. Removing that line
  deleted a protected role through a plain livewire call with the whole suite
  still green. Now it goes red.

## [0.5.0] - 2026-08-19

The condition builder. A cell now carries two things, and they are saved together.

### Added

- **How far a rule reaches, as a choice of three**: every row, only what it owns,
  or with these conditions. It lives in the inspector, under the explanation, and
  is offered on a cell that says something whose row has a model behind it.
- **A flat list of conditions with a joiner per line**, and the precedence drawn.
  `Group::passes()` binds `and` tighter than `or`, the way SQL does, so the lines
  joined by `and` are boxed together and the preview reads
  `name = editor or (scope >= 2 and title = account.name)`. A flat list that did
  not draw its groups would say the wrong thing.
- **Two kinds of line**: compare a column of the row with a value, or with a
  column of the account being checked. Six operators and no more — no `LIKE`, no
  `IN`, no nulls. `not` is in warden's enum and its serializer refuses any group
  carrying one, so it is not offered.
- **Values are typed by round trip.** `2` is an integer, `2.5` a decimal,
  `true`/`false` booleans, and everything else text — but only when casting back
  to text returns exactly what was typed, so a SKU of `007` stays a string. The
  type is part of what identifies a twin permission: `'2'` and `2` are two rows.
- **A narrowed cell can now be changed**, where before it was shown and disabled.
- **Two marks instead of one**: amber for a rule that needs a record in front of
  it and can be changed here, red for one this screen can read and cannot draw.
- **Two states the grid says out loud rather than approximating**: a rule whose
  conditions cannot be read — a corrupt blob, an empty group, a nested group —
  and a cell the store holds more than one rule for, which is what an edit made
  with the wrong sequence leaves behind. Both are shown, explained and untouched.
- Row presets (`read`, `all`, `none`) now also put every cell they touch back to
  every row. "All" that left a condition underneath said the opposite of what it
  promised.

### Fixed

- **Editing a condition no longer widens the grant.** Warden's `reconstrain()`
  deletes only the grant pointing at the permission it has in hand, so a fresh
  `allow()->to()->where()` starts from the plain row and leaves the previous
  twin's grant standing — the old condition goes on authorizing and nothing says
  so. Every cell is now cleared in both shapes before it is written, because
  `to()` and `toOwn()` are disjoint revokes.

### Not included

- No nested groups, and no way to build one: the builder draws a flat list.
- **Orphaned twin permissions are not pruned.** A twin another role still points
  at is reused and kept; the audit command in `0.8.0` is where they are reported.
- The permission resource, with the builder embedded and the ownership checkbox,
  is `0.6.0`.
- A condition is only ever written through warden's fluent API. `options` is
  never touched by hand: valid JSON that does not deserialize fails closed in
  both polarities, but invalid JSON is decoded to `null` by Eloquent before the
  serializer sees it, and the resolvers then treat the rule as unconditional.

## [0.4.0] - 2026-08-19

Reading a role. Every cell can now say why it is the way it is.

### Added

- **An inspector beside the grid.** Click a cell and it says why: the cause, the
  decisive permission, and the role it came through. It tells "explicitly
  forbidden" apart from "warden abstains and your policies decide" — the only
  helper warden ships conflates them, and that distinction is the reason this
  panel exists.
- It is asked, never volunteered. `explain()` costs three to seven queries with
  no cache and no batching, so a grid that explained every cell on render would
  spend more than a hundred on a screen nobody may question. One cell at a time.
- **It says when the screen and the store disagree.** The answer is always about
  what is stored — that is all `explain()` can read — so when a cell has been
  cycled and not saved, the panel says so rather than appearing to contradict it.
- **It says when a rule is narrowed**, which `explain()` cannot. A permission
  carrying conditions is skipped and reported as "nothing matched", and on a role
  grid — where cells are asked about a class with no record in front of them — a
  narrowed rule can never match. Left alone it read as if the rule were not there.
- **A read-only screen for a role**, with its infolist and the same grid drawn
  from the store. `view` and `update` are two permissions in warden, so somebody
  may be trusted to read a role and not to change it, and until now they had
  nowhere to look. The inspector answers there too: understanding is reading.
- The eight causes are translated in English and Spanish, and the raw case is
  shown beside the sentence.

### Not included

- The inspector explains **what is stored**, not what is pending.
- It does not list the conditions of a narrowed rule, nor say which one failed —
  warden does not know, and editing them is `0.5.0`.
- "Granted to everyone" is what warden concludes without confirming a grant for
  everyone exists. The raw case is printed so it can be traced when it does not
  add up.

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
