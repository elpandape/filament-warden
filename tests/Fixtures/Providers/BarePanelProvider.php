<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A panel without the plugin, so a test can tell what the plugin adds from what
 * Filament brought on its own. Deliberately not the default one.
 */
final class BarePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('bare')
            ->path('bare')
            ->strictAuthorization();
    }
}
