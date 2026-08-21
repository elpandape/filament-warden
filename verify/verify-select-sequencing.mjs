// Verifies the sequencing guard in resources/js/permission-grid.js's select()
// against the *real* @vue/reactivity package Alpine pins (alpinejs@3.16.2
// depends on "@vue/reactivity": "~3.5.40"; installed here: 3.5.41).
//
// Alpine's x-data component is wrapped with @vue/reactivity's reactive().
// Its `get` trap lazily wraps any object-valued property in a Proxy on every
// read. A Proxy is never === its raw target. That is the bug this script
// exists to prove and re-prove: comparing `this.selected !== asked`, where
// `asked` is the raw object assigned to `this.selected`, is *always* true
// once read back through the reactive proxy — not just on a real race. It
// shipped in this release's first cut of select(), was caught in review (not
// by any of the 640 Pest tests — those assert the guard's TEXT is present in
// the file, never that it behaves), and every cell click stuck on "Asking
// the store…" forever. See AGENTS.md §6.26 for the full account and §6.27
// for why no gate in this package could have caught it.
//
// selectBroken()/selectFixed() below are TRANSCRIBED to match the shipped
// select() exactly (old and new), not imported live — unlike the sibling
// script verify-reach-of.mjs, which imports resources/js/permission-grid.js
// directly. Keep them byte-identical to select() if select() changes; the
// two automated PHP tests that read the file as text will not catch drift
// between this script's logic and the real one.
//
// ── How to run ──────────────────────────────────────────────────────────
//   cd verify
//   npm install
//   node verify-select-sequencing.mjs
//
// Requires Node + npm on the HOST. Not part of `make ci` — the Docker image
// behind it (php:8.5-cli-alpine) has neither Node nor npm, and this
// package's JS has no gate at all (AGENTS.md §6.27, §7). This script, and
// its sibling verify-reach-of.mjs, are the only executable evidence this
// package's JS has; they are run by hand, not on every change.
//
// This directory (verify/) lives at the repo root, on purpose, not under
// resources/js/ (that is a registered Filament asset — `php artisan
// filament:assets` copies it into every consuming application's public
// directory, and a verification script has no business shipping there) and
// not under tests/js/ (that would imply these are part of the Pest suite;
// they are not — `make ci` never runs them). `/verify export-ignore` in
// .gitattributes keeps this whole directory out of the distributed package.

import { reactive } from '@vue/reactivity'

function freshComponent() {
    return reactive({
        selected: null,
        why: null,
        narrowing: null,
        loading: false,
        failed: false,
        asked: 0,
        grid: { explain: true, constraints: true, key: 'form.permissions' },
    })
}

// ---- The BROKEN guard, exactly as shipped in the first version of this task ----
async function selectBroken(comp, wire, row, action, label, name) {
    if (! comp.grid.explain && ! comp.grid.constraints) {
        return
    }

    const asked = { row, action, title: label, subtitle: name ?? row }

    comp.selected = asked
    comp.why = null
    comp.narrowing = null
    comp.failed = false
    comp.loading = true

    try {
        const [why, narrowing] = await Promise.all([
            wire.callSchemaComponentMethod('explainCell', { row, action }),
            wire.callSchemaComponentMethod('narrowingFor', { row, action }),
        ])

        if (comp.selected !== asked) {
            return
        }

        comp.why = why && why.verdict ? why : null
        comp.narrowing = narrowing
    } catch {
        if (comp.selected === asked) {
            comp.failed = true
        }
    } finally {
        if (comp.selected === asked) {
            comp.loading = false
        }
    }
}

// ---- The FIXED guard: a primitive token, not an object identity check ----
async function selectFixed(comp, wire, row, action, label, name) {
    if (! comp.grid.explain && ! comp.grid.constraints) {
        return
    }

    comp.selected = { row, action, title: label, subtitle: name ?? row }
    comp.why = null
    comp.narrowing = null
    comp.failed = false
    comp.loading = true

    const token = (comp.asked ?? 0) + 1
    comp.asked = token

    try {
        const [why, narrowing] = await Promise.all([
            wire.callSchemaComponentMethod('explainCell', { row, action }),
            wire.callSchemaComponentMethod('narrowingFor', { row, action }),
        ])

        if (comp.asked !== token) {
            return
        }

        comp.why = why && why.verdict ? why : null
        comp.narrowing = narrowing
    } catch {
        if (comp.asked === token) {
            comp.failed = true
        }
    } finally {
        if (comp.asked === token) {
            comp.loading = false
        }
    }
}

function immediateWire(whyPayload, narrowingPayload) {
    return {
        async callSchemaComponentMethod(method) {
            return method === 'explainCell' ? whyPayload : narrowingPayload
        },
    }
}

function controllableWire() {
    // Indexed by call order (0 = first invocation, 1 = second, …), not FIFO —
    // the whole point of the test is resolving the SECOND call's pair before
    // the first's, which a plain queue cannot express.
    const pending = { explainCell: [], narrowingFor: [] }

    return {
        wire: {
            callSchemaComponentMethod(method) {
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
let expectedRegressionFailures = 0

function check(label, condition) {
    console.log(`${condition ? 'PASS' : 'FAIL'} — ${label}`)

    if (! condition) {
        failures++
    }
}

// Section 1 intentionally reproduces the reported bug against the BROKEN
// guard — those two checks are SUPPOSED to read FAIL. They are counted
// separately so the final verdict reports the fix's own correctness, not a
// re-statement of the bug it fixes.
function checkRegression(label, condition) {
    console.log(`${condition ? 'unexpected PASS' : 'FAIL (expected — reproduces the reported bug)'} — ${label}`)

    if (condition) {
        failures++
    } else {
        expectedRegressionFailures++
    }
}

// ---------------------------------------------------------------------------
console.log('\n=== 1. THE REGRESSION, reproduced: broken guard, single UNCONTESTED click ===')
{
    const comp = freshComponent()
    const wire = immediateWire({ verdict: 'abstain', cause: null, summary: 'x' }, { model: null })

    await selectBroken(comp, wire, 'App\\Role', 'viewAny', 'Role · viewAny', 'App\\Role')

    checkRegression('why lands', comp.why !== null)
    checkRegression('loading resolves to false', comp.loading === false)
}

// ---------------------------------------------------------------------------
console.log('\n=== 2. THE FIX: single UNCONTESTED click lands ===')
{
    const comp = freshComponent()
    const wire = immediateWire({ verdict: 'abstain', cause: null, summary: 'This role has not been saved yet…' }, { model: null })

    await selectFixed(comp, wire, 'App\\Role', 'viewAny', 'Role · viewAny', 'App\\Role')

    check('why lands', comp.why !== null && comp.why.summary === 'This role has not been saved yet…')
    check('narrowing lands', comp.narrowing !== null)
    check('loading resolves to false', comp.loading === false)
    check('failed stays false', comp.failed === false)
}

// ---------------------------------------------------------------------------
console.log('\n=== 3. THE FIX: a superseded (stale) call is discarded, the later one wins ===')
{
    const comp = freshComponent()
    const { wire, resolve } = controllableWire()

    const first = selectFixed(comp, wire, 'App\\Role', 'viewAny', 'Cell A', 'App\\Role')
    // The click on B happens before A's server round trip returns — the exact race this guard exists for.
    const second = selectFixed(comp, wire, 'App\\Role', 'create', 'Cell B', 'App\\Role')

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
console.log('\n=== 4. THE FIX: a rejected call sets failed, an uncontested one ===')
{
    const comp = freshComponent()
    const wire = {
        async callSchemaComponentMethod() {
            throw new Error('offline')
        },
    }

    await selectFixed(comp, wire, 'App\\Role', 'viewAny', 'Cell A', 'App\\Role')

    check('failed becomes true', comp.failed === true)
    check('loading resolves to false', comp.loading === false)
    check('why stays null — no sentence exists for it to paint', comp.why === null)
}

// ---------------------------------------------------------------------------
console.log('\n=== 5. THE FIX: a superseded call that later rejects does not paint failure onto its successor ===')
{
    const comp = freshComponent()
    const pendingReject = { current: null }
    const wireA = {
        callSchemaComponentMethod() {
            return new Promise((_resolve, reject) => { pendingReject.current = reject })
        },
    }
    const wireB = immediateWire({ verdict: 'granted', cause: 'granted-directly', summary: 'B answer' }, { model: null })

    const first = selectFixed(comp, wireA, 'App\\Role', 'viewAny', 'Cell A', 'App\\Role')
    const second = selectFixed(comp, wireB, 'App\\Role', 'create', 'Cell B', 'App\\Role')

    await second
    pendingReject.current(new Error('offline, but only for the superseded call'))
    await first

    check('B’s answer still stands', comp.why.summary === 'B answer')
    check('failed was NOT set by A’s late rejection', comp.failed === false)
    check('loading stayed resolved', comp.loading === false)
}

console.log(`\nRegression reproduced as expected: ${expectedRegressionFailures}/2 checks failed against the BROKEN guard.`)
console.log(failures === 0
    ? '\nALL FIX CHECKS PASSED — the token guard lands an uncontested call, discards a superseded one, and its failure path is scoped correctly.'
    : `\n${failures} UNEXPECTED CHECK(S) FAILED`)
process.exit(failures === 0 ? 0 : 1)
