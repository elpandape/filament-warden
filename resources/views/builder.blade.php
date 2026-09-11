{{--
    The condition builder, inside the inspector.
--}}
<template x-if="offered()">
    <div class="fw-builder">
        <div class="fw-field-label" id="{{ $ids }}-reach">{{ __('filament-warden::ui.conditions.scope') }}</div>

        {{--
            A radiogroup and not three buttons: one tab stop, and the arrows
            walk it.
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
                    x-bind:aria-describedby="mode === 'owned' && narrowing.ownership.reason
                        ? '{{ $ids }}-reach-hint {{ $ids }}-reach-reason'
                        : '{{ $ids }}-reach-hint'"
                    aria-describedby="{{ $ids }}-reach-hint"
                ></button>
            </template>
        </div>

        {{--
            One hint, the chosen mode's, pointed at by every option: selection
            follows focus here, so it always describes the focused radio.

            A locked cell's shape is none of the three `modes` offers, so the
            slot carries the stored note instead, the sentence that says why it
            is locked — never null there, since only the editable shapes are
            built without a reason.
        --}}
        <p class="fw-reach-hint" id="{{ $ids }}-reach-hint" x-text="grid.modes[reachOf()] ? grid.modes[reachOf()].hint : narrowing.stored.note"></p>

        {{--
            Why an option cannot be picked is the description of that option
            alone, and only while there is a reason: describing "Every row"
            with why "Only what it owns" is unavailable would be worse than
            saying nothing. There is a reason only while that option is
            disabled, so the description reaches only whoever reads the option
            in place: a disabled option takes no focus, and `stepReach()` steps
            over it.
        --}}
        <p
            class="fw-reach-reason"
            id="{{ $ids }}-reach-reason"
            x-show="narrowing.ownership.reason"
            x-text="narrowing.ownership.reason"
            x-cloak
        ></p>

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
