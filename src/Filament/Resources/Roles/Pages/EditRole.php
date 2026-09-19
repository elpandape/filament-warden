<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    public static bool $formActionsAreSticky = true;

    protected static string $resource = RoleResource::class;

    /**
     * One save, one warden operation.
     *
     * The record's own write, the grid and the inheritance each reach warden on
     * their own — the first as a catalog write through the model, the others as
     * calls — and warden names an operation for each. Inside one, they share
     * its id, so an audit log reads the save as the one act it was.
     */
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        Warden::operation(function () use ($shouldRedirect, $shouldSendSavedNotification): void {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        });
    }

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
            ViewAction::make(),

            DeleteAction::make()
                ->modalDescription(static fn (Model $record): string => RolesTable::warning($record))
                ->visible(fn (Model $record): bool => RoleResource::canDelete($record)),
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
     *
     * The inheritance is written HERE and not through the form's own state,
     * because the edges are rows in `assigned_roles` and not columns on this
     * record — the field is `dehydrated(false)` for exactly that reason, the
     * same way the grid is. Read from the RAW state: `getState()` drops a field
     * that does not dehydrate, which is the whole point of the flag and would
     * leave nothing here to write.
     */
    protected function afterSave(): void
    {
        $raw = $this->form->getRawState();

        Hierarchy::apply($this->getRecord(), is_array($raw) ? ($raw['inherits'] ?? null) : null);

        $this->fillForm();
    }

    /**
     * The plain "Saved" says a save happened; this says what it did.
     *
     * The words come from the field because the field is what computed them,
     * and they hang on this page's own notification rather than arriving as a
     * second toast — an ordinary save is the common case, and two notifications
     * for it would be noise. What the save MET stays with the field, which
     * sends its own only when there is something exceptional to say.
     */
    protected function getSavedNotification(): ?Notification
    {
        $notification = parent::getSavedNotification();
        $body = PermissionGrid::savedBody();

        return $notification instanceof Notification && $body !== null
            ? $notification->body($body)
            : $notification;
    }
}
