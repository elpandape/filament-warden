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
     * There was an `action(Entry)` here that applied it and that nothing called,
     * while the two places that build an action key did so without it. Both go
     * through this now. (`RoleGrants::of()` builds one bare as well, on the READ
     * half: a stored name with a dot lands in the state map, is drawn by
     * nothing, is emitted by nothing and counted by nothing, so it is left
     * alone rather than made to throw on a screen that is only reading.)
     * Routing them is defence in depth rather than a hole closed, and the
     * difference is worth writing down because the first version of this comment
     * claimed the hole: **an action name cannot carry a dot today**. Those names
     * come from `ReflectionMethod::getName()` on a policy, and
     * `public function export.csv()` is a PHP parse error; a `catalog.scopes`
     * entry that no policy declares never reaches a column, because both loops
     * in `GridView::groups()` gate on the reflected set.
     *
     * What IS reachable is a loose `catalog.custom` name with a dot, which
     * arrives as a ROW key and has always thrown here. `filament-warden:audit`
     * finds that one before a screen does, which is the part of this that
     * changed something.
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
