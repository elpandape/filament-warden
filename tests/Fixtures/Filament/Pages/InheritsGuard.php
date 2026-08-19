<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

/**
 * A page that decides nothing itself and inherits a decision from its own base.
 */
final class InheritsGuard extends GuardedByBase
{
    protected string $view = 'filament-warden-tests::grid-host';
}
