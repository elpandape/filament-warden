// Verifies storedStance() and moved() in resources/js/permission-grid.js against
// the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends on
// "@vue/reactivity": "~3.5.40").
//
// Why this needs a gate at all
// ────────────────────────────
// Only the FORM's payload carries a `baseline` key — `PermissionGrid::setUp()`'s
// `afterStateHydrated` is the one place that ever stamps one on. The read-only
// host's payload — `Js::from($grid->stored)`, what `ViewRole` and every other
// infolist screen send — is `{stances, narrowing}`, nothing else, always. So on
// that screen `this.state.baseline` is not `null` and not `{}`: it is
// `undefined`, every time, by construction.
//
// `PermissionGridEntryTest.php` proves the two guards that answer for it are
// WRITTEN — a `toContain` against the raw script, the same pattern §6.26/§6.27
// name as the one that let a broken sequencing guard ship green in v1.1.0. A
// string being present in the file says nothing about what the method it sits
// inside actually returns once Alpine calls it, so what has to be proved here is
// the ANSWER, under the real reactive wrapper.
//
// ── What it proves ──────────────────────────────────────────────────────
// 1. With the infolist's exact shape — no `baseline` key at all — a granted
//    cell's storedStance() reads the live, granted state, not the abstain
//    fallback `grid.order[0]` gave before this fix.
// 2. Under that same shape, moved() is null: a screen with no save action never
//    claims an unsaved change.
// 3. The control that makes 1 and 2 mean something: the FORM's shape — the same
//    stances, plus a baseline whose stored value for that cell differs — still
//    reads storedStance() off the baseline and still reports moved() as a real
//    change. The new baseline-absent branch does not swallow the case it was
//    never meant to touch.
//
// Run: cd verify && npm install && node verify-two-voices.mjs

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

function freshGrid(state) {
    const component = factory({
        state,
        grid: {
            order: ['abstain', 'granted', 'forbidden'],
            manage: '*',
            rows: { [POST]: { label: 'Posts', model: POST, actions: ['viewAny'], read: ['viewAny'], cells: [{ action: 'viewAny', name: 'viewAny' }] } },
            tabs: [{ key: 'resources', rows: [POST] }],
            wider: {},
            states: { narrowed: 'fw-narrowed', abstain: 'no rule', granted: 'granted', forbidden: 'forbidden', broader: '' },
            explain: true,
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

console.log('=== 1. The infolist\'s exact shape: no `baseline` key at all ===')
{
    // What `PermissionGridEntry`'s view actually hands the browser:
    // `Js::from($grid->stored)`, which is `RoleState::toPayload()` —
    // `{stances, narrowing}` and nothing more.
    const comp = freshGrid({ stances: { [POST]: { viewAny: 'granted' } }, narrowing: {} })

    comp.selected = { row: POST, action: 'viewAny', title: 'Posts · viewAny', subtitle: POST }

    check('the envelope really is absent, not null or empty', comp.state.baseline === undefined)
    check('storedStance() reads the live granted state', comp.storedStance() === 'granted')
    check('moved() claims nothing on a screen with no save action', comp.moved() === null)
}

console.log('\n=== 2. The control: the FORM\'s shape, where a baseline exists and disagrees ===')
{
    const comp = freshGrid({
        stances: { [POST]: { viewAny: 'granted' } },
        narrowing: {},
        baseline: { stances: { [POST]: { viewAny: 'abstain' } }, narrowing: {} },
    })

    comp.selected = { row: POST, action: 'viewAny', title: 'Posts · viewAny', subtitle: POST }

    check('storedStance() still reads the baseline, not the live state', comp.storedStance() === 'abstain')
    check('moved() still reports the real, unsaved move', comp.moved() !== null)
    check('and names it correctly', comp.moved()?.from === 'no rule' && comp.moved()?.to === 'granted')
}

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — with no baseline the store is read live; with one, it still wins.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
