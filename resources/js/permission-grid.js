/**
 * The permission grid.
 *
 * The VALUES arrive from PHP in `grid` — the cycle order, which actions each
 * row offers, which of them are reads, the operators, every word the builder
 * says — so none of them has a second copy here that could disagree with PHP's.
 *
 * What does not arrive is the RULES below. A click has to redraw without asking
 * the server, so each is written here a second time, named beside the PHP that
 * decides the same thing, because each pair has to move together. The PHP half
 * is the authority: it is what gets saved, or drawn before alpine boots.
 *
 * - `drawn()`: an abstaining cell that something wider already answers is drawn
 *   as `broader`. PHP: `Cell::drawn()`.
 * - `reached()`: which wider answer wins — the row's own MANAGE column, the
 *   wildcard over every entity, a rule over every entity for this name, or an
 *   inherited one — with a forbidding one beating a granting one.
 *   PHP: `GridView::reach()`.
 * - `answers()`: what one cell ANSWERS rather than what was written on it.
 *   PHP: `Cell::answers()`.
 * - `granted()`: the tab counter, over what the cells answer.
 *   PHP: `Tab::granted()`; `GridViewTest` pins the two on a role that holds
 *   only the wildcard.
 * - `answered()`: the same count over one row, forbidden as well as granted.
 *   PHP: `Row::answered()`.
 * - `stackSummary()`: that count as the line the fold reads.
 *   PHP: `GridView::summaryOf()`.
 * - `clauses()`: the clause cut. PHP: `Narrowing::clauses()`.
 * - `preview()` and `lineOf()`: the sentence a rule reads as, and each line of
 *   it. PHP: `Narrowing::preview()` and `Rule::text()`.
 * - `booleanMisfit()` and `booleanColumnMisfit()`: a condition that can never
 *   hold, one direction each, read off `Columns::booleans()`.
 *   PHP: `Narrowing::unsatisfiableColumns()`, which asks warden.
 * - `offered()`: the builder only on a cell that says something, on a row with
 *   a model. PHP: `RoleGrants::plan()`, which reads no reach for an abstaining
 *   cell, and `RoleGrants::wanted()`, which reads none on a row with no model.
 * - `reachOf()` and `reachEnabled()`: a locked cell stays on the store's reach
 *   and none of the three can be picked, and "owned" needs ownership to
 *   resolve.
 *   PHP: `RoleGrants::plan()`, which never writes the screen's reach onto a
 *   cell `Narrowing::isEditable()` refuses, and `Narrowing::fromPayload()`.
 * - `untilEnabled()`: which cells may carry an end date.
 *   PHP: `RoleGrants::plan()`.
 * - `movedAt()`: what this screen has changed in a cell since it opened.
 *   PHP: `RoleGrants::plan()`, where it asks whether this person touched it —
 *   compared there as instants and rebuilt rules, here as text, so a date
 *   re-picked to the stored day reads as moved only here.
 *
 * Not on the list, on purpose: `cycle()`, because the order is declared once,
 * in `Stance::order()`, and only this file walks it; and the filter — `axis()`
 * and everything that reads it — which the server must know nothing about, as
 * `axis()` explains.
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
     * Cut at every or, because and binds tighter — the precedence
     * `Narrowing::clauses()` draws.
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
     * A typed `true` or `false` against a column the model does not cast to
     * boolean, which can never hold. The save refuses it; this says so while it
     * is being typed, when it can still be changed.
     */
    booleanMisfit(rule) {
        return rule.kind === 'value'
            && (rule.value === 'true' || rule.value === 'false')
            && ! this.source.booleans.includes(rule.column)
    },

    /**
     * The other direction: anything but `true` or `false` against a column the
     * model DOES cast to boolean can never hold either, because warden's rule —
     * `Group::unsatisfiableColumns()` — is a biconditional.
     *
     * An empty value is left alone: it is a rule still being typed, not a
     * mistake, and the save refuses it all the same.
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
 * There are no three alternatives here: ownership is a field of its own on
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

        only: 'all',

        tab: grid.tabs.length > 0 ? grid.tabs[0].key : null,

        // One term for the whole grid rather than one per tab, because there is
        // exactly one matrix tab by construction: `GridView::matrix()` is
        // private and called once. The doors tabs draw no filter.
        filter: '',

        selected: null,

        // Whether the panel is on the page at all. Separate from `selected` on
        // purpose: a cell stays selected when the panel closes, so the grid keeps
        // showing WHICH cell was being read.
        panel: false,

        // And whether it is showing more than one line. A third flag rather than
        // a second meaning on `panel`, because they answer different questions:
        // `panel` is "is there an answer on screen", `expanded` is "is somebody
        // working on it". Collapsing keeps the answer and the selection; closing
        // gives the focus back to the cell.
        //
        // It survives a click on another cell on purpose: somebody comparing two
        // rules wants the second one open the way the first was, and re-opening
        // by hand on every cell is the shape this panel exists to avoid.
        expanded: false,

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
        // `this.selected` back against the object assigned to it never matches,
        // on the very first uncontested click, with no race anywhere near it. A
        // number is never wrapped, so its identity survives the round trip
        // through reactive state.
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
         * the click handler is not, so every click would cost two round trips
         * into markup that was never rendered. The answer is dropped when a
         * faster click already replaced it, by the `asked` token and never by
         * `this.selected`, for the reason given where `asked` is declared. And a
         * rejected call says so, rather than leaving the header over an empty
         * body.
         */
        async select(row, action, label, name) {
            if (! this.grid.explain && ! this.grid.constraints) {
                return
            }

            // `name` is kept raw as well as folded into `subtitle`: the inspector
            // hands it to `drawn()`, `reached()` and `stateOf()`, which key a
            // rule written over every entity by its catalogue name.
            this.selected = { row, action, title: label, subtitle: name ?? row, name }
            this.panel = true
            this.why = null
            this.narrowing = null
            this.failed = false
            this.loading = true

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
                // answer, and it is truthy here, so it becomes null before any
                // `why` test in the template can take it for an answer.
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

        drawn(row, action, name) {
            const stance = this.stanceOf(row, action)

            if (stance !== this.grid.order[0]) {
                return stance
            }

            return this.reached(row, action, name) === null ? stance : 'broader'
        },

        reached(row, action, name) {
            const candidates = [
                action === this.grid.manage ? null : this.stanceOf(row, this.grid.manage),
                this.grid.wider['*'] ?? null,
                this.grid.wider[name ?? action] ?? null,
                this.inheritedAt(row, action)?.stance ?? null,
            ].filter((stance) => stance !== null && stance !== this.grid.order[0])

            if (candidates.length === 0) {
                return null
            }

            return candidates.includes(this.grid.order[2]) ? this.grid.order[2] : this.grid.order[1]
        },

        decisionEnabled() {
            if (! this.interactive || ! this.selected) return false
            const { row, action } = this.selected
            return action === this.grid.manage
                ? this.grid.rows[row]?.manage === true
                : (this.grid.rows[row]?.actions ?? []).includes(action)
        },

        setDecision(stance) {
            if (! this.decisionEnabled() || ! this.grid.order.includes(stance)) return
            this.write(this.selected.row, this.selected.action, stance)
        },

        stepDecision(group, step) {
            const buttons = Array.from(group.querySelectorAll('[role="radio"]:not([disabled])'))
            if (buttons.length === 0) return
            const at = buttons.indexOf(document.activeElement)
            const next = buttons[(at + step + buttons.length) % buttons.length]
            next.click()
            next.focus()
        },

        cycle(row, action, backwards = false) {
            const order = this.grid.order
            const step = backwards ? order.length - 1 : 1
            const next = order[(order.indexOf(this.stanceOf(row, action)) + step) % order.length]

            this.write(row, action, next)
        },

        /**
         * Every preset puts each cell it touches back to every row: an "all" that
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
         * What the filter's term matches, rows and columns both.
         *
         * It reads `grid`, which PHP filled and nothing ever writes, and NEVER
         * `state`. That is the whole safety property of this feature, not a
         * style: a filter that pruned `state.stances` would send a payload with
         * an entity missing, and `RoleGrants::plan()` walks the CATALOGUE, so a
         * missing cell arrives as an abstention against a baseline that still
         * has it and is written as a deliberate revoke — with a success report
         * on top. It cannot be defended against downstream either: clearing a
         * cell deletes its key too, so the two are byte for byte the same
         * payload. Two tests in `RoleGrantsTest` pin what that costs, and
         * `verify/verify-filter-keeps-state.mjs` drives the boundary.
         *
         * Both readings filter through it, so the table and the fold cannot
         * disagree about which rows exist.
         */
        axis() {
            const term = this.filter.trim().toLowerCase()
            if (this._axis?.term === term) return this._axis
            const columns = this.grid.axisColumns ?? {}
            this._axis = {
                term,
                rows: Object.keys(this.grid.rows).filter((key) => {
                    const row = this.grid.rows[key]
                    return (row.label + ' ' + (row.model ?? '')).toLowerCase().includes(term)
                }),
                actions: Object.keys(columns).filter((key) => (key + ' ' + columns[key].label).toLowerCase().includes(term)),
            }
            return this._axis
        },

        shown(row) {
            const found = this.grid.rows[row] ?? {}
            if (this.only !== 'all' && ! found.has?.[this.only]) return false
            const axis = this.axis()
            if (axis.term === '' || axis.rows.includes(row)) return true
            return axis.rows.length === 0 && axis.actions.length > 0
                && (found.cells ?? []).some((cell) => axis.actions.includes(cell.action))
        },

        shownAction(action) {
            if (action === this.grid.manage) return true
            const axis = this.axis()
            return axis.term === '' || axis.actions.length === 0 || axis.actions.includes(action)
        },

        shownIn(scope) {
            return Object.entries(this.grid.axisColumns ?? {}).filter(([action, column]) => column.scope === scope && this.shownAction(action)).length
        },

        narrowingColumns() {
            return this.axis().term !== '' && this.axis().actions.length > 0
        },

        filtering() {
            return this.filter.trim() !== '' || this.only !== 'all'
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
         * `verify/verify-filter-keeps-state.mjs` drives it.
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
         * Looked up, never composed: `grid.states` is keyed by the stance value
         * `answers()` returns, so nothing here has to know what a stance is
         * called — and a test pins that the script carries no stance name.
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

        /**
         * When the grant behind this cell stops, as an ISO 8601 string — the
         * full one `RoleState::toPayload()` sends, or the bare date the input
         * writes back — or null.
         *
         * Never compared here: whether a date has passed is already answered by
         * the stance beside it, decided against the SERVER's clock, and this
         * machine's is not ours to trust.
         */
        untilAt(row, action) {
            return (this.state.until?.[row] ?? {})[action] ?? null
        },

        /**
         * The role lending this cell its answer, or null.
         *
         * `RoleGrants::inheritedCells()` leaves out every cell the store already
         * answers with a rule of its own.
         */
        inheritedAt(row, action) {
            return (this.state.inherited?.[row] ?? {})[action] ?? null
        },

        timeMark(row, action) {
            return this.untilAt(row, action) === null ? '' : this.grid.states.expires
        },

        lentMark(row, action) {
            return this.inheritedAt(row, action) === null ? '' : this.grid.states.inherited
        },

        write(row, action, stance) {
            const held = { ...(this.state.stances?.[row] ?? {}) }

            if (stance === this.grid.order[0]) {
                delete held[action]
            } else {
                held[action] = stance
            }

            this.state = { ...this.state, stances: this.replace(this.state.stances, row, held) }

            // Said here rather than in `pick()`, so a preset and the decision
            // buttons announce too; and not in `select()`, which returns early
            // when the inspector and the builder are both off.
            //
            // After the write, so `spoken()` reads the new state. The cell is
            // not named: a click leaves the focus on it, and a preset writes
            // several cells of a row at once, where naming the last of them
            // would be worse than naming none.
            this.said = this.spoken(row, action)
        },

        /**
         * The cell's own words, in the cell's own order.
         *
         * `box.blade.php` binds its word spans to these same methods, in this
         * order — the label and a locked cell's fixed word aside — and the
         * accessibility tree joins them itself; joining them the same
         * way here keeps one cell from having two descriptions on one screen.
         * Taking `grid.states[stance]` instead would be one of those: a cell
         * cleared under a granting wildcard answers "granted" and carries
         * "reached by a broader rule" beside it, where the stance alone says
         * "no rule".
         */
        spoken(row, action) {
            const name = (this.grid.rows[row]?.cells ?? []).find((cell) => cell.action === action)?.name

            return [
                this.stateOf(row, action, name),
                this.reachedMark(row, action, name),
                this.markOf(row, action),
                this.timeMark(row, action),
                this.lentMark(row, action),
            ].filter((word) => word !== '' && word !== undefined).join(' ')
        },

        /**
         * The one line the collapsed bar says.
         *
         * What the cell answers on screen plus, when there is one, the date —
         * which is the pair somebody reads a cell for. Not the cause: that is a
         * sentence, and a sentence in a bar that must stay one line is a
         * sentence read to its ellipsis.
         *
         * Composed here and not on the server for the reason every other reading
         * in this file is: the bar has to be right the instant a cell is clicked,
         * and the server has not been asked yet.
         */
        gist() {
            if (! this.selected) {
                return ''
            }

            const stance = this.stateOf(this.selected.row, this.selected.action, this.selected.name)
            const ends = this.timeMark(this.selected.row, this.selected.action)

            return ends ? `${stance} · ${ends}` : stance
        },

        /**
         * Shuts the panel, keeping the selection, and gives the focus back.
         *
         * Returning the focus is not a nicety. The panel is opened by clicking a
         * cell and closed by a button INSIDE it, so without this the focus is
         * left on an element that has just been hidden — and a hidden element
         * with focus drops the caret to the top of the document, which for
         * somebody on a keyboard means starting the whole page again.
         *
         * The root arrives as an argument, as `stepTab()`'s list does, so the
         * lookup starts from an element the markup handed over: `$root` is
         * Alpine's magic for the component's outermost element, which holds the
         * grid and the panel both. A cell that cannot be found leaves the focus
         * where it is, which beats moving it somewhere arbitrary.
         */
        closePanel(root) {
            this.panel = false
            this.expanded = false

            if (! root || ! this.selected) {
                return
            }

            // Walked and compared, never built into a selector. A row key is a
            // fully qualified class name — backslashes and all — and quoting one
            // into an attribute selector is exactly the kind of escaping that
            // works until somebody's namespace has the wrong character in it.
            // `stepTab()` reads `dataset` for the same reason.
            const cell = Array.from(root.querySelectorAll('[data-fw-row]')).find(
                (one) => one.dataset.fwRow === this.selected.row && one.dataset.fwAction === this.selected.action,
            )

            if (cell) {
                cell.focus()
            }
        },

        /**
         * Whether this cell can carry an end date at all.
         *
         * Warden REFUSES a date on a prohibition — `until()` on a forbid throws,
         * `null` included — and an abstention, a cell answered by something
         * wider or inherited among them, has no row of its own to end.
         * `untilReason()` says which, so a disabled control always carries its
         * reason.
         *
         * Advisory, not the guarantee: the payload of a disabled control still
         * reaches the server, so `RoleGrants::plan()` asks the same question
         * again and drops the date whatever arrives.
         */
        untilEnabled(row, action) {
            return this.grid.expiry && this.stanceOf(row, action) === this.grid.order[1]
        },

        untilReason(row, action) {
            if (! this.grid.expiry) {
                return this.grid.until.off
            }

            const stance = this.stanceOf(row, action)

            if (stance === this.grid.order[2]) {
                return this.grid.until.forbidden
            }

            return stance === this.grid.order[1] ? '' : this.grid.until.unwritten
        },

        /**
         * Write a date, or take it away.
         *
         * Through the same spread `write()` uses, and for the same reason: it
         * carries every key this file does not know about, `baseline` among
         * them. Rebuilding the object from the keys this file DOES know would
         * drop the baseline on the first click, and a save with no baseline
         * treats every cell as touched.
         */
        setUntil(row, action, value) {
            if (! this.untilEnabled(row, action)) {
                return
            }

            const held = { ...(this.state.until?.[row] ?? {}) }

            if (value === '' || value === null) {
                delete held[action]
            } else {
                held[action] = value
            }

            this.state = { ...this.state, until: this.replace(this.state.until, row, held) }

            this.said = this.spoken(row, action)
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
         * a `tab` that matched nothing lands at -1, and one step from there still
         * indexes a real button.
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
         * What the STORE held for the selected cell when this screen opened,
         * read off the baseline, so nothing is asked of the server for it.
         *
         * A host that never sent one — the infolist's payload carries no
         * baseline, having no save to compare against — has nothing to read
         * there: the live state IS the store's answer on that screen, by
         * construction. Keyed off the envelope's own absence and not off
         * `interactive`, so anywhere else it goes missing fails the same safe
         * way.
         */
        storedStance() {
            if (this.selected === null) {
                return this.grid.order[0]
            }

            const { row, action } = this.selected

            if (this.state.baseline === undefined) {
                return this.stanceOf(row, action)
            }

            return (this.state.baseline.stances?.[row] ?? {})[action] ?? this.grid.order[0]
        },

        /**
         * What this screen has changed in one cell since it opened, in the
         * cell's own words, or null when it has changed nothing there.
         *
         * Also null with no baseline to compare against, for the reason
         * `storedStance()` gives.
         */
        movedAt(row, action) {
            if (this.state.baseline === undefined) return null
            const baseline = this.state.baseline
            const was = baseline.stances?.[row]?.[action] ?? this.grid.order[0]
            const now = this.stanceOf(row, action)
            const reach = JSON.stringify(baseline.narrowing?.[row]?.[action] ?? { mode: 'all', rules: [] })
                !== JSON.stringify(this.state.narrowing?.[row]?.[action] ?? { mode: 'all', rules: [] })
            const until = (baseline.until?.[row]?.[action] ?? null) !== (this.state.until?.[row]?.[action] ?? null)
            return was === now && ! reach && ! until ? null : { from: this.grid.states[was], to: this.grid.states[now], reach, until }
        },

        moved() {
            return this.selected === null || this.state.baseline === undefined ? null : this.movedAt(this.selected.row, this.selected.action)
        },

        pending() {
            const changes = []
            Object.entries(this.grid.rows).forEach(([row, data]) => {
                data.cells.forEach(({ action, name }) => {
                    const change = this.movedAt(row, action)
                    if (change) changes.push({ row, action, name, label: data.label, ...change })
                })
            })
            return changes
        },

        /** The catalogue row warden matched, when it named one. */
        matchedName() {
            return this.why?.permission ?? ''
        },

        storedRule() {
            return this.storedRuleWorthSaying() ? this.narrowing.stored.preview : ''
        },

        /**
         * The stored rule, said only when it says something: with no builder,
         * or when the screen no longer shows what the store holds. With the
         * builder open and untouched, the stored rule and the builder's preview
         * are one fact twice.
         */
        storedRuleWorthSaying() {
            const stored = this.narrowing?.stored?.preview

            if (! stored) {
                return false
            }

            return ! this.offered() || stored !== (this.rules().length > 0 ? this.preview() : '')
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
         * Asked once and read twice: the markup binds `disabled` to it and
         * `stepReach()` skips whatever is disabled, so the arrows skip exactly
         * what the buttons refuse.
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
         * The rendered buttons are the list, as in `stepTab()`.
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

        /* The two names the shared half reads; the grid keeps its own so
           nothing else has to move. */
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
         * a model behind it: warden refuses a condition on a permission with no
         * entity, since there is no instance to test it against.
         *
         * The config check is the first clause, inside the predicate rather than
         * ahead of it in the template's `x-if`, where only the order of two
         * operands would keep it a guard. With the builder switched off
         * `narrowing` is `[]`, and `[].model` is `undefined`, which is
         * `!== null` — so every clause below would pass and
         * `narrowing.stored.locked` in the markup would throw.
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
