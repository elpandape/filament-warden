<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Support\Config;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;

pest()->extend(TestCase::class);

test('a key survives an application that cached its config before publishing', function (): void {
    config()->set('filament-warden', []);

    expect(Config::get('guard.pages'))->toBeTrue()
        ->and(Config::get('permissions.delete'))->toBe('orphaned');
});

test('a key an application did declare wins over the packaged default', function (): void {
    config()->set('filament-warden.permissions.delete', 'all');

    expect(Config::get('permissions.delete'))->toBe('all');
});

test('a key that is nowhere answers with the fallback the caller asked for', function (): void {
    expect(Config::get('nothing.here', 'fallback'))->toBe('fallback');
});

test('the scope map arrives keyed by scope, with only the action names in it', function (): void {
    expect(Config::scopes())->toBe([
        'read' => ['viewAny', 'view'],
        'write' => ['create', 'update'],
        'withdraw' => ['delete', 'restore'],
        'irreversible' => ['forceDelete'],
    ]);
});

test('a scope map an application filled with rubbish loses the rubbish and keeps the rest', function (): void {
    config()->set('filament-warden.catalog.scopes', [
        'read' => ['viewAny', 42],
        'write' => 'not-a-list',
        7 => ['view'],
    ]);

    expect(Config::scopes())->toBe(['read' => ['viewAny']]);
});

test('only declared models that are eloquent models are declared models', function (): void {
    config()->set('filament-warden.catalog.models', [User::class, 'NotAClass', 42]);

    expect(Config::models())->toBe([User::class]);
});

test('loose permissions arrive as name and scope, and anything else is dropped', function (): void {
    config()->set('filament-warden.catalog.custom', [
        'export-reports' => 'read',
        'close-month' => ['irreversible'],
        9 => 'read',
    ]);

    expect(Config::custom())->toBe(['export-reports' => 'read']);
});

test('a panel with no override has no stored name of its own', function (): void {
    expect(Config::panelPermission('test'))->toBeNull();
});

test('a panel an installation already had a name for keeps it', function (): void {
    config()->set('filament-warden.guard.panel', ['admin' => 'viewAdminPanel', 'empty' => '']);

    expect(Config::panelPermission('admin'))->toBe('viewAdminPanel')
        ->and(Config::panelPermission('empty'))->toBeNull();
});
