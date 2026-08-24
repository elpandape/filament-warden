// Verifies the reach rail's keyboard half in resources/js/permission-grid.js
// against the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2
// depends on "@vue/reactivity": "~3.5.40").
//
// The rail is a radiogroup: one tab stop, and the arrows walk it. Everything
// that makes that true lives in three methods this script drives directly, on
// the component's ACTUAL exported factory rather than a re-implementation of
// it — the same discipline as the sibling scripts, and for the same reason:
// reading an object-valued property off `this` hands back a fresh Proxy every
// time, so a mistake that only shows up through the reactive wrapper is
// invisible to a hand-rolled stand-in.
//
// ── What it proves ──────────────────────────────────────────────────────
// 1. reachEnabled() answers all three the way the markup binds `disabled`:
//    a non-interactive grid offers none, a locked cell offers none, and
//    `owned` alone is refused when the model has no ownership to resolve.
// 2. stepReach() STEPS OVER a disabled option instead of landing on it. An
//    arrow that moves somewhere and does nothing reads as a broken keyboard,
//    and it is the failure a naive `(at + step) % 3` produces every time.
// 3. stepReach() wraps in both directions, and never stops on the option the
//    server refused.
// 4. With every option disabled, stepReach() returns without writing. The page
//    cannot reach that call — a group with three disabled buttons has nothing
//    to focus, so no arrow keydown of ours fires on it — so this checks the
//    method, not a defect the markup can produce: without the guard an empty
//    list indexes to undefined and throws.
// 5. reachStop() puts the group's single tab stop on the chosen option, and
//    moves it to the first reachable one when the store holds a reach this
//    screen does not offer — so a radiogroup is never a region with no way in.
// 6. The three agree with each other: whatever reachStop() names is something
//    reachEnabled() allows, and whatever stepReach() lands on is too. Three
//    methods deriving the same predicate separately is how they drift.
//
// ── How to run ──────────────────────────────────────────────────────────
//   cd verify
//   npm install
//   node verify-reach-keyboard.mjs
//
// It IS part of `make ci`, as the seventh gate: the Docker image installs
// nodejs and npm, and `make verify` runs this script and its three siblings.
// They are the only executable evidence this package's JS behaves rather than
// merely reading a certain way — a PHP test can assert that a guard is
// WRITTEN, never that it runs. `/verify export-ignore` in .gitattributes keeps
// this whole directory out of the distributed package.

import { reactive } from '@vue/reactivity'
import gridComponent from '../resources/js/permission-grid.js'

function freshGrid(overrides = {}) {
    const props = {
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            wider: {},
            tabs: [{ key: 'role', rows: ['App\\Post'] }],
            rows: { 'App\\Post': { actions: ['update'], read: ['viewAny'], cells: [] } },
            explain: true,
            constraints: true,
            key: 'form.permissions',
            modes: {
                all: { name: 'Every row', hint: 'a' },
                owned: { name: 'Only what it owns', hint: 'b' },
                conditions: { name: 'With conditions', hint: 'c' },
            },
        },
        state: { stances: {}, narrowing: {} },
        interactive: true,
        ...overrides,
    }

    return reactive(gridComponent(props))
}

/**
 * A cell with a reach, as `select()` leaves it once the server answers.
 */
function select(comp, { locked = false, mode = 'all', ownership = true, note = null } = {}) {
    comp.selected = { row: 'App\\Post', action: 'update', title: 'Post · update', subtitle: 'App\\Post' }
    comp.narrowing = {
        model: 'App\\Post',
        columns: ['title'],
        authority: ['id'],
        ownership: { available: ownership, reason: ownership ? null : 'The table posts has no column owner_id.' },
        stored: { mode, preview: '', locked, note },
    }
}

/**
 * The rail, as the browser has it: three buttons whose `disabled` and
 * `aria-checked` are what the markup binds them to. `stepReach()` reads the DOM
 * for the same reason `stepTab()` does — a keydown on the group needs a focused
 * element inside it, and the only elements inside it are these.
 */
function rail(comp) {
    const buttons = ['all', 'owned', 'conditions'].map((mode) => ({
        dataset: { fwMode: mode },
        disabled: ! comp.reachEnabled(mode),
        focused: false,
        getAttribute: (name) => (name === 'aria-checked' ? (comp.reachOf() === mode ? 'true' : 'false') : null),
        focus() {
            this.focused = true
        },
    }))

    return {
        buttons,
        querySelectorAll: () => buttons,
        focusedMode: () => buttons.find((one) => one.focused)?.dataset.fwMode ?? null,
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
console.log('\n=== 1. reachEnabled() answers the three the way the markup binds `disabled` ===')
{
    const comp = freshGrid()
    select(comp)

    check('all three are open on an editable cell whose model resolves ownership',
        comp.reachEnabled('all') && comp.reachEnabled('owned') && comp.reachEnabled('conditions'))

    select(comp, { ownership: false })
    check('only `owned` closes when the model has no ownership to resolve',
        comp.reachEnabled('all') && ! comp.reachEnabled('owned') && comp.reachEnabled('conditions'))

    select(comp, { locked: true, mode: 'tangled', note: 'more than one rule' })
    check('a locked cell closes all three — the store holds a reach this screen cannot draw',
        ! comp.reachEnabled('all') && ! comp.reachEnabled('owned') && ! comp.reachEnabled('conditions'))

    const readOnly = freshGrid({ interactive: false })
    select(readOnly)
    check('a grid that does not write closes all three as well',
        ! readOnly.reachEnabled('all') && ! readOnly.reachEnabled('owned') && ! readOnly.reachEnabled('conditions'))
}

// ---------------------------------------------------------------------------
console.log('\n=== 2. stepReach() steps OVER a disabled option instead of landing on it ===')
{
    const comp = freshGrid()
    select(comp, { ownership: false })

    check('starts on "all"', comp.reachOf() === 'all')

    // The naive `(at + step) % 3` lands on `owned` here and writes nothing,
    // which reads as a key that does not work.
    comp.stepReach(rail(comp), 1)
    check('one step forward skips the refused `owned` and lands on `conditions`', comp.reachOf() === 'conditions')

    comp.stepReach(rail(comp), 1)
    check('another wraps back to `all`, still never stopping on `owned`', comp.reachOf() === 'all')

    comp.stepReach(rail(comp), -1)
    check('backwards skips it too, landing on `conditions`', comp.reachOf() === 'conditions')
}

// ---------------------------------------------------------------------------
console.log('\n=== 3. It walks all three when all three are open, and wraps ===')
{
    const comp = freshGrid()
    select(comp)

    comp.stepReach(rail(comp), 1)
    check('all → owned', comp.reachOf() === 'owned')

    comp.stepReach(rail(comp), 1)
    check('owned → conditions', comp.reachOf() === 'conditions')

    comp.stepReach(rail(comp), 1)
    check('conditions wraps to all', comp.reachOf() === 'all')

    comp.stepReach(rail(comp), -1)
    check('and backwards from all wraps to conditions', comp.reachOf() === 'conditions')
}

// ---------------------------------------------------------------------------
console.log('\n=== 4. With every option disabled it writes nothing — a state the page cannot produce ===')
{
    const comp = freshGrid()
    select(comp, { locked: true, mode: 'elsewhere', note: 'this grant belongs to another tenant' })

    const before = comp.reachOf()
    comp.stepReach(rail(comp), 1)

    check('the reach is untouched — a locked cell is not rewritten by an arrow key', comp.reachOf() === before)
    check('and it is still the word the store holds, not one of the three', comp.reachOf() === 'elsewhere')
}

// ---------------------------------------------------------------------------
console.log('\n=== 5. reachStop() always names a way into the group ===')
{
    const comp = freshGrid()
    select(comp)
    check('the chosen option holds the tab stop', comp.reachStop() === comp.reachOf())

    comp.setMode('conditions')
    check('and it follows the choice', comp.reachStop() === 'conditions')

    // The store holds a reach the rail does not offer, so nothing is checked —
    // without a fallback the group would have three `tabindex="-1"` buttons and
    // no way in at all.
    select(comp, { locked: false, mode: 'all', ownership: false })
    comp.narrowing.stored.mode = 'unreadable'
    comp.narrow('App\\Post', 'update', { mode: 'unreadable', rules: [] })

    check('when nothing is checked the stop falls to the first reachable option',
        comp.reachStop() === 'all')

    select(comp, { locked: true, mode: 'tangled', note: 'x' })
    check('and with every option closed it names none, rather than one that cannot be focused',
        comp.reachStop() === null)
}

// ---------------------------------------------------------------------------
console.log('\n=== 6. The three agree — one predicate, read three ways ===')
{
    const comp = freshGrid()

    for (const ownership of [true, false]) {
        select(comp, { ownership })

        const stop = comp.reachStop()
        check(`reachStop() names something reachEnabled() allows (ownership: ${ownership})`,
            stop !== null && comp.reachEnabled(stop))

        for (let press = 0; press < 4; press++) {
            comp.stepReach(rail(comp), 1)

            if (! comp.reachEnabled(comp.reachOf())) {
                check(`stepReach() landed on a refused option after ${press + 1} press(es)`, false)
                break
            }
        }

        check(`four presses never landed anywhere reachEnabled() refuses (ownership: ${ownership})`,
            comp.reachEnabled(comp.reachOf()))
    }
}

console.log(failures === 0
    ? '\nALL CHECKS PASSED — the reach rail, run through the real grid() factory under a real @vue/reactivity proxy, offers exactly what it may, steps over what it may not, and never leaves the group without a way in.'
    : `\n${failures} CHECK(S) FAILED`)
process.exit(failures === 0 ? 0 : 1)
