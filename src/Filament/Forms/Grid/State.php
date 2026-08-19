<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

/**
 * The shape the grid holds, whatever livewire hands over.
 *
 * A field whose state path is not a column of the record hydrates to null, and
 * `getRawState()` answers `[]`, `null` or `''` depending on how it was written.
 * All three mean the same thing here: the role says nothing yet.
 *
 * A cell carries two things, so the state carries two maps: the stance, and how
 * far it reaches.
 */
final class State
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function stances(mixed $state): array
    {
        return self::normalize(is_array($state) ? ($state['stances'] ?? null) : null);
    }

    /**
     * The reach arrives raw. Checking it means knowing which table it is about,
     * and that is the catalogue's answer, not this class's.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function narrowings(mixed $state): array
    {
        $raw = is_array($state) ? ($state['narrowing'] ?? null) : null;
        $normalized = [];

        foreach (is_array($raw) ? $raw : [] as $row => $actions) {
            if (! is_string($row) || ! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action => $narrowing) {
                if (is_string($action)) {
                    $normalized[$row][$action] = $narrowing;
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function normalize(mixed $state): array
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
