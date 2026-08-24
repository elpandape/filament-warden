{{--
    The condition builder, inside the inspector.

    It is offered on a cell that says something — abstaining is the absence of a
    row, and a row that does not exist has no reach — and whose row has a model
    behind it: a permission with no model and conditions on it is created, shown,
    and grants nothing ever.

    Everything here is drawn by the browser, because what it draws is unsaved.
    The words all arrive from PHP; the only rule this file's script decides is
    the clause cut, and `Narrowing::clauses()` is its authority.

    Whether the installation offers a builder at all is `offered()`'s first
    question, not this file's. A gate written here reads a value the browser is
    holding: with the builder switched off the payload is `[]`, every property
    read off it is `undefined`, and `undefined !== null` passes — so the only
    thing that stopped a TypeError was which operand came first.
--}}
<template x-if="offered()">
    <div class="fw-builder">
        <div class="fw-field-label" id="{{ $ids }}-reach">{{ __('filament-warden::ui.conditions.scope') }}</div>

        {{--
            A radiogroup and not three buttons: one tab stop, and the arrows walk
            it. Which of the three can be reached is `reachEnabled()`, asked once
            — the markup binds `disabled` to it and the arrow keys step over it,
            so the rule is not written twice inside one component.
        --}}
        <div
            class="fw-reach"
            role="radiogroup"
            aria-labelledby="{{ $ids }}-reach"
            x-bind:data-locked="narrowing.stored.locked ? 'true' : 'false'"
            x-on:keydown.arrow-right.prevent="stepReach($el, 1)"
            x-on:keydown.arrow-down.prevent="stepReach($el, 1)"
            x-on:keydown.arrow-left.prevent="stepReach($el, -1)"
            x-on:keydown.arrow-up.prevent="stepReach($el, -1)"
        >
            <template x-for="mode in ['all', 'owned', 'conditions']" :key="mode">
                <button
                    type="button"
                    class="fw-reach-option"
                    role="radio"
                    x-bind:data-fw-mode="mode"
                    x-bind:aria-checked="reachOf() === mode ? 'true' : 'false'"
                    x-bind:tabindex="reachStop() === mode ? 0 : -1"
                    x-bind:disabled="! reachEnabled(mode)"
                    x-on:click="setMode(mode)"
                    x-text="grid.modes[mode].name"
                ></button>
            </template>
        </div>

        {{--
            One hint, the chosen mode's. A locked cell has no entry in `modes` —
            `reachOf()` answers with the stored `Shape`, which has six cases to
            that map's three — so the slot carries the stored note instead, which
            is the sentence that says WHY it is locked. It is never null when
            locked: the three editable shapes are the only ones built without a
            reason.
        --}}
        <p class="fw-reach-hint" x-text="grid.modes[reachOf()] ? grid.modes[reachOf()].hint : narrowing.stored.note"></p>

        {{--
            And why an option cannot be picked lives out here, not inside it: a
            disabled button cannot be focused, so said in there a keyboard would
            never reach it.
        --}}
        <p class="fw-reach-reason" x-show="narrowing.ownership.reason" x-text="narrowing.ownership.reason" x-cloak></p>

        {{--
            The rule as the store has it, written out by PHP.

            Not the shared list of controls: a `<select>` has no option for a
            column the table no longer has, so the browser would fall back to
            the first one and a rule stored as `subtitle = alpha` would draw as
            `id = alpha`. `Rule::text()` prints what is stored.

            It never doubles the pending preview below: that one lives inside
            the conditions partial, which is drawn only when the cell is NOT
            locked, and this one only when it is.
        --}}
        <code
            class="fw-preview"
            x-show="narrowing.stored.locked && narrowing.stored.preview !== ''"
            x-text="narrowing.stored.preview"
            x-cloak
        ></code>

        <template x-if="modeOf() === 'conditions' && ! narrowing.stored.locked">
            @include('filament-warden::conditions')
        </template>
    </div>
</template>
