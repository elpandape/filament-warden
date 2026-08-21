<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Policies;

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Vault;

/**
 * A policy that declares `manage` itself. Nothing reserves the word, so an
 * application is free to take the one the wildcard column used to be filed
 * under — and then one cell on screen would drive two writes.
 */
final class VaultPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function manage(User $user, Vault $vault): bool
    {
        return false;
    }
}
