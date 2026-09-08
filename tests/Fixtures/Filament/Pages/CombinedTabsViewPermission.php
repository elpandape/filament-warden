<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;

/**
 * The view screen as an application can configure it: relation managers folded
 * into tabs with the content.
 *
 * A page and not a config flag because that is how Filament exposes it — a
 * method on the page, returning false in its own concern. The bench has to
 * survive that arrangement, and the only way to say so is to build one.
 */
final class CombinedTabsViewPermission extends ViewPermission
{
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }
}
