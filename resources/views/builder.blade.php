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
<template x-if="offered()">
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
            <div class="fw-conditions">
                <template x-for="(clause, group) in clauses()" :key="group">
                    <div class="fw-clause">
                        <template x-for="rule in clause" :key="rule.at">
                            <div class="fw-condition">
                                <span class="fw-joiner" x-show="rule.at === 0">{{ __('filament-warden::ui.conditions.if') }}</span>

                                <select
                                    class="fw-joiner"
                                    x-show="rule.at > 0"
                                    x-bind:disabled="! interactive"
                                    x-on:change="edit(rule.at, 'logic', $event.target.value)"
                                >
                                    <template x-for="logic in ['and', 'or']" :key="logic">
                                        <option :value="logic" :selected="rule.logic === logic" x-text="grid.joiners[logic]"></option>
                                    </template>
                                </select>

                                <select
                                    class="fw-column"
                                    x-bind:disabled="! interactive"
                                    x-on:change="edit(rule.at, 'column', $event.target.value)"
                                >
                                    <template x-for="column in narrowing.columns" :key="column">
                                        <option :value="column" :selected="rule.column === column" x-text="column"></option>
                                    </template>
                                </select>

                                <select
                                    class="fw-operator"
                                    x-bind:disabled="! interactive"
                                    x-on:change="edit(rule.at, 'operator', $event.target.value)"
                                >
                                    <template x-for="operator in grid.operators" :key="operator">
                                        <option :value="operator" :selected="rule.operator === operator" x-text="operator"></option>
                                    </template>
                                </select>

                                <select
                                    class="fw-against"
                                    x-show="rule.kind === 'column'"
                                    x-bind:disabled="! interactive"
                                    x-on:change="edit(rule.at, 'authority', $event.target.value)"
                                >
                                    <template x-for="column in narrowing.authority" :key="column">
                                        <option
                                            :value="column"
                                            :selected="rule.authority === column"
                                            x-text="grid.authority + '.' + column"
                                        ></option>
                                    </template>
                                </select>

                                <input
                                    type="text"
                                    x-show="rule.kind === 'value'"
                                    x-bind:disabled="! interactive"
                                    x-bind:value="rule.value"
                                    x-on:input="edit(rule.at, 'value', $event.target.value)"
                                    placeholder="{{ __('filament-warden::ui.conditions.value') }}"
                                >

                                <button
                                    type="button"
                                    class="fw-drop"
                                    x-show="interactive"
                                    x-on:click="drop(rule.at)"
                                    aria-label="{{ __('filament-warden::ui.conditions.drop') }}"
                                >&times;</button>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="fw-adds" x-show="interactive">
                    <button type="button" class="fw-add" x-on:click="add('value')">
                        {{ __('filament-warden::ui.conditions.add_value') }}
                    </button>
                    <button
                        type="button"
                        class="fw-add"
                        x-bind:disabled="narrowing.authority.length === 0"
                        x-on:click="add('column')"
                    >{{ __('filament-warden::ui.conditions.add_column') }}</button>
                </div>

                <p class="fw-note" x-show="rules().length === 0">{{ __('filament-warden::ui.conditions.empty') }}</p>

                <p class="fw-warn">{{ __('filament-warden::ui.conditions.warning') }}</p>

                <code class="fw-preview" x-show="rules().length > 0" x-text="preview()"></code>
            </div>
        </template>
    </div>
</template>
