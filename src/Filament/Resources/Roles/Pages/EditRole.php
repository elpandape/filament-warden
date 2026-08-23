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
     * The visibility is the guarantee, not decoration.
     *
     * A delete button asks `getDeleteAuthorizationResponse()`, which goes
     * straight to the policy: the resource's `canDelete()` — where the protected
     * list and the `roles.delete` rule live — is never on that path. Measured
     * with the plain `DeleteAction::make()` this page first carried: a protected
     * role was deleted outright from its own edit screen, and the next assertion
     * died with `ModelNotFoundException`. The description is `RolesTable`'s own
     * — the assignments go with it by a foreign key, below Eloquent and with no
     * event of their own, so this is the last moment anybody is told.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // The delete takes its assignments with it below Eloquent, and
            // nothing in warden bumps the version for a write made through the
            // model layer: without the hook every check goes on answering the old
            // way, silently and with no expiry. Void on purpose — whatever
            // `after()` returns stands in for the action's own result.
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

    /**
     * The rest of the form catches up with what actually got written.
     *
     * `mutateFormDataBeforeSave()` only reaches the record — it rewrites the
     * array `handleRecordUpdate()` persists, never the browser's own copy of
     * `data.name`. So a forged rename of a protected role never reaches the
     * store, but without this hook the screen keeps showing what was typed,
     * not what is actually there: the truth this whole version is about,
     * one field short. The permissions grid no longer needs this: its own
     * field re-reads itself and re-stamps its own baseline after every save
     * (`PermissionGrid::setUp()`), on any page that embeds it, this one
     * included.
     */
    protected function afterSave(): void
    {
        $this->fillForm();
    }
}
