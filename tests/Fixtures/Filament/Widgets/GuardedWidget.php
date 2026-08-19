<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets;

use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesWidgetView;
use Filament\Widgets\Widget;

final class GuardedWidget extends Widget
{
    use AuthorizesWidgetView;

    protected string $view = 'filament-panels::pages.page';
}
