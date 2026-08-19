<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use Filament\Pages\Page;

final class GuardedPage extends Page
{
    use AuthorizesPageAccess;

    protected string $view = 'filament-panels::pages.page';
}
