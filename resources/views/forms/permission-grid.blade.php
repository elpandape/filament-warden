{{--
    The template decides nothing. Every condition below is a value the view model
    already worked out, because the coverage gate measures `src/` and never this
    file: a rule that lived here would be a rule nothing verifies.

    What the server renders is also the answer without javascript: each cell
    carries the stance it holds today. Alpine takes over the same attributes.
--}}
@php
    $grid = $getGrid();
    $alpine = $grid->alpine();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('permission-grid', 'elpandape/filament-warden') }}"
        x-data="wardenPermissionGrid({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$getStatePath()}')") }},
            grid: @js($alpine),
        })"
        class="fw-grid"
    >
        <div class="fw-tabs" role="tablist">
            @foreach ($grid->tabs as $tab)
                <button
                    type="button"
                    role="tab"
                    class="fw-tab"
                    x-on:click="tab = @js($tab->key)"
                    x-bind:aria-selected="tab === @js($tab->key) ? 'true' : 'false'"
                    aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                >
                    {{ $tab->label }}
                    <span
                        class="fw-tally"
                        x-bind:data-on="granted(@js($tab->key)) > 0 ? 'true' : 'false'"
                        x-text="granted(@js($tab->key))"
                        data-on="{{ $tab->granted() > 0 ? 'true' : 'false' }}"
                    >{{ $tab->granted() }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($grid->tabs as $tab)
            <div class="fw-panel" role="tabpanel" x-show="tab === @js($tab->key)" @unless ($loop->first) x-cloak @endunless>
                @if ($tab->matrix)
                    <div class="fw-scroll">
                        <table class="fw-table">
                            <thead>
                                <tr>
                                    <th class="fw-corner" rowspan="2" scope="col">{{ __('filament-warden::ui.grid.entity') }}</th>
                                    <th class="fw-manage" rowspan="2" scope="col">{{ __('filament-warden::ui.grid.manage') }}</th>
                                    @foreach ($grid->groups as $group)
                                        <th class="fw-group" data-scope="{{ $group->scope->value }}" colspan="{{ count($group->columns) }}" scope="colgroup">{{ $group->label }}</th>
                                    @endforeach
                                </tr>
                                <tr>
                                    @foreach ($grid->groups as $group)
                                        @foreach ($group->columns as $column)
                                            <th class="fw-action" data-scope="{{ $group->scope->value }}" scope="col">
                                                <span class="fw-action-label">{{ $column->label }}</span>
                                                <span class="fw-action-name">{{ $column->action }}</span>
                                            </th>
                                        @endforeach
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tab->rows as $row)
                                    <tr>
                                        <th class="fw-entity" scope="row">
                                            <span class="fw-entity-name">{{ $row->label }}</span>
                                            <span class="fw-entity-model">{{ $row->model }}</span>
                                            <span class="fw-shortcuts">
                                                @foreach (['read', 'all', 'clear'] as $preset)
                                                    <button
                                                        type="button"
                                                        class="fw-shortcut"
                                                        x-on:click="apply(@js($row->key), @js($preset))"
                                                    >{{ __('filament-warden::ui.grid.presets.'.$preset) }}</button>
                                                @endforeach
                                            </span>
                                        </th>
                                        @foreach ($row->allCells() as $cell)
                                            <td class="fw-cell">
                                                @if ($cell->declared)
                                                    <button
                                                        type="button"
                                                        class="fw-box"
                                                        data-fw-row="{{ $cell->row }}"
                                                        data-fw-action="{{ $cell->action }}"
                                                        data-state="{{ $cell->stance->value }}"
                                                        x-bind:data-state="drawn(@js($cell->row), @js($cell->action))"
                                                        x-bind:data-broader="reached(@js($cell->row))"
                                                        x-on:click="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                                        x-on:keydown.enter.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                                        x-on:keydown.space.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                                        @disabled($cell->narrowed)
                                                        aria-label="{{ $row->label }} · {{ $cell->label }}"
                                                    >
                                                        @if ($cell->narrowed)
                                                            <span class="fw-noted"></span>
                                                        @endif
                                                    </button>
                                                @else
                                                    <span class="fw-void" title="{{ __('filament-warden::ui.grid.undeclared') }}">·</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <ul class="fw-doors">
                        @foreach ($tab->rows as $row)
                            @foreach ($row->cells as $cell)
                                <li class="fw-door">
                                    <button
                                        type="button"
                                        class="fw-box"
                                        data-fw-row="{{ $cell->row }}"
                                        data-fw-action="{{ $cell->action }}"
                                        data-state="{{ $cell->stance->value }}"
                                        x-bind:data-state="drawn(@js($cell->row), @js($cell->action))"
                                        x-on:click="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                        x-on:keydown.enter.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                        x-on:keydown.space.prevent="cycle(@js($cell->row), @js($cell->action), $event.shiftKey)"
                                        @disabled($cell->narrowed)
                                        aria-label="{{ $row->label }}"
                                    >
                                        @if ($cell->narrowed)
                                            <span class="fw-noted"></span>
                                        @endif
                                    </button>
                                    <span class="fw-door-text">
                                        <span class="fw-entity-name">{{ $row->label }}</span>
                                        <span class="fw-action-name">{{ $cell->entry?->name }}</span>
                                    </span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        <div class="fw-legend">
            @foreach (['abstains', 'granted', 'forbidden', 'broader', 'undeclared', 'narrowed'] as $item)
                <span class="fw-legend-item">{{ __('filament-warden::ui.grid.legend.'.$item) }}</span>
            @endforeach
            <span class="fw-legend-item">{{ __('filament-warden::ui.grid.shift') }}</span>
        </div>
    </div>
</x-dynamic-component>
