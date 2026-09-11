<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Policies;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * Registered by `FilamentWardenServiceProvider::registerPolicies()`, which
 * says why Laravel's own guessing never finds it.
 *
 * Six actions and no more. Neither model has soft deletes, so there is no restore
 * and no force delete; the grid neither reorders nor replicates. Under
 * `->strictAuthorization()`, asking for an action a policy does not declare
 * throws, so declaring more than exists is not free.
 *
 * The record is typed as a model and not as a role, because the class is
 * swappable and a valid replacement may compose warden's trait without
 * extending it.
 */
final class RolePolicy extends WardenPolicy
{
    public function viewAny(Model $authority): bool
    {
        return $this->allows($authority, 'viewAny', Context::resolve()->roleClass());
    }

    public function view(Model $authority, Model $role): bool
    {
        return $this->allows($authority, 'view', $role);
    }

    public function create(Model $authority): bool
    {
        return $this->allows($authority, 'create', Context::resolve()->roleClass());
    }

    public function update(Model $authority, Model $role): bool
    {
        return $this->allows($authority, 'update', $role);
    }

    public function delete(Model $authority, Model $role): bool
    {
        return $this->allows($authority, 'delete', $role);
    }

    public function deleteAny(Model $authority): bool
    {
        return $this->allows($authority, 'deleteAny', Context::resolve()->roleClass());
    }
}
