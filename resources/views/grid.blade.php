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

    // Whether the class name is drawn under each entity. Read once here rather
    // than per row: a matrix asks it once per entity and the accessor walks the
    // packaged defaults every time.
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
        The layout takes its second track only while the panel is open, which is
        the difference between this and the fixed side column 2.11 measured and
        threw away: that one took its width whether anybody was reading it or
        not. `data-open` is what the stylesheet keys the second track on, and it
        is bound rather than rendered, because the panel opens and closes without
        a round trip.
    --}}
    {{--
        Escape lives HERE and not on the panel, and the difference was measured
        in a browser rather than reasoned about. The panel is opened by clicking
        a cell, which leaves the focus on the cell — inside the matrix, outside
        the panel — so a listener scoped to the panel never received the
        keystroke on the only path anybody takes to open it. Measured: the panel
        stayed open on Escape every time.

        Still not on the window: this covers the field and nothing else, so a
        keystroke elsewhere on a Filament page is not this component's to
        swallow, which is the whole of the original reasoning. What it adds is
        the matrix, where Escape means nothing else. Inside the panel the
        builder's own inputs handle it first and it bubbles here after, exactly
        as before.
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
                    What a click just did, said once, for everyone who is not
                    looking at the cell.

                    A cell's accessible name already changes on its own — every
                    `fw-sr` span inside it is bound with `x-text` — but a name
                    that changes under a focus that never moved is not a name a
                    screen reader re-reads. This is the element that says it.

                    Empty from the first paint and never behind a condition, for
                    the reason the inspector's own region below already names: a
                    live region added to the page at the same moment its content
                    appears is not announced by NVDA or JAWS. Only its TEXT may
                    change.

                    One for the whole grid, not one per tab like the filter's:
                    only one cell can be clicked at a time, and a second region
                    would only ever repeat this one.

                    Driven by `write()`, which every stance change goes through,
                    and NOT by `select()` — that one returns early when the
                    inspector and the condition builder are both switched off,
                    which is a configuration where a click used to say nothing
                    at all, ever.
                --}}
                <p class="fw-sr" role="status" x-text="said"></p>

                <details class="fw-legend-fold" data-fw-inline="true">
                    <summary>{{ __('filament-warden::ui.grid.legend.title') }}</summary>

                    <div class="fw-key">
                        {{--
                            Two groups and not one list of eight: three of these
                            are what a click puts on a cell — the shift hint
                            belongs with them, not at the end of everything —
                            and the rest are marks the grid adds on its own.
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
                            {{--
                                Above both readings, so one input answers for
                                the table and for the fold. It only ever decides
                                what is DRAWN: the state below keeps every row,
                                filtered out or not, because a payload with an
                                entity missing is written as a deliberate revoke
                                (`RoleGrants::plan()` walks the catalogue, not
                                the payload).
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
                                    told about. Same pattern as the inspector's
                                    own line below.
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
                                                    Named by its two spans and
                                                    not by its contents, because
                                                    the preset buttons live in
                                                    here too: without this, every
                                                    row of the table is announced
                                                    as "users … read all none",
                                                    with the three button labels
                                                    glued to the entity's name.
                                                    Seen in an accessibility tree
                                                    dump, not guessed.
                                                --}}
                                                {{--
                                                    El `aria-labelledby` nombra
                                                    la clase solo si la clase se
                                                    dibuja. Una referencia a un
                                                    id que no existe se salta en
                                                    silencio —el nombre saldría
                                                    igual— pero apuntar a algo
                                                    ausente es una promesa rota
                                                    en el marcado, y el título de
                                                    la fila sigue llevando la
                                                    clase entera de todos modos.
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
                                columns do not fit across it. One reading is
                                painted at a time — the stylesheet turns the pair
                                over together with `display: none`, which is the
                                only way of hiding that also takes the losing
                                copy out of the accessibility tree and out of the
                                tab order.

                                Every cell here is the SAME partial, with the
                                same arguments byte for byte, so both copies bind
                                to one state and cannot drift apart. The notices,
                                the tabs and the legend are not repeated: they
                                sit above both readings, once.
                            --}}
                            <div class="fw-stack" x-show="matched(@js($tab->key)) > 0">
                                @foreach ($tab->rows as $row)
                                    <details class="fw-stack-entity" x-show="shown(@js($row->key))" @if ($loop->first) open @endif>
                                        <summary>
                                            <span class="fw-stack-name" title="{{ $row->model }}">{{ $row->label }}</span>
                                            @if ($showsClassNames)
                                                <span class="fw-stack-model">{{ $row->model }}</span>
                                            @endif
                                            {{--
                                                What the row's cells answer, for
                                                the reading that folds them away.
                                                Drawn by the server and redrawn
                                                by the browser from the same
                                                count: `Row::answered()` and
                                                `answered()` in the script.
                                            --}}
                                            <span class="fw-stack-summary" x-text="stackSummary(@js($row->key))">{{ $grid->summaryOf($row) }}</span>
                                        </summary>

                                        <div class="fw-stack-rows">
                                            {{--
                                                The presets again, because the
                                                fold has no `<tr>` to carry the
                                                copy above. In the body and never
                                                in the `<summary>`, where a click
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
                Solo con una celda seleccionada. Antes decía `panel || ! selected`,
                que era correcto mientras el inspector era una columna con su
                estado vacío dentro: al no haber nada elegido, la columna
                enseñaba «pulsa una celda». Como barra pegada abajo eso es otra
                cosa — una tira flotante que aparece nada más abrir el
                formulario, sin que nadie haya pulsado nada, y que dice que
                pulses algo. La invitación sobra: la rejilla está delante.
            --}}
            {{--
                `panel && selected`, y las dos mitades hacen falta.

                `selected` sola es lo que se puso al mover el panel abajo, y
                rompió Escape sin que ninguna de las nueve puertas lo viera:
                `closePanel()` baja `panel` y NO toca `selected` —a propósito,
                porque la celda sigue marcada con su aro para decir cuál se
                estaba leyendo— así que con `x-show="selected"` el panel se
                quedaba en pantalla después de cerrarlo.

                `panel` sola tampoco vale: era `panel || ! selected`, que sin
                nada elegido enseñaba el estado vacío. Con las dos, entrar no
                enseña nada, pulsar abre, y Escape cierra dejando el aro donde
                estaba.
            --}}
            x-show="panel && selected"
            x-cloak
        >
            {{--
                La barra plegada: una línea con el dibujo, la celda y el
                veredicto. Ahí acaba lo que la mayoría de los clics quiere saber,
                y es lo que deja que el resto solo aparezca cuando alguien lo
                pide — que es lo que le devuelve a la matriz su ancho entero.

                El dibujo va `aria-hidden` y con `tabindex="-1"`: repite el
                estado que la frase de al lado ya dice, y un botón más en el
                recorrido de teclado por decir dos veces lo mismo es ruido.
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
                    Who answers this cell, when it is not this role.

                    Above the two voices rather than inside the stored one: it is
                    not a second reading of the same rule, it is a different rule
                    belonging to a different role — and changing the cell here
                    writes one of this role's own on top rather than editing
                    theirs, which is the part somebody has to know BEFORE they
                    click.
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
                    When this cell's grant ends, and whether it may be given one.

                    Three noes with three sentences rather than one greyed
                    control: warden REFUSES a date on a prohibition — `until()`
                    on a forbid throws, `null` included — an abstention is the
                    absence of a row and has no life to end, and a cell answered
                    by something wider or inherited has no rule here to date.

                    Advisory only. The payload of a disabled control still
                    reaches the server, so `RoleGrants::plan()` asks the same
                    question again and drops the date whatever arrives.
                --}}


                {{--
                    The two voices that were one paragraph.

                    The panel said "No grant matches" and, three lines down,
                    "granted". They do not disagree: the first speaks for the
                    store and the second for the screen. Naming them is what
                    makes warden's sentence readable.

                    The stored half is read from the baseline, so none of this
                    costs a query.
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
                                Beside warden's cause and never instead of it.
                                There is no `Cause::Expired`: a lapsed grant comes
                                back `NoMatchingGrant`, byte for byte what a cell
                                nobody ever wrote answers, so the sentence above
                                is true and is not the story. Same shape as the
                                narrowed note for the same reason.
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
                                Pointed at FROM the control, and only while there
                                is a reason: a disabled input keeps its label in
                                the accessibility tree but takes no focus, so a
                                sentence sitting beside it is read in browse mode
                                and never in focus mode. It is the same lesson the
                                scope rail paid for in the v2.6.0 tree dump, on
                                the one control 3.0 added.
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
