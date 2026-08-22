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
 * The catalogue row a grant just wrote, whichever of them it is.
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
