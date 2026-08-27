<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Conditions;

use Closure;
use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether "only what it owns" can be offered for this model, and why not.
 *
 * A closure is the answer the application registered and is taken at its word:
 * there is no column to check. A string is a column name, and it is only
 * believable when the table actually has it.
 *
 * The two ways it can be unavailable are DIFFERENT and the screen has to be
 * able to tell them apart, which is why `$column` alone was not enough. Warden
 * answers `resolvesOwnershipFor()` false when nothing is registered for the
 * class, nothing is registered for `*`, and no default attribute is configured
 * — and in that case `ownershipResolverFor()` falls through to the EMPTY
 * STRING. Reading that as a column name gave the right answer for the wrong
 * reason: `in_array('', $columns)` is false, so the toggle was correctly
 * refused, while the sentence beside it said the table had no `` column,
 * naming a column nobody had asked for instead of saying that this installation
 * does not resolve ownership at all.
 */
final readonly class Ownership
{
    private function __construct(
        public bool $available,
        public ?string $column = null,
        public bool $resolved = true,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function of(string $model): self
    {
        $context = Context::resolve();
        $instance = new $model;

        // Asked first, so the empty-string fallback below is never mistaken for
        // a column name.
        if (! $context->resolvesOwnershipFor($instance)) {
            return new self(false, resolved: false);
        }

        $resolver = $context->ownershipResolverFor($instance);

        if ($resolver instanceof Closure) {
            return new self(true);
        }

        return new self(in_array($resolver, Columns::of($model), true), $resolver);
    }

    /**
     * There is no ownership to resolve where there is no model.
     */
    public static function unavailable(): self
    {
        return new self(false);
    }
}
