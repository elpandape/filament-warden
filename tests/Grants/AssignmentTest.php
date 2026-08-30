<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Events\RoleAssigned;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * `apply()`'s own `isElsewhere()` guard is not the only thing standing between
 * an uncheck and a deleted row: `RetractsRoles::from()` filters on the exact
 * write scope (§6.24), so a role held ONLY globally already survives a retract
 * issued from inside a tenant, gate or no gate. Measured by removing just the
 * `apply()` clause: every test in this file, including the one named for this
 * exact scenario, stayed green. The clause only does real work when a role is
 * held BOTH globally and at the active scope, where warden's own filter would
 * otherwise delete the local row while the checkbox — reading a still-present
 * global row — reports nothing changed.
 *
 * `assignments()` is memoised as of v1.5.0 ("Que no cueste") — a `WeakMap` on
 * the account instance, keyed inside by tenant — and every writer this class
 * has (`give()`, `take()`, `apply()`) empties it. A write made ANY OTHER way
 * does not: `Warden::assign()`/`::retract()` is how every test in this file
 * arranges its fixtures, and after one of those the same `$account` object
 * answers `of()`/`isRestricted()`/`isElsewhere()` from the rows it read
 * before. One test needs that said out loud — 'an assignment narrowed to a
 * context says so' reads, writes past this class, and reads the same object
 * again, so it calls `Assignment::forget()` in between. Not a request
 * boundary standing in for anything: the sequence it performs has no
 * deployment analogue at all, because nothing in this package writes a
 * context-restricted assignment (§6.21). The call is there because the memo
 * would otherwise answer with the read before it, and this is the file that
 * says so rather than each test body.
 */
pest()->extend(TestCase::class);

/**
 * Whoever is signed in, trusted with every role there is.
 */
function signInAsHandOut(): Model
{
    $user = signIn();

    Warden::allow($user)->to('update', roleClass());

    return $user;
}

/**
 * A role key, narrowed: `getKey()` is mixed and a key that does not read as one
 * names no row at all.
 */
function roleKey(Model $role): int|string
{
    $key = $role->getKey();

    return is_int($key) || is_string($key) ? $key : '';
}

function assignmentCount(): int
{
    return Context::resolve()->assignedRoleClass()::query()->count();
}

/**
 * Counts every SQL statement touching `assigned_roles` in the measured
 * window — before v1.5.0's memo, that meant one per call, from
 * `isRestricted()` and again from `isElsewhere()`; after, every call for the
 * SAME account shares the one query the first of them makes, so this
 * function's own count is what the caps below actually measure.
 */
function assignedRoleReads(): int
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

/**
 * Named for what it counts, corrected from an earlier `roleTableReads()`:
 * every SQL STATEMENT whose text matches the table name, not reads
 * specifically — it would count a write too, if one happened to run in the
 * measured window. Every measurement this file takes with it only ever runs
 * SELECTs, so the numbers are correct; the name should not have implied more
 * than that.
 *
 * Quoted, and not a bare `str_contains($query, $table)` the way
 * `assignedRoleReads()` above gets away with: `roles` is a literal substring
 * of `assigned_roles`, so an unquoted check here would count every assignment
 * statement as a roles one too. Measured catching itself: an early draft of
 * this file's cost test used the unquoted form and reported 407 "roles"
 * statements against a 200-role catalogue where the real number, once
 * memoised, is 2.
 */
function roleTableStatements(): int
{
    $table = Context::resolve()->table('roles');
    $statements = 0;

    foreach (DB::getQueryLog() as $entry) {
        if (str_contains($entry['query'], '"'.$table.'"')) {
            $statements++;
        }
    }

    return $statements;
}

test('every role there is can be offered, named the way a person reads it', function (): void {
    $editor = makeRole('editor');

    expect(Assignment::options())->toBe([roleKey($editor) => 'Editor']);
});

test('what an account holds is read off the assignments, not off the relation', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    expect(Assignment::of($account))->toBe([roleKey($role)]);
});

test('a role held in a context and out of it is held once, not twice', function (): void {
    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->to($account);
    Warden::assign($role)->on($post)->to($account);

    expect(Assignment::of($account))->toBe([roleKey($role)])
        ->and(assignmentCount())->toBe(2);
});

test('a role you could not edit is one you cannot hand out', function (): void {
    signIn();

    $role = makeRole('editor');

    expect(Assignment::mayHandOut($role))->toBeFalse();

    Warden::allow(signedIn())->to('update', roleClass());

    expect(Assignment::mayHandOut($role->refresh()))->toBeTrue();
});

test('an assignment narrowed to a context says so', function (): void {
    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    expect(Assignment::isRestricted($account, roleKey($role)))->toBeFalse();

    Warden::assign($role)->on($post)->to($account);
    Assignment::forget();

    expect(Assignment::isRestricted($account, roleKey($role)))->toBeTrue();
});

test('a role that cannot be handed out carries the reason, and one that can carries none', function (): void {
    signIn();

    $account = makeUser();
    $role = makeRole('editor');

    expect(Assignment::descriptions($account)[roleKey($role)])->toContain('cannot edit this role');

    Warden::allow(signedIn())->to('update', roleClass());

    expect(Assignment::descriptions($account))->toBeEmpty();
});

test('a restricted assignment carries its own reason', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->on($post)->to($account);

    expect(Assignment::descriptions($account)[roleKey($role)])->toContain('in a context');
});

test('handing a role out writes it, and the store answers for it straight away', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    // Warmed on purpose: from here the check is answered from the cache, so
    // the assertion below can only flip if the write moved the version behind
    // it. Warden's own action classes move it, and so does an Eloquent event on
    // a `grants` or `assigned_roles` row — `WardenServiceProvider` listens on
    // `eloquent.created/updated/deleting/deleted: *` and hands the model to
    // `CacheInvalidations::markFrom()`, which acts on those two classes and no
    // other. What moves nothing is a raw pivot write, which fires neither.
    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse();

    Assignment::apply($account, [roleKey($role)]);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeTrue();
});

test('taking a role away takes it away, and the store stops answering', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::assign($role)->to($account);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeTrue();

    Assignment::apply($account, []);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse()
        ->and(assignmentCount())->toBe(0);
});

test('a payload naming a role nobody may hand out writes nothing', function (): void {
    signIn();

    $account = makeUser();
    $role = makeRole('editor');

    Assignment::apply($account, [roleKey($role)]);

    expect(assignmentCount())->toBe(0);
});

test('a payload omitting a role nobody may hand out does not take it away either', function (): void {
    signIn();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Assignment::apply($account, []);

    expect(assignmentCount())->toBe(1);
});

test('a restricted assignment is left alone, whatever the payload says', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->on($post)->to($account);

    Assignment::apply($account, []);

    expect(assignmentCount())->toBe(1);
});

test('a key that arrives as text still names the same role', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Assignment::apply($account, [(string) roleKey($role)]);

    expect(Assignment::of($account))->toBe([roleKey($role)]);
});

test('a payload of things that are not keys writes nothing', function (): void {
    signInAsHandOut();

    $account = makeUser();
    makeRole('editor');

    Assignment::apply($account, [['nope'], null]);

    expect(assignmentCount())->toBe(0);
});

test('nothing is offered where there is no account yet, as on a create form', function (): void {
    signInAsHandOut();

    $role = makeRole('editor');

    expect(Assignment::offers(null, roleKey($role)))->toBeFalse()
        ->and(Assignment::descriptions(null))->toBeEmpty();
});

test('a value that is not a key names no role', function (): void {
    signInAsHandOut();

    $account = makeUser();
    makeRole('editor');

    expect(Assignment::offers($account, ['nope']))->toBeFalse()
        ->and(Assignment::offers($account, 9999))->toBeFalse()
        ->and(Assignment::role(9999))->toBeNull();
});

test('a role this account may hand out is offered', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    expect(Assignment::offers($account, roleKey($role)))->toBeTrue()
        ->and(Assignment::offers($account, (string) roleKey($role)))->toBeTrue();
});

test('a state that is not a list is read as nothing held', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Assignment::apply($account, 'nope');

    expect(assignmentCount())->toBe(0);
});

test('a role assigned outside this tenant cannot be handed back from here', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    Warden::assign($role)->to($account);

    Warden::tenant()->onceTo(5, function () use ($account, $role): void {
        expect(Assignment::isElsewhere($account, roleKey($role)))->toBeTrue()
            ->and(Assignment::offers($account, roleKey($role)))->toBeFalse();
    });
});

test('a role assigned in this very tenant is offered as usual', function (): void {
    signInAsHandOut();

    $account = makeUser();

    Warden::tenant()->onceTo(5, function () use ($account): void {
        $role = makeRole('editor');
        Warden::assign($role)->to($account);

        expect(Assignment::isElsewhere($account, roleKey($role)))->toBeFalse()
            ->and(Assignment::offers($account, roleKey($role)))->toBeTrue();
    });
});

test('a string tenant id still matches a row scoped as an integer', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::tenant()->onceTo(5, function () use ($account, $role): void {
        Warden::assign($role)->to($account);
    });

    Warden::tenant()->onceTo('5', function () use ($account, $role): void {
        expect(Assignment::isElsewhere($account, roleKey($role)))->toBeFalse()
            ->and(Assignment::offers($account, roleKey($role)))->toBeTrue();
    });
});

test('unticking a role this screen cannot retract deletes nothing and says so', function (): void {
    signInAsHandOut();

    $account = makeUser();
    Warden::assign(makeRole('editor'))->to($account);

    Warden::tenant()->onceTo(5, function () use ($account): void {
        Assignment::apply($account, []);
    });

    expect(Context::resolve()->assignedRoleClass()::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('unticking a role held both globally and here leaves the local row too', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    Warden::assign($role)->to($account);

    Warden::tenant()->onceTo(5, function () use ($account, $role): void {
        Warden::assign($role)->to($account);

        Assignment::apply($account, []);
    });

    expect(Context::resolve()->assignedRoleClass()::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the screen says why it will not hand that role back', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    Warden::assign($role)->to($account);

    Warden::tenant()->onceTo(5, function () use ($account, $role): void {
        expect(Assignment::descriptions($account)[roleKey($role)] ?? null)
            ->toBe(__('filament-warden::ui.relations.roles.elsewhere'));
    });
});

/**
 * Capped at 4, two over the 2 measured after `assignments()` was memoised
 * (v1.5.0, "Que no cueste"): `descriptions()` loops `byKey()` calling
 * `isRestricted()` then `isElsewhere()` for every role, and both used to pay
 * their own query per role per call; now every one of those, for the same
 * account instance and the same tenant, shares the one query the first of
 * them makes.
 *
 * Two over and not three, because three would put the ceiling at 5 — and 5 is
 * what this same fixture measures against an unmemoised `assignments()`. A
 * ceiling the shape it excludes already passes bounds nothing.
 */
test('the elsewhere check is capped at 4, two over the 2 measured', function (): void {
    signInAsHandOut();

    $account = makeUser();
    Warden::assign(makeRole('editor'))->to($account);
    Warden::assign(makeRole('author'))->to($account);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Assignment::descriptions($account);

    $reads = assignedRoleReads();
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(4);
});

test('give() hands a role out and the store answers for it straight away', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeFalse();

    Assignment::give($account, roleKey($role));

    expect(Access::granted($account, 'viewAny', Post::class))->toBeTrue()
        ->and(assignmentCount())->toBe(1);
});

/**
 * NOT a guard against a duplicate row — corrected from an earlier draft that
 * said so. `AssignsRoles::to()` writes through `firstOrCreate()`, and
 * `Query\Builder::where()` redirects a `null` search value to `whereNull()`,
 * so the existing unrestricted row is FOUND, never duplicated: this exact
 * count assertion stays green with `isHeld()` deleted from `give()`'s guard.
 *
 * What `isHeld()` buys is `give()`'s own return value. `AssignsRoles` exposes
 * no counterpart to `RetractsRoles::retractedCount()`, so this class cannot ask
 * warden whether a row was written, and without the guard `give()` would answer
 * true for a no-op. The test that goes red for that is in
 * `RolesRelationManagerTest`: `assigning a role the account already holds
 * notifies nothing`.
 */
test('give() writes nothing for a role already held', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Assignment::give($account, roleKey($role));

    expect(assignmentCount())->toBe(1);
});

/**
 * A pin on the DEPENDENCY, not on this package, and it is written down here so
 * the next reader does not mistake it for a plugin guarantee again.
 * `AssignsRoles::to()` accumulates whether any row was recently created and
 * returns before both `bumpCacheVersion($scope)` and the `RoleAssigned`
 * dispatch, so a found row invalidates nothing at that scope. `give()`'s own
 * guard returns earlier still and never reaches warden, so this case can only
 * go red on two faults at once — the guard gone AND warden regressed.
 */
test('give() does not bump the cache version for a role already held, which warden now guarantees itself', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $versioner = app(CacheKeyVersioner::class);
    $before = $versioner->segment();

    Assignment::give($account, roleKey($role));

    expect($versioner->segment())->toBe($before);
});

test('give() writes nothing for a role this account may not hand out', function (): void {
    signIn();

    $account = makeUser();
    $role = makeRole('editor');

    Assignment::give($account, roleKey($role));

    expect(assignmentCount())->toBe(0);
});

test('give() writes nothing for a value that names no role', function (): void {
    signInAsHandOut();

    $account = makeUser();

    Assignment::give($account, 9999);

    expect(assignmentCount())->toBe(0);
});

/**
 * The test the `$assignmentsByEntity` memo's own docblock cites as the one
 * that would actually go stale if `give()`/`take()` forgot to invalidate: the
 * SAME `$account` object, no Livewire round-trip between the write and the
 * re-read, so nothing outside this class's own invalidation could make the
 * second `of()` call see the write.
 */
test('give()/take() invalidate the assignments memo, a read right after a write sees it', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    expect(Assignment::of($account))->toBeEmpty();

    Assignment::give($account, roleKey($role));

    expect(Assignment::of($account))->toBe([roleKey($role)]);

    Assignment::take($account, roleKey($role));

    expect(Assignment::of($account))->toBeEmpty();
});

/**
 * `assignments()` reads through warden's `TenantScope`, so the answer depends
 * on the read context and not only on the account. One entry per account
 * would hand whichever tenant asked first to every tenant that asked after —
 * measured on this exact fixture before the tenant became part of the key:
 * outside any tenant the role is held, inside `onceTo(8)` the memo still said
 * held, and a cold read said empty.
 */
test('the memo answers per tenant, not once for the account', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::tenant()->onceTo(7, static function () use ($role, $account): void {
        Warden::assign($role)->to($account);
    });

    expect(Assignment::of($account))->toBe([roleKey($role)]);

    Warden::tenant()->onceTo(8, static function () use ($account): void {
        expect(Assignment::of($account))->toBeEmpty();
    });

    expect(Assignment::of($account))->toBe([roleKey($role)]);
});

test('take() takes a role back and the store stops answering', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::assign($role)->to($account);

    expect(Access::granted($account, 'viewAny', Post::class))->toBeTrue();

    $result = Assignment::take($account, roleKey($role));

    expect($result)->toBeTrue()
        ->and(Access::granted($account, 'viewAny', Post::class))->toBeFalse()
        ->and(assignmentCount())->toBe(0);
});

test('take() leaves a restricted assignment alone', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');
    $post = Post::query()->create(['title' => 'A post']);

    Warden::assign($role)->on($post)->to($account);

    $result = Assignment::take($account, roleKey($role));

    expect($result)->toBeFalse()
        ->and(assignmentCount())->toBe(1);
});

test('take() refuses a role this scope could not delete anyway', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    // Two independent defences hold this, and it took breaking each alone to
    // find out: `isElsewhere()` refuses before any write, and `retractedCount()`
    // refuses after one that removed nothing. Break either and this stays green;
    // break both and a no-op reports success.
    $result = Warden::tenant()->onceTo(5, static fn (): bool => Assignment::take($account, roleKey($role)));

    expect($result)->toBeFalse()
        ->and(assignmentCount())->toBe(1);
});

test('take() leaves a role alone this account may not hand out', function (): void {
    signIn();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    $result = Assignment::take($account, roleKey($role));

    expect($result)->toBeFalse()
        ->and(assignmentCount())->toBe(1);
});

/**
 * The one that discriminates `isHeld()`'s inverted guard inside `take()` —
 * `RolesRelationManagerTest.php`'s Livewire-mounted attempt at the same
 * scenario cannot: `getTableRecord()` there is scoped to the same
 * `Assignment::of($account)` this guard reads, so a role not held there
 * never resolves a record in the first place and the closure never runs.
 * Called directly, bypassing all of that, `Warden::retract()->from()` would
 * still delete nothing for a role never assigned — the count assertion below
 * would stay green even with the guard deleted — but it WOULD still be a
 * call to warden for no reason, and the return value is what says so:
 * `take()` reports `false` before ever reaching for `role()` or `Warden`.
 */
test('take() writes nothing for a role not held', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    $result = Assignment::take($account, roleKey($role));

    expect($result)->toBeFalse()
        ->and(assignmentCount())->toBe(0);
});

/**
 * The reason `give()`/`take()` exist: `apply()` re-derives its answer for every
 * role in the catalogue on every call, so before `assignments()` was memoised
 * (v1.5.0, "Que no cueste") a screen built for a 200-role installation paid
 * for all 200 on a single click. Measured over a 21-role catalogue then:
 * `apply()` read `assigned_roles` 47 times reaching the same state `give()`
 * reached in 6.
 *
 * What this test can still show, and what it no longer can. Measured after the
 * memo at BOTH a 21-role and a 201-role catalogue, and identical at each: 4
 * for `give()`, 5 for `apply()` — so `apply()`'s per-role cost has stopped
 * being visible as `assigned_roles` statements at all, not merely shrunk. The
 * per-role authorization `mayHandOut()` still performs is answered from
 * warden's own cache after the first role and adds no statement; total
 * statements are 7 and 10, also flat at both catalogue sizes. The margin this
 * comparison used to have is therefore gone, and it is a floor now rather than
 * a demonstration: it goes red if `apply()` ever becomes the cheaper path, or
 * if `give()` regains a read per role. `give()`'s own cap is one over its
 * measured 4 and not the usual two or three, because two would put it at 6 —
 * exactly what `give()` cost before the memo.
 */
test('give() reads assigned_roles far fewer times than apply() reaching the same state', function (): void {
    signInAsHandOut();

    $account = makeUser();

    for ($i = 0; $i < 20; $i++) {
        makeRole('role-'.$i);
    }

    $role = makeRole('editor');

    DB::flushQueryLog();
    DB::enableQueryLog();

    Assignment::give($account, roleKey($role));

    $giveReads = assignedRoleReads();
    DB::disableQueryLog();

    expect(assignmentCount())->toBe(1);

    Assignment::take($account, roleKey($role));

    expect(assignmentCount())->toBe(0);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Assignment::apply($account, [roleKey($role)]);

    $applyReads = assignedRoleReads();
    DB::disableQueryLog();

    expect(assignmentCount())->toBe(1)
        ->and($giveReads)->toBeLessThanOrEqual(5)
        ->and($applyReads)->toBeGreaterThan($giveReads);
});

/**
 * The cost the earlier ruling that created `give()`/`take()` was supposed to
 * settle, come back through a different door: `Select::disableOptionWhen()`
 * evaluates its closure once per option with no memo of its own
 * (`CanDisableOptions::isOptionDisabled()`), so opening the assign modal on a
 * 200-role installation called `Assignment::offers()` 200 times, and every one
 * of those, unmemoised, cost its own `roles` table read through `byKey()`.
 * Measured mounting the real modal against 200 roles: 202 `roles` reads before
 * `byKey()` was memoised, 2 after — the two that remain are `options()`'s own
 * first read and the table's separate pagination count query, neither of
 * which scales with the catalogue. The cap here is a smaller, exact version of
 * the same shape: `options()` once plus `offers()` per option costs exactly
 * one `roles` read, however many options there are.
 */
test('byKey() answers every option-disabling check from one roles read', function (): void {
    signInAsHandOut();

    for ($i = 0; $i < 5; $i++) {
        makeRole('role-'.$i);
    }

    $account = makeUser();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $keys = array_keys(Assignment::options());

    foreach ($keys as $key) {
        Assignment::offers($account, $key);
    }

    $reads = roleTableStatements();
    DB::disableQueryLog();

    expect($keys)->toHaveCount(5)
        ->and($reads)->toBeLessThanOrEqual(2);
});

test('the apply() transaction opens on warden own connection, not the default one', function (): void {
    config()->set('database.connections.warden_write', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('warden.connection', 'warden_write');
    Context::resolve()->setConnection('warden_write');

    $installPath = InstalledVersions::getInstallPath('elpandape/warden');

    /** @var Migration $migration */
    $migration = require $installPath.'/database/migrations/create_warden_tables.php.stub';
    $migration->up(); // @phpstan-ignore method.notFound

    signInAsHandOut();
    $account = makeUser();
    $role = makeRole('editor');

    Event::listen(RoleAssigned::class, static function (): void {
        throw new RuntimeException('interrupted mid-assign, on purpose');
    });

    expect(static function () use ($account, $role): void {
        Assignment::apply($account, [roleKey($role)]);
    })->toThrow(RuntimeException::class)
        ->and(assignmentCount())->toBe(0);
});

test('a role nobody ticked here is left as somebody else set it', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole();

    $wasShowing = [];

    Warden::assign($role)->to($account);

    $report = Assignment::apply($account, [], $wasShowing);

    expect(Assignment::of($account))->toContain(roleKey($role))
        ->and($report->preserved)->toBe(1)
        ->and($report->written)->toBe(0)
        ->and($report->refused)->toBeEmpty();
});

test('a role this person ticked is assigned when nobody else moved it', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole();

    $report = Assignment::apply($account, [roleKey($role)], []);

    expect(Assignment::of($account))->toContain(roleKey($role))
        ->and($report->written)->toBe(1)
        ->and($report->preserved)->toBe(0);
});

test('a checkbox cannot collide the way a cell can, so nothing is ever refused here', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole();

    // The screen showed it unticked; this person ticks it; somebody else ticks
    // it too. There is no third value for the two of them to disagree about.
    $wasShowing = [];

    Warden::assign($role)->to($account);

    $report = Assignment::apply($account, [roleKey($role)], $wasShowing);

    expect($report->refused)->toBeEmpty()
        ->and($report->metAnother())->toBeFalse();
});

test('two people who hand out the same role do not annoy each other', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole();

    $wasShowing = [];

    Warden::assign($role)->to($account);

    $report = Assignment::apply($account, [roleKey($role)], $wasShowing);

    expect(Assignment::of($account))->toContain(roleKey($role))
        ->and($report->metAnother())->toBeFalse()
        ->and($report->written)->toBe(0);
});

test('without a baseline every role counts as ticked by this person', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole();

    Warden::assign($role)->to($account);

    $report = Assignment::apply($account, []);

    expect(Assignment::of($account))->toBeEmpty()
        ->and($report->written)->toBe(1)
        ->and($report->metAnother())->toBeFalse();
});
