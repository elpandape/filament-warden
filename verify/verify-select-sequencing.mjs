// Verifies the sequencing guard in resources/js/permission-grid.js's select()
// against the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2
// depends on "@vue/reactivity": "~3.5.40"; installed here: 3.5.41).
//
// Alpine's x-data component is wrapped with @vue/reactivity's reactive().
// Its `get` trap lazily wraps any object-valued property in a Proxy on every
// read. A Proxy is never === its raw target. That is the bug this script
// exists to prove is FIXED: comparing `this.selected !== asked`, where
// `asked` is the raw object assigned to `this.selected`, is *always* true
// once read back through the reactive proxy — not just on a real race. It
// shipped in this release's first cut of select(), was caught in review (not
// by any of the 660 Pest tests — those assert the guard's TEXT is present in
// the file, never that it behaves), and every cell click stuck on "Asking
// the store…" forever. See AGENTS.md §6.26 for the full account and §6.27
// for why no gate in this package could have caught it.
//
// ── What changed from the first cut of this script, and why ──────────────
// The first cut of this file did not import select() at all: it TRANSCRIBED
// a copy of it (`selectFixed`) into the script and ran assertions against the
// copy. That copy could drift from the real file silently — the two
// automated PHP tests that read permission-grid.js only grep for text — and,
// worse, the copy could never actually exercise a regression reintroduced
// into the real file. A gate that cannot fail protects nothing.
//
// This version imports the component's ACTUAL exported factory function —
// the same one verify-reach-of.mjs already drives — wraps it in a real
// reactive() proxy, stubs only `$wire.callSchemaComponentMethod` (which
// stands in for the server; that boundary is legitimate to stub, per the
// v1.1.0 verification brief — the server is not what this script tests),
// and calls the real `select()` method through it.
//
// ── What runs against the REAL select(), and what does not ───────────────
// Every check below (sections 1-5) calls `comp.select(...)` on a component
// built by the real, imported `grid()` factory — there is no hand-copied
// select() left in this file. Nothing in this script is a transcription of
// the method under test.
//
// The ONE thing this script cannot do is import the ORIGINAL BROKEN
// select() — it no longer exists anywhere in the repository; it was fixed
// before the fix was ever committed. So there is no "reproduce the shipped
// bug" section here that runs old code: that would require transcribing a
// version of select() that no longer exists, which is exactly the kind of
// copy this rewrite exists to stop doing. Proof that this script WOULD
// catch that regression if it reappeared is instead done the only honest
// way — externally, by hand, against the real file — and is not something
// a script can assert about itself:
//
//   1. Edit the real `if (this.asked !== token)` guard in select() in
//      resources/js/permission-grid.js to an object-identity comparison
//      (`if (this.selected !== asked)`, reintroducing the exact shipped
//      defect), matching it with an `asked` object assignment.
//   2. Run `make verify` and confirm it exits non-zero.
//   3. Restore the file with a NAMED `git checkout resources/js/permission-grid.js`.
//
// That sequence is recorded in the task report that accompanies this
// change, with both exit codes. It is not embedded here as an automated
// self-mutating test: this script drives the file as it stands on disk,
// which is the same contract verify-reach-of.mjs already keeps.
//
// ── How to run ──────────────────────────────────────────────────────────
//   cd verify
//   npm install
//   node verify-select-sequencing.mjs
//
// Requires Node + npm on the HOST. Part of `make verify`, which runs inside
// the `php` Docker service (Node was added to docker/Dockerfile for exactly
// this gate) and is wired into `make ci`. This script, and its sibling
// verify-reach-of.mjs, are the only executable evidence this package's JS
// has.
//
// This directory (verify/) lives at the repo root, on purpose, not under
// resources/js/ (that is a registered Filament asset — `php artisan
// filament:assets` copies it into every consuming application's public
// directory, and a verification script has no business shipping there) and
// not under tests/js/ (that would imply these are part of the Pest suite;
// they are not — Pest never runs them, `make verify` does). `/verify
// export-ignore` in .gitattributes keeps this whole directory out of the
// distributed package.

import { reactive } from '@vue/reactivity'
import gridComponent from '../resources/js/permission-grid.js'

// Same fixture shape as verify-reach-of.mjs's freshGrid(): the exported
// default dispatches on props.builder; grid() is the branch select() lives
// on. select() itself only reads grid.explain, grid.constraints and
// grid.key, but the rest is supplied anyway so the real factory function
// constructs the same object shape it does in the browser, with nothing
// missing that a later property read inside grid() (e.g. the initial `tab`
// assignment, which reads grid.tabs at construction time) could trip on.
function freshGrid(overrides = {}) {
    const props = {
        grid: {
            order: ['abstained', 'granted', 'forbidden'],
            manage: 'manage',
            wider: {},
            tabs: [{ key: 'role', rows: ['App\\Post'] }],
            rows: { 'App\\Post': { actions: ['update'], read: ['viewAny', 'view'], cells: [] } },
            explain: true,
            constraints: true,
            key: 'form.permissions',
            modes: {
                all: { name: 'Every row', hint: '' },
                owned: { name: 'Only what it owns', hint: '' },
                conditions: { name: 'With conditions', hint: '' },
            },
        },
        state: { stances: {}, narrowing: {} },
        interactive: true,
        ...overrides,
    }

    return reactive(gridComponent(props))
}

// Stands in for a `$wire` whose two calls both resolve immediately, with the
// given payloads — the server answering promptly and in order.
function wireImmediate(whyPayload, narrowingPayload) {
    return {
        async callSchemaComponentMethod(key, method) {
            return method === 'explainCell' ? whyPayload : narrowingPayload
        },
    }
}

// Stands in for a `$wire` whose calls never resolve on their own; the test
// resolves them itself, in whatever order it needs, to force a race. Indexed
// by call order (0 = first invocation of that method, 1 = second, …), not
// FIFO across methods — the whole point of the race check is resolving the
// SECOND select() call's pair before the FIRST's, which a plain queue cannot
// express.
function wireControllable() {
    const pending = { explainCell: [], narrowingFor: [] }

    return {
        wire: {
            callSchemaComponentMethod(key, method) {
                return new Promise((resolve) => pending[method].push(resolve))
            },
        },
        resolve(method, at, value) {
            const next = pending[method][at]

            if (next === undefined) {
                throw new Error(`no pending ${method} call at index ${at}`)
            }

            next(value)
        },
    }
}

let failures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

// ---------------------------------------------------------------------------
console.log('\n=== 1. Real select(): a single UNCONTESTED click lands ===')
{
    const comp = freshGrid()
    comp.$wire = wireImmediate(
        { verdict: 'abstain', cause: null, summary: 'This role has not been saved yet…' },
        { model: null },
    )

    await comp.select('App\\Role', 'viewAny', 'Role · viewAny', 'App\\Role')

    check('selected names the clicked cell', comp.selected.row === 'App\\Role' && comp.selected.action === 'viewAny')
    check('why lands', comp.why !== null && comp.why.summary === 'This role has not been saved yet…')
    check('narrowing lands', comp.narrowing !== null)
    check('loading resolves to false', comp.loading === false)
    check('failed stays false', comp.failed === false)
}

// ---------------------------------------------------------------------------
console.log('\n=== 2. Real select(): a superseded (stale) call is discarded, the later one wins ===')
{
    const comp = freshGrid()
    const { wire, resolve } = wireControllable()
    comp.$wire = wire

    const first = comp.select('App\\Role', 'viewAny', 'Cell A', 'App\\Role')
    // The click on B happens before A's server round trip returns — the exact
    // race the token guard (this.asked !== token) exists for. Both calls run
    // against the SAME comp.$wire and the SAME comp.asked counter that the
    // real select() maintains — nothing about the race is simulated here,
    // only the server's two promises are held open on purpose.
    const second = comp.select('App\\Role', 'create', 'Cell B', 'App\\Role')

    // B's answer arrives first (index 1 = the second invocation)…
    resolve('explainCell', 1, { verdict: 'granted', cause: 'granted-directly', summary: 'B answer' })
    resolve('narrowingFor', 1, { model: null, marker: 'B' })
    await second

    // …then A's slower answer arrives after B has already landed (index 0 = the first invocation).
    resolve('explainCell', 0, { verdict: 'abstain', cause: null, summary: 'A answer (must not land)' })
    resolve('narrowingFor', 0, { model: null, marker: 'A' })
    await first

    check('the title on screen is B’s', comp.selected.title === 'Cell B')
    check('the verdict box is B’s, not A’s late reply', comp.why.summary === 'B answer')
    check('the spinner stopped exactly once (false)', comp.loading === false)
    check('no failure was recorded', comp.failed === false)
}

// ---------------------------------------------------------------------------
console.log('\n=== 3. Real select(): a rejected call sets failed, on an uncontested click ===')
{
    const comp = freshGrid()
    comp.$wire = {
        async callSchemaComponentMethod() {
            throw new Error('offline')
        },
    }

    await comp.select('App\\Role', 'viewAny', 'Cell A', 'App\\Role')

    check('failed becomes true', comp.failed === true)
    check('loading resolves to false', comp.loading === false)
    check('why stays null — no sentence exists for it to paint', comp.why === null)
}

// ---------------------------------------------------------------------------
console.log('\n=== 4. Real select(): a superseded call that later rejects does not paint failure onto its successor ===')
{
    const comp = freshGrid()
    let rejectA

    // A's promises never resolve until rejectA() is called by hand, below —
    // after B has already landed. Both of A's two callSchemaComponentMethod
    // invocations share this one wire, so rejectA ends up bound to the
    // LAST one constructed (narrowingFor); rejecting it is enough, because
    // Promise.all rejects as soon as any one of its promises rejects.
    const wireA = {
        callSchemaComponentMethod() {
            return new Promise((_resolve, reject) => { rejectA = reject })
        },
    }
    const wireB = wireImmediate({ verdict: 'granted', cause: 'granted-directly', summary: 'B answer' }, { model: null })

    // comp.$wire is swapped between the two calls, standing in for the one
    // real $wire a live component has — select() reads `this.$wire` only in
    // the synchronous span before its first await, so each call captures the
    // wire that was current at the moment IT started, exactly as it would if
    // two different requests were in flight.
    comp.$wire = wireA
    const first = comp.select('App\\Role', 'viewAny', 'Cell A', 'App\\Role')

    comp.$wire = wireB
    const second = comp.select('App\\Role', 'create', 'Cell B', 'App\\Role')

    await second
    rejectA(new Error('offline, but only for the superseded call'))
    await first

    check('B’s answer still stands', comp.why.summary === 'B answer')
    check('failed was NOT set by A’s late rejection', comp.failed === false)
    check('loading stayed resolved', comp.loading === false)
}

// ---------------------------------------------------------------------------
console.log('\n=== 5. Real select(): the inspector-off guard skips the round trip entirely ===')
{
    const comp = freshGrid({
        grid: {
            order: ['abstained', 'granted', 'forbidden'],
            manage: 'manage',
            wider: {},
            tabs: [{ key: 'role', rows: ['App\\Post'] }],
            rows: { 'App\\Post': { actions: ['update'], read: ['viewAny', 'view'], cells: [] } },
            explain: false,
            constraints: false,
            key: 'form.permissions',
            modes: {},
        },
    })
    let called = false
    comp.$wire = {
        async callSchemaComponentMethod() {
            called = true

            return null
        },
    }

    await comp.select('App\\Role', 'viewAny', 'Cell A', 'App\\Role')

    check('the server is never asked when neither half of the inspector is on the page', called === false)
    check('selected stays untouched', comp.selected === null)
    check('loading stays false', comp.loading === false)
}

console.log(failures === 0
    ? '\nALL CHECKS PASSED — select(), run through the real grid() factory under a real @vue/reactivity proxy, lands an uncontested call, discards a superseded one, and scopes its failure path correctly.'
    : `\n${failures} CHECK(S) FAILED`)
process.exit(failures === 0 ? 0 : 1)
