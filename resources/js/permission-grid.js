/**
 * The permission grid.
 *
 * It carries no rules of its own. The cycle order, which actions each row
 * offers, and which of them are reads all arrive from PHP in `grid`, because the
 * generation before this one implemented the same precedence twice — once in PHP
 * and once here — and nothing could tell when the two disagreed.
 */
export default function ({ state, grid }) {
    return {
        grid,

        state: state ?? {},

        tab: grid.tabs.length > 0 ? grid.tabs[0].key : null,

        selected: null,

        why: null,

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
         * The answer is worked out on the server, one cell at a time, and arrives
         * already written: nothing here composes a sentence.
         */
        async select(row, action, label, name) {
            this.selected = { row, action, title: label, subtitle: name ?? row }
            this.why = null
            this.loading = true

            try {
                this.why = await this.$wire.callSchemaComponentMethod(this.grid.key, 'explainCell', { row, action })
            } finally {
                this.loading = false
            }
        },

        /**
         * Abstaining is the absence of a key, never a value.
         */
        stanceOf(row, action) {
            return (this.state?.[row] ?? {})[action] ?? this.grid.order[0]
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
         */
        apply(row, preset) {
            const offered = this.grid.rows[row] ?? { actions: [], read: [] }
            const granted = this.grid.order[1]

            if (preset === 'read') {
                offered.read.forEach((action) => this.write(row, action, granted))

                return
            }

            if (preset === 'all') {
                this.write(row, this.grid.manage, granted)
                offered.actions.forEach((action) => this.write(row, action, granted))

                return
            }

            this.write(row, this.grid.manage, this.grid.order[0])
            offered.actions.forEach((action) => this.write(row, action, this.grid.order[0]))
        },

        granted(tabKey) {
            const tab = this.grid.tabs.find((candidate) => candidate.key === tabKey)

            if (tab === undefined) {
                return 0
            }

            return tab.rows.reduce(
                (total, row) => total + Object.values(this.state?.[row] ?? {})
                    .filter((stance) => stance === this.grid.order[1]).length,
                0,
            )
        },

        write(row, action, stance) {
            const held = { ...(this.state?.[row] ?? {}) }

            if (stance === this.grid.order[0]) {
                delete held[action]
            } else {
                held[action] = stance
            }

            // Replaced whole rather than mutated in place: livewire only notices
            // a change it can see on the entangled property itself.
            const next = { ...(this.state ?? {}) }

            if (Object.keys(held).length === 0) {
                delete next[row]
            } else {
                next[row] = held
            }

            this.state = next
        },
    }
}
