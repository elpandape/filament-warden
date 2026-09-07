<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Catalog\Catalog;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\GridView;
use ElPandaPe\FilamentWarden\Filament\Forms\Grid\StateKey;
use ElPandaPe\FilamentWarden\Filament\Forms\PermissionGrid;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\CreateRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ListRoles;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\RoleResource;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Tables\RolesTable;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Grants\RoleGrants;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Livewire\livewire;

/**
 * The "held by" count beside a role and `RolesTable::warning()`'s own delete
 * warning read `assigned_roles` under two different scope rules on purpose
 * (§6.24), and it is easy to get backwards: the count column INFORMS, so it
 * stays under the active tenant — 'the count column stays under the tenant
 * you are in, unlike the delete rule beside it'. `warning()` describes what an
 * actual delete's cascade removes, which is blind to tenancy exactly like
 * `RoleResource::isDeletable()`'s own read, so it reads wide — 'the delete
 * warning reads wide, unlike the count beside it'.
 *
 * The count column used to be a second query per row alongside
 * `isDeletable()`'s own EXISTS behind the delete button — 11 `assigned_roles`
 * reads for 5 roles, capped at 13, before "Que no cueste" (v1.5.0) gave each
 * one query of its own: one scoped (`RolesTable::heldCounts()`, grouped) for
 * the column that informs, one wide (`RolesTable::assignedRoleIds()`,
 * distinct) for the button that decides — never folded into ONE query,
 * because §6.24 ties each to a different scope rule. 'the listing's held-by
 * column and delete button together cost 3 assigned_roles reads for 5 roles,
 * capped at 5' is what is measured and capped now.
 *
 * That statement count is HALF the guarantee, and on its own it is the
 * comfortable half. Reading every row of `assigned_roles` and reducing in PHP
 * costs exactly three statements too: measured over a 200-row fixture, that
 * shape hydrates 400 `AssignedRole` models against 10 for the bounded one,
 * and head to head over 20 000 rows it takes 0.439 s and 46 MB against
 * 0.002 s and no measurable allocation. A statement counter cannot see any of
 * that, so 'the listing's two reads are bounded by the role catalogue, not by
 * the assignment table' counts rows instead, through Eloquent's own
 * `retrieved` event.
 *
 * `AssignmentTest.php` already carries an `assignedRoleReads()` helper that
 * does exactly this counting — reused instead of duplicated everywhere else
 * in this suite. Not here: `make test`/`make coverage` run `pest --parallel`,
 * which splits test FILES across worker processes, and a worker running only
 * this file never `require`s `AssignmentTest.php`, so the global function is
 * simply undefined — measured as `Error: Call to undefined function
 * assignedRoleReads()` on a parallel run that passed file-by-file. `heldReads()`
 * below is this file's own copy for that reason, not a naming preference.
 *
 * Neither the count column nor `RolesTable::warning()`/`labels()` filters on
 * `restricted_to_type`: an assignment narrowed to a context is one more row
 * with the same `role_id`, counted and named exactly like an unrestricted one
 * — 'a holder restricted to a context still counts as held' and 'the delete
 * warning names a holder restricted to a context too'. A translated sentence
 * on `ViewRole`'s section claimed otherwise for one release; it was the
 * sentence that was wrong, and it was corrected rather than the behaviour.
 *
 * `assertSee()` after `mountAction` never renders a modal's body in this
 * harness — confirmed by dumping the full HTML of a mounted delete action and
 * finding no `fi-modal` in it at all — so the only way to prove a
 * `modalDescription` closure is actually WIRED to a given action (as opposed
 * to merely correct in isolation, which the direct `RolesTable::warning()`
 * calls above already cover) is to resolve the action object itself and read
 * `getModalDescription()` off it: `assertActionExists()` for a page's own
 * action, `assertTableActionExists(..., record: $role)` for a table row's.
 * This pin exists on all three delete surfaces — "the edit screen's delete
 * modal...", "the listing's delete modal..." and "the view screen's delete
 * modal..." — and their three "nobody holds" siblings, because leaving even
 * one untested is exactly the shape §6.30 warns about: every other test stays
 * green if that one `->modalDescription(...)` line is deleted.
 *
 * The wildcard column has to be addressed with `->set()` and its raw dotted
 * path, never through `fillForm()`. `fillForm()` normalises with `Arr::undot`
 * and writes with `data_set()`, which reads `*` as a WILDCARD: on the empty row
 * it just created it writes nothing at all, and on a populated one it
 * overwrites every key. Down the real wire the same character is an ordinary
 * segment — `HandleComponents::updateProperty()` does a plain `explode('.')` —
 * so `->set('data.permissions.stances.<row>.'.StateKey::MANAGE, …)` is what the
 * browser does and what 'the wildcard cell on the roles row reaches the account
 * through the role it holds' does. That test is the only one in this suite that
 * takes the wildcard through a real resource page: its two siblings elsewhere
 * drive `RoleGrants::apply()` and the bare field harness, neither of which has a
 * policy in front of it.
 *
 * A check answered once is answered from the cache from there on, and only
 * warden's own fluent actions bump the version behind it — which is why the
 * tests here that mean to exercise an invalidation warm the check first, on
 * purpose. Said here rather than beside each of them.
 */
pest()->extend(TestCase::class);

/**
 * The body of the notification the page actually sent.
 *
 * `Notification::assertNotified()` compares whole objects, so it cannot say
 * WHICH words a body carries; it reads them out of a freshly mounted
 * `Notifications` component, and so does this. Mounting CONSUMES them, so this
 * CONSUMES them, so a test uses this OR `assertNotified()`, never both: whichever
 * runs second finds nothing. This one is the stronger of the two — it can say
 * which words a body carries, which is the whole point of a notification that
 * names the cells it refused.
 */
function lastNotification(): ?Notification
{
    $component = new Notifications;
    $component->mount();

    $first = $component->notifications->first();

    return $first instanceof Notification ? $first : null;
}

function catalogForRoles(): Catalog
{
    /** @var Panel $panel */
    $panel = Filament\Facades\Filament::getCurrentOrDefaultPanel();

    return Catalog::for($panel);
}

function heldReads(): int
{
    $table = Context::resolve()->table('assigned_roles');
    $reads = 0;

    foreach (DB::getQueryLog() as $entry) {
        if (str_contains($entry['query'], $table)) {
            $reads++;
        }
    }

    return $reads;
}

test('the resource points at the configured role model, never at a guessed one', function (): void {
    expect(RoleResource::getModel())->toBe(roleClass());
});

test('the url, the icon, the group and the sort all come from config', function (): void {
    expect(RoleResource::getSlug())->toBe('roles')
        ->and(RoleResource::getNavigationIcon())->toBe(Heroicon::OutlinedShieldCheck)
        ->and(RoleResource::getNavigationGroup())->toBe('Security')
        ->and(RoleResource::getNavigationSort())->toBeNull();

    config()->set('filament-warden.navigation', [
        'group' => 'Access',
        'roles' => ['slug' => 'access/roles', 'icon' => 'heroicon-o-key', 'sort' => 3],
    ]);

    expect(RoleResource::getNavigationIcon())->toBe('heroicon-o-key')
        ->and(RoleResource::getNavigationGroup())->toBe('Access')
        ->and(RoleResource::getNavigationSort())->toBe(3);
});

test('the labels are translated, so the screen speaks one language', function (): void {
    expect(RoleResource::getModelLabel())->toBe('Role')
        ->and(RoleResource::getPluralModelLabel())->toBe('Roles');

    app()->setLocale('es');

    expect(RoleResource::getModelLabel())->toBe('Rol');
});

test('an authority with no grant is kept out of the listing', function (): void {
    signIn();

    livewire(ListRoles::class)->assertForbidden();
});

test('the screen that changes a role is shut at both of its gates', function (): void {
    $user = signIn();
    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])->assertForbidden();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(EditRole::class, ['record' => $role->getKey()])->assertForbidden();

    Warden::allow($user)->to('update', $role);

    livewire(EditRole::class, ['record' => $role->getKey()])->assertOk();
});

test('the screen that mints a role is shut at both of its gates', function (): void {
    $user = signIn();

    livewire(CreateRole::class)->assertForbidden();

    Warden::allow($user)->to('viewAny', roleClass());

    livewire(CreateRole::class)->assertForbidden();

    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)->assertOk();
});

test('an authority the store trusts sees the roles there are', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole('editor');

    livewire(ListRoles::class)
        ->assertCanSeeTableRecords([$role])
        ->assertOk();
});

test('the edit screen carries the grid', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists('permissions')
        ->assertSee('fw-grid', escape: false)
        ->assertOk();
});

test('saving the edit screen hands the grid to warden', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'editor',
            'permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('editor')
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();
});

test('whoever may change a role hands out what it does not hold, itself included', function (): void {
    $user = signIn();
    $role = makeRole('editor');
    Warden::assign($role)->to($user);

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    expect(Access::granted($user, 'delete', roleClass()))->toBeFalse();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['delete' => 'granted']]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'delete', roleClass()))->toBeTrue();
});

test('the wildcard cell on the roles row reaches the account through the role it holds', function (): void {
    $user = signIn();
    $role = makeRole('editor');
    Warden::assign($role)->to($user);

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    expect(Access::granted($user, 'view', roleClass()))->toBeFalse()
        ->and(Access::granted($user, 'delete', roleClass()))->toBeFalse();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.'.StateKey::MANAGE, 'granted')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($user, 'view', roleClass()))->toBeTrue()
        ->and(Access::granted($user, 'delete', roleClass()))->toBeTrue();
});

test('a role is created with its name and its title', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'auditor', 'title' => 'Auditor'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(roleClass()::query()->where('name', 'auditor')->value('title'))->toBe('Auditor');
});

test('the listing carries the way in, and only for an authority that may create', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    livewire(ListRoles::class)->assertActionHidden('create');

    Warden::allow($user)->to('create', roleClass());

    livewire(ListRoles::class)->assertActionVisible('create');
});

test('the config that closes creation takes the button with it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    config()->set('filament-warden.roles.create', false);

    livewire(ListRoles::class)->assertActionHidden('create');
});

test('the config can close the door on creating roles at all', function (): void {
    $user = signIn();
    Warden::allow($user)->to('create', roleClass());

    expect(RoleResource::canCreate())->toBeTrue();

    config()->set('filament-warden.roles.create', false);

    expect(RoleResource::canCreate())->toBeFalse();
});

test('a protected role never leaves, whatever the policy says', function (): void {
    $user = signIn();
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('super-admin');

    expect(RoleResource::isProtected($role))->toBeTrue()
        ->and(RoleResource::canDelete($role))->toBeFalse();
});

test('deletion follows the rule the installation chose', function (bool|string $rule, bool $assigned, bool $deletable): void {
    $user = signIn();
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    if ($assigned) {
        Warden::assign($role)->to(makeUser('Holder'));
    }

    config()->set('filament-warden.roles.delete', $rule);

    expect(RoleResource::isDeletable($role))->toBe($deletable);
})->with([
    'never' => [false, false, false],
    'unassigned and free' => ['unassigned', false, true],
    'unassigned but held' => ['unassigned', true, false],
    'always' => ['all', true, true],
]);

test('a role held under another tenant is not offered for deletion', function (): void {
    $role = makeRole();
    $free = makeRole('unheld');

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    config()->set('filament-warden.roles.delete', 'unassigned');

    $held = Warden::tenant()->onceTo(8, static fn (): bool => RoleResource::isDeletable($role));
    $unheld = Warden::tenant()->onceTo(8, static fn (): bool => RoleResource::isDeletable($free));

    expect($held)->toBeFalse()
        ->and($unheld)->toBeTrue();
});

test('and a strict installation with no tenant active sees that assignment too', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    config()->set('filament-warden.roles.delete', 'unassigned');
    config()->set('warden.scope.null_behavior', 'strict');

    expect(RoleResource::isDeletable($role))->toBeFalse();
});

test('a protected role cannot be renamed from the form', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'name',
            checkComponentUsing: fn (Filament\Forms\Components\TextInput $field): bool => $field->isDisabled(),
        );
});

test('a protected role shows its grid and does not let it be operated', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'permissions',
            checkComponentUsing: fn (PermissionGrid $field): bool => $field->isDisabled(),
        )
        ->assertSee('fw-locked', escape: false)
        ->assertDontSee('x-on:click="cycle(', escape: false);
});

test('saving a protected role leaves its permissions alone, whatever the browser sends', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('the title of a protected role is its own business, and it saves', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSchemaComponentExists(
            'title',
            checkComponentUsing: fn (Filament\Forms\Components\TextInput $field): bool => ! $field->isDisabled(),
        )
        ->fillForm(['title' => 'Quien lo puede todo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('title'))->toBe('Quien lo puede todo');
});

test('a role cannot be renamed onto the protected list', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($role->refresh()->getAttribute('name'))->toBe('editor')
        ->and(RoleResource::isProtected($role))->toBeFalse();
});

test('a role cannot be created onto the protected list', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'super-admin', 'title' => 'Born locked'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(roleClass()::query()->where('name', 'super-admin')->exists())->toBeFalse();
});

test('the refused names are the ones the installation listed, not a literal in the code', function (): void {
    config()->set('filament-warden.roles.protected', ['owner']);

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'owner'])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($role->refresh()->getAttribute('name'))->toBe('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('super-admin')
        ->and(RoleResource::isProtected($role))->toBeFalse();
});

test('a protected role keeps its name, whatever the browser sends', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'owner'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->getAttribute('name'))->toBe('super-admin')
        ->and(RoleResource::isProtected($role))->toBeTrue();
});

test('a role nobody protected is still operated as before', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');
    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]]])
        ->call('save');

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();
});

test('the form is two questions in two sections, not four stacked blocks', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertSee('The role')
        ->assertSee('Permissions')
        ->assertSee('One row per entity');
});

test('a protected role does not take a condition from the payload either', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => [
            'stances' => [roleClass() => ['update' => 'granted']],
            'narrowing' => [roleClass() => ['update' => [
                'mode' => 'conditions',
                'rules' => [['logic' => 'and', 'kind' => 'value', 'column' => 'name', 'operator' => '=', 'value' => 'editor']],
            ]]],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Context::resolve()->grantClass()::query()->count())->toBe(2);
});

test('the edit and view screens carry the actions that belong on them', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertActionVisible('delete');

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertActionVisible('edit')
        ->assertActionVisible('delete');
});

test('a protected role keeps its delete button off its own screens too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->assertActionHidden('delete');

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->assertActionHidden('delete');
});

test('a protected role survives the delete action on its own screens, not only the check', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $protected->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    livewire(ViewRole::class, ['record' => $protected->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($protected->getKey())->exists())->toBeTrue();
});

test('a protected role survives the delete action itself, not only the check', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $protected = makeRole('super-admin');
    $plain = makeRole('editor');

    // Straight at livewire, the way a crafted request arrives: `callAction()`
    // asserts the action is visible before calling it, so it would prove the
    // button is hidden and nothing about whether the server refuses.
    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($protected)])
        ->call('callMountedAction', []);

    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($plain)])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($protected->getKey())->exists())->toBeTrue()
        ->and(roleClass()::query()->whereKey($plain->getKey())->exists())->toBeFalse();
});

test('deleting a role from the listing reaches the store, and warden invalidates it', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(ListRoles::class)
        ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($role)])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('deleting a role from its edit screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test("the edit screen's delete modal says what it takes with it too", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Amaru Quispe'),
        );
});

test('a role nobody holds says so on the edit screen too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this role'),
        );
});

test("the listing's delete modal says what it takes with it too", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    livewire(ListRoles::class)
        ->assertTableActionExists(
            'delete',
            record: $role,
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Amaru Quispe'),
        );
});

test('a role nobody holds says so on the listing too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    livewire(ListRoles::class)
        ->assertTableActionExists(
            'delete',
            record: $role,
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this role'),
        );
});

test("the view screen's delete modal says what it takes with it too", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Amaru Quispe'),
        );
});

test('a role nobody holds says so on the view screen too', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->assertActionExists(
            'delete',
            checkActionUsing: fn (DeleteAction $action): bool => is_string($description = $action->getModalDescription()) && str_contains($description, 'Nobody holds this role'),
        );
});

test('deleting a role from its view screen reaches the store too', function (): void {
    config()->set('cache.default', 'array');
    config()->set('filament-warden.roles.delete', 'all');

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole('editor');
    Warden::allow($role)->to('viewAny', roleClass());

    $holder = makeUser('Holder');
    Warden::assign($role)->to($holder);

    expect(Access::granted($holder, 'viewAny', roleClass()))->toBeTrue();

    livewire(ViewRole::class, ['record' => $role->getKey()])
        ->call('mountAction', 'delete', [])
        ->call('callMountedAction', []);

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeFalse()
        ->and(Access::granted($holder, 'viewAny', roleClass()))->toBeFalse();
});

test('the refusal names the protected list, in words this package wrote', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('editor');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'super-admin'])
        ->call('save')
        ->assertHasFormErrors([
            'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
        ]);

    expect($role->refresh()->getAttribute('name'))->toBe('editor');
});

test('the create screen refuses with the same sentence, not a different one', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm(['name' => 'super-admin', 'title' => 'Born locked'])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
        ]);

    expect(roleClass()::query()->where('name', 'super-admin')->exists())->toBeFalse();
});

test('a list with several names on it still refuses in one sentence', function (): void {
    config()->set('filament-warden.roles.protected', ['owner', 'super-admin', 'auditor']);

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('auditor');

    foreach (['owner', 'super-admin'] as $name) {
        livewire(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['name' => $name])
            ->call('save')
            ->assertHasFormErrors([
                'name' => 'roles.protected lists this name. A role holding it cannot be renamed, cannot be deleted, and its grid cannot be edited.',
            ]);
    }

    expect($role->refresh()->getAttribute('name'))->toBe('auditor');
});

test('a list with nothing left on it refuses nothing and says nothing', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['title' => 'Whoever can do everything'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDontSee('roles.protected lists this name');

    expect($role->refresh()->getAttribute('title'))->toBe('Whoever can do everything');
});

test('a role nobody holds counts as zero, not blank', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole();

    livewire(ListRoles::class)
        ->assertTableColumnStateSet('held', 0, $role);
});

test('the listing counts how many accounts hold each role', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole();
    Warden::assign($role)->to(makeUser('Holder'));

    livewire(ListRoles::class)
        ->assertTableColumnStateSet('held', 1, $role);
});

test('a holder restricted to a context still counts as held', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);
    Warden::assign($role)->on($post)->to(makeUser('Holder'));

    livewire(ListRoles::class)
        ->assertTableColumnStateSet('held', 1, $role);
});

test('the count column stays under the tenant you are in, unlike the delete rule beside it', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());

    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    Warden::tenant()->onceTo(8, function () use ($role): void {
        livewire(ListRoles::class)->assertTableColumnStateSet('held', 0, $role);
    });
});

/**
 * The listing's own version of "a role held under another tenant is not
 * offered for deletion" above: that test calls `RoleResource::isDeletable()`
 * directly, which never exercises `RolesTable::assignedRoleIds()` — the
 * batched, wide query "Que no cueste" (v1.5.0) built for the button's own
 * `visible()` closure. Button AND server, not only one: `assertTableActionHidden()`
 * proves the button is closed and the raw `mountAction`/`callMountedAction`
 * call proves the write is refused too, the same "not only the check" shape
 * `EditRole`'s protected-role test already uses. Both `viewAny` and `delete`
 * are granted so the only thing left to close the button is `isDeletable()`
 * itself — the previous test above grants only `viewAny`, which would hide
 * the button on policy grounds alone and prove nothing about this batch.
 */
test('the delete button beside it reads wide, unlike the count column, even batched for the page', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    Warden::tenant()->onceTo(8, function () use ($role): void {
        livewire(ListRoles::class)
            ->assertTableActionHidden('delete', $role)
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => recordKey($role)])
            ->call('callMountedAction', []);
    });

    expect(roleClass()::query()->whereKey($role->getKey())->exists())->toBeTrue();
});

test("the listing's held-by column and delete button together cost 3 assigned_roles reads for 5 roles, capped at 5", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    for ($index = 0; $index < 5; $index++) {
        Warden::assign(makeRole("role-{$index}"))->to(makeUser("Holder {$index}"));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ListRoles::class);

    $reads = heldReads();
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(5);
});

/**
 * The half a statement counter cannot see: 200 assignment rows over 5 roles,
 * so a read bounded by the role catalogue and a read bounded by the table are
 * two orders of magnitude apart while both stay at three statements. Measured
 * on this exact fixture: 400 rows hydrated by the shape that read every row,
 * 10 by the shape that groups and distincts — five roles, once per query.
 * Capped at 20, which leaves room for warden's own reads of the signed-in
 * account without leaving room for a per-row read of the fixture.
 *
 * The rows are written straight to the table rather than through
 * `Warden::assign()`: the two queries under test read `role_id` and nothing
 * else, and 200 accounts would measure the fixture builder instead.
 */
test("the listing's two reads are bounded by the role catalogue, not by the assignment table", function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('delete', roleClass());

    $rows = [];
    $entity = 1000;

    for ($index = 0; $index < 5; $index++) {
        $role = makeRole("role-{$index}");

        for ($holder = 0; $holder < 40; $holder++) {
            $rows[] = [
                'role_id' => $role->getKey(),
                'entity_type' => 'holder',
                'entity_id' => $entity++,
                'restricted_to_type' => null,
                'restricted_to_id' => null,
                'scope' => null,
            ];
        }
    }

    DB::table(Context::resolve()->table('assigned_roles'))->insert($rows);

    $hydrated = 0;

    Event::listen(
        'eloquent.retrieved: '.Context::resolve()->assignedRoleClass(),
        static function () use (&$hydrated): void {
            $hydrated++;
        },
    );

    livewire(ListRoles::class);

    expect($hydrated)->toBeLessThanOrEqual(20);
});

test('a role nobody holds says so in the delete warning', function (): void {
    expect(RolesTable::warning(makeRole()))->toContain('Nobody holds this role');
});

test('the delete warning names who it takes with it', function (): void {
    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    $warning = RolesTable::warning($role);

    expect($warning)->toContain('1 in total')
        ->and($warning)->toContain('Amaru Quispe');
});

test('the delete warning names a holder restricted to a context too', function (): void {
    $role = makeRole();
    $post = Post::query()->create(['title' => 'A post']);
    Warden::assign($role)->on($post)->to(makeUser('Amaru Quispe'));

    $warning = RolesTable::warning($role);

    expect($warning)->toContain('1 in total')
        ->and($warning)->toContain('Amaru Quispe');
});

test('the delete warning reads wide, unlike the count beside it', function (): void {
    $role = makeRole();

    Warden::tenant()->onceTo(7, static function () use ($role): void {
        Warden::assign($role)->to(makeUser('Holder'));
    });

    $warning = Warden::tenant()->onceTo(8, static fn (): string => RolesTable::warning($role));

    expect($warning)->toContain('1 in total');
});

test('the delete warning caps the names it lists, not the count', function (): void {
    $role = makeRole();

    for ($index = 0; $index < Holders::LABELS + 3; $index++) {
        Warden::assign($role)->to(makeUser("Account {$index}"));
    }

    $warning = RolesTable::warning($role);

    expect($warning)->toContain((Holders::LABELS + 3).' in total')
        ->and(mb_substr_count($warning, 'Account '))->toBe(Holders::LABELS);
});

test('a holder whose morph alias no longer resolves is counted without a name', function (): void {
    $role = makeRole();
    Warden::assign($role)->to(makeUser('Amaru Quispe'));

    Context::resolve()->assignedRoleClass()::query()->withoutGlobalScopes()->update(['entity_type' => 'gone.away']);

    $warning = RolesTable::warning($role);

    expect($warning)->toContain('1 in total')
        ->and($warning)->not->toContain('Amaru Quispe');
});

test('a save leaves alone the cell somebody else changed while this screen was open', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $screen = livewire(EditRole::class, ['record' => $role->getKey()]);

    // Somebody else, in another request, while this screen sits open.
    Warden::allow($role)->to('viewAny', roleClass());

    $screen->call('save')->assertHasNoFormErrors();

    $sent = lastNotification();

    expect(Access::granted($role, 'viewAny', roleClass()))->toBeTrue()
        ->and($sent?->getTitle())->toBe(__('filament-warden::ui.grid.concurrent.kept_title'))
        ->and($sent?->getBody())->toBe(trans_choice('filament-warden::ui.grid.concurrent.kept', 1));
});

test('a save refuses the cell this person and somebody else moved apart', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $screen = livewire(EditRole::class, ['record' => $role->getKey()]);

    $screen->set('data.permissions.stances.'.roleClass().'.viewAny', 'granted');

    Warden::forbid($role)->to('viewAny', roleClass());

    $screen->call('save')->assertHasNoFormErrors();

    expect(Access::granted($role, 'viewAny', roleClass()))->toBeFalse();
});

test('the screen re-reads the store after a save, so the next one does not collide again', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $screen = livewire(EditRole::class, ['record' => $role->getKey()]);

    Warden::allow($role)->to('viewAny', roleClass());

    $screen->call('save');

    $screen->assertSet('data.permissions.stances.'.roleClass().'.viewAny', 'granted')
        ->assertSet('data.permissions.baseline.stances.'.roleClass().'.viewAny', 'granted');
});

test('a tangled cell is emptied from the screen, which is the only way out there has ever been', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    Warden::allow($role)->to('update', roleClass())->where('name', 'alpha');
    Warden::allow($role)->to('update', roleClass());

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.update', 'abstain')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Access::granted($role, 'update', roleClass()))->toBeFalse();
});

test('a tangled cell asked for anything but off says so rather than going quiet', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    Warden::allow($role)->to('update', roleClass())->where('name', 'alpha');
    Warden::allow($role)->to('update', roleClass());

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.update', 'forbidden')
        ->call('save')
        ->assertHasNoFormErrors();

    $sent = lastNotification();
    $body = $sent?->getBody();

    expect($sent?->getTitle())->toBe(__('filament-warden::ui.grid.tangled.title'))
        ->and(is_string($body) ? $body : '')
        ->toContain(GridView::cellLabel(catalogForRoles(), roleClass(), 'update'));
});

test('a cell asked to end on a day already gone is named, not written', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    Carbon::setTestNow('2026-09-09 12:00:00');

    // Its own notice, not a line inside the tangled one: the two report the same
    // outcome for opposite reasons, and "the store holds two rules for these"
    // sends a person chasing a problem that is not there.
    livewire(EditRole::class, ['record' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.update', 'granted')
        ->set('data.permissions.until.'.roleClass().'.update', '2026-09-08T12:00:00+00:00')
        ->call('save')
        ->assertHasNoFormErrors();

    $sent = lastNotification();
    $body = $sent?->getBody();

    expect($sent?->getTitle())->toBe(__('filament-warden::ui.grid.lapsed.title'))
        ->and(is_string($body) ? $body : '')
        ->toContain(GridView::cellLabel(catalogForRoles(), roleClass(), 'update'));

    Carbon::setTestNow();
});

test('a rule that can never be true is named in red, not written as a plain grant', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    // `name` is a text column with no bool cast, so `true` against it is a rule
    // warden refuses. The refusal reaches this package AFTER the plain grant
    // beneath the condition is written, and a catch meant for two other causes
    // swallows it — so its own notice, in danger rather than warning, is the
    // difference between granting nothing and granting every row.
    livewire(EditRole::class, ['record' => $role->getKey()])
        ->set('data.permissions.stances.'.roleClass().'.update', 'granted')
        ->set('data.permissions.narrowing.'.roleClass().'.update', [
            'mode' => 'conditions',
            'rules' => [[
                'logic' => 'and', 'kind' => 'value', 'column' => 'name',
                'operator' => '=', 'value' => 'true', 'authority' => '',
            ]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $sent = lastNotification();
    $body = $sent?->getBody();

    expect($sent?->getTitle())->toBe(__('filament-warden::ui.grid.impossible.title'))
        ->and(is_string($body) ? $body : '')
        ->toContain(GridView::cellLabel(catalogForRoles(), roleClass(), 'update'))
        ->and(Access::granted($user, 'update', roleClass()))->toBeTrue();

    // The signed-in user's own grant is the one above; the ROLE got nothing.
    expect(RoleGrants::of($role, catalogForRoles())->stances)->toBeEmpty();
});

test('a save that refuses more cells than it names counts the rest', function (): void {
    $user = signIn();
    $role = makeRole();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $actions = ['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny'];

    $screen = livewire(EditRole::class, ['record' => $role->getKey()]);

    foreach ($actions as $action) {
        $screen->set('data.permissions.stances.'.roleClass().'.'.$action, 'granted');
    }

    // Somebody else, meanwhile, forbids every one of them.
    foreach ($actions as $action) {
        Warden::forbid($role)->to($action, roleClass());
    }

    $screen->call('save');

    foreach ($actions as $action) {
        expect(Access::granted($role, $action, roleClass()))->toBeFalse();
    }

    $sent = lastNotification();
    $body = $sent?->getBody();
    $body = is_string($body) ? $body : '';

    expect($sent?->getTitle())->toBe(__('filament-warden::ui.grid.concurrent.refused_title'))
        ->and($body)->toContain(GridView::cellLabel(catalogForRoles(), roleClass(), 'viewAny'))
        ->and($body)->toContain(__('filament-warden::ui.grid.concurrent.more', ['count' => 1]))
        ->and($body)->not->toContain('viewAny on ');
});

test('the screen shows the name that was actually written, not what was typed', function (): void {
    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole('super-admin');

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'owner'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.name', 'super-admin');
});

test('an ordinary save says what it did, not only that it happened', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    Warden::allow($role)->to('view', roleClass());

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => ['stances' => [roleClass() => [
            'viewAny' => 'granted',
            'create' => 'granted',
            'update' => 'forbidden',
            'view' => 'abstain',
        ]]]])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))
            ->body('2 granted, 1 forbidden, 1 revoked'));
});

test('a save that changed nothing says nothing about what it did', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('view', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $role = makeRole();

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title')));

    expect(PermissionGrid::savedBody())->toBeNull();
});

test('a role created with cells already ticked reports them too', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('create', roleClass());

    livewire(CreateRole::class)
        ->fillForm([
            'name' => 'auditor',
            'permissions' => ['stances' => [roleClass() => ['viewAny' => 'granted']]],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(Notification::make()
            ->success()
            ->title(__('filament-panels::resources/pages/create-record.notifications.created.title'))
            ->body('1 granted'));
});

test('a role whose only assignment has lapsed is still not deletable', function (): void {
    $role = makeRole();
    $account = makeUser();

    config()->set('filament-warden.roles.delete', 'unassigned');

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::assign($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to($account);

    Carbon::setTestNow('2026-09-09 12:00:00');

    // The cascade is blind to the clock exactly as it is blind to the scope: the
    // row is still there and the delete still takes it. A read that DECIDES a
    // delete therefore counts it, while the badge beside it does not — the two
    // disagree on purpose, one saying who holds the role and the other what the
    // delete would destroy. `warden:clean --expired` is what makes it deletable,
    // which is a decision somebody makes rather than one a date makes for them.
    expect(RoleResource::isDeletable($role->refresh()))->toBeFalse();

    Carbon::setTestNow();
});

test('the badge counts who holds a role, and a lapsed assignment is nobody', function (): void {
    $user = signIn();
    $role = makeRole();
    $account = makeUser('Amaru Quispe');

    Warden::allow($user)->to('viewAny', roleClass());

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::assign($role)->until(Carbon::parse('2026-09-08 12:00:00'))->to($account);

    Carbon::setTestNow('2026-09-09 12:00:00');

    // The holders sentence on the record page reports; the delete warning beside
    // it does not. Same table, two questions.
    $page = livewire(ViewRole::class, ['record' => $role->getKey()]);

    $page->assertDontSee('Amaru Quispe');

    expect(RolesTable::warning($role->refresh()))->toContain('Amaru Quispe');

    Carbon::setTestNow();
});
