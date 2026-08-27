<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
use ElPandaPe\Warden\Context;
use ElPandaPe\Warden\Facades\Warden;

pest()->extend(TestCase::class);

test('a model whose table carries the ownership column offers it', function (): void {
    $ownership = Ownership::of(Comment::class);

    expect($ownership->available)->toBeTrue()
        ->and($ownership->column)->toBe('user_id');
});

test('a model whose table has no such column does not, and names what is missing', function (): void {
    $ownership = Ownership::of(Post::class);

    expect($ownership->available)->toBeFalse()
        ->and($ownership->column)->toBe('user_id');
});

test('an application that said how ownership resolves is taken at its word', function (): void {
    Warden::ownedVia(Post::class, static fn (Post $post, User $user): bool => true);

    $ownership = Ownership::of(Post::class);

    expect($ownership->available)->toBeTrue()
        ->and($ownership->column)->toBeNull();
});

test('a column named by hand is the one that gets checked', function (): void {
    Warden::ownedVia(Post::class, 'title');

    $ownership = Ownership::of(Post::class);

    expect($ownership->available)->toBeTrue()
        ->and($ownership->column)->toBe('title');
});

test('an installation that resolves no ownership says that, not that a column is missing', function (): void {
    config()->set('warden.ownership.default_attribute');
    app()->forgetInstance(Context::class);

    $ownership = Ownership::of(Post::class);

    // Warden's `ownershipResolverFor()` falls through to the EMPTY STRING here,
    // and reading that as a column name gave the right refusal for the wrong
    // reason — `in_array('', $columns)` is false either way, so only `resolved`
    // can tell the screen which of the two sentences to print.
    expect($ownership->available)->toBeFalse()
        ->and($ownership->resolved)->toBeFalse()
        ->and($ownership->column)->toBeNull();
});

test('a model whose table lacks the column is a different refusal, and still names it', function (): void {
    $ownership = Ownership::of(Post::class);

    expect($ownership->available)->toBeFalse()
        ->and($ownership->resolved)->toBeTrue()
        ->and($ownership->column)->toBe('user_id');
});

test('there is no ownership to resolve where there is no model', function (): void {
    $ownership = Ownership::unavailable();

    expect($ownership->available)->toBeFalse()
        ->and($ownership->column)->toBeNull();
});
