<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Concerns;

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Support\Access;

/**
 * The `canAccess()` Filament does not write. Its own returns `true` literally,
 * and `->strictAuthorization()` never reaches a page.
 *
 * For standalone pages only. `Filament\Resources\Pages\Page::canAccess()` takes an
 * array of parameters, and a page of a resource is already covered by that
 * resource's own `canAccess()`.
 *
 * Not named `CanAuthorizeAccess`: livewire builds its lifecycle hook names out of
 * the trait's base name, and that one is already Filament's.
 */
trait AuthorizesPageAccess
{
    public static function canAccess(): bool
    {
        return Access::grantedToCurrentUser(PermissionName::page(static::class));
    }
}
