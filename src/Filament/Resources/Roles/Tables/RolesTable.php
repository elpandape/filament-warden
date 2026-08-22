<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
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
    public static function configure(Table $table): Table
    {
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

                // Worked out per row against `assigned_roles`, under the tenant
                // this request is in — informing, not deciding (§6.24), and a
                // SECOND read alongside `isDeletable()`'s own EXISTS behind the
                // delete button's `visible()`: the two answer different
                // questions under different scope rules — `isDeletable()` must
                // read wide because the cascade is blind to tenancy, this must
                // stay scoped because it only informs — and cannot share a
                // query without a memo, which is the next tag's ("Que no
                // cueste") to add. The cost this doubles into is capped by a
                // test in `RoleResourceTest.php`, not just described here.
                //
                // Neither sortable nor searchable: both fall back to the
                // column's own name and would ask the database for a column
                // that does not exist — an error at click time, not at build
                // time (§6.17).
                TextColumn::make('held')
                    ->label(__('filament-warden::ui.resources.roles.columns.held'))
                    ->badge()
                    ->state(static fn (Model $record): int => self::assignmentCount($record)),
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
                    ->visible(static fn (Model $record): bool => RoleResource::canDelete($record))
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
     * Scoped, because a badge that informs keeps its scope (§6.24): counting
     * every tenant's rows would show a number `retract()` issued from here
     * could not act on.
     */
    private static function assignmentCount(Model $record): int
    {
        return Context::resolve()->assignedRoleClass()::query()
            ->where('role_id', $record->getKey())
            ->count();
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
