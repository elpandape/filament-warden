<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Provenance;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Support\Line;
use ElPandaPe\FilamentWarden\Support\Morph;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * The catalogue as a list, with the two things a row cannot say for itself:
 * where it came from, and how far it reaches.
 */
final class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        $heldCounts = null;

        return $table
            ->defaultSort('name')
            // A fresh install has no rows at all, and Filament's own default
            // says only "No Permissions" — true, and no help: this store fills
            // as roles are granted, never from this screen. The pointer names
            // both ways to see the catalogue because an installation that
            // called `->roles(false)` only has the second.
            ->emptyStateHeading(__('filament-warden::ui.resources.permissions.empty.heading'))
            // Filament draws the empty state on ANY render with no rows, a
            // search or a filter that matches nothing included — and there the
            // pointer would be a lie, since the catalogue is not empty. Asking
            // for the indicators is Filament's own answer to "is anything
            // narrowing this", covers search, per-column search and every
            // filter, and costs no query.
            ->emptyStateDescription(static fn (Table $table): ?string => $table->getFilterIndicators() === []
                ? Line::of('filament-warden::ui.resources.permissions.empty.description')
                : null)
            ->columns([
                TextColumn::make('title')
                    ->label(__('filament-warden::ui.resources.permissions.columns.title'))
                    ->description(static fn (Model $record): string => self::text($record->getAttribute('name')))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('entity_type')
                    ->label(__('filament-warden::ui.resources.permissions.columns.entity'))
                    ->formatStateUsing(static fn (string $state): string => self::entity($state))
                    ->placeholder(__('filament-warden::ui.resources.permissions.entity.none'))
                    ->sortable(),

                // Worked out per row, so neither sortable nor searchable: both
                // fall back to the column name and would ask the database for a
                // column that does not exist — an error at click time, not at
                // build time. The filter below is how it is narrowed instead.
                TextColumn::make('provenance')
                    ->label(__('filament-warden::ui.resources.permissions.columns.provenance'))
                    ->badge()
                    ->color(static fn (Model $record): string => match (Provenance::of($record, self::catalog())) {
                        Provenance::Wildcard => 'danger',
                        Provenance::Policy => 'success',
                        Provenance::Loose => 'gray',
                        Provenance::Unknown => 'warning',
                    })
                    ->state(static fn (Model $record): string => __(
                        'filament-warden::ui.provenance.'.Provenance::of($record, self::catalog())->value,
                    )),

                TextColumn::make('reach')
                    ->label(__('filament-warden::ui.resources.permissions.columns.reach'))
                    ->badge()
                    ->state(static fn (Model $record): string => self::reach($record)),

                // One grouped read for the page, never one per row: `Holders::of()`
                // is the honest answer for ONE record and hydrates every grant
                // that names it, which is a page's worth of hydration per row on
                // a listing. The three figures are a count each, and the
                // forbidden one is apart because a denial is a state and not an
                // absence.
                TextColumn::make('held')
                    ->label(__('filament-warden::ui.resources.permissions.columns.held'))
                    ->badge()
                    ->placeholder('—')
                    ->state(static function (Model $record) use (&$heldCounts): ?string {
                        $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();
                        $counts = (is_int($key) || is_string($key)) ? ($heldCounts[$key] ?? null) : null;

                        return $counts === null ? null : trans_choice(
                            'filament-warden::ui.resources.permissions.columns.held_count',
                            $counts['roles'] + $counts['accounts'],
                        );
                    })
                    ->description(static function (Model $record) use (&$heldCounts): ?string {
                        $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();
                        $forbidden = (is_int($key) || is_string($key)) ? ($heldCounts[$key]['forbidden'] ?? 0) : 0;

                        return $forbidden === 0 ? null : trans_choice(
                            'filament-warden::ui.resources.permissions.columns.forbidden_count',
                            $forbidden,
                        );
                    })
                    ->color(static function (Model $record) use (&$heldCounts): ?string {
                        $heldCounts ??= self::heldCounts();

                        $key = $record->getKey();

                        return (is_int($key) || is_string($key)) && ($heldCounts[$key]['forbidden'] ?? 0) > 0
                            ? 'danger'
                            : null;
                    }),

                // In PHP, over the row already loaded: no query, and the rule is
                // warden's own — `Group::unsatisfiableColumns()` through
                // `Narrowing`, which wants an instance because the answer is the
                // model's casts.
                TextColumn::make('health')
                    ->label(__('filament-warden::ui.resources.permissions.columns.health'))
                    ->badge()
                    ->placeholder('—')
                    ->color('danger')
                    ->state(static fn (Model $record): ?string => self::health($record)),
            ])
            ->filters([
                SelectFilter::make('provenance')
                    ->label(__('filament-warden::ui.resources.permissions.columns.provenance'))
                    ->options(self::provenances())
                    // Without a query closure a filter is a no-op: `apply()`
                    // hands the query back untouched.
                    ->query(static function (Builder $query, array $data): void {
                        $provenance = Provenance::tryFrom(is_string($data['value'] ?? null) ? $data['value'] : '');

                        $provenance?->applyTo($query, self::catalog());
                    }),

                TernaryFilter::make('held')
                    ->label(__('filament-warden::ui.resources.permissions.filters.held'))
                    ->placeholder(__('filament-warden::ui.resources.permissions.filters.any'))
                    ->trueLabel(__('filament-warden::ui.resources.permissions.filters.held_yes'))
                    ->falseLabel(__('filament-warden::ui.resources.permissions.filters.held_no'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->whereExists(self::grants(...)),
                        false: static fn (Builder $query): Builder => $query->whereNotExists(self::grants(...)),
                        blank: static fn (Builder $query): Builder => $query,
                    ),

                // The only filter here whose predicate cannot be SQL: whether a
                // rule can ever be true is a question about a MODEL's casts, and
                // the database knows nothing about those. So it narrows to the
                // rows that could possibly qualify — the ones carrying options —
                // and the rest is decided in PHP by the same call the column
                // makes. `whereKey` over that answer, so the filter and the
                // badge cannot drift: one of them would otherwise be a second
                // implementation of the rule (§6.17's own lesson, where a
                // provenance badge and its filter disagreed row by row).
                Filter::make('unsatisfiable')
                    ->label(__('filament-warden::ui.resources.permissions.filters.unsatisfiable'))
                    ->query(static fn (Builder $query): Builder => $query->whereKey(self::unsatisfiableKeys())),
            ])
            ->recordActions([
                ViewAction::make(),

                EditAction::make()
                    ->visible(static fn (Model $record): bool => PermissionResource::canEdit($record)),

                // The config has its say before the policy does, and the action
                // disappears rather than failing later. Overriding `canDelete()`
                // alone would not close it: the action asks the authorization
                // response directly.
                DeleteAction::make()
                    ->modalDescription(static fn (Model $record): string => self::warning($record))
                    ->visible(static fn (Model $record): bool => PermissionResource::canDelete($record)),
            ]);
    }

    /**
     * What goes away with it. The grants are removed by a foreign key, below
     * Eloquent and without a `Grant` model event, so an application watching a
     * swapped grant model sees nothing.
     *
     * Warden does announce them: it reads the doomed rows unscoped before the
     * delete and then dispatches one `PermissionRevoked` per row — or
     * `PermissionUnforbidden` where the row was a prohibition — with the
     * authority, the row's own scope and the resolved actor. But
     * `warden.events_enabled` switches it off, it costs a query per row to
     * hydrate the authority, and it goes only to a listener the application
     * wrote. None of it reaches the person clicking Delete, so this is still the
     * only moment they are told.
     */
    public static function warning(Model $record): string
    {
        $holders = Holders::of($record);

        if ($holders->isOrphaned()) {
            return __('filament-warden::ui.resources.permissions.delete.nobody');
        }

        return __('filament-warden::ui.resources.permissions.delete.holders', [
            'roles' => $holders->roleCount,
            'accounts' => $holders->accountCount,
            'names' => implode(', ', [...$holders->roles, ...$holders->accounts]),
        ]);
    }

    /**
     * How many roles, accounts and denials each catalogue row carries.
     *
     * One grouped read for the whole table, and the shape is warden's own: the
     * authority of a grant is polymorphic, so a role and an account are told
     * apart by `entity_type` and not by two relations. Read across every tenant,
     * exactly as `Holders` does and for the same reason — a grant is held or it
     * is not, and that question has no scope.
     *
     * The denial is counted apart rather than folded in, because it is a STATE
     * and not an absence: a row three roles are forbidden is not a row nobody
     * holds.
     *
     * @return array<int|string, array{roles: int, accounts: int, forbidden: int}>
     */
    private static function heldCounts(): array
    {
        $context = Context::resolve();
        $roleMorph = new ($context->roleClass())()->getMorphClass();

        $rows = $context->grantClass()::query()
            ->withoutGlobalScopes()
            ->select('permission_id', 'entity_type', 'forbidden')
            ->selectRaw('count(*) as tally')
            ->groupBy('permission_id', 'entity_type', 'forbidden')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $key = $row->getAttribute('permission_id');
            $tally = $row->getAttribute('tally');

            if ((! is_int($key) && ! is_string($key)) || ! is_numeric($tally)) {
                // Neither half is reachable and both are what `level: max`
                // demands: `permission_id` is NOT NULL and `count(*)` is
                // whatever the driver hands back, which `AssignedRole` declares
                // no cast for. A key that does not read as a key matches no row
                // anyway, which is the safe way to lose one — the same shape
                // `RoleGrants::of()` carries, where the unreachable half rides
                // a reachable line instead. There is no reachable half here.
                continue; // @codeCoverageIgnore
            }

            $counts[$key] ??= ['roles' => 0, 'accounts' => 0, 'forbidden' => 0];

            $bucket = match (true) {
                (bool) $row->getAttribute('forbidden') => 'forbidden',
                $row->getAttribute('entity_type') === $roleMorph => 'roles',
                default => 'accounts',
            };

            $counts[$key][$bucket] += (int) $tally;
        }

        return $counts;
    }

    /**
     * Whether this row's condition can never be true, said in one word.
     *
     * No query and no store read: `Narrowing::of()` is pure and the model comes
     * out of the morph alias the row already carries. A row whose entity
     * resolves to nothing is `drifted`'s finding in the audit, not this
     * column's — there is no model to ask about casts, and naming it here would
     * send somebody to add one to a class that does not exist.
     */
    private static function health(Model $record): ?string
    {
        $type = $record->getAttribute('entity_type');

        /** @var class-string<Model>|null $model */
        $model = is_string($type) ? Morph::model($type) : null;

        if ($model === null) {
            return null;
        }

        return Narrowing::of($record)->unsatisfiableColumns($model) === []
            ? null
            : (string) __('filament-warden::ui.resources.permissions.health.unsatisfiable');
    }

    /**
     * The keys of every row whose condition can never be true.
     *
     * Read once per filtered render rather than per row, and narrowed in SQL to
     * the rows that could possibly qualify before PHP is asked anything: a row
     * with no `options` carries no condition and cannot fail a satisfiability
     * check.
     *
     * @return list<int|string>
     */
    private static function unsatisfiableKeys(): array
    {
        $keys = [];

        $rows = Context::resolve()->permissionClass()::query()
            ->withoutGlobalScopes()
            ->whereNotNull('options')
            ->get();

        foreach ($rows as $row) {
            $key = $row->getKey();

            if ((is_int($key) || is_string($key)) && self::health($row) !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private static function provenances(): array
    {
        $options = [];

        foreach (Provenance::cases() as $provenance) {
            $options[$provenance->value] = (string) __('filament-warden::ui.provenance.'.$provenance->value);
        }

        return $options;
    }

    /**
     * Any grant pointing at this row, of any kind of authority.
     *
     * The same shape warden's own `clean` command uses, so the screen, the console
     * and warden agree on which rows are unused — and built against the grants
     * table rather than through `Permission::roles()`, which reaches roles only and
     * welds a tenant predicate no scope removal can strip.
     *
     * The console splits that same set in two by severity; this filter does not.
     * `held` here means what it means in `warden:clean`: no grant points at the
     * row, declared or not.
     *
     * The permission side is qualified by the model, which is what the listing
     * selects from: its key is only called `id` until an installation swaps the
     * model.
     */
    private static function grants(QueryBuilder $query): void
    {
        $context = Context::resolve();
        $permissionClass = $context->permissionClass();

        $query->from($context->table('grants'))
            ->whereColumn(
                $context->table('grants').'.permission_id',
                (new $permissionClass)->getQualifiedKeyName(),
            );
    }

    private static function catalog(): Catalog
    {
        return Catalog::union(array_values(Filament::getPanels()));
    }

    /**
     * A null entity never reaches this: Filament draws the placeholder instead
     * of formatting, so a branch for it here would be a branch nothing runs.
     */
    private static function entity(string $state): string
    {
        return $state === '*'
            ? (string) __('filament-warden::ui.resources.permissions.entity.any')
            : Str::headline(Str::plural(class_basename(Str::afterLast($state, '.'))));
    }

    /**
     * How far one row reaches, in one word.
     *
     * A row pinned to a record is not any of the six shapes: `Narrowing::of()`
     * reads `options` and `only_owned` and never `entity_id`, so it answered
     * "Every row" for a rule that answers no check about the class at all. The
     * word is read literally rather than composed, so the test that fails a key
     * nothing reads can still see it.
     */
    private static function reach(Model $record): string
    {
        if ($record->getAttribute('entity_id') !== null) {
            return (string) __('filament-warden::ui.resources.permissions.entity.record');
        }

        return (string) __('filament-warden::ui.reach.'.Narrowing::of($record)->shape->value);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
