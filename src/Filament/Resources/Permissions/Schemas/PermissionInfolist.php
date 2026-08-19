<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Schemas;

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Provenance;
use ElPandaPe\FilamentWarden\Conditions\Narrowing;
use ElPandaPe\FilamentWarden\Grants\Holders;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The same questions the form asks, answered instead.
 *
 * Who holds it arrives as counts and not as names: a role screen has a handful
 * of roles, and a permission can be held by every account in the installation.
 * The names are read in one place only — the moment somebody deletes it, and
 * the grants go with it.
 */
class PermissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('filament-warden::ui.resources.permissions.sections.identity'))
                    ->icon(Heroicon::OutlinedKey)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('filament-warden::ui.resources.permissions.fields.name'))
                            ->copyable(),

                        TextEntry::make('title')
                            ->label(__('filament-warden::ui.resources.permissions.fields.title'))
                            ->placeholder('—'),

                        TextEntry::make('entity_type')
                            ->label(__('filament-warden::ui.resources.permissions.fields.entity'))
                            ->formatStateUsing(static fn (string $state): string => self::entity($state))
                            ->placeholder(__('filament-warden::ui.resources.permissions.entity.none')),

                        TextEntry::make('provenance')
                            ->label(__('filament-warden::ui.resources.permissions.columns.provenance'))
                            ->badge()
                            ->state(static fn (Model $record): string => (string) __(
                                'filament-warden::ui.provenance.'.Provenance::of($record, self::catalog())->value,
                            )),

                        TextEntry::make('reach')
                            ->label(__('filament-warden::ui.resources.permissions.columns.reach'))
                            ->badge()
                            ->state(static fn (Model $record): string => (string) __(
                                'filament-warden::ui.reach.'.Narrowing::of($record)->shape->value,
                            )),

                        TextEntry::make('rule')
                            ->label(__('filament-warden::ui.resources.permissions.fields.conditions'))
                            ->placeholder('—')
                            ->state(static fn (Model $record): ?string => self::rule($record)),
                    ]),

                Section::make(__('filament-warden::ui.resources.permissions.sections.holders'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->description(__('filament-warden::ui.resources.permissions.holders.description'))
                    ->columns(4)
                    ->schema([
                        TextEntry::make('roles')
                            ->label(__('filament-warden::ui.resources.permissions.holders.roles'))
                            ->state(static fn (Model $record): int => count(Holders::of($record)->roles)),

                        TextEntry::make('accounts')
                            ->label(__('filament-warden::ui.resources.permissions.holders.accounts'))
                            ->state(static fn (Model $record): int => Holders::of($record)->accountCount),

                        TextEntry::make('everyone')
                            ->label(__('filament-warden::ui.resources.permissions.holders.everyone'))
                            ->state(static fn (Model $record): string => (string) __(
                                'filament-warden::ui.resources.permissions.holders.'
                                .(Holders::of($record)->everyone ? 'yes' : 'no'),
                            )),

                        // A denial is a state and not an absence, so it is never
                        // folded into the tally of who holds it.
                        TextEntry::make('forbidden')
                            ->label(__('filament-warden::ui.resources.permissions.holders.forbidden'))
                            ->badge()
                            ->color(static fn (Model $record): string => Holders::of($record)->forbidden > 0 ? 'danger' : 'gray')
                            ->state(static fn (Model $record): int => Holders::of($record)->forbidden),
                    ]),
            ]);
    }

    /**
     * The stored rule, read out as it will be evaluated — brackets included.
     */
    private static function rule(Model $record): ?string
    {
        $narrowing = Narrowing::of($record);

        if ($narrowing->rules === []) {
            return null;
        }

        return $narrowing->preview(
            (string) __('filament-warden::ui.conditions.authority'),
            (string) __('filament-warden::ui.conditions.and'),
            (string) __('filament-warden::ui.conditions.or'),
        );
    }

    private static function catalog(): Catalog
    {
        // Nullable in the signature and never null in fact.
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
}
