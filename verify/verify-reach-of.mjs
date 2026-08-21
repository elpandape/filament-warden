// Verifies reachOf() in resources/js/permission-grid.js against the *real*
// @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends on
// "@vue/reactivity": "~3.5.40"; installed here via `npm view`: 3.5.41).
//
// This is the same class of check the sibling script (verify-select-sequencing.mjs)
// runs for the sequencing guard in select(): drive the component's ACTUAL exported
// factory function through a real reactive() wrapper, not a hand-reimplementation
// of its logic, so a Proxy-identity mistake in reachOf() itself would be caught
// the same way the select() one was — reading an object-valued property off `this`
// hands back a fresh Proxy on every access, never === anything held separately.
//
// reachOf() takes no such object-identity shortcut (`this.narrowing.stored.locked
// ? this.narrowing.stored.mode : this.modeOf()` compares only primitives —
// a boolean and, transitively through modeOf(), string mode values), so this
// script is not chasing a reported bug the way the sequencing one was. It exists
// to discharge the v1.1.0 brief's invitation honestly: prove the branch RUNS,
// under the real reactive wrapper, for both arms — locked and editable —
// including a live click changing modeOf() while narrowing.stored stays fixed.
//
// ── What it proves ──────────────────────────────────────────────────────
// 1. A locked cell's reachOf() reads the STORE's word, ignoring any pending
//    click state — the exact fix for the "a locked cell was highlighting
//    Every row" defect this release exists to close.
// 2. Every locked reach the server can send (unreadable, tangled, elsewhere)
//    round-trips through reachOf() unchanged and lights none of the three
//    editable buttons.
// 3. An editable cell's reachOf() follows modeOf() live, across a click.
// 4. The locked/editable branch is decided by narrowing.stored.locked alone,
//    never by the pending mode — stored and pending are made to disagree on
//    purpose, and the store wins.
// 5. reachOf() has NO guard of its own: with narrowing === null it throws.
//    offered() (the sequencing script's sibling guard) is what keeps the
//    template from ever calling it in that state — proven load-bearing, not
//    decorative, by calling it anyway.
//
// ── How to run ──────────────────────────────────────────────────────────
//   cd verify
//   npm install
//   node verify-reach-of.mjs
//
// Requires Node + npm on the HOST. Not part of `make ci` — the Docker image
// behind it (php:8.5-cli-alpine) has neither Node nor npm, and this
// package's JS has no gate at all (AGENTS.md §6.27, §7). This script, and
// its sibling verify-select-sequencing.mjs, are the only executable
// evidence this package's JS has; they are run by hand, not on every change.
//
// This directory (verify/) lives at the repo root, on purpose, not under
// resources/js/ (that is a registered Filament asset — `php artisan
// filament:assets` copies it into every consuming application's public
// directory, and a verification script has no business shipping there) and
// not under tests/js/ (that would imply these are part of the Pest suite;
// they are not — `make ci` never runs them). `/verify export-ignore` in
// .gitattributes keeps this whole directory out of the distributed package.

import { reactive } from '@vue/reactivity'
import gridComponent from '../resources/js/permission-grid.js'

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

    // The exported default dispatches on props.builder; grid() is the branch
    // this task's reachOf() lives on (§ "It goes on grid() and not on the
    // shared conditions object" in the brief).
    return reactive(gridComponent(props))
}

let failures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

// ---------------------------------------------------------------------------
console.log('\n=== 1. A locked cell: reachOf() reads the STORE\'s word, ignoring the pending click state ===')
{
    const comp = freshGrid()

    comp.selected = { row: 'App\\Post', action: 'update', title: 'Post · update', subtitle: 'App\\Post' }
    // Pending state says "conditions" — if reachOf() ever fell back to
    // modeOf() while locked, this is exactly the wrong answer it would give.
    comp.narrow('App\\Post', 'update', { mode: 'conditions', rules: [{ logic: 'and', kind: 'value', column: 'title', operator: '=', value: 'alpha', authority: '' }] })
    comp.narrowing = { model: 'App\\Post', columns: ['title'], authority: [], ownership: { available: false }, stored: { mode: 'unreadable', preview: 'title = alpha', locked: true, note: 'This role has not been saved yet…' } }

    check('modeOf() itself still reads the pending "conditions"', comp.modeOf() === 'conditions')
    check('reachOf() reads the STORE\'s word instead', comp.reachOf() === 'unreadable')
    check('"unreadable" is not one of the three buttons offered', ! ['all', 'owned', 'conditions'].includes(comp.reachOf()))
}

// ---------------------------------------------------------------------------
console.log('\n=== 2. Every locked reach the server can send lights none of the three buttons ===')
{
    for (const mode of ['unreadable', 'tangled', 'elsewhere']) {
        const comp = freshGrid()
        comp.selected = { row: 'App\\Post', action: 'update', title: 'x', subtitle: 'App\\Post' }
        comp.narrowing = { model: 'App\\Post', columns: [], authority: [], ownership: { available: false }, stored: { mode, preview: '', locked: true, note: 'locked' } }

        check(`locked "${mode}" round-trips through reachOf() unchanged`, comp.reachOf() === mode)
    }
}

// ---------------------------------------------------------------------------
console.log('\n=== 3. An editable cell: reachOf() follows modeOf(), live, across a click ===')
{
    const comp = freshGrid()

    comp.selected = { row: 'App\\Post', action: 'update', title: 'x', subtitle: 'App\\Post' }
    comp.narrowing = { model: 'App\\Post', columns: ['title'], authority: [], ownership: { available: false }, stored: { mode: 'all', preview: '', locked: false, note: null } }

    check('starts on "all" — nothing written yet', comp.reachOf() === 'all')

    // setMode() is the real click handler the <button x-on:click> fires.
    comp.setMode('owned')
    check('reachOf() follows the click to "owned"', comp.reachOf() === 'owned')
    check('and agrees with modeOf(), which is what an editable cell must bind to', comp.reachOf() === comp.modeOf())

    comp.setMode('conditions')
    check('a second click moves it again, to "conditions"', comp.reachOf() === 'conditions')
}

// ---------------------------------------------------------------------------
console.log('\n=== 4. The locked/editable branch is decided by narrowing.stored.locked alone, not by mode ===')
{
    const comp = freshGrid()
    comp.selected = { row: 'App\\Post', action: 'update', title: 'x', subtitle: 'App\\Post' }

    // Pending state and stored state DISAGREE on the word, and stored.locked
    // is what has to win — this is the exact scenario the headline bug this
    // release fixes shipped without: RoleState::toPayload() answering
    // {mode:'all'} for a shape the browser was never told was locked.
    comp.narrow('App\\Post', 'update', { mode: 'all', rules: [] })
    comp.narrowing = { model: 'App\\Post', columns: [], authority: [], ownership: { available: false }, stored: { mode: 'tangled', preview: '', locked: true, note: 'tangled' } }

    check('modeOf() (pending) says "all"', comp.modeOf() === 'all')
    check('reachOf() says "tangled" — the store wins over the pending click', comp.reachOf() === 'tangled')
}

// ---------------------------------------------------------------------------
console.log('\n=== 5. The dependency on offered(): reachOf() has NO guard of its own ===')
{
    const comp = freshGrid()
    comp.selected = { row: 'App\\Post', action: 'update', title: 'x', subtitle: 'App\\Post' }
    comp.narrowing = null // exactly what select() sets it to before the round trip returns

    check('offered() correctly refuses — the <template x-if> never reaches reachOf()', comp.offered() === false)

    let threw = false
    try {
        comp.reachOf()
    } catch {
        threw = true
    }

    check('reachOf(), called anyway (as the blade never does), throws — proving offered() is load-bearing, not decorative', threw)
}

console.log(failures === 0
    ? '\nALL CHECKS PASSED — reachOf(), run through the real grid() factory under a real @vue/reactivity proxy, reads the store\'s word while locked and the pending click while editable.'
    : `\n${failures} CHECK(S) FAILED`)
process.exit(failures === 0 ? 0 : 1)
