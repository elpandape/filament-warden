<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The `visible()` is the guarantee, not decoration: a delete button asks
     * `getDeleteAuthorizationResponse()`, which goes straight to the policy, so
     * the resource's `canDelete()` — where the protected list and the
     * `roles.delete` rule live — is never on that path.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Void on purpose: whatever `after()` returns stands in for the
            // action's own result.
            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record))
                ->after(static function (): void {
                    Warden::refresh();
                }),
        ];
    }

    /**
     * The name of a protected role is put back from the record, always.
     *
     * Filament already drops it — `disabled()` also calls `saved(false)`, so the
     * key never arrives — and Filament's own source says to write this anyway:
     * the client can be made to send the field regardless. Which name a role
     * carries IS `roles.protected`, so renaming it off the list unprotects the
     * role, and that guarantee does not rest on how another package derives a
     * flag.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (RoleResource::isProtected($record)) {
            $data['name'] = $record->getAttribute('name');
        }

        return $data;
    }

    /**
     * The form catches up with what was actually written.
     *
     * `mutateFormDataBeforeSave()` rewrites the array that gets persisted, never
     * the browser's copy of `data.name`, so without this the screen keeps
     * showing a forged rename that never reached the store. The grid does not
     * need it: its field re-reads itself after every save.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }
}
