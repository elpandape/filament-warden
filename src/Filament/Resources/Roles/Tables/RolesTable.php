<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class RolesTable
{
    /**
     * A memo apiece for the two closures below — `$heldCounts` for the
     * informing column, `$assignedRoleIds` for the deciding button — each one
     * query the first row asks for and every row after reuses.
     *
     * Neither is bounded by the PAGE: a column closure cannot reach the record
     * set Filament paginated. Both are bounded by the CATALOGUE instead, one
     * row per role however many assignments exist — which a statement count
     * cannot see, so `RoleResourceTest` counts hydrated rows as well.
     *
     * Never merged into one query: they answer different questions under
     * different scope rules. `$assignedRoleIds` reads wide because it feeds a
     * DELETE and the cascade is blind to tenancy; `$heldCounts` keeps the
     * active tenant because it only INFORMS, and a wider number is one
     * `retract()` could not act on from here.
     *
     * Local variables rather than static properties: a fresh pair per
     * `configure()`, so nothing outlives the render or leaks between tests.
     */
    public static function configure(Table $table): Table
    {
        $heldCounts = null;
        $assignedRoleIds = null;

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-warden::ui.resources.roles.columns.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('filament-warden::ui.resources.roles.columns.title'))
                    ->placeholder('—')
                    ->toggleable(),

                // Neither sortable nor searchable: both fall back to the
                // column's own name and would ask the database for a column
                // that does not exist — an error at click time, not at build
                // time (§6.17).
                TextColumn::make('held')
                    ->label(__('filament-warden::ui.resources.roles.columns.held'))
                    ->badge()
                    ->state(static function (Model $record) use (&$heldCounts): int {
                        $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();

                        return (is_int($key) || is_string($key)) ? ($heldCounts[$key] ?? 0) : 0;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                // The config and the protected list have their say before the
                // policy does; the action disappears rather than failing later.
                //
                // The delete takes its assignments with it below Eloquent, and
                // nothing in warden bumps the version for a write made through
                // the model layer: without the hook every check goes on answering
                // the old way, silently and with no expiry. Void on purpose —
                // whatever `after()` returns stands in for the action's own result.
                DeleteAction::make()
                    ->modalDescription(static fn (Model $record): string => self::warning($record))
                    ->visible(static function (Model $record) use (&$assignedRoleIds): bool {
                        // Fetched only under the one rule that ever reads it
                        // (`RoleResource::isDeletable()`'s own `'all'`/`false`
                        // branches never query at all): a page where nothing
                        // asks about it pays nothing.
                        if (Config::get('roles.delete') === 'unassigned') {
                            $assignedRoleIds ??= self::assignedRoleIds();
                        }

                        return RoleResource::canDelete($record, $assignedRoleIds);
                    })
                    ->after(static function (): void {
                        Warden::refresh();
                    }),
            ]);
    }

    /**
     * What goes away with it, said the way `PermissionsTable::warning()`
     * already says it — public for the same reason (§6.23): `EditRole` and
     * `ViewRole` carry their own `DeleteAction` and reuse this directly.
     *
     * Read wide on purpose: the assignment rows follow the role down through a
     * foreign key and THE CASCADE IS BLIND TO THE SCOPE, exactly like
     * `RoleResource::isDeletable()`'s own read and `Holders::of()`'s — counting
     * only the active tenant's rows would promise a smaller loss than the
     * delete actually causes.
     */
    public static function warning(Model $record): string
    {
        $rows = self::assignments($record);

        if ($rows->isEmpty()) {
            return (string) __('filament-warden::ui.resources.roles.delete.nobody');
        }

        return (string) __('filament-warden::ui.resources.roles.delete.holders', [
            'count' => $rows->count(),
            'names' => implode(', ', self::labels($rows)),
        ]);
    }

    /**
     * Who holds it, from rows the CALLER already scoped: a pure transform, so
     * the decision that matters — wide for a delete warning, tenant-scoped for
     * an informational section — stays in the caller's query.
     *
     * Simpler than `Holders::labels()` in one way: `assigned_roles`'s two
     * authority columns are `NOT NULL`, unlike the `grants` ones, so there is
     * no "granted to everyone" bucket and no line only a null would reach.
     *
     * @param  Collection<int, Model>  $rows
     * @return list<string>
     */
    public static function labels(Collection $rows): array
    {
        /** @var array<string, list<int|string>> $byType */
        $byType = [];
        $counted = 0;

        foreach ($rows as $row) {
            if ($counted >= Holders::LABELS) {
                break;
            }

            $type = $row->getAttribute('entity_type');
            $key = $row->getAttribute('entity_id');

            if (is_string($type) && (is_int($key) || is_string($key))) {
                $byType[$type][] = $key;
                $counted++;
            }
        }

        $labels = [];

        foreach ($byType as $type => $keys) {
            // A stale morph alias does not throw, it stops resolving — and an
            // authority nobody can name is simply left out, the same choice
            // `Holders::accounts()` makes.
            $class = Morph::model($type);

            if ($class === null) {
                continue;
            }

            $records = $class::query()->withoutGlobalScopes()->whereKey($keys)->get();

            foreach ($records as $holder) {
                $labels[] = Holders::label($holder);
            }
        }

        return $labels;
    }

    /**
     * How many rows each role has in `assigned_roles`, scoped, one row per
     * role: a badge that informs keeps its scope, or it shows a number
     * `retract()` from this screen could not act on.
     *
     * The aggregate is what bounds it — reducing in PHP answers the same
     * question and hydrates the whole table, which a statement count cannot
     * see. A holder restricted to a context counts like any other.
     *
     * `count(*)` is whatever the driver hands back and `AssignedRole` declares
     * no casts, so it is narrowed with `is_numeric()` rather than assumed. The
     * other values this class reads are keys, not aggregates, and take
     * `is_int() || is_string()`.
     *
     * @return array<int|string, int>
     */
    private static function heldCounts(): array
    {
        $counts = [];

        $rows = Context::resolve()->assignedRoleClass()::query()
            ->select('role_id')
            ->selectRaw('count(*) as held')
            ->groupBy('role_id')
            ->get();

        foreach ($rows as $row) {
            $key = $row->getAttribute('role_id');
            $held = $row->getAttribute('held');

            if ((is_int($key) || is_string($key)) && is_numeric($held)) {
                $counts[$key] = (int) $held;
            }
        }

        return $counts;
    }

    /**
     * Every role id with at least one assignment row, ANY tenant: this decides
     * a DELETE, and the cascade that removes those rows is blind to scope.
     *
     * `distinct()` is what bounds it to the catalogue rather than the
     * assignment table.
     *
     * A set rather than a list, so `isDeletable()` does one `isset()` per row.
     * No cast either way: PHP normalises a canonical numeric string key back to
     * `int`, so a set built from either type answers for either.
     *
     * @return array<int|string, true>
     */
    private static function assignedRoleIds(): array
    {
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->distinct()
            ->get(['role_id']);

        $ids = [];

        foreach ($rows as $row) {
            $key = $row->getAttribute('role_id');

            if (is_int($key) || is_string($key)) {
                $ids[$key] = true;
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Model>
     */
    private static function assignments(Model $record): Collection
    {
        /** @var Collection<int, Model> $rows */
        $rows = Context::resolve()->assignedRoleClass()::query()
            ->withoutGlobalScopes()
            ->where('role_id', $record->getKey())
            ->orderBy('id')
            ->get();

        return $rows;
    }
}
