<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ListRoles;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
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

test('the screen that changes a role is shut at both of its gates', function (): void {
    $user = signIn();
    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])->assertForbidden();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(EditRole::class, ['record' => $role->getKey()])->assertForbidden();

    Warden::allow($user)->to('update', $role);

    livewire(EditRole::class, ['record' => $role->getKey()])->assertOk();
});

test('the screen that mints a role is shut at both of its gates', function (): void {
    $user = signIn();

    livewire(CreateRole::class)->assertForbidden();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(CreateRole::class)->assertForbidden();

    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)->assertOk();
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

test('whoever may change a role hands out what it does not hold, itself included', function (): void {
    $user = signIn();
    $role = makeRole('editor');
    Warden::assign($role)->to($user);

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    expect(Access::granted($user, 'delete', roleClass()))->toBeFalse();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['delete' => 'granted']]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'delete', roleClass()))->toBeTrue();
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

test('the listing carries the way in, and only for an authority that may create', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    livewire(ListRoles::class)->assertActionHidden('create');

    Warden::allow($user)->to('create', roleClass());

    livewire(ListRoles::class)->assertActionVisible('create');
});

test('the config that closes creation takes the button with it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    config()->set('filament-warden.roles.create', false);

    livewire(ListRoles::class)->assertActionHidden('create');
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

test('a role held under another tenant is not offered for deletion', function (): void {
    $role = makeRole();
    $free = makeRole('unheld');

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    config()->set('filament-warden.roles.delete', 'unassigned');

    $held = Warden::tenant()->onceTo(8, static fn (): bool => RoleResource::isDeletable($role));
    $unheld = Warden::tenant()->onceTo(8, static fn (): bool => RoleResource::isDeletable($free));

    expect($held)->toBeFalse()
        ->and($unheld)->toBeTrue();
});

test('and a strict installation with no tenant active sees that assignment too', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    config()->set('filament-warden.roles.delete', 'unassigned');
    config()->set('warden.scope.null_behavior', 'strict');

    expect(RoleResource::isDeletable($role))->toBeFalse();
});

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

test('a role cannot be renamed onto the protected list', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($role->refresh()->getAttribute('name'))->toBe('editor')
        ->and(RoleResource::isProtected($role))->toBeFalse();
});

test('a role cannot be created onto the protected list', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'super-admin', 'title' => 'Born locked'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(roleClass()::query()->where('name', 'super-admin')->exists())->toBeFalse();
});

test('the refused names are the ones the installation listed, not a literal in the code', function (): void {
    config()->set('filament-warden.roles.protected', ['owner']);

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'owner'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($role->refresh()->getAttribute('name'))->toBe('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('super-admin')
        ->and(RoleResource::isProtected($role))->toBeFalse();
});

test('a protected role keeps its name, whatever the browser sends', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'owner'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('super-admin')
        ->and(RoleResource::isProtected($role))->toBeTrue();
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

test('the edit and view screens carry the actions that belong on them', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertActionVisible('delete');

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertActionVisible('edit')
        ->assertActionVisible('delete');
});

test('a protected role keeps its delete button off its own screens too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->assertActionHidden('delete');

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->assertActionHidden('delete');
});

test('a protected role survives the delete action on its own screens, not only the check', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($protected->getKey())->exists())->toBeTrue();
});

test('a protected role survives the delete action itself, not only the check', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');
    $plain = makeRole('editor');

    // Straight at livewire, the way a crafted request arrives: `callAction()`
    // asserts the action is visible before calling it, so it would prove the
    // button is hidden and nothing about whether the server refuses.
    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($protected)])
        ->call('callMountedAction', []);

    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($plain)])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($protected->getKey())->exists())->toBeTrue()
        ->and(roleClass()::query()->whereKey($plain->getKey())->exists())->toBeFalse();
});

test('deleting a role from the listing reaches the store, which nothing in warden invalidates', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    // Warmed on purpose: the check is answered from the cache from here on, and
    // only warden's own fluent actions bump the version behind it.
    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($role)])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('deleting a role from its edit screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('deleting a role from its view screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('the refusal names the protected list, in words this package wrote', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasFormErrors([
            'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
        ]);

    expect($role->refresh()->getAttribute('name'))->toBe('editor');
});

test('the create screen refuses with the same sentence, not a different one', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'super-admin', 'title' => 'Born locked'])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
        ]);

    expect(roleClass()::query()->where('name', 'super-admin')->exists())->toBeFalse();
});

test('a list with several names on it still refuses in one sentence', function (): void {
    config()->set('filament-warden.roles.protected', ['owner', 'super-admin', 'auditor']);

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('auditor');

    foreach (['owner', 'super-admin'] as $name) {
        livewire(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['name' => $name])
            ->call('save')
            ->assertHasFormErrors([
                'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
            ]);
    }

    expect($role->refresh()->getAttribute('name'))->toBe('auditor');
});

test('a list with nothing left on it refuses nothing and says nothing', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['title' => 'Whoever can do everything'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDontSee('roles.protected lists this name');

    expect($role->refresh()->getAttribute('title'))->toBe('Whoever can do everything');
});
