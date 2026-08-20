<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Tenants;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    /**
     * With no tenant active warden reads every tenant at once, so the listing
     * holds rows that belong to none of the one being looked at. Saying so is
     * cheaper than a reader working it out from a name.
     */
    public function getSubheading(): ?string
    {
        return Tenants::mixing() ? (string) __('filament-warden::ui.grid.mixing') : null;
    }

    /**
     * The same hole as the roles listing had: Filament's `getHeaderActions()`
     * defaults to an empty array and never adds a create action of its own.
     *
     * The visibility is written by hand because the button asks
     * `getCreateAuthorizationResponse()`, which goes straight to the policy and
     * never passes through the resource's `canCreate()` — and `permissions.create`,
     * false out of the box, lives there. Without this line a fresh installation
     * would be offered a button its own create page answers 403 to.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => PermissionResource::canCreate()),
        ];
    }
}
