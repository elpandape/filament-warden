{{--
    The condition builder, inside the inspector.

    It is offered on a cell that says something — abstaining is the absence of a
    row, and a row that does not exist has no reach — and whose row has a model
    behind it: a permission with no model and conditions on it is created, shown,
    and grants nothing ever.

    Everything here is drawn by the browser, because what it draws is unsaved.
    The words all arrive from PHP; the only rule this file's script decides is
    the clause cut, and `Narrowing::clauses()` is its authority.
--}}
<template x-if="grid.constraints && offered()">
    <div class="fw-builder">
        <div class="fw-field-label">{{ __('filament-warden::ui.conditions.scope') }}</div>

        <div class="fw-modes">
            <template x-for="mode in ['all', 'owned', 'conditions']" :key="mode">
                <button
                    type="button"
                    class="fw-mode"
                    x-bind:data-on="modeOf() === mode ? 'true' : 'false'"
                    x-bind:disabled="! interactive || narrowing.stored.locked || (mode === 'owned' && ! narrowing.ownership.available)"
                    x-on:click="setMode(mode)"
                >
                    <span class="fw-mode-name" x-text="grid.modes[mode].name"></span>
                    <span
                        class="fw-mode-hint"
                        x-text="mode === 'owned' && ! narrowing.ownership.available
                            ? narrowing.ownership.reason
                            : grid.modes[mode].hint"
                    ></span>
                </button>
            </template>
        </div>

        <p class="fw-note fw-note-locked" x-show="narrowing.stored.locked" x-text="narrowing.stored.note" x-cloak></p>

        <template x-if="modeOf() === 'conditions' && ! narrowing.stored.locked">
            @include('filament-warden::conditions')
        </template>
    </div>
</template>
