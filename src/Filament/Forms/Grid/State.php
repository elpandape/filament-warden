<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The shape the grid holds, whatever livewire hands over.
 *
 * A field whose state path is not a column of the record hydrates to null, and
 * `getRawState()` answers `[]`, `null` or `''` depending on how it was written.
 * All three mean the same thing here: the role says nothing yet.
 *
 * A cell carries three things, so the state carries three maps: the stance, how
 * far it reaches, and when it stops.
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
     * When each cell stops, as the browser hands it back.
     *
     * An ABSENT map and a map MISSING A KEY are different answers, and the
     * difference is the same one `narrowings` carries: no map at all means the
     * screen never offered dates, so the store keeps what it has; a map without
     * this cell's key means the person cleared it. Reading the second as the
     * first would make a cleared date impossible to save; reading the first as
     * the second would drop every date on the grid the first time anybody saved
     * from a screen with the feature switched off.
     *
     * A value that will not parse is dropped rather than guessed at. The browser
     * is handed ISO 8601 and hands it back; anything else did not come from this
     * screen, and inventing a date for it is the one direction that can widen a
     * grant's life.
     *
     * @return array<string, array<string, CarbonImmutable>>
     */
    public static function untils(mixed $state): array
    {
        $raw = is_array($state) ? ($state['until'] ?? null) : null;
        $normalized = [];

        foreach (is_array($raw) ? $raw : [] as $row => $actions) {
            if (! is_string($row) || ! is_array($actions)) {
                continue;
            }

            foreach ($actions as $action => $moment) {
                if (! is_string($action) || ! is_string($moment) || $moment === '') {
                    continue;
                }

                try {
                    $normalized[$row][$action] = CarbonImmutable::parse($moment);
                } catch (Throwable) {
                    continue;
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
