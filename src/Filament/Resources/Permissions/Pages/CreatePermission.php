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
     * Warden does invalidate a write made through the model layer: its service
     * provider listens on the wildcard `eloquent.*` events and feeds
     * `CacheInvalidations`. What that listener never marks is a catalogue row —
     * `CacheInvalidations::isWardenRow()` admits only the configured grant and
     * assigned-role classes — so nothing bumps the version for this create.
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
