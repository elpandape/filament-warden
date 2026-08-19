<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\GridHost;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('the field keeps itself out of the data a record is updated with', function (): void {
    $field = PermissionGrid::make('permissions');

    expect($field->isDehydrated())->toBeFalse()
        ->and($field->isSaved())->toBeTrue();
});

test('the grid fills itself from the store, not from the record', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions.'.roleClass().'.viewAny', 'granted');
});

test('a role that holds nothing opens on an empty grid', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSet('data.permissions', []);
});

test('saving writes what the grid says through warden', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => [roleClass() => ['viewAny' => 'granted']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeTrue();
});

test('saving takes away what the grid stopped saying', function (): void {
    $role = makeRole();
    $user = makeUser();
    Warden::assign($role)->to($user);

    Warden::allow($role)->to('viewAny', roleClass());

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->fillForm(['permissions' => [roleClass() => ['viewAny' => 'abstain']]])
        ->call('save');

    expect(Access::granted($user, 'viewAny', roleClass()))->toBeFalse();
});

test('the field renders every tab of the catalogue at once', function (): void {
    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-grid', escape: false)
        ->assertSee('data-fw-action="viewAny"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::MANAGE.'"', escape: false)
        ->assertSee('data-fw-action="'.StateKey::DOOR.'"', escape: false);
});

test('a cell the policy does not declare renders as a dot and not as a control', function (): void {
    config()->set('filament-warden.catalog.models', [ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Tag::class]);

    $role = makeRole();

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-void', escape: false);
});

test('a narrowed cell renders disabled, so a save cannot touch it', function (): void {
    $role = makeRole();

    Warden::allow($role)->to('update', roleClass())->where('id', 1);

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('fw-noted', escape: false);
});

test('what the field offers is what the catalogue holds', function (): void {
    $role = makeRole();

    $grid = PermissionGrid::make('permissions');

    expect(RoleGrants::of($role, Catalog::for(Filament::getPanel('test')))->stances)->toBeEmpty()
        ->and($grid->getName())->toBe('permissions');
});
