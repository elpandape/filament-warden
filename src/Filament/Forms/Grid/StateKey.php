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
     * Defence in depth on the action half, not a hole closed: an action name
     * comes from `ReflectionMethod::getName()` on a policy, and
     * `public function export.csv()` is a parse error. What IS reachable is a
     * loose `catalog.custom` name with a dot, which arrives as a ROW key.
     *
     * `RoleGrants::of()` builds one bare on the READ half on purpose: a stored
     * name with a dot is drawn by nothing and counted by nothing, so a screen
     * that is only reading is not made to throw over it.
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
