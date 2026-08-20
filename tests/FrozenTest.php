<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Console\AssignRoleCommand;
use ElPandaPe\FilamentWarden\Console\AuditCommand;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;

/**
 * The promise, with something that breaks when it is broken.
 *
 * Everything pinned here is covered by semantic versioning from `1.0.0` on:
 * changing any of it is a MAJOR, and this file is what says so out loud. Two
 * different kinds of thing are in here and both matter for the same reason.
 *
 * The names are rows in somebody else's database. A permission called
 * `page:App\Filament\Pages\Settings` was granted to a role a year ago; renaming
 * the prefix does not fail, it stops matching — the permission stays, stays
 * grantable, and opens nothing.
 *
 * The keys are lines in somebody else's application: a published config, an
 * overridden translation, a command in a deploy script. Removing one is silent
 * on our side and loud on theirs.
 *
 * Nothing else is promised. `Grants\`, `Conditions\`, `Filament\Guard`,
 * `Filament\Forms\Grid\` and the rest of `Catalog\` are this package's insides
 * and move without warning.
 */
pest()->extend(TestCase::class);

test('the permission name prefixes are frozen', function (): void {
    expect(PermissionName::page('App\Filament\Pages\Settings'))
        ->toBe('page:App\Filament\Pages\Settings')
        ->and(PermissionName::widget('App\Filament\Widgets\Sales'))
        ->toBe('widget:App\Filament\Widgets\Sales')
        ->and(PermissionName::panel(Filament::getPanel('test')))
        ->toBe('panel:test');
});

test('a title this package generated is one of exactly three shapes', function (): void {
    expect(PermissionName::generated('page:App\Filament\Pages\Settings'))->toBe([
        'Page:App\Filament\Pages\Settings',
        'Settings',
        'Access Settings',
    ]);
});

test('the catalogue entry key is frozen', function (): void {
    $entry = new Entry('view', 'post', null, Scope::Read, Origin::Resource);

    expect($entry->key())->toBe('view|post')
        ->and(new Entry('page:X', null, null, Scope::Read, Origin::Page)->key())->toBe('page:X|');
});

test('the two enums the README teaches are frozen', function (): void {
    expect(array_map(fn (Origin $o): string => $o->value, Origin::cases()))
        ->toBe(['resource', 'model', 'page', 'widget', 'custom', 'panel'])
        ->and(array_map(fn (Scope $s): string => $s->value, Scope::cases()))
        ->toBe(['read', 'write', 'withdraw', 'irreversible']);
});

test('the config keys an application publishes are frozen', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__).'/config/filament-warden.php';

    expect(array_keys($config))
        ->toBe(['permissions', 'navigation', 'roles', 'grid', 'guard', 'catalog']);
});

test('the translation keys an application overrides are frozen', function (): void {
    $expected = [
        'navigation', 'resources', 'provenance', 'reach', 'tabs', 'scopes',
        'actions', 'explain', 'stances', 'probe', 'relations', 'console',
        'conditions', 'grid',
    ];

    foreach (['en', 'es'] as $locale) {
        /** @var array<string, mixed> $lines */
        $lines = require dirname(__DIR__)."/lang/{$locale}/ui.php";

        expect(array_keys($lines))->toBe($expected);
    }
});

test('the console commands are frozen', function (): void {
    $assign = app(AssignRoleCommand::class);
    $audit = app(AuditCommand::class);

    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('filament-warden:assign')
        ->toContain('filament-warden:audit')
        ->and($assign->getName())->toBe('filament-warden:assign')
        ->and($audit->getName())->toBe('filament-warden:audit')
        ->and(array_keys($assign->getDefinition()->getArguments()))
        ->toBe(['role', 'authority'])
        ->and(array_keys($audit->getDefinition()->getOptions()))
        ->toContain('check');
});
