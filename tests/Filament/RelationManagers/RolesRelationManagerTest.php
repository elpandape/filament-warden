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
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect(Assignment::of($account))->toBe([heldKey($role)])
        ->and(Access::granted($account, 'viewAny', Post::class))->toBeTrue();
});

/**
 * `Assignment::offers()` does not exclude a role already held — only
 * `isHeld()` does, inside `give()` — so without checking `give()`'s return
 * value here the screen would report success for a write that never
 * happened. Confirmed by reverting the `if (! Assignment::give(...))` guard
 * to an unconditional call: this test's `assertNotNotified()` went red while
 * every other test in this file stayed green, which is what "reachable"
 * means here and "the retract side is not" (see `retractAction()`'s
 * docblock) does not.
 */
test('assigning a role the account already holds notifies nothing', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->mountTableAction('assign')
        ->setTableActionData(['role' => recordKey($role)])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertNotNotified();

    expect(Assignment::of($account))->toBe([heldKey($role)]);
});

/**
 * NOT a test of `give()`'s own guard — corrected from an earlier draft that
 * named it as one. `disableOptionWhen()` is exactly `! Assignment::offers()`,
 * so any value that fails `give()`'s check is also a value the `Select`'s
 * `in:` rule already rejects (`Select::getInValidationRuleValues()` restricts
 * to `getEnabledOptions()`, computed fresh at validation time from the same
 * closure): no value can clear the form and still reach `give()`'s guard by
 * this route. Confirmed by deleting `give()`'s `! self::offers(...)` clause —
 * this test stayed green, because the form never let the value through in the
 * first place. `Assignment::give() writes nothing for a role this account may
 * not hand out` in `AssignmentTest.php` calls `give()` directly and is what
 * actually pins that guard.
 */
test('the in: rule keeps an unofferable role out of the header action form', function (): void {
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
        ->callMountedTableAction()
        ->assertHasTableActionErrors();

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

/**
 * CORRECTED, against an earlier draft that credited `Assignment::take()`'s
 * own `isHeld()` guard for the silence this test shows. Planting a thrown
 * exception at the top of `retractAction()`'s closure and retracting the
 * role between mount and call — the exact steps below — the exception never
 * surfaced: the closure does not run at all. `getTableRecord()` resolves
 * against this same table's own query, scoped to `Assignment::of($account)`,
 * so a role no longer held cannot be resolved either;
 * `resolveTableAction()` throws `ActionNotResolvableException` and
 * `mountAction()`/`callMountedAction()` swallow it before the closure is
 * ever reached (`InteractsWithActions.php:651-659`). What this test actually
 * proves is that silence, not a guard inside this screen — `take()`'s own
 * `isHeld()` guard is real but unreachable through this exact wiring, pinned
 * instead directly against `Assignment::take()` in `AssignmentTest.php`'s
 * "take() writes nothing for a role not held".
 */
test('a raw retract call on a role already gone by the time it runs notifies nothing', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ]);

    $test->mountTableAction('retract', $role);

    Warden::retract($role)->from($account);

    $test->callMountedTableAction()
        ->assertNotNotified();

    expect(Assignment::of($account))->toBeEmpty();
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

/**
 * CORRECTED, against a Step 7 breakage that predicted a fatal and did not get
 * one. Removing `$relationship` outright left every test in this file green,
 * `stan` clean. The reason: `getRelationship()`'s lazy closure, installed by
 * the base `makeTable()`, is overwritten by `->relationship(null)` in
 * `table()` before it is ever evaluated, and the one branch of
 * `canViewForRecord()` that would call `getRelationshipName()` is exactly the
 * one `$relatedResource` skips. `$relationship` is kept anyway (documented in
 * its own docblock) as the contract Filament expects, not because this class
 * is measured to need it. `Post` stands in for the owner record here on
 * purpose — it declares no `roles()` method at all, so the assertion would
 * fail loudly, not silently, if any code path here ever reached for the
 * account's own relation instead of `Assignment::of()`.
 */
test('an owner record with no roles() relation of its own still renders', function (): void {
    signInAsRoleManager();

    $post = Post::query()->create(['title' => 'Not an authority']);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $post,
        'pageClass' => EditRole::class,
    ])
        ->assertCountTableRecords(0)
        ->assertOk();
});

/**
 * `$relatedResource` — not `$relationship`, and this is the one that carries
 * the actual security property — is unpinned without this. `canViewForRecord()`
 * takes its `$relatedResource::canAccess()` branch and never touches
 * `$ownerRecord`'s own relation at all, so this passes even for `Post`, which
 * declares no `roles()` method: `RolePolicy` is what decided it, not a guess
 * at an unrelated model's relation. Without `$relatedResource`, the fallback
 * branch runs `$ownerRecord->roles()`, and `Post` has no such method — a
 * `BadMethodCallException`, confirmed by removing the property and running
 * this exact assertion.
 */
test('canViewForRecord() closes with the packaged Policy, not a guess at an unrelated relation', function (): void {
    signInAsRoleManager();

    $post = Post::query()->create(['title' => 'Not an authority']);

    expect(RolesRelationManager::canViewForRecord($post, EditRole::class))->toBeTrue();
});
