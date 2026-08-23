<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Forms\Grid;

use ElPandaPe\FilamentWarden\Catalog\Entry;
use LogicException;

/**
 * The keys a cell is filed under inside the field's state.
 *
 * Livewire splits a state path on dots, so a key carrying one would silently
 * address a nested array that does not exist. Neither a class name nor
 * `page:Foo\Bar` can hold a dot; a loose permission an application declared can,
 * and that fails loudly here rather than quietly in the browser.
 */
final class StateKey
{
    /**
     * The action a door is filed under: a page or a widget has exactly one.
     */
    public const string DOOR = 'access';

    /**
     * The extra column every entity row offers, filed under the very name warden
     * stores it as. Any word a person could write is a word a policy could
     * declare as an action, and then one cell on screen would drive two writes.
     */
    public const string MANAGE = '*';

    public static function row(Entry $entry): string
    {
        return self::guard($entry->model ?? $entry->name);
    }

    /**
     * The same guard, for a caller holding a bare name rather than an entry.
     *
     * There was a `action(Entry)` here that applied it and that nothing called:
     * `GridView` keys a door with `DOOR` and an entity's columns with the action
     * string straight off the catalogue, so the guard covered the ROW and left
     * the COLUMN unguarded — a policy declaring `export.csv` reached the browser
     * as a nested path that does not exist, quietly, while a model or loose name
     * with a dot threw. One guard, and both halves go through it.
     */
    public static function of(string $key): string
    {
        return self::guard($key);
    }

    /**
     * Whether a name can be a state key at all, asked rather than enforced.
     *
     * `filament-warden:audit` needs the question without the exception: a build
     * should go red on a name the grid cannot draw, not a person's screen.
     */
    public static function keyable(string $key): bool
    {
        return ! str_contains($key, '.');
    }

    private static function guard(string $key): string
    {
        if (! self::keyable($key)) {
            throw new LogicException(
                "The permission [{$key}] cannot be shown on the grid: livewire splits state paths on dots. Rename it.",
            );
        }

        return $key;
    }
}
