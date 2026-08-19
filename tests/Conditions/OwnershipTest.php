<?php

declare(strict_types=1);

use ElPandaPe\FilamentWarden\Conditions\Ownership;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Comment;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\Post;
use ElPandaPe\FilamentWarden\Tests\Fixtures\Models\User;
use ElPandaPe\FilamentWarden\Tests\TestCase;
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

test('there is no ownership to resolve where there is no model', function (): void {
    $ownership = Ownership::unavailable();

    expect($ownership->available)->toBeFalse()
        ->and($ownership->column)->toBeNull();
});
