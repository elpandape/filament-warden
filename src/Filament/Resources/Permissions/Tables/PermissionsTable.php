<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Provenance;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\Warden\Context;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
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
class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
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
                    ->state(static fn (Model $record): string => __(
                        'filament-warden::ui.reach.'.Narrowing::of($record)->shape->value,
                    )),
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
     * Eloquent and without an event of their own, so this is the only moment
     * anybody is told.
     */
    public static function warning(Model $record): string
    {
        $holders = Holders::of($record);

        if ($holders->isOrphaned()) {
            return __('filament-warden::ui.resources.permissions.delete.nobody');
        }

        return __('filament-warden::ui.resources.permissions.delete.holders', [
            'roles' => count($holders->roles),
            'accounts' => $holders->accountCount,
            'names' => implode(', ', [...$holders->roles, ...$holders->accounts]),
        ]);
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
     * The same shape warden's own `clean` command uses, so the screen and the
     * console agree on what an orphan is — and built against the grants table
     * rather than through `Permission::roles()`, which reaches roles only and
     * welds a tenant predicate no scope removal can strip.
     */
    private static function grants(QueryBuilder $query): void
    {
        $context = Context::resolve();

        $query->from($context->table('grants'))
            ->whereColumn(
                $context->table('grants').'.permission_id',
                $context->table('permissions').'.id',
            );
    }

    private static function catalog(): Catalog
    {
        // Nullable in the signature and never null in fact: it throws when there
        // is no panel at all, which is the only way it could answer null.
        /** @var Panel $panel */
        $panel = Filament::getCurrentOrDefaultPanel();

        return Catalog::for($panel);
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

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
