<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Console\AssignRoleCommand;
use ElPandaPe\FilamentWarden\Console\AuditCommand;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\FilamentWardenPlugin;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;

use function Pest\Livewire\livewire;

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

test('the class names an application writes are frozen', function (): void {
    $named = [
        'ElPandaPe\\FilamentWarden\\FilamentWardenPlugin',
        'ElPandaPe\\FilamentWarden\\Filament\\Forms\\PermissionGrid',
        'ElPandaPe\\FilamentWarden\\Filament\\Forms\\ConditionBuilder',
        'ElPandaPe\\FilamentWarden\\Filament\\Forms\\RoleAssignment',
        'ElPandaPe\\FilamentWarden\\Filament\\Infolists\\PermissionGridEntry',
        'ElPandaPe\\FilamentWarden\\Filament\\Concerns\\AuthorizesPageAccess',
        'ElPandaPe\\FilamentWarden\\Filament\\Concerns\\AuthorizesWidgetView',
        'ElPandaPe\\FilamentWarden\\Concerns\\AccessesPanels',
        'ElPandaPe\\FilamentWarden\\Policies\\WardenPolicy',
        'ElPandaPe\\FilamentWarden\\Support\\Access',
        'ElPandaPe\\FilamentWarden\\Catalog\\Catalog',
        'ElPandaPe\\FilamentWarden\\Catalog\\Entry',
        'ElPandaPe\\FilamentWarden\\Catalog\\Origin',
        'ElPandaPe\\FilamentWarden\\Catalog\\Scope',
        'ElPandaPe\\FilamentWarden\\Catalog\\PermissionName',
        'ElPandaPe\\FilamentWarden\\Exceptions\\PanelIsOpen',
    ];

    foreach ($named as $name) {
        expect(class_exists($name) || trait_exists($name) || enum_exists($name))
            ->toBeTrue($name.' is promised by the README and no longer answers to that name.');
    }

    expect(FilamentWardenPlugin::make()->getId())->toBe('filament-warden');
});

test('the plugin offers exactly the methods the README names', function (): void {
    expect(get_class_methods(FilamentWardenPlugin::class))
        ->toBe(['make', 'getId', 'register', 'boot']);
});

test('the state a grid field hands to a form is frozen', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();
    Warden::allow($role)->to('view', Post::class);

    $component = livewire(EditRole::class, ['record' => $role->getKey()]);
    $component->assertOk();

    $state = $component->get('data');

    expect(array_keys(partOf(is_array($state) ? $state : [], 'permissions')))
        ->toBe(['stances', 'narrowing']);
});

test('the config keys an application publishes are frozen', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__).'/config/filament-warden.php';

    expect(array_keys($config))
        ->toBe(['permissions', 'navigation', 'roles', 'grid', 'guard', 'catalog']);
});

test('the translation keys an application overrides are frozen', function (): void {
    $expected = [
        'navigation.group',
        'resources.roles.model',
        'resources.roles.models',
        'resources.roles.sections.identity',
        'resources.roles.fields.name',
        'resources.roles.fields.name_help',
        'resources.roles.fields.title_help',
        'resources.roles.fields.title',
        'resources.roles.columns.name',
        'resources.roles.columns.title',
        'resources.permissions.model',
        'resources.permissions.models',
        'resources.permissions.sections.identity',
        'resources.permissions.sections.reach',
        'resources.permissions.sections.holders',
        'resources.permissions.entity.none',
        'resources.permissions.entity.any',
        'resources.permissions.columns.title',
        'resources.permissions.columns.entity',
        'resources.permissions.columns.provenance',
        'resources.permissions.columns.reach',
        'resources.permissions.filters.held',
        'resources.permissions.filters.any',
        'resources.permissions.filters.held_yes',
        'resources.permissions.filters.held_no',
        'resources.permissions.fields.name',
        'resources.permissions.fields.name_help_derived',
        'resources.permissions.fields.name_help_loose',
        'resources.permissions.fields.taken',
        'resources.permissions.fields.title',
        'resources.permissions.fields.title_help',
        'resources.permissions.fields.entity',
        'resources.permissions.fields.entity_help',
        'resources.permissions.fields.only_owned',
        'resources.permissions.fields.only_owned_help',
        'resources.permissions.fields.only_owned_no_model',
        'resources.permissions.fields.conditions',
        'resources.permissions.fields.conditions_shared',
        'resources.permissions.holders.description',
        'resources.permissions.holders.every_tenant',
        'resources.permissions.holders.roles',
        'resources.permissions.holders.accounts',
        'resources.permissions.holders.everyone',
        'resources.permissions.holders.forbidden',
        'resources.permissions.holders.yes',
        'resources.permissions.holders.no',
        'resources.permissions.delete.nobody',
        'resources.permissions.delete.holders',
        'resources.permissions.probe.label',
        'resources.permissions.probe.submit',
        'resources.permissions.probe.account',
        'resources.permissions.probe.record',
        'resources.permissions.probe.record_help',
        'provenance.wildcard',
        'provenance.policy',
        'provenance.loose',
        'provenance.unknown',
        'reach.all',
        'reach.owned',
        'reach.conditions',
        'reach.unreadable',
        'reach.tangled',
        'reach.elsewhere',
        'tabs.resources',
        'tabs.pages',
        'tabs.widgets',
        'tabs.loose',
        'scopes.read',
        'scopes.write',
        'scopes.withdraw',
        'scopes.irreversible',
        'actions.viewAny',
        'actions.view',
        'actions.create',
        'actions.update',
        'actions.delete',
        'actions.deleteAny',
        'actions.restore',
        'actions.restoreAny',
        'actions.forceDelete',
        'actions.forceDeleteAny',
        'actions.reorder',
        'actions.replicate',
        'explain.causes.granted-directly',
        'explain.causes.granted-via-role',
        'explain.causes.granted-to-everyone',
        'explain.causes.forbidden-directly',
        'explain.causes.forbidden-via-role',
        'explain.causes.forbidden-to-everyone',
        'explain.causes.no-matching-grant',
        'explain.causes.not-applicable',
        'explain.empty',
        'explain.title',
        'explain.loading',
        'explain.no_permission',
        'explain.narrowed',
        'explain.pending',
        'stances.abstain',
        'stances.granted',
        'stances.forbidden',
        'probe.reach.no_model',
        'probe.reach.no_trait',
        'probe.reach.failed',
        'probe.reach.counted',
        'probe.reach.partial',
        'probe.narrowed',
        'probe.no_record',
        'probe.no_model',
        'probe.unresolved',
        'probe.unreadable',
        'relations.roles.label',
        'relations.roles.help',
        'relations.roles.protected',
        'relations.roles.restricted',
        'console.audit.open',
        'console.audit.unpoliced',
        'console.audit.orphans',
        'console.audit.strays',
        'console.audit.drifted',
        'console.audit.unwalkable',
        'console.audit.clean',
        'console.assign.missing_role',
        'console.assign.missing_authority',
        'console.assign.done',
        'conditions.scope',
        'conditions.if',
        'conditions.and',
        'conditions.or',
        'conditions.authority',
        'conditions.value',
        'conditions.drop',
        'conditions.add_value',
        'conditions.add_column',
        'conditions.empty',
        'conditions.warning',
        'conditions.no_model',
        'conditions.no_ownership',
        'conditions.modes.all.name',
        'conditions.modes.all.hint',
        'conditions.modes.owned.name',
        'conditions.modes.owned.hint',
        'conditions.modes.conditions.name',
        'conditions.modes.conditions.hint',
        'conditions.locked.corrupt',
        'conditions.locked.empty',
        'conditions.locked.shape',
        'conditions.locked.tangled',
        'conditions.locked.elsewhere',
        'grid.panel',
        'grid.locked',
        'grid.read_description',
        'grid.description',
        'grid.label',
        'grid.entity',
        'grid.manage',
        'grid.undeclared',
        'grid.presets.read',
        'grid.presets.all',
        'grid.presets.clear',
        'grid.mixing',
        'grid.wider',
        'grid.legend.abstains',
        'grid.legend.granted',
        'grid.legend.forbidden',
        'grid.legend.broader',
        'grid.legend.undeclared',
        'grid.legend.narrowed',
        'grid.legend.locked',
        'grid.shift',
    ];

    foreach (['en', 'es'] as $locale) {
        /** @var array<string, mixed> $lines */
        $lines = require dirname(__DIR__)."/lang/{$locale}/ui.php";

        expect(flattenKeys($lines))->toBe($expected);
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
