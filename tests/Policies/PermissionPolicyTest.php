<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Filament\get_authorization_response;

pest()->extend(TestCase::class);

test('an authority with no grant is turned away from a class-wide action', function (string $ability): void {
    signIn();

    expect(get_authorization_response($ability, permissionClass())->denied())->toBeTrue();
})->with(['viewAny', 'create', 'deleteAny']);

test('a class-wide action answers what the store answers', function (string $ability): void {
    $user = signIn();

    Warden::allow($user)->to($ability, permissionClass());

    expect(get_authorization_response($ability, permissionClass())->allowed())->toBeTrue();
})->with(['viewAny', 'create', 'deleteAny']);

test('an authority with no grant is turned away from a record action', function (string $ability): void {
    signIn();

    expect(get_authorization_response($ability, makePermission())->denied())->toBeTrue();
})->with(['view', 'update', 'delete']);

test('a record action answers what the store answers', function (string $ability): void {
    $user = signIn();
    $permission = makePermission();

    Warden::allow($user)->to($ability, $permission);

    expect(get_authorization_response($ability, $permission)->allowed())->toBeTrue();
})->with(['view', 'update', 'delete']);

test('an explicit denial beats the grant that came before it', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::forbid($user)->to('viewAny', permissionClass());

    expect(get_authorization_response('viewAny', permissionClass())->denied())->toBeTrue();
});
