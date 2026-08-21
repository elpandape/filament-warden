<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\CreatePermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\EditPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ListPermissions;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Tables\PermissionsTable;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

function heldRow(string $name = 'viewAny'): Model
{
    return Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', $name)
        ->orderByDesc('id')
        ->firstOrFail();
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

test('an authority the store trusts sees the catalogue', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    livewire(ListPermissions::class)
        ->assertCanSeeTableRecords([heldRow('viewAny')])
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

test('the modal says who loses it, because the cascade leaves no trace', function (): void {
    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', Post::class);

    $warning = PermissionsTable::warning(heldRow());
    $title = $role->refresh()->getAttribute('title');

    expect($warning)->toContain('1 role')
        ->and($warning)->toContain(is_string($title) ? $title : '');
});

test('a permission nobody holds says so instead', function (): void {
    expect(PermissionsTable::warning(makePermission('viewAny')))->toContain('Nobody holds');
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

test('an edit made here reaches the store, which nothing in warden invalidates', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    // Warmed on purpose: the check is answered from the cache from here on, and
    // only warden's own fluent actions bump the version behind it.
    expect(Access::granted($holder, 'viewAny', Post::class))->toBeTrue();

    livewire(EditPermission::class, ['record' => heldRow()->getKey()])
        ->fillForm(['name' => 'browse'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($holder, 'viewAny', Post::class))->toBeFalse()
        ->and(Access::granted($holder, 'browse', Post::class))->toBeTrue();
});

test('deleting a permission from the listing reaches the store, which nothing in warden invalidates', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.permissions.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('delete', permissionClass());

    $holder = makeUser('Holder');
    Warden::allow($holder)->to('viewAny', Post::class);

    // Warmed on purpose: the check is answered from the cache from here on, and
    // only warden's own fluent actions bump the version behind it.
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

    expect($permission->getAttribute('title'))->toBe('ViewAny posts');

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

test("the lock's grant reads are capped, not promised to cost nothing", function (): void {
    config()->set('filament-warden.permissions.update', 'loose');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('export');

    $held = heldRow('export');

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(EditPermission::class, ['record' => $held->getKey()]);

    expect(grantReads())->toBeLessThanOrEqual(16);
});

test('a row a single holder has says so too, and one nobody has says nothing', function (): void {
    config()->set('filament-warden.permissions.update', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    Warden::allow(makeRole('one'))->to('export');

    livewire(EditPermission::class, ['record' => heldRow('export')->getKey()])
        ->assertSee('It is one row and one rule');

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

    expect($door->getAttribute('title'))->toBe('Page:App\\Filament\\Pages\\Reports');

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
