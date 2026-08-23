<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Grants;

use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Tenancy\Tenancy;

/**
 * Whether the screen is showing more than one tenant at once.
 *
 * With no tenant active and warden's shipped `scope.null_behavior` of `all`,
 * every read is unfiltered: the four tables come back whole, every tenant mixed
 * together. That is what the engine answers, so it is what the screen shows —
 * hiding rows would make the two disagree, and somebody would take a permission
 * away believing it was not there.
 *
 * What the screen owes is the sentence saying so.
 */
final class Tenants
{
    /**
     * Only true where it means something: an installation that never sets a
     * tenant and has no scoped row is not being told anything by this.
     */
    public static function mixing(): bool
    {
        // Not `current() === null`: that is only half the question. Without a
        // tenant, `scope.null_behavior` decides what a read sees, and warden
        // answers it in one place — `readFilter()` returns `['null', null]` under
        // `'strict'`, which is only the global rows, and null under the factory
        // `'all'`, which is no filter at all. Only the second is every tenant at
        // once, and only the second is what the sentence above the grid says.
        if (app(Tenancy::class)->readFilter() !== null) {
            return false;
        }

        $context = Context::resolve();

        return $context->grantClass()::query()->withoutGlobalScopes()->whereNotNull('scope')->exists()
            || $context->roleClass()::query()->withoutGlobalScopes()->whereNotNull('scope')->exists();
    }
}
