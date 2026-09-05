// Verifies the TWO boolean-mismatch warnings in resources/js/permission-grid.js
// against the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2 depends
// on "@vue/reactivity": "~3.5.40").
//
// Why this exists rather than a text assertion. `PermissionGridTest` proves the
// predicate is WRITTEN, by matching its source verbatim — which is the check that
// let a broken sequencing guard ship in v1.1.0, green, because a string being
// present says nothing about what it answers. These two predicates decide whether
// a person is warned that the rule they just typed can never fire, so what has to
// be proved is the ANSWER, under the wrapper Alpine really puts around `this`.
//
// What the two halves are. Warden's query side is symmetric: `WhereCan::compileOne()`
// compares `is_bool($constraint->value)` against `$model->hasCast(...)` and fails
// closed either way round. Its in-memory sibling is not: `ComparisonOperator::compare()`
// is `$left === $right || ($numeric && $left == $right)`, and `is_numeric(true)` is
// false, so a mismatch in EITHER direction is stored and never matches an instance.
// Written as a prohibition it never fires at all. This screen is the only place a
// person hears about it before saving, so it has to warn in both directions too.
//
// ── What it proves ──────────────────────────────────────────────────────
// 1. booleanMisfit(): `true`/`false` typed against a column with no boolean cast.
// 2. booleanColumnMisfit(): the mirror — anything else against a column that IS cast.
// 3. Neither fires on the matching cases, so a correct rule stays quiet.
// 4. Neither fires on a half-typed empty value, which is not a mistake yet.
// 5. Neither fires on a column-to-column rule, which compares no literal at all.
// 6. They are mutually exclusive: no rule ever lights both sentences.
// 7. The answers follow a live edit through the reactive proxy, which is what a
//    person actually does — type into the value box and watch the warning appear.
//
// Run: cd verify && npm ci && node verify-boolean-misfit.mjs

import { reactive } from '@vue/reactivity'
import gridComponent from '../resources/js/permission-grid.js'

let failures = 0

function check(label, actual, expected) {
    const ok = actual === expected
    if (!ok) {
        failures++
    }
    console.log(`${ok ? 'PASS' : 'FAIL'} — ${label}${ok ? '' : ` (got ${actual}, wanted ${expected})`}`)
}

function make(rules) {
    return reactive(gridComponent({
        builder: true,
        state: { mode: 'conditions', rules },
        words: { boolean: 'not cast', boolean_column: 'casts :column', operators: [], joiners: {}, modes: {} },
        // `published` is cast to boolean by the model; `title` is not. This is
        // exactly the shape `Conditions\Columns::booleans()` ships.
        source: { columns: ['title', 'published'], booleans: ['published'], authority: [] },
        interactive: true,
    }))
}

const rule = (column, value) => ({ kind: 'value', at: 0, column, operator: '=', value, logic: 'and' })

console.log('=== 1. A boolean value against a column with no cast ===\n')

let c = make([rule('title', 'true')])
check('booleanMisfit fires', c.booleanMisfit(c.rules()[0]), true)
check('and the mirror stays quiet', c.booleanColumnMisfit(c.rules()[0]), false)

c = make([rule('title', 'false')])
check('false too', c.booleanMisfit(c.rules()[0]), true)

console.log('\n=== 2. The mirror: a non-boolean value against a cast column ===\n')

c = make([rule('published', 'alpha')])
check('booleanColumnMisfit fires', c.booleanColumnMisfit(c.rules()[0]), true)
check('and the first stays quiet', c.booleanMisfit(c.rules()[0]), false)

c = make([rule('published', '1')])
check('a numeric string is a mismatch too — is_numeric(true) is false', c.booleanColumnMisfit(c.rules()[0]), true)

console.log('\n=== 3. The matching cases say nothing ===\n')

c = make([rule('published', 'true')])
check('boolean value, boolean column', c.booleanMisfit(c.rules()[0]) || c.booleanColumnMisfit(c.rules()[0]), false)

c = make([rule('title', 'alpha')])
check('plain value, plain column', c.booleanMisfit(c.rules()[0]) || c.booleanColumnMisfit(c.rules()[0]), false)

console.log('\n=== 4. A value still being typed is not a mistake ===\n')

c = make([rule('published', '')])
check('an empty value against a cast column warns nothing', c.booleanColumnMisfit(c.rules()[0]), false)

console.log('\n=== 5. A column-to-column rule compares no literal ===\n')

c = make([{ kind: 'column', at: 0, column: 'published', operator: '=', value: 'id', logic: 'and' }])
check('booleanMisfit stays quiet', c.booleanMisfit(c.rules()[0]), false)
check('booleanColumnMisfit stays quiet', c.booleanColumnMisfit(c.rules()[0]), false)

console.log('\n=== 6. Never both at once ===\n')

let both = 0
for (const column of ['title', 'published']) {
    for (const value of ['true', 'false', 'alpha', '1', '']) {
        const one = make([rule(column, value)])
        const r = one.rules()[0]
        if (one.booleanMisfit(r) && one.booleanColumnMisfit(r)) {
            both++
        }
    }
}
check('no combination lights both sentences', both, 0)

console.log('\n=== 7. The control: it follows a live edit ===\n')

c = make([rule('published', 'true')])
check('quiet to begin with', c.booleanColumnMisfit(c.rules()[0]), false)

c.edit(0, 'value', 'alpha')
check('and speaks once the value stops being boolean', c.booleanColumnMisfit(c.rules()[0]), true)

c.edit(0, 'column', 'title')
check('moving to an uncast column silences it again', c.booleanColumnMisfit(c.rules()[0]), false)
check('and hands the warning to its sibling', c.booleanMisfit(c.rules()[0]), false)

console.log(
    failures === 0
        ? '\nALL CHECKS PASSED — both directions of the boolean mismatch are said, and only one at a time.'
        : `\n${failures} CHECK(S) FAILED`,
)

process.exit(failures === 0 ? 0 : 1)
