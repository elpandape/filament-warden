<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasRolesAndPermissions;

    protected $guarded = [];
}
