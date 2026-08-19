<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\Scope;
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
     */
    private function __construct(
        public array $tabs,
        public array $groups,
    ) {}

    /**
     * @param  array<string, array<string, string>>  $state  what the role says, keyed row => action
     * @param  array<string, array<string, bool>>  $narrowed  cells carrying conditions or ownership
     */
    public static function for(Catalog $catalog, array $state = [], array $narrowed = []): self
    {
        $groups = self::groups($catalog);

        $columns = [];

        foreach ($groups as $group) {
            foreach ($group->columns as $column) {
                $columns[] = $column;
            }
        }

        $tabs = [
            self::matrix($catalog, $columns, $state, $narrowed),
            self::doors('pages', $catalog, [Origin::Page], $state, $narrowed),
            self::doors('widgets', $catalog, [Origin::Widget], $state, $narrowed),
            self::doors('loose', $catalog, [Origin::Custom, Origin::Panel], $state, $narrowed),
        ];

        // An empty tab is a tab that shows nothing: the generation before this
        // one shipped one, and the grid could open on it.
        return new self(
            tabs: array_values(array_filter($tabs, static fn (Tab $tab): bool => ! $tab->isEmpty())),
            groups: $groups,
        );
    }

    /**
     * What the browser needs and nothing more: the cycle order, which actions a
     * granted wildcard reaches on each row, and which rows belong to each tab.
     *
     * @return array{
     *     order: list<string>,
     *     manage: string,
     *     rows: array<string, array{actions: list<string>, read: list<string>}>,
     *     tabs: list<array{key: string, rows: list<string>}>,
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

        foreach (Scope::cases() as $scope) {
            $columns = [];

            foreach ($scopes as $action => $actionScope) {
                if ($actionScope === $scope) {
                    $columns[] = new Column((string) $action, self::actionLabel((string) $action), $scope);
                }
            }

            if ($columns !== []) {
                $groups[] = new ColumnGroup($scope, self::translated('filament-warden::ui.scopes.'.$scope->value, $scope->value), $columns);
            }
        }

        return $groups;
    }

    /**
     * @param  list<Column>  $columns
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, bool>>  $narrowed
     */
    private static function matrix(Catalog $catalog, array $columns, array $state, array $narrowed): Tab
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
                    ? self::cell($key, $column->action, $column->label, $state, $narrowed, $column->scope, $entry)
                    : Cell::undeclared($key, $column->action, $column->label, $column->scope);
            }

            /** @var class-string<Model> $model */
            $rows[] = new Row(
                key: $key,
                label: self::entityLabel($model, $entries),
                model: $model,
                cells: $cells,
                manage: self::cell($key, StateKey::MANAGE, self::translated('filament-warden::ui.grid.manage', StateKey::MANAGE), $state, $narrowed),
            );
        }

        return new Tab('resources', self::translated('filament-warden::ui.tabs.resources', 'resources'), $rows, matrix: true);
    }

    /**
     * @param  list<Origin>  $origins
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, bool>>  $narrowed
     */
    private static function doors(string $key, Catalog $catalog, array $origins, array $state, array $narrowed): Tab
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
                cells: [self::cell($row, StateKey::DOOR, $label, $state, $narrowed, $entry->scope, $entry)],
            );
        }

        return new Tab($key, self::translated('filament-warden::ui.tabs.'.$key, $key), $rows, matrix: false);
    }

    /**
     * @param  array<string, array<string, string>>  $state
     * @param  array<string, array<string, bool>>  $narrowed
     */
    private static function cell(
        string $row,
        string $action,
        string $label,
        array $state,
        array $narrowed,
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
            narrowed: (bool) ($narrowed[$row][$action] ?? false),
            scope: $scope,
            entry: $entry,
        );
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
}
