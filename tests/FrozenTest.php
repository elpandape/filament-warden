<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Catalog\Entry;
use ElPandaPe\FilamentWarden\Catalog\Origin;
use ElPandaPe\FilamentWarden\Catalog\PermissionName;
use ElPandaPe\FilamentWarden\Catalog\Scope;
use ElPandaPe\FilamentWarden\Console\AssignRoleCommand;
use ElPandaPe\FilamentWarden\Console\AuditCommand;
use ElPandaPe\FilamentWarden\Console\CatalogCommand;
use ElPandaPe\FilamentWarden\Filament\Forms\RoleAssignment;
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
 * on our side and loud on theirs. Adding one is a minor for them and a line
 * here somebody has to type: both key pins compare with `toBe()`, so a new key
 * goes red until it is written down on purpose.
 *
 * The config pin names key paths and the shape each one holds, and it stops at a
 * list and at an empty array: what goes inside those is the application's data,
 * not this package's schema. That is why it does not reuse `flattenKeys()`, and
 * why one test here compares the two — reusing it would drop eight of the
 * twenty-seven paths without a word.
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

test('a title this package generated is one of exactly four shapes', function (): void {
    // Compared as a SET, sorted, because every consumer asks this list for
    // membership — `in_array(…, true)` on the edit screen and `whereIn` on the
    // grid's save. Warden hands its half back current-first, so pinning the
    // order would go red on a warden release that reordered and decided
    // nothing, and a pin that cries wolf is a pin somebody updates without
    // reading. What must stay red is a shape LEAVING, which is how this broke
    // twice: warden's answer for today was taken live, so `2.0`'s `Str::snake()`
    // and `2.0.1`'s correction each REPLACED a shape rather than adding one, and
    // rows an installation had already been titled with fell out of the list.
    $shapes = PermissionName::generated('page:App\Filament\Pages\Settings');
    sort($shapes);

    expect($shapes)->toBe([
        'Access Settings',
        'Page: app\ filament\ pages\ settings',
        'Page:App\Filament\Pages\Settings',
        'Settings',
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
        'ElPandaPe\\FilamentWarden\\Filament\\RelationManagers\\PermissionsRelationManager',
        'ElPandaPe\\FilamentWarden\\Filament\\RelationManagers\\RolesRelationManager',
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
        ->toBe(['make', 'getId', 'register', 'boot', 'roles', 'permissions']);
});

test("the catalogue's public methods are exactly these, forget among them", function (): void {
    expect(get_class_methods(Catalog::class))
        ->toBe(['for', 'relationManagers', 'resourceClasses', 'pageClasses', 'widgetClasses', 'union', 'forget']);
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

    // `baseline` joined the envelope in 1.6.0 and `until` in 3.0.0, and adding
    // to a frozen surface is a minor by this package's own rule — an application
    // reading `stances` or `narrowing` goes on working. Each addition still
    // turns this red on purpose, so a new key is a line somebody typed rather
    // than a diff nobody read.
    //
    // Everything sits before `baseline`, and that order is load bearing:
    // `baseline` is a copy of everything in front of it, so a key added after it
    // would be missing from the copy and a save would read every cell carrying
    // it as one nobody touched.
    expect(array_keys(partOf(is_array($state) ? $state : [], 'permissions')))
        ->toBe(['stances', 'narrowing', 'until', 'inherited', 'baseline']);
});

test('the key the roles field keeps beside its own is frozen', function (): void {
    // The literal is the promise. `RoleAssignment::BASELINE` would follow a
    // rename and go green on the very change this exists to catch (§6.22), and
    // this key is not ours alone: from `1.7.0` it sits at the top level of an
    // application's own state array.
    expect(RoleAssignment::BASELINE)->toBe('__filament_warden_roles_baseline');
});

test('the config keys an application publishes are frozen', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__).'/config/filament-warden.php';

    expect(array_keys($config))
        ->toBe(['permissions', 'navigation', 'roles', 'grid', 'guard', 'catalog']);
});

test('every config key path is frozen, and so is the shape it holds', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__).'/config/filament-warden.php';

    expect(configPaths($config))->toBe([
        'permissions.create' => 'scalar',
        'permissions.update' => 'scalar',
        'permissions.delete' => 'scalar',
        'permissions.constraints' => 'scalar',
        'permissions.only_owned' => 'scalar',
        'permissions.probe' => 'scalar',
        'permissions.direct' => 'scalar',
        'navigation.group' => 'scalar',
        'navigation.roles.slug' => 'scalar',
        'navigation.roles.icon' => 'scalar',
        'navigation.roles.sort' => 'scalar',
        'navigation.permissions.slug' => 'scalar',
        'navigation.permissions.icon' => 'scalar',
        'navigation.permissions.sort' => 'scalar',
        'roles.create' => 'scalar',
        'roles.delete' => 'scalar',
        'roles.protected' => 'list',
        'grid.explain' => 'scalar',
        'grid.constraints' => 'scalar',
        'grid.expiry' => 'scalar',
        'grid.class_names' => 'scalar',
        'guard.panel' => 'empty',
        'guard.pages' => 'scalar',
        'guard.widgets' => 'scalar',
        'catalog.models' => 'empty',
        'catalog.custom' => 'empty',
        'catalog.scopes.read' => 'list',
        'catalog.scopes.write' => 'list',
        'catalog.scopes.withdraw' => 'list',
        'catalog.scopes.irreversible' => 'list',
    ]);
});

test('the config pin names a key that holds a list or an empty array, and never what is inside it', function (): void {
    /** @var array<string, mixed> $config */
    $config = require dirname(__DIR__).'/config/filament-warden.php';

    $pinned = array_keys(configPaths($config));
    $recursed = flattenKeys($config);

    expect(array_values(array_diff($pinned, $recursed)))->toBe([
        'roles.protected',
        'guard.panel',
        'catalog.models',
        'catalog.custom',
        'catalog.scopes.read',
        'catalog.scopes.write',
        'catalog.scopes.withdraw',
        'catalog.scopes.irreversible',
    ])
        ->and($recursed)->toContain('catalog.scopes.write.0')
        ->and($pinned)->not->toContain('catalog.scopes.write.0');
});

test('the translation keys an application overrides are frozen', function (): void {
    $expected = [
        'navigation.group',
        'resources.roles.model',
        'resources.roles.models',
        'resources.roles.sections.identity',
        'resources.roles.sections.inherits',
        'resources.roles.sections.inherits_help',
        'resources.roles.sections.hierarchy',
        'resources.roles.sections.holders',
        'resources.roles.fields.inherits',
        'resources.roles.fields.inherits_help',
        'resources.roles.fields.name',
        'resources.roles.fields.name_help',
        'resources.roles.fields.name_protected',
        'resources.roles.fields.title_help',
        'resources.roles.fields.title',
        'resources.roles.columns.role',
        'resources.roles.columns.rules',
        'resources.roles.columns.name',
        'resources.roles.columns.title',
        'resources.roles.columns.inherits',
        'resources.roles.columns.ending',
        'resources.roles.columns.held',
        'resources.roles.hierarchy.description',
        'resources.roles.hierarchy.none',
        'resources.roles.hierarchy.inherits',
        'resources.roles.hierarchy.reaching',
        'resources.roles.hierarchy.inherited_by',
        'resources.roles.hand_out.label',
        'resources.roles.hand_out.account',
        'resources.roles.hand_out.until',
        'resources.roles.hand_out.until_help',
        'resources.roles.holders.ending',
        'resources.roles.holders.description',
        'resources.roles.holders.nobody',
        'resources.roles.holders.held',
        'resources.roles.delete.nobody',
        'resources.roles.delete.holders',
        'resources.permissions.model',
        'resources.permissions.models',
        'resources.permissions.sections.identity',
        'resources.permissions.sections.reach',
        'resources.permissions.sections.held',
        'resources.permissions.sections.expiry',
        'resources.permissions.sections.holders',
        'resources.permissions.provenance.declared_by',
        'resources.permissions.provenance.undeclared',
        'resources.permissions.provenance.rule_help',
        'resources.permissions.entity.none',
        'resources.permissions.entity.any',
        'resources.permissions.entity.record',
        'resources.permissions.columns.title',
        'resources.permissions.columns.entity',
        'resources.permissions.columns.provenance',
        'resources.permissions.columns.reach',
        'resources.permissions.columns.health',
        'resources.permissions.columns.held',
        'resources.permissions.columns.held_count',
        'resources.permissions.columns.forbidden_count',
        'resources.permissions.expiry.none',
        'resources.permissions.expiry.some',
        'resources.permissions.health.unsatisfiable',
        'resources.permissions.health.warning',
        'resources.permissions.filters.held',
        'resources.permissions.filters.any',
        'resources.permissions.filters.held_yes',
        'resources.permissions.filters.unsatisfiable',
        'resources.permissions.filters.held_no',
        'resources.permissions.empty.heading',
        'resources.permissions.empty.description',
        'resources.permissions.create.subheading',
        'resources.permissions.fields.name',
        'resources.permissions.fields.name_help_derived',
        'resources.permissions.fields.name_help_loose',
        'resources.permissions.fields.name_help_held',
        'resources.permissions.fields.taken',
        'resources.permissions.fields.collides',
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
        'resources.permissions.holders.ending',
        'resources.permissions.holders.lapsed',
        'resources.permissions.holders.yes',
        'resources.permissions.holders.no',
        'resources.permissions.delete.nobody',
        'resources.permissions.delete.holders',
        'resources.permissions.grant.label',
        'resources.permissions.grant.description',
        'resources.permissions.grant.account',
        'resources.permissions.grant.polarity',
        'resources.permissions.grant.granted',
        'resources.permissions.grant.forbidden',
        'resources.permissions.grant.until',
        'resources.permissions.grant.until_help',
        'resources.permissions.grant.forbidden_help',
        'resources.permissions.grant.done',
        'resources.permissions.grant.done_granted',
        'resources.permissions.grant.done_forbidden',
        'resources.permissions.probe.label',
        'resources.permissions.probe.submit',
        'resources.permissions.probe.account',
        'resources.permissions.probe.record',
        'resources.permissions.probe.record_help',
        'resources.permissions.probe.description',
        'resources.permissions.probe.answer',
        'resources.permissions.probe.via',
        'resources.permissions.probe.until',
        'resources.permissions.probe.reach',
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
        'explain.decision',
        'explain.decisions.abstain',
        'explain.decisions.granted',
        'explain.decisions.forbidden',
        'explain.causes.granted-directly',
        'explain.causes.granted-via-role',
        'explain.causes.granted-to-everyone',
        'explain.causes.forbidden-directly',
        'explain.causes.forbidden-via-role',
        'explain.causes.forbidden-to-everyone',
        'explain.causes.conditions-not-met',
        'explain.causes.no-matching-grant',
        'explain.causes.not-applicable',
        'explain.empty',
        'explain.title',
        'explain.loading',
        'explain.unsaved',
        'explain.failed',
        'explain.expand',
        'explain.collapse',
        'explain.close',
        'explain.no_permission',
        'explain.narrowed',
        'explain.expires',
        'explain.expired',
        'explain.inherited',
        'explain.pending',
        'explain.stored',
        'explain.screen',
        'explain.save_hint',
        'explain.matched',
        'stances.abstain',
        'stances.granted',
        'stances.forbidden',
        'probe.reach.no_model',
        'probe.reach.no_trait',
        'probe.reach.failed',
        'probe.reach.counted',
        'probe.reach.partial',
        'probe.narrowed_label',
        'probe.narrowed',
        'probe.no_record',
        'probe.no_model',
        'probe.unresolved',
        'probe.via',
        'probe.via_restricted',
        'probe.until_grant',
        'probe.until_assignment',
        'probe.unreadable',
        'relations.permissions.label',
        'relations.permissions.polarity',
        'relations.permissions.grant.label',
        'relations.permissions.grant.heading',
        'relations.permissions.grant.description',
        'relations.permissions.grant.field',
        'relations.permissions.grant.until_help',
        'relations.permissions.grant.notified',
        'relations.permissions.revoke.label',
        'relations.permissions.revoke.description',
        'relations.permissions.revoke.notified',
        'relations.permissions.elsewhere',
        'relations.roles.label',
        'relations.roles.concurrent.kept_title',
        'relations.roles.concurrent.kept',
        'relations.roles.help',

        'relations.roles.protected',
        'relations.roles.restricted',
        'relations.roles.elsewhere',
        'relations.roles.held_column',
        'relations.roles.ends_column',
        'relations.roles.ends',
        'relations.roles.held.here',
        'relations.roles.held.elsewhere',
        'relations.roles.held.restricted',
        'relations.roles.assign.label',
        'relations.roles.assign.heading',
        'relations.roles.assign.field',
        'relations.roles.assign.until',
        'relations.roles.assign.until_help',
        'relations.roles.assign.notified',
        'relations.roles.renew.label',
        'relations.roles.renew.heading',
        'relations.roles.renew.help',
        'relations.roles.renew.notified',
        'relations.roles.retract.label',
        'relations.roles.retract.notified',
        'console.audit.unknown_panel',
        'console.audit.unmigrated',
        'console.audit.open',
        'console.audit.unpoliced',
        'console.audit.orphans',
        'console.audit.forgotten',
        'console.audit.strays',
        'console.audit.drifted',
        'console.audit.unkeyable',
        'console.audit.unownable',
        'console.audit.unwalkable',
        'console.audit.unsatisfiable',
        'console.audit.stranded',
        'console.audit.dormant',
        'console.audit.expired',
        'console.audit.misconfigured',
        'console.audit.clean',
        'console.catalog.heading',
        'console.catalog.unknown_panel',
        'console.assign.missing_role',
        'console.assign.missing_authority',
        'console.assign.done',
        'conditions.column',
        'conditions.comparison',
        'conditions.authority_field',
        'conditions.connector',
        'conditions.preview',
        'conditions.comparisons.=',
        'conditions.comparisons.!=',
        'conditions.comparisons.>',
        'conditions.comparisons.>=',
        'conditions.comparisons.<',
        'conditions.comparisons.<=',
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
        'conditions.no_ownership_resolver',
        'conditions.boolean',
        'conditions.boolean_column',
        'conditions.modes.all.name',
        'conditions.modes.all.hint',
        'conditions.modes.owned.name',
        'conditions.modes.owned.hint',
        'conditions.modes.conditions.name',
        'conditions.modes.conditions.hint',
        'conditions.impossible',
        'conditions.locked.corrupt',
        'conditions.locked.empty',
        'conditions.locked.shape',
        'conditions.locked.model',
        'conditions.locked.column',
        'conditions.locked.rewrite',
        'conditions.locked.owned_with_conditions',
        'conditions.locked.unsatisfiable',
        'conditions.locked.tangled',
        'conditions.locked.elsewhere',
        'grid.only.label',
        'grid.only.all',
        'grid.only.own',
        'grid.only.forbidden',
        'grid.only.narrowed',
        'grid.only.ending',
        'grid.only.inherited',
        'grid.only.stored',
        'grid.pending.title',
        'grid.pending.help',
        'grid.pending.reach',
        'grid.pending.until',
        'grid.panel',
        'grid.locked',
        'grid.read_only',
        'grid.read_description',
        'grid.description',
        'grid.label',
        'grid.entity',
        'grid.manage',
        'grid.undeclared',
        'grid.filter.label',
        'grid.filter.count',
        'grid.filter.empty',
        'grid.summary.ratio',
        'grid.summary.forbidden',
        'grid.presets.read',
        'grid.presets.all',
        'grid.presets.clear',
        'grid.concurrent.kept_title',
        'grid.concurrent.kept',
        'grid.concurrent.refused_title',
        'grid.concurrent.refused',
        'grid.concurrent.more',
        'grid.tangled.title',
        'grid.tangled.body',
        'grid.lapsed.title',
        'grid.lapsed.body',
        'grid.impossible.title',
        'grid.impossible.body',
        'grid.saved.granted',
        'grid.saved.forbidden',
        'grid.saved.revoked',
        'grid.until.label',
        'grid.until.forbidden',
        'grid.until.unwritten',
        'grid.until.off',
        'grid.mixing',
        'grid.wider',
        'grid.records',
        'grid.legend.abstains',
        'grid.legend.granted',
        'grid.legend.forbidden',
        'grid.legend.broader',
        'grid.legend.undeclared',
        'grid.legend.narrowed',
        'grid.legend.locked',
        'grid.legend.title',
        'grid.legend.set',
        'grid.legend.added',
        'grid.states.abstain',
        'grid.states.granted',
        'grid.states.forbidden',
        'grid.states.broader',
        'grid.states.narrowed',
        'grid.states.locked',
        'grid.states.undeclared',
        'grid.states.expires',
        'grid.states.inherited',
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
    $catalog = app(CatalogCommand::class);

    expect(array_keys(app(Kernel::class)->all()))
        ->toContain('filament-warden:assign')
        ->toContain('filament-warden:audit')
        ->toContain('filament-warden:catalog')
        ->and($assign->getName())->toBe('filament-warden:assign')
        ->and($audit->getName())->toBe('filament-warden:audit')
        ->and($catalog->getName())->toBe('filament-warden:catalog')
        ->and(array_keys($assign->getDefinition()->getArguments()))
        ->toBe(['role', 'authority'])
        ->and(array_keys($audit->getDefinition()->getOptions()))
        ->toContain('check')
        ->and(array_keys($catalog->getDefinition()->getOptions()))
        ->toContain('panel');
});
