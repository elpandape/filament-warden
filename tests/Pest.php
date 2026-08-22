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

/**
 * Every key path of a config array, dotted and in file order, with the shape of what it holds.
 *
 * Deliberately not `flattenKeys()`, and the difference is the whole point. That helper recurses
 * into any array, so a key whose value is an empty array yields no entry at all and drops out of
 * the promise in silence, while a key whose value is a list explodes into `.0` and `.1` entries
 * that pin values instead of paths — and loses the list's own path on the way. On the packaged
 * config that is eight of the twenty-seven leaves gone and eleven value entries invented, and the
 * eight are `roles.protected`, `guard.panel`, `catalog.models`, `catalog.custom` and the four
 * scope buckets. Those are the blocks §6.1 warns about, and the buckets are the only keys that
 * feed `Scope`. One test in `FrozenTest` asserts that difference rather than trusting this note.
 * `Arr::dot()` is a half fix: it keeps the empty array and still explodes the list.
 *
 * The stop rule is one predicate: descend only into a non-empty array with string keys, because
 * `array_is_list()` answers true for the empty array and one branch covers both cases. It encodes
 * "string keys are our schema, integer keys are the application's data". The day a default ships
 * a non-empty `guard.panel`, this walks into it and the pin grows an entry — which goes red, and
 * is meant to.
 *
 * @param  array<string, mixed>  $values
 * @return array<string, string> path => `scalar`, `list` or `empty`
 */
function configPaths(array $values, string $prefix = ''): array
{
    $paths = [];

    foreach ($values as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (! is_array($value)) {
            $paths[$path] = 'scalar';

            continue;
        }

        if ($value === []) {
            $paths[$path] = 'empty';

            continue;
        }

        if (array_is_list($value)) {
            $paths[$path] = 'list';

            continue;
        }

        /** @var array<string, mixed> $value */
        $paths = array_merge($paths, configPaths($value, $path));
    }

    return $paths;
}
