<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Policies;

use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * The twin of the role policy, over the catalogue's own rows. What the screens
 * may do with them is narrowed further by config — a fresh installation cannot
 * create a permission by hand — but what exists is declared here.
 */
final class PermissionPolicy extends WardenPolicy
{
    public function viewAny(Model $authority): bool
    {
        return $this->allows($authority, 'viewAny', Context::resolve()->permissionClass());
    }

    public function view(Model $authority, Model $permission): bool
    {
        return $this->allows($authority, 'view', $permission);
    }

    public function create(Model $authority): bool
    {
        return $this->allows($authority, 'create', Context::resolve()->permissionClass());
    }

    public function update(Model $authority, Model $permission): bool
    {
        return $this->allows($authority, 'update', $permission);
    }

    public function delete(Model $authority, Model $permission): bool
    {
        return $this->allows($authority, 'delete', $permission);
    }

    public function deleteAny(Model $authority): bool
    {
        return $this->allows($authority, 'deleteAny', Context::resolve()->permissionClass());
    }
}
