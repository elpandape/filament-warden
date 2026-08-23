<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;
use Illuminate\Database\Eloquent\Model;

pest()->extend(TestCase::class);

/**
 * `of()` and `anyFor()` are memoised for the life of the process, keyed on
 * the exact `Model` instance handed to them — never on the row's primary
 * key. That is why every test in this file is safe reusing `makePermission()`
 * and `heldPermission()` freely: each call builds a brand new object, so a
 * fresh in-memory database reusing the same id between test cases never
 * shares a cache entry with the one before it.
 *
 * The one test below that reads the SAME instance twice across a write
 * depends on that write NOT reaching the memoised answer on its own —
 * `forget()` is what closes that gap, and nothing in `src/` ever calls it,
 * so this file is the only thing that exercises it at all.
 */
function heldPermission(string $name = 'viewAny'): Model
{
    return latestPermission($name);
}

test('a permission nobody holds is an orphan, and says so', function (): void {
    $permission = makePermission('viewAny');

    $holders = Holders::of($permission);

    expect($holders->isOrphaned())->toBeTrue()
        ->and($holders->total())->toBe(0)
        ->and($holders->roles)->toBeEmpty()
        ->and($holders->accounts)->toBeEmpty();
});

test('a role that holds it is named', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    $holders = Holders::of(heldPermission());

    expect($holders->roles)->toBe([$role->refresh()->getAttribute('title')])
        ->and($holders->isOrphaned())->toBeFalse();
});

test('an account that holds it is named too, which no relation on warden reaches', function (): void {
    $user = makeUser('Amaru Quispe');

    Warden::allow($user)->to('viewAny', Post::class);

    $holders = Holders::of(heldPermission());

    expect($holders->accounts)->toBe(['Amaru Quispe'])
        ->and($holders->accountCount)->toBe(1)
        ->and($holders->roles)->toBeEmpty();
});

test('a role and an account are counted apart, never together', function (): void {
    $role = makeRole('editor');
    $user = makeUser('Amaru Quispe');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::allow($user)->to('viewAny', Post::class);

    $holders = Holders::of(heldPermission());

    expect($holders->roles)->toHaveCount(1)
        ->and($holders->accountCount)->toBe(1)
        ->and($holders->total())->toBe(2);
});

test('a permission given to everyone belongs to nobody in particular', function (): void {
    Warden::allowEveryone()->to('viewAny', Post::class);

    $holders = Holders::of(heldPermission());

    expect($holders->everyone)->toBeTrue()
        ->and($holders->isOrphaned())->toBeFalse()
        ->and($holders->roles)->toBeEmpty();
});

test('an explicit denial is counted apart, because it is a state and not an absence', function (): void {
    $role = makeRole('editor');

    Warden::forbid($role)->to('viewAny', Post::class);

    $holders = Holders::of(heldPermission());

    expect($holders->forbidden)->toBe(1)
        ->and($holders->roles)->toHaveCount(1);
});

test('only the first accounts are named, and the rest are still counted', function (): void {
    for ($index = 0; $index < Holders::LABELS + 3; $index++) {
        Warden::allow(makeUser("Account {$index}"))->to('viewAny', Post::class);
    }

    $holders = Holders::of(heldPermission());

    expect($holders->accounts)->toHaveCount(Holders::LABELS)
        ->and($holders->accountCount)->toBe(Holders::LABELS + 3);
});

test('an authority whose morph alias no longer resolves is counted without a name', function (): void {
    $user = makeUser('Amaru Quispe');

    Warden::allow($user)->to('viewAny', Post::class);

    Context::resolve()->grantClass()::query()->withoutGlobalScopes()->update(['entity_type' => 'gone.away']);

    $holders = Holders::of(heldPermission());

    expect($holders->accountCount)->toBe(1)
        ->and($holders->accounts)->toBeEmpty()
        ->and($holders->isOrphaned())->toBeFalse();
});

test('an account with no readable label is named by its key', function (): void {
    $post = Post::query()->create(['title' => '']);

    Warden::allow($post)->to('viewAny', Post::class);

    expect(Holders::of(heldPermission())->accounts)->toBe(['#'.recordKey($post)]);
});

test('the tally counts every tenant, because the delete cascade does not look at one', function (): void {
    $permission = null;

    Warden::tenant()->onceTo(7, static function (): void {
        Warden::allow(makeRole('seven'))->to('viewAny', Post::class);
    });

    $permission = heldPermission();

    Warden::allow(makeRole('global'))->to('viewAny', Post::class);

    // Two grants, one of them another tenant's, and the delete would take both.
    expect(Holders::of($permission)->roles)->toHaveCount(1);

    $seven = Context::resolve()->permissionClass()::query()
        ->withoutGlobalScopes()
        ->where('name', 'viewAny')
        ->orderBy('id')
        ->firstOrFail();

    expect(Holders::of($seven)->roles)->toHaveCount(1);
});

test('of() answers the exact same instance for the exact same record', function (): void {
    $permission = makePermission('viewAny');

    expect(Holders::of($permission))->toBe(Holders::of($permission));
});

test('a stale copy of a row does not lock a freshly loaded copy out of the truth', function (): void {
    $permission = makePermission('viewAny');

    expect(Holders::of($permission)->isOrphaned())->toBeTrue();

    Warden::allow(makeRole('editor'))->to($permission);

    $reloaded = permissionClass()::query()->whereKey($permission->getKey())->firstOrFail();

    expect(Holders::of($permission)->isOrphaned())->toBeTrue()
        ->and(Holders::of($reloaded)->isOrphaned())->toBeFalse();
});

test('anyFor() agrees with isOrphaned() for every shape this class builds', function (): void {
    $orphan = makePermission('unheld');
    $roleHeld = makePermission('by-role');
    $accountHeld = makePermission('by-account');
    $everyoneHeld = makePermission('by-everyone');
    $forbiddenOnly = makePermission('by-denial');

    Warden::allow(makeRole('editor'))->to($roleHeld);
    Warden::allow(makeUser('Amaru Quispe'))->to($accountHeld);
    Warden::allowEveryone()->to($everyoneHeld);
    Warden::forbid(makeRole('editor'))->to($forbiddenOnly);

    expect(Holders::anyFor($orphan))->toBe(! Holders::of($orphan)->isOrphaned())
        ->and(Holders::anyFor($roleHeld))->toBe(! Holders::of($roleHeld)->isOrphaned())
        ->and(Holders::anyFor($accountHeld))->toBe(! Holders::of($accountHeld)->isOrphaned())
        ->and(Holders::anyFor($everyoneHeld))->toBe(! Holders::of($everyoneHeld)->isOrphaned())
        ->and(Holders::anyFor($forbiddenOnly))->toBe(! Holders::of($forbiddenOnly)->isOrphaned())
        ->and(Holders::anyFor($orphan))->toBeFalse()
        ->and(Holders::anyFor($roleHeld))->toBeTrue()
        ->and(Holders::anyFor($accountHeld))->toBeTrue()
        ->and(Holders::anyFor($everyoneHeld))->toBeTrue()
        ->and(Holders::anyFor($forbiddenOnly))->toBeTrue();
});

test('a memoised answer survives a grant made after it, until forget() is called', function (): void {
    $permission = makePermission('viewAny');

    expect(Holders::of($permission)->isOrphaned())->toBeTrue()
        ->and(Holders::anyFor($permission))->toBeFalse();

    Warden::allow(makeRole('editor'))->to($permission);

    expect(Holders::of($permission)->isOrphaned())->toBeTrue()
        ->and(Holders::anyFor($permission))->toBeFalse();

    Holders::forget($permission);

    expect(Holders::of($permission)->isOrphaned())->toBeFalse()
        ->and(Holders::anyFor($permission))->toBeTrue();
});

test('a grant pointing at a role that no longer exists is still somebody holding it', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    $permission = latestPermission('viewAny');

    Context::resolve()->roleClass()::query()->whereKey($role->getKey())->delete();

    $holders = Holders::of($permission);

    expect($holders->roleCount)->toBe(1)
        ->and($holders->roles)->toBeEmpty()
        ->and($holders->isOrphaned())->toBeFalse()
        ->and($holders->total())->toBe(1)
        ->and(Holders::anyFor(latestPermission('viewAny')))->toBeTrue();
});

test('the count and the names agree for a role that does exist', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);

    $holders = Holders::of(latestPermission('viewAny'));

    expect($holders->roleCount)->toBe(1)
        ->and($holders->roles)->toBe(['Editor']);
});

test('a role that both grants and forbids the same permission counts once', function (): void {
    $role = makeRole('editor');

    Warden::allow($role)->to('viewAny', Post::class);
    Warden::forbid($role)->to('viewAny', Post::class);

    $holders = Holders::of(latestPermission('viewAny'));

    expect($holders->roleCount)->toBe(1)
        ->and($holders->roles)->toBe(['Editor'])
        ->and($holders->forbidden)->toBe(1);
});

test('two distinct roles that hold it are counted apart, not folded to one', function (): void {
    Warden::allow(makeRole('editor'))->to('viewAny', Post::class);
    Warden::allow(makeRole('viewer'))->to('viewAny', Post::class);

    $holders = Holders::of(latestPermission('viewAny'));

    expect($holders->roleCount)->toBe(2)
        ->and($holders->roles)->toHaveCount(2);
});

test('an account that both grants and forbids the same permission counts once', function (): void {
    $user = makeUser('Amaru Quispe');

    Warden::allow($user)->to('viewAny', Post::class);
    Warden::forbid($user)->to('viewAny', Post::class);

    $holders = Holders::of(latestPermission('viewAny'));

    expect($holders->accountCount)->toBe(1)
        ->and($holders->accounts)->toBe(['Amaru Quispe']);
});
