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
     * The extra column every entity row offers, which warden stores as `*`.
     */
    public const string MANAGE = 'manage';

    public static function row(Entry $entry): string
    {
        return self::guard($entry->model ?? $entry->name);
    }

    public static function action(Entry $entry): string
    {
        return $entry->model === null ? self::DOOR : self::guard($entry->name);
    }

    private static function guard(string $key): string
    {
        if (str_contains($key, '.')) {
            throw new LogicException(
                "The permission [{$key}] cannot be shown on the grid: livewire splits state paths on dots. Rename it.",
            );
        }

        return $key;
    }
}
