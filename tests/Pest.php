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

/**
 * A record key in the shape livewire sends it, narrowed so static analysis can
 * read it: `getKey()` is `mixed`, and a key that does not look like a key would
 * address no row at all.
 */
function recordKey(Model $model): string
{
    $key = $model->getKey();

    return is_int($key) || is_string($key) ? (string) $key : '';
}

/**
 * The account the suite signed in, typed as a model so warden's actions accept it.
 */
function signedIn(): Model
{
    $user = Auth::user();

    return $user instanceof Model ? $user : makeUser();
}

/**
 * Every key path of a nested translation array, dotted and in file order.
 *
 * @param  array<string, mixed>  $lines
 * @return list<string>
 */
function flattenKeys(array $lines, string $prefix = ''): array
{
    $keys = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        /** @var array<string, mixed>|null $nested */
        $nested = is_array($value) ? $value : null;

        $keys = $nested === null
            ? array_merge($keys, [$path])
            : array_merge($keys, flattenKeys($nested, $path));
    }

    return $keys;
}

/**
 * The newest catalogue row a fluent chain just wrote, by the name it carries.
 *
 * Five test files each carried their own copy of this query, and every copy
 * carried the same three clauses that make the read honest: `withoutGlobalScopes()`
 * because all four warden models carry `TenantScope`, `orderByDesc('id')` because
 * a chain that narrows leaves a twin behind and the twin is the newer row, and
 * `firstOrFail()` so a missing row fails here instead of as a null two lines on.
 */
function latestPermission(?string $name = null): Model
{
    return permissionClass()::query()
        ->withoutGlobalScopes()
        ->when($name !== null, fn ($query) => $query->where('name', $name))
        ->orderByDesc('id')
        ->firstOrFail();
}
