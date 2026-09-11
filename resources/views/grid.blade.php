{{--
    The grid and its inspector, shared by the screen that hands permissions out
    and the one that only reads them.
--}}
@php
    // `$states` is worked out once and handed to every cell: the partial is
    // included once per cell, and each word is a translator lookup.
    $states = $grid->states();
    $ids = \ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView::domId($componentKey);

    $showsClassNames = \ElPandaPe\FilamentWarden\Support\Config::enabled('grid.class_names');
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
    {{--
        Escape is heard here, on the element that holds both the matrix and the
        panel, and not on the panel: the panel is opened from a cell, which
        leaves the focus on the cell, outside the panel. Not on the window
        either: a keystroke elsewhere on a Filament page is not this
        component's to act on.
    --}}
    <div
        class="fw-layout"
        data-open="false"
        x-bind:data-open="panel ? 'true' : 'false'"
        x-on:keydown.escape="closePanel($root)"
    >
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
                            <span class="fw-sr">{{ $states[$stance] }}</span>
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
                                <span class="fw-sr">{{ $states[$pinned->stance->value] }}</span>
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
                    What a click just did, for everyone who is not looking at
                    the cell. The cell's own words change with it, but a name
                    that changes under a focus that never moved is not reliably
                    re-read by a screen reader.

                    On the page and empty from the first paint, never behind a
                    condition: NVDA and JAWS do not announce a live region that
                    arrives with its text already in it, so only its TEXT may
                    change. The filter's region below keeps the same rule.
                --}}
                <p class="fw-sr" role="status" x-text="said"></p>

                <details class="fw-legend-fold" data-fw-inline="true">
                    <summary>{{ __('filament-warden::ui.grid.legend.title') }}</summary>

                    <div class="fw-key">
                        {{--
                            Two groups, not one list: `legend()` lists first the
                            three drawings a click can set, and the shift hint
                            belongs with them rather than at the end; the rest
                            are marks the grid adds on its own.
                        --}}
                        <section class="fw-key-group">
                            <h4>{{ __('filament-warden::ui.grid.legend.set') }}</h4>
                            @foreach (array_slice($grid->legend(), 0, 3) as $item)
                                <span class="fw-legend-item">
                                    <span
                                        class="fw-box"
                                        data-state="{{ $item['state'] }}"
                                        data-broader="{{ $item['broader'] }}"
                                        data-noted="{{ $item['noted'] ? 'true' : 'false' }}"
                                        data-locked="{{ $item['locked'] ? 'true' : 'false' }}"
                                        aria-hidden="true"
                                    ></span>
                                    {{ $item['label'] }}
                                </span>
                            @endforeach
                            <span class="fw-legend-item fw-legend-shift">{{ __('filament-warden::ui.grid.shift') }}</span>
                        </section>

                        <section class="fw-key-group">
                            <h4>{{ __('filament-warden::ui.grid.legend.added') }}</h4>
                            @foreach (array_slice($grid->legend(), 3) as $item)
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
                        </section>
                    </div>
                </details>

                {{--
                    One tab stop, and the arrows walk it, so the panel below is
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
                            {{--
                                Above both readings, so one input answers for
                                the table and for the fold. It only ever decides
                                what is DRAWN. The state keeps every row: a
                                payload with an entity missing is written as a
                                revoke, because `RoleGrants::plan()` walks the
                                catalogue, not the payload.
                            --}}
                            <div class="fw-filter">
                                <input
                                    type="search"
                                    class="fw-filter-field"
                                    x-model="filter"
                                    aria-label="{{ __('filament-warden::ui.grid.filter.label') }}"
                                    placeholder="{{ __('filament-warden::ui.grid.filter.label') }}"
                                >
                                <div class="fw-only" role="radiogroup" aria-label="{{ __('filament-warden::ui.grid.only.label') }}" x-on:keydown.arrow-right.prevent="stepDecision($el, 1)" x-on:keydown.arrow-left.prevent="stepDecision($el, -1)">
                                    @foreach (['all', 'own', 'forbidden', 'narrowed', 'ending', 'inherited'] as $filter)
                                        <button type="button" role="radio" class="fw-only-chip" x-bind:aria-checked="only === @js($filter) ? 'true' : 'false'" x-bind:tabindex="only === @js($filter) ? 0 : -1" x-on:click="only = @js($filter)">{{ __('filament-warden::ui.grid.only.'.$filter) }}</button>
                                    @endforeach
                                </div>
                                <span class="fw-filter-count" x-show="filtering()" x-cloak x-text="filterCount(@js($tab->key))"></span>
                                {{--
                                    Said out loud as well as drawn: a filter that
                                    takes rows away without announcing it is a
                                    change nobody who cannot see the screen is
                                    told about.
                                --}}
                                <p class="fw-sr" role="status" x-text="! filtering() ? '' : filterCount(@js($tab->key))"></p>
                            </div>

                            <p class="fw-only-help" x-show="only !== 'all'" x-cloak>{{ __('filament-warden::ui.grid.only.stored') }}</p>
                            <p class="fw-filter-empty" x-show="matched(@js($tab->key)) === 0" x-cloak x-text="filterEmpty()"></p>
                            <div class="fw-scroll" x-show="matched(@js($tab->key)) > 0">
                                <table class="fw-table">
                                    <thead>
                                        <tr>
                                            <th class="fw-corner" rowspan="2" scope="col">{{ __('filament-warden::ui.grid.entity') }}</th>
                                            <th class="fw-manage" rowspan="2" scope="col">{{ __('filament-warden::ui.grid.manage') }}</th>
                                            @foreach ($grid->groups as $group)
                                                <th class="fw-group" data-scope="{{ $group->scope->value }}" colspan="{{ count($group->columns) }}" x-bind:colspan="shownIn(@js($group->scope->value))" x-show="shownIn(@js($group->scope->value)) > 0" scope="colgroup">{{ $group->label }}</th>
                                            @endforeach
                                        </tr>
                                        <tr>
                                            @foreach ($grid->groups as $group)
                                                @foreach ($group->columns as $column)
                                                    <th class="fw-action" x-show="shownAction(@js($column->action))" data-scope="{{ $group->scope->value }}" scope="col">
                                                        <span class="fw-action-label">{{ $column->label }}</span>
                                                        <span class="fw-action-name">{{ $column->action }}</span>
                                                    </th>
                                                @endforeach
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($tab->rows as $row)
                                            <tr x-show="shown(@js($row->key))">
                                                {{--
                                                    Named by its spans and not
                                                    by its contents, because the
                                                    preset buttons live in here
                                                    too: otherwise every row is
                                                    announced with the three
                                                    button labels glued to the
                                                    entity's name.

                                                    The class span is referenced
                                                    only when it is drawn: an
                                                    absent id is skipped
                                                    silently, but it is still a
                                                    promise the markup does not
                                                    keep, and the `title`
                                                    carries the class either way.
                                                --}}
                                                <th
                                                    class="fw-entity"
                                                    scope="row"
                                                    title="{{ $row->model }}"
                                                    aria-labelledby="{{ $ids }}-row-{{ $loop->index }}-name{{ $showsClassNames ? ' '.$ids.'-row-'.$loop->index.'-model' : '' }}"
                                                >
                                                    <span class="fw-entity-name" id="{{ $ids }}-row-{{ $loop->index }}-name">{{ $row->label }}</span>
                                                    @if ($showsClassNames)
                                                        <span class="fw-entity-model" id="{{ $ids }}-row-{{ $loop->index }}-model">{{ $row->model }}</span>
                                                    @endif
                                                    <span class="fw-shortcuts" x-show="! narrowingColumns()" @unless ($interactive) hidden @endunless>
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
                                                    <td class="fw-cell" x-show="shownAction(@js($cell->action))">
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

                            {{--
                                The same grid read down the page, for when the
                                columns do not fit across it; the stylesheet
                                paints one reading at a time. Every cell here is
                                the SAME partial with the same arguments as in
                                the table, so both copies bind to one state and
                                cannot drift apart. The notices, the tabs and
                                the legend sit above both readings, once.
                            --}}
                            <div class="fw-stack" x-show="matched(@js($tab->key)) > 0">
                                @foreach ($tab->rows as $row)
                                    <details class="fw-stack-entity" x-show="shown(@js($row->key))" @if ($loop->first) open @endif>
                                        <summary>
                                            <span class="fw-stack-name" title="{{ $row->model }}">{{ $row->label }}</span>
                                            @if ($showsClassNames)
                                                <span class="fw-stack-model">{{ $row->model }}</span>
                                            @endif
                                            <span class="fw-stack-summary" x-text="stackSummary(@js($row->key))">{{ $grid->summaryOf($row) }}</span>
                                        </summary>

                                        <div class="fw-stack-rows">
                                            {{--
                                                The presets again, because the
                                                table's copy is hidden with the
                                                table. In the body and never in
                                                the `<summary>`, where a click
                                                would toggle the disclosure.
                                            --}}
                                            <span class="fw-shortcuts fw-stack-shortcuts" x-show="! narrowingColumns()" @unless ($interactive) hidden @endunless>
                                                @foreach (['read', 'all', 'clear'] as $preset)
                                                    <button
                                                        type="button"
                                                        class="fw-shortcut"
                                                        x-on:click="apply(@js($row->key), @js($preset))"
                                                    >{{ __('filament-warden::ui.grid.presets.'.$preset) }}</button>
                                                @endforeach
                                            </span>

                                            @if ($row->manage instanceof \ElPandaPe\FilamentWarden\Filament\Forms\Grid\Cell)
                                                <div class="fw-stack-row">
                                                    <span class="fw-stack-label">{{ $row->manage->label }}<span class="fw-stack-action">{{ $row->manage->action }}</span></span>
                                                    @include('filament-warden::box', ['cell' => $row->manage, 'label' => $row->label.' · '.$row->manage->label, 'interactive' => $interactive, 'states' => $states])
                                                </div>
                                            @endif

                                            @foreach ($grid->groups as $group)
                                                <details class="fw-stack-scope" data-scope="{{ $group->scope->value }}" x-show="shownIn(@js($group->scope->value)) > 0">
                                                    <summary><span>{{ $group->label }}</span></summary>

                                                    @foreach ($row->inScope($group->scope) as $cell)
                                                        <div class="fw-stack-row" x-show="shownAction(@js($cell->action))">
                                                            <span class="fw-stack-label">{{ $cell->label }}<span class="fw-stack-action">{{ $cell->action }}</span></span>
                                                            @if ($cell->declared)
                                                                @include('filament-warden::box', ['cell' => $cell, 'label' => $row->label.' · '.$cell->label, 'interactive' => $interactive, 'states' => $states])
                                                            @else
                                                                <span class="fw-void" title="{{ __('filament-warden::ui.grid.undeclared') }}"><span aria-hidden="true">·</span><span class="fw-sr">{{ $states['undeclared'] }}</span></span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </details>
                                            @endforeach
                                        </div>
                                    </details>
                                @endforeach
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
        </div>

        @if ($interactive)
            <details class="fw-pending" x-show="pending().length > 0" x-cloak>
                <summary>{{ __('filament-warden::ui.grid.pending.title') }} <span x-text="pending().length"></span></summary>
                <p class="fw-only-help">{{ __('filament-warden::ui.grid.pending.help') }}</p>
                <template x-for="change in pending()" :key="change.row + '|' + change.action">
                    <button type="button" class="fw-pending-line" x-on:click="select(change.row, change.action, change.label, change.name); expanded = true">
                        <strong x-text="change.label"></strong> · <span x-text="grid.axisColumns[change.action]?.label ?? change.action"></span>:
                        <span x-text="change.from"></span> → <span x-text="change.to"></span>
                        <span x-show="change.reach">· {{ __('filament-warden::ui.grid.pending.reach') }}</span>
                        <span x-show="change.until">· {{ __('filament-warden::ui.grid.pending.until') }}</span>
                    </button>
                </template>
            </details>
        @endif

        @if ($grid->alpine()['explain'] || $grid->alpine()['constraints'])
        <aside
            class="fw-inspector fw-permission-editor"
            aria-label="{{ __('filament-warden::ui.explain.title') }}"
            data-fw-open="false"
            data-fw-expanded="false"
            x-bind:data-fw-open="panel ? 'true' : 'false'"
            x-bind:data-fw-expanded="expanded ? 'true' : 'false'"
            {{--
                Shown once a cell has been picked and not before: with the grid
                right there, nobody needs an invitation to click one. `panel` is
                the half that closes it — `closePanel()` lowers `panel` and keeps
                `selected`, so the cell keeps its ring — which is why `selected`
                alone would leave the panel on screen after Escape.
            --}}
            x-show="panel && selected"
            x-cloak
        >
            {{--
                Collapsed, the panel is this one line — the drawing, the cell
                and what it answers — which is what most clicks want to know;
                the rest appears when somebody asks for it. The drawing is
                `aria-hidden` because the gist beside it already says the state.
            --}}
            <div class="fw-inspector-bar" x-show="selected" x-cloak>
                <span
                    class="fw-box"
                    aria-hidden="true"
                    tabindex="-1"
                    x-bind:data-state="selected ? drawn(selected.row, selected.action, selected.name) : 'abstain'"
                ></span>

                <span class="fw-inspector-cell" x-text="selected ? selected.title : ''"></span>

                <span class="fw-inspector-gist" x-text="gist()"></span>

                <button
                    type="button"
                    class="fw-inspector-more"
                    x-bind:aria-expanded="expanded ? 'true' : 'false'"
                    x-on:click="expanded = ! expanded"
                >
                    <span x-text="expanded
                        ? @js(__('filament-warden::ui.explain.collapse'))
                        : @js(__('filament-warden::ui.explain.expand'))"></span>
                </button>

                <button type="button" class="fw-inspector-close" x-on:click="closePanel($root)" aria-label="{{ __('filament-warden::ui.explain.close') }}">
                    <x-filament::icon icon="heroicon-o-x-mark" class="fw-close-icon" />
                </button>
            </div>

            <div class="fw-inspector-body" x-show="expanded || ! selected" x-cloak>
                {{--
                    Empty from the first paint, for the reason the grid's own
                    region above gives, and it carries the failure sentence
                    itself: the paragraph below appears by a `display` toggle,
                    which announces nothing. It says `why.summary` and not the
                    whole panel, so a keystroke in the condition builder stays
                    silent.
                --}}
                <p class="fw-sr" role="status" x-text="failed ? @js(__('filament-warden::ui.explain.failed')) : (why ? why.summary : '')"></p>

                <p class="fw-inspector-empty" x-show="! selected">{{ __('filament-warden::ui.explain.empty') }}</p>
                <p class="fw-inspector-empty" x-show="selected && loading" x-cloak>{{ __('filament-warden::ui.explain.loading') }}</p>
                <p class="fw-inspector-empty fw-inspector-failed" x-show="selected && failed && ! loading" x-cloak>{{ __('filament-warden::ui.explain.failed') }}</p>

                <div class="fw-editor-layout">
                    <div class="fw-editor-controls">
                        <template x-if="selected && interactive">
                            <div class="fw-decision">
                                <h4 class="fw-field-label">{{ __('filament-warden::ui.explain.decision') }}</h4>
                                <div class="fw-stance" role="radiogroup" aria-label="{{ __('filament-warden::ui.explain.decision') }}" x-on:keydown.arrow-right.prevent="stepDecision($el, 1)" x-on:keydown.arrow-left.prevent="stepDecision($el, -1)">
                                    @foreach (['abstain', 'granted', 'forbidden'] as $stance)
                                        <button type="button" role="radio" data-fw-stance="{{ $stance }}"
                                            x-bind:aria-checked="stanceOf(selected.row, selected.action) === @js($stance) ? 'true' : 'false'"
                                            x-bind:tabindex="stanceOf(selected.row, selected.action) === @js($stance) ? 0 : -1"
                                            x-bind:disabled="! decisionEnabled()"
                                            x-on:click="setDecision(@js($stance))"
                                        >{{ __('filament-warden::ui.explain.decisions.'.$stance) }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </template>
                <div class="fw-write">
                    @include('filament-warden::builder')
                </div>
                    </div>
                    <div class="fw-editor-context">
                {{--
                    Who answers this cell when it is not this role, above the two
                    voices rather than inside the stored one: it is a different
                    rule belonging to a different role, not a second reading of
                    this one, and changing the cell writes one of this role's own
                    on top — the part somebody has to know BEFORE they click.
                --}}
                <template x-if="selected && inheritedAt(selected.row, selected.action) && ! loading">
                    <p class="fw-inspector-lent">
                        <span
                            x-text="@js(__('filament-warden::ui.explain.inherited')).replace(
                                ':role',
                                inheritedAt(selected.row, selected.action).role,
                            )"
                        ></span>
                    </p>
                </template>

                {{--
                    Two voices, because the store and the screen can disagree:
                    the explanation is about what is stored, and a cell changed
                    since would otherwise read as contradicting it.
                --}}
                <template x-if="why && ! loading && grid.explain">
                    <div class="fw-voices">
                        <section class="fw-voice">
                            <h4>{{ __('filament-warden::ui.explain.stored') }}</h4>
                            <p class="fw-voice-state">
                                <span class="fw-box" aria-hidden="true" x-bind:data-state="storedStance()"></span>
                                <span x-text="grid.states[storedStance()]"></span>
                            </p>
                            <p class="fw-voice-why" x-text="why.summary"></p>
                            <p class="fw-voice-note" x-show="why.narrowed" x-text="why.narrowed" x-cloak></p>
                            {{--
                                Beside the sentence above, never instead of it:
                                a lapsed grant comes back as `NoMatchingGrant`,
                                the cause a cell nobody ever wrote gets too, so
                                that sentence is true and is not the story.
                                Written by `Explanation::until()`.
                            --}}
                            <p class="fw-voice-note" x-show="why.until" x-text="why.until" x-cloak></p>
                        </section>

                        <section class="fw-voice fw-voice-screen" x-show="moved()" x-cloak>
                            <h4>{{ __('filament-warden::ui.explain.screen') }}</h4>
                            <p class="fw-voice-state">
                                <span
                                    class="fw-box"
                                    aria-hidden="true"
                                    x-bind:data-state="drawn(selected.row, selected.action, selected.name)"
                                    x-bind:data-broader="reached(selected.row, selected.action, selected.name)"
                                ></span>
                                <span x-text="stateOf(selected.row, selected.action, selected.name)"></span>
                            </p>
                            <p class="fw-voice-why">{{ __('filament-warden::ui.explain.save_hint') }}</p>
                        </section>

                        {{--
                            With nothing unsaved the second column would sit
                            empty, so it says what the store matched instead.
                        --}}
                        <section class="fw-voice" x-show="! moved() && (matchedName() || storedRule())" x-cloak>
                            <h4>{{ __('filament-warden::ui.explain.matched') }}</h4>
                            <p class="fw-voice-state" x-show="matchedName()" x-text="matchedName()"></p>
                            <p class="fw-voice-why" x-show="storedRule()">
                                <code x-text="storedRule()"></code>
                            </p>
                        </section>
                    </div>
                </template>


                <template x-if="selected && grid.constraints && interactive && ! loading">
                    <div class="fw-until">
                        <span class="fw-until-label">{{ __('filament-warden::ui.grid.until.label') }}</span>
                        <input
                            type="date"
                            class="fw-until-date"
                            aria-label="{{ __('filament-warden::ui.grid.until.label') }}"
                            x-bind:disabled="! untilEnabled(selected.row, selected.action)"
                            x-bind:value="(untilAt(selected.row, selected.action) ?? '').slice(0, 10)"
                            x-on:change="setUntil(selected.row, selected.action, $event.target.value)"
                            {{--
                                The reason is the input's description only while
                                there is one, which is only while the input is
                                disabled: it reaches whoever reads the input in
                                place, since a disabled input takes no focus.
                            --}}
                            x-bind:aria-describedby="untilReason(selected.row, selected.action) ? '{{ $ids }}-until-why' : null"
                        >
                        <p
                            class="fw-until-why"
                            id="{{ $ids }}-until-why"
                            x-show="untilReason(selected.row, selected.action)"
                            x-text="untilReason(selected.row, selected.action)"
                            x-cloak
                        ></p>
                    </div>
                </template>
                    </div>
                </div>
            </div>
        </aside>
        @endif
    </div>
</div>
