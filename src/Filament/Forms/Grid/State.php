<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

/**
 * The shape the grid holds, whatever livewire hands over.
 *
 * A field whose state path is not a column of the record hydrates to null, and
 * `getRawState()` answers `[]`, `null` or `''` depending on how it was written.
 * All three mean the same thing here: the role says nothing yet.
 */
final class State
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function normalize(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $normalized = [];

        foreach ($state as $row => $actions) {
            if (! is_string($row) || ! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action => $stance) {
                if (is_string($action) && is_string($stance) && Stance::tryFrom($stance)?->isWritten()) {
                    $normalized[$row][$action] = $stance;
                }
            }
        }

        return $normalized;
    }
}
