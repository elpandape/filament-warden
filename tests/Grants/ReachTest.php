<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Grants\Reach;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Document;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

pest()->extend(TestCase::class);

function reachedPermission(string $name = 'view'): Model
{
    return latestPermission($name);
}

function documents(int $count = 3): void
{
    for ($index = 0; $index < $count; $index++) {
        Document::query()->create(['title' => "Document {$index}"]);
    }
}

test('a permission with no model behind it does not fall on rows at all', function (): void {
    $account = makeUser();

    Warden::allow($account)->to('export-reports');

    $reach = Reach::of(reachedPermission('export-reports'), $account);

    expect($reach->available)->toBeFalse()
        ->and($reach->sentence())->toContain('does not fall on rows');
});

test('the wildcard is not a model either', function (): void {
    $account = makeUser();

    Warden::allow($account)->everything();

    expect(Reach::of(reachedPermission('*'), $account)->available)->toBeFalse();
});

test('a model that never opted in is detected before it is asked, not after', function (): void {
    $account = makeUser();

    Post::query()->create(['title' => 'A post']);

    Warden::allow($account)->to('view', Post::class);

    $reach = Reach::of(reachedPermission(), $account);

    // Without the check this would answer `0 of 1` in silence: the call does not
    // fail, it builds `where "can" = ?` and returns nothing.
    expect($reach->available)->toBeFalse()
        ->and($reach->matched)->toBe(0)
        ->and($reach->sentence())->toContain('QueriesByPermission')
        ->and($reach->sentence())->toContain(Post::class);
});

test('a model that opted in is counted', function (): void {
    $account = makeUser();

    documents();

    Warden::allow($account)->to('view', Document::class);

    $reach = Reach::of(reachedPermission(), $account);

    expect($reach->available)->toBeTrue()
        ->and($reach->matched)->toBe(3)
        ->and($reach->total)->toBe(3)
        ->and($reach->partial)->toBeFalse()
        ->and($reach->sentence())->toStartWith('It falls on 3 of 3 rows.')
        ->and($reach->sentence())->toContain('a policy that denies never shows up in it');
});

test('a narrowed grant falls on the rows it narrows to', function (): void {
    $account = makeUser();

    documents();

    Warden::allow($account)->to('view', Document::class)->where('title', 'Document 1');

    $reach = Reach::of(reachedPermission(), $account);

    expect($reach->matched)->toBe(1)
        ->and($reach->total)->toBe(3);
});

test('a grant over what the account owns falls on what it owns', function (): void {
    $account = makeUser();

    documents();

    Document::query()->first()?->update(['user_id' => $account->getKey()]);

    Warden::allow($account)->toOwn(Document::class, 'view');

    expect(Reach::of(reachedPermission(), $account)->matched)->toBe(1);
});

test('an account with nothing falls on nothing', function (): void {
    $account = makeUser();

    documents();

    $permission = makePermission('view');
    $permission->update(['entity_type' => new Document()->getMorphClass()]);

    $reach = Reach::of($permission, $account);

    expect($reach->available)->toBeTrue()
        ->and($reach->matched)->toBe(0)
        ->and($reach->total)->toBe(3);
});

test('a role held in a context makes the count a lower bound, and it says so', function (): void {
    $account = makeUser();
    $role = makeRole('editor');

    documents();

    $document = Document::query()->firstOrFail();

    Warden::allow($role)->to('view', Document::class);
    Warden::assign($role)->on($document)->to($account);

    $reach = Reach::of(reachedPermission(), $account);

    // Measured divergence: the panel answers true for the in-context row and the
    // query cannot see it at all.
    expect($reach->partial)->toBeTrue()
        ->and($reach->sentence())->toContain('at least')
        ->and($reach->sentence())->toContain('the panel will answer for more rows')
        ->and($reach->sentence())->toContain('a policy that denies never shows up in it');
});

test('a query that cannot run is a reason, not a fatal', function (): void {
    $account = makeUser();

    documents();

    // A stored condition naming a column the table does not have. Warden
    // compiles it into the query and the database refuses the statement.
    //
    // Ownership used to reach this branch and no longer does: warden's `1.1.0`
    // put a `hasColumn()` in front of it (`Checks/Queries/WhereCan.php`,
    // `ownershipAttribute()`), so that half now fails closed instead of
    // throwing — pinned by the test below. This branch is still live, and
    // `make coverage` is a 100 % line gate, so it needs a trigger that still
    // throws rather than a `@codeCoverageIgnore`.
    Warden::allow($account)->to('view', Document::class)->where('not_a_column', '=', 1);

    $reach = Reach::of(reachedPermission(), $account);

    expect($reach->available)->toBeFalse()
        ->and($reach->sentence())->toContain('Could not be counted');
});

test('ownership through a column the table lacks answers nothing, and does not throw', function (): void {
    $account = makeUser();

    documents();

    Warden::ownedVia(Document::class, 'not_a_column');
    Warden::allow($account)->toOwn(Document::class, 'view');

    $reach = Reach::of(reachedPermission(), $account);

    // Warden's own answer since `1.1.0`, pinned here because nothing else in
    // this package pins it: a grant it cannot express in SQL grants no rows,
    // rather than emitting a statement the database refuses.
    expect($reach->available)->toBeTrue()
        ->and($reach->matched)->toBe(0)
        ->and($reach->total)->toBe(3);
});

test('a row with no readable name is not asked about', function (): void {
    $account = makeUser();

    $permission = new (Context::resolve()->permissionClass())();
    $permission->setAttribute('entity_type', new Document()->getMorphClass());

    expect(Reach::of($permission, $account)->available)->toBeFalse();
});

test('a lapsed assignment in a context is not why the caveat appears', function (): void {
    $account = makeUser();
    $role = makeRole('editor');

    documents();

    $document = Document::query()->firstOrFail();

    Carbon::setTestNow('2026-09-07 12:00:00');

    Warden::allow($role)->to('view', Document::class);
    Warden::assign($role)->until(Carbon::parse('2026-09-08 12:00:00'))->on($document)->to($account);

    expect(Reach::of(reachedPermission(), $account)->partial)->toBeTrue();

    Carbon::setTestNow('2026-09-09 12:00:00');

    // The caveat says the count and the panel can disagree BECAUSE a restricted
    // assignment falls out of one pass and into the other. A lapsed one falls
    // out of both, so it cannot be the reason — and printing the caveat anyway
    // sends somebody looking for a row that stopped existing yesterday.
    expect(Reach::of(reachedPermission(), $account)->partial)->toBeFalse();

    Carbon::setTestNow();
});
