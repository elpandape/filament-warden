<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The edit action needs nothing written by hand — the resource leaves
     * `canEdit()` alone, so the policy closes it on its own, and a protected role
     * opens with its form disabled rather than not opening at all.
     *
     * The delete action does, for the same reason as on the edit screen: its
     * authorization response never passes through `RoleResource::canDelete()`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            // The delete takes its assignments with it below Eloquent, and
            // nothing in warden bumps the version for a write made through the
            // model layer: without the hook every check goes on answering the old
            // way, silently and with no expiry. Void on purpose — whatever
            // `after()` returns stands in for the action's own result.
            DeleteAction::make()
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record))
                ->after(static function (): void {
                    Warden::refresh();
                }),
        ];
    }
}
