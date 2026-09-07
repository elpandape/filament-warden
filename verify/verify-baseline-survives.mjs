// Verifies that the grid's own state writes carry a key they know nothing about,
// against the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends
// on "@vue/reactivity": "~3.5.40").
//
// Why this needs a gate at all
// ────────────────────────────
// From v1.6.0 the field stamps a `baseline` key into its state: what the store
// held when the screen opened. The save compares three things — baseline,
// payload, store — so that a cell this person never touched is left as whoever
// did touch it set it, instead of being quietly moved back.
//
// The browser must return that key untouched. It does, and only because both
// state writes are spreads:
//
//     this.state = { ...this.state, stances:   … }   // write()
//     this.state = { ...this.state, narrowing: … }   // the builder
//
// Rewrite either as `{ stances, narrowing }` — which reads like tidying up, since
// those are the only two keys the script itself knows — and `baseline` is gone
// from the first click onward. The save then sees no baseline, falls back to
// treating every cell as touched, and the defect v1.6.0 exists to close is back,
// silently: no error, no failing PHP test, nothing on screen. The PHP side cannot
// see it either, because by the time the payload arrives the key simply is not
// there, which is indistinguishable from a screen that never stamped one.
//
// So this drives the component's ACTUAL exported factory through a real
// reactive() wrapper and asserts the key survives every write the grid makes.
//
// Run: cd verify && npm install && node verify-baseline-survives.mjs

import { reactive } from '@vue/reactivity'
import factory from '../resources/js/permission-grid.js'

let failures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

const BASELINE = {
    stances: { 'App\\Models\\Post': { viewAny: 'granted' } },
    narrowing: {},
    // 3.0.0 put two more maps in the envelope, and they ride the same spread
    // this file exists to protect. Carried here so a rewrite that drops one is
    // caught by the same block rather than by a second script saying the same
    // thing twice.
    until: {},
    inherited: {},
}

function makeGrid() {
    const component = factory({
        state: {
            stances: { 'App\\Models\\Post': { viewAny: 'granted' } },
            narrowing: {},
            until: {},
            inherited: {},
            baseline: BASELINE,
        },
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            // `alpine()` always sends this map; the fixture went without it until
            // a cell learned to say what it became, which reads it.
            rows: {
                'App\\Models\\Post': {
                    label: 'Posts',
                    model: 'App\\Models\\Post',
                    actions: ['viewAny'],
                    read: ['viewAny'],
                    cells: [{ action: 'viewAny', name: 'viewAny' }],
                },
            },
            tabs: [{ key: 'resources', rows: ['App\\Models\\Post'] }],
            wider: {},
            states: { narrowed: 'fw-narrowed', abstain: 'no rule', granted: 'granted', forbidden: 'forbidden', broader: '' },
            explain: false,
            constraints: false,
            words: {},
            operators: [],
            columns: {},
            reach: {},
            matrix: {},
        },
        interactive: true,
    })

    return reactive(component)
}

console.log('=== 1. A stance write carries the baseline through ===')

const one = makeGrid()

one.write('App\\Models\\Post', 'viewAny', 'forbidden')

check('the stance actually changed', one.state.stances['App\\Models\\Post'].viewAny === 'forbidden')
check('baseline is still there', one.state.baseline !== undefined)
check(
    'and it still says what the store said when the screen opened',
    one.state.baseline?.stances?.['App\\Models\\Post']?.viewAny === 'granted',
)

console.log('\n=== 2. Clearing a cell carries it too — the delete branch ===')

const two = makeGrid()

two.write('App\\Models\\Post', 'viewAny', 'abstain')

check('the cell was cleared', two.state.stances['App\\Models\\Post']?.viewAny === undefined)
check('baseline survived the delete branch as well', two.state.baseline?.stances?.['App\\Models\\Post']?.viewAny === 'granted')

console.log('\n=== 3. Many writes in a row do not wear it away ===')

const three = makeGrid()

for (const stance of ['forbidden', 'granted', 'abstain', 'granted']) {
    three.write('App\\Models\\Post', 'viewAny', stance)
}

check('baseline is intact after four writes', three.state.baseline?.stances?.['App\\Models\\Post']?.viewAny === 'granted')

console.log('\n=== 4. The OTHER write: narrow(), which every condition edit goes through ===')

// The stance write and the reach write are two separate spreads on two separate
// lines. A gate that drove only the first would have said "either one" while
// covering one of them — and the reach write is the one a person reaches by
// editing a condition rather than by clicking a cell.
const five = makeGrid()

five.narrow('App\\Models\\Post', 'viewAny', {
    mode: 'conditions',
    rules: [{ logic: 'and', column: 'published', operator: '=', value: 'true' }],
})

check('the reach actually changed', five.state.narrowing?.['App\\Models\\Post']?.viewAny?.mode === 'conditions')
check('baseline survived the reach write too', five.state.baseline?.stances?.['App\\Models\\Post']?.viewAny === 'granted')

console.log('\n=== 5. And clearing a reach — narrow()\'s own delete branch ===')

const six = makeGrid()

six.narrow('App\\Models\\Post', 'viewAny', { mode: 'all', rules: [] })

check('baseline survived the reach delete branch', six.state.baseline?.stances?.['App\\Models\\Post']?.viewAny === 'granted')

console.log('\n=== 6. The control: a non-spread write is what would lose it ===')

const four = makeGrid()

// Exactly the tidy-up this script exists to catch, applied by hand.
four.state = { stances: four.state.stances, narrowing: four.state.narrowing }

check(
    'rebuilding state from its two known keys DOES drop the baseline — so the check above discriminates',
    four.state.baseline === undefined,
)


console.log('\n=== The two maps 3.0.0 added ride the same spread ===')

const withMaps = makeGrid()

withMaps.write('App\\Models\\Post', 'viewAny', 'forbidden')

check('`until` survived a stance write', withMaps.state.until !== undefined)
check('and so did `inherited`', withMaps.state.inherited !== undefined)

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — the grid returns the baseline it was handed, through a real reactive proxy.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
