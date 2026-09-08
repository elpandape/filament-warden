<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\CreatePermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\EditPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ListPermissions;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use ElPandaPe\Warden\Support\Titles\PermissionTitle;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/**
 * `assertSee()` after `mountAction` never renders a modal's body in this
 * harness — the modal markup is not part of `Testable::html()`, confirmed by
 * dumping the full HTML of a mounted delete action and finding no `fi-modal`
 * in it at all. The only way to prove a `modalDescription` closure is WIRED to
 * a given action, rather than merely correct in isolation (which the direct
 * `PermissionsTable::warning()` calls elsewhere in this file already cover),
 * is to resolve the action object itself and read `getModalDescription()` off
 * it: `assertActionExists()` for a page's own action,
 * `assertTableActionExists(..., record: $permission)` for the listing's.
 *
 * `PermissionForm::conditionsHelp()` asks two different questions and only
 * one of them is live. `self::model($get)` reads the FORM's current
 * `entity_type` and answers `ui.conditions.no_model` the moment it does not
 * resolve — including the row's own starting value, which shadows every
 * other reason. `lockedReason($record)`, reached only once that live entity
 * DOES resolve, reads the RECORD's stored `entity_type` instead and answers
 * `locked.model` when THAT one does not. So the only way to see `locked.model`
 * is to store a row against an entity that no longer resolves and then, in
 * the open form and without saving, move the live field to one that does.
 * `fillForm()` disables the field's own state-update hooks for the duration
 * (`disableSchemaStateUpdateHooksForTesting`), so the `afterStateUpdated` that
 * would otherwise blank `options` on a live entity change never fires, and the
 * stored row survives to be read back by `lockedReason()`.
 *
 * A check answered once is answered from the cache from there on, and only
 * warden's own fluent actions bump the version behind it — which is why the
 * tests here that mean to exercise an invalidation warm the check first, on
 * purpose. Said here rather than beside each of them.
 */
pest()->extend(TestCase::class);

function heldRow(string $name = 'viewAny'): Model
{
    return latestPermission($name);
}

function grantReads(): int
{
    $table = new (Context::resolve()->grantClass())()->getTable();
    $reads = 0;

    // Larastan types each entry as `array{query: string, bindings: array,
    // time: float|null}`, so at `level: max` a defensive `is_array()` /
    // `is_string()` around it is dead code the analyser refuses to pass.
    foreach (DB::getQueryLog() as $entry) {
        if (str_contains($entry['query'], $table)) {
            $reads++;
        }
    }

    return $reads;
}

/**
 * A row carrying a raw `options` blob written straight onto the column, past
 * `ConstraintSerializer` and past `ConditionBuilder` entirely: what the tests
 * in this file need to read is a shape the builder itself would never write —
 * a value that changes type on the way back, or a column the table dropped —
 * and the fluent API has no way to ask for either on purpose.
 *
 * @param  class-string<Model>  $model
 * @param  array<string, mixed>  $options
 */
function permissionWithOptions(string $model, array $options): Model
{
    $permission = makePermission();
    $permission->update([
        'entity_type' => new $model()->getMorphClass(),
        'options' => $options,
    ]);

    return $permission;
}

test('the resource points at the configured permission model, never at a guessed one', function (): void {
    expect(PermissionResource::getModel())->toBe(permissionClass());
});

test('the url, the icon, the group and the sort all come from config', function (): void {
    expect(PermissionResource::getSlug())->toBe('permissions')
        ->and(PermissionResource::getNavigationIcon())->toBe(Heroicon::OutlinedKey)
        ->and(PermissionResource::getNavigationGroup())->toBe('Security')
        ->and(PermissionResource::getNavigationSort())->toBeNull();

    config()->set('filament-warden.navigation', [
        'group' => 'Access',
        'permissions' => ['slug' => 'access/permissions', 'icon' => 'heroicon-o-key', 'sort' => 4],
    ]);

    expect(PermissionResource::getNavigationIcon())->toBe('heroicon-o-key')
        ->and(PermissionResource::getNavigationGroup())->toBe('Access')
        ->and(PermissionResource::getNavigationSort())->toBe(4);
});

test('the labels are translated, so the screen speaks one language', function (): void {
    expect(PermissionResource::getModelLabel())->toBe('Permission');

    app()->setLocale('es');

    expect(PermissionResource::getPluralModelLabel())->toBe('Permisos');
});

test('an authority with no grant is kept out of the listing', function (): void {
    signIn();

    livewire(ListPermissions::class)->assertForbidden();
});

test('the screen that changes a permission is shut at both of its gates, and at the config too', function (): void {
    $user = signIn();
    $row = makePermission('export-reports');

    livewire(EditPermission::class, ['record' => $row->getKey()])->assertForbidden();

    Warden::allow($user)->to('viewAny', permissionClass());

    livewire(EditPermission::class, ['record' => $row->getKey()])->assertForbidden();

    Warden::allow($user)->to('update', $row);

    livewire(EditPermission::class, ['record' => $row->getKey()])->assertOk();

    config()->set('filament-warden.permissions.update', false);

    livewire(EditPermission::class, ['record' => $row->getKey()])->assertForbidden();
});

test('the screen that mints a permission is shut at both of its gates, and at the config too', function (): void {
    $user = signIn();

    config()->set('filament-warden.permissions.create', true);

    livewire(CreatePermission::class)->assertForbidden();

    Warden::allow($user)->to('viewAny', permissionClass());

    livewire(CreatePermission::class)->assertForbidden();

    Warden::allow($user)->to('create', permissionClass());

    livewire(CreatePermission::class)->assertOk();

    config()->set('filament-warden.permissions.create', false);

    livewire(CreatePermission::class)->assertForbidden();
});

test('an authority the store trusts sees the catalogue', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    livewire(ListPermissions::class)
        ->assertCanSeeTableRecords([heldRow('viewAny')])
        ->assertOk();
});

test('a permission pinned to one record does not badge as reaching every row', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    $post = Post::query()->create(['title' => 'A post']);
    Warden::allow(makeRole())->to('view', $post);

    livewire(ListPermissions::class)
        ->assertSee('One record only')
        ->assertOk();
});

test('a fresh installation cannot mint a permission nothing consults', function (): void {
    $user = signIn();
    Warden::allow($user)->to('create', permissionClass());

    expect(PermissionResource::canCreate())->toBeFalse();

    config()->set('filament-warden.permissions.create', true);

    expect(PermissionResource::canCreate())->toBeTrue();
});

test('the listing carries the way in, and only for an authority that may create', function (): void {
    config()->set('filament-warden.permissions.create', true);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    livewire(ListPermissions::class)->assertActionHidden('create');

    Warden::allow($user)->to('create', permissionClass());

    livewire(ListPermissions::class)->assertActionVisible('create');
});

test('a fresh installation is offered no button to mint what nothing consults', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('create', permissionClass());

    livewire(ListPermissions::class)->assertActionHidden('create');
});

test('the edit and view screens carry the actions that belong on them', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $permission = makePermission('view');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertActionVisible('delete');

    livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->assertActionVisible('edit')
        ->assertActionVisible('delete');
});

test('a permission somebody holds keeps its delete button off its own screens', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', roleClass());

    $held = heldRow('viewAny');

    livewire(EditPermission::class, ['record' => $held->getKey()])
        ->assertActionHidden('delete');

    livewire(ViewPermission::class, ['record' => $held->getKey()])
        ->assertActionHidden('delete');
});

test('a permission somebody holds survives the delete action on its own screens', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', roleClass());

    $held = heldRow('viewAny');

    livewire(EditPermission::class, ['record' => $held->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    livewire(ViewPermission::class, ['record' => $held->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->withoutGlobalScopes()->whereKey($held->getKey())->exists())->toBeTrue();
});

test('the config that closes editing takes the edit button with it', function (): void {
    config()->set('filament-warden.permissions.update', false);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');

    livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->assertActionHidden('edit');
});

test('what may be edited follows the rule the installation chose', function (bool|string $rule, bool $loose, bool $name): void {
    config()->set('filament-warden.permissions.update', $rule);

    $permission = makePermission('viewAny');
    $permission->update(['entity_type' => $loose ? null : new Post()->getMorphClass()]);

    expect(PermissionResource::mayEditName($permission))->toBe($name);
})->with([
    'nothing' => [false, true, false],
    'only the title' => ['title', true, false],
    'loose ones' => ['loose', true, true],
    'loose rule, derived row' => ['loose', false, false],
    'everything' => ['all', false, true],
]);

test('the two switches of the reach follow the same rule, and their own', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $permission = makePermission('viewAny');

    expect(PermissionResource::mayEditConditions($permission))->toBeTrue()
        ->and(PermissionResource::mayEditOwnership($permission))->toBeTrue();

    config()->set('filament-warden.permissions.constraints', false);
    config()->set('filament-warden.permissions.only_owned', false);

    expect(PermissionResource::mayEditConditions($permission))->toBeFalse()
        ->and(PermissionResource::mayEditOwnership($permission))->toBeFalse();
});

test('a permission somebody holds is not deletable by default', function (): void {
    Warden::allow(makeRole())->to('viewAny', Post::class);

    expect(PermissionResource::isDeletable(heldRow()))->toBeFalse();
});

test('a permission nobody holds is', function (): void {
    expect(PermissionResource::isDeletable(makePermission('viewAny')))->toBeTrue();
});

test('deletion follows the rule the installation chose', function (bool|string $rule, bool $held, bool $deletable): void {
    if ($held) {
        Warden::allow(makeRole())->to('viewAny', Post::class);
        $permission = heldRow();
    } else {
        $permission = makePermission('viewAny');
    }

    config()->set('filament-warden.permissions.delete', $rule);

    expect(PermissionResource::isDeletable($permission))->toBe($deletable);
})->with([
    'never' => [false, false, false],
    'orphaned and free' => ['orphaned', false, true],
    'orphaned but held' => ['orphaned', true, false],
    'always' => ['all', true, true],
]);

test('the modal says who loses it, because it is the only warning anybody gets', function (): void {
    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', Post::class);

    $warning = PermissionsTable::warning(heldRow());
    $title = $role->refresh()->getAttribute('title');

    expect($warning)->toContain('roles: 1')
        ->and($warning)->toContain('accounts: 0')
        ->and($warning)->not->toContain('role(s)')
        ->and($warning)->toContain(is_string($title) ? $title : '');
});

test('a permission nobody holds says so instead', function (): void {
    expect(PermissionsTable::warning(makePermission('viewAny')))->toContain('Nobody holds');
});

test("the edit screen's delete modal says what it takes with it too", function (): void {
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', Post::class);

    $held = heldRow('viewAny');

    livewire(EditPermission::class, ['record' => $held->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'roles: 1'),
        );
});

test('a permission nobody holds says so on the edit screen too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $permission = makePermission('viewAny');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this permission'),
        );
});

test("the listing's delete modal says what it takes with it too", function (): void {
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', Post::class);

    $held = heldRow('viewAny');

    livewire(ListPermissions::class)
        ->assertTableActionExists(
            'delete',
            record: $held,
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'roles: 1'),
        );
});

test('a permission nobody holds says so on the listing too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $permission = makePermission('viewAny');

    livewire(ListPermissions::class)
        ->assertTableActionExists(
            'delete',
            record: $permission,
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this permission'),
        );
});

test("the view screen's delete modal says what it takes with it too", function (): void {
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', Post::class);

    $held = heldRow('viewAny');

    livewire(ViewPermission::class, ['record' => $held->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'roles: 1'),
        );
});

test('a permission nobody holds says so on the view screen too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $permission = makePermission('viewAny');

    livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this permission'),
        );
});

test('deleting a permission takes its grants with it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    config()->set('filament-warden.permissions.delete', 'all');

    $role = makeRole();
    Warden::allow($role)->to('viewAny', Post::class);

    $permission = heldRow();

    livewire(ListPermissions::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($permission)])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->whereKey($permission->getKey())->exists())->toBeFalse()
        ->and(Context::resolve()->grantClass()::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the config closes the delete action itself, not only the check', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $role = makeRole();
    Warden::allow($role)->to('viewAny', Post::class);

    $permission = heldRow();

    livewire(ListPermissions::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($permission)])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->whereKey($permission->getKey())->exists())->toBeTrue();
});

test('an edit made here reaches the store, which warden alone does not invalidate', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeTrue();

    livewire(EditPermission::class, ['record' => heldRow()->getKey()])
        ->fillForm(['name' => 'browse'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeFalse()
        ->and(Access::granted($holder, 'browse', Post::class))->toBeTrue();
});

test('deleting a permission from the listing reaches the store, and warden invalidates it', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeTrue();

    $permission = heldRow();

    livewire(ListPermissions::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($permission)])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->withoutGlobalScopes()->whereKey($permission->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', Post::class))->toBeFalse();
});

test('deleting a permission from its edit screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeTrue();

    $permission = heldRow();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->withoutGlobalScopes()->whereKey($permission->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', Post::class))->toBeFalse();
});

test('deleting a permission from its view screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeTrue();

    $permission = heldRow();

    livewire(ViewPermission::class, ['record' => $permission->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(permissionClass()::query()->withoutGlobalScopes()->whereKey($permission->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', Post::class))->toBeFalse();
});

test('renaming regenerates a title warden wrote, and leaves one a person wrote', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('viewAny', Post::class);

    $permission = heldRow();

    expect($permission->getAttribute('title'))->toBe('View any posts');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['name' => 'delete'])
        ->call('save');

    expect($permission->refresh()->getAttribute('title'))->toBe('Delete posts');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['title' => 'Throw a post away'])
        ->call('save');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['name' => 'destroy'])
        ->call('save');

    expect($permission->refresh()->getAttribute('title'))->toBe('Throw a post away');
});

test('the catalogue cannot take the same permission twice', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    makePermission('taken');
    $permission = makePermission('other');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['name' => 'taken'])
        ->call('save')
        ->assertHasFormErrors(['name']);
});

test('the same name under another tenant is another permission, as the index says', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    // Warden's unique index is over `(name, identity_key)`, and the digest
    // carries the tenant — so this row and one named the same under tenant 6 are
    // two rows the index admits side by side. The screen used to read the
    // catalogue with every scope dropped, see this one, and refuse the rename
    // for a collision the database would never have raised.
    Warden::tenant()->onceTo(5, static fn (): Model => makePermission('taken'));

    /** @var Model $mine */
    $mine = Warden::tenant()->onceTo(6, static fn (): Model => makePermission('other'));

    Warden::tenant()->onceTo(6, function () use ($mine): void {
        livewire(EditPermission::class, ['record' => $mine->getKey()])
            ->fillForm(['name' => 'taken'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($mine->refresh()->getAttribute('name'))->toBe('taken');
});

test('the same name over another entity is another permission', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    makePermission('view')->update(['entity_type' => new Post()->getMorphClass()]);

    $mine = makePermission('browse');
    $mine->update(['entity_type' => new Comment()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $mine->getKey()])
        ->fillForm(['name' => 'view'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mine->refresh()->getAttribute('name'))->toBe('view');
});

test('the same name over the same entity is the one already there', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    makePermission('view')->update(['entity_type' => new Post()->getMorphClass()]);

    $mine = makePermission('browse');
    $mine->update(['entity_type' => new Post()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $mine->getKey()])
        ->fillForm(['name' => 'view'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($mine->refresh()->getAttribute('name'))->toBe('browse');
});

test('a permission pinned to one record is not the one pinned to another', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    makePermission('view')->update([
        'entity_type' => new Post()->getMorphClass(),
        'entity_id' => 1,
    ]);

    $mine = makePermission('browse');
    $mine->update([
        'entity_type' => new Post()->getMorphClass(),
        'entity_id' => 2,
    ]);

    livewire(EditPermission::class, ['record' => $mine->getKey()])
        ->fillForm(['name' => 'view'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mine->refresh()->getAttribute('name'))->toBe('view');
});

test('an ownership of its own makes it another permission too', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    makePermission('view')->update([
        'entity_type' => new Comment()->getMorphClass(),
        'only_owned' => true,
    ]);

    $mine = makePermission('browse');
    $mine->update(['entity_type' => new Comment()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $mine->getKey()])
        ->fillForm(['name' => 'view'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mine->refresh()->getAttribute('name'))->toBe('view');
});

test('a name another entity already uses does not block a row nobody may rename', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('viewAny', Post::class);

    $derived = heldRow('viewAny');

    expect(PermissionResource::mayEditName($derived))->toBeFalse();

    livewire(EditPermission::class, ['record' => $derived->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($derived->refresh()->getAttribute('name'))->toBe('viewAny');
});

test('a twin does not collide with the plain sibling it was narrowed from', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('publish', Post::class);
    Warden::allow(makeRole('two'))->to('publish', Post::class)->where('title', '=', 'alpha');

    $twin = permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'publish')
        ->whereNotNull('options')
        ->firstOrFail();

    livewire(EditPermission::class, ['record' => $twin->getKey()])
        ->fillForm(['title' => 'Publish a post, narrowed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($twin->refresh()->getAttribute('title'))->toBe('Publish a post, narrowed');
});

test('the listing can be narrowed to what somebody holds, and to what nobody does', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    $held = heldRow();
    $orphan = makePermission('nobody-holds-this');

    livewire(ListPermissions::class)
        ->filterTable('held', true)
        ->assertCanSeeTableRecords([$held])
        ->assertCanNotSeeTableRecords([$orphan]);

    livewire(ListPermissions::class)
        ->filterTable('held', false)
        ->assertCanSeeTableRecords([$orphan])
        ->assertCanNotSeeTableRecords([$held]);
});

test('the listing can be narrowed to where a permission came from', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    Warden::allow(makeRole())->everything();

    $wildcard = permissionClass()::query()->withoutGlobalScopes()->where('entity_type', '*')->firstOrFail();

    livewire(ListPermissions::class)
        ->filterTable('provenance', 'wildcard')
        ->assertCanSeeTableRecords([$wildcard])
        ->assertCanNotSeeTableRecords([heldRow()]);
});

test('the wildcard reads as the wildcard in the listing too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    Warden::allow(makeRole())->everything();

    livewire(ListPermissions::class)->assertSee('Any entity');
});

test('a shared row says so, and a fresh form has nobody to warn about', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('filament-warden.permissions.create', true);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('create', permissionClass());

    Warden::allow(makeRole('one'))->to('viewAny', Post::class);
    Warden::allow(makeRole('two'))->to('viewAny', Post::class);

    livewire(EditPermission::class, ['record' => heldRow()->getKey()])
        ->assertSee('It is one row and one rule');

    livewire(CreatePermission::class)->assertDontSee('It is one row and one rule');
});

test('a loose installation may not re-point a row somebody holds', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    Warden::allow(makeRole('one'))->to('export');

    expect(PermissionResource::mayEditName(heldRow('export')))->toBeFalse()
        ->and(PermissionResource::mayEditName(makePermission('archive')))->toBeTrue();

    config()->set('filament-warden.permissions.update', 'all');

    expect(PermissionResource::mayEditName(heldRow('export')))->toBeTrue();

    config()->set('filament-warden.permissions.update', 'title');

    expect(PermissionResource::mayEditName(makePermission('purge')))->toBeFalse();
});

test('the lock leaves the conditions and the ownership where they were', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    Warden::allow(makeRole('one'))->to('export');

    $held = heldRow('export');

    expect(PermissionResource::mayEditName($held))->toBeFalse()
        ->and(PermissionResource::mayEditConditions($held))->toBeTrue()
        ->and(PermissionResource::mayEditOwnership($held))->toBeTrue();
});

test('the save keeps the lock the screen draws', function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('export');

    $held = heldRow('export');

    livewire(EditPermission::class, ['record' => $held->getKey()])
        ->fillForm(['name' => 'exfiltrate'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($held->refresh()->getAttribute('name'))->toBe('export');
});

test("the lock's grant reads are capped at 5, two over the 3 measured after Holders was memoised", function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('export');

    $held = heldRow('export');

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(EditPermission::class, ['record' => $held->getKey()]);

    expect(grantReads())->toBeLessThanOrEqual(5);
});

test("a permission's card reads its holders once per record, capped at 5 over the 3 measured", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());
    Warden::allow($user)->to('update', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    Warden::allow(makeRole('editor'))->to('viewAny', roleClass());

    $held = heldRow('viewAny');

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ViewPermission::class, ['record' => $held->getKey()]);

    expect(grantReads())->toBeLessThanOrEqual(5);
});

test('the listing asks anyFor() per row and not the full Holders, capped at 20 over the 17 measured', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('view', permissionClass());

    for ($index = 0; $index < 10; $index++) {
        Warden::allow(makeRole("role-{$index}"))->to("action-{$index}");
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ListPermissions::class);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(20);
});

test('a row a single holder has says so too, and one nobody has says nothing', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('export');

    livewire(EditPermission::class, ['record' => heldRow('export')->getKey()])
        ->assertSee('Holders of this row: 1')
        ->assertSee('It is one row and one rule')
        ->assertDontSee('1 times over');

    livewire(EditPermission::class, ['record' => makePermission('unheld')->getKey()])
        ->assertDontSee('It is one row and one rule');
});

test('ownership is offered where it could resolve, and refused where it could not', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $owned = makePermission('view');
    $owned->update(['entity_type' => new Comment()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $owned->getKey()])
        ->assertSee('ownedVia()');

    $notOwned = makePermission('view');
    $notOwned->update(['entity_type' => new Post()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $notOwned->getKey()])
        ->assertSee('has no user_id column');
});

test('an installation resolving no ownership is told that, not that a column is missing', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('warden.ownership.default_attribute');

    app()->forgetInstance(Context::class);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');
    $permission->update(['entity_type' => new Comment()->getMorphClass()]);

    // `Comment` HAS a `user_id` column, so the old wording would have been
    // doubly wrong here: it would have named a column that is present as the
    // thing that is missing.
    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.conditions.no_ownership_resolver'))
        ->assertDontSee('has no user_id column');
});

test('switching the entity gives up an ownership it cannot resolve', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('filament-warden.catalog.models', [Post::class]);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');
    $permission->update(['entity_type' => new Comment()->getMorphClass()]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['only_owned' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('only_owned'))->toBeTruthy();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['entity_type' => new Post()->getMorphClass()])
        ->assertFormSet(['only_owned' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('only_owned'))->toBeFalsy();
});

test('an ownership survives a move to an entity that can still resolve it', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('filament-warden.catalog.models', [Post::class]);

    Warden::ownedVia(Post::class, 'title');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');
    $permission->update([
        'entity_type' => new Comment()->getMorphClass(),
        'only_owned' => true,
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm([
            'entity_type' => new Post()->getMorphClass(),
            'only_owned' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('only_owned'))->toBeTruthy();
});

test('an ownership the row already carried is left where it is', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');
    $permission->update([
        'entity_type' => new Post()->getMorphClass(),
        'only_owned' => true,
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('only_owned'))->toBeTruthy();
});

test('a permission created here is asked for straight away, cache and all', function (): void {
    config()->set('filament-warden.permissions.create', true);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('create', permissionClass());

    livewire(CreatePermission::class)
        ->fillForm(['name' => 'export-reports'])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = permissionClass()::query()->withoutGlobalScopes()->where('name', 'export-reports')->firstOrFail();

    expect($created->getAttribute('title'))->toBe('Export reports')
        ->and($created->getAttribute('entity_type'))->toBeNull();
});

test('the form suggests the title this package would give a door, not a capital letter', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $door = makePermission('widget:Filament\\Widgets\\AccountWidget');

    livewire(EditPermission::class, ['record' => $door->getKey()])
        ->assertSee('View Account Widget');
});

test('renaming a door regenerates its title the same way', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $door = makePermission('page:App\\Filament\\Pages\\Reports');

    // Whatever warden makes of a name it has no way to read — asked of warden
    // rather than written out, because the wording is warden's to change and it
    // did between `2.0.0` and `2.0.1`. What this line pins is that the row
    // starts out carrying warden's OWN answer, which is what the rename below
    // has to recognise before it may rewrite it.
    expect($door->getAttribute('title'))
        ->toBe(PermissionTitle::generate('page:App\\Filament\\Pages\\Reports', null, null, false));

    livewire(EditPermission::class, ['record' => $door->getKey()])
        ->fillForm(['name' => 'widget:App\\Filament\\Widgets\\Summary'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($door->refresh()->getAttribute('title'))->toBe('View Summary');
});

test('a derived permission keeps its title when only loose ones may be edited', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('publish', Post::class);

    $permission = heldRow('publish');

    expect($permission->getAttribute('title'))->toBe('Publish posts')
        ->and(PermissionResource::mayEditName($permission))->toBeFalse();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->refresh()->getAttribute('title'))->toBe('Publish posts');
});

test('a derived permission keeps every field the form would not let the browser send', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('publish', Post::class);

    $permission = heldRow('publish');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm([
            'name' => 'destroy',
            'entity_type' => null,
            'only_owned' => true,
            'options' => [
                'mode' => 'conditions',
                'rules' => [['logic' => 'and', 'kind' => 'value', 'column' => 'title', 'operator' => '=', 'value' => 'x']],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $permission->refresh();

    expect($permission->getAttribute('name'))->toBe('publish')
        ->and($permission->getAttribute('entity_type'))->toBe(new Post()->getMorphClass())
        ->and($permission->getAttribute('only_owned'))->toBeFalsy()
        ->and($permission->getAttribute('options'))->toBeNull()
        ->and($permission->getAttribute('title'))->toBe('Publish posts');
});

test('a permission repointed at nothing loses the entity from its title', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('publish', Post::class);

    $permission = heldRow('publish');

    expect($permission->getAttribute('title'))->toBe('Publish posts');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['entity_type' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    $permission->refresh();

    expect($permission->getAttribute('entity_type'))->toBeNull()
        ->and($permission->getAttribute('title'))->toBe('Publish');
});

test('a condition whose value would come back as another type is not editable', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'id', 'o' => '=', 'v' => '2']]]],
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.conditions.locked.rewrite'));
});

test('a rule whose first line reads or is not locked, because warden calls it the same rule', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    // `Narrowing::conditions()` normalises the first line's operator to `and`,
    // so this row cannot be written back byte for byte and the screen used to
    // close the builder over it. Warden's own `sameRule()` normalises the same
    // way before comparing — `Group::passes()` ignores the first item's logic on
    // every evaluation, so the two forms ARE one rule — and asking warden rather
    // than a private copy of the comparison is what reopens the row.
    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['or', ['t' => 'value', 'c' => 'title', 'o' => '=', 'v' => 'draft']]]],
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertDontSee(__('filament-warden::ui.conditions.locked.rewrite'));
});

test('a title-only save leaves a rule the screen cannot write back exactly as it was', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'id', 'o' => '=', 'v' => '2']]]],
    ]);

    $before = $permission->getAttribute('options');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($permission->fresh()?->getAttribute('options'))->toBe($before);
});

test('a rule warden itself wrote stays editable', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('editor'))->to('view', Post::class)->where('id', '=', 2);

    $permission = permissionClass()::query()
        ->withoutGlobalScopes()
        ->whereNotNull('options')
        ->orderByDesc('id')
        ->firstOrFail();

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertDontSee(__('filament-warden::ui.conditions.locked.rewrite'));
});

test('a rule naming a column the table no longer has says which cause it is', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'gone', 'o' => '=', 'v' => 'x']]]],
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.conditions.locked.column'))
        ->assertDontSee(__('filament-warden::ui.conditions.locked.shape'));
});

test('a row stored against an entity that no longer resolves says so once the live entity does', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('view');
    $permission->update([
        'entity_type' => 'App\\Models\\Gone',
        'options' => [
            'v' => 1,
            'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'title', 'o' => '=', 'v' => 'x']]]],
        ],
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['entity_type' => new Post()->getMorphClass()])
        ->assertSee(__('filament-warden::ui.conditions.locked.model'))
        ->assertDontSee(__('filament-warden::ui.conditions.no_model'));
});

test('the probe searches only columns a like can compare', function (): void {
    makeUser('Amaru Quispe');

    expect(Columns::texts(User::class))->toContain('name')
        ->and(Columns::texts(User::class))->not->toContain('id')
        ->and(ViewPermission::accounts('Amaru'))->not->toBeEmpty();
});

test('a save that collides with the catalogue index says so on the field', function (): void {
    config()->set('filament-warden.permissions.update', 'all');
    config()->set('filament-warden.catalog.models', [Post::class, Comment::class]);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole())->to('view', Post::class);
    Warden::allow(makeRole())->to('view', Comment::class)->where('id', '=', 1);

    $twin = permissionClass()::query()->withoutGlobalScopes()
        ->where('name', 'view')->whereNotNull('options')->orderByDesc('id')->firstOrFail();

    // Choosing the entity clears the conditions, so the row being saved stops
    // being a twin — and `exists()` had already excused it for being one. Before
    // warden 2.0 there was no unique index and the duplicate was simply created;
    // now the database refuses it, and without this guard the person gets a 500.
    livewire(EditPermission::class, ['record' => $twin->getKey()])
        ->fillForm(['entity_type' => new Post()->getMorphClass()])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($twin->refresh()->getAttribute('entity_type'))->toBe(new Comment()->getMorphClass());
});

test('an empty catalogue points at the two places that do show one', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    /** @var ListPermissions $page */
    $page = livewire(ListPermissions::class)->instance();

    $table = $page->getTable();

    expect($table->getEmptyStateHeading())
        ->toBe(__('filament-warden::ui.resources.permissions.empty.heading'))
        ->and($table->getEmptyStateDescription())
        ->toBe(__('filament-warden::ui.resources.permissions.empty.description'))
        ->and($table->getEmptyStateDescription())
        ->toContain('filament-warden:catalog');
});

test('a table narrowed to nothing is not told the catalogue is empty', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    $screen = livewire(ListPermissions::class)->set('tableSearch', 'nothing matches this');

    /** @var ListPermissions $page */
    $page = $screen->instance();

    expect($page->getTable()->getEmptyStateDescription())->toBeNull();

    $screen->assertCanSeeTableRecords([])
        ->assertSee(__('filament-warden::ui.resources.permissions.empty.heading'))
        ->assertDontSee('filament-warden:catalog');
});

/*
 * The two below are the permission-side siblings of `RoleResourceTest`'s pair
 * about a role assigned inside one tenant. Same reason, different table: the
 * foreign key that takes a permission's grants down goes below Eloquent and
 * below the `TenantScope`, so a read that DECIDES the delete has to be wide
 * while a read that only INFORMS keeps its scope. `Holders` reads without
 * global scopes on purpose, and these are what go red if that is ever taken
 * for redundant.
 */

test('a permission held only inside another tenant is not offered for deletion', function (): void {
    $role = makeRole();

    // The control row is minted with no grant at all: it has to be genuinely
    // orphaned, or it proves nothing.
    $free = makePermission('archive');

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('publish', Post::class);
    });

    $held = latestPermission('publish');

    config()->set('filament-warden.permissions.delete', 'orphaned');

    $one = Warden::tenant()->onceTo(8, static fn (): bool => PermissionResource::isDeletable($held));
    $other = Warden::tenant()->onceTo(8, static fn (): bool => PermissionResource::isDeletable($free));

    // The unheld row is the control: without it a bare `return false` would
    // paint this green while proving nothing.
    expect($one)->toBeFalse()
        ->and($other)->toBeTrue();
});

test('and a strict installation with no tenant active sees that grant too', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::allow($role)->to('publish', Post::class);
    });

    $held = latestPermission('publish');

    config()->set('filament-warden.permissions.delete', 'orphaned');

    // Read live on every call by `Tenancy::readFilter()`, so this bites even
    // though the `Tenancy` singleton is already resolved. Without it the
    // factory `all` adds no predicate at all and the test passes either way.
    config()->set('warden.scope.null_behavior', 'strict');

    expect(PermissionResource::isDeletable($held))->toBeFalse();
});

/*
 * The writer half of the same question the `Narrowing` tests ask on the read
 * side. A blob that does not decode is a rule this screen cannot show, and the
 * promise is that it is said out loud and left alone — not silently replaced.
 * Both rows below are written straight onto the column: through the model the
 * `array` cast re-encodes them into valid JSON, which is exactly the step that
 * used to hide them.
 */

test('a save that never touched an unreadable condition does not blank it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = permissionWithOptions(Post::class, ['column' => 'title', 'operator' => '=', 'value' => 'alpha']);

    $raw = '{"v":1,"g":';
    DB::table(Context::resolve()->table('permissions'))
        ->where('id', $permission->getKey())
        ->update(['options' => $raw]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $after = DB::table(Context::resolve()->table('permissions'))
        ->where('id', $permission->getKey())
        ->value('options');

    // Before this release `mutateFormDataBeforeSave()` put the key back with the
    // CAST — null for this blob — and the update wrote SQL NULL over it, turning
    // a rule nobody could decode into an unconditional grant.
    expect($after)->toBe($raw);
});

test('a stored rule that can never be true closes the builder with its own word', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    // `Post` casts `published` to bool and the stored value is the string
    // 'true'. It reads back and writes back perfectly well — the round trip has
    // no opinion on whether anything could ever match it — so this has to be
    // asked BEFORE that check, or the builder opens on a rule the save refuses
    // and the person is told nothing until they press save.
    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]);

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.conditions.locked.unsatisfiable'))
        ->assertDontSee(__('filament-warden::ui.conditions.locked.rewrite'));
});

test('a rule that can never be true is refused on the field, not saved and audited later', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'title', 'o' => '=', 'v' => 'alpha']]]],
    ]);

    $before = $permission->getAttribute('options');

    // This screen writes `options` through Eloquent, never through warden's
    // fluent chain, so warden's own refusal never runs here: without a rule on
    // the field the row would simply be saved unsatisfiable and `warden:doctor`
    // would find it later — which is exactly the state 3.0 exists to stop
    // anybody entering.
    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->set('data.options.rules.0.column', 'published')
        ->set('data.options.rules.0.value', 'alpha')
        ->call('save')
        ->assertHasFormErrors(['options']);

    expect($permission->refresh()->getAttribute('options'))->toBe($before);
});

test('the health badge and its filter agree, row by row', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    // One row whose condition can never be true, one whose can, and one plain.
    // The pairing is what matters: §6.17 measured a provenance badge and its
    // filter disagreeing row by row, because each carried its own version of
    // one rule — so this asserts they answer the same question and not that
    // each answers something.
    $broken = permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]);

    permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'title', 'o' => '=', 'v' => 'alpha']]]],
    ]);

    Warden::allow(makeRole())->to('export');

    livewire(ListPermissions::class)
        ->assertCanSeeTableRecords([$broken], inOrder: false)
        ->filterTable('unsatisfiable')
        ->assertCanSeeTableRecords([$broken])
        ->assertCountTableRecords(1);
});

test('the held badge counts roles and accounts, and keeps the denial apart', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    $permission = makePermission('export');

    Warden::allow(makeRole('one'))->to('export');
    Warden::allow(makeUser('Amaru Quispe'))->to('export');
    Warden::forbid(makeRole('two'))->to('export');

    // Two holders and one denial, and the denial is NOT folded into the count:
    // a row two roles are forbidden is not a row nobody holds — a denial is a
    // state, which is the distinction this package has drawn since 0.6.0.
    livewire(ListPermissions::class)
        ->assertSee(trans_choice('filament-warden::ui.resources.permissions.columns.held_count', 2))
        ->assertSee(trans_choice('filament-warden::ui.resources.permissions.columns.forbidden_count', 1));

    expect($permission->refresh()->getKey())->not->toBeNull();
});

test('the doctor banner appears only when there is something, and only unfiltered', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    Warden::allow(makeRole())->to('export');

    // Nothing wrong: no banner. A page that warns whatever it finds trains
    // people to stop reading it.
    livewire(ListPermissions::class)
        ->assertDontSee(trans_choice('filament-warden::ui.resources.permissions.health.warning', 1));

    permissionWithOptions(Post::class, [
        'v' => 1,
        'g' => ['t' => 'group', 'i' => [['and', ['t' => 'value', 'c' => 'published', 'o' => '=', 'v' => 'true']]]],
    ]);

    livewire(ListPermissions::class)
        ->assertSee(trans_choice('filament-warden::ui.resources.permissions.health.warning', 1));

    // And gone on a filtered reading: over a page already showing exactly those
    // rows it is noise, and over a page filtered to something else it names a
    // problem that is not on the screen. Same oracle as the empty state, which
    // 2.4.0 measured for the same reason (§6.42).
    livewire(ListPermissions::class)
        ->filterTable('unsatisfiable')
        ->assertDontSee(trans_choice('filament-warden::ui.resources.permissions.health.warning', 1));
});

test('the edit screen says what the row costs beside the form, not under it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('export');

    Warden::allow(makeRole('one'))->to('export');

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow(makeRole('two'))->until(Carbon::parse('2026-09-14 12:00:00'))->to('export');

    // A permission does not expire and neither does a role: what expires is the
    // GRANT that points at one. So the card COUNTS rather than offers — there is
    // no date on this record to edit, and a field here would write nothing.
    // Saying it beside the count is cheaper than a paragraph nobody reads.
    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.resources.permissions.sections.expiry'))
        ->assertSee(trans_choice('filament-warden::ui.resources.permissions.expiry.some', 1, [
            'first' => CarbonImmutable::parse('2026-09-14 12:00:00')->toDayDateTimeString(),
        ]));

    Carbon::setTestNow();
});

test('a row nothing points at says a permission does not expire at all', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $permission = makePermission('export');

    livewire(EditPermission::class, ['record' => $permission->getKey()])
        ->assertSee(__('filament-warden::ui.resources.permissions.expiry.none'));
});
