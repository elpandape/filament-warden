<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\RelationManagers\RolesRelationManager;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\EditRole;
use ElPandaPe\FilamentWarden\Filament\Resources\Roles\Pages\ViewRole;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

/**
 * This file's own copy, not a naming preference: `make test`/`make coverage`
 * run `pest --parallel`, which splits test FILES across worker processes, so
 * a global helper declared in `AssignmentTest.php` is simply undefined in a
 * worker that never loads that file — the same reason `RoleResourceTest.php`
 * carries its own `heldReads()` instead of reusing `assignedRoleReads()`.
 */
function assignModalReads(): int
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
        ->assertTableColumnStateSet('held_as', 'here', $role)
        ->assertOk();
});

/**
 * I1 of the v1.4.0 whole-branch review: this test and the one below it built
 * exactly the two scenarios `heldAs()` tells apart, and until now both
 * proved only `assertTableActionHidden('retract', …)` — which reads the same
 * whether `heldAs()` answers `restricted` or `elsewhere`, because BOTH close
 * the retract action the same way. `LanguageTest.php`'s "every way a role
 * can be held has a word" pins the SET `heldAs()` can produce against the
 * SET of declared keys, which cannot see a swap of which record produces
 * which value — the two match arms in `heldAs()` (`Assignment::isRestricted()`
 * before `isElsewhere()`, mirroring `Assignment::descriptions()`'s own
 * priority) could trade places and all 798 tests before this one stayed
 * green. `assertTableColumnStateSet()` reads the badge's own state, not
 * just whether a button is hidden, and is what actually distinguishes them.
 */
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
        ->assertTableColumnStateSet('held_as', 'restricted', $role)
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
            ->assertTableColumnStateSet('held_as', 'elsewhere', $role)
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

/**
 * I2 of the v1.4.0 whole-branch review: the CHANGELOG's own "Not included"
 * entry named this cost — 405 `assigned_roles` statements opening the assign
 * modal against a 200-role catalogue — and deferred the fix (memoising
 * `Assignment::assignments()` risks handing a check right after a write a
 * stale row list, `AssignmentTest.php` already explains why `assignments()`
 * stays unmemoised) without capping it, unlike every other cost this
 * codebase measures and defers: `RoleResourceTest.php`'s 11-measured/13-cap,
 * `AssignmentTest.php`'s 5-measured/8-cap and 6-measured/10-cap.
 * `Select::getOptionsForJs()` calls `isOptionDisabled()` once per option
 * (`Select.php:155`), and `disableOptionWhen()` here is
 * `! Assignment::offers()`, which costs two unmemoised `assignments()` reads
 * per role — `isRestricted()` and `isElsewhere()` each loop it fresh. Without
 * a cap, nothing reddens when 405 becomes 2000 on the 200-role installation
 * this screen exists to serve.
 */
test('the assign modal costs 405 assigned_roles statements for 200 roles, capped at 410', function (): void {
    signInAsRoleManager();

    $account = makeUser();

    for ($i = 0; $i < 200; $i++) {
        makeRole('role-'.$i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])->mountTableAction('assign');

    $reads = assignModalReads();
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(410);
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
 * Removing `$relationship` outright leaves every test in this file green,
 * `stan` clean — re-measured after `$relatedResource` moved to `null` for
 * B1/B2 (its own docblock), because that change was flagged as exactly the
 * kind of thing that could put `getRelationshipName()` back in play. It did
 * not: `getRelationship()`'s lazy closure, installed by the base
 * `makeTable()`, is still overwritten by `->relationship(null)` in `table()`
 * before it is ever evaluated, and `canViewForRecord()` is now overridden
 * directly below and never reaches the base branch that would call
 * `getRelationshipName()` either way. `$relationship` is kept anyway
 * (documented in its own docblock) as the contract Filament expects, not
 * because this class is measured to need it. `Post` stands in for the owner
 * record here on purpose — it declares no `roles()` method at all, so the
 * assertion would fail loudly, not silently, if any code path here ever
 * reached for the account's own relation instead of `Assignment::of()`.
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
 * The overridden `canViewForRecord()` — not `$relationship`, and this is the
 * one that carries the actual security property — is unpinned without this.
 * Since B1/B2 forced `$relatedResource` to `null`, the override returns
 * `RoleResource::canAccess()` directly instead of relying on the base
 * class's own `$relatedResource` branch to do it, so this passes even for
 * `Post`, which declares no `roles()` method: `RolePolicy` is what decided
 * it, not a guess at an unrelated model's relation. Removing the override
 * falls to the base implementation's `null`-`$relatedResource` branch, which
 * runs `$ownerRecord->roles()` — `Post` has no such method — a
 * `BadMethodCallException`, confirmed by removing the override and running
 * this exact assertion.
 */
test('canViewForRecord() closes with the packaged Policy, not a guess at an unrelated relation', function (): void {
    signInAsRoleManager();

    $post = Post::query()->create(['title' => 'Not an authority']);

    expect(RolesRelationManager::canViewForRecord($post, EditRole::class))->toBeTrue();
});

/**
 * B1 of the v1.4.0 whole-branch review. With `$relatedResource` pointed at
 * `RoleResource::class`, `InteractsWithRelationshipTable::makeTable()` ran
 * `RoleResource::configureTable($table)` — `RolesTable::configure()` —
 * before `table()` below ever set `recordActions([retract])`, and that
 * registers `RolesTable`'s own `EditAction`/`DeleteAction` into the table's
 * `$flatActions` cache. `recordActions()` replaces the array a render walks
 * but never touches that cache (`HasRecordActions.php:32-42` — no
 * `removeCachedActions()` call, unlike `headerActions()`), and
 * `resolveTableAction()` resolves a mounted action by name straight off it
 * (`InteractsWithActions.php:649`). Measured against that exact source:
 * `getFlatActions()` on a mounted instance returned
 * `edit, delete, assign, retract`, and a raw `mountAction`/`callMountedAction`
 * call reached the leaked `edit` to rename a role signed in with only
 * `viewAny`/`update`, and the leaked `delete` to remove one signed in with
 * `delete` and `roles.delete => 'all'` — neither action this screen shows or
 * intends to serve. `$relatedResource = null` (its own docblock) stops
 * `configureTable()` from ever running, so nothing but `assign`/`retract` is
 * ever cached.
 */
test('the flat action list is exactly assign and retract, never the edit/delete RolesTable would leak in', function (): void {
    signInAsRoleManager();

    $account = makeUser();

    /** @var RolesRelationManager $manager */
    $manager = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])->instance();

    $names = array_keys($manager->getTable()->getFlatActions());
    sort($names);

    expect($names)->toBe(['assign', 'retract']);
});

/**
 * B2 of the v1.4.0 whole-branch review, closed by the same `null` as the test
 * above. The leaked `RoleResource::configureTable()` call also left this
 * table without `->hasCustomRecordUrl()`, so `InteractsWithRelationshipTable
 * ::makeTable()` installed its own default `recordUrl` closure
 * (`InteractsWithRelationshipTable.php:146-181`), which walks the leaked
 * `edit`/`view` actions for a URL and reaches
 * `RelationManager::getDefaultActionUrl()` → `RoleResource::getUrl('edit', …)`.
 * On the `bare` panel fixture (AGENTS.md §6.3 / `BarePanelProvider`: no
 * plugin, so `RoleResource` is never attached there) that measured throwing
 * `Route [filament.bare.resources.roles.edit] not defined` as soon as the
 * table had one row — a panel this package's own guard and Policies still
 * protect (`RolePolicy` is registered by the service provider, not by the
 * panel's plugin), so this table has every right to render there. With
 * `$relatedResource = null`, `configureTable()` never runs, so no `edit`/
 * `view` action exists for that closure to find — it returns `null`, and the
 * table draws no row link at all rather than reaching for a route that does
 * not exist.
 */
test('a panel without RoleResource attached still renders the table', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('bare'));
    Filament::bootCurrentPanel();

    $user = signIn();
    Warden::allow($user)->to('viewAny', roleClass());
    Warden::allow($user)->to('update', roleClass());

    $account = makeUser();
    $role = makeRole('editor');
    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])
        ->assertCountTableRecords(1)
        ->assertOk();
});

/**
 * `ViewRecord`'s gate, and it is a DIFFERENT one from every other test above:
 * `isReadOnly()`, not `Assignment::offers()`. `->call('mountAction', …, ['table'
 * => true, 'recordKey' => …])` then `->call('callMountedAction', [])` are used
 * throughout, never `callAction()` — §6.16 measured that `callAction()`'s test
 * helper checks visibility BEFORE calling, so a test written with it proves the
 * button is hidden and never that the server refuses. `assertTableActionHidden()`
 * is used for the same reason `assertTableActionDoesNotExist()` is not (§6.23):
 * the second swallows `ActionNotResolvableException` and calls that success,
 * so it cannot tell "absent" from "unresolvable".
 *
 * The hidden/visible tests and the write tests are DELIBERATELY separate —
 * CORRECTED from an earlier draft that chained both onto one `$test` per
 * case, `assertTableActionHidden()` first and the write check after. That
 * chain cannot discriminate: `assertTableActionHidden()` halts the test on
 * its own failure before the write assertion is ever reached, so a broken
 * `->visible()` alone always reddens the SAME line regardless of whether the
 * closure's own repeated check would also have caught it. Measured directly,
 * three ways, once per action (`assignAction()`/`retractAction()`), against
 * the write-only tests below with NO `assertTableActionHidden()` in front of
 * them:
 *
 *   1. `! $this->isReadOnly()` removed from `->visible()` ONLY, the closure's
 *      own copy left in place — the write-only test STAYED GREEN. The
 *      closure caught it alone.
 *   2. Restored, then `! $this->isReadOnly() &&` removed from the closure
 *      ONLY, `->visible()` left in place — the write-only test STAYED GREEN
 *      too. `isDisabled()`, fed by `->visible()`, caught it alone, the same
 *      mechanism Task 2 measured for the restricted-role case.
 *   3. Both removed together — the write-only test WENT RED: the write
 *      happened. Restored.
 *
 * So both copies are real, independently sufficient protection — not the
 * "cannot be shown to discriminate" an earlier draft of this file claimed.
 * The mechanism that makes the closure's copy matter beyond a hypothetical:
 * `Action::call()` (`vendor/filament/actions/src/Action.php:675-684`) performs
 * no `isDisabled()`/`isVisible()` check of its own — that gate lives only in
 * `mountAction()`/`callMountedAction()`. Anything that reaches `->call()` by
 * another route bypasses `->visible()` entirely, and the closure's own check
 * is the only thing left standing.
 *
 * The record resolves fine on `ViewRole` in every test below — the account
 * still holds the role, `Assignment::of($account)` still finds it, nothing
 * here relies on Task 2's OTHER finding (a role that cannot resolve throws
 * before the closure for an unrelated reason). What closes these cases is
 * `isReadOnly()` alone.
 */
test('on ViewRecord the assign action is hidden', function (): void {
    signInAsRoleManager();

    $account = makeUser();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewRole::class,
    ])->assertTableActionHidden('assign');
});

test('off ViewRecord the assign action is visible', function (): void {
    signInAsRoleManager();

    $account = makeUser();

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])->assertTableActionVisible('assign');
});

test('a raw call to the assign action on ViewRecord writes nothing', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewRole::class,
    ]);

    $test->call('mountAction', 'assign', [], ['table' => true]);
    $test->set('mountedActions.0.data.role', recordKey($role));
    $test->call('callMountedAction', []);

    expect(Assignment::of($account))->toBeEmpty();
});

test('a raw call to the assign action off ViewRecord writes one row', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ]);

    $test->call('mountAction', 'assign', [], ['table' => true]);
    $test->set('mountedActions.0.data.role', recordKey($role));
    $test->call('callMountedAction', []);

    expect(Assignment::of($account))->toBe([heldKey($role)]);
});

test('on ViewRecord the retract action is hidden', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewRole::class,
    ])->assertTableActionHidden('retract', $role);
});

test('off ViewRecord the retract action is visible', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ])->assertTableActionVisible('retract', $role);
});

test('a raw call to the retract action on ViewRecord writes nothing', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewRole::class,
    ]);

    $test->call('mountAction', 'retract', [], ['table' => true, 'recordKey' => recordKey($role)]);
    $test->call('callMountedAction', []);

    expect(Assignment::of($account))->toBe([heldKey($role)]);
});

test('a raw call to the retract action off ViewRecord retracts it', function (): void {
    signInAsRoleManager();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $test = livewire(RolesRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditRole::class,
    ]);

    $test->call('mountAction', 'retract', [], ['table' => true, 'recordKey' => recordKey($role)]);
    $test->call('callMountedAction', []);

    expect(Assignment::of($account))->toBeEmpty();
});
