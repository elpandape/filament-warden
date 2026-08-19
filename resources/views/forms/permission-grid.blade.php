{{--
    The template decides nothing. Every condition below is a value the view model
    already worked out, because the coverage gate measures `src/` and never this
    file: a rule that lived here would be a rule nothing verifies.
--}}
@php($grid = $getGrid())

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fw-grid">
        <div class="fw-tabs" role="tablist">
            @foreach ($grid->tabs as $tab)
                <button type="button" role="tab" class="fw-tab" data-fw-tab="{{ $tab->key }}">
                    {{ $tab->label }}
                    <span class="fw-tally" data-on="{{ $tab->granted() > 0 ? 'true' : 'false' }}">{{ $tab->granted() }}</span>
                </button>
            @endforeach
        </div>

        @foreach ($grid->tabs as $tab)
            <div class="fw-panel" data-fw-panel="{{ $tab->key }}">
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
                                        @disabled($cell->narrowed)
                                        aria-label="{{ $row->label }}"
                                    ></button>
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
    </div>
</x-dynamic-component>
