<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\Warden\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Creates an authority for a test to grant things to.
 */
function makeUser(string $name = 'Amaru Quispe'): User
{
    return User::forceCreate([
        'name' => $name,
        'email' => Str::random(12).'@example.test',
        'password' => 'irrelevant',
    ]);
}

/**
 * The role and permission classes are swappable, so the suite asks for them the
 * same way the package does.
 *
 * @return class-string<Model>
 */
function roleClass(): string
{
    return Context::resolve()->roleClass();
}

/** @return class-string<Model> */
function permissionClass(): string
{
    return Context::resolve()->permissionClass();
}
