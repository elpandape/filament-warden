<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreatePermission extends CreateRecord
{
    public static bool $formActionsAreSticky = true;

    protected static string $resource = PermissionResource::class;

    /**
     * What this screen costs, said where somebody about to use it will read it.
     *
     * `permissions.create` is off out of the box, and while it is off this page
     * answers 403 — `CreateRecord::authorizeAccess()` asks the resource's
     * `canCreate()` — so the switch is on for anybody standing here. The
     * sentence is not about the key: it is about what a hand-written row IS.
     * Nothing derives it, so nothing asks for it until the installation's own
     * code does, and the audit reports it until something declares it —
     * `forgotten` while nobody holds it, `strays` once somebody does — except a
     * row over `*`, which the audit treats as deliberate: informational while
     * unheld, silent once held. A loose row is declared by
     * `catalog.custom`, and only then is it `loose`; one over a model, only by a
     * policy method. That is the part people find out a release later.
     *
     * Unconditional, unlike the listing's, whose sentence only holds while
     * warden is reading every tenant at once. This one is true every time this
     * page renders, because reaching it is what makes it true.
     */
    public function getSubheading(): ?string
    {
        return (string) __('filament-warden::ui.resources.permissions.create.subheading');
    }

    /**
     * The catalogue's unique index, reported as a field error rather than a 500.
     *
     * Warden's unique index is over `(name, identity_key)`, where the identity
     * is entity, record, ownership, tenant and a digest of the canonical
     * conditions. `PermissionForm::exists()` is the friendly pre-check and does
     * not cover the whole index: it cannot see a duplicate twin, for the reason
     * its docblock gives.
     *
     * The database is the authority and this is the courtesy: it turns every
     * collision path into the same field error, including the ones nobody has
     * enumerated.
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
