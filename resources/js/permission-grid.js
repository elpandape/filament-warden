/**
 * The permission grid.
 *
 * Every VALUE arrives from PHP in `grid` — the cycle order, which actions each
 * row offers, which of them are reads, the six operators, every word the builder
 * says — because the generation before this one wrote those out twice and
 * nothing could tell when the two copies disagreed.
 *
 * What does NOT arrive is the RULES below. This comment claimed there was one
 * of them, and then that there were five; counted properly there are nine with
 * a counterpart in PHP and one without. They are here because a click has to
 * redraw without asking the server, so they cannot be collapsed — what they can
 * be is named, beside the file that decides the same thing on the server,
 * because each pair has to move together:
 *
 * - `drawn()`: an abstaining cell that something wider already answers is drawn
 *   as `broader`. PHP: `Cell::drawn()`.
 * - `reached()`: which wider rule wins — the row's own MANAGE column, the
 *   wildcard over every entity, or the rule for this name — with a forbidding
 *   one beating a granting one. PHP: `GridView::reach()`.
 * - `answers()`: what one cell ANSWERS rather than what was written on it.
 *   PHP: `Cell::answers()`.
 * - `granted()`: the tab counter, which counts what the cells answer and not
 *   what the role wrote. PHP: `Tab::granted()`, and a test pins the two together
 *   on a role that holds only the wildcard.
 * - `reachOf()`: a locked cell shows the store's word, an editable one the
 *   pending click. PHP: `Conditions/Narrowing`, reached through `Grants/RoleState`.
 * - `clauses()`: the clause cut. PHP: `Narrowing::clauses()`.
 * - `preview()` and `lineOf()`: the sentence a rule reads as, and its bracketing.
 *   PHP: `Narrowing::preview()` and `Conditions/Rule::text()`.
 *
 * - `answered()`: what one row's cells answer, for the reading that folds them
 *   away — granted and forbidden, one stance more than the tab counter.
 *   PHP: `Row::answered()`.
 * - `stackSummary()`: that count as the line a person reads, and the only place
 *   this file puts a computed value inside a translated sentence.
 *   PHP: `GridView::summaryOf()`, and a test pins the two together.
 *
 * `booleanMisfit()` and `booleanColumnMisfit()` are the two with no counterpart:
 * PHP ships the column list they need (`Columns::booleans()`) and decides nothing
 * about it. Between them they mirror warden's own `WhereCan::compileOne()` guard,
 * which is symmetric where its in-memory sibling `ComparisonOperator::compare()`
 * is not.
 *
 * `said` is not a rule and not a word of its own: it is `stateOf()`'s answer,
 * parked where a live region can read it. Written in `write()` so a preset and
 * a keyboard cycle announce too, and deliberately NOT in `select()`, which
 * returns early when the inspector and the condition builder are both off.
 *
 * `shown()`, `matched()`, `filterCount()` and `filterEmpty()` are not on either
 * list, and that is the point of them: the filter decides what is DRAWN and the
 * server decides nothing about it, because a filter the server knew about would
 * be a filter that reaches the payload — and a payload with an entity missing is
 * written as a deliberate revoke. `verify/verify-filter-keeps-state.mjs` drives
 * that boundary.
 *
 * `cycle()` came off this list in `1.8.0`: PHP carried a second walker over the
 * same order until then. Now the order is declared once, in `Stance::order()`,
 * and only this file walks it.
 */
/**
 * The half both screens share: a flat list of conditions, its groups and the
 * line it reads as. Each component says where the rule lives — `current()` and
 * `replaceRules()` — and everything else is the same on both.
 */
const conditions = {
    rules() {
        return this.current().rules ?? []
    },

    modeOf() {
        return this.current().mode
    },

    /**
     * The groups the precedence draws. This is the one rule written twice:
     * `Narrowing::clauses()` in PHP is the authority — it is what gets saved and
     * what the suite pins — and this copy exists because the pending state never
     * reaches the server between one keystroke and the next.
     */
    clauses() {
        const clauses = []
        let clause = []

        this.rules().forEach((rule, at) => {
            if (at > 0 && rule.logic === 'or') {
                clauses.push(clause)
                clause = []
            }

            clause.push({ ...rule, at })
        })

        if (clause.length > 0) {
            clauses.push(clause)
        }

        return clauses
    },

    preview() {
        const groups = this.clauses()

        return groups
            .map((clause) => {
                const text = clause.map((rule) => this.lineOf(rule)).join(' ' + this.words.joiners.and + ' ')

                return clause.length > 1 && groups.length > 1 ? '(' + text + ')' : text
            })
            .join(' ' + this.words.joiners.or + ' ')
    },

    lineOf(rule) {
        const right = rule.kind === 'column'
            ? this.words.authority + '.' + rule.authority
            : rule.value

        return rule.column + ' ' + rule.operator + ' ' + right
    },

    /**
     * A typed `true` or `false` against a column with no boolean cast. Warden
     * compares strictly, so that condition is stored and never matches — and
     * the person typing it is the only one who can still change it.
     */
    booleanMisfit(rule) {
        return rule.kind === 'value'
            && (rule.value === 'true' || rule.value === 'false')
            && ! this.source.booleans.includes(rule.column)
    },

    /**
     * The mirror, and it is the half warden closed on its own side and not on
     * the other: `WhereCan::compileOne()` compares `is_bool($value)` against
     * `hasCast()` and fails closed either way round, while
     * `ComparisonOperator::compare()` — the in-memory pass — still reads
     * `$left === $right || ($numeric && $left == $right)`, and `is_numeric(true)`
     * is false. So anything that is not `true`/`false` against a column the
     * model DOES cast to boolean is stored and never matches an instance.
     * Written as a prohibition it never fires at all, which is the direction
     * that matters.
     *
     * An empty value is left alone: it is a rule still being typed, not a
     * mistake, and `Value::cast()` refuses it on the way in anyway.
     */
    booleanColumnMisfit(rule) {
        return rule.kind === 'value'
            && rule.value !== ''
            && rule.value !== 'true'
            && rule.value !== 'false'
            && this.source.booleans.includes(rule.column)
    },

    add(kind) {
        this.replaceRules([...this.rules(), {
            logic: 'and',
            kind,
            column: this.source.columns[0] ?? '',
            operator: this.words.operators[0],
            value: '',
            authority: kind === 'column' ? (this.source.authority[0] ?? '') : '',
        }])
    },

    edit(at, field, value) {
        this.replaceRules(this.rules().map((rule, index) => (index === at ? { ...rule, [field]: value } : rule)))
    },

    drop(at) {
        this.replaceRules(this.rules().filter((rule, index) => index !== at))
    },
}

/**
 * One registration serves both screens, because Filament publishes one file per
 * component and a relative import between two of them would depend on where
 * `filament:assets` happened to put them.
 */
export default function (props) {
    return props.builder === undefined ? grid(props) : builder(props)
}

/**
 * The condition builder on its own, over one rule and with no cell to select.
 * There are no three alternatives here: ownership is a checkbox of its own on
 * that screen, so an empty list is simply every row.
 */
function builder({ state, words, source, interactive }) {
    return {
        ...conditions,

        words,

        source,

        interactive,

        state: state ?? { mode: 'all', rules: [] },

        current() {
            return this.state ?? { mode: 'all', rules: [] }
        },

        replaceRules(rules) {
            // Replaced whole rather than mutated in place: livewire only notices
            // a change it can see on the entangled property itself.
            this.state = { mode: rules.length > 0 ? 'conditions' : 'all', rules }
        },
    }
}

function grid({ state, grid, interactive }) {
    return {
        ...conditions,

        grid,

        interactive,

        state: state ?? {},

        tab: grid.tabs.length > 0 ? grid.tabs[0].key : null,

        // One term for the whole grid rather than one per tab, because there is
        // exactly one matrix tab by construction: `GridView::matrix()` is
        // private and called once. The doors tabs have no rows to filter.
        filter: '',

        selected: null,

        why: null,

        narrowing: null,

        loading: false,

        failed: false,

        // What the last click did, for the live region above the grid. Empty
        // until something is clicked, because a region that arrives with its
        // text already in it is a region NVDA and JAWS do not announce.
        said: '',

        // A plain counter, never an object. Alpine's reactivity (`@vue/reactivity`,
        // which it pins) wraps every object-valued property in a Proxy on each
        // read, and a Proxy is never `===` its raw target — so comparing
        // `this.selected` back against the object assigned to it is always
        // false-negative, on the very first uncontested click, with no race
        // anywhere near it. A number is never wrapped, so its identity survives
        // the round trip through reactive state.
        asked: 0,

        /**
         * Cycling and selecting at once: the person who changes a cell is the one
         * who wants to know what it meant.
         */
        pick(row, action, label, name, backwards = false) {
            this.cycle(row, action, backwards)
            this.select(row, action, label, name)
        },

        /**
         * Both answers are worked out on the server, one cell at a time, and
         * arrive already written: nothing here composes a sentence. They are
         * asked for together because they are one question about one cell.
         *
         * Three things guard the assignment, and each is a state the screen
         * would otherwise show falsely. The call is not made at all when neither
         * half of the inspector is on the page: the panel is gated by config and
         * the click handler was not, so every click cost two round trips into
         * markup that was never rendered. The answer is dropped when a faster
         * click already replaced it — tracked by a token, not by comparing
         * `this.selected` to the object this call wrote: Alpine's reactivity
         * hands back a fresh Proxy on every read of an object property, and a
         * Proxy is never `===` its raw target, so an object-identity guard here
         * discards every reply, uncontested or not. And a rejected call says so
         * — before this there was a header over an empty body and no sentence
         * anywhere in the package to put in it.
         */
        async select(row, action, label, name) {
            if (! this.grid.explain && ! this.grid.constraints) {
                return
            }

            this.selected = { row, action, title: label, subtitle: name ?? row }
            this.why = null
            this.narrowing = null
            this.failed = false
            this.loading = true

            // The sequencing token. `this.selected` still names the cell on
            // screen, but it stopped being what proves a reply is still wanted:
            // it is an object, and Alpine reads it back through a reactive Proxy
            // that is never `===` the raw value this method just assigned. A
            // number is not wrapped, so it is what the three guards below compare.
            const token = (this.asked ?? 0) + 1
            this.asked = token

            try {
                const [why, narrowing] = await Promise.all([
                    this.$wire.callSchemaComponentMethod(this.grid.key, 'explainCell', { row, action }),
                    this.$wire.callSchemaComponentMethod(this.grid.key, 'narrowingFor', { row, action }),
                ])

                if (this.asked !== token) {
                    return
                }

                // `[]` is the server saying it was asked nothing this grid can
                // answer, and it is truthy here. Left as it came, the template's
                // `why &&` passed it and drew a verdict box of undefineds.
                this.why = why && why.verdict ? why : null
                this.narrowing = narrowing
            } catch {
                if (this.asked === token) {
                    this.failed = true
                }
            } finally {
                if (this.asked === token) {
                    this.loading = false
                }
            }
        },

        /**
         * Abstaining is the absence of a key, never a value.
         */
        stanceOf(row, action) {
            return (this.state.stances?.[row] ?? {})[action] ?? this.grid.order[0]
        },

        /**
         * What the cell draws. A cell nobody wrote still shows when a granted or
         * forbidden wildcard already reaches it, and it says which.
         */
        drawn(row, action, name) {
            const stance = this.stanceOf(row, action)

            if (stance !== this.grid.order[0]) {
                return stance
            }

            return this.reached(row, action, name) === null ? stance : 'broader'
        },

        /**
         * What already answers for a cell nobody wrote: the wildcard on its own
         * row, or a rule written over every entity at once. Forbidden wins, the
         * same way it wins when the store resolves the check.
         */
        reached(row, action, name) {
            const candidates = [
                action === this.grid.manage ? null : this.stanceOf(row, this.grid.manage),
                this.grid.wider['*'] ?? null,
                this.grid.wider[name ?? action] ?? null,
            ].filter((stance) => stance !== null && stance !== this.grid.order[0])

            if (candidates.length === 0) {
                return null
            }

            return candidates.includes(this.grid.order[2]) ? this.grid.order[2] : this.grid.order[1]
        },

        cycle(row, action, backwards = false) {
            const order = this.grid.order
            const step = backwards ? order.length - 1 : 1
            const next = order[(order.indexOf(this.stanceOf(row, action)) + step) % order.length]

            this.write(row, action, next)
        },

        /**
         * `read` grants the reading half and leaves the rest as it was; `all`
         * grants the wildcard and everything the policy declares; `clear` says
         * nothing about anything.
         *
         * All three also put every cell they touch back to every row: "all" that
         * left a condition underneath would say the opposite of what it promises.
         */
        apply(row, preset) {
            const offered = this.grid.rows[row] ?? { actions: [], read: [] }
            const granted = this.grid.order[1]

            if (preset === 'read') {
                offered.read.forEach((action) => this.widen(row, action, granted))

                return
            }

            if (preset === 'all') {
                this.widen(row, this.grid.manage, granted)
                offered.actions.forEach((action) => this.widen(row, action, granted))

                return
            }

            this.widen(row, this.grid.manage, this.grid.order[0])
            offered.actions.forEach((action) => this.widen(row, action, this.grid.order[0]))
        },

        widen(row, action, stance) {
            this.write(row, action, stance)
            this.narrow(row, action, { mode: 'all', rules: [] })
        },

        /* ── Finding a row ───────────────────────────────────── */

        /**
         * Whether a row is one of the ones being looked for.
         *
         * It reads `grid`, which PHP filled and nothing ever writes, and NEVER
         * `state`. That is the whole safety property of this feature, not a
         * style: a filter that pruned `state.stances` would send a payload with
         * an entity missing, and `RoleGrants::plan()` walks the CATALOGUE, so a
         * missing cell arrives as an abstention against a baseline that still
         * has it and is written as a deliberate revoke — with a success report
         * on top. It cannot be defended against downstream either: clearing a
         * cell deletes its key too, so the two are byte for byte the same
         * payload. Two tests in `RoleGrantsTest` pin what that costs.
         *
         * Both readings ask this same method, so the table and the fold cannot
         * disagree about which rows exist.
         */
        shown(row) {
            const term = this.filter.trim().toLowerCase()

            if (term === '') {
                return true
            }

            const found = this.grid.rows[row] ?? {}

            return (found.label + ' ' + (found.model ?? '')).toLowerCase().includes(term)
        },

        matched(tabKey) {
            const tab = this.grid.tabs.find((candidate) => candidate.key === tabKey)

            return tab === undefined ? 0 : tab.rows.filter((row) => this.shown(row)).length
        },

        filterCount(tabKey) {
            const tab = this.grid.tabs.find((candidate) => candidate.key === tabKey)

            return this.grid.filter.count
                .replace(':matched', this.matched(tabKey))
                .replace(':total', tab === undefined ? 0 : tab.rows.length)
        },

        filterEmpty() {
            return this.grid.filter.empty.replace(':term', this.filter.trim())
        },

        /**
         * The fold's per-entity count, which the wide reading does not need
         * because its cells are all on one line.
         *
         * It counts what the cells ANSWER, like the tab counter, and one stance
         * more than it: a tab counts granted, this counts granted and forbidden,
         * because a collapsed entity hides a prohibition just as well as a
         * grant. PHP: `Row::answered()`, and a test pins the two together.
         *
         * The one place this file puts a computed value inside a translated
         * line. Every other string it produces is a whole word looked up in a
         * map PHP filled. `verify/verify-stack-summary.mjs` drives it.
         */
        stackSummary(row) {
            const answered = this.answered(row)
            const ratio = this.grid.summary.ratio
                .replace(':granted', answered.granted)
                .replace(':total', answered.total)

            return answered.forbidden === 0
                ? ratio
                : ratio + ' · ' + this.grid.summary.forbidden.replace(':count', answered.forbidden)
        },

        answered(row) {
            const cells = this.grid.rows[row]?.cells ?? []
            const states = cells.map((cell) => this.answers(row, cell.action, cell.name))

            return {
                granted: states.filter((state) => state === this.grid.order[1]).length,
                forbidden: states.filter((state) => state === this.grid.order[2]).length,
                total: states.length,
            }
        },

        /**
         * What the tab GRANTS, which is not what was written on it: a role
         * holding the wildcard wrote nothing on any cell and reaches all of them.
         * `Tab::granted()` in PHP counts the same way, and there is a test that
         * pins the two together on a role that only holds the wildcard.
         */
        granted(tabKey) {
            const tab = this.grid.tabs.find((candidate) => candidate.key === tabKey)

            if (tab === undefined) {
                return 0
            }

            return tab.rows.reduce(
                (total, row) => total + (this.grid.rows[row]?.cells ?? [])
                    .filter((cell) => this.answers(row, cell.action, cell.name) === this.grid.order[1]).length,
                0,
            )
        },

        answers(row, action, name) {
            const drawn = this.drawn(row, action, name)

            return drawn === 'broader' ? this.reached(row, action, name) : drawn
        },

        /**
         * The three words a cell says to a screen reader, and the only thing the
         * browser does with them: index a map php filled in, the same way it
         * indexes the cycle. `answers()` returns a stance value and `states` is
         * keyed by one, so nothing here has to know what a stance is called —
         * which is a rule with a test behind it, not a preference.
         */
        stateOf(row, action, name) {
            return this.grid.states[this.answers(row, action, name)]
        },

        reachedMark(row, action, name) {
            return this.drawn(row, action, name) === 'broader' ? this.grid.states.broader : ''
        },

        markOf(row, action) {
            return this.narrowedAt(row, action) ? this.grid.states.narrowed : ''
        },

        write(row, action, stance) {
            const held = { ...(this.state.stances?.[row] ?? {}) }

            if (stance === this.grid.order[0]) {
                delete held[action]
            } else {
                held[action] = stance
            }

            this.state = { ...this.state, stances: this.replace(this.state.stances, row, held) }

            // Said here and not in `pick()`, so a preset and a keyboard cycle
            // announce too; and not in `select()`, which returns without doing
            // anything when the inspector and the builder are both off — a
            // configuration where a click said nothing at all, ever.
            //
            // AFTER the write, and through the same three methods the cell's
            // own spans are bound to, in the same order — so the region says
            // byte for byte what the cell now says. Taking `grid.states[stance]`
            // instead would be a second version of one fact: a cell cleared
            // under a granting wildcard answers "granted", and carries "reached
            // by a broader rule" beside it, where the stance alone says "no
            // rule".
            //
            // The cell is not named. The person's focus is on it, so it is
            // already known — and a preset writes a whole row at once, where
            // naming one of them would be worse than naming none.
            //
            // A preset writes one stance to every cell it touches, so the last
            // call through here is true of all of them.
            this.said = this.spoken(row, action)
        },

        /**
         * The cell's own words, in the cell's own order.
         *
         * `box.blade.php` binds three spans — `stateOf()`, `reachedMark()` and
         * `markOf()` — and the accessibility tree joins them itself. This joins
         * the same three the same way, which is what keeps one cell from having
         * two descriptions on one screen.
         */
        spoken(row, action) {
            const name = (this.grid.rows[row]?.cells ?? []).find((cell) => cell.action === action)?.name

            return [
                this.stateOf(row, action, name),
                this.reachedMark(row, action, name),
                this.markOf(row, action),
            ].filter((word) => word !== '' && word !== undefined).join(' ')
        },

        /* ── The tabs, from the keyboard ────────────────────────────────── */

        /**
         * The arrows select and move focus together, which is what a tablist
         * does when its panels are cheap to show — and these are: every panel is
         * already in the DOM and `x-show` only toggles display.
         *
         * The rendered buttons are the list, not `grid.tabs`: the handler is on
         * the tablist and a keydown there needs a focused element inside it, and
         * the only elements inside it ARE these buttons. So there is no empty
         * list to guard against and no lookup that can miss — a guard here would
         * be a branch nothing can reach and no javascript gate can measure. Even
         * a `tab` that matched nothing lands at -1 and steps to the first
         * button rather than at anything undefined.
         */
        stepTab(list, step) {
            const buttons = Array.from(list.querySelectorAll('[role="tab"]'))
            const at = buttons.findIndex((button) => button.dataset.fwTab === this.tab)

            this.openTab(buttons[(at + step + buttons.length) % buttons.length])
        },

        edgeTab(list, last) {
            const buttons = Array.from(list.querySelectorAll('[role="tab"]'))

            this.openTab(buttons[last ? buttons.length - 1 : 0])
        },

        /**
         * Selecting and focusing are one move: the roving tabindex has to land on
         * the element that is now the tab stop, or the next Tab press leaves from
         * somewhere the eye is not.
         */
        openTab(button) {
            this.tab = button.dataset.fwTab
            button.focus()
        },

        /* ── How far a cell reaches ─────────────────────────────────────── */

        narrowedAt(row, action) {
            return this.narrowingAt(row, action).mode !== 'all'
        },

        narrowingAt(row, action) {
            return (this.state.narrowing?.[row] ?? {})[action] ?? { mode: 'all', rules: [] }
        },

        /**
         * Which of the three the buttons light.
         *
         * A cell this screen may not change shows the store's own word, and
         * that word is never one of the three offered, so none of them lights:
         * the store holds a reach this screen does not offer, and painting
         * "every row" there says the exact opposite of what is stored. A cell
         * that CAN be changed follows the pending state, because the buttons
         * are live and must follow the click.
         *
         * `narrowing.stored` is read without a guard on purpose: `offered()`
         * refuses a null payload and a grid with the builder switched off, and
         * nothing inside that `<template>` is evaluated until it says yes.
         */
        reachOf() {
            return this.narrowing.stored.locked ? this.narrowing.stored.mode : this.modeOf()
        },

        /**
         * Which of the three a cell may be moved to.
         *
         * Asked once and read twice: the markup binds `disabled` to it and the
         * arrow keys step over it, so the rule that decides is not the rule
         * that skips.
         */
        reachEnabled(mode) {
            return this.interactive
                && ! this.narrowing.stored.locked
                && (mode !== 'owned' || this.narrowing.ownership.available)
        },

        /**
         * Which option holds the group's single tab stop.
         *
         * The chosen one, unless the store holds a reach this screen does not
         * offer — then the first one still reachable, so a radiogroup is never
         * a region with no way into it.
         */
        reachStop() {
            const open = ['all', 'owned', 'conditions'].filter((mode) => this.reachEnabled(mode))

            return open.includes(this.reachOf()) ? this.reachOf() : (open[0] ?? null)
        },

        /**
         * The rendered buttons are the list, for the same reason `stepTab` reads
         * them: a keydown on the group needs a focused element inside it, and
         * the only elements inside it are these.
         *
         * Disabled options are stepped over rather than landed on — an arrow
         * that moves somewhere and does nothing reads as a broken keyboard.
         *
         * The empty case cannot arrive from the page: a group with all three
         * disabled has no focusable child, so no keydown of ours fires on it.
         * The guard is for the call, not for a defect the markup can produce —
         * without it an empty list indexes to `undefined` and throws.
         */
        stepReach(group, step) {
            const open = Array.from(group.querySelectorAll('[role="radio"]')).filter((one) => ! one.disabled)

            if (open.length === 0) {
                return
            }

            const at = open.findIndex((one) => one.getAttribute('aria-checked') === 'true')
            const next = open[(Math.max(at, 0) + step + open.length) % open.length]

            this.setMode(next.dataset.fwMode)
            next.focus()
        },

        /* The two names the shared half reads. The grid keeps its own, older
           ones so nothing else has to move. */
        get words() {
            return this.grid
        },

        get source() {
            return this.narrowing
        },

        current() {
            return this.selected === null
                ? { mode: 'all', rules: [] }
                : this.narrowingAt(this.selected.row, this.selected.action)
        },

        replaceRules(rules) {
            this.narrow(this.selected.row, this.selected.action, { mode: 'conditions', rules })
        },


        /**
         * The builder is offered on a cell that says something and whose row has
         * a model behind it: a permission with no model and conditions on it is
         * created, shown, and grants nothing ever.
         *
         * The config check is the first clause and it lives here rather than in
         * the template. With the builder switched off `narrowing` is `[]`, and
         * `[].model` is `undefined`, which is `!== null` — so every clause below
         * would pass and `narrowing.stored.locked` in the markup would throw. The
         * only thing that stopped it was the order of two operands inside an
         * `x-if`, which is not a guard.
         */
        offered() {
            return this.grid.constraints
                && this.selected !== null
                && this.narrowing !== null
                && this.narrowing.model !== null
                && this.narrowing.stored !== null
                && this.stanceOf(this.selected.row, this.selected.action) !== this.grid.order[0]
        },

        setMode(mode) {
            this.narrow(this.selected.row, this.selected.action, {
                mode,
                rules: mode === 'conditions' ? this.rules() : [],
            })
        },

        narrow(row, action, narrowing) {
            const held = { ...(this.state.narrowing?.[row] ?? {}) }

            if (narrowing.mode === 'all') {
                delete held[action]
            } else {
                held[action] = narrowing
            }

            this.state = { ...this.state, narrowing: this.replace(this.state.narrowing, row, held) }
        },

        /**
         * Replaced whole rather than mutated in place: livewire only notices a
         * change it can see on the entangled property itself.
         */
        replace(map, row, held) {
            const next = { ...(map ?? {}) }

            if (Object.keys(held).length === 0) {
                delete next[row]
            } else {
                next[row] = held
            }

            return next
        },
    }
}
