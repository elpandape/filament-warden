<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ListRoles;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Support\Icons\Heroicon;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('the resource points at the configured role model, never at a guessed one', function (): void {
    expect(RoleResource::getModel())->toBe(roleClass());
});

test('the url, the icon, the group and the sort all come from config', function (): void {
    expect(RoleResource::getSlug())->toBe('roles')
        ->and(RoleResource::getNavigationIcon())->toBe(Heroicon::OutlinedShieldCheck)
        ->and(RoleResource::getNavigationGroup())->toBe('Security')
        ->and(RoleResource::getNavigationSort())->toBeNull();

    config()->set('filament-warden.navigation', [
        'group' => 'Access',
        'roles' => ['slug' => 'access/roles', 'icon' => 'heroicon-o-key', 'sort' => 3],
    ]);

    expect(RoleResource::getNavigationIcon())->toBe('heroicon-o-key')
        ->and(RoleResource::getNavigationGroup())->toBe('Access')
        ->and(RoleResource::getNavigationSort())->toBe(3);
});

test('the labels are translated, so the screen speaks one language', function (): void {
    expect(RoleResource::getModelLabel())->toBe('Role')
        ->and(RoleResource::getPluralModelLabel())->toBe('Roles');

    app()->setLocale('es');

    expect(RoleResource::getModelLabel())->toBe('Rol');
});

test('an authority with no grant is kept out of the listing', function (): void {
    signIn();

    livewire(ListRoles::class)->assertForbidden();
});

test('an authority the store trusts sees the roles there are', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole('editor');

    livewire(ListRoles::class)
        ->assertCanSeeTableRecords([$role])
        ->assertOk();
});

test('the edit screen carries the grid', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists('permissions')
        ->assertSee('fw-grid', escape: false)
        ->assertOk();
});

test('saving the edit screen hands the grid to warden', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'editor',
            'permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('editor')
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();
});

test('a role is created with its name and its title', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'auditor', 'title' => 'Auditor'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(roleClass()::query()->where('name', 'auditor')->value('title'))->toBe('Auditor');
});

test('the config can close the door on creating roles at all', function (): void {
    $user = signIn();
    Warden::allow($user)->to('create', roleClass());

    expect(RoleResource::canCreate())->toBeTrue();

    config()->set('filament-warden.roles.create', false);

    expect(RoleResource::canCreate())->toBeFalse();
});

test('a protected role never leaves, whatever the policy says', function (): void {
    $user = signIn();
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('super-admin');

    expect(RoleResource::isProtected($role))->toBeTrue()
        ->and(RoleResource::canDelete($role))->toBeFalse();
});

test('deletion follows the rule the installation chose', function (bool|string $rule, bool $assigned, bool $deletable): void {
    $user = signIn();
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    if ($assigned) {
        Warden::assign($role)->to(makeUser('Holder'));
    }

    config()->set('filament-warden.roles.delete', $rule);

    expect(RoleResource::isDeletable($role))->toBe($deletable);
})->with([
    'never' => [false, false, false],
    'unassigned and free' => ['unassigned', false, true],
    'unassigned but held' => ['unassigned', true, false],
    'always' => ['all', true, true],
]);

test('a protected role cannot be renamed from the form', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'name',
            checkComponentUsing: fn (Filament\Forms\Components\TextInput $field): bool => $field->isDisabled(),
        );
});

test('a protected role shows its grid and does not let it be operated', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'permissions',
            checkComponentUsing: fn (ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid $field): bool => $field->isDisabled(),
        )
        ->assertSee('fw-locked', escape: false)
        ->assertDontSee('x-on:click="cycle(', escape: false);
});

test('saving a protected role leaves its permissions alone, whatever the browser sends', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('the title of a protected role is its own business, and it saves', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'title',
            checkComponentUsing: fn (Filament\Forms\Components\TextInput $field): bool => ! $field->isDisabled(),
        )
        ->fillForm(['title' => 'Quien lo puede todo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('title'))->toBe('Quien lo puede todo');
});

test('a role nobody protected is still operated as before', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]]])
        ->call('save');

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();
});

test('the form is two questions in two sections, not four stacked blocks', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSee('The role')
        ->assertSee('Permissions')
        ->assertSee('One row per entity');
});

test('a protected role does not take a condition from the payload either', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => [
            'stances' => [roleClass() => ['update' => 'granted']],
            'narrowing' => [roleClass() => ['update' => [
                'mode' => 'conditions',
                'rules' => [['logic' => 'and', 'kind' => 'value', 'column' => 'name', 'operator' => '=', 'value' => 'editor']],
            ]]],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Context::resolve()->grantClass()::query()->count())->toBe(2);
});
