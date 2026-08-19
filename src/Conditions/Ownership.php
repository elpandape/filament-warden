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
 * believable when the table actually has it — `Context::ownershipAttribute()`
 * is typed `string` and falls back to the literal `user_id`, so the guess
 * cannot be turned off and a model without that column would be offered an
 * ownership rule that can never be true.
 */
final readonly class Ownership
{
    private function __construct(
        public bool $available,
        public ?string $column = null,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function of(string $model): self
    {
        $resolver = Context::resolve()->ownershipResolverFor(new $model);

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
