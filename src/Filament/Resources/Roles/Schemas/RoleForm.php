<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Schemas;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * A name, a title, and the grid.
 *
 * The name of a protected role is shown and not editable: renaming it would
 * leave every grant that points at it answering for a role nobody guards.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label(__('filament-warden::ui.resources.roles.fields.name'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->disabled(static fn (?Model $record): bool => $record instanceof Model && RoleResource::isProtected($record)),

                TextInput::make('title')
                    ->label(__('filament-warden::ui.resources.roles.fields.title'))
                    ->maxLength(255),

                PermissionGrid::make('permissions')
                    ->label(__('filament-warden::ui.grid.label'))
                    ->columnSpanFull(),
            ]);
    }
}
