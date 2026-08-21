<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The visibility is the guarantee, not decoration.
     *
     * A delete button asks `getDeleteAuthorizationResponse()`, which goes
     * straight to the policy: the resource's `canDelete()` — where the protected
     * list and the `roles.delete` rule live — is never on that path. Measured
     * with the plain `DeleteAction::make()` this page first carried: a protected
     * role was deleted outright from its own edit screen, and the next assertion
     * died with `ModelNotFoundException`.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
        ];
    }

    /**
     * The name of a protected role is put back from the record, always.
     *
     * Filament already drops it: `disabled()` also calls `saved(false)`, so the
     * field is not dehydrated and the key never arrives — measured, the forged
     * payload does not reach the store today. The check stays anyway, and
     * Filament's own source says to write it: the comment inside `disabled()`
     * spells out that the client can be made to send the field regardless, and
     * that authorization belongs in `mutateFormDataBeforeSave()`. Which name a
     * role carries is the whole of `roles.protected` — renaming it off the list
     * unprotects the role on the spot — so a guarantee about who can unlock the
     * most powerful role in the installation does not rest on how another
     * package derives a flag. It is the same reasoning as the `! isDisabled()`
     * check inside `PermissionGrid`.
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
}
