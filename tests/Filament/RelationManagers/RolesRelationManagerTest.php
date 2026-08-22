<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\RelationManagers\RolesRelationManager;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

use function Pest\Livewire\livewire;

/**
 * Mounted for real, never through a static method alone: §6.29 measured that
 * `livewire(GuardedPage::class)` runs Filament's own guard hook even on a
 * class no test panel registers, so a bare `assertForbidden()` proves nothing
 * about where the 403 came from without a positive twin sitting next to it.
 * Every negative case here has one.
 *
 * A relation manager's OWN guard, `CanAuthorizeAccess::hydrateCanAuthorizeAccess()`,
 * is wired to Livewire's `hydrate` cycle — not `mount`. Measured: a freshly
 * created `livewire(RolesRelationManager::class, [...])` returns 200 for a
 * signed-in account with no `viewAny`, because a component's first appearance
 * has no snapshot to hydrate FROM and only `mount`-family hooks run for it
 * (`SupportLifecycleHooks::mount()` calls `boot`/`mount`/`booted`;
 * `hydrate()` — the one that fires `hydrateCanAuthorizeAccess()` — only runs
 * reviving an EXISTING snapshot). A second interaction on the same instance,
 * such as `->call('$refresh')`, forces exactly that revival and the 403
 * appears. This is not a gap this package can close — it belongs to Filament's
 * `RelationManager` base — and it is not the primary gate either:
 * `HasRelationManagers::getRelationManagers()` calls the same static
 * `canViewForRecord()` to filter the tab list BEFORE a denied manager is ever
 * instantiated, so a real page never offers the tab in the first place. The
 * hydrate hook is the second layer, for a request that reaches the component
 * directly, and it is what the interaction-based test below exercises.
 *
 * `pageClass` never points at a real account resource — this package ships
 * none, the account model belongs to the consuming application — so `EditRole`
 * stands in. `canViewForRecord()` and `isReadOnly()` only care whether the
 * class is an `EditRecord`/`ViewRecord` subclass, never what it manages.
 *
 * `Assignment::of()` reads `assigned_roles` by entity only, with no scope
 * predicate, so a role held elsewhere still surfaces here — that is what lets
 * the "elsewhere" badge exist at all.
 *
 * `assertOk()` always comes LAST in a chain here, never first (AGENTS.md
 * §6.12, §6.22): PHPStan types it as returning `TestResponse`, which carries
 * none of the table-testing methods this file also calls, even though it
 * actually returns `Testable` at runtime under the installed livewire and the
 * chain genuinely keeps running. Chaining a table assertion after it is a
 * `stan` failure, not a real one.
 */
pest()->extend(TestCase::class);

/**
 * Trusted with both halves this screen needs: `viewAny` opens the tab through
 * `RoleResource::canAccess()`, `update` is what `Assignment::mayHandOut()` asks
 * for on every role in the catalogue.
 */
function signInAsRoleManager(): Model
{
    $user = signIn();

    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    return $user;
}

/**
 * A role's own key, narrowed the way `Assignment::of()` returns it: `getKey()`
 * is `mixed`, and a key that does not read as one names no row at all.
 */
function heldKey(Model $role): int|string
{
    $key = $role->getKey();

    return is_int($key) || is_string($key) ? $key : '';
}

test('without viewAny on roles the tab is filtered out of a page before it mounts', function (): void {
    signIn();

    $account = makeUser();

    expect(RolesRelationManager::canViewForRecord($account, EditRole::class))->toBeFalse();
});

test('without viewAny on roles a live instance still refuses on the next request', function (): void {
    signIn();

    $account = makeUser();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->call('$refresh')
        ->assertForbidden();
});

test('with viewAny on roles the tab opens and lists what the account holds', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->assertCanSeeTableRecords([$role])
        ->assertOk();
});

test('a role restricted to a context is listed and has no retract action', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->on($post)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->assertCanSeeTableRecords([$role])
        ->assertTableActionHidden('retract', $role)
        ->assertOk();
});

test('a role held at another scope is listed and has no retract action', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Warden::tenant()->onceTo(5, function () use ($account, $role): void {
        livewire(RolesRelationManager::class, [
            'ownerRecord' => $account,
            'pageClass' => EditRole::class,
        ])
            ->assertCanSeeTableRecords([$role])
            ->assertTableActionHidden('retract', $role)
            ->assertOk();
    });
});

test('a role held both with and without a context is listed once', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->to($account);
    Warden::assign($role)->on($post)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->assertCountTableRecords(1)
        ->assertOk();
});

test('assigning through the header action writes one row the store honours at once', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->mountTableAction('assign')
        ->setTableActionData(['role' => recordKey($role)])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Assignment::of($account))->toBe([heldKey($role)])
        ->and(Access::granted($account, 'viewAny', Post::class))->toBeTrue();
});

test('assigning a role nobody may hand out through the header action writes nothing', function (): void {
    signIn();
    Warden::allow(signedIn())->to('viewAny', roleClass());

    $account = makeUser();
    $role = makeRole('editor');

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->mountTableAction('assign')
        ->setTableActionData(['role' => recordKey($role)])
        ->callMountedTableAction();

    expect(Assignment::of($account))->toBeEmpty();
});

test('a role value that is not a key writes nothing through the header action', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    makeRole('editor');

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->mountTableAction('assign')
        ->setTableActionData(['role' => ['not-a-key']])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Assignment::of($account))->toBeEmpty();
});

test('retracting through the row action takes the role back', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->mountTableAction('retract', $role)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect(Assignment::of($account))->toBeEmpty();
});

/**
 * NOT a bypass of `visible()` — measured, and corrected from an earlier draft
 * of this file that assumed it was. `mountAction()`/`callMountedAction()` both
 * check `isDisabled()`, which folds in `isHidden()` — the same `visible()`
 * closure, evaluated fresh at each call — so a raw call respects it exactly as
 * a real click would (§6.23). What this proves instead is that the check is
 * live, not cached from render time: mounting while the role is offered and
 * restricting it before calling still leaves the row untouched.
 */
test('a raw retract call on a role restricted after it was mounted writes nothing', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ]);

    $test->mountTableAction('retract', $role);

    $post = Post::query()->create(['title' => 'A post']);
    Warden::assign($role)->on($post)->to($account);

    $test->callMountedTableAction();

    expect(Assignment::of($account))->toBe([heldKey($role)]);
});

test('the name column is searchable and sortable', function (): void {
    signInAsRoleManager();

    $account = makeUser();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])->assertTableColumnExists(
        'name',
        static fn (TextColumn $column): bool => $column->isSearchable() && $column->isSortable(),
    );
});
