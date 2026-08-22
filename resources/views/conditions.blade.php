{{--
    The conditions themselves, shared by the cell inspector and the permission
    form. Each screen says where the rule lives; this only draws it.

    `words` is every sentence, worked out in PHP. `source` is what a condition
    can be built from here: the columns of the entity and of the account. The
    only rule this file's script decides is the clause cut, and its authority is
    `Narrowing::clauses()`.
--}}
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
                                <option :value="logic" :selected="rule.logic === logic" x-text="words.joiners[logic]"></option>
                            </template>
                        </select>

                        <select
                            class="fw-column"
                            x-bind:disabled="! interactive"
                            x-on:change="edit(rule.at, 'column', $event.target.value)"
                        >
                            <template x-for="column in source.columns" :key="column">
                                <option :value="column" :selected="rule.column === column" x-text="column"></option>
                            </template>
                        </select>

                        <select
                            class="fw-operator"
                            x-bind:disabled="! interactive"
                            x-on:change="edit(rule.at, 'operator', $event.target.value)"
                        >
                            <template x-for="operator in words.operators" :key="operator">
                                <option :value="operator" :selected="rule.operator === operator" x-text="operator"></option>
                            </template>
                        </select>

                        <select
                            class="fw-against"
                            x-show="rule.kind === 'column'"
                            x-bind:disabled="! interactive"
                            x-on:change="edit(rule.at, 'authority', $event.target.value)"
                        >
                            <template x-for="column in source.authority" :key="column">
                                <option
                                    :value="column"
                                    :selected="rule.authority === column"
                                    x-text="words.authority + '.' + column"
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

                        <span
                            class="fw-misfit"
                            x-show="booleanMisfit(rule)"
                            x-text="words.boolean"
                        ></span>

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
                x-bind:disabled="source.authority.length === 0"
                x-on:click="add('column')"
            >{{ __('filament-warden::ui.conditions.add_column') }}</button>
        </div>

        <p class="fw-note" x-show="rules().length === 0">{{ __('filament-warden::ui.conditions.empty') }}</p>

        <p class="fw-warn">{{ __('filament-warden::ui.conditions.warning') }}</p>

        <code class="fw-preview" x-show="rules().length > 0" x-text="preview()"></code>
    </div>
