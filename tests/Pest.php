<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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
 * Filament resolves the acting user through the panel's own guard, so a test that
 * authorizes anything has to sign in first.
 */
function signIn(?User $user = null): User
{
    $user ??= makeUser();

    Auth::login($user);

    return $user;
}

/**
 * Both are built through the configured class, never through the shipped one.
 */
function makeRole(string $name = 'editor'): Model
{
    $role = Warden::role(['name' => $name]);
    $role->save();

    return $role;
}

function makePermission(string $name = 'view'): Model
{
    $permission = Warden::permission(['name' => $name]);
    $permission->save();

    return $permission;
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

/**
 * One part of a payload the bridge answered with.
 *
 * An assertion callback receives a plain `array`, so everything inside it is
 * `mixed` and reading two levels down is not something static analysis will
 * allow. Narrowing once here keeps the tests readable.
 *
 * @param  array<mixed>  $payload
 * @return array<string, mixed>
 */
function partOf(array $payload, string $key): array
{
    $part = $payload[$key] ?? null;

    if (! is_array($part)) {
        return [];
    }

    $narrowed = [];

    foreach ($part as $name => $value) {
        $narrowed[(string) $name] = $value;
    }

    return $narrowed;
}

/**
 * @param  array<mixed>  $payload
 * @return list<string>
 */
function stringsOf(array $payload, string $key): array
{
    return array_values(array_filter(partOf($payload, $key), is_string(...)));
}
