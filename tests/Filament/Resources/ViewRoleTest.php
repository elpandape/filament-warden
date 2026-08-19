<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('an authority the store never trusted with this role does not read it', function (): void {
    signIn();

    $role = makeRole();

    livewire(ViewRole::class, ['record' => $role->getKey()])->assertForbidden();
});

test('the listing opens a resource, and the record still has its own gate', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])->assertForbidden();
});

test('reading a role is its own permission, apart from changing it', function (): void {
    $user = signIn();
    $role = makeRole('editor');

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('editor')
        ->assertOk();
});

test('the screen draws what the role holds', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertSee('data-state="granted"', escape: false);
});

test('no cell of a screen that only reads is a control that cycles', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-locked', escape: false)
        ->assertDontSee('x-on:click="pick(', escape: false)
        ->assertSee('x-on:click="select(', escape: false);
});

test('the inspector answers here too, because understanding is reading', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::forbid($role)->to('delete', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'explainCell', [roleClass(), 'delete'])
        ->assertReturned(fn (array $why): bool => $why['verdict'] === 'forbidden');
});

test('a screen that cannot change anything never says something is unsaved', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'explainCell', [roleClass(), 'viewAny'])
        ->assertReturned(fn (array $why): bool => $why['pending'] === null);
});
