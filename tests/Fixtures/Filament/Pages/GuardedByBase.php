<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages;

use ElPandaPe\FilamentWarden\Filament\Concerns\AuthorizesPageAccess;
use Filament\Pages\Page;

/**
 * An application's own base page, deciding once for everything under it.
 *
 * The trait never reports itself as the declaring class, so what reflection sees
 * here is this class — which is the application's, and therefore a decision.
 */
abstract class GuardedByBase extends Page
{
    use AuthorizesPageAccess;
}
