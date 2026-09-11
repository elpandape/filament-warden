import assert from 'node:assert/strict'
import { reactive } from '@vue/reactivity'
import factory from '../resources/js/permission-grid.js'

const original = { stances: { posts: { view: 'granted' } }, narrowing: {}, until: {} }
function fresh() {
    return reactive(factory({ interactive: true, state: { ...structuredClone(original), baseline: structuredClone(original) }, grid: {
        order: ['abstain', 'granted', 'forbidden'], manage: '*', wider: {},
        states: { abstain: 'None', granted: 'Grant', forbidden: 'Forbid' },
        tabs: [{ key: 'resources', rows: ['posts', 'tags'] }],
        axisColumns: { view: { label: 'View', scope: 'read' }, delete: { label: 'Delete', scope: 'withdraw' } },
        rows: {
            posts: { label: 'Posts', actions: ['view', 'delete'], manage: true, has: { own: true }, cells: [{ action: '*'}, { action: 'view' }, { action: 'delete' }] },
            tags: { label: 'Tags', actions: ['view'], manage: false, has: { own: false }, cells: [{ action: 'view' }] },
        },
    } }))
}

const component = fresh()
const before = JSON.stringify(component.state)
component.only = 'own'
assert.equal(component.shown('posts'), true)
assert.equal(component.shown('tags'), false)
component.filter = 'delete'
assert.equal(component.shownAction('view'), false)
assert.equal(component.shownIn('withdraw'), 1)
assert.equal(component.narrowingColumns(), true)
assert.equal(JSON.stringify(component.state), before)
component.selected = { row: 'tags', action: '*' }
component.setDecision('granted')
assert.equal(JSON.stringify(component.state), before)
component.selected = { row: 'posts', action: 'view' }
component.interactive = false
component.setDecision('forbidden')
assert.equal(JSON.stringify(component.state), before)
component.interactive = true
component.setDecision('forbidden')
assert.equal(component.pending().length, 1)
assert.equal(component.shown('posts'), true)
component.setDecision('granted')
assert.equal(component.pending().length, 0)
component.state.until = { posts: { view: '2026-12-01' } }
assert.equal(component.pending()[0].until, true)
component.state.until = {}
component.state.narrowing = { posts: { view: { mode: 'owned', rules: [] } } }
assert.equal(component.pending()[0].reach, true)

// The assertions must detect both a destructive filter and a bypassed readonly guard.
const mutant = fresh()
mutant.shown = function () { this.state.stances = {}; return true }
assert.throws(() => { mutant.shown('posts'); assert.equal(JSON.stringify(mutant.state), before) })
const unlocked = fresh()
unlocked.interactive = false
unlocked.selected = { row: 'posts', action: 'view' }
unlocked.decisionEnabled = () => true
assert.throws(() => { unlocked.setDecision('forbidden'); assert.equal(JSON.stringify(unlocked.state), before) })
console.log('PASS — filters preserve grants, decisions respect locks, pending includes scope and expiry; both mutation controls detected')
