<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\Warden\Facades\Warden;
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
                //
                // The delete takes its assignments with it below Eloquent, and
                // nothing in warden bumps the version for a write made through
                // the model layer: without the hook every check goes on answering
                // the old way, silently and with no expiry. Void on purpose —
                // whatever `after()` returns stands in for the action's own result.
                DeleteAction::make()
                    ->visible(static fn (Model $record): bool => RoleResource::canDelete($record))
                    ->after(static function (): void {
                        Warden::refresh();
                    }),
            ]);
    }
}
