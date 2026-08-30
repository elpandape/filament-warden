// Verifies that a cell says what it just became, against the *real*
// @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends on
// "@vue/reactivity": "~3.5.40").
//
// Why this needs a gate at all
// ────────────────────────────
// A cell's accessible name already changes on its own: every `fw-sr` span in
// `box.blade.php` is bound with `x-text`, so Alpine rewrites the name in the
// click, before any trip to the server. What a changing name under a focus that
// never moved does NOT do is get re-read. The live region above the grid is what
// says it, and this drives the value that region reads.
//
// Two things can go wrong silently, and no PHP test can see either:
//
//   1. The announcement gets hung off `select()` instead of `write()`. `select()`
//      returns before doing anything when `grid.explain` and `grid.constraints`
//      are both off — a configuration where a click used to say nothing at all,
//      ever. A test that only renders the markup cannot tell the two apart.
//   2. The announcement gets taken off the STANCE rather than off `stateOf()`.
//      Those disagree exactly where it matters: a cell cleared under a wider
//      rule is drawn, and named, as "reached by a broader rule" — not as "no
//      rule". Saying the stance would put a second version of the same fact on
//      the same screen, which is the defect §6.24 exists to name.
//
// Run: cd verify && npm install && node verify-cell-announces.mjs

import { reactive } from '@vue/reactivity'
import factory from '../resources/js/permission-grid.js'

let failures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

const POST = 'App\\Models\\Post'

const STATES = {
    abstain: 'no rule',
    granted: 'granted',
    forbidden: 'forbidden',
    broader: 'reached by a broader rule',
    narrowed: 'narrowed',
    locked: 'not changeable here',
    undeclared: 'not declared',
}

function makeGrid({ wider = {}, explain = true, constraints = true } = {}) {
    const component = factory({
        state: { stances: {}, narrowing: {}, baseline: { stances: {}, narrowing: {} } },
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            rows: {
                [POST]: {
                    label: 'Posts',
                    model: POST,
                    actions: ['viewAny', 'view'],
                    read: ['viewAny', 'view'],
                    cells: [
                        { action: 'viewAny', name: 'viewAny' },
                        { action: 'view', name: 'view' },
                    ],
                },
            },
            tabs: [{ key: 'resources', rows: [POST] }],
            wider,
            states: STATES,
            filter: { count: ':matched of :total', empty: 'nothing' },
            summary: { ratio: ':granted of :total', forbidden: ':count forbidden' },
            explain,
            constraints,
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

console.log('=== 1. It starts empty, because a region that arrives full is not announced ===')

const one = makeGrid()

check('nothing is said before anything is clicked', one.said === '')

console.log('\n=== 2. A cycle says what the cell became, each step of the way ===')

one.cycle(POST, 'viewAny')
check('granted', one.said === STATES.granted)

one.cycle(POST, 'viewAny')
check('forbidden', one.said === STATES.forbidden)

one.cycle(POST, 'viewAny')
check('and back to no rule', one.said === STATES.abstain)

console.log('\n=== 3. Backwards too, which is the shift-click the legend documents ===')

const three = makeGrid()

three.cycle(POST, 'viewAny', true)
check('shift steps the other way and still says it', three.said === STATES.forbidden)

console.log('\n=== 4. A preset says what it made every cell it touched ===')

const four = makeGrid()

four.apply(POST, 'all')
check('all', four.said === STATES.granted)

four.apply(POST, 'clear')
check('clear', four.said === STATES.abstain)

console.log('\n=== 5. It says what the CELL says, not what was written ===')

// A wider rule already answers this cell. Written to abstain, the cell draws and
// names itself `broader` — so the region has to say that, or the screen carries
// two versions of one fact.
const five = makeGrid({ wider: { '*': 'granted' } })

five.write(POST, 'viewAny', 'abstain')

check(
    'a cell cleared under a wider rule says what it now answers AND that a wider rule answers it',
    five.said === `${STATES.granted} ${STATES.broader}`,
)
check(
    'and it is the cell\'s own three spans, joined the way the cell joins them',
    five.said === [
        five.stateOf(POST, 'viewAny', 'viewAny'),
        five.reachedMark(POST, 'viewAny', 'viewAny'),
        five.markOf(POST, 'viewAny'),
    ].filter(Boolean).join(' '),
)

console.log('\n=== 6. The configuration that used to be silent ===')

// `select()` returns before doing anything when both are off. An announcement
// hung off it would never fire here.
const six = makeGrid({ explain: false, constraints: false })

six.cycle(POST, 'view')

check('a click still says what it did', six.said === STATES.granted)

console.log('\n=== 7. The control: taking it off the stance is what this forbids ===')

// Without this block nothing above would fail for an implementation that read
// `grid.states[stance]` instead — every check but 5 would pass. This is the one
// that discriminates, and it shows the wrong answer the wrong shape gives.
const seven = makeGrid({ wider: { '*': 'granted' } })

seven.write(POST, 'viewAny', 'abstain')

check(
    'the stance-shaped answer would have said "no rule" and been wrong',
    seven.said !== STATES.abstain && STATES[seven.stanceOf(POST, 'viewAny')] === STATES.abstain,
)

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — a cell says what it became, in the words its own name uses.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
