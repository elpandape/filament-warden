<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Tenants;
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
}
