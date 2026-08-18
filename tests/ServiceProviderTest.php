<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\FilamentWardenServiceProvider;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

pest()->extend(TestCase::class);

test('a key the application never declared still answers with the package default', function (): void {
    expect(config('filament-warden.permissions.create'))->toBeFalse()
        ->and(config('filament-warden.permissions.update'))->toBe('loose')
        ->and(config('filament-warden.permissions.delete'))->toBe('orphaned')
        ->and(config('filament-warden.permissions.constraints'))->toBeTrue()
        ->and(config('filament-warden.roles.create'))->toBeTrue()
        ->and(config('filament-warden.roles.protected'))->toBe(['super-admin'])
        ->and(config('filament-warden.grid.explain'))->toBeTrue()
        ->and(config('filament-warden.guard.pages'))->toBeTrue();
});

test('an application that declares part of the config keeps its own values and inherits the rest', function (): void {
    config()->set('filament-warden', ['permissions' => ['create' => true]]);

    $this->app->register(FilamentWardenServiceProvider::class, true);

    expect(config('filament-warden.permissions.create'))->toBeTrue()
        ->and(config('filament-warden.roles.protected'))->toBe(['super-admin'])
        ->and(config('filament-warden.guard.pages'))->toBeTrue()
        ->and(config('filament-warden.grid.explain'))->toBeTrue();
});

test('declaring one block replaces that block whole, siblings inside it included', function (): void {
    config()->set('filament-warden', ['permissions' => ['create' => true]]);

    $this->app->register(FilamentWardenServiceProvider::class, true);

    expect(config('filament-warden.permissions.delete'))->toBeNull();
});

test('the package publishes its config and its translations, and nothing else', function (): void {
    $groups = ServiceProvider::publishableGroups();

    expect($groups)->toContain('filament-warden-config')
        ->and($groups)->toContain('filament-warden-translations');

    $config = ServiceProvider::pathsToPublish(FilamentWardenServiceProvider::class, 'filament-warden-config');
    $translations = ServiceProvider::pathsToPublish(FilamentWardenServiceProvider::class, 'filament-warden-translations');

    expect($config)->toHaveCount(1)
        ->and(Arr::first($config))->toEndWith('config/filament-warden.php')
        ->and($translations)->toHaveCount(1)
        ->and(Arr::first($translations))->toEndWith('vendor/filament-warden');
});

test('a tag the package never registered publishes nothing', function (): void {
    expect(ServiceProvider::pathsToPublish(FilamentWardenServiceProvider::class, 'filament-warden-views'))->toBeEmpty();
});
