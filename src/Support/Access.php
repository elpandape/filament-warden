<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Support;

use ElPandaPe\Warden\Contracts\Resolver;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a screen asks the store. Never the gate: a loose permission has
 * no policy to answer it, and going through the gate would make the answer depend
 * on whether warden's gate hook is registered at all.
 */
final class Access
{
    public static function granted(Model $authority, string $permission, Model|string|null $entity = null): bool
    {
        // Resolved per check: the binding is scoped, and a captured instance would
        // go stale between octane requests.
        return app(Resolver::class)->resolve($authority, $permission, $entity)->isGranted();
    }

    /**
     * Filament resolves the acting user through the panel's own guard, which is
     * not necessarily the default one.
     */
    public static function grantedToCurrentUser(string $permission, Model|string|null $entity = null): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof Model && self::granted($user, $permission, $entity);
    }
}
