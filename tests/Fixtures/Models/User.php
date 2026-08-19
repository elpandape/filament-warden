<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Tests\Fixtures\Models;

use ElPandaPe\FilamentWarden\Concerns\AccessesPanels;
use ElPandaPe\Warden\Concerns\HasRolesAndPermissions;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable implements FilamentUser
{
    use AccessesPanels;
    use HasRolesAndPermissions;

    protected $guarded = [];
}
