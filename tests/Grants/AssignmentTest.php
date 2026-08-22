<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Grants\Assignment;
use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

test('unticking a role this screen cannot retract deletes nothing and says so', function (): void {
    signInAsHandOut();

    $account = makeUser();
    Warden::assign(makeRole('editor'))->to($account);

    Warden::tenant()->onceTo(5, function () use ($account): void {
        Assignment::apply($account, []);
    });

    expect(Context::resolve()->assignedRoleClass()::query()->withoutGlobalScopes()->count())->toBe(1);
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
