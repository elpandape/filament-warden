// Verifies that an end date survives every write the grid makes, and that the
// browser refuses to put one where warden would throw — against the *real*
// @vue/reactivity Alpine pins (alpinejs@3.16.2 → "@vue/reactivity": "~3.5.40").
//
// Why this needs a gate at all
// ────────────────────────────
// `until` joined the state envelope in 3.0.0 as its own map, alongside
// `stances`, `narrowing` and `inherited`. Two things about it cannot be seen
// from PHP:
//
//   1. It travels through the SAME spread every other write uses. Rewriting
//      `setUntil()` or `write()` as `{ stances, until }` — which reads like
//      tidying, since those are the keys the file knows — drops whichever key
//      the rewrite forgot, from the first click onward. There is no error and no
//      failing PHP test: by the time the payload reaches the server the key
//      simply is not there, which is indistinguishable from a screen that never
//      had one.
//
//   2. The browser must not offer a date where warden refuses one.
//      `ForbidsPermissions::until()` throws UNCONDITIONALLY — passing `null` is
//      still an error, not a way of saying "no end" — so a screen that lets
//      somebody put a date on a prohibition produces a 500 on save rather than a
//      wrong row. The server drops it anyway (§6.11: a disabled control's
//      payload still arrives), so this is the advisory half; it is still worth
//      pinning, because the advisory is what stops the person getting there.
//
// Run: cd verify && npm install && node verify-until.mjs

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

function makeGrid(stances = { [ROW]: { viewAny: 'granted' } }, until = {}) {
    const component = factory({
        state: {
            stances,
            narrowing: {},
            until,
            inherited: {},
            baseline: { stances, narrowing: {}, until, inherited: {} },
        },
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
                narrowed: 'fw-narrowed',
                abstain: 'no rule',
                granted: 'granted',
                forbidden: 'forbidden',
                broader: 'reached by a broader rule',
                expires: 'expires',
                inherited: 'inherited',
            },
            until: { forbidden: 'a forbid does not expire', unwritten: 'nothing here to date' },
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

console.log('=== 1. A date is written, and every sibling key survives it ===')

const one = makeGrid()

one.setUntil(ROW, 'viewAny', '2026-10-12')

check('the date is in the state', one.state.until[ROW].viewAny === '2026-10-12')
check('stances are untouched', one.state.stances[ROW].viewAny === 'granted')
check('the baseline is still there', one.state.baseline !== undefined)
check('and so is every other key', one.state.narrowing !== undefined && one.state.inherited !== undefined)

console.log('\n=== 2. A stance write carries the date through ===')

const two = makeGrid({ [ROW]: { viewAny: 'granted' } }, { [ROW]: { viewAny: '2026-10-12' } })

two.write(ROW, 'update', 'granted')

check('the other cell was written', two.state.stances[ROW].update === 'granted')
check('and the first cell kept its date', two.state.until[ROW].viewAny === '2026-10-12')

console.log('\n=== 3. Clearing a date takes the row with it, never leaves an empty map ===')

const three = makeGrid({ [ROW]: { viewAny: 'granted' } }, { [ROW]: { viewAny: '2026-10-12' } })

three.setUntil(ROW, 'viewAny', '')

check('the date is gone', three.untilAt(ROW, 'viewAny') === null)
check('and so is the row it was the only entry of', three.state.until[ROW] === undefined)

console.log('\n=== 4. A prohibition refuses a date, because warden throws on one ===')

const four = makeGrid({ [ROW]: { viewAny: 'forbidden' } })

check('the control is off', four.untilEnabled(ROW, 'viewAny') === false)
check('and it says which of the three noes this is', four.untilReason(ROW, 'viewAny') === 'a forbid does not expire')

four.setUntil(ROW, 'viewAny', '2026-10-12')

check('and asking anyway writes nothing', four.untilAt(ROW, 'viewAny') === null)

console.log('\n=== 5. An abstention refuses one too, and says a different sentence ===')

const five = makeGrid({})

check('the control is off', five.untilEnabled(ROW, 'viewAny') === false)
check('with the other reason', five.untilReason(ROW, 'viewAny') === 'nothing here to date')

console.log('\n=== 6. The control: a rewrite that "tidies" the spread loses a key ===')

const six = makeGrid({ [ROW]: { viewAny: 'granted' } }, { [ROW]: { viewAny: '2026-10-12' } })

// Exactly what `setUntil()` would be if somebody rebuilt the object from the
// keys this file knows about. Without this block the five above would pass on a
// component that had already dropped the baseline.
six.state = { stances: six.state.stances, until: six.state.until }

check('the baseline is gone, which is what the spread is for', six.state.baseline === undefined)

console.log('\n=== 7. A date is said out loud, beside the words the cell already says ===')

const seven = makeGrid()

seven.setUntil(ROW, 'viewAny', '2026-10-12')

check('the region names the state', seven.said.includes('granted'))
check('and the date', seven.said.includes('expires'))

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — the date rides the same spread as everything else, and is refused where warden would throw.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
