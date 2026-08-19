<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Facades\Filament;

pest()->extend(TestCase::class);

test('a page and a widget are named after the class, because neither is a model', function (): void {
    expect(PermissionName::page('App\Filament\Pages\Reports'))->toBe('page:App\Filament\Pages\Reports')
        ->and(PermissionName::widget('App\Filament\Widgets\Summary'))->toBe('widget:App\Filament\Widgets\Summary');
});

test('a panel door is named after the panel, so two panels are two doors', function (): void {
    expect(PermissionName::panel(Filament::getPanel('test')))->toBe('panel:test')
        ->and(PermissionName::panel(Filament::getPanel('bare')))->toBe('panel:bare');
});

test('an installation that already stored a name for its door keeps it', function (): void {
    config()->set('filament-warden.guard.panel', ['test' => 'viewAdminPanel']);

    expect(PermissionName::panel(Filament::getPanel('test')))->toBe('viewAdminPanel');
});
