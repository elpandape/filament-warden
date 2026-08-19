<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\GuardedWidget;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Filament\Widgets\Summary;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

pest()->extend(TestCase::class);

test('a guarded widget stays hidden from an authority the store never granted it', function (): void {
    signIn();

    expect(GuardedWidget::canView())->toBeFalse();
});

test('a guarded widget shows for the permission the catalogue offers for it', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::widget(GuardedWidget::class));

    expect(GuardedWidget::canView())->toBeTrue();
});

test('an explicit denial hides a widget that a grant had shown', function (): void {
    $user = signIn();

    Warden::allow($user)->to(PermissionName::widget(GuardedWidget::class));
    Warden::forbid($user)->to(PermissionName::widget(GuardedWidget::class));

    expect(GuardedWidget::canView())->toBeFalse();
});

test('a widget that never took the guard is the open door filament ships', function (): void {
    signIn();

    expect(Summary::canView())->toBeTrue();
});
