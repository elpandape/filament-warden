# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API changed between minor versions. From `1.0.0` on,
what is covered is listed under **Stability** in the README and pinned by
`tests/FrozenTest.php`.

## [1.3.1] - 2026-08-22

Found by opening a browser, not by the suite: the condition builder has never worked in any
installation of this package, on any version, since the screen it lives on shipped.

### Fixed

- **The condition builder drew nothing, in every browser, on every version since `0.6.0`.**
  Opening a permission with a stored condition — or any permission with a model behind it at
  all — drew zero rule lines and threw seven console errors, the last of which killed the whole
  Alpine component before it could construct itself: `$entangle is not defined`, then `clauses`,
  `interactive`, `source`, `rules`, `preview`. Two templates start the same
  `wardenPermissionGrid(...)` component the same way. `grid.blade.php` composes its binding as
  `$wire.$entangle('{path}')` — the form Livewire still supports for reaching a component's own
  state from inside `x-data` — and `condition-builder.blade.php` entangled bare:
  `$entangle('{path}')`, with no `$wire.` in front of it. A bare `$entangle(...)` is not a magic
  Alpine resolves on its own; it is a directive Livewire's own `wire:` bindings register, invisible
  to a plain `x-data` expression. `condition-builder.blade.php` now composes the same `$binding`
  the grid does, the same shape, so the two templates read alike. Confirmed against a real
  installation, both before the fix (`localhost/admin/security/permissions/{id}/edit`, zero rule
  lines, seven console errors) and after (rules drawn, no errors), and reproduced by downgrading
  that same installation to `1.2.0`: identical failure — **this is not a `1.3.0` regression**, it
  is as old as the field itself. `git log --follow` puts the first version of
  `condition-builder.blade.php`, bare `$entangle` and all, at the commit that shipped it —
  first released in `0.6.0`.
- **Why no gate caught it.** `PermissionGridTest.php:195` already
  asserts `->assertSee('$wire.$entangle(', escape: false)` for the grid — the exact string
  this defect was missing. Nothing in the suite made the equivalent assertion for the builder:
  `grep -rn "condition-builder\|entangle" tests/` returned only the grid's own lines. The package
  had already written the correct check once and never pointed it at the second screen that needed
  it. A new test, `ConditionBuilderTest.php`'s *"the state travels to the browser wire-scoped,
  exactly as the grid does"*, pins the builder's half the same way; it mounts the field through a
  real Livewire round trip (`ConditionHost`, the same pattern `GridHost` already uses for the
  grid) and asserts the rendered `x-data` carries `$wire.$entangle(`. Verified red against the
  unfixed template before this fix landed: `assertStringContainsString` failed, reporting the
  bare-entangled `x-data` markup with no `$wire.$entangle(` anywhere in it.
- **The consequence for `1.3.0` specifically: its own boolean-misfit warning could not be seen at
  all.** That release added `x-show="booleanMisfit(rule)"` to warn when a condition compares
  `true`/`false` against a column the model does not cast to boolean (see `1.3.0`'s own entry
  above) — Alpine markup, inside the same component this defect kept from ever mounting. The PHP
  side of that guarantee was real and tested; the half a person was meant to see in a browser
  never rendered, on any installation, until this release.

## [1.3.0] - 2026-08-22

`1.0.2` stopped the screen *writing* the wrong thing. `1.1.0` stopped it *saying* the wrong thing.
`1.2.0` stopped **the build** saying the wrong thing. This release stops the screen **rewriting** a
rule nobody asked to change.

**What this release owes you: the shapes it stops editing.**

| Stored as | Through `1.2.0` | From `1.3.0` |
|---|---|---|
| the string `'2'` | editable; a save rewrote it as the integer `2` | drawn, explained, no longer editable |
| the string `'2.5'` | editable; a save rewrote it as the float `2.5` | same |
| the string `'true'` or `'false'` | editable; a save rewrote it as a boolean | same |
| a leading `or` | editable; a save rewrote it as `and` | same |

A row shaped like one of these can no longer be edited from this screen, and it can still be edited
from warden's own fluent API.

### Fixed

- **A save that only touched a permission's title could silently rewrite the condition attached to
  it.** `PermissionForm::conditionsWritable()` asked whether a stored rule could be *parsed*, not
  whether it came back byte-identical — exactly the debt `1.2.0` deferred here by name. `lockedReason()`
  is now the single decision-maker: it re-serialises the rebuilt rule through `ConstraintSerializer`
  and compares it, order-insensitive on maps and order-sensitive on lists, to what is actually
  stored — the same question warden itself asks when it decides two rows are the same twin — before
  calling a row writable. Two locks that used to borrow the generic "a shape this builder cannot
  draw" sentence now say which cause it actually is: an `entity_type` that no longer resolves to a
  model, or a stored column the table no longer has. A third cause, new outright, names the
  round-trip failure itself (see the table above). One case is deliberate and stays: a leading `or`
  always reads back as `and`, because `Narrowing::conditions()` normalises the first line on
  purpose — that row is not "fixed," it is now honestly locked instead of silently rewritten.
- **A condition comparing `true` or `false` against a column the model does not cast to boolean
  stored, drew, explained itself, and never matched a single row — silently, forever.**
  `Value::cast('true')` returns PHP's own `true`, and warden compares it with `===` against the raw
  attribute, which on an uncast column comes back as `1`. Both screens that draw a condition — the
  permission form's builder and the role grid's own cell inspector — now show a warning next to
  that value, sourced from one place (`Conditions\Words::all()`) so neither can drift from the
  other. Wiring the inspector's half (`DrawsThePermissionGrid::narrowingFor()`) was outside this
  fix's own file list; without it, the cell inspector would have shipped calling `.includes()` on
  `undefined` the moment any rule row compared a column against the literal `'true'` or `'false'`,
  breaking that row of the screen the warning exists to serve.
- **Unticking a role assigned outside the tenant you were viewing from deleted nothing, reported
  success, and came back ticked on reload.** `Assignment::of()` reads under warden's tenant scope,
  which with a tenant active answers *global or this tenant* — so a globally assigned role shows as
  held from inside any tenant, while `Warden::retract()->from()` deletes one exact scope. For a role
  held only globally, that exact-scope delete already touched nothing on its own; for a role held
  both globally and at the active tenant, it silently destroyed the tenant-scoped row while the
  global one kept the checkbox ticked — a real loss the screen never reported either way. Both cases
  now read as `elsewhere`, the same shape the permission grid already gives a grant that belongs to
  another tenant: shown, marked, and left out of the diff. Measured cost:
  `Assignment::descriptions()` now reads the assignments table 5 times for two held roles, capped at
  8 (see "Not included" below — this is a real, accepted increase, not a wash). **A bug in the fix
  itself, found and closed during this same task**: the first version compared a row's `scope`
  against the write scope with strict `!==`; warden types a tenant id `int|string|null` while the
  column is a plain integer, so a `TenantResolver` returning a string tenant id would have had
  *every* correctly scoped assignment misclassified as elsewhere — locked, un-retractable,
  everywhere, for that installation. It now compares as text, mirroring how `RoleGrants::writable()`
  already solves the identical mismatch for the permission grid.

### Added

- **An eighth `make ci` gate, `helpers`.** A duplicate name among the suite's unnamespaced global
  test helpers is a fatal at PHP's load phase, confirmed by deliberately declaring one:
  `Cannot redeclare function stylesheet() (previously declared in /app/tests/PackageTest.php:24)`,
  thrown before a single test runs. No test inside Pest can catch a failure that happens before
  Pest starts, so this check runs on the host, outside Docker, as a `grep`/`sort`/`uniq -d` pass
  over `tests/*.php` — not PHP execution, so it does not need the container. (Not counted here on
  purpose: the tally moves every time anybody adds one — `1.2.0`'s own entry below already dates
  its count to the tree it measured, and two tasks inside this very release added a helper each.)
- **Five new keys** (`en` and `es`; the flattened translation list moves `185 → 190`, both locales
  identical): `conditions.boolean`, `conditions.locked.model`, `conditions.locked.column`,
  `conditions.locked.rewrite`, `relations.roles.elsewhere`.

### Not included

- **The same round-trip hazard this release closes on the permission form is still live on the
  role grid, and nothing here fixes it.** `RoleGrants::changes()` decides whether a cell moved by
  comparing `Narrowing::is()`, which compares `toPayload()` — pushing every value through
  `Value::text()`, with `Narrowing::conditions()`'s own first-line normalisation already applied —
  the exact distinctions the new guard exists to catch. An untouched cell is correctly left out of
  the diff, but the moment somebody flips that cell's stance, `RoleGrants::write()` calls `narrow()`
  and re-applies the *cast* value and the *normalised* logic instead of what is actually stored: a
  `'true'` held against a column the model does not cast to boolean, which never matches today,
  would then match every row. Same hazard, other screen. Deferred, not fixed here.
- **The `Catalog` and `Holders` memo, and with it the query budget of these screens.** Deferred to
  `1.5.0`. This release makes a measured cost *worse*, on purpose, and caps it with a test instead
  of fixing it: `Assignment::descriptions()` now asks two things per role instead of one, 5 reads
  measured for two held roles against a ceiling of 8.
- **The relation manager for handing roles out from the account screen.** Deferred to `1.4.0`.
  `RoleAssignment`'s own checkbox field is what this release touched.
- **`conditions.locked.model` has no test asserting it is ever actually displayed.**
  `PermissionForm::conditionsHelp()`'s earlier `no_model` guard checks the *live form's* current
  `entity_type` before `lockedReason()` ever runs, so that branch is reachable today only through
  the `disabled()` boolean, never through the help text a person reads — seeing it for real needs a
  stored `entity_type` that does not resolve **with** a live form value that does.
- **`RoleGrants::writable()` has no test for a string-typed tenant id**, the same systemic gap this
  release closed in `Assignment`, left open on its older sibling on purpose: that method already
  compares as text (fixed earlier, for an unrelated reason, per its own docblock), but nobody has
  proven it against a resolver that hands back a string — this release does not touch pre-existing
  code outside what it set out to fix.
- **Nobody opened a browser.** The boolean-misfit warning and the three new lock sentences are the
  only visible surface this release adds, and none of it has been seen rendered — in light or dark,
  against a real installation holding a row whose condition value is the string `'2'`. Verified end
  to end through Pest's HTTP/Livewire layer only. Pending, not seen.

## [1.2.0] - 2026-08-22

`1.0.2` stopped the screen writing the wrong thing to the database. `1.1.0` stopped it *saying*
the wrong thing on screen. This release stops **the build** saying the wrong thing. The headline
is not any single fix below — it is that using the grid the ordinary way turned
`filament-warden:audit --check` red forever, and the only way back to a green build was to stop
using the grid.

### Fixed

- **Turning a cell off in the grid turned `audit --check` red, permanently, on the very next
  run.** Turning it on mints a row in `permissions`; turning it off calls
  `RevokesPermissions::revoke()`, which only deletes from `grants` — the catalogue row survives,
  and `Audit` reported it as an actionable finding indistinguishable from a real problem. `Audit`
  now splits that population in two: a permission the catalogue still declares but nobody holds is
  informational (`php artisan warden:clean` is what removes it, and `--dry-run` shows the list
  first), and a permission nothing declares at all — the case a rename actually leaves behind —
  keeps failing the build. `isClean()` is a flat conjunction, six terms before this release and
  six after — one term swapped out for another, so a check that only counts how many terms it
  combines cannot tell the old, buggy gate from the fixed one; only checking *which* six does.
  Verified by breaking the fix itself, both ways: putting the old term back into the new gate
  turned `--check` red again on an ordinary saved cell while the pre-existing "red build" test
  stayed green, and taking the new term back out reddened four named tests — three of them
  asserting `isClean()` directly, and the one test in `AuditCommandTest` that drives `--check`
  itself to a nonzero exit code through `forgotten` (a second such test exists, over an unpoliced
  resource, and is untouched by this term).
- **A permission that is genuinely undeclared but pinned to a single record can no longer turn the
  build red, and that is a real reduction in what CI catches, decided on purpose.** Whether a
  permission is "declared" is a question asked of the catalogue, which is keyed by class; a
  permission clamped to one record has no class-keyed answer to give, so it is now routed to the
  informational bucket alongside a permission on the wildcard (which really is unused, and
  `warden:clean` really will delete it — the informational bucket says so rather than staying
  silent) and, unreachably in practice since a permission's name is `NOT NULL`, a non-string name.
- **The Stability table's promise that every config key path is frozen was not true for any of
  them — the pin only ever named the six top-level blocks, so all 27 inner paths were
  unpinned.** The obvious fix is reusing `flattenKeys()`, the helper the translation pins already
  use, but it recurses into any array: a key whose value is an empty array produces no entry at
  all (`guard.panel`, `catalog.models`, `catalog.custom`) and a key whose value is a list explodes
  into per-index entries instead of naming the key itself (`roles.protected`, and the four
  `catalog.scopes.*` buckets that feed `Scope`) — that fix would have closed 19 of the 27 and
  silently missed the same eight. A new pin stops descending at exactly a list or an empty array
  and names all 27 leaves by path and by shape (19 scalar, 3 empty, 5 list). Measured by deleting
  each of the 27 in turn: the new pin goes red on every one, including the eight `flattenKeys()`
  alone would still miss.

### Added

- **`console.audit.forgotten`**, the release's one new key (`en` and `es`; the flattened
  translation list moves `184 → 185`, both locales identical). `console.audit.orphans` keeps its
  text byte-for-byte — confirmed insertion-only in both language files — because that sentence was
  already written for the informational bucket and any installation that published translations
  has the key frozen: renaming it would have been silently overridden by a stale copy, printing the
  old reassuring sentence over a red CI failure. What changes is which **property** the word
  answers for, not the key or its wording.
- **The seven product properties this package exists to hold each now have a named, breakable
  test — three of them for the first time.** An unpoliced resource reddens
  `filament-warden:audit` itself, not only the `Audit` class underneath it. Whoever may edit a role
  is proven to hand out everything the panel declares, including the wildcard cell, to themself as
  much as to anyone else. A denial and a grant genuinely coexisting on one ordinary cell — not
  produced through the fluent API's `apply()`, which always deletes the opposite when it writes one
  — is proven to read as forbidden. The account-repair recipe the README prints (`everything()` +
  `filament-warden:assign`) is run end to end against a real panel door, before and after. And a
  guarded widget is proven to stay out of a real page render, not only out of a static call to its
  own guard method.

### Not included

A minor: one new key, and nothing on a screen changes because of this release — everything above
is a machine's business.

- **The round-trip identity guard.** A value stored as the string `"2"` still returns as the
  integer `2`, and a rule whose first line is `or` still returns as `and`, on a save that only
  touches a permission's title. Deferred to `1.3.0` **with its own headline**, because closing it
  removes editing from rows a consumer can edit today, and that is a visible behaviour change that
  belongs in a release note nobody has to go looking for.
- **The column-type warning.** `Value::cast('true')` fails closed on any model without `'boolean'`
  in its `$casts` — correct, but silent about why. Same reason, same release: `1.3.0`.
- **A cap on how many record-pinned rules the grid lists above its tabs before summarising.**
  Declined here because a cap needs an "…and N more" sentence in both locales — that is a key, and
  this release's one key belongs to the audit split above.
- **The word "orphaned" now means three things in this package, and only two surfaces agree.**
  `warden:clean`'s predicate and `Holders::isOrphaned()` (which gates `permissions.delete`) still
  mean what they always meant; the audit's new informational bucket is a strict subset of both.
  Reconciled in the README, not on screen: saying it on the permissions screen's **Orphaned**
  filter would have needed a second new key, and this release spent its one.
- **A self-grant of the wildcard's MANAGE column through the real `EditRole` screen.** The
  property is pinned server-side; nobody wrote the version that clicks the actual cell.
- **The README's own account-repair recipe is run, but never read.** The new end-to-end test
  proves the recipe works; it does not compare its steps against the paragraph a person actually
  reads, so the two can still drift apart silently.
- **Three of the probe's assertions compare against English translation text that happens to equal
  the underlying enum value today.** Rewording `stances.*` — a copy change, not a behaviour change
  — would turn them red, and nothing in the suite says why.
- **64 unnamespaced global test helpers, where a name collision under `--parallel` is a fatal, not
  a failing assertion.** The tripwire that would catch a collision is run by hand; it is not one of
  the seven gates.

## [1.1.0] - 2026-08-21

`1.0.2` stopped the screen writing the wrong thing to the database. This release stops it *saying*
the wrong thing on screen. The headline is not the accessibility work below — it is that a locked
cell highlighted "Every row," the exact opposite of the reach its own store held, because the
server had already computed the right word and the browser threw it away.

### Fixed

- **A locked cell highlighted "Every row" — the opposite of the reach actually stored.**
  `DrawsThePermissionGrid::stored()` already computed the true word for a cell the grid cannot let
  you edit (`unreadable`, `tangled`, `elsewhere`), but the template only ever read two of its four
  fields — the highlight itself was driven by the pending click state, which `RoleState::toPayload()`
  never populates for a locked narrowing at all, so it fell back to its default of "Every row"
  every time. A role with a tangled or unreadable rule told the reader the opposite of what was
  stored, on every render, not only a cold one. The grid now reads the store's own word and lights none
  of the three buttons for a locked cell. A row that is both "only what it owns" **and** carries
  conditions gets its own sentence instead of borrowing the generic "cannot draw this shape"
  reason, and its stored rule is shown read-only underneath.
- **The inspector on a role that does not exist yet explained nothing, and a failed request left it
  stuck open forever with no sentence.** `explainCell()` and `narrowingFor()` both answered `[]`
  for unrelated reasons — no policy for the panel's own resource, a role with no `id` yet, the
  condition builder switched off by config — and `[]` is truthy in the browser, so the template's
  `why &&` guard let it through: `CreateRole` showed an inspector with a title and an empty body,
  because nothing closed it. A rejected request left `loading` on `true` with no way out. The
  inspector now answers a real sentence for the one case a person actually sees — a role that has
  not been saved — every other guard moved out of the template and into the Alpine model, and a
  failed request always resolves to a sentence, never a stuck spinner.
- **A CRITICAL was found in review after the fix above first shipped, and it is worth repeating as
  the release's own lesson: comparing an object read off Alpine's reactive state is never a valid
  identity check.** The sequencing guard compared `this.selected !== asked`, an object against the
  raw object it was assigned from — but Alpine wraps `x-data` with `@vue/reactivity`, whose `get`
  trap returns a new Proxy on every read of an object-valued property, and a Proxy is never `===`
  its target. The guard was true on every read, including the first, uncontested one: every cell
  click stuck on "Asking the store…" forever, regressing behaviour that worked before this release.
  None of 640 Pest tests caught it — the PHP tests never touch Alpine, and the JS tests assert the
  guard's *text* is present in the file, not that it behaves. Measured by installing the real
  `@vue/reactivity@~3.5.40` Alpine pins and running the actual guard logic: broken on a single
  uncontested click, fixed against five scenarios including two genuine races. The guard now
  compares a primitive counter, never an object.
- **A grid that could not be operated — a `->disabled()` field, or the read-only screen — accepted
  clicks and said nothing.** A protected role already had its own notice; a plain `->disabled()`
  field and `ViewRole`'s read-only render had none. Both now share one sentence — "This grid cannot
  be changed from here: its cells select, they do not cycle." — drawn whenever
  `GridView::isReadOnly()` is true, which a protected role's own stronger notice still takes
  precedence over.
  **If you composed `DrawsThePermissionGrid` into a class of your own, this release is a fatal on
  upgrade.** The trait gained an abstract method, `gridInteracts(): bool`, so both classes that use
  it can say whether the grid in front of them writes. It is not one of the frozen traits —
  implement it (`return false;` if your screen does not write) or the upgrade throws.
- **A grant pinned to a single record was invisible everywhere except a warning that it existed.**
  The permission screen's Reach column already knew a row was pinned to one record but drew it the
  same as any other narrowed row, and the role's own grid — which cannot draw a record-pinned grant
  as a cell — said nothing about which rules those were. Closes the item `1.0.2` left open ("the
  record-scoped grant the grid still discards without a trace"): the permission screen now says
  "One record only" instead of guessing at a shape, and the role's grid lists every record-pinned
  rule it holds, by name, above the tabs, with the reach each one carries — read-only, because this
  screen still cannot change them.
- **A rename refused onto a protected name explained nothing — Laravel's own validation message,
  not this package's.** `Rule::notIn([])` never renders a message when the list of other protected
  names is empty, which it always is for a role editing only its own title, so the refusal fell
  through to the framework's generic wording. Closes the item `1.0.2` left open ("a sentence of our
  own for a protected name"): the role form now writes its own sentence naming `roles.protected`.
- **A permission's name and entity being locked because somebody already holds the row was never
  explained — the fields just went grey.** `PermissionResource::mayEdit()` — promoted from
  `private` to `public`, unchanged otherwise — now backs a helper text that tells a configuration
  decision (`update` is not `'all'`) apart from a holder-based one, and states the holder reason
  only when a holder is the actual cause. Cost, measured: three more `grants` reads per render of
  the field that already had the highest cap on the screen — 14 (up from 11 before this release)
  against a cap raised to 16.
- **Two English sentences read like a template with the blanks still showing.**
  `resources.permissions.delete.holders` and `resources.permissions.fields.conditions_shared` both
  leaned on `:roles`/`:accounts`/`:count` in a way that read as generated rather than written — with
  a single holder the shared-row warning said "held 1 times over." Closes the item `1.0.2` left
  open ("the plural of the shared-row warning"): both are rewritten to read naturally at every
  count, by rewording rather than pluralising.
- **`falla cerrado` disagreed with its own subject.** `conditions.warning`'s Spanish text paired a
  masculine ending with the feminine `Una comprobación de clase`; corrected to `falla cerrada`,
  matching how the identical fact is already phrased elsewhere in the same file.
- **Six declarations read `--fw-ghost` at roughly 1.34–1.48:1 against its background — below even
  WCAG's lowest text threshold, nowhere near AA's 4.5:1.** All six now read `--fw-muted`, and
  `--fw-muted` itself moved in light mode from `--gray-500` to `--gray-600`. Measured, recomputed
  independently through Filament's own OKLCH palette: 7.01–7.73:1 against both backgrounds it
  appears on (dark mode was already 5.78–6.74:1 and is unchanged). The now-unused `--fw-ghost`
  token is deleted from both palettes.

### Added

- **Every cell drew one of seven states as a single character or a colour, with nothing a screen
  reader could announce that told them apart.** All seven — no rule, granted, forbidden, reached by
  a broader rule, narrowed, not changeable here, not declared — now carry their own word, attached
  to the cell's accessible name instead of left in a `title` attribute or a colour alone.
- **The tab strip had no keyboard behaviour beyond Tab-to-next.** Tabs now expose
  `role="tablist"`, roving `tabindex`, matching `id`/`aria-controls`/`aria-labelledby` pairs between
  each tab and its panel, and arrow-key navigation between them — the standard ARIA tabs pattern,
  wired by hand, since this package ships no JS framework beyond Alpine.

### Not included

A patch cannot add a key, but a minor can: fifteen new lines land in `lang/en/ui.php` and
`lang/es/ui.php` this release (169 → 184, both locales identical) — and they do not close
everything this screen still gets wrong.

- **The round-trip identity guard.** A stored condition value of the string `"2"` still returns as
  the integer `2`, and a rule whose first line is `or` still returns as `and`. Closing this widens
  the set of rows this screen refuses to edit, which is a visible behaviour change a minor should
  announce on its own — `v1.2.0`.
- **Two contrast remainders, left in on purpose so this release does not read as a clean AA claim.**
  An empty cell's `--fw-line` border sits at roughly 1.27:1 against its background — WCAG 1.4.11
  territory (3:1, UI component boundaries), not 1.4.3, and raising it is a visual decision this
  release does not make. A "reached by a broader rule" tick is drawn at `opacity: 0.62` over its
  colour on purpose — the hollow mark is meant to read as quieter than a written one.
- **This package's JS has no gate.** None of the six `make ci` gates touch
  `resources/js/permission-grid.js`. The only executable evidence its logic behaves as documented
  is two scripts run by hand against the real `@vue/reactivity` package Alpine pins —
  `verify/verify-select-sequencing.mjs` and `verify/verify-reach-of.mjs` — which is how the
  CRITICAL above was actually confirmed, not guessed at. Whether to add Node as a seventh gate (the
  Docker image behind `make ci` is `php:8.5-cli-alpine`, which has neither Node nor npm) is a
  decision for whoever maintains this package next; this release does not make it for them.
- **Nobody opened a browser.** Every fix above was verified by a real HTTP/Livewire round trip in
  Pest, and the two scripts above ran the real JS logic under Node — but no consuming Filament
  application was available to confirm any of it in Chrome, in light and dark, or with a screen
  reader. Not performed for this release; carried forward as an open checklist.
- **What a browser would still find: this release fixes reading the grid, not operating it.** The
  seven grid states, the ARIA tabs pattern and the record-pinned sr-only text all land this
  release, but a cell's accessible name changing after a click is never announced on its own:
  `.fw-box` carries no `aria-pressed`, and nothing about a pending stance reaches the one live
  region this screen has — `role="status"` is wired to the inspector's verdict only. A
  screen-reader user who cycles a cell has to leave it and come back to hear what it now says.
- **Five templates changed, not three, and two of them changed nothing you would notice.**
  `vendor:publish --tag=filament-warden-views` publishes all of `resources/views`, and this release
  touches `grid.blade.php`, `box.blade.php`, `builder.blade.php`,
  `resources/views/forms/permission-grid.blade.php` and
  `resources/views/infolists/permission-grid.blade.php`. A published copy of the first three is
  welded to the pre-`1.1.0` markup and keeps every defect this release fixes — the locked-cell
  highlight, the silent read-only grid, the missing accessible names — until reintegrated. The
  other two only moved a decision the field and the entry already made (`gridInteracts()`) into
  `$grid->isInteractive`, the same value the old inline expression computed in both callers: a
  stale copy of *those two specifically* behaves identically and reintegrating them buys nothing.
- **A stale published translation file is a narrower hazard than it looks.**
  `vendor:publish --tag=filament-warden-translations` does layer your copy on top of the package's
  own, but through Laravel's real merge — `FileLoader::loadNamespaceOverrides()` calls
  `array_replace_recursive($packageLines, $publishedCopy)`, a recursive merge, not a wholesale
  replacement. Measured: a stale override freezes only the individual paths it declares, and every
  other key, including untouched siblings inside a partially-overridden block, still arrives from
  the package — this release's fifteen new keys among them. Even a genuine miss would not show a
  raw dotted path: `GridView::translated()` falls back to a readable label. The hazard that is
  real, and worth reconciling: if you published before this release, your copy still serves
  whatever text it declared for `resources.permissions.delete.holders` and
  `resources.permissions.fields.conditions_shared` — the two sentences `1.1.0` reworded, including
  the "1 times over" plural this release exists to fix. Diff just those two keys against
  `lang/{en,es}/ui.php`.
- **Run `php artisan filament:assets` regardless of either hazard above — skipping it is not a
  quiet downgrade.** Both `resources/js/permission-grid.js` and `resources/css/permission-grid.css`
  changed, and neither change degrades gracefully. Against the old script, `stateOf()`,
  `reachedMark()`, `markOf()`, `reachOf()`, `stepTab()` and `edgeTab()` do not exist — confirmed
  against the file as it stood at `v1.0.2` — so every `x-text`/`x-bind` in the new markup that calls
  one throws in Alpine, on every cell and every condition-builder mode button; arrow-key and
  Home/End navigation on the tabs throws too, since `stepTab()`/`edgeTab()` call the equally
  missing `openTab()` internally. Against the old stylesheet there is no `.fw-sr` rule — also
  confirmed absent at `v1.0.2` — so the four screen-reader-only spans this release adds render as
  ordinary text inside each cell's 1.5rem box, and the grid's layout breaks open.

## [1.0.2] - 2026-08-21

A reading of the whole package after `1.0.1`, not a report from an installation this time. Two
holes open under tenancy, four places that could rewrite a consumer's own data, a screen that
only reads and drew itself empty, a name that could unprotect a role, a join that assumed a
primary key it does not own, and a README that documented two things that do not exist — one of
them fatal at login.

### Fixed

- **A role held only under another tenant could still be deleted from this one.**
  `RoleResource::isDeletable()` read `assigned_roles` through warden's own tenant scope, but the
  foreign-key cascade that removes those rows on delete does not filter by scope at all —
  `$record->delete()` skips it the same way. Measured: a role assigned only under tenant 7 read as
  unassigned from tenant 8, and also with no tenant active under `scope.null_behavior => 'strict'`;
  from either vantage point the delete button opened, and going through it took every tenant's
  assignments and grants with it. The check now reads across every tenant before it answers, the
  same rule `Holders::of()` and the audit command already followed for the mirror case.
- **A role-grant kept deliberately global by `scope.role_grants => false` was locked as if it
  belonged to somebody else's tenant.** The grid compared a stored grant's scope against a bare
  `writeScope()`, which always answers the active tenant — but warden itself writes a role's grant
  with `scope = NULL` whenever role-grant scoping is turned off. The cell was drawn locked, marked
  as another tenant's, and dropped silently from the diff, even though a save through `disallow()`
  would have deleted the grant outright: the caution was not conservative, it was wrong in both
  directions. The grid now asks the same question warden asks itself,
  `writeScope(forRoleGrant: ...)`.
- **A Policy action named `manage` collided with the grid's wildcard cell.** The wildcard column
  that draws warden's `*` permission was filed under the state key `'manage'` — a name an
  application's own Policy is free to declare as an action. Measured with a fixture Policy that
  does: granting that one cell wrote two grants at once, and left `viewAny` granted on that row
  with nobody asking for it. The wildcard now files under `*`, the one name warden itself reserves
  and no Policy method can be called.
- **A role's read-only screen drew the grid correctly and then blanked it.** The infolist handed
  Alpine the literal `'{}'` as its state, and the script re-derives every cell and every tab's
  tally from that object the instant it boots — so the HTML the server rendered was right and what
  the browser showed a moment later was empty, tallying zero. It survived two minor versions
  because the one test on this screen asserted what the server drew, never what it handed to the
  browser. The read-only grid and the form now share one payload builder, and a test compares the
  two screens against each other rather than each against its own private expectation.
- **Every role, not only a protected one, announced itself as protected on its read-only screen.**
  The notice was gated on the render being read-only rather than on the role actually being
  protected — since `0.4.0`. The form's coincidentally correct behaviour, which disables the field
  for the same reason it shows the notice, is what hid this for six versions: the two questions
  only happened to agree there.
- **If you ran `vendor:publish --tag=filament-warden-views` on `1.0.1`, neither fix above reaches
  you until you reintegrate your copies.** Both live in the published templates —
  `resources/views/grid.blade.php` and `resources/views/infolists/permission-grid.blade.php` — and
  a published copy silently keeps the old behaviour of both: the read-only grid still draws itself
  empty, and every role still claims to be protected.
- **A permission that was both "only what it owns" and "with these conditions" was drawn as plain
  ownership, and the first save dropped the condition.** Warden writes exactly that row — `toOwn()`
  followed by a chained `where()` copies ownership onto the twin — and honours both halves when it
  resolves. The screen read ownership first and stopped before it ever looked at the conditions,
  which made the cell editable; saving it then rewrote the row with bare ownership. It now reads as
  a reach this screen cannot draw: shown, explained, excluded from the diff. Broken on purpose to
  see it — with the old order back, saving a grid that touched nothing else deleted the condition
  and left the count at zero.
- **A stored rule the permission form cannot read back was erased by a save that only touched the
  title.** The condition builder writes the rule it could parse and clears the field for everything
  else, so a corrupt blob, a rule naming a column the table no longer has, and a rule on a
  permission with no model behind it were all replaced with SQL `NULL` by any save of that screen —
  and a cleared rule is the one shape that fails open, so what vanished was a restriction, silently.
  The builder is now closed for those rows and says why.
- **Saving a derived permission under the shipped `'loose'` default could wipe its title.** `name`
  and `entity_type` are disabled for a derived row, and Filament drops a disabled field from the
  saved data entirely rather than sending it as empty — so the title-regeneration code read the
  absence as an empty string and wrote an empty title. Opening a permission like `Publish posts`
  and saving without touching anything left the title blank. The rename code now tells "absent"
  apart from "cleared on purpose" and falls back to the record only for the first.
- **A role could be renamed onto a name the installation protects.** Nothing stopped a rename that
  landed exactly on `super-admin`, or any other name in `roles.protected` — the field only locks a
  role that is *already* protected, and the create screen has no record to ask. Renaming onto the
  list is now refused, on both the create and the edit form.
- **A protected role's name could still reach the database if the disabled field was bypassed.**
  `disabled()` keeps a forged rename out of the browser, but the guarantee needs to hold even if it
  is not — the edit page now restores the stored name from the record whenever the role should not
  have been able to move.
- **Two permissions differing only by model, or only by ownership, could not both be named the
  same thing, and a derived row could be blocked from saving by a namesake it shares nothing else
  with.** The uniqueness check compared the name alone; a permission is the tuple `(name,
  entity_type, entity_id, only_owned)`. The field the validation failed on was disabled, so there
  was nothing the person looking at the screen could do about it. The check now compares the full
  tuple.
- **Renaming a permission's name or entity moved what its holders already have, silently and with
  no revocation.** Nothing stopped renaming a loose permission that somebody already held — a
  rename from `widget:AccountWidget` to `page:Billing`, say — and every existing holder of the old
  row gained the new one instantly. Re-pointing a row now needs the installation to allow editing
  every permission, or that nobody holds the row yet; narrowing its conditions or its ownership,
  which does not change what the row means to a holder, still only needs the ordinary edit
  permission. The shared-row warning now fires from a single holder instead of two. The lock costs
  reads to ask: an edit-screen render of the shipped default's most common row — a loose permission
  with no entity, under `'loose'` — measured at 11 grant reads against 3 before this release, one
  `Holders::of()` call per field it now guards.
- **Changing a permission's entity left a stale ownership flag behind.** "Only what it owns" only
  makes sense against the model it was checked on; moving a derived permission to a different
  entity now clears that flag along with the reset it already did to its conditions.
- **Deleting a role or a permission from its own screen left every check still answering the old
  way.** Warden only bumps its cache version from its own six fluent actions — a plain delete,
  which is what every delete button in this package does, left warden's cache oblivious. Measured:
  with the cache warm, deleting an assigned role through its edit screen cascaded the assignment
  away in the database while the check kept answering the old way. All six delete actions — both
  listings and the four record pages — now refresh warden's cache once the delete completes.
- **A join on the permissions table assumed its primary key was called `id`.** Three places
  building a correlated column concatenated `.id` onto the configured table name by hand, next to
  code that already asked the model for its own key. All three now ask the model, the same way. This
  is a consistency change and it ships without a test of its own — reaching the failure needs an
  installation that swaps the permission model *and* renames its primary key, which this suite
  cannot stand up without hand-building warden's schema. What was measured instead: the SQL emitted
  on a stock install is byte-for-byte unchanged, and the two suites already covering these
  predicates in both polarities stay green.
- **The README documented an API that does not exist, and one recipe was fatal at login.**
  `FilamentWardenPlugin::make()->guardPages(true)->guardWidgets(true)` named two methods the
  plugin does not have — it is `final` with exactly four: `make`, `getId`, `register`, `boot` —
  and the `canAccessPanel()` recipe declared the method on the class and called
  `parent::canAccessPanel($panel)`, which silently shadows the trait's own method and has no
  `Authenticatable` ancestor to fall back to: following it as written is a fatal error at login.
  Both now document the working form — `guard.pages` / `guard.widgets` in config, and
  `use AccessesPanels { canAccessPanel as wardenCanAccessPanel; }` — and `tests/FrozenTest.php`
  now pins the plugin's four real methods by name, which is what would have caught the invented
  ones before they shipped.
- **Six more claims in the README were wrong.** `sync()`'s warning said the opposite of the
  danger — it *skips* warden's cache bump, it does not invalidate the cache; the navigation config
  block showed flat keys the package only ever reads nested; `->tenant(null)` is not a Filament
  resource API, the real declaration is `protected static bool $isScopedToTenant = false;`; the
  permissions screen was described as showing the full catalogue when it lists a table a fresh
  install starts with empty; a table-of-contents anchor pointed at a heading that does not exist,
  and `### Permission Scope` appeared twice, breaking its own link; and the banner image 404s and
  was removed.
- **A documentation rewrite after `1.0.0` had quietly dropped four sections the `1.0.0` CHANGELOG
  promised the README carries.** The `$user->can()` vs. `Access` comparison, the shallow-merge
  warning for `roles.protected`, the *account* model's stability note, and `warden:clean` under
  the audit section are back, and the Stability section now names its four frozen methods instead
  of promising "methods" without listing any.
- **`CONTRIBUTING.md` and `SECURITY.md` still said "before `1.0.0`."** Both retired that wording
  now that the surface they were hedging about has been frozen since `1.0.0`.
- **`illuminate/console` and `livewire/livewire` were used and never declared.** `src/Console/`
  imports the first and the grid's Livewire bridge imports the second; both only ever arrived
  transitively through `filament/filament`. Now declared directly — the lock file's resolved
  versions did not move.

### Not included

A patch cannot add a key: `config/filament-warden.php` and `lang/*/ui.php` are frozen whole, so
anything needing a new sentence waits for `1.1.0`.

- **The plural of the shared-row warning.** It now fires from one holder instead of two, but the
  sentence is untouched, so with a single holder English reads "held 1 times over".
- **A sentence of our own for a protected name.** Renaming a role onto a protected name is refused,
  and the message comes from Laravel's own validation, not a sentence this package wrote.
- **The dedicated word for a rule this screen can read but not write back exactly, and for a row
  carrying both ownership and conditions.** Both borrow the reason already used for "a shape this
  screen cannot draw," which names the wrong cause for either one.
- **Two shapes a save can still rewrite without asking**, because the guard added this release asks
  whether a stored rule can be *parsed*, not whether it comes back byte-identical: a value stored as
  the string `"2"` returns as the integer `2`, and a rule whose first line is `or` returns as `and`.
  Both change what the row means. Closing this would widen the set of rows this screen refuses to
  edit, which is a visible behaviour change a patch must not make unannounced.

Also still open, and scheduled: the record-scoped grant the grid still discards without a trace,
the roles relation manager for the account screen, every performance memo this audit wrote down,
and a fresh install's empty permission screen.

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

## [1.0.0-rc.1] - 2026-08-20

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
