<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Infolists;

use ElPandaPe\FilamentWarden\Filament\Concerns\DrawsThePermissionGrid;
use Filament\Infolists\Components\ViewEntry;

/**
 * The grid on a screen that only reads.
 *
 * `view` and `update` are two permissions in warden, so somebody may be trusted
 * to read a role and not to change it. What they see is the same grid, drawn
 * from the store, with cells that select but do not cycle — understanding why a
 * cell is the way it is is reading, not operating.
 */
final class PermissionGridEntry extends ViewEntry
{
    use DrawsThePermissionGrid;

    protected string $view = 'filament-warden::infolists.permission-grid';

    /**
     * Nothing is pending on a screen that cannot change anything.
     *
     * @return array<string, array<string, string>>
     */
    protected function gridState(): array
    {
        return $this->storedState()->stances;
    }

    /**
     * Never. An entry is not a control, and there is nothing to ask.
     */
    protected function gridInteracts(): bool
    {
        return false;
    }
}
