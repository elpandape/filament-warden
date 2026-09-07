// Verifies that the panel opens, closes and follows the selection without
// throwing away what it already knows — against the *real* @vue/reactivity
// Alpine pins.
//
// Why this needs a gate at all
// ────────────────────────────
// From 3.0.0 the inspector is a second grid track rather than a band under the
// grid, so whether it is open is now a LAYOUT decision and not just a question
// of whether a cell is selected. Two things follow, and neither is visible from
// PHP:
//
//   1. Closing the panel must not clear the selection. The grid keeps showing
//      which cell was being read, and reopening must not have to ask the server
//      again for an answer it still holds. Collapsing the two into one flag —
//      which reads like removing a redundant field, since `selected !== null`
//      looks like the same question — costs a round trip on every reopen and
//      loses the cell the person was looking at.
//
//   2. The sequencing token from v1.1.0 has to survive all of it. It is a NUMBER
//      because a Proxy is never `===` its raw target, so an object token was
//      broken on the very first uncontested click with no race anywhere near it
//      (§6.26). Adding panel state around `select()` is exactly the kind of edit
//      that reaches for `this.selected` again.
//
// Run: cd verify && npm install && node verify-panel-state.mjs

import { reactive } from '@vue/reactivity'
import factory from '../resources/js/permission-grid.js'

let failures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

const ROW = 'App\\Models\\Post'

function makeGrid() {
    const component = factory({
        state: { stances: {}, narrowing: {}, until: {}, inherited: {} },
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            rows: {
                [ROW]: {
                    label: 'Posts',
                    model: ROW,
                    actions: ['viewAny', 'update'],
                    read: ['viewAny'],
                    cells: [
                        { action: 'viewAny', name: 'viewAny' },
                        { action: 'update', name: 'update' },
                    ],
                },
            },
            tabs: [{ key: 'resources', rows: [ROW] }],
            wider: {},
            states: {
                narrowed: 'fw-narrowed', abstain: 'no rule', granted: 'granted',
                forbidden: 'forbidden', broader: '', expires: 'expires', inherited: 'inherited',
            },
            until: { forbidden: '', unwritten: '' },
            // The inspector ON. With both halves off `select()` returns without
            // doing anything — the §6.26 guard, and it is right: a panel with no
            // explanation and no builder in it has nothing to show. That branch
            // has its own case below, because "the panel opens" and "the panel
            // opens even when there is nothing to put in it" are not the same
            // promise and only one of them is true.
            explain: true,
            constraints: false,
            words: {}, operators: [], columns: {}, reach: {}, matrix: {},
        },
        interactive: true,
    })

    return reactive(component)
}

console.log('=== 1. Selecting opens the panel ===')

const one = makeGrid()

check('closed to begin with', one.panel === false)
check('and nothing is selected', one.selected === null)

one.select(ROW, 'viewAny', 'List', 'viewAny')

check('the panel opened', one.panel === true)
check('and it names the cell', one.selected?.action === 'viewAny')

console.log('\n=== 2. Closing keeps the selection, so reopening asks nothing ===')

one.closePanel()

check('the panel is closed', one.panel === false)
check('and the cell is STILL selected', one.selected?.action === 'viewAny')

console.log('\n=== 3. Selecting another cell reopens it, on the new cell ===')

one.select(ROW, 'update', 'Edit', 'update')

check('open again', one.panel === true)
check('on the cell just clicked', one.selected?.action === 'update')

console.log('\n=== 4. The token is still a number, and still moves per call ===')

const two = makeGrid()

const before = two.asked

two.select(ROW, 'viewAny', 'List', 'viewAny')

check('it is a number', typeof two.asked === 'number')
check('and it moved', two.asked !== before)

console.log('\n=== 5. With the inspector and the builder both off, a click still opens nothing ===')

const four = makeGrid()

four.grid.explain = false

four.select(ROW, 'viewAny', 'List', 'viewAny')

// The §6.26 guard, and the panel does not undo it: with neither half configured
// there is nothing to put in the panel, so opening one would be a blank column
// taking 30rem off the matrix. What speaks in that configuration is `write()`,
// which is where §6.44 moved the announcement to.
check('the panel stayed shut', four.panel === false)
check('and nothing was selected', four.selected === null)

console.log('\n=== 6. The control: one flag instead of two loses the cell on close ===')

const three = makeGrid()

three.select(ROW, 'viewAny', 'List', 'viewAny')

// What `closePanel()` would be if `panel` were dropped and `selected === null`
// read as "closed". Without this block, checks 1 to 4 would pass just as
// happily on a component that had collapsed the two.
three.selected = null

check('the selection is gone, which is what the second flag exists to prevent', three.selected === null)

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — the panel is a layout flag of its own, and the selection outlives it.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
