<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    /**
     * Nothing in warden invalidates the check cache for a write made through the
     * model layer: only its own fluent actions bump the version, and the
     * `PermissionCreated`/`PermissionDeleted` events have no listener anywhere.
     * Without this, every check goes on answering the old way — silently, and
     * with no expiry.
     */
    protected function afterCreate(): void
    {
        Warden::refresh();
    }

    /**
     * The catalogue's unique index, reported as a field error rather than a 500.
     *
     * Warden 2.0 put a unique index on `(name, identity_key)`, where the
     * identity is entity, record, ownership, tenant and a digest of the
     * canonical conditions. `PermissionForm::exists()` is the friendly
     * pre-check and it does not cover the whole index: it compares columns and
     * looks only at rows with no conditions on them, because until warden 2.0 a
     * twin was a row of its own and collided with nothing. Building the same
     * twin twice now reaches the database.
     *
     * The database is the authority and this is the courtesy: it turns every
     * collision path into the same field error, including the ones nobody has
     * enumerated. Aligning `exists()` with the index itself needs the value
     * `options` is ABOUT to take, which the builder's dehydration and
     * `mutateFormDataBeforeSave()` settle after this rule has run; that is a
     * change of its own and does not ride inside an upgrade.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'data.name' => __('filament-warden::ui.resources.permissions.fields.collides'),
            ]);
        }
    }
}
