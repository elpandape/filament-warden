<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Support;

use Illuminate\Support\Str;

/**
 * A translated line, under one of the two fallback policies this package has.
 *
 * Six places wrote one of these out, and the copies were not identical — which
 * is the part worth keeping rather than the count. There are two real policies,
 * and which one a caller needs depends on **whose key it is**:
 *
 * - `of()` is for a key this package ships. It must exist, so there is nothing
 *   to fall back to; the only guard is against a translation file handing back
 *   an array, which `__()` does when a published copy nests where ours does not.
 * - `orHumanize()` is for a key that may legitimately be missing, because it is
 *   named after something the application owns — a policy's own action, a column.
 *   `__()` answers with the key itself when it has no line, so "did this
 *   translate?" is a comparison against the key, and the fallback is a word made
 *   out of whatever the caller has.
 *
 * Collapsing the two would mean either printing `filament-warden::ui.x.y` at a
 * person, or humanising a key of ours that simply went missing and calling it a
 * translation.
 */
final class Line
{
    /**
     * @param  array<string, bool|float|int|string|null>  $replace
     */
    public static function of(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    public static function orHumanize(string $key, string $fallback): string
    {
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : Str::headline($fallback);
    }
}
