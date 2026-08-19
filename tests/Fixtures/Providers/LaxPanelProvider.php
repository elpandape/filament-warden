<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Providers;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A panel without `->strictAuthorization()`, which is Filament's default and the
 * shape most applications ship. It exists so the suite can hold on to what
 * Filament does with a model that has no policy: it lets everyone through.
 */
final class LaxPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('lax')
            ->path('lax');
    }
}
