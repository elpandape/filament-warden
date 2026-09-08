<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages;

use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Grants\Hierarchy;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The inheritance the form offered, written after the role exists.
     *
     * The edges are rows in `assigned_roles` and not columns on this record, so
     * the field is `dehydrated(false)` — which is right, and is exactly why it
     * needs a hook of its own here. Without one the select was drawn, filled in,
     * and its picks dropped on the floor: the state never reached the record and
     * nothing else read it. Measured on the create screen with nesting on, and
     * it is the reason this method exists.
     *
     * Read from the RAW state for the same reason `EditRole::afterSave()` does:
     * `getState()` drops a field that does not dehydrate, so there would be
     * nothing left here to write.
     *
     * After creation and not before: `Warden::assign()` needs a saved model on
     * both sides, and on `beforeCreate` there is no role yet to assign anything
     * to.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        // Typed `?Model` by `CreateRecord` and never null on this hook — it runs
        // after the record exists, which is the whole reason it is this hook and
        // not `beforeCreate`. Narrowed rather than asserted so `level: max` has
        // no branch to complain about and none is invented for it either.
        if (! $record instanceof Model) {
            return; // @codeCoverageIgnore
        }

        $raw = $this->form->getRawState();

        Hierarchy::apply($record, is_array($raw) ? ($raw['inherits'] ?? null) : null);
    }

    /**
     * The same sentence the edit screen says, for the same reason.
     *
     * A role created with cells already ticked wrote them here, so the save
     * that made it has as much to report as any later one — and saying nothing
     * on the way in would make the grid look like it did not take.
     */
    protected function getCreatedNotification(): ?Notification
    {
        $notification = parent::getCreatedNotification();
        $body = PermissionGrid::savedBody();

        return $notification instanceof Notification && $body !== null
            ? $notification->body($body)
            : $notification;
    }
}
