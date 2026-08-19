<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;

pest()->extend(TestCase::class);

test('the door of a panel is shut until the store opens it', function (): void {
    $user = makeUser();

    expect($user->canAccessPanel(Filament::getPanel('test')))->toBeFalse();

    Warden::allow($user)->to('panel:test');

    expect($user->canAccessPanel(Filament::getPanel('test')))->toBeTrue();
});

test('two panels are two doors', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('panel:test');

    expect($user->canAccessPanel(Filament::getPanel('test')))->toBeTrue()
        ->and($user->canAccessPanel(Filament::getPanel('bare')))->toBeFalse();
});

test('an installation that already stored a name for its door keeps opening with it', function (): void {
    config()->set('filament-warden.guard.panel', ['test' => 'viewAdminPanel']);

    $user = makeUser();

    Warden::allow($user)->to('viewAdminPanel');

    expect($user->canAccessPanel(Filament::getPanel('test')))->toBeTrue();
});

test('an explicit denial shuts a door a grant had opened', function (): void {
    $user = makeUser();

    Warden::allow($user)->to('panel:test');
    Warden::forbid($user)->to('panel:test');

    expect($user->canAccessPanel(Filament::getPanel('test')))->toBeFalse();
});
