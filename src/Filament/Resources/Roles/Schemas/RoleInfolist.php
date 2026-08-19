<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Infolists\PermissionGridEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The same two questions as the form, answered and not asked.
 */
class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('filament-warden::ui.resources.roles.sections.identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('filament-warden::ui.resources.roles.fields.name'))
                            ->copyable(),

                        TextEntry::make('title')
                            ->label(__('filament-warden::ui.resources.roles.fields.title'))
                            ->placeholder('—'),
                    ]),

                Section::make(__('filament-warden::ui.grid.label'))
                    ->description(__('filament-warden::ui.grid.read_description'))
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        PermissionGridEntry::make('permissions')
                            ->hiddenLabel()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
