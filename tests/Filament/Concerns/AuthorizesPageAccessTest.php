<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\GuardedPage;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Pages\Reports;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

test('a guarded page keeps out an authority the store never granted it', function (): void {
    signIn();

    expect(GuardedPage::canAccess())->toBeFalse();
});

test('a guarded page opens for the permission the catalogue offers for it', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::page(GuardedPage::class));

    expect(GuardedPage::canAccess())->toBeTrue();
});

test('an explicit denial closes a page that a grant had opened', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::page(GuardedPage::class));
    Warden::forbid($user)->to(PermissionName::page(GuardedPage::class));

    expect(GuardedPage::canAccess())->toBeFalse();
});

test('nobody signed in gets into nothing', function (): void {
    expect(GuardedPage::canAccess())->toBeFalse();
});

test('a page that never took the guard is the open door filament ships', function (): void {
    signIn();

    expect(Reports::canAccess())->toBeTrue();
});

test('the guard is what filament aborts on when the screen is actually mounted', function (): void {
    signIn();

    livewire(GuardedPage::class)->assertForbidden();
});

test('and the same mount goes through once the store says so', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::page(GuardedPage::class));

    livewire(GuardedPage::class)->assertOk();

    livewire(Reports::class)->assertOk();
});
