<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\GuardedWidget;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use Filament\Pages\Page;
use Filament\Widgets\Widget;

/**
 * A screen carrying both widgets, so one render asks both questions.
 *
 * It takes the page guard rather than leaving the door open: `Reports` is
 * already the fixture for a screen nobody decided about, and a second one would
 * be one more open door the boot guard has to be kept away from.
 */
final class WidgetHost extends Page
{
    use AuthorizesPageAccess;

    protected string $view = 'filament-panels::pages.page';

    /**
     * @return array<class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [GuardedWidget::class, Summary::class];
    }
}
