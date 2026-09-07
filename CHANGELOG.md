# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Before `1.0.0` the public API changed between minor versions. From `1.0.0` on,
what is covered is listed under **Stability** in the README and pinned by
`tests/FrozenTest.php`.

## [2.11.0] - 2026-09-07

The grid keeps the width its own empty column used to waste, the key folds away above the tabs
instead of sitting between a cell and the panel that explains it, and the inspector stops running
two disagreeing sentences together by naming them apart. Nothing about the cycle, the diff, the
save or the `{stances, narrowing, baseline}` envelope moves — every change here is where a pixel
lands or which sentence says what.

### Added

- **Seven translation keys**, in both locales and pinned by `FrozenTest`: `grid.legend.title`,
  `grid.legend.set` and `grid.legend.added` for the folded key's two group headings, and
  `explain.stored`, `explain.screen`, `explain.save_hint` and `explain.matched` for the inspector's
  two voices. An application with translations published sees these seven sentences in English
  until it copies the keys across — `FileLoader::loadNamespaceOverrides()` merges recursively, so
  the new keys still arrive, just untranslated — and an application with the view published has to
  reintegrate it, the same as any release that touches `grid.blade.php`.
- **`verify/verify-two-voices.mjs`**, wired into `make verify`. It drives `storedStance()` and
  `moved()` through the real `@vue/reactivity` Alpine pins, under both payload shapes a host can
  send: the form's, which carries a `baseline`, and the read-only infolist's, which never has and
  never will.

### Changed

- **The grid keeps the width it used to hand to an empty column.** `th.fw-filler`/`td.fw-filler` —
  311px of a 1010px table, with an FQCN wrapping beside that much empty space — is gone from both
  head rows and every body row; the entity column takes what it freed instead. Measured in a
  browser: the action cells used to end 313px short of the card and now end flush with it; the
  entity column runs 224px to 556px, and gives the slack back as the card narrows — 392px of it in
  an 844px table — without the matrix ever compressing below what it needs.
- **The key folds away above the tabs, in two named groups.** It used to sit under the grid as
  eight lines of flat vocabulary, between a cell and the panel that explains it. It now opens from a
  one-line `<details>` — "What the marks mean" — split into what a click puts on a cell ("A cell you
  set": the three states, plus the shift hint that used to trail the whole list) and what the grid
  draws on its own ("What the grid adds"). Closed by default.
- **The inspector names its two voices instead of running them together.** It used to say "No grant
  matches" and, three lines down, "granted" — both true at once, because the first spoke for the
  store and the second for the screen, with an unsaved change between them and nothing saying so. It
  now shows "In the store" beside either "On screen, not saved" — only when a save would change this
  cell — or "Matched rule": the catalogue row or stored rule warden matched, shown when nothing has
  moved. The store half reads off the baseline the form already sends, so it costs no query. A
  read-only screen's payload never carries a baseline at all — the infolist sends `{stances,
  narrowing}`, nothing else — so it reads its own live state as the store's answer, by construction,
  and never claims an unsaved move.
- **The panel gains an accessible name it never had.** `explain.title` ("Why") used to title the
  inspector only while it sat empty; it now labels the `<aside>` itself as an `aria-label`, so the
  region is named whether or not a cell is selected.
- **The condition builder's controls take the shape of a panel field.** Selects and inputs pick up
  the field radius and a focus-visible ring, and a select draws its own chevron instead of the
  browser's; the joiner column widens from 3.25rem — which drew "ar" for "and" — to 4.75rem. The
  rule and what it means, its warning and its live preview, sit beside it instead of stacked with
  the right two-thirds of the panel empty.
- **Tabs stop wrapping across two rows below `55.9375rem`**, the same cut where the table already
  folds into an accordion. At 390px they used to break the tablist itself across two lines; they now
  scroll as one 39px strip, with a fade at the trailing edge as the only sign there is more.
- **Group headings and field labels drop small caps.** `.fw-group`, `.fw-manage`, `.fw-field-label`
  and the scope stack's summary lose `text-transform: uppercase` and its letter-spacing; weight and
  colour already separated them and read as well without it.

### Fixed

- **The head corner cell carried a visible step in dark mode.** It painted on `--fw-surface` while
  the rest of the header row paints on `--fw-raised` — a seam across the table that only dark mode
  showed. It cannot simply take `--fw-raised`: that token is a `color-mix` with transparency, and
  this cell is sticky over body rows that would show through it. The two are stacked instead, the
  tint on top of an opaque surface.
- **The narrow-screen collapse of the condition editor never applied.** The `@media (max-width:
  55.9375rem)` query that stacks the rule editor, its warning and its live preview into one column
  was declared before the unconditional rules it had to override, so the later rule always won the
  cascade — at every width, on a real screen, including the 390px one this was written for. The
  visible symptom was the warning squeezed into a column one word wide. Eight gates and three code
  reviews passed it: `declarationsOf()` and `blockOf()` can only show that a declaration exists in
  the sheet, never that it wins against another one declared later. Found by opening the screen in a
  browser and reading the computed `grid-template-columns`; fixed by moving the query to the end of
  the file, after every rule it overrides. The test that pins it now asserts the query's position
  relative to its base rule, not the declaration's presence.

### Not included

- **A real screen reader.** Nobody has run one against these screens. What was run is the
  accessibility tree, in both themes, against a real panel: the inspector reports as a named region,
  its two voices as headings, the folded key as two heading-led groups, and no column header without
  a name. That establishes what role, name and properties each node exposes — nothing about
  announcement order, pacing or verbosity, which stay unverified.
- **The width split, seen at scale.** The consuming application this was measured against has five
  entity rows. What twenty resources and eight actions would do to the same layout has not been
  seen — the self-regulating behaviour is reasoned about and measured at three widths, not against a
  large catalogue.
- **The key inside the resting panel**, tried and measured before the fold. It costs no fixed
  height and teaches the vocabulary exactly where the answers it describes go on to appear — but
  after the first click it never comes back for the rest of the session, because this screen has no
  way to deselect a cell. A key has to stay reachable, so it lives above the grid instead.
- **The tabs as a `<select>` below `55.9375rem`.** It would trade the tablist for a listbox, and
  with it the single tab stop, the arrow keys, and the `aria-controls` relationship between each tab
  and its panel — work `2.6.0` paid for with an accessibility-tree dump of its own.

## [2.10.2] - 2026-09-05

Warden `2.2.2` answered the question this package could not answer for itself: which of the
behaviours it works around are permanent. Three are, and now say so where somebody is about to rely
on them.

### Changed

- **`elpandape/warden ^2.2.1` → `^2.2.2`.** `PermissionIdentity` asked the cast for `options`, so a
  row whose blob does not decode digested as a row with **no conditions at all** — byte for byte the
  print of its plain sister, which `(name, identity_key)` then read as one permission. It also left
  such a row unrepairable: the save that would recompute its key is the one that collides. Reported
  from here after the same defect was closed on this side in `2.8.0`. Warden states no migration and
  no key changes for a readable rule.

### Fixed

- **Three workaround comments that could not say whether they were permanent.** Warden `2.2.2`
  declares all three, so each now carries the reason it stays rather than reading as a stopgap
  somebody could tidy away:
  - `Words::joiners` is written out by hand and never derived from `LogicalOperator::cases()`.
    Warden refuses `Not` on the way in and out while a hand-built group still reads it as a
    conjunction, and closing that needs a published signature to move — so it cannot land in 2.x.
  - The two boolean warnings in the condition builder stay for as long as this package supports the
    2.x line: aligning `ComparisonOperator::compare()` would change documented behaviour, and a row
    stored before warden's write-time refusal still evaluates to false — written as a forbid, it
    never fires.
  - `Assignment`'s docblock settles that narrowing by scope and not by restriction is deliberate, so
    the three reasons it lists for writing through the fluent API are the whole of it.
- **A test comment that went false the moment warden shipped.** It said recomputing an
  `identity_key` on an unreadable row was "not even available" because warden read the cast too.
  It is available now. The test still does not recompute, for the reason that survives: a blob
  corrupted after the fact keeps the digest of the rule it held, and that is what a real row in that
  state looks like.

## [2.10.1] - 2026-09-04

Two measured numbers that stopped being true. Warden `2.2.0` partitions a single `assigned_roles`
read in PHP where it used to run several, and both figures this package had written down were
counted before that.

### Fixed

- **`explain()` is three to five queries, not three to six**, and the note now says it was measured
  against a query log rather than counted from call sites — which is what this project asks of a
  figure in prose.
- **A plain `whereCan()` is five queries, four of them preamble**, where the note said seven. The
  product decision does not move: it is still lazy or nothing, and the number the screen shows is
  still a lower bound.

## [2.10.0] - 2026-09-04

Warden took the work back. Its `2.2.0` taught its invalidation hook to recognise the permission
catalogue, so the two compensating calls this package carried are gone — and the floor moves to the
version that makes that true, rather than staying optimistic about it.

### Changed

- **`elpandape/warden ^2.1` → `^2.2.1`.** The invalidation below is only correct from `2.2.0`, and
  `2.2.1` carries a tenancy fix this package reported: under a tenant, granting a permission
  attached the concession to the **global** catalogue row even when the tenant had minted its own,
  leaving that row orphaned and the rule governed by conditions somebody else chose. Measured here
  before it was reported; nothing on this side had to change for the fix.

### Removed

- **Both surviving `Warden::refresh()` calls.** `CacheInvalidations::isWardenRow()` now admits
  `permissionClass()`, so an edit made through the model bumps the version by the event it always
  should have. Measured rather than taken from a changelog: with that one line switched off in the
  vendor, the test that warms a check before editing goes red and nothing else does — which is both
  the proof that warden does the work and the proof that the suite can tell.
  The create-side call went with it and **nothing pins that half**, said plainly: a permission that
  has just been created has no grants and nobody holds it, so no cached answer can have changed —
  the same reason `CreateRole` never carried one.

### Not included

- Anything warden `2.2.0` added that this package could now lean on. `Warden::notOwned()`, the
  write-time warnings for an unownable grant and an unsatisfiable boolean, and the refusal to
  serialise a reserved negation all overlap with guards and screens here, and each is its own
  decision about what a person should be told and where. The screens keep saying what they say: a
  line in a log is not a sentence beside the field somebody is typing in.

## [2.9.0] - 2026-09-04

Two rules a person can write, save, and never have fire. Warden takes both without complaint and
neither screen said anything, so the only way to find out was that a check kept answering no. This
release makes the screen say it first. Nothing already stored changes; two new sentences appear.

### Added

- **The other half of the boolean mismatch.** The builder already warned about `true`/`false` typed
  against a column the model does not cast to boolean. It now warns about the mirror as well —
  anything else against a column that IS cast. Warden's query side is symmetric about this
  (`WhereCan::compileOne()` fails closed either way round) and its in-memory side is not:
  `ComparisonOperator::compare()` is `$left === $right || ($numeric && $left == $right)`, and
  `is_numeric(true)` is false, so a mismatch in either direction is stored and never matches an
  instance. Written as a prohibition it never fires at all, which is the direction that matters.
  A value still being typed says nothing, and a column-to-column rule compares no literal, so
  neither of those is warned about.
- **`filament-warden:audit` names an ownership rule that can never own anything.** A catalogue row
  carrying `only_owned` whose model resolves no ownership grants nothing and forbids nothing:
  `Context::isOwnedBy()` has no attribute to compare, and the query side fails closed through
  `inexpressible()`. Nothing on these screens can write one — `Conditions\Ownership::of()` is asked
  before the checkbox is offered — but warden's `toOwn()` asks nothing at all, so a seeder, a console
  command or a migration mints them in silence. It is a **red** finding, because both fixes are the
  operator's: register the ownership with `ownedVia()`, or take the row out. A row whose entity type
  resolves nothing at all is left to `drifted`, whose fix is the morph map and not this.
- **`verify/verify-boolean-misfit.mjs`**, wired into `make verify`. Both predicates are driven
  through the real `@vue/reactivity` Alpine pins, across a live edit — because a test that matches
  the source text proves a guard is written, not that it answers, which is exactly how a broken
  guard shipped green in `1.1.0`. Inverting one `!` turns five of its checks red.

### Not included

- Widening `Columns::booleans()` past the `bool` and `boolean` casts. A boolean produced by an
  `Attribute::make()` or a cast class of its own still reads as uncast, so the builder would warn
  about a condition that in fact works. It is a known, narrow false positive; broadening cast
  detection is a separate decision with its own measuring.
- Anything that repairs an ownership row. The audit names it and writes nothing, which is what that
  command is for.

## [2.8.0] - 2026-09-04

Ask the column, not the cast. Warden moved its three engines off Eloquent's `array` cast in its
`1.0.2`, for a reason this package had written down and not acted on: three stored values cast to
`null`, and reading them as "no conditions" turns a rule nobody can decode into an unconditional
grant. **Read the upgrade note — on a catalogue that has such a row, screens that used to accept an
edit stop accepting one.**

### Fixed

- **An `options` blob that does not decode is no longer drawn as "every row".** `Narrowing::of()`
  reads `permissions.options` from the column. `HasAttributes::fromJson()` flattens exactly three
  stored values to null — text that is not JSON, the empty string, and the JSON literal `null`, the
  last two even through a real `json` column — and every one of them arrived here looking like a
  plain unconditional row: shown as every row, left editable, and overwritten on the next save.
  It now reads unreadable, with its reason on screen, in the grid cell, the reach badge, the
  inspector and the permission form alike.
- **A save no longer blanks a condition it could not show.** `EditPermission` re-added the `options`
  key with the CAST when the builder was closed, which wrote SQL `NULL` over the column. A disabled
  field is already absent from the payload, so the key is simply left out and the column is not
  touched. Re-assigning the raw string would have been no better: the `array` cast encodes it a
  second time.
- **An unreadable twin is reachable when its cell is cleared.** `RoleGrants::twinsHeld()` collects
  the models a revoke has to name — since warden's `2.0` a name resolves only the plain row — and it
  skipped exactly the rows whose blob does not decode. Clearing a cell backed by one reported
  success and left half the grant standing.
- **`PermissionForm::exists()` stops comparing an unreadable twin against the plain rows**, which
  refused a name the database would have taken on a row nobody can fix from that screen anyway.

### Added

- Tests for the class of blob that had never been exercised: every previous "corrupt" case stored an
  array THROUGH the cast, which `json_encode` leaves as valid JSON. The three column values are
  covered on the read side and both writers are pinned — measured red before the fix, each for its
  own reason.

### Not included

- Anything that repairs an existing row. This release stops the screens from making it worse and
  says what it is; the column is repaired outside the panel. `warden:clean --duplicates` is not the
  tool, and the upgrade note says why.
- Removing either surviving `Warden::refresh()`. Still waiting on warden admitting catalogue rows
  into `CacheInvalidations::isWardenRow()`, and on this package raising its floor to the version
  that brings it.

## [2.7.1] - 2026-09-04

Reasons, not conclusions. Warden moved through five releases while this package was reading its own
notes, and several sentences here went on describing a version that no longer exists. Nothing a
person sees changes except one modal, which had been promising something warden stopped doing a
year of releases ago.

### Fixed

- **The permission delete modal no longer claims the cascade leaves no trace.** Warden has announced
  it since its `1.1.0` — `CacheInvalidations::prepareCascade()` reads the doomed rows before the
  delete and `announceCascade()` dispatches one event per removed grant — so the sentence was telling
  people to treat the modal as their only record when it is not. What it still is: the only warning
  that reaches the person about to click, because the cascade is a foreign key below Eloquent, fires
  no `Grant` model event, and is blind to the tenant. The role sentence beside it is untouched and
  still true.
- **Two docblocks that gave a false reason for a guard that is right.** `CreatePermission` said
  nothing in warden invalidates a model-layer write; warden's provider has listened on the wildcard
  `eloquent.*` events since `1.1.0`. The `Warden::refresh()` call stays, because
  `CacheInvalidations::isWardenRow()` admits only the grant and assigned-role classes and so never
  marks a catalogue row — which is the reason it now gives. And the `catch` in `RoleGrants::narrow()`
  named one cause where there are two: a vetoed grant, and a narrowing asked for on a row with no
  entity to test against.

### Added

- **A door carrying a hand-written condition is pinned.** A door or loose permission has no
  `entity_type`, and `Narrowing::of()` reads `options` without asking for one, so a blob written by a
  seeder or a console comes back as `Shape::Conditions` with nothing to compare against. Moving that
  cell still lands its stance, and the test says why: warden refuses ahead of its own transaction, so
  the plain grant stands and only the condition is dropped. If that ordering ever moves upstream, the
  cell would lose its grant while the report counted it written.
- **The permission delete now has the tenant tests the role delete already had.** A permission held
  only inside another tenant is not offered for deletion, and a `strict` installation with no tenant
  active sees that grant too. `Holders` reads without global scopes on purpose — the cascade does —
  and until now nothing went red if that was taken for redundant. Measured: removing it fails both.
- **The `dontScopeRoleGrants()` test now pins what the mutator moves.** It asserts the `scope` warden
  stamped on the row, which is null only while the call stays bare. Everything else it asserted holds
  in both polarities, so the test was green whichever way the flag was read.

### Not included

- Adopting warden's raw-column read for `permissions.options`. `Narrowing::of()` still asks the
  Eloquent cast, so the three column values that cast to `null` — undecodable text, the empty string,
  the JSON literal `null` — are still drawn as "every row" and still overwritten on save. It changes
  behaviour, so it gets its own release.
- Removing either surviving `Warden::refresh()`. That waits on warden admitting catalogue rows into
  `CacheInvalidations::isWardenRow()`, and on this package raising its floor to the version that
  brings it.

## [2.7.0] - 2026-08-30

Fewer words. The last tag of this plan, and the only one whose whole job was to delete. It deleted
24 lines, and that is the finding: the density was never the problem. What the reading found instead
was three defects hiding inside prose nobody had reread.

### Fixed

- **A comment that told a reader something this package had already measured and found false.**
  `Provenance::of()` said `where('entity_type', null)` compiles to `= null` and never matches.
  Laravel's own query builder detects a null value and redirects to `whereNull()`, in both the
  two- and three-argument forms — which this repository measured, wrote down, and corrected in the
  sibling method that shares the pattern, never here, in the file its own note names as the origin.
  Unchanged since it was written. The branch it sits above was always right; only the reason was
  wrong, which is the failure mode this project has logged more than twenty times.

- **A docblock naming the wrong class.** `PermissionResource`'s tenancy guard carried a byte-identical
  copy of `RoleResource`'s, including its measurement — *"with this line gone, `Role::query()->count()`
  … throw"* — on the permission resource. It points at the one copy now instead of restating it.

- **A docblock stranded above the wrong method.** `Row::answered()`, added last release, was inserted
  between `editableActions()` and the docblock describing it, so a paragraph about a list of action
  names sat above a method returning three counts.

### Changed

- **Seven vendor line citations removed**, six in one file. §5 has forbidden them since `1.5.0`, with
  its reason measured: they drift between one and eight lines and send a reader to the wrong place,
  which is worse than not citing. Every one already named its method beside the line range; the
  method is what stayed.

- **Nine chronicle passages replaced by what they were chronicling.** "A save used to be one
  outcome", "that earlier sentence said six", "since `1.9.0`" — history belongs in AGENTS and in this
  file, and the code keeps the fact. Where the history explains a live guard, it now points at its
  section the way nine other comments in `src/` already do rather than retelling it.

- **AGENTS §5's rule about test bodies, amended rather than enforced.** This tag existed partly to
  sweep the comments inside test bodies that the rule forbids. Recounted with the rule's exact
  wording: **310 lines in 23 files, which are 97 paragraphs, of which two are repeated.** The other
  95 all answer the one question no test can pin — why the test is written the way it is — and the
  rule's own carve-out already exempted them. A rule whose exception does its entire job is not being
  applied, it is being ignored, which is the lesson §5 had already written about itself once before,
  in 2026-08-21. So the two genuine repetitions moved to their files' docblocks and the rule was
  rewritten to say what is actually wanted. The plan asked for one whole exit or the other and said
  half would not do; this is the other one.

### Not included

- **A density sweep, because measuring found nothing to sweep.** `src/` was 36.6% comment before this
  release and is 36.5% after. The long comment blocks were read one by one and they are what §5 asks
  for: the environment fact that does not follow from the code and cost something to find — warden
  comparing with `===`, `whereCan()` falling through to a dynamic where, what a twin's identity
  includes. The chronicle §5 bans was already at zero and stayed there. The honest answer is that
  this is the floor for a codebase whose hazards are mostly invisible in its own syntax, and a
  reading that cut deeper would have cut measurements.

## [2.6.0] - 2026-08-30

A screen reader. An accessibility fix without the session that checks it is how this line stayed
declared for nine versions, so the fix and the session ship together — and the session found two
more defects than the fix set out to close.

### Added

- **A cell says what it just became.** Its accessible name already changed on its own: every
  screen-reader span inside a cell is bound, so the browser rewrites the name in the click. What a
  name changing under a focus that never moved does not do is get re-read. The grid now carries a
  live region that says it.

  It says the cell's own three words, through the same three methods the cell's own spans are bound
  to and in the same order — so a cell cleared under a granting wildcard says *granted, reached by a
  broader rule*, which is what it now answers, and not *no rule*, which is only what was written on
  it. Taking it off the stance would have put two versions of one fact on one screen.

  It fires on the **write**, so a preset, a keyboard cycle and a shift-click all announce — and, the
  part that matters most, so does a click in an installation with `grid.explain` and
  `grid.constraints` both switched off. That configuration returns before doing anything on the path
  the inspector uses, so a click there used to say nothing at all, ever. The plan did not name that
  case; measuring found it.

  Empty from the first paint and never behind a condition, for the reason this package already had
  written down beside the inspector's own region: a live region added to the page at the same moment
  its content appears is not announced by NVDA or JAWS. One for the whole grid, not one per tab like
  the filter's — only one cell can be clicked at a time. No new keys: the words are the ones the cell
  already renders.

### Fixed

- **Every row of the grid was announced with its three preset buttons glued to its name** —
  *"users App\Models\Security\User read all none"*. The row header was named by its contents and
  the presets live inside it. It is named by its two spans now. Seen in an accessibility tree dump
  against a real panel, not deduced.

- **The spare-width column turned up as a column header with no name.** It heads nothing, which the
  template has said in a comment since it was written, and now says in markup.

- **Why a rule-scope option cannot be picked was pointed at by nothing.** All three options described
  themselves with the shared hint, and the sentence giving the reason sat outside with no id. Its
  docblock said "out here it is read either way" — true in browse mode, false in the focus mode a
  radiogroup puts a reader in, where the arrows read a radio's name and its description and nothing
  else. It is pointed at now by the one option it is about, and only while there is a reason to give.

### Changed

- **A test that would have gone green with the thing it guards broken.** `'the filter says out loud
  what it took away'` asked whether `role="status"` appeared anywhere in a 900-character window. The
  grid has other live regions — the inspector's since `1.1.0` — so this release's new one landing
  near the filter would have kept it passing with the filter's own gone. It asks for the filter's own
  node now, and was narrowed **before** the new region was added rather than after.

### Not included

- **The cell as a `role="radio"` triplet**, which the plan asked for by name, saying to reuse the
  reach rail's pattern rather than invent another. The ARIA pattern is a real precedent; the cost is
  not. A rail is one roomy widget with three mutually exclusive options; a cell is 24 by 24 pixels,
  cycles on a single click with shift reversing it — an affordance with its own entry in the legend
  — and there are hundreds of them in a grid that already scrolls sideways. It would be three times
  the interactive nodes, a redesign of the cell's CSS, and the loss of the cycle. The live region
  gets the same guarantee for none of that.

- **A real screen reader.** Nobody has run NVDA, JAWS or VoiceOver against these screens. What was
  run is the accessibility tree, in both themes, against a real panel — which establishes what role,
  what name and what properties each node exposes, and establishes nothing about announcement order,
  live-region timing or actual speech. That half is written down as pending rather than implied to be
  done.

- **A narrower account in front of the role assignment screen.** It was opened in a browser for the
  first time in its life — it renders, its table names every column, its assign modal opens, and
  there is not one console error in either theme — but the account it was opened with holds the
  wildcard, so the disabled option a narrower one would see was never drawn.

## [2.5.0] - 2026-08-29

Finding a row. The component's tag: four things that live in the same markup and the same state, and
one of them is the most dangerous change this plan has left — a filter, over a grid whose save walks
the catalogue rather than the payload.

### Added

- **A box that finds a row.** It filters the entities by their title and their class name, in both
  readings at once, and says how many matched. The tab counters keep counting the whole tab, and the
  line beside the box says so: they answer what the role grants, which a filter does not change.

  **It only decides what is drawn, and that is the whole feature.** A filter that pruned the field's
  state would send a payload with an entity missing, and `RoleGrants::plan()` walks the CATALOGUE, so
  every one of that entity's cells would arrive as an abstention against a baseline that still has
  them — written as a deliberate revoke, with a success notification on top. Measured on purpose
  before a line was written: two entities granted, one pruned from the payload, `written=1
  revoked=1 refused=[] preserved=0`, and the grant gone.

  It cannot be defended against downstream either. Clearing a cell DELETES its key, so "this person
  cleared it" and "the payload never mentioned it" are byte for byte the same payload. Two tests in
  `RoleGrantsTest` pin what that costs, named for the reason rather than for a wish, and
  `verify/verify-filter-keeps-state.mjs` drives the boundary against the real `@vue/reactivity` —
  with a control block that writes what a pruning filter would, so the check is known to
  discriminate.

  The box is announced as well as drawn, with the `role="status"` pattern the inspector line beside
  it already uses. A filter that takes rows away without saying so is a change nobody who cannot see
  the screen is told about.

- **A second hazard of the same family, found while measuring the first and pinned with it.** A
  `narrowing` map that is present but missing one cell's key widens that cell's condition to
  unconditional — `wanted(null, …)` answers `Narrowing::all()`. A map that is absent altogether is
  the safe half and keeps what the store holds, which is what makes the first a hazard rather than a
  defect. Both halves are now pinned.

- **The row shortcuts, in the folded reading.** `read` / `all` / `none` lived only inside the wide
  table's `<tr>`, and the fold has no `<tr>` — so the reading a phone gets lost them the moment the
  fold shipped in `1.10.0`, eight tags ago. They sit in the body of the disclosure and never in its
  summary, where a click would toggle the fold instead. No new keys: `ui.grid.presets.*` already
  existed.

- **A count on each folded entity**, because a collapsed entity hides what it answers. It counts what
  the cells ANSWER, like the tab counter, and one stance more than it: granted and forbidden, since a
  fold hides a prohibition just as well as a grant. Drawn by the server and redrawn by the browser
  from the same count — `Row::answered()` and `answered()` — with a test pinning the two together.

- **A head that stays put, and cells a finger can hit.** The header sticks to the top of the scroll
  while the entity column keeps sticking to its side, with the corner beating both. Under a coarse
  pointer every cell's real button grows to 2.75rem and takes the room back with a negative margin,
  so the touch area grows and the drawing does not move. Wide reading only: below the breakpoint the
  whole table is `display: none` in favour of the fold.

  **The sketch's version of this does not work, and neither did the first draft of ours.** Two
  things were measured in a real browser rather than reasoned about, and both came back no:

  - A sticky head needs something that scrolls, and `.fw-scroll` never did. `overflow-x: auto`
    already makes it a scroll container, so the head sticks to IT rather than to the page — and with
    no height cap it never scrolls vertically. Measured: the head travelled 589px to 205px on a
    384px page scroll, a rule that drew nothing. It now caps at `60vh` and takes its own
    `overflow-y`, which turns the grid into a window on a long catalogue and changes nothing on a
    short one. Verified after: the head does not move a pixel across a 123px inner scroll.
  - The sketch offsets the second header row by a declared first-row height, `2.25rem`. That height
    is not this package's to declare — it comes out of the host theme's line-height, and measured
    26px against the sketch's 36px, so a ten-pixel strip of body rows showed between two rows meant
    to be flush. The `<thead>` is stuck whole instead: it travels as one element, there is no offset
    to get wrong, and the gap is zero.

### Fixed

- **`tests/StylesheetTest.php` could not read a token with a digit in it.** Both its regexes matched
  `[a-z-]+`, which stops at the digit — and the two sides then disagree about the same token: the
  declaration side fails to match at all while the usage side captures a truncated name, so the token
  reads as used and never declared. Seen live, not reasoned about: adding `--fw-head-1` turned the
  gate red on a sheet with nothing wrong with it, 18 used against 17 declared. Widened to
  `[a-z0-9-]+`, which is byte-identical on today's sheet and catches the next one too. The token
  that exposed it did not end up shipping (see below); the defect it exposed did.

### Changed

- **`.fw-scroll` gained `contain: layout`**, which is what keeps a browser from recomputing the whole
  table's layout on every scroll now that the head is fixed above it.

### Not included

- **A filter per tab.** There is exactly one matrix tab by construction — `GridView::matrix()` is
  private and called once — so a single shared term is not a choice between two designs. Said here
  because it looked like one.

- **`--fw-head-1`, the token that started the fix above and did not survive it.** It was added to
  this package's own `.fw-table` rule rather than by copying the sketch's block, which carries a
  width strategy `StylesheetTest` pins the opposite of — and then it stopped existing altogether
  when the head became one sticky element. The regex fix it exposed is kept: the defect is real and
  measured, and the change is byte-identical on a sheet with no digit in any token name.

## [2.4.0] - 2026-08-29

Saying what happened. Two screens that stayed quiet where they had something to say, and the
decision the first one answers for the second: if the save can report itself, a package-owned event
is a public class frozen forever for a gap that is already covered.

### Added

- **A save says what it did, not only that it happened.** `RoleGrants::plan()` already built a list
  of `Change`, each carrying the stance its cell was moved to, and threw the stances away —
  `SaveReport` kept one number. It now keeps three beside it, tallied in the same pass with no extra
  query, and the role screens hang them on their own "Saved": *3 granted, 1 forbidden, 2 revoked*.
  Only the counts above zero become clauses, so a save that changed nothing says nothing — the body
  is absent, not a row of zeroes.

  Said by the page rather than as a second toast from the field, because an ordinary save is the
  common case and two notifications for it would be noise. This is not a return to what `1.9.0`
  removed: that was the refused-cell sentence, which names cells and needs the catalogue, and it
  stays with the field along with everything else about what a save *met*.

  Three keys, not the one or two this was scoped for. A clause per stance is what lets a count agree
  with its adjective in Spanish and what lets a zero be skipped; one key with three placeholders can
  do neither.

- **The permissions listing points somewhere when it has nothing to show.** A fresh installation had
  a heading — Filament's own "No Permissions", true and no help — and nothing else. The description
  now names both places a catalogue can be seen, the role grid and `filament-warden:catalog`, because
  an installation that called `->roles(false)` only has the second.

  It is withheld the moment anything narrows the table. Filament draws the empty state on **any**
  render with no rows, a search or a filter that matches nothing included, and there the sentence
  would be a lie — the catalogue is not empty, the query is. `Table::getFilterIndicators()` is
  Filament's own answer to *is anything narrowing this*, covers search, per-column search and every
  filter, and costs no query.

- **What a save did is now reachable from both screens, not one.** `app()->instance(SaveReport::class, …)`
  had lived only in `PermissionGrid` since `1.9.0`; the account screen returned its report to nobody.
  A recipe cannot be documented as one thing while being true on one screen, so the account screen
  binds it too. Thinner there by construction, and said so: a role is held or it is not, so `refused`
  and `unresolved` are always empty and the three stance counts stay at zero.

- **A README recipe for reacting to a save**, which is the other half of the decision below. It names
  warden's own events for *who* — since warden 2.0 the eight write events carry `?Model $actor`, so a
  panel already leaves an audit trail with a name on it and no code from this package — and
  `SaveReport` for *what*, with its request-scoped lifetime and its non-frozen status stated rather
  than implied.

### Changed

- **`SaveReport` gained three properties**, `granted`, `forbidden` and `revoked`, after `unresolved`
  and with defaults, so every existing construction still compiles. The class is not frozen and the
  README says so.

### Not included

- **An event of this package's own, argued against and left out.** The measured gap over "warden's
  events plus the report in the container" is real and narrow: warden's events are coarser than a
  cell (one names every permission written in the same group), and a cell this screen *refused* fires
  nothing at all, because nothing was written. Both of those the report already answers. What neither
  answers is asynchronous delivery, and that is one gap, with no consumer asking for it, against a
  public class this package would owe forever. Documented instead. Revisit when somebody needs a save
  delivered to a queue.

- **The plan claimed the actor fact "is said in the 2.0.0 README".** It is not: `grep -ni actor
  README.md` returned nothing before this release, and the only CHANGELOG mention is a `1.9.0`
  "Not included" note written before warden had an actor at all. The fact was true and undocumented,
  which is why writing it down is scope here rather than something already on record.

- **A guard that reads like a defence and is not one.** `savedBody()` was written with two: `written
  < 1`, and then "no clauses, no sentence". Breaking the first alone changed nothing — every change
  carries one of three stances, so the counts sum to `written` by construction and the two questions
  have one answer. It was collapsed to the one that decides rather than kept for the look of it; the
  measurement is in the docblock.

## [2.3.0] - 2026-08-29

What the audit can say. Three changes to what `--check` decides, made in one sitting on purpose:
`Audit::isClean()` is a flat `&&` and the only source of the exit code, and this project has already
measured that swapping one term for another leaves the count unchanged and no counting test can see
it. Every test here is written over the exit code.

### Added

- **`filament-warden:audit --panel=`**, the option its sibling `filament-warden:catalog` has carried
  since `1.9.0`. A name no panel answers to fails rather than quietly auditing everything, under a
  key of the audit command's own — not the catalogue's, because a published translation may carry one
  and not the other, and somebody chasing the sentence should land on the command that said it.

- **A config entry this package reads and cannot use now turns a build red.** Four keys drop what
  they cannot use and say nothing: `catalog.models`, `catalog.custom`, `catalog.scopes` and
  `guard.panel`. The plan asked for the first two and made naming both a condition of doing either;
  measuring found the other two share the shape exactly, and naming two of four would have been the
  same mistake the condition was written to prevent. A typo in `catalog.models` takes a whole entity
  out of every grid, and the only symptom was a screen quietly missing something. Red rather than
  informational because it is clearable: nobody types one of these keys by accident, so the build is
  telling its author about a line they wrote and got wrong.

- **And one entry that does not vanish — it arrives wrong.** A `catalog.custom` scope that is not one
  of the four names survives the filter, because a string is all that filter asks, and
  `Catalog::fromCustom()` then falls back to `write` one layer down. A permission filed under the
  wrong heading is worse than one that never appeared, so it is reported as its own finding with the
  word it was given and the word it got.

### Changed

- **A panel that never registered this package is no longer told its screens are open.** A screen
  with no `canAccess()` is open because Filament answers true for one — not because of anything this
  package did — so on a panel that never asked for the grid it is somebody else's decision, and
  reporting it turned an unrelated build red. This can only make such a build greener, which is the
  safe direction for an audit and still a change worth stating.

  **Scoped to that one bucket, and the reason is measured.** Skipping a pluginless panel's whole
  contribution also drops its catalogue, and a permission only that panel declares then moves from
  the informational *unused* list into the red *forgotten* one — a build going redder, which is the
  opposite of the point. Ten other tests change meaning under that broader reading. Only the narrow
  one was taken.

### Not included

- **A `guard.panel` key naming a panel that does not exist.** The four findings above are answered
  from the config alone; that question needs the panel list, which is a different shape and would
  make the finding depend on which panels happen to be booted.
- **Detecting a policy that never consults warden.** Closed with reasons in `1.9.0` — undecidable
  without running it, with three legitimate shapes measured that it would call false positives. Not
  reopened without a new signal.

## [2.2.0] - 2026-08-26

Untangling the cell. A cell the grid could not draw was stuck for the life of the installation: the
save skipped it in silence, and nothing in the panel could take it away. It can be emptied now — and
only emptied.

### Added

- **A tangled cell can be switched off.** Two rules of one polarity for one cell is a state the grid
  cannot draw, so it must not rewrite it — but taking it away reads no reach and rebuilds none, and
  warden has been able to reach both rows precisely since its `2.0.0` gave revocation by permission
  model. That is why this arrives now and not earlier. `Shape::isClearable()` is a smaller permission
  than `isEditable()` and only `Tangled` has it: `Elsewhere` is another tenant's row, where a write
  targets one exact scope and would delete nothing while reporting success, and `Unreadable` is a rule
  this version cannot parse — and a corrupt PROHIBITION fails closed, so taking it away would remove
  protection nobody could read well enough to consent to losing.

- **Asked for anything else, the save says which cells it left alone**, under two new keys of its
  own. It could not borrow the concurrent-save sentence: that one says somebody else was editing, and
  saying it when nobody was is the borrowed-sentence mistake this project has recorded going wrong
  twice. The screen lets the stance move, so silence would have read as a save that worked.

### Fixed

- **Two comments in `src/` still quoted a sentence warden no longer says.** `Explanation`'s docblock
  and an inline comment in `Probe` both carried warden's old `ConditionsNotMet` wording — "its
  conditions did not hold for this record" — which warden changed in its own `2.0.1` after this
  package reported it. The `Probe` one contradicted its own class docblock three lines above it.

### Not included

- **The inspector adopting what `ConditionsNotMet` brings, because it already did.** The plan listed
  it for this release as something "this package has never been able to say". Measured against the
  tree: `Explanation::of()` has read the rejected row generically since the inspector shipped, warden
  2.0's new cause needed no change there beyond an enum case and a translated line, and a test has
  been pinning it since. `git diff v2.1.0` on those two files is empty. What was left of that item
  was the two stale comments above.
- **Choosing between the two rules.** The screen empties a tangled cell; it does not offer to keep
  one and drop the other. That needs the browser to be handed a reach it has never been sent and to
  draw two rules where it draws one, which is a screen of its own, not a passenger here.
- **Per-line conservation inside one cell.** Unchanged and still deferred, for the reason it has
  been deferred twice already.

## [2.1.0] - 2026-08-26

What warden already does. Every item here deletes something this package was carrying because warden
could not yet do it — plus one that had to come first: `2.0.3` **does not work against warden 2.1.0**,
which its own `^2.0` allowed. The dependency floor moves to `^2.1`.

Each deletion was measured by breaking it on purpose, and where two guards covered one answer, by
breaking each ALONE and then both. That distinction earned its keep: one item that looked like a
tautology turned out to be an independent second defence.

### Requirements

- **`elpandape/warden` moves from `^2.0` to `^2.1`.** `PermissionTitle::generations()` is new there,
  and it is what closes the defect below.

### Fixed

- **A title warden itself wrote stopped being recognised, twice.** `PermissionName::generated()` held
  warden's answer for TODAY beside a frozen copy of `1.x`. Warden `2.0` changed that answer, and
  warden `2.0.1` changed it again — and each time the wording an installation had ALREADY been
  titled with fell out of the list, so `settleTitle()` and the rename-regenerates path stopped
  treating those rows as warden's and left them alone for good. The list now comes from
  `PermissionTitle::generations()`, frozen per warden release on warden's own side, and this
  package's transcription of the `1.x` generator is deleted — 48 lines warden now owns. The two
  shapes this package mints itself stay frozen here.

- **The `taken` rule refused a name another tenant held.** Warden's unique index is over
  `(name, identity_key)` and that digest carries the tenant, so the same name under two tenants is
  two rows the database admits side by side. The rule read the catalogue with every scope dropped,
  saw the neighbour's row, and reported a collision that could never have happened. It now asks
  about the scope the row would be written at, the way `stampScope()` decides it.

- **The ownership refusal named a column when no column was the problem.** With nothing registered
  through `ownedVia()` and no default attribute configured, warden's `ownershipResolverFor()` falls
  through to the EMPTY STRING — and reading that as a column name gave the right refusal for the
  wrong reason. The toggle was correctly closed while the sentence beside it said the table had no
  `` column. `Ownership` now carries `resolved`, asked of `Context::resolvesOwnershipFor()` before
  the fallback can be mistaken for anything, and the two refusals get two sentences. On a model that
  DOES have a `user_id` column the old wording was doubly wrong: it named a column that is there.

- **The screens dropped every global scope, including an application's own.** Warden reads grants
  through Eloquent, so a scope an application put on a swapped `grant` model applies to the resolver
  too — and four reads here stepped over it, drawing rows the resolver will never answer with. They
  now drop warden's `TenantScope` and nothing else.

### Changed

- **Deleting a role or a permission no longer refreshes the whole cache from here.** Warden has
  predicted that cascade since its own `1.1.0` — including the foreign-key cascade that runs below
  Eloquent and the polymorphic grants no key reaches — and its invalidation is per scope where this
  package's was a global flush. Six `after()` hooks go. Measured by disabling warden's listener in
  `vendor/`: exactly six tests go red, one per hook, which is what says both that warden does the
  work and that those tests were exercising the cache at all.
- **EDITING a permission still refreshes, and that is not an oversight.** Warden's `markFrom()` acts
  only on `grants` and `assigned_roles` rows; a catalogue row edited through the model layer is
  neither. Measured by removing that one too — one test red. The comment there now says which half
  warden covers instead of saying it covers none.
- **A rule whose first line reads `or` is no longer locked.** This package compared two serialised
  rules with a private transcription of warden's comparison, and the transcription had drifted:
  warden normalises the first item's logical operator before ordering, because `Group::passes()`
  ignores it on every evaluation — so the two forms ARE one rule. Asking
  `ConstraintSerializer::sameRule()` reopens those rows and deletes the second place this package
  wrote that comparison.
- **`Assignment::take()` reports what the retraction did.** It used to end in
  `return $model instanceof Model`, a tautology on a path that had already resolved the role twice.
  `retractedCount()` is warden's own answer. It overlaps with the `isElsewhere()` guard in front of
  it, and the two were measured apart: break either and a no-op is still refused; break both and it
  reports success.
- **`Reach`'s cost sentence said six queries and said the candidate catalogue was hydrated whole.**
  Both were true when written. Warden filters the candidates in SQL now, and the count is not one
  number: seven for a plain grant, nine for a `toOwn()`, whose ownership check asks the schema and
  pays twice for it on sqlite. The product decision does not move — still one call per row, so a
  listing column is still wrong.

### Added

- `ui.conditions.no_ownership_resolver`, in both languages: the sentence for an installation that
  resolves no ownership at all. A new key is a minor, which is what this release is.

### Not included

- **The other half of the `taken` rule stays open, with its reason.** A duplicate TWIN — two rows
  agreeing on everything including their conditions — is still invisible to that rule, because the
  identity digest needs the value `options` is about to take and the rule runs before the condition
  builder dehydrates. There is no honest answer at that point in the lifecycle; the backstop added
  in `2.0.0` catches it after the write and reports it on the same field.
- **`CreatePermission::afterCreate()` still refreshes.** It was already redundant under warden `1.0`
  and no test moves with it either way, so it is not something this upgrade earned the right to
  decide. Its own tag.

## [2.0.3] - 2026-08-26

`2.0.2` rewrote two audit headings and shipped a stray backslash in both of them. Found by running
the command on a real installation rather than by reading the diff, which is the same way `2.0.2`
itself was found.

### Fixed

- **Two headings printed `` `\$relatedResource` `` with a backslash in front.** In a single-quoted
  PHP string `\$` is two characters, not an escape — the finding rows underneath were right, because
  those are built in a double-quoted string where `\$` does escape. `en` and `es` both.

### Added

- **A guard so no translated line can carry one again**, since `\$` in a single-quoted string is
  always a mistake. It was written decorative first and that is worth recording: `toContain` in Pest
  is **variadic**, so `->not->toContain($needle, $message)` reads the message as a SECOND needle and
  passes the moment either is absent — the negation could never fail. Caught by breaking it on
  purpose and watching it stay green; it now asserts `str_contains(...)` is false, which does take a
  message, and goes red naming the key.

### Not included

- **Nothing else.** No behaviour, no key, no surface.

## [2.0.2] - 2026-08-26

Installing `2.0.1` on a real application and following its own upgrade note found three things, and
the first one means the upgrade instruction in `2.0.0` did not work. If you have not upgraded yet,
this is the release to read. If you have, and your `migrate` failed, the fix is one word.

### Fixed

- **The upgrade note named the wrong publish tag, so following it broke the migration it was there
  to run.** Warden registers its CREATE migration under `warden-migrations` and its UPGRADE
  migration under `warden-migrations-v2`. This package's upgrade note, and the audit's own pre-flight
  line in both languages, all named the first. `create_warden_tables` carries no `hasTable` guard on
  its `Schema::create()` calls, so on a database that already has warden's tables — every database
  the note is written for — `php artisan migrate` stops on the first table and `identity_key` never
  arrives. The note's own step 2, `warden:clean --duplicates`, does not help: the problem is not
  duplicates. Measured on a consumer running the published `2.0.1`. The tag is now read off warden's
  provider by a test rather than typed a second time, so the day warden renames it, the three places
  that print it go red together.

- **`filament-warden:audit` told people to do something that cannot clear the finding it was
  attached to.** The *models only a relation manager reaches* line said "declare it in
  `catalog.models`", while the condition behind it asks whether the relation manager declares
  `$relatedResource`. They are separate mechanisms that never meet: `catalog.models` injects a
  model's abilities into the catalogue directly and never touches `$relatedResource`, and the
  finding never reads `catalog.models`. Measured on a real installation with the model already
  declared and the finding still firing. The line now says what actually stops the walk, and says
  that `catalog.models` remains the right fix for the underlying gap without clearing the line.

- **Wiring this package's own relation manager, exactly as the README says to, turned
  `filament-warden:audit --check` red for good.** `unwalkable` was one of the terms of `isClean()`,
  and `RolesRelationManager` declares no `$relatedResource` **on purpose**: pointing it at
  `RoleResource` makes `makeTable()` run that resource's `configureTable()`, which caches its `edit`
  and `delete` into `flatActions` where `recordActions()` does not reach them — that was tried, and
  reverted in `1.4.0` for exactly this reason. So the finding was uncloseable by design for the one
  integration the README documents, and had been since `1.4.0` without anybody noticing. It moves to
  the informational set, beside *permissions no grant points at* and *grants whose authority is
  gone*: still reported, still printed, never red. It is the only one of the buckets with that
  shape — every other term names something an operator can go and fix.

### Changed

- **Two sentences change under keys that are frozen, which is allowed and worth saying.** The
  pre-flight line and the *models only a relation manager reaches* heading both change wording in
  `en` and `es`. `FrozenTest` pins key paths, not strings. An installation that PUBLISHED its
  translations keeps its own copy — `FileLoader::loadNamespaceOverrides()` merges recursively — so
  it will go on printing the wrong publish tag until it updates that copy. If you published
  translations and are about to upgrade, read the note here rather than the one your panel prints.

### Not included

- **`RolesRelationManager` still declares no `$relatedResource`, and will not.** The two leaks it
  closes were measured and are pinned by tests. What changed is that the audit no longer punishes
  the consumer for it.
- **The audit still cannot resolve a relation manager's model.** Reaching it means instantiating the
  owner and running the relationship, which can hit an abstract class, a `booted()` that throws, or
  a relation that reads request state — and a `MorphTo` answers with the OWNER's model without
  failing at all. It is named rather than resolved, which is why the finding exists; what this
  release fixes is what the finding SAYS and what it COSTS, not what it can see.
- **No behaviour of the grid, the screens or the guard changed.** The only executable change outside
  the audit is which conjunction one array is read in.

## [2.0.1] - 2026-08-26

Six guarantees this package makes had been carried as open for between three and nine releases,
re-checked by hand at each one and never written down. Re-checking them had come to cost more than
pinning them, so this release pins them. Nothing under `src/` changed and no key was added: the
whole diff is eleven tests and the reasoning that goes with them.

Each one was broken on purpose afterwards, committed first and reverted with a named
`git checkout`, and what went red is recorded below. Where a mutation reddens tests that already
existed, that is said too — a new test whose only evidence is "the suite went red" has not shown
what it adds.

### Added

- **The three `modalDescription` calls on the permission side had nothing proving they are wired to
  an action.** The role side got that pin in `1.4.0`; the permission side never did. What existed
  was a test calling `PermissionsTable::warning()` statically, which proves the sentence and says
  nothing about whether any action reads it. `assertSee()` after `mountAction` cannot close the gap
  either — the modal's markup is not part of `Testable::html()` — so each of the six new tests
  resolves the action object and reads `getModalDescription()` off it. Measured by deleting one
  `->modalDescription(...)` line at a time: each deletion reddens exactly the two tests for that
  screen and nothing else, so a build now names the screen that lost it.

- **Property 3 of the seven — "whoever may change a role hands out everything the panel declares,
  the wildcard included and itself included" — had never been taken through a real screen.** Two
  tests already wrote the `*` cell, but one drives `RoleGrants::apply()` directly and the other a
  bare field harness; neither has a policy in front of it. The new test signs in, holds the role it
  is editing, and grants the wildcard column through `EditRole`. It has to use `->set()` with the
  raw dotted path: `fillForm()` normalises through `data_set()`, which reads `*` as a wildcard and
  writes nothing, while the real wire treats it as an ordinary segment. Renaming the wildcard
  permission reddens seven tests including this one, which alone does not show what it adds; the
  measurement that does is a self-demotion guard — the "protect the admin from themselves" check
  property 3 forbids — injected into the grid's save. That reddens two tests, and this is the one
  that covers the wildcard column.

- **`RoleGrants::writable()` compares its two scopes as text, and no test had ever handed it a
  string.** Every existing one drives it with the integers 7 and 8. The column reads back as a
  native PHP `int`, so the cast only earns its keep against a tenant resolver answering `'7'` —
  which is what a UUID-keyed installation does, and not, as this was once described, a UUID in the
  column: `scope` is `integer` on all four of warden's tables and could not hold one. Dropping both
  casts for a bare `===` reddens this test and no other in the suite.

- **`ui.conditions.locked.model` was a sentence no screen had ever drawn.** It appeared only in the
  frozen key list, and every release since `1.3.0` re-checked it by hand and carried it forward.
  Reaching it needs a divergence, because `PermissionForm::conditionsHelp()` asks two questions and
  only one is live: the form's current `entity_type` decides whether `conditions.no_model` shadows
  everything, and the record's stored one decides `locked.model`. So the row is saved against an
  entity that no longer resolves and the live field is then moved, without saving, to one that does.
  `fillForm()` suspends the field's own state-update hooks, so the `afterStateUpdated` that would
  blank `options` never fires and the stored row survives to be read back. Changing that branch's
  return reddens this test — and `LanguageTest`, which until now was the only thing standing behind
  the key, and which only ever proved the literal exists in the source, never that anybody renders
  it.

- **The PHPStan ceiling was declared in four places and compared to none.** The floor has had a
  manifest since `1.5.0` — `composer.json` — and a test cross-checking three files against it. The
  ceiling had neither, and it drifts silently in a way the floor does not: `phpstan.neon` carries no
  `phpVersion` on purpose so that half of the gate analyses at the runtime, and the runtime is the
  dev image, so an image that drifts from the workflow stops checking the newer version's
  deprecations while every gate stays green. The manifest is `compose.yaml`'s build arg, not
  `docker/Dockerfile`'s `ARG` default — `make build` is `docker compose build php`, which shadows
  that default on every path this project takes. The test cross-checks the Dockerfile, both of
  `quality.yml`'s version strings, the matrix's top end in `run-tests.yml`, and **the absence** of a
  `phpVersion` in `phpstan.neon`, which is the assertion that protects the mechanism rather than the
  number. Measured leaf by leaf: each of the six goes red on its own mutation and on no other.

- **The README's way back was not compared against anything that runs.** `1.0.2` recorded three
  plugin methods that do not exist shipping twice because nothing read the README; the recovery
  recipe is the passage where that would cost most, since it is what somebody locked out of the
  panel follows. The command's registered name and the argument order its own definition declares
  are now read off the command rather than typed a second time, so the README and the code cannot
  disagree quietly. Swapping the README's two arguments reddens it; so does swapping the command's.

### Not included

- **Nothing under `src/`, deliberately.** The release was scoped as tests only, and a defect found
  while writing one would have gone to its own tag. None was found: all six held, and what was
  missing was the evidence, not the behaviour.
- **The `everything();` line of the recipe is pinned more weakly than the other two, and the
  difference is written down rather than glossed.** It is compared against a second literal here;
  what makes that worth doing is that it is byte-identical to the line `AssignRoleCommandTest`
  executes, so a README that drifts leaves that test demonstrating a recipe the README no longer
  prints. `Warden::role([...])` and `$role->save()` get less than that — the suite reaches role
  creation through a helper — and nothing here can do more for them than notice the README still
  says it.
- **There is no negative beside the string-tenant test, and the reason is measured, not assumed.**
  Reading a row of a genuinely different tenant back would look like the obvious other half, but
  `RoleGrants::held()` queries through warden's own `TenantScope`, so such a row is filtered out in
  SQL before `writable()` runs at all: asking for it throws on a missing array key rather than
  answering `Shape::Elsewhere`. The case that `writable()` could answer wrong unconditionally is
  already covered by an existing test reading with no tenant active.
- **Nobody opened a browser.** This release changes no rendered surface.

## [2.0.0] - 2026-08-26

Warden 2.0. The dependency moved and this package moves with it — but the version number is not
about a signature: it is about a database. Warden 2.0 adds `permissions.identity_key` and a unique
index over `(name, identity_key)`, and stamps that key on every save, so an installation whose
catalogue is still in the 1.x shape gets `no column named identity_key` the first time anything
writes a permission. Composer resolves without complaint and the application breaks on first use.

**Before you upgrade**, publish and run warden's migration. `php artisan warden:clean --duplicates`
first if the migration stops on duplicates. The README has the sequence, and
`filament-warden:audit --check` now reports an unmigrated catalogue as its own finding.

### Requirements

- **`elpandape/warden` moves from `^1.0` to `^2.0`.** This package skipped warden's `1.1.0`, `1.2.0`
  and `1.3.0` on the way, and two of the changes below come from those rather than from 2.0.

### Fixed

- **Switching a narrowed cell off did nothing, and said it had worked.** Warden's
  `findPermissions()` now resolves a name against the plain row only — `whereNull('options')` — so a
  name no longer reaches a twin. Three symptoms, all measured: clearing a narrowed cell deleted no
  grant and left the access granted; widening one left the old twin's grant standing beside the new
  plain row, two of the same polarity, which the grid can only draw as `Shape::Tangled` from then on;
  and a granted → forbidden → granted cycle left the cell forbidden for good. A twin is reached by
  its **model**, which is what warden's own comment says, so a save now carries the twin models the
  role holds alongside the names. Read from the store rather than from the diff, so it takes away
  what is there rather than what the screen believed was there.

- **A condition on a permission with no model behind it is not inert, and the screen said it was.**
  Since warden `1.3.0`, `passesConstraints()` returns the pass flag rather than `false` when there
  is no instance: as a grant such a row never grants, and **as a prohibition it always forbids**.
  `ui.conditions.no_model` promised it "would never grant anything" — true and dangerously
  incomplete on a security screen. Dated by reading warden 1.0.1 through 1.3.0 one at a time.

- **A catalogue collision came back as a 500.** Two paths reach warden's new unique index without
  `PermissionForm::exists()` seeing them, both reproduced through the real screens: building the
  same twin twice on the create screen, and moving a twin onto an entity that already holds the
  plain row of the same name — choosing the entity clears the conditions, so what gets saved is no
  longer a twin, while the rule had already excused it for being one. Both now report on the `name`
  field.

- **A record-pinned grant could be swept up by a save.** New guard, and it needed its own test: the
  first version of it was a claim with nothing behind it, and removing it left all 918 tests green.

### Changed

- **The list of titles this package recognises grows to four shapes, which is why this is a major.**
  `PermissionName::generated()` delegated one of its entries to warden's generator **live**, so
  warden 2.0's new `Str::snake()` did not add a shape — it **replaced** one. `ViewAny posts` and
  `Page:App\Filament\Pages\Settings`, titles warden itself wrote, stopped being recognised as
  generated, and rows an upgraded installation already carries stopped being rewritable. Warden 1.x's
  generator is now transcribed and frozen in this package, verified against the published `1.3.0`
  archive rather than remembered. Its own docblock had said a fourth shape would be a major since
  `1.0.0`.

- **Warden titles differently, and does not retitle what is already there.** `viewAny` on `Post` is
  `View any posts` now, where 1.x wrote `ViewAny posts`. An upgraded catalogue shows mixed wording
  until somebody renames each row, and warden ships no command for it. This package still recognises
  both.

- **The stranded bucket's sentence, in all three places it is written.** Warden 2.0 sweeps some of
  those grants — `CacheInvalidations::markCascade()` deletes the grants of a deleted role, but only
  when the model's class is exactly the configured role class, and only through `eloquent.deleted`.
  An account, a role subclass and anything deleted by query builder or raw SQL are still left behind,
  and `warden:clean --stranded` is opt-in. The bucket keeps its job, narrower and true.

- **The suite raises warden's schema through `Testing\Schema::up()`**, which warden has shipped
  since `1.1.0`, instead of resolving a vendor path and requiring the stub by hand.
  `upgradeToV2()` is deliberately not called: warden publishes its creation stub already in the 2.0
  shape, so this suite has no 1.x database to upgrade.

### Added

- **A ninth cause: `ConditionsNotMet`.** Warden 2.0 answers it with the rejected row attached, where
  before the cause was indistinguishable from "there is no such grant". `Cause::of()` is a `from()`,
  so the missing case was a `ValueError` rather than a gap — five tests died on it. New keys
  `ui.explain.causes.conditions-not-met` in both languages, plus a map-against-enum test for causes
  of the same mould `Shape` and `Stance` already had. The package keeps its own narrowed line:
  warden's wording names a record, and a role grid asks about a class.

- **`filament-warden:audit` reports an unmigrated catalogue, and `--check` exits 1 for it.** Red, and
  in this release rather than a later one for a reason about *when*: a bucket that ships afterwards
  arrives after everybody has already hit the error. It stays permanently empty once migrated, which
  is correct.

- **`ui.resources.permissions.fields.collides`**, the sentence the new backstop reports.

- **A testing section in the README**, which is only writable now: warden 2.0's fake can name an
  authority, ownership, a scope and conditions, and `Warden::fake()` rebinds the very
  `Contracts\Resolver` that `Access` and every policy here resolve. Two tests hold it up — until
  today nothing in this suite had ever touched the fake.

- **A test pinning warden's new fail-closed answer** for ownership through a column the table does
  not have. Warden `1.1.0` put a `hasColumn()` in front of it, so that half no longer throws; nothing
  pinned the new behaviour.

### Not included

- **The eight `Warden::refresh()` calls stay.** Warden 2.0 invalidates model writes itself, per
  scope, where ours is a global flush — so six of them are now redundant. Removing a defence needs
  its own measurement and its own deliberate breakage, not a line inside an upgrade diff. What this
  release does do is correct the docblocks that justified them with measurements that now invert.

- **`PermissionForm::exists()` is not aligned with the unique index.** It compares columns and skips
  rows carrying conditions, so it is both stricter than the index under a tenant and blind to a
  duplicate twin. Doing it properly needs the value `options` is *about* to take, which the builder's
  dehydration and `mutateFormDataBeforeSave()` settle after that rule has run. The backstop above
  covers every path meanwhile.

- **`ConstraintSerializer::sameRule()`, `Ownership::of()`'s ordering, and `Assignment::take()`'s
  inference.** All three are things warden can now do for us; all three change behaviour rather than
  adapt to it.

- **Unlocking `Shape::Tangled`.** It was locked for want of precise revocation, which this release
  restores — but the unlock deserves its own measurement and its own release.

## [1.10.0] - 2026-08-24

The grid's shape, in three places it did not fit: a side column that took width from the matrix at
exactly the size the matrix needed it, a table that had no reading at all when the columns ran out
of room, and a three-of-one choice drawn as the tallest thing on the panel.

### Changed

- **The inspector moved below the grid.** `.fw-layout` reserved a fixed `19rem` right-hand column
  above `64rem`. Measured in a consuming panel of 1280px, whose own navigation rail this repository
  does not build: the form card left 974px and the table saw 604 of them. It is now one column at
  every width, with the inspector as a band
  under the grid. Three rules moved with it and are part of the same change, not decoration:
  `.fw-table` is `max-content` (at `100%` every spare pixel went to the one auto-width cell and the
  matrix read as a broken form), the entity column is capped at `14rem` **and wraps** — the cap alone
  does not contain it while `white-space: nowrap` is in force, and that cell is sticky over an opaque
  background only as wide as itself, so a long class name painted across the action columns
  scrolling underneath — and `.fw-condition` stops wrapping above `56rem`, where there is no second
  line to fall to.

- **The table fills the card, and the spare width lands in a filler column.** `max-content` alone
  left it ending mid-card with the row rules cut short — measured in a real panel, 696px of 1041 with
  345 of blank beside it. `100%` alone was worse: the auto table algorithm handed the spare width to
  the entity column, 224px to 521px, with every cell still bunched at the left. The pair, plus one
  empty column at the end with no width of its own, keeps each column the size it asked for and still
  reaches the edge. The entity column carries its 14rem from both sides — with only the cap declared
  a 520px card took it back down to 94px, because in tables a declared width is a suggestion.

- **The grid folds by row when the columns do not fit.** Below `55.9375rem` the table is replaced by
  one card per entity, holding one disclosure per scope — read, write, withdraw, irreversible — and
  one row per action inside it. It is not a second grid: every cell in it is the same
  `box.blade.php` partial with the same arguments, so neither reading can say something about a cell
  that the other does not, and both bind to one state. `Row::inScope()` is the one method added for it. The cut is a base plus a single query
  rather than two disjoint ones: two thresholds look equivalent and are not, because between them
  neither fires and the grid is gone. And the hiding is `display: none` and nothing else — it is the
  only technique that takes the losing reading out of the accessibility tree and out of the tab
  order, which is what makes two copies of one button safe.

- **The rule-scope picker is a segmented radiogroup.** It was three stacked cards, each carrying a
  name and a hint. It is now one row of three with `role="radiogroup"`, a roving tabindex and arrow
  keys that step OVER a disabled option rather than landing on one — an arrow that moves somewhere
  and does nothing reads as a broken keyboard. Only the chosen mode's hint is shown; the other two
  are read once and are noise from the second reading on. Why an option cannot be picked moved out
  of that option and into a line below it: a disabled button cannot be focused, so said in there a
  keyboard would never reach it. The rail asks its CONTAINER how wide it is, not the window — it
  lives at two widths at once, and a window query answers "no need to stack" while the container is
  narrow and the three labels truncate.

- **A locked cell says why in the hint slot.** `reachOf()` answers with the stored `Shape` when a
  cell cannot be changed, and that enum has six cases to the mode map's three — so the slot falls
  through to the stored note, which is the sentence that says why. The separate locked paragraph
  that carried it before is gone; it would now print twice.

### Added

- `Row::inScope(Scope $scope)` — the row's cells belonging to one scope, declared or not. The
  wildcard cell is built with no scope at all, so it falls outside every group, which is where the
  folded reading wants it.
- `reachEnabled()`, `reachStop()` and `stepReach()` on the grid component. The disabled predicate
  used to live only in the markup; the arrows and the buttons now read one answer rather than each
  deriving it.
- `verify/verify-reach-keyboard.mjs`, run by `make verify` as part of the seventh gate. It drives
  the three new methods through the real `@vue/reactivity` proxy: a PHP test can assert that a guard
  is written, never that it runs.

### Not included

- **No new translation keys.** The sketch this came from drew a per-entity and per-scope tally
  ("3 of 7 · 1 forbidden") and scope bars. Both are cut. Every string in them would have been a new
  frozen key, and a count composed in the browser would have been the first placeholder substitution
  in a script whose whole discipline is that it only ever picks a word out of a map PHP filled in.
  The cost is real and is stated rather than hidden: a collapsed scope in the folded reading gives
  no hint of what is inside it until it is opened.
- **The row presets are not in the folded reading.** `read` / `all` / `clear` are reachable only
  from the three buttons inside the table's row header, revealed on hover of a `<tr>` — and there is
  no `<tr>` in a disclosure. Carrying them over needs a second reveal arm or three buttons inside a
  `<summary>`, where a click toggles the disclosure instead. It is a functional loss below
  `55.9375rem`.
- **No sticky `<thead>`, no touch-target block, no entity filter.** All three are in the sketch and
  none is part of this change. The sticky header's token would also have failed the stylesheet gate:
  its declaration regex stops before a digit, so `--fw-head-1` reads as used and never as declared.
- **Nothing rendered a page — the gates, that is.** None of the eight walks an accessibility tree or
  measures a layout, so everything visual here rests on somebody having looked. Somebody did: all
  four changes were driven in a real consuming panel, on a real role, with the package symlinked in.
  The table at full width, the entity column holding 224px from 1041px down to a 420px card, the fold
  turning over at 820px, the segmented rail with Alpine live, and its arrow keys moving the choice
  and carrying focus and the tab stop with it — in both themes, and on a cell holding a rule the
  screen can read and cannot draw, where the rail goes dashed-red and the hint slot carries the
  stored sentence rather than indexing a map that has no entry for it. What is still owed is one
  thing: a screen reader.

## [1.9.0] - 2026-08-23

Seven changes, one thread running through all of them: whether a screen, a count, an audit or a
notification tells the truth about what the store actually holds. A role deleted out from under a
grant, a catalogue read from the wrong panel, a search aimed at a column that cannot answer it, a
baseline gone stale after a refusal — and, on the notification side, a report that had drifted to
the wrong owner.

### Fixed

- **Counting who holds a permission missed anyone whose role had since been deleted.** `Holders::of()`
  counted roles from the labels its own query FOUND — `whereKey()` over the role class, which simply
  has nothing to return for a key that no longer exists — rather than from the keys the grants
  themselves carry. So a permission whose only grant pointed at a deleted role reported as held by
  nobody, on the delete-confirmation modal whose entire job is to name who loses what. `Holders` now
  keeps `$roleCount`, read from the keys, the same way `$accountCount` always was; `isOrphaned()` and
  `total()` follow it, and so do `PermissionsTable::warning()` and the permission infolist.

- **A multi-panel installation could watch its own audit disagree with its own screens.**
  `PermissionsTable`, `PermissionInfolist` and `PermissionForm` all asked
  `Catalog::for($currentPanel)` for provenance, so a row derived by a *different* panel's resource
  drew as "Nothing declares it" while `filament-warden:audit` — which already read every panel — said
  the opposite. The three now ask the new `Catalog::union()` over every panel, the same union the
  audit already built.

- **The account-search probe could raise on Postgres by asking `LIKE` of a column that cannot answer
  it.** Postgres raises when `LIKE` meets a column that is not a text type; MySQL and SQLite silently
  coerce instead. The search now keeps only the columns the new `Columns::texts()` names as
  text-shaped, read from the schema's own type name. `ViewPermission`'s own `SEARCHABLE` name
  whitelist is not gone — `accounts()` still intersects the two — this only narrows what it already
  named, on top of it, rather than replacing it.

- **A refused cell on a page this package does not own stayed refused forever.** `EditRole` recovers
  from a refusal by re-filling its whole form afterwards, which happens to re-stamp the grid's own
  baseline — a page that merely embeds `PermissionGrid` has no such hook. Without one, the stale
  baseline made the very same cell read as touched on every later save, so it kept colliding with
  itself until the page was reloaded. The field now re-reads the store and re-stamps its own baseline
  at the end of its own save hook, on any page.

### Added

- **A ninth, informational audit bucket: grants whose authority no longer exists.** Warden's schema
  puts a foreign key on exactly `assigned_roles.role_id` and `grants.permission_id` — never on either
  polymorphic authority column — so deleting a role takes its assignments and leaves its own grants
  behind, and nothing removes them: not warden, and not `warden:clean`, which prunes permissions
  nothing points at, never grants pointing at nobody. Never red — there is nothing inside this
  package that could cure it. An authority type this installation cannot even resolve is named too,
  once per type.

- **`filament-warden:catalog`**, a new command, with `--panel=`. Prints the catalogue as data — name,
  entity, model, scope, origin — with a column saying whether the permissions table already has a row
  for each entry. Kept out of `filament-warden:audit` on purpose: that command's contract is what is
  wrong and nothing else, and a dump is neither.

### Changed

- **The concurrent-save notification belongs to the field now, not the page — which makes `1.6.0`'s
  own CHANGELOG entry wrong, and it is corrected here rather than edited there.** That entry said the
  notification "is `EditRole`'s" and that a foreign page embedding `PermissionGrid` would read
  `SaveReport` off the container to say something of its own. The first no longer holds; the second no
  longer has to: `EditRole::getSavedNotification()` is gone, and `PermissionGrid::announce()` /
  `RoleAssignment::announce()` send their own report through `Connection::afterCommit()`, so a message
  can never describe a save a later failure undoes — the container binding stays, still reachable, for
  a page that wants to read it by hand.

  Two consequences worth knowing about. On `EditRole`, a concurrent save used to show ONE
  notification — the report replacing Filament's own "Saved" outright — and now shows TWO, side by
  side: Filament's "Saved" and the field's report. This is what the account screen already did, where
  the field was never the page's to replace. And an application that subclassed `EditRole` and
  overrode `getSavedNotification()` loses only what `parent::getSavedNotification()` used to hand
  back — the package's own report. The hook itself is `EditRecord`'s, not this package's: it still
  exists and still fires, there is just nothing of ours left inside it to call up to.

### Not included

- **Phantom "not yet in the store" rows on the permissions listing.** Filament's tables are either a
  query or a `records()` data source, never both — declaring one turns off filtering, searching,
  sorting and pagination at the database, and would force `Provenance::applyTo()`'s SQL to be
  rewritten in PHP, which is the same rule written twice. `filament-warden:catalog` answers this
  question instead, outside the table.
- **An audit gate for "a policy that never consults warden".** Undecidable without executing it: the
  signal such a gate would need is per class, while a screen's cells are per method, so the check has
  structural false negatives — and it would be a false positive on any of these legitimate shapes: a
  policy built on role checks (`Warden::is($user)->a('admin')`, or `$user->isA(...)` from warden's
  `HasRolesAndPermissions`), which consults `assigned_roles` with no Gate and no `Resolver` in sight;
  a policy calling this package's own `Support\Access::granted()`, which resolves the resolver out of
  the container and injects nothing; and a policy composing a trait that provides `allows()`.
- **The roles table's `granted` column, and a `DeleteBulkAction`.** Both recorded as deliberate
  decisions, not gaps.
- **A fifth provenance badge, for "declared, but by another panel".** `Provenance::of()` and
  `::applyTo()` live glued together on purpose; a fifth badge would grow a branch in both, forever.
- **The grid filter**, deferred to the next tag with the redesign it needs. The trap worth writing
  down now, measured rather than assumed: a filter must never prune `state.stances`, because
  `RoleGrants::plan()` walks the catalogue and not the payload, so a pruned cell would read as abstain
  against an unpruned baseline and get WRITTEN — a mass revocation with a success notification on top
  of it.
- **Change events carrying an actor and a diff.** Warden's own events carry neither; this package
  would have to invent both.
- **A consumer testing guide — including a correction of what the backlog claimed for it.**
  `Warden::fake()` does not cover what was promised: it is deny-by-default here, because for a policy
  extending `WardenPolicy` the policy IS the caller, so an unscripted check is a hard deny — the
  opposite of what a reader arriving from warden's own README would assume — and it cannot express
  the authority, the wildcard, `only_owned`, conditions or tenancy at all.

## [1.8.0] - 2026-08-23

Nine small things, and one gate. Nothing on screen moves, except a search that stops obeying a
wildcard. The headline is one thing: the package stops saying things about itself that are not true,
and stops carrying weight nobody lifts.

### Fixed

- **The account picker on a permission's test bench searched for a wildcard instead of a word.** A
  `%` typed in it is a LIKE wildcard, so the box was a way to page through your account table rather
  than a way to find someone in it — and it is offered to everyone who may *view* a permission.
  Escaping it needs an ESCAPE clause, and the character in it had to be measured on three engines
  rather than one. A backslash — the obvious choice, and what this was written with first — is a
  syntax error on MySQL; SQLite and Postgres both take it, so exactly one engine forced the change
  and a first draft of this entry blamed two. Doubling it satisfies MySQL and breaks the other two.
  There is no backslash literal that works everywhere, so the clause uses `!`, measured on SQLite,
  Postgres 16 and MySQL 8.4. The clause itself cannot be dropped either: SQLite has no
  default escape character, so an escaped term without it matches nothing at all.

  The other half of the same method: with an account model whose table has none of `name`, `email` or
  `title`, the query had no condition at all and answered with the first twenty accounts, none of
  them what was typed. It returns nothing now. And the README says what that bench exposes where the
  switch that turns it off is documented, because it is a trade to make on purpose.

- **`Tenants::mixing()` printed "this shows every tenant at once" when it was showing one tenant's
  worth.** Without an active tenant, `warden.scope.null_behavior` decides what a read sees:
  `'strict'` filters to the global rows and the factory `'all'` does not filter at all. Only the
  second is every tenant. It asks warden's own `readFilter()` now.

- **A permission name carrying a dot is found by `filament-warden:audit`.** Livewire splits a state
  path on dots, so such a name cannot be a cell, and the grid throws when it meets one — a 500 on
  somebody's role screen at the moment they open it. The audit walked the same catalogue and said
  nothing. It has a bucket now and reddens `--check` with the rest.

  The two other places that build a state key did so without going through `StateKey`, and they do
  now — defence in depth rather than a hole closed, which is worth stating precisely because the
  first draft of
  this entry claimed the hole: **an action name cannot carry a dot today.** Those names come from
  reflecting a policy's methods, and `public function export.csv()` is a PHP parse error. The name
  that *is* reachable is a loose `catalog.custom` one, and that has always thrown — what changed is
  that the audit finds it first.

### Changed

- **The grid script says how many rules it carries, and it is seven rather than one.** It opened by
  claiming it carried none of its own bar the clause cut; a first correction said five and also
  under-counted. `drawn()`, `reached()`, `answers()`, `granted()`, `reachOf()`, `clauses()` and the
  `preview()`/`lineOf()` pair each decide something PHP decides too, and each is there for the same
  honest reason: a click has to redraw without a round trip. They cannot be collapsed, so the script
  names every counterpart by the file it actually lives in — `Cell`, `GridView`, `Tab`, `Narrowing`,
  `Rule` — and `GridView` points back at that list. A pair that has to move together should not have
  to be discovered.

- **Three public methods are gone, and one turned out not to be dead.** `Stance::next()`/`previous()`
  spelled the cycle out a second time, three lines under an `order()` whose own comment warns about
  exactly that. `RoleState::locked()` was a second expression of `isEditable()`.
  `Provenance::isDeclared()` had no reader. `StateKey::action()` looked like the fourth and was the
  disconnected guard above.

- **Six classes are `final`.** Sixteen were open with no reason written near them; the README names
  which ten are open and why — the resources and their pages, so an application can experiment. The
  other six build a form, an infolist or a table, and nothing was ever promised about those. The ten
  keep their door open and now say so where a reader is.

- **Two helpers written out many times are one each.** `Support\Morph::model()` answers the question
  every morph reader actually had — is this a model? — instead of six copies of
  `getMorphedModel($x) ?? $x` each re-checking the result. `Support\Line` collapses seven copies of a
  translated line into the two general fallback policies, and says why they cannot be one: a key we
  ship must exist, a key named after something your application owns may not. A third policy stays
  where it was, with `Grants\Cause`, because its fallback is the enum case's own value and nothing
  shared can know that.

- **The grid's template stopped deciding two things.** It compared `drawn()` against the literal
  `'broader'` — one of `Cell`'s own words, in the file that decides nothing — and held the `&&` for
  whether a cell can be worked at all.

- **"Did we write this title?" is asked in one place.** `PermissionName::generated()` answered for the
  doors this package mints; the permission form asked warden's generator directly for everything
  else. Same question, two families of row, one method now.

### Added

- **`release.yml` refuses a tag whose subject is not `<tag> — <headline>`.** From `1.5.0` that
  subject *is* the release title, verbatim, and five of the tags that already have a release carry
  the headline with no version on the front — measured when there were 29 of them. It is the one half of the convention a machine can
  check; that it is in English is not, and AGENTS.md says so rather than pretending otherwise.

### Not included

- **Nothing about the grid or the account form.** Concurrent editing was closed in `1.6.0` and
  `1.7.0`; this release does not touch either path.
- **A page of your own embedding `PermissionGrid` still gets the protection and not the report.**
  `1.7.0` showed the shape of the answer — a field that speaks for itself — and applying it to the
  grid is still open.
- **No major.** Every public method removed here lives in a namespace the README declares internal.

## [1.7.0] - 2026-08-23

`1.6.0` stopped two people editing the same role from undoing each other. This does the same for the
other screen that saves a whole set at once: handing roles out from an account.

### Fixed

- **Saving an account's roles wrote back over whatever somebody else had changed in the meantime.**
  `Assignment::apply()` compared the store against the browser's payload, exactly as the grid did
  before `1.6.0`: a role somebody else handed out while this screen sat open was unticked back, and
  one they took back was handed out again. Every role in the list was a potential write, not only the
  ones this person clicked. `1.6.0` measured this and shipped without fixing it.

  It now compares three things, and a role this person did not tick or untick is left exactly as
  whoever did left it. The field says so afterwards and re-reads the store, so the next save starts
  from what is actually there.

### Changed

- **The copy of what the store said travels beside the field's own state, under a namespaced key.**
  A `CheckboxList` has one state slot and the set is in it — that part of `1.6.0`'s reasoning was
  right. What was wrong was the conclusion drawn from it: a field can put a sibling key beside its
  own, and this one does. Measured before it was relied on rather than argued: the key survives the
  mount, a click and the save, and `Schema::getState()` does not return it, so it can never reach
  `$record->update()`. That last part is checked by a test; the rest was measured on a real
  `EditRecord` and `CreateRecord` in a throwaway probe and is not pinned by anything in the suite,
  which is said here rather than implied.

  The place it goes is this field's own **container path**, not a property called `data`. Filament's
  own resource pages do call it that, but Filament itself mounts schemas under seven other roots —
  `filters`, `tableFilters`, `deferredTableFilters`, `columnMap`, `settings`, `data.multiFactor` and
  `mountedActions.{i}.data` — so keying off the name would have left the field silently unprotected
  wherever the name was something else, with the screen looking fixed. And keying off the *root* of
  the path, which was the first correction, is worse than the name: an action modal's root is
  `mountedActions`, a public array Filament reads and writes and still not a state bag, and a string
  key in it breaks Filament's own action machinery. A schema with no state path of its own leaves the
  field nothing to sit beside, and then there is simply no baseline.

- **Nothing is ever refused from this screen, and that is a property of a checkbox rather than a gap.**
  A cell has three stances, so two people can move one to *different* values and genuinely collide. A
  role is held or it is not. If the store and the payload disagree and the baseline also disagrees
  with the payload, this person did not touch it; if the baseline agrees with the payload, whoever
  moved the store moved it the same way. Both moving it to different values is unreachable — there is
  no third value to differ about. `SaveReport::refused` stays empty here, always, and a test says so.

- **`Assignment::apply()` returns a `SaveReport`** and takes an optional baseline as its last
  argument. A caller that passes none is asserting a state outright rather than relaying a form
  somebody had open, so every role counts as touched — what this method did before. `SaveReport`'s
  `refused` widened from the grid's own cell keys to any map, so one report serves both screens
  instead of two nearly identical ones.

- **The field sends its own notification.** The grid leaves that to `EditRole`, which owns its page
  and replaces the "Saved" notification outright; this field is one line inside a form the application
  wrote, so there is nothing of ours to replace and saying nothing would be the silence this release
  exists to end. It sends one beside whatever the page sends.

### Not included

- **The gap `1.6.0` disclosed for an embedded `PermissionGrid` is still open.** A page of your own that
  embeds the grid gets the protection and not the report. This release shows the shape of the answer —
  a field that speaks for itself — but applying it to the grid is a change to the roles screen, and
  this release is about the account screen. It goes to `1.8.0`.
- **The roles relation manager is untouched, and does not need touching.** Its actions say "assign
  *this* role", not "make the set equal this".
- **No history of who changed what**, and **no automatic resolution** in either direction.
- **A role the screen may not hand out is still dropped without being counted.** `mayHandOut()`,
  `isRestricted()` and `isElsewhere()` skip a role before any of this, exactly as they did before, so
  an intent they refuse is not in `written`, `preserved` or the notification. The screen shows why
  next to the checkbox, so it is visible rather than silent — but "nothing is ever refused here" is a
  statement about two people colliding, not about everything a save might decline to do.
- **Everything under "Calidad" moves to `1.8.0`**, with the tag-subject shape check `release.yml` was
  promised. `1.6.0` said those would be `1.7.0`; the second half of a data-loss fix earned the slot
  instead, and a release headline should be one thing.

## [1.6.0] - 2026-08-23

Two people editing the same role stopped undoing each other's work. Nothing else moved.

### Fixed

- **A save wrote back over whatever somebody else had changed in the meantime, silently.** The grid
  compared the store against the browser's payload and wrote every cell where the two disagreed —
  but a payload is not an intent. It is what the store held when the screen opened plus whatever
  this person changed, and nothing separated the two halves. So a cell somebody else had moved while
  this page sat open was quietly moved back: what they revoked was granted again, what they granted
  was revoked, and it applied to **every drawn cell**, not only the ones this person touched. There
  was no error, no notice, and nothing on screen afterwards to say it had happened.

  The screen now stamps what it was showing into its own state, and the save compares three things
  instead of two. A cell this person did not move is left exactly as whoever did move it set it. A
  cell this person moved, with nobody else in the way, is written as before. A cell both people moved
  to **different** values is refused and named, rather than resolved in silence in favour of whoever
  saved last — on a screen that decides who can do what, overwriting a colleague's deliberate change
  is the defect, not a resolution of it. Two people who moved the same cell to the **same** value need
  no branch and get none: their payload and the store already agree.

  A save that met nobody else keeps the notification it has always had. One that did says which cells
  were kept and which were refused, naming up to five and counting the rest — the shape
  `Holders::LABELS` already used, though not its number: ten labels fit a screen, five cells fit a
  sentence. And the grid re-reads the store afterwards, so the next save starts
  from what is actually there instead of colliding on the very same cells again.

### Changed

- **A cell is compared whole — its stance and how far it reaches — and the reach only weighs in when
  the baseline actually holds one.** It does not when the condition builder is switched off, nor when
  the stored rule is one this screen can read and cannot rebuild from a payload. Both are a flag
  rather than a stand-in value, because a substituted reach can make one of the two comparisons
  trivially true but never both, and the one it misses collapses into "the reach changed" — which is
  true of every cell somebody clears. That would have refused a lone administrator's own revoke and
  blamed a colleague who was not there.
- **A forged baseline cannot escalate.** It decides only *whether* a change is written, never what:
  the stance and the reach come from the payload. The worst a doctored one does is suppress the
  forger's own save, which is what everybody had before this release.

- **The grid field's state envelope gained a third key, `baseline`.** It is the same payload
  `RoleState::toPayload()` already worked out at hydration — not a second derivation of the store
  that could drift — and it travels in the state because it cannot be worked out later (by save time
  the store answers about *now*) and cannot live on the component (Filament rebuilds the schema every
  request). **Adding to a frozen surface is a minor by this package's own rule**: an application
  reading `stances` or `narrowing` goes on working. `tests/FrozenTest.php` still turns red on the
  addition, deliberately, so a new key is a line somebody typed rather than a diff nobody read.

  The read-only screen is handed no baseline, and the test that keeps the two screens from drifting
  now says so explicitly instead of being loosened: their shared halves must still match byte for
  byte, and the extra key has to be a copy of that same payload.

- **`RoleGrants::apply()` returns a `SaveReport`** instead of nothing, and takes an optional baseline
  as its last argument. A caller that passes none — a console script, a seeder, a test — is asserting
  a state outright rather than relaying a form somebody had open, so every cell counts as touched,
  which is what this method did before there was a baseline at all. `Grants\` is not part of the
  frozen surface.

- **`GridView::cellLabel()` is new**, so a notification about a refused cell says the words the grid
  says rather than inventing a second vocabulary for the same cell on the same screen. It reads the
  catalogue, which `1.5.0` memoised per panel, so asking again costs nothing.

- **A ninth thing runs under `make verify`.** The baseline survives the round trip only because both
  of the grid script's state writes are spreads, which carry a key they know nothing about. Rewriting
  either as `{ stances, narrowing }` reads like tidying up and would drop the baseline from the first
  click onward — with no error, no failing PHP test and nothing on screen, because a payload with no
  baseline is indistinguishable from a screen that never stamped one.
  `verify/verify-baseline-survives.mjs` drives the real component under the real `@vue/reactivity`
  Alpine pins, and does that rewrite by hand as its own control.

### Not included

- **Handing roles out from an account has the same defect and is not fixed here.** Measured, not
  assumed: `Assignment::apply()` compares the same two things — `$isHeld` against `$isWanted` — so
  the same lost update happens in both directions. It is not fixed because the baseline does not fit:
  a `CheckboxList`'s state is a flat list with one slot, `RoleAssignment` is a frozen field so
  turning it into a group with a hidden sibling is a major, a sibling field cannot be added because
  the form belongs to the application, and a sentinel inside the list is a value the field's own
  options and lock rules know nothing about. It goes to `1.7.0`, where the shape of that field can be
  decided on its own terms. The window there is also far narrower: two people editing the **same
  account** at once, rather than the same role.
- **A page of your own that embeds `PermissionGrid` gets the protection but not the report.** The
  three-way comparison lives in the field, so nothing is silently reverted there either; the
  notification is `EditRole`'s, so a refused cell on your page is simply not written and the save says
  nothing. Reading `SaveReport` off the container after a save is what a page would do about it.
- **The roles relation manager is not touched, and does not need to be.** Its actions say "assign
  *this* role", not "make the set equal this" — the vulnerable shape is the set diff, and that is the
  thing worth naming.
- **A cell is compared whole, and a cell is the unit.** Two people editing two different lines of the
  same multi-line condition still collide as one cell.
- **No history of who changed what.** That is a separate feature and it is not in this release.
- **Everything under "Calidad" — the ten quality items this version was originally going to carry —
  moves to `1.7.0`**, and with it the tag-subject shape check `release.yml` was promised. The headline
  of a release should be one thing.

## [1.5.0] - 2026-08-23

Nothing on screen changes. What changes is what the screens cost — and one correctness bug found on
the way there, in a transaction nobody was looking at.

Every figure below was measured, in the unit it names, and the unit is not decoration: this release
produced one fix that cut a listing from 11 statements to 3 while making it hydrate the whole
`assigned_roles` table twice per render. A cap counting statements could not see it. So each number
here says what it counts, and a projection says that it is a projection.

### Changed

- **The catalogue is built once per panel, not once per call.** `Catalog::for()` reflects every
  Policy a panel declares — through `Gate::getPolicyFor()`, which the container resolves with no
  cache of its own — and walks the panel's resources, pages and widgets besides. It is now memoised
  per panel id, the shape `Conditions\Columns` already used for a model's schema. **Measured by
  counting Policy constructions**, a real side effect rather than a counter invented in `src/`:
  three calls to `Catalog::for()` on the same panel cost **3 policy instantiations → 1**.

  Two consequences worth knowing. `catalog.models` and `catalog.custom` changed at runtime are
  **invisible to an already-built catalogue until `Catalog::forget()`** — the same rule `Columns`
  applies to a schema. And the memo stores the `Panel` beside its rows and compares it with `===`,
  so a second `Panel` object that happens to reuse an old id rebuilds instead of being served the
  first one's answers: an id alone can lie, and what a catalogue answers decides authorization.

- **`Holders` is memoised per record, and the two lock checks ask a cheaper question.**
  `PermissionInfolist` alone asked `Holders::of()` five times over the same record. Measured in
  statements against the **`grants` table**: `EditPermission` on mount **14 → 3**; the
  `ViewPermission` card **7 → 3**.

  A ten-row permissions listing goes **27 → 17 total statements** — and here the unit is the whole
  story: the listing's **`grants`-only** count did not move at all (**12 → 12**). The saving is ten
  `roles`-table queries that the old `Holders::of()` made per held row for its labels, and that the
  new `anyFor()` EXISTS never makes. Anyone re-measuring this with a `grants`-only filter will see
  nothing move.

  The memo is a `WeakMap` keyed on the model instance, not `once()`. `once()` called from a static
  method is a trap: `Onceable::objectFromTrace()` finds no `object` frame for a `self::` call, so
  `Once::value()` falls back to the one shared `Once` singleton in the process, disambiguated by a
  hash folding in `spl_object_hash()` — a value PHP reuses the moment the object it named is
  collected. A permission read, freed and replaced at the same address would inherit the first one's
  holders, and `isDeletable()` would call a row with holders an orphan.

- **A grid save writes in groups.** Cells that share an entity and a stance and have nothing left to
  narrow now go out in one warden call. Measured in total statements around one
  `RoleGrants::apply()`: five cells sharing an entity, all granted, **41 → 25**. One cell alone
  **9 → 9**, unchanged — pinned by its own cap so grouping can never regress the case it cannot
  help. A 20-resource, 7-action panel projects to **1121 → 641**, and that one is **arithmetic on a
  formula both measurements satisfy exactly** (`old = 1 + 8N`, `new = 1 + 4G + 4N`), not a third
  measurement; a re-save widens the gap rather than narrowing it, so the projection is conservative.

  The honest promise is not "one write for the whole grid". It is that the up-to-five warden calls a
  changed cell used to cost become up to five per **group**. Cells narrowed to "only what it owns" or
  to conditions still run one at a time, and that is warden's limit rather than a choice:
  `where()` goes through `reconstrain()`, which re-points **every** permission in the chain at the
  same twin, so two cells asking for two different conditions can never share a call.

- **Grouping changes the granularity of a veto, which is behaviour and not cost.**
  `GrantsPermissions::to()` builds **one** `GrantingPermission` / `ForbiddingPermission` per call,
  carrying every name in it, and `eventPermits()` decides on that list. An application that listened
  for those events to veto one cell now vetoes every cell grouped with it. This exists only inside
  `RoleGrants::apply()`; a `Warden::allow()` an application makes itself is untouched.

  The revoke side moved too, more quietly and with nothing to veto — there is no pre-event there.
  `PermissionRevoked` / `PermissionUnforbidden` and the cache bump are gated on whether the group's
  `delete()` removed any row at all, and that gate now covers the whole group: a name with nothing to
  revoke used to mean no event for it, and can now ride inside one fired because a sibling had a row
  removed. The event's collection still names every permission the group resolved.

  Ordering also became structural rather than habitual: revoke now runs to completion across the
  whole batch before any grant runs, where the old per-cell path merely happened to do it in that
  order each time.

- **The two screens that read once per row now read once per page.** The assign modal at 200 roles:
  **405 → 3** `assigned_roles` statements, and flat at both a 20-role and a 200-role catalogue.
  `Assignment::descriptions()` over two held roles: **5 → 2**. `give()` and `apply()`: **6 / 47 →
  4 / 5**.

  The roles listing is the one that needs both numbers. Statements went **11 → 3** — and the first
  shape that achieved it hydrated the whole `assigned_roles` table twice per render, bounded by
  nothing where the old shape was bounded by the page size. Head to head at 20 000 assignment rows:
  **0.439 s / 46 MB → 0.002 s / 0.0 MB** (peak allocation with both collections held). The shipped
  shape is bounded by the role catalogue with `groupBy` and `distinct`: at 200 assignment rows,
  **400 → 10 rows hydrated** while statements stayed **3 → 3**, which is precisely why the statement
  cap was blind to it. The cap that replaced it counts rows, through Eloquent's `retrieved` event.

  The §6.24 split survives the batching and is pinned through it: the "held by" column, which
  **informs**, stays tenant-scoped; the delete button, which **decides**, reads wide.

- **`RoleResource::canDelete()` and `::isDeletable()` gained an optional trailing parameter.** Both
  are `public static` on a non-final class, so **a subclass that overrode either is now a fatal**.
  The Stability section already says the screens are not an extension point; this is what that
  sentence costs in practice, said out loud rather than left to be discovered.

- **PHP `^8.5` → `^8.4`.** Not one line of 8.5-only syntax exists in this package — re-established
  three ways, including a parser probe over all 163 files at target 8.4 — and no dependency asks for
  it. The CI matrix goes from 2 to 4 combinations, adding 8.4 to both `prefer-lowest` and
  `prefer-stable`.

- **`make stan` runs PHPStan twice, at the ceiling and at the declared floor.** A single
  `phpVersion: {min, max}` range does **not** report the union of the two ends:
  `PhpVersionFactoryFactory::create()` takes `min` and hands that one version to every
  version-dependent rule. Measured on a file carrying one 8.4 deprecation and two 8.5 ones — the
  range reported only the 8.4 one, byte for byte what `phpVersion: 80400` reports, while the runtime
  default reported all three. Both runs proved load-bearing in opposite directions: the ceiling
  catches 8.5 deprecations the floor is blind to, the floor rejects 8.5 syntax the ceiling accepts.

### Fixed

- **The grid's transaction opened on the wrong connection, and that silently disabled a cache
  invalidation.** `RoleGrants::apply()` and `Assignment::apply()` called `DB::transaction()`, which
  uses the default connection, not `warden.connection`. On a single-connection install that is a
  no-op. On a split-connection one it wrapped queries that never ran on it — and it turned off a
  promise `BumpsCacheVersion` already made: that trait only registers its after-commit second bump
  when **warden's own** connection reports `transactionLevel() > 0`, so that check read zero always
  and the second bump never registered. That bump exists to orphan a payload a concurrent reader
  rebuilt from pre-commit rows, so what was lost was not lock scope but a cache invalidation —
  stale authorization answers, with no expiry and nothing to see. The comment claiming the trait
  registered one became true only with this change.

### Added

- **`Catalog::forget(): void`**, and `Catalog`'s public surface is now frozen at exactly six methods
  — `for()`, `relationManagers()`, `resourceClasses()`, `pageClasses()`, `widgetClasses()`,
  `forget()` — pinned by `tests/FrozenTest.php` and named one by one in the README's Stability
  table, because a promise that does not name its elements is a category and not a promise.
- **`Holders::anyFor(Model): bool`** — a single `EXISTS` for the two lock checks that only ever
  needed a yes or no — and **`Holders::forget(Model): void`**, the escape hatch for a caller that
  writes a grant and re-reads the same instance. Nothing in `src/` calls `forget()` today; the test
  that pins the invalidation rule does, and it is exercised rather than decorative.
- **`.github/workflows/release.yml`.** A pushed `v*` tag now creates the GitHub release with that
  version's CHANGELOG section as its body, and refuses to publish rather than publish an empty one
  when no section matches. AGENTS.md §8 has asked for this since two tags shipped with no release
  and had to be created by hand.
- **`phpstan-floor.neon`**, the second half of the PHPStan gate, export-ignored like every other
  development file at the root.
- **Two gates in `tests/PackageTest.php`.** One pins the PHP floor across every file that states it
  — `composer.json`, `phpstan-floor.neon`, the CI matrix, and the README's badge URL, its `alt`
  fallback and its Requirements row — because lowering a floor is silent in all of them at once. The
  other asks `git archive` what the distribution actually holds and compares it, per shipped
  directory, against what git tracks: the first thing in this suite to read the tarball rather than
  infer it from `.gitattributes`.

### Not included

- **Everything under "Calidad" in the plan moves to `1.6.0`, by a decision made before this tag was
  planned rather than by omission.** The six JS rules the script re-implements against the one its
  own comment claims, the publics only tests call, the two rules that live in Blade, the search
  `ViewPermission::accounts()` ignores, `Tenants::mixing()` under `'strict'`, the stale title after a
  rename, the helpers copied seven times, the 16 non-`final` resource classes, the dotted custom name
  that 500s a role screen, and concurrent editing silently re-granting what another save revoked.
  The headline of a release should be one thing; that is a different one.
- **`Shape::Owned` cells are not grouped, although they provably could be.** Only `Shape::All` is.
  Grouping ownership writes would work today and would leave a trap for whoever next touches the
  narrowed path, where `reconstrain()` makes sharing a call unsafe.
- **This release is the first thing the release workflow has ever published.** Before it, its title
  extraction, its CHANGELOG extraction, its pre-release flag and its refusal on a missing section
  had been exercised only with `act`, against synthetic tag pushes on this repository's real
  history; the command-injection fix was demonstrated in both directions with the same payload. `act`
  also skips `actions/checkout` by default, so the checkout half was established by reading the
  action's own refspecs and by fetching the live remote by hand. If you are reading this on GitHub,
  the rest of that chain has now run once. Packagist picking it up is downstream of this file and is
  checked by hand.
- **The distribution gate has three limits, disclosed rather than closed.** A development file
  somebody *tracks* inside `src/` is counted on both sides and ships; the oracle reads `HEAD` and
  not the tag a consumer installs; and it is blind to a root file until that file is committed.
- **The PHPStan ceiling is still whatever the runtime is**, stated independently in `compose.yaml`,
  `quality.yml` and the analyser cache key with nothing crossing them. That is not new — the ceiling
  was runtime-driven before this release too, and the net position is strictly better than it was.

## [1.4.0] - 2026-08-22

`RoleAssignment`, since `v0.7.0`, has been the only way to hand a role out from a screen — a
`CheckboxList` that does not page, does not sort, and does not say why a role is where it is. This
release adds the other half: a relation manager a consuming application attaches to its own
`UserResource`, for the installation that field cannot serve.

### Fixed

- **The tab could rename or delete a role through actions it never shows.**
  `RolesRelationManager` pointed `$relatedResource` at `RoleResource`, which routed `makeTable()`
  through `RolesTable::configure()` and cached ITS `EditAction`/`DeleteAction` into the table's
  `$flatActions` — `recordActions([retract])` replaces the array a render walks but not that cache
  (`HasRecordActions.php` has no `removeCachedActions()` call, unlike `headerActions()`), and
  `resolveTableAction()` resolves a mounted action by name straight off it. Measured: a raw
  `mountAction`/`callMountedAction` call reached the leaked `edit` to rename a role signed in with
  only `viewAny`/`update`, and the leaked `delete` to remove one signed in with `delete` and
  `roles.delete => 'all'`. Not privilege escalation — both leaked actions run the same Policies a
  direct call to `RoleResource`'s own screens would — but the leaked `edit` opened the permission
  grid on a path that bypasses `EditRole::mutateFormDataBeforeSave()`, the protected-role rename
  guard AGENTS.md §6.24 built for that page, and it contradicted what this same release says about
  itself twice: the README and this class's own docblock both promise "never `AttachAction`,
  `DetachAction` or `DetachBulkAction`" while two of Filament's *other* built-ins were reachable
  regardless. The same setting also reached `RoleResource::getUrl('edit', …)` for the table's
  default row link, which threw `Route [filament.{panel}.resources.roles.edit] not defined` as soon
  as the table had one row on a panel that never registered `RoleResource` — a combination
  `->roles(false)` and this relation manager are both documented in this same release, with nothing
  testing them together. Fixed by setting `$relatedResource` to `null` and overriding
  `canViewForRecord()` directly instead of relying on the base class's own `$relatedResource` branch
  to provide it: `configureTable()` never runs, so `flatActions` holds only `assign`/`retract`, and
  the default row-link closure finds no `edit`/`view` action to build a URL from. One consequence,
  accepted rather than worked around: this table draws no row link at all now, where the leaked
  `edit` action gave it one. That link was undocumented, untested and unmentioned anywhere in this
  file, so nothing shipped is being taken away — it existed only within this unreleased branch.
- **The same fix above silently swapped a translated label for an untranslated one.** The
  `if ($relatedResource = …)` block in `makeTable()` that leaked `edit`/`delete` did two MORE
  things: `$table->modelLabel($relatedResource::getModelLabel())` and the plural sibling, which
  read this package's own translated `ui.resources.roles.model`/`.models` through `RoleResource`.
  Setting `$relatedResource` to `null` switched those off too, and nothing fell back to them:
  Filament's own `get_model_label()` (`Support/helpers.php:55-60`) — a bare
  `Str::plural(kebab(class_basename($model)))` — is always English and always lowercase regardless
  of the application's locale. `HasEmptyState::getEmptyStateHeading()` reads exactly that label for
  "No :model", the state every new account is in, not an edge case: measured before this fix,
  `No roles`; the translated string is `Roles`. Fixed by setting `->modelLabel()`/
  `->pluralModelLabel()` explicitly in `table()` from `RoleResource::getModelLabel()`/
  `::getPluralModelLabel()` — the same keys, read once, never duplicated.
- **The "held as" badge's `restricted`/`elsewhere` distinction was pinned by nothing that could see
  the two swap.** The two tests that already built exactly those scenarios asserted only that the
  retract action was hidden, which reads the same either way `heldAs()` answers, and
  `LanguageTest.php`'s own pin compares the SET of strings `heldAs()` can produce against the SET of
  declared translation keys — blind to which record produces which value. Both tests now also
  assert the column's own state with `assertTableColumnStateSet()`.

### Added

- **`RolesRelationManager`**, a new public (not `final`) class at
  `ElPandaPe\FilamentWarden\Filament\RelationManagers\RolesRelationManager`. A package cannot
  attach a relation manager to a resource it does not own — `Resource::getRelations()` is a
  concrete static and there is no registry a plugin can write to (AGENTS.md §6.18) — so the whole
  of what a consuming application does is add one line to its own `UserResource`:

  ```php
  public static function getRelations(): array
  {
      return [RolesRelationManager::class];
  }
  ```

  It lists the roles the signed-in account's target holds, read through `Assignment::of()`, which
  already deduplicates a role assigned both with and without a context to one row, with a badge
  naming how it is held: here, elsewhere, or restricted to a context. One header action assigns a
  role from a searchable list; one row action retracts it. Both are hand-written, never
  `AttachAction`, `DetachAction` or `DetachBulkAction` — those three check **no policy at all** in
  Filament 5.7 (`RelationManager::getDefaultActionAuthorizationResponse()` closes them only with
  `isReadOnly()`, `false` on any edit page) — and both write through warden's fluent API, never
  `attach()`/`detach()`/`sync()`, which skip warden's cache bump the same way `RoleAssignment`
  already warns about. Both actions are hidden **and** denied in the server on a `ViewRecord` page,
  where `isReadOnly()` is `true` but closes no `Action` of this package's own — only Filament's own
  action classes are in that `match`.
- **`Assignment::give()`/`take()`**, two new public methods, one entry point per role. The relation
  manager's actions write through these, never `Assignment::apply()` — `apply()` is a set diff over
  the *whole* catalogue, unmemoised, the right shape for `RoleAssignment`'s `CheckboxList` (which
  hands over the entire wanted state at once) and the wrong one for a row action that already knows
  exactly which role it touched. Measured over a 21-role catalogue, counting `assigned_roles`
  reads: `give()` reaching a given state costs **6**, `apply()` reaching the same state costs
  **47** — capped at `give()` ≤ 10, with a second assertion that `apply()`'s count is strictly
  higher, so the comparison itself is what the guarantee rests on, not a number frozen for one
  side.
- **`FilamentWardenPlugin::roles(bool $condition = true)` and
  `::permissions(bool $condition = true)`**, two new fluent methods. Off leaves the matching
  resource unregistered — the role resource takes its grid with it, the permission resource with
  it. Both default to `true`, so an application that upgrades and calls neither keeps both
  resources exactly as before. The guard, the audit and the two Filament assets stay registered
  regardless of either toggle.
- **A "held by" count on the roles listing**, and a **"Who holds it" section on `ViewRole`**,
  both reading `assigned_roles` under the active tenant. The section names up to 10 holders
  (`Holders::LABELS`, the same cap the permissions screen already uses) and skips an authority
  whose morph alias no longer resolves rather than erroring.
- **The delete action's modal now says what it takes with it, on all three surfaces a role can be
  deleted from** — the roles listing, `ViewRole`, and `EditRole` — mirroring
  `PermissionResource`'s own three-surface coverage since `v1.0.2`. The warning reads **wide**
  (`withoutGlobalScopes()`), the same reasoning `RoleResource::isDeletable()` already uses: the
  cascade that removes `assigned_roles` rows on delete is blind to tenancy, so counting only the
  active tenant's rows would understate what is actually lost.
- **17 new keys** in `en` and `es`: `relations.roles.held_column`, `relations.roles.held.here`,
  `relations.roles.held.elsewhere`, `relations.roles.held.restricted`,
  `relations.roles.assign.label`, `relations.roles.assign.heading`, `relations.roles.assign.field`,
  `relations.roles.assign.notified`, `relations.roles.retract.label`,
  `relations.roles.retract.notified`, `resources.roles.sections.holders`,
  `resources.roles.columns.held`, `resources.roles.holders.description`,
  `resources.roles.holders.nobody`, `resources.roles.holders.held`, `resources.roles.delete.nobody`,
  `resources.roles.delete.holders`. The flattened translation list `FrozenTest.php` pins moves
  `190 → 207`, both locales identical, regenerated from the language files rather than counted by
  hand.
- **Stability**: the plugin now names six methods instead of four — `make()`, `getId()`,
  `register()`, `boot()`, `roles()`, `permissions()` — each named one by one, per the lesson §6.24
  already drew from `->guardPages()`/`->guardWidgets()`/`->tenant(null)` shipping against a test
  that only asserted a category. `RolesRelationManager` gets its own row: its class name is frozen,
  since a consuming application's own `UserResource::getRelations()` stores it by name.

### Not included

- **`->cluster()`, promised in the plan, is not shipped — a verified negative, not an omission.**
  A resource's cluster is `protected static ?string $cluster` on the resource class itself
  (`BelongsToCluster.php:12`), with only a getter and no setter anywhere in the framework: no
  `Panel::cluster()`, no field on `ResourceConfiguration` (which carries exactly `resource`, `key`,
  `slug`), and `Panel.php` itself has zero cluster-related methods. Assignment happens once, at
  registration time, inside `HasComponents::resources()` → `registerToCluster()`, reading that
  static property directly — there is no later hook and no per-panel override this call accepts.
  The only way left to change the answer is to reflection-mutate the class's own static property,
  which is one value shared by the whole process, not scoped per `Panel` — the same unscoped
  global-switch shape AGENTS.md §6.21 already names for `Warden::tenant()->to()`, one layer lower
  (a PHP class static instead of a container singleton). Two escapes were checked and ruled out: a
  subclass (every `Page` hardcodes `$resource` at the concrete base, so the same problem cascades
  to it) and `Panel::bootUsing()` (clusters are computed before those callbacks run, and
  `resources()` only appends — it does not let a later call redirect an already-registered
  resource's cluster). Two of the three plugin options from the plan ship; the third does not,
  because there is no version of it that would not silently fight another panel loading the same
  plugin in the same process.
- **This release makes two screens measurably slower, and neither cost is fixed here** — both are
  deferred to the `Catalog`/`Holders` memo already promised for `v1.5.0` ("Que no cueste"), and both
  are measured and capped by a test rather than only described. The roles listing's new "held by"
  column costs **11** `assigned_roles` reads for 5 roles (2 per row plus 1 fixed overhead), on top
  of the pre-existing read `isDeletable()`'s own delete-button `visible()` already paid — capped at
  13 by a test. The relation manager's own assign modal costs **405** `assigned_roles` statements
  against a 200-role catalogue — a new cost, not a carried-over one: this screen did not exist
  before this release — capped at 410 by a test: each option's `disableOptionWhen()` check re-reads
  the assignments table fresh, because `give()`/`take()` write to that same table and a memo there
  would risk handing a check made right after a write a stale row list in the same request.
- **The same untested `modalDescription` wiring this release closed on all three role surfaces is
  still open on the three permission ones.** `PermissionsTable.php`, `EditPermission.php` and
  `ViewPermission.php` each carry a `->modalDescription(...)` closure with no test proving it is
  wired to that specific action — the exact gap this release closed for `RolesTable.php`,
  `ViewRole.php` and `EditRole.php`. Pre-existing, not introduced here, not touched.
- **Two minors `1.3.0` left open are still open — checked, not assumed.**
  `conditions.locked.model` still has no test proving the sentence is ever actually shown on
  screen. `RoleGrants::writable()` still has no test for a string-typed tenant id, the same
  systemic gap `1.3.0` closed on `Assignment`'s side of the identical comparison.
- **Nobody attached the relation manager to a real application and opened it.** Every guarantee in
  this release is verified end to end through Pest's HTTP/Livewire layer only. The deliverable
  *is* a screen, and unlike the last three releases, that screen has not been seen — not in a
  browser, not in light mode or dark, not with `RolesRelationManager` actually wired into a
  `UserResource::getRelations()`. Pending, not seen.

## [1.3.2] - 2026-08-22

`1.3.0` closed this hazard on the permission form and said, in its own release note, that it was
still live on the grid. This release closes it there — and, unlike `1.3.0`, it draws no new lock:
no cell becomes uneditable, no new lock sentence, no new key. Say that plainly, because anyone who
read the `1.3.0` note is expecting the opposite. It does take one thing away, narrowly — see *Not
included* below.

### Fixed

- **Flipping a grid cell's stance — grant to forbid, or back — could silently rewrite a condition
  the click never touched, changing what it matched.** A rule stored as the string `'true'` —
  which warden compares with `===`, and so never matches a row only because that column is cast to
  `boolean` (a plain varchar column literally holding `'true'` would match) — could come back as
  the boolean `true`, which then matches every row whose column is `true`, on a click that only
  meant to change one cell's stance, not its condition. `RoleGrants::changes()` used the same
  object, `$wanted`, for two different jobs: as the right-hand side of `Narrowing::is()` — which
  compares `toPayload()`, and a payload carries every value as text, the same distinction `1.3.0`
  already drew for the permission form — to decide **whether** a cell moved, and then again as the
  source of **what** to write. So the diff correctly read an untouched condition as unchanged, and
  then wrote it back anyway from the browser's own rules, already cast through `Value::cast()`.
  Fixed by asking the two questions of two different things: `$moved = ! $stored->is($wanted)`
  still decides *whether*, computed once; what gets written is `$wanted` only when `$moved` is
  true, and the row already in the store, untouched, otherwise. Covers all three value shapes a
  condition can hold: an integer string (`'2'` → `2`), a decimal string (`'2.5'` → `2.5`), and a
  boolean string (`'true'`/`'false'` → `true`/`false`) — the first and third are pinned by their
  own tests, the decimal follows the same `Value::cast()` path as the integer and is pinned
  alongside it. Verified by breaking the fix itself, in both directions, each mutation committed
  first and reverted with a named `git checkout`: reverting to always write the browser's rules
  reddened exactly the two tests that assert preservation and never the one that asserts a real
  edit is written as sent; always writing the stored row instead reddened that control test, plus
  four pre-existing tests nobody had to add. Two of them are a cell with no prior condition at all,
  where the stored side defaults to "every row" and would have overwritten the operator's
  first-ever rule with it; the other two are not — one makes two saves and asserts on the second,
  which acts on a cell that already holds a prior condition, and one overwrites an ownership choice
  rather than a rule.
- **Why this one locks nothing, unlike `1.3.0`.** The permission form edits the row a condition
  lives in, so a round trip that does not come back byte-identical has to be refused — that release
  drew a lock and a sentence for it. The grid never edits a row: a stance change points the grant at
  a twin, either the one already stored or a freshly written one, so it is enough to point at the
  right one.

### Not included

- **One capability does go, narrowly — and only for a stance-only flip.** Before this release,
  flipping a stance with the condition payload otherwise byte-identical would normalise a mis-typed
  stored value as a side effect — the very repair `1.3.0` already refused to make from the
  permission form, on purpose, because it only ever widened what a rule matched. That accidental
  path is closed for exactly that case: since `1.3.0` locked those rows on the permission form and
  this release stops a bare stance flip from touching them either, no click that changes nothing
  but the stance can still rewrite such a row. Warden's own fluent API can. So can this screen, one
  other way — see the next bullet.
- **Editing any other line of the same cell still re-casts a mis-typed line it never touched.**
  `RoleGrants::changes()` decides whether a cell moved once, for the whole cell, not per line: the
  moment a person adds a rule, drops one, or edits a different column in a multi-line condition,
  `$wanted` is rebuilt in full from the browser's payload through `Narrowing::fromPayload()` →
  `Value::cast()`, and the untouched-but-mistyped line is re-cast right along with the one that
  changed. Same silent retyping this release exists to stop, reached through a different door: an
  edit instead of a bare flip. Pre-existing, not introduced here, and deferred — per-line
  conservation inside one cell is a design question this release does not answer.
- **A rule whose first line reads `or` still comes back as `and` on rewrite, and this release does
  not touch that.** It is a different, lighter hazard: `Group::passes()`
  (`vendor/elpandape/warden/src/Constraints/Group.php:34-35`) ignores the first item's own logic on
  every evaluation, so the normalisation never changes what the rule matches. What it does change
  is the twin's identity — the old row is left holding no grants and surfaces in
  `filament-warden:audit`'s informational bucket, the same bucket a turned-off cell already lands
  in. Not an authorization defect.
- **The relation manager for handing roles out from the account screen stays exactly where `1.3.0`
  deferred it, `v1.4.0`.** This is a patch, not a minor, so it did not take that tag's number and
  nothing behind it moves: the `Catalog`/`Holders` memo `1.3.0` also deferred stays at `v1.5.0`,
  inside "Que no cueste".
- **Two minors `1.3.0` left open are still open — checked, not assumed, and neither is closed
  here.** `conditions.locked.model` still has no test proving the sentence is ever actually shown
  on screen. `RoleGrants::writable()` still has no test for a string-typed tenant id, the same
  systemic gap `1.3.0` closed on `Assignment`'s side of the identical comparison.
- **Nobody opened a browser.** This release changes no visible surface — no new key, no new lock —
  so there was nothing new to see; the fix is verified end to end through Pest's database layer
  only. A stance flip on a string-valued condition, watched through a real browser, is pending, not
  seen.

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
