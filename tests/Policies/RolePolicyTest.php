<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Filament\get_authorization_response;

pest()->extend(TestCase::class);

test('an authority with no grant is turned away from a class-wide action', function (string $ability): void {
    signIn();

    expect(get_authorization_response($ability, roleClass())->denied())->toBeTrue();
})->with(['viewAny', 'create', 'deleteAny']);

test('a class-wide action answers what the store answers', function (string $ability): void {
    $user = signIn();

    Warden::allow($user)->to($ability, roleClass());

    expect(get_authorization_response($ability, roleClass())->allowed())->toBeTrue();
})->with(['viewAny', 'create', 'deleteAny']);

test('an authority with no grant is turned away from a record action', function (string $ability): void {
    signIn();

    expect(get_authorization_response($ability, makeRole())->denied())->toBeTrue();
})->with(['view', 'update', 'delete']);

test('a record action answers what the store answers', function (string $ability): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to($ability, $role);

    expect(get_authorization_response($ability, $role)->allowed())->toBeTrue();
})->with(['view', 'update', 'delete']);

test('an explicit denial beats the grant that came before it', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::forbid($user)->to('viewAny', roleClass());

    expect(get_authorization_response('viewAny', roleClass())->denied())->toBeTrue();
});

test('a grant over one role says nothing about another', function (): void {
    $user = signIn();
    $editor = makeRole('editor');
    $auditor = makeRole('auditor');

    Warden::allow($user)->to('update', $editor);

    expect(get_authorization_response('update', $editor)->allowed())->toBeTrue()
        ->and(get_authorization_response('update', $auditor)->denied())->toBeTrue();
});
