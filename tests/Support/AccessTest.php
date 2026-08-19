<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Support\Access;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Facades\Warden;

pest()->extend(TestCase::class);

test('with nobody signed in nothing is granted', function (): void {
    expect(Access::grantedToCurrentUser('panel:test'))->toBeFalse();
});

test('a loose permission is asked of the store with no entity at all', function (): void {
    $user = signIn();

    Warden::allow($user)->to('panel:test');

    expect(Access::grantedToCurrentUser('panel:test'))->toBeTrue();
});

test('a permission over a model class is asked of the store with the class', function (): void {
    $user = signIn();

    Warden::allow($user)->to('viewAny', Post::class);

    expect(Access::grantedToCurrentUser('viewAny', Post::class))->toBeTrue()
        ->and(Access::granted($user, 'viewAny', Post::class))->toBeTrue();
});

test('an explicit denial answers the same false as never having been granted', function (): void {
    $user = signIn();

    Warden::allow($user)->to('panel:test');
    Warden::forbid($user)->to('panel:test');

    expect(Access::grantedToCurrentUser('panel:test'))->toBeFalse();
});
