// Verifies that the entity filter never touches grant state, and that the one
// sentence this script composes composes correctly — both against the *real*
// @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends on
// "@vue/reactivity": "~3.5.40").
//
// Why this needs a gate at all
// ────────────────────────────
// `RoleGrants::plan()` walks the CATALOGUE, not the payload. So a filter that
// pruned `state.stances` down to the rows it is showing would send a payload
// with an entity missing, and every one of that entity's cells would arrive as
// an abstention against a baseline that still has them — written as a
// deliberate revoke, with a success notification on top.
//
// It cannot be defended against on the server either: `write()` DELETES a cell's
// key when it is cleared, so "this person cleared it" and "the payload never
// mentioned it" are byte for byte the same payload. Two tests in
// `tests/Grants/RoleGrantsTest.php` pin exactly what that costs.
//
// Which leaves one place the guarantee can live: the filter must be a predicate
// over `grid`, which PHP filled and nothing writes, and must never assign into
// `state`. That is what this drives — on the actual exported factory, with a
// control block that writes through the filtered-away row to prove the check
// discriminates.
//
// The second half drives `stackSummary()`, the one place this script puts a
// computed number inside a translated line. Every other string it produces is a
// whole word looked up in a map PHP filled, so this helper is the only one that
// can get a substitution wrong.
//
// Run: cd verify && npm install && node verify-filter-keeps-state.mjs

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
const TAG = 'App\\Models\\Tag'

function makeGrid() {
    const component = factory({
        state: {
            stances: {
                [POST]: { viewAny: 'granted', view: 'forbidden' },
                [TAG]: { viewAny: 'granted' },
            },
            narrowing: { [POST]: { viewAny: { mode: 'conditions', rules: [] } } },
            baseline: { stances: {}, narrowing: {} },
        },
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            rows: {
                [POST]: {
                    label: 'Posts',
                    model: POST,
                    actions: ['viewAny', 'view'],
                    read: ['viewAny'],
                    cells: [
                        { action: 'viewAny', name: 'viewAny' },
                        { action: 'view', name: 'view' },
                    ],
                },
                [TAG]: {
                    label: 'Tags',
                    model: TAG,
                    actions: ['viewAny'],
                    read: ['viewAny'],
                    cells: [{ action: 'viewAny', name: 'viewAny' }],
                },
            },
            tabs: [{ key: 'resources', rows: [POST, TAG] }],
            wider: {},
            states: { narrowed: 'fw-narrowed' },
            filter: { count: ':matched of :total', empty: 'Nothing matches ":term".' },
            summary: { ratio: ':granted of :total', forbidden: ':count forbidden' },
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

console.log('=== 1. A filter decides what is drawn, and nothing else ===')

const one = makeGrid()

one.filter = 'tag'

check('the row that matches is shown', one.shown(TAG) === true)
check('the row that does not is hidden', one.shown(POST) === false)
check('the count is what matched', one.matched('resources') === 1)
check('and it reads as the line PHP sent', one.filterCount('resources') === '1 of 2')

check(
    'the hidden row keeps every stance it had',
    one.state.stances[POST]?.viewAny === 'granted' && one.state.stances[POST]?.view === 'forbidden',
)
check('and keeps its reach too', one.state.narrowing[POST]?.viewAny?.mode === 'conditions')

console.log('\n=== 2. Writing to a VISIBLE row still leaves the hidden one alone ===')

const two = makeGrid()

two.filter = 'tag'
two.write(TAG, 'viewAny', 'forbidden')

check('the visible row moved', two.state.stances[TAG].viewAny === 'forbidden')
check(
    'the filtered-away row is untouched',
    two.state.stances[POST]?.viewAny === 'granted' && two.state.stances[POST]?.view === 'forbidden',
)
check('and its reach is untouched', two.state.narrowing[POST]?.viewAny?.mode === 'conditions')

console.log('\n=== 3. A term nobody matches empties the reading, not the state ===')

const three = makeGrid()

three.filter = 'zzzz'

check('nothing matches', three.matched('resources') === 0)
check('the empty line names the term', three.filterEmpty() === 'Nothing matches "zzzz".')
check('both rows are still in state', Object.keys(three.state.stances).length === 2)

console.log('\n=== 4. Clearing the term brings the reading back ===')

const four = makeGrid()

four.filter = 'tag'
four.filter = '   '

check('whitespace is not a term', four.shown(POST) === true && four.shown(TAG) === true)
check('and the count is everything again', four.matched('resources') === 2)

console.log('\n=== 5. The fold summary, the one composed sentence ===')

const five = makeGrid()

check('a row with one grant and one prohibition', five.stackSummary(POST) === '1 of 2 · 1 forbidden')
check('a row with nothing forbidden drops that half', five.stackSummary(TAG) === '1 of 1')

five.write(TAG, 'viewAny', 'abstain')

check('and it follows a click', five.stackSummary(TAG) === '0 of 1')

console.log('\n=== 6. The control: pruning state IS what this forbids ===')

// Without this block the checks above would pass for a filter that pruned, since
// nothing above ever asks what pruning would look like. This is the shape the
// gate exists to reject: it writes exactly what a "tidy" filter would, and shows
// the hidden row's grants gone from the payload the save would send.
const six = makeGrid()

const pruned = {}

for (const row of Object.keys(six.state.stances)) {
    if (six.shown(row)) {
        pruned[row] = six.state.stances[row]
    }
}

six.filter = 'tag'

const wouldPrune = {}

for (const row of Object.keys(six.state.stances)) {
    if (six.shown(row)) {
        wouldPrune[row] = six.state.stances[row]
    }
}

check(
    'a filter that pruned would drop the hidden row from the payload — so this one must not',
    Object.keys(pruned).length === 2 && Object.keys(wouldPrune).length === 1,
)
check('and the real state still has both', Object.keys(six.state.stances).length === 2)

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — the filter reads the catalogue and writes nothing.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
