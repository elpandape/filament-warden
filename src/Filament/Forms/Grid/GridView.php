<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Conditions\Words;
use ElPandaPe\FilamentWarden\Grants\RoleState;
use ElPandaPe\FilamentWarden\Grants\Tenants;
use ElPandaPe\FilamentWarden\Support\Config;
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
     */
    private function __construct(
        public array $tabs,
        public array $groups,
        public array $wider,
    ) {}

    /**
     * @param  RoleState  $stored  what the store has, which is what locks a cell
     * @param  array<string, array<string, string>>  $state  what is on screen, keyed row => action
     */
    public static function for(Catalog $catalog, RoleState $stored = new RoleState, array $state = []): self
    {
        $narrowings = $stored->narrowings;
        $wider = $stored->wider;

        $groups = self::groups($catalog);

        $columns = [];

        foreach ($groups as $group) {
            foreach ($group->columns as $column) {
                $columns[] = $column;
            }
        }

        $tabs = [
            self::matrix($catalog, $columns, $state, $narrowings, $wider),
            self::doors('pages', $catalog, [Origin::Page], $state, $narrowings, $wider),
            self::doors('widgets', $catalog, [Origin::Widget], $state, $narrowings, $wider),
            self::doors('loose', $catalog, [Origin::Custom, Origin::Panel], $state, $narrowings, $wider),
        ];

        // An empty tab is a tab that shows nothing: the generation before this
        // one shipped one, and the grid could open on it.
        return new self(
            tabs: array_values(array_filter($tabs, static fn (Tab $tab): bool => ! $tab->isEmpty())),
            groups: $groups,
            wider: $wider,
        );
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
     *     rows: array<string, array{actions: list<string>, read: list<string>, cells: list<array{action: string, name: string|null}>}>,
     *     tabs: list<array{key: string, rows: list<string>}>,
     *     wider: array<string, string>,
     *     operators: list<string>,
     *     authority: string,
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
            'explain' => Config::enabled('grid.explain'),
            'constraints' => Config::enabled('grid.constraints'),
            ...Words::all(),
        ];
    }

    /**
     * Whether what is drawn belongs to more than one tenant.
     */
    public function mixing(): bool
    {
        return Tenants::mixing();
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
        return new Column($action, self::actionLabel($action), $scope);
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, string>  $wider
     */
    private static function matrix(Catalog $catalog, array $columns, array $state, array $narrowings, array $wider): Tab
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
                    ? self::cell($key, $column->action, $column->label, $state, $narrowings, $wider, $column->scope, $entry)
                    : Cell::undeclared($key, $column->action, $column->label, $column->scope);
            }

            /** @var class-string<Model> $model */
            $rows[] = new Row(
                key: $key,
                label: self::entityLabel($model, $entries),
                model: $model,
                cells: $cells,
                manage: self::cell($key, StateKey::MANAGE, self::translated('filament-warden::ui.grid.manage', 'everything'), $state, $narrowings, $wider),
            );
        }

        return new Tab('resources', self::translated('filament-warden::ui.tabs.resources', 'resources'), $rows, matrix: true);
    }

    /**
     * @param  list<Origin>  $origins
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, string>  $wider
     */
    private static function doors(string $key, Catalog $catalog, array $origins, array $state, array $narrowings, array $wider): Tab
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
                cells: [self::cell($row, StateKey::DOOR, $label, $state, $narrowings, $wider, $entry->scope, $entry)],
            );
        }

        return new Tab($key, self::translated('filament-warden::ui.tabs.'.$key, $key), $rows, matrix: false);
    }

    /**
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, Narrowing>>  $narrowings
     * @param  array<string, string>  $wider
     */
    private static function cell(
        string $row,
        string $action,
        string $label,
        array $state,
        array $narrowings,
        array $wider,
        ?Scope $scope = null,
        ?Entry $entry = null,
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
            reach: self::reach($row, $action, $entry->name ?? $action, $state, $wider),
        );
    }

    /**
     * What already answers for a cell nobody wrote: the wildcard on its own row,
     * or a rule written over every entity at once. Forbidden wins, the same way
     * it wins when the store resolves the check.
     *
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, string>  $wider
     */
    private static function reach(string $row, string $action, string $name, array $state, array $wider): ?Stance
    {
        $candidates = [
            $action === StateKey::MANAGE ? null : ($state[$row][StateKey::MANAGE] ?? null),
            $wider['*'] ?? null,
            $wider[$name] ?? null,
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
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : self::humanize($fallback);
    }

    private static function humanize(string $value): string
    {
        return Str::headline($value);
    }

    private function line(string $key): string
    {
        return self::translated('filament-warden::ui.grid.legend.'.$key, $key);
    }
}
