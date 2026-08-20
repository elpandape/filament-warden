# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API changed between minor versions. From `1.0.0` on,
what is covered is listed under **Stability** in the README and pinned by
`tests/FrozenTest.php`.

## [1.0.1] - 2026-08-20

Reported from an installation: **there was no way in to either create screen.**

### Fixed

- **Neither listing declared a create action, so no button led to its own create
  page.** Filament adds none by itself — `getHeaderActions()` defaults to an
  empty array — and `grep CreateAction src/` found nothing in the whole package.
  Measured on the reported installation: the authority held the wildcard,
  `create` on `Role` resolved `true`, `admin/security/roles/create` answered
  **200**, and the listing's HTML contained no link to it at all. Both listings
  now carry one.
- **The button's visibility is written by hand, because the config would not
  have closed it.** A create button asks `getCreateAuthorizationResponse()`,
  which goes straight to the policy and never passes through the resource's
  `canCreate()` — where `roles.create` and `permissions.create` live. Broken on
  purpose to see it: with the `visible()` gone, `permissions.create` false out of
  the box still offers a button, and its own create page answers 403.
- **A protected role could be deleted from its own edit and view screens.** The
  same gap, one screen over: those pages now carry a delete action, and it is the
  hand-written `visible()` that keeps `RoleResource::canDelete()` — the protected
  list and the `roles.delete` rule — on the path. Measured with the guard
  removed: `super-admin` was deleted outright and the following assertion died
  with `ModelNotFoundException`. The table's action had this guard from `0.4.0`;
  the pages had no action at all until now.

- **The permission screens had the same hole, with one more gate.** Their edit
  and view pages carry delete now, and the view page carries edit — each with the
  visibility written by hand, because `permissions.update` lives in
  `canEdit()` and the orphan rule in `canDelete()`, both off the authorization
  path. Broken on purpose: a permission two roles held was deleted from its own
  edit screen, and the edit button showed up with `permissions.update` at
  `false`. The delete modal carries the table's own warning — the grants go with
  it by a foreign key, below Eloquent and with no event of their own, so this is
  the last moment anybody is told.
- **The probe was the only header action the view page had, behind an early
  return.** Had edit and delete been added beside it without moving that guard,
  turning `permissions.probe` off would have taken all three away. It is its own
  method now.

### Added

- Header actions on the role screens: delete on the edit page, edit and delete on
  the view page. The edit action needs no guard of its own — the resource leaves
  `canEdit()` alone, so the policy closes it, and a protected role opens with its
  form disabled rather than not opening.
- Header actions on the permission screens: delete on the edit page, edit and
  delete on the view page, beside the probe that was already there.

## [1.0.0] - 2026-08-20

The freeze. **No code changed since `1.0.0-rc.1`** — the candidate went out, was
installed from Packagist into a fresh Laravel 13 + Filament 5 project, and the
published artifact was checked to carry the whole frozen surface: the sixteen
promised classes, six config keys and 169 translation key paths in both locales.

What changed is the documentation, and two pieces of it were stopping people
before they got started.

### Fixed

- **The README never said to run the migrations.** Warden **publishes** its
  schema rather than loading it from vendor, so a fresh install has no tables at
  all and the first thing anybody clicked was a missing one. `warden:install
  --migrate` is now the first step of the installation, with the reason beside
  it. This was missing from every version, not just this one.
- **"Create `super-admin` from the roles screen" could not work**, three times
  over: nothing in this package ever calls warden's `everything()`, so the grid
  cannot mint a wildcard — a permission over `*` is not a cell; the grid is
  disabled outright for a protected role; and by that point in the instructions
  the panel door is already locked against you. The first role now comes from a
  seeder, and the wildcard is measured to open the loose `panel:` permission.
- **`canAccessPanel()` is called from four places, not one.** Folding an extra
  condition into it — email verification was the case that surfaced it — makes
  `Login` throw the *same* validation exception as a wrong password, and makes
  both password-reset pages fail silently while still reporting success. The
  README now says so where people write that override.
- **Four smaller claims were wrong**: `strictAuthorization()` covers relation
  managers and Filament's tenancy pages as well as resources; Laravel's policy
  guessing walks the model's own namespace rather than only looking beside it;
  the permissions screen's icon falls back to a key, not a shield; and the
  wildcard cell makes an entity row one switch wider than its policy declares.
- **The Stability section contradicted itself.** It named five `Catalog\` classes
  as covered and then called everything but four of them internal. The stability
  section *is* the contract; one that disagrees with itself is worse than none.
- **`filament-warden-translations` was a promised extension point with no
  documented command.** The translation keys are covered by semantic versioning
  and the install section showed only two of the three publish tags.
- **"Only loose permissions can be edited" overstated the shipped default.** A
  derived permission's edit screen is reachable and its label has no lock on it.
  What is closed is its name, its entity and its rule — the three things that
  connect it to the policy that asks for it.

### Added

- **A README you can follow from nothing to a working panel**: a table of
  contents, a five-step quick start with the policy, the account model, strict
  authorization, the two traits and the seeder that lets you back in, and a
  configuration reference for all six blocks — which are frozen API and had no
  table of their own.
- **How to ask warden yourself, and why not to use `$user->can()`.** Warden ships
  a `gate.register` switch so an application can register its own gate callback;
  the day one does, `$user->can('some-loose-permission')` starts answering
  `false` with no error to read, because a loose permission has no policy to
  answer for it. `Access` goes straight to the resolver. Measured both ways.

### Not included

- No new behaviour. A `1.0.0` that added something would not be a freeze.

## [1.0.0-rc.1] - 2026-08-19

A release candidate. Nothing new: this is where the package stops moving and
says what can be relied on.

### Coming from an older `0.x`? Read this first

There is no `UPGRADING.md`, so the three things that can bite are here.

- **The panel refuses to start with a page or widget nobody guards** — since
  `0.8.0`. This is deliberate and it is the fourth of the seven properties: a
  screen with no `canAccess()` is open to everyone, and Filament's
  `strictAuthorization()` only reaches resources. Every page and widget you
  register needs `AuthorizesPageAccess` or `AuthorizesWidgetView`. If you need
  the panel up before you get to them, `guard.pages` and `guard.widgets` turn
  the check off one kind at a time — and `filament-warden:audit` lists what is
  open without stopping anything.
- **`php artisan filament:assets` has to run**, and to run again on every
  deploy. It is a file copy, not a build. Until it does, the panel asks for a
  stylesheet that answers 404 and the grid renders unstyled.
- **A grid cell has been stance *and* reach since `0.5.0`**, not a bare stance.
  Nothing in an application reads that state — but a test that filled the field
  by hand will not recognise it.

### Changed

- **Door titles stop being rewritten.** The list of shapes this package
  recognises as its own grew three times in two days while the wording settled,
  and each entry is a licence to rewrite rows in your database because we
  changed our minds about a verb. It is closed at three: what warden generates,
  the bare screen name `0.9.1` wrote, and the single verb `0.10.1` wrote. A row
  carrying any of them is still corrected once; a fourth shape would be a major.
- **The reach count says what it is.** It read `It falls on 3 of 12 rows.` and
  let somebody decide on that. `whereCan()` never consults the Gate, so a policy
  that denies is invisible to it — true of every count, not only the partial
  ones, so both sentences now carry it.

### Added

- **A Stability section in the README**, and `tests/FrozenTest.php` behind it.
  Covered from `1.0.0` on: the permission name prefixes, the plugin and its id,
  the four fields and the `{stances, narrowing}` state a form receives, the
  three traits, `WardenPolicy` and `Access`, `Catalog::for()`, `Entry`, `Origin`
  and `Scope`, `PanelIsOpen`, every config key, every translation key path in
  both locales, and the two commands. Adding a key is a minor; removing or
  renaming one is a major.

  Not covered, said plainly: `Grants\`, `Conditions\`, `Filament\Guard`,
  `Filament\Forms\Grid\` and the rest of `Catalog\`. With the three consequences
  that follow — a **published view is welded to those insides** and should be
  expected to need re-merging, the **resources are non-final for experimenting
  and not as an extension point**, and `whereCan()` is warden's, not this
  package's.
- **The two things the README taught around.** A custom permission name cannot
  carry a dot — Livewire splits state paths on them, and the grid refuses to
  draw rather than render something broken. And `Access` is how a hand-written
  `canAccess()` asks warden through the panel's guard, which `Gate::allows()`
  does not do; it was on the promised list and named nowhere.

### Not included

- No new screens, no new columns, no new config. A candidate exists to be
  installed and contradicted, not to add anything.
- Nothing widens: `php: ^8.5` stays. Widening a constraint is not a break, so
  `8.4` can be added in a minor if somebody turns up needing it.

## [0.10.2] - 2026-08-19

### Fixed

- **A widget is seen, not entered.** `0.10.1` gave every door the same verb, and
  `Access Account Widget` says the wrong thing about a widget: nobody navigates
  to one. The verb now comes from the question Filament itself asks — a page and
  a panel answer `canAccess()`, a widget answers `canView()`, which is why this
  package has two traits and not one. Titles read `View Account Widget`,
  `Access Dashboard`, `Access the Admin panel`.

  A title written by `0.9.1` or `0.10.1` is corrected the next time the grid
  writes that grant. The list of shapes this package has generated grows and
  never changes, because an upgraded installation still carries the older ones in
  its rows.

## [0.10.1] - 2026-08-19

### Fixed

- **A page, widget or panel permission is titled by what it lets you do, not by
  the screen's name.** `0.9.1` stopped the title being the name with one capital
  letter, but left it naming the screen — `Account Widget`, `Dashboard` — while
  every other title in the catalogue reads as an action. They now read
  `Access Account Widget`, `Access Dashboard`, `Access the Admin panel`.

  The verb is not a free choice: `access` is the word the grid already uses for a
  door — `StateKey::DOOR` is literally that — so the title and the column say the
  same thing about the same cell.

  A title written by an older version of this package is corrected the next time
  the grid writes that grant, alongside the one warden generates. A title
  somebody wrote by hand is in neither list and is never touched.

## [0.10.0] - 2026-08-19

Tenancy, honestly. **Read this one before upgrading a multi-tenant panel.**

### Fixed

- **A panel with `->tenant()` no longer breaks warden.** Filament scopes a
  resource by putting a global scope on its **model**, and that scope demands a
  relationship named after the tenant class — so with this plugin installed,
  `Role::query()->count()`, `Role::create()` and every screen threw
  `LogicException`. It did not break two screens: it poisoned warden's models for
  the whole request, its own internals included. Both resources now say they
  belong to no tenant, through the declared property and never through
  `scopeToTenant()`, which is static and would un-scope every resource of the
  consuming application.
- **A cell whose grant belongs to another tenant can no longer be switched off in
  silence.** A write targets one exact scope, so `disallow()` deleted nothing,
  the screen reported success, and the cell came back green on reload. It is now
  drawn, marked and left alone — the fourth reason a cell is locked, beside the
  unreadable one and the tangled one.

### Added

- **The screens say when they are showing every tenant at once.** With no tenant
  active and warden's shipped `null_behavior`, every read is unfiltered: that is
  what the engine answers, so it is what the screen shows — hiding rows would
  make the two disagree and somebody would take a permission away believing it
  was not there. The notice appears only where it means something.
- The permission screen says its holder counts cross every tenant, because
  deleting a permission takes its grants with it through a foreign key and **that
  cascade does not look at the tenant**.
- A full read of both language files: `store` was translated into Spanish as a
  shop in three places, and the reach map had no word for the new shape. Two
  tests now pin the reach and stance maps to their enums, so a new case cannot
  ship without its word.

### Not included

- Assigning a role **on a context** is still not offered from a screen. Warden
  does not expose which classes are valid contexts, and a context-restricted
  assignment is invisible in a grid — which asks about classes — drops out of
  `getPermissions()` and out of `whereCan()`'s grant pass, and `explain()` cannot
  say why. It is shown and protected where it already was.
- Nothing bridges warden's tenant to Filament's. They are two different things
  with the same name, and the bridge is a `TenantResolver` in your application.

## [0.9.1] - 2026-08-19

### Fixed

- **A page, widget or panel permission is no longer titled with its own name.**
  Warden writes a permission's title in the `creating` hook, and for one with no
  entity that title is `Str::ucfirst()` of the name — so
  `widget:Filament\Widgets\AccountWidget` came out as
  `Widget:Filament\Widgets\AccountWidget`, the same string with one capital
  letter, which is what a person then read on the permission screen. Warden is
  not wrong: it has no way to know that `widget:` means anything.

  This package does know — `PermissionName` is the one place those names are
  minted, and now the one place they are read back. A door written from the grid
  is titled `Account Widget`, the permission form suggests the same, and renaming
  one regenerates it the same way. A title somebody wrote by hand is theirs and
  is never touched; a name the application declared in `catalog.custom` is left
  to warden, whose title for it was already good.

### Changed

- CI checks out with `actions/checkout@v7`.

## [0.9.0] - 2026-08-19

How far a permission reaches, said with its limits.

### Added

- **The test bench now says how many rows a permission falls on** for the account
  being probed — `whereCan()` put on screen. It is worked out when somebody asks
  and never on a render: one call is six queries with no cache, and it hydrates
  the whole candidate catalogue every time.
- **It says when the number cannot be trusted, which is half the feature.**
  `whereCan()` and the panel's own checks do not answer the same thing, measured
  in both directions: a role assigned in a context is excluded from the grant
  pass and included in the forbid pass, so the panel can answer `true` for a row
  the query cannot see at all. When the account holds a role in a context, the
  line says the count is a lower bound and why.
- **A model that never opted in is detected before it is asked.** Without
  `QueriesByPermission` the call does not fail: Eloquent turns it into a dynamic
  where — `where "can" = ?`, bound to the authority model — and answers zero rows
  in silence. The screen names the model and the trait instead of printing a
  number that means nothing.
- A query that cannot run is a reason, not a fatal: `only_owned` on a model whose
  ownership attribute is not a column emits invalid SQL and throws at execution.

### Not included

- No count anywhere else. A table column would be six queries per row.
- `whereCan()` never consults the Gate or a policy, so a policy that denies is
  invisible to it. That is warden's design, and it is one more reason the number
  is shown as a bound rather than as a fact.

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
