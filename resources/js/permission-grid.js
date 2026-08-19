/**
 * The permission grid.
 *
 * It carries no rules of its own. The cycle order, which actions each row
 * offers, which of them are reads, the six operators and every word the builder
 * says all arrive from PHP in `grid`, because the generation before this one
 * implemented the same precedence twice — once in PHP and once here — and
 * nothing could tell when the two disagreed.
 *
 * The one exception is the clause cut, and it is marked where it happens.
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

        selected: null,

        why: null,

        narrowing: null,

        loading: false,

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
         */
        async select(row, action, label, name) {
            this.selected = { row, action, title: label, subtitle: name ?? row }
            this.why = null
            this.narrowing = null
            this.loading = true

            try {
                const [why, narrowing] = await Promise.all([
                    this.$wire.callSchemaComponentMethod(this.grid.key, 'explainCell', { row, action }),
                    this.$wire.callSchemaComponentMethod(this.grid.key, 'narrowingFor', { row, action }),
                ])

                this.why = why
                this.narrowing = narrowing
            } finally {
                this.loading = false
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

        granted(tabKey) {
            const tab = this.grid.tabs.find((candidate) => candidate.key === tabKey)

            if (tab === undefined) {
                return 0
            }

            return tab.rows.reduce(
                (total, row) => total + Object.values(this.state.stances?.[row] ?? {})
                    .filter((stance) => stance === this.grid.order[1]).length,
                0,
            )
        },

        write(row, action, stance) {
            const held = { ...(this.state.stances?.[row] ?? {}) }

            if (stance === this.grid.order[0]) {
                delete held[action]
            } else {
                held[action] = stance
            }

            this.state = { ...this.state, stances: this.replace(this.state.stances, row, held) }
        },

        /* ── How far a cell reaches ─────────────────────────────────────── */

        narrowedAt(row, action) {
            return this.narrowingAt(row, action).mode !== 'all'
        },

        narrowingAt(row, action) {
            return (this.state.narrowing?.[row] ?? {})[action] ?? { mode: 'all', rules: [] }
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
         */
        offered() {
            return this.selected !== null
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
