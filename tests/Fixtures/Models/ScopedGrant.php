<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use ElPandaPe\Warden\Models\Grant;
use Illuminate\Database\Eloquent\Builder;

/**
 * A grant model an application swapped in, carrying a global scope of its own.
 *
 * Warden reads grants through Eloquent, so a scope like this one applies to the
 * resolver's own reads — which is what makes it the honest way to tell a query
 * that drops EVERY scope apart from one that drops only warden's tenancy. A
 * screen that drops every scope draws rows the resolver will never answer with.
 *
 * `forbidden` is the column it hides on because a fixture needs a column that is
 * already there and already meaningful; nothing about this scope is a shape a
 * real application would want.
 */
final class ScopedGrant extends Grant
{
    protected static function booted(): void
    {
        self::addGlobalScope('hides-prohibitions', static function (Builder $query): void {
            $query->where('forbidden', false);
        });
    }
}
