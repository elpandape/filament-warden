<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Concerns;

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Support\Access;
use Filament\Panel;

/**
 * The door of the panel, for the account model to compose.
 *
 * It is the only check no policy declares, and therefore the likeliest place for
 * a silent typo: here the failure is closed and mute — nobody gets in and there is
 * no error to read. The name is in the catalogue for that reason.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait AccessesPanels
{
    public function canAccessPanel(Panel $panel): bool
    {
        return Access::granted($this, PermissionName::panel($panel));
    }
}
