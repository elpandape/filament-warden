<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
            ])
            ->recordActions([
                EditAction::make(),
                // The config and the protected list have their say before the
                // policy does; the action disappears rather than failing later.
                DeleteAction::make()
                    ->visible(static fn (Model $record): bool => RoleResource::canDelete($record)),
            ]);
    }
}
