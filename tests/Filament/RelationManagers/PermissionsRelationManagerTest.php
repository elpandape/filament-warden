<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Filament\RelationManagers\PermissionsRelationManager;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\EditPermission;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages\ViewPermission;
use ElPandaPe\FilamentWarden\Grants\DirectGrants;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

pest()->extend(TestCase::class);

/**
 * The screen is off out of the box, so every test that renders it has to turn
 * it on — which is the point of the key and not an inconvenience of the suite:
 * an installation that never decides to show direct grants never sees one
 * handed out from a screen it did not ask for.
 *
 * `update` over a permission is what a write here asks for: the same ability
 * the permission's own hand-out asks for, because this is the way back from
 * that screen and two doors onto one write cannot ask two different questions.
 */
function signInAsGrantManager(): Model
{
    config()->set('filament-warden.permissions.direct', true);

    $user = signIn();

    Warden::allow($user)->to('viewAny', permissionClass());
    Warden::allow($user)->to('update', permissionClass());

    return $user;
}

/**
 * The screen under test, mounted the way Filament mounts it.
 *
 * `Testable` is generic over the component and `livewire()` hands back one
 * parameterised by the class it was given, so the annotation says the same
 * thing rather than widening it — a bare `Testable` fails the analyser at
 * level max, and a mismatched parameter fails it differently.
 *
 * @return Testable<PermissionsRelationManager>
 */
function directManager(Model $account): Testable
{
    /** @var Testable<PermissionsRelationManager> $page */
    $page = livewire(PermissionsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => EditPermission::class,
    ]);

    return $page;
}

function grantRows(Model $account): int
{
    return Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('entity_type', $account->getMorphClass())
        ->where('entity_id', $account->getKey())
        ->count();
}

test('the screen does not exist until the installation says it does', function (): void {
    signIn();

    // Two questions and both have to answer yes, so this only proves the first.
    // The one below proves the other half.
    expect(PermissionsRelationManager::canViewForRecord(makeUser(), EditPermission::class))->toBeFalse();
});

test('and it does not exist for somebody who may not look at permissions', function (): void {
    config()->set('filament-warden.permissions.direct', true);

    signIn();

    expect(PermissionsRelationManager::canViewForRecord(makeUser(), EditPermission::class))->toBeFalse();
});

test('a direct grant is listed with its polarity, its reach and its date', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');

    Warden::allow($account)->until(now()->addWeeks(2))->to('viewAny', Post::class);

    directManager($account)
        ->assertOk()
        ->assertSee('granted')
        ->assertSee('Every row');

    expect(DirectGrants::of($account))->toHaveCount(1);
});

test('a direct prohibition reads as a prohibition, not as an absence', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');

    Warden::forbid($account)->to('viewAny', Post::class);

    directManager($account)->assertSee('forbidden');

    expect(DirectGrants::of($account)[0]->forbidden)->toBeTrue();
});

test('a lapsed grant is not listed, because it authorises nothing', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');

    Warden::allow($account)->until(now()->addWeek())->to('viewAny', Post::class);

    // Backdated by hand: warden refuses a past date on the way in, which leaves
    // this the only way to build the row an installation gets by waiting.
    Context::resolve()->grantClass()::query()
        ->withoutGlobalScopes()
        ->where('entity_id', $account->getKey())
        ->update(['expires_at' => now()->subDay()]);

    expect(DirectGrants::of($account))->toBeEmpty();
});

test('a permission can be handed straight to an account, with a date', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    directManager($account)
        ->callTableAction('grant', arguments: [], data: [
            'permission' => $row->getKey(),
            'forbidden' => 0,
            'until' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertNotified();

    $held = DirectGrants::of($account);

    expect($held)->toHaveCount(1)
        ->and($held[0]->forbidden)->toBeFalse()
        ->and($held[0]->ends)->not->toBeNull();
});

test('a prohibition is written with no date at all', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    directManager($account)
        ->callTableAction('grant', arguments: [], data: ['permission' => $row->getKey(), 'forbidden' => 1])
        ->assertNotified();

    $held = DirectGrants::of($account);

    expect($held[0]->forbidden)->toBeTrue()
        ->and($held[0]->ends)->toBeNull();
});

test('flipping the polarity leaves one row, not two', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    // `forbidden` is part of the unique index, so granting and forbidding
    // coexist as two rows. A screen that only wrote the new state would leave
    // both behind, and the cell would be in two states at once — which is the
    // rule the grid already follows for every step of its cycle (§6.11).
    directManager($account)
        ->callTableAction('grant', arguments: [], data: ['permission' => $row->getKey(), 'forbidden' => 0]);

    directManager($account)
        ->callTableAction('grant', arguments: [], data: ['permission' => $row->getKey(), 'forbidden' => 1]);

    expect(grantRows($account))->toBe(1)
        ->and(DirectGrants::of($account)[0]->forbidden)->toBeTrue();
});

test('a direct grant can be taken back, and the catalogue row stays', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    Warden::allow($account)->to($row);

    directManager($account)
        ->callTableAction('revoke', $row)
        ->assertNotified();

    expect(grantRows($account))->toBe(0)
        ->and(permissionClass()::query()->withoutGlobalScopes()->whereKey($row->getKey())->exists())->toBeTrue();
});

test('a prohibition is taken back by the same button', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    Warden::forbid($account)->to($row);

    // Both are sent, because the row says which one exists and a caller that
    // guessed would leave the other behind.
    directManager($account)
        ->callTableAction('revoke', $row)
        ->assertNotified();

    expect(grantRows($account))->toBe(0);
});

test('flipping a direct grant into a prohibition is one operation', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');
    DirectGrants::write($account, $row, false, null);

    $operations = operationsDuring(static function () use ($account, $row): void {
        DirectGrants::write($account, $row, true, null);
    });

    expect(count($operations))->toBeGreaterThan(1)
        ->and($operations)->not->toContain(null)
        ->and(array_unique($operations))->toHaveCount(1);
});

test('taking back a permission held in both polarities is one operation', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    // `write()` never leaves both rows; warden's own API does, and `revoke()`
    // sends both removals because it cannot know which one it will find.
    Warden::allow($account)->to($row);
    Warden::forbid($account)->to($row);

    $operations = operationsDuring(static function () use ($account, $row): void {
        DirectGrants::revoke($account, $row);
    });

    expect(count($operations))->toBeGreaterThan(1)
        ->and($operations)->not->toContain(null)
        ->and(array_unique($operations))->toHaveCount(1);
});

test('an authority without update over permissions writes nothing', function (): void {
    config()->set('filament-warden.permissions.direct', true);

    $user = signIn();
    Warden::allow($user)->to('viewAny', permissionClass());

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    // Reading is not handing out. The write is refused in `DirectGrants`, which
    // is where the guarantee lives — the modal has no per-option gate to lean on.
    expect(DirectGrants::write($account, $row, false, null))->toBeFalse()
        ->and(DirectGrants::revoke($account, $row))->toBeFalse()
        ->and(grantRows($account))->toBe(0);
});

test('the wildcard is never offered, whatever is typed into the box', function (): void {
    signInAsGrantManager();

    Warden::allow(makeRole('editor'))->everything();

    // `entity_type = '*'` is the one row that covers literally everything, and
    // property 6 says this package never writes it. Offering it from a dropdown
    // would be the single write that hands an account the whole installation.
    $offered = DirectGrants::offerable('');

    $wildcard = permissionClass()::query()->withoutGlobalScopes()->where('entity_type', '*')->firstOrFail();

    /** @var int|string $key */
    $key = $wildcard->getKey();

    expect($offered)->not->toHaveKey($key);
});

test('a wildcard in the search is looked for, not obeyed', function (): void {
    signInAsGrantManager();

    Warden::allow(makeRole('editor'))->to('viewAny', Post::class);

    // Unescaped, `%` matches every row and the box becomes a way to page
    // through the catalogue rather than to search it; escaped without an ESCAPE
    // clause it matches nothing at all on SQLite (§6.38).
    expect(DirectGrants::offerable('%'))->toBeEmpty();
});

test('a grant written in another tenant is shown, marked, and left alone', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    Warden::tenant()->onceTo(7, static fn () => Warden::allow($account)->to($row));

    // `disallow()` targets one exact scope, so a revoke from here would delete
    // nothing, report success and come back unchanged on reload (§6.21). Shown
    // and explained rather than hidden: a row whose action is simply missing
    // reads as a bug rather than as a decision.
    $page = directManager($account);

    $page->assertTableActionHidden('revoke', $row);
    $page->assertSee('outside the tenant you are in');
});

test('a grant written in one tenant is not listed from another', function (): void {
    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    Warden::tenant()->onceTo(7, static fn () => Warden::allow($account)->to($row));

    // Read from a tenant, never without one: with no tenant active, warden's
    // `all` default reads every scope and the row is listed either way. Tenant
    // 7 is the control — without it, an empty list from anywhere would pass.
    $fromSeven = Warden::tenant()->onceTo(7, static fn (): array => DirectGrants::of($account));
    $fromEight = Warden::tenant()->onceTo(8, static fn (): array => DirectGrants::of($account));

    expect($fromSeven)->toHaveCount(1)
        ->and($fromEight)->toBeEmpty();
});

test('a global grant is listed under a tenant, marked as from elsewhere', function (): void {
    $account = makeUser('Holder');

    Warden::allow($account)->to(makePermission('export-reports'));

    /** @var list<DirectGrants> $held */
    $held = Warden::tenant()->onceTo(8, static fn (): array => DirectGrants::of($account));

    expect($held)->toHaveCount(1)
        ->and($held[0]->elsewhere)->toBeTrue();
});

test('a rule pinned to one record says so, and does not borrow a shape', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $post = Post::query()->create(['title' => 'alpha']);

    Warden::allow($account)->to('view', $post);

    // A rule with an `entity_id` answers no class check at all, so none of the
    // reach shapes describes it — the same order `PermissionInfolist` asks in,
    // and both screens change together or they disagree about one row.
    $held = DirectGrants::of($account);

    expect($held)->toHaveCount(1)
        ->and($held[0]->reach())->toBe('record');

    directManager($account)->assertSee('One record only');
});

test('a read-only page writes nothing, whatever reaches the action', function (): void {
    signInAsGrantManager();

    $account = makeUser('Holder');
    $row = makePermission('export-reports');

    // `ViewRecord` is what makes the manager read-only, and the guard inside
    // the closure is a SECOND one: `Action::call()` consults neither
    // `isDisabled()` nor `isVisible()`, so anything arriving by that route has
    // only that line in front of it. Driven with a bare mount pair rather than
    // `callTableAction()`, which checks visibility before calling and would
    // prove the button hidden instead of the server refusing (§6.16).
    $page = livewire(PermissionsRelationManager::class, [
        'ownerRecord' => $account,
        'pageClass' => ViewPermission::class,
    ]);

    $page->assertTableActionHidden('grant');
    $page->call('mountAction', 'grant', [], ['table' => true]);
    $page->call('callMountedAction', []);

    expect(grantRows($account))->toBe(0);
});
