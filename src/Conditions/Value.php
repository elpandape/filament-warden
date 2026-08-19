<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

/**
 * The value of a condition, between a text input and the store.
 *
 * Warden compares strictly, and the type is part of what identifies a twin
 * permission: `'2'` and `2` are two different rows carrying two different
 * conditions. A form sends everything as text, so the type is decided here,
 * once and always the same way — otherwise saving the same condition twice
 * would leave two twins behind.
 *
 * The rule is conservative: a value is converted only when casting it back to
 * text returns exactly what was typed. That is why `007` stays a string —
 * `(string) 7` is not `007` — and so does `2.50`. The two boolean words are the
 * named exception: `(string) true` is `1`, so no round trip could ever admit
 * them, and they are worth admitting because a boolean column is the most
 * common thing a condition compares against.
 */
final class Value
{
    public static function cast(string $typed): string|int|float|bool
    {
        if ($typed === 'true') {
            return true;
        }

        if ($typed === 'false') {
            return false;
        }

        if (! is_numeric($typed)) {
            return $typed;
        }

        $integer = (int) $typed;

        if ((string) $integer === $typed) {
            return $integer;
        }

        $float = (float) $typed;

        return (string) $float === $typed ? $float : $typed;
    }

    /**
     * The way back, so the input redraws what was typed.
     *
     * Anything that is not a scalar never came out of this builder — a null, an
     * array, a blob written by hand — and the answer is null so the caller can
     * say so, rather than showing an empty input that would overwrite it on the
     * next save.
     */
    public static function text(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $value,
            default => null,
        };
    }
}
