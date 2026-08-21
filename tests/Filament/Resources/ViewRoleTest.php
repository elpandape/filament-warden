<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

/**
 * The literal object the template hands alpine, decoded.
 *
 * `Js::from()` writes a json string inside a json string, so it comes back the
 * way it went in: once for the javascript literal, once for the payload. The
 * pattern is anchored on `state:` because `grid:` is a second `JSON.parse` on
 * the next line of the same attribute.
 *
 * @return array<string, mixed>
 */
function stateHandedTo(string $html): array
{
    $matches = [];

    preg_match("/state: JSON\.parse\('(.+?)'\)/", $html, $matches);

    $literal = json_decode('"'.($matches[1] ?? '').'"');

    /** @var array<string, mixed> $state */
    $state = json_decode(
        is_string($literal) && $literal !== '' ? $literal : '{}',
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $state;
}

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

test('the screen that only reads hands the browser what it drew', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('viewAny', roleClass());
    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    $state = stateHandedTo(livewire(ViewRole::class, ['record' => $role->getKey()])->html());

    expect(partOf($state, 'stances'))->toEqual([roleClass() => ['viewAny' => 'granted', 'update' => 'granted']])
        ->and(partOf($state, 'narrowing'))->toHaveKey(roleClass().'.update.mode', 'conditions');
});

test('both screens are handed the same payload, because it is worked out once', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($role)->to('viewAny', roleClass());
    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    $onThePage = stateHandedTo(livewire(ViewRole::class, ['record' => $role->getKey()])->html());

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSet('data.permissions', $onThePage);
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

test('only a role the installation protects is announced as protected', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('create', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $plain = makeRole('editor');
    $protected = makeRole('super-admin');

    livewire(ViewRole::class, ['record' => $plain->getKey()])
        ->assertSee('x-on:click="select(', escape: false)
        ->assertDontSee('fw-locked-notice', escape: false);

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false);

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->assertSee('fw-locked-notice', escape: false);

    livewire(CreateRole::class)
        ->assertDontSee('fw-locked-notice', escape: false);
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

test('a screen that only reads still says how far a rule reaches', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);
    Warden::allow($role)->to('update', roleClass())->where('name', 'editor');

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('callSchemaComponentMethod', 'infolist.permissions', 'narrowingFor', [roleClass(), 'update'])
        ->assertReturned(fn (array $narrowing): bool => partOf($narrowing, 'stored')['preview'] === 'name = editor');
});

test('the builder is on the read-only screen with nothing to operate', function (): void {
    $user = signIn();
    $role = makeRole();

    // Two gates, not one: the resource opens on `viewAny` and the record on `view`.
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', $role);

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertSee('fw-builder', escape: false)
        ->assertSee('interactive: false', escape: false);
});
