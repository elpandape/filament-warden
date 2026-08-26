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

test('a scripted resolver answers for this package too, which is what makes it testable', function (): void {
    // Everything this package asks about authorization goes through
    // `Contracts\Resolver` — `Access` resolves it out of the container and
    // `WardenPolicy` takes it by constructor — and `Warden::fake()` is an
    // `app()->instance()` on that same binding. So a consumer scripting the
    // fake is scripting this package's answers, with no tables involved. The
    // README says so; this is what keeps it true.
    $fake = Warden::fake();

    $admin = makeUser('Admin');
    $editor = makeUser('Editor');
    $post = Post::query()->create(['title' => 'A post']);

    $fake->allow('update', Post::class)->for($admin);

    expect(Access::granted($admin, 'update', $post))->toBeTrue()
        ->and(Access::granted($editor, 'update', $post))->toBeFalse();

    $fake->assertChecked('update');
});

test('the scripted wildcard reaches all three shapes of check', function (): void {
    // Warden 2.0's fake expresses the wildcard in both halves — `namedBy()`
    // takes `*` as the permission and `coversEntity()` takes `*` as the entity
    // — so the account the roles screen hands everything to can be scripted.
    // Measured, because the assumption on the way in was that it could not be.
    $fake = Warden::fake();

    $admin = makeUser('Admin');
    $post = Post::query()->create(['title' => 'A post']);

    $fake->allow('*', '*');

    expect(Access::granted($admin, 'viewAny', Post::class))->toBeTrue()
        ->and(Access::granted($admin, 'export-reports'))->toBeTrue()
        ->and(Access::granted($admin, 'update', $post))->toBeTrue();
});
