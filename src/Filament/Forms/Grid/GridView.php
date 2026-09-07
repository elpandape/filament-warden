<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Words;
use ElPandaPe\FilamentWarden\Grants\RecordGrant;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use ElPandaPe\FilamentWarden\Grants\Tenants;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Line;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Everything the grid draws, worked out before anything is drawn.
 *
 * The template walks this and decides nothing. That is not a style preference:
 * the coverage gate measures `src/` only, so a rule that lives in a Blade file
 * is a rule nothing verifies.
 */
final readonly class GridView
{
    /**
     * @param  list<Tab>  $tabs
     * @param  list<ColumnGroup>  $groups
     * @param  array<string, string>  $wider
     * @param  list<RecordGrant>  $records  rules pinned to one row: reported above the grid, never drawn as a cell
     * @param  array{stances: array<string, array<string, string>>, narrowing: array<string, array<string, array{mode: string, rules: list<array<string, string>>}>>, until: array<string, array<string, string>>, inherited: array<string, array<string, array{role: string, stance: string}>>}  $stored  the store, in the shape the browser holds it
     * @param  bool  $isProtected  whether the installation protects this role, which is not the same question as whether this screen can edit it
     * @param  bool  $isInteractive  whether this render's cells are controls, which the component knows and the view model does not
     */
    private function __construct(
        public array $tabs,
        public array $groups,
        public array $wider,
        public array $records,
        public array $stored,
        public bool $isProtected,
        public bool $isInteractive,
    ) {}

    /**
     * @param  RoleState  $stored  what the store has, which is what locks a cell
     * @param  array<string, array<string, string>>  $state  what is on screen, keyed row => action
     */
    public static function for(
        Catalog $catalog,
        RoleState $stored = new RoleState,
        array $state = [],
        bool $isProtected = false,
        bool $isInteractive = true,
    ): self {
        $narrowings = $stored->narrowings;
        $untils = $stored->untils;
        $inherited = $stored->inherited;
        $wider = $stored->wider;

        $groups = self::groups($catalog);

        $columns = [];

        foreach ($groups as $group) {
            foreach ($group->columns as $column) {
                $columns[] = $column;
            }
        }

        $tabs = [
            self::matrix($catalog, $columns, $state, $narrowings, $untils, $wider, $inherited),
            self::doors('pages', $catalog, [Origin::Page], $state, $narrowings, $untils, $wider, $inherited),
            self::doors('widgets', $catalog, [Origin::Widget], $state, $narrowings, $untils, $wider, $inherited),
            self::doors('loose', $catalog, [Origin::Custom, Origin::Panel], $state, $narrowings, $untils, $wider, $inherited),
        ];

        // `reach()` below is one of seven decisions this package makes twice —
        // once here for the server-rendered pass and once in
        // `resources/js/permission-grid.js`, because a click has to redraw
        // without a round trip. Most of the others live in `Cell`,
        // `Conditions/Narrowing` and `Conditions/Rule`; that file's docblock is
        // the index and names which of ours each of its rules pairs with.

        // An empty tab is a tab that shows nothing: the generation before this
        // one shipped one, and the grid could open on it.
        return new self(
            tabs: array_values(array_filter($tabs, static fn (Tab $tab): bool => ! $tab->isEmpty())),
            groups: $groups,
            wider: $wider,
            records: $stored->records,
            stored: $stored->toPayload(),
            isProtected: $isProtected,
            isInteractive: $isInteractive,
        );
    }

    /**
     * A stable id for one field's tabs and the panels they open.
     *
     * The component key is absolute — `form.permissions` — so it is already
     * unique on the page, and it carries a dot, which is an id nobody escapes
     * correctly the first time they write a selector for it.
     */
    public static function domId(string $componentKey): string
    {
        return 'fw-'.Str::of($componentKey)->replaceMatches('/[^A-Za-z0-9]+/', '-')->lower()->toString();
    }

    /**
     * How the grid names one cell, for a caller that holds its keys and needs
     * its words.
     *
     * A save that refuses a cell has to name it, and naming it any other way
     * would give one cell two vocabularies on one screen — the store's
     * `deleteAny on warden.role` beside the grid's own `Roles - Delete any`.
     * The catalogue this reads is memoised per panel, so asking for it again
     * here costs nothing.
     */
    public static function cellLabel(Catalog $catalog, string $row, string $action): string
    {
        $entries = array_values(array_filter(
            $catalog->entries,
            static fn (Entry $entry): bool => StateKey::row($entry) === $row,
        ));

        $entry = $entries[0] ?? null;

        $rowLabel = match (true) {
            ! $entry instanceof Entry => self::humanize(class_basename($row)),
            $entry->model === null => self::doorLabel($entry),
            default => self::entityLabel($entry->model, $entries),
        };

        // A door has one cell and it is the row: naming its column as well would
        // read as two things where there is one.
        if ($action === StateKey::DOOR) {
            return $rowLabel;
        }

        $actionLabel = $action === StateKey::MANAGE
            ? self::translated('filament-warden::ui.grid.manage', 'everything')
            : self::actionLabel($action);

        return $rowLabel.' · '.$actionLabel;
    }

    /**
     * What the browser needs and nothing more: the cycle order, which actions a
     * granted wildcard reaches on each row, and which rows belong to each tab.
     *
     * The words the builder needs travel with it, so the only rule written twice
     * is the clause cut — and even that one is warden's, not this package's.
     *
     * @return array{
     *     order: list<string>,
     *     manage: string,
     *     rows: array<string, array{label: string, model: string|null, actions: list<string>, read: list<string>, cells: list<array{action: string, name: string|null}>}>,
     *     tabs: list<array{key: string, rows: list<string>}>,
     *     wider: array<string, string>,
     *     states: array<string, string>,
     *     filter: array{count: string, empty: string},
     *     summary: array{ratio: string, forbidden: string},
     *     until: array{forbidden: string, unwritten: string},
     *     operators: list<string>,
     *     authority: string,
     *     boolean: string,
     *     boolean_column: string,
     *     joiners: array{and: string, or: string},
     *     modes: array<string, array{name: string, hint: string}>,
     *     explain: bool,
     *     constraints: bool,
     * }
     */
    public function alpine(): array
    {
        $rows = [];

        foreach ($this->tabs as $tab) {
            foreach ($tab->rows as $row) {
                $rows[$row->key] = [
                    // The two words the filter matches on. They are already
                    // drawn in both readings; sending them is what lets one
                    // predicate answer for both without the browser reading
                    // the DOM back.
                    'label' => $row->label,
                    'model' => $row->model,
                    'actions' => $row->editableActions(),
                    'read' => $row->readActions(),
                    'cells' => $row->drawnCells(),
                ];
            }
        }

        return [
            'order' => Stance::order(),
            'manage' => StateKey::MANAGE,
            'rows' => $rows,
            'tabs' => array_map(static fn (Tab $tab): array => [
                'key' => $tab->key,
                'rows' => array_map(static fn (Row $row): string => $row->key, $tab->rows),
            ], $this->tabs),
            'wider' => $this->wider,
            'states' => $this->states(),
            // The one sentence the browser composes rather than looks up whole,
            // because its numbers only exist once a cell has been clicked.
            'filter' => [
                'count' => self::translated('filament-warden::ui.grid.filter.count', ':matched / :total'),
                'empty' => self::translated('filament-warden::ui.grid.filter.empty', ':term'),
            ],
            'summary' => [
                'ratio' => self::translated('filament-warden::ui.grid.summary.ratio', ':granted / :total'),
                'forbidden' => self::translated('filament-warden::ui.grid.summary.forbidden', ':count'),
            ],
            // The two sentences the date control says when it cannot be used.
            // Handed over whole rather than composed: the browser picks one out
            // of a map, exactly as it does with `states`, so no stance name and
            // no reason ever lives in the script.
            'until' => [
                'forbidden' => self::translated('filament-warden::ui.grid.until.forbidden', 'forbidden'),
                'unwritten' => self::translated('filament-warden::ui.grid.until.unwritten', 'unwritten'),
            ],
            'explain' => Config::enabled('grid.explain'),
            'constraints' => Config::enabled('grid.constraints'),
            ...Words::all(),
        ];
    }

    /**
     * The fold's per-entity count, drawn by the server.
     *
     * The browser redraws it from the same count the moment a cell is clicked
     * — `stackSummary()` in the script — so this is the half that is right
     * before alpine boots, and the two are pinned together by a test. The
     * placeholder substitution is the one this package does in two places on
     * purpose (§6.15's rule: a rule written twice is named beside its
     * counterpart, not collapsed).
     */
    public function summaryOf(Row $row): string
    {
        $answered = $row->answered();

        $ratio = str_replace(
            [':granted', ':total'],
            [(string) $answered['granted'], (string) $answered['total']],
            self::translated('filament-warden::ui.grid.summary.ratio', ':granted / :total'),
        );

        if ($answered['forbidden'] === 0) {
            return $ratio;
        }

        return $ratio.' · '.str_replace(
            ':count',
            (string) $answered['forbidden'],
            self::translated('filament-warden::ui.grid.summary.forbidden', ':count'),
        );
    }

    /**
     * Whether what is drawn belongs to more than one tenant.
     */
    public function mixing(): bool
    {
        return Tenants::mixing();
    }

    /**
     * Whether this grid needs to be told it does not write.
     *
     * A protected role already has a sentence that says more than this one: it
     * names the cause, and the reader can act on it. The other two ways of
     * arriving at a grid that is not a control — a field the application
     * disabled, a screen that only reads — get one sentence between them,
     * because the package cannot tell them apart honestly and naming a cause it
     * cannot check is the defect this answer exists to remove.
     *
     * The exclusivity lives here and not in the template for the reason every
     * other rule does: `src/` is the half the coverage gate measures.
     */
    public function isReadOnly(): bool
    {
        return ! $this->isInteractive && ! $this->isProtected;
    }

    /**
     * The seven drawings, each with the sample the reader compares against. It
     * lives here and not in the template for the same reason everything else
     * does: this is the half that is measured.
     *
     * @return list<array{state: string, broader: string|null, void: bool, noted: bool, locked: bool, label: string}>
     */
    public function legend(): array
    {
        return [
            ['state' => 'abstain', 'broader' => null, 'void' => false, 'noted' => false, 'locked' => false, 'label' => $this->line('abstains')],
            ['state' => 'granted', 'broader' => null, 'void' => false, 'noted' => false, 'locked' => false, 'label' => $this->line('granted')],
            ['state' => 'forbidden', 'broader' => null, 'void' => false, 'noted' => false, 'locked' => false, 'label' => $this->line('forbidden')],
            ['state' => 'broader', 'broader' => 'granted', 'void' => false, 'noted' => false, 'locked' => false, 'label' => $this->line('broader')],
            ['state' => 'abstain', 'broader' => null, 'void' => true, 'noted' => false, 'locked' => false, 'label' => $this->line('undeclared')],
            ['state' => 'granted', 'broader' => null, 'void' => false, 'noted' => true, 'locked' => false, 'label' => $this->line('narrowed')],
            ['state' => 'granted', 'broader' => null, 'void' => false, 'noted' => false, 'locked' => true, 'label' => $this->line('locked')],
        ];
    }

    /**
     * The word each drawing says out loud, which is not the word the legend
     * prints beside its sample. A legend line is a caption with a subject in it
     * — "the role abstains" — and this one is read after the cell's own name and
     * after the row and column headers, so it has to survive being said in a
     * list. Two of the seven happen to coincide today; welding them would mean a
     * change to a caption silently changed a control's name.
     *
     * The first three keys are `Stance::order()`, and in that order on purpose:
     * the script indexes this map by what a cell ANSWERS, and what a cell
     * answers is a stance value. Composing the name in javascript instead would
     * put the three stance names back into the one file whose whole point is
     * that it carries none.
     *
     * @return array<string, string>
     */
    public function states(): array
    {
        $states = [];

        foreach (Stance::order() as $stance) {
            $states[$stance] = $this->state($stance);
        }

        // `expires` and `inherited` joined in 3.0.0. They are marks like the
        // four beside them — a word said after the cell's own name, in a list —
        // and not captions, which is why they are here and not in the legend.
        foreach (['broader', 'narrowed', 'locked', 'undeclared', 'expires', 'inherited'] as $mark) {
            $states[$mark] = $this->state($mark);
        }

        return $states;
    }

    /**
     * The columns, grouped by scope. Their order inside a group is the order the
     * policy declared them, which is the order a reader already knows.
     *
     * @return list<ColumnGroup>
     */
    private static function groups(Catalog $catalog): array
    {
        $scopes = [];

        foreach ($catalog->entries as $entry) {
            if ($entry->model !== null) {
                $scopes[$entry->name] ??= $entry->scope;
            }
        }

        $groups = [];
        $map = Config::scopes();

        foreach (Scope::cases() as $scope) {
            $declared = $map[$scope->value] ?? [];
            $columns = [];

            // First in the order the scope map declares, which is intentional;
            // then whatever landed here without being named, which is not.
            foreach ($declared as $action) {
                if (($scopes[$action] ?? null) === $scope) {
                    $columns[] = self::column($action, $scope);
                }
            }

            foreach ($scopes as $action => $actionScope) {
                if ($actionScope === $scope && ! in_array((string) $action, $declared, true)) {
                    $columns[] = self::column((string) $action, $scope);
                }
            }

            if ($columns !== []) {
                $groups[] = new ColumnGroup($scope, self::translated('filament-warden::ui.scopes.'.$scope->value, $scope->value), $columns);
            }
        }

        return $groups;
    }

    private static function column(string $action, Scope $scope): Column
    {
        return new Column(StateKey::of($action), self::actionLabel($action), $scope);
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, array<string, CarbonImmutable>>  $untils
     * @param  array<string, string>  $wider
     * @param  array<string, array<string, array{role: string, stance: string}>>  $inherited
     */
    private static function matrix(Catalog $catalog, array $columns, array $state, array $narrowings, array $untils, array $wider, array $inherited): Tab
    {
        /** @var array<string, list<Entry>> $byModel */
        $byModel = [];

        foreach ($catalog->entries as $entry) {
            if ($entry->model !== null) {
                $byModel[$entry->model][] = $entry;
            }
        }

        $rows = [];

        foreach ($byModel as $model => $entries) {
            $key = StateKey::row($entries[0]);

            $declared = [];

            foreach ($entries as $entry) {
                $declared[$entry->name] = $entry;
            }

            $cells = [];

            foreach ($columns as $column) {
                $entry = $declared[$column->action] ?? null;

                $cells[] = $entry instanceof Entry
                    ? self::cell($key, $column->action, $column->label, $state, $narrowings, $untils, $wider, $column->scope, $entry, $inherited)
                    : Cell::undeclared($key, $column->action, $column->label, $column->scope);
            }

            /** @var class-string<Model> $model */
            $rows[] = new Row(
                key: $key,
                label: self::entityLabel($model, $entries),
                model: $model,
                cells: $cells,
                manage: self::cell($key, StateKey::MANAGE, self::translated('filament-warden::ui.grid.manage', 'everything'), $state, $narrowings, $untils, $wider, null, null, $inherited),
            );
        }

        return new Tab('resources', self::translated('filament-warden::ui.tabs.resources', 'resources'), $rows, matrix: true);
    }

    /**
     * @param  list<Origin>  $origins
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, array<string, CarbonImmutable>>  $untils
     * @param  array<string, string>  $wider
     * @param  array<string, array<string, array{role: string, stance: string}>>  $inherited
     */
    private static function doors(string $key, Catalog $catalog, array $origins, array $state, array $narrowings, array $untils, array $wider, array $inherited): Tab
    {
        $rows = [];

        foreach ($catalog->entries as $entry) {
            if (! in_array($entry->origin, $origins, true)) {
                continue;
            }

            $row = StateKey::row($entry);
            $label = self::doorLabel($entry);

            $rows[] = new Row(
                key: $row,
                label: $label,
                model: null,
                cells: [self::cell($row, StateKey::DOOR, $label, $state, $narrowings, $untils, $wider, $entry->scope, $entry, $inherited)],
            );
        }

        return new Tab($key, self::translated('filament-warden::ui.tabs.'.$key, $key), $rows, matrix: false);
    }

    /**
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, array<string, CarbonImmutable>>  $untils
     * @param  array<string, string>  $wider
     * @param  array<string, array<string, array{role: string, stance: string}>>  $inherited
     */
    private static function cell(
        string $row,
        string $action,
        string $label,
        array $state,
        array $narrowings,
        array $untils,
        array $wider,
        ?Scope $scope = null,
        ?Entry $entry = null,
        array $inherited = [],
    ): Cell {
        $written = $state[$row][$action] ?? null;

        return new Cell(
            row: $row,
            action: $action,
            label: $label,
            stance: Stance::tryFrom(is_string($written) ? $written : '') ?? Stance::Abstain,
            declared: true,
            narrowing: $narrowings[$row][$action] ?? null,
            scope: $scope,
            entry: $entry,
            reach: self::reach($row, $action, $entry->name ?? $action, $state, $wider, $inherited),
            until: $untils[$row][$action] ?? null,
            inheritedFrom: isset($state[$row][$action]) ? null : ($inherited[$row][$action]['role'] ?? null),
        );
    }

    /**
     * What already answers for a cell nobody wrote: the wildcard on its own row,
     * or a rule written over every entity at once. Forbidden wins, the same way
     * it wins when the store resolves the check.
     *
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, string>  $wider
     * @param  array<string, array<string, array{role: string, stance: string}>>  $inherited
     */
    private static function reach(string $row, string $action, string $name, array $state, array $wider, array $inherited = []): ?Stance
    {
        $candidates = [
            $action === StateKey::MANAGE ? null : ($state[$row][StateKey::MANAGE] ?? null),
            $wider['*'] ?? null,
            $wider[$name] ?? null,
            // An inherited answer is the same SHAPE as a wider rule: nobody
            // wrote this cell and something else answers it. Reading it here
            // rather than beside it is what keeps the tab counters honest for
            // free — they count what a cell ANSWERS (§6.11), and the browser
            // re-derives the same thing from the same payload. What differs is
            // only WHICH thing answers, and that is `inheritedFrom` on the cell.
            $inherited[$row][$action]['stance'] ?? null,
        ];

        $reach = null;

        foreach ($candidates as $candidate) {
            $stance = Stance::tryFrom(is_string($candidate) ? $candidate : '');

            if ($stance === Stance::Forbidden) {
                return $stance;
            }

            $reach ??= $stance === Stance::Granted ? $stance : null;
        }

        return $reach;
    }

    /**
     * The resource already names the entity for the rest of the panel, and the
     * grid says the same word it does.
     *
     * @param  class-string<Model>  $model
     * @param  list<Entry>  $entries
     */
    private static function entityLabel(string $model, array $entries): string
    {
        foreach ($entries as $entry) {
            if ($entry->source !== null && is_subclass_of($entry->source, \Filament\Resources\Resource::class)) {
                return $entry->source::getPluralModelLabel();
            }
        }

        return self::humanize(Str::plural(class_basename($model)));
    }

    private static function doorLabel(Entry $entry): string
    {
        return match ($entry->origin) {
            Origin::Panel => self::translated('filament-warden::ui.grid.panel', 'the panel'),
            default => self::humanize(class_basename(Str::afterLast($entry->name, ':'))),
        };
    }

    /**
     * An action the package can name, it names; the rest are the application's
     * own and only it knows what they are called.
     */
    private static function actionLabel(string $action): string
    {
        return self::translated('filament-warden::ui.actions.'.$action, $action);
    }

    /**
     * Falls back to the humanised value when nothing translates it, because the
     * catalogue is derived from the application's policies and no shipped file
     * can list their names in advance.
     */
    private static function translated(string $key, string $fallback): string
    {
        return Line::orHumanize($key, $fallback);
    }

    private static function humanize(string $value): string
    {
        return Str::headline($value);
    }

    private function state(string $key): string
    {
        return self::translated('filament-warden::ui.grid.states.'.$key, $key);
    }

    private function line(string $key): string
    {
        return self::translated('filament-warden::ui.grid.legend.'.$key, $key);
    }
}
