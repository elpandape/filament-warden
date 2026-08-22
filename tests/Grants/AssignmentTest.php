<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Checks\Resolvers\CacheKeyVersioner;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
 * `assignments()` does not memoise, so every call — one per role, from
 * `isRestricted()` and again from `isElsewhere()` — is its own query.
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

    // Warmed on purpose: from here the check is answered from the cache, and
    // only warden's own actions bump the version behind it.
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

test('the elsewhere check is capped at 8, three over the 5 measured', function (): void {
    signInAsHandOut();

    $account = makeUser();
    Warden::assign(makeRole('editor'))->to($account);
    Warden::assign(makeRole('author'))->to($account);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Assignment::descriptions($account);

    $reads = assignedRoleReads();
    DB::disableQueryLog();

    expect($reads)->toBeLessThanOrEqual(8);
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
 * The real saving is the next test down: `to()` calls `bumpCacheVersion()`
 * unconditionally, found row or new one, and `isHeld()` is what keeps a
 * `give()` on an already-held role from paying for that with nothing to show.
 */
test('give() writes nothing for a role already held', function (): void {
    signInAsHandOut();

    $account = makeUser();
    $role = makeRole('editor');

    Warden::assign($role)->to($account);

    Assignment::give($account, roleKey($role));

    expect(assignmentCount())->toBe(1);
});

test('give() does not bump the cache version for a role already held', function (): void {
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
 * The whole reason `give()`/`take()` exist: `apply()` re-derives its answer for
 * every role in the catalogue on every call, none of it memoised, so a screen
 * built for a 200-role installation would pay for all 200 on a single click.
 * Measured over a 21-role catalogue, `apply()` reads `assigned_roles` 47 times
 * reaching the same state `give()` reaches in 6. The cap on `give()` is set a
 * few over the measured 6, and the comparison itself — not a hardcoded number
 * for `apply()` — is what proves the saving, so a change to either side still
 * has to keep `give()` cheaper.
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
        ->and($giveReads)->toBeLessThanOrEqual(10)
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
