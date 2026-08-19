<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Concerns;

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Support\Access;

/**
 * The `canView()` Filament does not write. Its own returns `true` literally.
 *
 * Note that on the first render a widget is filtered only by the page calling
 * this method: the widget's own livewire hook runs on hydration and no sooner.
 */
trait AuthorizesWidgetView
{
    public static function canView(): bool
    {
        return Access::grantedToCurrentUser(PermissionName::widget(static::class));
    }
}
