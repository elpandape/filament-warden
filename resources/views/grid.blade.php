{{--
    The grid and its inspector, shared by the screen that hands permissions out
    and the one that only reads them.

    The template decides nothing. Every condition below is a value the caller
    already worked out: `$interactive` says whether the cells are controls, and
    `$grid` is the whole view model.
--}}
@php
    // Worked out once: a matrix includes the cell partial once per cell, and
    // each word is a translator lookup. The id is the component's own key,
    // which is absolute — `form.permissions` — and therefore already unique on
    // the page, which is what a tab and its panel need to point at each other.
    $states = $grid->states();
    $ids = \ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView::domId($componentKey);
@endphp
<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('permission-grid', 'elpandape/filament-warden') }}"
    x-data="wardenPermissionGrid({
        state: {{ $binding }},
        grid: @js($grid->alpine() + ['key' => $componentKey]),
        interactive: @js($interactive),
    })"
    class="fw-grid"
>
    <div class="fw-layout">
        <div class="fw-main">
                @if ($grid->isProtected)
                    <p class="fw-locked-notice">{{ __('filament-warden::ui.grid.locked') }}</p>
                @endif

                @if ($grid->isReadOnly())
                    <p class="fw-read-only-notice">{{ __('filament-warden::ui.grid.read_only') }}</p>
                @endif

                @if ($grid->mixing())
                    <p class="fw-note fw-note-locked">{{ __('filament-warden::ui.grid.mixing') }}</p>
                @endif

                @if ($grid->wider !== [])
                    <p class="fw-wider">
                        {{ __('filament-warden::ui.grid.wider') }}
                        @foreach ($grid->wider as $name => $stance)
                            <span class="fw-box" data-state="{{ $stance }}" aria-hidden="true"></span>
                            <code>{{ $name }}</code>
                        @endforeach
                    </p>
                @endif

                @if ($grid->records !== [])
                    <p class="fw-records">
                        {{ __('filament-warden::ui.grid.records') }}
                        @foreach ($grid->records as $pinned)
                            <span class="fw-record">
                                <span class="fw-box" data-state="{{ $pinned->stance->value }}" aria-hidden="true"></span>
                                <code>{{ $pinned->name }}</code>
                                <code>{{ $pinned->model }}</code>
                                <span class="fw-record-id">#{{ $pinned->id }}</span>
                                @if ($pinned->reach() !== null)
                                    <span class="fw-record-reach">{{ __('filament-warden::ui.reach.'.$pinned->reach()) }}</span>
                                @endif
                            </span>
                        @endforeach
                    </p>
                @endif

                {{--
                    A tablist is ONE tab stop and the arrows walk it: that is the
                    pattern, and it is also the only way the panel below is
                    reachable without tabbing past every tab first. Without
                    javascript the tabs never switched anyway — the click handler
                    is `x-on:click` — so the roving tabindex takes nothing away.
                --}}
                <div
                    class="fw-tabs"
                    role="tablist"
                    x-on:keydown.arrow-right.prevent="stepTab($el, 1)"
                    x-on:keydown.arrow-left.prevent="stepTab($el, -1)"
                    x-on:keydown.home.prevent="edgeTab($el, false)"
                    x-on:keydown.end.prevent="edgeTab($el, true)"
                >
                    @foreach ($grid->tabs as $tab)
                        <button
                            type="button"
                            role="tab"
                            class="fw-tab"
                            id="{{ $ids }}-tab-{{ $tab->key }}"
                            data-fw-tab="{{ $tab->key }}"
                            aria-controls="{{ $ids }}-panel-{{ $tab->key }}"
                            x-on:click="tab = @js($tab->key)"
                            x-bind:aria-selected="tab === @js($tab->key) ? 'true' : 'false'"
                            x-bind:tabindex="tab === @js($tab->key) ? 0 : -1"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                            tabindex="{{ $loop->first ? '0' : '-1' }}"
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
                    <div
                        class="fw-panel"
                        role="tabpanel"
                        id="{{ $ids }}-panel-{{ $tab->key }}"
                        aria-labelledby="{{ $ids }}-tab-{{ $tab->key }}"
                        x-show="tab === @js($tab->key)"
                        @unless ($loop->first) x-cloak @endunless
                    >
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
                                                    <span class="fw-shortcuts" @unless ($interactive) hidden @endunless>
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
                                                            @include('filament-warden::box', ['cell' => $cell, 'label' => $row->label.' · '.$cell->label, 'interactive' => $interactive, 'states' => $states])
                                                        @else
                                                            <span class="fw-void" title="{{ __('filament-warden::ui.grid.undeclared') }}"><span aria-hidden="true">·</span><span class="fw-sr">{{ $states['undeclared'] }}</span></span>
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
                                            @include('filament-warden::box', ['cell' => $cell, 'label' => $row->label, 'interactive' => $interactive, 'states' => $states])
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
                    @foreach ($grid->legend() as $item)
                        <span class="fw-legend-item">
                            @if ($item['void'])
                                <span class="fw-void" aria-hidden="true">·</span>
                            @else
                                <span
                                    class="fw-box"
                                    data-state="{{ $item['state'] }}"
                                    data-broader="{{ $item['broader'] }}"
                                    data-noted="{{ $item['noted'] ? 'true' : 'false' }}"
                                    data-locked="{{ $item['locked'] ? 'true' : 'false' }}"
                                    aria-hidden="true"
                                ></span>
                            @endif
                            {{ $item['label'] }}
                        </span>
                    @endforeach
                    <span class="fw-legend-item fw-legend-shift">{{ __('filament-warden::ui.grid.shift') }}</span>
                </div>
        </div>

        @if ($grid->alpine()['explain'] || $grid->alpine()['constraints'])
        <aside class="fw-inspector">
            <div class="fw-inspector-head">
                <div class="fw-inspector-title" x-text="selected ? selected.title : @js(__('filament-warden::ui.explain.title'))"></div>
                <div class="fw-inspector-sub" x-text="selected ? selected.subtitle : ''"></div>
            </div>

            <div class="fw-inspector-body">
                {{--
                    Empty from the first paint on purpose: a live region added to
                    the page at the same moment its content appears is not
                    announced by NVDA or JAWS. What makes it reliable is that the
                    element is already here and only its TEXT changes — which is
                    also why it carries the failure sentence itself instead of
                    leaving it to the paragraph below, whose announcement would
                    depend on a `display` toggle. It says the verdict and not the
                    whole panel, so a keystroke in the condition builder stays
                    silent. `why` is already null or a real answer by the time it
                    is read: `select()` normalises the empty payload.
                --}}
                <p class="fw-sr" role="status" x-text="failed ? @js(__('filament-warden::ui.explain.failed')) : (why ? why.summary : '')"></p>

                <p class="fw-inspector-empty" x-show="! selected">{{ __('filament-warden::ui.explain.empty') }}</p>
                <p class="fw-inspector-empty" x-show="selected && loading" x-cloak>{{ __('filament-warden::ui.explain.loading') }}</p>
                <p class="fw-inspector-empty fw-inspector-failed" x-show="selected && failed && ! loading" x-cloak>{{ __('filament-warden::ui.explain.failed') }}</p>

                <template x-if="why && ! loading && grid.explain">
                    <div>
                        <div class="fw-why" x-bind:data-verdict="why.verdict">
                            <span x-text="why.summary"></span>
                            <code class="fw-why-cause" x-show="why.cause" x-text="why.cause"></code>
                        </div>
                        <p class="fw-note" x-show="why.narrowed" x-text="why.narrowed" x-cloak></p>
                        <p class="fw-note fw-note-pending" x-show="why.pending" x-text="why.pending" x-cloak></p>
                    </div>
                </template>

                @include('filament-warden::builder')
            </div>
        </aside>
        @endif
    </div>
</div>
