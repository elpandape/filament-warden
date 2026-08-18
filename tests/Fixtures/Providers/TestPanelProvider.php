<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Providers;

use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use Filament\Panel;
use Filament\PanelProvider;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('test')
            ->path('test')
            // The panel this package is built for: without it Filament opens wide any
            // resource whose model has no policy, and in silence.
            ->strictAuthorization()
            ->plugin(FilamentWardenPlugin::make());
    }
}
