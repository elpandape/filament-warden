<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Grants\Tenants;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\DocumentResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Livewire\GridHost;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Team;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
use Filament\Panel;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function tenantPanel(): Panel
{
    $panel = Panel::make()
        ->id('tenanted')
        ->tenant(Team::class)
        ->resources([DocumentResource::class])
        ->plugin(FilamentWardenPlugin::make());

    $panel->boot();

    // Three things have to be true before Filament's scope bites, and a test that
    // sets fewer proves nothing: the panel must be booted, it must be the CURRENT
    // panel — the closure compares object identity, so the object goes in, not its
    // id — and a tenant must be set.
    Filament::setCurrentPanel($panel);
    Filament::setTenant(Team::query()->create(['name' => 'Acme']), isQuiet: true);

    return $panel;
}

test('a panel with a tenant does not poison warden own models', function (): void {
    tenantPanel();

    // With `$isScopedToTenant` gone, all three of these throw a LogicException:
    // Filament's scope lives on the MODEL and demands a `team` relationship.
    expect(roleClass()::query()->count())->toBe(0)
        ->and(permissionClass()::query()->count())->toBe(0)
        ->and(RoleResource::getEloquentQuery()->count())->toBe(0);

    makeRole('editor');

    expect(roleClass()::query()->count())->toBe(1);
});

test('and the resources of the application keep their tenant, which is not ours to remove', function (): void {
    tenantPanel();

    expect(RoleResource::isScopedToTenant())->toBeFalse()
        ->and(PermissionResource::isScopedToTenant())->toBeFalse()
        ->and(DocumentResource::isScopedToTenant())->toBeTrue()
        ->and(Document::hasGlobalScope('tenanted_tenancy'))->toBeTrue()
        ->and(roleClass()::hasGlobalScope('tenanted_tenancy'))->toBeFalse();
});

test('with no tenant active the grid says it is showing every tenant at once', function (): void {
    $role = makeRole();

    expect(Tenants::mixing())->toBeFalse();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', roleClass());
    });

    expect(Tenants::mixing())->toBeTrue();
});

test('and with a tenant active it says nothing, because it is not mixing', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', roleClass());
    });

    $mixing = Warden::tenant()->onceTo(7, static fn (): bool => Tenants::mixing());

    expect($mixing)->toBeFalse();
});

test('the grid draws the warning where a person is already reading', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('viewAny', roleClass());
    });

    livewire(GridHost::class, ['roleKey' => $role->getKey()])
        ->assertSee('No tenant is active');
});
