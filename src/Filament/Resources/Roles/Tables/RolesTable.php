<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class RolesTable
{
    /**
     * Two closures below share a memo apiece — `$heldCounts` for the
     * informing column, `$assignedRoleIds` for the deciding button — each
     * built from ONE query the first row that needs it asks for, and reused
     * by every row after (§6.24, "Que no cueste"). Before this,
     * `assignmentCount()` and `RoleResource::isDeletable()`'s own `EXISTS`
     * each paid their own query PER ROW: measured for 5 roles, 11
     * `assigned_roles` statements; after, 3 — those two queries plus one
     * unrelated to either (warden authorizing the signed-in user's own
     * `delete`/`viewAny` on the resource, which this fix does not touch).
     *
     * Neither query is bounded by the PAGE: a column closure cannot reach the
     * record set Filament paginated without going back through the table
     * component. Both are bounded by the ROLE CATALOGUE instead —
     * `heldCounts()` groups and `assignedRoleIds()` is `distinct()`, so each
     * returns at most one row per role however many assignment rows exist.
     * That is the whole cost of this screen, and it is invisible to a
     * statement count: reading every row and reducing in PHP gives the same
     * three statements and hydrates the entire table.
     *
     * Local variables captured by reference, not a class-level static
     * property: a fresh pair is born every time `configure()` runs — once
     * per table build, i.e. once per request — so there is nothing to reset
     * between test cases the way `Assignment::forget()` has to reset its own
     * memo, and nothing here can outlive the render it was built for.
     *
     * The two memos answer DIFFERENT questions under DIFFERENT scope rules,
     * on purpose, and are never merged into one query: `$assignedRoleIds` is
     * wide (`withoutGlobalScopes()`) because it feeds a DELETE decision and
     * the cascade that decision triggers is blind to tenancy; `$heldCounts`
     * keeps the active tenant's scope because it only INFORMS, and reading
     * wide there would show a number `retract()` issued from this screen
     * could not act on. Folding both into one query would silently pick one
     * of those two rules for both columns.
     *
     * Both ceilings live in `RoleResourceTest.php`, not only in this
     * paragraph: one test counts the statements and one counts the rows
     * Eloquent hydrates, because a statement counter reads 3 whichever
     * shape these two queries take.
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
     * Who holds it, resolved from rows the CALLER already scoped: a pure
     * transform, so the one decision that matters — wide for a delete warning,
     * tenant-scoped for `ViewRole`'s own informational section — stays entirely
     * in the caller's query and never leaks in here. Public for the same
     * reason `warning()` is: `ViewRole` reads its own rows and hands them here
     * to name them.
     *
     * Shaped after `Holders::labels()`/`accounts()`, with one thing simpler:
     * `assigned_roles.entity_type` and `.entity_id` are both `NOT NULL`
     * columns (the warden migration stub), unlike the nullable `grants` ones
     * `Holders` reads — so there is no "granted to everyone" bucket here, and
     * no line only a null value would reach.
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
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_subclass_of($class, Model::class)) {
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
     * How many rows each role has in `assigned_roles`, scoped, in one query
     * that returns ONE ROW PER ROLE — because a badge that informs keeps its
     * scope (§6.24): counting every tenant's rows would show a number
     * `retract()` issued from here could not act on.
     *
     * The aggregate is what bounds this. Reading `role_id` for every row and
     * reducing in PHP answers the same question and costs the whole table:
     * measured against 200 assignment rows over 5 roles, the listing
     * hydrated 400 `AssignedRole` models — this query and
     * `assignedRoleIds()` below reading every row once each — against 10
     * after, five apiece. The test `'the listing's two reads are bounded by
     * the role catalogue, not by the assignment table'` counts the 10,
     * through Eloquent's `retrieved` event, and caps it; the 400 is what the
     * unbounded bodies produce on the same fixture. A statement counter sees
     * 3 either way and cannot tell them apart.
     *
     * `count(*)` is whatever the driver hands back, and nothing normalises it
     * on the way through: `AssignedRole` declares no `$casts` at all, so an
     * aggregate has no cast to fall into. Measured here, under SQLite, it
     * arrives as `int`; what another driver returns is not something this
     * suite can measure, so it is narrowed with `is_numeric()` rather than
     * assumed. The other row values this class reads are keys, not
     * aggregates, and take a different narrowing (`is_int() || is_string()`)
     * for that reason.
     *
     * A holder restricted to a context is one more row with the same
     * `role_id` and counts here exactly like an unrestricted one — this
     * column has never filtered on `restricted_to_type`, and grouping does
     * not change that.
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
     * Every role id with at least one assignment row, ANY tenant — the
     * DECIDING half of §6.24's split, read wide for the same reason
     * `RoleResource::isDeletable()`'s own single-record query already is:
     * the cascade that removes these rows on delete is blind to scope.
     *
     * `distinct()` is what bounds it: without it this reads and hydrates
     * every row of `assigned_roles` to learn a set that can never be larger
     * than the role catalogue. See `heldCounts()` above for the measurement
     * and for the test that counts it.
     *
     * A set (`true` values, keyed by id) rather than a list: `isDeletable()`
     * does one `isset()` per row against this instead of an `in_array()`
     * scan. No cast on the way in and none on the way out: PHP normalises a
     * canonical numeric string array key back to `int`, so a set built from
     * either type answers `isset()` for a key of either type. Measured:
     * `$ids[(string) 5]` stores `int(5)`, and both `isset($ids[5])` and
     * `isset($ids['5'])` are true. A cast here would be a no-op that made
     * the declared key type wrong.
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
