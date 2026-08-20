<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Resources\DocumentResource;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Team;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Facades\Filament;
use Filament\Panel;

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
